# Market research and variable-sensitive design

**Research date: 7 October 2026 · Public primary-source review for the option 3 plan**

The research supports a connected incident workspace with a named, changeable owner and separate delegated actions. It also shows why a generic IT-ticket lifecycle is insufficient: care context, restricted specialist work, notifications, learning and verification need their own rules. This conclusion is a design inference from the documented patterns below, combined with Oblivion Findings' inspected source and the user's requirements.

## 1. Method and limits

Reviewed six relevant product families: ShiftCare, Logiqc, HSI Donesafe, Person Centred Software, VCare and RLDatix. Sources are their public product pages and operational help documentation. Operational help gives stronger evidence of described behavior than broad marketing claims. No vendor account, paid demonstration, usability test, security audit, procurement comparison or pricing verification was performed. A missing description means **not established by this research**, not that the vendor lacks the capability.

These products span disability/community care, residential care and safety/quality platforms; none is treated as a perfect supported-living template. Australian NDIS, UK care regulation and generic workplace-safety examples are not imported as New Zealand policy. No source establishes a universal six-item navigation rule or that Oblivion Findings is already better than these products. Six destinations is our application-specific information-architecture decision; Rory's current guides govern its appearance.

Some sources publish relative update dates; those are not converted into invented release dates. Features may depend on vendor plans or configuration. The current RiskMan page distinguishes continuing RiskMan support, hosted migration and DatixCloudIQ; current DatixCloudIQ capabilities must not be attributed automatically to an older RiskMan installation.

## 2. Comparator findings

| Product | What its primary material documents | Lesson for Oblivion Findings | Boundary |
| --- | --- | --- | --- |
| **ShiftCare** | Shift-linked forms create incident tickets with an assignee, priority, due date, discussion and privacy controls. Its guide distinguishes an internal reporting indicator from direct regulator submission. [Incident forms and tickets](https://help.shiftcare.com/en/articles/12321658-incident-management-forms-and-tickets) | Preserve a simple worker report feeding a permanent managed incident; make assignment and delivery state explicit. | Do not copy its Australian reporting checkbox, generic statuses or escalation intervals as NZ policy. |
| **Logiqc** | The manage stage supports reassignment, delegated tasks, returned work and action history. Conditional rules can route and notify by event attributes; response protocols offer assigned actions. [Manage incidents](https://knowledgebase.logiqc.com.au/manage-incidents), [business rules](https://knowledgebase.logiqc.com.au/how-to-set-business-rules-for-reporting-events), [response protocols](https://knowledgebase.logiqc.com.au/how-to-apply-response-protocols) | Separate accountable management from task execution; use understandable category/site rules and auditable history. | Build a bounded routing configuration, not a new general-purpose workflow designer. Rules must never weaken canonical source privacy. |
| **HSI Donesafe** | Describes connected incidents, hazards/risks and other registers; conditional forms, configurable triage, investigations, assigned corrective work and role-specific views. [Incident management](https://www.donesafe.com/module/incident-management/) | Keep the short intake and deeper manager workflow connected; reveal relevant fields progressively and retain specialist context. | Marketing speed/compliance claims are not acceptance evidence. No assumption that its generic investigation methods fit every supported-living event. |
| **Person Centred Software** | Describes raising incidents at the point of care, manager follow-up/sign-off and recorded incident history/charts. [Accidents and incidents](https://personcentredsoftware.com/products/digital-care-system/key-features/accidents-and-incidents) | Keep My Day/person/shift reporting convenient; show follow-through back in care context. | The cited page does not establish detailed owner-transfer, emergency-desk or restricted-case rules. |
| **VCare** | Documents event-triggered task checklists, unresolved-event worklists and links to one or more people/admissions/contracts. [Care management](https://www.vcaresoftware.com/aged-care-software-features/care-management-software/) | Explicitly handle multiple affected people and continuity across episodes of support; do not leave completed capture as the end of the workflow. | Its residential-care data model is not a reason to replace this application's canonical person/source relationships. |
| **RLDatix / DatixCloudIQ, with RiskMan context** | Presents distinct incident, quality-improvement, risk, safeguarding, learning, reporting and mortality capabilities. RiskMan's page separately describes support and migration options. [Risk and safety](https://www.rldatix.com/en-apac/solutions/risk-safety/), [RiskMan](https://www.rldatix.com/en-apac/solution/risk-safety/riskman/) | Bring related work together while retaining specialist confidentiality, learning and review authority. | Product-family descriptions do not prove equivalent functionality in every legacy edition or local configuration. |

ShiftCare's separate corrective-action guide documents assigned execution and reviewer sign-off, including rejection back to the assignee. Its alternatives include self-attestation, but this is **not** a reason to weaken Oblivion Findings' existing independent H&S verification. The relevant pattern is clearly distinguishing doing the work from verifying it. [Corrective actions](https://help.shiftcare.com/en/articles/16638491-managing-corrective-actions)

The most useful combination is care-context reporting from PCS/VCare, a visible ticket-like record from ShiftCare, and explicit delegation/routing from Logiqc, bounded by the application's existing source services. These are selected interaction patterns, not a proposal to reproduce another vendor's product.

## 3. New Zealand supported-living check

DSS's current guidance distinguishes contractual critical-incident reporting, death processes and qualifying Te Tāhū Hauora notifications. Its expectations also include support, review and learning across incidents. This supports destination-specific applicability and follow-through rather than one WorkSafe checkbox. The provider's actual contracts/services still determine configuration. [DSS reporting guidance](https://www.disabilitysupport.govt.nz/providers/reporting-of-critical-incidents-and-deaths)

Te Tāhū Hauora's framework explicitly addresses consumer/whānau participation, culturally responsive practice, safe reporting, system accountability and learning. Its resources include sector-specific assessment and proportionate learning tools. We should support those activities rather than make every case an adversarial investigation or a blame-oriented “root cause” form. [Healing, learning and improving from harm](https://www.hqsc.govt.nz/our-work/system-safety/healing-learning-and-improving-from-harm/), [system accountability resources](https://www.hqsc.govt.nz/our-work/system-safety/healing-learning-and-improving-from-harm/system-accountability/)

Reporting applicability must consider event category as well as severity; check relevant always-report/review rules where applicable rather than assuming a no-injury incident can never require escalation. This is a configuration requirement to validate against authoritative guidance, not an automatic rule applied to all services by this plan.

## 4. Variables the design must handle

The following are **proposed Oblivion Findings requirements and implementation checks**, not claims that every comparator supports every row. Together they test the chosen site-manager default against realistic exceptions.

| ID | Variable | Required behavior | Package / check |
| --- | --- | --- | --- |
| V01 | Actual harm, potential harm, current danger and report priority differ | Separate fields/display and approved mappings; no automatic equivalence between app severity and a regulatory classification | HS06/10/24; X01 |
| V02 | Low harm but reportable category, or uncertain facts | Apply service/category-specific rules and human review; uncertainty is not Not required | HS10/24; C22/X01 |
| V03 | Ordinary worker versus authorized manager reporting | Same canonical capture; only authorized actor can override eligible routing | HS04/06; O11 |
| V04 | Site manager is available and eligible | Assign configured real user and record routing provenance | HS03–04; O01 |
| V05 | Site manager is on leave, inactive or loses scope | Use approved cover if configured; otherwise monitored replacement/unowned work with escalation | HS03–04; O02/O07/O13 |
| V06 | Allegation concerns the manager or another proposed recipient | Independent restricted routing; no sensitive notification to a conflicted recipient | HS03/10; O08 |
| V07 | After hours or immediate danger | Live response follows current operational/on-call duty; case routing does not wait for office hours | HS11/15; O13/X02 |
| V08 | Occurrence site differs from current home or transport destination | Record occurrence/service responsibility; preserve historical scope; do not route solely from current placement | HS03/20/22; C18/X03 |
| V09 | No client: staff, visitor, contractor, asset or unknown person | Use correct typed intake and source review; never invent a client to pass validation | HS06/10/17/22; C03/C05 |
| V10 | Several affected people in one event | Reuse canonical originating event and per-person reports where required; scope each person's data independently | HS07/22/25; X04 |
| V11 | Person and employee harmed in the same occurrence | Linked client and confidential injury/recovery branches; no copied staff-health narrative | HS17/22; C17/C21/X04 |
| V12 | Late report or later change in assessed severity | Preserve occurred, discovered, reported and assessed times as applicable; reevaluate response/notification needs without resetting elapsed time or history | HS06/10/24; X05 |
| V13 | Duplicate submission versus another person's legitimate account | Request-id retries return same record; human duplicate review preserves distinct accounts; no text-based automatic merge | HS06/07/26; C04/C23/X06 |
| V14 | Repeat pattern across different events/houses | Link an authorized learning/theme review; keep events, dates and people separate | HS08/24/25; X07 |
| V15 | Several parallel actions and external information awaited | Each task has its owner/clock; waiting reason/review date visible; no blanket pause of notification or emergency deadlines | HS08–11/24; X08 |
| V16 | Owner changes at a stage boundary | Explicit reasoned reassignment, same incident ID/history; H&S acceptance and operational handover stay separate | HS04; O04/O12 |
| V17 | Rule changes while a report is open or after it is submitted | Evaluate current policy at commit, record version; preview/review existing-case impact without silently replacing manual owners | HS03/04/24; X09 |
| V18 | Sensitive source, family request or representative change | Current legal/role/source authority controls disclosure; incident ownership is not family or clinical access | HS07/21/24; C18/C21/C30 |
| V19 | Owner notified but email fails or recipient loses permission | Keep committed owner; retry safe minimal message after current authorization; in-app work remains visible | HS04/23; O16/X10 |
| V20 | Poor connection, interrupted form or failed upload | Honest unsaved/pending state, secure existing recovery, safe retry; no false successful report or invented offline guarantee | HS06/09; C01/C23/X11 |
| V21 | Person dies, leaves service or moves sites | Retain outstanding review/notification work and historical scope; do not close cases on discharge | HS20/24/26; X03/X12 |
| V22 | Viewer has no permitted action while work remains open | Explain allowed waiting/owner context; lack of authority is not a completed journey | HS07; X02 |
| V23 | Treatment, clinical order, staff capacity or vehicle release remains restricted | Safety case outcome never releases another domain's control automatically | HS17/21/22; C16/C17 |
| V24 | Record closed, new evidence or historical link conflict | Use guarded reopen/repair, preserve decisions and evidence; no new owner backfill or auto-merge disguised as cleanup | HS06/26; C15/C27/O14–15 |
| V25 | Internal, external or combined response arrangements | Approved scope/coverage, named internal coordination, receipt versus acceptance and failover; simulate before real provider integration | HS11; R01 variants |
| V26 | Different reconstruction windows and source permissions | Bounded configurable pre/post duration/trigger/source set; versioned applied policy and justified override; no retroactive expansion | HS22c; R04 variants |

V25/V26 incorporate the user's follow-up answers in the Devices chat. They extend the initial 24-variable review rather than claiming additional vendor capabilities. The user also confirmed development-only status, so the first implementation uses the shared synthetic pilot and labels simulated evidence accurately.

## 5. Concrete additions to the plan

1. **Bounded routing rules with an explanation and preview.** A manager can see why the site lead or specialist received the report. Rule precedence is deterministic; ambiguous matches create a triage exception. Updating a rule does not silently transfer existing incidents or broaden privacy.
2. **Separate clocks and versioned reassessment.** Response, ownership/triage, report review, actions and external notification do not share one generic ticket SLA. A severity change records the old/new assessment and triggers review of affected obligations without deleting the previous due history.
3. **Related-event handling without a universal case rewrite.** Multiple people, parallel source records, duplicates and repeat-event learning receive explicit link/visibility rules. Every metric names whether it counts occurrences, person incidents or work items.
4. **Proportionate response/review templates.** Reuse approved Control Room playbooks and existing incident/investigation templates. Offer relevant suggested tasks for human confirmation and record template/version. Do not create duplicate protocol engines or automatically complete/skip required actions.
5. **Owner return loop.** Delegated actions return an outcome to the accountable owner/reviewer; failure, rejected verification and unavailable assignees remain visible. Do not let a long list of child tasks conceal who coordinates the whole incident.
6. **Learning outcome, not just closure count.** Track the approved control/plan/training change and, where required, later effectiveness review. A repeat-event theme may remain a separate improvement activity after individual records close under their own rules.

These changes are folded into HS03–04, HS06–11, HS20–26 and the X01–X12 scenarios in the detailed plan. They are not a new speculative software platform.

## 6. Features deliberately not copied into the first implementation

- A universal drag-and-drop workflow designer: too much complexity for one organisation when typed source workflows already exist.
- Vendor-specific 4/8-hour escalations, regulator labels, or generic status lists: use actual local policy and canonical lifecycles.
- AI automatic severity, safeguarding, notification or closure decisions: no such authority is granted by this plan. Future assistance would need its own review, privacy and human-confirmation contract.
- Full offline incident synchronization or a new public/anonymous portal: the primary release preserves secure draft/retry behavior. Any new channel requires a concrete threat/privacy, identity and operational-response design. Existing confidential/safeguarding paths remain available.
- Blanket family visibility, automatic duplicate merging or low-reporting-is-safe rankings: these conflict with the proposed permission, evidence and learning principles.

## 7. Remaining uncertainty

Public documentation cannot settle actual on-call coverage, specific manager user accounts, contract applicability, escalation times or specialist independence rules. Resolve these from current configuration/policy and service owners during HS00/03/24; use labelled synthetic choices for development, then validate real settings before operational reliance. No additional broad product-choice question is needed now: the user selected the site-manager default and changeable ownership, development pilot, flexible response arrangement and customizable evidence windows.
