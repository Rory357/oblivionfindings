package setup

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"oblivion.local/endpoint-agent/internal/release"
	"time"
)

type RepairReview struct {
	Schema              int    `json:"schema"`
	Action              string `json:"action"`
	Role                string `json:"role"`
	Version             string `json:"version"`
	Revision            uint64 `json:"revision"`
	Architecture        string `json:"architecture"`
	Computer            string `json:"computer"`
	ServerURL           string `json:"server_url"`
	CollectorURL        string `json:"collector_url"`
	AgentUUID           string `json:"agent_uuid"`
	CollectorUUID       string `json:"collector_uuid"`
	SiteID              int64  `json:"site_id"`
	InstallationAttempt string `json:"installation_attempt"`
	ManifestSHA256      string `json:"manifest_sha256"`
	Stage               string `json:"stage"`
	ExpiresAt           string `json:"expires_at"`
	ReviewDigest        string `json:"review_digest"`
}

func repairReviewFor(j Journal, m release.Manifest, computer, collectorOrigin, computerBinding, collectorBinding, expiry string, now time.Time) (RepairReview, error) {
	var zero RepairReview
	limit, err := time.Parse(time.RFC3339, m.ExpiresAt)
	if err != nil {
		return zero, err
	}
	if expiry == "" {
		at := now.Add(10 * time.Minute).UTC().Truncate(time.Second)
		if limit.Before(at) {
			at = limit
		}
		expiry = at.Format(time.RFC3339Nano)
	}
	expires, err := time.Parse(time.RFC3339Nano, expiry)
	if err != nil || !expires.After(now) || expires.After(limit) || expires.After(now.Add(10*time.Minute)) {
		return zero, errors.New("service repair review expired; review the installed connection again")
	}
	if hasComputer(&j) != repairDigest.MatchString(computerBinding) || (j.Role == "site" || j.Role == "both") != repairDigest.MatchString(collectorBinding) {
		return zero, errors.New("service repair identity review is incomplete")
	}
	r := RepairReview{Schema: 1, Action: "repair_services", Role: j.Role, Version: j.Version, Revision: j.Revision, Architecture: j.Architecture, Computer: computer,
		ServerURL: j.ServerURL, CollectorURL: collectorOrigin, AgentUUID: j.AgentUUID, CollectorUUID: j.CollectorUUID, SiteID: j.CollectorSiteID,
		InstallationAttempt: j.Attempt, ManifestSHA256: j.ManifestSHA256, Stage: j.Stage, ExpiresAt: expires.UTC().Format(time.RFC3339Nano)}
	payload, err := json.Marshal(struct {
		Journal                           Journal
		Review                            RepairReview
		ComputerBinding, CollectorBinding string
	}{j, r, computerBinding, collectorBinding})
	if err != nil {
		return zero, err
	}
	defer clear(payload)
	sum := sha256.Sum256(append([]byte("OBLIVION-SERVICE-REPAIR-REVIEW-V1\n"), payload...))
	r.ReviewDigest = hex.EncodeToString(sum[:])
	return r, nil
}
