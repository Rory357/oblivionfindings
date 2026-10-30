# Referrals and funding assistance — product specification

Date: 15 October 2026 (New Zealand)  
Status: Research/specification and clickable mockups approved. Main-application implementation started on 16 October 2026 under the user's implementation goal. The isolated prototype remains available in [mockup/](mockup/README.md). Implementation is in progress; prototype verification does not establish production readiness.  
Product: Oblivion Care, in the Oblivion Findings repository.  
Delivery sequence: Research and specification → user approval → fully featured mockups → review of mockups and agreement on implementation.

## 1. What the user is asking for

Create a dedicated **Referrals** area in Oblivion Care. The existing public website will become the website of a supported living company. People, whānau, professionals and agencies can enquire or make a referral there. Staff can also record referrals received by phone, email, in person or from another provider.

Every referral becomes an owned piece of work: someone reviews it, contacts the right people, follows up missing information, helps arrange funding where needed, assesses whether the company can provide the requested support, and prepares the handover into care. When appropriate, the information flows into the existing supported person's record and onboarding workflow without repeated data entry.

The confirmed scope includes **helping people obtain funding**, not simply recording funding they already have. It covers DSS/NASC, EGL, Individualised Funding, ACC, private payment and configurable other pathways. The process, forms, responsibilities and requirements must be configurable through settings.

This is a single-tenant application for one organisation across multiple sites. Authorization uses permissions, approved sites, assignment, canonical record ownership and privacy restrictions. Existing legacy organisation fields remain compatibility details. See [the application boundary](../architecture/single-tenant-application.md).

### Product principles

- An enquiry can be accepted before funding, NHI, a full assessment or a placement is known.
- Each open case has an accountable team, a next action and a due date; after triage it also has a named owner.
- The person chooses who participates and whether to proceed with this provider.
- Referral progress, funding decisions and permission to start services are separate.
- Staff record external decisions with evidence; the application does not decide government eligibility or award funding.
- Conversion creates or links the existing `Client` record. There is no second supported-person database.
- Funding assistance can continue after handover. Conversion does not silently complete outstanding work.
- Settings can adapt the workflow, but cannot remove authorization, audit, financial integrity or applicable legal safeguards.

## 2. Research findings and their implications

Research uses the checked-out application source and public primary sources accessed on 15 October 2026. Vendor documentation demonstrates product patterns; it is not independent proof of product performance or New Zealand compliance. No vendor account, live referral records, production database or agency integration was inspected.

### Market patterns worth adopting

**Care Control, UK:** its CRM combines calls, website enquiries and referrals with prospect details, notes, visits and follow-ups through admission. Adopt the common queue, ownership and complete contact history. Its occupancy focus is only relevant where the company offers accommodation; community support must also work without a bed or room. [Care Control CRM](https://carecontrolsystems.co.uk/benefits/crm/)

**AlayaCare:** its documented intake interface receives client, contact and service information for coordinator review and can create the corresponding operational records when processed. Adopt a durable intake record, human review and an explicit handover into canonical records. No AlayaCare integration is proposed. [AlayaCare Intake API Guide](https://alayacare.github.io/external-integration-docs/intake-referral-guide.html)

**Brevity, Australia:** documentation describes converting a quote/lead to a client, recording client funding and invoice recipients, and defining document checklists by funding source. Adopt separate funding arrangements, payer details and pathway-specific evidence requirements. Australian NDIS rules and price catalogues are not New Zealand funding rules. [Client records](https://brevityau.atlassian.net/wiki/spaces/BS/pages/154207914729), [funding records](https://brevityau.atlassian.net/wiki/spaces/BS/pages/155530559612), [client checklists](https://brevityau.atlassian.net/wiki/spaces/BS/pages/154633240577)

**SupportAbility, Australia:** services have configurable operational settings and permissions, including controls around activities and funding. Adopt configuration by service and explicit action permissions, within this application's single-organisation boundary. [Configuring services](https://help.supportability.com.au/article/437-configuring-services)

**Recommended synthesis:** a care intake workspace with funding assistance, supported by existing care and finance modules. A conventional sales funnel is insufficient: a person can need help securing support without being ready to become an active service user.

### New Zealand pathways

**DSS-funded Supported Living:** the public service description directs people to a NASC and describes support based on a personal plan. The service specification describes assessment, eligibility, provider choice, provider referral and acceptance. Therefore a website enquiry must not be presented as a funding award or a guaranteed place. Eligibility rules belong to the relevant external pathway, not a universal website rejection rule. [DSS Supported Living](https://www.disabilitysupport.govt.nz/disabled-people/support-and-services/housing-and-transport/living-at-home-and-in-a-community-residence/supported-living), [Supported Living service specification](https://www.disabilitysupport.govt.nz/assets/Service-Specification-Supported-Living1.pdf)

**Agency names and responsibilities:** DSS delivery transferred from Whaikaha to MSD in September 2024. The software should distinguish the funding body, assessment/allocation organisation, funding mechanism and invoice recipient. NASC is not a sufficient universal value for “who pays”. Preserve historical labels on old records while using current labels for new configuration. [DSS independent review and changes](https://www.disabilitysupport.govt.nz/about-us/independent-review)

**DSS assessment and allocation:** the April 2026 policy covers assessment, allocation and the My DSS Funding Plan, including new entrants and reviews. Our workflow should support preparation, external assessment and evidence of the resulting plan. Do not recreate the official allocation calculation or invent an integration with its assessment tools. [Assessment and allocation policy](https://www.disabilitysupport.govt.nz/assets/Providers/1-April-2026-Update-Assessment-and-Allocation-of-disability-support-funding-Operational-Policy-v2.pdf)

**IF and hosted flexible funding:** IF is a funding mechanism with host support, and suitability and available supports require assessment. It is not automatically interchangeable with every supported living contract. Record the person's applicable arrangement and verify that the proposed service can be purchased under it. [Individualised Funding](https://www.disabilitysupport.govt.nz/disabled-people/assessment-and-funding/individualised-funding)

**Recent policy change:** the hosted funding policy was updated effective 5 October 2026. It distinguishes host referral and commencement requirements, including service authorization, relevant funding-plan information and signed hosting documents. These are host-specific responsibilities: being a care provider does not establish that Oblivion Care is a contracted funding host. Track the external host and required evidence; enable a host-provider workflow only if that role and contract are confirmed. [DSS policy register](https://www.disabilitysupport.govt.nz/providers/disability-support-services-operational-policy), [hosted funding policy, version 2](https://www.disabilitysupport.govt.nz/assets/Management-of-Hosted-Flexible-Funding-Arrangements-Version-2.pdf)

**ACC:** support at home follows covered injury and support approval processes. People can seek help through ACC, their recovery team or a health professional; acting on someone else's behalf requires authority. Track claim and support references, assessment, approved support, dates and the appropriate provider arrangement. An ACC claim number alone is not approval for the proposed service. [ACC help at home](https://www.acc.co.nz/im-injured/types-of-ongoing-support/home-help)

**Other assistance:** support configurable Health NZ, private, insurance, charitable and other pathways, each with its own evidence and payer. Offer a separate navigation track for personal income assistance where useful. Work and Income's Supported Living Payment is a personal weekly payment; it is distinct from funding authorization for the provider's supported living service. Do not count it automatically as provider revenue. [Supported Living Payment](https://www.workandincome.govt.nz/products/a-z-benefits/supported-living-payment.html)

**Privacy and participation:** the Health Information Privacy Code includes collection, security, access/correction, use and disclosure requirements. Its current version includes Rule 3A concerning information collected from another source. The design needs notice evidence and lawful handling of third-party referrals, not just a single consent checkbox. The hosted funding authority policy also distinguishes decision-specific capacity, supported participation and representative authority. [HIPC, current code](https://www.privacy.org.nz/assets/Codes-of-Practice-2020/Health-Information-Privacy-Code-2020-website-version.pdf), [decision-making authority policy](https://www.disabilitysupport.govt.nz/assets/Providers/Decision-Making-Authority-within-Hosted-Flexible-Funding-Arrangements.pdf)

**Identity compatibility:** Health NZ's dedicated change notice now gives 1 July 2027 for the expanded NHI format, although some older pages still say 2026. The canonical client validator inspected here accepts only the existing shape. Before implementation, verify the current identity standard and upgrade the shared validator/check-digit handling where required; do not copy the existing regex into another form. NHI remains optional for the initial enquiry. [Health NZ NHI change notice](https://www.healthnz.govt.nz/health-professionals/guidance-standards/topic/health-identity/national-health-index-nhi/upcoming-changes-to-the-nhi)

The following sections are proposed product requirements derived from this research. They are not quotations of agency rules. Exact service contracts, rate schedules, evidence requirements and applicable retention rules must be configured against the organisation's actual arrangements before launch.

## 3. Existing application foundations

### Verified in source

- `routes/web.php`, `app/Http/Controllers/ContactController.php`, `resources/js/pages/contact.tsx`: the public contact form is currently a software-sales contact flow. It sends staff and acknowledgement emails, without creating a referral. The controller catches mail errors and still returns a success message.
- `resources/js/pages/home.tsx`: current website copy advertises a platform for supported living providers. The future provider website must change this purpose and its calls to action.
- `routes/respite.php`, `app/Models/RespiteReferral.php`, `app/Http/Controllers/Respite/RespiteReferralController.php`: respite referrals support source, urgency, triage, funding source/reference, cultural information and linkage to a person. The store path can immediately create an active client. It is not a general pre-admission funding-assistance workflow.
- `app/Http/Controllers/Respite/RespiteWorkspaceController.php`: the live respite workspace uses accessible-client scope. Do not mistake the older controller index method for the active listing route.
- `app/Http/Controllers/ClientController.php`, `app/Http/Requests/StoreClientRequest.php`: canonical person creation already uses a transaction, defaults new people to `onboarding`, creates an onboarding workflow and validates care setup. Reuse/extract this behavior rather than copy the respite shortcut.
- `app/Models/ClientOnboardingWorkflow.php`: existing default steps include referral, assessment, consents, care plan, agreement, staff and orientation. Defaults are currently defined in code.
- `app/Http/Controllers/Operations/ClientOnboardingWorkflowController.php`: completion can set a person active. Any new start-of-service gates must also be enforced here and in other activation paths, not only on the referral screen.
- `app/Models/ServiceAgreement.php`, `ServiceAgreementLineItem.php`, `ServiceAgreementRate.php`: existing agreements contain funding references, budgets, hours, dates, rates, signatures and approval metadata.
- `app/Domain/Finance/Models/FinFundingStream.php`, `app/Domain/Finance/Http/Controllers/QuoteController.php`: finance has funding streams and quote conversion. Existing quotes require a client; a pre-client proposal cannot simply use that endpoint unchanged.
- `app/Services/Operations/FundingService.php`: existing agreement expiry and budget-alert helpers are relevant after handover. Claims and journals remain with existing finance owners.
- `app/Policies/ClientPolicy.php`, `app/Services/UserSiteAccessService.php`, `app/Services/Clients/ClientOnboardingAccess.php`: existing permission and approved-site boundaries must remain in force.
- `app/Services/Tasks/Contracts/TaskProvider.php`, `app/Services/Tasks/TaskProviderAuthorization.php`: existing shared task projections can expose authorized follow-ups without creating another task dashboard.
- `app/Models/AppSetting.php` and the data-retention policy infrastructure provide integration points. They do not establish that referral configuration, retention classifications or publish/version behavior already exist.
- `app/Support/Respite/RespiteFundingSource.php` and `app/Enums/ServiceType.php` mix some historic bodies, funding mechanisms and service descriptions. New pathways need clear definitions and explicit compatibility mappings.

### Reuse and addition decisions

Add a dedicated referrals domain for pre-care intake, funding assistance and its evidence. Reuse canonical people, sites, staff, onboarding, documents/privacy infrastructure, service agreements, finance, notifications and task presentation wherever their access contracts fit.

Keep respite bookings and stays in Respite. A new referral may hand off to respite or supported living according to service choice. Do not silently migrate all existing respite referrals, alter their historic status, or change their clients to inactive. Historical migration is a separately reviewed, repeatable operation.

## 4. Public website entry

### Website structure

The rebuilt company website should explain support services, areas served, how funding and assessment work, the team, accessibility and ways to get in touch. Primary actions: **Ask about support**, **Make a referral**, and **Help with funding**. Keep general business enquiries separate from care referrals. The website redesign itself follows its own approved mockup scope; this specification defines the intake pages and their integration.

Public route proposal: `/referrals/new`. Staff workspace: `/operations/referrals`, displayed as **Referrals** in navigation. These are separate public and authenticated surfaces.

### Progressive forms

1. **What would you like help with?** Explore support; make a formal referral; help with funding; request a callback.
2. **Who are you contacting us for?** Myself; someone I support; a professional/agency referral. Capture relationship and organisation where relevant.
3. **How can we contact you safely?** Name and at least one contact method; preferred channel/time; permission to leave a message; communication or interpreter requirements. Permit a general enquiry without identifying another person.
4. **What support is being considered?** Location/region, service interests, goals or a short description, preferred timeframe. “Not sure yet” is valid.
5. **What is the funding situation?** Not explored; need help; assessment/application underway; funding arranged; private payment; unsure. Optional agency or host details. Unknown amounts stay unknown, never zero.
6. **Privacy and submit.** Plain-language collection notice, version, relationship/authority declaration and safe follow-up information. Marketing consent, if offered at all, is separate and optional.

The short form does not require NHI, diagnosis, medical documents, a funding award or a placement. A professional form can ask for more structured details where necessary. Forms must explain why requested information is needed. Detailed identity and supporting evidence can be collected later through a staff-requested secure process.

If someone is unsure of their authority to disclose another person's information, offer a callback about the process with minimal identifying information. Staff then establish the appropriate basis for further collection. Do not automatically reject someone who needs supported decision making, or treat being a family member as blanket authority.

### Submission guarantees

- Validate and durably save the referral before displaying success. A mail outage must not lose the referral.
- Show a receipt reference and the next step. The receipt is not a login or permission to view a case.
- If storage fails, show a truthful retry message and the company's configured contact alternative.
- Prevent duplicate submissions from retries using a submission token/idempotency key. Matching separate people or separate enquiries is a different review process.
- Queue a minimal acknowledgement only to an appropriate contact. Do not echo sensitive form content into email, SMS, logs or analytics.
- Clearly state staffed response hours and that the form is not monitored as an emergency service. Use configured, approved urgent-contact guidance.
- Provide keyboard operation, readable labels, accessible validation, preserved input after validation errors, and a phone-assisted alternative. Avoid storing sensitive form drafts in browser local storage.

## 5. The dedicated Referrals workspace

### Main page

Navigation entry near People and Onboarding; visible only with referral access. Default to a scannable list with an optional pipeline view. Use server-side pagination/filtering; counts and search must use the same authorization scope as rows.

Saved views: **My referrals**, **New and unassigned**, **Follow up today**, **Overdue**, **Funding help**, **Waiting for someone else**, **Ready for onboarding**, **Waitlist**, **Closed**.

Each row shows reference, preferred name or general-enquiry label, service sought, source, stage, owner/team, next action, due time, funding summary and significant blocker. Funding amounts and sensitive risk details are permission-dependent. Summary measures: unassigned, due today, overdue, awaiting funding decision and ready for onboarding; each opens the corresponding filtered list.

Filters: source/channel, referring organisation, service, approved site/region, owner/team, stage, hold reason, funding pathway/state, priority and received date. Search only authorized records and permitted fields. Never expose restricted counts or search suggestions.

Primary action: **Add referral**. Its phone/in-person mode is a short, interruptible capture form. Staff can save a draft and resume; **Save referral** publishes it to the team queue and starts response tracking. Imported or web submissions enter the queue immediately.

### Referral detail

Header: reference, person, stage, owner, next action/due date and last meaningful contact. A concise readiness panel lists blockers and links directly to the missing information.

Sections:

- **Overview:** goals, support requested, preferences, source, site/region and summary.
- **Contacts and permissions:** person, whānau, referrer, agent/representative, safe contact instructions and authority evidence.
- **Activity:** chronological calls, meetings, messages, documents received, ownership changes and decisions.
- **Tasks and follow-ups:** owner, due date, dependency, completion evidence and escalation.
- **Funding help:** pathways, applications, assessments, evidence, external decisions, unresolved gaps and renewals.
- **Assessment and support:** provider suitability, person's goals, visit/assessment outcomes, capacity and proposed support.
- **Documents:** categorized, versioned, access-controlled evidence with expiry and verification state.
- **Onboarding:** conversion preview, missing requirements and linked person/onboarding record after handover.

Show permitted actions in context: **Log contact**, **Add follow-up**, **Assign**, **Request information**, **Record funding decision**, **Prepare onboarding**, **Close referral**. Avoid exposing every action at once or using drag-and-drop as the only way to progress.

## 6. Referral lifecycle and accountability

Default stages: **New → Contacting → Assessing needs → Planning support → Ready for onboarding → Converted**. A referral can also be **Closed**, with a reason. These are proposed defaults and can have configurable business sub-stages mapped to stable lifecycle categories.

- **New:** saved intake; responsible queue/team, first-contact due date, no acceptance implied.
- **Contacting:** named owner; contact attempts, communication needs and privacy/authority work recorded.
- **Assessing needs:** understand goals, existing support, service suitability and external assessment requirements. Distinguish the provider's assessment from the funder's assessment.
- **Planning support:** agree proposed service and funding work; investigate availability and required evidence.
- **Ready for onboarding:** reviewed identity, person's intention to proceed, approved service/site, owner and an explicit funding position meet the conversion gate.
- **Converted:** a linked canonical person and onboarding workflow exist, with a handover receipt. Remaining funding cases stay visible and owned.
- **Closed:** reason, explanation, actor/date, information given to the person and disposition of unfinished tasks.

Hold reasons are separate from stage: awaiting person/whānau, awaiting evidence, awaiting assessment, awaiting funding decision, waitlisted for capacity, paused at person's request. Every hold has an owner, next review date and reason. Funding and suitability activity can continue in parallel.

Close reasons: information only, person withdrew, chose another provider, service unavailable/outside scope, unable to contact after reviewed attempts, duplicate, spam, or other documented reason. A funding decline does not automatically close the referral; consider review, alternatives or the person's preference. Reopening requires permission and reason and preserves history. Converted referrals are not reset to create another person; later support requests use a linked episode.

### Ownership and follow-up

One accountable owner plus an owning team; optional collaborators have explicit access, never access inferred from a mention. Assignment candidates must be active, permitted and eligible for the record's scope. Reassignment records who, why and when. Leave, revoked access and departure trigger team recovery rather than abandoned cases.

Follow-ups support calls, meetings, assessment preparation, document requests, funding enquiries and internal decisions. Record attempted contact separately from successful contact and from automatic acknowledgement. Every open referral needs a scheduled next action or a dated hold; new unassigned referrals have the team triage action.

Use configurable business-day targets and a New Zealand business calendar. Suggested mockup defaults: initial human contact within one business day; unassigned review by the end of the next staffed period; waiting cases reviewed within five business days. These are proposed company targets, not claimed government deadlines. External promised dates and review/appeal deadlines are recorded separately with their source. A hold never erases elapsed time or changes historic performance.

## 7. Funding assistance workspace

### A funding case is more than a funding-source field

A referral may have multiple funding cases, including concurrent or alternative pathways. Each has a named funding navigator, current next action, external contact, pathway/template version and scope of assistance authorized by the person.

Separate these roles: funding body; assessor/allocator; host or budget manager; contracting party; invoice payer; person/authorized representative; internal navigator. One organisation can fill several roles, but they remain distinct fields.

### Default assisted journey

1. **Understand the starting point:** goals, current services, known agency, existing plan and whether funding is new, changing, expiring or unknown.
2. **Agree assistance and authority:** what staff may help with, information they may share, intended recipients, supported decision-making needs and how the person will review submissions.
3. **Identify a pathway:** use a versioned guide and regional contact directory. Suggestions are for staff review, never automatic eligibility decisions.
4. **Prepare evidence:** a checklist with document owner, request date, due date, verification and expiry. Record what the person can provide and what requires an assessor or health professional.
5. **Make or support the assessment referral/application:** record destination, date, channel, submitted version and external acknowledgement/reference. A prepared pack is not a submitted application.
6. **Support assessment:** appointment, accessibility, chosen supporters, questions/goals and information still required.
7. **Follow up:** track agency requests, responses, promised dates and responsibility. Waiting is visible on the main Referrals page.
8. **Record the external decision:** full, partial, conditional or declined; decision-maker, evidence, date, service, conditions, units, amounts and validity. Internal evidence verification is separate.
9. **Resolve the service gap:** compare requested support with usable authorization, available capacity and the person's preferences. Record additional information, reassessment/review, another pathway, staged support or an explicitly agreed private arrangement.
10. **Hand over and review:** link verified funding to the proposed agreement, carry open tasks forward and schedule expiry/review follow-ups.

The person can decline help, change provider or withdraw authority. Navigation work does not imply they have chosen Oblivion Care or owe payment. Time spent assisting with funding is not automatically billable; any charge needs its own applicable, explicit agreement and authorization. Staff should record the person's chosen outcome even if it is support from another provider, and disclose the company's role when assisting with a funding request for services it may deliver.

### States and evidence

Application progress: **Exploring → Preparing → Submitted → Awaiting assessment → Awaiting decision → Decision received**; alternatively **Withdrawn**. “More information needed” is a blocker with an action. A review/reassessment is a linked new attempt that retains the original decision.

Decision outcome: **Approved**, **Partially approved**, **Conditional**, **Declined** or **No decision yet**. Verification: **Unverified**, **Verified**, **Needs clarification**, **Superseded**. Operational availability is derived from verified evidence, conditions, dates, restrictions and service allocation; an outcome label alone never authorizes care or billing.

For each application/decision record: external reference, responsible agency/person, source document/version, dates, evidence owner, requested service, requested amount/units, approved amount/units, period, conditions, review/renewal date, submission/notification evidence and any deadline provenance. Do not overwrite a prior decision when a new award arrives.

### Initial pathway templates

- **DSS/NASC provider-funded service:** assessment referral; provider choice; relevant plan/service authorization; provider contract/service match; scope, units, period and external contact.
- **EGL/flexible allocation:** relevant EGL contact, funding plan and purchase constraints; clarify whether a host or other payer is involved.
- **IF/hosted funding:** appropriate allocation, named host, applicable plan/authorization and host acceptance/evidence relevant to the purchase. Host-service agreements and host tiers are conditional fields, not requirements imposed on every care provider.
- **ACC:** covered-claim reference where appropriate, authority to act, assessment/support request, external approval, provider suitability/contract, service quantities, dates and review contact.
- **Private:** payer identity/authority, cost proposal, agreed service and payment terms, affordability discussion where appropriate, and signed agreement. Mark **Private arrangement confirmed**, never “government funding approved”.
- **Other contract/grant/insurance/Health NZ:** configurable decision-maker, referral mechanism, evidence, purchase restrictions and payer; not automatically eligible for every service.
- **Personal income assistance:** supported navigation with the person's permission, application tasks and outcome; separate from the provider's service budget and claims.

All pathways support “unknown” at intake. Enable all of the above in the design; actual provider offerings and contacts are configured before public launch. Official forms and agency systems remain the external submission channels unless an approved integration is later established. Staff must not store personal agency passwords or impersonate the person.

## 8. Funding, agreements and financial boundaries

Store original quantities and units: money, hours, days, visits or other contract units, with period and currency (NZD default). Do not add hours to days, infer a dollar value without an approved rate, or treat a weekly allocation as an annual allocation. Preserve distinctions between total awarded funding, the amount reserved for this provider, planned service cost and actual usage. Supporting an initial enquiry is different from having a contract or authorization to deliver and claim for a funded service: each pathway must show whether the provider arrangement is verified.

For multiple payers, allocate specific service lines, periods and amounts/units. Prevent overlapping claims for the same delivered support and flag funding that is conditional, expired, insufficient or already committed elsewhere. Record private contributions explicitly. Any permitted price/tax treatment comes from the relevant finance configuration; do not hard-code rates or universal GST assumptions into Referrals.

Before conversion, a **support proposal** can describe services, quantities, dates, estimated prices and unresolved funding. It is a versioned proposal, not a live finance agreement or claim. On conversion, an authorized user maps it to the canonical quote/agreement flow. Existing finance currently expects a client; expanding its scope would need a deliberate change rather than inserting an active client early to satisfy that constraint.

Three decisions remain separate:

1. **External authorization:** what the funder/host/payer has actually agreed to support.
2. **Internal approval:** whether the company accepts the price, staffing, service commitment and financial risk.
3. **Person's agreement:** whether the person or appropriately authorized signatory agrees to the proposed support and terms.

No referral status creates invoices, consumes budgets, posts journals or activates an agreement. Existing finance retains those responsibilities. Claims use actual eligible service delivery and canonical agreement rules.

## 9. Conversion into a supported person's record

Use **Prepare onboarding** and **Create person and start onboarding** for a new person; **Link person and prepare additional support** for an existing person. The software can retain its existing `Client` model while displaying the configured person terminology.

### Conversion preview

Show a field-by-field handover: identity, contact preferences, approved contacts, culture/language, goals, proposed service/site, verified funding references, documents and outstanding actions. Distinguish submitted claims from staff-verified facts. Show the target person and onboarding template, what is new and what would change.

Find possible duplicates using authorized identity checks. Never merge by name alone or treat a shared family email as a unique person. Exact identifiers use the canonical identity service/normalization after verifying supported formats. Matching a record the actor cannot access must not reveal its name, site or identifier; offer a generic restricted intake review route. An authorized reviewer resolves the match. Linking requires authorization for both records.

### Two readiness gates

**Gate A — prepare onboarding:** identity sufficiently verified; person intends to proceed or a documented lawful authority applies; proposed service and approved site/service team selected; provider suitability accepted; required referral evidence checked; funding position and remaining actions explicit. Funding can still be pending: create the person in `onboarding` and keep activation blocked with an assigned action.

**Gate B — start services:** applicable funding/payer arrangement verified and valid for the proposed service/start date; required conditions satisfied; agreement appropriately approved/signed; required care/safety information and consent ready; staffing and service capacity confirmed; responsible manager approves the start. Enforce this through canonical onboarding and service activation, including direct requests and non-referral entry points.

Company-approved temporary unfunded support, if offered, is a separate internal arrangement with an approver, cost cap, dates, responsible payer/budget and review. It cannot fabricate an external authorization or waive legal, privacy, safety or contract requirements. A setting must not turn missing authorization into an approved government-funded service.

### Transaction and handover

- Recheck current permissions, scope, record version and readiness on the server at submission.
- Lock the referral/conversion and relevant target identity records; enforce unique conversion identity and duplicate-identity protections. A preview cannot reserve an unlocked duplicate decision indefinitely.
- Create a canonical client in `onboarding` or link an existing authorized client. Never downgrade an existing active client's status just because they have a new referral.
- Create/reuse the appropriate onboarding workflow without duplicate active workflows. Additional services for an active person need explicit workflow scope; extend the canonical model if required.
- Link verified evidence and draft agreements using authorized canonical services. Do not overwrite established clinical or contact data with unverified intake information.
- Record original referral, actor, time, field mappings, settings versions, linked records and unresolved work in a conversion receipt.
- Mark conversion committed only when the database transaction succeeds. Failed attempts leave no partial person or agreement. Post-commit notifications have durable retry records.
- Retrying the same conversion returns its existing authorized result. Concurrent attempts must not create two people or workflows.
- Open the linked profile only if the actor has person access. Referral permission is never a shortcut to the full clinical profile.

The referral remains available under its own restrictions. Frontline workers receive the care information required for their role through the person's profile; they do not inherit unrestricted funding applications or third-party correspondence. Reversing a mistaken link is a privileged correction with history, not deletion of a person who may already have care records.

## 10. RBAC and record scope

Register explicit proposed permissions in the existing permission catalogue, role editor and server-side policy system. Do not rely on sidebar visibility or role names.

### Permission groups

- Read: `referrals.viewAny` (within permitted sites), `referrals.viewAssigned`, `referrals.intake.view` (central intake without a site), `referrals.viewAllSites` (explicit cross-site scope expansion).
- Work: `referrals.create`, `referrals.update`, `referrals.assign`, `referrals.transition`, `referrals.close`, `referrals.reopen`.
- Contact/evidence: `referrals.communications.record`, `referrals.communications.send`, `referrals.documents.manage`, `referrals.sensitive.view`, `referrals.sensitive.manage`.
- Funding: `referrals.funding.view`, `referrals.funding.manage`, `referrals.funding.verify`, `referrals.funding.disclose`, `referrals.exceptions.approve`.
- Handover: `referrals.convert`, `referrals.duplicates.resolve`.
- Oversight: `referrals.reports.view`, `referrals.export`, `referrals.audit.view`.
- Configuration: `referrals.settings.view`, `referrals.settings.manage`, `referrals.settings.publish`; privacy/retention changes also use existing governance authority.

Field-level restrictions distinguish general intake, sensitive support/clinical content and financial/authority evidence. Document classification follows the content, including material added to free-text notes. System-generated timeline entries must not leak hidden amounts, diagnoses or filenames.

### Scope evaluation

For a site-owned case: a base read grant plus approved-site membership, and assignment when relying on `viewAssigned`. `viewAllSites` can expand site scope only with a base read grant; it does not grant sensitive-field or action permissions.

For an unallocated public referral: explicit `referrals.intake.view` plus a base read grant; assignment is additionally required when relying on `viewAssigned`. A null site never means visible to everyone. Publicly supplied preferred site/region is a request; staff/server routing establishes the authorized owning scope.

For every mutation: read access to that record, the specific action permission, valid nested-record ownership and current transition/evidence requirements. Assigning a task, being mentioned, or knowing a URL does not grant access. Conversion additionally requires target `Client` permissions, site access and whichever canonical onboarding/finance capabilities its selected actions require.

### Suggested role presets

- **Reception/intake:** create and triage; record contacts and tasks within approved scope; limited funding summary. No funding verification, conversion or unrestricted sensitive evidence.
- **Referral coordinator:** manage assigned/site cases, coordinate assessments and funding work, progress cases and prepare handover. Conversion is separately grantable.
- **Funding navigator:** manage applications, authorized evidence and submissions within scope. External funding decisions remain evidenced, not made by this role.
- **Service manager:** acceptance, assignment, capacity, conversion and internal exceptions within delegated limits.
- **Finance reviewer:** relevant payer/authorization/proposal detail and internal financial approval; no automatic clinical narrative access.
- **Privacy/quality reviewer:** explicitly scoped audit, disclosure and retention functions.
- **Support worker:** no general referrals access by default; assigned care work appears through existing frontline surfaces after appropriate handover.
- **Settings administrator:** configuration permissions do not inherently grant referral content or export access.
- **Public referrer/family:** submit or use narrowly authorized information-request links only; no staff workspace access.

Reapply the same boundary to lists, totals, search, direct URLs, attachments, exports, task aggregations, notification delivery, background jobs and any future API. Revoked permissions apply at action/delivery time. Use generic denial behavior where necessary to prevent existence disclosure.

## 11. Configurable settings

Proposed page: **Settings → Referrals**, with a permission-gated shortcut from Referrals. Effective settings follow organisation defaults with explicit service/site overrides where appropriate. Public forms expose only a safe published subset.

### Settings areas

1. **Services and intake:** offered services, regions, responsible sites/teams, source channels, referrer categories, priority labels and closure reasons. Separate service offering from funding mechanism and map to existing service contexts.
2. **Forms:** published form versions, field labels/help, field types/options, conditional display, required-at-intake versus required-before-handover, sensitivity and explicit destination mapping. Custom fields support bounded text, dates, numbers, choices and checkboxes. No arbitrary scripts, SQL or executable formulas.
3. **Workflow:** business stages, stable lifecycle mapping, allowed transitions, mandatory evidence, responsible roles, hold reasons and checklist templates. Keep terminal/converted semantics and activation guards protected.
4. **Assignment:** eligible teams, routing by service/region/pathway, priority order, fallback intake team, workload limits and absence handling. Rule simulation explains the chosen team. Assignment never creates a permission grant.
5. **Follow-up:** business hours, holidays, first-contact targets, hold review periods, escalations, overdue recipients and notification preferences. Urgent categories route to staffed processes; settings cannot promise 24-hour monitoring where none exists.
6. **Funding pathways:** funding bodies, allocators, hosts, payers, regions, scheme/service compatibility, external directories, application stages, evidence checklists, links, review requirements and effective dates. Store source URL, verified date, policy owner and next review date.
7. **Proposals and approvals:** delegation limits, reviewer separation, permitted temporary funding arrangements and references to finance-controlled rate/tax configuration. Finance remains the owner of rates and agreement lifecycle.
8. **Documents and privacy:** classifications, required evidence, expiry/review rules, notices, consent/authority templates and secure request settings. Retention uses governed classifications and legal holds.
9. **Communications:** sender identity, acknowledgement/contact templates, safe merge fields, secure-link expiry and delivery policies. Preview templates with synthetic data. No sensitive details in generic notifications.
10. **Conversion and onboarding:** field mapping, duplicate review, onboarding templates, required gate evidence and responsibility for open actions after handover.
11. **Access and reporting:** links to the existing role editor, approved-site scope, report audiences and export controls; no second role database.

### Publishing and change control

Settings have **Draft → Validate → Preview impact → Publish**, effective date, author, approver where required and version history. Preview includes sample forms, affected routing, missing owners, unreachable stages and cases affected by a proposed change. Validate permission keys, service/site mappings, rates references and contradictory requirements.

New cases take the effective published version. Existing cases keep their version unless an authorized migration is previewed and applied with a reason. Emergency policy changes can flag affected cases for review without silently altering recorded decisions. Live RBAC, legal restrictions and current safety/authorization validity are always rechecked; pinning a workflow never preserves revoked access or expired funding.

Archive a setting used by historical records instead of deleting it. Rollback publishes a new version, preserving history. Updating a label must not change the meaning of prior reports. Existing legal retention constraints cannot be lowered by an ordinary workflow administrator.

## 12. Communications, evidence and privacy behavior

Contact logging is available from launch. Staff-assisted email/SMS requests and acknowledgements require configured transports and explicit action permissions. Clearly distinguish **Draft**, **Queued**, **Sent**, **Delivered**, **Failed** and **Response recorded** when supported by the transport. Do not imply delivery where only sending is confirmed.

Before sharing evidence, show intended recipient, verified address/channel, purpose, included documents and authority. Record an explicit human action for external application submissions or disclosures; automated escalation stays internal unless a separately configured, approved communication rule applies.

Use secure, expiring, revocable requests for sensitive uploads; bind links to one purpose/record and appropriate identity verification. A public reference or email link alone must not disclose the case. No full referral payloads in notification bodies, browser telemetry, application logs or URL query strings.

Capture privacy notice version, delivery method/date and, where relevant, third-party collection notice completion or a documented applicable exception. Separate contact permission, information collection, authority to act, disclosure to a funder, service consent and marketing preferences. The Privacy/quality function owns decisions about exceptions and authority disputes.

Support factual correction with history, verified versus unverified information, disclosure/access requests and restricted third-party information. Retention classifications distinguish general enquiries, unsuccessful care referrals, funding assistance, converted care evidence and spam. Register them with existing retention governance; set periods against applicable requirements and contract/legal review before launch. Honor legal holds and avoid unrestricted permanent raw-payload copies.

Uploads use private storage, size/type checks, malware scanning/quarantine and per-download authorization. Evidence includes original uploader/source, timestamp, document version, classification, verification and replacement history. Quarantined files cannot be downloaded, converted into clinical evidence or sent externally.

## 13. Integration and reliability design

### Website connection

The present public website and application are in the same Laravel repository. Prefer a shared referral-intake application service behind the public form. If the replacement website is separately hosted, use its server to call a narrowly scoped intake endpoint; no staff credentials or shared secret in browser code.

Public intake accepts only allowlisted fields and published form identifiers. Staff-only fields such as owner, approval, trusted identity link, internal stage and permission scope cannot be supplied by the browser. Store original submission provenance separately from reviewed values.

Both options need server validation, rate limits, abuse controls, request size limits and retry-safe durable receipts. Same-origin forms use the application's request protection; a separate server connection uses scoped credentials/signatures, replay protection and rotation. Neither exposes internal read endpoints. Do not enable unrestricted cross-origin staff APIs.

After durable save, queue assignment and notifications through retryable work. Use a durable outbox/recovery record for side effects, not only an in-memory “after commit” callback. Lost acknowledgements do not imply lost referrals. Monitor queue age, delivery failures, unassigned intake and failed conversion attempts without logging sensitive payloads.

### Proposed application contracts

- Public: submit referral and return only a receipt; optional bounded secure upload/request completion.
- Staff: scoped list/detail/create/update; assign; log contact; manage tasks/documents; validated transition/close/reopen.
- Funding: manage case/attempt; record external submission and outcome; verify evidence; preview authorized disclosure.
- Conversion: preview, commit and obtain an authorized conversion receipt.
- Settings: draft, validate, preview impact, publish, archive and version history.

Use validated requests, policies and application services for transitions and conversion. Include record versions for optimistic concurrency; return a conflict and preserve user input when another staff member has changed the case. Don't perform irreversible side effects during preview. Background work rechecks eligibility and authorization before sensitive delivery.

### Existing shared surfaces

Project referral tasks into the existing task system using the referral domain's canonical query scope, including its explicit central-intake rule. Do not label the whole provider globally visible to support referrals with no site. Link relevant appointments into existing calendar mechanisms only with matched access controls. Existing `/my-day` remains the frontline home.

## 14. Proposed data ownership

Logical entities below describe responsibility; they are not permission to add duplicate stores where an existing canonical owner already works.

- **Referral:** unique reference, intake/source timestamps, submitted-for identity/contact snapshot, optional linked client, owning team/site, owner, service interest, stage, hold, priority, next action, configuration version and record version.
- **Referral contacts/authority:** distinct person/referrer/supporter roles, contact preferences and authorization scope/evidence. A contact relationship does not create portal membership.
- **Referral activities and tasks:** appendable history, assignee, due/completed times, contact outcomes, dependencies and evidence. Corrections are linked amendments.
- **Funding case:** pathway/version, navigator, applicant, agency roles, service scope, state and next action; remains linked to both originating referral and converted client.
- **Funding attempts/decisions/authorizations:** immutable submitted versions, external outcome evidence, verified limits/conditions and supersession lineage. Keep approved external authorization distinct from internal approval records.
- **Support proposal and lines:** pre-client service proposal, units, cost assumptions, intended payer split and links to canonical quote/agreement after handover.
- **Evidence and disclosure records:** canonical private document references plus classification, notice/authority evidence, recipients and disclosure purpose.
- **Configuration versions:** published forms, workflows, pathway templates, routing and checklists with stable IDs and effective dates.
- **Conversion receipt:** unique referral/conversion key, target client/workflow/agreement links, field mapping and transaction outcome.
- **Audit and outbox:** append-only permitted event metadata and durable side-effect delivery status; sensitive content remains in protected domain storage.

Index scoped list/filter keys, owner/due times, reference and lifecycle dates. Use precise decimals for money/quantities and explicit units. Use UTC timestamps with Pacific/Auckland display/business calendars and source dates for external evidence. Avoid storing DOB/NHI or full narratives in search logs. New referral tables have no tenant selectors or tenant-scoped queues.

## 15. Reporting and measures

Operational reports should answer: what needs attention, where are cases waiting, and who owns the next step?

- Referrals received by channel, service and region; separate spam/test/duplicate closures.
- Time to first human attempt and successful contact; automated acknowledgements excluded.
- Unassigned age, overdue follow-ups, time in stages and holds, with elapsed and company-working time distinguished.
- Funding pathways and application outcomes, partial approvals, pending age and evidence gaps.
- Planned support versus verified coverage, in comparable units only; no inferred award value.
- Acceptance, waitlist, conversion and actual service-start counts as separate milestones.
- Closure reasons, reopenings and repeat referrals without counting them as new people indiscriminately.
- Workload and cases needing reassignment, with useful service-level trends.

Specify cohort date, denominator and outcome cutoff for conversion rates; open cases remain visible in cohort definitions. Do not call all enquiries “lost sales” or optimize funding assistance solely for conversion. People helped to another provider can be a successful support outcome.

Reports and exports use the same record and field restrictions as detail pages. Suppress small or identifying aggregate groups where privacy policy requires. Export needs a distinct permission, audit and safe handling of spreadsheet formula-like content. Marketing analytics receive no health/funding narratives or identifiable case events.

## 16. Fully featured mockups — approved review scope

Mockups should be clickable and cover complete representative journeys using clearly synthetic data. They demonstrate proposed behavior; simulated saves, emails, funding decisions and conversions must not affect real records or send messages.

### Screen inventory

1. Public support/referral entry and funding-help choice.
2. Short enquiry/callback form and professional referral form, including conditional fields and privacy.
3. Confirmation, validation failure, duplicate retry and unavailable-service recovery.
4. Referrals list, saved views, pipeline and filter/search behavior.
5. Phone/manual intake, draft resume and submission to the queue.
6. Referral detail with overview, activity, contacts, tasks, evidence and readiness.
7. Assignment/reassignment, team fallback and overdue escalation.
8. Contact logging, message/request preview and failed-delivery recovery.
9. Funding-help overview with multiple pathways and a named navigator.
10. Funding application preparation, checklist, assessment appointment and external submission evidence.
11. External full/partial/conditional/declined decision, verification and next-step planning.
12. Funding gap, alternative pathway and reassessment/review journey.
13. Support proposal, payer split, capacity review and internal approval.
14. Waitlist/hold, review date, close/reopen and duplicate resolution.
15. Conversion preview, duplicate warning/restricted review, blocked readiness and successful handover.
16. Existing-person additional support, pending-funding onboarding and final service-start gate.
17. Linked person view showing transferred information and ongoing funding work at the correct access level.
18. Settings overview and each setting area, including forms, workflows, routing, pathways, follow-up and permissions.
19. Settings draft/validation, impact preview, publish, history and controlled migration of open cases.
20. Operational reports, restricted report view and export preview.

### Required mockup journeys

- Self-referral without funding → assigned navigator → NASC assessment preparation → waiting → verified decision → onboarding → service start.
- Whānau calls → minimal manual intake → safe-contact/authority clarification → funding assistance.
- Professional referral already funded → document review → capacity/acceptance → conversion.
- IF/EGL arrangement → external host clarification → partial coverage → revised proposal with the person's agreement.
- ACC pathway → authority to assist → support approval → provider/service confirmation.
- Private payer → proposal → payer acceptance → onboarding; no fictitious funder decision.
- Other configurable grant/contract and separate personal-income-assistance track.
- Existing client seeks another service → authorized link → additional support workflow; no duplicate person or loss of active status.
- No available capacity or declined funding → continued follow-up, review/alternative or person-chosen closure.
- Restricted receptionist, coordinator, funding navigator, manager, finance reviewer, support worker and settings-admin views.

### Mockup quality requirements

Include filled, loading, empty, validation, conflict, permission-denied and recovery states where applicable. Desktop workspace plus phone-width public form and essential staff capture/follow-up views; keyboard and focus behavior; clear save state; unsaved-change protection; readable dates and next actions. No decorative charts without meaningful decisions.

Use one consistent case across screens to prove information continuity. Demonstrate that changing a pathway template changes a new case's checklist while an existing case retains its version. Permission demonstrations are explanatory mockups, not a claim of implemented security.

Mockup review is complete when the user can walk through each journey, identify responsibility and blockers at every step, understand the funding position and verify what will become part of the person's record. Record approved changes to this specification before implementation planning is finalized.

## 17. Implementation sequence after mockup review

These are proposed reviewable work packages, not work being executed now. Preserve unrelated current repository changes.

1. **Canonical contracts and permissions:** referral vocabulary, models, policy/scope, permission registration, configuration version contracts and identity/retention decisions. Likely new `app/Domain/Referrals/`, `routes/referrals.php`, migrations and seed/permission catalogue updates.
2. **Staff intake workspace:** authorized list/detail, manual capture, assignment, notes, next actions, lifecycle and audit. Proposed `resources/js/pages/operations/referrals/`; sidebar and shared permission projections.
3. **Funding assistance:** pathways, evidence, external applications/decisions, verification, proposals and review work. Include all confirmed pathway types; configure actual agency details rather than invent them.
4. **Settings and communications:** published forms/workflows/routing/checklists, safe messages, secure evidence requests, outbox/retry and task integration. Settings versions must be functional before relying on configurable production intake.
5. **Safe conversion and activation:** extract/reuse canonical client creation; integrate onboarding/agreement authority; duplicate prevention, transaction recovery and all service-start entry points.
6. **Public intake and provider website entry:** approved public forms, durable receipts, safe acknowledgements and anti-abuse; confirm hosting/connection contract. Full marketing-site redesign remains separately scoped.
7. **Reports and operational readiness:** definitions, scoped exports, monitoring, recovery tools, privacy/retention registration, performance and access checks.
8. **Pilot and optional historic linkage:** synthetic and approved pilot referrals, staff training, configured pathways/owners, reconciliation and staged publication. Migrate historic respite records only through an approved mapping and dry run.

Minimum launch is a complete working journey: website/phone intake → ownership/follow-up → funding assistance → supported-person onboarding, backed by real settings and RBAC. Do not launch a public form into an unstaffed or untracked queue. Future direct agency integrations, full inbound mailbox ingestion, telephony transcription and a referrer portal are separate enhancements; manual capture and supported external submission remain available.

## 18. Acceptance criteria

### Intake and workflow

1. Website submission without NHI/funding creates one durable referral and no client; retries do not duplicate it.
2. A mail outage leaves the referral visible and a retryable delivery failure; storage failure never displays a false receipt.
3. A phone referral can be saved quickly, assigned and given a follow-up with unknown fields left explicitly unknown.
4. Every open record has an accountable team/owner and next action or dated hold. Reassignment and staff access removal recover work safely.
5. Contact attempts, successful contact and automatic acknowledgement produce distinct history and metrics.
6. Stage progression and closure obey published rules; invalid direct requests cannot skip required evidence.

### Funding assistance

7. A person with no funding can receive a navigator, pathway checklist, assessment referral and tracked follow-ups without becoming an active client.
8. DSS/NASC, EGL/IF, ACC, private and another configured pathway retain their different roles, evidence and units.
9. Multiple applications and partial/conditional/declined outcomes can coexist without overwriting decisions or treating a pending application as approved.
10. Funding verification requires evidence and the correct permission. Expired authorization and unmet conditions block the affected service start.
11. Agency submission records distinguish prepared, submitted and acknowledged. Sensitive disclosure checks recipient and authority.
12. Hours/days/money are not added indiscriminately; full personal budgets are not all allocated to this provider; duplicate payment for the same support is prevented at the canonical financial boundary.
13. Personal-income assistance is separate from provider funding. A funding decline supports alternatives or review instead of automatic rejection.

### Conversion and security

14. Conversion reuses reviewed data, creates one onboarding client/workflow, links evidence and preserves outstanding funding work.
15. Repeated/concurrent conversion, stale preview, a duplicate identity or transaction failure cannot leave duplicate or partial operational records.
16. Linking an existing active person preserves their status and existing verified information; updates require appropriate authority and review.
17. A user without referral permission cannot view lists, counts or direct records; approved-site and assignment restrictions apply everywhere.
18. Null-site intake is limited to the explicitly authorized intake audience. Assigning someone does not widen their access automatically.
19. Forged referral, task, evidence, funding-case, client or site IDs fail their parent/scope checks; attachment URLs do not bypass policy.
20. Search, exports, notifications, shared tasks and conversion responses cannot disclose hidden records or restricted fields.
21. Settings access does not grant referral access. Funding access does not grant unrestricted clinical access. Referral access does not grant client-profile or portal membership.
22. All routes to active care, including existing onboarding completion, respect applicable readiness gates. Pending funding can allow preparation without allowing unauthorized service/billing.

### Settings, privacy and operation

23. New cases use published configuration; historic cases retain versions; migrations and rollback are previewed and audited.
24. Invalid routing, missing owners, contradictory forms and unreachable required stages fail validation before publication.
25. Privacy notice/authority evidence and applicable third-party notice handling are recorded; withdrawn authority affects future use/disclosure.
26. Quarantined or unauthorized documents cannot be downloaded or sent; retention holds prevent inappropriate disposal.
27. Business-day reminders work across New Zealand daylight-saving changes and holidays; retry jobs do not spam staff or recipients.
28. Public intake and staff actions work with keyboard, screen-reader labels, readable errors and practical narrow-screen layouts.
29. Reports have stated denominators and apply the same visibility rules as records. Conversion and actual service commencement remain separate measures.
30. Staff can recover failed assignments, requests and conversions without editing the database, losing evidence or falsely recording success.

Implementation verification should combine focused backend policy/lifecycle/concurrency tests, frontend behavior checks and real-browser walkthroughs of approved mockup journeys. The standalone prototype and its browser audits do not validate production runtime behavior or constitute a production-readiness audit.

## 19. Decisions retained for configuration and review

Confirmed: dedicated Referrals page; website and staff intake; assignment/follow-up; help obtaining funding across all pathways; customizable settings; RBAC; reuse of the supported-person record; mockups only after user approval of the research/specification.

Recommended defaults: New Zealand context, central intake before site allocation, human-reviewed funding/navigation decisions, draft onboarding while funding is pending, separate start-of-service gate, and source/version-aware settings.

Still to supply before implementation/public launch: actual company/provider identity and website domain, offered services/regions, approved sites and teams, agency/host/provider contracts, contacts and rates, operating hours, delegated approvals, communications transports, retention classifications and any authorized role as a contracted host. These do not block comprehensive synthetic mockups; placeholders must be clearly marked as sample configuration.

**Next action:** review the approved-scope [clickable mockup](mockup/README.md) and its [three audit passes](mockup/AUDIT-LOG.md), then agree production implementation. No production feature, website publication, external submission or message sending is authorized by this document alone.
