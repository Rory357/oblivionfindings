package setup

import (
	"context"
	"encoding/json"
	"errors"
	"oblivion.local/endpoint-agent/internal/release"
	"reflect"
	"strings"
	"testing"
	"time"
)

func repairFixture(role string) (Journal, release.Manifest) {
	j := newJournal()
	j.Phase = "installed"
	j.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	j.Role = role
	if role == "site" {
		j.AgentUUID = ""
	}
	if role == "site" || role == "both" {
		j.CollectorUUID = "2df1d87c-2d04-4e57-80ab-8a15f39c944d"
		j.CollectorSiteID = 9
		j.CollectorProfileHash = strings.Repeat("c", 64)
	}
	return j, release.Manifest{Version: j.Version, Revision: j.Revision, Architecture: j.Architecture}
}
func encodedRepairJournal(t *testing.T, j Journal) []byte {
	t.Helper()
	b, e := json.Marshal(j)
	if e != nil {
		t.Fatal(e)
	}
	return b
}
func TestServiceRepairReceiptBindsExactInstalledReleaseAndAllRoles(t *testing.T) {
	for _, role := range []string{"computer", "site", "both"} {
		j, m := repairFixture(role)
		data := encodedRepairJournal(t, j)
		got, err := decodeRepairInstallation(data, m, j.ManifestSHA256, j.ServerURL, role)
		if err != nil || got != j {
			t.Fatal(role, err)
		}
		cases := []struct {
			m                    release.Manifest
			digest, server, role string
		}{
			{m, strings.Repeat("1", 64), j.ServerURL, role}, {m, j.ManifestSHA256, "https://other.example.test", role}, {m, j.ManifestSHA256, j.ServerURL, "other"},
		}
		old := m
		old.Revision--
		cases = append(cases, struct {
			m                    release.Manifest
			digest, server, role string
		}{old, j.ManifestSHA256, j.ServerURL, role})
		old = m
		old.Version = "1.2.4"
		cases = append(cases, struct {
			m                    release.Manifest
			digest, server, role string
		}{old, j.ManifestSHA256, j.ServerURL, role})
		old = m
		old.Architecture = "arm64"
		cases = append(cases, struct {
			m                    release.Manifest
			digest, server, role string
		}{old, j.ManifestSHA256, j.ServerURL, role})
		for _, c := range cases {
			if _, err := decodeRepairInstallation(data, c.m, c.digest, c.server, c.role); err == nil {
				t.Fatal("changed binding accepted", c)
			}
		}
	}
}
func TestServiceRepairRejectsIncompleteAmbiguousOrMalformedInstalledRecords(t *testing.T) {
	j, m := repairFixture("both")
	raw := string(encodedRepairJournal(t, j))
	samples := []string{raw + raw, raw[:len(raw)-1], strings.Replace(raw, "{", "{\"schema\":1,", 1),
		strings.Replace(raw, "\"schema\"", "\"Schema\"", 1), strings.Replace(raw, "\"recovery_required\":false", "\"recovery_required\":null", 1),
		strings.Replace(raw, "\"recovery_required\":false,", "", 1), strings.Replace(raw, "\"phase\":\"installed\"", "\"phase\":\"service_ready\"", 1),
		strings.Replace(raw, "{", "{\"unknown\":true,", 1), strings.Repeat(" ", 32769)}
	// Include schema-valid but invalid identity/state shapes.
	for _, change := range []func(*Journal){func(v *Journal) { v.AgentUUID = "" }, func(v *Journal) { v.CollectorSiteID = 0 }, func(v *Journal) { v.CollectorUUID = "" }, func(v *Journal) { v.CollectorProfileHash = "broken" }, func(v *Journal) { v.Stage = "../elsewhere" }, func(v *Journal) { v.FailedStep = "interrupted"; v.RecoveryRequired = true }} {
		invalid := j
		change(&invalid)
		samples = append(samples, string(encodedRepairJournal(t, invalid)))
	}
	for i, data := range samples {
		if _, err := decodeRepairInstallation([]byte(data), m, j.ManifestSHA256, j.ServerURL, j.Role); err == nil {
			t.Fatal("invalid record accepted", i)
		}
	}
}

type repairHostFake struct {
	expected               Journal
	calls                  []string
	records                map[string]RepairReceipt
	saves, failSave        int
	failStep, cancelAt     string
	cancel                 context.CancelFunc
	service, tray, running bool
}

func (h *repairHostFake) step(ctx context.Context, j Journal, name string) error {
	if j != h.expected {
		return errors.New("installation identity changed")
	}
	h.calls = append(h.calls, name)
	if name == h.cancelAt {
		h.cancel()
	}
	if name == h.failStep {
		return errors.New("private credential must never reach output")
	}
	return ctx.Err()
}
func (h *repairHostFake) ValidateRepair(c context.Context, j Journal) error {
	return h.step(c, j, "validate")
}
func (h *repairHostFake) RestoreServices(c context.Context, j Journal) error {
	h.service = true
	return h.step(c, j, "service_registration")
}
func (h *repairHostFake) RestoreTray(c context.Context, j Journal) error {
	h.tray = true
	return h.step(c, j, "tray_registration")
}
func (h *repairHostFake) EnsureRunning(c context.Context, j Journal) error {
	h.running = true
	return h.step(c, j, "service_start")
}
func (h *repairHostFake) SaveRepair(r RepairReceipt) error {
	h.saves++
	if h.saves == h.failSave {
		return errors.New("disk unavailable")
	}
	if h.records == nil {
		h.records = map[string]RepairReceipt{}
	}
	h.records[r.ID] = r
	return nil
}
func TestServiceRepairFailureRetainsRestoredComponentsAndCanConvergeOnRetry(t *testing.T) {
	for _, step := range []string{"service_registration", "tray_registration", "service_start"} {
		t.Run(step, func(t *testing.T) {
			j, _ := repairFixture("both")
			h := &repairHostFake{expected: j, failStep: step}
			r, err := RunServiceRepair(context.Background(), j, h)
			if err == nil || r.Status != "incomplete" || strings.Contains(err.Error(), "credential") || !h.service {
				t.Fatal("failure was destructive or falsely successful", r, err)
			}
			prior := r
			h.failStep = ""
			r, err = RunServiceRepair(context.Background(), j, h)
			if err != nil || r.Status != "local_services_ready" || !h.service || !h.tray || !h.running || r.ID == prior.ID || h.records[prior.ID] != prior || j != h.expected {
				t.Fatal("retry did not preserve identity/evidence", r, err)
			}
		})
	}
}
func TestServiceRepairRefusesValidationAndPreCancelledWorkBeforeMutations(t *testing.T) {
	j, _ := repairFixture("computer")
	h := &repairHostFake{expected: j, failStep: "validate"}
	if _, err := RunServiceRepair(context.Background(), j, h); err == nil || h.service || h.saves != 0 || strings.Contains(err.Error(), "credential") {
		t.Fatal("failed preflight mutated installation", err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	h = &repairHostFake{expected: j}
	if _, err := RunServiceRepair(ctx, j, h); err == nil || len(h.calls) != 0 || h.saves != 0 {
		t.Fatal("cancelled repair ran")
	}
}
func TestServiceRepairJournalFailuresNeverPermitUnrecordedNextActionOrSuccess(t *testing.T) {
	j, _ := repairFixture("computer")
	for fail := 1; fail <= 4; fail++ {
		h := &repairHostFake{expected: j, failSave: fail}
		r, err := RunServiceRepair(context.Background(), j, h)
		if err == nil || r.Status == "local_services_ready" {
			t.Fatal("failed commit reported success", fail, r)
		}
		if len(h.calls) != fail {
			t.Fatal("mutation ran after failed durable intent", fail, h.calls)
		}
	}
}
func TestServiceRepairCancellationDoesNotRollbackExistingOrNewServices(t *testing.T) {
	j, _ := repairFixture("both")
	for _, step := range []string{"service_registration", "tray_registration", "service_start"} {
		ctx, cancel := context.WithCancel(context.Background())
		h := &repairHostFake{expected: j, cancelAt: step, cancel: cancel}
		r, err := RunServiceRepair(ctx, j, h)
		cancel()
		if err == nil || r.Status != "incomplete" || !h.service || !reflect.DeepEqual(h.expected, j) {
			t.Fatal("cancellation lost existing installation", r, err)
		}
	}
}
