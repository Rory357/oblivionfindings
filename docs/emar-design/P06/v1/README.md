# eMAR P06 v1 — Stock & pharmacy

**Status: approved by Main (the review session, “Codex eMAR audit re-review”) under Stephan’s delegation, 30 September 2026, for Stephan’s final inspection — see [APPROVAL.md](APPROVAL.md).** Frozen. Not implemented.

- Version: v1, 30 September 2026 (NZDT). Branch `claude/emar-p06`, based on `origin/main` `ada567669`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of every source, build and tool file).
- Design only. No application code, routes, schema, seeders, configuration, DESIGN.md or `design_styles` are changed. Nothing here calls an application API.
- Contracts reused:
  - **P01 v1 / v2** (`d96e29a52`): Meds today’s page top (via P07a’s copy); the dose dialog gains “Use the pack expiring first” and “Pack photo — received …” (build notes).
  - **P02 v1** (`28a5a2ddf`): the hub pattern and the concealment rule — controlled rows left out and counted in captions.
  - **P03 v1** (`9822d78b4`): self-managed medicines aren’t counted, and their doses don’t come off stock.
  - **P04 v1** (`24d230ed9`): dispensing moves here (Q8); the order’s Supply section reads it.
  - **P07a v1** (`8520c08b4`): controlled counts and controlled going out / coming back stay in Controlled checks; P06’s ordinary movement follows its pattern without the witness.
  - **P08a v1** (`c5c115092`): a count difference is a house-lead follow-up.
  - **P11 v5** (`12ecb24a2`) Q5: stock settings show their defaults as “Default — not yet reviewed”; alerts follow P11 Delivery.
  - **PIN-1**: the witness PIN for the controlled receipt.
- Linked, not designed here: recording a dose (P01), the person record (P02), orders (P04), controlled counts (P07a), the controlled register and destruction (P07b), follow-ups (P08a), settings (P11).

## Main’s answers (30 September 2026, under Stephan’s delegation)

**EM-10, decided before P06:** move to **lot-level stock** — a lots table with batch, expiry, quantity and source per lot — for first-expiry-first-out, per-lot expiry warnings, and no more overwriting. The migration is approved as part of the P06 build.

All ten questions took the recommended option.

| # | Question | Answer |
|---|---|---|
| Q1 | Pages | **The Stock & controlled drugs hub** (P02 hub pattern) for leads and managers, **plus Meds today › Stock alerts** for frontline staff. |
| Q2 | A pack (lot) | **Created at each receipt.** Batch and expiry are required, with an explicit “Not printed on the pack”. Expired stock can’t be received; within 7 days of expiry needs a reason. On hand is the sum of open packs. |
| Q3 | Doses use stock | **Each dose comes off the pack that expires first, automatically.** P01 shows “Use the pack expiring first” when packs differ. Self-managed doses don’t come off stock. |
| Q4 | Pharmacy orders | **One supply record per order**, through the full lifecycle (draft, sent, dispensed, received, part received, closed short, cancelled). A received order is read only. Dispensing moves here from P04. |
| Q5 | Who | **New key `medications.stock.receive`** (with a grant migration) for staff who give medicines. `stock.update` goes to house leads. Finance’s `stock.update` is flagged for review at build. |
| Q6 | Pack photo | **Prompted, not required, per pack.** Stored privately; controlled medicines’ photos concealed; history kept. |
| Q7 | Counts and movements | **Blind counts; reasoned movements on a new stock-movements record.** Ordinary removals no longer need `controlled.record`. Scheduled counts are an organisation setting, off by default. |
| Q8 | Alerts | **Days of supply for regular doses, the reorder level for as-needed ones.** P11 defaults “not yet reviewed”. Discontinued medicines never alert; day boundaries in NZ time. |
| Q9 | Controlled receipt | **P06 receives controlled deliveries as a register entry** (house lead, witness PIN, balance before and after, the pack). Everything else controlled stays in P07. The controlled batch/expiry edit leak closes. |
| Q10 | Going out / coming back | **Ordinary movements live in P06**, without a witness. |

Main also sent two live defects from the audit to a fix-first session, so the test site stops losing data before the P06 build: EM-10 (“every delivery wipes batch and expiry”) and “0.00 is truthy” (every scheduled count a discrepancy). Both are **fixed-first on main `21bfb4ce4`** (AUDIT 2.3, 3.5).

## Open it

```
node docs/emar-design/P06/v1/serve.mjs
```

Then open http://127.0.0.1:4389/ — port 4389, as the review session asked (P02 4383, P01 v2 4384, P07a 4385, P08a 4386, P03 4387, P04 4388).

The hatched bar is the **mockup viewer, not product UI**. It has:
- **Signed in as:** Priya Shah (support worker), Jordan Tipene (house lead, Kōwhai House), Hana Kereama (clinical lead, **no stock or controlled-medicine access**), Mereana Walsh (auditor, read only), Rangi Parata (provider manager, both houses), Sione Taufa (house lead, Rimu House).
- **Scenario:** normal · loading · no stock yet · couldn’t load · out of date · offline.
- Links to the hub’s five views, Meds today › Stock alerts and the contract page.

Records made in the preview survive persona switches and reset on reload or when the scenario changes. The clock is P01’s: **Monday 28 September 2026, 9:12 am NZDT**.

To rebuild: `npm ci`, then `node node_modules/vite/bin/vite.js build --config docs/emar-design/P06/v1/vite.config.mjs`. For the evidence, start the server and run `node docs/emar-design/P06/v1/tools/verify.mjs`.

## What P06 decides

### 1. Packs (lots), not one row per medicine (EM-10, Q2)

- Every receipt creates a **pack**: batch, expiry, quantity, source (pharmacy order · brought in by the person or whānau · came back with the person), who received it and when, and its pack photo.
- **Batch and expiry are required.** If one isn’t on the pack, staff tick **“Not printed on the pack”** — it’s never a silent blank.
- **On hand** is the sum of the open, unexpired packs. An expired pack is shown for removal and isn’t counted.
- **Doses come off the pack that expires first** (Q3). Staff don’t pick a pack at dose time; P01’s dose dialog says **“Use the pack expiring first: batch X, expires …”** when packs differ.

### 2. Stock & controlled drugs (the hub, Q1)

At today’s URL, `/emar/stock`, in the P02 hub pattern:
- **Meters:** Running low · Expiring · To receive · Counts to sign off · Pharmacy orders · Medicines in stock. Each opens its view.
- **Rail:** Stock · Deliveries & orders · Counts · Expiring · Removals, and **Controlled** (link-only to P07) for people with controlled-medicine view.
- **Stock:** one row per medicine — on hand and days of supply, packs and the first expiry, and the state with its lines (Out of stock · Expired pack · Running low · Count to sign off · Use first · Expiring · Delivery due · No stock · In stock · Self-managed). The row button is the next step (Receive · Remove expired · Sign off count · Order · Send the order). ⋯, right-click and the menu key give the same menu. Recent movements follow, newest first, kept.
- **Deliveries & orders:** To receive · Waiting on the pharmacy · Drafts · Closed.
- **Counts:** differences to sign off, recent counts, and scheduled counts (off, a setting).
- **Expiring:** packs expired, within 7 days and within 30 days.
- **Removals:** expired packs still in the cabinet, and everything removed or returned, with reasons.
- Controlled medicines are shown only to people with controlled-medicine view; others see them counted in captions (P02).

### 3. Meds today › Stock alerts (frontline, Q1)

The view P01 and P07a left for P06, inside P01’s approved page top: **deliveries to receive · packs to use first · running low · expired — take out of use · out with people today**. Each row says what to do and who does it (“The house lead orders it”, “The house lead receives it, with a witness”).

### 4. The stock item

A `WizardShell` viewer: **This medicine · Packs · Movements · Pack photos · Pharmacy orders**, with the next actions in the footer.

### 5. Receive a delivery (Q2, Q4, Q6, Q9)

Steps: **what arrived → packs → pack photo → (register entry, controlled only) → review & save**.
- **Against a pharmacy order**, the pharmacy’s dispensing details are pre-filled; staff check the label and **count what arrived** (“don’t copy the label”). Fewer than sent: **the rest is still to come** (the order stays open) or **no more is coming** (closed short, with a reason).
- **Brought in** or **came back**: the person, a medicine on their chart, and who brought it.
- **Expired packs are refused**; within 7 days needs a reason.
- **Pack photo** (Q6): prompted when there’s no photo yet or the pack looks different; optional (“Skip the photo”).
- **Controlled** (Q9): the house lead receives it into the register, with a witness PIN and the balance before and after.

### 6. Pharmacy orders (Q4)

- **Order from the pharmacy:** medicine, quantity (suggested: 28 days of regular doses, or twice the reorder level), needed by, note; **Save as draft** or **Send to the pharmacy**.
- **The order record:** Draft → Sent → Dispensed → Received, with every step’s who and when. Received, closed-short and cancelled orders are read only.
- **What the pharmacy dispensed** (today’s DispenseDialog, moved from P04): batch, expiry, quantity, when, due to arrive.
- **Cancel** (draft or sent) and **Close short** (part received) take a reason and a destructive confirm; the reason stays on the order.

### 7. Counts (Q7)

- **Blind:** staff enter what they count, then see what was expected. One medicine or the whole house; controlled medicines are counted in Controlled checks.
- **Every difference needs a reason** (dropped or lost · found extra · given but not recorded · recorded but not given · last count was wrong · don’t know) and goes to the house lead as a follow-up, due by the end of the day.
- **Sign off:** accept (stock set to the count, as a count-correction movement) or recount first.
- **Scheduled counts** are an organisation setting, off by default.

### 8. Adjust or remove, and going out / coming back (Q7, Q10)

- **Adjust or remove** (house leads): expired — removed · damaged or dropped · returned to the pharmacy · found · count correction, for a chosen pack, with a destructive confirm for removals. Kept as a movement with its reason. No controlled permissions needed.
- **Going out / coming back** (staff who give medicines): how many, where (day programme, whānau, respite, hospital, another house), handed to or brought back by, and when. Coming back shows how many were **given while out**.

### 9. Alerts and settings (Q8)

- **Low stock:** under **7 days’ supply** for regular doses (enough until a course or respite ends counts as enough), or **at the reorder level** for as-needed doses.
- **Expiry:** per pack, at **30 and 7 days**.
- All three are P11 settings shown as **“Default — not yet reviewed”**. Alerts go to the house lead (P11 Delivery) and All Tasks › Stock. Discontinued medicines never alert; day boundaries are NZ time.

## Build notes (for the implementation plan)

1. **Lots (EM-10):** a `medication_stock_lots` table (batch nullable only with “not printed”, expiry, quantity, received, source, reference, received by/at, short-expiry reason); on hand = sum of open, unexpired lots; migrate each current stock row to one lot. **Nothing ever overwrites a lot’s batch or expiry** (AUDIT 2.3).
2. **Stock movements:** a `medication_stock_movements` table (kind, quantity, lot, reason, note, by, at) replacing the audit-log history; reasons never go in `stock.notes` (AUDIT 2.2, 3.4).
3. **Doses decrement the first-expiry lot** for staff-given doses; self-managed doses don’t; P01’s dose dialog shows “Use the pack expiring first” and the latest pack photo (AUDIT 3.1).
4. **Pharmacy orders:** the full lifecycle with part received, closed short and cancelled (reasons stored); received orders read only; delivered quantity bounded; dispensing moves from the prescriber order to the pharmacy order (AUDIT 3.2, 3.3). Receiving against an order closes it (AUDIT 3.9).
5. **Receiving:** expiry after today required (or “not printed”); within 7 days needs a reason; the scan requirement is reviewed so the dashboard path works (AUDIT 2.4, 3.8).
6. **Counts:** blind; differences with reasons create a P08a house-lead follow-up; the scheduled-count witness is kept; “0.00” is not a discrepancy (AUDIT 3.5; also fix-first). Receipts never set `last_counted_at` (AUDIT 3.7).
7. **Removals without controlled permissions** for ordinary medicines (AUDIT 3.6).
8. **Photos:** a `medication_stock_photos` record per lot on the private disk, concealed for controlled medicines without controlled view; history kept (AUDIT 5). **The pack-photo `FileDropzone` uses `accept="image/*"` with `capture="environment"`** (Main, 30 September): the page is desktop-first, but staff photographing a pack often open it on a phone or tablet at the cupboard, and this opens the camera directly.
9. **Alerts:** days of supply, reorder level for as-needed; P11 settings (7 days, 30/7 days, scheduled counts off) as “Default — not yet reviewed”; P11 Delivery routing; an All Tasks “Stock” provider; skip discontinued medicines; NZ day boundaries (AUDIT 4).
10. **Permissions:** add `medications.stock.receive` (grant migration) for roles that administer; grant `stock.update` to house leads (team_lead lacks it); review finance’s `stock.update` (AUDIT 7).
11. **Controlled:** the controlled receipt becomes a witnessed register entry with a lot; close the `updateStockItem` batch/expiry edit for controlled medicines (AUDIT 6.2).
12. **Pagination:** stock items, orders and movements page on the server — no 40 / 400 caps (AUDIT 8).

## Verification (30 September 2026)

- **`tools/verify.mjs`:** 144 captures: all 70 states at 1440, plus the 37 core states at 1280 and 200 %. Across all 144: overflow 0, console errors 0, every step completed, and no truncated meter captions or table cells. Details are in CHECKLIST §4 and `screenshots/report.json`.
- **Keyboard:** Enter opens Receive from Meds today › Stock alerts, Tab stays inside it, Escape closes the untouched receipt and returns focus to “Receive”, and the menu key opens the stock row menu.
- **`tsc` and ESLint:** clean for `src/` (ESLint: 16 files, 0 problems; no unused imports). The 2 `tsc` errors in shared files come from P01’s Inertia shim, as in the earlier packages.
- **Live reference:** not checked live; see AUDIT §9.

## Deviations (accepted by Main)

1. **Reference frames.** The shell chrome is reproduced (AppLayout needs Inertia). Meds today’s page top is P01’s, as P07a reproduced it, with 9:12 am reference numbers; its other views are link-only.
2. **Fixtures** follow P02/P03/P04’s people. Additions: packs, pharmacy orders and counts; Tama’s levetiracetam out at the day programme; Grace’s expired levothyroxine pack.
3. **`ConfirmDialog` is the real one.** Its destructive confirm renders purple in this preview; PR #15 has since merged, so it is red on main — this deviation no longer applies at build.
4. **Pack photos are placeholders** (“Synthetic pack photo”) — no real images in the preview.
5. **Expiry is entered as month/year** (as printed on NZ packs), not with the date-and-time field; the build stores the last day of that month.
6. **Days of supply counts regular doses only**; an as-needed medicine uses its reorder level (Q8). A course or a respite stay that ends before stock runs out isn’t “low”.

**Main’s inspection, 30 September:** v1 at `7c2144c28` passed (`sha256sum -c` 30 OK) with two fixes, made in this version:
1. **Receive › Packs:** “N fewer than the pharmacy sent — is the rest coming?” is asked only once every pack has a counted quantity, and it’s less than what was sent. Until then only the field’s own validation shows.
2. **The hub search** reads “Search medicines or batches” (it was truncated at 1440).

Main also accepted deviations 1–6 (month/year expiry, stored as the last day of the month, is right) and added the camera-capture build note (build note 8). EM-10 and the “0.00” count fix are fixed-first on main `21bfb4ce4` (AUDIT 2.3, 3.5).

## Approval

Approved by Main under Stephan’s delegation, for his final inspection. The exact version and the record are in [`APPROVAL.md`](APPROVAL.md). **v1 is frozen**; any change goes in `P06/v2/`.
