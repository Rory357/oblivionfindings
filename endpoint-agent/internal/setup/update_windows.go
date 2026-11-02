package setup

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
	"golang.org/x/sys/windows/svc/mgr"
	"oblivion.local/endpoint-agent/internal/eventsource"
	"oblivion.local/endpoint-agent/internal/hubupdate"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/securefs"
)

// Update is "Update to 1.3.1" when the installer runs on a computer that already has Oblivion
// (windows tab, "Oblivion is already on this computer"). It keeps the computer's identity: the
// signed package is unpacked and checked into a new release folder, the services and Obli's
// startup entry are pointed at it, and the record moves to it. If anything fails, everything
// is pointed back and the new folder goes.
func Update(ctx context.Context, pack *release.Package, progress Progress) (Journal, error) {
	if progress == nil {
		progress = func(string, string, string) {}
	}
	if pack == nil {
		return Journal{}, errors.New("update needs the signed package")
	}
	if err := pack.CheckFresh(time.Now()); err != nil {
		return Journal{}, err
	}
	if err := securefs.RequireAdministrator(); err != nil {
		return Journal{}, err
	}
	h := &windowsHost{pack: pack}
	defer h.close()
	org, journal, unlock, err := h.openInstalled()
	if err != nil {
		return Journal{}, err
	}
	defer unlock()
	if !pack.Supports(journal.Role) || pack.Manifest().Architecture != journal.Architecture {
		return journal, errors.New("this installer doesn't have what this computer runs; download the matching installer")
	}
	h.profiles = Profiles{Role: journal.Role}
	if h.profiles.HasCollector() {
		if h.collector, err = h.existingChild(org, "Collector", true); err != nil {
			return journal, err
		}
	}
	if err = h.openReleases(); err != nil {
		return journal, err
	}
	if h.manager, err = mgr.Connect(); err != nil {
		return journal, errors.New("cannot access Windows Service Control Manager")
	}
	old := journal
	live := liveRelease{Stage: journal.Stage, Version: journal.Version, Revision: journal.Revision}
	if h.profiles.HasCollector() {
		releaseUpdates, err := holdHubUpdates(h.collector)
		if err != nil {
			return journal, err
		}
		defer releaseUpdates()
		if live, err = h.liveCollectorRelease(journal); err != nil {
			return journal, err
		}
		old.siteStage = live.Stage
	}
	m := pack.Manifest()
	parts := []liveRelease{live}
	if h.profiles.HasComputer() && live.Stage != journal.Stage {
		parts = append(parts, liveRelease{Stage: journal.Stage, Version: journal.Version, Revision: journal.Revision})
	}
	newer := false
	for _, part := range parts {
		order := compareRelease(m.Version, m.Revision, part.Version, part.Revision)
		if order < 0 {
			return journal, errors.New("this installer is older than the version on this computer")
		}
		newer = newer || order > 0
	}
	if !newer {
		return journal, errors.New("this computer already has this version")
	}

	progress("staging", "started", "")
	stage, err := h.stageUpdate(ctx, m)
	if err != nil {
		progress("staging", "failed", err.Error())
		return journal, err
	}
	progress("staging", "done", "")
	next := journal
	next.Stage = stage
	next.Version, next.Revision, next.ManifestSHA256 = m.Version, m.Revision, pack.Digest()
	next.UpdatedAt = time.Now().UTC().Format(time.RFC3339)

	progress("switch", "started", "")
	if err = h.switchRelease(ctx, &old, &next); err != nil {
		progress("switch", "failed", err.Error())
		if backErr := h.switchBack(&old, &next); backErr != nil {
			return journal, errors.New("the update failed and the old version could not be restored; administrator review is required")
		}
		_ = os.RemoveAll(filepath.Join(h.releases.Path(), stage))
		return journal, errors.New("the update failed, so this computer went back to version " + live.Version)
	}
	progress("switch", "done", "")
	if h.profiles.HasCollector() {
		h.forgetHubUpdates(old, stage)
	}
	return next, nil
}

// compareRelease orders x.y.z versions, then release revisions.
func compareRelease(version string, revision uint64, otherVersion string, otherRevision uint64) int {
	if order := hubupdate.CompareVersions(version, otherVersion); order != 0 {
		return order
	}
	switch {
	case revision > otherRevision:
		return 1
	case revision < otherRevision:
		return -1
	}
	return 0
}

// openInstalled opens the installation record under its lock. Any completed record is
// accepted against the release it names; callers decide what must match.
func (h *windowsHost) openInstalled() (*securefs.Directory, Journal, func(), error) {
	root, err := h.known(windows.FOLDERID_ProgramData)
	if err != nil {
		return nil, Journal{}, nil, err
	}
	org, err := h.existingChild(root, "Oblivion", true)
	if err != nil {
		return nil, Journal{}, nil, errNotInstalled
	}
	if h.journals, err = h.existingChild(org, "Installer", true); err != nil {
		return nil, Journal{}, nil, errNotInstalled
	}
	lock, err := h.journals.Lock("setup.lock")
	if err != nil {
		return nil, Journal{}, nil, err
	}
	data, err := h.journals.ReadFile("installation.json", 32768)
	if err != nil {
		lock.Close()
		return nil, Journal{}, nil, errNotInstalled
	}
	found, err := peekRecord(data)
	if err != nil {
		lock.Close()
		return nil, Journal{}, nil, err
	}
	j, err := decodeInstalledRecord(data, found.ServerURL, found.Role)
	if err != nil {
		lock.Close()
		return nil, Journal{}, nil, err
	}
	return org, j, func() { lock.Close() }, nil
}

var errNotInstalled = errors.New("Oblivion isn't installed on this computer")

func (h *windowsHost) openReleases() error {
	program, err := h.known(windows.FOLDERID_ProgramFiles)
	if err != nil {
		return err
	}
	parent := program
	for _, name := range []string{"Oblivion", "Agent", "releases"} {
		if parent, err = h.existingChild(parent, name, false); err != nil {
			return err
		}
	}
	h.releases = parent
	return nil
}

// stageUpdate unpacks the package into a new, empty, protected release folder and checks it
// exactly as installation does: every file's bytes, protected permissions and Authenticode.
func (h *windowsHost) stageUpdate(ctx context.Context, m release.Manifest) (string, error) {
	id, err := nonce()
	if err != nil {
		return "", err
	}
	stage := fmt.Sprintf("r-%d-%s", m.Revision, id)
	folder, err := h.child(h.releases, stage, false)
	if err != nil {
		return "", err
	}
	keep := false
	defer func() {
		if !keep {
			_ = os.RemoveAll(folder.Path())
		}
	}()
	if entries, err := os.ReadDir(folder.Path()); err != nil || len(entries) != 0 {
		return "", errors.New("the new release folder isn't empty; administrator review is required")
	}
	if err = h.pack.ExtractTo(folder); err != nil {
		return "", err
	}
	if err = h.pack.VerifyExtracted(folder.Path()); err != nil {
		return "", err
	}
	for _, file := range m.Files {
		if err = ctx.Err(); err != nil {
			return "", err
		}
		if err = folder.CheckRelativeFile(file.Path); err != nil {
			return "", err
		}
		if strings.EqualFold(filepath.Ext(file.Path), ".exe") {
			if err = release.VerifyExecutable(filepath.Join(folder.Path(), filepath.FromSlash(file.Path)), m.Architecture); err != nil {
				return "", err
			}
		}
	}
	keep = true
	return stage, nil
}

// switchRelease stops our services, moves their logging sources, program paths and Obli's
// startup entry to the new folder, saves the record and starts them again. Each service must
// be exactly ours at the old folder first.
func (h *windowsHost) switchRelease(ctx context.Context, old, next *Journal) error {
	oldSpecs, err := selectedServices(old)
	if err != nil {
		return err
	}
	newSpecs, err := selectedServices(next)
	if err != nil {
		return err
	}
	for i, spec := range oldSpecs {
		service, err := h.manager.OpenService(spec.name)
		if err != nil {
			return errors.New("a Windows service is missing; choose Repair first")
		}
		owned := ownsServiceSpec(service, spec)
		security := verifyServiceSecurity(service)
		service.Close()
		if !owned || security != nil {
			return errors.New("an existing Windows service does not belong to this installation")
		}
		if err = h.stopServiceForRetry(ctx, spec); err != nil {
			return err
		}
		if err = moveEventSource(ctx, spec.name, old, next); err != nil {
			return err
		}
		if err = h.repointService(spec, newSpecs[i]); err != nil {
			return err
		}
	}
	if hasComputer(old) {
		if err = moveTray(old, next); err != nil {
			return err
		}
	}
	if err = ensureRelayRule(next); err != nil {
		return err
	}
	if err = h.Persist(next); err != nil {
		return err
	}
	return h.startServices(ctx, next, true)
}

// switchBack undoes switchRelease as far as it got, then starts the old version.
func (h *windowsHost) switchBack(old, next *Journal) error {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()
	oldSpecs, err := selectedServices(old)
	if err != nil {
		return err
	}
	newSpecs, err := selectedServices(next)
	if err != nil {
		return err
	}
	var problems []error
	for i, spec := range newSpecs {
		current := spec
		service, err := h.manager.OpenService(spec.name)
		if err != nil {
			problems = append(problems, err)
			continue
		}
		if !ownsServiceSpec(service, spec) {
			current = oldSpecs[i] // never moved
		}
		service.Close()
		if err = h.stopServiceForRetry(ctx, current); err != nil {
			problems = append(problems, err)
			continue
		}
		if current.command != oldSpecs[i].command {
			if err = h.repointService(current, oldSpecs[i]); err != nil {
				problems = append(problems, err)
			}
		}
		if err = moveEventSource(ctx, spec.name, next, old); err != nil {
			problems = append(problems, err)
		}
	}
	if hasComputer(old) {
		if value, exists, err := readTray(); err == nil && exists {
			if expected, _ := trayCommand(next); value == expected {
				if err = moveTray(next, old); err != nil {
					problems = append(problems, err)
				}
			}
		}
	}
	_ = ensureRelayRule(old) // a firewall problem never blocks going back
	if err = h.Persist(old); err != nil {
		problems = append(problems, err)
	}
	if err = h.startServices(ctx, old, true); err != nil {
		problems = append(problems, err)
	}
	return errors.Join(problems...)
}

// repointService changes only the program path, from exactly ours at the old folder.
func (h *windowsHost) repointService(from, to installationService) error {
	service, err := h.manager.OpenService(from.name)
	if err != nil {
		return err
	}
	defer service.Close()
	config, err := service.Config()
	if err != nil {
		return err
	}
	if !ownsServiceSpecConfig(config, from) {
		return errors.New("service ownership changed")
	}
	config.BinaryPathName = to.command
	return service.UpdateConfig(config)
}

// moveEventSource re-owns a service's logging source for the new release (the owner names the
// signed release). A source that already moved is left alone.
func moveEventSource(ctx context.Context, name string, from, to *Journal) error {
	if from.ManifestSHA256 == to.ManifestSHA256 {
		return nil
	}
	target, err := eventsource.New(name, to.Attempt, to.ManifestSHA256)
	if err != nil {
		return err
	}
	if eventsource.Check(target) == nil {
		return nil
	}
	source, err := eventsource.New(name, from.Attempt, from.ManifestSHA256)
	if err != nil {
		return err
	}
	if err = eventsource.Remove(source); err != nil {
		return err
	}
	return eventsource.Ensure(ctx, target)
}

// moveTray points Obli's startup entry at the new folder, only if it's exactly ours now.
func moveTray(from, to *Journal) error {
	expected, err := trayCommand(from)
	if err != nil {
		return err
	}
	value, exists, err := readTray()
	if err != nil {
		return err
	}
	if !exists || value != expected {
		return errors.New("Obli's startup entry does not belong to this installation; choose Repair first")
	}
	next, err := trayCommand(to)
	if err != nil {
		return err
	}
	key, err := registry.OpenKey(registry.LOCAL_MACHINE, runKeyPath, registry.QUERY_VALUE|registry.SET_VALUE|registry.WOW64_64KEY)
	if err != nil {
		return err
	}
	defer key.Close()
	return key.SetStringValue(trayValueName, next)
}

// forgetHubUpdates tells hubupdate the hub now runs the folder setup recorded. Installed is
// cleared so a later self-update never tidies away setup's own folder (which, on a hub PC,
// also holds Obli). Old hub-only folders are left for hubupdate to tidy after two good cycles.
func (h *windowsHost) forgetHubUpdates(old Journal, stage string) {
	state, err := readHubUpdates(h.collector)
	if err != nil {
		return
	}
	state.Installed = nil
	state.Failure = nil
	notBefore := time.Now().Add(10 * time.Minute)
	if old.collectorStage() != old.Stage && old.collectorStage() != stage {
		state.Cleanups = append(state.Cleanups, hubupdate.Cleanup{Stage: old.collectorStage(), NotBefore: notBefore})
	}
	if old.Role == "site" && old.Stage != stage {
		state.Cleanups = append(state.Cleanups, hubupdate.Cleanup{Stage: old.Stage, NotBefore: notBefore})
	}
	if len(state.Cleanups) > 8 {
		state.Cleanups = state.Cleanups[len(state.Cleanups)-8:]
	}
	_ = saveHubUpdates(h.collector, state)
}

// peekRecord reads the role and destination a record names, before the strict check.
func peekRecord(data []byte) (Journal, error) {
	var found Journal
	if len(data) > 32768 || json.Unmarshal(data, &found) != nil || found.Role == "" || found.ServerURL == "" {
		return Journal{}, errors.New("invalid installed record")
	}
	return found, nil
}
