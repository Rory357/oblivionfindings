package release

import (
	"os"
	"unsafe"

	"golang.org/x/sys/windows"
)

// verifyAuthenticode asks Windows to check the file's Authenticode signature with full-chain
// revocation checks (restored from before 28 Oct 2026, commit 7f1fdcfed).
func verifyAuthenticode(filename string, file *os.File) error {
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
		return errUnsigned
	}
	return nil
}
