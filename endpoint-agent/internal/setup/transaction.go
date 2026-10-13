// Package setup coordinates an installation using a durable, non-secret journal.
// Platform mutations live behind Host; tests exercise interruption and rollback
// without registering services or changing the developer's computer.
package setup

import (
	"context"
	"errors"
	"time"
)

type Journal struct {
	Schema               int    `json:"schema"`
	Attempt              string `json:"attempt"`
	Stage                string `json:"stage"`
	Version              string `json:"version"`
	Revision             uint64 `json:"revision"`
	ManifestSHA256       string `json:"manifest_sha256"`
	Architecture         string `json:"architecture"`
	Role                 string `json:"role"`
	ServerURL            string `json:"server_url"`
	AgentUUID            string `json:"agent_uuid"`
	CollectorUUID        string `json:"collector_uuid,omitempty"`
	CollectorSiteID      int64  `json:"collector_site_id,omitempty"`
	CollectorProfileHash string `json:"collector_profile_hash,omitempty"`
	Phase                string `json:"phase"`
	FailedStep           string `json:"failed_step,omitempty"`
	RecoveryRequired     bool   `json:"recovery_required"`
	UpdatedAt            string `json:"updated_at"`
}
type Host interface {
	Persist(*Journal) error
	Stage(context.Context, *Journal) error
	Enrol(context.Context, *Journal) error
	RegisterService(context.Context, *Journal) error
	RegisterTray(context.Context, *Journal) error
	StartService(context.Context, *Journal) error
	Rollback(context.Context, *Journal) error
}

// Run repeats idempotent steps against the same bound journal. A completed
// installation needs an explicit repair/update flow rather than replacement.
func Run(ctx context.Context, journal *Journal, host Host) error {
	if journal == nil || host == nil {
		return errors.New("installation transaction is not initialized")
	}
	if journal.Phase == "installed" {
		return errors.New("this release is already installed; use the approved repair or update workflow")
	}
	if journal.RecoveryRequired {
		return errors.New("the previous attempt requires administrator recovery before retry")
	}
	steps := []struct {
		name, phase string
		run         func(context.Context, *Journal) error
	}{
		{"staging", "staged", host.Stage},
		{"enrolment", "enrolled", host.Enrol},
		{"service_registration", "service_registered", host.RegisterService},
		{"tray_registration", "tray_registered", host.RegisterTray},
		{"service_start", "service_ready", host.StartService},
	}
	for _, step := range steps {
		if err := ctx.Err(); err != nil {
			return fail(journal, host, "interrupted")
		}
		if err := step.run(ctx, journal); err != nil {
			return fail(journal, host, step.name)
		}
		if ctx.Err() != nil {
			return fail(journal, host, "interrupted")
		}
		journal.Phase = step.phase
		journal.FailedStep = ""
		journal.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
		if err := host.Persist(journal); err != nil {
			return fail(journal, host, "journal_write")
		}
	}
	journal.Phase = "installed"
	journal.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	if err := host.Persist(journal); err != nil {
		return fail(journal, host, "journal_write")
	}
	return nil
}
func fail(j *Journal, host Host, step string) error {
	// Cleanup has its own bounded context; cancellation of setup must not skip it.
	cleanup, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	rollbackErr := host.Rollback(cleanup, j)
	j.FailedStep = step
	j.RecoveryRequired = rollbackErr != nil
	j.UpdatedAt = time.Now().UTC().Format(time.RFC3339)
	if j.Phase == "installed" {
		j.Phase = "service_ready"
	}
	saveErr := host.Persist(j)
	if rollbackErr != nil || saveErr != nil {
		return errors.New("installation stopped; administrator recovery is required and existing identity has been retained")
	}
	return errors.New("installation stopped at " + step + "; the same approved installation can be retried without replacing its identity")
}
