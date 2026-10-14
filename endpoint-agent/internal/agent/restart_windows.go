//go:build windows

package agent

import (
	"golang.org/x/sys/windows"
	"unsafe"
)

type windowsRestartExecutor struct{}

// Restart performs no force-close, no remote machine name, and no shell. Call only from the gated installed service.
func (windowsRestartExecutor) Restart(allowed func() bool) (bool, error) {
	var token windows.Token
	if e := windows.OpenProcessToken(windows.CurrentProcess(), windows.TOKEN_ADJUST_PRIVILEGES|windows.TOKEN_QUERY, &token); e != nil {
		return false, e
	}
	defer token.Close()
	var luid windows.LUID
	name, _ := windows.UTF16PtrFromString("SeShutdownPrivilege")
	if e := windows.LookupPrivilegeValue(nil, name, &luid); e != nil {
		return false, e
	}
	privileges := windows.Tokenprivileges{PrivilegeCount: 1}
	privileges.Privileges[0] = windows.LUIDAndAttributes{Luid: luid, Attributes: windows.SE_PRIVILEGE_ENABLED}
	var previous windows.Tokenprivileges
	var size uint32
	if e := windows.AdjustTokenPrivileges(token, false, &privileges, uint32(unsafe.Sizeof(previous)), &previous, &size); e != nil {
		return false, e
	}
	defer windows.AdjustTokenPrivileges(token, false, &previous, 0, nil, nil)
	message, _ := windows.UTF16PtrFromString("Approved computer restart requested through Oblivion IT support.")
	proc := windows.NewLazySystemDLL("advapi32.dll").NewProc("InitiateSystemShutdownExW")
	if e := proc.Find(); e != nil {
		return false, e
	}
	if allowed == nil || !allowed() {
		return false, errServiceAction
	}
	result, _, e := proc.Call(0, uintptr(unsafe.Pointer(message)), 0, 0, 1, 0x80040001)
	if result == 0 {
		return true, e
	}
	return true, nil
}
func NewWindowsRestarts(check func(RemotePeer) bool, releaseEnabled bool) *RestartController {
	r := newRestartController(check, windowsRestartExecutor{})
	r.executionEnabled = releaseEnabled
	return r
}
