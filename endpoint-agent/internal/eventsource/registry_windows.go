package eventsource

import (
	"context"
	"encoding/binary"
	"errors"
	"path/filepath"
	"strings"
	"unicode/utf16"
	"unsafe"

	"golang.org/x/sys/windows"
	"golang.org/x/sys/windows/registry"
	"golang.org/x/sys/windows/svc/eventlog"
)

const parentPath = `SYSTEM\CurrentControlSet\Services\EventLog\Application`
const sourceSDDL = "O:BAG:BAD:P(A;;KA;;;SY)(A;;KA;;;BA)(A;;KR;;;BU)"

var advapi = windows.NewLazySystemDLL("advapi32.dll")
var createKey = advapi.NewProc("RegCreateKeyExW")
var flushKey = advapi.NewProc("RegFlushKey")
var enumerateValue = advapi.NewProc("RegEnumValueW")

type registryStore struct{ write bool }
type registrySource struct {
	key, parent registry.Key
	name        string
}

func messageFile() (string, error) {
	root, err := windows.GetSystemDirectory()
	if err != nil {
		return "", err
	}
	return filepath.Join(root, "EventCreate.exe"), nil
}
func Preflight(s Source) error {
	path, err := messageFile()
	if err != nil {
		return err
	}
	if err = verifyMessageFile(path); err != nil {
		return err
	}
	return inspect(registryStore{}, s, path, true, true)
}
func Ensure(ctx context.Context, s Source) error {
	path, err := messageFile()
	if err != nil {
		return err
	}
	if err = verifyMessageFile(path); err != nil {
		return err
	}
	return ensure(ctx, registryStore{write: true}, s, path)
}
func Check(s Source) error {
	path, err := messageFile()
	if err != nil {
		return err
	}
	return inspect(registryStore{}, s, path, false, false)
}
func Remove(s Source) error {
	path, err := messageFile()
	if err != nil {
		return err
	}
	return remove(registryStore{write: true}, s, path)
}

// Open refuses Windows' fallback to an unnamed/unconfigured Application source.
func Open(name string) (*eventlog.Log, error) {
	if !validName(name) {
		return nil, ErrUnsafe
	}
	key, _, err := (registryStore{}).Open(name, false)
	if err != nil {
		return nil, err
	}
	defer key.Close()
	state, err := key.Read()
	if err != nil {
		return nil, err
	}
	s := Source{Name: name, Owner: state.values[ownerValue].text}
	path, err := messageFile()
	if err != nil {
		return nil, err
	}
	if err = validSnapshot(s, state, path, false); err != nil {
		return nil, err
	}
	return eventlog.Open(name)
}
func (store registryStore) Open(name string, create bool) (sourceKey, bool, error) {
	if !validName(name) || create && !store.write {
		return nil, false, ErrUnsafe
	}
	rights := uint32(registry.READ | registry.WOW64_64KEY)
	if store.write {
		rights |= registry.CREATE_SUB_KEY | registry.SET_VALUE
	}
	parent, err := registry.OpenKey(registry.LOCAL_MACHINE, parentPath, rights)
	if err != nil {
		return nil, false, err
	}
	keep := false
	defer func() {
		if !keep {
			parent.Close()
		}
	}()
	encoded, _ := windows.UTF16PtrFromString(name)
	access := uint32(registry.READ | registry.WOW64_64KEY)
	if store.write {
		access |= registry.SET_VALUE | windows.DELETE
	}
	var handle windows.Handle
	// REG_OPTION_OPEN_LINK: inspect the fixed child itself, not its link target.
	err = windows.RegOpenKeyEx(windows.Handle(parent), encoded, 8, access, &handle)
	created := false
	if errors.Is(err, windows.ERROR_FILE_NOT_FOUND) {
		if !create {
			return nil, false, ErrAbsent
		}
		sd, e := windows.SecurityDescriptorFromString(sourceSDDL)
		if e != nil {
			return nil, false, e
		}
		attrs := windows.SecurityAttributes{Length: uint32(unsafe.Sizeof(windows.SecurityAttributes{})), SecurityDescriptor: sd}
		var disposition uint32
		status, _, _ := createKey.Call(uintptr(parent), uintptr(unsafe.Pointer(encoded)), 0, 0, 0, uintptr(access), uintptr(unsafe.Pointer(&attrs)), uintptr(unsafe.Pointer(&handle)), uintptr(unsafe.Pointer(&disposition)))
		if status != 0 {
			return nil, false, windows.Errno(status)
		}
		created = disposition == 1
		if !created {
			// A source appeared between open/create. Never write through a raced link.
			registry.Key(handle).Close()
			err = windows.RegOpenKeyEx(windows.Handle(parent), encoded, 8, access, &handle)
			if err != nil {
				return nil, false, err
			}
		}
	} else if err != nil {
		return nil, false, err
	}
	keep = true
	return &registrySource{key: registry.Key(handle), parent: parent, name: name}, created, nil
}
func (k *registrySource) Close() error { return errors.Join(k.key.Close(), k.parent.Close()) }
func (k *registrySource) Read() (snapshot, error) {
	out := snapshot{values: map[string]value{}}
	sd, err := windows.GetSecurityInfo(windows.Handle(k.key), windows.SE_REGISTRY_KEY, windows.OWNER_SECURITY_INFORMATION|windows.DACL_SECURITY_INFORMATION)
	if err != nil {
		return out, err
	}
	out.secure = verifyDescriptor(sd) == nil
	info, err := k.key.Stat()
	if err != nil {
		return out, err
	}
	if info.SubKeyCount != 0 || info.ValueCount > 4 || info.MaxValueNameLen > 64 || info.MaxValueLen > 2048 {
		return out, ErrUnsafe
	}
	names, err := boundedValueNames(k.key)
	if err != nil {
		return out, err
	}
	for _, name := range names {
		var data [2048]byte
		n, kind, err := k.key.GetValue(name, data[:])
		if err != nil || n > len(data) {
			return out, ErrUnsafe
		}
		v := value{kind: kind}
		switch kind {
		case registry.SZ:
			if n < 2 || n%2 != 0 {
				return out, ErrUnsafe
			}
			units := make([]uint16, n/2)
			for i := range units {
				units[i] = binary.LittleEndian.Uint16(data[i*2:])
			}
			if units[len(units)-1] != 0 {
				return out, ErrUnsafe
			}
			for _, u := range units[:len(units)-1] {
				if u == 0 {
					return out, ErrUnsafe
				}
			}
			v.text = string(utf16.Decode(units[:len(units)-1]))
		case registry.DWORD:
			if n != 4 {
				return out, ErrUnsafe
			}
			v.number = binary.LittleEndian.Uint32(data[:4])
		default:
			return out, ErrUnsafe
		}
		out.values[name] = v
	}
	return out, nil
}
func (k *registrySource) Put(name string, v value) error {
	switch name {
	case ownerValue, "CustomSource", "EventMessageFile", "TypesSupported":
	default:
		return ErrUnsafe
	}
	if v.kind == registry.SZ {
		return k.key.SetStringValue(name, v.text)
	}
	if v.kind == registry.DWORD {
		return k.key.SetDWordValue(name, v.number)
	}
	return ErrUnsafe
}
func (k *registrySource) Flush() error {
	status, _, _ := flushKey.Call(uintptr(k.key))
	if status != 0 {
		return windows.Errno(status)
	}
	return nil
}
func (k *registrySource) Delete() error {
	// No recursive registry deletion and no clearing Windows event records.
	info, err := k.key.Stat()
	if err != nil || info.SubKeyCount != 0 {
		return ErrUnsafe
	}
	return registry.DeleteKey(k.parent, k.name)
}
func verifyDescriptor(sd *windows.SECURITY_DESCRIPTOR) error {
	owner, _, err := sd.Owner()
	if err != nil || owner == nil || (!owner.IsWellKnown(windows.WinBuiltinAdministratorsSid) && !owner.IsWellKnown(windows.WinLocalSystemSid)) {
		return ErrUnsafe
	}
	control, _, err := sd.Control()
	if err != nil || control&windows.SE_DACL_PROTECTED == 0 {
		return ErrUnsafe
	}
	acl, _, err := sd.DACL()
	if err != nil || acl == nil || acl.AceCount != 3 {
		return ErrUnsafe
	}
	required := map[string]uint32{"S-1-5-18": registry.ALL_ACCESS, "S-1-5-32-544": registry.ALL_ACCESS, "S-1-5-32-545": registry.READ}
	for i := uint32(0); i < uint32(acl.AceCount); i++ {
		var ace *windows.ACCESS_ALLOWED_ACE
		if windows.GetAce(acl, i, &ace) != nil || ace.Header.AceType != windows.ACCESS_ALLOWED_ACE_TYPE || ace.Header.AceFlags != 0 || ace.Header.AceSize < 20 {
			return ErrUnsafe
		}
		sid := (*windows.SID)(unsafe.Pointer(&ace.SidStart))
		if !sid.IsValid() || int(ace.Header.AceSize) != 8+sid.Len() {
			return ErrUnsafe
		}
		want, ok := required[sid.String()]
		if !ok || uint32(ace.Mask) != want {
			return ErrUnsafe
		}
		delete(required, sid.String())
	}
	if len(required) != 0 {
		return ErrUnsafe
	}
	return nil
}

func boundedValueNames(key registry.Key) ([]string, error) {
	var names []string
	for index := uint32(0); index < 5; index++ {
		var name [65]uint16
		size := uint32(len(name))
		status, _, _ := enumerateValue.Call(uintptr(key), uintptr(index), uintptr(unsafe.Pointer(&name[0])), uintptr(unsafe.Pointer(&size)), 0, 0, 0, 0)
		if status == uintptr(windows.ERROR_NO_MORE_ITEMS) {
			return names, nil
		}
		if status != 0 || size > 64 || index == 4 {
			return nil, ErrUnsafe
		}
		names = append(names, windows.UTF16ToString(name[:size]))
	}
	return nil, ErrUnsafe
}

func verifyMessageFile(path string) error {
	module, err := windows.LoadLibraryEx(path, 0, windows.LOAD_LIBRARY_AS_DATAFILE|windows.LOAD_LIBRARY_AS_IMAGE_RESOURCE)
	if err != nil {
		return errors.New("Windows event message resource is unavailable")
	}
	defer windows.FreeLibrary(module)
	for _, id := range []uint32{1, 2, 10, 11} {
		var buffer [1024]uint16
		size, err := windows.FormatMessage(windows.FORMAT_MESSAGE_FROM_HMODULE|windows.FORMAT_MESSAGE_IGNORE_INSERTS, uintptr(module), id, 0, buffer[:], nil)
		if err != nil || size == 0 || !strings.Contains(windows.UTF16ToString(buffer[:size]), "%1") {
			return errors.New("Windows event message resource is incompatible")
		}
	}
	return nil
}
