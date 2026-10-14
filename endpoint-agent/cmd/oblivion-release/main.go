// oblivion-release assembles an offline package in a controlled publisher
// workspace. It does not upload releases, install software or enrol machines.
package main

import (
	"context"
	"crypto/ed25519"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"os/signal"
	"time"

	"oblivion.local/endpoint-agent/internal/release"
)

const usage = "use: oblivion-release build|catalogue --stage <curated-directory> --metadata <metadata.json> --output <new-zip-or-directory> --publisher-key <base64-public-key>; catalogue also accepts --package-key <Windows-package-public-key>; supply the Base64 32-byte signing seed on protected standard input"

type options struct {
	command, stage, metadata, output string
	publicKey, packageKey            ed25519.PublicKey
}

func parseOptions(args []string) (options, error) {
	var out options
	if len(args) == 0 || (args[0] != "build" && args[0] != "catalogue") {
		return out, errors.New(usage)
	}
	out.command = args[0]
	flags := flag.NewFlagSet(out.command, flag.ContinueOnError)
	flags.SetOutput(io.Discard)
	var public, packagePublic string
	flags.StringVar(&out.stage, "stage", "", "curated payload or flat distribution-artifact directory")
	flags.StringVar(&out.metadata, "metadata", "", "strict non-secret release metadata")
	flags.StringVar(&out.output, "output", "", "new ZIP or catalogue directory outside the staging directory")
	flags.StringVar(&public, "publisher-key", "", "expected publisher public key")
	if out.command == "catalogue" {
		flags.StringVar(&packagePublic, "package-key", "", "independent Windows ZIP package public key")
	}
	if flags.Parse(args[1:]) != nil || flags.NArg() != 0 || out.stage == "" || out.metadata == "" || out.output == "" {
		return out, errors.New(usage)
	}
	decoded, err := base64.StdEncoding.Strict().DecodeString(public)
	if err != nil || len(decoded) != ed25519.PublicKeySize || base64.StdEncoding.EncodeToString(decoded) != public {
		return out, errors.New("a canonical Base64 Ed25519 publisher public key is required")
	}
	out.publicKey = ed25519.PublicKey(decoded)
	if packagePublic != "" {
		decodedPackage, err := base64.StdEncoding.Strict().DecodeString(packagePublic)
		if err != nil || len(decodedPackage) != ed25519.PublicKeySize || base64.StdEncoding.EncodeToString(decodedPackage) != packagePublic {
			return out, errors.New("invalid package publisher public key")
		}
		out.packageKey = ed25519.PublicKey(decodedPackage)
	}
	return out, nil
}

// Only a seed on bounded standard input is supported here. No secret flag,
// environment variable, key file copy, generated key or unsigned mode exists.
func readSigner(input io.Reader, expected ed25519.PublicKey) (ed25519.PrivateKey, error) {
	encoded, err := io.ReadAll(io.LimitReader(input, 47))
	defer clear(encoded)
	if err != nil || len(encoded) > 46 {
		return nil, errors.New("invalid publisher signing input")
	}
	if len(encoded) > 0 && encoded[len(encoded)-1] == '\n' {
		encoded = encoded[:len(encoded)-1]
		if len(encoded) > 0 && encoded[len(encoded)-1] == '\r' {
			encoded = encoded[:len(encoded)-1]
		}
	}
	if len(encoded) != 44 {
		return nil, errors.New("invalid publisher signing input")
	}
	seed := make([]byte, base64.StdEncoding.DecodedLen(len(encoded)))
	defer clear(seed)
	n, err := base64.StdEncoding.Strict().Decode(seed, encoded)
	if err != nil || n != ed25519.SeedSize {
		return nil, errors.New("invalid publisher signing input")
	}
	key := ed25519.NewKeyFromSeed(seed[:n])
	if !key.Public().(ed25519.PublicKey).Equal(expected) {
		clear(key)
		return nil, errors.New("signing input does not match the expected publisher public key")
	}
	return key, nil
}

func main() {
	ctx, cancel := signal.NotifyContext(context.Background(), os.Interrupt)
	defer cancel()
	if err := run(ctx, os.Args[1:], os.Stdin, os.Stdout); err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
}
func run(ctx context.Context, args []string, input io.Reader, output io.Writer) error {
	opt, err := parseOptions(args)
	if err != nil {
		return err
	}
	if err = ctx.Err(); err != nil {
		return err
	}
	file, err := os.Open(opt.metadata)
	if err != nil {
		return errors.New("cannot read release metadata")
	}
	var spec release.BuildSpec
	var catalogueSpec release.CatalogueSpec
	var readErr error
	if opt.command == "catalogue" {
		catalogueSpec, readErr = release.ReadCatalogueSpec(file)
	} else {
		spec, readErr = release.ReadBuildSpec(file)
	}
	file.Close()
	if readErr != nil {
		return readErr
	}
	if err = ctx.Err(); err != nil {
		return err
	}
	key, err := readSigner(input, opt.publicKey)
	if err != nil {
		return err
	}
	defer clear(key) // Best effort; this is not a claim of secure-memory erasure.
	var result any
	if opt.command == "catalogue" {
		result, err = release.BuildCatalogue(ctx, opt.stage, opt.output, catalogueSpec, key, opt.publicKey, opt.packageKey, time.Now())
	} else {
		result, err = release.BuildWindows(ctx, opt.stage, opt.output, spec, key, opt.publicKey, time.Now())
	}
	if err != nil {
		return err
	}
	if err = json.NewEncoder(output).Encode(result); err != nil {
		return errors.New("release was built but its receipt could not be written; inspect the requested output before retrying")
	}
	return nil
}
