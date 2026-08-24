# CAP-OPS-ATTENDANCE-CLOCK-SESSION — Attendance Clock Session

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-OPS-ATTENDANCE-CLOCK-SESSION`
- Canonical module: `OPERATIONS`
- ID provenance: `exact`
- Source families: `OPS-ATTENDANCE`
- Route scope: exact target allocation
- Route evidence: `ROUTE-0065`, `ROUTE-0068`, `ROUTE-0069`, `ROUTE-0072`, `ROUTE-0073`
- Route names: `attendance.clockIn`, `attendance.clockOut`, `attendance.index`, `attendance.sessions.correct`, `attendance.sessions.end`
- Route paths: `attendance`, `attendance/clock-in`, `attendance/clock-out`, `attendance/sessions/{session}/correct`, `attendance/sessions/{session}/end`
- Page scope: exact/shared target support allocation retained in the working manifest
- Page evidence: `PAGE-0002`, `PAGE-0003`, `PAGE-0004`, `PAGE-0005`, `PAGE-0006`, `PAGE-0638`, `PAGE-0645`, `PAGE-0646`, `PAGE-0647`
- Exact target actions: `clockIn`, `clockOut`, `correctSession`, `endSession`, `index`
- Backend anchors: `app/Http/Controllers/AttendanceController.php`
- Exact working-ID findings: `WF-ATTENDANCE-FORCED-END-SITE`

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised operations practitioner, scheduler or frontline worker

Goal: Complete **Attendance Clock Session** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `shifts.manageAny`, `shifts.update`, `shifts.viewAssigned`, `timesheets.create`, `timesheets.manageAny`, `timesheets.viewAny`, `timesheets.viewAssigned`
- Known wrong-site, wrong-parent and wrong-record fixtures for denial checks.

Steps:

1. Enter through an authorised route/page for this final capability. If target-exclusive entry evidence is not enriched, locate the source-family owner without assuming a shared page is an exclusive entry.
2. Confirm the actor, site, parent/child relation, owning record and prerequisite state before disclosing or changing data.
3. Perform only the action(s) evidenced for this capability; do not infer a split target's action from the entire source-family envelope.
4. Verify the authoritative persisted state and immutable/auditable actor, effective time and source provenance. A rendered page, toast or HTTP success alone is not completion.
5. Verify the next owner, notification/outbox/reporting effect or terminal outcome, then exercise the documented correction/retry path where safe.

## Required error and recovery checks

- Wrong site, person, parent or nested child: deny before disclosure or side effect.
- Invalid input: retain safe input, bind messages to fields and preserve authoritative state.
- Stale, concurrent or replayed action: at most one effect; expose the current state and a safe retry/review path.
- Background or integration failure: retain visible queued/failed evidence, stable source identity and authorised replay/reconciliation.
- Correction/reversal: preserve prior provenance and re-check authorization and state.

## Current ease scores

All ten current scores are **Not measured**. Under the audit rubric, numeric 0 means blocked, misleading, inaccessible or missing; it is therefore not used as a substitute for absent representative-user measurement.

| Dimension | Score |
|---|---:|
| Discoverability | Not measured |
| Comprehension | Not measured |
| Learnability | Not measured |
| Efficiency | Not measured |
| Error prevention | Not measured |
| Recovery | Not measured |
| Accessibility | Not measured |
| Safety and trust | Not measured |
| Consistency | Not measured |
| Cross-module continuity | Not measured |

Target scores are not assigned until the task is executed and independently reviewed. No ease or completion claim is made.
