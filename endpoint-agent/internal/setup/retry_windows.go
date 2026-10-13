package setup

import (
	"context"
	"errors"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

// A crash can leave the new service running before the final journal commit.
// Stop only that same attempt before opening its exclusively locked state.
// Completed installations are rejected before this retry path is reached.

func (h *windowsHost) stopOwnedServiceForRetry(ctx context.Context, j *Journal) error {
	specs, err := selectedServices(j)
	if err != nil {
		return err
	}
	for _, spec := range specs {
		if err := h.stopServiceForRetry(ctx, spec); err != nil {
			return err
		}
	}
	return nil
}
func (h *windowsHost) stopServiceForRetry(ctx context.Context, spec installationService) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	service, err := h.manager.OpenService(spec.name)
	if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
		return nil
	}
	if err != nil {
		return err
	}
	defer service.Close()
	if !ownsServiceSpec(service, spec) {
		return errors.New("refusing to stop a service outside this installation")
	}
	config, err := service.Config()
	if err != nil {
		return err
	}
	config.StartType = mgr.StartManual
	config.DelayedAutoStart = false
	if err = service.UpdateConfig(config); err != nil {
		return err
	}
	status, err := service.Query()
	if err != nil {
		return err
	}
	if status.State == svc.Stopped {
		return nil
	}
	if status.State != svc.StopPending {
		if _, err = service.Control(svc.Stop); err != nil && !errors.Is(err, windows.ERROR_SERVICE_NOT_ACTIVE) {
			return err
		}
	}
	return waitForService(ctx, service, svc.Stopped, 20*time.Second)
}
