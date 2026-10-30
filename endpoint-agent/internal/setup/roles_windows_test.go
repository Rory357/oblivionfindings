package setup

import (
	"context"
	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc/mgr"
	"strings"
	"testing"
)

func TestSelectedServicesPreserveSeparateFixedIdentities(t *testing.T) {
	j := newJournal()
	for _, role := range []string{"computer", "site", "both"} {
		j.Role = role
		specs, err := selectedServices(&j)
		count := 1
		if role == "both" {
			count = 2
		}
		if err != nil || len(specs) != count {
			t.Fatal(role, err)
		}
		for _, spec := range specs {
			if !strings.Contains(spec.command, j.Stage) || strings.Contains(spec.command, "--token") {
				t.Fatal("unsafe service command")
			}
			good := mgr.Config{BinaryPathName: spec.command, Description: spec.description, ServiceType: windows.SERVICE_WIN32_OWN_PROCESS, ServiceStartName: "LocalSystem", SidType: windows.SERVICE_SID_TYPE_UNRESTRICTED}
			if !ownsServiceSpecConfig(good, spec) {
				t.Fatal("owned service rejected")
			}
			for _, change := range []func(*mgr.Config){
				func(c *mgr.Config) { c.BinaryPathName += " --other" },
				func(c *mgr.Config) { c.Description = "foreign" },
				func(c *mgr.Config) { c.ServiceStartName = "LocalService" },
				func(c *mgr.Config) { c.ServiceType = windows.SERVICE_WIN32_SHARE_PROCESS },
				func(c *mgr.Config) { c.SidType = windows.SERVICE_SID_TYPE_NONE },
			} {
				bad := good
				change(&bad)
				if ownsServiceSpecConfig(bad, spec) {
					t.Fatal("foreign service adopted")
				}
			}
		}
		if role == "both" && (specs[0].name != "OblivionAgent" || specs[1].name != "OblivionCollector" || specs[0].executable == specs[1].executable) {
			t.Fatal("combined services not separated")
		}
	}
	j.Role = "site"
	// No registry access is needed for a Site-only installation.
	if err := (&windowsHost{}).RegisterTray(context.Background(), &j); err != nil {
		t.Fatal(err)
	}
}
