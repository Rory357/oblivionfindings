package agent

import (
	"context"
	"errors"
	"time"
)

var ErrReviewRequired = errors.New("Oblivion rejected the agent identity or report; administrator review is required")

type runnerDependencies struct {
	prepare func(context.Context, *Store, *State, string) ([]byte, error)
	send    func(context.Context, State, []byte) (Acknowledgement, error)
	wait    func(context.Context, time.Duration) error
	report  func(string)
	monitor *StatusMonitor
}

func waitFor(ctx context.Context, delay time.Duration) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if delay <= 0 {
		return nil
	}
	timer := time.NewTimer(delay)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C:
		return nil
	}
}

// Run owns the state lock until the background loop has stopped. Logs contain no
// URL, inventory, token, provider content or upstream error text.
func Run(ctx context.Context, dir, version string, ready func(), report func(string)) error {
	store, err := OpenStore(dir)
	if err != nil {
		return err
	}
	defer store.Close()
	return runFromStore(ctx, store, version, ready, report, nil)
}

func runFromStore(ctx context.Context, store *Store, version string, ready func(), report func(string), monitor *StatusMonitor) error {
	state, err := store.Load()
	if err != nil {
		return err
	}
	if state.AgentUUID == "" {
		return errors.New("complete agent enrolment before starting")
	}
	client, err := NewClient(state.ServerURL, state.ServerKey)
	if err != nil {
		return err
	}
	if monitor != nil {
		monitor.bind(state.ServerURL)
		proofCtx, cancelProof := context.WithCancel(ctx)
		defer cancelProof()
		defer monitor.bindTicketComputer(proofCtx, client, state.AgentUUID, state.Credential)()
	}
	if monitor != nil && monitor.remoteController() != nil {
		remote := monitor.remoteController()
		remote.bind(state.ServerURL, remoteTransport{client: client, agent: state.AgentUUID, credential: state.Credential})
		remoteCtx, cancel := context.WithCancel(ctx)
		done := make(chan struct{})
		go func() { defer close(done); remote.Run(remoteCtx, monitor.remoteConnected) }()
		defer func() { cancel(); <-done }()
	}
	if monitor != nil && monitor.softwareController() != nil {
		stopSoftware, softwareErr := monitor.softwareController().bind(ctx, store, client, state, monitor.remoteConnected)
		if softwareErr != nil {
			return softwareErr
		}
		defer stopSoftware()
	}
	if monitor != nil && monitor.restartController() != nil {
		stopRestart, restartErr := monitor.restartController().bind(ctx, store, client, state, monitor.remoteConnected)
		if restartErr != nil {
			return restartErr
		}
		defer stopRestart()
	}
	if monitor != nil && monitor.serviceActionController() != nil {
		stopActions, actionErr := monitor.serviceActionController().bind(ctx, store, client, state, monitor.remoteConnected)
		if actionErr != nil {
			return actionErr
		}
		defer stopActions()
	}
	if ready != nil {
		ready()
	}
	return runLoop(ctx, store, &state, version, runnerDependencies{prepare: PrepareHeartbeat, send: client.Heartbeat, wait: waitFor, report: report, monitor: monitor})
}

func runLoop(ctx context.Context, store *Store, state *State, version string, deps runnerDependencies) error {
	delay := time.Duration(0)
	for {
		if err := deps.wait(ctx, delay); err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return err
		}
		payload, err := deps.prepare(ctx, store, state, version)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			return err
		}
		ack, err := deps.send(ctx, *state, payload)
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			var response *HTTPError
			if errors.As(err, &response) && (response.Status == 401 || response.Status == 403 || response.Status == 404 || response.Status == 409 || response.Status == 422) {
				if deps.monitor != nil {
					deps.monitor.connection("review_required")
				}
				return ErrReviewRequired
			}
			if deps.monitor != nil {
				deps.monitor.connection("retrying")
			}
			if deps.report != nil {
				deps.report("Agent connection unavailable; the next report will be retried.")
			}
			if delay < 30*time.Second {
				delay = 30 * time.Second
			} else {
				delay *= 2
			}
			if delay > 5*time.Minute {
				delay = 5 * time.Minute
			}
			continue
		}
		// An acknowledgement is checked at the loop boundary as well as the transport.
		if ack.AgentUUID != state.AgentUUID || ack.Sequence != state.Sequence || ack.NextSeconds < 30 || ack.NextSeconds > 300 {
			return errors.New("invalid heartbeat acknowledgement")
		}
		if validateSoftwareACK(payload, ack.SoftwareAccepted) != nil {
			return errors.New("invalid software acknowledgement")
		}
		if validateRestartACK(payload, ack.RestartAccepted) != nil {
			return errors.New("invalid restart acknowledgement")
		}
		if _, e := acceptedServiceObservations(payload, ack.ServiceObservationsAccepted); e != nil {
			return errors.New("invalid service observation acknowledgement")
		}
		next := *state
		next.PendingHeartbeat = nil
		if err = store.Save(next); err != nil {
			return err
		}
		*state = next
		if deps.monitor != nil {
			deps.monitor.observedDiagnostics(payload)
			if software := deps.monitor.softwareController(); software != nil {
				software.acknowledge(payload, ack.SoftwareAccepted)
			}
			if restart := deps.monitor.restartController(); restart != nil {
				restart.acknowledge(payload, ack.RestartAccepted)
			}
			if actions := deps.monitor.serviceActionController(); actions != nil {
				actions.acknowledgeObservations(payload, ack.ServiceObservationsAccepted)
			}
			deps.monitor.connection("connected")
		}
		delay = time.Duration(ack.NextSeconds) * time.Second
	}
}
