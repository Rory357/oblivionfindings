# Windows service diagnostics and source ownership

8 October 2026. Implemented source and isolated checks; no privileged registration or installed-service acceptance has run.

Each Oblivion deployment serves one operating organisation across approved Sites. This logging work adds no organisation selector, cross-customer transport or new authority boundary. The Windows computer agent and Site collector keep their existing separate identities.

## Installer and runtime contract

The installer registers only the fixed Application log sources OblivionAgent and OblivionCollector, according to Computer support / Site monitoring / Both. It registers a source before its matching service and verifies its complete ownership/configuration before service startup.

The source uses the Windows System32 EventCreate.exe message resource, located through the Windows system-directory API rather than an inherited environment variable. Setup checks that all current event IDs have the expected insertion resource, loading the file only as data/resources. The existing operating-system file is not copied, executed or modified.

Each source has exactly four values: OblivionInstallation (the owner marker), CustomSource=1, EventMessageFile (the absolute system path) and TypesSupported=7. The owner marker binds the fixed source name to the exact installation attempt and signed-manifest digest. Source keys are created with protected administrator/SYSTEM ownership and full control, plus built-in Users read access. A source contains no enrolment secret, provider key, inventory or chat.

The reader uses bounded name/value buffers and typed values; it rejects unexpected values, subkeys, changed content and unsafe ownership/permissions. It opens the fixed child without following a registry link. Local administrators remain trusted host authorities; this is not a claim of resistance to a malicious administrator changing machine state.

Both services validate a complete managed source before opening their event handle. They refuse Windows' unconfigured Application-source fallback. If source validation itself fails, startup fails through the service manager; reporting through that rejected source cannot be promised.

## Interrupted setup and cleanup

The owner marker is written and flushed first. A retry may fill missing known fields only when the original exact owner and remaining fields match. A complete retry does not rewrite values. Cancellation, failed writes/flushes and final validation failures do not report successful registration.

If a crash/write failure leaves an ownerless key, setup retains it for administrator review; it does not claim or delete an object it cannot attribute. Existing foreign or modified sources are likewise preserved.

Failed-install cleanup attempts each selected service independently. Only after matching service cleanup succeeds does it remove that attempt's matching complete or partial source. A service that might still be active retains its source. Cleanup failure joins the existing recovery-required result. Source removal is non-recursive and does not clear historical Windows event records. It retains the system message-resource file and installed identity/spool/release evidence.

The source lifecycle participates in the existing service-registration phase and installation lock/journal. The separate completed-install service-repair command now restores a missing/partial source only for the original installed ownership (SERVICE-REPAIR.md). Full file repair, update and uninstall remain unfinished; no new installation attempt may take over an existing source.

## Messages

| Source | Event ID | Type and meaning |
| --- | --- | --- |
| OblivionAgent | 1 | Information: bounded existing connection states, confirmed local readiness, or completed Windows-requested stop/shutdown. |
| OblivionAgent | 2 | Error: protected-state access or service lifecycle failure requiring administrator review. |
| OblivionCollector | 10 | Information: local readiness, fixed collection-cycle status or completed Windows-requested stop/shutdown. |
| OblivionCollector | 11 | Error: fixed collection/lifecycle failure status. |

Messages use fixed sanitised strings. Raw child output, exception details, URLs, credentials, observations and staff conversations are not logged. Local readiness does not prove that Oblivion accepted a heartbeat, every device check is healthy or remote support is operational. Startup timeout and unexpected worker exit cannot emit the clean requested-stop message. Event reporting can itself fail; validated registration does not guarantee event delivery.

## Verification and remaining acceptance

- In-memory policy tests cover both fixed names, exact ownership, idempotence, interrupted registration, wrong attempt/release/role, foreign/ownerless/insecure sources, unexpected fields/links, failed writes/flushes and cancellation.
- Native Windows tests parse the intended security descriptor and reject unsafe variants. A read-only check loads the real Windows resource and verifies current IDs, including that a spoofed SystemRoot environment value cannot change the chosen path.
- Direct service-handler tests verify readiness, stop/shutdown, unexpected exit, startup timeout and bounded cancellation. They do not register/start real services or write event records.
- Scoped tests/vet passed for eventsource, servicehost, collectorhost, setup and all three runtime/setup commands. Agent, collector and setup compile for Windows amd64 and arm64. See CHECKPOINT.md for exact runs.

The production registry adapter has not been exercised against real HKLM source creation/deletion. Signed Windows pilots must verify native source ACLs, interruption/retry, retained foreign entries, cleanup ordering, Event Viewer rendering, actual event delivery and service start/reboot/stop behavior on both supported architectures and intended Windows versions. The message-resource check proves only compatibility on the current test host.

There is no supported signed installer publication, live machine enrolment or operational remote-control acceptance from these checks. The full Security & Devices release gate remains active.

## Platform references

Microsoft documents the [Application event-source registration model](https://learn.microsoft.com/en-us/windows/win32/eventlog/event-sources) and [registry creation/security attributes](https://learn.microsoft.com/en-us/windows/win32/api/winreg/nf-winreg-regcreatekeyexw). The implementation uses the pinned local golang.org/x/sys/windows interfaces. These contracts guide the source; they are not release acceptance evidence.
