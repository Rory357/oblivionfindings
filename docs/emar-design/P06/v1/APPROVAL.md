# eMAR P06 v1 — approval record

## What was approved

- **Package:** P06 “Stock & pharmacy”, version **v1**.
- **Exact version:** commit `871f06c3a` on branch `claude/emar-p06`.
  - The approved files are the 30 files listed in [`VERSION.txt`](VERSION.txt).
  - VERSION.txt itself has SHA-256 `68cb99cf39b878a12878a0bab5241c25d559f1778ee9c793fed05774331fe4f9`.
  - This file sits beside them and is not part of the approved design.
- **Approved by:** Main (the review session, “Codex eMAR audit re-review”), **under Stephan’s delegation, for Stephan’s final inspection**, on 30 September 2026 (NZDT). The delegation is recorded in `docs/emar-audit-2026-09-28/claude-second-review/Approval-record.md`, section “DELEGATION”.
- **Before approval:**
  - Main inspected `7c2144c28`: `sha256sum -c` 30 OK. It checked the hub (`01` — meters, and Controlled only with controlled view), the receive packs step (`31` — the label check and “count them, don’t copy the label”) and the photo prompt (`33` — optional, stored privately, earlier photos kept).
  - It passed with two fixes, made in `871f06c3a`:
    1. “N fewer than the pharmacy sent — is the rest coming?” is asked only once a counted quantity is entered and it’s less than what was sent.
    2. The hub search placeholder is “Search medicines or batches” (it was truncated at 1440).
  - The version was rebuilt, and the harness gave 144 captures with 0 problems.

**v1 is frozen.** Any further change goes in `P06/v2/` and needs its own approval.

## Main’s decisions under delegation (30 September)

**EM-10 (before P06):** move to **lot-level stock** — a lots table with batch, expiry, quantity and source per lot — for first-expiry-first-out guidance, per-lot expiry warnings, and no more overwriting batch or expiry from a blank pharmacy delivery. The migration is approved as part of the P06 build.

| # | Question | Answer |
|---|---|---|
| Q1 | Pages | **The Stock & controlled drugs hub** (P02 hub pattern), **plus Meds today › Stock alerts** for frontline staff. |
| Q2 | A pack (lot) | **Created at each receipt.** Batch and expiry required, with an explicit “Not printed on the pack”. Expired stock can’t be received; within 7 days of expiry needs a reason. On hand is the sum of open lots. |
| Q3 | Doses use stock | **Doses come off the lot expiring first, automatically.** P01 shows “Use the pack expiring first” when lots differ. Self-managed doses don’t decrement. |
| Q4 | Pharmacy orders | **One supply record per pharmacy order**, through the full lifecycle. A received order is read only. Dispensing moves here from P04. |
| Q5 | Who | **New key `medications.stock.receive`** (grant migration) for staff who give medicines. `stock.update` to house leads. Finance’s `stock.update` flagged for review at build. |
| Q6 | Pack photo | **Prompted, not required, per lot.** Stored privately; controlled medicines’ photos concealed; history kept. |
| Q7 | Counts and movements | **Blind counts; reasoned movements on a new stock-movements record.** Ordinary removals no longer need `controlled.record`. Scheduled counts an organisation setting, off by default. |
| Q8 | Alerts | **Days of supply for regular doses; reorder level for as-needed.** P11 defaults “not yet reviewed”. Discontinued medicines never alert; NZ day boundaries. |
| Q9 | Controlled receipt | **P06 receives controlled deliveries as a register entry** (house lead, witness PIN, balance before and after, lot). Everything else controlled stays in P07. The controlled batch/expiry edit leak closes. |
| Q10 | Going out / coming back | **Ordinary movements live in P06**, without a witness. |

Main also accepted deviations 1–6 (below).

## Build notes

Verified on origin/main `ada567669` (AUDIT.md).

1. **Lots (EM-10):** a `medication_stock_lots` table (batch nullable only with “not printed”, expiry, quantity, received, source, reference, received by/at, short-expiry reason); on hand = sum of open, unexpired lots; migrate each current stock row to one lot. Nothing ever overwrites a lot’s batch or expiry (AUDIT 2.3).
2. **Stock movements:** a `medication_stock_movements` table (kind, quantity, lot, reason, note, by, at) replacing the audit-log history; reasons never go in `stock.notes` (AUDIT 2.2, 3.4).
3. **Doses decrement the first-expiry lot** for staff-given doses; self-managed doses don’t; P01’s dose dialog shows “Use the pack expiring first” and the latest pack photo (AUDIT 3.1).
4. **Pharmacy orders:** the full lifecycle with part received, closed short and cancelled (reasons stored); received orders read only; delivered quantity bounded; dispensing moves from the prescriber order to the pharmacy order; receiving against an order closes it (AUDIT 3.2, 3.3, 3.9).
5. **Receiving:** expiry after today required (or “not printed”); within 7 days needs a reason; the counted quantity is never pre-filled; the scan requirement is reviewed so the dashboard path works (AUDIT 2.4, 3.8).
6. **Counts:** blind; differences with reasons create a P08a house-lead follow-up; the scheduled-count witness is kept; “0.00” is not a discrepancy; receipts never set `last_counted_at` (AUDIT 3.5, 3.7).
7. **Removals without controlled permissions** for ordinary medicines (AUDIT 3.6).
8. **Photos:** a `medication_stock_photos` record per lot on the private disk, concealed for controlled medicines without controlled view; history kept (AUDIT 5). **The pack-photo `FileDropzone` uses `accept="image/*"` with `capture="environment"`**, so the camera opens directly on a phone or tablet at the cupboard (Main).
9. **Alerts:** days of supply; reorder level for as-needed; P11 settings (7 days, 30/7 days, scheduled counts off) as “Default — not yet reviewed”; P11 Delivery routing; an All Tasks “Stock” provider; skip discontinued medicines; NZ day boundaries (AUDIT 4).
10. **Permissions:** add `medications.stock.receive` (grant migration) for roles that administer; grant `stock.update` to house leads; review finance’s `stock.update` (AUDIT 7).
11. **Controlled:** the controlled receipt becomes a witnessed register entry with a lot; close the `updateStockItem` batch/expiry edit for controlled medicines (AUDIT 6.2).
12. **Pagination:** stock items, orders and movements page on the server — no 40 / 400 caps (AUDIT 8).

**Fixed-first on main `21bfb4ce4` (Main, 30 September):** EM-10 — deliveries never wipe batch or expiry, and the earliest expiry on hand is kept — and the “0.00” count fix. AUDIT 2.3 and 3.5 say so. The lots design above still replaces the single stock row at build.

## Deviations accepted

1. The shell chrome is reproduced; Meds today’s page top is P01’s (via P07a) with 9:12 am reference numbers.
2. Fixtures follow P02–P04’s people, with packs, orders and counts added.
3. The real `ConfirmDialog` is used. It renders purple in this preview; PR #15 has merged, so it is red on main — no longer a deviation at build.
4. Pack photos are placeholders in the preview.
5. Expiry is entered as month/year, as printed; the build stores the last day of the month.
6. Days of supply counts regular doses only; as-needed uses the reorder level. A course or respite stay that ends before stock runs out isn’t “low”.
