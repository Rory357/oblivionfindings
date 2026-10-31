package agent

// ScriptCapability describes the server wire, not this build's availability.
// No production producer sets it until installed execution is implemented.
type ScriptCapability struct {
	Version          int    `json:"version"`
	InstalledService bool   `json:"installed_service"`
	ReleaseEnabled   bool   `json:"release_enabled"`
	Executor         string `json:"executor"`
}
