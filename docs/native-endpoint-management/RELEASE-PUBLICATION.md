# Signed download publication directories

One operating organisation owns each independent Oblivion deployment and its approved Sites. Generic software publication is separate from deployment enrolment profiles. This workflow adds no organisation selector, shared device credentials, deployment transport or AI-provider secret to release files.

## Implemented assembly command

oblivion-release now provides a catalogue subcommand in addition to the Windows ZIP builder:

~~~text
oblivion-release catalogue --stage <approved-flat-artifact-directory> --metadata <catalogue-spec.json> --output <new-publication-directory> --publisher-key <catalogue-public-key> --package-key <Windows-package-public-key>
~~~

The protected publisher process supplies the catalogue signing seed through bounded standard input, using the same secret-handling rules as RELEASE-BUILDING.md. Both command-line key values are public keys. The Windows package authority is explicit and is not silently inherited from the catalogue signer. --package-key is required whenever a Windows ZIP is listed.

The command creates a NEW directory containing exactly the listed artifact copies and downloads.json. It does not overwrite an existing directory, upload files, change application configuration, advance any deployed revision floor, enrol a device or execute/install an artifact. A successful receipt proves assembly, not operational or release acceptance.

catalogue-spec.json contains exactly:
- revision: positive integer up to 2^53−1.
- issued_at and expires_at: UTC YYYY-MM-DDTHH:MM:SSZ timestamps, satisfying the existing 90-day maximum validity contract.
- artifacts: an explicit list of up to 16 approved records. An explicit empty list supports a signed withdrawal catalogue; missing/null is refused.

Each artifact has exactly the nine fields documented in RELEASE-DOWNLOADS.md: id, platform, architecture, roles, version, release_revision, filename, size and sha256. Size/digest are the approved expected bytes, not advisory display values. Obtain them from the controlled build's verified receipt or independently reviewed artifact inventory. They are recalculated and compared while copying. The tool does not trust arbitrary filename paths, URLs, caller-supplied server addresses or hashes without matching artifact bytes.

Only listed files are copied; unrelated source files are ignored. Source filenames must be safe flat names. Windows .exe/.zip and Linux site-only .tar.gz records follow the application's exact schema, size, version, architecture and role constraints. Use clean, access-controlled publisher input/output directories. Creating mode 0700/0600 objects alone is not a Windows ACL guarantee.

## Verification before a catalogue is committed

1. The tool validates bounded, duplicate-free JSON with exact key spellings and complete object shapes. Go's default case-insensitive struct-field decoding is explicitly restricted so an alternative spelling cannot create a Go/PHP disagreement.
2. It copies each ordinary source file through an anchored directory, confirming identity, approved length and SHA-256. Source paths cannot escape the directory. Source mutation after the copy does not change the publication bytes.
3. A Windows ZIP is reopened through the existing publisher-signature/file-hash verifier using the explicit package key. Advertised version, exact release revision, architecture and role set must match its signed manifest. Catalogue expiry cannot outlive the package's expiry.
4. A standalone Windows .exe requires real PE architecture and Authenticode verification. Its advertised version/revision/roles still depend on the approved publisher inventory; the tool does not extract a semantic version or embedded setup key from arbitrary executables. Correct controlled compilation and setup trust-pin verification remain release-pipeline responsibilities.
5. Linux collector bytes are approved-digest-bound. They are neither extracted nor run. This does not claim Linux package-content, service installation or operational acceptance. That packaging/lifecycle path remains unfinished.
6. Windows holds read handles against writes/replacement while artifact verification and catalogue signing finish. Copied bytes are rehashed before the catalogue file is committed.
7. The exact OBLIVION-DOWNLOADS-V1 newline-prefixed payload is signed with Ed25519 and independently decoded/verified again. downloads.json is created only after artifact checks and freshness checks pass. A JSON receipt identifies the directory, catalogue digest/size and public metadata.

Controlled-process failures attempt to remove only the newly owned output directory. Existing releases and source files are preserved. Process crashes may leave a partial new directory; it must not be activated just because it exists. A partial/corrupt/unsigned catalogue is rejected by the application's download reader. Receipt-writing failure after success reports that the output may exist, rather than silently overwriting it on retry.

No production signing key, accepted Windows certificate, customer release or active publication directory has been created by the development tests.

## Activation of an accepted release

Assembly does not replace the single final release gate. The accepted installer/components, supported role/platform functionality and real installed-machine evidence must exist before offering a supported customer release.

A controlled deployment can select the accepted directory through ENDPOINT_RELEASE_DIRECTORY, whose default remains storage/app/private/endpoint-releases. Use an absolute private path outside public web storage. Configure ENDPOINT_RELEASE_PUBLIC_KEY to the catalogue authority and advance ENDPOINT_MINIMUM_CATALOGUE_REVISION to the approved revision. These are protected deployment settings, not web-user choices. The publisher private key must not be copied into the deployment.

Deploy the immutable directory and its appropriate filesystem permissions first, validate it with the application's actual reader, then activate the path/key/floor together through the deployment's normal configuration/reload mechanism. Preserve the existing accepted directory until the new deployment is verified. Do not copy over an active directory in place, lower a revision floor or point production at development fixtures. A new higher-revision catalogue can withdraw all artifacts using an explicit empty list.

Activation automation, durable highest-seen catalogue/installed revision storage, publisher-key rotation and recovery remain unfinished. The current application enforces the configured minimum revision; it does not automatically persist a higher observed value. No activation or environment-setting change was performed in this implementation turn.

## Verified evidence — 8 October 2026

- Go suite: 55 passing top-level tests across release, publisher command, setup command and setup transaction; no skips in the final environment-enabled run. Fourteen new catalogue/command cases include positive assembly, explicit withdrawals, changed source/bytes, wrong package metadata/key, unsafe fields/paths, expiry, cancellation, failed signing, no-overwrite behavior and actual unsigned Windows PE rejection.
- Real cross-language check: generated signed Windows/combined-role and Linux/site-only metadata plus exact copied bytes were accepted and downloaded by the production PHP EndpointReleaseCatalogue class. It rejected the wrong catalogue key, a higher configured minimum revision and changed artifact bytes. An empty withdrawal catalogue also passed. This runs without booting Laravel, a database, device enrolment or artifact execution.
- Existing PHP catalogue suite: 8 cases / 40 assertions passed, including hardlinks and immutable response snapshots.
- Go vet and Windows amd64/arm64 builds pass for publisher/setup; PHP syntax and scoped formatting pass. These compiled programs remain unsigned development output.
- No application UI behavior changed, so prior actual-browser evidence remains scoped to the already implemented download/profile pages. No browser screenshot is used as proof of publication.

Signed installed Windows acceptance, native role activation, Windows/Linux collector lifecycle, update/rollback/uninstall and the wider Security & Devices programme remain under the full release gate.
