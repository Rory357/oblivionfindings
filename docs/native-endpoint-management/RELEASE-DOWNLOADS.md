# Agent downloads and publisher catalogue

The application remains one operating organisation across approved Sites. Customer deployments each configure their own server; the common software carries no customer selector, AI key, reusable machine credential or deployment identity. Downloading software does not enrol a device or grant control.

## Implemented distribution surface

Security & Devices → Setup → Agent downloads serves the real Inertia page at /security-devices/agent-downloads. Its source distinguishes Windows installers, Windows offline packages and Linux site-collector archives. Computer support is Windows only. Windows releases may declare computer support, site monitoring or both; Linux releases must declare site monitoring only.

A signed catalogue can describe these roles without proving they have been implemented in the installer. In particular, site/both activation and the Linux packaged collector remain unfinished. No supported release has been published, and no sample release is placed in application storage. Missing/untrusted/expired catalogue data produces an explicit empty state, not a development executable.

Both page and file routes require authentication, securityDevices.viewAny and either securityDevices.commands.admin or securityDevices.integrations.manage. The controller reloads current account/RBAC evidence inside a transaction, requires approved and verified internal accounts, and honours required two-factor setup. It deliberately does not require an unrelated IT helpdesk grant or access to arbitrary Site records for generic binary bytes.

Computer-role artifacts require commands.admin; site-role artifacts require integrations.manage; combined artifacts require both. Direct file requests enforce the same rule and return 404 for an unavailable artifact/capability. Device-register and collector-management links also require those destinations' view permissions. The existing enrolment/profile services retain separate canonical device, assignment and approved-Site checks.

File responses require HTTPS. Their route binds both publisher artifact ID and expected SHA-256, so stale links cannot silently switch to another release. Files are attachments with no-store, nosniff and a sandbox content policy. There is no remote URL fetch, directory listing or caller-provided filesystem path.

## Publisher trust and deployment configuration

- endpoint_management.release_directory defaults to storage/app/private/endpoint-releases. The protected ENDPOINT_RELEASE_DIRECTORY setting can select an accepted immutable publication directory; callers cannot change it through the download API.
- ENDPOINT_RELEASE_PUBLIC_KEY contains the canonical Base64 Ed25519 publisher public key. This is independent of the deployment's command-signing authority.
- ENDPOINT_MINIMUM_CATALOGUE_REVISION is the protected deployment's minimum accepted catalogue revision (default 1). The controlled publishing/deployment process must advance it. The download service does not itself persist a highest-seen revision.
- Only the controlled release process should write the directory, catalogue, key pin and revision floor. The publisher private key must remain outside customer application configuration. Web users cannot upload releases or replace trust configuration through this page.
- Download distribution requires the completed signing/build/acceptance process. It does not replace Windows Authenticode checks, the setup executable's build-pinned package key, the signed release archive, component verification or installed-host acceptance.

downloads.json is a strict JSON envelope with exactly payload and signature. Both values use canonical Base64. payload contains the exact UTF-8 JSON bytes that were signed. signature is Ed25519 over the bytes "OBLIVION-DOWNLOADS-V1\n" followed by the payload bytes; \n denotes one LF byte.

Payload keys are exactly:
- schema: integer 1; product: oblivion-downloads.
- revision: positive integer, at least the configured floor and at most 2^53−1.
- issued_at and expires_at: UTC timestamps in YYYY-MM-DDTHH:MM:SSZ form. Publication cannot be more than five minutes in the future; expiry must be in the future, after issue and no more than 90 days after issue.
- artifacts: a list of up to 16 objects.

Each artifact has exactly:
- id: 2–64 lowercase alphanumeric/hyphen characters, beginning with an alphanumeric character; unique within the catalogue.
- platform: windows or linux; architecture: amd64 or arm64.
- roles: a nonempty unique list of computer and/or site. Linux permits only [site].
- version: numeric major.minor.patch without leading zeros; release_revision: positive integer at most 2^53−1.
- filename: a safe flat ASCII filename, at most 160 characters. Windows permits .exe or .zip; Linux permits .tar.gz. Traversal, device names, alternate data streams and case-insensitive duplicate filenames are refused.
- size: exact positive length, no more than 512 MiB; sha256: exactly 64 lowercase hexadecimal characters.

Envelope reads are bounded at 64 KiB. Duplicate JSON keys, unknown fields, malformed dates, incorrect signatures and unsupported types are rejected. Files must resolve inside the configured flat release directory and be regular, singly linked files rather than symlinks.

Before returning any download body, the service copies the file into a temporary snapshot while checking exact length and digest. It then revalidates the current signed catalogue and exact artifact metadata, and the controller rechecks current account/capability authority. Failures discard the copy. The response streams that verified copy, so later mutation of the source cannot change accepted response bytes.

These are authorization/publication decisions at request time. A transfer already returned to an authorized caller is not a remotely revocable copy.

## Relationship to installation profiles

Generic release files are independent of the organisation-specific, short-lived installation profile. Existing computer profile issuance uses the deployment's explicitly configured HTTPS origin, organisation label, approved device identity and enrolment authority; it never derives the destination from a download Host header. See endpoint-agent/README.md and RELEASE-INSTALLATION.md.

The page links to the canonical device register and collectors workspace. Eligible computer records now provide a guided Set up Obli profile review/create/save/revoke flow; see INSTALLATION-PROFILES.md. The offline Windows archive builder and signed catalogue-directory assembler now exist; see RELEASE-BUILDING.md and RELEASE-PUBLICATION.md. Actual Authenticode signing, accepted deployment activation, the native installer experience, separate collector/combined-role setup, repair/update/rollback/uninstall and operational Windows/Linux collector acceptance remain required. Do not present this catalogue as a finished installer.

## Verification and limits — 8 October 2026

- Unit catalogue checks: 8 passing cases, 40 assertions. Includes signature/domain/key/schema bounds, expiry/revision rules, Linux-role denial, byte changes/missing files, hardlinks and immutable response snapshots.
- Real Laravel feature checks against a process-isolated MySQL database: 7 passing cases, 119 assertions. Includes role-filtered pages/files, no unrelated IT grants, HTTPS, current-account/permission denial, exact attachment bytes/headers, empty state, stale/tampered/expired releases and no enrolment/provider side effects.
- Existing navigation contract: 4 cases pass with the new permission-filtered Setup entry.
- Actual Chromium rendered the real isolated-worktree React page and shared stylesheet on port 52214, with explicitly fictional release props. Empty/populated, light/dark, 390px, disclosure and sidebar states were inspected. An in-page meter initially triggered a server visit; it now uses the shared meter's in-page action and the browser rerun confirmed no server visit, clipping or horizontal overflow.
- Browser fixture links are not actual published installers; backend file tests use non-executable fixture strings. No production release key, signature, service install, machine enrolment, live provider, real-user capture or production migration was performed.
- The checked Windows environment exposed Hyper-V commands/services, but VM inspection was denied by Windows permissions. The current-user certificate store returned no code-signing certificate. These observations do not prove that no suitable VM/certificate exists elsewhere; signed installed acceptance remains pending.

The entire Security & Devices programme remains active under one final release gate.
