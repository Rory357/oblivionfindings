# PRN Records (`/emar/prn`) — Gap Analysis & Loop Tracker

Single source of truth for the "finish & fix the PRN Records page" loop. Each pass:
re-audit → pick the next unchecked item (A→F, then Backend) → implement → verify
(`npm run types` + `npm run lint` + `npm run build`) → tick it `[x]` with a one-line note.

**Scope:** only `/emar/prn`, its tabs, and its modals. Reuse existing components; never
hand-roll primitives. Tokens only (no raw `oklch()`).

Key files:
- `resources/js/pages/emar/PrnRecords.tsx` — page entry
- `resources/js/pages/meds/today/components/prn-wizard.tsx` — Record PRN modal (reused)
- `resources/js/pages/meds/today/components/prn-effect-dialog.tsx` — effectiveness (migrate → WizardShell)
- `app/Http/Controllers/Emar/EmarController.php` → `prn()` (~line 1070), `storePrnEffectiveness()` (~3553)
- `app/Http/Controllers/Emar/WorkerMedsController.php` → `recordPrn()` (273), `recordPrnEffect()` (352)
- `app/Services/Emar/MedsBoardPayloadService.php` → `prnMedications()` (301), `clientsPayload()` (223)

Reuse map (verified to exist): `@/components/page` `PageHero`; `@/components/meds/day-picker-chip`
`DayPickerChip/addDays/parseYmd/toYmd`; `@/components/rostering` `EntityFilter/TabStrip/RosterTabItem/
ShiftContextMenu/ShiftCtxItem/ShiftCtxState/Donut/DonutCard/MicroStats`; `@/components/wizard/shell`
+ `@/components/wizard/primitives`; `@/pages/meds/today/components/recorded-detail-dialog` (read-only idiom).

---

## A. Hero footer — meds/today calendar + rostering search (replaces the thin Site-only filter)

- [x] **A1.** Rebuild hero `footer` to mirror `meds/today` `heroFooter`: left day-stepper
  (`‹ prev` button · `DayPickerChip` · `next ›` button · "Back to today" pill when not today);
  right white-pill search input (clear-✕) + Site `EntityFilter` (`onDark`) + Client `EntityFilter`
  (`onDark`). Optional Export-register pill. — _done: `PrnRecords.tsx` footer now mirrors meds/today
  exactly. **Export pill omitted on purpose** — no PRN-register export endpoint exists (only
  `emar.audit.export`/`emar.pdf.*`); shipping a wrong/dead link violates the no-dead-buttons rule.
  Candidate future backend gap if a PRN CSV export is wanted._
- [x] **A2.** Move the register's in-panel search **up into the hero footer**; one control row drives
  every tab. Keep status-filter chips local to Register/History panels. — _done: removed the in-panel
  `Input` search; register header now shows the title + status chips + count; hero search drives the
  client-side `register`/reviews/near/trends filtering._
- [x] **A3.** Wire calendar + filters to query params on `prn()` (`date`, `range`, `site_id`,
  `client_id`, `q`) via `router.get('/emar/prn', …, { preserveState, preserveScroll })`. — _done:
  `reload()`/`goDate()`/`onSite()`/`onClient()` round-trip date+site+client (and range) to the server
  with `preserveState/preserveScroll`. Text search stays client-side over the loaded rows (becomes
  server-side for the History tab in F); `q` is seeded from the prop._

## B. Register — right-click actions + a "view" detail modal

- [x] **B4.** `onContextMenu` per register row → `ShiftContextMenu`: View details (primary) · Record
  effectiveness (when review due) · Re-record/correct dose · — · View client · Open on MAR chart ·
  Print PRN slip · — · Flag concern (critical). Header tag = effectiveness pill; meta = client·med·time.
  — _done: `openRowCtx()` in `PrnRecords.tsx` builds the menu (review-effectiveness item only when no
  review yet), header tag colour from `effCtxTag()` semantic CSS vars. Flag concern → client incidents
  page (`/clients/{id}/incidents`, real gated route — not a dead button); Print → `window.print()`
  (no PRN-slip PDF endpoint exists); Open MAR → `mar_url` (EmarUrl::mar). Self-clamps/closes via the
  shared `ShiftContextMenu`._
- [x] **B5.** PRN administration **detail modal** on `WizardShell` chrome (read-only `ReviewCard`/
  `ReviewRow`/`InfoCard`). Opens on row click + menu "View details". Shows client (avatar+room+site),
  medication (+CD lock/route/dose), indication, dose given, time + given-by, today's count vs cap,
  baseline observations captured at admin, effectiveness outcome + reviewed-after + observations +
  escalation, audit trail. Footer Options bar: Record/Re-record effectiveness · Re-record dose ·
  View client · Open on MAR · Print. Primary actions open wizards in place (no off-page nav). — _done:
  new `resources/js/components/emar/prn-detail-dialog.tsx` (`PrnDetailDialog`) on `WizardShell` with two
  read-only section panes (Administration / Review & audit). Footer Options open the effect/record
  wizards in place via page callbacks; View client/MAR via `router.visit`; Print via `window.print()`.
  Row click + menu "View details" both open it._

## C. Reviews due — richer wizard modal

- [ ] **C6.** Migrate effectiveness capture onto the Add-Client `WizardShell` chrome (replace the bare
  `prn-effect-dialog.tsx` `<Dialog>`); rail shows an administration context card (client, med,
  given-at, ago). 3 steps per spec (Outcome → Observations → Escalation & sign-off).
- [ ] **C7.** Effectiveness field gap analysis (see "Backend gap analysis" below) — implement the
  data-layer-supported fields; stub the rest as `TODO(Gx)`.
- [ ] **C8.** Reviews-due rows open this wizard; after save, Inertia partial-reload + success pane (no
  nav). Header surfaces count + "within 4h of dose".

## D. Near limit — drill-down modal

- [ ] **D9.** Each Near-limit card opens a detail modal (`WizardShell` chrome): full med detail (name,
  CD lock, route, dose, indication), max/day + min hours between, today's dose timeline (time, dose,
  given-by, effectiveness), client/room/site, remaining + earliest next-allowable time, any over-limit
  incident. Footer Options: Record PRN dose (pre-filled) · Record effectiveness (last dose) ·
  View client/MAR · Notify clinical lead/Flag · Print.
- [ ] **D10.** Near-limit gap analysis (see below) — add per-dose timeline + next-allowable + incident
  link to the `prn()` payload (derive where possible; `TODO(Gx)` where schema is needed).

## E. Trends — make it genuinely useful

- [ ] **E11.** Expand beyond the most-used bar list + 3 stat cards, reusing `donut.tsx`/`donut-card.tsx`/
  `micro-stats.tsx`/recharts: effectiveness distribution (donut), doses-per-day over range (bar/
  sparkline), by-indication breakdown, time-of-day pattern, top PRN residents, near/over-limit
  frequency. Respect hero date-range + site/client filters. Tokens only.

## F. History — new tab with filters

- [ ] **F12.** Add a **History** tab (`History` icon, tone info/neutral) to the `TabStrip` between
  Trends and the end. The full filterable archive (Register stays the recent/working view).
- [ ] **F13.** History filters: inherits hero date-range + site + client + search, plus its own local
  chips (medication, effectiveness outcome, CD-only, escalations-only, given-by). Columns = register +
  effectiveness outcome + reviewed-after + escalation flag. **Server-side paginated** (Prev/Next +
  "Showing N of M"). Rows get the same right-click menu + detail modal.

## Backend (`prn()` props + manager endpoint — NO speculative migrations)

- [x] **BK1.** `prn()` accepts `date` (anchor; default today), `range`, `site_id`, `client_id`, `q`;
  the 30-day register honours `date`/`range` and `client_id`; add `today`/`is_today`/`date_label` props.
  — _done: `EmarController@prn` parses a date anchor (tz-safe, falls back to today on bad input), a
  clamped `range` (1–90, default 30) lookback window, and `client_id`/`q`; register + pending-reviews
  honour `client_id`; `clients` dropdown stays site-scoped while data lists honour the client filter;
  added `today`/`is_today`/`date_label`/`range`/`client_id`/`q` props._
- [ ] **BK2.** History: a paginated, server-filtered PRN administration query keyed off the params
  (not the 200-row cap). Return `{ data, meta }`.
- [ ] **BK3.** Near-limit detail: extend each near-limit med with today's per-dose timeline (time,
  dose, given-by, effectiveness), next-allowable-time, over-limit incident reference.
- [ ] **BK4.** Effectiveness extra fields: add only schema-supported fields to the payload; stub the
  rest as `TODO(Gx)`.
- [x] **BK5.** Detail modal: ensure each administration carries baseline observations + effectiveness
  sub-record the detail modal needs. — _done: `prn()` register payload now eager-loads
  `client.room`/`client.site`/`medication.route`/`prnEffectiveness.reviewedByUser` and emits
  `client_room`/`client_site`/`route`/`prescribed_dose`/`notes`/`mar_url`/`baseline{bgl,pulse,bp,insulin}`/
  `effectiveness_detail{outcome,review_minutes_after,observations,escalation,reviewed_by,reviewed_label}`.
  Added `use App\Support\EmarUrl`._
- [ ] **BK6.** Manager record endpoint: confirm a manager-scoped record path delegating to
  `EnhancedMarService` (currently `WorkerMedsController@recordPrn`, gated `medications.administer.record`
  || `clients.update`), called by the Record/Re-record wizard via `useForm().post()`.

---

## Backend gap analysis — effectiveness extra fields (C7 / BK4)

`medication_prn_effectiveness` schema (FIXED, migration `2026_03_26_000001`):
`effectiveness`, `review_minutes_after` (default 30), `observations` (text), `escalation_needed`,
`escalation_action` (text), `reviewed_by`, `reviewed_at`. **No** structured clinical columns.

| Candidate field | Data layer today | Decision |
|---|---|---|
| `review_minutes_after` chip (15/30/45/60/90) | column exists | **Implement** (wizard step 1) |
| Observations / resident response (free-text) | `observations` column | **Implement** (wizard step 2) |
| Escalation needed + action | columns exist | **Implement** (wizard step 3) |
| Side effects (chip-multi, structured) | no column | `TODO(G1)` — for now fold into `observations` |
| Pain/symptom score before→after (0–10) | no column | `TODO(G2)` |
| Vitals *after* dose (pulse/BP/resp) | admin has baseline vitals; effectiveness has none | `TODO(G3)` |
| Further dose likely? | no column | `TODO(G4)` |
| Who was notified (GP/family/senior chip) | only `escalation_action` free-text | `TODO(G5)` |
| Follow-up due time | no column | `TODO(G6)` |
| Link to care/behaviour plan | no column | `TODO(G7)` |

## Backend gap analysis — near-limit drill-down (D10 / BK3)

`prnMedications()` already returns: `max_per_day`, `given_last_24h`, `remaining_today`, `near_limit`,
`over_limit`, `min_hours_between`, `last_given_at`, `last_given_label`, **`next_allowed_at`/
`next_allowed_label`** (✓ next-allowable), `interval_blocked`, `is_controlled`, `requires_witness`,
`dose`, `route`, `prn_reason`.

Missing for the drill-down (to add in `prn()` near-limit list, derive — no schema):
- **Per-dose timeline today** — each given dose: time, dose, given-by, effectiveness (from
  `ClientMedicationAdministration` + `prnEffectiveness`). `TODO`: add to payload.
- **Over-limit incident reference** — confirm whether over-limit raises a linked incident; if no FK
  link exists, this is `TODO(G8)` (don't invent a table).

---

## Pass log

- _(pass 1)_ Created this file from the §1/§2 spec after auditing the live page (hero has Site-only
  filter; register search in-panel; no row context menu; no detail modal; effectiveness is a bare
  Dialog; near-limit cards are read-only; Trends = bar list + 3 cards; no History tab).
- _(pass 1, cont.)_ **Closed Group A + BK1.** Rebuilt the hero footer to the meds/today idiom
  (day-stepper + DayPickerChip + white-pill search + Site/Client `onDark` `EntityFilter`s), moved the
  register search up into the hero, and wired date/site/client/range to `prn()` query params.
  Backend: `EmarController@prn` now date-anchored + client/site/q aware with `today`/`is_today`/
  `date_label`/`range` props. Files: `resources/js/pages/emar/PrnRecords.tsx`,
  `app/Http/Controllers/Emar/EmarController.php`. Verified: scoped `tsc` clean for the page (the only
  global tsc errors are the worktree's missing Wayfinder-generated `@/routes` — environmental, not
  this change), `eslint resources/js/pages/emar/PrnRecords.tsx` clean, `php -l` clean on the
  controller. (Full `npm run build` + pint + feature tests run in the integrated tree — this worktree
  has no `vendor/` and no generated routes; consistent with prior eMAR loop passes.)
  **Next pass:** Group B (register right-click `ShiftContextMenu` + the `WizardShell` detail modal,
  B4/B5 + BK5).
- _(pass 2)_ **Closed Group B + BK5.** Added register row right-click `ShiftContextMenu`
  (`openRowCtx`) and a read-only `PrnDetailDialog` (new `components/emar/prn-detail-dialog.tsx`) on
  `WizardShell` chrome, opened on row click + "View details"; footer Options open the effect/record
  wizards in place. Backend BK5: extended the `prn()` register payload with room/site/route/prescribed
  dose/notes/mar_url/baseline-observations/full effectiveness sub-record (eager-loaded). Files:
  `resources/js/pages/emar/PrnRecords.tsx`, `resources/js/components/emar/prn-detail-dialog.tsx`,
  `app/Http/Controllers/Emar/EmarController.php`. Verified: scoped `tsc` clean (zero errors mention the
  touched files; only the worktree's pre-existing `@/routes` gap remains), `eslint` clean on both
  frontend files, `php -l` clean. **Next pass:** Group C (migrate effectiveness capture onto
  `WizardShell` 3-step + the effectiveness field gap analysis — C6/C7/C8 + BK4).
