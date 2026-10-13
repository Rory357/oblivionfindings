package agent

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"os"
	"time"
	"unicode"
	"unicode/utf8"
)

const MaxBootstrapBytes = 16384

// Bootstrap is downloaded from the intended organisation's authenticated HTTPS portal.
// Its embedded public key is not an independent trust anchor for an untrusted download.
type Bootstrap struct {
	Version      int    `json:"version"`
	Platform     string `json:"platform"`
	ServerURL    string `json:"server_url"`
	ServerKey    string `json:"server_public_key"`
	AgentUUID    string `json:"agent_uuid"`
	Token        string `json:"enrolment_token"`
	ExpiresAt    string `json:"expires_at"`
	Organisation string `json:"organisation"`
	DeviceLabel  string `json:"device_label"`
	DeviceUID    string `json:"device_uid"`
}

func ReadBootstrap(reader io.Reader, now time.Time) (Bootstrap, error) {
	var profile Bootstrap
	data, err := io.ReadAll(io.LimitReader(reader, MaxBootstrapBytes+1))
	if err != nil || len(data) > MaxBootstrapBytes || !utf8.Valid(data) {
		return profile, errors.New("installation profile exceeds its bound")
	}
	// Reject duplicate names instead of allowing the UI and enrolment parser to disagree.
	decoder := json.NewDecoder(bytes.NewReader(data))
	token, err := decoder.Token()
	if err != nil || token != json.Delim('{') {
		return profile, errors.New("invalid installation profile")
	}
	names := map[string]bool{}
	for decoder.More() {
		key, err := decoder.Token()
		name, ok := key.(string)
		if err != nil || !ok || names[name] {
			return profile, errors.New("invalid or duplicate installation profile field")
		}
		names[name] = true
		var value json.RawMessage
		if decoder.Decode(&value) != nil {
			return profile, errors.New("invalid installation profile value")
		}
	}
	decoder = json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&profile) != nil {
		return Bootstrap{}, errors.New("unsupported installation profile")
	}
	var trailing any
	if decoder.Decode(&trailing) != io.EOF {
		return Bootstrap{}, errors.New("trailing installation profile content")
	}
	if err := ValidateBootstrap(profile, now); err != nil {
		return Bootstrap{}, err
	}
	return profile, nil
}

func ValidateBootstrap(profile Bootstrap, now time.Time) error {
	expires, err := time.Parse(time.RFC3339Nano, profile.ExpiresAt)
	if profile.Version != 1 || profile.Platform != "windows" || !uuidPattern.MatchString(profile.AgentUUID) ||
		!hexCredential(profile.Token) || err != nil || !expires.After(now) || expires.After(now.Add(31*time.Minute)) {
		return errors.New("installation profile is expired or invalid; download a new one")
	}
	for _, label := range []string{profile.Organisation, profile.DeviceLabel, profile.DeviceUID} {
		if label == "" || !utf8.ValidString(label) || utf8.RuneCountInString(label) > 300 {
			return errors.New("invalid installation identity label")
		}
		for _, r := range label {
			if unicode.IsControl(r) || unicode.Is(unicode.Cf, r) {
				return errors.New("invalid installation identity label")
			}
		}
	}
	if _, err = NewClient(profile.ServerURL, profile.ServerKey); err != nil {
		return err
	}
	return nil
}

// Enrolment retries must use the original local secret and the same target binding.
// A previously enrolled installation cannot be repointed by presenting another file.
func PrepareBootstrap(store *Store, profile Bootstrap) (State, error) {
	if err := ValidateBootstrap(profile, time.Now()); err != nil {
		return State{}, err
	}
	existing, err := store.Load()
	if err == nil {
		if existing.AgentUUID != "" {
			return State{}, errors.New("this computer is already enrolled; approved re-enrolment is required")
		}
		if existing.ServerURL != profile.ServerURL || existing.ServerKey != profile.ServerKey ||
			existing.EnrollmentToken != profile.Token || existing.ExpectedAgentUUID != profile.AgentUUID {
			return State{}, errors.New("installation profile does not match pending enrolment")
		}
		return existing, nil
	}
	if !os.IsNotExist(err) {
		return State{}, err
	}
	return beginEnrollment(store, profile.ServerURL, profile.ServerKey, profile.Token, profile.AgentUUID)
}
