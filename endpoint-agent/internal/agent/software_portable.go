//go:build !windows

package agent

import (
	"os"
)

func softwareLockArtifact(path string) (*os.File, error) { return os.Open(path) }
