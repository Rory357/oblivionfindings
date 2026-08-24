# FLEET-GEOFENCE — Geofence

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `FLEET-GEOFENCE`
- Canonical module: `FLEET`
- ID provenance: `exact`
- Source families: `FLEET-GEOFENCE`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-0052`, `ROUTE-0053`, `ROUTE-0736`, `ROUTE-0737`, `ROUTE-0738`, `ROUTE-0739`, `ROUTE-0740`, `ROUTE-0741`, `ROUTE-0742`
- Route names: `assets.geofences.destroy`, `assets.geofences.store`, `fleet-assets.geofences.create`, `fleet-assets.geofences.destroy`, `fleet-assets.geofences.edit`, `fleet-assets.geofences.index`, `fleet-assets.geofences.store`, `fleet-assets.geofences.toggle`, `fleet-assets.geofences.update`
- Route paths: `assets/{asset}/geofences`, `assets/{asset}/geofences/{geofence}`, `fleet-assets/geofences`, `fleet-assets/geofences/{geofence}`, `fleet-assets/geofences/{geofence}/edit`, `fleet-assets/geofences/{geofence}/toggle`, `fleet-assets/geofences/create`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0213`, `PAGE-0214`, `PAGE-0221`, `PAGE-0222`, `PAGE-0223`
- Target-supported route actions: `create`, `destroy`, `edit`, `index`, `store`, `toggleActive`, `update`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: `app/Http/Controllers/FleetAssets/GeofenceController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised fleet practitioner or driver

Goal: Complete **Geofence** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `assets.geofences.manage`, `fleet.viewAny`
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
