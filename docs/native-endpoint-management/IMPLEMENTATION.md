# Native endpoint management and Intune visibility

User-approved build direction, 7 October 2026. This supersedes the earlier NinjaOne choice. One operating organisation across approved sites; no multi-tenant architecture. Follow DESIGN.md and Rory's shared patterns. One complete final release gate.

Oblivion owns the native Windows computer agent, inventory, health, governed maintenance, approved task/software catalogue, execution evidence and IT links. Intune is read-only visibility through Microsoft Graph; Intune retains MDM enrolment, policy, app deployment and lock/wipe actions.

A canonical device can have both sources. Explicit mappings and one authority for each management workload prevent conflicting actions. Read-only Intune data never overwrites canonical site/person/lifecycle or native command ownership. Native agents and network collectors have separate identities.

Health & Safety owns SD-01/SD-02 on codex/health-safety-workspace. This branch does not duplicate those signal/maintenance fixes.

Required work: one-use enrolment and revocation; real platform inventory/health; signed bounded commands using existing approvals and final-delivery reauthorisation; durable replay protection; approved immutable tasks and result reconciliation; read-only Intune snapshots with safe pagination; Rory-compliant connected workspace; Windows computer service packages/downloads and Windows/Linux site collector packages/downloads; remote-support integration; isolated backend/agent/frontend tests and actual browser acceptance. Synthetic evidence, native host execution and live Intune/hardware evidence must be recorded separately.

This is an implementation record, not a completion claim.


## Installation and Windows service foundation — 7 October 2026

Source now implements a POST installation-profile attachment at the existing protected computer lifecycle boundary. Its HTTPS origin and organisation label are deployment configuration (ENDPOINT_SERVER_URL and ENDPOINT_ORGANISATION_LABEL), not request-host input. Profile creation holds the canonical device lock through current permission/site checks and label validation; failed construction rolls issuance back. Tokens remain short-lived, hashed in storage and absent from events.

The Windows CLI consumes a bounded profile, requires the expected agent UUID and signing key in the server reply, and preserves the original secret for pending retries. Existing enrolled or mismatched state cannot be silently repointed. Endpoint enrolment and authenticated reporting now enforce Windows-only computer agents; no site collector platform was changed.

The shared runner handles persisted reports, bounded retry, cancellable shutdown and acknowledgement-before-deletion. The Windows service host reports readiness only after opening enrolled state and validating its destination, accepts stop/shutdown, bounds startup/shutdown waits and does not create an interactive user session. It uses a fixed Windows known-folder location and sanitized event messages. Service mode is accepted only under SCM.

This foundation is source-level service hosting and bootstrap, not installed/accepted software. Subsequent work implements release verification and the computer installation transaction below. Repair/re-enrolment/uninstall/update, the unified Computer support / Site monitoring / Both package, tray/service IPC, remote-control transport/consent and central AI remain unfinished. The full programme release gate is unchanged.

## Computer installation transaction — source implemented, pilot pending

- Setup confirms the exact deployment origin from the bounded profile, checks its expiry and expected identity, and requires administrator elevation. No API key is in the profile.
- A private, exclusively locked journal binds the exact signed manifest digest/revision, architecture, role, destination and agent UUID. Strict decoding refuses damaged/ambiguous records, identity changes and implicit downgrade/repointing.
- Protected stages are checked as an exact file tree with hashes, sizes, extra/missing entries and file ACLs. Agent/tray executables require Windows signatures. An incomplete inactive stage is replaced only before staging completed, never overwritten.
- Enrolment preserves the generated credential. A retry reaffirms the same enrolment with its still-valid profile. A running service from that unfinished attempt is stopped before opening its exclusive state lock. Completed installations need a separate repair/update workflow.
- SCM uses a quoted immutable binary path, LocalSystem identity, a service SID, protected administrative configuration/control and bounded crash recovery. Recovery settings are applied again on retry after interrupted registration.
- The HKLM Run entry points to the staged Obli binary with --tray. Windows starts it in the signed-in user's ordinary session at next sign-in. Setup does not launch an elevated tray or claim it is already running.
- Startup waits for local enrolled-state readiness, then enables delayed automatic startup and commits the protected receipt. This does not prove a server heartbeat or interactive tray readiness.
- Cancellation, step failures and journal failures use a separate bounded cleanup context. Rollback removes only matching service/startup entries and retains identity, report spool and staged files. Incomplete cleanup requires administrator recovery.

Dedicated Windows Event Log sources now have installer-owned registration, interrupted-registration recovery and rollback cleanup; services refuse unconfigured source fallback. See WINDOWS-EVENT-LOG.md for exact ownership, sanitised event IDs and remaining native registry/Event Viewer acceptance. No live service registration or privileged installation has run on this workstation.

Verification: seven Go packages pass, with 54 passing top-level tests and two explicit integration skips. go vet passes; agent/setup compile for Windows amd64 and arm64. Tests cover interruptions, failure at every journal commit, preserved retry identity, cancellation after service startup, incomplete cleanup, strict journal binding, altered staged bytes, service ACL/ownership rejection and unsigned setup rejection. Privileged filesystem/service/registry behavior still needs the isolated Windows pilot.

## Native tray and service status channel

The WPF helper and authenticated status-only local pipe now build for Windows x64/ARM64. See endpoint-agent/tray/README.md for the protocol boundary, reproducible build, six native check groups, real temporary pipe evidence and remaining acceptance. AI, staff login, tickets and remote-control actions remain unavailable; the full programme is not complete.

## 8 October: staff sign-in and personal tickets

Implemented the native browser sign-in and reviewed personal IT ticket path; see [STAFF-HELPER.md](STAFF-HELPER.md) and the latest checkpoint for evidence and exact limits. This supersedes earlier unavailable-state notes for those two source capabilities only. AI and real remote support remain unfinished. The full programme and one final-release gate are unchanged.

## 8 October: private support and remote policy/session foundation

Central settings/private AI text chat and explicit approved diagnostics now exist in source; STAFF-HELPER.md and CHECKPOINT.md supersede earlier unavailable-state notes for those paths. Remote-support policy, the session broker and signed protocol validation are described in REMOTE-SUPPORT.md. Actual Windows remote consent/media/control and technician AI remain unfinished. Earlier status-only/service/UI descriptions are historical checkpoints, not the current completed-feature list. One final-release gate remains unchanged.
