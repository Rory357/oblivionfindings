package sensorrelay

import (
	"context"
	"errors"
	"testing"
	"time"
)

func TestSuperviseRestartsTheRelayUntilTheHubIsRemoved(t *testing.T) {
	runs := 0
	done := make(chan struct{})
	go func() {
		defer close(done)
		Supervise(context.Background(), func(context.Context) (uint32, error) {
			runs++
			switch runs {
			case 1:
				return 1, nil
			case 2:
				return 0, errors.New("could not start")
			default:
				return ExitRemoved, nil
			}
		}, time.Millisecond, 4*time.Millisecond)
	}()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("Supervise kept running after the hub was removed")
	}
	if runs != 3 {
		t.Fatalf("expected 3 runs, got %d", runs)
	}
}

func TestSuperviseStopsWhenTheServiceStops(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() {
		defer close(done)
		Supervise(ctx, func(ctx context.Context) (uint32, error) {
			<-ctx.Done()
			return 0, ctx.Err()
		}, time.Millisecond, time.Millisecond)
	}()
	cancel()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		t.Fatal("Supervise did not stop with the service")
	}
}
