# Windows package assembly

This is publisher tooling for the single-organisation application. The same generic software can be used by independent customer deployments; approved Site/device enrolment remains in each deployment's separate, short-lived profile. No deployment selector, server credential, profile or AI key belongs in a release.

## Implemented operator command

The new endpoint-agent/cmd/oblivion-release command builds the Windows offline ZIP accepted by oblivion-setup. It does not install software, create Authenticode signatures, publish the download catalogue or upload a release. Output is an offline package and a JSON build receipt, not an operational-acceptance claim.

From a dedicated, access-controlled publisher workspace:

~~~text
oblivion-release build --stage <curated-directory> --metadata <metadata.json> --output <new-release.zip> --publisher-key <expected-base64-ed25519-public-key>
~~~

The publisher environment supplies the canonical Base64 encoding of its 32-byte Ed25519 signing seed over standard input, optionally followed by LF or CRLF. Do not type the secret in command arguments or terminal history. The command has no secret flag, key-file option, generated-key fallback or unsigned mode. Its bounded input reader rejects malformed/oversized input and requires the derived public key to match the explicit public pin. Buffers are cleared on return as best effort, not a secure-memory guarantee. A protected signing service can instead implement the internal library's crypto.Signer interface; no HSM/KMS adapter is claimed.

The signing authority is the software publisher, separate from deployment command signing and central OpenAI/Claude configuration. The publisher seed must not be deployed with the web application or endpoints. Use the corresponding public key in the controlled setup build; the package cannot replace the setup's trust pin.

metadata.json is a strict, bounded JSON object containing version, revision, architecture, roles, published_at and expires_at. The existing installer contract validates numeric three-part versions, positive revision up to 2^53−1, amd64/arm64, declared computer/site roles and a validity window of at most 90 days. Metadata cannot supply hashes, file paths, a trust override or enrolment authority. The command rechecks freshness before signing and creating output, accounting for elapsed build time.

## Payload preparation and verification

The input must be a new, deliberately curated payload directory. Never point it at a checkout, live installation, user profile, deployment configuration or collector state directory. The builder inventories every ordinary file under that directory. It cannot determine whether an otherwise valid runtime/configuration file accidentally contains a secret; clean staging and inventory review remain publisher responsibilities.

For computer support, include:
- computer/oblivion-agent.exe.
- computer/obli.exe and every actual runtime/dependency file from the corresponding Windows tray publish output, preserving file-name casing.
- Required licences/notices and supporting non-secret application files under computer/.

The existing release contract also describes site/both layouts, but their required Windows collector host, installation activation and installed acceptance remain unfinished. A declared role or successful archive check does not implement it. Linux site-collector archives require a separate packaging path; this command cannot build them. Computer agents remain Windows only.

The implementation:
1. Anchors the staging directory with os.Root, refuses linked/unsafe paths and non-regular payload objects, limits file sizes/count/total bytes and copies into a fresh snapshot in the controlled output directory. It preserves runtime casing and rejects ambiguous Windows paths.
2. Validates the complete computed path/size/SHA-256 inventory against the same manifest contract as setup. Missing required programs, undeclared file roots and invalid metadata fail before signing.
3. Requires Windows PE architecture and Authenticode verification for every .exe in the snapshot. There is no production verifier injection or allow-unsigned switch. Windows read handles deny writes/replacement from this check until archive creation finishes. DLLs and other dependencies are bound by exact manifest hashes; the command does not claim individual Authenticode/architecture checks for managed DLLs.
4. Signs the exact OBLIVION-RELEASE-V1 newline-prefixed manifest bytes with the matching Ed25519 authority and verifies the returned signature. No arbitrary-message signing operation is exposed.
5. Creates a bounded ZIP, checks the copied bytes against the inventory again, and reopens it through the existing independent installer verifier before creating the requested output.
6. Computes output size/SHA-256 and atomically creates a new destination by same-volume hard link, then removes the private staging link. Existing or concurrently appearing outputs are never overwritten. The filesystem must support hard links (for example the controlled NTFS release workspace); unsupported filesystems fail closed.

The staging/output directories and signing environment are trusted publisher infrastructure with appropriate Windows ACLs. Mode bits are not claimed to create that ACL boundary. The tool makes no hostile-local-administrator guarantee. Snapshot cleanup is attempted on success/failure. A process crash may leave a private .oblivion-release-* directory, and a crash between link creation/removal may leave a shared hardlink that the application catalogue correctly refuses. Inspect and recover only the specific build workspace; do not blindly delete unrelated paths or overwrite a prior artifact.

The receipt records the absolute output path, exact length/digest and manifest. It contains no signing material. If writing the receipt fails after building, the command reports that the output may exist and must be inspected before retrying. Cancellation before output creation does not publish a result. Output is not automatically placed in application storage.

## Remaining release work

This tool fills the offline archive-assembly gap. Still required:
- Controlled compilation and provenance, supported runtime/licence inventory, actual Authenticode signing/timestamping and accepted publisher certificate/key custody.
- A separately signed setup executable with the correct build-pinned publisher key.
- The separate catalogue command now creates a signed catalogue and copied artifact directory; see RELEASE-PUBLICATION.md. Actual accepted deployment activation and revision-floor advancement remain required. The ZIP builder receipt itself is not a signed download catalogue.
- Highest-installed-revision enforcement, repair/update/rollback/uninstall, unified computer/site/both installation and Windows/Linux collector packages.
- Actual signed installed Windows service/tray/server/relay acceptance, both supported architectures and the entire programme's vendor/hardware acceptance. Normal remote Connect remains disabled until its required evidence exists.

Tests use explicit fake executable bytes and fixed test-only signing seeds for the private assembly seam; the public API/command always uses the real OS verifier. Positive fixture round-trips prove format, integrity, race/failure handling and installer compatibility, not accepted production Windows signatures or installed operation. No supported customer release has been published.
