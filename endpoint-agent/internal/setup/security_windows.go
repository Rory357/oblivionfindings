package setup

import (
	"errors"
	"unsafe"

	"golang.org/x/sys/windows"
)

const serviceSDDL = "D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;0x2008d;;;BU)"

func compareServiceACL(actual, expected *windows.ACL) error {
	invalid := errors.New("Windows service permissions differ from the approved policy")
	entries := func(acl *windows.ACL) (map[string]uint32, error) {
		if acl == nil || acl.AceCount != 3 {
			return nil, invalid
		}
		result := map[string]uint32{}
		for index := uint32(0); index < uint32(acl.AceCount); index++ {
			var ace *windows.ACCESS_ALLOWED_ACE
			if windows.GetAce(acl, index, &ace) != nil || ace.Header.AceType != windows.ACCESS_ALLOWED_ACE_TYPE || ace.Header.AceFlags != 0 || ace.Header.AceSize < 20 {
				return nil, invalid
			}
			sid := (*windows.SID)(unsafe.Pointer(&ace.SidStart))
			if !sid.IsValid() || int(ace.Header.AceSize) != 8+sid.Len() {
				return nil, invalid
			}
			name := sid.String()
			if _, exists := result[name]; exists {
				return nil, invalid
			}
			mask := uint32(ace.Mask)
			// SCM maps generic-all into the concrete service access mask.
			if mask&windows.GENERIC_ALL != 0 {
				mask = (mask &^ windows.GENERIC_ALL) | windows.SERVICE_ALL_ACCESS
			}
			result[name] = mask
		}
		return result, nil
	}
	a, err := entries(actual)
	if err != nil {
		return err
	}
	b, err := entries(expected)
	if err != nil {
		return err
	}
	for sid, mask := range b {
		if a[sid] != mask {
			return invalid
		}
	}
	return nil
}

func verifyServiceDescriptor(sd *windows.SECURITY_DESCRIPTOR) error {
	control, _, err := sd.Control()
	if err != nil || control&windows.SE_DACL_PROTECTED == 0 {
		return errors.New("Windows service permissions are not protected")
	}
	expected, err := windows.SecurityDescriptorFromString(serviceSDDL)
	if err != nil {
		return err
	}
	actualACL, _, err := sd.DACL()
	if err != nil || actualACL == nil {
		return errors.New("service permissions are invalid")
	}
	expectedACL, _, err := expected.DACL()
	if err != nil {
		return err
	}
	return compareServiceACL(actualACL, expectedACL)
}
