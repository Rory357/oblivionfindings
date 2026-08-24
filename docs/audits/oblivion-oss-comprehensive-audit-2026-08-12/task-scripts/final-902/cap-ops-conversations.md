# CAP-OPS-CONVERSATIONS — Conversations

Status: **Blocked — source-derived final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `CAP-OPS-CONVERSATIONS`
- Canonical module: `OPERATIONS`
- ID provenance: `exact`
- Source families: `CLI-PORTAL-MESSAGE`, `OPS-CLIENT-FAMILY-CHAT`, `OPS-MESSAGE`
- Route scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Route evidence: `ROUTE-1973`, `ROUTE-1974`, `ROUTE-2097`, `ROUTE-2098`, `ROUTE-2099`, `ROUTE-2100`, `ROUTE-2101`, `ROUTE-2102`, `ROUTE-2103`, `ROUTE-2104`, `ROUTE-2105`, `ROUTE-2263`, `ROUTE-2264`, `ROUTE-2265`, `ROUTE-2266`, `ROUTE-2267`, `ROUTE-2268`, `ROUTE-2269`, `ROUTE-2270`
- Route names: `operations.clients.family-chat.show`, `operations.clients.family-chat.store`, `operations.messages.archive`, `operations.messages.create`, `operations.messages.index`, `operations.messages.pin`, `operations.messages.react`, `operations.messages.read`, `operations.messages.search`, `operations.messages.show`, `operations.messages.store`, `portal.clients.messages`, `portal.clients.messages.archive`, `portal.clients.messages.pin`, `portal.clients.messages.react`, `portal.clients.messages.search`, `portal.clients.messages.send`, `portal.clients.messages.show`, `portal.clients.messages.start`
- Route paths: `operations/clients/{client}/family-chat`, `operations/messages`, `operations/messages-search`, `operations/messages/{conversation}`, `operations/messages/{conversation}/read`, `operations/messages/archive/{message}`, `operations/messages/create`, `operations/messages/pin/{message}`, `operations/messages/react/{message}`, `portal/clients/{client}/messages`, `portal/clients/{client}/messages-search`, `portal/clients/{client}/messages/{conversation}`, `portal/clients/{client}/messages/archive/{message}`, `portal/clients/{client}/messages/pin/{message}`, `portal/clients/{client}/messages/react/{message}`, `portal/clients/{client}/messages/start`
- Page scope: target-supported exact/shared relation retained in the working manifest; not necessarily exclusive ownership
- Page evidence: `PAGE-0653`, `PAGE-0722`, `PAGE-0723`
- Target-supported route actions: `archiveMessage`, `createConversation`, `index`, `markRead`, `searchMessages`, `show`, `startConversation`, `store`, `storeMessage`, `togglePin`, `toggleReaction`
- Other accepted IDs sharing retained routes: No other accepted working IDs share these retained route relations.
- Backend anchors: `app/Http/Controllers/Operations/ClientFamilyChatController.php`, `app/Http/Controllers/Operations/MessageController.php`, `app/Http/Controllers/Portal/PortalMessageController.php`
- Exact working-ID findings: No exact working-ID finding link is currently established.

Blank or source-family-envelope evidence must not be read as proof that this capability has no route/page or that every family route belongs exclusively to it.

## Representative task

Actor: Authorised operations practitioner, scheduler or frontline worker

Goal: Complete **Conversations** on the authoritative record, then verify an unambiguous persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented global/site/ownership scope.
- A resettable synthetic record in the correct prerequisite state.
- Target authorization evidence. Exact route permission atoms where enriched: `clients.viewAny`, `clients.viewAssigned`
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
