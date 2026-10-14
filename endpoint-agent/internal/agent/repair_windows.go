package agent

import (
	"errors"
	"oblivion.local/endpoint-agent/internal/securefs"
)

// Only stable identity contributes; heartbeat sequence/spool changes must not
// invalidate a technician's review. No credential or key is returned.
func InstalledRepairBinding(directory *securefs.Directory, uuid, server string) (string, error) {
	if directory == nil {
		return "", errors.New("computer identity is unavailable")
	}
	if err := directory.RequirePrivate(); err != nil {
		return "", err
	}
	state, err := (&Store{dir: directory.Path(), validateProtected: directory.CheckFile}).Load()
	if err != nil {
		return "", errors.New("computer identity requires approved recovery")
	}
	if err = checkRepairIdentity(state, uuid, server); err != nil {
		return "", err
	}
	return repairIdentityBinding(state), nil
}
func CheckInstalledRepairIdentity(directory *securefs.Directory, uuid, server string) error {
	_, err := InstalledRepairBinding(directory, uuid, server)
	return err
}
