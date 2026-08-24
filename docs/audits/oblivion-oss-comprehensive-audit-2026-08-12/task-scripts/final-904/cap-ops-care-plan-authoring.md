# CAP-OPS-CARE-PLAN-AUTHORING — Care Plan Authoring

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-OPS-CARE-PLAN-AUTHORING`
- Canonical module: `OPERATIONS`
- ID provenance: `exact`
- Source families: `OPS-CARE-PLAN`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-1905`, `ROUTE-1906`, `ROUTE-1907`, `ROUTE-1908`, `ROUTE-1909`, `ROUTE-1911`, `ROUTE-1922`, `ROUTE-1926`
- Route names: `operations.care_plans.create`, `operations.care_plans.destroy`, `operations.care_plans.edit`, `operations.care_plans.index`, `operations.care_plans.pdf`, `operations.care_plans.show`, `operations.care_plans.store`, `operations.care_plans.update`
- Route paths: `operations/care-plans`, `operations/care-plans/{carePlan}`, `operations/care-plans/{carePlan}/edit`, `operations/care-plans/{carePlan}/pdf`, `operations/care-plans/create`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0567`, `PAGE-0568`, `PAGE-0569`, `PAGE-0570`, `PAGE-0597`, `PAGE-0598`, `PAGE-0607`
- Target-supported route actions: `create`, `destroy`, `edit`, `exportPdf`, `index`, `show`, `store`, `update`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: `app/Http/Controllers/Operations/CarePlanController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised operations practitioner, scheduler or frontline worker

Goal: Complete **Care Plan Authoring** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `care_plans.create`, `care_plans.delete`, `care_plans.update`, `care_plans.viewAny`
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
