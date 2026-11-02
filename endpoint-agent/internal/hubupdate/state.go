package hubupdate

import (
	"bytes"
	"encoding/json"
	"errors"
	"time"
)

// StateFile is kept in the hub's private state folder next to hub-instructions.json.
const StateFile = "hub-update.json"

const maxStateBytes = 64 * 1024
const maxQueuedReports = 4
const maxCleanups = 8

var errState = errors.New("hub update record is invalid")

// State is the small record that survives restarts: the release on trial, the old release
// to go back to, what to tidy up, the last failure, and reports not yet sent.
type State struct {
	Schema    int       `json:"schema"`
	Pending   *Trial    `json:"pending,omitempty"`
	Cleanups  []Cleanup `json:"cleanups,omitempty"`
	Installed *Release  `json:"installed,omitempty"` // the release this updater last installed
	Failure   *Failure  `json:"last_failure,omitempty"`
	Reports   []Report  `json:"reports,omitempty"`
}

type Release struct {
	Stage    string `json:"stage"`
	Version  string `json:"version"`
	Revision uint64 `json:"revision"`
}

// Trial: the service now points at To. If To hasn't completed a cycle by Deadline, the hub
// goes back to From.
type Trial struct {
	To       Release   `json:"to"`
	From     Release   `json:"from"`
	Deadline time.Time `json:"deadline"`
}

// Cleanup removes an old release folder after two good cycles, and not before NotBefore.
type Cleanup struct {
	Stage      string    `json:"stage"`
	NotBefore  time.Time `json:"not_before"`
	GoodCycles int       `json:"good_cycles"`
}

type Failure struct {
	Version  string    `json:"version"`
	Revision uint64    `json:"revision"`
	At       time.Time `json:"at"`
}

// Report is sent with the PHP hub's update-report command.
type Report struct {
	Outcome string `json:"outcome"` // installed, rolled_back or failed
	From    string `json:"from_version,omitempty"`
	To      string `json:"to_version"`
	Detail  string `json:"detail,omitempty"`
}

// DecodeState reads the record. A missing file is an empty record.
func DecodeState(data []byte) (State, error) {
	if len(bytes.TrimSpace(data)) == 0 {
		return State{Schema: 1}, nil
	}
	var s State
	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.DisallowUnknownFields()
	if len(data) > maxStateBytes || decoder.Decode(&s) != nil || s.Schema != 1 {
		return State{}, errState
	}
	if s.Pending != nil && (!ValidStage(s.Pending.To.Stage) || !ValidStage(s.Pending.From.Stage) || s.Pending.To.Stage == s.Pending.From.Stage) {
		return State{}, errState
	}
	if len(s.Cleanups) > maxCleanups {
		return State{}, errState
	}
	for _, c := range s.Cleanups {
		if !ValidStage(c.Stage) {
			return State{}, errState
		}
	}
	if s.Installed != nil && !ValidStage(s.Installed.Stage) {
		return State{}, errState
	}
	return s, nil
}

func (s State) Encode() ([]byte, error) {
	s.Schema = 1
	return json.MarshalIndent(s, "", "  ")
}

func (s *State) queue(r Report) {
	s.Reports = append(s.Reports, r)
	if len(s.Reports) > maxQueuedReports {
		s.Reports = s.Reports[len(s.Reports)-maxQueuedReports:]
	}
}

func (s *State) tidyLater(c Cleanup) {
	for _, existing := range s.Cleanups {
		if existing.Stage == c.Stage {
			return
		}
	}
	s.Cleanups = append(s.Cleanups, c)
	if len(s.Cleanups) > maxCleanups {
		s.Cleanups = s.Cleanups[len(s.Cleanups)-maxCleanups:]
	}
}
