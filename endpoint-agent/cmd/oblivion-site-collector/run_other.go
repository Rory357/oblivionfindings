//go:build !linux

package main

import (
	"context"
	"errors"
)

func run(context.Context, []string) error {
	return errors.New("Windows Site mode uses the installed collector service")
}
