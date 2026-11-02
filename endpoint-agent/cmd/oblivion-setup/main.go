// oblivion-setup verifies publisher releases and coordinates Windows installation.
// Development builds have no publisher key and cannot install anything.
package main

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"os/signal"
	"runtime"
	"time"

	"oblivion.local/endpoint-agent/internal/agent"
	"oblivion.local/endpoint-agent/internal/collectorhost"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/setup"
)

var version = "0.1.0-dev"

// Set only by the controlled build/signing pipeline. Neither the package nor
// the deployment profile may replace the trusted software publisher key.
var publisherPublicKey = ""

type options struct {
	command, archive, bootstrap, collectorBootstrap, confirmedServer, confirmedCollector, role, reviewDigest, reviewExpires, proxy string
	controlStdin                                                                                                                   bool
	confirmRepair                                                                                                                  bool
	progress                                                                                                                       bool
}

func parseOptions(args []string) (options, error) {
	var out options
	commands := map[string]bool{"verify": true, "review": true, "install": true, "repair-services": true, "review-repair": true, "update": true, "install-user": true, "repair": true}
	if len(args) == 0 || !commands[args[0]] {
		return out, errors.New("use: oblivion-setup verify --package <release.zip> | review --package <release.zip> --role computer|site|both [--bootstrap <computer-profile.json>] [--collector-bootstrap <site-profile.json>] | install --package <release.zip> --bootstrap <profile.json> --confirm-server <https-origin> [--collector-bootstrap <site-profile.json>] [--confirm-collector-server <https-origin>] --role computer|site|both [--progress] [--proxy host:port] | install-user --package <release.zip> --bootstrap <computer-profile.json> --confirm-server <https-origin> [--progress] [--proxy host:port] | update --package <newer-release.zip> [--progress] | repair --package <same-release.zip> | review-repair --package <same-release.zip> | repair-services --package <same-release.zip> --role computer|site|both --confirm-server <https-origin> [--confirm-collector-server <https-origin>] --confirm-service-repair | remove | remove-user | status --out <file.ini> | network-check --out <file.ini> | redeem --out <folder> (--installer <path> | --server <host> --code <code>) | version")
	}
	out.command = args[0]
	flags := flag.NewFlagSet(out.command, flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	flags.StringVar(&out.archive, "package", "", "publisher-signed release archive")
	if out.command == "install" || out.command == "install-user" || out.command == "update" {
		// For the installer's screen: one "progress: <step> started|done|failed" line per step.
		flags.BoolVar(&out.progress, "progress", false, "print each step as it starts and finishes")
	}
	if out.command == "install" || out.command == "install-user" {
		flags.StringVar(&out.proxy, "proxy", "", "proxy for the computer's enrolment, when Windows' setting doesn't work")
	}
	if out.command == "install-user" {
		// Obli for this person only: the computer profile, its server, nothing else.
		flags.StringVar(&out.bootstrap, "bootstrap", "", "deployment-issued computer installation profile")
		flags.StringVar(&out.confirmedServer, "confirm-server", "", "exact intended HTTPS server")
		out.role = "computer"
	}
	if out.command == "install" || out.command == "review" {
		flags.StringVar(&out.bootstrap, "bootstrap", "", "deployment-issued computer installation profile")
		flags.StringVar(&out.collectorBootstrap, "collector-bootstrap", "", "deployment-issued Site installation profile")
		if out.command == "install" {
			flags.StringVar(&out.confirmedCollector, "confirm-collector-server", "", "exact dedicated collector server, if different")
			flags.StringVar(&out.confirmedServer, "confirm-server", "", "exact intended HTTPS server")
			flags.StringVar(&out.reviewDigest, "review-digest", "", "exact reviewed package and profiles")
			flags.BoolVar(&out.controlStdin, "control-stdin", false, "cancel on controller input or disconnect")
		}
		flags.StringVar(&out.role, "role", "computer", "computer, site or both")
	}
	if out.command == "repair-services" {
		flags.StringVar(&out.role, "role", "", "installed computer, site or both role")
		flags.StringVar(&out.confirmedServer, "confirm-server", "", "exact installed Oblivion destination")
		flags.StringVar(&out.confirmedCollector, "confirm-collector-server", "", "exact installed collector destination when different")
		flags.StringVar(&out.reviewDigest, "review-digest", "", "exact reviewed installed connection")
		flags.StringVar(&out.reviewExpires, "review-expires", "", "expiry from installed connection review")
		flags.BoolVar(&out.controlStdin, "control-stdin", false, "cancel on controller input or disconnect")
		flags.BoolVar(&out.confirmRepair, "confirm-service-repair", false, "restore missing registration and start installed services without replacing identity or files")
	}
	if flags.Parse(args[1:]) != nil || flags.NArg() != 0 || out.archive == "" {
		return out, errors.New("invalid setup arguments; publisher trust cannot be overridden")
	}
	if out.command == "install-user" && (out.bootstrap == "" || out.confirmedServer == "") {
		return out, errors.New("installing Obli for this person requires the computer profile and its server")
	}
	if out.command == "repair-services" && (!out.confirmRepair || out.confirmedServer == "" || (out.role != "computer" && out.role != "site" && out.role != "both") || out.role == "computer" && out.confirmedCollector != "") {
		return out, errors.New("service repair requires explicit role, installed destination and service-repair confirmation")
	}
	if out.command == "repair-services" && ((out.reviewDigest == "") != (out.reviewExpires == "") || out.reviewDigest != "" && !reviewDigestPattern.MatchString(out.reviewDigest) || out.controlStdin && out.reviewDigest == "") {
		return out, errors.New("controlled service repair requires a complete current review")
	}
	if out.command == "install" || out.command == "review" {
		if out.reviewDigest != "" && !reviewDigestPattern.MatchString(out.reviewDigest) {
			return out, errors.New("invalid review confirmation")
		}
		if out.controlStdin && out.reviewDigest == "" {
			return out, errors.New("controlled installation requires a reviewed package and profiles")
		}
		if out.command == "install" && out.confirmedServer == "" {
			return out, errors.New("installation requires explicit server confirmation")
		}
		switch out.role {
		case "computer":
			if out.bootstrap == "" || out.collectorBootstrap != "" || out.confirmedCollector != "" {
				return out, errors.New("computer role requires only the computer profile")
			}
		case "site":
			if out.collectorBootstrap == "" || out.bootstrap != "" {
				return out, errors.New("Site role requires only the collector profile")
			}
		case "both":
			if out.bootstrap == "" || out.collectorBootstrap == "" {
				return out, errors.New("combined role requires both profiles")
			}
		default:
			return out, errors.New("select computer, site or both")
		}
	}
	return out, nil
}
func main() {
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt)
	defer cancel()
	if err := runContext(ctx, os.Args[1:], os.Stdout); err != nil {
		fmt.Fprintln(os.Stderr, err)
		var coded exitError
		if errors.As(err, &coded) {
			os.Exit(coded.code)
		}
		os.Exit(1)
	}
}
func run(args []string, output io.Writer) error {
	return runContext(context.Background(), args, output)
}
func runContext(ctx context.Context, args []string, output io.Writer) error {
	if len(args) == 1 && args[0] == "version" {
		_, err := fmt.Fprintln(output, version)
		return err
	}
	// The installer's own steps that need no release package (lifecycle.go).
	if len(args) > 0 {
		if handler, ok := lifecycleCommands[args[0]]; ok {
			return handler(ctx, args[1:], output)
		}
	}
	opt, err := parseOptions(args)
	if err != nil {
		return err
	}
	if opt.command == "install" || opt.command == "install-user" {
		useProxyForEnrolment(opt.proxy)
	}
	if opt.controlStdin {
		controlled, cancel := context.WithTimeout(ctx, 15*time.Minute)
		defer cancel()
		go cancelOnControllerInput(os.Stdin, cancel)
		ctx = controlled
	}
	key, err := base64.StdEncoding.Strict().DecodeString(publisherPublicKey)
	if err != nil || len(key) != ed25519.PublicKeySize {
		return errors.New("this development build has no trusted release publisher configured")
	}
	pack, err := release.Open(opt.archive, ed25519.PublicKey(key), runtime.GOARCH, 0, time.Now())
	if err != nil {
		return err
	}
	defer pack.Close()
	manifest := pack.Manifest()
	if opt.command == "verify" {
		return json.NewEncoder(output).Encode(struct {
			Status       string   `json:"status"`
			Version      string   `json:"version"`
			Revision     uint64   `json:"revision"`
			Architecture string   `json:"architecture"`
			Roles        []string `json:"roles"`
		}{"package_verified", manifest.Version, manifest.Revision, manifest.Architecture, manifest.Roles})
	}

	if opt.command == "review-repair" {
		bounded, cancel := context.WithTimeout(ctx, 15*time.Minute)
		defer cancel()
		review, reviewErr := reviewRepairOnPlatform(bounded, pack)
		if reviewErr != nil {
			return reviewErr
		}
		return json.NewEncoder(output).Encode(review)
	}
	if opt.command == "repair-services" {
		bounded, cancel := context.WithTimeout(ctx, 15*time.Minute)
		defer cancel()
		receipt, repairErr := repairOnPlatform(bounded, pack, opt.confirmedServer, opt.confirmedCollector, opt.role, opt.reviewDigest, opt.reviewExpires)
		if repairErr != nil {
			return repairErr
		}
		return json.NewEncoder(output).Encode(receipt)
	}
	if opt.command == "repair" {
		bounded, cancel := context.WithTimeout(ctx, 15*time.Minute)
		defer cancel()
		receipt, repairErr := repairInstalledOnPlatform(bounded, pack)
		if repairErr != nil {
			return repairErr
		}
		return json.NewEncoder(output).Encode(receipt)
	}
	if opt.command == "update" {
		bounded, cancel := context.WithTimeout(ctx, 15*time.Minute)
		defer cancel()
		journal, updateErr := updateOnPlatform(bounded, pack, progressPrinter(opt.progress, output))
		if updateErr != nil {
			return updateErr
		}
		return json.NewEncoder(output).Encode(journal)
	}
	profiles, err := readProfiles(opt)
	if err != nil {
		return err
	}
	if !pack.Supports(opt.role) {
		return errors.New("release does not contain the requested installation role")
	}
	if opt.command == "install-user" {
		bounded, cancel := context.WithTimeout(ctx, 15*time.Minute)
		defer cancel()
		record, installErr := installUserOnPlatform(bounded, pack, *profiles.Computer, opt.confirmedServer, progressPrinter(opt.progress, output))
		if installErr != nil {
			return installErr
		}
		return json.NewEncoder(output).Encode(record)
	}
	review, err := installationReview(pack, profiles)
	if err != nil {
		return err
	}
	if opt.command == "review" {
		return json.NewEncoder(output).Encode(review)
	}
	if opt.reviewDigest != "" && opt.reviewDigest != review.ReviewDigest {
		return errors.New("installation files changed after review; review the setup again")
	}
	if err = ctx.Err(); err != nil {
		return errors.New("installation was cancelled before starting")
	}
	if !opt.controlStdin && !opt.progress {
		// Show only reviewed destination labels; never echo enrolment or API secrets.
		if _, err = fmt.Fprintf(output, "Installing %s at %s\n", opt.role, profiles.ServerURL()); err != nil {
			return err
		}
		if p := profiles.Computer; p != nil {
			if _, err = fmt.Fprintf(output, "Computer: %s / %s\n", p.Organisation, p.DeviceLabel); err != nil {
				return err
			}
		}
		if p := profiles.Collector; p != nil {
			if _, err = fmt.Fprintf(output, "Site monitoring: %s / %s via %s\n", p.Organisation, p.SiteLabel, p.ServerURL); err != nil {
				return err
			}
		}
	}
	journal, err := installOnPlatform(ctx, pack, profiles, opt.confirmedServer, opt.confirmedCollector, progressPrinter(opt.progress, output))
	if err != nil {
		return err
	}
	return json.NewEncoder(output).Encode(journal)
}

func readProfiles(opt options) (setup.Profiles, error) {
	result := setup.Profiles{Role: opt.role}
	if opt.bootstrap != "" {
		file, err := os.Open(opt.bootstrap)
		if err != nil {
			return result, errors.New("cannot open computer installation profile")
		}
		profile, readErr := agent.ReadBootstrap(file, time.Now())
		file.Close()
		if readErr != nil {
			return result, readErr
		}
		result.Computer = &profile
	}
	if opt.collectorBootstrap != "" {
		file, err := os.Open(opt.collectorBootstrap)
		if err != nil {
			return result, errors.New("cannot open Site installation profile")
		}
		profile, readErr := collectorhost.ReadBootstrap(file, time.Now())
		file.Close()
		if readErr != nil {
			return result, readErr
		}
		result.Collector = &profile
	}
	server, collector := opt.confirmedServer, opt.confirmedCollector
	if opt.command == "review" {
		server = result.ServerURL()
		if result.Collector != nil {
			collector = result.Collector.ServerURL
		}
	}
	return result, result.Validate(server, collector, time.Now())
}
