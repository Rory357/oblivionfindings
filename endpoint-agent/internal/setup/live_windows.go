package setup

import (
	"errors"
	"os"
	"time"

	"golang.org/x/sys/windows"
	"oblivion.local/endpoint-agent/internal/collectorhost"
	"oblivion.local/endpoint-agent/internal/hubupdate"
	"oblivion.local/endpoint-agent/internal/securefs"
)

// A hub updates itself overnight (internal/hubupdate, D3): it unpacks the new release into its
// own folder under releases\ and points the hub service at it. The installation record still
// names the folder setup made. Repair, Update and Remove find the live folder here.

// liveRelease is the release the hub service runs from.
type liveRelease struct {
	Stage    string
	Version  string
	Revision uint64
}

var errUpdateOnTrial = errors.New("an update is being tried on this hub right now; try again in 10 minutes")

// holdHubUpdates takes the hub's update lock, so the service can't switch releases while
// setup looks or changes things. The service waits up to 30 seconds for it, then carries on
// without updating that cycle.
func holdHubUpdates(collector *securefs.Directory) (func(), error) {
	deadline := time.Now().Add(45 * time.Second)
	for {
		lock, err := collector.Lock("hub-update.lock")
		if err == nil {
			return func() { lock.Close() }, nil
		}
		if time.Now().After(deadline) {
			return nil, errors.New("the hub is busy updating; try again in a few minutes")
		}
		time.Sleep(500 * time.Millisecond)
	}
}

// readHubUpdates reads the hub's update record (hub-update.json). A missing record means the
// hub never updated itself.
func readHubUpdates(collector *securefs.Directory) (hubupdate.State, error) {
	data, err := collector.ReadFile(hubupdate.StateFile, 64*1024)
	if os.IsNotExist(err) {
		return hubupdate.DecodeState(nil)
	}
	if err != nil {
		return hubupdate.State{}, err
	}
	return hubupdate.DecodeState(data)
}

func saveHubUpdates(collector *securefs.Directory, state hubupdate.State) error {
	data, err := state.Encode()
	if err != nil {
		return err
	}
	return collector.WriteAtomic(hubupdate.StateFile, data)
}

// liveCollectorRelease works out which release folder the hub service runs from. The caller
// holds the update lock. A folder other than the recorded one is trusted only when:
//   - no update is on trial;
//   - hubupdate's record says it installed that folder; and
//   - the service, if registered, is exactly ours pointing at it (program path, description
//     with this installation's attempt, LocalSystem), the same ownership test as everywhere else.
func (h *windowsHost) liveCollectorRelease(j Journal) (liveRelease, error) {
	return h.collectorReleaseFor(j, false)
}

// collectorReleaseFor is liveCollectorRelease; Remove passes duringTrial so a hub can be removed
// while an update is on trial (either side of the trial is then accepted).
func (h *windowsHost) collectorReleaseFor(j Journal, duringTrial bool) (liveRelease, error) {
	recorded := liveRelease{Stage: j.Stage, Version: j.Version, Revision: j.Revision}
	state, err := readHubUpdates(h.collector)
	if err != nil {
		return liveRelease{}, errors.New("the hub's update record can't be read; administrator review is required")
	}
	if state.Pending != nil && !duringTrial {
		return liveRelease{}, errUpdateOnTrial
	}
	candidates := []*liveRelease{&recorded}
	var updated *liveRelease
	if state.Installed != nil && state.Installed.Stage != j.Stage {
		updated = &liveRelease{Stage: state.Installed.Stage, Version: state.Installed.Version, Revision: state.Installed.Revision}
		candidates = append(candidates, updated)
	}
	if state.Pending != nil {
		for _, r := range []hubupdate.Release{state.Pending.To, state.Pending.From} {
			candidates = append(candidates, &liveRelease{Stage: r.Stage, Version: r.Version, Revision: r.Revision})
		}
	}
	service, err := h.manager.OpenService(collectorhost.Name)
	if errors.Is(err, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
		// Repair re-registers a missing service at the release the hub last installed.
		if updated != nil {
			return *updated, nil
		}
		return recorded, nil
	}
	if err != nil {
		return liveRelease{}, errors.New("cannot inspect the hub service")
	}
	defer service.Close()
	config, err := service.Config()
	if err != nil {
		return liveRelease{}, errors.New("cannot inspect the hub service")
	}
	for _, candidate := range candidates {
		probe := j
		probe.siteStage = candidate.Stage
		specs, err := selectedServices(&probe)
		if err != nil {
			return liveRelease{}, err
		}
		for _, spec := range specs {
			if spec.name == collectorhost.Name && ownsServiceSpecConfig(config, spec) {
				return *candidate, nil
			}
		}
	}
	return liveRelease{}, errors.New("the hub service doesn't point at a release this installation knows; administrator review is required")
}
