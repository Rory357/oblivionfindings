package agent

import (
	"context"
	"errors"
	"testing"
)

func TestUIStatusDoesNotClaimConnectionBeforeDurableAcknowledgement(t *testing.T) {
	store, state := runnerFixture(t)
	monitor := NewStatusMonitor("test")
	monitor.bind("https://care.example.test")
	store.writeProtected = func(string, []byte) error { return errors.New("simulated disk failure") }
	deps := runnerDependencies{
		prepare: func(context.Context, *Store, *State, string) ([]byte, error) { return state.PendingHeartbeat, nil },
		send: func(context.Context, State, []byte) (Acknowledgement, error) {
			return Acknowledgement{AgentUUID: state.AgentUUID, Sequence: state.Sequence, NextSeconds: 60}, nil
		},
		wait: waitFor, monitor: monitor,
	}
	if err := runLoop(context.Background(), store, &state, "test", deps); err == nil {
		t.Fatal("failed durable save accepted")
	}
	status := monitor.Snapshot()
	if status.Connection == "connected" || status.LastAcknowledgedAt != "" || status.AIAvailable || status.RemoteAvailable || status.TicketsAvailable {
		t.Fatal("unproven capabilities or acknowledgement advertised")
	}
}
