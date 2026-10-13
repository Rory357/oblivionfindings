package setup

import (
	"context"
	"errors"
	"reflect"
	"testing"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

type repairStartFake struct {
	config mgr.Config
	state  svc.State
	calls  []string
	fail   string
}

func (s *repairStartFake) Config() (mgr.Config, error) { return s.config, nil }
func (s *repairStartFake) UpdateConfig(c mgr.Config) error {
	s.calls = append(s.calls, "startup")
	if s.fail == "startup" {
		return errors.New("configuration denied")
	}
	s.config = c
	return nil
}
func (s *repairStartFake) Query() (svc.Status, error) { return svc.Status{State: s.state}, nil }
func (s *repairStartFake) Start(...string) error {
	s.calls = append(s.calls, "start")
	if s.config.StartType == mgr.StartDisabled {
		return windows.ERROR_SERVICE_DISABLED
	}
	if s.fail == "start" {
		return errors.New("start denied")
	}
	s.state = svc.Running
	return nil
}
func repairStartFixture() (installationService, *repairStartFake) {
	spec := installationService{command: "owned.exe service", description: "owned installation"}
	service := &repairStartFake{config: mgr.Config{
		BinaryPathName: spec.command, Description: spec.description,
		ServiceType: windows.SERVICE_WIN32_OWN_PROCESS, ServiceStartName: "LocalSystem",
		SidType: windows.SERVICE_SID_TYPE_UNRESTRICTED, StartType: mgr.StartDisabled,
	}, state: svc.Stopped}
	return spec, service
}
func TestServiceRepairRestoresDisabledStartupBeforeStartingOwnedService(t *testing.T) {
	spec, service := repairStartFixture()
	err := ensureRegisteredServiceRunning(context.Background(), service, spec, true, func() error {
		service.calls = append(service.calls, "readiness")
		return nil
	})
	if err != nil || !reflect.DeepEqual(service.calls, []string{"startup", "start", "readiness"}) || service.config.StartType != mgr.StartAutomatic || !service.config.DelayedAutoStart || service.state != svc.Running {
		t.Fatal("disabled owned service was not restored", service, err)
	}
}
func TestServiceRepairRunningServiceOnlyRestoresStartup(t *testing.T) {
	spec, service := repairStartFixture()
	service.state = svc.Running
	if err := ensureRegisteredServiceRunning(context.Background(), service, spec, true, func() error { t.Fatal("running service waited"); return nil }); err != nil || !reflect.DeepEqual(service.calls, []string{"startup"}) || service.state != svc.Running {
		t.Fatal("running service interrupted", service, err)
	}
}
func TestServiceRepairDoesNotChangeForeignOrPreCancelledService(t *testing.T) {
	for _, foreign := range []bool{false, true} {
		spec, service := repairStartFixture()
		ctx, cancel := context.WithCancel(context.Background())
		if foreign {
			service.config.Description = "foreign installation"
		} else {
			cancel()
		}
		err := ensureRegisteredServiceRunning(ctx, service, spec, true, func() error { t.Fatal("invalid service waited"); return nil })
		cancel()
		if err == nil || len(service.calls) != 0 {
			t.Fatal("invalid service mutated", service, err)
		}
	}
}
func TestFreshInstallationStillEnablesStartupAfterReadiness(t *testing.T) {
	spec, service := repairStartFixture()
	service.config.StartType = mgr.StartManual
	err := ensureRegisteredServiceRunning(context.Background(), service, spec, false, func() error {
		service.calls = append(service.calls, "readiness")
		if service.config.StartType != mgr.StartManual {
			t.Fatal("fresh install enabled startup too early")
		}
		return nil
	})
	if err != nil || !reflect.DeepEqual(service.calls, []string{"start", "readiness", "startup"}) {
		t.Fatal(service.calls, err)
	}
}

type repairStartHost struct {
	*repairHostFake
	service *repairStartFake
	spec    installationService
}

func (h *repairStartHost) EnsureRunning(ctx context.Context, j Journal) error {
	if j != h.expected {
		return errors.New("installation identity changed")
	}
	return ensureRegisteredServiceRunning(ctx, h.service, h.spec, true, func() error {
		h.service.calls = append(h.service.calls, "readiness")
		if h.service.fail == "readiness" {
			return errors.New("readiness failed")
		}
		return nil
	})
}
func TestServiceRepairStartupFailuresRetainInstallationAndRecordIncompleteOutcome(t *testing.T) {
	for _, step := range []string{"startup", "start", "readiness"} {
		t.Run(step, func(t *testing.T) {
			j, _ := repairFixture("computer")
			spec, service := repairStartFixture()
			service.fail = step
			h := &repairStartHost{repairHostFake: &repairHostFake{expected: j}, service: service, spec: spec}
			receipt, err := RunServiceRepair(context.Background(), j, h)

			if err == nil || receipt.Status != "incomplete" || receipt.Step != "service_start" || h.records[receipt.ID] != receipt || j != h.expected || !h.repairHostFake.service || !h.tray {
				t.Fatal("startup failure lost installed binding or falsely succeeded", receipt, err)
			}
			if step == "startup" && !reflect.DeepEqual(service.calls, []string{"startup"}) {
				t.Fatal("failed startup restoration still started service", service.calls)
			}
			service.fail = ""
			retry, err := RunServiceRepair(context.Background(), j, h)
			if err != nil || retry.Status != "local_services_ready" || retry.ID == receipt.ID || h.records[receipt.ID] != receipt || j != h.expected {
				t.Fatal("retry did not converge with retained evidence", retry, err)
			}
		})
	}
}

func TestFreshInstallationOwnershipChangeDuringReadinessPreventsStartupMutation(t *testing.T) {
	spec, service := repairStartFixture()
	service.config.StartType = mgr.StartManual
	err := ensureRegisteredServiceRunning(context.Background(), service, spec, false, func() error {
		service.config.BinaryPathName = "foreign.exe service"
		return nil
	})
	if err == nil || !reflect.DeepEqual(service.calls, []string{"start"}) {
		t.Fatal("changed ownership was overwritten after readiness", service.calls, err)
	}
}
