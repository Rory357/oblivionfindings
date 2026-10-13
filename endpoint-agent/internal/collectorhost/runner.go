// Package collectorhost runs bounded Site monitoring cycles. It does not own
// computer RMM credentials, display staff UI, or decide remote-support consent.
package collectorhost

import (
	"context"
	"errors"
	"time"
)

var errInstallation = errors.New("collector installation is not trusted; administrator review is required")

type cycleExecutor interface {
	Check(context.Context) error
	Cycle(context.Context) (uint32, error)
}
type timings struct{ startup, cycle, interval, maximumBackoff time.Duration }

var productionTimings = timings{20 * time.Second, 10 * time.Minute, time.Minute, 5 * time.Minute}

// Local readiness means the protected runtime can start. Only the collector's
// accepted heartbeat establishes central monitoring health.
func runCycles(ctx context.Context, executor cycleExecutor, ready func(), report func(string), timing timings) error {
	if executor == nil || ready == nil || report == nil || timing.startup <= 0 || timing.cycle <= 0 || timing.interval <= 0 || timing.maximumBackoff < timing.interval {
		return errors.New("collector service configuration is invalid")
	}
	startup, cancel := context.WithTimeout(ctx, timing.startup)
	err := executor.Check(startup)
	startupErr := startup.Err()
	cancel()
	if err != nil || startupErr != nil {
		return errors.New("collector runtime or identity requires administrator review")
	}
	if ctx.Err() != nil {
		return ctx.Err()
	}
	ready()
	report("Collector service ready locally; central monitoring health is not yet confirmed.")
	last := ""
	delay := timing.interval
	for {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		cycle, stop := context.WithTimeout(ctx, timing.cycle)
		code, cycleErr := executor.Cycle(cycle)
		if cycleErr == nil {
			cycleErr = cycle.Err()
		}
		stop()
		if errors.Is(cycleErr, errInstallation) {
			return errInstallation
		}
		if ctx.Err() != nil {
			return ctx.Err()
		}
		state := "Collector cycle failed; retained state will be retried."
		if cycleErr == nil && code == 0 {
			state = "Collector cycle completed."
			delay = timing.interval
		} else if cycleErr == nil && code == 2 {
			state = "Collector upload deferred; buffered observations are retained."
		}
		if state != last {
			report(state)
			last = state
		}
		timer := time.NewTimer(delay)
		select {
		case <-ctx.Done():
			timer.Stop()
			return ctx.Err()
		case <-timer.C:
		}
		if cycleErr != nil || code != 0 {
			delay *= 2
			if delay > timing.maximumBackoff {
				delay = timing.maximumBackoff
			}
		}
	}
}
