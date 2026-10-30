package main

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"oblivion.local/endpoint-agent/internal/agent"
	"os"
	"os/signal"
	"runtime"
	"strings"
	"time"
)

var version = "0.1.0-dev"

func main() {
	if len(os.Args) == 2 && os.Args[1] == "version" {
		fmt.Println(version)
		return
	}
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, err.Error())
		os.Exit(1)
	}
}
func run(args []string) error {
	return runWithOutput(args, os.Stdout)
}

func runWithOutput(args []string, stdout io.Writer) error {
	if len(args) == 0 {
		return errors.New("use: oblivion-agent enrol|run|service|probe|status|version")
	}
	if runtime.GOOS != "windows" {
		return errors.New("computer agents support Windows only; use the site collector for Linux monitoring")
	}
	if args[0] == "service" {
		if len(args) != 1 {
			return errors.New("service mode uses the fixed installation state directory")
		}
		return runService(version)
	}
	if args[0] != "enrol" && args[0] != "run" && args[0] != "probe" && args[0] != "status" {
		return errors.New("unknown agent command")
	}
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt)
	defer cancel()
	if args[0] == "probe" {
		if len(args) != 1 {
			return errors.New("probe does not accept arguments")
		}
		heartbeat, err := agent.Collect(ctx, version, 1)
		if err != nil {
			return err
		}
		return json.NewEncoder(stdout).Encode(map[string]any{"platform": runtime.GOOS, "disks": len(heartbeat.Inventory.Disks), "software_count": len(heartbeat.Inventory.Software), "services_count": len(heartbeat.Inventory.Services), "software_complete": heartbeat.Inventory.SoftwareComplete, "services_complete": heartbeat.Inventory.ServicesComplete, "probe_errors": heartbeat.Inventory.ProbeErrors})
	}
	flags := flag.NewFlagSet(args[0], flag.ContinueOnError)
	defaultDir, err := defaultStateDirectory()
	if err != nil {
		return err
	}
	dir := flags.String("state-dir", defaultDir, "private agent state directory")
	var server, key, bootstrap string
	if args[0] == "enrol" {
		flags.StringVar(&server, "server", "", "HTTPS Oblivion origin")
		flags.StringVar(&key, "server-key", "", "Oblivion command signing public key")
		flags.StringVar(&bootstrap, "bootstrap", "", "installation profile downloaded from this organisation's Oblivion")
	}
	if err = flags.Parse(args[1:]); err != nil {
		return err
	}
	if flags.NArg() != 0 {
		return errors.New("unexpected arguments")
	}
	if args[0] == "status" {
		status, err := agent.InspectState(*dir)
		if err != nil {
			return err
		}
		return json.NewEncoder(stdout).Encode(status)
	}
	if args[0] == "run" {
		return agent.Run(ctx, *dir, version, nil, func(message string) { fmt.Fprintln(os.Stderr, message) })
	}

	var profile *agent.Bootstrap
	if bootstrap != "" {
		if server != "" || key != "" {
			return errors.New("use either an installation profile or manual server details")
		}
		f, err := os.Open(bootstrap)
		if err != nil {
			return errors.New("cannot open installation profile")
		}
		parsed, readErr := agent.ReadBootstrap(f, time.Now())
		f.Close()
		if readErr != nil {
			return readErr
		}
		profile = &parsed
		// Validated labels only. Never echo the token or local machine credential.
		fmt.Fprintf(os.Stdout, "Enrolling %s with %s at %s\n", parsed.DeviceLabel, parsed.Organisation, parsed.ServerURL)
	}
	store, err := agent.OpenStore(*dir)
	if err != nil {
		return err
	}
	defer store.Close()
	state, err := store.Load()
	if profile != nil {
		state, err = agent.PrepareBootstrap(store, *profile)
	} else if os.IsNotExist(err) {
		fmt.Fprintln(os.Stderr, "Enter the short-lived enrolment token through standard input:")
		scanner := bufio.NewScanner(os.Stdin)
		scanner.Buffer(make([]byte, 128), 128)
		if !scanner.Scan() {
			return errors.New("enrolment token was not supplied or exceeded its bound")
		}
		state, err = agent.BeginEnrollment(store, server, key, strings.TrimSpace(scanner.Text()))
	} else if err == nil && ((server != "" && server != state.ServerURL) || (key != "" && key != state.ServerKey)) {
		return errors.New("server details do not match the existing installation")
	}
	if err != nil {
		return errors.New("cannot prepare agent enrolment; check the installation profile and protected state")
	}
	client, err := agent.NewClient(state.ServerURL, state.ServerKey)
	if err != nil {
		return err
	}
	if err = client.Enroll(ctx, store, &state, "windows"); err != nil {
		return err
	}
	fmt.Fprintln(os.Stdout, "Agent enrolled. The enrolment token has been removed from local state. Delete the downloaded installation profile after successful enrolment.")
	return nil
}
