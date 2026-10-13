//go:build !windows

package main

import (
	"context"
	"errors"

	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/setup"
)

func installOnPlatform(context.Context, *release.Package, setup.Profiles, string, string) (setup.Journal, error) {
	return setup.Journal{}, errors.New("this installer requires Windows; use the Linux Site collector package on Linux")
}

func repairOnPlatform(context.Context, *release.Package, string, string, string, ...string) (setup.RepairReceipt, error) {
	return setup.RepairReceipt{}, errors.New("Windows service repair requires Windows")
}

func reviewRepairOnPlatform(context.Context, *release.Package) (setup.RepairReview, error) {
	return setup.RepairReview{}, errors.New("Windows service repair requires Windows")
}
