# CAP-MED-MEDICATION-ORDER-LIFECYCLE — Medication Order Lifecycle

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-MED-MEDICATION-ORDER-LIFECYCLE`
- Canonical module: `EMAR`
- ID provenance: `exact`
- Source families: `MED-CLIENT-MEDICAL`, `MED-EMAR`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-0167`, `ROUTE-0175`, `ROUTE-0176`, `ROUTE-0177`, `ROUTE-0384`, `ROUTE-0385`, `ROUTE-0386`, `ROUTE-0387`, `ROUTE-0388`, `ROUTE-0391`, `ROUTE-2011`, `ROUTE-2018`, `ROUTE-2019`, `ROUTE-2020`
- Route names: `clients.medical.medications.destroy`, `clients.medical.medications.store`, `clients.medical.medications.update`, `clients.medical.show`, `emar.medications`, `emar.medications.detail`, `emar.medications.discontinue`, `emar.medications.import`, `emar.medications.store`, `emar.medications.update`, `operations.clients.medical.medications.destroy`, `operations.clients.medical.medications.store`, `operations.clients.medical.medications.update`, `operations.clients.medical.show`
- Route paths: `clients/{client}/medical`, `clients/{client}/medical/medications`, `clients/{client}/medical/medications/{medication}`, `emar/medications`, `emar/medications/{medication}`, `emar/medications/{medication}/detail`, `emar/medications/{medication}/discontinue`, `emar/medications/import`, `operations/clients/{client}/medical`, `operations/clients/{client}/medical/medications`, `operations/clients/{client}/medical/medications/{medication}`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0073`, `PAGE-0082`, `PAGE-0099`, `PAGE-0612`
- Target-supported route actions: `destroyMedication`, `discontinueMedication`, `importMedications`, `medicationDetail`, `medications`, `show`, `storeMedication`, `updateMedication`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: Not target-enriched; source-family/controller evidence remains in the audit inventory.
- Exact working-ID findings: `MED-SCOPE-01`, `MED-VERIFY-01`

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised medication practitioner

Goal: Complete **Medication Order Lifecycle** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `clients.update`, `clients.viewAny`, `clients.viewAssigned`, `medications.orders.manage`, `medications.view`
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
