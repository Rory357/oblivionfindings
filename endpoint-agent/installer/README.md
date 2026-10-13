# Obli graphical Windows installer

Development implementation for one organisation per deployment, across approved Sites. Independent customers use their own deployment profiles. Computer agents are Windows only; Linux remains a Site-collector requirement.

## Guided setup

The WPF window reuses the actual Orbit artwork and semantic theme from the staff tray source. Choose Install Obli, then Computer support, Site monitoring or Both, then select the generic software archive and required deployment profiles. The review displays organisation, computer/Site, application and collector origins, version/architecture and profile expiry. Installation requires explicit confirmation.

The visible launcher is oblivion-setup.exe. Its manifest requests administrator access. The fixed companion oblivion-setup-core.exe is the existing Go engine for the matching architecture. These are internal components of one guided setup. Site-only installation registers no tray; Computer/Both uses ordinary-user tray registration. Setup never opens the staff helper elevated.

## Guided service repair

Choose Repair Obli services in the same window and select the matching installed release archive. The signed core reads the existing protected installation to show the local computer, Site reference, server origins and software. No enrolment profiles or retyped destinations are needed. The label uses the saved numeric Site reference because a current display name is not stored in the installation receipt.

An explicit checkbox confirms repair. Its expiring review binds the installed receipt and stable private identities; heartbeat progress alone does not invalidate it. Back discards confirmation. Controlled execution rejects changed details before mutation. Strict result parsing distinguishes locally-ready, failed and unconfirmed outcomes; late cancellation preserves a committed result. See ../../docs/native-endpoint-management/SERVICE-REPAIR.md.

## Trust and lifecycle

- An exact backend SHA-256 is compiled into the launcher. No runtime key/hash/path override or enrolment token is accepted in launcher arguments.
- Startup holds the ordinary local directory chain against rename and the exact single-link backend against writes/replacement before comparing its digest. Reparse points, special files, network paths and changed bytes fail.
- The backend separately verifies the publisher-signed archive, architecture and contents. Installation retains the existing self/component Authenticode and protected-state gates.
- Read-only review emits bounded public JSON. Its domain-separated digest binds role, complete validated profiles and exact signed manifest. No enrolment token or server signing key is exposed.
- Controlled install requires this digest and destination confirmation. Changed files fail before activation; profile/package freshness is rechecked.
- The launcher uses a fixed executable with ArgumentList, no shell, a minimal child environment and bounded strict-UTF-8 output. Standard input carries cancellation only.
- Closing/cancelling keeps the UI alive until cooperative cleanup returns. Any controller input, EOF or read failure cancels the backend. Installation has a 15-minute deadline; the launcher terminates an unresponsive child after 17 minutes and instructs the operator to review recovery state.
- A late cancellation does not erase a confirmed installed receipt. Failure/uncertainty never displays success. The result still requires checking actual connection/reporting in Oblivion.
- Expiry disables confirmation; abandoning review installs nothing.

## Build

Use the pinned .NET 10 SDK through build.ps1. It runs the native checks and publishes self-contained x64/ARM64 launchers, embedding native runtime files for extraction. No additional third-party UI dependency was added.

Without backend parameters, development outputs have unconfigured engine authority and cannot install. For controlled publication, supply BackendX64 and BackendArm64 from a dedicated approved build/signing workspace. The script checks PE architecture and Windows signatures, embeds the exact backend hash and verifies the copied companion. It does not sign the launcher or publish it.

The publisher must sign each resulting launcher, retain the exact pinned companion, inventory the distribution/licences, package it for the signed download catalogue and complete installed acceptance. Use fresh controlled output directories. The backend still requires its existing build-pinned release publisher key. No development output is a supported customer installer.

## Evidence

Obli.Setup.Tests injects review/install/repair outcomes and renders only the app's WPF tree. It never installs, elevates, registers services, contacts a server, captures the desktop or executes the backend. Optional OBLI_SETUP_RENDER selects the render directory.

Checks cover strict review parsing, duplicate/unknown fields, role/profile combinations, confirmation, expiry, duplicate review/install prevention, close/cancel races, failure presentation, bounded/invalid output and exact-backend file/ancestor locks using temporary fixtures. An unconfigured production engine is rejected.

Go's test-only OBLI_SETUP_REVIEW_EXPORT emits a real review from its signed fixture archive. OBLI_SETUP_GO_REVIEW asks the WPF harness to parse it. This is contract evidence, not a privileged subprocess installation.

## Remaining acceptance

Real signed launcher/backend execution, UAC, file-picker/keyboard/high-DPI/screen-reader acceptance, runtime/package provenance and installed SCM/service/tray/server/relay operation remain required. Event Log registration, ownership recovery and cleanup now exist in the backend; native registry/Event Viewer acceptance remains required (see ../../docs/native-endpoint-management/WINDOWS-EVENT-LOG.md). The operator core now offers service-registration repair (see ../../docs/native-endpoint-management/SERVICE-REPAIR.md); this graphical launcher now exposes it with reviewed installed details and explicit confirmation. Also unfinished: full file repair/update/rollback/uninstall, durable accepted-revision policy and the wider programme. The normal remote Connect gate remains unchanged.

Platform references: [Process arguments and redirected streams](https://learn.microsoft.com/en-us/dotnet/api/system.diagnostics.processstartinfo), [single-file deployment and native extraction](https://learn.microsoft.com/en-us/dotnet/core/deploying/single-file/overview). Exact evidence is recorded in ../../docs/native-endpoint-management/CHECKPOINT.md.

Repair contract evidence: the Go test-only OBLI_SETUP_REPAIR_EXPORT writes an actual review JSON and a real transaction receipt produced against an injected host. OBLI_SETUP_GO_REPAIR_REVIEW asks the WPF harness to parse both. The actual Windows protected-state/SCM adapter is not bypassed to fabricate a positive machine repair test.
