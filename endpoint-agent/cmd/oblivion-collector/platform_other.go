//go:build !windows

package main

import "errors"

func runService() error {
	return errors.New("this host is for Windows; use the packaged Linux collector service")
}
