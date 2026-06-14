# eMAR Redesign — Page Plan: Handovers (meds) (`/emar/handovers`)

## 0. Identity
- **Route:** `GET /emar/handovers` → `emar.handovers` (`EmarController@handovers`). The **medication-focused lens** on the shared `ShiftHandover` workflow (same model + `ShiftHandoverService` as `/operations/handovers`, already redesigned — see [[project_shift_handovers_redesign]]).
- **Inertia page:** `resources/js/pages/emar/Handovers.tsx` (rewrite).
- **Write endpoints — ALL EXIST + accept the wizard contract:** `emar.handovers.store` (:3157, takes `shift_id`/`*_text`/`submit` — identical to Operations, same `handoverService->save()`), `.update` (:3990), `.submit` (:3208, POST), `.acknowledge` (:3225, POST), `.destroy` (:4033).
- **Goal:** flat list → the same shift-focused workspace as Operations handovers, **reusing the shared components** (CardsView / HandoverRail / HandoverDetailDialog / HandoverWizard / AddClientDialog), with a brand `PageHero`, a medication `TabStrip`, and a **MAR-bound meds picker** (medications-due selected from the client's active orders, not free text).

## Key findings (verify-against-code)
- **Reuse, do NOT fork.** The Operations `HandoverController` builds its payload via `App\Services\Operations\HandoverPresenter` (`mapEagerLoads()`/`mapHandover()`/`catalogue()`) → shapes that the shared `Handover`/`Catalogue` TS types expect. The current eMAR `handovers()` ships a *different* shape → **reshape eMAR to use the same presenter** so the shared components consume it.
- **`HandoverDetailDialog` has no router calls** — all actions delegate to parent callbacks (`onEdit`/`onSubmit`/`onAcknowledge`), so the eMAR page wires them to `/emar/handovers/*`. **No edit to the detail dialog.**
- **`HandoverWizard` posts to hardcoded `/operations/handovers`** + meds are free-text `string[]`. → two **additive** props: `basePath` (default `/operations/handovers`) + `medicationFocus`; `CatalogueClient` gains optional `medications[]`. Operations behaviour preserved by defaults.

## 1. Section + modal map (§1/§4)
| Block | Component | Source |
|---|---|---|
| Hero (live eyebrow, week-range title, stats Total/Submitted/Ack'd/Open, badges, week nav + search + site) | `PageHero` + `brandColour` (NEW eMAR hero) | counts + colour |
| Tabs (all/draft/submitted/acknowledged/needs_ack/open_incoming/activity) | `TabStrip` | client-side facets |
| Cards / rail | **REUSE** `CardsView` + `HandoverRail` | `handovers[]` |
| Activity feed | inline (derived) | handovers |
| New/Edit handover (4-step, MAR meds) | **REUSE** `HandoverWizard` (+`basePath`/`medicationFocus`) | `emar.handovers.store/update` |
| Detail (view + act) | **REUSE** `HandoverDetailDialog` | callbacks → `emar.handovers.submit/acknowledge` |
| Add client (inline) | **REUSE** `AddClientDialog` | clients.store |

## 2. Hero spec
Eyebrow live-ping `LIVE HANDOVERS · synced`; title "This week's medication handovers — {week range underlined}"; description (N handovers, M awaiting your ack, K open incoming); stats **Total · Submitted · Ack'd · Open**; badges awaiting-sign-off/open-incoming/incidents; footer week navigator + search + site `EntityFilter`. Brand colour from `?site_id`.

## 3. Backend (§5)
| # | Gap | Action | Test |
|---|---|---|---|
| reshape | payload mismatch | `handovers()`: use `HandoverPresenter` (mapEagerLoads/mapHandover/catalogue) → shared contract; mirror Operations week scoping (tz-aware `week` window) | feature: payload + weekStart |
| brand | parity | `?site_id` → `site_brand_colour` + `active_site` + `sites` | feature: brand colour |
| MAR meds | free-text only | enrich `catalogue.clients[]` with `medications` (active `ClientMedication` per client) | feature: catalogue has meds |
| writes | none | endpoints already accept wizard contract — no change | — |

## 4. Cross-module (§6)
- Same `ShiftHandover` records as `/operations/handovers` (shared service) — a handover created here shows there and vice-versa. The eMAR page just posts to `emar.handovers.*` (same `handoverService->save()`). [[project_shift_handovers_redesign]] is actively maintained → **shared-component edits strictly additive; run its tests.**

## 5. Retire → fold into modals
- Old eMAR inline handover dialog → the reused wizard + detail modal. No routes removed.

## 6. Execution checklist
- [ ] Backend: `handovers()` rewrite (presenter + week scope + brand colour + clients-with-meds). Test.
- [ ] Shared (additive): `shared.tsx` `CatalogueClient.medications?`; `handover-wizard.tsx` `basePath`/`medicationFocus` + MAR meds picker.
- [ ] Frontend: `emar/Handovers.tsx` (brand hero + 7-tab TabStrip + reused CardsView/Rail/Detail/Wizard/AddClient + activity feed; callbacks → emar endpoints).
- [ ] §9 gate (incl. **Operations handover tests** for no regression); commit; tick PROGRESS.

## 7. Notes / deferrals (backlog)
- §3d HARD RULE: the workflow modal is the **existing shared `HandoverWizard`** (Add-Client surface) — reuse-first (§4) trumps rebuilding it on MedsWizardDialog; rebuilding would fork an actively-maintained shared wizard + duplicate the write path. Flagged.
- **Structured meds refs** `{medication_id,dose,due_at}` (handoff gap 4): the MAR picker feeds medication **names** into the same `medications: string[]` storage (no backend storage change) — promoting to structured refs is a backlog refinement.
- **Deferred:** server-side search/staff/client params (filtered client-side over the week payload), Board/List view toggle (cards + rail only — the eMAR lens), retiring `operations/handovers/Show.tsx` + control-room/respite handover surfaces (cross-module — owned by [[project_shift_handovers_redesign]]). Reasons: separable / cross-module / refinement. Core = brand workspace + MAR-bound wizard + reused cards/rail/detail + medication TabStrip.
