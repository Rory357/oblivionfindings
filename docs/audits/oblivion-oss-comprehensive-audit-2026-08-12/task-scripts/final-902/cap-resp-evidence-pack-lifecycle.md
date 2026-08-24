# CAP-RESP-EVIDENCE-PACK-LIFECYCLE — Evidence Pack Lifecycle

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-RESP-EVIDENCE-PACK-LIFECYCLE`
- Canonical module: `RESPITE`
- ID provenance: `exact`
- Source families: `RESP-RESPITE-EVIDENCE-PACK`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-2385`, `ROUTE-2386`, `ROUTE-2387`, `ROUTE-2388`, `ROUTE-2389`, `ROUTE-2391`, `ROUTE-2392`, `ROUTE-2393`, `ROUTE-2451`
- Route names: `respite.evidence-packs.add-item`, `respite.evidence-packs.create`, `respite.evidence-packs.index`, `respite.evidence-packs.remove-item`, `respite.evidence-packs.seal`, `respite.evidence-packs.show`, `respite.evidence-packs.store`, `respite.evidence-packs.update`, `respite.stays.evidence-pack`
- Route paths: `respite/evidence-packs`, `respite/evidence-packs/{evidencePack}`, `respite/evidence-packs/{evidencePack}/add-item`, `respite/evidence-packs/{evidencePack}/remove-item`, `respite/evidence-packs/{evidencePack}/seal`, `respite/evidence-packs/create`, `respite/stays/{stay}/evidence-pack`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0768`, `PAGE-0769`, `PAGE-0770`, `PAGE-0771`
- Target-supported route actions: `addItem`, `create`, `forStay`, `index`, `removeItem`, `seal`, `show`, `store`, `update`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: `app/Http/Controllers/Respite/RespiteEvidencePackController.php`
- Exact working-ID findings: `RESP-EVIDENCE-01`, `RESP-SCOPE-01`

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised respite practitioner or approver

Goal: Complete **Evidence Pack Lifecycle** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `respite.evidence.manage`, `respite.evidence.seal`, `respite.evidence.view`
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
