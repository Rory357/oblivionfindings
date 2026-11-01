// Package collectorhost runs bounded Site monitoring cycles. It does not own
// computer RMM credentials, display staff UI, or decide remote-support consent.
package collectorhost

import (
	"context"
	"errors"
	"time"

	"oblivion.local/endpoint-agent/internal/hubupdate"
)

var errInstallation = errors.New("collector installation is not trusted; administrator review is required")

// errRemoved: the hub was removed in Oblivion and has wiped its saved logins (S&D Phase B).
// The service stops for good; uninstalling Oblivion is the next step on this computer.
var errRemoved = errors.New("this hub was removed in Oblivion; its saved logins have been wiped")

// Exit codes from the PHP hub's collect cycle (collector/src/CollectorApplication.php).
const (
	cycleComplete       = 0
	cycleDeferred       = 2
	cycleRemoved        = 3
	cycleWaitingApprove = 4
)

type cycleExecutor interface {
	Check(context.Context) error
	Cycle(context.Context) (uint32, error)
}
type timings struct{ startup, cycle, interval, maximumBackoff time.Duration }

var productionTimings = timings{20 * time.Second, 10 * time.Minute, time.Minute, 5 * time.Minute}

// updateHook is the hub's self-update (hubupdate.Updater, D3). It may be nil.
type updateHook interface {
	AfterCycle(context.Context, uint32, error) hubupdate.Action
	StartupFailed(context.Context) hubupdate.Action
}

// Local readiness means the protected runtime can start. Only the collector's
// accepted heartbeat establishes central monitoring health.
func runCycles(ctx context.Context, executor cycleExecutor, ready func(), report func(string), timing timings, updates updateHook) error {
	if executor == nil || ready == nil || report == nil || timing.startup <= 0 || timing.cycle <= 0 || timing.interval <= 0 || timing.maximumBackoff < timing.interval {
		return errors.New("collector service configuration is invalid")
	}
	startup, cancel := context.WithTimeout(ctx, timing.startup)
	err := executor.Check(startup)
	startupErr := startup.Err()
	cancel()
	if err != nil || startupErr != nil {
		if updates != nil && ctx.Err() == nil {
			// A new release on trial that can't start goes straight back to the old one.
			updates.StartupFailed(ctx)
		}
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
		if cycleErr == nil && code == cycleRemoved {
			report("This hub was removed in Oblivion. Its saved logins have been wiped. Uninstall Oblivion from this computer.")
			return errRemoved
		}
		state := "Collector cycle failed; retained state will be retried."
		waiting := cycleErr == nil && code == cycleWaitingApprove
		if cycleErr == nil && code == cycleComplete {
			state = "Collector cycle completed."
			delay = timing.interval
		} else if cycleErr == nil && code == cycleDeferred {
			state = "Collector upload deferred; buffered observations are retained."
		} else if waiting {
			// Connected and waiting: check again each interval, without backing off.
			state = "Connected. Waiting for an admin to approve this hub in Oblivion."
			delay = timing.interval
		}
		if state != last {
			report(state)
			last = state
		}
		if updates != nil {
			// Restart needs nothing here: the update helper stops this service and starts
			// it again from the new release. Until then cycles carry on as normal.
			if updates.AfterCycle(ctx, code, cycleErr) == hubupdate.Wait {
				// A release is on trial: check again at the normal pace, without backing off.
				waiting = true
				delay = timing.interval
			}
		}
		timer := time.NewTimer(delay)
		select {
		case <-ctx.Done():
			timer.Stop()
			return ctx.Err()
		case <-timer.C:
		}
		if !waiting && (cycleErr != nil || code != cycleComplete) {
			delay *= 2
			if delay > timing.maximumBackoff {
				delay = timing.maximumBackoff
			}
		}
	}
}
