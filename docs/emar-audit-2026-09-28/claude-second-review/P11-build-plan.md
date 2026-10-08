# P11 Settings & staff eligibility: build plan

30 September 2026. The review session owns this plan and tracks it on the approvals page (https://claude.ai/artifact/SbuteuGMxhP6YtDfaYiPM3). Stephan asked for it to be tracked so nothing is forgotten.

## What is being built

The approved design is **P11 v5, exactly as `12ecb24a2`**. The approval record is `cd0db835f` on branch `claude/serene-aryabhata-d0e908` (backed up to GitHub on 30 Sep 2026), at `docs/emar-design/P11/v5/`. Read these files there:

- `README.md`
- `APPROVAL.md` (every answer, Q1–Q13)
- `AUDIT.md` §2, §5 and §6 (build notes)
- `CHECKLIST.md`

**To view it:** add a read-only worktree of that branch and run `node docs/emar-design/P11/v5/serve.mjs`. The preview serves the committed `dist`.

**Rule:** build it view for view. Walk each finished screen beside the approved preview at 1440 px before calling it done. Any change to the approved design needs Stephan's OK and a new design version.

## Before step 1 starts

1. **PIN-1 is on main.** It already builds P11's PIN rules and the staff PIN status list, and it must fix the review's P0 (the lock bypass) first.
2. **The merge queue has landed:** Control Room recipients, dose window, days remaining, and the INR/stale tests.
3. **PR #15 (destructive buttons render red) is merged.** P11 relies on red destructive confirms for "loosens a check".
4. **One application writer at a time.** No other session is changing medication code while a build step runs.

## Three build steps

Each step is one session. It ends with the review session's review, then Stephan's OK to push. Every new permission key ships with a grant migration, because deploys skip seeders.

### B1: Settings foundation, rules, timing, staff

**Shared pieces**
- Move Fleet's `Modal` and `Notice` to `components/settings/*`, and point Fleet Settings at them (Q1, approved).

**Settings storage**
- Organisation values and per-house values, each with a "Default — not yet reviewed" state.
- Drafts that survive tab changes, with "Review changes" and an unsaved-changes guard.
- **Change history** records the structured before and after. It supports:
  - Put back the earlier value;
  - Keep today's value;
  - the "Review the defaults" walkthrough.
- One `loosens()` rule decides both the destructive confirm and the history label.

**Medication rules**
- Medicine rules and safety checks: the allergy mode, restricted competency, and area not passed.
- Controlled-drug witness: an organisation default and per-house settings.
- The medicine-photo prompt setting. Capturing the photo is P06.

**Rounds & timing**
- Round templates.
- Move dose timing from `config/medications.php` to settings.
- Make the late-dose incident and the refusal escalation settings. Unify the escalation, which today is defined twice: `RefusalFollowUpController.php:104-107` and `SendMedicationAlerts.php:285-288`.

**Staff & PINs**
- Competency values: the pass mark and validity. Unify the 30-day reminder, which is repeated in 4 places.
- The exemption limit (30 days), enforced on the server. Exemptions get routes and UI: the model and permission exist, but there is no route today.
- PIN rules and status, from PIN-1.
- PIN reminders: a new notification, at most one per person per day.

**Staff eligibility (Safety & oversight › Staff eligibility)**
- The register, renewals, exemptions and witnesses, plus the worker's own **My eligibility**.

**Page chrome**
- The header meters and the "At a house" view.

### B2: Alerts, delivery, on-call

**Alert catalogue**
- The alert catalogue with recipients the organisation sets: groups (the decided routing is locked on), named people and house extras. It includes the five new alert types from AUDIT §1.
- Medication Settings owns who is told; Control Room shows the alerts (Q3).
- Replace the recipients hard-coded in `SendMedicationAlerts` and the related commands.

**Channels**
- **Channels per alert:** In-app (always on for decided alerts), Email and **Push**. Add the push channel to the medication notifications.
- The privacy switch keeps client names and medicines out of email and push.

**Follow-up engine**
- **One "attended" record per alert, shared by every recipient.**
- Re-alerts and escalation run on the 15-minute scheduler. After an escalation, the recipient list grows.
- **Quiet hours per house** (Q12): email and push are held; Follow-up alerts are never held.
- The "keep unattended at the top of the bell" option is off by default, covers medication follow-ups only, and is a shared bell change (Q13).

**Alert log**
- Filtered by house scope, with **controlled-drug details hidden** from anyone without `medications.controlled.view`.
- Kept as long as the audit log (Q11).

**On-call contacts**
- On-call follows the roster: the on-call shift first, then the team lead on shift (Q8), then a backup.
- The phone number is the work phone; a cellphone is used only with the person's consent (Q10). Consent is set in their account.
- A "who can't be reached" list.

**Message preview**
- Renders the real templates with a synthetic record.

### B3: Emergency access tab

- The tab explains the steps, with the worked example.
- The policy values are stored and audited, and gated by a permission rather than a role.
- The "who gets the daily report" link.
- The other emergency-access items listed in AUDIT §6 are built with **P10**, not here.

## Also in the build programme (added 30 Sep 2026)

These came out of a coverage check against the audit findings.

- **NAV: the navigation restructure.** This is its own small build step, run before or alongside B1.
  - Support workers get a single "Meds today" entry (NF-01). The architecture test that locks in the 14-item panel changes with it.
  - Leads get the 7 hubs: Meds today · MAR & medicines · Orders & reviews · Stock & controlled drugs · Safety & oversight · Reports & audit · Settings.
  - Each hub route is added as its package is built. Until then the entry links to today's page.
- **NF-13: people who are away.** When a person is on leave, in hospital, on respite elsewhere, or inactive, their doses aren't "late". They show as "Away — {reason}" automatically, with no late or overdue alerts. P04 reconciliation handles the return. Build this with P01.
- **Shifts, as the eighth recording entry point.** The shift detail page's medication card (`components/operations/shift-medication-card.tsx`) has its own recording dialog. At the P01 build, its Record button opens P01's shared dialog ("Opened from: Shift"). The card's own dialog is removed.
- **The client profile.** Medication already appears in several tabs. The design coverage is:
  - **Health & safety › MAR:** P02 designed it as a summary and launch point. Record dose opens P01's dialog, and the old `emar-dialog` is removed.
  - **Medical › allergies:** P02. This is the one place allergies are edited, and leads confirm them.
  - **Health monitoring:** P02 Q5. Readings taken with a dose appear here.
  - **Timeline:** its medication events already have concealment tests.

  Gaps closed on 30 Sep, all build notes:
  - **Actions & reviews** shows no medication work (NF-05). At the P08a and P05 builds, that person's open medication follow-ups and medication reviews appear there, using the same records as All Tasks.
  - **Care & Support Plan** gets a read-only "Medication support" summary from P03, with a link. Support is edited only in P03.
  - **Leave & excursions and Respite:** a person marked away there drives the NF-13 "Away" dose status (P01 build). Respite admission and discharge start P04's reconciliation.
- **NF-09: interaction rules.** Creating interaction rules is restricted to `medications.settings.manage`. This is fix-first, running now. Independent authorisation of safety overrides is built with P04 and P11.
- **Every other design package needs its own build after approval:** P01, P02, P03, P04, P06, P07a, P07b, P08a, P08b, P05, P09 and P10. Each one follows the same rules as B1: view for view, tests, and a review by the review session. They run in dependency order, one app-code writer at a time.

## Tracking

The approvals page shows a **Build queue**: B1, then B2, then B3, with each step's status. When PIN-1 is on main, the review session starts B1 and tells Stephan. Each later step starts when the previous one is live.

## Build-time amendments to approved packages

The approved package files are frozen. These amendments are applied at build, and every one is recorded in Approval-record.md.

- **P04:**
  - team_lead gets `medications.orders.manage` through a grant migration (1 Oct, from P05 deviation 3). The independent check is unchanged.
  - P08a's phone-instruction Countersign gains the prescriber's written confirmation (P04 #15).
- **P07b:** `medications.controlled.manage` is granted to team_lead and provider_manager only, not clinical_lead (deviation 5 = B).
- **P11:** the medication-review default interval row is designed in P05 and built with P05, not in B1.
- **P11 B1 (copy):** the "Still to decide" meter caption becomes "5 not configured". The v5 caption truncates at 1280 px and at 200 % zoom (found in the P05 inspection, 1 Oct).
- **NF-18** (the dose quantity `?? 1`) is part of the P01 build. **NF-19** (the controlled.record check in the witness attestation) is part of the P07a build. Both were confirmed outside P08b during its audit (1 Oct).
- **P11 addition:** the medication-error triage due time is designed in P08b and built with P08b, not in B1.
- **The canonical dose-slot projection (EM-01/02) is a P01 build deliverable.** It feeds the dashboard, MAR, Meds today and all of P09's numbers, and it is a precondition for P09's standard reports and the builder's medication domain (1 Oct).
- **P11 additions built with P09:**
  - medication event-log retention (default: 10 years after last service, not yet reviewed);
  - "Add SAC ratings for adverse-event reporting" (off by default).
- **The P09 SAC confirmation** is added to the P08b close step when that setting is on.
- **ROLE BASELINE (prerequisite for P01, P07a, P07b and P08a–P10 builds; decided 1 Oct):** a grant migration gives team_lead `medications.administer.record`, `administer.correct`, `controlled.view`, `controlled.record` and `controlled.witness`, the same keys as support_worker. It ships with the first build that needs house-lead medication actions. On main today, team_lead holds only `medications.view`, `orders.verify` and `witness_pin.reset`.
- **P10:** the paper reconciliation build needs Stephan's scope OK before it's built.
- **PIN hardening (from the PIN-1 handoff, 1 Oct), built with PIN-2 alongside P01/P08a in Lane A:**
  - **Pepper the witness PIN hash:** bcrypt of HMAC(env key, PIN), rehashed on the next successful verify. A 6-digit PIN is brute-forceable offline from a leaked DB.
  - **Rate limiter:** set `cache.limiter` (or CACHE_STORE) to redis on the server for up-front burst protection. The durable per-witness lock already works.
  - **External or mobile apps** must send the witness PIN to the API witness fields. The login password is rejected and counts towards the lock.
  - **PIN-2 scope** is unchanged: the forgotten-PIN "I was there" fallback, CD witness overrides plus `medications.controlled.witness_override`, the heads-up, and the task/calendar providers.
- **Housekeeping (ask before dropping):** the isolated browser DB `oblivion_findings_pin1_browser` is still on Stephan's PC.

## Rory-rules build corrections (from the design-rules audit, 1 Oct)

Rory's rules are DESIGN.md plus `design_styles/*`. **Where a frozen mockup and Rory's rules disagree, the build follows the rules.** Each correction below is a deliberate, recorded difference from the approved mockup, for Stephan's final inspection. Main checks every chunk against all 12 guides, not only the mockup checklist. Full evidence (file:line and screenshots) is in the scratchpad reports `rory-audit-A.md` and `rory-audit-B.md`.

**These apply to every package's build:**
- **List pages have nothing below the header band.** Sub-views (e.g. To triage / Investigating, or a report's sub-tabs) move inside the header, as rail tabs or header filter chips (PAGE_HEADER §2).
- **A scoped search field always sits in the header** (PAGE_HEADER).
- **Every header meter block is a link** to the view where its number lives (PAGE_HEADER).
- **A share of a whole is shown as a donut** (e.g. "Given as due", "Witnessed") (PAGE_HEADER meter table).
- **Every list row has both the ⋯ menu and the right-click menu,** plus row click (LIST).
- **Every attachment opens in `components/files/file-preview-dialog.tsx`,** with View and Download (FILE_PREVIEW).
- **Every date or time uses the approved date/time picker,** never free text.
- **Body stat cards never repeat a header meter's number,** and use the type helpers and the standard 20 px gap (DESIGN.md).
- **Tile pickers go to 3 columns at wider dialog sizes** (POPUP).

**Package-specific, from audit B (all must-fix):**
- **P06:**
  - 5 tables need right-click; the "To remove" and "Recent counts" lists need row actions.
  - "Needed by" and "Due to arrive" become date pickers.
  - "Order from the pharmacy" gets a searchable picker.
- **P07b:**
  - 4 register tables need right-click.
  - The Witness overrides header needs search.
  - A destruction's photo is viewable and downloadable through file-preview.
- **P08b:**
  - The error stages move from the sub-tab strip into the header.
  - The Trends cards stop repeating the "Closed" meter, use the type helpers and a 20 px gap.
- **P09:**
  - About 35 header meters become links.
  - Percentages become donuts.
  - The header gets search, and the sub-tab strips move into the header.
  - The governance cards stop repeating meter numbers.
- **P10:**
  - The Downtime and Emergency access headers get search, and their meters become links.
  - A single downtime opens as a record page (back chip in the header), not in the list body.
  - Paper scans are viewable and downloadable through file-preview.
  - "Record a downtime" uses the shared file cards and says "attached" only after upload.

**Recorded exception, not a gap:** locked forward steps in wizards come from the approved P01 package.

**Package-specific, from audit A (Main spot-checked: POPUP "tile picker … never a <Select>" for categorical types such as severity, and "use search for a directory that can grow"):**
- **P00:** its Settings frames and its person-record frame are superseded. Build Settings only from P11 v5, and the person record only from P02 v1.
- **P11:**
  - "Can witness 4 of 10" and "On-call contacts 0 of 2" become donuts (chunks 6 and 7).
  - The support worker Acknowledge dialog uses `.frontline-tap` (44 px) (chunk 6).
  - Staff eligibility's sub-tab strip moves into the header (chunk 6).
  - No plain text in the header filter row.
- **P01:** "How serious does it seem?" becomes a tile picker.
- **P02:** the support worker "Before you record" chart-alerts dialog buttons use `.frontline-tap`.
- **P03:**
  - 3 tables get right-click.
  - The support worker consent dialog buttons use `.frontline-tap`.
- **P04:**
  - Covert "Active authorisations" becomes a real list (⋯, right-click, status bar).
  - 3 tables get right-click.
  - Person, prescriber and assessor use the searchable record selector, and a person fixed by the parent shows as a read-only card.
- **P05:**
  - Person, owner, clinician and prescriber use the searchable selector.
  - The main Outcome uses the tile picker.
  - The sub-tab strip moves into the header.

**Confirmed by Stephan 1 Oct ("improvements and consistency → yes"):**
- **Two-option mode choices:** four more P11 settings are a Switch with a two-option mode (e.g. Block | Co-signer). **Keep the two buttons**; DESIGN.md now allows two named modes.
- **Sub-tab strips under list-page headers** (P05, P08b, P09, P11 Staff eligibility). **Move them into the header**, per Rory.
- **Page sublines that repeat the full date** already shown in the top bar. **Remove the repeated full date** (APP_SHELL §4); use "Today" or a short date where scope needs it.
- **Held guide edits** on branch `claude/emar-shared-ui-design-docs` (`f3ac05f2c`), which document the new disabled menu item, the wizard ✕ target and the narrow WizardShell. **Approved.**
- **Decided by Main:** a hub header's meters and primary action follow the active tab (P04 and P05 on Orders & reviews).
- **P07a build:** the controlled-drug witness setting (org default + per house: Follow / Always / Not required), moved here from P11 B1 (1 Oct). Build it together with:
  - one server policy behind `ClientMedication::requiresWitness()`;
  - the inline copies replaced (MedsBoardPayloadService ×2, GuidedRoundService, transport logs);
  - the frontend dialogs reading the server's `requires_witness` (emar-dialog, mar-governance-dialogs, transport dialogs ×2).
  The default is ON (today's behaviour). Turning it off is a loosening: red confirm plus a critical warning. "An order can still require a witness" is kept. Every path is tested, including Fleet transport.
- **Dose-slot projection (P01 foundation, Lane C). Decisions, 1 Oct:**
  - **Away** = approved/completed client leave, respite at another location, or a hospital stay taken from paired admission/discharge events. Excursions are not away.
  - **One `DoseWindowResolver`:** config for now. P11 chunk 4 backs it with settings.
  - **Totals include controlled doses** for every reader; named rows need controlled view.
  - **An unverified order edit leaves the old version in effect,** flagged.
  - **Backfill:** 12 months, labelled.
- **P09 build amendments:**
  - "Away" is its own number;
  - a round's result comes from the real MedicationRound slots and window, not fixed time blocks.
- **P01 C6 (Lane C) must also:**
  - rewire `MedsBoardPayloadService:144-150` (Meds today due/overdue) and `EnhancedMarService::getScheduleState` (hard-coded 60/30/180) through `DoseWindowResolver` / `DoseTimingSettings`;
  - then add back P11's "Shows as due soon" setting (one registry entry), which was omitted from B1 chunk 4 because nothing enforced it;
  - restore v5's early hint "Before this, the dose shows as not yet due".
- **P04 build (safety, found 2 Oct by C6(a)):** while an order edit awaits its independent check, staff must be able to record against the in-effect (old verified) version, flagged "Order changed — check the new instructions". Today recording refuses unverified orders, so the person can't be given the medicine at all during the wait. After this, the dashboard's "Waiting for the order check" doses go back to normal counting.
