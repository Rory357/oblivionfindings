# CAP-CR-SHIFT-HANDOVER — Shift Handover

Status: **Blocked — authenticated deployed-current entry attempted, but the first safe GET rendered `500 Server Error` at all four required viewports; deployed build identity, representative task completion and independent task validation remain absent.**

## Evidence boundary

- Capability: `CAP-CR-SHIFT-HANDOVER`
- Canonical module: `CONTROL_ROOM`
- ID provenance: `exact`
- Source families: `CR-CONTROL-ROOM-HANDOVER`, `CR-CONTROL-ROOM-SHIFT`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-0305`, `ROUTE-0306`, `ROUTE-0307`
- Route names: `control-room.shifts.acknowledge-handover`, `control-room.shifts.handover`, `control-room.shifts.handover-page`
- Route paths: `control-room/shifts/{shift}/acknowledge-handover`, `control-room/shifts/{shift}/handover`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0063`
- Target-supported route actions: `acknowledgeHandover`, `handover`, `show`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: `app/Http/Controllers/ControlRoom/ControlRoomHandoverController.php`, `app/Http/Controllers/ControlRoom/ControlRoomShiftController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.
- Supplemental runtime evidence: `evidence/browser/deployed-current-control-room-handover-500-2026-08-24.json` records a rendered-link-derived Active shift and a read-only GET failure at 1440×900, 1280×800, 1024×768 and 390×844. The deployed build identity is unknown, so the observation is not source/root-cause or immutable-baseline proof.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised Control Room operator

Goal: Complete **Shift Handover** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `controlRoom.alerts.manage`
- Known wrong-site, wrong-parent and wrong-record fixtures for denial checks.

Steps:

1. Enter through an authorised route/page for this final capability. Do not assume a retained shared relation is an exclusive entry or ownership claim.
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

## 24 Aug 2026 deployed-current attempt

An authenticated Demo Admin session opened the Active shifts list, discovered the existing synthetic shift link `/control-room/shifts/9/handover`, and attempted the safe GET at all four required viewports. Every viewport rendered only `500 Server Error`; no handover UI, form or action was available and nothing was submitted. This is a failed entry attempt, not one of the 790 completed canonical tasks: the audit-wide task numerator remains **0/790**, all ease scores remain **Not measured**, and the deployment cannot be tied to audited commit `081ef198…` without a build marker.
