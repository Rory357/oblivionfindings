# eMAR P05 v1 — Medication review

**Status: candidate v1.1 for Main’s approval.** v1 was inspected on 1 October; v1.1 makes Main’s one fix and records the confirmed decision (see “Main’s inspection of v1”). Main is the review session, “Codex eMAR audit re-review”, acting under Stephan’s delegation. This design is not implemented.

- **Version:** v1, 30 September 2026 (NZDT). Branch `claude/emar-p05`, based on `origin/main` `2e1d38a8a`.
- **Exact file identity:** [`VERSION.txt`](VERSION.txt), the SHA-256 of every source, build and tool file.
- **Design only:**
  - No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed.
  - Nothing here calls an application API.
- **Contracts reused:**
  - **P04 v1** (`24d230ed9`):
    - The Orders & reviews hub, whose “Medication reviews” rail view P05 designs.
    - The order change: Enter a change → checked by someone else with `orders.verify` (Q2).
    - The phone and verbal rule: read back with a witness PIN, and the prescriber’s written confirmation before the check (Q3).
  - **P03 v1** (`9822d78b4`): any change a review leads to flags the person’s support for reassessment (the P03 trigger → a P08a follow-up).
  - **P02 v1** (`28a5a2ddf`):
    - The person record frame, via P03’s copy. P05 adds Clinical › Medication reviews.
    - P02’s redaction of a controlled medicine inside the person’s record.
    - The person rule for lists, with no count of hidden rows (Approval record, 30 Sep).
  - **P08a v1** (`c5c115092`): “Watch for something” is a P08a follow-up for the house lead.
  - **P11 v5** (`12ecb24a2`):
    - The Settings frame and its group/row pattern. P05 adds one setting (below).
    - The existing “Medication review due” alert and its Delivery routing.
  - **P07b v1.1** (`6fe3c0766`): the scaffold, harness and dialog host.
- **Linked, not designed here:**
  - Entering and checking the order change (P04).
  - The follow-up itself (P08a).
  - Support reassessment (P03).
  - The other Orders & reviews views (P04).
  - Every other Settings view (P11).
  - The rest of the client profile.

## Main’s answers (30 September 2026, under Stephan’s delegation)

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

## Open it

```
node docs/emar-design/P05/v1/serve.mjs
```

Then open http://127.0.0.1:4392/ — port 4392, as Main asked. The other ports: P02 4383, P01 v2 4384, P07a 4385, P08a 4386, P03 4387, P04 4388, P06 4389, P07b 4390.

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Signed in as:**
  - Priya Shah: support worker; read only.
  - Jordan Tipene: house lead, Kōwhai House; `reviews.manage`, with P04’s `orders.manage` and `orders.verify`.
  - Hana Kereama: clinical lead; `reviews.manage` and orders, **no controlled-medicine keys**; changes the organisation default.
  - Mereana Walsh: auditor; read only.
  - Rangi Parata: provider manager, both houses.
  - Sione Taufa: house lead, Rimu House.
- **Scenario:** normal · loading · no reviews yet · couldn’t load · out of date · offline.
- **Links** to the Reviews view, Grace’s record › Clinical › Reviews, her client profile › Actions & Reviews, Settings › Medication reviews (P11), and the contract page.

Records made in the preview survive persona switches. They reset on reload or when the scenario changes. The clock is P01’s: **Monday 28 September 2026, 9:12 am NZDT**.

**To rebuild:** run `npm ci`, then:

```
node node_modules/vite/bin/vite.js build --config docs/emar-design/P05/v1/vite.config.mjs
```

**For the evidence:** start the server, then run `node docs/emar-design/P05/v1/tools/verify.mjs`.

## What P05 decides

### 1. Orders & reviews › Medication reviews (Q1)

The page top is P04’s approved Orders & reviews header: title, chip, search, “Book a review” and the rail. The subline is the Reviews view’s own.

**Meters** — each opens its view:
- Overdue (with “away” when the person is away).
- Due in 30 days (with outcomes to record).
- Booked (later than 30 days).
- Waiting for the prescriber.
- Changes to make.
- Done (in the last 90 days).

**Views** (the tier-2 strip):
- **To do:** Overdue, and Due in the next 30 days.
- **Changes:** every open change, in step order — outcome to add · waiting for the prescriber · agreed, to enter · entered, waiting for the written confirmation or the check · watching.
- **Booked:** later than 30 days.
- **Recorded:** who did the review, the outcome counts, and the next review.
- **Cancelled or closed:** kept, with the reason.

**Rows:** each opens on click. ⋯, right-click and the menu key give the same menu. The row button is the next step for you: Record the outcome · Book the appointment · Record the decision · Enter the change · Add the outcome.

Filters: house (managers), kind, and the “As at” chip.

### 2. The person record › Clinical › Medication reviews (Q1, Q2, Q9)

A new sub-view beside P02’s INR, Syringe driver and Observations:
- **Next review:** its state, the appointment, the owner, and **how often** (the person’s own interval with the reason, or “the organisation default”). “Away” is shown when it applies.
- **Changes from reviews** that are still open, and what to watch.
- **Every review, kept**, including cancelled and closed ones.

**Change how often** sets 1–12 months or goes back to the organisation default, with a reason. A booked review keeps its date. The interval sets the next one.

### 3. Book a review (Q2, Q3) — one wizard, replacing both of today’s

Steps: **who and why → when and with whom → review & save**.
- **The person:** only people at your houses.
- **Regular or triggered.** A second regular review is refused, with the booked one named.
- **The trigger** comes from the fixed list, with an optional note (required for “other”).
- **Due by:** the approved date picker, not in the past.
- **Optional:** the clinician, the appointment (the PKG-01 date-and-time field) and where.
- **The owner.**

### 4. Record the outcome (Q4–Q7)

Steps: **who did it → who took part → each medicine → summary & letter → next review → review & save**.
- **Who did it:** the clinician (or “someone else” with name, role, practice and registration), when (not in the future) and how.
- **Who took part:** the person, and whānau / welfare guardian / EPOA, each answered, with who or why.
- **Each medicine:** the person’s current orders from the chart, each with an outcome.
  - A change needs what the clinician recommends.
  - If the clinician prescribes, “agreed it at the review” can be ticked.
  - A watch needs what to watch and until when.
  - **Start something new** adds a row.
  - **A controlled medicine is redacted** for people without controlled view, and saved as “Outcome to add” for a house lead.
- **Summary & letter:** the clinician’s words (shown only to people who manage reviews), the written review (FileDropzone), and optional clinician figures (drug burden index, falls) — never calculated.
- **Next review:**
  - A regular review books the next one automatically, at the interval.
  - A triggered one leaves the regular cycle as it is.
  - Either can set an earlier review if the clinician asked for one.
- **When you save,** it says what happens: nothing changes on the chart; changes wait for the prescriber; follow-ups open; the next review is booked; support is flagged for reassessment.

### 5. A change, and where it’s at (Q6, Q7)

The change dialog is a numbered timeline:
- recommended at the review;
- asked the prescriber;
- the prescriber’s decision (with what they wrote);
- entered in Orders;
- **the prescriber’s written confirmation** (for a phone or in-person decision — P04’s phone rule);
- checked.

A watch has its own timeline: the follow-up, then watched. A change that wasn’t agreed stops at the decision and is kept.

- **Record the prescriber’s decision:**
  - The choice is agreed · not agreed · asked, no answer yet.
  - Record the prescriber, when, and how: in writing (attach it, required), by phone or in person.
  - Add what they said (required when not agreed).
  - An agreed phone or in-person decision shows the phone-rule notice.
- **Enter the agreed change:** the bridge to P04. It shows what Orders’ “Enter a change” receives, pre-filled and linked to the review:
  - the person, the order now, what’s changing, and where it came from (written, phone or verbal);
  - then “Continue in Orders”.

### 6. Move, cancel, the appointment (Q8)

- **Move the review:** a new due date, a reason from the fixed list, and a note (required for “other”). Earlier moves are listed.
- **Cancel:**
  - A **regular** review says it can’t be cancelled and offers “Move it instead”.
  - A **triggered** one takes a reason, then a destructive confirm.
- **Book or change the appointment:** the clinician, the date and time, and where. The earlier appointment is kept.
- **A person who left the service:** their open reviews show “Closed automatically” with the reason (Wiremu).

### 7. Settings › Rounds & timing › Medication reviews — an addition to P11 (Q2)

This is drawn as a P11 v5 frame: its header, meters, rail and section strip, and its group/row pattern. It is **not in B1**; it is built with P05.
- **Regular reviews:**
  - “Regular review every” is a number of months (1–12), marked **Default — not yet reviewed** at 3 months, with “Keep today’s value”.
  - Below it, the people with their own interval.
- **Around a review:**
  - The “Review due alert”, with a link to Delivery (the existing P11 alert).
  - What happens when a person leaves.
- **The sticky save bar.** **Review changes** shows before → after, and a “Loosens this check” warning when the interval gets longer.
- **The change history** gets the saved row.
- **Who can change it:** only people who manage medication settings for all houses. For house leads and auditors the bar says why not.

### 8. Client profile › Actions & Reviews (NF-05)

This is **the real `ActionsReviewsTab`, unchanged**, inside P02’s client-profile frame. P05’s items sit among the tab’s others:
- the next review (overdue, due or booked);
- each open change;
- what to watch (as a follow-up).

They are the same records All Tasks shows the owner, and each opens the exact review or change. A controlled medicine reads “A controlled medicine” without controlled view.

## Build notes (for the implementation plan)

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

## Verification (30 September 2026)

- **`tools/verify.mjs`:** 151 captures — all 77 states at 1440, plus the 37 core states at 1280 and at 200 %. Across all of them:
  - overflow 0 and console errors 0;
  - every step completed;
  - no truncated meter captions or table cells.

  Details are in CHECKLIST §4 and `screenshots/report.json`.
- **Keyboard:**
  - Enter on “Record the outcome” opens the wizard, and Tab stays inside it.
  - Escape closes the untouched wizard and returns focus to the button.
  - The menu key opens the review row’s menu.
- **`tsc` and ESLint:** clean for `src/` (ESLint: 19 files, 0 problems; no unused imports). The 2 `tsc` errors in shared files come from P01’s Inertia shim, as in the earlier packages.

## Main’s inspection of v1 (1 October 2026)

Identity verified: VERSION.txt sha256 `48b0efec…`, 33 files, docs-only diff, branched from `2e1d38a8a`.

- **Confirmed (Main, 1 Oct):** when someone without controlled-medicine access records a review, a controlled row is saved as **“Outcome to add”**, and a house lead with access adds it in the small dialog. It never defaults to “Continue”.
- **Fixed in v1.1:** a regular review’s row menu offered a red “Cancel the review” that only led to a refusal. It’s now left out of a regular review’s menu, as approved mockups leave unavailable items out, and “Move the review” stays. The rule — “Regular reviews are moved, not cancelled” — is shown in the review’s “This review” section and on the Move dialog. A regular review reached by its cancel link says the same and offers “Move it instead”. Triggered reviews keep a working Cancel. The review’s own footer never offered Cancel.
- **P11 B1 build note (Main, 1 Oct):** P11’s meter caption “5 not configured · the rest are defaults” truncates at 1280 px and 200 %, in P11 v5 as well. It becomes “5 not configured”. P05 keeps P11’s copy as it is.

## Deviations (for Main)

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
