# Incident ownership and responsibility

**7 October 2026 · Option 3 planning extension · Proposed implementation**

The user explicitly requires the ability to assign a named person who owns the incident, clarified that ownership can change, and selected the responsible site manager/coordinator as the default recipient with an unassigned queue if unavailable. The recommendation is one accountable owner at a time, with explicit reassignment rather than automatic transfer whenever a different person receives a task. Detailed permissions, cover, escalation intervals and specialist exceptions still require the organisation's actual configuration; no rules have been activated.

## 1. One accountable owner, several clearly named responsibilities

An incident has one **Incident owner** who coordinates its progress, makes sure the right specialists are involved, follows up outstanding decisions and keeps the person/reporting team informed as permitted. Ownership remains visible through the connected journey. Changing the person doing the next task does not automatically change the incident owner.

| Label | Responsibility | Source of truth |
| --- | --- | --- |
| Reported by | Who recorded the report; immutable attribution | Existing incident reporter |
| Incident owner | Accountable coordinator for this incident and its outstanding connected work | Explicit incident ownership, proposed below |
| Response owner | Current operational response and checkpoints | Existing Control Room assignment/duty rules |
| Safety review owner | H&S acceptance, review and governance follow-through | Existing H&S event owner and acceptance |
| Lead investigator | Investigation findings and recommendations | Existing H&S investigation |
| Action owner / executor | Named corrective action or source-task responsibility | Existing action/source task; distinguish executor where needed |
| Independent verifier | Verifies the completed corrective action under existing separation rules | Existing verification record |
| Notification owner | Applicable reporting decision/delivery follow-through | Owning notification workflow |

The same eligible person can hold more than one responsibility when existing policy allows. The UI must show the roles separately; no background synchronization should overwrite one assignment with another. Incident ownership grants no right to see restricted safeguarding, clinical or staff-health evidence or to perform an independently controlled approval.

## 2. Source finding that changes the delivery plan

At inspected main `12d5665c47b0367a60ee9df5dcc46917c94b390f`, `ClientIncident` stores reporter/reviewer and a legacy `investigation_assigned_to`, but no explicit incident-owner relationship was found in its model/controller/route contract. `ClientIncidentProvider` explicitly says it is not assignable because that legacy field is no longer written by the incident module; it still presents the legacy investigator as task assignee. `HsEvent.owner_user_id` and follow-up assignees are different responsibilities.

Therefore the named-owner requirement needs a domain/API change, not a renamed dropdown. Do not reuse the legacy investigator column or relabel the H&S owner as the incident owner. `Site.managerContact()` points to a `SiteContact` with contact details, not an authenticated-user relationship: never infer a user account by matching a contact name or email.

## 3. Proposed initial routing

**User-selected default:** an explicitly configured eligible site manager/coordinator receives new ordinary incidents, with a monitored Needs an owner queue if unavailable. Use the incident's recorded occurrence/service scope, not merely the person's current house. A proposed routing configuration references real internal user IDs, approved sites, permitted source/category and an effective interval. Reuse an equivalent existing staff-responsibility configuration if the baseline check identifies one.

Routing order:

1. Apply a required restricted/specialist route first, including safeguarding confidentiality and declared conflicts of interest.
2. Honour a permitted explicit selection by an authorized assigning user if it satisfies current eligibility and specialist rules.
3. Resolve the effective configured site incident lead and any explicitly configured cover.
4. If no eligible person exists, keep the incident submitted and visible in **Needs an owner**, with an accountable triage service/team and an escalation destination. Do not choose an arbitrary administrator, reporter or contact.

Support workers can report without knowing the correct manager or waiting for an owner to become available. Saving an urgent incident and initiating an applicable response must not be blocked by failed assignment or an empty picker. The receipt distinguishes “Report saved”, “Owner assigned” and “Needs an owner”.

The fallback is a visible exception, not an acceptable unattended endpoint. Before enabling automatic routing, configuration must provide a real monitored triage destination and an approved escalation interval. Missing configuration raises an operational setup exception without discarding the report. Do not invent response deadlines in code.

Provide a plain-language routing explanation and an authorized dry-run preview. Restricted/specialist rules take precedence over an ordinary site default; explicit manual assignment must still satisfy those restrictions. Ambiguous matches become a triage exception. Keep one deterministic rule order and record its version. Updating a rule must not silently replace an existing manually selected owner or send a new round of sensitive notifications. Reclassification produces an impact preview and authorized changes with history.

A concern about the site manager must not be routed to that manager simply because they are the default. Capture an allowed restricted-routing reason and send it to an independent eligible safeguarding/management role. Suppress sensitive notification content and recipient suggestions from unauthorized users. Do not infer misconduct from free text or make an automatic employment decision.

## 4. Assign, reassign and cover

Proposed default for ordinary incident ownership: an authorized coordinator's assignment takes effect atomically when saved. Record who assigned whom, when and why; show notification delivery separately. Do not add a mandatory acceptance step to ordinary assignments solely because H&S or Control Room has one. If the organisation requires ordinary incident-owner acceptance, that is an explicit policy variant to settle before activation.

- **Assign:** select a currently eligible internal user, optionally reuse a configured default, then save. Show the actual new owner and assignment receipt.
- **Reassign:** replace the current owner in one transaction with a required reason. Recheck old owner/revision and new owner's current eligibility. Preserve the prior owner in history. Do not silently unassign before assigning the replacement.
- **Request reassignment:** the owner can request help/change with a reason where permitted; the current owner remains accountable until an authorized change is recorded. A request does not itself grant a third party access.
- **Cover:** an authorized, explicit reassignment or existing approved delegation covers absence. Show its effective dates/reason. Avoid a second bespoke availability/leave engine. Ordinary leave does not automatically transfer sensitive cases or close work.
- **Emergency takeover:** an appropriately authorized coordinator can immediately reassign with a recorded reason; this never substitutes for Control Room live-duty handover acceptance.
- **Owner becomes ineligible:** revoke their access immediately under current security rules, preserve attribution, show “Owner needs replacement”, notify the eligible triage destination and require reassignment. Do not keep access merely to preserve continuity and do not label an ineligible owner as currently responsible without the exception.
- **Closed records:** keep the historical owner immutable through normal controls. Reopen through existing authority before routine reassignment. Legacy closed reports with outstanding connected work need an explicit reconciliation decision; do not quietly change historical ownership through a new picker.

Assigning an incident never assigns every linked action, accepts an H&S handover, claims an alert, signs a notification decision or verifies the assignee's own corrective action.

## 5. Eligibility and permissions

Define explicit abilities **assign incident owner**, **hold incident ownership** and **request reassignment**. First check whether existing permission contracts faithfully cover them; otherwise add narrow permissions with explicit role grants. Do not overload `incidents.viewAny`, `incidents.approve` or `hazards.manage` to mean everything.

Eligible owner: active internal account, incident ownership capability, authorized access to the specific incident and its historical approved-site scope, permitted category/sensitivity, and no recorded conflict disqualifying the appointment. Ownership is not a grant of source access. External clinician and representative accounts are ineligible. An ordinary worker may complete an assigned follow-up without being eligible to own or approve the incident.

The assigning actor must independently have assignment authority for that record. Picker search, result counts, selected-value hydration, preview and commit must enforce the same scope. Recheck assignment and access after site/role/employee changes and while a page is open. A stale choice fails safely without leaking why a hidden person/record is restricted.

The original audit found index/show/task permission differences. Settle their intended site/global meaning before introducing ownership filters; assignment must not become another bypass. Read access, ownership eligibility and approval are different tests.

## 6. Proposed storage and API contract

These are proposed fields and endpoints, not claims that they exist. Confirm exact names against current source in HS03.

| Need | Proposed representation |
| --- | --- |
| Current accountable person | Nullable `incident_owner_user_id` on `ClientIncident`, with a real User relationship and query indexes justified by filters |
| Current assignment provenance | Assigned-at/by and a revision token, or equivalent existing audit/version primitives; avoid independently editable duplicate provenance |
| Historical changes | Existing append-only audit/event facility if it preserves old/new actor, reason, timestamp, source/routing version and revision; otherwise a narrow incident-assignment history |
| Routing | Reuse existing site/user responsibility settings; extend minimally only when no equivalent exists; record which effective rule produced the assignment |
| Reassignment request | Existing actionable request/task mechanism if compatible, otherwise a bounded incident request with status and resolution; no parallel case number |
| UI contract | Current owner, historical attribution, eligibility/exception state, can-assign/request flags, owner-filter semantics and assignment revision |

Proposed commands: eligible-owner lookup for a specific incident; assign/reassign owner with expected revision and request identity; request reassignment; resolve/cancel that request through authorized domain actions. All mutation entry points invoke the same service, including All Tasks. Generic update fields must not bypass it. Ownership/routing changes require attributable audit history and failure-safe delivery.

Use existing transaction/locking and outbox/delivery facilities where compatible. Save ownership/history together; enqueue post-commit notification once. A notification failure cannot undo a committed assignment or claim it was delivered. Repeated or concurrent requests produce one effective change, with a helpful conflict response and preserved input.

## 7. Where ownership must appear

- Incident register: explicit Owner column, “Owned by me”, owner picker filter, “Needs an owner” and “Owner needs replacement” views; distinct from “Tasks assigned to me”.
- Incident page: Incident owner above/next to the primary next-action panel; Assign/Reassign when permitted; separate response/review/investigator labels in context.
- All Tasks: incident row projects the canonical incident owner. Follow-up/action rows retain their own assignees. Remove legacy investigator fallback from the incident-assignee display once the migration is active; label historical investigator context separately.
- My Day: staff see their assigned follow-ups and permitted incident progress; managers/coordinators see owned work through the existing role-aware shell. Do not flood worker My Day with every administrative case branch.
- Person/site pages, Overview, search, exports and notifications: same source owner where allowed, same current revision and source address. No stale cached owner should be mistaken for current authority.
- History: assignment provenance and reason, with privacy shaping. Historical identities survive deactivation; they are not selectable simply because they appear in history.

## 8. Existing records and activation

Add the owner field as nullable and deploy compatible readers first. Existing reporter, reviewer, investigator and H&S owner are candidate evidence for a reviewed assignment; none automatically establishes the new accountable role.

Produce a dry-run reconciliation report grouping active records with a defensible explicit mapping, no candidate, conflicting candidates and restricted/ineligible candidates. Any approved assignment batch records a **new assignment at its actual time**, with provenance; it must not invent historical acceptance or pretend ownership existed earlier. Leave historical closed records unchanged unless a specific authorized correction is needed.

Before activating owner-required closure for new/active work, resolve the targeted unowned backlog or route exceptions explicitly, keep emergency reporting available and verify the role matrix. The accountable owner remains responsible for coordination, but existing domain closure services decide whether a report/review/action can close. Do not mass-reopen closed records merely to fill a new field.

## 9. Ownership acceptance scenarios

| ID | Scenario | Required result |
| --- | --- | --- |
| O01 | Ordinary report with eligible configured site lead | One canonical owner, route/version recorded, reporter and reviewers unchanged |
| O02 | No eligible default or route unavailable | Report and applicable response persist; monitored Needs an owner work appears; no arbitrary assignee |
| O03 | Authorized direct assignment | Record-specific eligibility, audited change and truthful receipt |
| O04 | Reassignment concurrent with another change | Expected revision/lock wins once; second actor receives conflict; no lost reason/history |
| O05 | Duplicate assignment delivery/retry | Same assignment once; no duplicate notifications or history |
| O06 | Owner lacks a linked source's authority | General ownership does not reveal source details or enable its actions |
| O07 | Owner leaves, is disabled or loses site access | Access revokes; replacement exception routed; attributable history retained |
| O08 | Case involves the configured manager | Independent restricted route; no inappropriate message to the subject |
| O09 | Actor forges owner/site or uses stale picker | Server denies safely; current owner and evidence unchanged |
| O10 | All Tasks versus incident page/list | Same incident owner; follow-up/investigation assignees remain separate |
| O11 | Reporter submits without assignment authority | Can save permitted report; cannot assign arbitrary users or bypass routing |
| O12 | Ordinary reassignment versus H&S/shift acceptance | Ordinary assignment follows approved policy; neither acceptance is fabricated |
| O13 | Owner absent while response is urgent | Response proceeds with its own authorized operator; incident cover resolved explicitly |
| O14 | Historical migration | No inferred historical ownership; batch decisions auditable and repeat-safe |
| O15 | Closed/reopened record | Historical owner preserved; reopened work rechecks eligibility before resuming |
| O16 | Assignment succeeds, notification fails | Owner change stays committed; delivery failure visible and retryable |

## 10. User direction

The user answered the ownership question: “i think it is a bit of both ownership can change right?” The plan therefore supports a named accountable owner who can change, with separate task/review responsibilities. The recommended operational detail is one accountable person at a time and an explicit recorded reassignment, including when the next stage genuinely calls for a new coordinator.

The user then selected “number one”: the responsible site manager/coordinator, with an unassigned queue if unavailable. This is the adopted product default, with the specialist restrictions and eligibility checks above. Actual staff accounts, effective cover, triage recipients and escalation intervals are configuration work, not unanswered product questions. The user also requested market research; [the research and variable matrix](MARKET-RESEARCH.md) informed the routing/exception refinements.
