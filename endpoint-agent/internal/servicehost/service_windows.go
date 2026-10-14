// Package servicehost adapts the non-interactive agent to the Windows service manager.
// It never displays UI or grants remote-control consent on behalf of a signed-in user.
package servicehost

import (
	"context"
	"errors"
	"path/filepath"
	"sync"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/eventsource"
	"oblivion.local/endpoint-agent/internal/localui"
)

const Name = "OblivionAgent"

// Default-off release gate; never enabled by host environment or staff configuration.
var ServiceActionsRelease = "disabled"
var RestartsRelease = "disabled"
var SoftwareRelease = "disabled"

func StateDirectory() (string, error) {
	root, err := windows.KnownFolderPath(windows.FOLDERID_ProgramData, windows.KF_FLAG_DEFAULT)
	if err != nil {
		return "", errors.New("cannot locate the Windows application data directory")
	}
	return filepath.Join(root, "Oblivion", "Endpoint"), nil
}

// Run is entered by SCM, never by clicking the helper. Registration and signing
// belong to the installer; this command does not create or start a service.
func Run(version string) error {
	isService, err := svc.IsWindowsService()
	if err != nil || !isService {
		return errors.New("service mode must be started by Windows Service Control Manager")
	}
	log, err := eventsource.Open(Name)
	if err != nil {
		return err
	}
	defer log.Close()
	dir, releaseState, err := holdInstalledState()
	if err != nil {
		_ = log.Error(2, "Computer support could not open its protected installation state; administrator review is required.")
		return err
	}
	defer releaseState()
	report := func(message string) {
		if log != nil {
			_ = log.Info(1, message)
		}
	}
	var actionsMu sync.RWMutex
	var currentActions *agent.ServiceActionController
	var currentRestarts *agent.RestartController
	var currentSoftware *agent.SoftwareController
	handler := &serviceHandler{
		sessionChange: func() {
			actionsMu.RLock()
			current := currentActions
			restart := currentRestarts
			software := currentSoftware
			actionsMu.RUnlock()
			if software != nil {
				software.Invalidate()
			}
			if restart != nil {
				restart.InvalidateConsent()
			}
			if current != nil {
				current.InvalidateConsent()
			}
		},
		worker: func(ctx context.Context, ready func()) error {
			monitor := agent.NewStatusMonitor(version)
			monitor.SetRemote(agent.NewRemoteController(localui.ValidateRemoteDesktop))
			if ServiceActionsRelease == "enabled" {
				controller := agent.NewWindowsServiceActions(localui.ValidateRemoteDesktop)
				monitor.SetServiceActions(controller)
				actionsMu.Lock()
				currentActions = controller
				actionsMu.Unlock()
				defer func() { controller.InvalidateConsent(); actionsMu.Lock(); currentActions = nil; actionsMu.Unlock() }()
			}
			{ // Installed recovery remains available when fresh execution is withdrawn.
				controller := agent.NewWindowsRestarts(localui.ValidateRemoteDesktop, RestartsRelease == "enabled")
				monitor.SetRestarts(controller)
				actionsMu.Lock()
				currentRestarts = controller
				actionsMu.Unlock()
				defer func() { controller.InvalidateConsent(); actionsMu.Lock(); currentRestarts = nil; actionsMu.Unlock() }()
			}
			{
				controller := agent.NewWindowsSoftware(localui.ValidateRemoteDesktop, SoftwareRelease == "enabled")
				monitor.SetSoftware(controller)
				actionsMu.Lock()
				currentSoftware = controller
				actionsMu.Unlock()
				defer func() { controller.Invalidate(); actionsMu.Lock(); currentSoftware = nil; actionsMu.Unlock() }()
			}
			workerContext, cancel := context.WithCancel(ctx)
			defer cancel()
			server, err := localui.StartWithSoftware(workerContext, monitor.Snapshot, monitor.Remote, monitor.TicketComputer, monitor.ServiceAction, monitor.Restart, monitor.Software, monitor.Diagnostics)
			if err != nil {
				return err
			}
			defer server.Close()
			desktopServer, err := localui.StartDesktop(workerContext, monitor.Desktop)
			if err != nil {
				return err
			}
			defer desktopServer.Close()
			stopped := make(chan struct{})
			defer close(stopped)
			go func() {
				select {
				case <-server.Done():
					cancel()
				case <-desktopServer.Done():
					cancel()
				case <-stopped:
				}
			}()
			err = agent.RunInstalled(workerContext, dir, version, ready, report, monitor)
			if ctx.Err() == nil && workerContext.Err() != nil {
				return errors.New("local helper channel stopped")
			}
			return err
		},
		reportState: func(state string) {
			if state == "ready" {
				_ = log.Info(1, "Computer support is ready locally; confirm connection and reporting in Oblivion.")
			}
			if state == "stopped" {
				_ = log.Info(1, "Computer support stopped after a Windows stop or shutdown request.")
			}
		},
		stopTimeout:  15 * time.Second,
		startTimeout: 30 * time.Second,
		reportFailure: func() {
			if log != nil {
				_ = log.Error(2, "Oblivion agent stopped. Administrator review of installation state and connectivity is required.")
			}
		},
	}
	return svc.Run(Name, handler)
}

type serviceHandler struct {
	sessionChange func()
	worker        func(context.Context, func()) error
	stopTimeout   time.Duration
	startTimeout  time.Duration
	reportFailure func()
	reportState   func(string)
}

func (h *serviceHandler) Execute(_ []string, requests <-chan svc.ChangeRequest, statuses chan<- svc.Status) (bool, uint32) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	status := svc.Status{State: svc.StartPending, CheckPoint: 1, WaitHint: uint32(h.startTimeout / time.Millisecond)}
	statuses <- status
	ready := make(chan struct{})
	done := make(chan error, 1)
	var once sync.Once
	go func() { done <- h.worker(ctx, func() { once.Do(func() { close(ready) }) }) }()
	startup := time.NewTimer(h.startTimeout)
	defer startup.Stop()
	fail := func() (bool, uint32) {
		if h.reportFailure != nil {
			h.reportFailure()
		}
		return true, 1
	}
	stop := func(requested bool) (bool, uint32) {
		cancel()
		stopping := svc.Status{State: svc.StopPending, CheckPoint: 1, WaitHint: uint32(h.stopTimeout / time.Millisecond)}
		statuses <- stopping
		deadline := time.NewTimer(h.stopTimeout)
		defer deadline.Stop()
		checkpoint := time.NewTicker(time.Second)
		defer checkpoint.Stop()
		for {
			select {
			case err := <-done:
				if err != nil && !errors.Is(err, context.Canceled) {
					return fail()
				}
				if requested && h.reportState != nil {
					h.reportState("stopped")
				}
				return false, 0
			case <-deadline.C:
				return fail()
			case <-checkpoint.C:
				stopping.CheckPoint++
				statuses <- stopping
			}
		}
	}
	for {
		select {
		case <-ready:
			ready = nil
			startup.Stop()
			status = svc.Status{State: svc.Running, Accepts: svc.AcceptStop | svc.AcceptShutdown}
			if h.sessionChange != nil {
				status.Accepts |= svc.AcceptSessionChange
			}
			statuses <- status
			if h.reportState != nil {
				h.reportState("ready")
			}
		case <-startup.C:
			// A stuck startup gets the same bounded cancellation as a service stop.
			if specific, code := stop(false); code != 0 {
				return specific, code
			}
			return fail()
		case <-done:
			// A worker exiting without a stop request must not appear healthy.
			return fail()
		case request, ok := <-requests:
			if !ok {
				return stop(false)
			}
			switch request.Cmd {
			case svc.SessionChange:
				if h.sessionChange != nil {
					h.sessionChange()
				}
			case svc.Interrogate:
				statuses <- status
			case svc.Stop, svc.Shutdown:
				return stop(true)
			}
		}
	}
}

// RunManagedWorker shares the bounded SCM lifecycle with the fixed collector
// service. It never installs a service or accepts an executable/command path.
func RunManagedWorker(name string, worker func(context.Context, func()) error) error {
	if name != "OblivionCollector" || worker == nil {
		return errors.New("unsupported managed service")
	}
	isService, err := svc.IsWindowsService()
	if err != nil || !isService {
		return errors.New("service mode must be started by Windows Service Control Manager")
	}
	log, err := eventsource.Open(name)
	if err != nil {
		return err
	}
	if log != nil {
		defer log.Close()
	}
	reportFailure := func() {
		if log != nil {
			_ = log.Error(11, "Collector service failed to start or stop cleanly; administrator review is required.")
		}
	}
	reportState := func(state string) {
		if state == "ready" {
			_ = log.Info(10, "Site monitoring is ready locally; central monitoring health is not yet confirmed.")
		}
		if state == "stopped" {
			_ = log.Info(10, "Site monitoring stopped after a Windows stop or shutdown request.")
		}
	}
	return svc.Run(name, &serviceHandler{worker: worker, stopTimeout: 15 * time.Second, startTimeout: 30 * time.Second, reportFailure: reportFailure, reportState: reportState})
}
