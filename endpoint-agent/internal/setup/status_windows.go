package setup

import (
	"os"
	"path/filepath"
	"strings"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
	"oblivion.local/endpoint-agent/internal/collectorhost"
	"oblivion.local/endpoint-agent/internal/hubupdate"
	"oblivion.local/endpoint-agent/internal/servicehost"
)

// MachineStatus is what the installer shows before it changes anything: "Oblivion is already
// on this computer. Version 1.3.0, hub for Tōtara House". It needs no administrator: ordinary
// users may query our services, and release folders are readable by everyone.
type MachineStatus struct {
	Installed bool
	Role      string // hub or computer
	Version   string // the hub's live version; empty for a computer (the uninstall entry has it)
	Running   bool
}

func ReadMachineStatus() MachineStatus {
	var status MachineStatus
	manager, err := windows.OpenSCManager(nil, nil, windows.SC_MANAGER_CONNECT)
	if err != nil {
		return status
	}
	defer windows.CloseServiceHandle(manager)
	for _, name := range []string{collectorhost.Name, servicehost.Name} {
		encoded, err := windows.UTF16PtrFromString(name)
		if err != nil {
			continue
		}
		handle, err := windows.OpenService(manager, encoded, windows.SERVICE_QUERY_CONFIG|windows.SERVICE_QUERY_STATUS)
		if err != nil {
			continue
		}
		service := &mgr.Service{Name: name, Handle: handle}
		config, configErr := service.Config()
		state, stateErr := service.Query()
		service.Close()
		if configErr != nil || !strings.HasPrefix(config.Description, "Oblivion ") {
			continue
		}
		status.Installed = true
		running := stateErr == nil && state.State == svc.Running
		if name == collectorhost.Name {
			status.Role = "hub"
			status.Running = running
			status.Version = liveHubVersion(config.BinaryPathName)
			break
		}
		status.Role = "computer"
		status.Running = running
	}
	return status
}

// liveHubVersion reads site\VERSION from the release folder the hub service runs.
func liveHubVersion(command string) string {
	program, err := windows.KnownFolderPath(windows.FOLDERID_ProgramFiles, windows.KF_FLAG_DEFAULT)
	if err != nil {
		return ""
	}
	path, found := strings.CutSuffix(command, " service")
	if !found {
		return ""
	}
	path = strings.Trim(path, `"`)
	relative, err := filepath.Rel(filepath.Join(program, "Oblivion", "Agent", "releases"), path)
	if err != nil {
		return ""
	}
	parts := strings.Split(relative, string(filepath.Separator))
	if len(parts) != 3 || !hubupdate.ValidStage(parts[0]) || parts[1] != "site" || !strings.EqualFold(parts[2], "oblivion-collector.exe") {
		return ""
	}
	file, err := os.Open(filepath.Join(program, "Oblivion", "Agent", "releases", parts[0], "site", "VERSION"))
	if err != nil {
		return ""
	}
	defer file.Close()
	data := make([]byte, 32)
	n, _ := file.Read(data)
	version := strings.TrimSpace(string(data[:n]))
	if !hubupdate.ValidVersion(version) {
		return ""
	}
	return version
}
