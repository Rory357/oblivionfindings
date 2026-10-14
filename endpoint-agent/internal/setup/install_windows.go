package setup

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc/mgr"
	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/collectorhost"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/securefs"
)

// InstallComputer is called only after the setup binary and release have passed
// publisher/Authenticode verification. It never runs a tray process elevated.
func InstallComputer(ctx context.Context, pack *release.Package, profile agent.Bootstrap, confirmedServer string) (Journal, error) {
	return Install(ctx, pack, Profiles{Role: "computer", Computer: &profile}, confirmedServer, "")
}
func Install(ctx context.Context, pack *release.Package, profiles Profiles, confirmedServer, confirmedCollector string) (Journal, error) {
	var zero Journal
	if err := profiles.Validate(confirmedServer, confirmedCollector, time.Now()); err != nil {
		return zero, err
	}
	if pack == nil || !pack.Supports(profiles.Role) {
		return zero, errors.New("the package does not contain the selected installation role")
	}
	if err := securefs.RequireAdministrator(); err != nil {
		return zero, err
	}
	host := &windowsHost{pack: pack, profiles: profiles}
	if profiles.Computer != nil {
		host.profile = *profiles.Computer
	}
	defer host.close()
	dataRoot, err := host.known(windows.FOLDERID_ProgramData)
	if err != nil {
		return zero, err
	}
	organisation, err := host.child(dataRoot, "Oblivion", true)
	if err != nil {
		return zero, err
	}
	host.journals, err = host.child(organisation, "Installer", true)
	if err != nil {
		return zero, err
	}
	lock, err := host.journals.Lock("setup.lock")
	if err != nil {
		return zero, err
	}
	defer lock.Close()
	journal, err := host.loadJournal()
	if err != nil {
		return zero, err
	}
	if journal.RecoveryRequired {
		return journal, errors.New("the previous installation requires administrator recovery")
	}
	if journal.Phase == "installed" {
		return journal, errors.New("Oblivion support is already installed; use the approved repair or update workflow")
	}
	host.manager, err = mgr.Connect()
	if err != nil {
		return journal, errors.New("cannot access Windows Service Control Manager")
	}
	// A foreign service or startup entry is never silently replaced.
	if err = host.preflight(&journal); err != nil {
		return journal, err
	}
	if profiles.HasComputer() {
		host.endpoint, err = host.child(organisation, "Endpoint", true)
		if err != nil {
			return journal, err
		}
	}
	if profiles.HasCollector() {
		host.collector, err = host.child(organisation, "Collector", true)
		if err != nil {
			return journal, err
		}
		host.temporary, err = host.child(host.journals, "Temp", true)
		if err != nil {
			return journal, err
		}
	}
	programRoot, err := host.known(windows.FOLDERID_ProgramFiles)
	if err != nil {
		return journal, err
	}
	programOrganisation, err := host.child(programRoot, "Oblivion", false)
	if err != nil {
		return journal, err
	}
	programAgent, err := host.child(programOrganisation, "Agent", false)
	if err != nil {
		return journal, err
	}
	host.releases, err = host.child(programAgent, "releases", false)
	if err != nil {
		return journal, err
	}
	if err = host.Persist(&journal); err != nil {
		return journal, err
	}
	err = Run(ctx, &journal, host)
	return journal, err
}

type windowsHost struct {
	pack                                *release.Package
	profile                             agent.Bootstrap
	profiles                            Profiles
	collector, temporary                *securefs.Directory
	manager                             *mgr.Mgr
	endpoint, journals, releases, stage *securefs.Directory
	opened                              []*securefs.Directory
}

func (h *windowsHost) close() {
	if h.manager != nil {
		h.manager.Disconnect()
	}
	for i := len(h.opened) - 1; i >= 0; i-- {
		h.opened[i].Close()
	}
}
func (h *windowsHost) known(folder *windows.KNOWNFOLDERID) (*securefs.Directory, error) {
	d, err := securefs.OpenKnownRoot(folder)
	if err == nil {
		h.opened = append(h.opened, d)
	}
	return d, err
}
func (h *windowsHost) child(parent *securefs.Directory, name string, private bool) (*securefs.Directory, error) {
	d, err := parent.EnsureChild(name, private)
	if err == nil {
		h.opened = append(h.opened, d)
	}
	return d, err
}
func nonce() (string, error) {
	value := make([]byte, 16)
	_, err := rand.Read(value)
	return hex.EncodeToString(value), err
}
func (h *windowsHost) loadJournal() (Journal, error) {
	m := h.pack.Manifest()
	expected := Journal{Version: m.Version, Revision: m.Revision, ManifestSHA256: h.pack.Digest(), Architecture: m.Architecture}
	h.profiles.bind(&expected)
	data, err := h.journals.ReadFile("installation.json", 32768)
	if os.IsNotExist(err) {
		attempt, err := nonce()
		if err != nil {
			return Journal{}, err
		}
		expected.Schema = 1
		expected.Attempt = attempt
		expected.Stage = fmt.Sprintf("r-%d-%s", m.Revision, attempt)
		expected.Phase = "planned"
		return expected, nil
	}
	if err != nil {
		return Journal{}, errors.New("installation record requires administrator recovery")
	}
	return decodeJournal(data, expected)
}
func (h *windowsHost) Persist(j *Journal) error {
	j.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	data, err := json.Marshal(j)
	if err != nil {
		return err
	}
	return h.journals.WriteAtomic("installation.json", data)
}
func (h *windowsHost) Stage(ctx context.Context, j *Journal) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if err := h.pack.CheckFresh(time.Now()); err != nil {
		return err
	}
	stage, err := h.child(h.releases, j.Stage, false)
	if err != nil {
		return err
	}
	h.stage = stage
	files, err := os.ReadDir(stage.Path())
	if err != nil {
		return err
	}
	if len(files) > 0 && h.pack.VerifyExtracted(stage.Path()) != nil {
		// Only an attempt interrupted before staging completed may allocate a new
		// inactive stage. Never rewrite a directory referenced by a registered service.
		if j.Phase != "planned" {
			return errors.New("staged release was changed; administrator recovery is required")
		}
		id, err := nonce()
		if err != nil {
			return err
		}
		j.Stage = fmt.Sprintf("r-%d-%s", j.Revision, id)
		if err = h.Persist(j); err != nil {
			return err
		}
		h.stage, err = h.child(h.releases, j.Stage, false)
		if err != nil {
			return err
		}
		files = nil
	}
	if len(files) == 0 {
		if err = h.pack.ExtractTo(h.stage); err != nil {
			return err
		}
	}
	if err = h.pack.VerifyExtracted(h.stage.Path()); err != nil {
		return err
	}
	for _, entry := range h.pack.Manifest().Files {
		if err = h.stage.CheckRelativeFile(entry.Path); err != nil {
			return err
		}
	}
	var executables []string
	if h.profiles.HasComputer() {
		executables = append(executables, "computer/oblivion-agent.exe", "computer/obli.exe")
	}
	if h.profiles.HasCollector() {
		executables = append(executables, "site/oblivion-collector.exe", "site/oblivion-media.exe", "site/runtime/php.exe")
	}
	for _, name := range executables {
		if err = release.VerifyExecutable(filepath.Join(h.stage.Path(), filepath.FromSlash(name)), j.Architecture); err != nil {
			return err
		}
	}
	return nil
}
func (h *windowsHost) Enrol(ctx context.Context, j *Journal) error {
	if err := h.stopOwnedServiceForRetry(ctx, j); err != nil {
		return err
	}
	if h.profiles.HasComputer() {
		if err := h.enrolComputer(ctx); err != nil {
			return err
		}
	}
	if h.profiles.HasCollector() {
		return collectorhost.EnrolInstalled(ctx, h.stage, h.collector, h.temporary, *h.profiles.Collector)
	}
	return nil
}
func (h *windowsHost) enrolComputer(ctx context.Context) error {
	if err := agent.ValidateBootstrap(h.profile, time.Now()); err != nil {
		return err
	}
	store, err := agent.OpenInstalledStore(h.endpoint)
	if err != nil {
		return err
	}
	defer store.Close()
	state, err := store.Load()
	if err == nil && state.AgentUUID != "" {
		if state.AgentUUID != h.profile.AgentUUID || state.ServerURL != h.profile.ServerURL || state.ServerKey != h.profile.ServerKey {
			return errors.New("existing computer identity does not match this installation")
		}
		// Reaffirm a completed lost-reply enrolment with the original credential.
		// The backend rechecks token expiry, assignment, revocation and issuer rights.
		state.AgentUUID = ""
		state.ExpectedAgentUUID = h.profile.AgentUUID
		state.EnrollmentToken = h.profile.Token
	} else {
		if err != nil && !os.IsNotExist(err) {
			return err
		}
		state, err = agent.PrepareBootstrap(store, h.profile)
		if err != nil {
			return err
		}
	}
	client, err := agent.NewClient(state.ServerURL, state.ServerKey)
	if err != nil {
		return err
	}
	if err = client.Enroll(ctx, store, &state, "windows"); err != nil {
		return err
	}
	return h.endpoint.CheckFile("state.json")
}
