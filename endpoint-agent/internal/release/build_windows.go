package release

func verifyBuildExecutable(filename, architecture string) error {
	return VerifyExecutable(filename, architecture)
}
