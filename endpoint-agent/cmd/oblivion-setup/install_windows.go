package main

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"errors"
	"os"
	"runtime"

	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/setup"
)

func verifySetupExecutable(pack *release.Package) error {
	key, err := base64.StdEncoding.Strict().DecodeString(publisherPublicKey)
	if err != nil || len(key) != ed25519.PublicKeySize || pack == nil {
		return errors.New("this development build has no trusted release publisher configured")
	}
	executable, err := os.Executable()
	if err != nil {
		return errors.New("cannot verify the setup executable")
	}
	if err = release.VerifyExecutable(executable, runtime.GOARCH); err != nil {
		return errors.New("the setup executable format or architecture could not be verified")
	}
	return nil
}

func installOnPlatform(ctx context.Context, pack *release.Package, profiles setup.Profiles, confirmedServer, confirmedCollector string, progress setup.Progress) (setup.Journal, error) {
	if err := verifySetupExecutable(pack); err != nil {
		return setup.Journal{}, err
	}
	return setup.InstallWithProgress(ctx, pack, profiles, confirmedServer, confirmedCollector, progress)
}

func updateOnPlatform(ctx context.Context, pack *release.Package, progress setup.Progress) (setup.Journal, error) {
	if err := verifySetupExecutable(pack); err != nil {
		return setup.Journal{}, err
	}
	return setup.Update(ctx, pack, progress)
}

func installUserOnPlatform(ctx context.Context, pack *release.Package, profile agent.Bootstrap, confirmedServer string, progress setup.Progress) (any, error) {
	if err := verifySetupExecutable(pack); err != nil {
		return nil, err
	}
	return setup.InstallForUser(ctx, pack, profile, confirmedServer, progress)
}

func removeOnPlatform(ctx context.Context) (removeResult, error) {
	result, err := setup.Remove(ctx)
	return removeResult{role: result.Role, serverTold: result.ServerTold, restartNeeded: result.RestartNeeded}, err
}

func removeUserOnPlatform() error { return setup.RemoveForUser() }

func machineStatusOnPlatform() machineStatus {
	s := setup.ReadMachineStatus()
	return machineStatus{installed: s.Installed, running: s.Running, role: s.Role, version: s.Version}
}

func repairOnPlatform(ctx context.Context, pack *release.Package, server, collector, role string, confirmation ...string) (setup.RepairReceipt, error) {
	if err := verifySetupExecutable(pack); err != nil {
		return setup.RepairReceipt{}, err
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
	if err := verifySetupExecutable(pack); err != nil {
		return setup.RepairReview{}, err
	}
	return setup.ReviewServiceRepair(ctx, pack)
}

func repairInstalledOnPlatform(ctx context.Context, pack *release.Package) (setup.RepairReceipt, error) {
	if err := verifySetupExecutable(pack); err != nil {
		return setup.RepairReceipt{}, err
	}
	return setup.RepairInstalled(ctx, pack)
}
