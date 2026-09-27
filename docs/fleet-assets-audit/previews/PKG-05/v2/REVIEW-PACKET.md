# Transport v2 — revised design and overall audit

**Status: ready for exact design review. Application implementation has not started.**

Open the candidate at [Transport v2](http://127.0.0.1:4396/#/fleet-assets/transports/overview). Its immutable delivery identity is recorded in `FREEZE.txt` and `artifact-manifest.json`. The original v1 preview remains on port 4395. All 62 of its frozen files and all 21 manifest-listed original audit files still match their recorded hashes.

This version incorporates the user-requested audit, the approved direct Transport navigation/workspace correction, and **three additional audit-and-improvement rounds**. It is an interactive synthetic desktop design. All actions operate in memory; reload resets them. No application, shared component, backend, route, database, real notification, medication, Finance or tracker record was changed.

## What changed

Transport is a direct Fleet & Assets navigation entry immediately after Fleet. The preview-owned navigation composition has the approved eight entries: Overview, Fleet, Transport, Assets, Maintenance, Maps & boundaries, Reports and Settings. The published shared helper is untouched.

The workspace has five focused views:

- **Overview:** permitted demand, upcoming departures, pending booking decisions, active journeys, overdue returns and open exceptions. Site/date filters and each count lead to the matching scoped records. Loading/error/denied counts are unavailable; stale results are labelled.
- **Requests & approvals:** request before allocation; assess, request information, respond, edit an unallocated request, allocate, review a source booking decision, reject, or record a cancellation/client-choice/not-fulfilled outcome. Edits preserve identity and history and require reassessment. Booking approval is distinct from request assessment and Finance.
- **Planner:** match assessed needs to vehicle, driver, escort, equipment and a time window; show current source readiness, conflict, expired result or missing configuration. A change updates the same linked reservation. Fleet retains the full calendar and reservation engine.
- **Journeys:** preparation is attached to a booking. The actual journey is created when its assigned driver records departure. Planned and actual times, passenger status, next action and accountable worker are visible together. After passenger return and required source obligations are resolved, **Complete journey** is the prominent action. A shift handover is optional and separate.
- **Returns & handovers:** immutable original observations, partial returns, later physical receipt by a named worker, outgoing/incoming shift parties, acknowledgement/dispute and owned reconciliation. Resolving an exception retains the original disputed handover and partial receipt.

My Day, Client, Site, vehicle and booking entry/return contexts are demonstrated. Outings and permission-limited medication logistics remain discoverable as bounded source handoffs. Other modules are not redesigned in this preview.

## Why the journey is clearer

V1 mixed request TR-1042, booking BK-208, actual journey J-608, return receipt and handover into one shared status and nearly identical page body. Large repeated headers pushed passenger work and completion below unrelated content.

V2 gives each record its own status, identity and history. A journey page starts with the trip stage, planned/actual times and one next-action panel. Request detail and booking authority remain linked context. On the final 1440×1000 normal-flow check, **Complete journey** was at document-top viewport y=519.56, height 42. On a 1280×800 laptop view, **Record return** was visible without scrolling. No shift handover was needed to complete the tested normal trip.

## Three additional rounds

### 1. Workflow completeness

Corrected pending/confirmed booking language, explicit equipment confirmation, passenger/site dependencies, actual-journey navigation after departure, empty scope presentation and calendar-date validation. Verified assessment gating, independent rejection, the recorded no-approval-required route, complete request review, cancellation, discarded drafts and unknown record links.

Evidence: `iterations/01-workflow/FINDINGS.md`, before-source snapshots/hashes, final 12 browser assertions and screenshots. The original baseline screenshots are retained under that round’s `before` directory.

### 2. Permissions, custody and recovery

Added the missing later-item receipt path; prevented reconciliation before actual missing items are received; kept original disputes and receipts immutable; corrected outstanding-work counts after reconciliation; retained unexpected-site observations as an owned exception. Exercised a lost return response, changed input on retry, denied generic/linked sources, medication completion boundaries and per-file upload failure/version recovery.

Evidence: `iterations/02-recovery/FINDINGS.md`, before-source snapshots/hashes, 17 browser assertions and screenshots.

### 3. Overall gaps, clarity and usability

Made the existing management-only checkout/return contract explicit, with a blocked unconfigured-authority example. Corrected the no-approval-required authority distinction, Client/Site prefill, My Day date scope, role/site filter changes, keyboard focus and stable view rendering. Added unallocated-request editing with reassessment. The final visual/source sweep corrected in-progress instructions, returned-passenger wording, source history and fixture chronology/occupancy. Out-of-site passenger names are absent from requester search results, not merely disabled.

Evidence: `iterations/03-usability/FINDINGS.md`, before/after source hashes, 26 browser assertions, four desktop widths and the recorded zoom limitation. The final cross-check adds 15 assertions for request edits, source scope, unknown readiness, missing-information recovery, unexpected-site observations, keyboard selectors, ordinary dialog motion and the runtime/network boundary.

## Original audit disposition

- **F01 — transition bypass:** commands share assessment, booking, check, scope, authority and version guards. Unassessed driver entry cannot create checkout or fabricate linked identities.
- **F02 — dispute overwritten by exception:** independent lifecycle fields; exception assignment never resets a pending/accepted/disputed handover. Later receipts and reconciliation preserve the original decision.
- **F03 — original receipt changed by draft:** saved receipts are snapshots. Exact retries return the same operation result; changed observations are rejected. Recovery shows the original 48,248 km, even after a draft was changed to 48,999.
- **F04 — denied linked detail:** scope applies to record bodies, counts, searches, notes/evidence and linked source dialogs. Generic Maintenance/Fleet/My Day do not resolve to a concealed object.
- **F05 — source lifecycle mismatch:** allocation creates only a proposed booking; departure creates an actual journey with the acting driver. Passenger completion does not release a vehicle restriction. **Remaining integration decisions are listed below; this finding is not declared a production fix.**
- **F06 — confusing journey and buried completion:** separate request/booking/trip pages, visible planned/actual comparison and next action; no compulsory shift-handover step.
- **F07 — misleading cancellation:** demand outcome is explicit; no false travel/return progress. Started travel cannot be silently cancelled away.
- **F08 — stale person/site/support:** dependent request fields and selectors follow the current permitted person/site. Capacity includes driver and any required escort. Source entries carry their actual person/site.
- **F09 — shared drafts/history:** each form owns its draft; discard removes selections; notes/events belong to their record. New requests start without old notes.
- **F10 — incomplete validation:** required fields, real dates, chronology, odometer direction, capacity, support and current versions are checked. Review contains the submitted need. Dirty receiver/site edits count as changes.
- **F11 — stale recovery error:** recovering the original receipt clears the uncertain-response and changed-input errors.
- **F12 — dead ends and false fallback:** record collections derive from actual fixtures; missing-information and conflict paths are actionable; unknown IDs show unavailable rather than J-608.
- **F13 — ineffective controls/navigation:** visible search, explicit status options, proper Fleet/Transport separation, source-linked counts and retained Back context.
- **F14 — inaccurate source/evidence claims:** failed check/report references are created only by the corresponding submitted operation; original evidence versions and per-file states are retained; existing source histories are explicit.
- **F15 — weak prior proof:** browser checks now assert outcomes and alternate paths; focused transition checks exercise bypass/retry/version invariants. No real notification, server delivery, API idempotency or integration success is claimed.
- **F16 — complexity and language:** view composition, forms and the typed synthetic domain are separated; record references are secondary; shared UI primitives are reused. Render helpers keep dialog openers and expanded notes stable.

## Remaining gaps before implementation

These are substantive source/integration decisions, not defects silently dismissed because the preview works.

1. **Scoped staff checkout and return authority.** `VehicleBookingController::checkout` and `returnVehicle` currently use `managerActor`, requiring Fleet management permission. Driver assignment alone does not grant it. The preview’s driver path demonstrates a proposed narrow observation/custody handoff. Its design-control checkbox explains this, and turning it off blocks the actions. A separately approved permission/transport adapter is required before implementing that staff path; do not broaden management authority as a shortcut.
2. **Request-to-reservation-to-journey linkage.** `ClientTransportBooking` owns demand, `FleetVehicleBooking` owns reservation/readiness and `ResidentTransportJourneyService` owns the actual trip. The richer assessment, support, ownership and deadline projections need an approved mapping and durable linkage; the existing request does not supply the complete proposed aggregate. Preserve source statuses and use one reservation, including changes/rejection/cancellation/retry.
3. **Returned-but-not-completed projection.** The preview’s returned phase derives from recorded return observations and passenger accountability. It is not authority to add a new backend journey enum. The canonical completion operation validates its own medication/log/attestation obligations and writes arrival/completion; implementation must map the earlier return projection without falsely closing it.
4. **Actual custody reconciliation.** Late receipts, disputed recipients, partial items and unexpected sites need exact canonical key/handover/record ownership, append-only evidence and permission contracts. The preview demonstrates those outcomes but does not implement durable events or a new custody store.
5. **Operating configuration.** Approval route authority, required equipment/checks, buffers, staff eligibility, hold/expiry behavior and escalation ownership require approved source configuration. The examples do not select a universal approval stage, default approver, hold duration or organisation-wide staff policy. Unknown configuration fails closed in the demonstrated flow.
6. **Integration verification and uploads.** Real concurrent availability, atomic multi-source effects, server idempotency/versions, persisted drafts, durable evidence, actual file policies, notifications, privacy enforcement and clinical source integration remain unbuilt and untested here. The local evidence uploader only simulates per-file outcomes and retains synthetic version metadata in memory.
7. **Browser zoom.** Keyboard zoom attempts did not change measured inner width, device-pixel ratio or visual viewport scale. Actual browser zoom is therefore **unverified**. Responsive desktop checks at 1920×1080, 1440×1000, 1280×800 and 1024×768 are real evidence of resizing, not a substitute zoom claim. Mobile/tablet/PWA/offline work is outside this desktop brief.

The Fleet calendar, vehicle profile, Client/Site records, Outings, medication logistics, Maintenance and shared My Day tasks retain their source ownership. Bounded handoff dialogs demonstrate the connection; they are not rebuilt full modules or working production endpoints. Generic exports/reports/settings also remain with their existing workspaces.

## Canonical mapping and reuse

- Demand: `app/Models/ClientTransportBooking.php` and `app/Http/Controllers/Operations/ClientTransportBookingController.php`. Existing Client permissions and direct-object ownership remain the boundary. Proposed request assessment/edit projections need the mapping above.
- Booking/readiness/approval: `app/Models/FleetVehicleBooking.php`, `app/Http/Controllers/FleetAssets/VehicleBookingController.php`, `app/Services/Fleet/VehicleBookingAccessService.php` and `VehicleReadinessService`. Independent required approval forbids self-approval; the `not_required` route has its own recorded authority. Current source state and readiness must be rechecked.
- Journey/passengers/medication: `ResidentTransportController`, `ResidentTransportJourneyService` and its scope service. Actual creation uses the acting driver; completion retains the source’s medication and evidence guards. No medication order/dose/administration screen is added.
- Custody: existing Fleet key logs, shift handovers and recipient-scoped handover controller. Preview exception ownership is separate from handover status.
- Checks/evidence/Maintenance: original checklist run/template version and source report/restriction. Repair completion, safety release and Finance remain independent.
- UI: actual shared PageHeader/rail/meters/search/buttons, EntityTable/cells/menu, WizardShell/review cards, Dialog/Popover/Command, StatusBadge, FileDropzone/StagedFileCard and maintenance DateTimeField/date/time pickers. The shared datetime and CSS token sources are reused. Build evidence records **32 inputs: 26 repository JS/TS, 2 repository CSS and 4 preview source files**. Neither the shared navigation helper nor a protected design guide was edited.

## Verification and provenance

The evidence records **74 passing browser assertions**, **31 passing synthetic transition assertions**, and zero scoped or imported TypeScript errors. The final browser pass captured no page errors and only loopback GET requests for this candidate’s static files. The served JavaScript hash matches the saved bundle. These counts describe this mockup, not application test coverage or full CI.

`verification.json` contains the individual results, runtime/source identity, original v1 preservation, reference hashes and exact limitations. Scripts and full logs accompany it. The three rounds’ source snapshots and hashes make the changes reviewable rather than replacing the prior findings.

Checkout baseline is `4ea64c547ed85a5b7504e59599db351f6eba7deb`, detached in the same isolated PKG-05 checkout. Actual effective turn metadata was verified as `gpt-6-astra/xhigh`; the existing chat remained pinned. No subagent/new worker/chat, sibling message, recurring monitor or Main progress loop was used.

During the work, Main’s read-only page register advanced from revision 96 to 99 and reported PKG-02B publication at `f7d517359da6ffdf90de2f259111fe5e8a1133f2`. That locally available commit was compared with the design baseline: Transport/Client booking, journey, booking controller/access/readiness and reused UI/navigation contracts remained unchanged. Adjacent changes improve Maintenance report-to-check linkage and reminder visibility in calendar/tasks; those source handoffs remain owned there. This preview was not rebased and makes no new remote/hosted-CI/deployment claim. The PKG-05 correction brief, navigation revision 2 and protected references remained unchanged. See `latest-baseline-review.md`.

Only `docs/fleet-assets-audit/previews/PKG-05/` contains untracked artifact work; tracked application files have no diff. The original audit and v1 runtime remain available for comparison.

## Review path and approval gate

Start at Overview, then inspect Requests & approvals, Planner, Journeys and Returns & handovers. Use **View as** to follow the requester, allocator, booking approver, driver, escort and receiver paths. The Scenario dialog supplies denied/loading/stale/error, conflict/expired/unknown readiness, lost response and unconfigured-authority examples. **Reset all synthetic records** returns to the initial queue.

Useful records: Alex’s assessment/edit/allocation request TR-1042; Jordan’s missing-information request TR-1043; Taylor’s pending booking BK-210; Morgan’s authority-recorded booking BK-212; Sam’s active J-608; Charlie’s returned J-609 with medication-source work; Casey’s disputed return TR-1048; Jamie’s cancelled request TR-1049; Elliot’s unallocated TR-1050.

The current PKG-05 Designer brief requires: **“Stop at explicit approval of the exact mockup.”** Approval of v1 was not inferred. This v2 review does not release application implementation, new authority, production activation or any unrelated package.
