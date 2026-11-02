package main

import (
	"errors"
	"os"
	"strings"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
)

// privateDescriptor: this user, SYSTEM and administrators only (the Windows version of 0600).
// An elevated copy of the installer, run by the same person, can still read it.
func privateDescriptor(container bool) (*windows.SECURITY_DESCRIPTOR, error) {
	user, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		return nil, err
	}
	inherit := ""
	if container {
		inherit = "OICI"
	}
	sid := user.User.Sid.String()
	return windows.SecurityDescriptorFromString("D:P(A;" + inherit + ";FA;;;SY)(A;" + inherit + ";FA;;;BA)(A;" + inherit + ";FA;;;" + sid + ")")
}

func makePrivateFolder(path string) error {
	sd, err := privateDescriptor(true)
	if err != nil {
		return err
	}
	name, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return err
	}
	attrs := &windows.SecurityAttributes{Length: uint32(unsafe.Sizeof(windows.SecurityAttributes{})), SecurityDescriptor: sd}
	if err = windows.CreateDirectory(name, attrs); err == nil {
		return nil
	}
	if !errors.Is(err, windows.ERROR_ALREADY_EXISTS) {
		return err
	}
	info, err := os.Lstat(path)
	if err != nil || !info.IsDir() || info.Mode()&os.ModeSymlink != 0 || info.Mode()&os.ModeIrregular != 0 {
		return errors.New("the setup folder is not a plain folder")
	}
	dacl, _, err := sd.DACL()
	if err != nil {
		return err
	}
	return windows.SetNamedSecurityInfo(path, windows.SE_FILE_OBJECT, windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION, nil, nil, dacl, nil)
}

// writePrivateFile replaces the file with a new one created with the private permissions,
// so there's no moment where it's readable by anyone else.
func writePrivateFile(path string, data []byte) error {
	sd, err := privateDescriptor(false)
	if err != nil {
		return err
	}
	if err = os.Remove(path); err != nil && !os.IsNotExist(err) {
		return err
	}
	name, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return err
	}
	attrs := &windows.SecurityAttributes{Length: uint32(unsafe.Sizeof(windows.SecurityAttributes{})), SecurityDescriptor: sd}
	handle, err := windows.CreateFile(name, windows.GENERIC_WRITE, 0, attrs, windows.CREATE_NEW, windows.FILE_ATTRIBUTE_NORMAL, 0)
	if err != nil {
		return err
	}
	file := os.NewFile(uintptr(handle), path)
	_, writeErr := file.Write(data)
	syncErr := file.Sync()
	closeErr := file.Close()
	return errors.Join(writeErr, syncErr, closeErr)
}

// personName is the signed-in person's display name, for "Set up Obli for Aroha Ngata".
func personName() string {
	if name := userNameEx(windows.NameDisplay); name != "" {
		return name
	}
	sam := userNameEx(windows.NameSamCompatible)
	_, user, found := strings.Cut(sam, `\`)
	if !found {
		user = sam
	}
	if user != "" {
		var info *byte
		account, err := windows.UTF16PtrFromString(user)
		if err == nil && windows.NetUserGetInfo(nil, account, 10, &info) == nil && info != nil {
			full := windows.UTF16PtrToString((*userInfo10)(unsafe.Pointer(info)).fullName)
			windows.NetApiBufferFree(info)
			if strings.TrimSpace(full) != "" {
				return strings.TrimSpace(full)
			}
		}
	}
	return user
}

// userInfo10 is USER_INFO_10 (lmaccess.h).
type userInfo10 struct {
	name, comment, userComment, fullName *uint16
}

func userNameEx(format uint32) string {
	size := uint32(256)
	buffer := make([]uint16, size)
	if windows.GetUserNameEx(format, &buffer[0], &size) != nil {
		return ""
	}
	return strings.TrimSpace(windows.UTF16ToString(buffer[:size]))
}

func isElevated() bool { return windows.GetCurrentProcessToken().IsElevated() }

// canElevate: an administrator who only has to click Yes (a split token), or someone already
// elevated. Anyone else would see a password box they can't fill in (windows tab, screen G).
func canElevate() bool {
	token := windows.GetCurrentProcessToken()
	if token.IsElevated() {
		return true
	}
	var kind uint32
	var size uint32
	if windows.GetTokenInformation(token, windows.TokenElevationType, (*byte)(unsafe.Pointer(&kind)), uint32(unsafe.Sizeof(kind)), &size) != nil {
		return false
	}
	return kind == 3 // TokenElevationTypeLimited
}

// windowsProxySetting reads this user's manual proxy from Internet Settings.
func windowsProxySetting() (server, override string, ok bool) {
	key, err := registry.OpenKey(registry.CURRENT_USER, `Software\Microsoft\Windows\CurrentVersion\Internet Settings`, registry.QUERY_VALUE)
	if err != nil {
		return "", "", false
	}
	defer key.Close()
	enabled, _, err := key.GetIntegerValue("ProxyEnable")
	if err != nil || enabled != 1 {
		return "", "", false
	}
	server, _, err = key.GetStringValue("ProxyServer")
	if err != nil || strings.TrimSpace(server) == "" {
		return "", "", false
	}
	override, _, _ = key.GetStringValue("ProxyOverride")
	return server, override, true
}
