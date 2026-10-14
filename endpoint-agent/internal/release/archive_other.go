//go:build !windows

package release

import "os"

func openArchive(filename string) (*os.File, error) {
	info, err := os.Lstat(filename)
	if err != nil || !info.Mode().IsRegular() {
		return nil, ErrInvalid
	}
	return os.Open(filename)
}
