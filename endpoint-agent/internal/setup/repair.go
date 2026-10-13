package setup

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"oblivion.local/endpoint-agent/internal/release"
	"regexp"
	"time"
)

var repairUUID = regexp.MustCompile("^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$")
var repairDigest = regexp.MustCompile("^[a-f0-9]{64}$")

// decodeRepairInstallation uses the protected receipt, not a new enrolment grant.
// It still requires the exact currently valid publisher archive and destination.
func decodeRepairInstallation(data []byte, m release.Manifest, digest, server, role string) (Journal, error) {
	var found Journal
	if len(data) > 32768 || json.Unmarshal(data, &found) != nil {
		return Journal{}, errors.New("installed record requires administrator review")
	}
	var fields map[string]json.RawMessage
	if json.Unmarshal(data, &fields) != nil {
		return Journal{}, errors.New("invalid installed record")
	}
	allowed := map[string]bool{"schema": true, "attempt": true, "stage": true, "version": true, "revision": true, "manifest_sha256": true, "architecture": true, "role": true, "server_url": true, "agent_uuid": true, "collector_uuid": true, "collector_site_id": true, "collector_profile_hash": true, "phase": true, "failed_step": true, "recovery_required": true, "updated_at": true}
	for name, value := range fields {
		if !allowed[name] || bytes.Equal(bytes.TrimSpace(value), []byte("null")) {
			return Journal{}, errors.New("invalid installed record field")
		}
	}
	for _, name := range []string{"schema", "attempt", "stage", "version", "revision", "manifest_sha256", "architecture", "role", "server_url", "agent_uuid", "phase", "recovery_required", "updated_at"} {
		if _, ok := fields[name]; !ok {
			return Journal{}, errors.New("incomplete installed record")
		}
	}
	expected := found
	expected.Version = m.Version
	expected.Revision = m.Revision
	expected.ManifestSHA256 = digest
	expected.Architecture = m.Architecture
	expected.ServerURL = server
	expected.Role = role
	j, err := decodeJournal(data, expected)
	if err != nil {
		return Journal{}, err
	}
	if j.Phase != "installed" || j.FailedStep != "" || j.RecoveryRequired || !repairDigest.MatchString(j.ManifestSHA256) ||
		(role != "computer" && role != "site" && role != "both") || server == "" {
		return Journal{}, errors.New("service repair requires a completed matching installation")
	}
	computer := role == "computer" || role == "both"
	collector := role == "site" || role == "both"
	if computer && !repairUUID.MatchString(j.AgentUUID) || !computer && j.AgentUUID != "" ||
		collector && (!repairUUID.MatchString(j.CollectorUUID) || j.CollectorSiteID < 1 || j.CollectorSiteID > 999999999999999 || !repairDigest.MatchString(j.CollectorProfileHash)) ||
		!collector && (j.CollectorUUID != "" || j.CollectorSiteID != 0 || j.CollectorProfileHash != "") {
		return Journal{}, errors.New("installed identity binding requires administrator review")
	}
	return j, nil
}

type RepairReceipt struct {
	Schema              int    `json:"schema"`
	ID                  string `json:"repair_id"`
	Action              string `json:"action"`
	InstallationAttempt string `json:"installation_attempt"`
	ManifestSHA256      string `json:"manifest_sha256"`
	Stage               string `json:"stage"`
	Role                string `json:"role"`
	Revision            uint64 `json:"revision"`
	Status              string `json:"status"`
	Step                string `json:"step"`
	StartedAt           string `json:"started_at"`
	UpdatedAt           string `json:"updated_at"`
}
type ServiceRepairHost interface {
	ValidateRepair(context.Context, Journal) error
	RestoreServices(context.Context, Journal) error
	RestoreTray(context.Context, Journal) error
	EnsureRunning(context.Context, Journal) error
	SaveRepair(RepairReceipt) error
}

// Repair is convergent: failure preserves existing services/data and records the
// incomplete outcome. It never calls installation rollback or enrolment.
func RunServiceRepair(ctx context.Context, j Journal, h ServiceRepairHost) (RepairReceipt, error) {
	var r RepairReceipt
	if h == nil || j.Phase != "installed" || j.RecoveryRequired || j.FailedStep != "" {
		return r, errors.New("service repair is not initialized for an installed release")
	}
	if ctx.Err() != nil {
		return r, errors.New("service repair cancelled before changes")
	}
	if err := h.ValidateRepair(ctx, j); err != nil {
		return r, errors.New("installed package, identity or service ownership requires review; no repair was started")
	}
	var nonce [16]byte
	if _, err := rand.Read(nonce[:]); err != nil {
		return r, errors.New("cannot create service repair record")
	}
	now := time.Now().UTC().Format(time.RFC3339Nano)
	r = RepairReceipt{Schema: 1, ID: hex.EncodeToString(nonce[:]), Action: "repair_services", InstallationAttempt: j.Attempt, ManifestSHA256: j.ManifestSHA256, Stage: j.Stage, Role: j.Role, Revision: j.Revision, Status: "in_progress", StartedAt: now, UpdatedAt: now}
	save := func() error { r.UpdatedAt = time.Now().UTC().Format(time.RFC3339Nano); return h.SaveRepair(r) }
	fail := func(step string) (RepairReceipt, error) {
		r.Status = "incomplete"
		r.Step = step
		if save() != nil {
			return r, errors.New("service repair outcome could not be saved; review the installation before retrying")
		}
		return r, errors.New("service repair incomplete at " + step + "; existing identity and service data have been retained")
	}
	steps := []struct {
		name string
		run  func(context.Context, Journal) error
	}{
		{"service_registration", h.RestoreServices}, {"tray_registration", h.RestoreTray}, {"service_start", h.EnsureRunning},
	}
	for _, step := range steps {
		if ctx.Err() != nil {
			return fail("interrupted")
		}
		r.Step = step.name
		if save() != nil {
			return r, errors.New("service repair record could not be saved; no further changes were made")
		}
		if err := step.run(ctx, j); err != nil {
			return fail(step.name)
		}
		if ctx.Err() != nil {
			return fail("interrupted")
		}
	}
	r.Status = "local_services_ready"
	r.Step = "complete"
	if save() != nil {
		r.Status = "unconfirmed"
		return r, errors.New("local service readiness could not be recorded; review the repair before retrying")
	}
	return r, nil
}
