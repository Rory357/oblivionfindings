# OPS-CLIENT-CONSENT — Client Consent

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `OPS-CLIENT-CONSENT`
- Canonical module: `OPERATIONS`
- ID provenance: `exact`
- Source families: `OPS-CLIENT-CONSENT`
- Route scope: exact target allocation
- Route evidence: `ROUTE-1953`, `ROUTE-1954`, `ROUTE-1955`
- Route names: `operations.clients.consents.index`, `operations.clients.consents.store`, `operations.clients.consents.withdraw`
- Route paths: `operations/clients/{client}/consents`, `operations/clients/{client}/consents/{consent}/withdraw`
- Page scope: exact/shared target support allocation retained in the working manifest
- Page evidence: `PAGE-0581`
- Exact target actions: `index`, `store`, `withdraw`
- Backend anchors: `app/Http/Controllers/Operations/ClientConsentController.php`
- Exact working-ID findings: `CONSENT-AUTH-01`, `CONSENT-CAPACITY-01`, `CONSENT-FILE-01`

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised operations practitioner, scheduler or frontline worker

Goal: Complete **Client Consent** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `clients.viewAny`, `clients.viewAssigned`, `consents.manage`, `consents.record`, `consents.viewAny`, `consents.withdraw`
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
