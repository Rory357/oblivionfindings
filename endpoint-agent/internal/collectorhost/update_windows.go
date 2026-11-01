package collectorhost

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"syscall"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
	"oblivion.local/endpoint-agent/internal/hubupdate"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/securefs"
)

// Hub self-update on Windows (S&D Phase B, D3).
//
// The service's program path in the Service Control Manager names the release folder
// (releases\<stage>\site\oblivion-collector.exe). An update points that path at a new,
// fully checked release folder, and a roll-back points it back. That was chosen over a
// stable launcher in front because:
//   - each release folder is checked whole (publisher signature, exact bytes, protected
//     permissions, Authenticode) before the service is pointed at it, and never changes after;
//   - switching back is the same single configuration write, to a folder that already ran;
//   - a launcher would be a new program outside the signed release folders, unknown to setup
//     and installedStage, with no safe way to update itself.
// The one case a launcher covers, a new release that can't even start, is covered by the
// update helper: it runs from the old, known-good release and rolls back at the deadline.

const UpdateHelperCommand = "update-helper"

const updateLockName = "hub-update.lock"

func newUpdater(i *installed, report func(string)) *hubupdate.Updater {
	host := &windowsUpdates{updateRecords: updateRecords{state: i.state, releases: i.releases}, i: i}
	return &hubupdate.Updater{Host: host, Platform: "windows", Arch: runtime.GOARCH, Now: time.Now, Log: report}
}

// updateRecords is shared by the service and the update helper: the saved record, its lock,
// and the service switch.
type updateRecords struct {
	state, releases *securefs.Directory
}

func (r *updateRecords) Lock(ctx context.Context) (func(), error) {
	deadline := time.Now().Add(30 * time.Second)
	for {
		lock, err := r.state.Lock(updateLockName)
		if err == nil {
			return func() { lock.Close() }, nil
		}
		if time.Now().After(deadline) {
			return nil, err
		}
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(250 * time.Millisecond):
		}
	}
}

func (r *updateRecords) Load() (hubupdate.State, error) {
	data, err := r.state.ReadFile(hubupdate.StateFile, 64*1024)
	if os.IsNotExist(err) {
		return hubupdate.DecodeState(nil)
	}
	if err != nil {
		return hubupdate.State{}, err
	}
	return hubupdate.DecodeState(data)
}

func (r *updateRecords) Save(s hubupdate.State) error {
	data, err := s.Encode()
	if err != nil {
		return err
	}
	return r.state.WriteAtomic(hubupdate.StateFile, data)
}

// Switch points the service at another release folder. It only changes a service that
// already points at one of our release folders, and only to a folder whose program is there
// with protected permissions.
func (r *updateRecords) Switch(_ context.Context, stage string) error {
	if !stagePattern.MatchString(stage) {
		return errInstallation
	}
	folder, err := r.releases.OpenChild(stage, false)
	if err != nil {
		return errInstallation
	}
	defer folder.Close()
	site, err := folder.OpenChild("site", false)
	if err != nil {
		return errInstallation
	}
	defer site.Close()
	if site.CheckFile("oblivion-collector.exe") != nil {
		return errInstallation
	}
	program, err := windows.KnownFolderPath(windows.FOLDERID_ProgramFiles, windows.KF_FLAG_DEFAULT)
	if err != nil {
		return errInstallation
	}
	manager, err := mgr.Connect()
	if err != nil {
		return errors.New("cannot access Windows Service Control Manager")
	}
	defer manager.Disconnect()
	service, err := manager.OpenService(Name)
	if err != nil {
		return err
	}
	defer service.Close()
	config, err := service.Config()
	if err != nil {
		return err
	}
	current, err := serviceStage(program, config.BinaryPathName)
	if err != nil {
		return errors.New("the collector service doesn't point at an Oblivion release; administrator review is required")
	}
	if current == stage {
		return nil
	}
	config.BinaryPathName = serviceCommand(program, stage)
	return service.UpdateConfig(config)
}

// serviceCommand matches what setup registers (internal/setup/service_windows.go).
func serviceCommand(program, stage string) string {
	return syscall.EscapeArg(filepath.Join(program, "Oblivion", "Agent", "releases", stage, "site", "oblivion-collector.exe")) + " service"
}

// serviceStage reads the release folder out of the service's command line, exactly.
func serviceStage(program, command string) (string, error) {
	path, found := strings.CutSuffix(command, " service")
	if !found {
		return "", errInstallation
	}
	if len(path) >= 2 && strings.HasPrefix(path, `"`) && strings.HasSuffix(path, `"`) {
		path = path[1 : len(path)-1]
	}
	stage, err := installedStage(program, path)
	if err != nil || serviceCommand(program, stage) != command {
		return "", errInstallation
	}
	return stage, nil
}

func configuredStage() (string, error) {
	program, err := windows.KnownFolderPath(windows.FOLDERID_ProgramFiles, windows.KF_FLAG_DEFAULT)
	if err != nil {
		return "", err
	}
	manager, err := mgr.Connect()
	if err != nil {
		return "", err
	}
	defer manager.Disconnect()
	service, err := manager.OpenService(Name)
	if err != nil {
		return "", err
	}
	defer service.Close()
	config, err := service.Config()
	if err != nil {
		return "", err
	}
	return serviceStage(program, config.BinaryPathName)
}

// windowsUpdates is the running service's side of hubupdate.Host.
type windowsUpdates struct {
	updateRecords
	i *installed
}

func (w *windowsUpdates) Running() hubupdate.Running {
	running := hubupdate.Running{Stage: w.i.stage, Revision: hubupdate.StageRevision(w.i.stage)}
	if data, err := w.i.code.ReadFile("VERSION", 64); err == nil {
		if version := strings.TrimSpace(string(data)); hubupdate.ValidVersion(version) {
			running.Version = version
		}
	}
	return running
}

func (w *windowsUpdates) ReadInstructions() ([]byte, error) {
	return w.i.state.ReadFile("hub-instructions.json", hubupdate.MaxInstructionsBytes)
}

// Fetch runs the PHP hub's update-fetch, which downloads on the hub's signed mTLS path and
// checks size and SHA-256 while it streams. Go makes the folder: on Windows only folders
// created with protected permissions pass the private state check.
func (w *windowsUpdates) Fetch(ctx context.Context, artifact hubupdate.Artifact) (string, error) {
	folder, err := w.i.state.EnsureChild("updates", true)
	if err != nil {
		return "", err
	}
	defer folder.Close()
	// Start empty: a download cut short by a stop can leave a part file behind.
	if err := emptyFolder(folder.Path()); err != nil {
		return "", err
	}
	output := filepath.Join(folder.Path(), "release.zip")
	bounded, cancel := context.WithTimeout(ctx, 45*time.Minute)
	defer cancel()
	code, err := runChild(bounded, w.i.call("update-fetch", "--artifact="+artifact.ID, "--output="+output))
	if err != nil || code != 0 {
		_ = os.Remove(output)
		return "", errors.New("the release could not be downloaded")
	}
	return output, nil
}

// Install checks the package (publisher signature, manifest, expiry, exact bytes), unpacks it
// into a new protected release folder, checks the whole folder again, and checks every
// Windows program in it with VerifyExecutable, as setup does.
func (w *windowsUpdates) Install(ctx context.Context, download string, target hubupdate.Target, _ hubupdate.Artifact, running hubupdate.Running) (hubupdate.Release, error) {
	var none hubupdate.Release
	pack, err := hubupdate.OpenWindowsPackage(download, target, runtime.GOARCH, running.Revision, time.Now())
	if err != nil {
		return none, err
	}
	defer pack.Close()
	m := pack.Manifest()
	nonce := make([]byte, 16)
	if _, err := rand.Read(nonce); err != nil {
		return none, err
	}
	stage := fmt.Sprintf("r-%d-%s", m.Revision, hex.EncodeToString(nonce))
	folder, err := w.i.releases.EnsureChild(stage, false)
	if err != nil {
		return none, err
	}
	keep := false
	defer func() {
		folder.Close()
		if !keep {
			_ = os.RemoveAll(filepath.Join(w.i.releases.Path(), stage))
		}
	}()
	if entries, err := os.ReadDir(folder.Path()); err != nil || len(entries) != 0 {
		return none, errInstallation
	}
	if err := pack.ExtractTo(folder); err != nil {
		return none, err
	}
	if err := pack.VerifyExtracted(folder.Path()); err != nil {
		return none, err
	}
	for _, file := range m.Files {
		if err := ctx.Err(); err != nil {
			return none, err
		}
		if err := folder.CheckRelativeFile(file.Path); err != nil {
			return none, err
		}
		if strings.EqualFold(filepath.Ext(file.Path), ".exe") {
			if err := release.VerifyExecutable(filepath.Join(folder.Path(), filepath.FromSlash(file.Path)), m.Architecture); err != nil {
				return none, err
			}
		}
	}
	keep = true
	return hubupdate.Release{Stage: stage, Version: m.Version, Revision: m.Revision}, nil
}

// HandOver starts the update helper from this release's own program, detached so it outlives
// this service process. The helper restarts the service and watches any trial's deadline.
func (w *windowsUpdates) HandOver(context.Context, time.Time) error {
	executable, err := os.Executable()
	if err != nil {
		return err
	}
	helper := exec.Command(executable, UpdateHelperCommand)
	helper.Dir = filepath.Dir(executable)
	helper.Env = w.i.environment
	helper.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: windows.DETACHED_PROCESS | windows.CREATE_NEW_PROCESS_GROUP}
	if err := helper.Start(); err != nil {
		return err
	}
	return helper.Process.Release()
}

func (w *windowsUpdates) Report(ctx context.Context, r hubupdate.Report) error {
	args := []string{"--outcome=" + r.Outcome, "--to=" + r.To}
	if r.From != "" {
		args = append(args, "--from="+r.From)
	}
	if r.Detail != "" {
		args = append(args, "--detail="+r.Detail)
	}
	bounded, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	code, err := runChild(bounded, w.i.call("update-report", args...))
	if err != nil || code != 0 {
		return errors.New("the update report did not get through")
	}
	return nil
}

// RemoveStage never removes the running release or the one the service is set to start.
func (w *windowsUpdates) RemoveStage(stage string) error {
	if !stagePattern.MatchString(stage) || strings.EqualFold(stage, w.i.stage) {
		return errInstallation
	}
	if configured, err := configuredStage(); err != nil || strings.EqualFold(configured, stage) {
		return errInstallation
	}
	return os.RemoveAll(filepath.Join(w.i.releases.Path(), stage))
}

func (w *windowsUpdates) Discard(download string) { _ = os.Remove(download) }

// emptyFolder removes the plain files in the downloads folder (it never holds folders).
func emptyFolder(path string) error {
	entries, err := os.ReadDir(path)
	if err != nil {
		return err
	}
	for _, entry := range entries {
		if !entry.Type().IsRegular() {
			return errInstallation
		}
		if err := os.Remove(filepath.Join(path, entry.Name())); err != nil {
			return err
		}
	}
	return nil
}

// RunUpdateHelper is "oblivion-collector.exe update-helper", started by the service when it
// switches releases. It restarts the service; during a trial it then waits for the deadline
// and points the service back at the old release if the new one hasn't checked in. It takes
// no arguments: everything comes from the protected state folder.
func RunUpdateHelper() error {
	records, closeAll, err := openUpdateRecords()
	if err != nil {
		return err
	}
	defer closeAll()
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Hour)
	defer cancel()
	// If the start fails, the deadline check below still switches back and starts again.
	restartErr := restartService(ctx)
	rolledBack, err := hubupdate.Watch(ctx, records, time.Now)
	if rolledBack {
		return restartService(ctx)
	}
	return errors.Join(restartErr, err)
}

// openUpdateRecords opens only the private state folder and the release folders. The service
// holds its own locks while it runs, so the helper doesn't open the full installation.
func openUpdateRecords() (*updateRecords, func(), error) {
	var opened []*securefs.Directory
	closeAll := func() {
		for i := len(opened) - 1; i >= 0; i-- {
			opened[i].Close()
		}
	}
	fail := func() (*updateRecords, func(), error) { closeAll(); return nil, func() {}, errInstallation }
	walk := func(root *windows.KNOWNFOLDERID, private bool, names ...string) *securefs.Directory {
		parent, err := securefs.OpenKnownRoot(root)
		if err != nil {
			return nil
		}
		opened = append(opened, parent)
		for _, name := range names {
			if parent, err = parent.OpenChild(name, private); err != nil {
				return nil
			}
			opened = append(opened, parent)
		}
		return parent
	}
	state := walk(windows.FOLDERID_ProgramData, true, "Oblivion", "Collector")
	if state == nil {
		return fail()
	}
	releases := walk(windows.FOLDERID_ProgramFiles, false, "Oblivion", "Agent", "releases")
	if releases == nil {
		return fail()
	}
	return &updateRecords{state: state, releases: releases}, closeAll, nil
}

// restartService stops the collector service (waiting for it) and starts it again.
func restartService(ctx context.Context) error {
	manager, err := mgr.Connect()
	if err != nil {
		return err
	}
	defer manager.Disconnect()
	service, err := manager.OpenService(Name)
	if err != nil {
		return err
	}
	defer service.Close()
	deadline := time.Now().Add(90 * time.Second)
	for {
		status, err := service.Query()
		if err != nil {
			return err
		}
		if status.State == svc.Stopped {
			break
		}
		if status.State == svc.Running {
			_, _ = service.Control(svc.Stop)
		}
		if time.Now().After(deadline) {
			return errors.New("the collector service did not stop in time")
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(500 * time.Millisecond):
		}
	}
	if err := service.Start(); err != nil && !errors.Is(err, windows.ERROR_SERVICE_ALREADY_RUNNING) {
		return err
	}
	return nil
}
