# CAP-HR-HR-PERFORMANCE-REVIEW-REVIEW-CYCLE — Hr Performance Review Review Cycle

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-HR-HR-PERFORMANCE-REVIEW-REVIEW-CYCLE`
- Canonical module: `HR`
- ID provenance: `exact`
- Source families: `HR-HR-PERFORMANCE-REVIEW`
- Route scope: exact target allocation
- Route evidence: `ROUTE-1639`, `ROUTE-1640`, `ROUTE-1641`, `ROUTE-1642`, `ROUTE-1643`, `ROUTE-1644`, `ROUTE-1645`, `ROUTE-1646`, `ROUTE-1647`, `ROUTE-1648`, `ROUTE-1649`
- Route names: `hr.performance.reviews.acknowledge`, `hr.performance.reviews.create`, `hr.performance.reviews.edit`, `hr.performance.reviews.evidence.show`, `hr.performance.reviews.evidence.store`, `hr.performance.reviews.index`, `hr.performance.reviews.show`, `hr.performance.reviews.sign-off`, `hr.performance.reviews.store`, `hr.performance.reviews.submit`, `hr.performance.reviews.update`
- Route paths: `hr/performance/reviews`, `hr/performance/reviews/{review}`, `hr/performance/reviews/{review}/acknowledge`, `hr/performance/reviews/{review}/edit`, `hr/performance/reviews/{review}/evidence`, `hr/performance/reviews/{review}/sign-off`, `hr/performance/reviews/{review}/submit`, `hr/performance/reviews/create`
- Page scope: exact/shared target support allocation retained in the working manifest
- Page evidence: `PAGE-0497`, `PAGE-0498`
- Exact target actions: `acknowledge`, `create`, `downloadEvidence`, `edit`, `index`, `show`, `signOff`, `store`, `submit`, `update`, `uploadEvidence`
- Backend anchors: `app/Http/Controllers/Hr/PerformanceReviewController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised HR practitioner, manager or employee where self-service applies

Goal: Complete **Hr Performance Review Review Cycle** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `hr.performance.manage`, `hr.performance.view`
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
