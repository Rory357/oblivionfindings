package localui

import (
	"encoding/binary"
	"unsafe"

	"golang.org/x/sys/windows"
	"oblivion.local/endpoint-agent/internal/agent"
)

var wts = windows.NewLazySystemDLL("wtsapi32.dll")
var querySession = wts.NewProc("WTSQuerySessionInformationW")
var freeSession = wts.NewProc("WTSFreeMemory")

// ValidateRemoteDesktop runs as SYSTEM, after pipe impersonation has ended.
// WTS_INFOEX level 1 binds active/unlocked state to the actual logon token.
// A host with several active desktops needs explicit target selection (not
// whichever user polls fastest); deny ambiguity until that workflow is present.
func ValidateRemoteDesktop(peer agent.RemotePeer) bool {
	if peer.SID == "" || peer.LogonSID == "" || peer.SessionID == 0 {
		return false
	}
	var sessions *windows.WTS_SESSION_INFO
	var count uint32
	if windows.WTSEnumerateSessions(0, 0, 1, &sessions, &count) != nil {
		return false
	}
	defer windows.WTSFreeMemory(uintptr(unsafe.Pointer(sessions)))
	if count == 0 || count > 1024 {
		return false
	}
	active := uint32(0)
	for _, session := range unsafe.Slice(sessions, int(count)) {
		if session.SessionID != 0 && session.State == windows.WTSActive {
			if active != 0 {
				return false
			}
			active = session.SessionID
		}
	}
	if active != peer.SessionID {
		return false
	}
	var data *byte
	var length uint32
	ok, _, _ := querySession.Call(0, uintptr(peer.SessionID), 25, uintptr(unsafe.Pointer(&data)), uintptr(unsafe.Pointer(&length)))
	if ok == 0 {
		return false
	}
	defer freeSession.Call(uintptr(unsafe.Pointer(data)))
	if data == nil || length < 20 || length > 4096 {
		return false
	}
	prefix := unsafe.Slice(data, 20)
	if !unlockedSessionPrefix(prefix, peer.SessionID) {
		return false
	}
	var token windows.Token
	if windows.WTSQueryUserToken(peer.SessionID, &token) != nil {
		return false
	}
	defer token.Close()
	user, err := token.GetTokenUser()
	if err != nil || user.User.Sid.String() != peer.SID {
		return false
	}
	groups, err := token.GetTokenGroups()
	if err != nil {
		return false
	}
	for _, group := range groups.AllGroups() {
		if group.Attributes&windows.SE_GROUP_ENABLED != 0 && group.Attributes&windows.SE_GROUP_USE_FOR_DENY_ONLY == 0 && group.Attributes&windows.SE_GROUP_LOGON_ID == windows.SE_GROUP_LOGON_ID && group.Sid.String() == peer.LogonSID {
			return true
		}
	}
	return false
}
func unlockedSessionPrefix(data []byte, session uint32) bool {
	// WTSINFOEXW has ULONG Level then an 8-byte-aligned union. The level-1
	// prefix is ULONG SessionId, enum SessionState, LONG SessionFlags.
	return len(data) >= 20 && binary.LittleEndian.Uint32(data) == 1 &&
		binary.LittleEndian.Uint32(data[8:]) == session &&
		binary.LittleEndian.Uint32(data[12:]) == windows.WTSActive &&
		binary.LittleEndian.Uint32(data[16:]) == 1
}
