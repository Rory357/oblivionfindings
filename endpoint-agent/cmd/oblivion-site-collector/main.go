package main

import (
	"context"
	"errors"
	"os"
	"os/signal"
	"syscall"
)

var MediaRelease = "disabled"

// version is stamped by the build (-X main.version=x.y.z).
var version = "0.0.0-dev"

// errRemoved: the hub was removed in Oblivion (S&D Phase B). main exits with code 3.
var errRemoved = errors.New("this hub was removed in Oblivion; its saved logins have been wiped")

// errRestart: the hub switched to another release (D3). main exits cleanly and systemd
// (Restart=always) starts the release the "current" link now points at.
var errRestart = errors.New("restarting into another release")

func main() {
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer cancel()
	if err := run(ctx, os.Args[1:]); err != nil {
		if errors.Is(err, errRemoved) {
			// Removed in Oblivion: systemd won't restart on exit 3 (RestartPreventExitStatus=3).
			os.Exit(3)
		}
		if errors.Is(err, errRestart) {
			os.Exit(0)
		}
		os.Exit(1)
	}
}
