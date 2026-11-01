//go:build !windows

package main

import "errors"

func runService() error {
	return errors.New("this host is for Windows; use the packaged Linux collector service")
}

func runUpdateHelper() error {
	return errors.New("this host is for Windows; the Linux launcher updates itself")
}
