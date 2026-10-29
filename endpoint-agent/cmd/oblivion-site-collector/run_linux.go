//go:build linux

package main

import (
	"context"
	"errors"
	"io"
	"oblivion.local/endpoint-agent/internal/cctv"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"
)

var unavailable = errors.New("approved Site runtime unavailable")

func protected(path string, private bool) error {
	if !filepath.IsAbs(path) {
		return unavailable
	}
	current := path
	for {
		info, e := os.Lstat(current)
		if e != nil || info.Mode()&os.ModeSymlink != 0 || info.Mode().Perm()&0022 != 0 {
			return unavailable
		}
		owner, ok := info.Sys().(*syscall.Stat_t)
		if !ok || owner.Uid != 0 {
			return unavailable
		}
		if current == path && private && info.Mode().Perm()&0077 != 0 {
			return unavailable
		}
		parent := filepath.Dir(current)
		if parent == current {
			break
		}
		current = parent
	}
	return nil
}

func resolvePhpRuntime(root string) (php string, workDir string, envPath string, arguments []string, bundled bool, err error) {
	bundledPhp := filepath.Join(root, "runtime", "php")
	if _, statErr := os.Lstat(bundledPhp); statErr == nil {
		if protected(bundledPhp, false) != nil {
			return "", "", "", nil, false, unavailable
		}
		runtimeDir := filepath.Join(root, "runtime")
		caFile := filepath.Join(runtimeDir, "cacert.pem")
		if protected(caFile, false) != nil {
			return "", "", "", nil, false, unavailable
		}
		args := []string{"-n", "-d", "date.timezone=UTC", "-d", "log_errors=0", "-d", "display_errors=stderr"}
		// snmp: Phase A, item A7 — hubs read switches over SNMP v2c/v3 (docs/security-devices/GOAL.md).
		for _, ext := range []string{"curl", "openssl", "sodium", "sockets", "snmp"} {
			extPath := filepath.Join(runtimeDir, "ext", ext+".so")
			if protected(extPath, false) != nil {
				return "", "", "", nil, false, unavailable
			}
			args = append(args, "-d", "extension="+extPath)
		}
		args = append(args, "-d", "curl.cainfo="+caFile, "-d", "openssl.cafile="+caFile)
		return bundledPhp, runtimeDir, runtimeDir, args, true, nil
	}

	for _, candidate := range []string{"/usr/bin/php8.4", "/usr/bin/php"} {
		resolved, evalErr := filepath.EvalSymlinks(candidate)
		if evalErr != nil || protected(resolved, false) != nil {
			continue
		}
		caFile := filepath.Join(root, "cacert.pem")
		if protected(caFile, false) != nil {
			resolvedCa, caErr := filepath.EvalSymlinks("/etc/ssl/certs/ca-certificates.crt")
			if caErr != nil || protected(resolvedCa, false) != nil {
				return "", "", "", nil, false, unavailable
			}
			caFile = resolvedCa
		}
		args := []string{
			"-d", "date.timezone=UTC",
			"-d", "log_errors=0",
			"-d", "display_errors=stderr",
			"-d", "curl.cainfo=" + caFile,
			"-d", "openssl.cafile=" + caFile,
		}
		return resolved, root, filepath.Dir(resolved), args, false, nil
	}

	return "", "", "", nil, false, unavailable
}

func run(ctx context.Context, args []string) error {
	if os.Geteuid() != 0 || len(args) != 1 || !strings.HasPrefix(args[0], "--identity=") {
		return unavailable
	}
	identity := strings.TrimPrefix(args[0], "--identity=")
	if protected(identity, true) != nil {
		return unavailable
	}
	executable, e := os.Executable()
	if e != nil || protected(executable, false) != nil {
		return unavailable
	}
	root := filepath.Dir(executable)
	count := 0
	if filepath.WalkDir(root, func(path string, entry os.DirEntry, e error) error {
		count++
		if e != nil || count > 16384 || entry.Type()&os.ModeSymlink != 0 || protected(path, false) != nil {
			return unavailable
		}
		if !entry.IsDir() && !entry.Type().IsRegular() {
			return unavailable
		}
		return nil
	}) != nil {
		return unavailable
	}
	php, workDir, envPath, baseArgs, bundled, resolveErr := resolvePhpRuntime(root)
	if resolveErr != nil {
		return unavailable
	}
	script := filepath.Join(root, "bin", "oblivion-collector")
	worker := filepath.Join(root, "oblivion-media")
	if protected(script, false) != nil {
		return unavailable
	}
	environment := []string{"PATH=" + envPath, "LANG=C.UTF-8", "TZ=UTC"}
	mediaContext, stopMedia := context.WithCancel(ctx)
	mediaDone := make(chan struct{})
	defer func() {
		stopMedia()
		select {
		case <-mediaDone:
		case <-time.After(4 * time.Second):
		}
	}()
	if MediaRelease == "enabled" && bundled {
		if protected(worker, false) != nil {
			close(mediaDone)
			return unavailable
		}
		go func() {
			defer close(mediaDone)
			_ = cctv.Supervise(mediaContext, php, script, worker, identity, workDir, environment)
		}()
	} else {
		close(mediaDone)
	}
	arguments := append(
		append([]string{}, baseArgs...),
		"-f", script, "--", "run",
		"--identity="+identity,
		"--config="+filepath.Join(filepath.Dir(identity), "collector.json"),
	)
	for {
		cycle, cancel := context.WithTimeout(ctx, 10*time.Minute)
		command := exec.CommandContext(cycle, php, arguments...)
		command.Dir = workDir
		command.Env = environment
		command.Stdout = io.Discard
		command.Stderr = io.Discard
		command.WaitDelay = 2 * time.Second
		_ = command.Run()
		cancel()
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(time.Minute):
		}
	}
}
