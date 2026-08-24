# CAP-HS-RESTRAINT-EVENTS — Restraint Events

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-HS-RESTRAINT-EVENTS`
- Canonical module: `HEALTH_SAFETY`
- ID provenance: `exact`
- Source families: `HS-RESTRAINT`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-1199`, `ROUTE-1200`, `ROUTE-1201`, `ROUTE-1202`, `ROUTE-1203`, `ROUTE-1204`, `ROUTE-1206`
- Route names: `health-safety.restraints.clients.summary`, `health-safety.restraints.events.attachments.destroy`, `health-safety.restraints.events.attachments.store`, `health-safety.restraints.events.link-incident`, `health-safety.restraints.events.store`, `health-safety.restraints.events.update`, `health-safety.restraints.index`
- Route paths: `health-safety/restraints`, `health-safety/restraints/clients/{client}/summary`, `health-safety/restraints/events`, `health-safety/restraints/events/{event}`, `health-safety/restraints/events/{event}/attachments`, `health-safety/restraints/events/{event}/attachments/{attachment}`, `health-safety/restraints/events/{event}/link-incident`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0373`, `PAGE-0394`, `PAGE-0395`
- Target-supported route actions: `clientSummary`, `destroyAttachment`, `index`, `linkIncident`, `storeAttachment`, `storeEvent`, `updateEvent`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: Not target-enriched; source-family/controller evidence remains in the audit inventory.
- Exact working-ID findings: `HS-SITE-01`

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised health and safety practitioner

Goal: Complete **Restraint Events** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `restraints.create`, `restraints.manage`, `restraints.review`, `restraints.view`
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
