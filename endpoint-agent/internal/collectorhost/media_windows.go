package collectorhost

import (
	"context"
	"oblivion.local/endpoint-agent/internal/cctv"
	"path/filepath"
)

// MediaRelease is disabled in shipping development outputs. Installed approved
// package acceptance is a separate gate, never inferred from compilation.
var MediaRelease = "disabled"

func (i *installed) runMedia(ctx context.Context) error {
	if MediaRelease != "enabled" {
		return nil
	}
	if i.Check(ctx) != nil {
		return errInstallation
	}
	if i.code.CheckRelativeFile("oblivion-media.exe") != nil || i.validatePrivateState() != nil {
		return errInstallation
	}
	return cctv.Supervise(ctx, filepath.Join(i.code.Path(), "runtime", "php.exe"), filepath.Join(i.code.Path(), "bin", "oblivion-collector"), filepath.Join(i.code.Path(), "oblivion-media.exe"), filepath.Join(i.state.Path(), "collector.identity.json"), filepath.Join(i.code.Path(), "runtime"), i.environment)
}
