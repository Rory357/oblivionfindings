//go:build !windows

package main

import (
	"context"
	"errors"

	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/setup"
)

var errWindowsOnly = errors.New("this installer requires Windows; use the Linux Site collector package on Linux")

func installOnPlatform(context.Context, *release.Package, setup.Profiles, string, string, setup.Progress) (setup.Journal, error) {
	return setup.Journal{}, errWindowsOnly
}

func updateOnPlatform(context.Context, *release.Package, setup.Progress) (setup.Journal, error) {
	return setup.Journal{}, errWindowsOnly
}

func installUserOnPlatform(context.Context, *release.Package, agent.Bootstrap, string, setup.Progress) (any, error) {
	return nil, errWindowsOnly
}

func removeOnPlatform(context.Context) (removeResult, error) { return removeResult{}, errWindowsOnly }
func removeUserOnPlatform() error                            { return errWindowsOnly }
func machineStatusOnPlatform() machineStatus                 { return machineStatus{} }

func repairOnPlatform(context.Context, *release.Package, string, string, string, ...string) (setup.RepairReceipt, error) {
	return setup.RepairReceipt{}, errors.New("Windows service repair requires Windows")
}

func reviewRepairOnPlatform(context.Context, *release.Package) (setup.RepairReview, error) {
	return setup.RepairReview{}, errors.New("Windows service repair requires Windows")
}

func repairInstalledOnPlatform(context.Context, *release.Package) (setup.RepairReceipt, error) {
	return setup.RepairReceipt{}, errors.New("Windows service repair requires Windows")
}
