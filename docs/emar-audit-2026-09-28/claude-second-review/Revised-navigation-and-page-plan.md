# Revised navigation and page plan (second review)

28 September 2026 · Independent second review of Codex's navigation proposal (`02-navigation-and-page-plan.md`) · Baseline `52dafa6728ebe343631453d4863f645d97c59506` · **Proposal only — nothing here is approved or implemented.**

Single-tenant boundary: one organisation, many sites. Navigation is discovery only. Every server gate, approved-site check, canonical ownership rule, controlled-drug (CD) concealment and direct-object denial stays exactly where it is. No tenant selector or tenant-scoped surface is proposed.

---

## 1. Verdict on Codex's seven workspaces

Codex's grouping is broadly sensible and I keep seven hubs for leads and managers. But four facts it missed change the design problem:

| # | Fact (verified at baseline) | Why it matters |
|---|---|---|
| F1 | **Seeded support workers already see a 14-item eMAR panel, not the single "Meds today" link.** `app-sidebar.tsx:667-690` sends anyone with `medications.view` + `controlled.view` + `controlled.record` to the admin sub-panel. The seeded Support Worker role holds all three (`RbacSeeder.php:777,798-799`, and the local demo DB's `role_permission` rows). The "frontline gets one link" intent written in the same file (`:656-666`) has been defeated since the April split (`bea363999`; narrowed but not fixed in `cd5d34e6b`). The architecture test `MedicationExactCapabilityUiBoundaryTest.php:51-60` locks this condition in, so the test suite *preserves* the problem. **Browser-reproduced** as `sw-meds@demo.test`: 14 links, no overdue badge. | The frontline problem isn't "18 entries → 7 workspaces". It is "support workers were meant to have one entry and currently have 14". The fix is cheap: restore the single frontline entry and make everything a worker needs contextual inside Meds today. |
| F2 | **The person's medication record already exists and is canonical.** `/operations/clients/{id}/mar` (`ClientMarController::show`, `:20-35`) redirects to `/emar/mar?client_id=…` (`EmarUrl::mar`). The client profile's Medical › MAR tab (`tabs/mar.tsx:36-245`) is a summary with Record dose and deep links. The profile header already has a Medications meter block. | Codex's "People & medicines" with a *People list* would create a second person directory beside Clients, and a third person medication surface. Don't add one (see §4.1). |
| F3 | **Some capability isn't reachable from any page.** The refusal/withholding follow-up has routes (`routes/emar.php:394-403`), a model with due date, GP and family fields (`MedicationRefusalFollowup`), incident escalation and correction auto-cancel. But `RefusalFollowUpDialog.tsx` is imported nowhere and no page lists follow-ups. The CD loss-report register (`/emar/controlled/loss-reports`) and `/emar/settings` are reachable only from inside other pages. | A conservation map built only from the sidebar misses these. They need a home in the new structure (Follow-ups tab, CD tab, Settings hub). |
| F4 | **The house style already has two approved consolidation patterns.** DESIGN.md's named anti-pattern "One sidebar link per register" (Governance, 2026-09-14): one sidebar entry per hub, with sibling registers as that hub's connected-tab header rail, each keeping its canonical URL. Fleet (`lib/fleet-navigation.ts`) adds a single workspace config that drives the sidebar, command search and a contextual menu in the breadcrumb strip. | Consistency with Fleet (which the user asked for) means a single `emar-navigation` config with permission-ordered landings, hubs rendered as `PageHeaderRail` tabs, all pages in command search, and old URLs kept. It does **not** mean inventing a new tab system. |

What I keep from Codex: separate stock and CD permissions; emergency access reachable from context; no clinical policy invented through UI; every existing route and mutation retained; 7 as a ceiling, not a per-user count.

What I change:

1. Support workers get **one** sidebar entry (Meds today, with its overdue badge restored). Everything else they need is a contextual action inside it.
2. **"People & medicines" becomes "MAR & medicines"**: cross-person registers only. Person-level medication stays on one canonical person record, entered from the client profile or from any row.
3. **Competency moves out of Settings** into the oversight hub as "Staff eligibility". In Fleet the equivalent (Drivers & eligibility) is operational, not a setting. **Settings mirrors Fleet Settings**: one page with rail views.
4. **Follow-ups become a first-class tab**: PRN effect checks, refusal follow-ups and handover carry-over. This uses the existing orphaned refusal follow-up backend.
5. **Reports reuses the shared operational report builder** (the Fleet one) as a later medication domain, once the counts are reconcilable (EM-01).

---

## 2. Recommended structure

### 2.1 Frontline: one entry

**Meds today** (`/meds/today`): the only sidebar item for anyone who does not hold a lead or manager capability (orders manage, orders verify, stock update, audit view, reports export, settings manage or break-glass). CD record/witness alone must **not** promote a worker into the admin panel.

Page header rail (at most 8 views, per DESIGN.md):

| Rail view | Content (existing unless marked) | Gate |
|---|---|---|
| Schedule | Current board (due, late, recorded) for people on the worker's covering shift | `medications.view` or `administer.record` |
| Rounds | Existing Rounds tab plus guided round (`/emar/rounds?guided=`) | same |
| As-needed | PRN wizard entry and today's PRN list | `administer.record` |
| Follow-ups | **New surface over existing data:** PRN effect checks due (durable, not day-limited, EM-05), the worker's refusal follow-ups (orphaned backend, NF-02), handover items assigned to them | `administer.record` |
| Controlled checks | **Contextual projection:** CD counts or witness requests due at this house, opening the existing CD dialogs (`BalanceCheckDialog`, `RecordCdEntryDialog`) | `controlled.record` or `controlled.witness`; hidden otherwise |
| Stock alerts | Existing tab | as today |
| Activity | Existing tab | as today |

Contextual actions stay on each row or in each dialog, never in the sidebar:

- Person MAR: opens the canonical person record.
- Report a medication error: existing `ReportErrorModal`.
- Shift handover medication lens: existing `/emar/handovers/shift-medications`.
- "Why can't I record this?" explanation (NF-07 in `New-findings.md`).
- Request emergency access: only for `medications.breakglass` holders, from the blocked state.

### 2.2 Leads, clinical, managers, auditors: seven hubs

Each hub is **one sidebar entry**. Its pages become the `PageHeaderRail` views (the Governance hub pattern) and keep their current URLs. A single `lib/emar-navigation.ts` (the Fleet pattern) owns labels, permission-ordered landings, visibility and command-search indexing. The server keeps all authorisation. Hidden navigation is never the security boundary.

| # | Hub (sidebar label) | Rail views → existing routes | Landing order (first permitted) |
|---|---|---|---|
| 1 | **Meds today** | as §2.1 | `/meds/today` |
| 2 | **MAR & medicines** | MAR charts `/emar/mar` · Medicines `/emar/medications` · As-needed history `/emar/prn` · Support & self-administration `/emar/self-admin` | MAR charts |
| 3 | **Orders & reviews** | Prescriptions `/emar/prescriptions` (orders, verification, countersign, dispensing, covert authorisation) · Medication reviews `/emar/reviews` · *Reconciliation (new, EM-20; shown only once built)* | Prescriptions for `orders.manage`/`orders.verify`; Reviews otherwise |
| 4 | **Stock & controlled drugs** | Stock & pharmacy `/emar/stock` · Controlled register `/emar/controlled` · Loss reports `/emar/controlled/loss-reports` · Destructions & returns `/emar/destructions` | Stock (`stock.update`) → Controlled register (`controlled.view`) |
| 5 | **Safety & oversight** | Overview `/emar` (repurposed to action-led, EM-01/15) · Follow-ups *(oversight queue over the same data as §2.1)* · Medication errors `/emar/errors` · Handovers `/emar/handovers` · Staff eligibility `/emar/competency` · Emergency access `/emar/emergency-access` (request, active grants, review) | Overview |
| 6 | **Reports & audit** | Reports `/emar/reports` · *Report builder (later, shared operational builder, medication domain)* · Audit trail `/emar/audit` · Print & exports (MAR PDF, CD register PDF, round sheet, CSV exports: existing routes) | Reports (`reports.export`) → Audit trail (`audit.view`) |
| 7 | **Settings** | Administration rules `/emar/settings` · Round templates *(currently inside Rounds; move the template dialog here with a link back)* · Emergency access policy (existing `PUT /emar/break-glass-policy`) · Alert recipients *(if made configurable)* · Change history | Administration rules (`settings.manage`) |

The canonical person medication record (not a hub) is `/emar/mar?client_id=…`, with a tier-2 sub-nav (`TierTwoTabs`): Chart · Medicines · Support plan · Allergies & alerts · Clinical (INR / syringe driver / observations) · History. Every entry point lands here: client profile, Meds today rows, MAR & medicines rows, Tasks, incidents and reports drill-downs.

Why seven and not fewer:

- Merging Orders into MAR & medicines makes a 6-view hub that mixes everyone's read-only chart with lead-only verification work.
- Merging Reports into oversight pushes that hub past 8 views, into the overflow menu.
- Seven matches Fleet's workspace scale (8) and stays well under the rail limit inside each hub.

### 2.3 Sidebar label

Use **"Medication"** (or "Medicines") rather than "eMAR" for the module entry. DESIGN.md's plain-language rule applies ("auditor and developer language in member-facing copy"), and "eMAR" is system jargon to most support staff. The old label can stay as a command-search synonym.

### 2.4 My Day and shift handover (added 29 September at Stephan's request)

Neither My Day nor the handover gets a second medication workflow. Both *show* the canonical eMAR state and hand off to it.

**How it works today** (verified at the baseline):

- My Day's work list shows medication items and links to `/meds/today?client_id=` (`my-day/components/day-work-list.tsx:57`).
- My Day's one-tap administer/refuse/snooze routes are live but have no mounted screen (NF-14).
- My Day counts due doses with its own shift window (EM-01), and one My Day medication test fails on the baseline (NF-26).
- The eMAR handover writes the canonical `ShiftHandover` with acknowledgement, but:
  - its follow-ups are free-text lines;
  - PRN reviews are only counted for the shift window;
  - a CD count discrepancy is never raised as a real discrepancy record (EM-21).

**Target contract:**

| Surface | Shows | Acts by | Must never |
|---|---|---|---|
| My Day medication card | Due, late and not-yet-recorded doses for the people on the worker's covering shift, counted from the **same schedule as Meds today**; the worker's own open follow-ups (PRN effect checks, refusal follow-ups); a CD check due, if eligible | Deep link into Meds today or the person record (Record dose opens the shared P01 recording flow) | Record a dose with its own one-tap path (retire or align NF-14); show different counts from Meds today |
| Shift handover medication lens | Live follow-up items (owner, due time, status) and unrecorded or late doses from the shared schedule; refusals, supply issues and CD count status for the shift | Acknowledge the handover; reassign an item's owner; start the CD count or discrepancy dialog | Close or resolve medication work by being acknowledged; keep follow-ups as free text; store a CD discrepancy only inside the handover |
| Tasks inbox (NF-05) | The same follow-up items as the two surfaces above, scoped and CD-concealed | Deep link to the exact record | Create a second record of the work |

**Package mapping:**

- P01 designs the My Day medication card and the retirement or alignment of the My Day routes.
- P08a designs the handover lens and the durable follow-up model it reads.
- P00 v2 shows both surfaces as reference frames, so the shared states are consistent before P01 and P08a start.

---

## 3. Role-specific navigation (seeded roles, verified in `RbacSeeder` and the local demo DB)

| Role (seeded grants) | Sidebar entries after change | Notes |
|---|---|---|
| Support worker: view, administer.record/correct, CD view/record/witness | **Meds today** only | Follow-ups, Controlled checks, person MAR, error report and handover are contextual. No breakglass is seeded; relief-worker access is an open decision (see Verification doc). Worker's own competency status is shown inline in Meds today. |
| Team lead: view, orders.verify | Meds today · MAR & medicines · Orders & reviews · Safety & oversight | Verification tasks are in Orders; oversight is read-only unless also granted. |
| Coordinator / house lead: most keys incl. stock, CD, settings, audit, export | All 7 | This is the "medication lead" persona. The label must not imply clinical authority (EM-03). |
| Clinical lead: orders, verify, settings, override_safety, competency.exempt, audit | Meds today · MAR & medicines · Orders & reviews · Safety & oversight · Reports & audit (audit trail only) · Settings | No stock/CD hub unless granted. |
| Auditor: view, audit.view | MAR & medicines (read) · Safety & oversight (errors read, emergency-access review) · Reports & audit (audit trail) | Break-glass **review** is `audit.view`-gated (`routes/emar.php:359-361`), but the Emergency access page is `breakglass`-gated (`:345-347`). Reviewers need a review list they can open (NF-12). |
| Finance: view, reports.export, stock.update | Stock & controlled drugs (stock only) · Reports & audit | CD stays hidden without `controlled.view`. |
| Provider manager / admin | All 7 | |

Fix the gate that `reports.viewAny` alone opens the eMAR panel (`app-sidebar.tsx:675`). `docs/architecture/reports-permissions.md` calls `reports.viewAny` a legacy global bypass. It should reveal the Reports hub only, not the module.

### 3.1 What each persona actually needs to see

Support workers must be able to answer six questions from the brief without leaving the task. This is where each answer lives:

| Support worker needs to know | Where it must be visible | Today |
|---|---|---|
| Whose medication task is this? | Row and dialog identity header: preferred name, photo if held, house | Name and initials; "Photo + NHI match" asked with no photo (EM-30) |
| What am I authorised and competent to do? | Inline, truthful eligibility (competency state and expiry, witness eligibility, covering shift) | Permission-derived labels (EM-03); restrictions not enforced (NF-03); generic 403 (NF-07) |
| What are the current approved instructions? | In the recording dialog: instructions, support mode (independent/prompt/assist/administer), covert plan, required observations, countersign | Missing on the board and PRN wizard (EM-25); support mode not used (EM-04) |
| What actually happened, and what do I record? | Outcome choices including re-offer and not-given reasons, with an actual dose where the order is variable | No re-offer (NF-11); refusal blocked by safety blocks (NF-06); prescribed dose used as given (EM-08) |
| What do I do if something is missing, unsafe or uncertain? | A named next step for each blocked reason; allergy status that is honest when unknown | "Give now if safe" (EM-24); "No known allergies" when there is no data (EM-07) |
| Who owns escalation and follow-up? | A follow-up with owner and due time that survives the shift | No owner; refusal follow-up unreachable (NF-02, EM-05, EM-06, EM-22) |

The other personas:

- **Medication/house leads** need, for their houses only:
  - unrecorded and overdue doses (EM-01)
  - open follow-ups and escalations
  - CD counts and discrepancies
  - supply at risk
  - orders awaiting verification or countersign
  - staff eligibility expiring
- **Authorised clinical users** need:
  - verification and countersign queues
  - reviews due
  - PRN use and response patterns
  - refusals
  - errors
  - the approved rule set (Orders & reviews, Safety & oversight)
- **Auditors** need:
  - the audit trail, with correction lineage and exports that match what's on screen
  - reports whose numbers reconcile with the MAR
  - break-glass review (NF-12)
  - read-only access to records, with CD concealment where their role lacks CD access

---

## 4. Answers to the specific questions

### 4.1 "People & medicines — the client profile also exists; will this duplicate it?"

Yes, as Codex drew it. Codex's N2 lists "People list; person MAR; medicines; support plan; allergies; history". Of those:

- **The People list duplicates `/operations/clients`**, the canonical directory with its own site scoping and list contract.
- **The person MAR already exists twice**: the client profile's Medical › MAR tab (summary plus Record dose) and the canonical `/emar/mar?client_id` chart. The profile route `/operations/clients/{id}/mar` is only a redirect to the latter.
- **Allergies and support plans have canonical owners outside eMAR** (client medical/health profile; care and support plan). See EM-07 and EM-04.

Recommended split:

- **Client profile** (owned by Clients) remains the person's front door. Its Medical › MAR tab is a *summary and launch point*: allergies banner, active medicines count, alerts, last dose, Record dose, and "Open medication record". It does not grow its own editing surfaces.
- **Canonical person medication record** (owned by eMAR) is `/emar/mar?client_id=`, with the tier-2 sub-nav in §2.2. It holds all per-person medication detail and actions, and every other surface links into it.
- **MAR & medicines hub** (owned by eMAR) is *cross-person* only: the chart board by house, the medicine list, PRN history, the self-administration register. Rows open the canonical person record. There is no second people directory.

Acceptance: from any person-level entry point, one click lands on the same URL and state. There is exactly one place to edit each medication fact. The client profile tab and the person record never disagree, because the tab reads the same service payload.

### 4.2 "Reports & audit — there is a report builder in Fleet, can we do something similar?"

Yes. Reuse it rather than build an eMAR-specific one. The Fleet builder is the shared **operational report builder**:

- routes: `routes/operational-reports.php`, `/report-builder/*`
- services: `App\Services\Reporting\{ReportAccess, ReportDefinition, ReportEngine, ReportRuns, ReportExporter}`
- page: `pages/reporting/workspace.tsx`
- features: saved versions, runs, cancel, exports, sharing and subscriptions
- current domains: `fleet`, `client`, `staff`, `self`

A `medication` domain means:

- sources in `config('operational-reports.sources')`
- a `ReportAccess::sources/context` branch that resolves scope through `MedicationGovernanceScopeService`. That gives CD concealment, canonical client ownership and approved sites, and denies CD sources to users without `controlled.view`.
- export gated by `medications.reports.export`
- existing CSV sanitisation

Preconditions (do not start before these):

1. One canonical obligation and outcome projection, so builder numbers reconcile to the MAR and dashboard (EM-01, EM-02).
2. Explicit CD and privacy rules for aggregates as well as rows. Small-number suppression for CD aggregates is an organisational privacy decision.
3. A decision on which fixed reports stay fixed: MAR PDF, CD register PDF, round sheet and discrepancy export are evidence documents, not builder output.

The Reports hub then has: Standard reports · Report builder · Audit trail · Print & exports.

### 4.3 "Settings & competency — also similar in Fleet; keep consistency"

Fleet Settings (`pages/fleet-assets/settings/index.tsx`) is **one `PageHeader` page whose rail views are configuration areas**: Maps · Tracking & data · Notifications · Setup · Change history. It has a draft/unsaved guard and a change history. Consistent eMAR Settings:

- Rail: Administration rules · Round templates · Emergency access policy · Alert recipients (if made configurable; today `SendMedicationAlerts` derives recipients) · Change history.
- Same draft/unsaved-changes and change-history treatment. Rules keep their existing publish lock (`MedicationRulePublicationLockOrderTest`).

**Competency is not a setting.** Assessors use it weekly, rostering reads it (`MedicationCompetencyRule`), and workers need to see their own status. Fleet puts its equivalent, "Drivers & eligibility", in an operational workspace, not in Settings. Put it in Safety & oversight as **Staff eligibility**: assessments, renewals due, finite exemptions. Show each worker's own status inline in Meds today. Rule-level competency *policy*, if configurable, is a Settings view.

---

## 5. Feature-conservation map: every current destination

Legend: **Keep** = same URL and same server gate; the hub/rail placement is discovery only.

| # | Current destination (sidebar group) | Route | Server gate (current) | New home | Deep links / notes |
|---|---|---|---|---|---|
| 1 | Meds today (Worker view) | `/meds/today` | `medications.view\|administer.record` | Frontline single entry; Hub 1 | Keep. Restore the sidebar overdue badge for workers. |
| 2 | Dashboard (Overview) | `/emar` | `medications.view` | Hub 5 · Overview | Keep. Content changes per EM-01/03/15/16/18. `/emar/daily` keeps its 301 redirect. |
| 3 | MAR Charts (Administration) | `/emar/mar` | `medications.view` | Hub 2 · MAR charts, and the canonical person record | Keep `?client_id=&date=`. Client profile redirect unchanged. |
| 4 | Medication Rounds | `/emar/rounds` | `view\|administer.record` | Hub 1 · Rounds (operational); round templates → Hub 7 | Keep `?guided=`; `/emar/rounds/{round}/guided` redirect kept. |
| 5 | PRN Records | `/emar/prn` | `medications.view` | Hub 2 · As-needed history; oversight review queue → Hub 5 Follow-ups | Keep. |
| 6 | Controlled Drugs | `/emar/controlled` | view + controlled.view | Hub 4 · Controlled register; frontline counts → Hub 1 Controlled checks | Keep. CD permission separate from stock. |
| 7 | Emergency Access | `/emar/emergency-access` | `breakglass` | Hub 5 · Emergency access, plus contextual request from blocked states | Keep `?request_client=` (used by `ClientMarController`). Add review access for `audit.view` holders. |
| 8 | Medications | `/emar/medications` | `medications.view` | Hub 2 · Medicines | Keep `?client_id=`; `/emar/medications/{id}/detail` kept. |
| 9 | Stock Management | `/emar/stock` | view + stock.update | Hub 4 · Stock & pharmacy | Keep. |
| 10 | Prescriptions | `/emar/prescriptions` | `medications.view` | Hub 3 · Prescriptions | Keep. |
| 11 | Medication Reviews | `/emar/reviews` | `medications.view` | Hub 3 · Medication reviews | Keep `?client_id=`. |
| 12 | Self-Administration | `/emar/self-admin` | `medications.view` | Hub 2 · Support & self-administration register; per person → the canonical record's Support plan tab | Keep. |
| 13 | Audit Trail | `/emar/audit` | `audit.view` | Hub 6 · Audit trail | Keep event integrity/export routes. |
| 14 | Reports | `/emar/reports` | `reports.export\|reports.viewAny` | Hub 6 · Reports | Keep exports. |
| 15 | Competency | `/emar/competency` | `medications.view` | Hub 5 · Staff eligibility; own status inline in Meds today | Keep. Check whether every `medications.view` holder should read all staff assessments (Verification doc). |
| 16 | Destructions | `/emar/destructions` | view + controlled.view | Hub 4 · Destructions & returns | Keep. |
| 17 | Handovers | `/emar/handovers` | `medications.view` | Hub 5 · Handovers; frontline lens contextual in Meds today | Keep canonical ShiftHandover. |
| 18 | Medication Errors | `/emar/errors` | `medications.view` | Hub 5 · Medication errors; report action contextual everywhere | Keep. |
| 19 | Administration rules (not in sidebar) | `/emar/settings` | `settings.manage` | Hub 7 · Administration rules | Keep. |
| 20 | CD loss reports (not in sidebar) | `/emar/controlled/loss-reports` | view + controlled.view | Hub 4 · Loss reports | Keep. |
| 21 | INR history (not in sidebar) | `/emar/clients/{client}/inr` | `medications.view` | Canonical person record · Clinical | Keep. |
| 22 | Refusal follow-ups (no UI) | `POST /emar/refusal-followups*` | `administer.record` / `administer.correct` | Hub 1 · Follow-ups (own); Hub 5 · Follow-ups (oversight) | **Mount the existing backend**. It is not new capability. |
| 23 | PDFs and exports | `/emar/pdf/*`, `/emar/reports/export*`, `/emar/audit/export`, `/clients/{client}/mar/export.csv` | various | Hub 6 · Print & exports, plus in-context buttons | Keep every one. |
| 24 | Other administration entry points | Client profile Record dose (`emar-dialog` → `RecordAdministrationDialog`); MAR grid one-click "Mark given" (`dose-context-menu`, simple non-CD medicines only); guided round; Fleet medication transit (`/fleet-assets/medication-transit/*`); mobile API (`/api/medications/*`). **Dormant:** the My Day `/my-day/medications/*` routes have no mounted UI (NF-14). | various | Unchanged homes. **They must share one recording contract** (see P01). Retire or align the dormant My Day routes. | Keep. |

Nothing is removed. Every URL above keeps working. Hubs change only what the sidebar and rail show.

---

## 6. Contextual actions and emergency access

- **Emergency access is already contextual in one place.** A user who fails `viewMedications` on a person but holds `breakglass` is redirected from the person MAR to the request wizard pre-opened for that person (`ClientMarController.php:24-28`). Extend the same pattern to blocked states in Meds today and in record dialogs (only for `breakglass` holders), and keep the Emergency access tab for grants and review. Don't hide it under Settings; Codex was right about that.
- **Grants are client-specific and never cover a whole round** (`MedicationScopeDecisionService.php:890-892`). The UI copy must say this, so nobody expects a grant to unlock a house.
- **Nobody without `breakglass` should be told about break-glass.** For everyone else the blocked state names the real route. Examples: "You're not clocked in on a shift for this person — clock in, or ask the coordinator on call", "Your medication competency expired on …".
- **Downtime.** Existing PDFs (MAR chart, round sheet, CD register) are the only offline paper path. A dated "print pack" belongs in Hub 6 · Print & exports, with a prominent link from Meds today when the page detects it is offline or stale. Paper-to-electronic reconciliation is a new capability; it needs scope approval before any mockup (P10).

---

## 7. Mockup packages: challenge and revised plan

### 7.1 Problems with the eleven-package plan

1. **No shared-contracts package.** Codex's step 1 says "agree shared contracts" but there's no artefact that gets approved. Each page session would reinvent dose states, blocked reasons, unknown and unavailable states, and the person identity header. Add **P00**, a clickable state catalogue and contract pack, approved before any page mockup.
2. **P01 covers only one of several recording surfaces.** `RecordDoseWizard` and `PrnWizard` are already shared by Meds today, MAR charts and the dashboard, which is good. But these also record administrations, each with its own interaction and requirement handling:
   - the guided round
   - the MAR grid one-click "Mark given"
   - the client profile's `RecordAdministrationDialog`
   - the Fleet medication-transit dialog
   - the mobile API
   - the dormant My Day routes

   EM-25 and NF-14 show they already diverge (countersign and observation requirements missing, prescribed dose used as the given dose). P01 must define one recording contract and requirement payload, reused (or explicitly retired) everywhere.
3. **P02 must be co-owned with the client profile.** It's the canonical person record, not a people directory (§4.1).
4. **P08 is too big and mixes audiences.** Frontline carry-over (follow-ups, handover) and governance cases (errors, incident link, investigation) are different journeys with different owners. Split them into **P08a Follow-ups & handover** and **P08b Errors & incidents**.
5. **P10 bundles an existing feature with an unbuilt one.** Emergency access exists. Downtime reconciliation of paper to electronic is new capability. Scope-approve the new part first; don't let a mockup become the requirement.
6. **Missing packages:**
   - CD work done by frontline staff (counts and witness at shift change): P07 is framed as a lead register.
   - Refusal follow-up: an orphaned backend.
   - The medication domain in the shared report builder (P09 decision).
   - A worker's own eligibility view.
7. **Defect fixes shouldn't wait behind mockups.** Some findings have small, UI-neutral fixes whose safety or privacy value doesn't depend on navigation: the Tasks CD privacy projection (EM-12), misleading authority labels (EM-03), the PRN effect default (EM-06), the dashboard obligation count (EM-01), and the sidebar gate (F1). Run a separately approved **fix-first track** alongside design (see the Verification doc). Only the user can release it.

### 7.2 Revised packages and dependency order

**Revised 29 September 2026 at Stephan's request:**

- **P11 (Settings & staff eligibility) moves up** to be the first design package after P00. It runs while PIN-1 is being built, since only one of them writes application code.
- **PIN-1** (implementation only) is inserted directly after P00 approval. Its approved mockup is the PIN screens in P00 v4.

| Order | Package | Pages / surfaces | Depends on | Owner of contracts |
|---|---|---|---|---|
| 0 | **P00 Shared contracts & state catalogue** (clickable pattern page, not a product page). Now also covers the Medication rules, the second-person PIN rules and the Witness PIN account page. | Dose obligation/outcome states; eligibility and blocked reasons; person identity header; unknown/unavailable/stale/queued/confirmed wording; time and timezone display; CD concealment pattern; follow-up owner and due pattern; allergy rule; CD witness default and override; amount given | Organisation decisions D1–D6 (Verification doc) | eMAR + clinical governance |
| 0a | **PIN-1: witness PIN** (implementation, no new mockup) | Witness PIN in account settings (set/change/reset); organisation PIN rules; staff PIN status; every witness and co-signer check switched from the login password to the PIN; attempt limit and audit | P00 v4 approved; P0 fixes merged | eMAR + security |
| 1 | **P11 Settings & staff eligibility** (moved up) | The whole Medication › Settings hub: every rail view (Medication rules, second-person confirmation, round templates, emergency access policy, alert recipients, change history) and its header numbers. Plus Staff eligibility (competency assessments, renewals, finite exemptions, the worker's own status). It builds on the rules already approved in P00. | P00 | eMAR + workforce |
| 2 | **P01 Record a dose (all entry points)** | Meds today Schedule/Rounds/As-needed; `RecordDoseWizard` and `PrnWizard` (shared with MAR and dashboard); guided round; MAR one-click "Mark given"; client profile Record dose; Fleet transit administer (parity check); mobile API contract; My Day medication card; retire or align the dormant My Day routes; offline/queued/rejected states; PIN-2 (forgotten-PIN fallback and confirmation) | P00, P11 | eMAR (EnhancedMarService) |
| 3 | **P02 Person medication record** | `/emar/mar?client_id` with its sub-nav; client profile Medical › MAR tab contract; INR / syringe driver / observations; **plus the MAR & medicines hub cross-person views (MAR charts board by house, Medicines, As-needed history), added 30 Sep 2026: a gap in the original plan** | P00, P01 | eMAR + Clients |
| 4 | **P08a Follow-ups & handover** | Meds today Follow-ups; oversight Follow-ups; PRN effect dialog; refusal follow-up (mount existing); handover medication lens; Tasks projection | P01, P02 | eMAR + Tasks + ShiftHandover |
| 5 | **P07a Controlled checks (frontline)** | Controlled checks tab; count and witness dialogs; discrepancy start; CD witness override requests | P00, P01 | eMAR CD ledger |
| 6 | **P03 Support & self-administration** | Assessment wizard; agreement; per-medicine support scope; reassessment; Support plan tab | P02 + **clinical governance decision** (support categories) | Clients care planning + eMAR |
| 7 | **P04 Orders, changes & reconciliation** | Prescriptions and dialogs; order detail/version; verification/countersign; covert authorisation; reconciliation (new); prescriber allergy confirmation on the order | P02, P03 | eMAR orders + Respite |
| 8 | **P06 Stock & pharmacy** | Stock register; receive/partial/adjust/count; pharmacy order lifecycle. **Medicine photo at receipt:** an optional prompt to photograph the supplied pack or tablet when there is no photo or the brand/pack has changed. It uses the existing unused `client_medications.photo_path`, stores the photo privately with CD concealment, and keeps the date and source pack. Photo history is kept; there is no licensed image library (Stephan, 29 Sep). | P04 + stock-lot migration decision (EM-10) | eMAR stock |
| 9 | **P07b CD register, loss & destruction** | Controlled register; loss reports; destructions/voids; detail dialogs; **plus the leads' Safety & oversight › Witness overrides view (added 30 Sep 2026; a gap left between P11 and P07a)** | P06, P07a | eMAR CD ledger |
| 10 | **P05 Medication review** | Review queue; conduct/reschedule/detail; outcome → P04 | P03, P04 | eMAR + clinical governance |
| 11 | **P08b Errors & incidents** | Error register; report/triage/review/resolve/close; incident link; Control Room signal | P08a | Incidents + eMAR |
| 12 | **P09 Reports & audit** | Reports; builder domain; audit trail/event integrity; print & exports | P01–P08 counts settled | Reporting + eMAR |
| 13 | **P10 Emergency access & downtime** | Request/review/revoke/extend/policy; print pack; (scope-approved) paper reconciliation | P02, P09 | eMAR + security |

The design sequence can overlap only where a user explicitly releases more than one session. The Revision 10 pattern is one designer at a time, each package stopping at exact-version approval.

### 7.3 Page, dialog and state inventory (what each full mockup must contain)

Every package mockup includes these **universal states**:

- loading (skeleton), empty (no work), not applicable, no access (403 wording without leaking existence), not found / concealed (404)
- stale data (with refresh time and timezone), server failure with retry
- validation error with values kept, offline, queued, rejected, conflict (order changed)
- success, correction, interruption and resume, focus return to the trigger
- 200% zoom and keyboard-only paths

It also covers **cross-module entry and return**: from My Day, client profile, Tasks, Control Room and incident detail, and back.

| Package | Pages | Dialogs / drawers (existing names) | Package-specific states |
|---|---|---|---|
| P00 | State catalogue page | — | Every dose state, including *not yet recorded* vs *confirmed missed*; every blocked reason (no shift, not clocked in, competency expired, exemption expired, no eligible witness, order awaiting verification, covert authorisation expired, site not permitted); unknown vs none for allergies |
| P01 | Meds today (Schedule, Rounds, As-needed, Activity) | `RecordDoseWizard`, `PrnWizard`, `PrnNearLimitDialog`, `GuidedRoundDialog`, `RecordedDetailDialog`, `RecordAdministrationDialog`, `emar-dialog`, MAR `dose-context-menu`, Fleet `transport-medication-dialogs`; decide the fate of the unmounted My Day `stream-context-menu` | Early, late and outside-window; refused → re-offered → given (NF-11); refusal while a safety block is active (NF-06); withheld, absent, self-administered; variable dose; witness required/absent; safety block vs override; pending server confirmation; duplicate submit; order changed mid-round; person away or in respite |
| P02 | Person record (Chart, Medicines, Support plan, Allergies & alerts, Clinical, History); client profile MAR tab | `MedicationDetailDialog`, `MedicationEventDrawer`, `ManageAlertsDialog`, `WarningsDialog`, `RecordInrDialog`, `SyringeDriverDialog`, `CorrectionsReviewDialog`, `InteractionsDialog` | Allergy status unknown / not reviewed; CD rows concealed; correction chain; stale INR; person moved site |
| P08a | Meds today Follow-ups; Oversight Follow-ups; handover lens | `PrnEffectDialog`, `PrnEffectivenessDialog`, `PrnDetailDialog`, `RefusalFollowUpDialog` (mount), handover acknowledgement | Due, overdue across midnight and shift change; unable to assess; escalated with no acknowledgement; reassigned owner; completed late |
| P07a | Controlled checks tab | `BalanceCheckDialog`, `RecordCdEntryDialog`, `ResolveDiscrepancyDialog` (start) | Witness is the same person / not eligible; count mismatch; count overdue under the approved cadence |
| P03 | Self-administration register; Support plan tab | `AssessmentWizardDialog` (self-admin), `SignAgreementDialog`, `MedScopeDialog`, `ViewSelfAdminDialog` | Mixed support across medicines; plan expired or missing; consent changed; reassessment trigger |
| P04 | Prescriptions; order detail; reconciliation (new) | `NewOrderDialog`, `CountersignDialog`, `DispenseDialog`, `CancelOrderDialog`, `CovertDialog`, `RevokeCovertDialog`, `LinkMarDialog`, `OrderDetailDialog`, `VerifyOrderDialog`, `AddMedicationDialog`/`AddMedicationModal`, `EditMedicationDialog`, `DiscontinueDialog`, `RejectOrderDialog`, `ImportCsvDialog` | Verbal order awaiting countersign; independent verifier required; order edited → re-verification; source document missing; reconciliation discrepancy |
| P06 | Stock & pharmacy | `NewPharmacyOrderDialog`, `ReceiveStockDialog`, `ControlledPharmacyDeliveryDialog`, `StockCountDialog`, `AdjustStockDialog`, `StockDetailDialog`, `StockMovementModal` | Partial supply; mixed lots (if EM-10 approved); expired/quarantined; person-owned supply; balance meaning (counted vs estimated) |
| P07b | Controlled register; loss reports; destructions | `CdRegisterModal`, `CdDetailDialog`, `ReportLossDialog`, `LossActionDialog`, `RecordDestructionDialog`, `VoidDestructionDialog`, `DestructionDetailDialog` | Negative-balance attempt; void with reason; loss investigation states |
| P05 | Reviews | `ScheduleReviewDialog`, `ConductReviewDialog`, `RescheduleReviewDialog`, `ReviewDetailDialog`, `MedicationReviewModal` | Review overdue by the configured policy; recommendation not yet authorised; no order change |
| P08b | Errors | `ReportErrorModal`, `TriageDialog`, `ReviewErrorDialog`, `ResolveErrorDialog`, `CloseErrorDialog` | CD error concealed from non-CD viewers; linked incident; duplicate report |
| P11 | Staff eligibility; Settings rail | `AssessmentWizardDialog` (competency), `ViewAssessmentDialog`, `RoundTemplateDialog`, `GenerateRoundsModal`, rule edit | Expired, due soon, exemption with end date; unsaved settings guard; change history |
| P09 | Reports; builder; audit trail; print & exports | `DrillDialog`, `ReportsModal`, `AuditLogModal` | Zero denominator = not applicable; truncated/paginated results; CD-concealed aggregates; export permission denied |
| P10 | Emergency access | `_request-dialog`, `_review-dialog`, extend/revoke | Grant expired mid-task; revoked; review justified / not justified; misuse flag |

### 7.4 What every page-session brief must contain

Codex's brief list (03 §"Brief each future page session must receive") is sound. Add:

- **The P00 contract version** the page must use.
- **The list of other surfaces sharing its component** (for example, every recording surface in P01).
- **The exact server gates and 404-vs-403 concealment behaviour** the page must reproduce.
- **The fix-first items already landed**, so mockups don't re-litigate them.
- **Viewport scope.** The user's standing rule is desktop web (memory: web-only app). Codex asserted that eMAR mobile capability must be preserved. That's a product decision, not an inherited rule; record it before P01 (Verification doc, D7).
