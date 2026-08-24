# CAP-MED-CLIENT-MEDICAL-CONDITION-LIFECYCLE — Client Medical Condition Lifecycle

Status: **Blocked — source-derived 904 final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-MED-CLIENT-MEDICAL-CONDITION-LIFECYCLE`
- Canonical module: `EMAR`
- ID provenance: `audit_assigned_stable_name`
- Source family: `MED-CLIENT-MEDICAL`
- Aggregate: `ClientCondition collection`
- Route evidence: `ROUTE-0168`, `ROUTE-0169`, `ROUTE-0170`, `ROUTE-2012`, `ROUTE-2013`, `ROUTE-2014`
- Route names: `clients.medical.conditions.destroy`, `clients.medical.conditions.store`, `clients.medical.conditions.update`, `operations.clients.medical.conditions.destroy`, `operations.clients.medical.conditions.store`, `operations.clients.medical.conditions.update`
- Route paths: `POST clients/{client}/medical/conditions`, `DELETE clients/{client}/medical/conditions/{condition}`, `PUT clients/{client}/medical/conditions/{condition}`, `POST operations/clients/{client}/medical/conditions`, `DELETE operations/clients/{client}/medical/conditions/{condition}`, `PUT operations/clients/{client}/medical/conditions/{condition}`
- Target-supported controller actions: `storeCondition`, `updateCondition`, `destroyCondition`
- Backend anchor: `app/Http/Controllers/ClientMedicalController.php`
- Page evidence: none. `PAGE-0038` and `PAGE-0590` remain resolver-orphans and are not entrypoint credit.
- Benchmark status: unproved; no comparator or No Credible Match credit was added by the denominator repair.

## Representative task

Actor: Authorised client-care practitioner

Goal: Complete **Client Medical Condition Lifecycle** on the authoritative client record, then verify the persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented Site/role/permission scope.
- A resettable synthetic client in the correct prerequisite state.
- Known wrong-Site, wrong-parent and wrong-record fixtures for denial checks.
- A current reachable navigation entry must be established; the legacy medical pages are source callers but resolver-orphans.

Steps:

1. Enter through a current authorised route/page for this final capability; do not infer entry from the resolver-orphan source pages.
2. Confirm actor, Site, client/direct-object relationship and prerequisite state before disclosure or mutation.
3. Perform only the exact `ClientCondition collection` action owned by this target.
4. Verify authoritative persisted state plus actor/time/source provenance; a toast or HTTP success is insufficient.
5. Exercise safe invalid-input, wrong-object, replay/concurrency and correction paths, then verify the next owner or terminal state.

## Required error and recovery checks

- Wrong Site, client, parent or nested object: deny before disclosure or side effect.
- Invalid input: bind field errors and preserve authoritative state.
- Stale, concurrent or replayed action: at most one intended effect with safe review/retry.
- Correction/reversal: retain prior provenance and re-check authority and state.

## Current ease scores

All ten current scores are **Not measured**. Numeric zero is not substituted for absent representative-user measurement. Representative execution is 0/790 and independent score validation is 0/790.
