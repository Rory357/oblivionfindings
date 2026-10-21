# Referrals mockup — three audit-and-improve passes

Scope: isolated fictional prototype; no live records, messages, agency submissions or production authorization. The original design pass was revised after user feedback to keep the shared brand and controls while improving body layouts. That revision is the baseline for these three additional audit passes.

## Pass 1 — complete workflow and work continuity

Browser walkthrough: public validation → website enquiry → duplicate-retry receipt → staff intake → assignment → assessment → funding preparation → external submission → decision → finance verification → proposal → onboarding conversion → safe service commencement → follow-up queue.

Finding: the follow-up queue included individual tasks but omitted a referral's primary next action. A newly submitted case could therefore lose visible follow-up work after conversion even though its next action remained saved.

Implemented: include primary next steps alongside tasks; retain post-conversion work in the default funding and follow-up views; avoid duplicating a task with the same label; add a reversible next-step completion control; exclude completed next steps from overdue counts; reset completion when a new next step is planned. Service-start approval now displays its completed state and cannot be accidentally repeated.

Verification: the entire browser walkthrough passed after the correction (8 checkpoints). The prior failed run was preserved as a finding rather than reported as a pass.

## Pass 2 — responsive layout, accessibility and interaction clarity

Reviewed seven desktop/phone screens (queue, settings overview, workflow settings, public landing, intake wizard and referral detail) plus the settled dark-theme detail. Automated WCAG A/AA scans reported no violations after fixes; the checked 390px phone layouts had no horizontal page overflow. This is targeted automated coverage, not a claim of full accessibility certification.

Findings and implemented changes: fixed low-contrast sidebar caption text; increased small phone action targets; collapsed the hero’s secondary controls using the shared mobile header; focused validation summaries; returned focus to the invoking control after completion; reset wizard body scroll and heading focus between steps; protected unfinished wizard edits on browser navigation; displayed friendly New Zealand dates in review.

Verification: completed the phone date-picker journey, confirmed the saved review date, triggered an empty required field and verified focus reached its error, then confirmed focus returned to Add follow-up. Dark-theme scans performed during colour animation gave transient contrast readings; the settled theme was rechecked and passed.

Final-build verification extended the scan to ten screen states, adding phone permissions and the open role picker and including dark mode. All ten had zero automated WCAG A/AA violations and no horizontal page overflow. Reduced-motion mode avoided sampling midway through theme transitions. The complete eight-checkpoint public-intake-to-service-start walkthrough also passed on the final build.

## Pass 3 — permission boundaries, versioning and recovery

Findings and implemented changes:

- Multiple unfinished intakes needed a named draft chooser. Drafts now retain the saving role and timestamp; switching role does not expose another role’s drafts.
- The Mine filter assumed Emma Taylor. It now uses the current preview role’s staff identity.
- Supported-person previews and conversion choices assumed Auckland access. They now follow permitted canonical referral links, and a different existing identity cannot be selected merely because the referral already has a match.
- Commands now recheck record scope; assessment/evidence actions require the sensitive-data grant as well as the action grant. Quarantined evidence is also denied at the viewer/review entry point. Permission settings explain combined grants.
- Settings now reject invalid/fractional review periods, blank message templates and duplicate preparation requirements. Validation receives focus and scrolls into view.
- The conflict message previously referenced an unavailable reload control. It now accurately explains the simulated review-and-retry flow, preserving entered values.
- Failed-delivery entries now expand automatically to reveal recovery. Quarantine simulation resets after the next staged document, matching its description.
- A clean rebuild exposed implicit dependence on utilities in prior generated output. The prototype now explicitly includes the shared components’ styling sources, without changing production styles or the design rules. Successive builds produced the same stylesheet asset.

Verification: the complete seven-checkpoint governance walkthrough passed. It covers direct-record denial, restricted roles, settings-only access, publication and existing-case version pinning, controlled migration, immediately effective grants, site scope on person previews, draft isolation, save/conflict recovery, new-case template adoption, quarantined evidence, delivery retry and existing-person handover with pending additional-service commencement blocked.

## Scope of assurance

All three requested audit-and-improve passes are complete. These checks verify the synthetic prototype’s demonstrated journeys, not the production application’s authorization, agency integration, eligibility rules, concurrent updates, full accessibility or legal/financial correctness. See [prototype boundaries](README.md#prototype-boundaries) for implementation work still required.

## Design-conformance correction after user review — 15 October 2026

The previous passes missed important design-contract requirements: the view chooser did not match the shared Cards/Table control, the work queues used custom presentation, settings did not open each group with its prescribed ReviewCard overview, and there was no actual calendar. Functional and automated accessibility checks alone did not establish design conformance.

Implemented the shared PageHeaderViewToggle, EntityCard/Grid/Table, cell library, ListCaption and matching context/kebab menus across referrals, funding assistance and follow-ups. Pipeline remains available for referrals. Removed local typography and blanket button-size overrides. Settings now opens with shared ReviewCards and Review links, uses Overview/Configuration subtabs, labelled On/Off channel controls, and destructive publication for policy changes that loosen safeguards.

Added the real SiteCalendar with a local referral adapter: five views, date navigation, source filters, new follow-ups, entry details, real date/time pickers, reasoned rescheduling and undo. Role permissions control sources, creation, editing and export. Business logic and fictional data remain local to the prototype. The shared calendar received an optional export gate (default behaviour unchanged), corrected month-grid header semantics and improved outside-month date contrast.

Browser verification: `qa/conformance-audit.js` passed five journey groups covering all three collection switches and menus; all five calendar views/navigation/source filters; scheduling, rescheduling and undo; restricted-role calendar behaviour; and settings Review/Configuration/channel controls. Seven desktop/390px phone screen states had zero automated WCAG A/AA violations, no horizontal page overflow and no JavaScript page errors. An earlier scan caught nested interactive card controls and month-grid semantics/contrast; these were fixed and rescanned. The current canonical screenshots show the corrected layouts.

Shared regression verification: three focused Vitest files passed, 17 tests, covering calendar SSR, protected press handling and entity interactions. The complete eight-checkpoint website-to-service-start browser journey also passed on the corrected build, including post-conversion follow-ups. Its final assertion was updated to target the shared table explicitly rather than matching both desktop and hidden mobile markup. The standalone production build passed; its bundle-size advisory remains. The live Fleet settings reference required sign-in, so settings conformance was checked against its source and the documented contract, not an authenticated live comparison.

## Workflow and UI gap review — 16 October 2026

**Review outcome: do not sign off the mockup as fully featured yet.** The successful journey and shared presentation foundations work, but important alternate journeys, information continuity and configuration behaviour remain incomplete. The earlier three completed audit passes describe work performed; they do not mean every specification requirement was satisfied. The 22 findings below were open at this review. Their subsequent implementation and verification are recorded in the correction and re-audit section at the end of this log.

Scope: the standalone Referrals prototype, the approved specification (especially sections 4–12, 15–16 and 18), DESIGN.md and the actual reused components. No live records, external submissions or production security were tested. This is one organisation with site, role, ownership and privacy boundaries.

### Evidence and limits

- Current browser: `http://127.0.0.1:5186/?calendar_date=2026-10-15`, isolated QA storage, same preview build as the user's localhost address. The user's localhost examples were not reset.
- Six edge-case groups reproduced in `qa/gap-audit.js`. They include a correctly enforced access boundary with an unclear handoff, as well as actual defects. The public handoff group also reproduces the receipt navigation exception.
- The complete eight-checkpoint website → assignment → assessment → funding → finance verification → proposal → onboarding → service-start journey passed again in this review.
- Seven screen states checked at desktop/390px phone width: cards, settings, calendar, referral detail and the public wizard. Zero automated WCAG A/AA violations and no horizontal page overflow in those samples. Visual inspection still found poor phone composition and a toast overlapping wizard controls. Automated accessibility success is not evidence of complete usability or design conformance.
- Browser observations: `output/playwright/referrals-gap-audit-results.json`, `referrals-gap-visual-results.json`, `referrals-gap-happy-path-results.json`; screenshots `referrals-review-*.png` and `referrals-gap-public-phone.png` in the same directory. The initial test harness had reload-dialog interference and one incorrect intake-button selector; the retained final run uses in-app reset and the actual Create referral control.
- Source-confirmed findings below are not all independently exercised in the browser. The review did not revalidate external agency policy; funding requirements are compared with the already approved specification, not presented as new legal or financial advice.
- P1 means resolve before mockup approval because the demonstrated behaviour is misleading or undermines a core boundary. P2 means complete the expected workflow or improve usability before calling the mockup fully featured. These are prototype priorities, not claims about production vulnerabilities.

### P1 — information, boundaries and handover

**F01 — Separate the referrer from the person and preserve the intake handoff. Browser reproduced.**

A professional submission captures the person's name but the referrer's phone/email. Contacts then labels those details as belonging to the “Person seeking support”. The referrer/organisation, requested region, custom answer and existing host arrangement are saved but absent from the reviewed Overview, Contacts and Funding screens. The funding-situation choice is overwritten by the funding-case array: “I have an allocation / approval” results in no case and no visible intake funding position. Evidence: `public.jsx` fields and save mapping; `app.jsx` Contacts; `case-design.jsx` Overview.

Required UI: distinct Person, Referrer and Safe contact recipient records; an Intake information review group showing every submitted answer with provenance and verification state; a separate funding-situation value. Unknown must remain unknown. Acceptance: repeat the professional journey, identify whose number will be used, and find all answers without re-entering them. Include a whānau caller whose contact differs from the person's.

**F02 — Give sensitive narratives their own visibility rules. Browser reproduced.**

The assessment's “What matters to the person” field writes `r.needs`; Overview renders it for a receptionist without the sensitive-data grant. Free-text contact notes also have no sensitivity classification. Hiding the Funding and Evidence tabs does not provide field-level separation. Evidence: `case-design.jsx` person statement; `app.jsx` assessment save and Activity.

Required UI: distinguish the general intake summary from restricted assessment/third-party notes, label the audience when entering information, and show a safe redacted summary to reception. Acceptance: a restricted narrative cannot appear in Overview, notes, search snippets, notifications or exports for a role lacking its permission. Keep useful goals visible when explicitly classified for general intake.

**F03 — Protect closed and converted lifecycle states. Browser reproduced.**

Closing Noah with a person-chosen closure reason still allows Review handover → Confirm handover, changing Closed directly to Converted. The conversion gate never checks the lifecycle state. The generic stage editor also initializes a converted referral as Assessment and permits changing its stage while retaining the person link. Evidence: `data.js` conversionBlocks; `app.jsx` lifecycle and convert commands.

Required UI: Closed offers a reasoned Reopen action before any further progression; Converted retains its link and completed conversion, with additional work managed separately. Acceptance: closed cases cannot convert, converted cases cannot become ordinary open intake through the stage picker, and valid reopenings retain closure history and renewed next steps.

**F04 — Demonstrate funding validity, scope and allocation before approving service start. Source confirmed.**

Coverage is the sum of every verified, non-income case's weekly dollar amount. Service-start checks compare that sum with proposal cost. There are no structured valid-from/expiry dates, approved units/period, provider allocation, service compatibility or condition-resolution records. A free-text “Conditions, scope and validity period” field and a confirmation checkbox cannot demonstrate the specified expiry/conditional-authorisation behaviour. New referrals also inherit a NZD 1,200 weekly proposal before any scope has been established; unknown money becomes zero in formatting. Evidence: `data.js` makeFunding/makeReferral/coverage/activationBlocks; `app.jsx` funding and verification forms.

Required UI: show Uncosted/Unknown separately from zero; capture original funding units, period, dates, conditions and allocation to this provider; derive service-start readiness for the proposed start date and service lines. Acceptance examples: expired, future-dated, conditional, insufficient, overlapping and income-only arrangements; all must produce the appropriate explicit blocker without inventing an award or rate.

**F05 — Preserve verified decisions when only administrative work changes. Browser and source confirmed.**

Opening Noah's verified funding case and saving without changes removes verification and changes Verified to Decision recorded, even though the amount remains 1,200. All case edits reset verification. Reassessment edits overwrite the same decision; history stores status/reference/evidence text, but not a complete prior amount, conditions, dates or decision version. Evidence: `app.jsx` fundingCase initial values/save and history.

Required UI: separate application tasks and appointments from immutable decision versions. Administrative changes retain verification; material decision changes create an unverified revision with a before/after review. Reassessment becomes a linked attempt, preserving the prior decision and its applicability. Acceptance: change only a follow-up date without losing verified coverage; revise an amount and require fresh verification while retaining the previous decision.

**F06 — Replace the handover acknowledgement with an actual transfer review. Source confirmed.**

The conversion wizard promises identity, consent and requested-support transfer but mostly shows acknowledgement checkboxes. A new person stores name, status, site, services, phone and safe-contact text; it omits other promised fields. Existing-person conversion adds the service immediately to its undifferentiated Services list, although approval to start additional support is separate. Duplicate matching also blocks new records based on name equality, and restricted matches have explanatory text but no review-request journey. Evidence: `app.jsx` convert, Handover and PeopleView.

Required UI: a field-by-field Submitted → Verified → Destination preview, explicit preserve/update decisions for existing people, a generic restricted-match review request and a conversion receipt linking the onboarding work. Show proposed additional services separately from active ones. Acceptance: two people with the same name can be reviewed safely; an inaccessible possible match has a recoverable path; transferred contacts/goals/evidence and ongoing funding tasks can be inspected in the destination preview.

**F07 — Make assignment and site controls reflect the configured access boundary. Source confirmed; handoff UX observed in browser.**

Manual intake and assessment use a hard-coded Central intake/Auckland option list for actors without all-sites access, irrespective of configured approved sites. Assignment offers every sample site and owner without demonstrating that the selected owner can work that record. Central-intake scope bypasses the assignment condition for assigned-only roles. Separately, a coordinator can correctly submit into central intake and then cannot see that record; the receipt needs to explain this handoff clearly rather than widen their access. Evidence: `app.jsx` inScope, intake, assessment and assign.

Required UI: eligible receiving sites, owners and navigators derived from the configured grants; a clear explanation for unavailable assignments; a central-intake team receipt and expected next step when the creator cannot reopen the case. Acceptance: a Waikato-only role never receives Auckland as an unintended editable destination, an inaccessible assignee cannot silently become the sole owner, and assigned-only central-intake access follows the documented scope rule.

### P2 — complete the daily workflows

**F08 — Use one coherent follow-up model across queues, detail and calendar. Source confirmed.**

Primary next actions and separate tasks behave differently. Opening a primary next action from the queue takes the user to Tasks, which lists only separate tasks. The primary action can be marked complete without supplying a replacement or dated hold, leaving an open referral without a live next step. Tasks can be completed but lack ordinary edit/reassign/cancel flows. Case-level funding next actions are stored, yet the funding editor initializes its next action and date from the parent referral; another pathway's work can therefore replace them on save. Evidence: `collections.jsx` WorkCollection; `app.jsx` TaskPanel/fundingCase; `case-design.jsx` next-step checkbox.

Required UI: one Work on follow-up action showing the selected item, owner, due time, outcome and replacement/hold where required; distinct referral and application tasks with synchronized projections. Add edit, reassignment, cancellation reason and safe undo. Acceptance: completing all tasks on an open case cannot silently remove accountability; two funding applications retain different deadlines and next steps.

**F09 — Correct Calendar “Mine” and use one clock and identity. Browser/source confirmed.**

As Funding navigator, “Open my referral follow-ups” opens All records and shows Emma's work. Root navigation never applies a Mine filter; the queue's own Mine filter follows referral owner rather than the selected work item's owner/navigator. The Inertia calendar identity remains Emma while the role preview can represent Sam. The prototype's TODAY and header are fixed to 15 October, while the reused calendar uses the actual browser clock; the audited browser was already 16 October in Pacific/Auckland. Evidence: `app.jsx` navigation/filter/Inertia initialPage; `referral-calendar.jsx`; shared SiteCalendar clock and mine calculation.

Required UI: one explicit demo clock and active staff identity propagated through all projections; Mine filters work-item ownership consistently. Acceptance: the Mine number, clicked queue and actual cards agree for each preview role, including after site/source/date filtering; Today and overdue labels agree across calendar, list and detail.

**F10 — Finish assessment scheduling and appointment outcomes. Source confirmed.**

New calendar entries create follow-ups only. Assessment entries come from a date-only funding field, without time, duration, location, attendees, appointment outcome, cancellation or no-show handling. Past appointments remain overdue until changed; there is no completed-assessment event state. Calendar completion statistics cannot reflect completed tasks because completed items are removed from its event projection. Evidence: `referral-calendar.jsx` event projection/create/detail; `app.jsx` funding preparation.

Required UI: a type-aware scheduling wizard using the existing calendar, with assessment location/remote details, participants and completion/cancellation outcomes. Preserve completed history with display filters. Acceptance: schedule → reschedule → complete/cancel a NASC/host/provider meeting and see the same state in the funding case and calendar; a completed appointment no longer prompts overdue follow-up.

**F11 — Make the proposal reviewable beyond a weekly total. Source confirmed.**

The proposal has one weekly cost, one payer and a private share, without service lines, hours/visits, dates, allocations or proposal versions. The entered rationale is not saved into the proposal. Editing a proposal keeps its prior person/payer-acceptance checkbox checked while clearing internal approval, so acceptance can appear to apply to changed terms without a distinct review. Evidence: `app.jsx` proposal editor/save and Proposal panel.

Required UI: versioned support lines and payer allocations, the person's agreed terms, cost basis and start/review dates; separate internal and person/payer approval records for that version. Acceptance: changing scope, price or payer terms shows the change and requires the relevant renewed acceptance; an old proposal remains inspectable. Rates and financial calculation ownership stay with the canonical finance design.

**F12 — Refine permissions around actual staff responsibilities. Browser/source confirmed.**

Reception can create but cannot log contact or add follow-ups. Edit is also the gate for closing, reopening, duplicate resolution, sending a request and evidence changes. Sensitive combines clinical/assessment and financial visibility. Detailed activity requires audit permission, removing useful operational chronology from coordinators. Reports and person-preview rights have no separate read permissions. Evidence: `data.js` permission presets; `app.jsx` command gate/Activity/PeopleView.

Required UI: demonstrate separate contact, task, transition, close/reopen, disclosure, duplicate, funding-read, report and settings-draft/publish grants using the eventual shared role editor contract. Finance should be able to verify permitted funding without automatic clinical narrative access. Acceptance: each preset can complete its intended job with minimum access; denied actions explain the needed permission without showing sensitive content.

**F13 — Demonstrate the complete information-request and evidence loop. Source confirmed.**

The request preview contains a manually typed recipient and body, with generic simulated delivery. It has no recipient verification, selected-document review, disclosure authority, secure request, expiry/revoke/resend, incoming response or reconciliation step. Evidence supports synthetic viewing/review/quarantine but lacks classification, expiry, replacement/version history and attachment to a particular decision requirement. Evidence: `app.jsx` message/retryMessage/document/reviewDocument.

Required UI: Request information → recipient/purpose/authority review → safe message preview → outstanding request → fictional recipient response → staff review → link accepted evidence to its requirement. Keep all external effects simulated. Acceptance: wrong/withdrawn recipients, expired requests and quarantined evidence are blocked; a response completes the intended requirement while preserving prior evidence.

**F14 — Add correction and authority-change workflows. Source confirmed.**

Main-person name, phone and email cannot be corrected through the assessment editor. Added contacts are append-only in the UI. Authority is free text plus a broad sharing checkbox, without scope, evidence dates, withdrawal/dispute or distinct contact/disclosure/service permissions. The website privacy acknowledgement has no explicit notice-version/delivery record. Evidence: `app.jsx` assessment/addContact and Contacts; `public.jsx` privacy/save.

Required UI: Edit person/contact, preserve an amendment history and show who can see each field; separate authority and contact preferences with validity/withdrawal. Acceptance: correct a mistyped email, change the nominated safe-contact person and withdraw disclosure authority; future requests reflect the change without deleting the historical basis.

**F15 — Complete configurable settings and demonstrate their effects. Source confirmed.**

Current settings cover one extra public question, an optional interpreter field, a receiving-team choice, integer targets, stage labels, preparation strings, basic switches and roles. They do not demonstrate configurable services/regions, conditional form mapping, transition requirements, routing conditions/priority, staff absence/fallback, approval delegation or pathway agency/host/contract metadata. Waiting-case review and notification settings are saved but do not drive a corresponding local task/escalation preview. Notifications and Delivery also repeat the message/channel groups. Evidence: `settings.jsx`, `settings-design.jsx`, configuration usage across the mockup.

Required UI: finish these settings as titled groups using the existing Overview → Review → Configuration pattern, and add a “Try this rule” simulation showing the assigned team, required fields and generated actions for a fictional referral. Separate event recipients/preferences from transport delivery controls. Acceptance: change a rule, preview its consequence, publish it, and observe the change in a new case; an ordinary administrator cannot redefine protected lifecycle semantics.

**F16 — Make configuration history, impact and migration genuinely reviewable. Source confirmed.**

Published history retains version/date/reason, not the full configuration snapshot. It cannot show an old form or restore that configuration. Existing cases carry a version number, but stage choices and pending-funding policy read current configuration. Migration replaces checklist items by matching label strings and loses removed items from the case view. Impact review does not show per-case additions/removals, routing consequences or unreachable required stages. Evidence: `settings.jsx` publish/history/migrate; `app.jsx` lifecycle and convert.

Required UI: immutable published snapshots and stable field/stage/check IDs; scheduled/effective-version indication; before/after impact and case-specific migration previews; rollback as a new version. Keep revoked RBAC and current safety validity live. Acceptance: inspect a v1 case after publishing v2, preview exactly what migration changes, preserve earlier answers/evidence, and demonstrate a reviewed rollback.

**F17 — Finish queue filtering, search and navigation continuity. Source confirmed.**

The list lacks the specified owner/team, service, channel, priority, pathway/status, received-date and hold-reason filters, plus sorting/pagination. Funding and task queues reuse referral-name/owner/phone matching, so displayed task text and case references are not searched. Saved views omit the workspace and have no rename/update flow. Navigation clears search and changes filters; view/route state is local React state, so refresh/back does not reliably restore the current workspace or selection. Evidence: `app.jsx` filtered/navigate/savedViews/find; `collections.jsx`.

Required UI: contextual filters and search over permitted displayed fields, saved views tied to a workspace, reset/result counts and a return-to-queue path preserving position. Add representative pagination/sort controls using existing list patterns. Acceptance: save a Funding awaiting decision view, reopen it, open a record and return without losing the view; a task can be found by its title, and browser back/refresh keep a meaningful destination.

**F18 — Complete minimal public intake and receipt recovery. Browser/source confirmed.**

“Our support” on a receipt calls scrollIntoView on a removed element and throws. A whānau/general callback lacks an explicit route for discussing the process without identifying another person. Safe-message permission, preferred communication method and accessible alternatives are not structured. Drafts exist only in the mounted public view; switching to the staff workspace loses the public draft. The duplicate-retry action only displays explanatory text and does not exercise a submission attempt. Evidence: `public.jsx` fields/receipt/draft/retry.

Required UI: working receipt navigation, minimal callback choice, clearly owned contact fields and an explicit draft lifetime/recovery policy that respects shared devices. Show configured staffed response hours and a fictional contact fallback for storage failure. Acceptance: return from receipt to support choices without errors; resume or explicitly discard a draft; exercise retry after an uncertain simulated save and see one receipt/record.

**F19 — Expand reports to the decisions staff need. Source confirmed.**

Reports are snapshot counts by source, several status totals and case/verified counts by pathway. They lack time to human contact, unassigned age, time in stage/hold, pending funding age, funding gaps, closure/reopen outcomes and separate service-start metrics. No period/cohort definition or drill-through is shown. “On capacity hold” counts all On hold records regardless of reason; verified case counts do not distinguish a verified decline from approved coverage. Evidence: `app.jsx` Reports/export.

Required UI: actionable measures with definitions, period/cohort selectors and permission-scoped drill-through; distinguish conversion from service commencement and successful alternative-provider outcomes from failure. Acceptance: a non-capacity hold is not reported as a capacity waitlist; a decline is not presented as funded success; each total reconciles to its filtered records.

**F20 — Make the history explain who did what and when. Source confirmed.**

Most referral activity and conversion entries use the hard-coded timestamp “15 Oct 2026, 10:30 am”, while the general audit uses actual time. Contact notes do not capture actual contact time or distinct attempt/success timing. The role label is frequently the actor rather than the named staff identity. Evidence: `app.jsx` update/contact/convert; `data.js` initial activity.

Required UI: one demo clock, named actor, occurrence versus recording time and concise categories; keep routine coordination activity separate from privileged audit detail. Acceptance: two contacts at different times remain distinguishable, and staff can identify the last successful human contact without opening every note. Do not put restricted narratives in generic event summaries.

**F21 — Reduce phone chrome and prioritize the current action. Visual evidence.**

At 390×844 the referral detail's content starts roughly 550px down. On the calendar, the shell and expanded five-meter hero fill the entire first screen before any calendar dates or events appear. The large prototype toolbar adds to this. Referral details emphasize Review handover even when safe first contact is the immediate job. A transient toast overlaps the public wizard's lower controls. Evidence: `referrals-review-case-phone.png`, `referrals-review-calendar-phone.png`, `referrals-gap-public-phone.png`.

Required UI: use the shared PageHeader mobile-summary/expand pattern for the calendar, collapse prototype-only controls, retain the date anchor/view choice and one context-appropriate action, and move secondary actions into the existing overflow pattern. Position notices away from sticky wizard actions. Acceptance: at 390×844, a meaningful event/task or next action is visible without scrolling past metrics; save/cancel controls remain visible and operable when a toast and phone keyboard are present. Preserve Rory's shared components and tokens rather than shrinking text or adding blanket size overrides.

### P1 — remaining settings design safeguard

**F22 — Flag every safety/access loosening in settings review. Source confirmed.**

The destructive-publication check detects enabling pending-funded onboarding and adding permission booleans only. Expanding approved sites, broadening ownership scope, removing preparation requirements or reducing the sample retention period does not enter that review branch. DESIGN.md requires safety-loosening changes to be flagged with destructive save treatment. Evidence: `settings.jsx` loosensPolicy and scope/checklist/retention editors.

Required UI: classify the actual changed policies, explain who or what becomes newly accessible/less constrained, and require the relevant review. Retention and other governed changes need the designated authority rather than an ordinary workflow editor. Acceptance: widen a coordinator's scope or remove a required check and see a specific impact warning and the appropriate publication treatment; harmless label edits remain ordinary saves.

### Recommended correction order

1. Information and lifecycle integrity: F01–F07 and F22. Reproduce each failure first, then verify the changed behaviour across the relevant roles.
2. Complete funding, contacts and ongoing work: F08–F14 and F20, including conditional/expired funding, reassessment, whānau intake, private payers and existing-person additional support.
3. Finish configurable behaviour and oversight: F15–F19. Demonstrate settings effects on new and pinned existing cases, with scoped reports and recovery.
4. Apply the phone composition improvements in F21 throughout the affected work, followed by a fresh desktop/phone and keyboard review. Reusing a component does not itself validate its composition on the page.

### Production work kept separate

Real server-side authorization, durable storage, concurrent/idempotent conversion, canonical Client/onboarding/agreement integration, secure uploads/scanning, communications delivery, background escalation, retention execution and verified provider/agency configuration remain production work. Their absence is not counted as a defect in this local synthetic prototype. Their user-facing review, blocked, failure and recovery journeys still need adequate mockup coverage where specified above. Direct agency integrations, telephony ingestion/transcription and a referrer portal remain separate future scope, not blockers invented by this audit.

## Correction and re-audit — 16 October 2026

**Outcome: all 22 findings have an implemented mockup correction; the targeted regression checks below pass.** This closes the identified prototype defects, not the complete production specification. The historical review above remains intact so the reasons and acceptance expectations are traceable.

The user additionally requested a better pipeline presentation after seeing compressed six-column cards. The pipeline now has 320px lanes, full next-action text, readable identity/ownership, stage totals, desktop scroll controls and direct stage navigation. Phones show one selected stage with full-width cards and a compact scrolling stage rail. Repeated stage chips and reassuring green badges were removed from pipeline cards; overdue work remains explicit. Shared EntityCard, Button, PageHeader, semantic tokens and focus/touch primitives are retained. Stage changes still use the reviewed workflow rather than bypassing checks through dragging.

### Finding closure map

| Finding | Implemented correction | Verification |
|---|---|---|
| F01 | Distinct person, referrer and safe-recipient fields; submitted answers, organisation, funding position and provenance retained in staff intake review. | Mobile professional submission through staff review; separate phone values and all answers asserted. |
| F02 | Restricted narratives, financial fields, evidence and information-request purpose follow their granular grants. | Finance/reception browser checks; final review additionally removed request-purpose exposure to reception. |
| F03 | Reasoned close/reopen history; closed conversion blocked; converted records lead to their canonical person. | Close → blocked handover → reasoned reopen browser journey and lifecycle model checks. |
| F04 | Structured decision dates, units, service/provider scope, conditions, allocation and overlap checks; unknown money stays unknown. | Model cases for expired/future/conditional/withdrawn/income/wrong-scope/overallocated funding; proposal allocation journey. |
| F05 | Material decision versions retained; administrative saves preserve verification; linked reassessments preserve the original award. | Browser unchanged-save/material-revision checks and decision comparison tests. |
| F06 | Field-by-field transfer review and receipt; identity review/manager match review; proposed services remain separate from active services. | Versioned proposal → independent approval → reviewed handover → separate service commencement browser journey. |
| F07 | Eligible receiving owners, approved-site/ownership checks, central-intake assignment and out-of-scope receipt handling. | Scope/absence/routing model checks; source review of assignment and canonical-person access. |
| F08 | One follow-up model for primary actions, tasks and funding cases; edit/complete/cancel/reopen, outcomes and undo; independent case dates. | Browser continuity/undo and model deadline independence checks. |
| F09 | Calendar Mine uses the current staff identity and the same visible source/date/site filters as calendar entries. | Navigator Mine browser check; shared-calendar regression tests. |
| F10 | Structured assessment appointments with dates, time, participants, state, outcome and history, shared with calendar. | Schedule → complete → calendar browser journey. |
| F11 | Versioned proposal service lines, quantities, units, rates, payer allocations, acceptance evidence and independent approval. | Full proposal/handover browser journey and allocation model cases. |
| F12 | Separate clinical, financial-read, contact, follow-up, lifecycle, settings and governance permissions; routine history remains available to permitted staff. | Role preset model checks; reception and finance browser journeys; ordinary settings administrator publication denial. |
| F13 | Request recipient/authority/expiry, simulated delivery and response, staff evidence review, versioned checklist linkage, supersession and quarantine. | Request → response → review browser test verifies linked requirement ID, evidence version and completion. |
| F14 | Person/contact correction, structured contact/disclosure/service authority, withdrawal/dispute, notice and review history. | Withdrawal blocks disclosure and preserves the record; browser and model checks. |
| F15 | Configurable public questions, ordered routing, service/region catalogue, availability fallback, closed dates, stage rules, approval delegation, pathway guidance and notification previews. | Published form effect, routing/notification simulation, governance browser checks; config/model validation and source review. |
| F16 | Immutable configuration snapshots, effective timestamps, pinned case policy, inspect/restore as a new version, selected-case migration previews and archived requirements. | Publish → pinned old cases → restore browser journey; migration explicitly checks additions, preserved completion, archived IDs and unchanged award. |
| F17 | Contextual filters/search, workspace saved views with rename/update, paging, history/refresh and return-to-queue continuity. | Funding saved-view browser journey. Final pipeline test found and fixed a stale navigation callback affecting card-title links. |
| F18 | Working public navigation/CTAs; minimal general callback; safe communication fields; explicit session draft expiry/resume/discard; refreshable receipt and real local idempotent retry. | Four public browser groups at 360/390px, including receipt reload/retry and all public information/CTA buttons. |
| F19 | Received-date cohorts, measure definitions and scoped drill-through; contact delay, unassigned age, hold reasons, funding age/gaps, closure/reopen and separate service-start measures. | Report total reconciles to eight matching records; routing/report browser group and source review of measure predicates. |
| F20 | Named actor and actual recorded timestamps; separate contact occurrence time; last successful human contact; operational/configuration audit separation. | Contact browser journey and source review of update/contact/migration/receipt histories. |
| F21 | Shared compact mobile header, collapsed prototype controls, contextual record action, non-overlapping wizard notices, readable phone agenda and focused pipeline stage. | Desktop/phone screenshots and 11-state automated accessibility/overflow scan; phone next action and calendar entry visually inspected. |
| F22 | Specific warnings for permission/site/ownership expansion, weaker stage/checklist/form safeguards, retention and approval changes; governance grant required to loosen policy. | Seven policy-warning model cases; browser retention warning and denied publication for ordinary settings administrator. |

### Three correction/review cycles

1. **Information and workflow integrity:** rebuilt intake/contact separation, lifecycle, structured funding/proposals, canonical handover, scope checks, follow-up continuity and evidence workflows. Browser journeys exposed incorrect verification reset, incomplete next-action continuity and missing information-transfer detail; the implemented checks now pass.
2. **Settings and operational use:** added effective configuration snapshots, restore/migration previews, governed publication, configurable forms/routing/gates, contextual views and defined reports. Checks exercised real publication effects, old-case pinning, migration evidence preservation, saved-view recovery and reporting reconciliation. Reset now clears advanced filters as well as basic filters.
3. **Visual, mobile and final regression review:** revised the compressed pipeline, then reduced phone stage-selector height and corrected clipped phone calendar titles. Further review fixed restricted information-request purpose exposure and stale card-link return navigation. Public receipt recovery was exercised after a real refresh. Automated scans supplemented screenshot inspection; they were not used as a substitute for visual or workflow review.

### Final verification evidence

- `qa/model.test.js`: **32 passing tests**, covering funding validity/coverage/allocation, lifecycle, approved sites/ownership, independent follow-ups, dates, pinned policy, safeguards and role presets.
- Shared calendar SSR/protected-press regression: **10 passing tests** across two files. Adapter extensions are opt-in.
- Browser callbacks: `revision-public` (4 groups), `revision-workflows` (6), `revision-handover-settings` (3), `revision-governance` (2), `revision-operations` (3), plus `revision-pipeline` (desktop/phone board navigation, card open/return and readable focused stage). All final results passed with no JavaScript page errors.
- `revision-visual`: **11 screen states**, zero automated WCAG A/AA violations and no horizontal page overflow. Screens cover pipeline, calendar, settings, reports, evidence, referral overview and public landing/form, including 360px and 390px widths. Calendar desktop retains Month/Week/Day/Agenda/Timeline; phone defaults to Agenda while keeping other views available.
- Screenshots: `output/playwright/referrals-final-*.png`; machine-readable final browser results: `output/playwright/referrals-final-results.json`.
- Standalone build and prototype undefined-variable lint passed. The approximately 1 MB JavaScript bundle still produces Vite's bundle-size advisory; this is a standalone review build.
- QA uses the separate `127.0.0.1:5186` browser origin. The reviewer's `localhost:5186` examples were not reset.

### Remaining production boundaries

Server-enforced authorization and privacy, durable/concurrent storage, real communications and jobs, secure uploads/scanning, canonical production onboarding/service records, verified agency/provider contracts and authoritative funding rules are still implementation work. Local role previews and fictional financial values are not evidence of production security or eligibility. Configuration activation is immediate in this preview; future scheduled activation is not simulated. Automated accessibility results are targeted coverage, not certification.

## Pipeline refinement after further user feedback — 16 October 2026

The user requested another improvement to the pipeline. Replaced fixed-width, partially clipped lanes with complete columns sized to the available board width: three at the reviewed 1440px desktop size and two at 1024px. Stage totals highlight the visible range, navigation disables at the beginning/end, and keyboard arrows work when the board itself is focused. The selected stage is retained in session storage when opening and returning from a record.

Removed the tall lane containers and tightened the stage headings. Canonical EntityCards now have a distinct next-step area, concise due-today/overdue labels and a simpler owner footer. Phones use a labelled stage picker with previous/next controls, leaving more room for the cards. Both the kebab and right-click menus now use the same action definition, including permission-gated stage review.

Verification: `qa/pipeline-polish.js` passed six interaction/layout groups (whole desktop columns, navigation/return, whole tablet columns, phone stage controls, matching menus and role restrictions). Four accessibility/overflow scans passed at 360px, 390px, desktop light and desktop dark, with no page errors. Visual inspection found a dark-theme contrast problem in the selected stage captions; this was corrected using the existing accent foreground token and rescanned successfully. The build and focused prototype lint pass. The existing standalone bundle-size advisory remains.

Evidence: `output/playwright/referrals-polished-desktop.png`, `referrals-polished-funding.png`, `referrals-polished-tablet.png`, `referrals-polished-phone.png`, `referrals-polished-dark.png`, and `referrals-pipeline-polish-results.json`. QA stayed on the separate `127.0.0.1:5186` origin and did not reset the reviewer's localhost data. No business workflow, permission grant, production route or design-rule changes were made in this refinement.

## Standalone reports and referral usability review — 16 October 2026

Reports is now a dedicated sidebar page with its own header and no tab rail. It has independent received-date, site and search filters, period presets, custom-date validation, empty-state recovery and permission-gated export. Report filters survive drill-through, return and refresh; queue searches no longer silently restrict report totals. Measures are grouped into intake/follow-up, funding/access and referral outcomes. Received dates use the shared New Zealand date conversion.

The shared header remains the source of the visual rules. Desktop actions now use compact sizing, while phones retain touch targets. Browser measurements across Referrals, Funding assistance, Follow-ups, Calendar, Reports, Settings and Supported people confirm 48px circular marks with 17.5px rendered module glyphs. Person profiles use initials in the canonical ring. Profile meters navigate to their relevant sections rather than opening edit dialogs. Expanded phone totals use two columns after visual review found that four narrow columns broke labels into fragments.

Cards now share the pipeline's readable next-step area and simpler owner footer; overdue work receives emphasis without a green badge on every other record. Stage review is available consistently through permitted card/table actions. Overview follow-ups use the canonical active-work list and reviewed completion flow, requiring an outcome instead of bypassing the workflow through a checkbox. Global search opens the scoped referral finder, including Ctrl/Cmd+K, without changing queue or report filters.

Verification: `qa/reports-page-review.js` passed standalone navigation, independent filters, site reconciliation, drill-through/return/refresh, empty and invalid-date handling, report permission denial, profile initials, meter navigation and required follow-up outcomes. Seven final accessibility/overflow scans passed with zero automated WCAG A/AA violations, no horizontal page overflow and no JavaScript page errors: desktop reports, custom dates, cards, person overview, phone reports, expanded phone filters and dark reports. The expanded phone test also checks readable meter widths. Visual review caught a low-contrast keyboard hint; its token was corrected before the passing scans. `qa/global-search-review.js` passed search, keyboard shortcut and report-filter independence, with no automated accessibility violations.

Evidence: `output/playwright/referrals-reports-review-results.json`, `referrals-global-search-review-results.json`, and `referrals-reports-*.png`. The standalone build and prototype undefined-variable lint pass; the known bundle-size advisory remains. Tests used the isolated `127.0.0.1` origin. Localhost examples were preserved. These are mockup checks, with the production boundaries above unchanged.

The existing `revision-operations.js` callback was also rerun with the standalone report meter selector: all three groups passed (saved funding views and refresh, settings safeguard denial, routing/notification previews and report reconciliation), with no page errors. Results are in `output/playwright/referrals-reports-operations-results.json`. The localhost HTML was checked against the final build assets.

## Fleet-style reporting overview and hero consolidation — 16 October 2026

Compared `resources/js/pages/fleet-assets/reports/index.tsx` and its shared chart/card components with the Referrals report. Reports remains a sidebar page; it now contains the user-requested Overview and Detailed reports views. Overview uses a two-series intake/contact line graph, the shared Fleet half-moon gauge, the shared donut, clickable stage/source/coordinator distributions and attention measures. Detailed reports combines site/channel charts with compact measure groups. Definitions and matching records remain available through drill-through.

The report period and eight headline measures now live in the shared hero meters, in four desktop columns and two narrow-screen columns. The duplicate KPI row and period caption below the hero were removed. Both report views share filters, and the selected view persists on refresh. Phone header details remain expandable. Charts use semantic chart tokens, actual scoped fictional records, New Zealand received dates and zero-value periods. The line series groups by received date and shows current successful-contact status; it does not claim to plot historical contact throughput. Exact chart data is available in an expandable table. Custom stages present in records are included in the stage chart. Funding values remain permission-gated and unknown costs are labelled.

Verification: `qa/reports-dashboard.js` passed three interaction groups covering two rendered line series, trend totals reconciling to eight records, source/stage/funding drill-through, shared filters, selected-view persistence and permission denial. It also verifies eight hero meters, no duplicated KPI row and readable expanded phone meter widths. Six final accessibility/overflow scans passed with no automated WCAG A/AA violations or horizontal page overflow: desktop Overview and Detailed reports, phone Overview and Detailed reports, expanded phone hero, and dark Overview. No JavaScript page errors were recorded. Screenshots were inspected; build and focused prototype lint passed. Evidence: `output/playwright/referrals-dashboard-results.json` and `referrals-dashboard-*.png`. QA used the isolated 127.0.0.1 origin and preserved localhost data. The standalone build retains its bundle-size advisory.

## Whole-prototype audit requested by the user — 16 October 2026

This is an audit-only pass of the current source and running standalone build. It supersedes any implication that closing the earlier 22 findings established completeness. No application implementation was changed. The review covers website intake, records, funding, follow-ups/calendar, handover/service start, reports, settings, permissions and responsive UI. Tests use the separate `http://127.0.0.1:5186/` origin, preserving the user's `localhost` examples. The served assets were checked against the current build (`index-B90Z1WTO.js`, `index-CUH8NpFx.css`).

### Confirmed defects

Priority 1 means address before accepting the mockup's workflow/permission behaviour; priority 2 means address before completing the mockup. “Browser” findings were reproduced through the UI, with explicitly synthetic setup where noted. “Source” findings are confirmed code paths, not claims of additional browser coverage.

| ID | Priority | Finding and effect | Evidence / recommended correction |
|---|---|---|---|
| A01 | 1 | Follow-up permission can change the referral owner without Assign referrals permission. | **Browser:** Receptionist changed Maia from Intake team to Emma Taylor in Work on next action. `workflows.jsx` checks `tasks` but writes `r.owner`. Gate assignment separately and distinguish task ownership from referral ownership. |
| A02 | 1 | Cancelling the primary follow-up changes the referral to On hold and invents the reason “Person requested time.” | **Browser:** Receptionist cancellation with an explicit duplicate-task explanation still changed New to On hold. `workflows.jsx` writes the stage and reason without transition permission. Keep cancellation separate from an explicit, authorised lifecycle change; undo must restore all changed fields. |
| A03 | 1 | Reopening bypasses the destination stage's requirements. | **Browser:** Maia reopened directly into Ready for handover with both identity and consent false. `handover-workflows.jsx` skips `stageIssue` when closed. Apply the target-stage gates to reopening too. This does not establish that the separate conversion gate is bypassed. |
| A04 | 1 | Correcting a referrer can lose their email or put an email into the phone field. | **Source:** `record-workflows.jsx`, contactEdit, starts from separate phone/email values but edits `contact`, then saves `phone: v.contact, email: ''`. Preserve independently editable phone/email fields and initialise them from the record. |
| A05 | 1 | Browser Back discards unpublished settings without the in-app navigation warning. | **Browser:** edited Additional public question, pressed browser Back, returned to Settings: old value restored and no warning. `app.jsx` popstate bypasses the dirty-settings guard and clears open modals. Guard history navigation for settings and unfinished wizards. |
| A06 | 2 | Primary and funding follow-up time fields are not persisted. | **Browser:** saved 09:00 AM on the primary next action; reopening showed Choose time. **Source:** funding saves also omit time, and `followupsFor` omits time for both kinds. Persist the time and carry it into the calendar adapter. Ordinary appointments are a separate working flow. |
| A07 | 2 | Cancelled funding follow-ups are later labelled Completed. | **Source:** `workflows.jsx` stores only `nextDone` for funding work; `model.js` maps true to Completed. Store cancellation, completion and reopening as distinct outcomes. |
| A08 | 2 | The displayed default hold reason is not saved. | **Browser:** Hold reason displayed Capacity; saving without changing it stored no hold reason. `handover-workflows.jsx` initialises `category` but saves `holdCategory`. Initialise and require the correct field; this also affects capacity-waitlist reporting. |
| A09 | 2 | Retiring a custom stage can make existing referrals disappear from the pipeline. | **Browser with a synthetic old-stage fixture:** the referral remained in Table but was absent in Pipeline. `pipeline.jsx` only builds lanes from current settings, while old cases can remain pinned and custom stages can be removed. Include occupied historical stages or require a reviewed migration before retirement. |
| A10 | 2 | The “more than one day” unassigned alert actually starts at 48 hours. | **Browser:** an otherwise eligible 36-hour-old central-intake referral yielded zero. `reporting.jsx` floors elapsed days and compares `> 1`, despite describing a 24-hour threshold. Compare elapsed hours directly. |
| A11 | 2 | Staff/phone intake ignores configured closed dates when assigning its first-contact deadline. | **Source:** `app.jsx` calls `businessDue`, whose implementation in `data.js` skips only weekends; website intake uses `businessDate(..., config)`, which also skips configured holidays. Use one configured calendar calculation. |
| A12 | 2 | New service regions cannot be fully configured for staff access and ownership. | **Source:** regions are editable in `settings-advanced.jsx`, but `ScopeEditor` hard-codes Auckland/Waikato and `eligibleOwners` uses a fixed staff directory. Connect approved sites, role scope and eligible staff to the same configured source, keeping organisation/site boundaries explicit. |
| A13 | 2 | “Funding-help guidance on receipt” is an ineffective setting. | **Source:** the acknowledgement flag can be edited and published in `settings.jsx`, but `public.jsx` never reads it. Implement the promised optional guidance or remove the switch. Keep the mandatory receipt and reference independent. |
| A14 | 2 | Website intake permits Phone as the preferred channel with only an email supplied. | **Source:** public step validation checks Email requires email, but has no equivalent Phone requires phone check. Require a usable contact method matching the selected preference. |

The seven groups in `qa/current-audit-edge-cases.js` reproduced A01/A02 together, A03, A05, A06, A08, A09 and A10, with no JavaScript page errors. The old-stage and 36-hour cases use fixtures on the isolated origin; they do not alter the user's examples. A04, A07 and A11–A14 are source-confirmed findings.

### UI/UX improvements, distinct from defects

1. **Separate current operational workload from received-period reporting.** Needs attention and coordinator workload currently use the received cohort. Older unresolved referrals are excluded by design, even when still overdue. Add an explicit All current open work view or stronger scope labels so managers can distinguish a cohort report from their whole backlog.
2. **Export the report being viewed.** The current report export contains only reference, person, stage, source, owner and due date. Add report period/site context, measure values and appropriately permission-filtered funding totals; retain raw referral export as a separate choice.
3. **Make long settings pages easier to navigate and finish on phones.** Preserve the canonical overview/configuration structure, but add section anchors and an accessible bottom review action so users do not repeatedly scroll back to the hero after editing lower sections. This is a discoverability improvement, not a failed accessibility scan.
4. **Provide a richer fictional reporting scenario.** Current defaults cluster received dates and contain no successful contact or conversion history. Add a selectable, clearly fictional scenario spanning multiple weeks, outcomes and funding states so the line graphs and handover metrics can be meaningfully reviewed. Do not replace measured values with decorative trends.

### Verification and boundaries

- Pure model suite: **32/32 passed** in this audit.
- `revision-workflows.js`: **6/6 groups passed**, covering funding decision preservation/revision, lifecycle continuity, follow-up replacement/undo, appointments/calendar, information request/evidence and finance/navigator scope; no page errors.
- `revision-handover-settings.js`: **3/3 groups passed**, covering independent proposal approval, conversion and separate service start, publication/snapshots/pinned versions/restoration, and reception privacy boundaries; no page errors.
- The current Reports build already passed the six screen-state scans and interaction checks recorded immediately above. Browser edge-case findings explain why successful normal journeys do not establish complete permission or recovery behaviour.
- `revision-public.js`: **4/4 groups passed**, covering mobile professional intake and safe-recipient separation, receipt refresh/retry and staff handoff, 360px minimal callback, draft recovery/discard and all tested public information links/entry actions on a phone; no page errors. A stale public-test assumption that staff view was always Table was corrected in the QA callback; it was not an application defect.
- Visual review used the current Reports screenshots and the existing settings/mobile evidence. The earlier 11-state general accessibility/overflow scan was not repeated in this audit; its results remain historical evidence, not a fresh full-screen certification. Current phone website interactions and the current Reports checks above provide the latest browser coverage.
- Server-enforced permissions, durable/concurrent data, real uploads and communications, live notifications/escalations, production supported-person integration, and authoritative funding/contract validation remain **production implementation boundaries**, not newly discovered mockup defects. Automated accessibility coverage is not a complete accessibility certification.

## Closure of all 18 findings — 16 October 2026

All 14 defects and four UI/UX improvements above are addressed in the standalone mockup. This closure supersedes their open status, while retaining the original findings as evidence. Rory's shared header, navigation, cards/tables, calendar, wizard and settings review patterns are retained. This revision did not change production shared components. All browser fixtures and resets used the isolated `127.0.0.1` origin; the reviewer's `localhost` data was preserved.

| ID | Implemented correction | Verification |
|---|---|---|
| A01 | Referral assignment has its own permission guard; working on a follow-up cannot silently change the referral owner. | Restricted-role browser journey and save guard review. |
| A02 | Cancelling a primary follow-up preserves the referral stage and hold reason, with replacement work and undo handled separately. | Cancellation/replacement/undo browser journeys. |
| A03 | Reopening applies destination-stage requirements, including Ready for handover checks. | Browser rejection and model regression. |
| A04 | Referrer phone and email are independently editable and preserved, separate from the person's contact details. | Edit/save/reopen browser check. |
| A05 | Browser Back/Forward guards unpublished settings and unfinished staff/public wizards, with Keep editing or explicit discard. | Browser history journeys. Re-audit also caught and repaired a same-URL Inertia replacement that could close the guarded wizard. Temporary diagnostic logging was removed. |
| A06 | Primary and funding follow-up times persist and reach the calendar adapter. | Save/reopen browser checks and model mapping regression. |
| A07 | Funding follow-ups preserve distinct Cancelled, Completed and Open outcomes. | Browser cancellation/reopening and model checks. |
| A08 | Hold category is initialised, required and saved consistently; leaving hold clears its category. | Hold-stage browser check and capacity reporting. |
| A09 | Pipeline includes occupied historical stages after their retirement from current settings. | Historical-stage browser fixture. |
| A10 | Unassigned alert uses elapsed time greater than 24 hours. | 36-hour fixture and threshold review. |
| A11 | Staff/phone first-contact deadlines use configured business dates and closed days. | Published closed-date phone-intake browser check. |
| A12 | Configured regions, role site scope, staff assignment sites, routing and eligible owners use compatible sources. | Added Wellington, configured access/staff/routing, published and created a referral there; model regression. |
| A13 | Receipt funding guidance responds to its setting and links to funding assistance; mandatory receipt/reference remain present. | Public receipt setting and link browser checks. |
| A14 | Phone/Text preferences require a phone number; Email requires email. | Mobile public validation and successful alternative submission. |
| A15 | Reports has a Current workload tab covering all permitted open work independently of received dates, with site/search filtering and explicit scope. | Older-backlog browser fixture, report interaction checks and desktop/phone/dark scans. |
| A16 | Export preview separates report measures from raw rows; summary CSV includes view, period/scope, site, search and permitted measures. | Downloaded scoped CSV inspection, CSV escaping model test and restricted-role export inspection confirming financial measures are omitted. |
| A17 | Long settings pages have a section jump selector and improved existing bottom review bar, including publication state and Back to top. | Phone jump/focus/publication journey and overflow/accessibility scan. |
| A18 | Explicitly selectable fictional reporting scenario provides 32 records across six weeks with varied contact, funding, conversion and service-start outcomes. | Scenario load, chart reconciliation, refresh persistence and reset to original eight records. |

### Re-audit results

- **37/37 model tests passed.** Prototype lint and standalone build passed. The build retains its existing bundle-size advisory.
- **33 browser journey groups passed:** `fixes-workflows` (6), `fixes-settings-reports` (5), `revision-public` (8), `revision-workflows` (6), `revision-handover-settings` (3), `revision-governance` (2), and `reports-dashboard` (3). No JavaScript page errors were reported in these final runs.
- **Additional export privacy check:** a role allowed reports/export but denied funding visibility received a report preview and downloaded CSV without financial measures. The CSV was inspected directly.
- **20 screen-state scans passed:** 11 general states from `revision-visual` and nine report states from `reports-dashboard`, including 360px mobile, expanded phone hero and dark report views. Final scans reported no automated WCAG A/AA violations or horizontal page overflow. An initial phone-calendar contrast warning did not recur after fonts/layout settled; no calendar palette change is claimed from that transient result. Automated scans are not a complete accessibility certification.
- Website regression covered professional referral, quick enquiry, funding help and minimal callback; phone validation, all tested public information links and entry actions, receipt guidance, refresh/retry, saved draft resume/discard, browser-history protection and staff handoff passed. No external submissions or messages were sent.
- Funding decision/revision, independent approval, conversion, separate service commencement, evidence sharing restrictions, settings version/restoration and role/site/privacy journeys were rechecked.
- Final served assets match the completed build: `index-D_qxJSD7.js` and `index-894aG0S4.css`. The local preview process was restarted after it stopped, and the user's `http://localhost:5186/` URL was verified to serve these assets.

No remaining failure was found in this final checked scope. Production implementation boundaries documented above and in the README still apply; this is mockup closure, not production security or funding-policy sign-off. Screenshots and downloaded CSV evidence are under `output/playwright/`; callback source is under `docs/referrals/mockup/qa/`.

## Website-to-supported-living continuity review — 16 October 2026

The user requested another review of the complete journey. This pass used a newly submitted fictional website enquiry, rather than treating separate prefilled examples as proof of the connections. It found additional continuity gaps despite the earlier passing component journeys. Changes are confined to the mockup and its QA/documentation; no production shared UI components were changed in this pass.

### Journey exercised

1. On a 390px phone, submit **Help with funding**, including safe contact, goals and privacy acknowledgement; obtain the receipt.
2. Open that same referral in the staff workspace. Confirm configured Auckland routing assigns Emma Taylor. Record a successful phone conversation and the next action.
3. Review identity, authority, provider choice and suitability, retaining assessment evidence. Open DSS / NASC assistance, assign an eligible navigator, complete preparation and record a fictional external submission and follow-up.
4. Create the supported-person onboarding record while funding is pending, explicitly acknowledging the policy boundary. Confirm service commencement is blocked, transferred goals/identity are present, and the same outstanding work remains searchable in Funding assistance and Follow-ups.
5. Record a fictional external decision with reference, evidence, units, provider allocation, service, dates and conditions. Switch to Finance reviewer to verify the award.
6. Build a costed proposal with funding allocated to the support line and person/payer acceptance. Record independent internal approval. Review the service agreement and safe-start evidence for that version.
7. Schedule a future start and verify the person remains in Onboarding, with no active service or commenced count. Then explicitly record an agreed earlier actual commencement, retaining the earlier schedule history, the approval and the safe-start handover.
8. Refresh and inspect the linked active supported person and transfer receipt. Existing-person linking was separately checked with Eli: no duplicate person or removal/downgrade of existing active services.

All agency references, support prices and dates used in these checks are fictional test inputs, not statements of eligibility or actual purchasing rates.

### Additional corrections

| Area | Correction |
|---|---|
| Unknown cost | Funding assistance now displays **Not yet costed** instead of a misleading green zero funding gap. |
| Opening funding assistance | Retains the person's requested assistance, restricts navigator choice to eligible staff, uses the configured business calendar and carries the requested service into the case. Website/staff-created funding cases use compatible defaults. |
| First contact | Phone contact initially selects the safe phone rather than the email; the primary action stops asking for first contact once a successful conversation is recorded. |
| Lifecycle history | Ordinary stage changes no longer receive a fabricated closure category. |
| Onboarding transfer | Preferred name, birth date, stated general goals, channel, message restrictions and communication needs transfer with identity/contact details. General custom answers retain their source labels; restricted answers remain on the access-controlled source referral. Existing-record preservation remains the default. |
| Work continuity | Converted referrals with outstanding work remain eligible for the default operational Follow-ups view and overdue filtering. Conversion still removes them from open intake. Queue assertions search the person explicitly to avoid mistaking pagination for absence. |
| Assessment evidence | Saves dated, attributed assessment/safe-start reviews and their check snapshots, viewable only with assessment permission. New review narratives are not placed in the general activity/configuration audit text. |
| Revised proposal | A new proposal version requires renewed service-agreement and safe-start review as well as internal approval and person/payer acceptance. |
| Multiple support lines | Valid funding for each proposed service contributes to coverage; unrelated service awards do not. Per-line allocation and provider limits still apply. |
| Scheduled versus commenced | Future approval records Scheduled, keeps a new person Onboarding and creates a dated commencement follow-up. Explicit actual commencement records Started and completes that follow-up; existing active services and the original person's commencement date are preserved. |
| Start evidence | Retains the approver, timestamp, agreed date, proposal version, funding references, safe-start note and prior start decisions, with the current receipt on both the referral and linked person. |
| Handover presentation | Completed handovers show the linked person and direct navigation; transferred fields use readable labels in an expandable receipt instead of raw field names. |

### Verification and limits

- **42/42 model tests passed**, including general/restricted transfer separation, converted-work visibility, scheduling versus commencement, existing service preservation and multi-service coverage.
- Fresh-person journey: `continuity-intake.js`, `continuity-handover.js`, `continuity-decision.js` passed as three consecutive phases against the same newly entered record, with no injected workflow state. The final successful journey used `REF-418302e6` / `SP-60d3207e` in the isolated QA profile.
- **20 additional browser groups passed:** existing-person linking (1), all public/mobile entry and recovery journeys (8), workflow/funding/evidence/role regressions (6), migration/withdrawn-authority governance (2), and proposal/handover/settings/privacy regressions (3). The proposal regression now explicitly rechecks the agreement and safe-start after revision.
- **Four updated screen states passed** automated accessibility and overflow checks: desktop handover, phone person record, phone handover with transfer details expanded, and phone assessment history. No page errors were reported. Screenshots were inspected; automated scans do not establish complete accessibility certification.
- Focused lint and standalone build passed. Final browser asset: `index-Cu-bCJvE.js`; CSS: `index-B28QdjLy.css`. The existing bundle-size advisory remains. Evidence screenshots: `output/playwright/referrals-continuity-*.png`.
- No unresolved blocker was found in the exercised website-to-supported-living journey. This is still a local fictional mockup: authenticated server policies, durable evidence/consent storage, real communications, external agency submissions/decisions and actual supported-person system integration remain production work described in the specification. No external messages or applications were sent, and the reviewer's localhost data was preserved.
