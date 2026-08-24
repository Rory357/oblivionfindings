# CAP-HS-EMERGENCY-DRILL-LIFECYCLE — Emergency Drill Lifecycle

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-HS-EMERGENCY-DRILL-LIFECYCLE`
- Canonical module: `HEALTH_SAFETY`
- ID provenance: `exact`
- Source families: `HS-EMERGENCY-DRILL`
- Route scope: exact target allocation
- Route evidence: `ROUTE-1083`, `ROUTE-1084`, `ROUTE-1085`, `ROUTE-1086`, `ROUTE-1087`, `ROUTE-1088`, `ROUTE-1090`, `ROUTE-1091`, `ROUTE-1094`, `ROUTE-1095`, `ROUTE-1096`
- Route names: `health-safety.drills.attachments.destroy`, `health-safety.drills.attachments.store`, `health-safety.drills.cancel`, `health-safety.drills.complete`, `health-safety.drills.create`, `health-safety.drills.index`, `health-safety.drills.participants.store`, `health-safety.drills.show`, `health-safety.drills.start`, `health-safety.drills.store`, `health-safety.drills.update`
- Route paths: `health-safety/drills`, `health-safety/drills/{drill}`, `health-safety/drills/{drill}/attachments`, `health-safety/drills/{drill}/attachments/{attachment}`, `health-safety/drills/{drill}/cancel`, `health-safety/drills/{drill}/complete`, `health-safety/drills/{drill}/participants`, `health-safety/drills/{drill}/start`, `health-safety/drills/create`
- Page scope: exact/shared target support allocation retained in the working manifest
- Page evidence: `PAGE-0373`, `PAGE-0374`, `PAGE-0378`, `PAGE-0379`, `PAGE-0380`
- Exact target actions: `addParticipant`, `cancel`, `complete`, `create`, `destroyAttachment`, `index`, `show`, `start`, `store`, `update`, `uploadAttachment`
- Backend anchors: Not target-enriched; source-family/controller evidence remains in the audit inventory.
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised health and safety practitioner

Goal: Complete **Emergency Drill Lifecycle** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `hazards.create`, `hazards.manage`, `hazards.view`
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
