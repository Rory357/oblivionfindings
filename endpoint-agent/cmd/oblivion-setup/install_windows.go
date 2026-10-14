package main

import (
	"context"
	"errors"
	"os"
	"runtime"

	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/setup"
)

func installOnPlatform(ctx context.Context, pack *release.Package, profiles setup.Profiles, confirmedServer, confirmedCollector string) (setup.Journal, error) {
	executable, err := os.Executable()
	if err != nil {
		return setup.Journal{}, errors.New("cannot verify the setup executable")
	}
	// Even with a publisher key compiled in, an unsigned setup cannot activate.
	if err = release.VerifyExecutable(executable, runtime.GOARCH); err != nil {
		return setup.Journal{}, errors.New("the setup executable's Windows signature could not be verified")
	}
	return setup.Install(ctx, pack, profiles, confirmedServer, confirmedCollector)
}

func repairOnPlatform(ctx context.Context, pack *release.Package, server, collector, role string, confirmation ...string) (setup.RepairReceipt, error) {
	executable, err := os.Executable()
	if err != nil {
		return setup.RepairReceipt{}, errors.New("cannot verify the setup executable")
	}
	if err = release.VerifyExecutable(executable, runtime.GOARCH); err != nil {
		return setup.RepairReceipt{}, errors.New("the setup executable's Windows signature could not be verified")
	}
	if len(confirmation) == 0 {
		return setup.RepairServices(ctx, pack, server, collector, role)
	}
	if len(confirmation) != 2 {
		return setup.RepairReceipt{}, errors.New("invalid repair confirmation")
	}
	return setup.RepairServicesConfirmed(ctx, pack, server, collector, role, confirmation[0], confirmation[1])
}

func reviewRepairOnPlatform(ctx context.Context, pack *release.Package) (setup.RepairReview, error) {
	executable, err := os.Executable()
	if err != nil {
		return setup.RepairReview{}, errors.New("cannot verify setup executable")
	}
	if err = release.VerifyExecutable(executable, runtime.GOARCH); err != nil {
		return setup.RepairReview{}, errors.New("the setup executable's Windows signature could not be verified")
	}
	return setup.ReviewServiceRepair(ctx, pack)
}
