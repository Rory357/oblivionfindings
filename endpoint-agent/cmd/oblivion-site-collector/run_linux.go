//go:build linux

package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"oblivion.local/endpoint-agent/internal/cctv"
	"oblivion.local/endpoint-agent/internal/hubupdate"
	"oblivion.local/endpoint-agent/internal/sensorrelay"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
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

// checkReleaseTree: everything under root is a plain folder or file, owned by root and not
// group or world writable. The launcher checks its own release this way before it starts,
// and checks a new release the same way before switching to it.
func checkReleaseTree(root string) error {
	count := 0
	return filepath.WalkDir(root, func(path string, entry os.DirEntry, e error) error {
		count++
		if e != nil || count > 16384 || entry.Type()&os.ModeSymlink != 0 || protected(path, false) != nil {
			return unavailable
		}
		if !entry.IsDir() && !entry.Type().IsRegular() {
			return unavailable
		}
		return nil
	})
}

// releaseLayout: a hub installed with self-updates runs from <install root>/releases/<stage>,
// and systemd starts it through <install root>/current. An older install runs straight from
// the install root and has no stage.
func releaseLayout(root string) (installRoot, stage string) {
	parent := filepath.Dir(root)
	if filepath.Base(parent) == "releases" && hubupdate.ValidStage(filepath.Base(root)) {
		return filepath.Dir(parent), filepath.Base(root)
	}
	return root, ""
}

// checkCurrentLink checks the "current" link, which is how systemd finds the release to start.
// protected() refuses links anywhere on the launcher's own path (os.Executable resolves this
// one away), so this link is checked here on purpose: it must be owned by root, sit in a
// root-owned folder that nobody else can write to (on Linux that folder is what guards a link;
// a link's own permission bits mean nothing), and point at releases/<release folder>, nowhere else.
func checkCurrentLink(installRoot string) (string, error) {
	link := filepath.Join(installRoot, "current")
	info, err := os.Lstat(link)
	if err != nil || info.Mode()&os.ModeSymlink == 0 {
		return "", unavailable
	}
	owner, ok := info.Sys().(*syscall.Stat_t)
	if !ok || owner.Uid != 0 || protected(installRoot, false) != nil {
		return "", unavailable
	}
	target, err := os.Readlink(link)
	if err != nil {
		return "", unavailable
	}
	stage, found := strings.CutPrefix(target, "releases/")
	if !found || !hubupdate.ValidStage(stage) {
		return "", unavailable
	}
	return stage, nil
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
		// The release's own runtime is a static PHP with the hub's extensions built in (S&D Phase B,
		// CI builds it with static-php-cli and proves each one loads). An older runtime with shared
		// extensions keeps them in runtime/ext, and then every one must be there and protected.
		// snmp: Phase A, item A7 — hubs read switches over SNMP v2c/v3 (docs/security-devices/GOAL.md).
		if info, statErr := os.Lstat(filepath.Join(runtimeDir, "ext")); statErr == nil && info.IsDir() {
			for _, ext := range []string{"curl", "openssl", "sodium", "sockets", "snmp"} {
				extPath := filepath.Join(runtimeDir, "ext", ext+".so")
				if protected(extPath, false) != nil {
					return "", "", "", nil, false, unavailable
				}
				args = append(args, "-d", "extension="+extPath)
			}
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

// run is "--identity=<file>" (the service) or "--update-check --identity=<file>" (the timer an
// update arms, which runs from the old release at the trial's deadline).
func run(ctx context.Context, args []string) error {
	updateCheck := len(args) == 2 && args[0] == "--update-check"
	if updateCheck {
		args = args[1:]
	}
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
	if checkReleaseTree(root) != nil {
		return unavailable
	}
	installRoot, stage := releaseLayout(root)
	if stage != "" {
		if _, err := os.Lstat(filepath.Join(installRoot, "current")); err == nil {
			if _, err := checkCurrentLink(installRoot); err != nil {
				return unavailable
			}
		}
	}
	updates := &linuxUpdates{installRoot: installRoot, stage: stage, root: root, stateDir: filepath.Dir(identity), identity: identity, executable: executable}
	if updateCheck {
		return updates.check(ctx)
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
	updates.php = func(ctx context.Context, command string, extra ...string) *exec.Cmd {
		arguments := append(append([]string{}, baseArgs...), "-f", script, "--", command, "--identity="+identity)
		child := exec.CommandContext(ctx, php, append(arguments, extra...)...)
		child.Dir = workDir
		child.Env = environment
		child.Stdout = io.Discard
		child.Stderr = io.Discard
		child.WaitDelay = 2 * time.Second
		return child
	}
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
	// S&D Phase B (D21): the sensor relay runs beside the cycles and keeps readings while offline.
	relayContext, stopRelay := context.WithCancel(ctx)
	relayDone := make(chan struct{})
	defer func() {
		stopRelay()
		select {
		case <-relayDone:
		case <-time.After(4 * time.Second):
		}
	}()
	go func() {
		defer close(relayDone)
		sensorrelay.Supervise(relayContext, func(ctx context.Context) (uint32, error) {
			return exitCode(updates.php(ctx, "relay").Run())
		}, 5*time.Second, time.Minute)
	}()
	// D3: after each cycle the hub may update itself overnight, and rolls back if it fails.
	updater := &hubupdate.Updater{Host: updates, Platform: "linux", Arch: runtime.GOARCH, Now: time.Now, Log: func(message string) { fmt.Println(message) }}
	for {
		cycle, cancel := context.WithTimeout(ctx, 10*time.Minute)
		code, cycleErr := exitCode(updates.php(cycle, "run", "--config="+filepath.Join(filepath.Dir(identity), "collector.json")).Run())
		cancel()
		if cycleErr == nil && code == 3 {
			// Removed in Oblivion: the hub wiped its saved logins and leaves removed.json.
			// Stop for good; systemd's unit treats exit 3 as final (RestartPreventExitStatus=3).
			return errRemoved
		}
		if ctx.Err() != nil {
			return nil
		}
		if updater.AfterCycle(ctx, code, cycleErr) == hubupdate.Restart {
			// "current" now points at another release: exit, and systemd starts that one.
			return errRestart
		}
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(time.Minute):
		}
	}
}

// exitCode turns a finished PHP cycle into its exit code. A cycle that couldn't start, timed
// out or was killed is an error.
func exitCode(err error) (uint32, error) {
	if err == nil {
		return 0, nil
	}
	var exit *exec.ExitError
	if errors.As(err, &exit) && exit.ExitCode() >= 0 {
		return uint32(exit.ExitCode()), nil
	}
	return 0, err
}
