# CAP-HR-WELLBEING-SURVEYS — Wellbeing Surveys

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-HR-WELLBEING-SURVEYS`
- Canonical module: `HR`
- ID provenance: `exact`
- Source families: `HR-WELLBEING`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-1769`, `ROUTE-1770`, `ROUTE-1771`, `ROUTE-1772`, `ROUTE-1821`, `ROUTE-1822`, `ROUTE-1823`, `ROUTE-1824`, `ROUTE-1826`, `ROUTE-1827`, `ROUTE-1828`, `ROUTE-1829`, `ROUTE-1830`, `ROUTE-1831`, `ROUTE-1832`
- Route names: `hr.surveys.create`, `hr.surveys.index`, `hr.surveys.respond`, `hr.surveys.show`, `hr.wellbeing.surveys.archive`, `hr.wellbeing.surveys.close`, `hr.wellbeing.surveys.destroy`, `hr.wellbeing.surveys.duplicate`, `hr.wellbeing.surveys.export`, `hr.wellbeing.surveys.nudge`, `hr.wellbeing.surveys.publish`, `hr.wellbeing.surveys.responses.store`, `hr.wellbeing.surveys.show`, `hr.wellbeing.surveys.store`, `hr.wellbeing.surveys.update`
- Route paths: `hr/surveys`, `hr/surveys/{survey}`, `hr/surveys/{survey}/respond`, `hr/surveys/create`, `hr/wellbeing/surveys`, `hr/wellbeing/surveys/{survey}`, `hr/wellbeing/surveys/{survey}/archive`, `hr/wellbeing/surveys/{survey}/close`, `hr/wellbeing/surveys/{survey}/duplicate`, `hr/wellbeing/surveys/{survey}/export`, `hr/wellbeing/surveys/{survey}/nudge`, `hr/wellbeing/surveys/{survey}/publish`, `hr/wellbeing/surveys/{survey}/responses`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0525`
- Target-supported route actions: `archiveSurvey`, `closeSurvey`, `destroySurvey`, `duplicateSurvey`, `exportSurvey`, `Illuminate\Routing\RedirectController`, `nudgeSurvey`, `publishSurvey`, `showSurvey`, `storeSurvey`, `submitResponse`, `updateSurvey`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: `app/Http/Controllers/Hr/WellbeingController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised HR practitioner, manager or employee where self-service applies

Goal: Complete **Wellbeing Surveys** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `hr.performance.manage`
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
