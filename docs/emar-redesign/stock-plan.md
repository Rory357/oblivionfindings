# eMAR Redesign — Page Plan: Stock Management (`/emar/stock`)

## 0. Identity
- **Route:** `GET /emar/stock` → `emar.stock` (`EmarController@stock` :1423).
- **Inertia page:** `resources/js/pages/emar/StockManagement.tsx` (rewrite).
- **Write endpoints — ALL EXIST (handoff: "no new endpoints needed"):** `emar.pharmacy_orders.store` (:2857), `.advance` (:2910, draft→submitted→confirmed→dispensed→delivered + auto stock-increment on delivered), `.update` (:2886); `emar.stock.receive` (:2985, scan-gate + offline-sync), `emar.stock.update` PATCH (:3080, reorder/expiry/batch/supplier), `emar.stock.adjust` (:3095, new_quantity + reason); `emar.controlled.balance_check.store` (:3428, expected vs actual + witness + auto-discrepancy).
- **Goal:** turn the card-stack + raw shadcn dialogs into a brand hero + 6-tab `TabStrip` register (client-grouped stock list, CD reconciliation, pharmacy-order lifecycle) with **all four workflows on `MedsWizardDialog`** (§3d), preserving the Receive offline-queue + scan-verify.

## Key findings (verify-against-code)
- **Receive offline path MUST be preserved:** `submitEmarMutation('/emar/stock/receive', {...,toMedicationScanPayload(capture)})` + conflict handling + `router.reload({only:[...]})` + `MedicationScanVerificationPanel` + `hasVerifiedMedicationScan` gate (`@/lib/medication-scan`, `@/lib/emar-offline`). Other modals can use plain Inertia `useForm`.
- **Count write path:** balance-check records a `balance_check` `ClientControlledDrugEntry` (expected `on_hand_before`, actual `on_hand_after`, witness ≠ recorder, auto-discrepancy). This is the CD system-of-record. **Non-CD routine counts post to `adjust`** (set on_hand + reason) — do NOT write CD-register entries for non-controlled meds.
- **`stock()` payload gaps:** no site/brand colour (§3b), no CD reconciliation, no cold-chain, orders returned as raw models.
- **Retire:** per-row `ScheduledStockCounts` standalone surface → folded into the Count modal. `/emar/controlled` stays the deep register; the new `controlled` tab is the operational summary linking into it.

## 1. Section + modal map (§1/§4)
| Block | Component | Source / endpoint |
|---|---|---|
| Hero (live eyebrow, stats Tracked/Low/Expiring/Orders, badges, actions, footer chips+search+site) | `PageHero` + `brandColour` | flat payload + counts + site colour |
| Tabs (all/low/expiring/expired/controlled/orders) | `TabStrip` | client-side facets |
| Stock list (client-grouped cards + table) | inline | `stockItems[]` |
| Controlled reconciliation | inline | `controlledRegister[]` (NEW payload) |
| Pharmacy-order lifecycle (5-stage tracker) | inline | `pharmacyOrders[]` (flat) |
| New pharmacy order (4-step) | **BUILD** `NewPharmacyOrderDialog` on MedsWizardDialog | `pharmacy_orders.store` |
| Receive stock (4-step, scan-gated, offline) | **BUILD** `ReceiveStockDialog` (reuse `MedicationScanVerificationPanel` + `submitEmarMutation`) | `stock.receive` |
| Stock count (3-step, CD-aware) | **BUILD** `StockCountDialog` | `controlled.balance_check.store` (CD) / `stock.adjust` (non-CD) |
| Adjust stock (2-step: details + quantity) | **BUILD** `AdjustStockDialog` | `stock.update` (PATCH details) + `stock.adjust` (quantity) |
| Advance order (next-action) | inline action | `pharmacy_orders.advance` |

## 2. Hero spec
Eyebrow live-ping `LIVE STOCK BOARD · live`; title "Kia ora — your medication stock for {site underlined}"; description derived (N tracked, low, expiring, CD discrepancies); stats **Tracked · Low · Expiring · Orders** (low/expiring amber); badges tone-coded (expired/expiring/below-reorder/CD-discrepancy); actions **New pharmacy order** (primary) + **Receive stock** (outline); footer = chip filter (All / Controlled / Cold chain) + **Run stock count** + search + site `EntityFilter`. Brand colour from `?site_id`.

## 3. Backend (§5)
| # | Gap | Action | Test |
|---|---|---|---|
| brand | no site/colour (§3b) | `stock()`: add `site_id`/`site_name` per item, `?site_id` filter, `sites`, `active_site`, `site_brand_colour` | feature: brand colour + payload |
| cold-chain | no storage field | **migration** `storage_condition` (string, default `ambient`) on `client_medication_stocks`; model fillable; `updateStockItem` validation `in:ambient,fridge,controlled_room`; payload field | feature: adjust sets storage_condition |
| CD recon | not on payload | `controlledRegister[]`: per controlled stock item → last balance_check (register balance, time, witness) + open discrepancy | feature: controlled register present |
| orders | raw models | map `pharmacyOrders` → flat (status + stage timestamps + names + qty + eta) | — |
- **Reuse, don't duplicate:** all write paths already exist — wire the modals to them. Stock-count for CD uses balance-check (witness + variance→discrepancy already enforced).

## 4. Cross-module (§6)
- `controlled` tab "Investigate"/"Record CD balance check" link into `/emar/controlled` (Page 6 register). Order lifecycle shares `MedicationPharmacyOrder`. Receive scan-verify shares `MedicationScanVerificationPanel` with meds/today. Sidebar "Stock" → `/emar/stock` (unchanged).

## 5. Retire → redirect
- No standalone GET page to retire (single route). Per-row `<ScheduledStockCounts>` popover surface retired → Count modal. No route changes.

## 6. Execution checklist
- [ ] Backend: migration (storage_condition); model fillable; `stock()` rebuild (site/brand colour + flat orders + controlledRegister + storage_condition + filter); `updateStockItem` storage_condition. Tests.
- [ ] Frontend: `_stock-dialogs.tsx` (4 MedsWizardDialog modals, Receive preserves offline+scan); `StockManagement.tsx` rewrite (hero + 6-tab + client-grouped list + CD recon + order lifecycle).
- [ ] §9 gate; commit; tick PROGRESS.

## 7. Notes / deferrals (backlog)
- **Days-of-supply / avg consumption** (no stored rate): show on-hand vs reorder ratio bar (real), NOT an invented "~Nd left". Deferred until consumption history is modelled.
- **True FEFO multi-batch lots** (one batch/expiry per row today): keep single-batch; FEFO tag shown when the row's own expiry ≤30d. Batch-lot modelling deferred.
- **Waste/return-to-pharmacy on expired lines:** destructions live on Page 7; a cross-link is enough — dedicated return action deferred.
- §3d HARD RULE: MedsWizardDialog (handoff said add-client shell — overridden). Reuse Pages 1–7 patterns + the Page-7 dialog-generalization style (CD-aware count).
