package agent

import (
	"context"
	"oblivion.local/endpoint-agent/internal/securefs"
)

// OpenInstalledStore never widens installer-owned ACLs to the interactive user.
// The caller holds the protected directory chain throughout the store lifetime.
func OpenInstalledStore(directory *securefs.Directory) (*Store, error) {
	if err := directory.RequirePrivate(); err != nil {
		return nil, err
	}
	lock, err := directory.Lock("agent.lock")
	if err != nil {
		return nil, err
	}
	return &Store{dir: directory.Path(), lock: lock, writeProtected: directory.WriteAtomic, validateProtected: directory.CheckFile}, nil
}
func RunInstalled(ctx context.Context, directory *securefs.Directory, version string, ready func(), report func(string), monitor *StatusMonitor) error {
	store, err := OpenInstalledStore(directory)
	if err != nil {
		return err
	}
	defer store.Close()
	return runFromStore(ctx, store, version, ready, report, monitor)
}
