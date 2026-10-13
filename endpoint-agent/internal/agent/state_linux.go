package agent

import (
	"errors"
	"golang.org/x/sys/unix"
	"os"
)

func protectDirectory(path string) error {
	var st unix.Stat_t
	if err := unix.Lstat(path, &st); err != nil || int(st.Uid) != os.Geteuid() {
		return errors.New("state directory has an unexpected owner")
	}
	return os.Chmod(path, 0700)
}
func lockFile(f *os.File) error         { return unix.Flock(int(f.Fd()), unix.LOCK_EX|unix.LOCK_NB) }
func replaceFile(from, to string) error { return os.Rename(from, to) }
func syncDirectory(path string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	return f.Sync()
}
