package securefs

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"io"
	"os"
	"path/filepath"

	"golang.org/x/sys/windows"
)

func (d *Directory) ReadFile(name string, maximum int64) ([]byte, error) {
	if !validComponent(name) || maximum < 1 {
		return nil, ErrUnsafe
	}
	target := filepath.Join(d.path, name)
	if _, err := os.Lstat(target); err != nil {
		return nil, err
	}
	if err := d.CheckFile(name); err != nil {
		return nil, err
	}
	file, err := os.Open(target)
	if err != nil {
		return nil, ErrUnsafe
	}
	defer file.Close()
	data, err := io.ReadAll(io.LimitReader(file, maximum+1))
	if err != nil || int64(len(data)) > maximum {
		return nil, ErrUnsafe
	}
	return data, nil
}
func (d *Directory) WriteAtomic(name string, data []byte) error {
	if !validComponent(name) || len(data) > 2*1024*1024 {
		return ErrUnsafe
	}
	if _, err := os.Lstat(filepath.Join(d.path, name)); err == nil {
		if err = d.CheckFile(name); err != nil {
			return err
		}
	} else if !os.IsNotExist(err) {
		return ErrUnsafe
	}
	nonce := make([]byte, 16)
	if _, err := rand.Read(nonce); err != nil {
		return err
	}
	temporary := "write-" + hex.EncodeToString(nonce) + ".tmp"
	file, err := d.NewFile(temporary)
	if err != nil {
		return err
	}
	// This exact file was exclusively created in the held, protected directory.
	defer os.Remove(filepath.Join(d.path, temporary))
	_, writeErr := file.Write(data)
	syncErr := file.Sync()
	closeErr := file.Close()
	if writeErr != nil || syncErr != nil || closeErr != nil {
		return errors.New("cannot persist installation record")
	}
	from, _ := windows.UTF16PtrFromString(filepath.Join(d.path, temporary))
	to, _ := windows.UTF16PtrFromString(filepath.Join(d.path, name))
	if err = windows.MoveFileEx(from, to, windows.MOVEFILE_REPLACE_EXISTING|windows.MOVEFILE_WRITE_THROUGH); err != nil {
		return errors.New("cannot commit installation record")
	}
	return nil
}
func (d *Directory) Lock(name string) (*os.File, error) {
	if !validComponent(name) {
		return nil, ErrUnsafe
	}
	target := filepath.Join(d.path, name)
	if _, err := os.Lstat(target); os.IsNotExist(err) {
		f, createErr := d.NewFile(name)
		if createErr != nil {
			return nil, createErr
		}
		if err = f.Close(); err != nil {
			return nil, err
		}
	} else if err != nil {
		return nil, ErrUnsafe
	}
	if err := d.CheckFile(name); err != nil {
		return nil, err
	}
	f, err := os.OpenFile(target, os.O_RDWR, 0600)
	if err != nil {
		return nil, ErrUnsafe
	}
	if err = windows.LockFileEx(windows.Handle(f.Fd()), windows.LOCKFILE_EXCLUSIVE_LOCK|windows.LOCKFILE_FAIL_IMMEDIATELY, 0, 1, 0, &windows.Overlapped{}); err != nil {
		f.Close()
		return nil, errors.New("another installation is already running")
	}
	return f, nil
}
func (d *Directory) CheckRelativeFile(relative string) error {
	parts, err := relativeParts(relative)
	if err != nil {
		return err
	}
	current := d
	var opened []*Directory
	defer func() {
		for i := len(opened) - 1; i >= 0; i-- {
			opened[i].Close()
		}
	}()
	for _, part := range parts[:len(parts)-1] {
		child, err := current.OpenChild(part, d.private)
		if err != nil {
			return err
		}
		opened = append(opened, child)
		current = child
	}
	return current.CheckFile(parts[len(parts)-1])
}
