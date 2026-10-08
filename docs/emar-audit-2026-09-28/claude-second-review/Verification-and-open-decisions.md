# Verification record and open decisions

28 September 2026 · Second review of the eMAR audit · Read-only. The only files I wrote were these review documents, plus a temporary launch entry for the preview, which I removed afterwards. No application code, schema, configuration, clinical record or medication record was changed. No commit, push, deploy or new chat.

## 1. Baseline

| Item | Value |
|---|---|
| Review checkout | Worktree `.claude/worktrees/busy-shirley-877f95`, branch `claude/codex-emar-audit-review-e681d3`, HEAD `52dafa6728ebe343631453d4863f645d97c59506`, clean before this review |
| Main checkout | `C:\Users\steph\Herd\oblivionfindings` on `main`, same HEAD. Untracked `docs/emar-audit-2026-09-28/` (Codex) and `docs/fleet-assets-audit/follow-ups/` (not touched) |
| Drift since Codex's baseline | **None.** The local `main` and `origin/main` refs both equal `52dafa672`. I did not fetch from the remote, so newer remote commits can't be excluded. |
| Other branches | `codex/claude-rollback-recovery` and `codex/archive/*` exist. Nothing here depends on them; stale `.claude/worktrees` copies were excluded from every search. |
| Build used for the browser | `public/build/manifest.json` from 27 Sep 21:24. Frontend commits after it touched only `app-sidebar.tsx`, and not its eMAR section. **No eMAR, Meds today or emergency page changed since that build.** |
| Local data | The main checkout's `.env` points at `oblivion_findings_codex_test` (a local demo/test database). Herd's `oblivionfindings.test` wasn't serving (port 443 refused). |

Single-tenant boundary: every recommendation uses roles, permissions, approved sites, canonical ownership and direct-object denial. None introduces tenant concepts.

## 2. Codex's reported checks, re-verified

| Codex claim | My result | What it actually proves |
|---|---|---|
| 14 architecture tests passed, 190 assertions | **Reproduced**: `tests/Architecture/Medication*.php` (5 files), 14 passed, 190 assertions, 5.27 s | These are **source-text guards** (`file_get_contents` + `toContain`). They stop specific strings regressing; they don't test behaviour. One of them (`MedicationExactCapabilityUiBoundaryTest.php:51-60`) **locks in the sidebar gate that gives support workers the admin panel** (NF-01). |
| 17 safety unit tests, 43 assertions | **Reproduced**: `tests/Unit/MedicationSafetyServiceTest.php`, 17 passed, 1.51 s | They enshrine the generic **120 % dose threshold** and the **count-based** PRN "daily limit" (EM-08). Passing preserves the rule; it doesn't validate it clinically. |
| Full eMAR feature suite stalled after 20 min, cause undiagnosed | **Diagnosed**: not a hang. `MedicationDatabaseRollbackSafetyTest` calls migration `down()` (DDL, which implicitly commits in MySQL). `RefreshDatabase` then rebuilds the schema (`migrate:fresh`, about 4.5 min) before each following test. In my run the 4-test file took ~14 min with no output (suite started 22:17, next suite 22:31). Codex's own log shows ~200 passes and **zero failures** before the silence. | A test-infrastructure cost, not an eMAR defect. It follows the known pattern in `tests/Support/CommittedFixtureCleanup`. Fix: snapshot or clean around the DDL, or run the file in its own process. |
| Frontend tests unavailable | **Confirmed**: the main checkout's `node_modules` is an empty directory (0 entries); the worktree has none. Vitest, tsc and ESLint can't run. | No frontend test result is claimed. |
| Browser review of 19 destinations as demo admin | Not repeated as admin. **Complemented** as the seeded **support worker**, which Codex didn't use (§3). | The admin view can't show frontline-specific navigation or labels. |

## 3. My executed checks

| # | Check | Result |
|---|---|---|
| 1 | Architecture tests (as above) | **Pass**, 14/190 |
| 2 | `MedicationSafetyServiceTest` | **Pass**, 17/43 |
| 3 | Feature run, one process: `tests/Feature/Emar` (65 files), `tests/Feature/Medication`, `Tasks/TaskProviderRowScopeTest`, `MyDayMedicationActionTest`, `MyDayMedicationsDuePayloadTest`, `MedicationOverdueAlertsTest`; 620 tests; per-process isolated test database; event log kept | **Partial:** 431 of 620 executed before the 60-minute cap; **427 passed, 4 failed** (2 are NF-23, 2 are demo-seeder drift); 26 files not reached. Details in §3a. |
| 4 | Read-only SQL on the local demo DB: role → medication permissions; demo users | Support Worker holds `medications.view` + `controlled.view/record/witness` + `administer.record/correct`. Clinical lead, team lead, finance, auditor and manager hold `medications.view` **without** CD view. Anomaly NF-22. The demo DB has **0 medication errors**, so EM-12 couldn't be reproduced at runtime without creating data. |
| 5 | Browser, read-only: `php -S` on port 8794 serving the checked-out app; signed in with the seeded test account `sw-meds@demo.test`; visited `/meds/today` and `/emar` only; submitted nothing | **Reproduced:** NF-01 (14-item panel, no badge); EM-01 (3 overdue vs 0 due); EM-02 ("10:33 PM" vs "9:34 AM"); EM-03 (support worker shown "Medication lead · CD witness authorised", "Oversight shift 07:00–15:00", "Admin rate · target 95%"); EM-16 (daily CD prompt); EM-24 ("Give now if safe"); EM-14 (conversational greeting headers) |
| 6 | Source re-reads of every claimed P0 | Confirmed: EM-01 (`pending`), EM-02(a/b), EM-07 (allergy source), EM-12 (provider scope), EM-26 (redirect → queue success), NF-03 (competency policy), NF-04, NF-06, NF-07, NF-08, NF-12 |
| 7 | Three independent read-only sub-reviews (time/reporting, clinical safety, stock/integration/privacy), each told to challenge Codex and look for counter-evidence | Their claims were spot-checked (item 6). Where not personally re-read, the finding is labelled "reviewer-sourced". |

### 3a. Feature suite outcome

**Command:** one Pest 4.6.3 process (PHP 8.4, per-process isolated test database), with a 60-minute `timeout` cap and an event log. It covered `tests/Feature/Emar`, `tests/Feature/Medication`, `TaskProviderRowScopeTest`, the two My Day medication tests and `MedicationOverdueAlertsTest`: **620 tests in total.**

**Result:** the run hit the 60-minute cap (exit 124). **431 tests executed: 427 passed, 4 failed.** It reached 47 of the 73 files; the cap was hit inside `MedicationStockPrecisionMigrationTest`, where 4 of 7 tests had passed.

| Failed test | Message | Cause | Classification |
|---|---|---|---|
| `MedicationOverviewServiceTest` › surfaces an INR-out-of-range item in the action centre | `Expecting null not to be null` | `latestInrPerClient` excludes INR rows with no linked medicine (`canonicalMedicationRows($q, false)`), and `storeInr` allows that link to be null. The test pins its clock with `setTestNow`. | **Product defect**, not a flake: NF-23 (P1). |
| `MedicationOverviewServiceTest` › sorts the action-centre feed by severity | `max(): Argument #1 must contain at least one element` | Same cause: the feed comes back empty. | Same as above, NF-23. |
| `MedicationRoundsDemoSeederTest` › seeds today's rounds with live cells | `actual size 0 matches expected size 8` | `GuidedRoundService::cells()` returns no cells for the seeded morning round, even though the test pins the clock. Not diagnosed. It's probably the demo seeder falling out of step with the newer verification or governance scopes (orders created with `created_by` start as `pending_verification`, and `scopeActive` excludes them). | **Demo and test drift** (P2). It affects demo data and any browser review based on the demo rounds; production behaviour is unverified. |
| `MedicationRoundsDemoSeederTest` › reseeding is idempotent | `actual size 0 matches expected size 8` | Same as above. | Same as above. |

**Why it was slow:** four files run migration `down()` or DDL, which forces a schema rebuild of about 4.5 minutes before each following test:

- `MedicationDatabaseRollbackSafetyTest`: its 4 tests took about 14 minutes.
- `MedicationPrescriberOrderClassificationMigrationTest`
- `MedicationPrescriberOrderReadBackMigrationTest`
- `MedicationStockPrecisionMigrationTest`

Together these took roughly 40 of the 60 minutes. The other 427 tests took under 20 minutes.

**Not reached (26 files).** I didn't re-run these because other sessions were running PHPUnit, `vite build` and `tsc` at the same time, and the machine-wide limit on heavy processes applies:

- the rest of `MedicationStockPrecisionMigrationTest`
- `MedicationTimelineProjectionVisibilityTest`
- `MedicationWitnessLockOrderTest`
- `MedicationsDatabaseTest`
- `OneChartAdministrationSafetyTest`
- `OneChartGovernanceWorkflowTest`
- `OneChartSettingsTest`
- `PrescriptionsPageTest`
- `PrnQuickRecordTest`
- `PrnRecordsHistoryTest`
- `PrnRecordsPageTest`
- `ReviewsTest`
- `RoundTemplateDaysOfWeekTest`
- `RoundsPagePayloadTest`
- `SelfAdminTest`
- `StockManagementTest`
- `WorkerMedsRecordDoseTest`
- `WorkerMedsTodayPayloadTest`
- `Medication/ControlledDrugStockTruthTest`
- `Medication/MedicationIncidentJourneyTest`
- `Tasks/TaskProviderRowScopeTest`
- `MyDayMedicationActionTest`
- `MyDayMedicationsDuePayloadTest`
- `MedicationOverdueAlertsTest`

**Recommended next run** (when the machine is quiet): those files in one process, with the four DDL migration files excluded or run in their own process.

**Compared with Codex's run:** Codex's run stopped silently at the first DDL file after about 200 passes. This run got past it and found 4 real failures that Codex never reached.

### 3b. Not executed, and why

| Not executed | Why | What would close it |
|---|---|---|
| EM-26 PRN false-success runtime repro | Needs a blocked PRN submission against demo data. I avoided medication writes. | One Playwright case against a disposable fixture: over-limit or unverified PRN, assert an error toast and no "recorded" message. |
| EM-12 `/tasks` CD leak runtime repro | Demo DB has no medication errors; creating one is a write | Add a same-site restricted-role case to `TaskProviderRowScopeTest` (CD-linked error, actor without CD view) |
| Playwright `tests/e2e/meds-readiness.spec.ts` | No Node toolchain in either checkout | Install dependencies in an isolated checkout; expect the badge assertion (`:138-145`) to fail until NF-01 is fixed |
| Vitest, tsc, ESLint | Same | Same |
| Restricted-role browser matrix (team lead, auditor, finance, clinical lead) | Scope and time. A clean demo auditor exists (`auditor@demo.test`); `clinical.lead@demo.test` also holds `support_worker` in this DB, so it isn't a clean case. | Browse each seeded role against the conservation map in the navigation plan |
| DST and midnight scenarios | No tests exist; time-travel needs a controlled environment | Add Pest cases using `Carbon::setTestNow` at 00:30, 09:00 and 23:30 NZ, and on both DST transition days |
| Production data checks (NF-22 roles; whether `medication_allergies` is populated by a mobile client) | No production access | Read-only queries by an authorised administrator |

### 3c. What passing tests do and don't prove

- Governance, concealment and concurrency are **extensively** tested (scope decisions, controlled API concealment, witness lock order, replay binding, handover concealment). That is real, valuable evidence the server boundaries hold.
- Some tests **encode the defects**:
  - `MedicationOverviewServiceTest` hand-creates `pending` rows that production never writes (EM-01).
  - It asserts a 0 % admin rate when nothing applies (EM-18).
  - The unit tests assert the 120 % threshold (EM-08).
  - An architecture test asserts the sidebar gate (NF-01).
  - `ControlledDrugsTest` accepts a register-only CD administration (NF-04).
  - `OneChartGovernanceWorkflowTest` uses a UTC wall-clock dose time, which masks EM-02(a).
- **Missing coverage:**
  - midnight and DST
  - allergy recorded on the health profile
  - restricted or failed-area competency
  - refusal on a blocked medicine
  - the final day of an order
  - two stock batches
  - rule-required countersign on the board or guided round
  - a revoked covert authorisation
  - a same-site restricted-role Tasks projection
  - PRN rejection surfaced as an error to the queue

## 4. NZ sources: mandatory, guidance and organisation policy

I didn't invent dosage rules, clinical thresholds, review or count intervals, or professional authority. Where the code contains such values, they're reported as unverified hard-coding (EM-17).

| Source | Status | Relevance to this review | Link |
|---|---|---|---|
| Health and Disability Services (Safety) Act 2001 | **Law.** Certification is required for hospital care, rest home care, residential disability care and some specified services. | Whether each house is *certified residential disability care* or supported living in the person's own home decides which standard applies (**D1**). | [legislation.govt.nz](https://www.legislation.govt.nz/act/public/2001/93/en/latest/) · [HealthCERT](https://www.health.govt.nz/regulation-legislation/certification-of-health-care-services) |
| Ngā Paerewa NZS 8134:2021, section 3.4 "My medication" | **Mandatory for certified services.** Otherwise it applies only if a contract adopts it. | Medicine management: reconciliation, prescribing, dispensing, administration, review, storage, disposal, error prevention, staff access to current information | [Standards NZ](https://www.standards.govt.nz/shop/nzs-81342021) · [MoH services standard](https://www.health.govt.nz/regulation-legislation/certification-of-health-care-services/services-standard) |
| Sector guidance for Ngā Paerewa | **Guidance** (non-mandatory interpretation) | How auditors may read 3.4. Not an audit standard in its own right, as Codex correctly noted. | [health.govt.nz](https://www.health.govt.nz/publications/sector-guidance-for-nga-paerewa-health-and-disability-services-standard-nzs-81342021) |
| Code of Health and Disability Services Consumers' Rights | **Mandatory** for health and disability providers | Right 7: informed choice and consent, a presumption of competence, and Right 7(4) where a person isn't competent. Relevant to self-administration (EM-04), covert administration (EM-25) and refusal (NF-02, NF-11). | [HDC Code](https://www.hdc.org.nz/your-rights/about-the-code/code-of-health-and-disability-services-consumers-rights/) |
| Health Information Privacy Code 2020 | **Mandatory** | Rule 5 (reasonable security safeguards) and Rule 11 (disclosure limits). Relevant to CD concealment, projections and exports (EM-12), offline device storage (EM-26) and demo-role anomalies (NF-22). | [OPC HIPC 2020](https://www.privacy.org.nz/privacy-principles/codes-of-practice/hipc2020/) · [Rule 5 factsheet](https://www.privacy.org.nz/privacy-principles/codes-of-practice/hipc2020/hipc-factsheet-5-storage-security-retention-and-disposal-of-health-information/) |
| Misuse of Drugs Regulations 1977, regs 37–45 | **Law for the named duty-holders**: pharmacists and dispensing practitioners, other dealers and licensees, hospitals and institutions (ward books, weekly check), and a six-monthly stocktake for register-keepers (reg 43) | Whether these duties apply to a supported-living house holding a person's own dispensed controlled drugs is **not established here**. CD cadence, register and witness design are organisation policy informed by pharmacy and legal advice (**D8**). | [legislation.govt.nz](https://www.legislation.govt.nz/regulation/public/1977/0037/latest/whole.html) |
| MoH *Medicines Management Guide for Community Residential and Facility-based Services – Disability, Mental Health and Addiction* | **Guidance.** The guidance most directly aimed at residential and respite disability services. | Codex cited only the home-support guidelines. I downloaded the PDF but couldn't extract its text (no PDF tooling here), so **I cite it for existence and scope only, not content**. The organisation should confirm the current version. | [health.govt.nz](https://www.health.govt.nz/publications/medicines-management-guide-for-community-residential-and-facility-based-services-disability-mental) |
| MoH *Medication Guidelines for the Home and Community Support Services Sector* (May 2019) | **Guidance** for home and community support; reflects NZS 8158:2012 | Relevant where support is delivered in the person's own home | [health.govt.nz](https://www.health.govt.nz/publications/medication-guidelines-for-the-home-and-community-support-services-sector) |
| HQSC *Healing, learning and improving from harm: National adverse events policy 2023* | **National policy.** Effective 1 July 2023; SAC 1–2 reporting is mandatory for the providers it covers. Whether it covers this provider depends on funder and contract. | Medication error, incident and closure design (EM-22) | [HQSC](https://www.hqsc.govt.nz/resources/resource-library/national-adverse-event-policy-2023/) |

Items that are **organisation policy** (never to be set by a mockup or by code defaults):

- count cadence and witnessing
- late, early and time-critical rules
- PRN response time and escalation
- re-offer after refusal
- competency areas, pass mark and expiry
- self-administration categories and review triggers
- covert administration process
- review intervals
- downtime and paper process
- allergy recording standard
- on-call contacts

Values currently hard-coded in the app for these (10/12 pass mark, 1-year expiry, 3 refusals in 7 days, 120 min late incident, 120 % dose warning, 95 % admin-rate target, 3-month review default, "MOH categories" 21/16/11, "under 60 bpm") need an accountable owner to confirm or replace them.

## 5. Open decisions

### 5.1 Organisation decisions (clinical, operational, privacy)

| # | Decision | Needed before | Owner (suggested) | Findings |
|---|---|---|---|---|
| D1 | Service classification per site (certified residential disability care / supported living in own home / respite), and therefore which standards and guidance apply | Mockups (P00) | Provider manager / quality | All |
| D2 | Who may prompt, assist, administer, witness, verify, reconcile, assess, review and override, by role; relief and agency staff; recording after clock-out | Mockups (P00, P01) | Clinical governance + operations | EM-03, NF-03, NF-07, NF-09 |
| D3 | Competency model: areas, pass mark, restriction and supervision meaning, per-task authority (insulin, covert, CD), exemption limits, witness competency | Implementing NF-03 (P0 mitigation can proceed on explicit `restricted`) | Clinical governance / L&D | NF-03, EM-17 |
| D4 | Clinical timing rules: late/early windows, time-critical medicines, scheduled-dose interval, PRN interval and amount limits, response times, re-offer after refusal | P01 design sign-off | Prescriber/pharmacist advice + clinical governance | EM-08, EM-24, NF-11 |
| D5 | Allergy source of truth and status vocabulary (reviewed / no known drug allergies / unknown) | **P0 fix** and P02 | Health & Clinical owner | EM-07 |
| D6 | Per-medicine support categories (independent / prompt / assist / administer), consent recording, review triggers | P03 | Care planning + clinical | EM-04 |
| D7 | Viewport and device scope for eMAR: desktop web only (the user's standing rule) or point-of-care devices, and the offline expectation | P01 | **Stephan** | EM-26, EM-29 |
| D8 | Controlled drugs in supported living: register use, count cadence, witness credential (separate PIN vs login password), destruction and returns | P07a/b | Clinical governance + pharmacy (+ legal if needed) | EM-16, NF-04, NF-08, NF-17 |
| D9 | CD need-to-know: which roles may see CD-related errors, incidents, timelines and aggregates | EM-12 incident/timeline part (the provider P0 fix can proceed under the current rule) | Privacy officer + clinical governance | EM-12 |
| D10 | Stock model: lots, person-owned supply, meaning of the ordinary balance, pharmacy communication | P06 (and any migration) | Operations + pharmacy | EM-10, EM-11, EM-27 |
| D11 | Downtime and paper recording, and reconciliation on return | P10 | Operations + IT | EM-28 |
| D12 | Escalation contacts and acknowledgement in houses without on-site clinical staff (replaces the hard-coded "on-call nurse") | P01, P08a | Operations | EM-06, EM-17, EM-22 |

### 5.2 Product decisions for Stephan

1. **A fix-first track for P0s.** Five themes: EM-01 dashboard counts, EM-07 allergy source and wording, EM-12 Tasks provider scope, EM-26 PRN false success, NF-03 explicit restriction enforcement. Plus cheap P1s that don't change navigation or visual design: NF-01 sidebar gate, EM-03 labels, EM-06 blank default, NF-06 refusal-on-block, EM-09 final day, NF-04 register default. **These shouldn't wait for mockups.** Each still needs your explicit release, isolated implementation and QA.
2. **Navigation.** Frontline single entry plus seven hubs for leads, as in `Revised-navigation-and-page-plan.md` §2. Also the sidebar label ("Medication" rather than "eMAR").
3. **Package plan.** P00 contracts first, then the revised order in §7.2 of the navigation plan; one designer at a time (the Revision 10 sequencing pattern). Fleet-specific approvals and restrictions don't carry over.
4. **Report builder.** Add a `medication` domain to the shared operational report builder *after* EM-01/02 are fixed.
5. **Viewport scope** (D7).

## 6. Gates

**Before any mockup session is created:**

1. Navigation and workflow agreement: the user approves the hub structure, conservation map and package order, or amends them.
2. P00 contract pack approved:
   - dose obligation/outcome states
   - blocked-reason catalogue
   - identity header
   - unknown / unavailable / stale / queued / confirmed wording
   - time and timezone display
   - CD concealment pattern
   - follow-up owner and due-time pattern
3. D1, D5, D7 and D9 decided; D2, D4 and D6 at least scoped for the first packages.
4. The fix-first track decision is made, so mockups design on top of corrected behaviour, not around defects.

**Before implementation of any package:**

1. Exact mockup version approved (full clickable, all states in navigation plan §7.3).
2. The package's organisation decisions recorded with owners. Unapproved policy values fail closed and are shown as "not configured", never as defaults.
3. Regression tests planned for the package's findings (§3c gaps).
4. Any data migration (for example EM-10 lots, EM-23 versions) has its own dependency audit and approval.

**Integrated acceptance:** walk W01–W11 (Codex `03-workflows-integrations-and-gates.md`, which I broadly endorse) with support worker, lead, clinical lead, auditor and restricted roles. Add these scenarios:

- self-administered medicine
- refusal then re-offer
- final day of an order
- allergy on the profile
- a restricted-competency worker
- a blocked PRN online and offline
- a Tasks projection for a restricted role
- a CD register entry vs a MAR dose
- NZ midnight and both DST days

## 7. Limitations

- This is a broad module review, not a line-by-line proof. Reviewer-sourced line references were spot-checked, not all re-read.
- No runtime reproduction of EM-12 or EM-26 (see §3b); both are source-confirmed on each side of the boundary.
- The browser pass was a single seeded role on local demo data at one moment in time, not a role matrix.
- NZ regulatory applicability depends on facts about each site's service type that the code can't tell me (D1).
- The MoH community-residential guide was cited for scope only (its text wasn't extracted).
