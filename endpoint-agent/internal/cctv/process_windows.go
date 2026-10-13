//go:build windows

package cctv

import (
	"os/exec"
	"syscall"
)

func hideProcess(c *exec.Cmd) {
	c.SysProcAttr = &syscall.SysProcAttr{CreationFlags: 0x08000000, HideWindow: true}
}
