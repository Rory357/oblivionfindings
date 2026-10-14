//go:build !windows

package main

import "errors"

func defaultStateDirectory() (string, error) { return "", errors.New("Windows computer agents only") }
func runService(string) error                { return errors.New("Windows computer agents only") }
