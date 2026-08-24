# CAP-OPS-CLIENT-NOTE — Client Note

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-OPS-CLIENT-NOTE`
- Canonical module: `OPERATIONS`
- ID provenance: `exact`
- Source families: `CLI-CLIENT-NOTE`, `OPS-CLIENT-DAILY-NOTE`, `OPS-PROGRESS-NOTE`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-0181`, `ROUTE-0182`, `ROUTE-1956`, `ROUTE-1957`, `ROUTE-1958`, `ROUTE-1959`, `ROUTE-1960`, `ROUTE-1961`, `ROUTE-1962`, `ROUTE-2024`, `ROUTE-2025`, `ROUTE-2127`, `ROUTE-2128`, `ROUTE-2129`, `ROUTE-2130`
- Route names: `clients.notes.pin`, `clients.notes.store`, `operations.clients.daily-notes.destroy`, `operations.clients.daily-notes.flag`, `operations.clients.daily-notes.index`, `operations.clients.daily-notes.review`, `operations.clients.daily-notes.review-queue`, `operations.clients.daily-notes.store`, `operations.clients.daily-notes.update`, `operations.clients.notes.pin`, `operations.clients.notes.store`, `operations.progress_notes.destroy`, `operations.progress_notes.index`, `operations.progress_notes.store`, `operations.progress_notes.update`
- Route paths: `clients/{client}/notes`, `clients/{client}/notes/{note}/pin`, `operations/clients/{client}/daily-notes`, `operations/clients/{client}/daily-notes/{note}`, `operations/clients/{client}/daily-notes/{note}/flag`, `operations/clients/{client}/daily-notes/{note}/review`, `operations/clients/{client}/daily-notes/review-queue`, `operations/clients/{client}/notes`, `operations/clients/{client}/notes/{note}/pin`, `operations/progress-notes`, `operations/progress-notes/{note}`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0584`, `PAGE-0585`, `PAGE-0601`, `PAGE-0617`
- Target-supported route actions: `Closure`, `destroy`, `flag`, `index`, `review`, `reviewQueue`, `store`, `togglePin`, `update`
- Other accepted IDs sharing retained routes: `CAP-OPS-CLIENT-RECORD-LIFECYCLE`
- Backend anchors: `app/Http/Controllers/ClientNoteController.php`, `app/Http/Controllers/Operations/ClientDailyNoteController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised operations practitioner, scheduler or frontline worker

Goal: Complete **Client Note** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `clients.update`, `progress_notes.create`, `progress_notes.delete`, `progress_notes.review`, `progress_notes.update`, `progress_notes.viewAny`, `timeline.create`, `timeline.pin`
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
