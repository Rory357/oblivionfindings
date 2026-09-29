# eMAR P02 v1 — Person medication record

**Status: design candidate for inspection by the review session (“Codex eMAR audit re-review”), then Stephan’s exact-version approval.** Not approved, not implemented.

- Version: v1, 30 September 2026 (NZDT). Branch `claude/interesting-lalande-efa3cf`, based on `origin/main` **`ddb8d3af4`** (fetched the same day).
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of every source, build and asset file). Approval applies to those hashes only.
- Design only: no application code, routes, schema, seeders, configuration, DESIGN.md or design_styles changed. Nothing here calls an application API.
- Contract: **P00 v5** (`ff3bff860`) wording and states; **P01 v1** (`3ac640485`, approved 30 Sep — `f5104956a`) is the recording dialog, reused **byte-identical** in `src/p01/`; **P11 v4** (`71d86968c`) concealment wording. Settings (P11), follow-ups/handover (P08a), support and self-administration (P03), orders (P04), stock and photo capture (P06), errors (P08b), audit/reports (P09) and emergency access (P10) are linked, not designed.

## Build method

A Vite + React preview built exactly like P01 v1 (its scaffold, harness and contract page copied): `vite.config.mjs` aliases `@` to `resources/js` and uses `@tailwindcss/vite`, so every primitive is the app’s real component with the real tokens. `src/p01/` holds P01 v1’s eight recording files plus its Inertia shim, unchanged, so a due dose on the chart opens P01’s approved dialog and its records appear on this chart at once. P02’s own state (`src/store.tsx`) sits beside P01’s; the chart reads P01’s live dose states for today and P02’s fixtures for earlier days (`src/model.tsx` — “one payload”). The app shell chrome is reproduced with the shell tokens, as P01 does.

## Open it

```
node docs/emar-design/P02/v1/serve.mjs
```

Then open **http://127.0.0.1:4383/** — port **4383**, not the 4382 the brief suggested: 4382 is already serving `docs/emar-design/P01/v2/` from another session. The hatched bar is the **mockup viewer, not product UI**: *Signed in as* (Priya Shah support worker · Jordan Tipene house lead · Hana Kereama clinical lead without controlled-medicine access · Mereana Walsh auditor · Rangi Parata provider manager · Sione Taufa Rimu House lead · Tui Morgan HR without medication access), *Person* (Aroha, Tama, Mele, Grace, Sam, Ben — each carries a state, named in the list) and *Page state* (normal, loading, no medicines, couldn’t load, out of date, INR test overdue, INR saved with no medicine linked, two anticoagulant orders). Changes made in the preview survive persona switches and reset on reload. The clock is P01’s: **Monday 28 September 2026, 9:12 am NZDT**.

**The contract** (`#/p02/contract`) states the decisions, the one place each fact is edited, who sees what, and deep-links every state and all eight dialogs.

Rebuild: `npm ci`, then `node node_modules/vite/bin/vite.js build --config docs/emar-design/P02/v1/vite.config.mjs`. Evidence: `node docs/emar-design/P02/v1/tools/verify.mjs` (headless Chrome over CDP; writes `screenshots/` and `screenshots/report.json`).

## What P02 decides

### 1. One canonical record, shaped like the Fleet vehicle profile

`/emar/mar?client_id=…` mirrors `fleet-assets/vehicles/show.tsx` + `vehicle-header.tsx` on main: profile PageHeader (photo ring, name + “Active”, a two-line identity subline, **Find in this record**, **Print MAR**, one primary **Record dose**), a meter row that links each number to its view (Due now · Late · Recorded today donut · Medicines · Allergies · INR — or Syringe driver), an **“As at 9:12 am · Pacific/Auckland”** filter chip with real pills for every view, the six sections on the connected-tab rail with Find, and each section’s sub-views on the tier-2 strip:

| Rail section | Tier-2 views | Absorbs |
|---|---|---|
| Chart | Scheduled doses (Day · Week) · As needed | today’s MarGrid, dose-context-menu, PRN card |
| Medicines | Current · Stopped · Photos | MedicationDetailDialog (sectioned WizardShell) |
| Support plan | By medicine · Assessment | per-medicine support from the self-administration assessment |
| Allergies & alerts | Allergies · Chart alerts · Interactions | ManageAlertsDialog, WarningsDialog, InteractionsDialog |
| Clinical | INR · Syringe driver · Observations | RecordInrDialog, SyringeDriverDialog, clinical rail |
| History | Doses · Corrections · All changes (audit access) | CorrectionsReviewDialog, MedicationEventDrawer |

The plan (§2.2) names these six as a “tier-2 sub-nav”. The live gold-standard record page puts a record’s sections on the rail and their sub-views on the tier-2 strip, so P02 follows Fleet — **declared deviation (Q7)**.

### 2. Recording is P01’s

A due cell, the header’s Record dose and the profile tab’s Record open P01’s dialog (“Opened from: MAR chart / Client profile”). The one-click **Mark given** is offered only for P01’s simple doses (Q6 of P01, confirmed by Stephan); anything else says why. P01’s approved change Q11 is applied on the chart: a real allergy match is always the critical line.

### 3. The client profile’s MAR tab is a summary and launch point

It reads the same payload as the record: allergies (with review line), chart alerts, last dose, due or late now (Record), active medicines (each opens the record), **Record dose** and **Open medication record**. It drops today’s four number tiles (they repeated the header’s Medications meter), the stock card, the per-medicine Sign / Give PRN buttons and the row of four links. Nothing is edited there.

### 4. One place to edit each fact

| Fact | Edited only in |
|---|---|
| Dose outcome | P01’s dialog; corrections on the record |
| Order | Orders & reviews (P04) |
| Support for a medicine | Support & self-administration reassessment (P03) |
| **Allergies and their review** | **The health profile** — client profile › Health & safety › Medical (Stephan, 30 Sep) |
| Chart alerts, dose-alert pause | Allergies & alerts on the record (house and clinical leads) |
| INR, syringe-driver checks | Clinical on the record |
| Pack photos | Stock receipt (P06) |

### 5. States

Allergies recorded and reviewed (Aroha) · recorded, **not reviewed** (Mele) · **none recorded** (Tama) · **couldn’t load** (Grace) · **no known allergies** (Sam); **controlled medicines concealed** for Hana and the auditor in the chart, week, medicines, support, photos, alerts, interactions, INR/driver (a driver with a controlled medicine is hidden whole), history, corrections, all changes, captions, dialogs and the printout, using P11 v4’s “Details need controlled-medicine access”; the **correction chain** (waiting, approved, declined; the two-person rule shown before anyone tries; controlled records can’t be corrected here); **stale INR** (test overdue); **INR and its anticoagulant** — one order pre-chosen, two orders chosen in a picker (and linked afterwards), no anticoagulant (Tama) giving “No medicine linked” with the reason, linked and unlinked results side by side (NF-23); the **house move** (Ben: Rimu House sees the move banner and a House column; Kōwhai staff get “We can’t show this record”); **no access** (page) vs **not found** (record); **loading, no medicines, couldn’t load, out of date**; **read-only** auditor; pause dose alerts and stop-showing-on-open as **loosening** with a destructive “Loosens this check” confirm.

## Stephan’s answers (asked before building, 30 September 2026)

| # | Question | Answer | Applied as |
|---|---|---|---|
| A1 | An INR result saved with no medicine is hidden (NF-23) | “follow industry standard”; then **decided (30 Sep, relayed by the review session): every INR result is shown, labelled “No medicine linked” when it has none** | Record INR links the result to the person’s anticoagulant: **one order → pre-chosen** (“Not for this medicine” switches to none); **several → chosen in a searchable picker**, never pre-selected; **none → “No medicine linked” with a required reason**. A lead can link a result afterwards. Clinical › INR shows linked and unlinked results side by side. |
| A2 | Where allergies are edited | **Health profile only** | The Medical tab’s allergy card is the one place; the API-only medication allergy list is merged into it (severity, reaction) at build; the record links there. |
| A3 | Who confirms the allergy list | **Leads confirm it** | House and clinical leads mark “reviewed” or “No known allergies”, saying how they checked; “Not reviewed” until then; review interval Not configured (clinical lead). |
| A4 | What the old house sees after a move | “follow industry standard” | **Access follows the current house** (need-to-know): old-house staff get “We can’t show this record”; each dose keeps the house it was given at; the new house sees the move banner. *Confirm at approval (Q1).* |

These go to the review session for the Approval record.

## Open questions for Stephan

1. **House move (A4):** confirm — access ends for the old house at the move; history keeps the house of each dose.
2. **Who approves a correction?** Today anyone with correction access except the person who asked — including support workers. Keep that (recommended: it works on a shift with no lead), or leads only?
3. **Syringe driver checks:** today only leads (`orders.manage`) can record a check. Let staff on shift who record doses record checks too (recommended), with leads starting and finishing a driver?
4. **Reading chart alerts:** store “I’ve read them” (who, when, once a day) — today it isn’t saved (recommended), or keep it as a reminder only?
5. **Readings taken with a dose** (blood sugar before insulin): also show them in the client’s Health monitoring (recommended: stored once, shown in both), or keep them in the dose record only?
6. **Sections on the rail (layout):** the record follows the Fleet vehicle profile — sections on the header rail, sub-views on the tier-2 strip — rather than the plan’s single tier-2 row. OK?

Also for the review session (not Stephan): which package designs the **MAR & medicines hub** itself (the cross-person chart board with no person chosen)? P02’s breadcrumb and back chip point there; the preview shows a boundary page.

## Deviations declared

- **Port 4383**, not 4382 (taken by P01 v2).
- **Two-line subline** on the record and profile headers: `PAGE_HEADER_STYLE_GUIDE.md` §4 requires two lines on record profiles, and the live Fleet vehicle and client profiles use two. The one-line rule from P01’s review applied to index pages.
- **P01 files byte-identical**, plus P01’s Inertia shim moved into `src/p01/` (one shim for both stores). `tsc` is clean for `src/`; four type errors remain in shared components typed against the shim (`breadcrumbs.tsx:36`, `laravel-pagination.tsx:48,63,77`) — the same shim P01 used.
- **Client profile frame**: header, two meter rows, group rail and Health & safety tabs copied from main; the recent-clients strip under the tabs is omitted; meters other than Safety and Medications are fixture values from the live layout. At 200 % two of its captions truncate — the real header component, unchanged.
- **Ben’s Rimu House dose** is shown as already recorded (P01’s fixtures only know Kōwhai House), so Sione has no Record dose to press.
- Actions owned by other packages or global chrome say so in a toast (“… — outside this preview”), the boundary P01 used.

## Verification (30 September 2026)

- `tools/verify.mjs`: **198 captures** — all 94 states at 1440 × 900 and the 52 core states also at 1280 × 800 and 200 % (720 × 450 CSS px at device scale 2). **Horizontal overflow 0, console errors 0, every scripted step completed** (`screenshots/report.json`). Header sublines are two lines (record and profile). Meter captions: none truncated at 1440 or 1280; at 200 % only the client-profile frame’s real Medications caption (“active meds · no pending alerts”, live wording) truncates.
- Keyboard (real key events over CDP): Enter on a focused chart cell opens P01’s dialog; Tab stays inside it; Escape closes it and focus returns to the same cell; the keyboard menu key on a cell opens the same menu as right-click.
- `tsc` clean for `src/`; the app’s ESLint config: 24 files, 0 errors, 0 warnings.
- Compared at 1440 in the browser with the live Fleet vehicle profile (`/fleet-assets/vehicles/11`), the live client profile MAR tab and today’s `/emar/mar`, as Demo Admin on oblivionfindings.test.

## Approval requested

After the review session’s inspection, please approve **eMAR P02 v1** exactly as identified by the hashes in `VERSION.txt`, with answers to Q1–Q6, or list the changes for a v2.
