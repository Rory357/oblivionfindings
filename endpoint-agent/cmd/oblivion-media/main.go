package main

import (
	"context"
	"oblivion.local/endpoint-agent/internal/cctv"
	"os"
)

func main() {
	if len(os.Args) != 1 {
		os.Exit(2)
	}
	if cctv.Serve(context.Background(), os.Stdin, os.Stdout, nil) != nil {
		os.Exit(1)
	}
}
