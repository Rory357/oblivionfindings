package release

import (
	"os"
	"testing"
)

// Package tests assemble packages from unsigned test programs, as CI builds do while no
// code-signing certificate exists (D1). Tests that check the switch itself set it explicitly.
func TestMain(m *testing.M) {
	UnsignedTestBuild = "true"
	os.Exit(m.Run())
}
