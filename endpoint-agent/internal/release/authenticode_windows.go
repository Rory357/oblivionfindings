package release

import (
	"debug/pe"
	"errors"
	"unsafe"

	"golang.org/x/sys/windows"
)

// VerifyExecutable requires both a valid Authenticode chain and the selected
// architecture. The caller separately verifies the publisher-signed manifest,
// which approves these exact bytes. There is no "allow unsigned" mode.
func VerifyExecutable(filename, architecture string) error {
	file, err := openArchive(filename)
	if err != nil {
		return ErrInvalid
	}
	defer file.Close()
	executable, err := pe.NewFile(file)
	if err != nil {
		return errors.New("release executable is not a valid Windows program")
	}
	defer executable.Close()
	expected := uint16(0)
	switch architecture {
	case "amd64":
		expected = pe.IMAGE_FILE_MACHINE_AMD64
	case "arm64":
		expected = pe.IMAGE_FILE_MACHINE_ARM64
	default:
		return ErrInvalid
	}
	if executable.Machine != expected {
		return errors.New("release executable architecture does not match this installation")
	}
	name, err := windows.UTF16PtrFromString(filename)
	if err != nil {
		return ErrInvalid
	}
	info := &windows.WinTrustFileInfo{Size: uint32(unsafe.Sizeof(windows.WinTrustFileInfo{})), FilePath: name, File: windows.Handle(file.Fd())}
	data := &windows.WinTrustData{
		Size:                            uint32(unsafe.Sizeof(windows.WinTrustData{})),
		UIChoice:                        windows.WTD_UI_NONE,
		RevocationChecks:                windows.WTD_REVOKE_WHOLECHAIN,
		UnionChoice:                     windows.WTD_CHOICE_FILE,
		StateAction:                     windows.WTD_STATEACTION_VERIFY,
		FileOrCatalogOrBlobOrSgnrOrCert: unsafe.Pointer(info),
		ProvFlags:                       windows.WTD_REVOCATION_CHECK_CHAIN_EXCLUDE_ROOT | windows.WTD_DISABLE_MD2_MD4,
	}
	verifyErr := windows.WinVerifyTrustEx(windows.InvalidHWND, &windows.WINTRUST_ACTION_GENERIC_VERIFY_V2, data)
	data.StateAction = windows.WTD_STATEACTION_CLOSE
	closeErr := windows.WinVerifyTrustEx(windows.InvalidHWND, &windows.WINTRUST_ACTION_GENERIC_VERIFY_V2, data)
	if verifyErr != nil || closeErr != nil {
		return errors.New("release executable signature or certificate status could not be verified")
	}
	return nil
}
