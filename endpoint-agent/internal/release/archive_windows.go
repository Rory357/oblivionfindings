package release

import (
	"golang.org/x/sys/windows"
	"os"
)

// Keep the archive immutable to other processes for verification and extraction.
func openArchive(filename string) (*os.File, error) {
	name, err := windows.UTF16PtrFromString(filename)
	if err != nil {
		return nil, err
	}
	handle, err := windows.CreateFile(name, windows.GENERIC_READ, windows.FILE_SHARE_READ, nil, windows.OPEN_EXISTING, windows.FILE_FLAG_OPEN_REPARSE_POINT, 0)
	if err != nil {
		return nil, err
	}
	info := windows.ByHandleFileInformation{}
	if err = windows.GetFileInformationByHandle(handle, &info); err != nil || info.FileAttributes&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 {
		windows.CloseHandle(handle)
		return nil, ErrInvalid
	}
	return os.NewFile(uintptr(handle), filename), nil
}
