package collectorhost

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"golang.org/x/sys/windows"
)

// Only this test binary is launched, never a real collector or host service.
func TestCollectorProcessFixture(t *testing.T) {
	mode := os.Getenv("OBLI_PROCESS_FIXTURE")
	if mode == "" {
		return
	}
	marker := os.Getenv("OBLI_PROCESS_MARKER")
	switch mode {
	case "inspect":
		bytes, _ := json.Marshal(map[string]any{"secret": os.Getenv("OBLI_PARENT_TEST_SECRET"), "args": os.Args[1:]})
		_ = os.WriteFile(marker, bytes, 0600)
		os.Exit(0)
	case "input":
		input, err := io.ReadAll(io.LimitReader(os.Stdin, 4097))
		if err != nil {
			os.Exit(9)
		}
		bytes, _ := json.Marshal(map[string]any{"input": string(input), "args": os.Args[1:], "env": os.Environ()})
		_ = os.WriteFile(marker, bytes, 0600)
		os.Exit(0)
	case "exit":
		os.Exit(7)
	case "descendant":
		_ = os.WriteFile(marker, []byte(strconv.Itoa(os.Getpid())), 0600)
		time.Sleep(30 * time.Second)
		os.Exit(0)
	case "spawn", "spawn-return":
		child := exec.Command(os.Args[0], "-test.run=^TestCollectorProcessFixture$")
		child.Env = []string{"OBLI_PROCESS_FIXTURE=descendant", "OBLI_PROCESS_MARKER=" + marker, "SystemRoot=" + os.Getenv("SystemRoot")}
		if child.Start() != nil {
			os.Exit(6)
		}
		deadline := time.Now().Add(5 * time.Second)
		for time.Now().Before(deadline) {
			if _, err := os.Stat(marker); err == nil {
				break
			}
			time.Sleep(10 * time.Millisecond)
		}
		if mode == "spawn-return" {
			os.Exit(0)
		}
		time.Sleep(30 * time.Second)
		os.Exit(0)
	default:
		os.Exit(8)
	}
}
func processFixture(t *testing.T, mode string) (invocation, string) {
	t.Helper()
	executable, err := os.Executable()
	if err != nil {
		t.Fatal(err)
	}
	marker := filepath.Join(t.TempDir(), "marker.json")
	windir, err := windows.GetWindowsDirectory()
	if err != nil {
		t.Fatal(err)
	}
	return invocation{executable: executable, directory: filepath.Dir(executable), arguments: []string{"-test.run=^TestCollectorProcessFixture$"},
		environment: []string{"OBLI_PROCESS_FIXTURE=" + mode, "OBLI_PROCESS_MARKER=" + marker, "SystemRoot=" + windir}}, marker
}
func waitMarker(t *testing.T, path string) []byte {
	t.Helper()
	deadline := time.Now().Add(5 * time.Second)
	for time.Now().Before(deadline) {
		if bytes, err := os.ReadFile(path); err == nil && len(bytes) > 0 {
			return bytes
		}
		time.Sleep(15 * time.Millisecond)
	}
	t.Fatal("fixture did not start")
	return nil
}
func waitProcessGone(t *testing.T, bytes []byte) {
	t.Helper()
	pid, err := strconv.Atoi(string(bytes))
	if err != nil {
		t.Fatal(err)
	}
	process, err := windows.OpenProcess(windows.SYNCHRONIZE|windows.PROCESS_QUERY_LIMITED_INFORMATION, false, uint32(pid))
	if errors.Is(err, windows.ERROR_INVALID_PARAMETER) {
		return
	}
	if err != nil {
		t.Fatal(err)
	}
	defer windows.CloseHandle(process)
	state, err := windows.WaitForSingleObject(process, 3000)
	if err != nil || state != windows.WAIT_OBJECT_0 {
		t.Fatal("descendant survived job cleanup", state, err)
	}
}
func TestProcessUsesExactArgumentsAndExplicitEnvironment(t *testing.T) {
	t.Setenv("OBLI_PARENT_TEST_SECRET", "must-not-cross-boundary")
	call, marker := processFixture(t, "inspect")
	call.arguments = append(call.arguments, "--", "space and unicode ā", "quotes \" and backslash\\", "$(not-a-shell)")
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	code, err := runChild(ctx, call)
	if err != nil || code != 0 {
		t.Fatal(code, err)
	}
	var result struct {
		Secret string
		Args   []string
	}
	if err = json.Unmarshal(waitMarker(t, marker), &result); err != nil {
		t.Fatal(err)
	}
	if result.Secret != "" || fmt.Sprint(result.Args) != fmt.Sprint(call.arguments) {
		t.Fatalf("environment/argument boundary failed: %+v", result)
	}
}
func TestProcessSuppliesSecretOnlyThroughBoundedInput(t *testing.T) {
	call, marker := processFixture(t, "input")
	secret := "ofc_enrol_" + strings.Repeat("A", 64)
	call.input = []byte(secret)
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	code, err := runChild(ctx, call)
	if err != nil || code != 0 {
		t.Fatal(code, err)
	}
	var result struct {
		Input     string
		Args, Env []string
	}
	if json.Unmarshal(waitMarker(t, marker), &result) != nil || result.Input != secret ||
		strings.Contains(fmt.Sprint(result.Args, result.Env), secret) {
		t.Fatal("secret input boundary failed")
	}
	call.input = make([]byte, 4097)
	if _, err := runChild(ctx, call); err == nil {
		t.Fatal("oversized input accepted")
	}
}

func TestProcessReportsExitCodeWithoutLeakingChildOutput(t *testing.T) {
	call, _ := processFixture(t, "exit")
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	code, err := runChild(ctx, call)
	if err != nil || code != 7 {
		t.Fatal(code, err)
	}
}
func TestProcessCancellationKillsTheWholeTree(t *testing.T) {
	call, marker := processFixture(t, "spawn")
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	done := make(chan error, 1)
	go func() { _, err := runChild(ctx, call); done <- err }()
	pid := waitMarker(t, marker)
	cancel()
	select {
	case err := <-done:
		if !errors.Is(err, context.Canceled) {
			t.Fatal(err)
		}
	case <-time.After(7 * time.Second):
		t.Fatal("host did not stop")
	}
	waitProcessGone(t, pid)
}
func TestProcessCompletionAlsoKillsLeftoverDescendants(t *testing.T) {
	call, marker := processFixture(t, "spawn-return")
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	code, err := runChild(ctx, call)
	if err != nil || code != 0 {
		t.Fatal(code, err)
	}
	waitProcessGone(t, waitMarker(t, marker))
}
func TestProcessRejectsUnsafeInputsAndDoesNotStartWhenCancelled(t *testing.T) {
	call, marker := processFixture(t, "inspect")
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if _, err := runChild(ctx, call); !errors.Is(err, context.Canceled) {
		t.Fatal(err)
	}
	if _, err := os.Stat(marker); !os.IsNotExist(err) {
		t.Fatal("cancelled process started")
	}
	for _, values := range [][]string{{"MISSING"}, {"=value"}, {"a=1", "A=2"}, {"a=x\x00y"}, {strings.Repeat("a", 33000) + "=x"}} {
		if _, err := environmentBlock(values); err == nil {
			t.Fatal("invalid environment accepted")
		}
	}
	call.executable = "relative.exe"
	if _, err := runChild(context.Background(), call); err == nil {
		t.Fatal("relative executable accepted")
	}
}
