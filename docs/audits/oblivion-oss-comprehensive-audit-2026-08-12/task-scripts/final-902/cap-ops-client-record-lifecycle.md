# CAP-OPS-CLIENT-RECORD-LIFECYCLE — Client Record Lifecycle

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-OPS-CLIENT-RECORD-LIFECYCLE`
- Canonical module: `OPERATIONS`
- ID provenance: `exact`
- Source families: `CLI-CLIENT-ONBOARDING`, `CLI-FRONTEND-CLIENTS`, `OPS-CLIENT`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-0122`, `ROUTE-0123`, `ROUTE-0125`, `ROUTE-0126`, `ROUTE-0152`, `ROUTE-0183`, `ROUTE-0200`, `ROUTE-1933`, `ROUTE-1934`, `ROUTE-1935`, `ROUTE-1936`, `ROUTE-1937`, `ROUTE-1947`, `ROUTE-1969`, `ROUTE-2027`, `ROUTE-2039`, `ROUTE-2041`, `ROUTE-2056`, `ROUTE-2127`
- Route names: `clients.create`, `clients.edit`, `clients.index`, `clients.onboarding.toggle`, `clients.show`, `clients.store`, `clients.update`, `operations.clients.archive`, `operations.clients.care`, `operations.clients.create`, `operations.clients.edit`, `operations.clients.index`, `operations.clients.onboarding.toggle`, `operations.clients.quick_update`, `operations.clients.restore`, `operations.clients.show`, `operations.clients.store`, `operations.clients.update`, `operations.progress_notes.index`
- Route paths: `clients`, `clients/{client}`, `clients/{client}/edit`, `clients/{client}/onboarding/{key}`, `clients/create`, `operations/clients`, `operations/clients/{client}`, `operations/clients/{client}/care`, `operations/clients/{client}/edit`, `operations/clients/{client}/onboarding/{key}`, `operations/clients/{client}/quick-update`, `operations/clients/{client}/restore`, `operations/clients/create`, `operations/progress-notes`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0031`, `PAGE-0033`, `PAGE-0036`, `PAGE-0073`, `PAGE-0074`, `PAGE-0077`, `PAGE-0078`, `PAGE-0343`, `PAGE-0367`, `PAGE-0375`, `PAGE-0384`, `PAGE-0545`, `PAGE-0546`, `PAGE-0554`, `PAGE-0555`, `PAGE-0574`, `PAGE-0582`, `PAGE-0583`, `PAGE-0587`, `PAGE-0589`, `PAGE-0593`, `PAGE-0595`, `PAGE-0597`, `PAGE-0599`, `PAGE-0600`, `PAGE-0603`, `PAGE-0608`, `PAGE-0610`, `PAGE-0613`, `PAGE-0618`, `PAGE-0637`, `PAGE-0638`, `PAGE-0639`, `PAGE-0640`, `PAGE-0678`, `PAGE-0687`, `PAGE-0688`, `PAGE-0694`, `PAGE-0703`, `PAGE-0707`, `PAGE-0864`, `PAGE-0878`, `PAGE-0932`
- Target-supported route actions: `archive`, `Closure`, `create`, `edit`, `Illuminate\Routing\RedirectController`, `index`, `quickUpdate`, `restore`, `show`, `store`, `toggle`, `update`
- Other accepted IDs sharing retained routes: `CAP-OPS-CLIENT-NOTE`
- Backend anchors: `app/Http/Controllers/ClientController.php`, `app/Http/Controllers/ClientOnboardingController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised operations practitioner, scheduler or frontline worker

Goal: Complete **Client Record Lifecycle** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `clients.create`, `clients.onboarding.manage`, `clients.update`, `clients.viewAny`, `clients.viewAssigned`, `clients.viewPortal`
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
