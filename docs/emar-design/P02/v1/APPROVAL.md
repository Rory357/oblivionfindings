# eMAR P02 v1 — approval record

## What was approved

- **Package:** P02 "Person medication record", version **v1**. It covers:
  - the canonical record at `/emar/mar?client_id=…`, with the Chart, Medicines, Support plan, Allergies & alerts, Clinical and History sections;
  - the client profile's Medical › MAR tab contract;
  - the MAR & medicines hub (MAR charts, Medicines, As-needed history), which the review session added to P02 on 30 September;
  - the eight dialogs absorbed or redesigned.
- **Exact version:** commit `28a5a2ddf` on branch `claude/interesting-lalande-efa3cf`. The approved files are the 62 listed in [`VERSION.txt`](VERSION.txt), whose own SHA-256 is `7bc8604f5889c15cf728dfb7d8a7ac9cc31adf47d0aaa8a3ad59341abce149b5`. This file sits beside them and is not part of the approved design.
- **Approved by:** Stephan, 30 September 2026 (NZDT). He replied "approve" in the review session ("Codex eMAR audit re-review"), where the approvals page read "P02: approve, all as recommended". The review session relayed this to the design session the same day.
- **Before approval:** the review session inspected `3b22fed5e` against the Mockup-design-rules checklist and the live Fleet vehicle profile.
  - It asked for:
    - Record INR linking to the anticoagulant (`34d9dad25`);
    - the "No medicine linked" wording and the MAR & medicines hub (`438084956`);
    - "↗" jumps using `variant="link"` (`28a5a2ddf`).
  - It passed `438084956` with that last fix, which needed no re-inspection.
- **Evidence** (not part of the version): 223 screenshots with `screenshots/report.json`. They cover 109 states at 1440 px and 57 core states also at 1280 px and 200 %. Horizontal overflow and console errors were 0 on every capture, and every scripted step completed.

**v1 is frozen.** Any further change goes in `P02/v2/` and needs its own approval.

## Stephan's answers

The answers given before the build, 30 September:

| # | Question | Answer |
|---|---|---|
| A1 | An INR result with no linked medicine (NF-23) | **Confirmed as applied.** Every INR result is shown, labelled "No medicine linked" when it has none. Record INR links the result to the person's anticoagulant: one order is pre-chosen, several are chosen in a picker, and none gives "No medicine linked" with a required reason. A lead can link a result afterwards. |
| A2 | Where allergies are edited | **On the health profile only** (client profile › Health & safety › Medical). The API-only medication allergy list is merged into it at build. |
| A3 | Who confirms the allergy list | **House leads and clinical leads.** They mark it "reviewed" or "No known allergies" and say how they checked. It shows "Not reviewed" until then. The review interval stays Not configured until the clinical lead sets one. |
| A4 | What the old house can see after a move | **Confirmed as applied.** Access follows the person's current house, so old-house staff get "We can't show this record". Each dose keeps the house it was given at. |

The answers given at approval:

| # | Question | Answer |
|---|---|---|
| Q1 | House move (A4) | Confirmed as applied (see A4). |
| Q2 | Who approves a correction | **Anyone with correction access except the person who asked.** This is today's two-person rule, unchanged. |
| Q3 | Syringe-driver checks | **Staff on shift who give doses can record checks. Leads start and finish the driver.** |
| Q4 | Reading chart alerts | **Stored:** "I've read the chart alerts", with who and when. |
| Q5 | Readings taken with a dose | **Also shown in Health monitoring**, stored once and shown in both places. |
| Q6 | Record layout | **Sections go on the header rail** and sub-views on the tier-2 strip, as on the Fleet vehicle profile. |

## Build notes

These are for the implementation session. They change today's code or permissions, and they sit here because AUDIT.md is one of the frozen, hashed files.

1. **Q3 changes a permission.** Recording a syringe-driver check today needs `medications.orders.manage` (`routes/emar.php:204`, inside the `orders.manage` group at `:176-232`).
   - The build moves the checks route to `medications.administer.record`, together with the person's shift or house scope.
   - Start (`:203`) and complete (`:205`) stay with `orders.manage`.
   - Test both roles. If a new or changed permission key is involved, ship a grant migration (deploys skip seeders).
2. **Q4 is new storage.** Today the warnings dialog's "Acknowledge & continue" only closes (`mar-governance-dialogs.tsx:1008-1016`).
   - Record one acknowledgement per reader, per person, per day, for alerts shown when the chart opens.
   - Make it visible in History › All changes.
3. **Q5.** Dose-linked readings (the blood glucose, pulse and blood pressure columns on `client_medication_administrations`) are also surfaced in the client's Health monitoring. They are stored once, not copied into `ClinicalObservation`.
4. **Q2** needs no change. Keep `MedicationAdministrationCorrectionController.php:50-58`, and fix the case where approving your own correction is treated as success (AUDIT §2).
5. **Controlled medicines for people without `medications.controlled.view`** (AUDIT §7, confirmed by the review session):
   - cross-person lists leave the rows out and count them in the caption;
   - the person's own record redacts them;
   - the same applies to counts, search, exports and the printout.
6. **"↗" jumps use `variant="link"`.**
7. **Bugs the build must fix are listed in AUDIT §6.** They include:
   - INR target and dose never shown;
   - Start syringe driver always refused;
   - controlled names in the `interactions` payload;
   - an explicit `client_id` skipping the per-client `viewMedications` check.

   The review session has opened a separate fix task for the privacy items and the driver refusal. The NF-23 reader fix is live on main as `d9dc17fa5`. The Record INR medicine link is P02's (A1).
8. **Shared-component follow-ups at build, from P01 v1's approval:**
   - `DateTimeField` `clearable={false}` on required times;
   - an `entity-menu` item that shows as disabled, with its reason.

   Plus the destructive ConfirmDialog colour (PR #15).

## Build dependencies

- **Recording is P01's.** It uses P01 v1's dialog, approved as `3ac640485`; P02's copy in `src/p01/` is byte-identical. The build follows P01's approved changes: Q2 (don't block when there's no second person), Q11 (a real allergy match is always the critical surface), and the other approved changes.
- **Linked, not designed here:**
  - Orders & reviews (P04);
  - Support & self-administration and the support vocabulary, D6 (P03);
  - pack-photo capture at stock receipt (P06);
  - follow-ups and effect checks (P08a);
  - errors (P08b);
  - Reports & audit (P09);
  - emergency access (P10);
  - the settings for alert routing and the allergy review interval (P11).
- **The preview** is `node docs/emar-design/P02/v1/serve.mjs` → http://127.0.0.1:4383/. It uses synthetic data only.
