package collectorhost

import (
	"bytes"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"regexp"
	"strings"
	"time"
	"unicode"
	"unicode/utf8"

	"oblivion.local/endpoint-agent/internal/agent"
)

const MaxBootstrapBytes = 16384

var errBootstrap = errors.New("collector installation profile is invalid, expired or unsupported")
var collectorUUIDPattern = regexp.MustCompile("^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$")
var enrolTokenPattern = regexp.MustCompile("^ofc_enrol_[A-Za-z0-9_-]{64}$")

// DeploymentURL identifies the organisation's application. ServerURL may identify
// its dedicated collector transport. Neither is inferred from the current host.
// Obtain this secret-bearing file from the intended organisation's HTTPS portal.
type Bootstrap struct {
	Version       int    `json:"version"`
	Component     string `json:"component"`
	Platform      string `json:"platform"`
	DeploymentURL string `json:"deployment_url"`
	ServerURL     string `json:"server_url"`
	ServerKey     string `json:"server_public_key"`
	TLSPin        string `json:"tls_public_key_pin"`
	Organisation  string `json:"organisation"`
	SiteID        int64  `json:"site_id"`
	SiteLabel     string `json:"site_label"`
	CollectorUUID string `json:"collector_uuid"`
	Token         string `json:"enrolment_token"`
	ExpiresAt     string `json:"expires_at"`
}

func ReadBootstrap(reader io.Reader, now time.Time) (Bootstrap, error) {
	data, err := io.ReadAll(io.LimitReader(reader, MaxBootstrapBytes+1))
	defer clear(data)
	if err != nil || len(data) > MaxBootstrapBytes || !utf8.Valid(data) {
		return Bootstrap{}, errBootstrap
	}
	fields := map[string]bool{"version": false, "component": false, "platform": false, "deployment_url": false, "server_url": false,
		"server_public_key": false, "tls_public_key_pin": false, "organisation": false, "site_id": false, "site_label": false,
		"collector_uuid": false, "enrolment_token": false, "expires_at": false}
	scan := json.NewDecoder(bytes.NewReader(data))
	token, err := scan.Token()
	if err != nil || token != json.Delim('{') {
		return Bootstrap{}, errBootstrap
	}
	for scan.More() {
		raw, err := scan.Token()
		key, ok := raw.(string)
		seen, known := fields[key]
		if err != nil || !ok || !known || seen {
			return Bootstrap{}, errBootstrap
		}
		fields[key] = true
		var value json.RawMessage
		if scan.Decode(&value) != nil || bytes.Equal(value, []byte("null")) {
			return Bootstrap{}, errBootstrap
		}
	}
	for _, seen := range fields {
		if !seen {
			return Bootstrap{}, errBootstrap
		}
	}
	var p Bootstrap
	decode := json.NewDecoder(bytes.NewReader(data))
	decode.DisallowUnknownFields()
	var trailing any
	if decode.Decode(&p) != nil || decode.Decode(&trailing) != io.EOF {
		return Bootstrap{}, errBootstrap
	}
	if err := ValidateBootstrap(p, now); err != nil {
		return Bootstrap{}, err
	}
	return p, nil
}

func ValidateBootstrap(p Bootstrap, now time.Time) error {
	expiry, err := time.Parse(time.RFC3339Nano, p.ExpiresAt)
	if err != nil || !expiry.After(now) || expiry.After(now.Add(31*time.Minute)) || p.Version != 1 ||
		p.Component != "site" || p.Platform != "windows" || p.SiteID < 1 || p.SiteID > 999999999999999 ||
		!collectorUUIDPattern.MatchString(p.CollectorUUID) || !enrolTokenPattern.MatchString(p.Token) {
		return errBootstrap
	}
	for _, label := range []string{p.Organisation, p.SiteLabel} {
		if label == "" || strings.TrimSpace(label) != label || !utf8.ValidString(label) || utf8.RuneCountInString(label) > 300 {
			return errBootstrap
		}
		for _, r := range label {
			if unicode.IsControl(r) || unicode.Is(unicode.Cf, r) {
				return errBootstrap
			}
		}
	}
	key, err := base64.StdEncoding.Strict().DecodeString(p.ServerKey)
	if err != nil || len(key) != 32 || base64.StdEncoding.EncodeToString(key) != p.ServerKey {
		return errBootstrap
	}
	pin, err := base64.StdEncoding.Strict().DecodeString(strings.TrimPrefix(p.TLSPin, "sha256//"))
	if err != nil || len(pin) != 32 || "sha256//"+base64.StdEncoding.EncodeToString(pin) != p.TLSPin {
		return errBootstrap
	}
	for _, origin := range []string{p.DeploymentURL, p.ServerURL} {
		if strings.HasSuffix(origin, "/") {
			return errBootstrap
		}
		if _, err := agent.NewClient(origin, p.ServerKey); err != nil {
			return errBootstrap
		}
	}
	return nil
}

// Bind interrupted installation to the entire approved profile without storing its token.
func (p Bootstrap) BindingSHA256() string {
	data, _ := json.Marshal(p)
	defer clear(data)
	digest := sha256.Sum256(data)
	return hex.EncodeToString(digest[:])
}
