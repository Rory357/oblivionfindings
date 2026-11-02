package setup

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/hubupdate"
	"oblivion.local/endpoint-agent/internal/release"
)

// Obli for one person, with no administrator (windows tab, "Staff computers without admin
// rights", screen F). Obli's files go into the person's own profile, its identity into their
// own private folder, and it starts at their sign-in from their own Run key. The service, the
// hub and updates for everyone on the PC stay with IT.
//
// The publisher signature, exact bytes and program checks are the same as a full install;
// only the place differs.

// UserInstall is the small record kept beside the identity, read by Remove.
type UserInstall struct {
	Schema    int    `json:"schema"`
	Stage     string `json:"stage"`
	Version   string `json:"version"`
	Revision  uint64 `json:"revision"`
	ServerURL string `json:"server_url"`
	AgentUUID string `json:"agent_uuid"`
	UpdatedAt string `json:"updated_at"`
}

const userRunKey = `Software\Microsoft\Windows\CurrentVersion\Run`

type userPaths struct{ program, releases, state, record string }

func perUserPaths() (userPaths, error) {
	local, err := windows.KnownFolderPath(windows.FOLDERID_LocalAppData, windows.KF_FLAG_DEFAULT)
	if err != nil || local == "" {
		return userPaths{}, errors.New("cannot find this person's local app folder")
	}
	program := filepath.Join(local, "Programs", "Oblivion", "Obli")
	return userPaths{
		program:  program,
		releases: filepath.Join(program, "releases"),
		state:    filepath.Join(local, "Oblivion", "Endpoint"),
		record:   filepath.Join(local, "Oblivion", "obli-install.json"),
	}, nil
}

// InstallForUser installs Obli for the signed-in person.
func InstallForUser(ctx context.Context, pack *release.Package, profile agent.Bootstrap, confirmedServer string, progress Progress) (UserInstall, error) {
	var none UserInstall
	if progress == nil {
		progress = func(string, string, string) {}
	}
	if pack == nil || !pack.Supports("computer") {
		return none, errors.New("this installer doesn't contain Obli")
	}
	if err := agent.ValidateBootstrap(profile, time.Now()); err != nil {
		return none, err
	}
	if confirmedServer == "" || confirmedServer != profile.ServerURL {
		return none, errors.New("confirm the installation profile's exact Oblivion server before installing")
	}
	paths, err := perUserPaths()
	if err != nil {
		return none, err
	}
	m := pack.Manifest()

	progress("staging", "started", "")
	stage, err := stageForUser(ctx, pack, paths)
	if err != nil {
		progress("staging", "failed", err.Error())
		return none, err
	}
	progress("staging", "done", "")
	tray := filepath.Join(paths.releases, stage, "computer", "obli.exe")

	progress("enrolment", "started", "")
	agentUUID, err := enrolForUser(ctx, paths.state, profile)
	if err != nil {
		progress("enrolment", "failed", err.Error())
		_ = os.RemoveAll(filepath.Join(paths.releases, stage))
		return none, err
	}
	progress("enrolment", "done", "")

	progress("tray_registration", "started", "")
	if err = setUserTray(paths, tray); err != nil {
		progress("tray_registration", "failed", err.Error())
		_ = os.RemoveAll(filepath.Join(paths.releases, stage))
		return none, err
	}
	record := UserInstall{Schema: 1, Stage: stage, Version: m.Version, Revision: m.Revision, ServerURL: profile.ServerURL, AgentUUID: agentUUID, UpdatedAt: time.Now().UTC().Format(time.RFC3339)}
	data, err := json.Marshal(record)
	if err == nil {
		err = os.WriteFile(paths.record, data, 0o600)
	}
	if err != nil {
		progress("tray_registration", "failed", err.Error())
		return none, err
	}
	progress("tray_registration", "done", "")
	return record, nil
}

// stageForUser unpacks only Obli's part into a new folder and checks it like any install.
func stageForUser(ctx context.Context, pack *release.Package, paths userPaths) (string, error) {
	m := pack.Manifest()
	id, err := nonce()
	if err != nil {
		return "", err
	}
	stage := fmt.Sprintf("r-%d-%s", m.Revision, id)
	folder := filepath.Join(paths.releases, stage)
	if err = os.MkdirAll(paths.releases, 0o700); err != nil {
		return "", errors.New("cannot create Obli's folder in this person's profile")
	}
	if err = rejectLinkedFolders(paths.program, paths.releases); err != nil {
		return "", err
	}
	if err = os.Mkdir(folder, 0o700); err != nil {
		return "", errors.New("cannot create Obli's folder in this person's profile")
	}
	keep := false
	defer func() {
		if !keep {
			_ = os.RemoveAll(folder)
		}
	}()
	if err = pack.ExtractPartsNew(folder, "computer"); err != nil {
		return "", err
	}
	if err = pack.VerifyExtractedParts(folder, "computer"); err != nil {
		return "", err
	}
	for _, file := range m.Files {
		if err = ctx.Err(); err != nil {
			return "", err
		}
		if strings.HasPrefix(file.Path, "computer/") && strings.EqualFold(filepath.Ext(file.Path), ".exe") {
			if err = release.VerifyExecutable(filepath.Join(folder, filepath.FromSlash(file.Path)), m.Architecture); err != nil {
				return "", err
			}
		}
	}
	keep = true
	return stage, nil
}

// rejectLinkedFolders refuses folders that are links elsewhere.
func rejectLinkedFolders(folders ...string) error {
	for _, folder := range folders {
		info, err := os.Lstat(folder)
		if err != nil || !info.IsDir() || info.Mode()&(os.ModeSymlink|os.ModeIrregular) != 0 {
			return errors.New("Obli's folder in this profile is not a plain folder")
		}
	}
	return nil
}

// enrolForUser enrols Obli's identity in the person's private folder (this user, SYSTEM and
// administrators only), exactly as "oblivion-agent enrol" does.
func enrolForUser(ctx context.Context, state string, profile agent.Bootstrap) (string, error) {
	store, err := agent.OpenStore(state)
	if err != nil {
		return "", err
	}
	defer store.Close()
	current, err := agent.PrepareBootstrap(store, profile)
	if err != nil {
		return "", err
	}
	client, err := agent.NewClient(current.ServerURL, current.ServerKey)
	if err != nil {
		return "", err
	}
	if err = client.Enroll(ctx, store, &current, "windows"); err != nil {
		return "", err
	}
	return current.AgentUUID, nil
}

// setUserTray starts Obli at this person's sign-in. Another program's entry under the same
// name is never replaced.
func setUserTray(paths userPaths, tray string) error {
	key, _, err := registry.CreateKey(registry.CURRENT_USER, userRunKey, registry.QUERY_VALUE|registry.SET_VALUE)
	if err != nil {
		return err
	}
	defer key.Close()
	if value, _, err := key.GetStringValue(trayValueName); err == nil && !ownUserTray(paths, value) {
		return errors.New("another program already starts as Obli at sign-in")
	}
	return key.SetStringValue(trayValueName, syscall.EscapeArg(tray)+" --tray")
}

// ownUserTray: the entry starts obli.exe from one of this person's Obli release folders.
func ownUserTray(paths userPaths, value string) bool {
	path, found := strings.CutSuffix(value, " --tray")
	if !found {
		return false
	}
	relative, err := filepath.Rel(paths.releases, strings.Trim(path, `"`))
	if err != nil {
		return false
	}
	parts := strings.Split(relative, string(filepath.Separator))
	return len(parts) == 3 && hubupdate.ValidStage(parts[0]) && parts[1] == "computer" && strings.EqualFold(parts[2], "obli.exe")
}

// RemoveForUser removes Obli from this person's profile: the sign-in entry (if it's ours),
// any Obli running from that folder, the files and the identity. There's no agent call to
// tell Oblivion, so the computer just goes offline there.
func RemoveForUser() error {
	paths, err := perUserPaths()
	if err != nil {
		return err
	}
	if key, err := registry.OpenKey(registry.CURRENT_USER, userRunKey, registry.QUERY_VALUE|registry.SET_VALUE); err == nil {
		if value, _, err := key.GetStringValue(trayValueName); err == nil && ownUserTray(paths, value) {
			_ = key.DeleteValue(trayValueName)
		}
		key.Close()
	}
	stopUserObli(paths.releases)
	var problems []error
	for _, folder := range []string{paths.program, paths.state} {
		if info, err := os.Lstat(folder); err == nil && info.IsDir() && info.Mode()&(os.ModeSymlink|os.ModeIrregular) == 0 {
			if err = os.RemoveAll(folder); err != nil {
				problems = append(problems, err)
			}
		}
	}
	if err = os.Remove(paths.record); err != nil && !os.IsNotExist(err) {
		problems = append(problems, err)
	}
	return errors.Join(problems...)
}

// stopUserObli closes this person's Obli if it runs from their Obli folder.
func stopUserObli(releases string) {
	prefix := strings.ToLower(releases + `\`)
	snapshot, err := windows.CreateToolhelp32Snapshot(windows.TH32CS_SNAPPROCESS, 0)
	if err != nil {
		return
	}
	defer windows.CloseHandle(snapshot)
	entry := windows.ProcessEntry32{Size: uint32(unsafe.Sizeof(windows.ProcessEntry32{}))}
	for err = windows.Process32First(snapshot, &entry); err == nil; err = windows.Process32Next(snapshot, &entry) {
		name := windows.UTF16ToString(entry.ExeFile[:])
		if !strings.EqualFold(name, "obli.exe") && !strings.EqualFold(name, "oblivion-agent.exe") {
			continue
		}
		process, err := windows.OpenProcess(windows.PROCESS_QUERY_LIMITED_INFORMATION|windows.PROCESS_TERMINATE|windows.SYNCHRONIZE, false, entry.ProcessID)
		if err != nil {
			continue
		}
		buffer := make([]uint16, windows.MAX_LONG_PATH)
		size := uint32(len(buffer))
		if windows.QueryFullProcessImageName(process, 0, &buffer[0], &size) == nil &&
			strings.HasPrefix(strings.ToLower(windows.UTF16ToString(buffer[:size])), prefix) {
			if windows.TerminateProcess(process, 0) == nil {
				_, _ = windows.WaitForSingleObject(process, 5000)
			}
		}
		windows.CloseHandle(process)
	}
}
