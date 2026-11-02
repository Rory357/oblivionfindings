//go:build !windows

package release

import "os"

// verifyAuthenticode can't run off Windows, so it never vouches for a file. A build made off
// Windows accepts Windows programs only when it is an explicit unsigned test build.
func verifyAuthenticode(string, *os.File) error {
	return errUnsigned
}
