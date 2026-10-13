package securefs

import (
	"golang.org/x/sys/windows"
	"os"
	"path/filepath"
	"testing"
)

func TestDescriptorSeparatesPublicBinariesFromPrivateState(t *testing.T) {
	for _, private := range []bool{false, true} {
		sd, err := descriptor(private)
		if err != nil {
			t.Fatal(err)
		}
		if err = verifyDescriptor(sd, private, true); err != nil {
			t.Fatal("canonical directory policy rejected", err)
		}
		if err = verifyDescriptor(sd, private, false); err != nil {
			t.Fatal("canonical file policy rejected", err)
		}
	}
	public, _ := descriptor(false)
	if err := verifyDescriptor(public, true, true); err == nil {
		t.Fatal("staff-readable directory accepted for private state")
	}
}
func TestDescriptorRejectsPreplantedOwnershipAndWritablePermissions(t *testing.T) {
	cases := []string{
		"O:WDG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)",
		"O:BAG:BAD:(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)",
		"O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)(A;OICI;FW;;;BU)",
		"O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)(A;OICI;WD;;;WD)",
		"O:BAG:BAD:P(A;OICI;FA;;;BA)",
		"O:BAG:BAD:P(A;OICI;FR;;;SY)(A;OICI;FA;;;BA)",
		"O:BAG:BAD:P(A;OIIO;FA;;;SY)(A;OICI;FA;;;BA)",
		"O:BAG:BAD:P(D;;FA;;;WD)(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)",
		"O:BAG:BAD:NO_ACCESS_CONTROL",
	}
	for _, text := range cases {
		sd, err := windows.SecurityDescriptorFromString(text)
		if err != nil {
			t.Fatal("invalid test descriptor", err)
		}
		if err = verifyDescriptor(sd, false, true); err == nil {
			t.Fatalf("unsafe descriptor accepted: %s", text)
		}
	}
}
func TestHeldDirectoryRejectsTraversalAndPreplantedChildrenWithoutRepair(t *testing.T) {
	rootPath := t.TempDir()
	root, err := holdRoot(rootPath)
	if err != nil {
		t.Fatal(err)
	}
	defer root.Close()
	for _, name := range []string{"../escape", "..", "/absolute", "a/b", "a\\b", "con", "nul.txt", "lpt1.exe", "file:stream", "tail.", " space", ""} {
		if child, err := root.EnsureChild(name, true); err == nil {
			child.Close()
			t.Fatalf("unsafe child accepted: %s", name)
		}
	}
	existing := filepath.Join(rootPath, "Existing")
	if err = os.Mkdir(existing, 0700); err != nil {
		t.Fatal(err)
	}
	sentinel := filepath.Join(existing, "keep.txt")
	if err = os.WriteFile(sentinel, []byte("existing content"), 0600); err != nil {
		t.Fatal(err)
	}
	if child, err := root.OpenChild("Existing", true); err == nil {
		child.Close()
		t.Fatal("unprepared user-owned child accepted")
	}
	data, err := os.ReadFile(sentinel)
	if err != nil || string(data) != "existing content" {
		t.Fatal("rejected directory was modified")
	}
}
func TestObjectChecksRejectHardlinksAndWrongObjectTypes(t *testing.T) {
	directory := t.TempDir()
	first := filepath.Join(directory, "first.txt")
	second := filepath.Join(directory, "second.txt")
	if err := os.WriteFile(first, []byte("content"), 0600); err != nil {
		t.Fatal(err)
	}
	if h, err := openObject(first, true); err == nil {
		windows.CloseHandle(h)
		t.Fatal("file accepted as directory")
	}
	if h, err := openObject(directory, false); err == nil {
		windows.CloseHandle(h)
		t.Fatal("directory accepted as file")
	}
	if err := os.Link(first, second); err != nil {
		t.Fatal(err)
	}
	if h, err := openObject(first, false); err == nil {
		windows.CloseHandle(h)
		t.Fatal("hardlinked state file accepted")
	}
}
func TestRootLocksAgainstRenameUntilClosed(t *testing.T) {
	parent := t.TempDir()
	path := filepath.Join(parent, "held")
	if err := os.Mkdir(path, 0700); err != nil {
		t.Fatal(err)
	}
	root, err := holdRoot(path)
	if err != nil {
		t.Fatal(err)
	}
	destination := filepath.Join(parent, "renamed")
	if err = os.Rename(path, destination); err == nil {
		root.Close()
		t.Fatal("held directory was renamed")
	}
	if err = root.Close(); err != nil {
		t.Fatal(err)
	}
	if err = os.Rename(path, destination); err != nil {
		t.Fatal("directory remained locked after close", err)
	}
}
func TestReparseDirectoryIsRejected(t *testing.T) {
	parent := t.TempDir()
	target := filepath.Join(parent, "target")
	link := filepath.Join(parent, "link")
	if err := os.Mkdir(target, 0700); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(target, link); err != nil {
		t.Skip("Windows symlink permission unavailable")
	}
	if root, err := holdRoot(link); err == nil {
		root.Close()
		t.Fatal("reparse root accepted")
	}
}
