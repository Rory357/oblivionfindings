# Stock Management (`/emar/stock`) — Gap Analysis & Parity Checklist

Single source of truth for the `/emar/stock` feature-completeness `/loop`. Bring the
Stock board to the same row-level interaction parity as `/emar/prn` and
`/emar/medications` (clickable rows, detail modal, right-click menu, client jump,
client filter, stacked alert strip) — **reusing** shared idioms, never hand-rolling.

Scope: ONLY `/emar/stock`, its 6 tabs, and its modals. Do NOT regress the working
wizards (New pharmacy order · Receive stock · Stock count / CD balance check · Adjust
stock — all on `MedsWizardDialog`).

Files:
- Page: `resources/js/pages/emar/StockManagement.tsx`
- Dialogs: `resources/js/pages/emar/_stock-dialogs.tsx`
- Detail modal: `resources/js/components/emar/stock-detail-dialog.tsx` (NEW — mirrors `prn-detail-dialog.tsx`)
- Controller: `app/Http/Controllers/Emar/EmarController.php` → `stock()` (~:1662)
- Test: `tests/Feature/Emar/StockManagementTest.php`

Reused idioms (copy, don't invent): `ShiftContextMenu`/`ShiftCtxItem`/`ShiftCtxState`
(`@/components/rostering/shift-context-menu`, template = PRN `openRowCtx`);
detail-modal + Options bar (`components/emar/prn-detail-dialog.tsx` → `WizardShell`);
`EntityFilter` onDark (`@/components/rostering`); client jump
`router.visit('/operations/clients/${id}/care')`; alert strip mirrors `/emar/controlled`;
search pill from PRN footer; `MedsWizardDialog`/`SummaryRow` (`@/components/meds/wizard-shell`).

---

## A. Stock detail / "view" modal + clickable rows
- [x] A1 — `StockDetailDialog` (`components/emar/stock-detail-dialog.tsx`, on `WizardShell`): Overview (resident name/room/site, medication + CD/cold-chain flags + dose + storage, on-hand vs reorder w/ ratio bar + status pill, batch/expiry/supplier) + Activity (last count, open pharmacy order panel, recent movements list w/ +/− deltas). Footer Options bar: Adjust stock · Run count · Order more · Client · MAR.
- [x] A2 — `StockRowView` rows clickable (cursor-pointer + hover + `role=button` + `tabIndex` + Enter/Space) → detail modal. Inline Count/Adjust kept, `stopPropagation` on each button. (StockManagement.tsx)

## B. Right-click context menu on every row
- [x] B3 — `onContextMenu` → `ShiftContextMenu` on every stock row (`openStockCtx`) AND every CD-register row (`openCdCtx`). Stock items: View details (primary) · Adjust stock · Run count/CD balance check · Order more · Receive against order (only when an open order matches) · sep · View client · Open on MAR · sep · Mark expired/quarantine (critical, only when expired). CD items: View details · Record CD balance check · Adjust · Order more · sep · View client · Open CD register · Investigate (critical, when unreconciled). Header tag = stock-state pill (OK/Low/Expiring/Expired or CD OK/CD discrepancy) via CSS-var tokens; meta = client · medication · on-hand. (StockManagement.tsx)

## C. "View client" jump
- [x] C4 — Client group header is now a button → `/operations/clients/{id}/care`; View client wired in both context menus and the detail modal footer. Off-page nav only for View client / Open on MAR; all stock actions open modals in place. (StockManagement.tsx)

## D. Client filter in the footer
- [ ] D5 — `Client` `EntityFilter` (allLabel="All clients", onDark) in the hero footer next to Site, built from payload clients; wire to `client_id` on `stock()` via `router.get('/emar/stock', …, { preserveState, preserveScroll })`. Search/chip/tab stay client-side.
- [x] D6 — Day-picker: intentionally NOT added (Stock is a live board; daily stepper is the wrong metaphor). Adding Client completes footer layout parity. Optional later: as-at date view — TODO(G-asat), not built now.

## E. Stacked alert strip
- [ ] E7 — Replace single CD banner with a stacked, dismissible alert strip (mirror `/emar/controlled`) from already-computed data: CD counts unreconciled (critical → Controlled tab), N expired — quarantine (critical → Expired tab), N low — reorder (warning → Low tab), N pharmacy orders overdue (warning → Orders tab). Each: icon + count + one-line message + "Review" → sets `activeTab`.

## F. Polish for parity
- [ ] F8a — Align hand-rolled footer search to the shared PRN/meds-today pill (clear-✕ affordance + matching classes).
- [ ] F8b — Replace grey empty state with the standard pattern: icon + message + CTA (Receive stock / New order). Semantic tokens only.

## Backend (`stock()` — minimal; NO speculative migrations)
- [x] BE-client — `stock()` now accepts `client_id` (filters stock/orders/active-meds by `medication.client_id`); `site_id` retained. (EmarController@stock)
- [x] BE-detail — each stock item carries `client_room`, `mar_url` (`EmarUrl::mar`), and recent `movements[]` derived honestly from `AuditLog` on the stock model (one grouped query, no new table — `formatStockMovement`). Order payload gained `medication_id` so open-order linkage is matched client-side. `last_counted_at` ISO-serialised. (EmarController@stock + StockManagementTest still green.)

## Verify each pass (§5)
`npm run types` + `npm run lint` clean for touched files; `npm run build` succeeds.
Compare against `docs/emar-redesign/stock-plan.md` + cross-module standard. Exercise:
row click → detail modal; right-click menu on stock + CD rows; View client lands on care
page; Client filter filters board; alert strip jumps to right tab; adjust/count/order/receive
open in-page + partial-reload (no full nav). Run `tests/Feature/Emar/StockManagementTest.php`.

## Loop exit (§6)
Every box `[x]`; types/lint/build pass; every stock + CD row has row-click detail modal +
right-click menu with View client; footer has Client filter beside Site; stacked alert strip
present; all stock actions in-page via Inertia partial reloads.

### TODO(Gx) deferred (no new tables)
- TODO(G-asat): optional "as-at date" inventory view (live board has no date param today).
- Days-of-supply / avg consumption: no stored rate — show on-hand vs reorder ratio bar only (per stock-plan §7).
