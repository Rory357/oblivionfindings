package main

// The Inno Setup installer's other steps (endpoint-agent/installer/inno): reading what's
// already installed, the hub's network check, and Remove. None of these need the release
// package; Remove needs an administrator, except Obli installed for one person.

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"path/filepath"
	"strings"
)

var lifecycleCommands = map[string]func(context.Context, []string, io.Writer) error{
	"redeem":        runRedeem,
	"status":        runStatus,
	"network-check": runNetworkCheck,
	"remove":        runRemove,
	"remove-user":   runRemoveUser,
}

// Exit codes for Remove.
const (
	exitRemovedServerNotTold = 3 // removed, but Oblivion didn't confirm (hubs only)
)

// outFlag reads the single --out <file.ini> these commands take.
func outFlag(name string, args []string, required bool) (string, error) {
	flags := flag.NewFlagSet(name, flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	out := flags.String("out", "", "results file (INI)")
	if flags.Parse(args) != nil || flags.NArg() != 0 || (required && *out == "") || (*out != "" && !filepath.IsAbs(*out)) {
		return "", errors.New("use: oblivion-setup " + name + " --out <file.ini>")
	}
	return *out, nil
}

// runStatus: "Oblivion is already on this computer" (windows tab). No administrator needed.
func runStatus(_ context.Context, args []string, output io.Writer) error {
	out, err := outFlag("status", args, true)
	if err != nil {
		return err
	}
	status := machineStatusOnPlatform()
	values := map[string]string{"installed": boolText(status.installed), "role": status.role, "version": status.version, "running": boolText(status.running)}
	if err = writeINI(out, []iniSection{{"status", values}}); err != nil {
		return err
	}
	_, err = fmt.Fprintf(output, "status: installed=%s role=%s version=%s running=%s\n", values["installed"], status.role, status.version, values["running"])
	return err
}

// runNetworkCheck: "Checking it can see 172.16.0.0/24" (screen 5). It only looks.
func runNetworkCheck(_ context.Context, args []string, output io.Writer) error {
	out, err := outFlag("network-check", args, true)
	if err != nil {
		return err
	}
	values := map[string]string{"subnet": "", "can_see": "0"}
	network, findErr := findLocalNetwork()
	if findErr == nil {
		values["subnet"] = network.subnet
		values["can_see"] = boolText(canSee(network))
	} else {
		values["detail"] = findErr.Error()
	}
	if err = writeINI(out, []iniSection{{"network", values}}); err != nil {
		return err
	}
	_, err = fmt.Fprintf(output, "network: subnet=%s can_see=%s\n", values["subnet"], values["can_see"])
	return err
}

// runRemove: Windows Settings › Apps › Uninstall for a hub or a full computer install.
func runRemove(ctx context.Context, args []string, output io.Writer) error {
	out, err := outFlag("remove", args, false)
	if err != nil {
		return err
	}
	result, removeErr := removeOnPlatform(ctx)
	values := map[string]string{"role": result.role, "server_told": boolText(result.serverTold), "restart_needed": boolText(result.restartNeeded), "state": "removed"}
	if removeErr != nil {
		values["state"] = "failed"
		values["detail"] = removeErr.Error()
	}
	if out != "" {
		if err = writeINI(out, []iniSection{{"remove", values}}); err != nil && removeErr == nil {
			removeErr = err
		}
	}
	fmt.Fprintf(output, "remove: %s role=%s server_told=%s\n", values["state"], result.role, values["server_told"])
	if removeErr != nil {
		return removeErr
	}
	if result.role != "" && result.role != "computer" && !result.serverTold {
		return exitError{code: exitRemovedServerNotTold, message: "remove: Oblivion did not confirm the hub was removed"}
	}
	return nil
}

// runRemoveUser: Uninstall for Obli installed in one person's profile.
func runRemoveUser(_ context.Context, args []string, output io.Writer) error {
	if len(args) != 0 {
		return errors.New("use: oblivion-setup remove-user")
	}
	if err := removeUserOnPlatform(); err != nil {
		return err
	}
	_, err := fmt.Fprintln(output, "remove-user: removed")
	return err
}

// progressPrinter prints "progress: staging started" lines for the installer, or nothing.
func progressPrinter(enabled bool, output io.Writer) func(step, status, detail string) {
	if !enabled {
		return nil
	}
	return func(step, status, detail string) {
		line := "progress: " + step + " " + status
		if detail != "" {
			line += ": " + oneLine(detail)
		}
		fmt.Fprintln(output, strings.TrimSpace(line))
	}
}

type machineStatus struct {
	installed, running bool
	role, version      string
}

type removeResult struct {
	role                      string
	serverTold, restartNeeded bool
}
