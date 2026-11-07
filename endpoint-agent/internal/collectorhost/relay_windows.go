package collectorhost

import (
	"context"
	"time"

	"oblivion.local/endpoint-agent/internal/sensorrelay"
)

// runRelay keeps the hub's sensor relay (S&D Phase B, D21) running beside the cycles. The PHP
// relay only listens while the signed configuration names gateway logins; otherwise it idles.
func (i *installed) runRelay(ctx context.Context) {
	sensorrelay.Supervise(ctx, func(ctx context.Context) (uint32, error) {
		if err := i.validatePrivateState(); err != nil {
			return 0, err
		}
		return runChild(ctx, i.call("relay"))
	}, 5*time.Second, time.Minute)
}
