# Governed Windows scheduled restart

Development source, 8 October 2026. The complete Security & Devices programme remains active and unshipped. Computer agents are Windows-only. Windows/Linux Site collectors remain separately scoped capabilities in the combined installation experience. Each customer runs an independent single-organisation deployment across approved Sites.

## Technician and staff experience

IT & Support → Remote support offers a separately permissioned Restart policy action for the selected computer. The existing review wizard prepares a disabled-by-default, versioned policy: staff consent or separately authorised unattended operation, and a minimum warning of 60–3,600 seconds. A lost save requires reading the saved policy before another edit.

The existing Device Management workflow owns the restart request, purpose, impact acknowledgement, independent approval, queue, attempt, delivery and audit history. The distinct capability is endpoint.computer.restart; the tracking-provider device.reboot capability is not repurposed. The technician selects a New Zealand local time within 24 hours. The UI converts it to exact UTC and rejects daylight-saving gaps or ambiguous repeated times. A lost request retains its original immutable details for explicit retry.

The Orbit helper shows the technician, complete bounded purpose, scheduled time in the computer's local time and the service-owned countdown. Attended operation requires Allow scheduled restart and uninterrupted visible warning in the current active, unlocked Windows session. Hiding the notice, changing session, losing consent or missing the deadline prevents execution. The helper reports visibility; the service independently validates the OS session and expires stale visibility. This does not constitute independent service attestation that the helper is unoccluded.

Unattended operation needs separate policy and permission. Consent for restart grants no screen control or AI execution. Obli continues to read and suggest.

Cancellation remains available before the execution boundary. The server displays Cancellation requested until the exact local no-execution acknowledgement is accepted. A lost local reply does not claim cancellation; the helper reads current state, then permits an explicit retry only if that attempt still allows cancellation. Closing Obli is not presented as cancellation. Postponing requires cancellation and a newly approved schedule.

## Authority and execution

The signed immutable schedule binds the canonical command, attempt, enrolled agent/incarnation, assignment, policy revision, technician, purpose, execution time and warning. It expires five seconds after the scheduled time and always specifies force_close:false. No shell command or arbitrary deadline change is accepted.

MFA and recent identity confirmation apply to request, independent approval, queue and initial dispatch. Only an already-dispatched signed schedule may retain its original nonfuture identity proof beyond the ordinary 900-second freshness limit. At final claim the server rechecks the current requester and independent approver, MFA eligibility, permissions, Site, device assignment, policy and revocation. This exception does not relax ordinary service actions.

A fresh short claim is available only near the scheduled execution time. The agent rechecks signed identity, payload, deadline, clock drift, warning and current consent after network delays and immediately before the actual Windows API. Privilege/DLL preparation failure is operation_failed with mutation_started:false; authority loss is policy_unavailable,false; only an actual API refusal is restart_refused,true.

The protected durable journal records the point beyond which execution may have happened. Crashes leave an uncertain outcome and never automatically repeat a restart. Missed windows, offline authority checks and changed clocks fail closed. Shared current-read database locking and the local mutation gate prevent overlap with unresolved service actions or another restart.

An accepted Windows request becomes Waiting for restart confirmation. It is not success. Only an authenticated later observation from the same identity and exact attempt, with changed startup identity and known accepted API evidence, can reconcile success. Unknown execution attribution stays uncertain. A pending restart restores its physical mutation gate before other workers or the first heartbeat; known refusal releases it.

## Recovery, privacy and release gates

The installed service binds restart recovery even when new execution is disabled. Withdrawal stops new offers, claims, capability advertisement and API calls while retaining cancellation, receipts and boot evidence. Missing restart capability remains valid for legacy/recovery-only heartbeats; a supplied capability must contain its complete strict four-field declaration.

Deployment and installed-service execution gates default to disabled. The ordinary developer console is not a restart execution path. Recovery state belongs to the existing authenticated agent and canonical command delivery; no second incident owner, tenant boundary or separate technician command queue is introduced.

The verified local pipe exchanges bounded, versioned, origin-bound status/visibility/decision messages. Consent binds the OS peer, exact attempt, payload hash and token. Provider keys, staff account tokens, private chat, screenshots and arbitrary shell content are excluded. Private content clears on session/context changes; late replies cannot restore a previous context.

## Development evidence and remaining acceptance

Native checks pass 61 groups, including unchanged PHP-to-Go requested/countdown/awaiting_boot/succeeded/cancelled local views, immutable replies, lost-approval recovery, explicit cancellation recovery, visibility clearing and narrow Orbit rendering. Both Windows x64 and ARM64 tray builds pass. Frontend checks cover reviewed policy, exact actor/version, New Zealand time conversion, immutable request retry, server-projected cancellation/boot states and unchanged PHP presentation and lifecycle DTOs. Real browser checks cover light/dark/390px layouts, expanded technical details and Inertia requests intercepted with recorded PHP projections; these are presentation checks, not a live deployed backend or installed-machine witness. Current confirmed results remain distinct from historical execution receipts, and recorded diagnostic details remain available.

Backend implementation and independent review addressed current-read mutation locking, crash attribution, final API timing, recovery under release withdrawal, strict preparation receipts and optional heartbeat compatibility. The final isolated application run passed 49 tests with 387 assertions, followed by a fixture-only cancellation export run (1 test, 8 assertions); both owned databases and processes were cleaned up. Actual unchanged signed PHP schedule/claim/result/boot acknowledgement and matching cancellation exports pass Go interoperability tests. Independent exact-field/type validation remains enforced after correcting MySQL JSON key-order comparison. The Go Windows agent/service-host builds and focused tests pass. CHECKPOINT.md records the exact evidence and initial failures. Execution is injected in these checks; no Windows restart was performed.

Required before release: signed installation on a dedicated development computer; actual attended and authorised unattended restarts; unsaved-work behavior without forced closure; cancellation/consent/revocation races; offline, sleep, clock and service-crash recovery; independent post-boot reconciliation; real upgrade/repair/uninstall; and every remaining programme requirement. No actual computer restart, installation, code signing or publication was performed for this development increment.
