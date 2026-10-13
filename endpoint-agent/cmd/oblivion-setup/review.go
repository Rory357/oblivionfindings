package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"regexp"
	"time"

	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/setup"
)

var reviewDigestPattern = regexp.MustCompile("^[a-f0-9]{64}$")

type reviewDetails struct {
	Schema       int    `json:"schema"`
	Role         string `json:"role"`
	Version      string `json:"version"`
	Revision     uint64 `json:"revision"`
	Architecture string `json:"architecture"`
	Organisation string `json:"organisation"`
	Computer     string `json:"computer"`
	Site         string `json:"site"`
	ServerURL    string `json:"server_url"`
	CollectorURL string `json:"collector_url"`
	ExpiresAt    string `json:"expires_at"`
	ReviewDigest string `json:"review_digest"`
}

func installationReview(pack *release.Package, profiles setup.Profiles) (reviewDetails, error) {
	if pack == nil {
		return reviewDetails{}, errors.New("release review is unavailable")
	}
	m := pack.Manifest()
	return reviewFor(m, pack.Digest(), profiles)
}
func reviewFor(m release.Manifest, manifestDigest string, p setup.Profiles) (reviewDetails, error) {
	r := reviewDetails{Schema: 1, Role: p.Role, Version: m.Version, Revision: m.Revision, Architecture: m.Architecture, ServerURL: p.ServerURL()}
	expires, err := time.Parse(time.RFC3339, m.ExpiresAt)
	if err != nil || !reviewDigestPattern.MatchString(manifestDigest) {
		return r, errors.New("invalid release review")
	}
	includeExpiry := func(value string) error {
		t, e := time.Parse(time.RFC3339Nano, value)
		if e != nil {
			return e
		}
		if t.Before(expires) {
			expires = t
		}
		return nil
	}
	if p.Computer != nil {
		r.Organisation = p.Computer.Organisation
		r.Computer = p.Computer.DeviceLabel
		if err = includeExpiry(p.Computer.ExpiresAt); err != nil {
			return r, err
		}
	}
	if p.Collector != nil {
		if r.Organisation != "" && r.Organisation != p.Collector.Organisation {
			return r, errors.New("organisation labels changed; download matching profiles")
		}
		r.Organisation = p.Collector.Organisation
		r.Site = p.Collector.SiteLabel
		r.CollectorURL = p.Collector.ServerURL
		if err = includeExpiry(p.Collector.ExpiresAt); err != nil {
			return r, err
		}
	}
	r.ExpiresAt = expires.UTC().Format(time.RFC3339Nano)
	payload, err := json.Marshal(struct {
		Manifest string
		Profiles setup.Profiles
	}{manifestDigest, p})
	if err != nil {
		return r, errors.New("cannot bind installation review")
	}
	digest := sha256.Sum256(append([]byte("OBLIVION-INSTALLATION-REVIEW-V1\n"), payload...))
	r.ReviewDigest = hex.EncodeToString(digest[:])
	for i := range payload {
		payload[i] = 0
	}
	return r, nil
}

// The UI keeps this private pipe open. Any input, EOF or read error requests
// cooperative cancellation, so a lost controller cannot leave setup running.
func cancelOnControllerInput(input io.Reader, cancel context.CancelFunc) {
	var one [1]byte
	_, _ = input.Read(one[:])
	cancel()
}
