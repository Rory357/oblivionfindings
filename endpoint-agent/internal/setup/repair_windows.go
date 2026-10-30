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
	close := func() {
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
	j, err := decodeRepairInstallation(data, pack.Manifest(), pack.Digest(), server, role)
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
	for _, name := range []string{"Oblivion", "Agent", "releases", j.Stage} {
		parent, err = h.existingChild(parent, name, false)
		if err != nil {
			return fail(err)
		}
	}
	h.stage = parent
	h.manager, err = mgr.Connect()
	if err != nil {
		return fail(errors.New("cannot access Windows Service Control Manager"))
	}
	if h.profiles.HasCollector() && collectorOrigin == "" {
		collectorOrigin = server
	}
	if !h.profiles.HasCollector() && collectorOrigin != "" {
		return fail(errors.New("computer-only installation has no collector transport"))
	}
	okay = true
	return &windowsRepair{windowsHost: h, collectorOrigin: collectorOrigin}, j, close, nil
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
	if err := h.pack.VerifyExtracted(h.stage.Path()); err != nil {
		return err
	}
	for _, file := range h.pack.Manifest().Files {
		if err := ctx.Err(); err != nil {
			return err
		}
		if err := h.stage.CheckRelativeFile(file.Path); err != nil {
			return err
		}
		if strings.EqualFold(filepath.Ext(file.Path), ".exe") {
			if err := release.VerifyExecutable(filepath.Join(h.stage.Path(), filepath.FromSlash(file.Path)), j.Architecture); err != nil {
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
		if err := collectorhost.CheckInstalledRepairIdentity(bounded, h.stage, h.collector, j.CollectorUUID, j.CollectorSiteID, h.collectorOrigin); err != nil {
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
