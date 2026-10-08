# eMAR: independent second review of Codex's audit

28 September 2026 · Baseline `52dafa6728ebe343631453d4863f645d97c59506` (identical to Codex's; no code drift) · Audit and planning only: nothing implemented, migrated, committed or deployed.

## Bottom line

Codex's audit is a sound *map*. It found the right problem areas, protected the right existing controls, and correctly refused to invent clinical policy. But it **under-called severity**. It reported no P0s. I found **five P0 themes** in current, normal use. All five are confirmed in source. EM-01 was also reproduced in the browser. EM-12 and EM-26 still need a runtime repro (the demo data has no medication errors, and I avoided medication writes). It also **misread the frontline navigation problem**, and it **missed capability that exists but can't be reached**.

The server-side safety architecture is strong and must be kept:

- scope decisions tied to a clocked-in shift
- competency re-checked under lock on every "given" path
- CD witness independence and password check
- verification before administration
- idempotent offline replay
- extensive concealment and concurrency tests

Most defects sit where that architecture meets the **read models, labels and projections** people actually look at.

## P0: fix before any live use

Small and UI-neutral; these should not wait for mockups.

1. **Allergies recorded on the health profile never reach eMAR** (EM-07). The dose wizard then says "No known medication allergies on file". The safety check reads a table that no web screen writes to.
2. **A blocked PRN dose is reported as "recorded"** (EM-26). The server rejects the dose with a redirect, and the shared offline queue treats that as success, both online and on replay. The dose then goes unrecorded, and a second dose becomes possible.
3. **Competency restrictions aren't enforced** (NF-03). A worker assessed as "supervised only", or who failed insulin, covert or controlled-drug areas, can still record those doses alone.
4. **Tasks leaks controlled-drug medication errors** (EM-12) to roles without CD access (team lead, clinical lead, finance, auditor). The leak reaches the list, detail view, global search lookup, CSV export and counts, bypassing the app's own heavily tested need-to-know rule.
5. **The eMAR dashboard's due and overdue counts are structurally always zero** (EM-01). It counts `pending` rows that nothing writes. The overdue safety net around it is also unreliable: the Control Room overdue check reads dose times as UTC (EM-02a), and overdue notifications reach only a round assignee (NF-10).

Reproduced in the browser, as the seeded support worker on the same fixture: Meds today showed **3 overdue**, while the dashboard showed **0 due**, "refreshed 9:34 AM" (UTC) against "10:33 PM". That same support worker saw "Medication lead · CD witness authorised" and a 14-item eMAR menu.

**Test evidence:**

- The 14 architecture tests and 17 unit tests Codex reported pass again.
- The eMAR feature run executed 431 of 620 tests before my 60-minute cap: **427 passed, 4 failed**.
  - Two failures are the hidden-INR defect (NF-23).
  - Two are demo-seeder drift (NF-24).
- Four schema-migration test files cause the long silent periods Codex took for a stall.
- 26 files were not reached, and weren't re-run because other sessions were loading the machine.

## Where Codex was right

- **Existing controls:** the offline, idempotency, witness and competency controls are substantial. "Not a missing offline feature" is correct.
- **Confirmed as stated:**
  - dashboard vs board disagreement (EM-01)
  - timezone and day-boundary drift (EM-02)
  - dashboard site filter (EM-15)
  - self-administration disconnected from daily tasks (EM-04)
  - PRN follow-ups aging out (EM-05)
  - order end date vs product expiry (EM-09)
  - one stock batch per medicine (EM-10)
  - thin transfer and respite reconciliation (EM-20)
  - order-version gaps (EM-23)
  - "Give now if safe" wording (EM-24)
  - special instructions not reaching the point of care (EM-25)
  - pharmacy status labels (EM-27)
  - PageHero debt (EM-14)
- **Framing and sequencing:** "no invented clinical policy", the Revision 10 sequencing, and not certifying compliance were all correct. So was keeping CD permission separate from stock, and keeping emergency access out of Settings.

## Where Codex overstated or misunderstood

- **EM-03 (P1 → P2).** The misleading competency and witness labels don't authorise anything: every "given" path re-checks on the server. The real P0 is next door (NF-03).
- **EM-16 (P1 → P2).** The CD workspace and the escalation job already agree on 7 days; the dashboard is the outlier.
- **EM-08 details.** PRN maximums are integer-validated and use a rolling 24 hours, not free text. The real gaps are counting administrations rather than amount, and checking against server time rather than dose time.
- **EM-06 details.** Only the worker-board dialog defaults to "Helped". The eMAR wizard starts blank but always saves "30 minutes after".
- **EM-19 details.** PRN has a paginated 90-day history. The worse issue is that the audit "export pack" exports a different dataset, capped silently at 5,000 rows.
- **EM-28.** It asked for contextual emergency access as if it were missing, but a blocked person MAR already redirects break-glass holders to a pre-opened request.
- **EM-13 and the navigation premise.** Codex assumed support workers "mainly enter through Meds today". In the code they get a **14-item admin panel**, because the gate treats CD record permission as a lead capability and the support worker role holds it. An architecture test locks this in.
- **"The eMAR feature suite stalled."** It didn't hang. A rollback-safety test runs DDL, which forces a ~4.5-minute schema rebuild before each following test. Codex's own log shows ~200 passes and no failures before the silence.
- **EM-30.** Barcode/scan verification already exists (internal codes; required in transport).
- **Package plan.** Eleven page packages with no shared-contracts package means every page session would reinvent dose states and blocked reasons. P01 covered one recording surface, while the guided round, the MAR one-click, the client-profile dialog, Fleet transit and the API each record doses differently.

## Important issues Codex missed

- **The refusal/withholding follow-up is unreachable** (NF-02). It has a backend, incident escalation and correction auto-cancel, but its dialog is mounted nowhere.
- **A blocked safety check also blocks recording a refusal or withhold** (NF-06), so the correct "not given" record can't be made when it matters most.
- **Re-offer after refusal can't be recorded** (NF-11). The later "given" is rejected as a duplicate.
- **A CD register "Administration" entry (the dialog's default) creates no MAR record** (NF-04). That risks double doses or a double balance decrement.
- **The worker board discards non-blocking safety warnings** (EM-07). There's also an **invented clinical instruction**, "Withhold… if under 60 bpm" (EM-17), and rule-required observations and countersign never reach the frontline screens (EM-25).
- **An out-of-range INR recorded without a linked order is hidden from the dashboard Action centre** (NF-23). Two existing tests fail on this baseline because of it; Codex's run never reached them.
- **Recording needs a clocked-in shift that includes the person** (NF-07). That's a good control, but blocked workers get a generic message with no route forward, and the board, My Day and server disagree about who is "yours".
- **Other P1 gaps:**
  - witness passwords unthrottled (NF-08)
  - self-authorised safety overrides and open interaction-rule creation (NF-09)
  - break-glass review unreachable by auditors, with self-review possible (NF-12)
  - Tasks and client "Actions & reviews" show almost no medication work (NF-05)
  - handover CD discrepancy never becomes canonical (EM-21)
  - past MAR days rebuilt from the current order (EM-23)
  - order's final day blocked as "EXPIRED" (EM-09)
  - ordinary stock "on hand" never decreases, so low-stock alerts don't fire (EM-11, raised to P1)

## Recommended navigation

- **Support workers: one sidebar entry, "Meds today"**, with its overdue badge. Its rail: Schedule · Rounds · As-needed · Follow-ups · Controlled checks · Stock alerts · Activity. Everything else they need is contextual: person record, report an error, handover, "why can't I record this?", and emergency access for holders only.
- **Leads, clinical staff, managers and auditors: seven permission-aware hubs**, each one sidebar entry, with siblings as `PageHeaderRail` tabs. This is the DESIGN.md Governance hub rule, driven by one navigation config (the Fleet pattern), with every URL kept:
  1. Meds today
  2. MAR & medicines
  3. Orders & reviews
  4. Stock & controlled drugs
  5. Safety & oversight (including Staff eligibility and Emergency access)
  6. Reports & audit
  7. Settings
- **"People & medicines" is dropped as a people directory.** It would duplicate Clients and the client profile's Medical › MAR tab. The canonical per-person medication record stays at `/emar/mar?client_id=`, with a tier-2 sub-nav, and every entry point lands there.
- **Reports** reuses the shared operational report builder (the Fleet one) as a `medication` domain, once counts reconcile.
- **Settings** mirrors Fleet Settings as a one-page rail. **Competency is not a setting.** Like Fleet's "Drivers & eligibility", it's operational, and it becomes "Staff eligibility".

Full mapping of all 18 sidebar destinations, Settings, and the destinations that aren't in the sidebar (CD loss reports, INR history, refusal follow-ups, PDFs and exports, other recording entry points): `Revised-navigation-and-page-plan.md` §5.

## What must be settled before mockups or implementation

- **Before mockups:**
  - Navigation and workflow agreement.
  - Approval of a new **P00 shared-contracts and state catalogue** package.
  - Decision on the **fix-first track** for the P0s.
  - Organisation decisions D1 (service classification per site), D5 (allergy source of truth), D7 (desktop-only vs point-of-care devices) and D9 (CD need-to-know).
  - D2, D4 and D6 scoped for the first packages.
- **Before implementation:**
  - Exact mockup approval per package.
  - Accountable owners for every clinical or operational value. Unapproved values fail closed as "not configured".
  - Planned regression tests for the missing scenarios: DST and midnight, profile allergy, restricted competency, blocked PRN, same-site restricted Tasks role, final order day, two batches.
  - A separate dependency-audited approval for any migration (lots, order versions).

## Deliverables

| File | Contents |
|---|---|
| `Finding-by-finding-verdicts.md` | EM-01 to EM-30: verdict, corrected conclusion, evidence and call paths, counter-evidence, tests, browser, impact, priority, fix, owner, acceptance, uncertainty |
| `New-findings.md` | NF-01 to NF-22, plus missed items folded into EM entries |
| `Revised-navigation-and-page-plan.md` | Challenge of the seven workspaces, the recommended structure, role matrix, answers to your three questions, the conservation map, and revised packages with dependency order and page/dialog/state inventory |
| `Verification-and-open-decisions.md` | Baseline, re-verification of Codex's test claims, my executed/blocked checks, what tests prove, NZ sources (mandatory vs guidance vs policy), open decisions D1–D12 and gates |
