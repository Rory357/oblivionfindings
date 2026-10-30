package collectorhost

import (
	"path/filepath"
	"strings"
	"testing"
)

func TestInstalledPathIsBoundToTheFixedPackageTree(t *testing.T) {
	root := `C:\Program Files`
	stage := "r-7-" + strings.Repeat("a", 32)
	good := filepath.Join(root, "Oblivion", "Agent", "releases", stage, "site", "oblivion-collector.exe")
	actual, err := installedStage(root, good)
	if err != nil || actual != stage {
		t.Fatal(actual, err)
	}
	for _, path := range []string{
		filepath.Join(root, "Other", "Agent", "releases", stage, "site", "oblivion-collector.exe"),
		filepath.Join(root, "Oblivion", "Agent", "releases", "current", "site", "oblivion-collector.exe"),
		filepath.Join(root, "Oblivion", "Agent", "releases", stage, "computer", "oblivion-collector.exe"),
		filepath.Join(root, "Oblivion", "Agent", "releases", stage, "site", "php.exe"),
		filepath.Join(root, "..", "Temp", "Oblivion", "Agent", "releases", stage, "site", "oblivion-collector.exe"),
	} {
		if _, err := installedStage(root, path); err == nil {
			t.Fatal("accepted", path)
		}
	}
}
func TestCollectorServiceRejectsInteractiveInvocation(t *testing.T) {
	if Run() == nil {
		t.Fatal("interactive process accepted as collector service")
	}
}
