# Tracker indicators, address layout and audit completion checkpoint

Historical r5 checkpoint. The later user-authorized safe-zone activation and Control Room implementation is recorded in [control-room-zone-monitoring.md](control-room-zone-monitoring.md); candidate r6 supersedes the draft-only status below.

Candidate r7 additionally completes [client tracker modes and fall delivery](client-tracker-modes-and-falls.md). Use those current implementation records for status; the following remains historical evidence.

21 September 2026. Same worktree and sole implementer. Implementation and isolated verification only; no integration, deployment, real tracker commands or operational migrations.

## User-facing status and layout

`TrackerIndicators` replaces the solitary active chip with a consistent device status and two matching movement/fall controls above SOS and battery. Each control is at least 48px high, includes icon and text, and opens keyboard/touch-accessible report details. Movement distinguishes reported moving/stationary from unknown. Fall reports use an amber falling-person icon; man-down retains its distinct wording. No event is represented as a resolved/unresolved alert or as proof that a sensor is enabled. Missing fall evidence remains unavailable, not “no falls.” Future tracker adapters must emit canonical evidence before these indicators can report it; sensor configuration is not implemented here.

`ClientTrackerStatusService` reads current authorized assignment/consent evidence, bounds motion and falls by assignment start, collection start, consent date, retention and now, then rechecks the fingerprint before return. Movement uses the canonical Fleet event (consent_blocked=false) and the metadata motion/last_location_at tuple written by the existing telemetry service. Native DeviceEvent fall_detected and Fleet fall_detected/man_down reports are read for the exact assigned device and period. Only normalized state/type and occurrence time are exposed. The controller also retains its final original fingerprint recheck. This is a read-only projection, not a notification or acknowledgement writer.

Previously completed: address heading with smaller coordinates, recorded/nearest/unknown provenance, Location-tab-only full width, grayscale tile layers, charging animation, SOS provenance, pausable received-report refresh, map context menus, whole-shape dragging/Undo/keyboard handles and typed address search. Safe zones remain inactive planning drafts. Power-saving status/control remains unavailable. Private reverse geocoding is off by default and requires an organisation-controlled provider; the browser's explicitly labelled example address is synthetic, not a real lookup.

## Browser verification

Verified in the isolated preview at `http://127.0.0.1:4335/operations/clients/1?address-preview=synthetic&tab=location`, build manifest SHA256 `03B56C54FC87E53F92DE13A248CEE1F30499D18D008EFD34A953D38B3C059083`.

- Desktop: matching icons and labels appear above SOS/battery; controls measured 139x51.84px, with device status 286x51.84px. Fall unavailable opens the honest capability/status explanation; Escape closes and restores trigger focus.
- Synthetic moving report: “In motion” appears; detail shows the exact last report time (10:09am), distinct from the selected latest location observation (9:54am). This verifies the actual PHP projection into the browser.
- Synthetic native fall report: “Fall reported” and amber icon appear; detail includes the event timestamp and does not claim resolution status. No alert notification/dispatch was created (`DeviceEvent::withoutEvents` for the synthetic fixture).
- Mobile 390x844: document width 375, no horizontal overflow; controls 149x51.84px, details remain inside the viewport. Screenshot visually checked. Tile filter still `grayscale(1)`.
- Temporary preview motion/event data restored; command count remains zero. Viewport reset to desktop. Agent QA tab retained as deliverable. Browser console had no captured errors in the new build.

Earlier browser evidence retained in the working history: polygon and circle whole-shape drag with exact single Undo, individual corner editing, keyboard radius adjustment, explicit address-result movement, unsaved editor state retained over live refresh, selected historical observation retained across refresh, and unavailable Locate permission shown without dispatch.

## Canonical audit writer inventory

The only application `DeviceCommandAuditEvent::create` writer remains `DeviceCommandAuditService`. Appends current-lock the exact request PK, then its exact event PK, insert with the unchanged hash input format, and atomically write only audit_tail_event_id. Pointer is hidden/not fillable, rejected even when null on generic/batch/client request inputs and client resume, and creation/ordinary changes are model guarded. No request lifecycle or signed bytes are changed by the internal pointer write; the caller's instance is not mutated.

- Intake: `DeviceCommandRequestService` creates the request inside its owning transaction, and existing-key/re-entry locks the request before evidence. Initial requested/break-glass/notification audit uses that owned row. Idempotency collisions unwind before looking up an existing row under context locks.
- Queue and approval: request FOR UPDATE precedes state/audit writes. Approval has no attempt-first parent acquisition.
- Dispatch: preparation locks request before attempt creation; completion locks request then attempt. Existing precondition-failure appends occur under the caller's request lock.
- Reconciliation: request is locked before result and audit.
- Break-glass: post-use review locks request. Notification updates its request and appends; no attempt lock is acquired before the parent. In initial intake it remains inside the transaction that created the parent.
- Evidence export: standalone append obtains the request lock itself and supports immutable terminal requests.
- Collector result: request then attempt; reconciliation is nested in that same scope.
- Collector recovery: discovers parent ID without locking, then locks request before attempt and revalidates association, runtime, statuses and expiry. Existing expired/uncertain outcomes, actions, retry count and no-repeat behavior are retained.
- Queclink lifecycle: existing pending-row lock precedes the normalized linkedLifecycle request then attempt sequence. Its delivery/ACK/observation/expiry appends already own the parent. The pointer does not introduce an attempt-to-request inversion there.

Migration 000004 backfills exact MAX(id) tails and verifies every pointer. Null is an empty chain only under the all-writer migration protocol; arbitrary privileged SQL corruption is not claimed detectable. Missing/foreign non-null tails fail closed. No range-scan fallback or SKIP LOCKED is used. Future rollout must quiesce/drain all old audit writers, migrate/backfill/verify, deploy every new writer, then resume. Mixed old/new writers are unsupported. Down refuses when audit events exist; code rollback retains the column/events, and re-upgrade requires quiesced pointer rebuild/verification. None of those operational steps were run.

After isolated verification passed, migration000004 was applied only to `oblivion_findings_pkg02a_2b9f_browser`, the synthetic preview schema. That preview contains zero canonical commands, no workers/listeners and no real tracker dispatch. Operational databases were not migrated.

## Verification ledger

- `.pkg02a-motion-frontend.log`: 54 tests / 12 files, exit0. Includes motion/fall, charging, SOS, editor, Locate, live refresh and privacy behavior.
- `.pkg02a-motion-types.log`: full TypeScript exit0. `.pkg02a-motion-eslint.log`: scoped lint exit0, max-warnings0.
- `.pkg02a-motion-build.log`: production build exit0, 3m26s. Existing app chunk exceeds 500kB advisory; not warning-free. Local preview assets merged, old hashed assets retained, manifest replaced atomically.
- `.pkg02a-motion-backend.log`: 16 tests / 335 assertions, one failure. Motion/SOS/fall and history passed. The address global-fallback fake accessed a missing countrycodes key on the intended global request and caused caught 503; corrected to nullable access. Original log retained.
- `.pkg02a-audit-final-feature.log`: corrected address/status and canonical request/dispatch/contract/collector feature families: **47 tests / 524 assertions, exit0**, 352.20s. Missing-.env warnings are explicit and expected under the forced isolated profile.
- `.pkg02a-provider-and-portal.log`: preserved 5-test/39-assertion run with one error. The process migrated before migration000004 was written but loaded the later service, producing missing audit_tail_event_id. Invalid source-timing run, not accepted as green.
- `.pkg02a-audit-final-unit.log`: 25 tests / 258 assertions, three new audit-fixture failures. Existing Locate concurrency/portal/current-connection, address and provider-budget cases passed. Fixture expiry had subsecond precision absent from schema storage; independent-read assertion incorrectly used the primary model connection's old RR snapshot; hash reconstruction used unrepresentable stored subsecond time. Corrections use canonical whole-second fixture expiry, explicit observer-connection query, and a controlled representable audit clock. No service, hash algorithm, lock or assertion guarantee was relaxed. Original log retained.
- `.pkg02a-audit-corrected-unit.log`: **10 tests / 129 assertions, exit0**, 219.51s, explicit missing-.env warnings. Proves empty/backfilled/retained migration behavior, adjacent/non-adjacent empty/existing independent appends, same-request competing first append and retry, old-snapshot current tail, unchanged canonical hash at a representable instant, valid signature after append/backfill, terminal/caller state preservation, rollback between insert/pointer and outer lifecycle rollback, missing/foreign pointer rejection, model/input guards, and both actual collector recovery/result orderings. Recovery-first blocks the concurrent result on the request and preserves its uncertain terminal attempt; result-first commits after discovery and is not overwritten by recovery. No physical command execution.

All backend runs were preceded by verify-isolation.php: forced MySQL localhost package PID schemas, absent .env/cached config/inherited credentials, fake queues/HTTP/providers, unchanged shared TestCase and lockfiles. Unit committed fixtures and ordinary RefreshDatabase feature families were run in separate processes. Earlier diagnostic failures and earlier 181-test/1323-assertion clean Feature run remain preserved; they are historical evidence, not a whole-candidate current green claim.

An attempted internal task update was rejected by automatic approval review because authorization for its destination was not established. It was not delivered or bypassed. This local checkpoint is reviewable evidence in the implementation workspace; it is not an integration or Main acceptance claim.
