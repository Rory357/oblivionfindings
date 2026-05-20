# Governance Module — Intensive Audit & Refactor Plan

> Branch: `feature/governance-module-intensive-audit`
> Audited: 2026-05-20
> Scope: Full inspection of [`app/Domain/Governance/`](../app/Domain/Governance/), [`routes/governance.php`](../routes/governance.php), [`resources/js/pages/Governance/`](../resources/js/pages/Governance/), and integration surface with Finance, HR, Roadmap, Clinical, Incidents, H&S, Sites, Control Room, Assets, Fleet, Safeguarding.
> Companion doc: [`docs/governance-privacy-consents-readiness-plan.md`](governance-privacy-consents-readiness-plan.md) (prior, narrower audit).

---

## TL;DR — Verdict

> **Recommendation: Option B — heavy refactor, not rebuild.**

The Governance module is **not** the "messy half-built thing" it feels like from the outside. The bones are substantial: 19 controllers, ~50 models, 13 services (~3,800 LoC), 8 jobs, 5 policies, 31 permissions, 60 Inertia pages, dedicated factories, ~14 feature tests, ~6 browser tests. A previous "full redesign" migration ([`2026_02_19_200000_governance_full_redesign.php`](../database/migrations/2026_02_19_200000_governance_full_redesign.php)) shows the module has already been through one big iteration and most concepts are correct for a supported-living board.

What makes it **feel** wrong is not the foundation — it is:

1. **Navigation overload** — 18 sidebar items, no grouping, no priority. The board chair, CEO, and audit user see the same flat 18-item list.
2. **Dashboard is policy/risk/workflow heavy, not executive-friendly** — no budget-vs-actual, no sites over budget, no spend-approval queue, no financial-risk widgets. The Dashboard does not surface what an executive or board member opens the app to see.
3. **Three duplicated source-of-truth conflicts**: Budgets (Governance vs Finance.SiteBudgetLine), Strategic Planning (Governance.StrategicPlan vs Roadmap.Initiative), Compliance (Governance.ComplianceObligation vs HR.HrComplianceRequirement).
4. **Orphaned audit infrastructure** — `GovernanceAuditService` exists and writes to two tables, but **is never called from any controller**. The module advertises an audit trail it does not produce.
5. **Mixed authorization patterns** — some controllers use Spatie permission checks (`abort_unless($user?->canDo(...))`), others use policy-based `$this->authorize()`. Inconsistent gating risks bypass on refactor.
6. **Half-finished pages** — Te Tiriti is index-only, Documents is list-only, Packs is show-only, Clinical is two-page stub.
7. **Some workflows are wired but never fire** — `RecurringMeetingSchedule` has a full table but no job to spawn meetings. `PolicyAttestation` is referenced in code paths but lacks a controller surface.

A rebuild would destroy ~13,000 lines of legitimate working functionality (board meetings, voting, RSVP, board packs with PDF generation, risk scoring service with heatmap, compliance engine with NZ frameworks, CEO reports, board interests register, performance reviews, strategic plans, evidence library, Te Tiriti tracker, clinical governance automation, incident escalation, multi-stage budget approvals). Most of that work is correct. **The problem is not "what got built" — it is "how it is composed, prioritised, and surfaced to executives and board members."**

A polish-only pass would leave the duplicate-source-of-truth bombs ticking and the audit-trail credibility gap unfixed.

**Heavy refactor lets us keep the substance, fix the structural problems, and make the module executive-friendly without throwing away working code.**

---

## Phase 1 — Audit findings

### 1.1 What the module currently does (concise narrative)

A board secretary can: schedule board and committee meetings (with recurring schedule templates), generate agendas, distribute board packs as PDF, track RSVPs, record attendance and quorum, capture and approve minutes, lock and sign-off meetings, propose resolutions, open and close voting, declare conflicts of interest, and finalise outcomes.

A board chair / CEO / audit role can: log organisational risks (with inherent/residual scoring on 1-5 scales, control effectiveness multiplier, appetite thresholds per category), generate a risk heatmap, link incidents/alerts/breaches/complaints to risks, accept above-appetite risks against a board resolution, schedule risk treatments, generate trends.

A compliance owner can: define obligations against 10+ NZ frameworks (Charities, Ngā Paerewa, HDSA, Privacy Act, HSWA, HIP Code, Employment, MoH/MSD/ACC funding), set frequency (monthly/quarterly/annual/ad-hoc/event-driven), schedule reminders, upload evidence, mark complete, log notifiable incidents to WorkSafe/Health NZ/Privacy Commissioner.

A CEO can: write pre-board reports with structured sections (operational summary, achievements, challenges, staffing, compliance, financial, recommendations). The CEO report binds into the board pack.

A board chair can: approve governance policies (with versioning + supersession + effective dates + review due dates), trigger policy attestation, run board self-evaluations, manage the board interests register, oversee performance reviews and KPIs.

A CFO / finance lead can: propose annual budgets, define budget line items per category (with GL account mapping), submit for board approval (carried via Resolution), record actuals, request adjustments above thresholds.

Underneath: a `DashboardAggregatorService` pulls cross-domain snapshots (risk, incident, safeguarding, clinical, workforce, financial, fleet, IT) into a unified executive cockpit. A `GovernanceWorkflowService` (~660 LoC) ranks open actions across meetings/resolutions/risks/compliance/budgets/follow-through into a single prioritised workflow list. A `ClinicalGovernanceAutomationService` consumes domain clinical events to auto-generate governance indicator snapshots.

### 1.2 Routes, controllers, models, policies, tests — current inventory

**Routes** ([`routes/governance.php`](../routes/governance.php), 337 lines, all under `governance.*`): 14 functional groups — dashboard, reports, meetings (incl. agenda/minutes/attendance/RSVP/lock/sign), board packs, resolutions (incl. vote/conflict/finalize), board-member admin, risks (incl. heatmap/trends/committee/accept/close/treatment/link-event), compliance (incl. calendar/notifiable-incident/complete/evidence), performance reviews, strategic plans, budgets (with line items and adjustments), action items, policies (with versioning/attestation/approval), CEO reports, board interests, board evaluations, governance documents, clinical governance, Te Tiriti.

**Controllers (19)**: [`DashboardController`](../app/Domain/Governance/Http/Controllers/DashboardController.php), [`GovernanceMeetingController`](../app/Domain/Governance/Http/Controllers/GovernanceMeetingController.php), [`ResolutionController`](../app/Domain/Governance/Http/Controllers/ResolutionController.php), [`RiskRegisterController`](../app/Domain/Governance/Http/Controllers/RiskRegisterController.php), [`ComplianceController`](../app/Domain/Governance/Http/Controllers/ComplianceController.php), [`PerformanceReviewController`](../app/Domain/Governance/Http/Controllers/PerformanceReviewController.php), [`BoardPackController`](../app/Domain/Governance/Http/Controllers/BoardPackController.php), [`StrategicPlanController`](../app/Domain/Governance/Http/Controllers/StrategicPlanController.php), [`BudgetController`](../app/Domain/Governance/Http/Controllers/BudgetController.php), [`BoardMemberAdminController`](../app/Domain/Governance/Http/Controllers/BoardMemberAdminController.php), [`ReportController`](../app/Domain/Governance/Http/Controllers/ReportController.php), [`ActionItemController`](../app/Domain/Governance/Http/Controllers/ActionItemController.php), [`GovernancePolicyController`](../app/Domain/Governance/Http/Controllers/GovernancePolicyController.php), [`CeoBoardReportController`](../app/Domain/Governance/Http/Controllers/CeoBoardReportController.php), [`BoardInterestController`](../app/Domain/Governance/Http/Controllers/BoardInterestController.php), [`BoardEvaluationController`](../app/Domain/Governance/Http/Controllers/BoardEvaluationController.php), [`GovernanceDocumentController`](../app/Domain/Governance/Http/Controllers/GovernanceDocumentController.php), [`ClinicalGovernanceController`](../app/Domain/Governance/Http/Controllers/ClinicalGovernanceController.php), [`TeTiritiController`](../app/Domain/Governance/Http/Controllers/TeTiritiController.php).

**Models (~50)** in [`app/Domain/Governance/Models/`](../app/Domain/Governance/Models/): ActionItem, AuditEvidencePack, BoardCommittee, BoardEvaluation, BoardEvaluationResponse, BoardMember, BoardMemberInterest, BoardMemberPreference, BoardPack, BoardSkillsMatrix, BreakGlassAccess, Budget, BudgetAdjustment, BudgetLineItem, CeoBoardReport, ClinicalGovernanceIndicator, ClinicalGovernanceSnapshot, CommitteeMembership, ComplianceEvidence, ComplianceObligation, ComplianceReminder, ConflictDeclaration, DashboardSnapshot, EvidenceLibrary, GovernanceDocument, GovernanceFeedbackEscalation, GovernanceMeeting, GovernancePolicy, IncidentGovernanceEscalation, MeetingAgendaItem, MeetingAttendance, MeetingMinute, MeetingRsvp, NotifiableIncident, PerformanceFeedback, PerformanceGoal, PerformanceKpi, PerformanceReview, PolicyAttestation, RecurringMeetingSchedule, Resolution, RiskAcceptance, RiskAppetiteSetting, RiskEventLink, RiskHeatmapSnapshot, RiskRegisterEntry, RiskTreatment, StrategicGoal, StrategicInitiative, StrategicPlan, TeTiritiObligation, Vote.

**Services (13)** in [`app/Domain/Governance/Services/`](../app/Domain/Governance/Services/): `GovernanceAuditService` (38 LoC — orphaned, see §1.3), `GovernanceWorkflowService` (662 LoC), `DashboardAggregatorService` (554 LoC), `ComplianceEngineService` (380 LoC), `RiskScoringService` (295 LoC), `VotingService`, `PerformanceReviewService`, `RecurringMeetingService`, `BoardPackBuilderService`, `AuditEvidencePackService`, `EvidenceLibraryService`, `ClinicalGovernanceAutomationService`, `IncidentEscalationService`.

**Jobs (8)**: `CaptureRiskHeatmapSnapshot`, `CheckComplianceDeadlines`, `EscalateOverdueActionItems`, `GenerateBoardPack`, `SendBoardDigest`, `SendBoardPackNotification`, `SendPreReadReminders`, `SendVotingReminder`.

**Policies (5)** in [`app/Domain/Governance/Policies/`](../app/Domain/Governance/Policies/): `BudgetPolicy`, `GovernanceMeetingPolicy`, plus implicit policies for risk/resolution/action. Inconsistent — see §1.3.

**Migrations (3)**: [`2026_02_06_100010_create_governance_meetings_table.php`](../database/migrations/2026_02_06_100010_create_governance_meetings_table.php), [`2026_02_11_000001_enhance_governance_module.php`](../database/migrations/2026_02_11_000001_enhance_governance_module.php), [`2026_02_19_200000_governance_full_redesign.php`](../database/migrations/2026_02_19_200000_governance_full_redesign.php).

**Seeders (3)**: [`GovernanceSeeder`](../database/seeders/GovernanceSeeder.php), [`GovernancePermissionsSeeder`](../database/seeders/GovernancePermissionsSeeder.php) (31 permissions, 4 board roles), [`GovernancePrivacyConsentsReadinessSeeder`](../database/seeders/GovernancePrivacyConsentsReadinessSeeder.php).

**Tests**: 14 Feature tests in [`tests/Feature/Governance/`](../tests/Feature/Governance/) (ActionItems, BoardMemberAdmin, BoardMemberSelfService, BoardPacks, Budgets, Compliance, Dashboard, Meetings, PerformanceReview, Reports, Resolutions, RiskRegister, Strategy). 6 Browser tests in [`tests/Browser/Governance/`](../tests/Browser/Governance/) (Compliance, Dashboard, ExecutiveJourneys, Meetings, Misc, Policies, Risks). 2 Unit tests for `GovernancePresenter` and `GovernanceWorkflowService`.

**Frontend pages**: 60 Inertia pages across 19 sub-folders under [`resources/js/pages/Governance/`](../resources/js/pages/Governance/) + the Dashboard. Total ~13,200 LoC. Median page 120 lines, largest ~520.

**Navigation**: [`resources/js/pages/Governance/Components/GovernanceNav.tsx`](../resources/js/pages/Governance/Components/GovernanceNav.tsx) is the in-module sidebar — 18 flat items, one conditional on `governance.meetings.manage`.

### 1.3 What's actually wrong — structural findings

**S1. Orphaned audit infrastructure.** [`GovernanceAuditService`](../app/Domain/Governance/Services/GovernanceAuditService.php) writes to `governance_audit_log` and `governance_change_log` tables, but a grep across all controllers turns up zero call sites. Budget approval, risk acceptance, compliance complete, policy approval, resolution finalize, meeting lock-and-sign — none of them log to the audit service. **A governance module without an audit trail is not a governance module.** This is the single biggest credibility gap.

**S2. Mixed authorization (policy vs. permission check).** [`ComplianceController`](../app/Domain/Governance/Http/Controllers/ComplianceController.php) uses `abort_unless($user?->canDo('governance.compliance.manage'))`. [`BudgetController`](../app/Domain/Governance/Http/Controllers/BudgetController.php) uses `$this->authorize('create', Budget::class)`. Half the controllers don't use the registered policies at all. If a future change updates a policy expecting it to gate access, the permission-direct paths will silently bypass it.

**S3. Budgets — duplicated source of truth.** Two parallel budget systems:
- **Governance**: [`Budget`](../app/Domain/Governance/Models/Budget.php) → annual, board-approved, organisation-wide, with `BudgetLineItem.gl_account_id` FK to `FinAccount`. Status: drafting → proposed → approved.
- **Finance**: `SiteBudgetLine` ([`2026_04_09_220000_create_site_budget_system.php`](../database/migrations/2026_04_09_220000_create_site_budget_system.php)) → monthly, per-site, per-category, with separate variance calculation.

A `BudgetActualsService` in Finance reads Governance.Budget and syncs actuals from `FinJournalLine`. A `BudgetVarianceService` reads `SiteBudgetLine` and calculates variances from `FinCostAllocation`. **Neither system knows the other exists.** No board view of site-level overspend. No site-level view of board-approved budget. No reconciliation.

**S4. Strategic planning — duplicated source of truth.** Governance has `StrategicPlan`, `StrategicGoal`, `StrategicInitiative`. [`app/Domain/Roadmap/`](../app/Domain/Roadmap/) has `Initiative`, `QuarterlyRoadmapPlan`, `InitiativeBudget`, `InitiativeMilestone`, `DecisionRequest`. Both are strategic planning constructs. There is a `RoadmapDashboardService` that gets called from `DashboardAggregatorService`, but no FK linking Roadmap.Initiative to Governance.StrategicGoal. **There is no defined authority answer to "what is our strategic plan, and which quarter's roadmap fulfills it?"**

**S5. Compliance — duplicated source of truth.** Governance owns `ComplianceObligation` (org-level frameworks). HR owns `HrComplianceRequirement` and `HrComplianceMatrix` (staff-level certifications, training expiry). There is no defined boundary or cross-reference. A board chair viewing "compliance" sees Governance obligations only; an HR manager viewing "compliance" sees only HR matrix data. The board never sees workforce certification status; HR never sees regulator obligations.

**S6. Spend approval has no gate.** [`FinBill`](../app/Domain/Finance/Models/FinBill.php) bills can be approved by any user with `finance.ap.manage`. No threshold-based escalation to Board / Finance Committee. No link to `Resolution`. A $50k bill is approved exactly the same way a $50 bill is. There is no governance.spend.approve flow despite the module advertising "approvals."

**S7. Dashboard is not executive-friendly.** [`Dashboard.tsx`](../resources/js/pages/Governance/Dashboard.tsx) shows workflow center + role actions + cockpit sections. The cockpit pulls from incidents, risks, compliance, clinical, workforce, fleet, safeguarding — but **not** budget-vs-actual, sites-over-budget, pending spend approvals, capex queue, financial risks, or board approval queue. The board chair opens this and does not see the financial side of their accountability.

**S8. Navigation overload.** 18 flat sidebar items. No grouping. No "what needs my attention this week" view that isn't just the workflow center buried inside the dashboard. A new board member needs 5 minutes to find anything.

**S9. Half-finished surfaces.** Te Tiriti (index only, no detail/edit), Documents (list only, no detail), Packs (show only — no list, no create), Clinical (2-page stub). These advertise capability the rest of the system does not deliver.

**S10. Workflow features wired but not running.** `RecurringMeetingSchedule` table is full of columns (frequency, day_of_month, default_chair_id, preread_days_before) — no job spawns meetings from it. `IncidentGovernanceEscalation` model exists but most paths from `ClientIncident` to governance go through `IncidentEscalationService` without writing the escalation record. Two compliance flows (escalation and reminder) share one `ComplianceReminder` table with overlapping semantics.

**S11. Missing transaction boundaries.** Budget proposal (creates resolution, updates budget, sends notification) is not wrapped in a transaction. Meeting lock-and-sign (multiple table updates) is not wrapped. A partial failure leaves orphan resolutions or half-locked meetings.

**S12. Permission/role mismatch.** Permissions seeder grants `governance.budgets.approve` to `board_chair` directly. But the controller logic checks for a carried resolution — `board_chair` cannot approve a budget without a resolution. The permission name suggests one model; the controller enforces another. Confusing for anyone reading the seeder.

**S13. TypeScript looseness in board-facing pages.** `auth: any`, hard-coded `(auth.can as any)?.governance?.budgets?.create` permission checks in JSX. CEO reports has arrays typed as `any[]` (`operational_highlights`, `financial_summary`). Refactoring becomes risky because the compiler can't catch breakage.

**S14. Duplicated status-colour logic.** Every page redefines its own `getStatusColor()` / `getRiskColor()` / `getComplianceStatusColor()`. 60 pages × ~20 lines of colour logic each = ~1,200 lines that should be 200.

**S15. Hard-coded UI behaviour in JSX.** Permission checks in render paths, no shared empty-state component (every page reimplements "No X yet."), no shared filter component, no pagination UI rendered even though backend serves Inertia pagination links.

### 1.4 What's right and should be preserved

Despite the structural issues, a lot of this module is correct and valuable. Anything in this list **must survive the refactor**.

| # | Feature | Why it's worth keeping |
|---|---|---|
| K1 | Board meeting lifecycle (scheduled → agenda → in_progress → minutes_draft → approved → signed → archived) | Maps real board procedure; statuses are correct |
| K2 | RSVP + attendance + quorum calculation | Necessary for valid resolutions |
| K3 | Resolution voting with conflict declarations + threshold | Correct governance practice; vote model is sound |
| K4 | Risk register with inherent/residual scoring + appetite thresholds + heatmap | NZ-style risk maturity; covers ISO 31000 patterns |
| K5 | Risk → incident/alert/breach/complaint linking via `RiskEventLink` polymorphic | Excellent design; foundation for cross-module event surfacing |
| K6 | Compliance obligations across 10+ NZ frameworks (Ngā Paerewa, HDSA, Privacy Act, HSWA, MoH funding, etc.) | Correct framework list; the seeded obligations are right |
| K7 | Compliance evidence upload + sign-off + reminders | Correct audit-readiness pattern |
| K8 | NotifiableIncident — WorkSafe / Health NZ / Privacy Commissioner / Charities Services | Regulatory escalation table; correct categorisation |
| K9 | Policy versioning + supersession + approval-via-resolution | Correct policy-management discipline |
| K10 | Board pack PDF generation + distribution + read tracking | Time-saving; preserves audit trail |
| K11 | CEO board report structure (operational / risks / compliance / financial / recommendations) | Aligned with NZ NFP board reporting |
| K12 | Board interests register with declaration dates + ceased dates + nature | Conflict-of-interest discipline |
| K13 | Board self-evaluations (board, chair, individual) | Governance maturity; supports continuous improvement |
| K14 | Te Tiriti o Waitangi obligations tracker | NZ-specific; required for many funded services |
| K15 | Clinical governance indicators + snapshots automated from ClinicalEvent | Strong cross-domain pattern; copy this design for other modules |
| K16 | Recurring meeting schedule (table; just needs the job) | Saves significant secretary time once wired |
| K17 | Risk acceptance requiring resolution link if above-appetite | Correct governance pattern; rare to find done right |
| K18 | Multi-stage budget approval (drafting → proposed → approved via resolution) | Correct in principle |
| K19 | Dashboard workflow center (top prioritised actions across domains) | Useful pattern; needs financial dimension added |
| K20 | DashboardAggregatorService cross-domain snapshots | Useful pattern; preserves modules' source-of-truth |

### 1.5 What's duplicated, broken, half-built, or unfit

| # | Feature | Diagnosis | Disposition |
|---|---|---|---|
| R1 | `GovernanceAuditService` | Defined, not called anywhere | **Wire into controllers**; do not delete |
| R2 | Mixed `abort_unless` + `authorize()` patterns | Inconsistent gating | **Standardise on policies**; keep permissions seeder |
| R3 | Governance.Budget vs Finance.SiteBudgetLine | Duplicated budgets, no cross-reference | **Decide one source of truth**; refactor — see §3.4 |
| R4 | Governance.StrategicPlan vs Roadmap.Initiative | Duplicated strategic constructs | **Define Strategy → Roadmap hierarchy**; add FK |
| R5 | Governance.ComplianceObligation vs HR.HrComplianceRequirement | Duplicated obligation registries | **Define org vs workforce boundary**; add link |
| R6 | RecurringMeetingSchedule with no spawn job | Half-built | **Add scheduled job**; small fix |
| R7 | PolicyAttestation (no controller surface) | Half-built | **Add attestation flow**; surface in CEO/board UI |
| R8 | Te Tiriti — index only | Half-built | **Add detail + edit pages** or move to read-only widget |
| R9 | Documents — list only | Half-built | **Add detail + folder structure** or merge into Evidence Library |
| R10 | Packs — show only, no list/create | Inconsistent CRUD surface | **Add list page**; create is via meeting context (OK) |
| R11 | Clinical — 2-page stub | Half-built | **Add indicator CRUD + snapshot history**; or scope down |
| R12 | Duplicated status-colour logic | 60 pages with own colour maps | **Centralise** in `lib/governance-styles.ts` |
| R13 | `any`-typed auth/props | Loose typing | **Tighten via PageProps extension** |
| R14 | `IncidentGovernanceEscalation` model | Likely under-used | **Wire properly into `IncidentEscalationService`** |
| R15 | Two-table audit log (governance_audit_log + governance_change_log) | Two destinations; no query interface | **Consolidate to one + read API**; or both via `AuditLogger` (the global one) |
| R16 | `GovernanceWorkflowService::dashboardWorkflow()` ranking | Hand-rolled ranking; works but opaque | **Keep, refactor for clarity** |
| R17 | DashboardController exception swallow (per-widget try/catch with log warning) | Hides permanent breakage | **Surface unavailable widgets to the user** |
| R18 | Compliance reminder escalation hardcoded to level 3 / user_id=1 | Won't scale; brittle | **Make configurable via RiskAppetiteSetting-style config** |
| R19 | No spend-approval workflow | Missing capability | **Add `SpendApproval` model linked to FinBill + Resolution** |
| R20 | Workflow tasks never wrapped in transactions | Risk of inconsistent state | **Wrap budget propose/approve, meeting lock/sign, resolution finalize** |

### 1.6 Why the current module feels confusing (the UX root cause)

A new user opens `/governance/dashboard`. They see:
1. A "Workflow Center" card with mixed-domain actions
2. "Role Actions" buttons
3. 4-8 "Cockpit Sections" each containing 3-6 cards each containing 2 metrics + 3 highlights + a CTA

Then they look at the sidebar. **Eighteen items.** No grouping. No "this is what you do today" vs "this is what you review monthly" vs "this is what is configuration."

Then they click **Compliance**. They see a list of obligations across 10 different NZ regulatory frameworks. Filters exist but no UI shows them. Pagination links exist but no UI renders them.

A board member who logs in 6 times a year for board meetings does not need to see 18 sidebar items, only the 5 they touch. A CEO who manages compliance and policies and the CEO report does not need to see board self-evaluations daily. **Governance is not one user's job — it is many roles, and the current UI does not adapt to which role you are.**

Combined with the duplication issues (you can budget here OR in finance, you can plan strategy here OR in roadmap), the module communicates "we built lots of things, we don't know which one is canonical."

---

## Phase 2 — Recommendation

### 2.1 Verdict: Option B — heavy refactor

**Not Option A (keep + polish):** The duplicate-source-of-truth issues (S3, S4, S5) cannot be fixed by polish. The audit-trail credibility gap (S1) is structural. The dashboard's missing financial dimension (S7) is structural. Polish alone produces a more attractive broken thing.

**Not Option C (rebuild):** ~13,000 frontend LoC and ~3,800 service LoC of legitimate, well-architected work would be thrown away. The previous "full redesign" migration is a warning sign — successive rebuilds of large modules in non-production codebases is a classic anti-pattern. The bones are correct; what needs work is composition, integration, and surface.

**Option B is right because:**
- Most of the data model is correct and salvageable.
- The most painful problems (duplication, orphaned audit, exec-friendly dashboard) are addressable without schema rebuilds.
- We can sequence the refactor as PR-style increments, each verifiable and reversible.
- Existing tests cover most workflows — we can move with confidence.

### 2.2 Risks of the chosen option

| Risk | Mitigation |
|---|---|
| Half-completing the refactor leaves it worse than before | Sequence PRs so each lands a usable improvement; never split a single concept across two PRs |
| Touching `BudgetController` or `ComplianceController` breaks tests | Run targeted Pest filters per PR; never merge a PR with regressing tests |
| Removing the duplicate Strategic/Compliance/Budget surfaces takes user-facing functionality away | Each removal PR must be preceded by the replacement PR landing first |
| Wiring `GovernanceAuditService` into every write path is invasive | Use a single Service+Trait pattern + tests; not 19 controller edits hand-rolled |
| New financial dashboard widgets require Finance team buy-in | Phase 4 sequence puts dashboard work AFTER the Finance integration contract is defined |

### 2.3 Feature preservation contract

Every item in §1.4 (K1–K20) must survive. Every item in §1.5 has an explicit disposition. Before we delete or rip anything from §1.5, the replacement must be in place and tested.

---

## Phase 3 — Target design

### 3.1 Module purpose statement (the test of every future decision)

> Governance is the **oversight and accountability layer** across Oblivion Findings. It does not own operational data. It pulls signals from Finance, HR, Sites, Incidents, H&S, Clinical, Safeguarding, Assets, Fleet, and surfaces what executives and board members need to **decide, approve, escalate, or attest to.**

### 3.2 Information architecture — reduce 18 items to 6 groups

```
Governance
├── Overview                       (the new exec dashboard)
├── Board & Meetings
│   ├── Meetings (+ Calendar)
│   ├── Resolutions & Voting
│   ├── Board Packs
│   ├── Action Items
│   └── Board Admin (members, committees, interests, evaluations, skills)
├── Risk & Compliance
│   ├── Risk Register (+ Heatmap, Trends)
│   ├── Compliance Register (+ Calendar)
│   ├── Notifiable Incidents
│   └── Te Tiriti Obligations
├── Policies & Evidence
│   ├── Policies (+ Attestations)
│   ├── Evidence Library
│   ├── Governance Documents
│   └── Audit Log
├── Strategy & Performance
│   ├── Strategic Plan (links to Roadmap)
│   ├── Performance Reviews
│   └── CEO Board Reports
└── Financial Governance              ← new top-level group
    ├── Budgets & Approvals
    ├── Spend Approvals (capex/opex over threshold)
    ├── Site / House Variance
    ├── Funding & Donor Compliance
    └── Financial Risks (linked to Risk Register)
```

Six groups in a collapsible sidebar. Each group sets context for sub-items. New board members find their way in seconds.

### 3.3 Overview dashboard — what executives actually need

A single page divided into **5 horizontal bands**, each one scannable in <5 seconds:

**Band 1 — "What needs my attention this week"** (the workflow center, but role-filtered)
- Critical risk items above appetite
- Overdue compliance obligations
- Pending board approvals (resolutions awaiting my vote / spend awaiting my sign-off)
- Policies due for review in next 30 days
- Notifiable incidents pending external report

**Band 2 — "How are we tracking financially"** (NEW)
- Budget vs actual (YTD) — single number with delta
- Sites/houses over budget — count and worst three
- Funding gaps — funds where commitments exceed available balance
- Capex/large spend awaiting approval — queue length
- Financial-risk count linked to risk register

**Band 3 — "How is the organisation performing operationally"**
- Open critical risks (count)
- Overdue compliance (count)
- Open notifiable incidents (count)
- Safeguarding concerns escalated (count)
- Workforce certification non-compliance (% — pulled from HR)

**Band 4 — "Upcoming governance"**
- Next 3 meetings (date, type, RSVP-needed, pre-read status)
- Pending resolutions (count, deadline)
- CEO report status for next meeting (draft/submitted/pending)
- Board pack readiness for next meeting

**Band 5 — "Recent decisions and changes"**
- Last 5 resolutions carried (with amounts where relevant)
- Last 5 audit-log events (linked changes)
- Last 3 policies approved

Each band uses the existing `DashboardAggregatorService` but extended with finance widgets. Each card has a clear CTA. No more "Cockpit Sections" with nested metric grids that don't tell a story.

### 3.4 Data-model corrections (no rebuilds — targeted changes)

**Budgets (highest priority):**

Two valid models in one platform — annual board-approved budget (Governance) vs. monthly operational site budget (Finance). They are different things. Make that explicit:

- Keep `Governance.Budget` for **annual organisational budget** approved by board.
- Keep `Finance.SiteBudgetLine` for **monthly per-site variance tracking**.
- Add new model `Governance.BudgetAllocation` — links `Budget.id` → many `SiteBudgetLine.id`. A single board-approved budget allocates funds to many site-month buckets. This makes the parent-child relationship explicit.
- Governance dashboard reads aggregated site variance from `SiteBudgetLine`; never duplicates the underlying data.
- Move `BudgetLineItem.gl_account_id` to be advisory only (cache the FinAccount code as a string, do not enforce FK). The chart of accounts belongs to Finance.

**Strategy:**

- Keep `Governance.StrategicPlan` + `StrategicGoal` as the 3–5 year vision.
- Add `Roadmap.Initiative.strategic_goal_id` FK (nullable; backfill later).
- The Strategy page shows: goals → which initiatives realise them → which quarterly roadmap plans they appear in. Pulls from Roadmap; never duplicates.

**Compliance:**

- Keep `Governance.ComplianceObligation` for **regulator-facing obligations** (Charities, MoH, WorkSafe, Privacy Act etc).
- Keep `HR.HrComplianceRequirement` for **workforce-facing requirements** (training certifications, supervision records).
- Add `Governance.ComplianceObligation.workforce_requirement_id` (nullable FK to `HrComplianceRequirement`) for obligations that have a workforce dimension (e.g. "All staff complete Health & Safety induction" — the obligation lives in Governance, the underlying training records live in HR).
- Add a new dashboard widget that pulls `% of workforce compliant with all required certifications` from HR for the Governance compliance dashboard.

**Spend approval (new):**

- Add `Governance.SpendApproval` model: morphs to FinBill, FinPurchaseOrder, FinPaymentRun, or a free-form "future spend commitment" record.
- Lifecycle: pending → approved (by user with permission) → rejected → expired.
- Above-threshold (configurable per type — default $5,000) requires a Resolution link.
- Add `FinBill.spend_approval_id` and `FinPurchaseOrder.spend_approval_id` (nullable).
- Pre-existing finance bills with no approval keep working — only NEW bills above threshold require it. No breaking change.

**Audit trail:**

- Consolidate `governance_audit_log` and `governance_change_log` into one read interface, even if the tables stay (a `GovernanceAuditEntry` view-model).
- Wire `GovernanceAuditService::log()` into every controller-level write path via a trait `LogsGovernanceWrites` that hooks `created/updated/deleted` events for governance models. Stops being something controllers remember to call.
- Add a new `/governance/audit-log` page (admin-gated) showing filtered audit entries.

**Cross-module event wiring (no schema change — just service integration):**

- `IncidentEscalationService` → on incident with severity ≥ critical, write a `IncidentGovernanceEscalation` row AND raise a `RiskEventLink` if not already linked. Make the link automatic.
- `SafeguardingConcern` → on creation with severity ≥ severe, auto-create `NotifiableIncident` if external reporting required.
- `FinDonorFund.next_report_due` → scheduled job creates `ComplianceObligation` 30 days before due.
- `Site.risk_review_date` → scheduled job creates a `RiskRegisterEntry` review action 14 days before due.

### 3.5 Permissions consolidation (no fundamental change — just enforcement)

- Keep the 31 existing permissions.
- **Add** `governance.spend.approve` and `governance.spend.view` for the new spend approval flow.
- **Add** `governance.audit.view` (admin / audit role).
- **Move** all controllers from `abort_unless($user?->canDo(...))` to `$this->authorize(...)` on policy methods. Permissions still drive the policy; the controllers all go through one gate.

### 3.6 UI/UX directives

- **6-group sidebar** (§3.2) replacing the 18-flat-item nav. Each group expands/collapses.
- **New Overview page** (§3.3) replacing the current Dashboard.
- **Centralise status colours** into [`resources/js/lib/governance-styles.ts`](../resources/js/lib/governance-styles.ts) — one source of truth.
- **Shared empty-state component** (`<EmptyState>`) — replace ~60 ad-hoc empty messages.
- **Shared filter component** (`<FilterBar>`) — Risks, Compliance, Budgets get visible filter UI.
- **Pagination component** — render backend Inertia pagination links as prev/next buttons.
- **Typed PageProps extension** for governance — kill the `auth: any` and `(auth.can as any)` patterns.
- **Keep**: PageHero pattern, card-based lists, AppLayout wrapper.

### 3.7 Integration contract (Governance is consumer, not owner)

For each cross-module signal, define ownership:

| Concept | Owner | Governance role |
|---|---|---|
| Sites, houses, addresses | Sites | Link only |
| Clients | Clients | Link only |
| Staff, training, supervision | HR | Link + summary widgets |
| Shifts, rostering | Shifts | Summary widgets |
| Incidents | Incidents | Escalate via `IncidentGovernanceEscalation`, link via `RiskEventLink` |
| Hazards | H&S | Summary widgets + risk linking |
| Live alerts | Control Room | SLA metric in dashboard |
| Clinical events | Clinical | Indicator snapshots via `ClinicalGovernanceAutomationService` |
| Safeguarding | Safeguarding | Escalate to `NotifiableIncident` |
| Bills, invoices, journals, GL | Finance | Link to spend approvals; pull variance for dashboard |
| Budgets | Finance owns site-level monthly; Governance owns annual board-approved | Link via `BudgetAllocation` |
| Funding streams, donor funds | Finance | Generate `ComplianceObligation` for reporting deadlines |
| Assets, depreciation | Assets + Finance | Pull asset depreciation as cost-driver |
| Fleet maintenance, telemetry | Fleet | Escalate overdue maintenance to risk register |
| Documents (operational) | each domain | Link from `EvidenceLibrary` |
| Board members | Governance (own) | Source of truth |
| Resolutions, votes, meetings | Governance (own) | Source of truth |
| Policies | Governance (own) | Source of truth; HR attests on staff side |
| Strategic plan / goals | Governance (own) | Source of truth; Roadmap.Initiative subscribes |
| Roadmap initiatives | Roadmap (own) | Link FK to `StrategicGoal` |

---

## Phase 4 — Implementation plan (PR-style)

Sequenced from foundation → integration → UI. Every PR ends with green tests.

| PR | Title | Scope | Risk |
|---|---|---|---|
| **P1** | Wire audit service into every governance write path | Add `LogsGovernanceWrites` trait; hook into 20+ models; one new `/governance/audit-log` admin page; tests | Low — additive |
| **P2** | Standardise authorization to policies | Move all `abort_unless($user?->canDo)` calls to `$this->authorize()`; add missing policies; tests for each | Medium — touches 19 controllers |
| **P3** | Add SpendApproval model + flow | New model, table, controller, page, integration with `FinBill` / `FinPurchaseOrder`; permission `governance.spend.approve` | Medium — finance touchpoint |
| **P4** | Strategy ↔ Roadmap reconciliation | Add `Roadmap.Initiative.strategic_goal_id` FK; backfill nullable; strategy page now pulls roadmap data | Low — additive |
| **P5** | Compliance ↔ HR reconciliation | Add `ComplianceObligation.workforce_requirement_id` FK nullable; new widget "workforce certification %" | Low — additive |
| **P6** | Budget allocation model + finance integration | Add `BudgetAllocation` linking annual `Budget` → monthly `SiteBudgetLine`; financial dashboard widgets pull from this | Medium — schema add; backfill |
| **P7** | RecurringMeetingSchedule spawn job | Add scheduled command `governance:spawn-recurring-meetings`; uses existing table | Low |
| **P8** | Cross-module auto-escalation wiring | `IncidentEscalationService` writes `IncidentGovernanceEscalation`; `SafeguardingConcern` → `NotifiableIncident`; `FinDonorFund.next_report_due` → `ComplianceObligation` job | Medium — observers/jobs |
| **P9** | Frontend foundation refactor | Centralise status colours, EmptyState component, FilterBar component, Pagination component, typed PageProps; update 5 most-used pages first | Low |
| **P10** | New Overview dashboard | Replace `Dashboard.tsx` with 5-band Overview; new finance widgets; preserve existing widgets behind feature flag | Medium — user-facing |
| **P11** | 6-group collapsible sidebar | Replace `GovernanceNav.tsx`; group existing pages; no page deletions | Low |
| **P12** | Half-finished page completion or scoping | Decide for each: Te Tiriti, Documents, Packs (list), Clinical — complete or formally scope down with deprecation notice | Low |
| **P13** | Transactions on multi-step workflows | Wrap budget propose/approve, meeting lock/sign, resolution finalize, risk acceptance in `DB::transaction()` | Low |
| **P14** | Compliance escalation made configurable | Replace hardcoded level-3 / user_id-1 with `RiskAppetiteSetting`-style config table | Low |
| **P15** | Audit log surface + filters + export | UI for the audit log; CSV/PDF export; integration with `AuditEvidencePack` | Low |

**This PR (the audit branch) lands P1, P2, and the audit document itself, plus laying the groundwork for P3.** Larger structural PRs (P3 onward) should be merged in sequence with verification gates.

---

## Phase 5 — What this PR actually changes

Defined in detail in [`docs/governance-module-intensive-audit-implementation.md`](governance-module-intensive-audit-implementation.md) (companion implementation doc — created when implementation begins).

The audit document itself (this file) is the deliverable for Phase 1 + 2 + 3 + 4. Implementation begins after user sign-off on the approach.

---

## Open questions for the user

1. **Scope of THIS PR.** Three options:
   - (a) Audit document only (this file). Lands an evidence-based plan, no code changes. User approves the plan, follow-up PRs do the work.
   - (b) Audit + Phase P1 (audit-service wiring) + P2 (authorization standardisation). Lands the foundation, no user-facing changes.
   - (c) Audit + P1 + P2 + P9 (frontend foundation) + P11 (6-group sidebar) + a minimal P10 Overview. User sees a refactored UI; risky integration work (P3, P4, P5, P6) follows in dedicated PRs.

2. **Budget ownership.** Confirm: Governance owns annual board-approved budget; Finance owns monthly site-level operational budget; they connect via `BudgetAllocation`. **OR** alternative: Finance owns all budgets; Governance is purely a viewer/approver. Need user direction here before P6.

3. **Te Tiriti, Documents, Packs (list), Clinical depth.** Each is currently a stub. Complete-or-scope-down decision required.

4. **Strategy vs Roadmap.** Confirm Governance is parent (StrategicPlan/StrategicGoal), Roadmap is child execution layer.

5. **Spend approval thresholds.** Default $5,000 for capex / large opex / supplier contract? Defaults configurable per cost-centre?

---

## Appendix — Audit method

This audit was performed by three parallel `Explore` subagents working against the worktree at `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\nice-jepsen-1cb058`:

- Agent 1: Governance backend deep map (controllers / services / models / migrations / permissions / policies / jobs).
- Agent 2: Governance frontend deep map (pages / components / layout / nav / forms / status patterns / TypeScript quality).
- Agent 3: Finance module map (60 models, 92 pages, budget ownership analysis, funding model, approval lifecycle, proposed integration points).
- Agent 4: Cross-module integration map (Incidents, H&S, Control Room, Sites, HR, Clinical, Assets, Fleet, Documents, Reports, Safeguarding, Roadmap, Privacy, Permissions, Audit, Notifications, Board/Executive).

Findings were cross-referenced against source files where critical (e.g. `routes/governance.php`, the three governance migrations, `GovernanceNav.tsx`, `Dashboard.tsx`).
