// Package sensorrelay keeps a hub's sensor relay running beside its monitoring cycles
// (S&D Phase B, decision D21). The relay itself is the PHP command "relay": a small
// publish-only MQTT listener on the house network that only listens while the signed
// configuration names sensor gateway logins.
package sensorrelay

import (
	"context"
	"time"
)

// ExitRemoved is the exit code the hub uses when it was removed in Oblivion.
const ExitRemoved = 3

// Supervise runs the relay, and runs it again with a growing pause (first, doubling up to
// most) whenever it stops. A run that lasted ten minutes resets the pause. It returns when
// ctx ends, or for good when the relay says the hub was removed.
func Supervise(ctx context.Context, run func(context.Context) (uint32, error), first, most time.Duration) {
	if first <= 0 || most < first {
		return
	}
	delay := first
	for ctx.Err() == nil {
		started := time.Now()
		code, err := run(ctx)
		if ctx.Err() != nil {
			return
		}
		if err == nil && code == ExitRemoved {
			return
		}
		if time.Since(started) >= 10*time.Minute {
			delay = first
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(delay):
		}
		if delay < most {
			delay *= 2
			if delay > most {
				delay = most
			}
		}
	}
}
