//go:build !windows

package release

import "errors"

func verifyBuildExecutable(string, string) error {
	return errors.New("Windows release assembly requires Windows Authenticode verification")
}
