package servicehost

import (
	"os"
	"path/filepath"

	"golang.org/x/sys/windows"
	"oblivion.local/endpoint-agent/internal/securefs"
)

// Hold the complete trusted directory chain while the service uses its state.
// Do not take ownership of a pre-planted directory, identity or lock file.
func holdInstalledState() (*securefs.Directory, func(), error) {
	var opened []*securefs.Directory
	closeAll := func() {
		for i := len(opened) - 1; i >= 0; i-- {
			opened[i].Close()
		}
	}
	fail := func() (*securefs.Directory, func(), error) { closeAll(); return nil, func() {}, securefs.ErrUnsafe }
	root, err := securefs.OpenKnownRoot(windows.FOLDERID_ProgramData)
	if err != nil {
		return fail()
	}
	opened = append(opened, root)
	organisation, err := root.OpenChild("Oblivion", true)
	if err != nil {
		return fail()
	}
	opened = append(opened, organisation)
	endpoint, err := organisation.OpenChild("Endpoint", true)
	if err != nil {
		return fail()
	}
	opened = append(opened, endpoint)
	if err = endpoint.CheckFile("state.json"); err != nil {
		return fail()
	}
	if _, err = os.Lstat(filepath.Join(endpoint.Path(), "agent.lock")); err == nil {
		if err = endpoint.CheckFile("agent.lock"); err != nil {
			return fail()
		}
	} else if !os.IsNotExist(err) {
		return fail()
	}
	return endpoint, closeAll, nil
}
