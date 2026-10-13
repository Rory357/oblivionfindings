package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

// State is private to this installation. It must never be logged or returned by diagnostics.
type State struct {
	ExpectedAgentUUID string          `json:"expected_agent_uuid,omitempty"`
	Version           int             `json:"version"`
	ServerURL         string          `json:"server_url"`
	ServerKey         string          `json:"server_key"`
	AgentUUID         string          `json:"agent_uuid"`
	Credential        string          `json:"credential"`
	EnrollmentToken   string          `json:"enrollment_token,omitempty"`
	Sequence          uint64          `json:"sequence"`
	PendingHeartbeat  json.RawMessage `json:"pending_heartbeat,omitempty"`
}

type Store struct {
	mutationGate              sync.Mutex
	softwareReady             func() bool
	softwareObservations      func(string, string) []SoftwareObservation
	restartReady              func() bool
	restartObservations       func(string, string) []RestartObservation
	serviceActionReady        func() bool
	serviceActionObservations func(context.Context) []ServiceActionObservation
	writeProtected            func(string, []byte) error
	validateProtected         func(string) error
	dir                       string
	lock                      *os.File
}

// OpenStore holds an OS lock for its entire lifetime; two processes cannot send or execute concurrently.
func OpenStore(dir string) (*Store, error) {
	if !filepath.IsAbs(dir) {
		return nil, errors.New("state directory must be absolute")
	}
	dir = filepath.Clean(dir)
	if err := os.MkdirAll(dir, 0700); err != nil {
		return nil, errors.New("cannot create state directory")
	}
	info, err := os.Lstat(dir)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 {
		return nil, errors.New("invalid state directory")
	}
	if err := protectDirectory(dir); err != nil {
		return nil, err
	}
	lockPath := filepath.Join(dir, "agent.lock")
	if err := rejectLink(lockPath); err != nil {
		return nil, err
	}
	lock, err := os.OpenFile(lockPath, os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, errors.New("cannot open agent lock")
	}
	if err = lockFile(lock); err != nil {
		lock.Close()
		return nil, errors.New("another agent is using this state directory")
	}
	return &Store{dir: dir, lock: lock}, nil
}

func (s *Store) Close() error { return s.lock.Close() }

func (s *Store) Load() (State, error) {
	var state State
	name := filepath.Join(s.dir, "state.json")
	if err := rejectLink(name); err != nil {
		return state, err
	}
	f, err := os.Open(name)
	if err != nil {
		return state, err
	}
	defer f.Close()
	if s.validateProtected != nil {
		if err = s.validateProtected("state.json"); err != nil {
			return State{}, err
		}
	}
	data, err := io.ReadAll(io.LimitReader(f, 2*1024*1024+1))
	if err != nil || len(data) > 2*1024*1024 {
		return State{}, errors.New("agent state exceeds its bound")
	}
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	var trailing any
	if err = decoder.Decode(&state); err != nil || decoder.Decode(&trailing) != io.EOF {
		return State{}, errors.New("agent state is invalid; approved recovery is required")
	}
	if state.Version != 1 || !hexCredential(state.Credential) || state.Sequence > 9007199254740991 || (state.ExpectedAgentUUID != "" && !uuidPattern.MatchString(state.ExpectedAgentUUID)) {
		return State{}, errors.New("unsupported or invalid agent state")
	}
	return state, nil
}

func (s *Store) Save(state State) error {
	if state.Version != 1 || !hexCredential(state.Credential) {
		return errors.New("invalid state")
	}
	data, err := json.Marshal(state)
	if err != nil || len(data) > 2*1024*1024 {
		return errors.New("agent state exceeds its bound")
	}
	return s.atomicWrite("state.json", data)
}

func (s *Store) atomicWrite(name string, data []byte) error {
	if filepath.Base(name) != name || strings.ContainsAny(name, "/\\") {
		return errors.New("invalid state filename")
	}
	if s.writeProtected != nil {
		return s.writeProtected(name, data)
	}
	target := filepath.Join(s.dir, name)
	if err := rejectLink(target); err != nil {
		return err
	}
	f, err := os.CreateTemp(s.dir, ".write-*")
	if err != nil {
		return errors.New("cannot prepare state write")
	}
	temporary := f.Name()
	defer os.Remove(temporary)
	if err = f.Chmod(0600); err == nil {
		_, err = f.Write(data)
	}
	if err == nil {
		err = f.Sync()
	}
	closeErr := f.Close()
	if err != nil || closeErr != nil {
		return errors.New("cannot persist agent state")
	}
	if err = replaceFile(temporary, target); err != nil {
		return errors.New("cannot commit agent state")
	}
	return syncDirectory(s.dir)
}

func rejectLink(path string) error {
	info, err := os.Lstat(path)
	if os.IsNotExist(err) {
		return nil
	}
	if err != nil || info.Mode()&os.ModeSymlink != 0 || info.IsDir() {
		return errors.New("unsafe state file")
	}
	return nil
}
