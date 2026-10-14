package setup

import (
	"context"
	"errors"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
	"oblivion.local/endpoint-agent/internal/collectorhost"
	"oblivion.local/endpoint-agent/internal/eventsource"
	"oblivion.local/endpoint-agent/internal/servicehost"
)

const trayValueName = "Oblivion Obli"
const runKeyPath = "SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run"

type installationService struct{ name, label, executable, command, description string }

func selectedServices(j *Journal) ([]installationService, error) {
	if j.Role != "computer" && j.Role != "site" && j.Role != "both" {
		return nil, errors.New("invalid installation role")
	}
	root, err := windows.KnownFolderPath(windows.FOLDERID_ProgramFiles, windows.KF_FLAG_DEFAULT)
	if err != nil {
		return nil, err
	}
	var specs []installationService
	appendService := func(name, label, component, executable string) {
		path := filepath.Join(root, "Oblivion", "Agent", "releases", j.Stage, component, executable)
		specs = append(specs, installationService{name: name, label: label, executable: path, command: syscall.EscapeArg(path) + " service", description: label + ". Installation " + j.Attempt})
	}
	if j.Role == "computer" || j.Role == "both" {
		appendService(servicehost.Name, "Oblivion computer support", "computer", "oblivion-agent.exe")
	}
	if j.Role == "site" || j.Role == "both" {
		appendService(collectorhost.Name, "Oblivion site monitoring", "site", "oblivion-collector.exe")
	}
	return specs, nil
}
func ownsServiceSpec(service *mgr.Service, spec installationService) bool {
	config, err := service.Config()
	return err == nil && ownsServiceSpecConfig(config, spec)
}
func ownsServiceSpecConfig(config mgr.Config, spec installationService) bool {
	return config.BinaryPathName == spec.command && config.Description == spec.description &&
		config.ServiceType == windows.SERVICE_WIN32_OWN_PROCESS && strings.EqualFold(config.ServiceStartName, "LocalSystem") &&
		config.SidType == windows.SERVICE_SID_TYPE_UNRESTRICTED
}
func hasComputer(j *Journal) bool { return j.Role == "computer" || j.Role == "both" }

func installationPaths(j *Journal) (string, string, error) {
	root, err := windows.KnownFolderPath(windows.FOLDERID_ProgramFiles, windows.KF_FLAG_DEFAULT)
	if err != nil {
		return "", "", err
	}
	directory := filepath.Join(root, "Oblivion", "Agent", "releases", j.Stage, "computer")
	return filepath.Join(directory, "oblivion-agent.exe"), filepath.Join(directory, "obli.exe"), nil
}
func serviceCommand(j *Journal) (string, error) {
	exe, _, err := installationPaths(j)
	return syscall.EscapeArg(exe) + " service", err
}
func trayCommand(j *Journal) (string, error) {
	_, exe, err := installationPaths(j)
	return syscall.EscapeArg(exe) + " --tray", err
}
func ownsService(service *mgr.Service, j *Journal) bool {
	config, err := service.Config()
	if err != nil {
		return false
	}
	expected, err := serviceCommand(j)
	return err == nil && ownsServiceConfig(config, expected, j.Attempt)
}
func ownsServiceConfig(config mgr.Config, expected, attempt string) bool {
	return config.BinaryPathName == expected && config.Description == "Oblivion computer support. Installation "+attempt && config.ServiceType == windows.SERVICE_WIN32_OWN_PROCESS && strings.EqualFold(config.ServiceStartName, "LocalSystem") && config.SidType == windows.SERVICE_SID_TYPE_UNRESTRICTED
}
func readTray() (string, bool, error) {
	key, err := registry.OpenKey(registry.LOCAL_MACHINE, runKeyPath, registry.QUERY_VALUE|registry.WOW64_64KEY)
	if errors.Is(err, windows.ERROR_FILE_NOT_FOUND) {
		return "", false, nil
	}
	if err != nil {
		return "", false, err
	}
	defer key.Close()
	value, kind, err := key.GetStringValue(trayValueName)
	if errors.Is(err, windows.ERROR_FILE_NOT_FOUND) {
		return "", false, nil
	}
	if err != nil || kind != registry.SZ {
		return "", true, errors.New("existing Obli startup entry needs administrator review")
	}
	return value, true, nil
}

func (h *windowsHost) preflight(j *Journal) error {
	specs, err := selectedServices(j)
	if err != nil {
		return err
	}
	for _, spec := range specs {
		source, err := eventsource.New(spec.name, j.Attempt, j.ManifestSHA256)
		if err != nil {
			return err
		}
		if err = eventsource.Preflight(source); err != nil {
			return err
		}
		service, err := h.manager.OpenService(spec.name)
		if err == nil {
			owned := ownsServiceSpec(service, spec)
			service.Close()
			if !owned {
				return errors.New("an existing Windows service does not belong to this installation")
			}
		} else if !errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
			return errors.New("cannot inspect the existing Windows service")
		}
	}
	if !hasComputer(j) {
		return nil
	}
	value, exists, err := readTray()
	if err != nil {
		return err
	}
	expected, err := trayCommand(j)
	if err != nil {
		return err
	}
	if exists && value != expected {
		return errors.New("an existing Obli startup entry does not belong to this installation")
	}
	return nil
}

func (h *windowsHost) RegisterService(ctx context.Context, j *Journal) error {
	specs, err := selectedServices(j)
	if err != nil {
		return err
	}
	for _, spec := range specs {
		source, err := eventsource.New(spec.name, j.Attempt, j.ManifestSHA256)
		if err != nil {
			return err
		}
		if err = eventsource.Ensure(ctx, source); err != nil {
			return err
		}
		if err := h.registerService(ctx, j, spec); err != nil {
			return err
		}
	}
	return nil
}
func (h *windowsHost) registerService(ctx context.Context, j *Journal, spec installationService) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if err := h.pack.CheckFresh(time.Now()); err != nil {
		return err
	}
	service, err := h.manager.OpenService(spec.name)
	if err == nil {
		defer service.Close()
		if !ownsServiceSpec(service, spec) {
			return errors.New("refusing to replace a different Windows service")
		}
		if err = verifyServiceSecurity(service); err != nil {
			return err
		}
		return configureRecovery(service)
	}
	if !errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
		return err
	}
	service, err = h.manager.CreateService(spec.name, spec.executable, mgr.Config{
		DisplayName: spec.label, Description: spec.description,
		ServiceType: windows.SERVICE_WIN32_OWN_PROCESS, StartType: mgr.StartManual, ErrorControl: mgr.ErrorNormal,
		ServiceStartName: "LocalSystem", SidType: windows.SERVICE_SID_TYPE_UNRESTRICTED,
	}, "service")
	if err != nil {
		return err
	}
	defer service.Close()
	// Changes to executable/configuration and service start/stop stay administrative.
	// Normal users can query/interrogate, not start, stop or send custom controls.
	sd, err := windows.SecurityDescriptorFromString(serviceSDDL)
	if err != nil {
		return err
	}
	acl, _, err := sd.DACL()
	if err != nil {
		return err
	}
	if err = windows.SetSecurityInfo(service.Handle, windows.SE_SERVICE, windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION, nil, nil, acl, nil); err != nil {
		return err
	}
	return configureRecovery(service)
}
func configureRecovery(service *mgr.Service) error {
	if err := service.SetRecoveryActions([]mgr.RecoveryAction{{Type: mgr.ServiceRestart, Delay: 30 * time.Second}, {Type: mgr.ServiceRestart, Delay: 2 * time.Minute}, {Type: mgr.NoAction}}, 86400); err != nil {
		return err
	}
	// Explicit identity/report rejection is an administrator-review stop, not a crash loop.
	if err := service.SetRecoveryActionsOnNonCrashFailures(false); err != nil {
		return err
	}
	return verifyServiceSecurity(service)
}
func verifyServiceSecurity(service *mgr.Service) error {
	sd, err := windows.GetSecurityInfo(service.Handle, windows.SE_SERVICE, windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		return err
	}
	return verifyServiceDescriptor(sd)
}

func (h *windowsHost) RegisterTray(ctx context.Context, j *Journal) error {
	if !hasComputer(j) {
		return nil
	}
	if err := ctx.Err(); err != nil {
		return err
	}
	expected, err := trayCommand(j)
	if err != nil {
		return err
	}
	value, exists, err := readTray()
	if err != nil {
		return err
	}
	if exists {
		if value == expected {
			return nil
		}
		return errors.New("refusing to replace another startup entry")
	}
	key, err := registry.OpenKey(registry.LOCAL_MACHINE, runKeyPath, registry.QUERY_VALUE|registry.SET_VALUE|registry.WOW64_64KEY)
	if err != nil {
		return err
	}
	defer key.Close()
	// Windows launches this under the signed-in user's normal session at logon.
	// The privileged installer does not launch or impersonate the user.
	return key.SetStringValue(trayValueName, expected)
}

func (h *windowsHost) StartService(ctx context.Context, j *Journal) error {
	return h.startServices(ctx, j, false)
}
func (h *windowsHost) startServices(ctx context.Context, j *Journal, repair bool) error {
	specs, err := selectedServices(j)
	if err != nil {
		return err
	}
	for _, spec := range specs {
		if err := h.startService(ctx, j, spec, repair); err != nil {
			return err
		}
	}
	return nil
}
func (h *windowsHost) startService(ctx context.Context, j *Journal, spec installationService, repair bool) error {
	source, err := eventsource.New(spec.name, j.Attempt, j.ManifestSHA256)
	if err != nil {
		return err
	}
	if err = eventsource.Check(source); err != nil {
		return err
	}
	if err := ctx.Err(); err != nil {
		return err
	}
	if err := h.pack.CheckFresh(time.Now()); err != nil {
		return err
	}
	service, err := h.manager.OpenService(spec.name)
	if err != nil {
		return err
	}
	defer service.Close()
	if !ownsServiceSpec(service, spec) {
		return errors.New("service ownership changed")
	}
	if err = verifyServiceSecurity(service); err != nil {
		return err
	}
	return ensureRegisteredServiceRunning(ctx, service, spec, repair, func() error {
		return waitForService(ctx, service, svc.Running, 45*time.Second)
	})
}

type registeredService interface {
	Config() (mgr.Config, error)
	UpdateConfig(mgr.Config) error
	Query() (svc.Status, error)
	Start(...string) error
}

// Repair restores startup before Start, which Windows otherwise refuses for a
// disabled service. Fresh installation still enables startup after readiness.
func ensureRegisteredServiceRunning(ctx context.Context, service registeredService, spec installationService, repair bool, wait func() error) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	config, err := service.Config()
	if err != nil {
		return err
	}
	if !ownsServiceSpecConfig(config, spec) {
		return errors.New("service ownership changed")
	}
	restoreStartup := func() error {
		if err := ctx.Err(); err != nil {
			return err
		}
		config, err := service.Config()
		if err != nil {
			return err
		}
		if !ownsServiceSpecConfig(config, spec) {
			return errors.New("service ownership changed")
		}
		config.StartType = mgr.StartAutomatic
		config.DelayedAutoStart = true
		return service.UpdateConfig(config)
	}
	if repair {
		if err := restoreStartup(); err != nil {
			return err
		}
	}
	status, err := service.Query()
	if err != nil {
		return err
	}
	if status.State != svc.Running {
		if err := ctx.Err(); err != nil {
			return err
		}
		if err = service.Start(); err != nil && !errors.Is(err, windows.ERROR_SERVICE_ALREADY_RUNNING) {
			return err
		}
		if err = wait(); err != nil {
			return err
		}
	}
	if repair {
		return nil
	}
	return restoreStartup()
}
func waitForService(ctx context.Context, service *mgr.Service, target svc.State, maximum time.Duration) error {
	bounded, cancel := context.WithTimeout(ctx, maximum)
	defer cancel()
	ticker := time.NewTicker(250 * time.Millisecond)
	defer ticker.Stop()
	for {
		status, err := service.Query()
		if err != nil {
			return err
		}
		if status.State == target {
			return nil
		}
		if target == svc.Running && status.State == svc.Stopped {
			return errors.New("Windows service stopped before readiness")
		}
		select {
		case <-bounded.Done():
			return errors.New("Windows service did not reach the required state in time")
		case <-ticker.C:
		}
	}
}

func (h *windowsHost) Rollback(ctx context.Context, j *Journal) error {
	var problems []error
	if hasComputer(j) {
		expected, err := trayCommand(j)
		if err != nil {
			problems = append(problems, err)
		} else {
			value, exists, err := readTray()
			if err != nil {
				problems = append(problems, err)
			} else if exists {
				if value != expected {
					problems = append(problems, errors.New("startup ownership changed; retained for review"))
				} else {
					key, err := registry.OpenKey(registry.LOCAL_MACHINE, runKeyPath, registry.QUERY_VALUE|registry.SET_VALUE|registry.WOW64_64KEY)
					if err != nil {
						problems = append(problems, err)
					} else {
						if err = key.DeleteValue(trayValueName); err != nil {
							problems = append(problems, err)
						}
						key.Close()
					}
				}
			}
		}
	}
	specs, err := selectedServices(j)
	if err != nil {
		return errors.Join(append(problems, err)...)
	}
	// Attempt cleanup independently for both services, even if the other failed.
	for n := len(specs) - 1; n >= 0; n-- {
		if err := h.rollbackService(ctx, specs[n]); err != nil {
			problems = append(problems, err)
			continue // Keep the logging source while its service may still be active.
		}
		source, err := eventsource.New(specs[n].name, j.Attempt, j.ManifestSHA256)
		if err == nil {
			err = eventsource.Remove(source)
		}
		if err != nil {
			problems = append(problems, err)
		}
	}
	// Identity, spool and immutable release files are intentionally retained.
	return errors.Join(problems...)
}
func (h *windowsHost) rollbackService(ctx context.Context, spec installationService) error {
	service, err := h.manager.OpenService(spec.name)
	if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
		return nil
	}
	if err != nil {
		return err
	}
	defer service.Close()
	if !ownsServiceSpec(service, spec) {
		return errors.New("service ownership changed; retained for administrator review")
	}
	status, err := service.Query()
	if err != nil {
		return err
	}
	if status.State != svc.Stopped {
		if _, err = service.Control(svc.Stop); err != nil && !errors.Is(err, windows.ERROR_SERVICE_NOT_ACTIVE) {
			return err
		}
		if err = waitForService(ctx, service, svc.Stopped, 20*time.Second); err != nil {
			return err
		}
	}
	return service.Delete()
}
