# Good catches — everything the refresh must not miss (19 Oct 2026)

Stephan caught that the approved date and time pickers were missing from the
first mockups. This file lists every other catch of that kind, from three
sweeps: every rule in Rory's guides (`DESIGN.md` + `design_styles/`), every
shared UI part in the code, and my own mockups checked against both.

Status key: ✅ drawn on the canvas · 🔧 fixed in the mockups today · ⏳ still
to draw · ❓ Stephan to decide.

---

## 1. Mistakes in my own mockups (caught and fixed)

| # | Catch | Rule | Status |
|---|---|---|---|
| 1 | Date of birth was a plain "dd / mm / yyyy" box | Calendar date + clock time pickers (POP, approved 20 Sep) | 🔧 now the date picker; full Date & time board added |
| 2 | I invented new pop-up widths (440 / 600 / 760) | Approved widths are 480 / 720 / 900 / 1100 (POP "Shell pattern") | 🔧 back to the approved widths |
| 3 | Entity card status stripe drawn on the left | The "meridian" is a 3 px **top** bar coloured by the worst alert (LIST §2.1) | 🔧 |
| 4 | Card footer was a plain line | Footer is a muted strip bleeding to the edges, person disc + "Open →" (LIST §2.6) | 🔧 strip; ⏳ person disc + Open |
| 5 | Table headers in sentence case 12 px | Header row 32 px, 10–11 px uppercase (LIST §3) | 🔧 |
| 6 | Wizard 1140 wide with a 290 px rail | WizardShell is 980 (1100 for medication), rail 248 | 🔧 1100 / 264 — ❓ one wizard width (1100) for every wizard? |
| 7 | Wizard progress strip drawn 6 px | Guide says 3 px | ❓ keep the thicker bar from your ChatGPT example, or 3 px? |
| 8 | Top bar missing Ask, Company hub, Announcements | AppHeader has them (code) | ✅ 20 Oct |
| 9 | Sidebar missing the personal rows (My Day, Overview, Today, My Calendar, All Tasks + overdue pill) and the real module groups | SHELL §3 | ✅ 20 Oct |
| 10 | Menus had no disabled item with its reason | LIST §4.2 ("You are not on shift at this house") | ⏳ Menus board |
| 11 | Buttons drawn 36 px | BTN says 44 px; DESIGN says the shipped size renders 38.5 px | 🔧 38 px — ❓ see contradiction 6 |

## 2. Approved patterns the mockups must show

**Pop-ups**
- ✅ Wizard step 1 · ✅ review step with Edit links · ✅ step with an error in the rail · ⏳ success pane ("View client", "Add another") · ⏳ "Discard this draft?" on close · ⏳ narrow screen (rail becomes a top stepper below 1024 px) · ⏳ edit = same wizard prefilled · ⏳ type tile picker as the first step.
- ✅ Form small / medium (+ error state, saving state) · ✅ confirm · ✅ destructive confirm · ✅ confirm with a reason · ⏳ large 900 and extra large 1100 · ⏳ read-only detail viewer (Close + Edit / Delete).
- ✅ Side panel (ticket quick-peek) · ⏳ edit-form side panel · ⏳ Sheet ✕ keeps a 16 px inset.
- ⏳ File preview — loading, PDF page, image, unsupported (Download), unavailable, blocked, access ended, password-protected, retry, version switch, long filename.
- ⏳ Settings modal · ⏳ overview 480 px detail panel · ⏳ block library.

**Form controls**
- ✅ Date (empty, filled, error, locked) · ✅ time (empty, filled, open, error) · ✅ date + time pair in a pop-up · ✅ half-filled pair = draft · ✅ range (chosen, start only, Not known yet) · ✅ one-day popover with month + year jump · ✅ range popover · ✅ clock hours face · ✅ typed time with an invalid minute · ✅ timed appointment (start/end + timezone + duration) · ✅ same value on Review · ⏳ minutes face · ⏳ range across two months · ⏳ disabled out-of-range days.
- ⏳ Searchable record picker — selected, open, keyboard highlight, loading, no results, failed + retry, access lost, more results, selection no longer valid.
- ⏳ Catalogue picker with "Add custom: [value]" (+ duplicate rejected).
- ✅ Tile picker · ✅ locked context card · ✅ switch with On word · ⏳ switch off / disabled · ⏳ checkbox, radio, segmented (≥3 options or two named modes), toggle on-state (`bg-primary-fill/10`).
- ⏳ Uploads — empty, drag-over, staged, uploading (real progress), saved, rejected file, batch error, partial failure, long filename, close guard.
- ⏳ Inputs: rest, hover, focus, filled, disabled, error; required asterisk; error text under the field.

**Pages & navigation**
- ✅ Index header (sky, meters, filters, connected rail, Find chip) · ⏳ profile header (back chip, two-line subline) + tier-2 toned strip · ⏳ rail overflow "⋯ More" · ⏳ two-row meter variant · ⏳ every meter type (delta, key contacts, budget bar) · ⏳ legacy PageHero pages.
- ⏳ Sidebar expanded / collapsed rail / flyout / edge-tab · ⏳ breadcrumb 2-, 3-, 4-level.
- ⏳ Settings page (Fleet pattern: Overview of review cards, titled groups, In-app / Email columns, sticky "Review changes" bar, safety-loosening flag).
- ⏳ Site Calendar (Month / Week / Day / Agenda / Timeline, date anchor, source pills).
- ⏳ Work-record detail (summary, next action, notes, evidence).
- ⏳ Maps (greyscale tiles, coloured overlays).
- ⏳ Charts and the overview block cards.
- ⏳ Sign-in (Journey) in the new palette — staff and family, light and dark.

**Feedback**
- ✅ Toast with Undo · ✅ info / warning / error callouts · ⏳ success callout · ⏳ empty, loading, error states · ⏳ skeletons · ⏳ the Event Horizon loader (full, section, ring-only) · ⏳ reduced-motion version.

**Preferences & accessibility** (draw key screens in each)
- ⏳ Text size 13 / 16 px · ⏳ compact density · ⏳ focus rings on every control · ⏳ frontline 44 px mode · ⏳ a second brand colour (orange or teal) to prove rebranding still works.

## 3. Things broken or missing in the app today

| # | Catch | Where | Count |
|---|---|---|---|
| 1 | **Two page-header systems**: new PageHeader vs old PageHero (+ PageShell + ~30 one-off hero kits) | `components/page/` | 290 vs **243** vs 125 pages |
| 2 | **The approved Event Horizon loader was never built** — only the guide + demo HTML exist | `LOADER_STYLE_GUIDE.md` | `Loader2` in 195 files |
| 3 | **Plain browser date/time boxes** instead of the approved pickers | app-wide | date 240 · datetime 58 · time 28 files |
| 4 | **Calendar colours have no dark-mode values** — pastel fills show as-is in dark | `--src-*` in `app.css` | 81 tokens |
| 5 | Chart colours hardcoded as hex, ignoring the theme | `ops-charts.tsx` (indigo), `fleet-charts.tsx` (purple) | 13+ files; `--chart-*` used in only 15 |
| 6 | Page-loading bar hardcoded `#7c3aed` | Inertia progress | 1 |
| 7 | Toasts use the toast library's own colours, not ours | `Toaster richColors` | all toasts |
| 8 | Add client — the guide's own reference wizard — doesn't use the shared wizard frame | `add-client-dialog.tsx` | +15 other step dialogs |
| 9 | Hand-built full-screen overlays with no focus trap | `fixed inset-0` | 13 files |
| 10 | Browser `window.confirm()` still used | — | 8 files |
| 11 | Pop-up widths all over the place | — | 20+ values, ~230 Tailwind overrides |
| 12 | 47 one-off record pickers, no shared one | — | 47 |
| 13 | 17 copies of StatusBadge, 141 one-off chip / pill / badge components | — | — |
| 14 | 53 one-off charts (DonutChart written 6 times) | — | — |
| 15 | ~15 calendars that don't use the Site Calendar | — | — |
| 16 | Undo toast exists but nothing uses it | `undo-toast.tsx` | 0 callers |
| 17 | Raw `<table>` instead of the table parts | — | 126 files |
| 18 | Hardcoded colours in code (oklch 46, hex 57, rgb/hsl 45 files) and in 30 module CSS files | — | — |
| 19 | Map marker colours hardcoded | `leaflet-map.tsx` | 16 places |
| 20 | Sign-in pages compute their brand from the app's indigo, so they don't show the real Journey purple unless an organisation sets `#7040D6` | `--journey-*` | 10 pages |
| 21 | **Risky actions with no "are you sure?"** — revoke API key, end sessions, turn off two-step sign-in, archive a service context | Settings / System | 4 |
| 22 | Three copies of the settings-modal shell (SettingsModal, Fleet `Modal`, Reporting `ReportDialog`) and three command palettes | — | — |
| 23 | Plain dialogs have no header icon | — | 72 in shell/settings alone |
| 24 | Pop-ups that nothing opens any more (keyboard shortcuts help, delete user, handover write sheet, appearance dropdown, date popover, legacy SSO groups) | — | 7 |
| 25 | Bottom sheets each pick their own corner radius | `ui/sheet.tsx` | — |
| 26 | **The shared confirm box is red unless told otherwise**, so harmless confirms ("Resolve chart alert", "Record this agreement") look like delete warnings | `confirm-dialog.tsx` | 34 of 53 eMAR uses |
| 27 | **Prescriptions "Confirm order" has no confirmation step** | Medication | 1 |
| 28 | Medication pop-ups missing the frontline 44 px sizing (incl. Record dose and all 15 review wizards) | Medication | ~39 |
| 29 | The same medication job built as 2–5 different dialogs (Record dose ×2, stock order / receive / count / adjust ×2, order entry, INR, syringe driver, covert, destruction) | Medication | ~20 clusters |
| 30 | "Discard changes?" written ~34 different ways with 6 different button labels | Medication | 34 |
| 31 | Health & clinical wizards have no "discard draft" guard | Clinical | 4 |
| 32 | **Payroll bank evidence collected through chained browser `prompt()` boxes** (a named anti-pattern) | HR › Payroll | 1 flow |
| 33 | Browser `confirm()` / `prompt()` still used in HR / Rostering / Privacy / Sites | — | 21 HR + 9 Privacy + 7 Sites |
| 34 | **34 of 49 wizards in Governance / H&S / hub close straight away and lose what was typed** | Governance, H&S, hub | 34 |
| 35 | **8 detail viewers delete attachments with no confirm** | Governance, H&S | 8 |
| 36 | Hub confirms use the primary (purple) button even for destructive actions | Company hub | 7 |
| 37 | Add client wizard has no "discard draft" guard on close | Clients | 1 |
| 38 | `ConfirmAction` always renders a red button | Sites / ops | — |
| 39 | Dialogs missing their one-line description | Sites / ops | 24 |
| 40 | Finance wizards with no Cancel button · Title Case step titles · four "Request Finance review" wizards · eight copy-pasted date-range popovers · 11 wizards that open by changing the page address | Finance & fleet | 22 · 17 · 4 · 8 · 11 |
| 41 | About eight different wizard footer layouts in HR alone | HR | ~8 |

**Totals from the seven area lists: 1,539 distinct pop-ups** — IT & support 96 · HR & rostering 229 · Sites, clients & ops 282 · Finance, fleet & assets 286 · Medication & clinical 233 · Shell, settings & devices 194 · Governance, H&S & hub 219. Every one is drawn on pages 8 and 9 of the canvas.

## 4. Rules the new colours will change (write into Rory's rules)

- "Violet-tinted or pure-white page grounds" anti-pattern and the "neutral grey ground `#dcdde0`" rule (SHELL §4).
- Ink chrome reference `#15151f`, sidebar text/icon references (`#a2a4bc`, `#a3a5f7`, `#8e90ae`), wordmark ring `#7b7ef0` (SHELL §2–3).
- **Button glow and hover border use a fixed indigo scale, not the brand** — they won't follow a rebrand (BTN).
- Category colours are hue rotations of `--primary`; moving the brand from hue 277 to 292 shifts all eight (TOK).
- Chart palette: adopt the calm overview palette app-wide? (WS)
- Dark mode: adopt the graphite approach app-wide? (WS)
- Journey tokens derive from `--primary`, so the new default purple fixes sign-in automatically.
- The tone cycle's first colour is "violet" = `--primary`; follows the brand.

## 5. Contradictions in Rory's rules — ❓ Stephan decides

1. Sidebar width 264 px (guide) vs 256 px (shipped).
2. Active tier-2 tab: 22 px square chip + 2 px underline (NAV) vs 18 px circle + 3 px underline (HDR).
3. Meter label 10 px (HDR) vs `text-xs` (DESIGN, 14 Oct).
4. Inactive rail text 80 % (NAV) vs 78 % (HDR).
5. Confirm width: `max-w-md` in the example vs 480 px inline in the shell rule.
6. Buttons: "44 px" via `min-h-11`, which DESIGN says renders 38.5 px.
7. Tone position 0 is called violet but is really the brand colour.
8. Fixed hue-277 values (chrome, button glow, sidebar icon, wordmark ring) don't follow the brand.
9. Workspace/site switcher chip is in the shell anatomy but marked not built.
10. `finance` category listed in DESIGN but not in DESIGN_TOKENS.

## 6. Second round, 20 Oct: what the first canvas still missed

Stephan said items were missing and the look was generic. A sweep of
`resources/js` against the canvas found **23 kinds of screen with no
board**, and my own shell was still not the real one. Each item below now
has a board. Its page number on the canvas is shown with →.

| # | Catch | Where in the code | Status |
|---|---|---|---|
| 42 | My shell's top bar had no Ask, Company hub or Announcements, and the sidebar wasn't the real nav. The real nav has the personal rows (Overview, Company hub, My Day, My Calendar, All Tasks with its overdue pill) and 14 module groups. | `app-header.tsx`, `app-sidebar.tsx` | ✅ every page scene |
| 43 | My "ticket quick-peek" was invented: Details/Activity/Files tabs and a footer. The real drawer is 576 px with no footer. Its head shows the reference, status, priority, Open full page, then requester · raised · assignee · watchers. The body has routing, People & feedback, and a Conversation / Activity thread with Reply / Internal note. It also has session, account and access alerts. | `components/it/ticket-drawer.tsx` | ✅ → 9 |
| 44 | Charts and dashboards had no style. Recharts is used in 36 files (bar 30, donut 15, line 12, area 9, mixed 8), and the Overview block workspace has 7 block kinds. | `features/overview/workspace.tsx` | ✅ → 10 |
| 45 | The old `PageHero` is still in 251 files: notifications, messages, the family portal and the permissions matrix. | `components/page/page-hero.tsx` | 🔧 redrawn on the sky header wherever it appears |
| 46 | Maps: tiles, markers, geofences, the vehicle hover card and trip routes (24 files). | `pages/control-room/map.tsx` | ✅ → 11 |
| 47 | The rostering week grid, capacity heatmap and respite Gantt. FullCalendar is still used in 4 places. | `components/rostering/week-grid-pane.tsx` | ✅ → 11 |
| 48 | The eMAR MAR grid, rounds, the PRN card and the witness PIN / second-person check. | `components/emar/mar/mar-grid.tsx` | ✅ → 12 |
| 49 | **Phone-only staff shell** (bottom nav, pull to refresh) in a web-only app. | `layouts/staff-page-shell.tsx`, `components/staff-bottom-nav.tsx` | ❓ remove? Drawn as desktop My Day |
| 50 | Ticket-style record page with a conversation and composer (templates, macros, KB hints). | `pages/it/tickets/show.tsx` | ✅ → 9 |
| 51 | Chat: the two-pane messages page and the hand-built family chat overlay. | `pages/operations/messages/Index.tsx` | ✅ → 13 |
| 52 | About **51 one-off timelines** and no shared component. | `pages/timeline`, `pages/audit` and others | ✅ one pathway timeline → 13 |
| 53 | **No error pages**: 403, 404, 419, 500 and 503 all show Laravel's defaults. A timed-out session only shows inline, in the ticket drawer. | `resources/views/errors` (missing) | ✅ → 14 |
| 54 | **The page-load bar is hard-coded `#7c3aed`**, so it ignores branding. | `app.tsx` ~line 90 | ✅ redrawn on `--primary` → 14 |
| 55 | The app-access consent page still uses the old auth layout. | `pages/obli/authorize.tsx` | ✅ redrawn in Journey → 14 |
| 56 | **144 notifications send on Laravel's plain mail template**; only 4 are branded. | `resources/views/emails` | ✅ one template → 15 |
| 57 | 14 PDF views and 22 print views have no shared header, footer or black-and-white rule. | `resources/views/pdf` | ✅ → 15 |
| 58 | The company hub has its own CSS, about 100 files. | `pages/company-hub` | ✅ → 15 |
| 59 | **872 plain browser `title=` tooltips.** A popover opens inside another popover. | across `resources/js` | ✅ one tooltip, and popovers are one level only → 6 |
| 60 | Permissions matrix, settings with the left menu (27 pages), and the impersonation and offline banners. | `pages/system/access/Matrix.tsx`, `layouts/settings/layout.tsx` | ✅ → 13 / 14 |
| 61 | Logos and colours must never be hard-coded (Stephan). The top bar, portal, print and email must all read one brand slot. | `components/brand/brand-lockup.tsx` | ✅ Branding board → 2 |

### What drawing each screen turned up (20 Oct)

These were found while drawing pages 9–15 against the real code.

**Real bugs**
- **The signature pad never shows busy.** `hr/signatures/sign.tsx:156` posts at once, so the `processing` flag at `:287` never turns on.
- **The timeline's filters always go to `/clients/{id}/timeline`** (`timeline/index.tsx:130`), so the staff timeline filters the wrong record.
- **A shift email reads "Time: 9:00 AM — "** because the end time is empty (`ShiftAssignedNotification.php:37`). The leave-request email has an empty approver (`LeaveRequestNotification.php:71`). The leave-approved email uses US dates.
- **Voice notes are recorded, then thrown away.** Only the words "Voice note" are posted (`operations/messages/Index.tsx:351–371`). Star and Pin call the same `togglePin`.
- **The payslip shows two different gross figures.** Regular hours include overtime, and there are no ESCT, YTD or IRD lines. The tax invoice has no GST number and placeholder bank details.
- **PDF fonts drop macrons**, so Māori names break: dompdf uses Arial and Helvetica. Times print in UTC with no label.
- **The HR calendar's time grid runs 6 am – 9 pm**, so night shifts and sleepovers are invisible (`calendar-time-grid.tsx:16`).
- **The audit log keeps before/after values but shows only field names.** It also sends a request on every keystroke.

**Safety and clarity**
- MAR grid:
  - `witness_required` is never drawn (`mar-grid.tsx:25`);
  - missed and overdue differ by colour only;
  - the legend merges refused and withheld.
- Overdue has three different tones: critical on the MAR, warning in My Day and in the medicines card.
- Withheld is styled red.
- Status is drawn in the brand colour: Away, Order check, Due now and the round arc.
- Nothing outside the downtime pages says a house is on paper. Nothing on a person's chart says emergency access is ending.
- Chart colours 2, 3 and 4 are green, red and amber, so they collide with the status colours. Recharts tooltips are white boxes in dark mode.
- Map clusters turn red when they're large, not when there's an alert.

**Brand slot and hard-coded colour (Branding rule)**
- **The default brand is still `#7c3aed`** (`derive-palette.ts:364`, `app.tsx:90`), not Journey `#7040D6`. The same literal appears in about 12 more files, and the geofence drawing map defines `PURPLE='#7c3aed'`.
- The live map hard-codes `#6366f1`, `#f97316`, `#8b5cf6` and `#2563eb`. `leaflet-map.tsx` uses Tailwind hex markers and loads marker images from unpkg.
- Emails:
  - `broadcast.blade.php` uses `#7c3aed`, and the contact emails use `#6366f1` and `#8b5cf6`;
  - 138 default-template emails show the app name as text, with no logo.
- PDFs:
  - accent colours are `#4338ca`, `#2563eb` and `#c00`;
  - they use `config('app.name')` instead of the branding name;
  - `report-styles` puts white text on the client's colour, which breaks for a light brand.
- `event-horizon-wordmark.tsx:41–45` crops a client logo into a 24 px square, so the layout depends on the logo's shape.
- The hub's `--hub-canvas` overrides `--background` with an off-Slate grey. `recognition.css` caps the width at 1180 px.
- The favicon is always the Oblivion ring (`app.blade.php:99`). Theme-color is `#fff` / `#0a0a0a`.
- The marketing site still says "Oblivion Findings" with an "OF" tile.
- **Clashes for clients to be warned about in Settings › Branding:**
  - a teal brand makes the live/teal "now" look like "in progress";
  - orange and rose brands sit next to amber and red.
- For the token PR: mixing in `oklch` turned orange tints pink, so mix the brand into slate with `oklab`.

**Consistency**
- Three separate kanban builds. Of the two named boards, neither actually drags; HR recruitment is the only real drag-and-drop board.
- The family chat is copied into the portal (about 1,750 lines). All 15 portal pages use the staff AppLayout.
- Many invented widths: hub pop-ups (512, 576, 672, 768, 800, 1040, 1120), the diagram studio (540, 860) and the announcement dialogs (512).
- Raw palette classes hidden inside JS maps (notifications) where ESLint can't see them.
- Title Case labels across settings, careers, the portal nav, the map and two-step settings, which also uses "Two-Factor" while sign-in says "two-step".
- Emoji used as icons and moods (messages, handovers, the family portal, the timeline empty state).
- The checklist builder's grip icon is fake: only Move up / Move down work.
- Native `<select>` and date boxes in the audit log, timeline, role edit, careers and the portal visit request.

Full per-board reports are kept with the canvas working files; ask if you want them added here.

Source detail: the full rule-by-rule checklist with every measurement is in
[RULES-CHECKLIST.md](RULES-CHECKLIST.md); the inventories are in `inventory/`.
