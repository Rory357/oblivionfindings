# CAP-OPS-ROSTER-TEMPLATE-DEFINITION — Roster Template Definition

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-OPS-ROSTER-TEMPLATE-DEFINITION`
- Canonical module: `OPERATIONS`
- ID provenance: `exact`
- Source families: `OPS-ROSTER-TEMPLATE`, `OPS-ROUTE-OPERATIONS-ROSTERING-TEMPLATES-INDEX`
- Route scope: exact target allocation
- Route evidence: `ROUTE-2161`, `ROUTE-2162`, `ROUTE-2163`, `ROUTE-2164`, `ROUTE-2165`, `ROUTE-2166`
- Route names: `operations.rostering.templates.apply`, `operations.rostering.templates.destroy`, `operations.rostering.templates.duplicate`, `operations.rostering.templates.index`, `operations.rostering.templates.store`, `operations.rostering.templates.update`
- Route paths: `operations/rostering/templates`, `operations/rostering/templates/{template}`, `operations/rostering/templates/{template}/apply`, `operations/rostering/templates/{template}/duplicate`
- Page scope: no page evidence enriched for this final target
- Page evidence: Not enriched.
- Exact target actions: `apply`, `Closure`, `destroy`, `duplicate`, `store`, `update`
- Backend anchors: `app/Http/Controllers/Operations/RosterTemplateController.php`, `routes/*.php closure`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised operations practitioner, scheduler or frontline worker

Goal: Complete **Roster Template Definition** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `roster_templates.create`, `roster_templates.delete`, `roster_templates.update`, `roster_templates.viewAny`, `rostering.viewAny`
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
