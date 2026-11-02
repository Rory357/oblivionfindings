// Package hubupdate decides when a Site hub updates itself, and keeps the small record that
// lets it roll back (S&D Phase B, decision D3: "Automatic overnight, rolls back if it fails.
// You can hold an update for all hubs."). The Windows service and the Linux launcher share
// it. Swapping releases and restarting are theirs, behind Host.
package hubupdate

import (
	"encoding/json"
	"errors"
	"regexp"
	"strconv"
	"time"
)

// MaxInstructionsBytes bounds hub-instructions.json (the release list rides along for Linux).
const MaxInstructionsBytes = 512 * 1024

var errInstructions = errors.New("hub update instructions are invalid")

// Instructions is <state dir>/hub-instructions.json, written by the PHP hub after every
// heartbeat (collector/src/CollectorApplication.php, writeHostInstructions).
type Instructions struct {
	Approval       string  `json:"approval"`
	Update         *Update `json:"update"`
	ReceivedAt     string  `json:"received_at"`
	RunningVersion string  `json:"running_version"`
}

// Update is the server's update block (app/Domain/SecurityDevices/Hubs/Services/HubUpdatePolicy.php).
type Update struct {
	Held                 bool   `json:"held"`
	Ring                 string `json:"ring"`
	Window               Window `json:"window"`
	RollbackAfterMinutes int    `json:"rollback_after_minutes"`
	// CheckNow: an admin pressed "Check now" for this hub, so it may update outside the night
	// window. The server sends it until the hub reports how that update went.
	CheckNow bool    `json:"check_now"`
	Target   *Target `json:"target"`
}

type Window struct {
	Start    string `json:"start"`
	End      string `json:"end"`
	Timezone string `json:"timezone"`
}

// Target is the release this hub should run. ReleaseList is the publisher-signed release
// list (base64), sent to Linux hubs so they can check their package against it.
type Target struct {
	Version         string     `json:"version"`
	ReleaseRevision uint64     `json:"release_revision"`
	Artifacts       []Artifact `json:"artifacts"`
	ReleaseList     string     `json:"release_list,omitempty"`
}

type Artifact struct {
	ID           string `json:"id"`
	Architecture string `json:"architecture"`
	Filename     string `json:"filename"`
	Size         int64  `json:"size"`
	SHA256       string `json:"sha256"`
}

var artifactIDPattern = regexp.MustCompile("^[a-z0-9][a-z0-9-]{1,63}$")
var artifactNamePattern = regexp.MustCompile("^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,159}$")
var digestPattern = regexp.MustCompile("^[a-f0-9]{64}$")

// ParseInstructions reads the file loosely (the server may add fields) but checks every
// value the decision uses.
func ParseInstructions(data []byte) (Instructions, error) {
	var in Instructions
	if len(data) == 0 || len(data) > MaxInstructionsBytes || json.Unmarshal(data, &in) != nil {
		return Instructions{}, errInstructions
	}
	if in.Approval != "approved" && in.Approval != "waiting" {
		return Instructions{}, errInstructions
	}
	if _, err := time.Parse(time.RFC3339, in.ReceivedAt); err != nil {
		return Instructions{}, errInstructions
	}
	return in, nil
}

func (a Artifact) valid() bool {
	return artifactIDPattern.MatchString(a.ID) && artifactNamePattern.MatchString(a.Filename) &&
		a.Size > 0 && a.Size <= 512*1024*1024 && digestPattern.MatchString(a.SHA256) &&
		(a.Architecture == "amd64" || a.Architecture == "arm64")
}

var versionPattern = regexp.MustCompile(`^(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})\.(0|[1-9][0-9]{0,8})$`)

// ValidVersion reports whether v is a plain x.y.z version.
func ValidVersion(v string) bool { return versionPattern.MatchString(v) }

// CompareVersions orders two x.y.z versions: -1, 0 or 1 (0 also when either is invalid).
// Setup uses it to offer "Update to 1.3.1".
func CompareVersions(a, b string) int {
	result, _ := compareVersions(a, b)
	return result
}

// compareVersions compares two x.y.z versions: -1, 0 or 1. ok is false if either is invalid.
func compareVersions(a, b string) (result int, ok bool) {
	x, y := versionPattern.FindStringSubmatch(a), versionPattern.FindStringSubmatch(b)
	if x == nil || y == nil {
		return 0, false
	}
	for i := 1; i <= 3; i++ {
		p, _ := strconv.ParseUint(x[i], 10, 64)
		q, _ := strconv.ParseUint(y[i], 10, 64)
		if p != q {
			if p < q {
				return -1, true
			}
			return 1, true
		}
	}
	return 0, true
}

// Release folders are named r-<revision>-<32 hex>. Revision 0 means "not known": the Linux
// installer uses it because the package doesn't say which build it is.
var stagePattern = regexp.MustCompile("^r-(0|[1-9][0-9]{0,15})-[a-f0-9]{32}$")

// ValidStage reports whether name is a release folder name.
func ValidStage(name string) bool { return stagePattern.MatchString(name) }

// StageRevision is the release revision in a release folder name, or 0 when unknown.
func StageRevision(name string) uint64 {
	match := stagePattern.FindStringSubmatch(name)
	if match == nil {
		return 0
	}
	revision, err := strconv.ParseUint(match[1], 10, 64)
	if err != nil {
		return 0
	}
	return revision
}
