package collectorhost

import (
	"context"

	"oblivion.local/endpoint-agent/internal/eventsource"
	"oblivion.local/endpoint-agent/internal/servicehost"
)

const Name = "OblivionCollector"

func Run() error {
	return servicehost.RunManagedWorker(Name, func(ctx context.Context, ready func()) error {
		state, err := openInstalled()
		if err != nil {
			return err
		}
		defer state.Close()
		log, err := eventsource.Open(Name)
		if err != nil {
			return err
		}
		if log != nil {
			defer log.Close()
		}
		report := func(message string) {
			if log != nil {
				_ = log.Info(10, message)
			}
		}
		mediaContext, stopMedia := context.WithCancel(ctx)
		mediaDone := make(chan struct{})
		defer func() { stopMedia(); <-mediaDone }()
		if MediaRelease == "enabled" {
			go func() {
				defer close(mediaDone)
				if state.runMedia(mediaContext) != nil && mediaContext.Err() == nil {
					report("Site media unavailable; viewing sessions require a new authorised start.")
				}
			}()
		} else {
			close(mediaDone)
		}
		err = runCycles(ctx, state, ready, report, productionTimings)
		if err != nil && ctx.Err() == nil && log != nil {
			_ = log.Error(11, "Collector service stopped; approved runtime and private state require review.")
		}
		return err
	})
}
