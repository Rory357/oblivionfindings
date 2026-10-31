package release

import (
	"debug/pe"
	"errors"
)

// VerifyExecutable checks that filename is a valid Windows PE executable image
// (not a DLL) targeting the expected architecture. Publisher authority is
// enforced by the Ed25519-signed release manifest (OBLIVION-RELEASE-V1) and
// download catalogue (OBLIVION-DOWNLOADS-V1) so unsigned Windows builds can be
// verified cross-platform without an X.509 Authenticode certificate.
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
	if executable.Characteristics&pe.IMAGE_FILE_EXECUTABLE_IMAGE == 0 || executable.Characteristics&pe.IMAGE_FILE_DLL != 0 {
		return errors.New("release executable is not a standalone Windows program")
	}
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
	return nil
}

func verifyBuildExecutable(filename, architecture string) error {
	return VerifyExecutable(filename, architecture)
}
