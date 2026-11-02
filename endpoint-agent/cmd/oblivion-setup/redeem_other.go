//go:build !windows

package main

import (
	"os"
	"os/user"
)

// The installer is Windows only; these keep the command buildable and testable elsewhere.

func makePrivateFolder(path string) error {
	if err := os.MkdirAll(path, 0o700); err != nil {
		return err
	}
	return os.Chmod(path, 0o700)
}

func writePrivateFile(path string, data []byte) error {
	if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
		return err
	}
	return os.WriteFile(path, data, 0o600)
}

func personName() string {
	if u, err := user.Current(); err == nil {
		if u.Name != "" {
			return u.Name
		}
		return u.Username
	}
	return ""
}

func isElevated() bool                            { return os.Geteuid() == 0 }
func canElevate() bool                            { return isElevated() }
func windowsProxySetting() (string, string, bool) { return "", "", false }
