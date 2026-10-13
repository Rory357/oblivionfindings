# Windows service repair

8 October 2026. Implemented operator command, native Orbit repair flow and isolated evidence; signed installed acceptance is pending. This work belongs to the full Security & Devices programme for one organisation per deployment across approved Sites.

## Operator flow

Use the exact same publisher-signed release archive that matches the completed protected installation receipt. The archive must still satisfy the existing freshness/architecture checks. Use a signed, publisher-configured setup core from the controlled distribution:

~~~text
oblivion-setup repair-services --package same-release.zip --role computer --confirm-server https://care.example.test --confirm-service-repair

oblivion-setup repair-services --package same-release.zip --role both --confirm-server https://care.example.test --confirm-collector-server https://collectors.example.test --confirm-service-repair
~~~

Site-only uses --role site. A separate collector origin must be explicitly confirmed; omission means the application origin. No computer/collector bootstrap profile, enrolment grant, publisher override or unsigned bypass is accepted. The original setup grant may have expired; repair uses the existing committed identity and does not attempt enrolment.

The command repairs service registration/configuration, corresponding Event Log sources and the ordinary-user tray startup entry for the installed role. It starts stopped/missing restored services and restores delayed automatic startup. It does not stop already running healthy services, launch an elevated tray, replace program files, change roles or switch deployment/device/Site identity. For repair, the validated owned service has its delayed automatic startup restored before StartService, allowing a disabled service to recover. Fresh installation still enables automatic startup only after readiness. Ownership is re-read before changing startup settings.

The Orbit graphical setup now exposes Repair Obli services alongside Install Obli. It uses the same pinned companion engine, Theme.xaml and Obli artwork. Select only the matching software archive; the read-only review discovers the installed role, computer name, Site reference and server origins from protected installed state. It does not ask for a new enrolment profile or ask the operator to re-enter server addresses. Review is available to the same elevated, signed setup authority; it is not an unauthenticated machine-inventory API.

The operator core also supports review-repair --package same-release.zip. Its 17-field public response contains action/role, software metadata, local computer name, server origins, installed UUIDs/Site reference, installation attempt/manifest/stage, expiry and review digest. It contains no credential, signing key, certificate body or raw private-state fingerprint.

The review lasts at most ten minutes and never outlives the signed archive. A domain-separated digest binds the complete validated installed receipt, public review and private identity fingerprints. Computer fingerprinting excludes mutable heartbeat sequence/queued reports, so normal reporting does not invalidate a review; changes to UUID, server, server key or credential do. Collector identity/certificate-reference content is also bound.

The graphical repair invocation supplies --review-digest, --review-expires, the reviewed role/origins, --confirm-service-repair and --control-stdin. Incomplete reviewed confirmations are rejected. Controlled repair rechecks the current installed details under the setup lock before its first mutation. Changed identity/release/details or expired review require another review. The explicit operator repair command remains supported without graphical confirmation arguments.

The screen requires a separate confirmation checkbox, disables expired review, clears confirmation on Back, prevents duplicate operations and waits for cancellation outcomes on close. A successfully committed receipt is not hidden by late cancellation. Strict result parsing binds the response to the reviewed installation attempt/manifest/stage/role/revision. A failed or unconfirmed result does not show success.

The IT workspace does not yet dispatch service repair remotely. No supported repair binary has been published.

## Checks before changes

The setup executable must pass Windows signature verification before opening machine installation state. Repair requires elevation and the existing protected ProgramData installation tree, exact completed receipt and setup lock. It opens only existing directory chains; it does not create a substitute installation or identity directory.

Strict receipt decoding rejects unknown/case-variant/duplicate/null/missing fields, invalid state and identity shapes, changed release version/revision/digest/architecture, changed confirmed deployment or selected role, and incomplete/recovery-required installation records. The existing installation attempt and stage remain unchanged.

Before registration changes, repair checks the complete installed file tree against the signed archive, protected file/directory access, all packaged executable signatures, current service/startup/Event Log ownership and existing service permissions. It refuses foreign or modified registrations and unsafe permissions instead of adopting or overwriting them. Missing, changed or extra program files require the separate file-repair/update workflow; this service command does not rebuild them.

Computer identity inspection reads the atomically committed private state without taking the running agent's writer lock. It requires the installed UUID/server, a complete enrolment and valid configured server authority. It does not save the credential, sequence or pending report.

Collector checks bind the existing UUID, Site and confirmed transport; reject ambiguous identity JSON; inspect protected private state; and run only the packaged PHP runtime-check through the existing bounded process launcher. That command checks the saved certificate/key/authority and runtime extensions locally; it neither enrols nor contacts the central server. Runtime checks are bounded to 20 seconds, and the overall operator command has a 15-minute deadline.

## Durable results and interrupted repair

Each attempt creates a separate protected repair-<random-id>.json file in the existing Installer directory. It binds the original installation attempt, exact manifest, stage, role and revision. It contains no credential, enrolment profile or raw upstream error.

The first record is created exclusively and flushed. Each later intent/outcome uses the existing atomic protected writer. An intent is recorded before service registration, tray registration and service startup. A failure to save an intent prevents the next action. The original installation receipt is never rewritten by service repair.

Failure or cancellation retains existing and already-restored components, identity, certificates, reports, spool and release files. It does not run fresh-install rollback, which could remove services belonging to a completed installation. A subsequent explicit repair rechecks everything and converges on the same installed registration. Each attempt keeps its own outcome record.

The success status is local_services_ready, written only after all steps and the final record commit. This is local readiness, not an accepted heartbeat, tray interaction, remote support or provider integration. If the final save fails, the command returns an error even if services are running. After a crash, an in_progress record means an unconfirmed outcome; the record alone is not evidence that a repair process is still alive. Results are local records; central IT action-history integration remains work to do.

## Evidence and remaining work

- Receipt tests exercise Computer/Site/Both, exact archive/destination/role bindings, invalid identities and malformed/ambiguous records.
- Transaction tests inject each registration/start failure, every durable-save failure and cancellation. They verify retained restored components, separate retry records, unchanged installed binding and no false success.
- Injected Windows-service checks cover disabled-start ordering, running-service preservation, foreign or cancelled service refusal, startup/readiness failures and retry convergence. A changed service owner during fresh-install readiness prevents the later startup-setting write.
- Computer identity checks deny changed UUID/server, incomplete enrolment and malformed authority while retaining pending data.
- Collector checks use generated private identity files to prove UUID/Site/origin binding and reject duplicate/case-mismatched/null/trailing data. The actual PHP runtime-check confirms generated identity/certificate/key files remain unchanged.
- A real publisher-signed fixture archive still cannot pass an unsigned setup executable into machine repair. Unconfigured publisher authority and attempts to supply replacement enrolment profiles are rejected.
- Scoped Go tests/vet and agent/collector/setup builds for Windows amd64/arm64 passed. Native WPF checks and self-contained x64/ARM64 publication passed.
- Actual Go review generation and repair-transaction receipt JSON were parsed by the real C# protocol. These use validated test receipts and an injected transaction host, not a privileged installed service.
- WPF tests cover strict review/result schemas, confirmation, expiry/back, duplicate prevention, close cancellation, late committed results, failure presentation and unconfigured engine refusal. Own-window visual trees were rendered and inspected at desktop and 520px width. Exact runs are in CHECKPOINT.md.

No actual SCM/registry repair, live installed identity, host enrolment or customer connection was used. Native adapter behavior, real signed installed interruption/retry, automatic-start restoration and Windows Event Viewer acceptance still need approved test machines. ARM64 compilation is not ARM64 runtime acceptance.

Full file repair/reinstall, upgrades/rollback/uninstall, durable highest accepted revision, expired-release maintenance, authorised IT dispatch, central action history and repair-record retention remain unfinished. An expired manifest cannot be refreshed or bypassed by this command; an approved update/maintenance flow remains required. All staff/technician and wider network/security/care/tracking/reconstruction requirements remain under the single final release gate.
