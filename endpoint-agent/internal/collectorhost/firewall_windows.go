package collectorhost

import (
	"context"
	"errors"
	"os/exec"
	"path/filepath"
	"syscall"
	"time"

	"golang.org/x/sys/windows"
)

// The hub's sensor relay (S&D Phase B, D21) listens on TCP 1883 for publish-only MQTT from
// sensors on the house network. Windows Firewall silently blocks inbound connections unless a
// rule allows them, so hubs get one rule, by name:
//   - inbound TCP 1883, allowed only for the hub's own PHP runtime;
//   - private and domain networks only, from the local subnet only.
//
// The runtime lives in the release folder, which changes when the hub updates itself, so the
// rule is replaced (deleted by name, then added) whenever setup or a self-update points the
// service at a folder. Remove deletes it.

const RelayFirewallRule = "Oblivion hub - sensor relay"

// RelayRuntime is the program the rule allows: <release folder>\site\runtime\php.exe.
func RelayRuntime(releaseFolder string) string {
	return filepath.Join(releaseFolder, "site", "runtime", "php.exe")
}

// EnsureRelayFirewallRule replaces the rule so it allows exactly this runtime.
func EnsureRelayFirewallRule(program string) error {
	if !filepath.IsAbs(program) || filepath.Base(program) != "php.exe" {
		return errors.New("invalid sensor relay program")
	}
	_ = netsh(`advfirewall firewall delete rule name="` + RelayFirewallRule + `"`)
	if err := netsh(`advfirewall firewall add rule name="` + RelayFirewallRule + `" dir=in action=allow enable=yes protocol=TCP localport=1883 program="` + program + `" profile=private,domain remoteip=localsubnet`); err != nil {
		return errors.New("could not add the Windows Firewall rule for the hub's sensor relay")
	}
	return nil
}

// RemoveRelayFirewallRule deletes the rule. A rule that isn't there is fine.
func RemoveRelayFirewallRule() {
	_ = netsh(`advfirewall firewall delete rule name="` + RelayFirewallRule + `"`)
}

// netsh runs Windows' own netsh.exe from System32 with an exact command line.
func netsh(arguments string) error {
	system, err := windows.GetSystemDirectory()
	if err != nil {
		return err
	}
	program := filepath.Join(system, "netsh.exe")
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	command := exec.CommandContext(ctx, program)
	command.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: windows.CREATE_NO_WINDOW, CmdLine: `"` + program + `" ` + arguments}
	command.Dir = system
	return command.Run()
}
