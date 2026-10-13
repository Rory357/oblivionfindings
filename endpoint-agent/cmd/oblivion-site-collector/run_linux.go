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
	php := filepath.Join(root, "runtime", "php")
	script := filepath.Join(root, "bin", "oblivion-collector")
	worker := filepath.Join(root, "oblivion-media")
	for _, p := range []string{php, script} {
		if protected(p, false) != nil {
			return unavailable
		}
	}
	environment := []string{"PATH=" + filepath.Join(root, "runtime"), "LANG=C.UTF-8", "TZ=UTC"}
	mediaContext, stopMedia := context.WithCancel(ctx)
	mediaDone := make(chan struct{})
	defer func() {
		stopMedia()
		select {
		case <-mediaDone:
		case <-time.After(4 * time.Second):
		}
	}()
	if MediaRelease == "enabled" {
		if protected(worker, false) != nil {
			close(mediaDone)
			return unavailable
		}
		go func() {
			defer close(mediaDone)
			_ = cctv.Supervise(mediaContext, php, script, worker, identity, filepath.Join(root, "runtime"), environment)
		}()
	} else {
		close(mediaDone)
	}
	runtime := filepath.Join(root, "runtime")
	arguments := []string{"-n", "-d", "date.timezone=UTC", "-d", "log_errors=0", "-d", "display_errors=stderr"}
	for _, ext := range []string{"curl", "openssl", "sodium", "sockets"} {
		arguments = append(arguments, "-d", "extension="+filepath.Join(runtime, "ext", ext+".so"))
	}
	arguments = append(arguments, "-d", "curl.cainfo="+filepath.Join(runtime, "cacert.pem"), "-d", "openssl.cafile="+filepath.Join(runtime, "cacert.pem"), "-f", script, "--", "run", "--identity="+identity, "--config="+filepath.Join(filepath.Dir(identity), "collector.json"))
	for {
		cycle, cancel := context.WithTimeout(ctx, 10*time.Minute)
		command := exec.CommandContext(cycle, php, arguments...)
		command.Dir = runtime
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
