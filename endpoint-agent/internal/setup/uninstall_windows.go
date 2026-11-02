package setup

import (
	"context"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc/mgr"
	"oblivion.local/endpoint-agent/internal/collectorhost"
	"oblivion.local/endpoint-agent/internal/securefs"
)

// RemoveResult says what Remove did, for the uninstaller's last message.
type RemoveResult struct {
	Role string `json:"role"`
	// ServerTold: Oblivion confirmed it no longer trusts this hub ("hub removed").
	ServerTold bool `json:"server_told"`
	// RestartNeeded: some files were in use and go when Windows next starts.
	RestartNeeded bool `json:"restart_needed"`
}

// Remove is "Uninstall" in Windows Settings › Apps (windows tab E). For a hub it first tells
// Oblivion, which shows "hub removed" and stops trusting it. It then stops and deletes only the
// services, logging sources and startup entry that are exactly this installation's, closes
// Obli, and deletes the installation's files and private state.
//
// Computers: there's no agent call to tell Oblivion a computer was uninstalled, so it just
// goes offline there.
func Remove(ctx context.Context) (RemoveResult, error) {
	var result RemoveResult
	if err := securefs.RequireAdministrator(); err != nil {
		return result, err
	}
	h := &windowsHost{}
	closed := false
	closeAll := func() {
		if !closed {
			closed = true
			h.close()
		}
	}
	defer closeAll()
	org, j, unlock, err := h.openRecord()
	if errors.Is(err, errNotInstalled) {
		return result, nil // nothing of ours left; the uninstaller removes its own files
	}
	if err != nil {
		return result, err
	}
	releaseLock := unlock
	defer func() { releaseLock() }()
	result.Role = j.Role
	h.profiles = Profiles{Role: j.Role}
	if h.manager, err = mgr.Connect(); err != nil {
		return result, errors.New("cannot access Windows Service Control Manager")
	}
	if h.openReleases() != nil {
		h.releases = nil
	}
	releaseUpdates := func() {}
	if h.profiles.HasCollector() {
		if h.collector, err = h.existingChild(org, "Collector", true); err == nil {
			if releaseUpdates, err = holdHubUpdates(h.collector); err != nil {
				return result, err
			}
			live, liveErr := h.collectorReleaseFor(j, true)
			if liveErr != nil {
				releaseUpdates()
				return result, liveErr
			}
			j.siteStage = live.Stage
		}
	}
	// Stop first, so the hub can't check in again after it's been told.
	if err = h.stopOwnedServiceForRetry(ctx, &j); err != nil {
		releaseUpdates()
		return result, err
	}
	if h.collector != nil && h.releases != nil {
		if stage, err := h.existingChild(h.releases, j.collectorStage(), false); err == nil {
			result.ServerTold = collectorhost.DeregisterInstalled(ctx, stage, h.collector) == nil
		}
	}
	// Services, logging sources and Obli's startup entry: only exactly ours, else stop here.
	if err = h.Rollback(ctx, &j); err != nil {
		releaseUpdates()
		return result, errors.New("something on this computer no longer matches this installation, so it was left for IT: " + err.Error())
	}
	releaseUpdates()
	stopObli()
	data, _ := windows.KnownFolderPath(windows.FOLDERID_ProgramData, windows.KF_FLAG_DEFAULT)
	program, _ := windows.KnownFolderPath(windows.FOLDERID_ProgramFiles, windows.KF_FLAG_DEFAULT)
	releaseLock()
	releaseLock = func() {}
	closeAll()
	if data == "" || program == "" {
		return result, errors.New("cannot find the installation folders")
	}
	for _, folder := range []string{
		filepath.Join(data, "Oblivion", "Collector"),
		filepath.Join(data, "Oblivion", "Endpoint"),
		filepath.Join(data, "Oblivion", "Installer"),
		filepath.Join(program, "Oblivion", "Agent"),
	} {
		if removeFolder(folder) {
			result.RestartNeeded = true
		}
	}
	_ = os.Remove(filepath.Join(data, "Oblivion")) // only when empty
	return result, nil
}

// openRecord opens the installation record of any phase (a failed install can be removed too).
func (h *windowsHost) openRecord() (*securefs.Directory, Journal, func(), error) {
	root, err := h.known(windows.FOLDERID_ProgramData)
	if err != nil {
		return nil, Journal{}, nil, err
	}
	org, err := h.existingChild(root, "Oblivion", true)
	if err != nil {
		return nil, Journal{}, nil, errNotInstalled
	}
	if h.journals, err = h.existingChild(org, "Installer", true); err != nil {
		return nil, Journal{}, nil, errNotInstalled
	}
	lock, err := h.journals.Lock("setup.lock")
	if err != nil {
		return nil, Journal{}, nil, err
	}
	data, err := h.journals.ReadFile("installation.json", 32768)
	if os.IsNotExist(err) {
		lock.Close()
		return nil, Journal{}, nil, errNotInstalled
	}
	if err != nil {
		lock.Close()
		return nil, Journal{}, nil, errors.New("installation record requires administrator review")
	}
	found, err := peekRecord(data)
	if err == nil {
		found, err = decodeJournal(data, found)
	}
	if err != nil {
		lock.Close()
		return nil, Journal{}, nil, err
	}
	return org, found, func() { lock.Close() }, nil
}

// stopObli closes Obli for everyone signed in, but only copies running from this
// installation's release folders, so its files can be deleted.
func stopObli() {
	program, err := windows.KnownFolderPath(windows.FOLDERID_ProgramFiles, windows.KF_FLAG_DEFAULT)
	if err != nil {
		return
	}
	releases := strings.ToLower(filepath.Join(program, "Oblivion", "Agent", "releases") + `\`)
	snapshot, err := windows.CreateToolhelp32Snapshot(windows.TH32CS_SNAPPROCESS, 0)
	if err != nil {
		return
	}
	defer windows.CloseHandle(snapshot)
	entry := windows.ProcessEntry32{Size: uint32(unsafe.Sizeof(windows.ProcessEntry32{}))}
	for err = windows.Process32First(snapshot, &entry); err == nil; err = windows.Process32Next(snapshot, &entry) {
		if !strings.EqualFold(windows.UTF16ToString(entry.ExeFile[:]), "obli.exe") {
			continue
		}
		process, err := windows.OpenProcess(windows.PROCESS_QUERY_LIMITED_INFORMATION|windows.PROCESS_TERMINATE|windows.SYNCHRONIZE, false, entry.ProcessID)
		if err != nil {
			continue
		}
		buffer := make([]uint16, windows.MAX_LONG_PATH)
		size := uint32(len(buffer))
		if windows.QueryFullProcessImageName(process, 0, &buffer[0], &size) == nil &&
			strings.HasPrefix(strings.ToLower(windows.UTF16ToString(buffer[:size])), releases) {
			if windows.TerminateProcess(process, 0) == nil {
				_, _ = windows.WaitForSingleObject(process, 5000)
			}
		}
		windows.CloseHandle(process)
	}
}

// removeFolder deletes a folder. Anything still in use is set to go when Windows next starts;
// it returns true when that was needed.
func removeFolder(folder string) bool {
	info, err := os.Lstat(folder)
	if os.IsNotExist(err) {
		return false
	}
	if err != nil || !info.IsDir() || info.Mode()&(os.ModeSymlink|os.ModeIrregular) != 0 {
		return false
	}
	if os.RemoveAll(folder) == nil {
		return false
	}
	var leftovers []string
	_ = filepath.WalkDir(folder, func(path string, entry fs.DirEntry, err error) error {
		if err == nil {
			leftovers = append(leftovers, path)
		}
		if err == nil && entry.Type()&(fs.ModeSymlink|fs.ModeIrregular) != 0 {
			return fs.SkipDir
		}
		return nil
	})
	// Deepest first, so each folder is empty by the time Windows reaches it.
	sort.Slice(leftovers, func(a, b int) bool { return len(leftovers[a]) > len(leftovers[b]) })
	for _, path := range leftovers {
		if name, err := windows.UTF16PtrFromString(path); err == nil {
			_ = windows.MoveFileEx(name, nil, windows.MOVEFILE_DELAY_UNTIL_REBOOT)
		}
	}
	return true
}
