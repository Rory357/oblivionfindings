package eventsource

import (
	"golang.org/x/sys/windows"
	"strings"
	"testing"
)

func TestEventSourceDescriptorAllowsOnlyAdministratorsToChangeIt(t *testing.T) {
	good, err := windows.SecurityDescriptorFromString(sourceSDDL)
	if err != nil || verifyDescriptor(good) != nil {
		t.Fatal("approved ACL rejected", err)
	}
	for _, text := range []string{
		strings.Replace(sourceSDDL, "O:BA", "O:WD", 1), strings.Replace(sourceSDDL, "D:P", "D:", 1),
		strings.Replace(sourceSDDL, "KR;;;BU", "KA;;;BU", 1), strings.Replace(sourceSDDL, "KA;;;SY", "KR;;;SY", 1),
		sourceSDDL + "(A;;KR;;;WD)", strings.Replace(sourceSDDL, "(A;;KA;;;BA)", "", 1),
	} {
		sd, err := windows.SecurityDescriptorFromString(text)
		if err != nil {
			t.Fatal(err)
		}
		if verifyDescriptor(sd) == nil {
			t.Fatal("unsafe registry permissions accepted", text)
		}
	}
}
func TestWindowsMessageResourceSupportsEveryCurrentEventID(t *testing.T) {
	path, err := messageFile()
	if err != nil {
		t.Fatal(err)
	}
	t.Setenv("SystemRoot", "C:\\not-the-system")
	unchanged, err := messageFile()
	if err != nil || unchanged != path {
		t.Fatal("message path came from inherited environment")
	}
	if err := verifyMessageFile(path); err != nil {
		t.Fatal(err)
	}
}
