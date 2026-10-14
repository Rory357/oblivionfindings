package setup

import (
	"strings"
	"testing"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc/mgr"
)

func TestServicePolicyKeepsControlAndConfigurationAdministrative(t *testing.T) {
	for _, sddl := range []string{serviceSDDL, "D:P(A;;0xf01ff;;;BA)(A;;0x2008d;;;BU)(A;;0xf01ff;;;SY)"} {
		sd, err := windows.SecurityDescriptorFromString(sddl)
		if err != nil {
			t.Fatal(err)
		}
		if err = verifyServiceDescriptor(sd); err != nil {
			t.Fatal("canonical service policy rejected", err)
		}
	}
	invalid := []string{
		strings.Replace(serviceSDDL, "D:P", "D:", 1),
		strings.Replace(serviceSDDL, "0x2008d", "0x2009d", 1), // user start
		strings.Replace(serviceSDDL, "0x2008d", "0x200ad", 1), // user stop
		strings.Replace(serviceSDDL, "0x2008d", "0x2018d", 1), // user control
		strings.Replace(serviceSDDL, "0x2008d", "0x2008f", 1), // change config
		strings.Replace(serviceSDDL, ";;;BU", ";;;WD", 1),
		serviceSDDL + "(A;;GA;;;WD)",
		"D:P(A;;GA;;;SY)(A;;GA;;;BA)",
		strings.Replace(serviceSDDL, "(A;;GA;;;SY)", "(A;IO;GA;;;SY)", 1),
		strings.Replace(serviceSDDL, "(A;;GA;;;SY)", "(D;;GA;;;SY)", 1),
	}
	for _, sddl := range invalid {
		sd, err := windows.SecurityDescriptorFromString(sddl)
		if err != nil {
			t.Fatal(err)
		}
		if verifyServiceDescriptor(sd) == nil {
			t.Fatalf("unsafe service policy accepted: %s", sddl)
		}
	}
}

func TestServiceOwnershipCannotAdoptForeignRegistration(t *testing.T) {
	expected := `"C:\Program Files\Oblivion\Agent\releases\r-7-fixture\computer\oblivion-agent.exe" service`
	attempt := strings.Repeat("a", 32)
	canonical := mgr.Config{BinaryPathName: expected, Description: "Oblivion computer support. Installation " + attempt, ServiceType: windows.SERVICE_WIN32_OWN_PROCESS, ServiceStartName: "LocalSystem", SidType: windows.SERVICE_SID_TYPE_UNRESTRICTED}
	if !ownsServiceConfig(canonical, expected, attempt) {
		t.Fatal("own service rejected")
	}
	for _, mutate := range []func(*mgr.Config){
		func(c *mgr.Config) { c.BinaryPathName = expected + " --other" },
		func(c *mgr.Config) { c.Description = "Another installation" },
		func(c *mgr.Config) { c.ServiceStartName = "LocalService" },
		func(c *mgr.Config) { c.ServiceType = windows.SERVICE_WIN32_SHARE_PROCESS },
		func(c *mgr.Config) { c.SidType = windows.SERVICE_SID_TYPE_NONE },
	} {
		other := canonical
		mutate(&other)
		if ownsServiceConfig(other, expected, attempt) {
			t.Fatal("foreign registration accepted as owned")
		}
	}
}
