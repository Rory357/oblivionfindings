package collectorhost

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"golang.org/x/sys/windows"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/securefs"
)

func enrollmentCall(call invocation, state string, p Bootstrap) invocation {
	call.arguments = append(call.arguments, "--collector-id="+p.CollectorUUID, "--site-id="+strconv.FormatInt(p.SiteID, 10),
		"--central-url="+p.ServerURL, "--tls-public-key-pin="+p.TLSPin, "--state-directory="+state,
		"--token-fd=0", "--confirm-current", "--expected-server-key="+p.ServerKey)
	call.input = []byte(p.Token)
	return call
}

// EnrolInstalled is called by privileged setup only after publisher/signature
// checks, ownership checks and stopping the same attempt's service. All directory
// handles remain held by setup. Fresh state stays empty until PHP commits identity.
func EnrolInstalled(ctx context.Context, stage, state, temporary *securefs.Directory, p Bootstrap) error {
	if err := ValidateBootstrap(p, time.Now()); err != nil {
		return err
	}
	if stage == nil || state == nil || temporary == nil {
		return errInstallation
	}
	worker := &installed{state: state}
	defer worker.Close()
	var err error
	worker.code, err = worker.child(stage, "site", false)
	if err != nil {
		return errInstallation
	}
	if err = worker.holdCodeTree(worker.code, 0, new(int)); err != nil {
		return err
	}
	for _, path := range release.SiteRuntimeFiles() {
		if worker.code.CheckRelativeFile(strings.TrimPrefix(path, "site/")) != nil {
			return errInstallation
		}
	}
	if err = checkPrivateTree(state, 0, new(int)); err != nil {
		return err
	}
	system, err := windows.GetSystemDirectory()
	if err != nil {
		return errInstallation
	}
	windir, err := windows.GetWindowsDirectory()
	if err != nil {
		return errInstallation
	}
	runtime := filepath.Join(worker.code.Path(), "runtime")
	worker.environment = []string{"SystemRoot=" + windir, "WINDIR=" + windir,
		"PATH=" + strings.Join([]string{runtime, system}, ";"),
		"TEMP=" + temporary.Path(), "TMP=" + temporary.Path()}
	call := enrollmentCall(worker.call("enrol-recoverable"), state.Path(), p)
	defer clear(call.input)
	bounded, cancel := context.WithTimeout(ctx, 90*time.Second)
	defer cancel()
	code, err := runChild(bounded, call)
	if err != nil || code != 0 {
		return errors.New("collector enrolment was not confirmed; retained identity can be retried with the original approved profile")
	}
	if err = worker.validatePrivateState(); err != nil {
		return err
	}
	data, err := state.ReadFile("collector.identity.json", 16384)
	if err != nil {
		return errInstallation
	}
	defer clear(data)
	var identity struct {
		UUID      string `json:"collector_id"`
		Site      int64  `json:"site_id"`
		Origin    string `json:"central_url"`
		Pin       string `json:"tls_public_key_pin"`
		Key       string `json:"central_signing_public_key"`
		TokenHash string `json:"enrolment_token_hash"`
	}
	hash := sha256.Sum256([]byte(p.Token))
	if json.Unmarshal(data, &identity) != nil || identity.UUID != p.CollectorUUID || identity.Site != p.SiteID ||
		identity.Origin != p.ServerURL || identity.Pin != p.TLSPin || identity.Key != p.ServerKey ||
		identity.TokenHash != hex.EncodeToString(hash[:]) {
		return errInstallation
	}
	// These must follow successful identity commit: first enrolment requires empty state.
	for _, name := range []string{"Temp", "quarantine", "command-journal"} {
		directory, err := state.EnsureChild(name, true)
		if err != nil {
			return errInstallation
		}
		directory.Close()
	}
	local, cancelLocal := context.WithTimeout(ctx, 20*time.Second)
	defer cancelLocal()
	return worker.Check(local)
}
