package agent

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"runtime"
	"strings"
	"time"
	"unicode"
)

type Disk struct {
	Name  string `json:"name"`
	Total uint64 `json:"total_bytes"`
	Free  uint64 `json:"free_bytes"`
}
type Software struct {
	Name      string  `json:"name"`
	Version   *string `json:"version"`
	Publisher *string `json:"publisher"`
}
type Service struct {
	Name  string `json:"name"`
	State string `json:"state"`
}
type Inventory struct {
	Hostname         string     `json:"hostname"`
	OSVersion        string     `json:"os_version"`
	Architecture     string     `json:"architecture"`
	MemoryTotal      *uint64    `json:"memory_total_bytes"`
	MemoryAvailable  *uint64    `json:"memory_available_bytes"`
	Uptime           *uint64    `json:"uptime_seconds"`
	CPU              *float64   `json:"cpu_percent"`
	Disks            []Disk     `json:"disks"`
	Software         []Software `json:"software"`
	SoftwareComplete bool       `json:"software_complete"`
	Services         []Service  `json:"services"`
	ServicesComplete bool       `json:"services_complete"`
	ProbeErrors      []string   `json:"probe_errors"`
}
type Heartbeat struct {
	ScriptActions             *ScriptCapability          `json:"script_actions,omitempty"`
	SoftwareActions           *SoftwareCapability        `json:"software_actions,omitempty"`
	SoftwareObservations      []SoftwareObservation      `json:"software_observations,omitempty"`
	RestartActions            *RestartCapability         `json:"restart_actions,omitempty"`
	RestartObservations       []RestartObservation       `json:"restart_observations,omitempty"`
	ServiceActionObservations []ServiceActionObservation `json:"service_action_observations,omitempty"`
	ServiceActions            *ServiceActionCapability   `json:"service_actions,omitempty"`
	Sequence                  uint64                     `json:"sequence"`
	ObservedAt                string                     `json:"observed_at"`
	AgentVersion              string                     `json:"agent_version"`
	BootID                    string                     `json:"boot_id"`
	Inventory                 Inventory                  `json:"inventory"`
}

func Collect(ctx context.Context, version string, sequence uint64) (Heartbeat, error) {
	inventory := Inventory{Architecture: runtime.GOARCH, Disks: []Disk{}, Software: []Software{}, Services: []Service{}, ProbeErrors: []string{}}
	host, err := os.Hostname()
	if err != nil {
		return Heartbeat{}, errors.New("host identity unavailable")
	}
	inventory.Hostname = safeText(host)
	boot, err := probe(ctx, &inventory)
	if err != nil {
		return Heartbeat{}, err
	}
	return Heartbeat{Sequence: sequence, ObservedAt: time.Now().UTC().Format(time.RFC3339), AgentVersion: version, BootID: boot, Inventory: inventory}, nil
}

// PrepareHeartbeat writes before transmission. Crashes and lost replies preserve one pending observation.
func PrepareHeartbeat(ctx context.Context, store *Store, state *State, version string) ([]byte, error) {
	if len(state.PendingHeartbeat) > 0 {
		var old Heartbeat
		if err := json.Unmarshal(state.PendingHeartbeat, &old); err != nil {
			return nil, errors.New("pending heartbeat is corrupt")
		}
		available := store.serviceActionReady != nil && store.serviceActionReady()
		if pendingHeartbeatReusable(old, available, time.Now()) && (old.RestartActions != nil) == (store.restartReady != nil && store.restartReady()) && (old.SoftwareActions != nil) == (store.softwareReady != nil && store.softwareReady()) {
			return state.PendingHeartbeat, nil
		}
	}
	if state.Sequence >= 9007199254740991 {
		return nil, errors.New("agent sequence exhausted; approved recovery required")
	}
	next := *state
	next.Sequence++
	heartbeat, err := Collect(ctx, version, next.Sequence)
	if store.serviceActionReady != nil && store.serviceActionReady() {
		if store.serviceActionObservations != nil {
			heartbeat.ServiceActionObservations = store.serviceActionObservations(ctx)
			heartbeat.ObservedAt = time.Now().UTC().Format(time.RFC3339)
		}
		heartbeat.ServiceActions = &ServiceActionCapability{Version: 1, InstalledService: true, ReleaseEnabled: true, Executor: "windows_scm", Operations: []string{"start", "stop", "restart"}}
	}
	if err != nil {
		return nil, err
	}
	if store.restartReady != nil && store.restartReady() {
		heartbeat.RestartActions = &RestartCapability{1, true, true, "windows_restart"}
	}
	if store.restartObservations != nil {
		heartbeat.RestartObservations = store.restartObservations(heartbeat.BootID, heartbeat.ObservedAt)
	}
	if store.softwareReady != nil && store.softwareReady() {
		heartbeat.SoftwareActions = &SoftwareCapability{1, true, true, "windows_msi"}
	}
	if store.softwareObservations != nil {
		heartbeat.SoftwareObservations = store.softwareObservations(heartbeat.BootID, heartbeat.ObservedAt)
	}
	data, err := json.Marshal(heartbeat)
	if err != nil || len(data) > 1024*1024 {
		return nil, errors.New("inventory exceeds reporting limit")
	}
	next.PendingHeartbeat = data
	if err := store.Save(next); err != nil {
		return nil, err
	}
	*state = next
	return data, nil
}

func safeText(value string) string {
	value = strings.Map(func(r rune) rune {
		if unicode.IsControl(r) {
			return -1
		}
		return r
	}, value)
	runes := []rune(strings.TrimSpace(value))
	if len(runes) > 300 {
		runes = runes[:300]
	}
	return string(runes)
}
func textPtr(value string) *string {
	v := safeText(value)
	if v == "" {
		return nil
	}
	return &v
}
func bootUUID(seed string) string {
	hash := sha256.Sum256([]byte(seed))
	hash[6] = (hash[6] & 15) | 0x50
	hash[8] = (hash[8] & 63) | 0x80
	encoded := hex.EncodeToString(hash[:16])
	return encoded[:8] + "-" + encoded[8:12] + "-" + encoded[12:16] + "-" + encoded[16:20] + "-" + encoded[20:]
}

func pendingHeartbeatReusable(old Heartbeat, serviceActions bool, now time.Time) bool {
	when, e := time.Parse(time.RFC3339, old.ObservedAt)
	// There is no installed reviewed-script executor yet. Never retransmit an
	// old/future capability claim just because a pending observation is recent.
	return old.ScriptActions == nil && e == nil && now.Sub(when) < 90*time.Second && when.Sub(now) < 30*time.Second && (old.ServiceActions != nil) == serviceActions
}
