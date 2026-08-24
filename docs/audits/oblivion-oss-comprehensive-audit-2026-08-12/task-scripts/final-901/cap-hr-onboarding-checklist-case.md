# CAP-HR-ONBOARDING-CHECKLIST-CASE — Onboarding Checklist Case

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-HR-ONBOARDING-CHECKLIST-CASE`
- Canonical module: `HR`
- ID provenance: `exact`
- Source families: `HR-ONBOARDING`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-1554`, `ROUTE-1555`, `ROUTE-1556`, `ROUTE-1557`, `ROUTE-1558`, `ROUTE-1559`, `ROUTE-1560`, `ROUTE-1561`, `ROUTE-1564`, `ROUTE-1565`, `ROUTE-1573`, `ROUTE-2932`
- Route names: `hr.onboarding.bulk`, `hr.onboarding.complete`, `hr.onboarding.create`, `hr.onboarding.destroy`, `hr.onboarding.export`, `hr.onboarding.index`, `hr.onboarding.reassign`, `hr.onboarding.remind`, `hr.onboarding.show`, `hr.onboarding.status`, `hr.onboarding.store`, `staff.induction.show`
- Route paths: `hr/onboarding`, `hr/onboarding/{checklist}`, `hr/onboarding/{checklist}/complete`, `hr/onboarding/{checklist}/reassign`, `hr/onboarding/{checklist}/remind`, `hr/onboarding/{checklist}/status`, `hr/onboarding/bulk`, `hr/onboarding/create`, `hr/onboarding/export`, `staff/{user}/induction`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0485`, `PAGE-0486`
- Target-supported route actions: `bulkAction`, `completeChecklist`, `create`, `destroy`, `export`, `index`, `reassignChecklist`, `remindChecklist`, `setChecklistStatus`, `show`, `store`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: `app/Http/Controllers/Hr/OnboardingController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised HR practitioner, manager or employee where self-service applies

Goal: Complete **Onboarding Checklist Case** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `hr.onboarding.manage`, `hr.onboarding.view`, `training.viewAny`
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
