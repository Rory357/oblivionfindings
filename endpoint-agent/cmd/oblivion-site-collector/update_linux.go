//go:build linux

package main

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"syscall"
	"time"

	"oblivion.local/endpoint-agent/internal/hubupdate"
)

// Hub self-update on Linux (S&D Phase B, D3). Releases live in <install root>/releases/<stage>
// and systemd starts <install root>/current/oblivion-site-collector. An update unpacks a new,
// checked release next to the running one and swaps the "current" link; a roll-back swaps it
// back. Restarting is systemd's job: the launcher exits and Restart=always starts it again.

const serviceUnit = "oblivion-site-collector.service"

type linuxUpdates struct {
	installRoot, stage, root string
	stateDir, identity       string
	executable               string
	php                      func(ctx context.Context, command string, extra ...string) *exec.Cmd // nil in --update-check
}

func (l *linuxUpdates) Lock(ctx context.Context) (func(), error) {
	path := filepath.Join(l.stateDir, "hub-update.lock")
	file, err := os.OpenFile(path, os.O_RDWR|os.O_CREATE|syscall.O_NOFOLLOW, 0600)
	if err != nil {
		return nil, err
	}
	if protected(path, true) != nil {
		file.Close()
		return nil, unavailable
	}
	deadline := time.Now().Add(30 * time.Second)
	for {
		if syscall.Flock(int(file.Fd()), syscall.LOCK_EX|syscall.LOCK_NB) == nil {
			return func() { file.Close() }, nil
		}
		if time.Now().After(deadline) {
			file.Close()
			return nil, errors.New("the hub update record is busy")
		}
		select {
		case <-ctx.Done():
			file.Close()
			return nil, ctx.Err()
		case <-time.After(250 * time.Millisecond):
		}
	}
}

func (l *linuxUpdates) Load() (hubupdate.State, error) {
	data, err := readPrivate(filepath.Join(l.stateDir, hubupdate.StateFile), 64*1024)
	if os.IsNotExist(err) {
		return hubupdate.DecodeState(nil)
	}
	if err != nil {
		return hubupdate.State{}, err
	}
	return hubupdate.DecodeState(data)
}

func (l *linuxUpdates) Save(s hubupdate.State) error {
	data, err := s.Encode()
	if err != nil {
		return err
	}
	return writePrivate(filepath.Join(l.stateDir, hubupdate.StateFile), data)
}

func (l *linuxUpdates) Running() hubupdate.Running {
	running := hubupdate.Running{Stage: l.stage, Revision: hubupdate.StageRevision(l.stage)}
	if data, err := os.ReadFile(filepath.Join(l.root, "VERSION")); err == nil && len(data) <= 64 {
		if version := strings.TrimSpace(string(data)); hubupdate.ValidVersion(version) {
			running.Version = version
		}
	}
	return running
}

func (l *linuxUpdates) ReadInstructions() ([]byte, error) {
	return readPrivate(filepath.Join(l.stateDir, "hub-instructions.json"), hubupdate.MaxInstructionsBytes)
}

// Fetch runs the PHP hub's update-fetch, which downloads on the hub's signed mTLS path and
// checks size and SHA-256 while it streams.
func (l *linuxUpdates) Fetch(ctx context.Context, artifact hubupdate.Artifact) (string, error) {
	if l.php == nil {
		return "", unavailable
	}
	folder := filepath.Join(l.stateDir, "updates")
	if err := os.Mkdir(folder, 0700); err != nil && !os.IsExist(err) {
		return "", err
	}
	if protected(folder, true) != nil {
		return "", unavailable
	}
	// Start empty: a download cut short by a stop can leave a part file behind.
	entries, err := os.ReadDir(folder)
	if err != nil {
		return "", err
	}
	for _, entry := range entries {
		if !entry.Type().IsRegular() {
			return "", unavailable
		}
		if err := os.Remove(filepath.Join(folder, entry.Name())); err != nil {
			return "", err
		}
	}
	output := filepath.Join(folder, "release.tar.gz")
	bounded, cancel := context.WithTimeout(ctx, 45*time.Minute)
	defer cancel()
	if err := l.php(bounded, "update-fetch", "--artifact="+artifact.ID, "--output="+output).Run(); err != nil {
		_ = os.Remove(output)
		return "", errors.New("the release could not be downloaded")
	}
	return output, nil
}

// Install checks the download against the publisher-signed release list, unpacks it into a
// new release folder, and checks that folder the way the launcher checks itself at start.
func (l *linuxUpdates) Install(_ context.Context, download string, target hubupdate.Target, artifact hubupdate.Artifact, _ hubupdate.Running) (hubupdate.Release, error) {
	var none hubupdate.Release
	if err := hubupdate.CheckLinuxRelease(download, target, artifact, runtime.GOARCH, time.Now()); err != nil {
		return none, err
	}
	releases := filepath.Join(l.installRoot, "releases")
	if protected(releases, false) != nil {
		return none, unavailable
	}
	nonce, err := randomHex(16)
	if err != nil {
		return none, err
	}
	stage := fmt.Sprintf("r-%d-%s", target.ReleaseRevision, nonce)
	folder := filepath.Join(releases, stage)
	if err := os.Mkdir(folder, 0755); err != nil {
		return none, err
	}
	keep := false
	defer func() {
		if !keep {
			_ = os.RemoveAll(folder)
		}
	}()
	if err := os.Chmod(folder, 0755); err != nil { // the service's umask would otherwise trim it
		return none, err
	}
	if err := hubupdate.ExtractLinuxPackage(download, folder, runtime.GOARCH); err != nil {
		return none, err
	}
	if err := hubupdate.CheckLinuxTree(folder, target.Version); err != nil {
		return none, err
	}
	if checkReleaseTree(folder) != nil || protected(filepath.Join(folder, "oblivion-site-collector"), false) != nil {
		return none, unavailable
	}
	keep = true
	return hubupdate.Release{Stage: stage, Version: target.Version, Revision: target.ReleaseRevision}, nil
}

// Switch points "current" at another release folder by renaming a new link over it, so
// systemd always finds either the old link or the new one.
func (l *linuxUpdates) Switch(_ context.Context, stage string) error {
	if !hubupdate.ValidStage(stage) || protected(filepath.Join(l.installRoot, "releases", stage, "oblivion-site-collector"), false) != nil {
		return unavailable
	}
	link := filepath.Join(l.installRoot, "current")
	if _, err := os.Lstat(link); err == nil {
		current, err := checkCurrentLink(l.installRoot)
		if err != nil {
			return unavailable // never replace something that isn't our own link
		}
		if current == stage {
			return nil
		}
	}
	nonce, err := randomHex(8)
	if err != nil {
		return err
	}
	temporary := filepath.Join(l.installRoot, ".current-"+nonce)
	if err := os.Symlink("releases/"+stage, temporary); err != nil {
		return err
	}
	if err := os.Rename(temporary, link); err != nil {
		_ = os.Remove(temporary)
		return err
	}
	syncFolder(l.installRoot)
	if current, err := checkCurrentLink(l.installRoot); err != nil || current != stage {
		return unavailable
	}
	return nil
}

// HandOver: the launcher exits after this and systemd starts the release "current" points at.
// During a trial it first arms a one-off systemd timer that runs this (old, known-good)
// launcher with --update-check just after the deadline. The timer lives outside the service,
// so it still runs if the new release can't even start.
func (l *linuxUpdates) HandOver(ctx context.Context, watchUntil time.Time) error {
	if watchUntil.IsZero() {
		return nil
	}
	systemdRun, err := trustedTool("systemd-run")
	if err != nil {
		return err
	}
	seconds := int(time.Until(watchUntil.Add(hubupdate.WatchGrace)).Seconds()) + 1
	if seconds < 1 {
		seconds = 1
	}
	nonce, err := randomHex(6)
	if err != nil {
		return err
	}
	bounded, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()
	timer := exec.CommandContext(bounded, systemdRun, "--quiet", "--collect", "--unit=oblivion-hub-update-check-"+nonce,
		"--on-active="+strconv.Itoa(seconds)+"s", "--timer-property=AccuracySec=1s",
		l.executable, "--update-check", "--identity="+l.identity)
	timer.Env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8"}
	timer.Stdout, timer.Stderr = io.Discard, io.Discard
	return timer.Run()
}

// check is the timer's run (--update-check), from the old release at the trial's deadline.
func (l *linuxUpdates) check(ctx context.Context) error {
	rolledBack, err := hubupdate.Watch(ctx, l, time.Now)
	if err != nil || !rolledBack {
		return err
	}
	systemctl, err := trustedTool("systemctl")
	if err != nil {
		return err
	}
	restart := exec.CommandContext(ctx, systemctl, "restart", serviceUnit)
	restart.Env = []string{"PATH=/usr/sbin:/usr/bin:/sbin:/bin", "LANG=C.UTF-8"}
	restart.Stdout, restart.Stderr = io.Discard, io.Discard
	return restart.Run()
}

func (l *linuxUpdates) Report(ctx context.Context, r hubupdate.Report) error {
	if l.php == nil {
		return unavailable
	}
	args := []string{"--outcome=" + r.Outcome, "--to=" + r.To}
	if r.From != "" {
		args = append(args, "--from="+r.From)
	}
	if r.Detail != "" {
		args = append(args, "--detail="+r.Detail)
	}
	bounded, cancel := context.WithTimeout(ctx, 2*time.Minute)
	defer cancel()
	return l.php(bounded, "update-report", args...).Run()
}

// RemoveStage never removes the running release or the one "current" points at.
func (l *linuxUpdates) RemoveStage(stage string) error {
	if !hubupdate.ValidStage(stage) || stage == l.stage {
		return unavailable
	}
	if current, err := checkCurrentLink(l.installRoot); err != nil || current == stage {
		return unavailable
	}
	return os.RemoveAll(filepath.Join(l.installRoot, "releases", stage))
}

func (l *linuxUpdates) Discard(download string) { _ = os.Remove(download) }

// trustedTool finds a system program that only root can change.
func trustedTool(name string) (string, error) {
	for _, candidate := range []string{"/usr/bin/" + name, "/bin/" + name} {
		resolved, err := filepath.EvalSymlinks(candidate)
		if err == nil && protected(resolved, false) == nil {
			return resolved, nil
		}
	}
	return "", unavailable
}

func readPrivate(path string, maximum int64) ([]byte, error) {
	if _, err := os.Lstat(path); err != nil {
		return nil, err
	}
	if protected(path, true) != nil {
		return nil, unavailable
	}
	file, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		return nil, err
	}
	defer file.Close()
	data, err := io.ReadAll(io.LimitReader(file, maximum+1))
	if err != nil || int64(len(data)) > maximum {
		return nil, unavailable
	}
	return data, nil
}

// writePrivate replaces a private state file atomically (write, sync, rename).
func writePrivate(path string, data []byte) error {
	nonce, err := randomHex(8)
	if err != nil {
		return err
	}
	temporary := path + ".tmp-" + nonce
	file, err := os.OpenFile(temporary, os.O_WRONLY|os.O_CREATE|os.O_EXCL|syscall.O_NOFOLLOW, 0600)
	if err != nil {
		return err
	}
	_, writeErr := file.Write(data)
	syncErr := file.Sync()
	closeErr := file.Close()
	if err := errors.Join(writeErr, syncErr, closeErr); err != nil {
		_ = os.Remove(temporary)
		return err
	}
	if err := os.Rename(temporary, path); err != nil {
		_ = os.Remove(temporary)
		return err
	}
	syncFolder(filepath.Dir(path))
	return nil
}

func syncFolder(path string) {
	if folder, err := os.Open(path); err == nil {
		_ = folder.Sync()
		folder.Close()
	}
}

func randomHex(size int) (string, error) {
	value := make([]byte, size)
	if _, err := rand.Read(value); err != nil {
		return "", err
	}
	return hex.EncodeToString(value), nil
}
