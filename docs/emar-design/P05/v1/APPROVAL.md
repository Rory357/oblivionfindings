# eMAR P05 v1.1 — approval record

## What was approved

- **Package:** P05 “Medication review”, version **v1.1**.
- **Exact version:** commit `22982b1ff` (`22982b1ff81d252e446f543a3494ea918b03a047`) on branch `claude/emar-p05`.
  - The approved files are the 33 files listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `03183d87dcc77a7a70600d253dcf4b6919bc2003fc22324a41a6a5849a915bfa`.
  - This file sits beside them and is not part of the approved design.
  - The README’s status line still says “candidate v1.1 for Main’s approval”. It is a hashed file, so it stays as approved; this record is the approval.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 1 October 2026 (NZDT). The delegation is recorded in `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”.
- **Before approval:**
  - Main inspected v1, `a402dfcd7`: identity verified (VERSION.txt `48b0efec…`, 33 files, docs-only, from `2e1d38a8a`).
  - These passed:
    - the phone-rule timeline;
    - the controlled row (“A house lead records this one”);
    - the P11 addition, in P11’s pattern;
    - NF-05 in the real tab, with the controlled text redacted for Hana;
    - the overdue arithmetic and “Away”;
    - the support worker, with no actions.
  - It passed with one fix, made in `22982b1ff`:
    - A regular review’s menu no longer offers “Cancel the review”, which only led to a refusal.
    - The shared `MenuItem` has no disabled state, so approved mockups leave unavailable items out (Main chose option A).
    - “Move the review” stays, and the rule is shown in the review and on the Move dialog. Triggered reviews keep Cancel.
  - The touched states were re-run: 15 captures, 0 problems. `report.json` holds 151 captures, all passing.
  - Main checked the committed tree: Cancel is gated to triggered reviews (`reviews.tsx:99`), and Sam’s menu is clean on screen 02.

**v1 is frozen at v1.1.** Any further change goes in `P05/v2/` and needs its own approval.

**Decisions at inspection:**
- **Confirmed:** when someone without controlled-medicine access records a review, a controlled row is saved as “Outcome to add”, and a house lead with access adds it. It never defaults to “Continue”.
- **Deviation 3 → (A), 1 Oct:** team_lead gets `medications.orders.manage` through a grant migration that ships with the P04 build.
- **P11 B1 build note:** P11’s caption “5 not configured · the rest are defaults” becomes “5 not configured”.
- **Build note 15:** the shared `MenuItem` gains `disabled?: string`, applied app-wide at build.

## Main’s decisions under delegation (30 September – 1 October)

All nine questions took the recommended option (A). Main added five points, built into v1.

| # | Question | Answer |
|---|---|---|
| Q1 | Pages | **Orders & reviews › Medication reviews** at `/emar/reviews`, in the P02 hub pattern. The view has **its own meters**: Overdue · Due in 30 days · Booked · Waiting for the prescriber · Changes to make · Done. **The person record gets a Reviews section**, and **Actions & Reviews and All Tasks** deep-link to the exact review. |
| Q2 | How often | **One per-person interval, 1–12 months**, set on the person’s record. **An organisation default in Settings**, shown as “Default — not yet reviewed” and starting at today’s 3 months. **Recording a review books the next one**, a real review owned by the house lead. `next_chart_review_date` is folded in. No cadence or authority claims in the copy. **Addition:** the Settings row is drawn in P11 v5’s exact pattern, as an **addition to P11**, built with P05 and not in B1. |
| Q3 | What starts a review | **Regular**, from the interval, or **triggered** from a fixed list: back from hospital or respite · a fall or injury · a medication error · a change in health or behaviour · the person, whānau or GP asked · the refusal pattern (3 in 7 days) · other (say what). Moving in or leaving stays with P04’s reconciliation. |
| Q4 | Who does it | **A clinician** — GP, pharmacist, nurse practitioner or specialist, usually from outside — recorded by name, role and practice, with registration optional. **A staff member owns it**: they book it and record the outcome, and the system stores who recorded it and when. **Addition:** an optional attachment of the clinician’s written review (letter or report), on the private disk. It is the source record. |
| Q5 | Who took part | **Structured, and each part must be answered**: the person (took part, or why not), whānau / welfare guardian / EPOA (took part, told afterwards, or why not), and how (in person, phone, video). |
| Q6 | The outcome and P04 | **Each current order gets an outcome**: Continue · Change the dose or times · Stop · Start something new · Swap · Watch for something. **A change is a recommendation, not an order.** It waits for the prescriber’s decision (agreed or not, who, when, how). An agreed change appears in **Changes to make**, is entered with P04’s “Enter a change” linked to the review, and is then checked (P04 Q2). **Addition:** a decision recorded “by phone” follows P04’s phone rule when entered — written confirmation before the check — shown in the change’s timeline. |
| Q7 | Watching | “Watch for something” (what, until when) creates a **P08a follow-up for the house lead**. The person record shows it while it’s open, and its outcome is recorded on the review. |
| Q8 | Move and cancel | **Moving needs a reason** from a fixed list, and **every earlier date is kept**. **A regular review can’t be cancelled**, only moved. A triggered one can be cancelled, with a reason. Overdue means the day after the due date, NZ time. **Additions:** when the person leaves the service (inactive, discharged or died), their open reviews **close automatically** with a system reason. **Being away (NF-13) doesn’t pause the due date**; an overdue review shows “Away — {reason}”. |
| Q9 | Who | **New key `medications.reviews.manage`**, with a grant migration to team_lead, clinical_lead, coordinator and provider_manager. It covers book, move, cancel, record the outcome and record the prescriber’s decision. **Entering an agreed change stays `orders.manage`; checking it stays `orders.verify`.** Everyone with `medications.view` sees the reviews for their own people. **Addition, inside one person’s review (P02’s redaction):** controlled rows read “Controlled medicine”, with no name, strength or dose and no edit, for people without controlled view. The clinician’s free-text summary shows only to `reviews.manage` holders. Support workers see the structured outcome, what to watch and the next date. |

## Build notes

These were verified on origin/main `2e1d38a8a` (AUDIT.md).

1. **The Reviews view** replaces today’s page (AUDIT 1.3):
   - The PageHero, quarter chips, KPI cards, deprescribing kanban and “GP accept %” go.
   - `?client_id` is honoured.
   - `can` flags reach the page, and the server pages the lists (no 250 cap).
2. **One Book wizard:**
   - Retire `MedicationReviewModal`.
   - `review_type` becomes `regular | triggered`, plus a `trigger` enum and a note.
   - The due date is not in the past, and there is one open regular review per person (AUDIT 3.2).
3. **Cadence:**
   - `clients.chart_review_interval_months` (1–12, nullable = the organisation default) is edited on the record, with a reason.
   - A new P11 organisation setting `medication_review_default_months` (3, “Default — not yet reviewed”).
   - Recording a regular review creates the next `medication_reviews` row.
   - `next_chart_review_date` is derived from it, then retired (AUDIT 2.2, 3.3).
4. **Recording:**
   - Clinicians (name, role, practice, registration), `recorded_by` and `recorded_at`, and the took-part fields.
   - A new `medication_review_items` table (order id, outcome enum, what, watch what/until, follow-up id, decision fields, entered order version id, `pending_controlled`).
   - The letter on the private disk.
   - Clinician figures optional and never computed.
   - `completed_date` from WorkerClock (AUDIT 3.3).
5. **The prescriber’s decision:**
   - Fields: state, prescriber, at, how, file, note, recorded_by, and asked how/at.
   - Retire `advanceReviewAction` and `gp_status` (AUDIT 3.5).
6. **Into Orders (P04):**
   - “Enter a change” is pre-filled from the item and linked both ways.
   - The source maps from how the prescriber decided: in writing → written (the file); phone, in person or at the review → phone/verbal, with read-back and written confirmation before the check.
   - The item’s state is derived from the order version.
   - Any entered change raises the P03 reassessment trigger.
7. **Watching:** a P08a follow-up (owner: the review’s owner; due: the until date). Closing it closes the item’s watch.
8. **Move and cancel:**
   - A `medication_review_moves` history with a reason enum and a note (required for “other”).
   - Regular reviews can’t be cancelled.
   - Cancel UI for triggered reviews (today’s `destroyReview`).
   - **Auto-close on leaving:** when the person becomes inactive, discharged or deceased, open reviews close with a system reason.
   - Away (NF-13) never pauses the due date and is shown beside an overdue review.
   - Overdue is derived from the NZ date — fix EC:3255, RV:132-151 and `clinical-rail.tsx:313` (AUDIT 3.7, 3.8).
9. **Permissions:**
   - New `medications.reviews.manage`, with a grant migration to team_lead, clinical_lead, coordinator and provider_manager. Deploys skip seeders.
   - Review writes are gated by it. Entering a change stays `orders.manage`; checking it stays `orders.verify` (AUDIT 5).
   - **A P04 dependency:** team_lead gets `orders.manage` through P04’s grant migration (Main, 1 October), so house leads can enter agreed changes (deviation 3).
10. **Redaction:**
    - Controlled rows read “Controlled medicine” without controlled view, with no edit; `pending_controlled` items are for a house lead.
    - The summary and letter go only to `reviews.manage`.
    - Support workers get the structured outcome, what to watch and the next date.
    - The calendar provider needs `medications.view` and the person rule (AUDIT 1.6, 6).
11. **NF-05:**
    - An All Tasks “Medication reviews” provider: due, overdue and outcome to record for the owner; decisions waiting for the owner; changes to make for `orders.manage`.
    - `ActionsAggregator` item types `medication_review`, `overdue_medication_review` and `medication_change`, with type labels “Medication review” and “Medication change”. Watches use the existing `open_follow_up`.
    - The same records throughout.
12. **Alerts:**
    - Dashboard review alerts resolve when a review is booked or recorded.
    - P11 Delivery routes “Medication review due”.
    - One “Due in 30 days” number, not 7 days on the card and 30 in the list (AUDIT 1.6, 7.10).
13. **Copy (EM-17):** remove “3-monthly…”, “Pharmacist-led, GP-signed, whānau-informed”, “HQSC expectation”, the quarter cycle and “Resident” (AUDIT 4).
14. **The person rule** for the schedule dialog’s client picker. It’s on Stephan’s end-review list, and P05 lists only the reader’s people (AUDIT 6.1).
15. **Disabled menu items with a reason — a shared change (Main, 1 Oct).** Extend the shared `MenuItem` (`components/lists/entity-menu.tsx`) with `disabled?: string`, rendered `aria-disabled` with the reason as a second line, in both the kebab and the context menu. It’s applied app-wide at build. Until then, unavailable items are left out of menus, as here.

## Deviations accepted

1. **Reference frames are reproduced:**
   - P04’s Orders & reviews header and rail. Its other four views say they’re P04’s.
   - P02’s person record header, as P03 reproduced it.
   - P11 v5’s Settings frame. Its numbers are P11’s reference numbers as Hana sees them, with “Still to decide” one higher (46) until the new default is reviewed, and its history rows up to 28 Sep.
   - P02’s client-profile frame around the real Actions & Reviews tab.
2. **P04 and P08a are linked, not redesigned.** “Continue in Orders” records the change as entered, and the follow-up opens with a toast.
3. **House leads enter agreed changes — decided (A), Main, 1 October.** team_lead gets `medications.orders.manage` through a grant migration that ships with **the P04 build**, not P05.
   - P04 v1’s approved preview already has house leads (Jordan, Sione) entering changes. Its build note 14 kept the key names and didn’t block this grant.
   - Every entry still goes through P04’s independent check by someone else with `orders.verify`, and the next-day second check when nobody else is available.
   - The preview shows house leads entering agreed changes from a review, as drawn.
4. **“Still to decide” in the P11 frame is link-only.** P11 owns that list; the new setting joins it at build.
5. **The Actions & Reviews tab shows its fallback type labels** (“Medication Review”, “Medication Change”) until the build adds labels (build note 11). The component itself is unchanged.
6. **The clinicians are synthetic** (Dr Lena Chen, Sarah Wong, Dr Arun Patel). “Someone else” covers anyone else.
7. **`ConfirmDialog` is the real one** (red, PR #15).
8. **Fixtures** follow P02–P04’s people and orders. Additions:
   - Wiremu, who left the service on 20 Sep.
   - Sam, away with whānau.
   - Ben’s own 6-month interval.
   - Reviews R-19 to R-35.
