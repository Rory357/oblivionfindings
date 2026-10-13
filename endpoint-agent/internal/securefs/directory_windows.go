// Package securefs holds Windows directory handles throughout installation.
// Production roots come only from Windows known folders. Existing objects are
// verified before use; ownership or permissions are never repaired in place.
package securefs

import (
	"errors"
	"golang.org/x/sys/windows"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"unsafe"
)

const fileAllAccess = windows.STANDARD_RIGHTS_REQUIRED | windows.SYNCHRONIZE | 0x1ff // FILE_ALL_ACCESS in winnt.h

var ErrUnsafe = errors.New("installation path is not protected; administrator review is required")
var componentPattern = regexp.MustCompile("^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,100}$")
var reservedPattern = regexp.MustCompile("^(COM|LPT)[0-9]$")

type Directory struct {
	path      string
	handle    windows.Handle
	ancestors []windows.Handle
	private   bool
}

func (d *Directory) Path() string { return d.path }
func (d *Directory) Close() error {
	if d.handle == windows.InvalidHandle {
		return nil
	}
	err := windows.CloseHandle(d.handle)
	d.handle = windows.InvalidHandle
	for i := len(d.ancestors) - 1; i >= 0; i-- {
		windows.CloseHandle(d.ancestors[i])
	}
	return err
}
func RequireAdministrator() error {
	if !windows.GetCurrentProcessToken().IsElevated() {
		return errors.New("installation requires an elevated administrator")
	}
	return nil
}

// OpenKnownRoot holds every ancestor against rename and rejects path redirection.
// Root security is owned by Windows; children must satisfy our stricter policy.
func OpenKnownRoot(folder *windows.KNOWNFOLDERID) (*Directory, error) {
	if folder != windows.FOLDERID_ProgramData && folder != windows.FOLDERID_ProgramFiles {
		return nil, ErrUnsafe
	}
	path, err := windows.KnownFolderPath(folder, windows.KF_FLAG_DEFAULT)
	if err != nil {
		return nil, ErrUnsafe
	}
	return holdRoot(path)
}
func holdRoot(path string) (*Directory, error) {
	path = filepath.Clean(path)
	volume := filepath.VolumeName(path)
	if !filepath.IsAbs(path) || len(volume) != 2 || volume[1] != ':' {
		return nil, ErrUnsafe
	}
	current := volume + "\\"
	var handles []windows.Handle
	fail := func() (*Directory, error) {
		for _, h := range handles {
			windows.CloseHandle(h)
		}
		return nil, ErrUnsafe
	}
	parts := strings.Split(strings.TrimPrefix(path, current), string(filepath.Separator))
	for i := -1; i < len(parts); i++ {
		if i >= 0 {
			if parts[i] == "" {
				continue
			}
			current = filepath.Join(current, parts[i])
		}
		handle, err := openObject(current, true)
		if err != nil {
			return fail()
		}
		handles = append(handles, handle)
	}
	if len(handles) == 0 {
		return fail()
	}
	return &Directory{path: path, handle: handles[len(handles)-1], ancestors: handles[:len(handles)-1]}, nil
}
func validComponent(name string) bool {
	if !componentPattern.MatchString(name) || strings.HasSuffix(name, ".") {
		return false
	}
	stem := strings.ToUpper(strings.SplitN(name, ".", 2)[0])
	if stem == "CON" || stem == "NUL" || stem == "AUX" || stem == "PRN" || reservedPattern.MatchString(stem) {
		return false
	}
	return true
}
func descriptor(private bool) (*windows.SECURITY_DESCRIPTOR, error) {
	permissions := "O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)"
	if !private {
		permissions += "(A;OICI;FRFX;;;BU)"
	}
	return windows.SecurityDescriptorFromString(permissions)
}

// EnsureChild atomically creates with the final ACL, or verifies an existing
// directory without taking ownership of possibly pre-planted content.
// Keep the parent Directory open until every child operation has completed.
func (d *Directory) EnsureChild(name string, private bool) (*Directory, error) {
	if !validComponent(name) {
		return nil, ErrUnsafe
	}
	target := filepath.Join(d.path, name)
	sd, err := descriptor(private)
	if err != nil {
		return nil, ErrUnsafe
	}
	attrs := &windows.SecurityAttributes{Length: uint32(unsafe.Sizeof(windows.SecurityAttributes{})), SecurityDescriptor: sd}
	encoded, err := windows.UTF16PtrFromString(target)
	if err != nil {
		return nil, ErrUnsafe
	}
	if err = windows.CreateDirectory(encoded, attrs); err != nil && !errors.Is(err, windows.ERROR_ALREADY_EXISTS) {
		return nil, ErrUnsafe
	}
	return d.OpenChild(name, private)
}
func (d *Directory) OpenChild(name string, private bool) (*Directory, error) {
	if !validComponent(name) {
		return nil, ErrUnsafe
	}
	target := filepath.Join(d.path, name)
	handle, err := openObject(target, true)
	if err != nil {
		return nil, ErrUnsafe
	}
	if err = verifyHandle(handle, private, true); err != nil {
		windows.CloseHandle(handle)
		return nil, err
	}
	return &Directory{path: target, handle: handle, private: private}, nil
}
func openObject(path string, directory bool) (windows.Handle, error) {
	name, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return windows.InvalidHandle, ErrUnsafe
	}
	flags := uint32(windows.FILE_FLAG_OPEN_REPARSE_POINT)
	if directory {
		flags |= windows.FILE_FLAG_BACKUP_SEMANTICS
	}
	handle, err := windows.CreateFile(name, windows.GENERIC_READ|windows.READ_CONTROL, windows.FILE_SHARE_READ|windows.FILE_SHARE_WRITE, nil, windows.OPEN_EXISTING, flags, 0)
	if err != nil {
		return windows.InvalidHandle, ErrUnsafe
	}
	info := windows.ByHandleFileInformation{}
	if err = windows.GetFileInformationByHandle(handle, &info); err != nil || info.FileAttributes&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 || (info.FileAttributes&windows.FILE_ATTRIBUTE_DIRECTORY != 0) != directory || (!directory && info.NumberOfLinks != 1) {
		windows.CloseHandle(handle)
		return windows.InvalidHandle, ErrUnsafe
	}
	return handle, nil
}
func verifyHandle(handle windows.Handle, private, directory bool) error {
	sd, err := windows.GetSecurityInfo(handle, windows.SE_FILE_OBJECT, windows.OWNER_SECURITY_INFORMATION|windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		return ErrUnsafe
	}
	return verifyDescriptor(sd, private, directory)
}
func privileged(sid *windows.SID) bool {
	return sid != nil && (sid.IsWellKnown(windows.WinLocalSystemSid) || sid.IsWellKnown(windows.WinBuiltinAdministratorsSid))
}
func verifyDescriptor(sd *windows.SECURITY_DESCRIPTOR, private, directory bool) error {
	owner, _, err := sd.Owner()
	if err != nil || !privileged(owner) {
		return ErrUnsafe
	}
	control, _, err := sd.Control()
	if err != nil || (directory && control&windows.SE_DACL_PROTECTED == 0) {
		return ErrUnsafe
	}
	acl, _, err := sd.DACL()
	if err != nil || acl == nil {
		return ErrUnsafe
	}
	systemFull, adminsFull := false, false
	const readOnly = windows.FILE_GENERIC_READ | windows.FILE_GENERIC_EXECUTE
	for index := uint32(0); index < uint32(acl.AceCount); index++ {
		var ace *windows.ACCESS_ALLOWED_ACE
		if windows.GetAce(acl, index, &ace) != nil || ace.Header.AceType != windows.ACCESS_ALLOWED_ACE_TYPE || ace.Header.AceSize < 20 {
			return ErrUnsafe
		}
		sid := (*windows.SID)(unsafe.Pointer(&ace.SidStart))
		if !sid.IsValid() {
			return ErrUnsafe
		}
		if privileged(sid) {
			full := uint32(ace.Mask)&fileAllAccess == fileAllAccess
			if directory {
				full = full && ace.Header.AceFlags&windows.CONTAINER_INHERIT_ACE != 0 && ace.Header.AceFlags&windows.OBJECT_INHERIT_ACE != 0
			}
			if ace.Header.AceFlags&windows.INHERIT_ONLY_ACE != 0 {
				full = false
			}
			if sid.IsWellKnown(windows.WinLocalSystemSid) {
				systemFull = systemFull || full
			} else {
				adminsFull = adminsFull || full
			}
			continue
		}
		if private || uint32(ace.Mask)&^uint32(readOnly) != 0 {
			return ErrUnsafe
		}
	}
	if !systemFull || !adminsFull {
		return ErrUnsafe
	}
	return nil
}

// CheckFile rejects links, shared hardlinks and weaker per-file ACLs.
func (d *Directory) CheckFile(name string) error {
	if !validComponent(name) {
		return ErrUnsafe
	}
	h, err := openObject(filepath.Join(d.path, name), false)
	if err != nil {
		return err
	}
	defer windows.CloseHandle(h)
	return verifyHandle(h, d.private, false)
}

// NewFile creates an exclusive file with final ownership and permissions.
func (d *Directory) NewFile(name string) (*os.File, error) {
	if !validComponent(name) {
		return nil, ErrUnsafe
	}
	sd, err := descriptor(d.private)
	if err != nil {
		return nil, ErrUnsafe
	}
	attrs := &windows.SecurityAttributes{Length: uint32(unsafe.Sizeof(windows.SecurityAttributes{})), SecurityDescriptor: sd}
	target := filepath.Join(d.path, name)
	encoded, err := windows.UTF16PtrFromString(target)
	if err != nil {
		return nil, ErrUnsafe
	}
	handle, err := windows.CreateFile(encoded, windows.GENERIC_READ|windows.GENERIC_WRITE, 0, attrs, windows.CREATE_NEW, windows.FILE_ATTRIBUTE_NORMAL|windows.FILE_FLAG_OPEN_REPARSE_POINT, 0)
	if err != nil {
		return nil, ErrUnsafe
	}
	return os.NewFile(uintptr(handle), target), nil
}

// WriteFile is the protected target for release.Package.ExtractTo. Every new
// directory and file gets its final ACL and ownership at creation time.
func (d *Directory) WriteFile(relative string, write func(io.Writer) error) error {
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
		child, err := current.EnsureChild(part, d.private)
		if err != nil {
			return err
		}
		opened = append(opened, child)
		current = child
	}
	file, err := current.NewFile(parts[len(parts)-1])
	if err != nil {
		return err
	}
	writeErr := write(file)
	syncErr := file.Sync()
	closeErr := file.Close()
	if writeErr != nil {
		return writeErr
	}
	if syncErr != nil {
		return syncErr
	}
	return closeErr
}

func relativeParts(relative string) ([]string, error) {
	if strings.ContainsAny(relative, "\\:") || strings.HasPrefix(relative, "/") {
		return nil, ErrUnsafe
	}
	parts := strings.Split(relative, "/")
	for _, part := range parts {
		if !validComponent(part) {
			return nil, ErrUnsafe
		}
	}
	return parts, nil
}
func (d *Directory) RequirePrivate() error {
	if !d.private || d.handle == windows.InvalidHandle {
		return ErrUnsafe
	}
	return verifyHandle(d.handle, true, true)
}
