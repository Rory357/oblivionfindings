package setup

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc/mgr"
	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/collectorhost"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/securefs"
	"os"
	"path/filepath"
	"strings"
	"time"
)

type windowsRepair struct {
	*windowsHost
	collectorOrigin            string
	recordID                   string
	reviewDigest, reviewExpiry string
	// siteDir is the hub's live release folder when the hub updated itself after setup; nil
	// when the hub part still runs from the recorded folder (h.stage).
	siteDir *securefs.Directory
}

func RepairServices(ctx context.Context, pack *release.Package, server, collectorOrigin, role string) (RepairReceipt, error) {
	return RepairServicesConfirmed(ctx, pack, server, collectorOrigin, role, "", "")
}
func RepairServicesConfirmed(ctx context.Context, pack *release.Package, server, collectorOrigin, role, digest, expiry string) (RepairReceipt, error) {
	h, j, close, err := openRepairHost(pack, server, collectorOrigin, role, false)
	if err != nil {
		return RepairReceipt{}, err
	}
	defer close()
	if (digest == "") != (expiry == "") || digest != "" && !repairDigest.MatchString(digest) {
		return RepairReceipt{}, errors.New("invalid service repair confirmation")
	}
	h.reviewDigest = digest
	h.reviewExpiry = expiry
	return RunServiceRepair(ctx, j, h)
}
func ReviewServiceRepair(ctx context.Context, pack *release.Package) (RepairReview, error) {
	h, j, close, err := openRepairHost(pack, "", "", "", true)
	if err != nil {
		return RepairReview{}, err
	}
	defer close()
	if err = h.ValidateRepair(ctx, j); err != nil {
		return RepairReview{}, errors.New("installed files, identity or registration need administrator review")
	}
	return h.repairReview(j, "")
}
func openRepairHost(pack *release.Package, server, collectorOrigin, role string, discover bool) (*windowsRepair, Journal, func(), error) {
	h := &windowsHost{pack: pack}
	var lock *os.File
	releaseUpdates := func() {}
	close := func() {
		releaseUpdates()
		if lock != nil {
			lock.Close()
		}
		h.close()
	}
	okay := false
	defer func() {
		if !okay {
			close()
		}
	}()
	fail := func(err error) (*windowsRepair, Journal, func(), error) { return nil, Journal{}, func() {}, err }
	if pack == nil {
		return fail(errors.New("service repair requires the matching signed package"))
	}
	if err := securefs.RequireAdministrator(); err != nil {
		return fail(err)
	}
	root, err := h.known(windows.FOLDERID_ProgramData)
	if err != nil {
		return fail(err)
	}
	org, err := h.existingChild(root, "Oblivion", true)
	if err != nil {
		return fail(err)
	}
	h.journals, err = h.existingChild(org, "Installer", true)
	if err != nil {
		return fail(err)
	}
	if err = h.journals.CheckFile("setup.lock"); err != nil {
		return fail(err)
	}
	lock, err = h.journals.Lock("setup.lock")
	if err != nil {
		return fail(err)
	}
	data, err := h.journals.ReadFile("installation.json", 32768)
	if err != nil {
		return fail(errors.New("completed installation record is unavailable"))
	}
	if discover {
		var found Journal
		if json.Unmarshal(data, &found) != nil {
			return fail(errors.New("invalid installed record"))
		}
		role = found.Role
		server = found.ServerURL
	}
	if !pack.Supports(role) || server == "" {
		return fail(errors.New("package and role do not match the installed service"))
	}
	j, err := decodeInstalledRecord(data, server, role)
	if err != nil {
		return fail(err)
	}
	h.profiles = Profiles{Role: role}
	if h.profiles.HasComputer() {
		h.endpoint, err = h.existingChild(org, "Endpoint", true)
		if err != nil {
			return fail(err)
		}
	}
	if h.profiles.HasCollector() {
		h.collector, err = h.existingChild(org, "Collector", true)
		if err != nil {
			return fail(err)
		}
		if discover {
			identity, e := h.collector.ReadFile("collector.identity.json", 16384)
			if e != nil {
				return fail(e)
			}
			var value struct {
				Origin string `json:"central_url"`
			}
			e = json.Unmarshal(identity, &value)
			clear(identity)
			if e != nil || value.Origin == "" {
				return fail(errors.New("collector destination is unavailable"))
			}
			collectorOrigin = value.Origin
		}
	}
	program, err := h.known(windows.FOLDERID_ProgramFiles)
	if err != nil {
		return fail(err)
	}
	parent := program
	for _, name := range []string{"Oblivion", "Agent", "releases"} {
		parent, err = h.existingChild(parent, name, false)
		if err != nil {
			return fail(err)
		}
	}
	h.releases = parent
	h.manager, err = mgr.Connect()
	if err != nil {
		return fail(errors.New("cannot access Windows Service Control Manager"))
	}
	// Which signed package each part must match: the computer part, and a hub part that never
	// updated itself, live in the recorded folder and must be exactly this package. A hub part
	// that updated itself lives in its own folder and must be this package's version.
	m := pack.Manifest()
	live := liveRelease{Stage: j.Stage, Version: j.Version, Revision: j.Revision}
	if h.profiles.HasCollector() {
		releaseUpdates, err = holdHubUpdates(h.collector)
		if err != nil {
			return fail(err)
		}
		if live, err = h.liveCollectorRelease(j); err != nil {
			return fail(err)
		}
	}
	movedSite := live.Stage != j.Stage
	if (h.profiles.HasComputer() || !movedSite) && !recordMatchesPackage(j, m, pack.Digest()) {
		return fail(errors.New("this installer is a different version from the one on this computer; use Update instead of Repair"))
	}
	if movedSite && (live.Version != m.Version || live.Revision != m.Revision) {
		return fail(errors.New("this installer is a different version from the one this hub runs now; use Update instead of Repair"))
	}
	var siteDir *securefs.Directory
	if movedSite {
		if siteDir, err = h.existingChild(h.releases, live.Stage, false); err != nil {
			return fail(err)
		}
		j.siteStage = live.Stage
	}
	if h.profiles.HasComputer() || !movedSite {
		if h.stage, err = h.existingChild(h.releases, j.Stage, false); err != nil {
			return fail(err)
		}
	}
	if h.profiles.HasCollector() && collectorOrigin == "" {
		collectorOrigin = server
	}
	if !h.profiles.HasCollector() && collectorOrigin != "" {
		return fail(errors.New("computer-only installation has no collector transport"))
	}
	okay = true
	return &windowsRepair{windowsHost: h, collectorOrigin: collectorOrigin, siteDir: siteDir}, j, close, nil
}
func (h *windowsRepair) repairReview(j Journal, expiry string) (RepairReview, error) {
	var computerBinding, collectorBinding string
	var err error
	if hasComputer(&j) {
		computerBinding, err = agent.InstalledRepairBinding(h.endpoint, j.AgentUUID, j.ServerURL)
		if err != nil {
			return RepairReview{}, err
		}
	}
	if h.collector != nil {
		data, e := h.collector.ReadFile("collector.identity.json", 16384)
		if e != nil {
			return RepairReview{}, e
		}
		sum := sha256.Sum256(data)
		clear(data)
		collectorBinding = hex.EncodeToString(sum[:])
	}
	computer, err := os.Hostname()
	if err != nil {
		return RepairReview{}, errors.New("this computer's name is unavailable")
	}
	return repairReviewFor(j, h.pack.Manifest(), computer, h.collectorOrigin, computerBinding, collectorBinding, expiry, time.Now())
}
func (h *windowsHost) existingChild(parent *securefs.Directory, name string, private bool) (*securefs.Directory, error) {
	d, err := parent.OpenChild(name, private)
	if err == nil {
		h.opened = append(h.opened, d)
	}
	return d, err
}
func (h *windowsRepair) ValidateRepair(ctx context.Context, j Journal) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if err := h.pack.CheckFresh(time.Now()); err != nil {
		return err
	}
	if err := h.preflight(&j); err != nil {
		return err
	}
	// Never repair a different service or change unsafe existing service ACLs.
	specs, err := selectedServices(&j)
	if err != nil {
		return err
	}
	for _, spec := range specs {
		service, e := h.manager.OpenService(spec.name)
		if errors.Is(e, windows.ERROR_SERVICE_DOES_NOT_EXIST) {
			continue
		}
		if e != nil {
			return e
		}
		e = verifyServiceSecurity(service)
		service.Close()
		if e != nil {
			return e
		}
	}
	// Each folder in use is checked byte for byte against the signed package, with protected
	// permissions and Authenticode on every program, whichever folder the part lives in.
	siteDir := h.stage
	if h.siteDir != nil {
		siteDir = h.siteDir
		if err := h.pack.VerifyExtractedParts(h.siteDir.Path(), "site"); err != nil {
			return err
		}
	}
	if h.stage != nil {
		if err := h.pack.VerifyExtracted(h.stage.Path()); err != nil {
			return err
		}
	}
	for _, file := range h.pack.Manifest().Files {
		if err := ctx.Err(); err != nil {
			return err
		}
		folder := h.stage
		if strings.HasPrefix(file.Path, "site/") && h.siteDir != nil {
			folder = h.siteDir
		}
		if folder == nil {
			continue // a hub-only install that moved: its recorded folder is no longer used
		}
		if err := folder.CheckRelativeFile(file.Path); err != nil {
			return err
		}
		if strings.EqualFold(filepath.Ext(file.Path), ".exe") {
			if err := release.VerifyExecutable(filepath.Join(folder.Path(), filepath.FromSlash(file.Path)), j.Architecture); err != nil {
				return err
			}
		}
	}
	if h.profiles.HasComputer() {
		if err := agent.CheckInstalledRepairIdentity(h.endpoint, j.AgentUUID, j.ServerURL); err != nil {
			return err
		}
	}
	if h.profiles.HasCollector() {
		bounded, cancel := context.WithTimeout(ctx, 20*time.Second)
		defer cancel()
		if err := collectorhost.CheckInstalledRepairIdentity(bounded, siteDir, h.collector, j.CollectorUUID, j.CollectorSiteID, h.collectorOrigin); err != nil {
			return err
		}
	}
	if err := ctx.Err(); err != nil {
		return err
	}
	if h.reviewDigest != "" {
		review, err := h.repairReview(j, h.reviewExpiry)
		if err != nil {
			return err
		}
		if review.ReviewDigest != h.reviewDigest {
			return errors.New("installed details changed after review; review the connection again")
		}
	}
	return nil
}
func (h *windowsRepair) RestoreServices(ctx context.Context, j Journal) error {
	return h.RegisterService(ctx, &j)
}
func (h *windowsRepair) RestoreTray(ctx context.Context, j Journal) error {
	return h.RegisterTray(ctx, &j)
}
func (h *windowsRepair) EnsureRunning(ctx context.Context, j Journal) error {
	return h.startServices(ctx, &j, true)
}
func (h *windowsRepair) SaveRepair(r RepairReceipt) error {
	if !noncePattern.MatchString(r.ID) {
		return errors.New("invalid service repair record")
	}
	data, err := json.Marshal(r)
	if err != nil {
		return err
	}
	name := "repair-" + r.ID + ".json"
	if h.recordID == "" {
		file, err := h.journals.NewFile(name)
		if err != nil {
			return err
		}
		_, writeErr := file.Write(data)
		syncErr := file.Sync()
		closeErr := file.Close()
		if err = errors.Join(writeErr, syncErr, closeErr); err != nil {
			return err
		}
		h.recordID = r.ID
		return nil
	}
	if h.recordID != r.ID {
		return errors.New("service repair record changed")
	}
	return h.journals.WriteAtomic(name, data)
}

// RepairInstalled is the installer's Repair button ("Oblivion is already on this computer").
// Choosing Repair is the confirmation; the destination, role and identity all come from the
// installed record, so nothing new can be supplied. The checks are those of RepairServices.
func RepairInstalled(ctx context.Context, pack *release.Package) (RepairReceipt, error) {
	h, j, close, err := openRepairHost(pack, "", "", "", true)
	if err != nil {
		return RepairReceipt{}, err
	}
	defer close()
	return RunServiceRepair(ctx, j, h)
}
