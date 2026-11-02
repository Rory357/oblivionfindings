package release

import (
	"debug/pe"
	"errors"
)

// UnsignedTestBuild is the explicit switch that lets a build accept Windows programs without an
// Authenticode signature. It is set only with
//
//	-ldflags "-X oblivion.local/endpoint-agent/internal/release.UnsignedTestBuild=true"
//
// and CI sets it only while no code-signing certificate exists (D1: unsigned while testing).
// Once signing exists, release builds leave it off, so a signed install can never be swapped
// for an unsigned update.
var UnsignedTestBuild = "false"

var errUnsigned = errors.New("release executable signature or certificate status could not be verified")

// VerifyExecutable checks that filename is a standalone Windows program for the expected
// architecture and, unless this is an unsigned test build, that it carries a valid Authenticode
// signature chain. The publisher-signed manifest (OBLIVION-RELEASE-V1) separately approves
// these exact bytes.
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
	if verifyAuthenticode(filename, file) == nil {
		return nil
	}
	if UnsignedTestBuild == "true" {
		return nil
	}
	return errUnsigned
}

func verifyBuildExecutable(filename, architecture string) error {
	return VerifyExecutable(filename, architecture)
}
