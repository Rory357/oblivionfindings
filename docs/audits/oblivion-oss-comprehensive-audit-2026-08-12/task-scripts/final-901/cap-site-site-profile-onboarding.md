# CAP-SITE-SITE-PROFILE-ONBOARDING — Site Profile Onboarding

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-SITE-SITE-PROFILE-ONBOARDING`
- Canonical module: `SITES`
- ID provenance: `exact`
- Source families: `SITE-SITE`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-2728`, `ROUTE-2729`, `ROUTE-2730`, `ROUTE-2731`, `ROUTE-2756`, `ROUTE-2784`, `ROUTE-2822`, `ROUTE-2858`, `ROUTE-2883`, `ROUTE-2901`
- Route names: `sites.contact-info.update`, `sites.create`, `sites.edit`, `sites.index`, `sites.location.update`, `sites.onboarding.step`, `sites.safety.update`, `sites.show`, `sites.store`, `sites.update`
- Route paths: `sites`, `sites/{site}`, `sites/{site}/contact-info`, `sites/{site}/edit`, `sites/{site}/location`, `sites/{site}/onboarding/step`, `sites/{site}/safety`, `sites/create`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0021`, `PAGE-0363`, `PAGE-0552`, `PAGE-0555`, `PAGE-0557`, `PAGE-0864`, `PAGE-0866`, `PAGE-0868`, `PAGE-0869`, `PAGE-0870`, `PAGE-0872`, `PAGE-0873`, `PAGE-0875`, `PAGE-0879`, `PAGE-0882`, `PAGE-0883`, `PAGE-0888`, `PAGE-0894`, `PAGE-0898`, `PAGE-0901`, `PAGE-0902`, `PAGE-0909`, `PAGE-0911`, `PAGE-0912`, `PAGE-0913`, `PAGE-0914`, `PAGE-0915`, `PAGE-0916`, `PAGE-0917`, `PAGE-0918`, `PAGE-0919`, `PAGE-0920`, `PAGE-0934`
- Target-supported route actions: `create`, `edit`, `index`, `show`, `store`, `storeOnboardingStep`, `update`, `updateContactInfo`, `updateLocation`, `updateSafety`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: `app/Http/Controllers/SiteController.php`
- Exact working-ID findings: `SITE-RBAC-001`

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised site practitioner

Goal: Complete **Site Profile Onboarding** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `sites.create`, `sites.update`, `sites.viewAny`
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
