//go:build !windows

package cctv

import "os/exec"

func hideProcess(*exec.Cmd) {}
