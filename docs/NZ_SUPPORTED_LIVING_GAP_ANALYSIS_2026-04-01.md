# Oblivion Findings: NZ Supported Living Gap Analysis

Date: 2026-04-01

## Scope

This review compares the current Oblivion Findings product surface against:

- ShiftCare
- eCase / Health Metrics
- Webcare
- AlayaCare
- Adjacent ANZ disability-platform patterns where relevant

The focus is the New Zealand supported living market, not generic global healthcare software.

## Method

This assessment is based on:

- Current codebase modules, routes, tests, and internal docs in this repository
- Public vendor feature pages and help docs
- New Zealand supported living and privacy requirements

Important note:
The codebase contains both mature modules and phase-labelled or still-maturing areas. So this analysis distinguishes between broad capability presence and likely product maturity.

## What Oblivion Findings Already Has

Oblivion Findings is already much broader than a standard care-management product.

### Core care and workforce

Evidence in the codebase shows current coverage for:

- Client profiles, documents, medical profiles, medications, emergency contacts, support plans, assessments, incidents, risks, portal users, notes, and AI/RAG queries
- Shifts, recurring shifts, assignment, attendance, timesheets, approvals, and rostering
- Medication management with enhanced MAR/eMAR, audit logs, discrepancies, corrections, break-glass access, reporting, and compliance dashboard
- Training, competency, staff induction, background checks, credentials, leave, onboarding, offboarding, performance, feedback, and payroll export

### Provider operations

Oblivion also goes well beyond care delivery into provider operations:

- Service agreements, funding, funding claims, billing, invoices, quotes, price books, payroll export, recurring charges, mileage, EVV, geofences, calendar sync, and qualification matching
- Family portal and client/next-of-kin access patterns
- Messaging, notifications, summaries, and timeline views

### Quality, risk, and governance

This is where Oblivion is unusually strong:

- Incident workflows, safeguarding, risk registers, follow-ups, external reporting
- Privacy workflows including data subject requests, retention, legal holds, breaches, and DPIAs
- Governance including board packs, meetings, resolutions, risk, compliance, strategic planning, budgets, interests, evaluations, policies, and clinical governance
- Health and safety including lone worker, PPE, hazardous substances, drills, return-to-work, first aid, restraint, and safe work procedures
- Sites/facilities operations including hazards, checklists, inspections, rooms, zones, resources, hardware, credentials, and vendor management
- Control Room capabilities including alerts, escalation, playbooks, SLA tracking, live map, device monitoring, evidence packs, watcher lists, and operator tasks

### NZ domain signals already in the product

The codebase already contains NZ-relevant concepts such as:

- NHI fields
- ACC-related injury/claim handling
- NASC and Whaikaha references in service agreements
- Cultural support and Te Ao Maori / whanau language in service types
- Governance support for Te Tiriti-related work

## Where Oblivion Already Exceeds ShiftCare and Most Care Platforms

Compared with ShiftCare and most mainstream care-management systems, Oblivion is stronger in these areas:

- Governance and board-level oversight
- Privacy and regulated information governance
- Safeguarding and structured investigations
- Health and safety management
- Site and facilities compliance
- Control-room / command-centre style escalation workflows
- Integrated finance depth
- Evidence-pack and audit-oriented operating model

In market terms, ShiftCare, Webcare, and eCase are primarily care operations systems. Oblivion is already shaping into a full provider operating system.

That is a genuine differentiator.

## Where ShiftCare and Other Providers Are Stronger Today

### 1. Frontline simplicity and mobile maturity

ShiftCare, Webcare, and eCase all visibly emphasise frontline worker usability:

- Dedicated iOS/Android staff apps
- Clear worker workflows for rosters, notes, clock-in/out, alerts, and care-plan access
- Family/portal experiences positioned as product features, not side modules

Based on this repo, Oblivion has strong workflow coverage but I found no clear evidence of an equally mature dedicated native frontline mobile product or offline-first worker experience.

### 2. Better-packaged daily operations

Competitors present a cleaner story around:

- scheduling
- worker check-in/check-out
- care notes
- family visibility
- funding/billing linkage
- payroll/timesheet completion

Oblivion has many of these features, but they are spread across operations, shifts, portal, HR, reports, privacy, and control-room domains. That breadth is powerful, but it risks feeling complex compared with simpler market products.

### 3. NZ disability workflow packaging

eCase is especially strong in packaging New Zealand disability-provider workflows:

- multiple funding streams including EGL, MoH, and DHB
- roster matching based on preferences and staff traits
- mobile worker app
- family/client portal with budgets, plans, and goals
- explicit compliance reporting against NZ standards

Oblivion has many pieces of this, but not yet a single visible "NZ supported living operating model" layer that ties them together.

### 4. Maturity in customer-facing product framing

Vendors like ShiftCare, Webcare, eCase, and AlayaCare market clear role-based products:

- coordinator
- support worker
- family
- finance/admin
- clinical/quality

Oblivion currently looks more like a powerful internal platform than a tightly packaged go-to-market product.

## Key Gaps for the NZ Supported Living Market

These are the most important gaps based on the codebase and market benchmark.

### Gap 1: A dedicated NZ supported living workflow

New Zealand supported living providers need strong support for:

- person-led plans and reviews
- tracking progress against plan goals
- validating delivered hours against the approved support plan
- documenting family/whanau involvement
- satisfaction evidence
- Maori strategy and outcome reporting
- complaints, compliments, incidents, and quality returns

Oblivion has many supporting modules, but not yet a clearly packaged end-to-end supported living workflow that starts with referral/NASC/Whaikaha context and ends in validated delivery, outcomes, and reporting.

### Gap 2: Mobile-first worker execution

This is the biggest product gap versus market leaders.

Needed:

- native or strongly app-like mobile experience
- offline-first notes, support plans, and medication workflows
- fast end-of-shift notes
- lightweight alerts and reminders
- photo/file capture from mobile
- geofenced attendance with low friction

Without this, Oblivion risks being stronger for managers than for the people actually delivering support.

### Gap 3: Family/whanau portal depth

Portal capability exists in the repo, but the market baseline is moving toward:

- schedules and visit history
- support-plan and goal visibility
- document sharing
- budgets / funding visibility
- service requests
- secure messaging
- configurable privacy and consent

This area needs to feel intentional and central for NZ supported living, not just available.

### Gap 4: NZ ecosystem integrations

I found NZ concepts in the data model, but not a strong visible integration layer for the NZ ecosystem.

Potential gaps include:

- payroll ecosystem integrations used in NZ
- finance integrations beyond general accounting patterns
- Health NZ / referral / clinical-adjacent interoperability where appropriate
- stronger Whaikaha/NASC-oriented data exchange and reporting outputs
- complaint / incident / quality submission-ready evidence packs for NZ regulators and funders

### Gap 5: Supported decision-making and outcome measurement

NZ disability services increasingly need to show:

- personal goals
- choice and control
- reduced restrictive practice
- culturally responsive support
- family/whanau outcomes
- service quality evidence

Oblivion has goals, feedback, governance, safeguarding, and reporting foundations, but it does not yet appear to turn these into a distinctive supported-living outcomes product.

### Gap 6: Medication maturity at the last mile

Internal docs already show some medication gaps:

- medication version history UI
- scheduled stock counts
- stale alert cleanup
- drug interaction management UI
- system-level allergy management
- MAR chart attachment management
- barcode scanning
- offline MAR

For supported living providers with complex medication governance, these are important.

## Areas Where Competitors Are Still Thin, and Oblivion Can Win

There is a real opening in the market if Oblivion focuses on these themes:

### 1. Supported living + governance in one system

Most products stop at care operations. Oblivion can combine:

- person support
- workforce
- site compliance
- safety
- safeguarding
- privacy
- board/governance

That is especially compelling for larger or more regulated NZ providers.

### 2. Quality and evidence automation

Providers are under pressure to prove:

- plans exist
- reviews happened
- goals progressed
- incidents were managed
- staff were trained
- complaints were resolved
- Maori outcomes and quality plans are monitored

Oblivion can become the easiest system for producing evidence packs, not just storing records.

### 3. Housing + support + provider ops

The combination of:

- sites
- rooms/zones/resources
- hazards
- staff matching
- respite
- supported living operations

is stronger than most care platforms.

### 4. Command-centre model for complex providers

The Control Room concept is unusual in this market and could become a flagship differentiator for:

- after-hours management
- serious incident escalation
- lone worker response
- missed medication / missed visit / no-show workflows
- high-risk client monitoring

## What Oblivion Is Missing Most Versus the Best NZ-Fit Market Offer

If I reduce this to the most important missing pieces, they are:

1. A polished frontline worker mobile experience
2. Offline-first note / MAR / attendance workflows
3. A strong family/whanau portal with budgets, goals, service requests, and messaging
4. A single NZ supported living workflow from referral and support plan to hours validation and outcome reporting
5. Stronger NZ-specific reporting and integration packaging
6. Better product simplification so the day-to-day experience feels easier than the codebase complexity suggests

## Recommended Product Direction

The best positioning is not "another ShiftCare".

The stronger position is:

"An NZ-native supported living operating system for providers that need care delivery, quality, safety, governance, and evidence in one place."

That is a more defensible category.

## Recommended Roadmap

### Priority 1: Make the frontline product excellent

- Build or tighten the worker mobile experience around roster, support plan, alerts, notes, incidents, medication, and attendance
- Add offline-first capability for critical workflows
- Make end-of-shift documentation much faster
- Add low-friction media capture and voice-to-note options

### Priority 2: Package a supported living core

- Create a dedicated supported living workflow spanning referral, service agreement, NASC/Whaikaha details, plan, goals, reviews, delivered hours, and outcomes
- Add plan-to-delivery validation dashboards
- Add individual-plan review reminders and outcome evidence capture
- Create quality-return and board-ready reporting packs

### Priority 3: Build the best family/whanau experience in the market

- Portal access to plans, goals, schedules, visit history, key documents, budgets, and service requests
- Fine-grained consent and privacy controls
- Optional whanau participation in reviews and satisfaction collection
- Plain-language and culturally safe portal design

### Priority 4: Finish the medication edge cases

- Version history UI
- stock-count workflows
- allergy and interaction tooling
- barcode support
- offline MAR
- stronger attachment and evidence handling

### Priority 5: NZ-specific differentiation

- Explicit Whaikaha/NASC and supported living reporting outputs
- Maori outcome and quality-plan reporting support
- Strong ACC and community-support workflows where relevant
- NZ payroll/accounting/integration priorities based on target customers

### Priority 6: Simplify the product surface

- Build role-based product views for frontline staff, coordinators, quality/compliance, HR, finance, and leadership
- Hide advanced modules until needed
- Turn the broad platform into clear role-based experiences

## Biggest Strategic Risk

The main risk is not lack of features.

It is feature sprawl.

Oblivion already has enough surface area to beat many competitors on breadth. If the product is not packaged carefully, buyers may perceive it as harder to adopt than simpler incumbents.

## Bottom Line

Oblivion Findings already has more strategic depth than ShiftCare and most care-management competitors.

What it does not yet appear to have is the same level of product packaging around the daily supported living workflow, frontline mobile simplicity, family/whanau engagement, and NZ-specific supported living reporting.

If you close those gaps, this can be more than a care platform. It can be a new category for New Zealand: a supported living provider operating system.

## Sources

Internal repository sources:

- `routes/clients.php`
- `routes/shifts.php`
- `routes/medications.php`
- `routes/incidents.php`
- `routes/operations.php`
- `routes/hr.php`
- `routes/training.php`
- `routes/control-room.php`
- `routes/governance.php`
- `routes/privacy.php`
- `routes/health-safety.php`
- `routes/safeguarding.php`
- `routes/sites.php`
- `routes/respite.php`
- `docs/MEDICAL_MODULE_GAP_ANALYSIS.md`
- `docs/hr-module-design.md`
- `docs/hr-module-checklist.md`

External sources:

- ShiftCare Help Center: https://help.shiftcare.com/
- ShiftCare medication management: https://help.shiftcare.com/en/articles/12378432-medication-management-in-shiftcare
- ShiftCare Family Portal: https://shiftcare.com/us/care-management-software/shiftcare-connect
- ShiftCare support worker app: https://shiftcare.com/us/shiftcare-app
- eCase disability care software (NZ): https://healthmetrics.co.nz/disability-care-software/
- eCase case study with St John of God Hauora Trust: https://healthmetrics.co.nz/ecase-drives-operational-change/
- Webcare Staff App: https://www.webcare.co.nz/webcare-staff-app
- AlayaCare EVV: https://alayacare.com/wp-content/uploads/2023/06/electronic-visit-verification.pdf
- AlayaFlow: https://alayacare.com/en-au/alayaflow-anz/
- Whaikaha supported living service specification: https://www.disabilitysupport.govt.nz/assets/Service-Specification-Supported-Living1.pdf
- Office of the Privacy Commissioner, Health Information Privacy Code 2020: https://www.privacy.org.nz/privacy-principles/codes-of-practice/hipc2020/
