# Windows release and installation boundary

This is implementation documentation for the full Security & Devices programme, not release acceptance. One operating organisation owns each deployment and its approved sites. A publisher may distribute the same signed application to multiple independent deployments. Deployment enrolment identity and software publisher authority are separate.

## Implemented verification

The endpoint-agent/internal/release package opens a ZIP containing release.json plus exactly the signed file list. release.json contains base64 payload and signature fields. The signature is Ed25519 over the literal prefix OBLIVION-RELEASE-V1 followed by a newline and the exact decoded payload bytes. The trusted publisher key comes from the controlled installer build; neither the archive nor an enrolment profile supplies that authority.

Payload schema 1 contains product oblivion-windows, a three-part numeric version, monotonically increasing revision, platform windows, architecture amd64 or arm64, unique roles, publication/expiry times and a list of path/size/SHA-256 file records. Validity is at most 90 days. Update callers must pass the current protected minimum revision; standalone verification uses zero and does not claim installed downgrade protection.

Supported role declarations:
- computer requires nonempty computer/oblivion-agent.exe and computer/obli.exe.
- site requires nonempty site/oblivion-collector.exe, site/runtime/php.exe, site/bin/oblivion-collector, site/vendor/autoload.php, site/runtime/cacert.pem and runtime/ext/php_curl.dll, php_openssl.dll, php_sodium.dll and php_sockets.dll beneath site/. See WINDOWS-COLLECTOR.md for the fixed runtime contract.
- Both means both role declarations must be present.

This describes package requirements, not installed acceptance of the tray or Windows collector host. The Windows collector host now exists and builds; Site/Both activation source now exists; signed runtime and installed acceptance remain unfinished. See COMBINED-INSTALLATION.md. The native tray now compiles for both architectures; its real runtime DLLs must accompany obli.exe and appear in the signed manifest. Filename casing is preserved while conflicting case spellings of any file/directory are rejected. Remaining dependencies and all vendor/runtime files must also appear in the signed list. No Windows support package can be assembled as a complete release while its required components are missing.

The verifier rejects altered/missing/extra entries, duplicate names or JSON fields, unknown fields, unsafe Windows paths, case ambiguity, alternate data streams, device names, links, conflicting file/directory paths, stale metadata, incompatible architecture, older revisions and incorrect publisher signatures. Bounds: 512 MiB archive, 1 GiB expanded bytes, 256 MiB per file, 2 MiB envelope and 8,192 payload files. Archive handles deny replacement/writes while open on Windows. Hashes are checked both during verification and while extracting.

ProtectedTarget extraction creates files through the Windows protected directory writer. It stops at a failed write and leaves an incomplete, inactive stage. It never activates a partially extracted package. The portable ExtractNew helper is for an empty staging directory and is not a substitute for the Windows ownership boundary.

Windows VerifyExecutable separately checks PE architecture and Authenticode trust with certificate revocation checking. There is no unsigned bypass. Unit evidence proves rejection of unsigned development executables and non-executable files; positive publisher certificate acceptance remains external release evidence.

The oblivion-setup command provides version, verify --package, and install for computer, site or both, with separate computer/collector profiles and explicit destination confirmation. See COMBINED-INSTALLATION.md for exact invocations. The trusted publisher key remains an empty build-time setting until the controlled signing pipeline supplies it. Installation also checks the running setup's Windows signature before machine changes. Runtime trust overrides and unsigned activation are rejected. The operator installer accepts Computer, Site and Both with the exact required deployment profiles; Site activation uses the recoverable enrolment path and fixed collector service. This is implemented source, not signed installation acceptance. Verification reports package metadata only, without claiming operational readiness.

## Offline package assembly

The oblivion-release publisher command now assembles the archive from a dedicated staging tree, validates every .exe through Windows Authenticode/architecture checks, signs the computed inventory and round-trips through this verifier before creating a new output. See RELEASE-BUILDING.md. It is not a Windows signing service, catalogue publisher or installed-acceptance substitute. No production release has been published.

## Implemented Windows folder boundary

securefs accepts production roots only from Windows Program Files and ProgramData known-folder APIs. It opens the complete path chain, rejects reparse points, and holds directory handles against renaming. A test caught insufficient handle access flags; using real read access fixed the rename lock and passed the Windows temporary-directory check.

New directories/files receive final administrator/SYSTEM ownership and ACLs at creation. Program files may be readable/executable by normal users; private state may not. Existing directories are accepted only with privileged ownership, protected directory ACLs, full administrator/SYSTEM access and no unsafe access entries. Existing files also reject reparse points, shared hardlinks, unsafe ownership and weaker effective ACLs. The code refuses unsafe objects instead of taking ownership of pre-existing contents.

The service holds the protected ProgramData/Oblivion/Endpoint chain and validates state.json plus agent.lock before opening the installed store. Writes preserve administrator/SYSTEM-only ACLs rather than applying foreground development permissions. Foreground development state remains distinct from accepted service installation. The service still does not register or install itself.

Verification covers in-memory Windows ACL policies, real temporary-file hardlinks/object types, preservation of rejected directories and actual directory/archive rename exclusion. The symbolic-link integration test was skipped because this host lacks symlink-creation permission. Actual privileged directory creation, installation and reparse/junction acceptance remain required in the Windows pilot.


## Computer installation transaction — source implemented, pilot pending

- Setup confirms the exact deployment origin from the bounded profile, checks its expiry and expected identity, and requires administrator elevation. No API key is in the profile.
- A private, exclusively locked journal binds the exact signed manifest digest/revision, architecture, role, destination and agent UUID. Strict decoding refuses damaged/ambiguous records, identity changes and implicit downgrade/repointing.
- Protected stages are checked as an exact file tree with hashes, sizes, extra/missing entries and file ACLs. Agent/tray executables require Windows signatures. An incomplete inactive stage is replaced only before staging completed, never overwritten.
- Enrolment preserves the generated credential. A retry reaffirms the same enrolment with its still-valid profile. A running service from that unfinished attempt is stopped before opening its exclusive state lock. Completed installations use the separate service-repair command for registration restoration; file repair/update remain separate unfinished workflows. See SERVICE-REPAIR.md.
- SCM uses a quoted immutable binary path, LocalSystem identity, a service SID, protected administrative configuration/control and bounded crash recovery. Recovery settings are applied again on retry after interrupted registration.
- The HKLM Run entry points to the staged Obli binary with --tray. Windows starts it in the signed-in user's ordinary session at next sign-in. Setup does not launch an elevated tray or claim it is already running.
- Startup waits for local enrolled-state readiness, then enables delayed automatic startup and commits the protected receipt. This does not prove a server heartbeat or interactive tray readiness.
- Cancellation, step failures and journal failures use a separate bounded cleanup context. Rollback removes only matching service/startup entries and retains identity, report spool and staged files. Incomplete cleanup requires administrator recovery.

Dedicated Windows Event Log sources now have installer-owned registration, interrupted-registration recovery and rollback cleanup; services refuse unconfigured source fallback. See WINDOWS-EVENT-LOG.md for exact ownership, sanitised event IDs and remaining native registry/Event Viewer acceptance. No live service registration or privileged installation has run on this workstation.

Verification: seven Go packages pass, with 54 passing top-level tests and two explicit integration skips. go vet passes; agent/setup compile for Windows amd64 and arm64. Tests cover interruptions, failure at every journal commit, preserved retry identity, cancellation after service startup, incomplete cleanup, strict journal binding, altered staged bytes, service ACL/ownership rejection and unsigned setup rejection. Privileged filesystem/service/registry behavior still needs the isolated Windows pilot.

## Remaining installer and update work

Complete and accept the unified installer. The Windows source implements computer/site/both service activation and separate enrolment in parts of steps 1-5; the complete product still requires:
1. Display the independently downloaded deployment profile's organisation, server, device/site and selected Computer support / Site monitoring / Both roles. Confirm the intended destination. AI keys never enter this package.
2. Verify publisher authority, all component bytes, Windows signatures and role/platform compatibility before privileged changes or consuming enrolment.
3. Stage into freshly protected Program Files paths. Initialise private ProgramData state through the appropriate computer and/or collector enrolment APIs; keep their identities separate.
4. Register only the intended services, with quoted immutable paths, administrator-only configuration/control, explicit service identities and bounded crash recovery. Never replace an unrelated existing service. Launch the tray in an ordinary interactive user session.
5. Commit a protected installation receipt only after required services and the tray launch path pass readiness. On failure, roll back only changes proven to belong to this attempt; preserve identity/spool/evidence for approved recovery.
6. Service-registration repair now exists; see SERVICE-REPAIR.md. Its graphical entry now exists with an installed-identity review; complete authorised IT dispatch and signed installed acceptance, plus full file repair, restart, upgrade, rollback, uninstall and explicit re-enrolment. Persist the highest accepted release revision; bind rollback to the exact previous verified installation. Never treat receipt metadata alone as successful operational acceptance.
7. Connect authorised downloads in Oblivion, including Windows/Linux collector packages. Independently verify staff consent, remote view/control, AI privacy, technician actions, offline recovery, crash/reboot/update cases, both Windows architectures and the supported provider/hardware matrix.

These are unfinished work, not disabled finished features. The full programme keeps its one final release gate.

## Platform references

Implementation uses the installed golang.org/x/sys/windows v0.48.0 APIs and Microsoft's [service access-rights guidance](https://learn.microsoft.com/en-us/windows/win32/services/service-security-and-access-rights), [CreateFile sharing contract](https://learn.microsoft.com/en-us/windows/win32/api/fileapi/nf-fileapi-createfilew), [reparse-point documentation](https://learn.microsoft.com/en-us/windows/win32/fileio/reparse-points) and [WinVerifyTrust options](https://learn.microsoft.com/windows/win32/api/wintrust/ns-wintrust-wintrust_data). These describe platform behavior; they do not constitute Oblivion release acceptance.

## Download distribution follow-up

The authenticated catalogue/page and verified private file distribution now exist; see RELEASE-DOWNLOADS.md. This completes that source component of step 7, not the signed publication, installation profile UI, unified role installer or Windows/Linux operational acceptance. No supported release has been published.

## Graphical setup continuation

The Orbit WPF launcher now drives the operator engine through read-only review and explicitly controlled installation. It pins exact companion bytes, holds the file/ordinary ancestor chain against replacement, and binds installation to the reviewed profiles and signed package digest. Controller disconnect/cancellation enters the existing cleanup path. See ../../endpoint-agent/installer/README.md for the flow, trust boundary and evidence.

Signed launcher/backend distribution and real elevated installation acceptance remain required. A compiled or rendered wizard is not installed service/tray/remote-control evidence.
