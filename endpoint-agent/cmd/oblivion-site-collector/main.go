package main

import (
	"context"
	"os"
	"os/signal"
	"syscall"
)

var MediaRelease = "disabled"

func main() {
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer cancel()
	if run(ctx, os.Args[1:]) != nil {
		os.Exit(1)
	}
}
