package agent

import (
	"crypto/sha256"
	"encoding/hex"
	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/svc/mgr"
	"io"
	"os"
	"runtime"
	"strings"
	"unicode/utf16"
	"unsafe"
)

var softwareMSI = windows.NewLazySystemDLL("msi.dll")
var softwareTrust = windows.NewLazySystemDLL("wintrust.dll")

func NewWindowsSoftware(check func(RemotePeer) bool, enabled bool) *SoftwareController {
	r := newSoftwareController(check, windowsSoftwareExecutor{})
	r.executionEnabled = enabled
	return r
}
func softwareLockArtifact(path string) (*os.File, error) {
	n, e := windows.UTF16PtrFromString(path)
	if e != nil {
		return nil, e
	}
	h, e := windows.CreateFile(n, windows.GENERIC_READ, windows.FILE_SHARE_READ, nil, windows.OPEN_EXISTING, windows.FILE_FLAG_OPEN_REPARSE_POINT, 0)
	if e != nil {
		return nil, e
	}
	var info windows.ByHandleFileInformation
	if windows.GetFileInformationByHandle(h, &info) != nil || info.FileAttributes&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 {
		windows.CloseHandle(h)
		return nil, errServiceAction
	}
	return os.NewFile(uintptr(h), path), nil
}

type windowsSoftwareExecutor struct{}

func (windowsSoftwareExecutor) Verify(f *os.File, c SoftwareCommand) string {
	p := c.Parameters
	if p.Architecture != runtime.GOARCH {
		return "architecture_mismatch"
	}
	if f == nil {
		return "artifact_invalid"
	}
	if _, e := f.Seek(0, 0); e != nil {
		return "artifact_invalid"
	}
	h := sha256.New()
	n, e := io.Copy(h, io.LimitReader(f, p.SizeBytes+1))
	if e != nil || n != p.SizeBytes || hex.EncodeToString(h.Sum(nil)) != p.SHA256 {
		return "artifact_invalid"
	}
	if !softwareVerifySigner(f, p.SignerSHA256) {
		return "signature_invalid"
	}
	return softwareVerifyMSI(f.Name(), p)
}

type softwareTrustSigner struct {
	Size       uint32
	VerifyTime windows.Filetime
	CertCount  uint32
	Certs      uintptr
}
type softwareTrustCert struct {
	Size uint32
	Cert uintptr
}

func softwareVerifySigner(f *os.File, pin string) bool {
	name, e := windows.UTF16PtrFromString(f.Name())
	if e != nil {
		return false
	}
	info := &windows.WinTrustFileInfo{Size: uint32(unsafe.Sizeof(windows.WinTrustFileInfo{})), FilePath: name, File: windows.Handle(f.Fd())}
	data := &windows.WinTrustData{Size: uint32(unsafe.Sizeof(windows.WinTrustData{})), UIChoice: windows.WTD_UI_NONE, RevocationChecks: windows.WTD_REVOKE_WHOLECHAIN, UnionChoice: windows.WTD_CHOICE_FILE, StateAction: windows.WTD_STATEACTION_VERIFY, FileOrCatalogOrBlobOrSgnrOrCert: unsafe.Pointer(info), ProvFlags: windows.WTD_REVOCATION_CHECK_CHAIN_EXCLUDE_ROOT | windows.WTD_DISABLE_MD2_MD4}
	if windows.WinVerifyTrustEx(windows.InvalidHWND, &windows.WINTRUST_ACTION_GENERIC_VERIFY_V2, data) != nil {
		data.StateAction = windows.WTD_STATEACTION_CLOSE
		_ = windows.WinVerifyTrustEx(windows.InvalidHWND, &windows.WINTRUST_ACTION_GENERIC_VERIFY_V2, data)
		return false
	}
	provider, _, _ := softwareTrust.NewProc("WTHelperProvDataFromStateData").Call(uintptr(data.StateData))
	signer := uintptr(0)
	if provider != 0 {
		signer, _, _ = softwareTrust.NewProc("WTHelperGetProvSignerFromChain").Call(provider, 0, 0, 0)
	}
	ok := false
	if signer != 0 {
		var s softwareTrustSigner
		if softwareTrustRead(signer, unsafe.Pointer(&s), unsafe.Sizeof(s)) && s.CertCount > 0 && s.Certs != 0 {
			var chain softwareTrustCert
			if softwareTrustRead(s.Certs, unsafe.Pointer(&chain), unsafe.Sizeof(chain)) && chain.Cert != 0 {
				var cert windows.CertContext
				if softwareTrustRead(chain.Cert, unsafe.Pointer(&cert), unsafe.Sizeof(cert)) && cert.EncodedCert != nil && cert.Length > 0 && cert.Length <= 1024*1024 {
					encoded := make([]byte, cert.Length)
					var read uintptr
					if windows.ReadProcessMemory(windows.CurrentProcess(), uintptr(unsafe.Pointer(cert.EncodedCert)), &encoded[0], uintptr(len(encoded)), &read) == nil && read == uintptr(len(encoded)) {
						digest := sha256.Sum256(encoded)
						ok = hex.EncodeToString(digest[:]) == pin
					}
				}
			}
		}
	}
	data.StateAction = windows.WTD_STATEACTION_CLOSE
	closeErr := windows.WinVerifyTrustEx(windows.InvalidHWND, &windows.WINTRUST_ACTION_GENERIC_VERIFY_V2, data)
	return ok && closeErr == nil
}
func softwareMSICall(name string, args ...uintptr) uint32 {
	x, _, _ := softwareMSI.NewProc(name).Call(args...)
	return uint32(x)
}
func softwareMSIString(record uintptr, field uint32) (string, error) {
	buf := make([]uint16, 2049)
	n := uint32(len(buf) - 1)
	if softwareMSICall("MsiRecordGetStringW", record, uintptr(field), uintptr(unsafe.Pointer(&buf[0])), uintptr(unsafe.Pointer(&n))) != 0 || n > 2048 {
		return "", errServiceAction
	}
	return windows.UTF16ToString(buf), nil
}
func softwareMSIQuery(db uintptr, query string, visit func(uintptr) bool) bool {
	q, _ := windows.UTF16PtrFromString(query)
	var view uintptr
	if softwareMSICall("MsiDatabaseOpenViewW", db, uintptr(unsafe.Pointer(q)), uintptr(unsafe.Pointer(&view))) != 0 {
		return false
	}
	defer softwareMSICall("MsiCloseHandle", view)
	if softwareMSICall("MsiViewExecute", view, 0) != 0 {
		return false
	}
	for count := 0; count <= 32767; count++ {
		var row uintptr
		code := softwareMSICall("MsiViewFetch", view, uintptr(unsafe.Pointer(&row)))
		if code == 259 {
			return true
		}
		if code != 0 {
			return false
		}
		ok := visit(row)
		softwareMSICall("MsiCloseHandle", row)
		if !ok {
			return false
		}
	}
	return false
}
func softwareMSIProperty(db uintptr, name string) (string, bool) {
	value := ""
	seen := false
	ok := softwareMSIQuery(db, "SELECT `Value` FROM `Property` WHERE `Property` = '"+name+"'", func(row uintptr) bool {
		if seen {
			return false
		}
		seen = true
		v, e := softwareMSIString(row, 1)
		value = v
		return e == nil
	})
	return value, ok
}
func softwareSummary(handle uintptr, id uint32) (string, int32, bool) {
	var typ uint32
	var number int32
	buf := make([]uint16, 2049)
	n := uint32(len(buf) - 1)
	code := softwareMSICall("MsiSummaryInfoGetPropertyW", handle, uintptr(id), uintptr(unsafe.Pointer(&typ)), uintptr(unsafe.Pointer(&number)), 0, uintptr(unsafe.Pointer(&buf[0])), uintptr(unsafe.Pointer(&n)))
	return windows.UTF16ToString(buf), number, code == 0 && n <= 2048
}
func softwareVerifyMSI(path string, p SoftwareParameters) string {
	name, _ := windows.UTF16PtrFromString(path)
	var db uintptr
	if softwareMSICall("MsiOpenDatabaseW", uintptr(unsafe.Pointer(name)), 0, uintptr(unsafe.Pointer(&db))) != 0 {
		return "artifact_invalid"
	}
	defer softwareMSICall("MsiCloseHandle", db)
	code, ok := softwareMSIProperty(db, "ProductCode")
	version, vok := softwareMSIProperty(db, "ProductVersion")
	all, aok := softwareMSIProperty(db, "ALLUSERS")
	user, uok := softwareMSIProperty(db, "MSIINSTALLPERUSER")
	if !ok || !vok || code != p.ProductCode || version != p.ProductVersion {
		return "product_mismatch"
	}
	if !aok || all != "1" || !uok || (user != "" && user != "0") {
		return "artifact_invalid"
	}
	var summary uintptr
	if softwareMSICall("MsiGetSummaryInformationW", db, 0, 0, uintptr(unsafe.Pointer(&summary))) != 0 {
		return "artifact_invalid"
	}
	defer softwareMSICall("MsiCloseHandle", summary)
	template, _, tok := softwareSummary(summary, 7)
	expected := "x64"
	if p.Architecture == "arm64" {
		expected = "Arm64"
	}
	if !tok || strings.Split(template, ";")[0] != expected {
		return "architecture_mismatch"
	}
	_, page, pok := softwareSummary(summary, 14)
	_, word, wok := softwareSummary(summary, 15)
	if !pok || !wok || page < 200 || (p.Architecture == "arm64" && page < 500) {
		return "artifact_invalid"
	}
	files := 0
	if !softwareMSIQuery(db, "SELECT `Attributes` FROM `File`", func(row uintptr) bool {
		files++
		a := int32(softwareMSICall("MsiRecordGetInteger", row, 1))
		return a >= 0 && a&0x2000 == 0 && (word&2 != 0 || a&0x4000 != 0)
	}) || files == 0 {
		return "artifact_invalid"
	}
	media := 0
	if !softwareMSIQuery(db, "SELECT `Cabinet` FROM `Media`", func(row uintptr) bool {
		media++
		v, e := softwareMSIString(row, 1)
		return e == nil && len(v) > 1 && strings.HasPrefix(v, "#") && !strings.ContainsAny(v, "/\\")
	}) {
		return "artifact_invalid"
	}
	if media == 0 {
		return "artifact_invalid"
	}
	return ""
}
func softwareProductProperty(code, property string) (string, uint32) {
	c, _ := windows.UTF16PtrFromString(code)
	p, _ := windows.UTF16PtrFromString(property)
	b := make([]uint16, 2049)
	n := uint32(len(b) - 1)
	e := softwareMSICall("MsiGetProductInfoExW", uintptr(unsafe.Pointer(c)), 0, 4, uintptr(unsafe.Pointer(p)), uintptr(unsafe.Pointer(&b[0])), uintptr(unsafe.Pointer(&n)))
	if n > 2048 {
		return "", 234
	}
	return windows.UTF16ToString(b), e
}
func (windowsSoftwareExecutor) Observe(code string) (string, *string, error) {
	if !softwareProductCode.MatchString(code) {
		return "unknown", nil, errServiceAction
	}
	state, e := softwareProductProperty(code, "ProductState")
	if e == 1605 {
		return "absent", nil, nil
	}
	if e != 0 {
		return "unknown", nil, errServiceAction
	}
	property := "VersionString"
	if state == "1" {
		property = "Version"
	}
	version, e := softwareProductProperty(code, property)
	if e == 0 && state == "1" {
		var ok bool
		version, ok = softwarePackedVersion(version)
		if !ok {
			return "unknown", nil, errServiceAction
		}
	}
	if e != 0 {
		return "unknown", nil, errServiceAction
	}
	if _, ok := softwareVersion(version); !ok {
		return "unknown", nil, errServiceAction
	}
	switch state {
	case "5":
		return "installed", &version, nil
	case "1":
		return "advertised", &version, nil
	}
	return "unknown", nil, errServiceAction
}

type windowsSoftwareChild struct{ handle windows.Handle }

func (c *windowsSoftwareChild) Exit() (uint32, bool, error) {
	wait, e := windows.WaitForSingleObject(c.handle, 0)
	if e != nil {
		return 0, false, e
	}
	if wait == uint32(windows.WAIT_TIMEOUT) {
		return 0, false, nil
	}
	if wait != windows.WAIT_OBJECT_0 {
		return 0, false, errServiceAction
	}
	var code uint32
	e = windows.GetExitCodeProcess(c.handle, &code)
	return code, e == nil, e
}
func (c *windowsSoftwareChild) Close() error { return windows.CloseHandle(c.handle) }
func softwareCreation(h windows.Handle) (uint64, error) {
	var a, b, c, d windows.Filetime
	e := windows.GetProcessTimes(h, &a, &b, &c, &d)
	return uint64(a.HighDateTime)<<32 | uint64(a.LowDateTime), e
}
func (windowsSoftwareExecutor) Start(f *os.File, c SoftwareCommand, persist func(softwareProcessIdentity) error, allowed func() bool) (softwareChild, bool, error) {
	if f == nil || !allowed() {
		return nil, false, errServiceAction
	}
	quiet, err := (windowsSoftwareExecutor{}).Quiescent(nil)
	if err != nil || !quiet {
		return nil, false, errServiceAction
	}
	state, version, err := (windowsSoftwareExecutor{}).Observe(c.Parameters.ProductCode)
	if err != nil || state == "unknown" || (state == "installed" && version != nil && compareSoftwareVersion(*version, c.Parameters.ProductVersion) >= 0) {
		return nil, false, errServiceAction
	}
	root, e := windows.GetWindowsDirectory()
	if e != nil {
		return nil, false, e
	}
	app, _ := windows.UTF16PtrFromString(root + "\\System32\\msiexec.exe")
	command, _ := windows.UTF16PtrFromString(windows.ComposeCommandLine([]string{root + "\\System32\\msiexec.exe", "/i", f.Name(), "/qn", "/norestart", "REBOOT=ReallySuppress", "MSIRESTARTMANAGERCONTROL=Disable"}))
	directory, _ := windows.UTF16PtrFromString(root + "\\System32")
	environment := utf16.Encode([]rune("SystemRoot=" + root + "\x00WINDIR=" + root + "\x00\x00"))
	start := windows.StartupInfo{Cb: uint32(unsafe.Sizeof(windows.StartupInfo{})), Flags: windows.STARTF_USESHOWWINDOW, ShowWindow: windows.SW_HIDE}
	var p windows.ProcessInformation
	if e = windows.CreateProcess(app, command, nil, nil, false, windows.CREATE_SUSPENDED|windows.CREATE_NO_WINDOW|windows.CREATE_UNICODE_ENVIRONMENT, &environment[0], directory, &start, &p); e != nil {
		return nil, false, e
	}
	defer windows.CloseHandle(p.Thread)
	dispose := func() (softwareChild, bool, error) {
		if windows.TerminateProcess(p.Process, 1) != nil {
			windows.CloseHandle(p.Process)
			return nil, true, errServiceAction
		}
		wait, e := windows.WaitForSingleObject(p.Process, 5000)
		windows.CloseHandle(p.Process)
		return nil, e != nil || wait != windows.WAIT_OBJECT_0, errServiceAction
	}
	created, e := softwareCreation(p.Process)
	if e != nil || persist(softwareProcessIdentity{p.ProcessId, created}) != nil || !allowed() {
		return dispose()
	}
	if _, e = windows.ResumeThread(p.Thread); e != nil {
		return dispose()
	}
	return &windowsSoftwareChild{p.Process}, true, nil
}
func (windowsSoftwareExecutor) Quiescent(id *softwareProcessIdentity) (bool, error) {
	if id != nil {
		h, e := windows.OpenProcess(windows.PROCESS_QUERY_LIMITED_INFORMATION|windows.SYNCHRONIZE, false, id.PID)
		if e != nil && e != windows.ERROR_INVALID_PARAMETER {
			return false, e
		}
		if e == nil {
			created, e := softwareCreation(h)
			wait, exit := windows.WaitForSingleObject(h, 0)
			windows.CloseHandle(h)
			if e != nil || exit != nil || (wait != windows.WAIT_OBJECT_0 && wait != uint32(windows.WAIT_TIMEOUT)) {
				return false, errServiceAction
			}
			if created == id.Created && wait == uint32(windows.WAIT_TIMEOUT) {
				return false, nil
			}
		}
	}
	for _, name := range []string{"Global\\_MSIExecute", "_MSIExecute"} {
		n, _ := windows.UTF16PtrFromString(name)
		h, e := windows.OpenMutex(windows.SYNCHRONIZE|windows.MUTEX_MODIFY_STATE, false, n)
		if e == windows.ERROR_FILE_NOT_FOUND {
			continue
		}
		if e != nil {
			return false, e
		}
		wait, e := windows.WaitForSingleObject(h, 0)
		if e == nil && (wait == windows.WAIT_OBJECT_0 || wait == windows.WAIT_ABANDONED) {
			_ = windows.ReleaseMutex(h)
		}
		windows.CloseHandle(h)
		if e != nil || wait == uint32(windows.WAIT_TIMEOUT) {
			return false, e
		}
	}
	manager, e := mgr.Connect()
	if e != nil {
		return false, e
	}
	defer manager.Disconnect()
	service, e := manager.OpenService("msiserver")
	if e != nil {
		return false, e
	}
	defer service.Close()
	status, e := service.Query()
	if e != nil {
		return false, e
	}
	return status.Accepts&windows.SERVICE_ACCEPT_SHUTDOWN == 0, nil
}

// Copy bounded structures from the active verified WinTrust provider; never enumerate unverified embedded certificates.
func softwareTrustRead(address uintptr, destination unsafe.Pointer, size uintptr) bool {
	var read uintptr
	return windows.ReadProcessMemory(windows.CurrentProcess(), address, (*byte)(destination), size, &read) == nil && read == size
}
