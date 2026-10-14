package collectorhost

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"golang.org/x/sys/windows"
	"io"
	"oblivion.local/endpoint-agent/internal/securefs"
	"path/filepath"
	"strings"
	"unicode/utf8"
)

// Repair checks only the existing private identity/runtime. It does not enrol,
// confirm a setup grant, create directories, or reset certificates/checkpoints.
func CheckInstalledRepairIdentity(ctx context.Context, stage, state *securefs.Directory, uuid string, site int64, origin string) error {
	if stage == nil || state == nil {
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
	for _, name := range []string{"Temp", "quarantine", "command-journal"} {
		if _, err = worker.child(state, name, true); err != nil {
			return errInstallation
		}
	}
	data, err := state.ReadFile("collector.identity.json", 16384)
	if err != nil {
		return errInstallation
	}
	defer clear(data)
	if err = checkRepairIdentity(data, uuid, site, origin); err != nil {
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
	worker.environment = []string{"SystemRoot=" + windir, "WINDIR=" + windir, "PATH=" + strings.Join([]string{runtime, system}, ";"),
		"TEMP=" + filepath.Join(state.Path(), "Temp"), "TMP=" + filepath.Join(state.Path(), "Temp")}
	return worker.Check(ctx)
}

func checkRepairIdentity(data []byte, uuid string, site int64, origin string) error {
	invalid := errors.New("collector identity does not match the installed record and confirmed origin")
	if len(data) > 16384 || !utf8.Valid(data) || !collectorUUIDPattern.MatchString(uuid) || site < 1 || origin == "" {
		return invalid
	}
	scan := json.NewDecoder(bytes.NewReader(data))
	token, err := scan.Token()
	if err != nil || token != json.Delim('{') {
		return invalid
	}
	fields := map[string]json.RawMessage{}
	for scan.More() {
		token, err = scan.Token()
		name, ok := token.(string)
		if err != nil || !ok {
			return invalid
		}
		if _, exists := fields[name]; exists {
			return invalid
		}
		var value json.RawMessage
		if scan.Decode(&value) != nil {
			return invalid
		}
		fields[name] = value
	}
	if token, err = scan.Token(); err != nil || token != json.Delim('}') {
		return invalid
	}
	var extra any
	if scan.Decode(&extra) != io.EOF {
		return invalid
	}
	var actualUUID, actualOrigin string
	var actualSite int64
	if json.Unmarshal(fields["collector_id"], &actualUUID) != nil || json.Unmarshal(fields["site_id"], &actualSite) != nil ||
		json.Unmarshal(fields["central_url"], &actualOrigin) != nil || actualUUID != uuid || actualSite != site || actualOrigin != origin {
		return invalid
	}
	return nil
}
