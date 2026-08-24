# CAP-SITE-MEAL-PLANNING — Meal Planning

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-SITE-MEAL-PLANNING`
- Canonical module: `SITES`
- ID provenance: `exact`
- Source families: `SITE-SITE-MEAL-PLAN`
- Route scope: exact target allocation
- Route evidence: `ROUTE-2830`, `ROUTE-2831`, `ROUTE-2832`, `ROUTE-2833`, `ROUTE-2834`, `ROUTE-2835`, `ROUTE-2838`, `ROUTE-2839`, `ROUTE-2840`, `ROUTE-2841`, `ROUTE-2843`
- Route names: `sites.meals.bootstrap`, `sites.meals.checkConflicts`, `sites.meals.plan.clearWeek`, `sites.meals.plan.copyWeek`, `sites.meals.plan.destroy`, `sites.meals.plan.index`, `sites.meals.plan.store`, `sites.meals.plan.update`, `sites.meals.plan.weekSummary`, `sites.meals.residents.update`, `sites.meals.takeawayVendors`
- Route paths: `sites/{site}/meal-plan`, `sites/{site}/meal-plan-week/clear`, `sites/{site}/meal-plan-week/copy`, `sites/{site}/meal-plan/{entry}`, `sites/{site}/meal-plan/week-summary`, `sites/{site}/meal-planner/bootstrap`, `sites/{site}/meal-planner/check-conflicts`, `sites/{site}/meal-planner/residents/{client}`, `sites/{site}/meal-planner/takeaway-vendors`
- Page scope: exact/shared target support allocation retained in the working manifest
- Page evidence: `PAGE-0899`, `PAGE-0900`, `PAGE-0909`
- Exact target actions: `bootstrap`, `checkConflicts`, `clearWeek`, `copyWeek`, `destroy`, `index`, `store`, `takeawayVendors`, `update`, `updateResident`, `weekSummary`
- Backend anchors: `app/Http/Controllers/Sites/SiteMealPlanController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised site practitioner

Goal: Complete **Meal Planning** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `sites.meals.plan`, `sites.meals.view`, `sites.viewAny`
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
