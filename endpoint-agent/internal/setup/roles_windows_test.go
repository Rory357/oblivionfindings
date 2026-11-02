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

// After a hub updates itself, only the hub service moves to the live release folder; Obli's
// service and the recorded attempt stay as setup made them, so ownership is still checked.
func TestHubServiceFollowsTheLiveReleaseFolderOnly(t *testing.T) {
	j := newJournal()
	j.Role = "both"
	live := "r-41-" + strings.Repeat("c", 32)
	j.siteStage = live
	specs, err := selectedServices(&j)
	if err != nil || len(specs) != 2 {
		t.Fatal(err)
	}
	if !strings.Contains(specs[0].command, j.Stage) || strings.Contains(specs[0].command, live) {
		t.Fatal("Obli's service moved with the hub")
	}
	if !strings.Contains(specs[1].command, live) || strings.Contains(specs[1].command, j.Stage) {
		t.Fatal("the hub service doesn't use the live folder")
	}
	if !strings.HasSuffix(specs[1].description, j.Attempt) {
		t.Fatal("the hub service lost its installation attempt")
	}
	recorded := j
	recorded.siteStage = ""
	before, _ := selectedServices(&recorded)
	movedConfig := mgr.Config{BinaryPathName: specs[1].command, Description: specs[1].description, ServiceType: windows.SERVICE_WIN32_OWN_PROCESS, ServiceStartName: "LocalSystem", SidType: windows.SERVICE_SID_TYPE_UNRESTRICTED}
	if ownsServiceSpecConfig(movedConfig, before[1]) || !ownsServiceSpecConfig(movedConfig, specs[1]) {
		t.Fatal("a moved hub service must match the live folder and nothing else")
	}
}
