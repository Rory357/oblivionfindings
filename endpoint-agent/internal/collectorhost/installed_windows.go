package collectorhost

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"strings"

	"golang.org/x/sys/windows"
	"oblivion.local/endpoint-agent/internal/release"
	"oblivion.local/endpoint-agent/internal/securefs"
)

var stagePattern = regexp.MustCompile("^r-[1-9][0-9]{0,15}-[a-f0-9]{32}$")

type installed struct {
	code, state *securefs.Directory
	opened      []*securefs.Directory
	locks       []*os.File
	environment []string
}

func (i *installed) Close() {
	for n := len(i.locks) - 1; n >= 0; n-- {
		i.locks[n].Close()
	}
	for n := len(i.opened) - 1; n >= 0; n-- {
		i.opened[n].Close()
	}
}
func (i *installed) child(parent *securefs.Directory, name string, private bool) (*securefs.Directory, error) {
	d, err := parent.OpenChild(name, private)
	if err == nil {
		i.opened = append(i.opened, d)
	}
	return d, err
}
func (i *installed) root(folder *windows.KNOWNFOLDERID) (*securefs.Directory, error) {
	d, err := securefs.OpenKnownRoot(folder)
	if err == nil {
		i.opened = append(i.opened, d)
	}
	return d, err
}

func openInstalled() (*installed, error) {
	i := &installed{}
	okay := false
	defer func() {
		if !okay {
			i.Close()
		}
	}()
	executable, err := os.Executable()
	if err != nil {
		return nil, errInstallation
	}
	program, err := i.root(windows.FOLDERID_ProgramFiles)
	if err != nil {
		return nil, errInstallation
	}
	stage, err := installedStage(program.Path(), executable)
	if err != nil {
		return nil, err
	}
	parent := program
	for _, name := range []string{"Oblivion", "Agent", "releases", stage, "site"} {
		parent, err = i.child(parent, name, false)
		if err != nil {
			return nil, errInstallation
		}
	}
	i.code = parent
	// Hold every package directory, so no path can be redirected while in use.
	if err = i.holdCodeTree(i.code, 0, new(int)); err != nil {
		return nil, err
	}
	data, err := i.root(windows.FOLDERID_ProgramData)
	if err != nil {
		return nil, errInstallation
	}
	parent, err = i.child(data, "Oblivion", true)
	if err != nil {
		return nil, errInstallation
	}
	i.state, err = i.child(parent, "Collector", true)
	if err != nil {
		return nil, errInstallation
	}
	for _, name := range []string{"Temp", "quarantine", "command-journal"} {
		if _, err = i.child(i.state, name, true); err != nil {
			return nil, errInstallation
		}
	}
	for _, name := range []string{"collector-host.lock", "enrolment.lock"} {
		lock, err := i.state.Lock(name)
		if err != nil {
			return nil, errInstallation
		}
		i.locks = append(i.locks, lock)
	}
	if err = i.validatePrivateState(); err != nil {
		return nil, err
	}
	system, err := windows.GetSystemDirectory()
	if err != nil {
		return nil, errInstallation
	}
	windir, err := windows.GetWindowsDirectory()
	if err != nil {
		return nil, errInstallation
	}
	runtime := filepath.Join(i.code.Path(), "runtime")
	i.environment = []string{
		"SystemRoot=" + windir, "WINDIR=" + windir, "ComSpec=" + filepath.Join(system, "cmd.exe"),
		"PATH=" + strings.Join([]string{runtime, filepath.Join(i.code.Path(), "tools"), system, filepath.Join(system, "Wbem"), filepath.Join(system, "OpenSSH")}, ";"),
		"TEMP=" + filepath.Join(i.state.Path(), "Temp"), "TMP=" + filepath.Join(i.state.Path(), "Temp"),
	}
	okay = true
	return i, nil
}
func installedStage(program, executable string) (string, error) {
	relative, err := filepath.Rel(program, executable)
	if err != nil {
		return "", errInstallation
	}
	parts := strings.Split(relative, string(filepath.Separator))
	if len(parts) != 6 || !strings.EqualFold(parts[0], "Oblivion") || !strings.EqualFold(parts[1], "Agent") || !strings.EqualFold(parts[2], "releases") ||
		!stagePattern.MatchString(parts[3]) || parts[4] != "site" || parts[5] != "oblivion-collector.exe" {
		return "", errInstallation
	}
	return parts[3], nil
}
func (i *installed) holdCodeTree(directory *securefs.Directory, depth int, count *int) error {
	if depth > 20 {
		return errInstallation
	}
	entries, err := os.ReadDir(directory.Path())
	if err != nil {
		return errInstallation
	}
	for _, entry := range entries {
		*count++
		if *count > release.MaxFiles+128 {
			return errInstallation
		}
		if entry.IsDir() {
			child, err := i.child(directory, entry.Name(), false)
			if err != nil {
				return errInstallation
			}
			if err = i.holdCodeTree(child, depth+1, count); err != nil {
				return err
			}
		} else if err = directory.CheckFile(entry.Name()); err != nil {
			return errInstallation
		}
	}
	return nil
}
func (i *installed) validatePrivateState() error {
	for _, name := range []string{"collector.identity.json", "collector.crt.pem", "collector.key.pem", "checkpoint.json"} {
		if i.state.CheckFile(name) != nil {
			return errInstallation
		}
	}
	bytes, err := i.state.ReadFile("collector.identity.json", 16384)
	if err != nil {
		return errInstallation
	}
	defer clear(bytes)
	var identity struct {
		State       string `json:"state_directory"`
		Certificate string `json:"client_certificate_file"`
		Key         string `json:"client_private_key_file"`
	}
	if json.Unmarshal(bytes, &identity) != nil || identity.State != i.state.Path() ||
		identity.Certificate != filepath.Join(i.state.Path(), "collector.crt.pem") || identity.Key != filepath.Join(i.state.Path(), "collector.key.pem") {
		return errInstallation
	}
	// PHP runtime-check performs the complete identity/certificate validation.
	return checkPrivateTree(i.state, 0, new(int))
}
func (i *installed) call(command string) invocation {
	runtime := filepath.Join(i.code.Path(), "runtime")
	args := phpArguments(runtime, filepath.Join(i.code.Path(), "bin", "oblivion-collector"), i.state.Path(), command)
	return invocation{executable: filepath.Join(runtime, "php.exe"), directory: runtime, arguments: args, environment: i.environment}
}
func (i *installed) Check(ctx context.Context) error {
	for _, name := range release.SiteRuntimeFiles() {
		if i.code.CheckRelativeFile(strings.TrimPrefix(name, "site/")) != nil {
			return errInstallation
		}
	}
	if err := i.validatePrivateState(); err != nil {
		return err
	}
	code, err := runChild(ctx, i.call("runtime-check"))
	if err != nil || code != 0 {
		return errors.New("collector packaged runtime or identity is unavailable")
	}
	return nil
}
func (i *installed) Cycle(ctx context.Context) (uint32, error) {
	if err := i.validatePrivateState(); err != nil {
		return 0, err
	}
	return runChild(ctx, i.call("run"))
}

func phpArguments(runtime, script, state, command string) []string {
	args := []string{"-n", "-d", "extension_dir=" + filepath.Join(runtime, "ext")}
	for _, extension := range []string{"curl", "openssl", "sodium", "sockets"} {
		args = append(args, "-d", "extension=php_"+extension+".dll")
	}
	args = append(args, "-d", "curl.cainfo="+filepath.Join(runtime, "cacert.pem"), "-d", "openssl.cafile="+filepath.Join(runtime, "cacert.pem"),
		"-d", "date.timezone=UTC", "-d", "log_errors=0", "-d", "display_errors=stderr",
		"-f", script, "--", command, "--identity="+filepath.Join(state, "collector.identity.json"))
	if command == "run" {
		args = append(args, "--config="+filepath.Join(state, "collector.json"))
	}
	return args
}

func checkPrivateTree(directory *securefs.Directory, depth int, count *int) error {
	if depth > 10 {
		return errInstallation
	}
	entries, err := os.ReadDir(directory.Path())
	if err != nil {
		return errInstallation
	}
	for _, entry := range entries {
		*count++
		if *count > 16384 {
			return errInstallation
		}
		if entry.IsDir() {
			child, err := directory.OpenChild(entry.Name(), true)
			if err != nil {
				return errInstallation
			}
			checkErr := checkPrivateTree(child, depth+1, count)
			child.Close()
			if checkErr != nil {
				return checkErr
			}
		} else if directory.CheckFile(entry.Name()) != nil {
			return errInstallation
		}
	}
	return nil
}
