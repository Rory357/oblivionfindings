# Lone Worker Safety redesign — 01 design digest (screen-by-screen spec)

Source of truth: `LoneWorkersRedesign_extracted/design_handoff_lone_workers/Lone Worker Safety.dc.html`
(907 lines; markup lines 1–490, data+logic in the `<script type="text/x-dc">` block lines 492–904).
Cross-referenced with `README.md` + `INTEGRATION_AUDIT.md` and the live codebase kits.

This spec lets you build WITHOUT reopening the HTML. All copy/tones are exact.

---

## 0. Semantic tone vocabulary (mock → app token)

The mock defines a `tone(t)` map (HTML lines 600–606) with five tones. Map each to the kit:

| Mock tone | Mock vars (`bg / fg / dot`) | App token (`TONE_BG` / `TONE_DOT` in `register-row-kit.tsx`) |
|---|---|---|
| `success` | `--ssb / --ss / --ss` (green oklch 150) | `bg-status-success-bg text-status-success` / `bg-status-success` |
| `warning` | `--swb / --sw / --sw` (amber oklch 85) | `bg-status-warning-bg text-status-warning` / `bg-status-warning` |
| `critical` | `--scb / --sc / --sc` (red oklch 25) | `bg-status-critical-bg text-status-critical` / `bg-status-critical` |
| `info` | `--accent / --primary / --primary` | `bg-status-info-bg text-status-info` (TabStrip) / `bg-primary/10 text-primary` |
| `neutral` | `--muted / --muted-foreground` | `bg-muted text-muted-foreground` / `bg-muted-foreground` |

Lifecycle maps (HTML 607–610):
- **Session status → tone** (`sessTone`): `active→success`, `overdue→warning`, `emergency→critical`, `completed→neutral`.
- **Session status → label** (`sessLabel`): `Active / Overdue / Emergency / Completed`.
- **Alert type → {tone,label,icon}** (`alertTypeMeta`): `emergency→{critical, "Emergency", AlertTriangle}`, `overdue_check_in→{warning, "Overdue check-in", Clock}`, `no_response→{warning, "No response", Bell}`.
- **Alert status → {tone,label}** (`alertStatusMeta`): `active→{critical,"Active"}`, `acknowledged→{warning,"Acknowledged"}`, `resolved→{success,"Resolved"}`.

**Build implication — NO raw colours.** App-primary gradient only on the hero (via `HeroShell`). en-NZ dates via `@/lib/datetime formatDateTime` (never en-GB). Reuse the kits' sanctioned `eslint-disable no-restricted-syntax` headers for on-dark hero controls / bespoke modal surfaces.

---

## 1. HERO (`HeroShell`)  — HTML lines 66–149

Wrapper: `<HeroShell footer={…}>`. Right-click the whole hero → quick-actions context menu (§1.6). Order of children top→bottom: WorkflowRibbon, medallion+title row+CTAs, two clusters, NZ badge row. Footer band = filter bar.

### 1.1 Workflow ribbon (HTML 73–78)
`<WorkflowRibbon current="report" />` from `@/pages/health-safety/components/workflow-ribbon`. Renders `H&S ▸ Report & respond (active) ▸ Investigate ▸ Resolve ▸ Analyse`. (Component is fixed; pass `current="report"`.)

### 1.2 Medallion + status pill + title (HTML 82–90)
- `<HeroMedallion icon={Radio} />` — lucide `Radio`.
- `<HeroStatusPill>Lone worker monitoring · live</HeroStatusPill>` — green `animate-ping` dot built in.
- `<h1>`: **Lone Worker Safety** (27px/700).
- Description `<p>` (max-w 560): **"Live monitoring for staff working alone or remotely — start sessions, track check-ins, and escalate emergencies. Operational alerts are owned by the Control Room."**

### 1.3 Top-right CTAs (HTML 92–95)
- **Reports** — outline on-dark button, lucide `FileText`. Mock = popover ("board reports"); build as a `Popover` CTA (mirror Incidents' Reports popover with `report-launcher`/board-reports links).
- **Start session** — primary solid (white bg / primary text), lucide `Plus`. Opens the Start-session wizard (§6).

### 1.4 The TWO HeroClusters + every tile (HTML 99–124, data 732–743)

Each tile = `<HeroClusterTile href=… label value caption tone />`. In the mock tiles use `onClick` to set tab/statusFilter; in the app give each an `href` to the matching tab/filter (server-driven via `router.get`). Grid is 4-up per cluster.

**Cluster A — "Live monitoring"** (icon `Activity`):
| label | value (mock) | caption | tone | links to |
|---|---|---|---|---|
| Active | 12 | being monitored | success | `tab=sessions&status=active` |
| Overdue | 1 | check-in late | warning | `tab=sessions&status=overdue` |
| Emergencies | 1 | unresolved | critical | `tab=sessions&status=emergency` |
| Ending <1h | 3 | wrap-up soon | warning | `tab=sessions&status=all` (server: ending-within-1h subset) |

**Cluster B — "Alerts & response"** (icon `Bell`):
| label | value (mock) | caption | tone | links to |
|---|---|---|---|---|
| Alerts today | 7 | all sources | neutral | `tab=alerts` |
| Awaiting ack | 2 | need a response | warning | `tab=alerts` (status=active) |
| Unresolved | 3 | Control Room | critical | Control Room deep-link (canonical) |
| No recent check-in | 1 | >1 interval | warning | `tab=sessions&status=overdue` |

### 1.5 NZ compliance badge row (HTML 127–131, data 746–753)

⚠️ **Key build decision:** the shared `HeroComplianceBadges` (`hs-hero-kit.tsx:177`) is HARD-CODED to the dashboard's 5 badges (WorkSafe/Ngā Paerewa/Hazardous substances/Fire/First aid) — it does NOT accept arbitrary chips. The lone-worker mock needs a DIFFERENT 5-chip set. Options: (a) extend `HeroComplianceBadges` to accept an optional `items` override, or (b) render a local chip row reusing the kit's `CHIP_CLASS`/`CHIP_ICON` tone classes (`border-status-warning/50 bg-status-warning/25 text-primary-foreground`, etc.). Either way, feed COUNTS/BOOLEANS, never pre-formatted strings (README rule).

Mock badges (each: tone, icon, label — label is computed from the count/bool):
| # | tone | icon | label (mock) | driven by |
|---|---|---|---|---|
| 1 | warning | `Users` | **11 of 12 workers checked in** | `checkedIn` of `activeTotal` (warning if any not checked in, else success) |
| 2 | warning | `Clock` | **1 overdue check-in** | overdue-session count (warning if >0, else success/hidden) |
| 3 | critical | `AlertTriangle` | **1 emergency active** | unresolved emergency count (critical if >0, else success) |
| 4 | success | `ShieldCheck` | **HSWA 2015 · lone/remote duty met** | boolean (HSWA s lone/remote-worker duty) |
| 5 | success | `HeartPulse` | **After-hours cover · ACC ready** | boolean (after-hours cover present) |

### 1.6 Hero footer = filter bar (HTML 134–148, data 756–764)

Rendered via `<HeroShell footer={…}>`. Controls left→right (all drive `router.get(url, {...filters, ...}, {preserveState, preserveScroll})`):
1. **Period** — label "Period" + `HeroSegmented variant="pill"` items `[Today | This week | 30 days]` (keys `today/week/30d`, default `today`).
2. **Site filter** — `<EntityFilter onDark label="Site" allLabel="All sites · {n}" …>`. Mock toggles a single value; inactive shows chevron-down, active shows the site name + an `X` to clear.
3. **Status select** — pill cycling `All → Active → Overdue → Emergency` (labels: `Status: {All|Active|Overdue|Emergency}`), chevron-down. Build as a small select/segmented.
4. **Search** (right-aligned, `ml-auto`) — `<input type="search" placeholder="Search workers…">` with leading `Search` icon, width ~180px.
5. **Clear** — shown only when a filter is active; `X` + "Clear" → resets site+status (keep current tab).

### 1.7 Right-click hero → quick actions (`ShiftContextMenu`) (HTML data 656–663)
Menu header tag **QUICK** (bg `accent`, fg `primary`), meta "Lone worker quick actions". Items:
1. **Start session** — sub "New lone worker session", tone primary, `Plus` → open wizard.
2. **View emergencies** — sub "Active emergency sessions", tone critical, `AlertTriangle` → `tab=sessions&status=emergency`.
3. **Open Control Room** — sub "Alert triage desk", `RadioTower` → Control Room.
4. *(separator)*
5. **Export register** — sub "CSV · current filters", `FileText` → export.

---

## 2. TABS (`TabStrip`)  — HTML 152–159, data 766–771

`<TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Lone worker views">` from `@/components/rostering`. `RosterTabItem = { id, label, icon, tone, badge? }`. `setTab` → `router.get(url, {...filters, tab:id}, {preserveScroll})`.

| id | label | icon | tone | badge formula (mock value) |
|---|---|---|---|---|
| `sessions` | Sessions | `Radio` | `info` | active + overdue + emergency (mock badge **14**) |
| `alerts` | Alerts | `Bell` | `critical` | unresolved alerts (mock badge **5**) |

(Badge omitted when 0 via `badge || undefined`.)

---

## 3. REGISTER TABLES  — card with `RegisterTableHeader` + `<table>` (HTML 162–228)

Card header (HTML 163–169): accent-tiled icon + title + subtitle + right-side hint.
- Sessions header: icon `Radio`, title **Sessions**, subtitle **live monitoring register**.
- Alerts header: icon `Bell`, title **Alerts**, subtitle **lone worker signals**.
- Hint (both): **"Right-click a row for the full list of actions"** + icon `MousePointer2` (`MousePointer2` in lucide; mock calls it `mousePointer`). Use `RegisterTableHeader hint=… hintIcon={MousePointer2}`.

Every `<tr>` (copy Incidents row exactly, HTML 180 / 209):
`tabIndex={0}`, `onClick → openDetail(id)`, `onContextMenu → openRowCtx(e)`, `onKeyDown` Enter/Space → openDetail, `cursor-pointer outline-none`, hover `bg-muted/55` (mock `color-mix(muted 55%)`) + focus ring. Trailing ⋮ button (`onClick stopPropagation` → opens same ctx menu anchored at button: see `dotsAt`, HTML 655 — anchors at `{clientX:right-282, clientY:bottom+4}`).

### 3.1 SESSIONS table (HTML 175–196; row data 780–785; sample rows 554–569)
Ordered columns + cell render:
1. **Worker** — avatar circle (30px, `entityTone(id)` bg, `initials(name)` white) + name (600) + below it, if `location`, a muted line with `MapPin` icon + location text.
2. **Site / Client** — site (500) + muted client line if present.
3. **Started** — muted, nowrap (e.g. "20 Jun, 9:10 AM"); format via `formatDateTime`.
4. **Expected end** — muted, nowrap.
5. **Last check-in** — foreground time; if `overdueBy>0`, a second line **"overdue by {n}m"** in `text-status-warning` (mock `--sw`, weight 600).
6. **Status** — pill: dot + label, `TONE_BG[sessTone(status)]`. (Active/Overdue/Emergency/Completed.)
7. **⋮** — kebab (right-aligned).

Tone rules: active=success, overdue=warning, emergency=critical, completed=neutral. **No `animate-pulse`.** Pagination: `<LaravelPagination>`; sessions paginate 25 (mock footer "Showing 6 of 14 sessions", HTML 775).

### 3.2 ALERTS table (HTML 203–225; row data 788–796; sample rows 571–577)
Ordered columns:
1. **Worker** — avatar + name (no location line).
2. **Site / Client** — site (500) + muted client line.
3. **Type** — tinted pill: type icon + label, `TONE_BG[alertTypeMeta.tone]` (Emergency=critical, Overdue check-in=warning, No response=warning).
4. **Triggered** — muted nowrap timestamp.
5. **Status** — pill dot+label (`Active`/`Acknowledged`/`Resolved`).
6. **Source** — `FlagBadge` (`register-row-kit`): `control_room → {tone:'info', label:"Control Room", icon: RadioTower, title:"Canonical · owned by Control Room"}`; `legacy → {tone:'neutral', label:"Legacy", icon: FileText, title:"Pre-PR4 compatibility record"}`.
7. **⋮** — kebab.
Mock footer "Showing 5 of 5 alerts". Paginate alerts when `tab=alerts`.

---

## 4. RIGHT-CLICK + KEBAB MENUS (`ShiftContextMenu`)  — one `actionsFor(entity)` powers both

`ShiftCtxItem = {sep:true} | {icon, label, sub?, kbd?, tone?:'primary'|'critical', onClick?}`. Header: `tag` = status label (tone-coloured via `tagBg/tagColor`), `meta` = "{worker} · {site/type}". Gate mutating items on `can.manage` AND status. Every mutating item opens a MODAL (never bare nav).

### 4.1 Sessions menu (HTML data 630–643)
Tag = session status label; meta = "{worker} · {site}". `can = status==='active' || status==='overdue'`.
1. **View session** — sub "#{id} · {worker}", tone primary, `Eye` → openDetail('session', id). *(always)*
2. **Record check-in** — sub "Log worker status", `CheckCircle2` → action `checkin`. *(only if `can`)*
3. **Extend / edit session** — sub "Push out expected end", `Clock` → action `extend`. *(only if `can`)*
4. *(separator)*
5. **End session** — sub "Stop monitoring", `XCircle` → action `end`. *(only if `can`)*
6. **Trigger emergency** — sub "Notify contacts now", tone critical, `AlertTriangle` → action `emergency`. *(only if `can`)*
7. *(separator)*
8. **Open worker profile** — sub "{worker}", `User` → worker profile.
9. **Copy link** — `Link` → copy deep-link.

### 4.2 Alerts menu (HTML data 644–654)
Tag = alert status label; meta = "{worker} · {type label}".
1. **View alert** — sub "{type label}", tone primary, `Eye` → openDetail('alert', id). *(always)*
2. **Open in Control Room** — sub "Triage · SLA · playbooks", tone primary, `RadioTower` → deep-link `control-room.alerts.show`. *(always; PRIMARY action)*
3. *(separator)*
4. **Acknowledge** — sub "Convenience action", `Bell` → action `acknowledge`. *(only if `status==='active'`)*
5. **Resolve** — sub "Convenience action", tone critical, `Check` → action `resolve`. *(only if `status!=='resolved'`)*
6. *(separator — only if `sessionId`)*
7. **Open session** — sub "{worker}", `Activity` → openDetail('session', sessionId). *(only if `sessionId`)*
8. **Copy link** — `Link`.

---

## 5. DETAIL MODAL (follow `IncidentDetailDialog`)  — param-driven `?session=` / `?alert=`

Open via `router.get(url, {...filters, session:id}, {preserveState, preserveScroll, only:['detail']})` (mirror Incidents `openDetail`/`closeDetail`, `incidents/index.tsx:244-247`). Two variants share the param-driven overlay.

### 5.1 SESSION detail (HTML 266–325; builder 804–815) — width min(94vw, 840px), two-column body
**Header:** 44px avatar (`entityTone`/`initials`) + `<h2>` worker name + status pill (dot+label). Subline: **"Session #{id} · {site}"**. Close `X`.

**Body — LEFT column:**
1. **Monitoring plan** (eyebrow icon `ClipboardCheck`, primary) — bordered rows (`{k,v}`, HTML 810): Worker · Site · Client (`—` if none) · Activity · Started · Expected end · Check-in interval ("Every {n} min") · Last check-in ("{time}" + " · overdue by {n}m" if overdue).
2. **Last-known location** (eyebrow icon `MapPin`) — 96px gradient map placeholder with a rotated `Navigation` pin; footer row = location text (600) + coords ("{lat4}, {lng4}" tabular) + **Open map** outline button (`ChevronRight`) → static-map/maps link using `location_lat/location_lng`.

**Body — RIGHT column:**
3. **Check-in timeline** (eyebrow icon `ListChecks`) — vertical connector timeline from `checkIns`. Each node icon by kind (HTML 806): `ok→{Check, success}`, `start→{Radio, info}`, `emergency→{AlertTriangle, critical}`, `end→{XCircle, neutral}`. Each entry: title + muted time + optional note.
4. **Alert history** (eyebrow icon `Bell`, critical) — only if session has alerts. Each = clickable card (type icon+label, time + status, `ChevronRight`) → openDetail('alert', alertId).

**Footer Options bar** (HTML 313–323): left label "Lifecycle actions"; right cluster shown only when `canAct` (`active`||`overdue`):
- **Record check-in** — outline, `CheckCircle2` (success-tinted icon) → action `checkin`.
- **Extend / edit** — outline, `Clock` → action `extend`.
- **End session** — outline, `XCircle` → action `end`.
- **Trigger emergency** — critical-tinted button, `AlertTriangle` → action `emergency`.

### 5.2 ALERT detail (HTML 327–344; builder 816–822) — width min(94vw, 460px), single column
**Header:** rounded type-icon tile (tone-coloured) + `<h2>` type label + subline "{worker} · {site}". Close `X`.
Body (top→bottom):
1. Bordered summary rows: Worker · Site / Client · Triggered · Status · Source ("Control Room (canonical)" / "Legacy record").
2. **Control Room info banner** (primary tint, `RadioTower`): *"SLA, escalation and playbooks for this alert live in the **Control Room**. Acknowledge / resolve here are convenience actions only."*
3. **Open in Control Room** — full-width primary button, `RadioTower` (the foregrounded action) → `control-room.alerts.show`.
4. Row of two: **Acknowledge** (outline, `Bell`) + **Resolve** (critical-tinted, `Check`).
5. **View linked session** — text button, `ChevronRight` → openDetail('session', sessionId) (or toast "No linked session").
Do NOT rebuild Control Room triage here.

---

## 6. START-SESSION WIZARD (model on `add-client-dialog.tsx` via `wizard/shell` + `primitives`)

Full-height `Dialog` min(94vw,980px)×min(88vh,760px). 248px stepper rail (logo `Radio` "Start session / Lone worker monitoring", numbered steps with ✓ when done, completeness meter at bottom). Header "Step {n} of 3 · {label}". 3px progress bar. Sticky footer. Build with `WizardShell` + `WizardStep[]` + `WizardStepPane` + `WizardSuccessPane` + `ReviewCard`/`ReviewRow` + `Ring`; inputs via `Field`/`FieldErr`/`SelectInput`/`Segmented`/`StepHead`/`InfoCard`.

Form state (HTML 593): `{shift_id, user_id, site_id, client_id, location, lat, lng, expected_end_at, interval:'30', activity}`. Two modes: `wizMode = 'shift' | 'adhoc'`.
Completeness % (HTML 706-708): fields `[user_id, site_id, client_id, location, expected_end_at, activity]` each +1, plus interval always counts; `round(f/7*100)`.

### Step 1 (index 0) — "Choose the shift" (HTML 382–420)
- `StepHead`: icon `Calendar`, title **"Choose the shift"**, blurb **"Lone work maps to a rostered shift — pick it and the worker, site, client & end time prefill from the roster."**
- **Mode toggle** (`Segmented`): `[From a roster shift | Ad-hoc · no shift]` (keys `shift`/`adhoc`, default `shift`).

**Mode = shift** (HTML 387–399): sub-label "Today's lone / remote shifts · from the roster". A list of shift tiles (data 579–584); each tile: avatar(initials) + worker (700) + tag pill (tone from `tagTone`) + line "{shiftId} · {time} · {site} {· client|· No client}" + a check icon that appears when selected (selected tile gets primary border + ring + primary-tint bg). Selecting a shift calls `selectShift` → prefills `shift_id, user_id, site_id, client_id, expected_end_at(=shift.end), location` (HTML 684). Validation error `user_id` ("Select the worker who is working alone.") rendered with `AlertTriangle`.
  - Shift tile fields (mock): `{id:'SH-3301', worker, user_id, site, site_id, client, client_id, time:'9:00 AM – 1:00 PM', end:'2026-06-20T13:00', location, tag:'Lone · community visit', tagTone:'warning'|'critical'}`.

**Mode = adhoc** (HTML 401–411): amber `InfoCard` (`AlertTriangle`): *"No rostered shift — capture the worker manually. Ad-hoc sessions aren't linked to a timesheet or the roster."* Then:
  - **Worker** `*` required — `SelectInput`, placeholder "Select staff member…", options = `staff` (id→name). Error → red border + `FieldErr`.
  - **Site** *optional* — select, "No site" + `sites`.
  - **Client** *optional* — select, "No client" + `clients`.

**Shared below (both modes)** (HTML 413–418):
  - **Location** — text input, helper "street address or area", placeholder "e.g. 14 Cameron Rd, Tauranga".
  - **Latitude** *optional* — text, placeholder "-37.6878" (tabular).
  - **Longitude** *optional* — text, placeholder "176.1651" (tabular).
  - Primary `InfoCard` (`MapPin`): *"Coordinates are optional — on a shift they default to the worker's last GPS ping (ShiftGpsLog). Reverse-geocoding runs via self-hosted Nominatim."*

### Step 2 (index 1) — "Monitoring plan" (HTML 422–432)
- `StepHead`: icon `Clock`, title **"Monitoring plan"**, blurb **"When to expect them back and how often to check in."**
- **Expected end** `*` required — `<input type="datetime-local">`; client validation: required + after:now (mirror `startSession` rule `after:now`). Error "Set when the worker is expected back."
- **Check-in interval** — `Segmented` `[15m | 30m | 60m | 2h]` (values `15/30/60/120`, default `30`).
- **Activity description** — textarea (3 rows), placeholder "Describe the lone-work activity — e.g. home visit, medication support, site lock-up."

### Step 3 (index 2) — "Review & start" (HTML 434–443; rows 868–869)
- Header with `Ring pct={wizPct}` + title **"Review & start"**, blurb **"Confirm the details, then start monitoring."**
- Two `ReviewCard`s (each with Edit pencil → jump to step):
  - **Worker & location** (`User`, edit→step 0): rows — **Linked shift** (`{shift_id}` or "Ad-hoc (no shift)") · Worker · Site (`—` if none) · Client (`—` if none) · Location (`—`).
  - **Monitoring plan** (`Clock`, edit→step 1): rows — Expected end · Check-in interval ("Every {n} min") · Activity (`—`).

### Footer (HTML 445–452)
- Left: **Back** (when step>0).
- Right: **Cancel** (text) · on review only **Save & add another** (outline-primary) · **Continue** (→ on last step becomes **Start session** with `Radio` icon; otherwise `ChevronRight`).
- Submit: `useForm.post(route('health-safety.lone-workers.sessions.store'), {preserveScroll, preserveState})`. `validateStep` mirrors `startSession`; `onError` jumps to failing step (HTML 697-698: `step = e.user_id ? 0 : 1`). **Save & add another** POSTs `stay=1` (controller honours `$request->boolean('stay')`).

### Success pane (HTML 353–360; `WizardSuccessPane`)
Green `Check` medallion + `Sparkles`. `<h2>` **"Session started"**. Body: **"{worker} is now being monitored. Overdue check-ins will surface here and in the Control Room automatically."** Buttons: **View session** (primary) + **Done** (outline).

---

## 7. ACTION MODALS (single-screen on shared Dialog chrome, NOT wizards) — HTML 459–484; config 873–897

Width min(94vw,440px). Thin context header eyebrow = **"{worker} · Session #{id}"** (or "Alert #{id}" for ack/resolve) + close `X`. Body `StepHead`-style: tinted icon tile + title + blurb. Shared footer: **Cancel** (outline) + primary/critical CTA. POST to existing endpoints; refresh in place (partial reload). All actForm seed `{status:'ok', end:'', interval, notes:''}`.

| kind | title | blurb | icon | body fields | CTA (label / icon / tone) |
|---|---|---|---|---|---|
| **checkin** | Record check-in | "Log the worker's status and time-stamp the check-in." | `CheckCircle2` | **Worker status*** 3-tile picker (below) + Notes (optional) textarea | Submit check-in / `Check` / primary |
| **extend** | Extend / edit session | "Push out the expected end or adjust the check-in interval." | `Clock` | **New expected end** datetime-local + **Check-in interval** `Segmented` [15m/30m/60m/2h] | Save changes / `Check` / primary |
| **end** | End session | "Stop monitoring this session." | `XCircle` | Confirm `InfoCard` (warning tone): *"End monitoring for this session? The worker will no longer be tracked and overdue alerts stop."* | End session / `XCircle` / primary |
| **emergency** | Trigger emergency | "Raise an emergency and notify contacts now." | `AlertTriangle` | Confirm `InfoCard` (**critical** tone, `AlertTriangle`): *"This immediately raises an emergency for this worker and notifies their emergency contacts and the Control Room. Continue?"* | Confirm emergency / `Phone` / **critical** (`var(--sc)`) |
| **acknowledge** | Acknowledge alert | "Mark that someone is responding — a convenience action." | `Bell` | Notes (optional) textarea, placeholder "e.g. Contacted worker, awaiting response" | Acknowledge / `Check` / primary |
| **resolve** | Resolve alert | "Close out the alert — a convenience action." | `Check` | **Resolution notes** textarea, placeholder "Describe how this was resolved" | Resolve alert / `Check` / **critical** (`var(--sc)`) |

**Check-in 3-tile picker** (HTML 467-469, 893): `[OK (CheckCircle2, success) | Concern (AlertTriangle, warning) | Emergency (Phone, critical)]`; active tile gets tone border + tint + ring. Endpoint validates `status in:ok,concern,emergency`.
Notes shown for: checkin, acknowledge, resolve (label "Notes (optional)" except resolve → "Resolution notes"). Confirm-banner shown for: end, emergency.

Toast (HTML 487-489, submit msgs 677): checkin→"Check-in recorded", extend→"Session updated", end→"Session ended", emergency→"Emergency alert triggered", acknowledge→"Alert acknowledged", resolve→"Alert resolved".

---

## 8. Existing kit signatures (so you compose, not reinvent)

- **`hs-hero-kit.tsx`** exports: `HeroShell({children, footer?})`, `HeroStatusPill({children})`, `HeroMedallion({icon})`, `HeroCluster({title, icon, children})`, `HeroClusterTile({href?, label, value, caption, tone, delta?, deltaTone?})`, `HeroSegmented({label?, items, value, onChange, ariaLabel, variant?:'pill'|'segmented'})`, `HeroComplianceBadges({worksafeAwaiting, sdsExpiring, drillsDue?, drillsOverdue?, ngaPaerewaCertified?, firstAidOk?})` ⚠️fixed 5-badge set, `HeroSummaryStrip`, `HeroSummaryMetric`, `fmt(value, suffix?)`, `type Tone`.
- **`register-row-kit.tsx`**: `TONE_BG`, `TONE_DOT` (Record<Tone,string>), `titleCase`, `initials(label)`, `entityTone(id)`, `FlagBadge({icon, children, tone:'critical'|'warning'|'success'|'info'|'neutral', title})`, `RegisterTableHeader({icon, title, subtitle?, hint?, hintIcon?})`, `type Tone`.
- **`@/components/rostering`**: `TabStrip({value, onChange, items, className?, ariaLabel?})` + `type RosterTabItem {id, label, icon, tone, badge?}` (tones: primary/warning/success/info/violet/critical); `EntityFilter({label, allLabel, items:EntityFilterOption[], value:number|null, onChange, onDark?, className?, pluralLabel?})` + `type EntityFilterOption {id, name, description?}`; `ShiftContextMenu({ctx:ShiftCtxState, onClose})` + `type ShiftCtxItem` + `type ShiftCtxState {x, y, tag, tagBg?, tagColor?, meta, items}`.
- **`@/components/wizard/shell`**: `WizardShell`, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`, `ReviewRow`, `type WizardStep`.
- **`@/components/wizard/primitives`**: `Field`, `FieldErr`, `SelectInput`, `Segmented<T>`, `ChipMulti`, `TilePicker`, `StepHead`, `SubHead`, `InfoCard`, `Ring({pct, size?})`.
- **`@/components/ui/laravel-pagination`**: `LaravelPagination`.
- Structural template to copy: `resources/js/pages/incidents/index.tsx` (HeroShell → WorkflowRibbon → footer filter → TabStrip → table with row click + right-click + `LaravelPagination`; `openDetail`/`closeDetail` partial-reload at lines 244-247).

---

## 9. Backend reality (current state — for the build plan)

Routes (`routes/health-safety.php:265-281`, all under `permission:hazards.view`/`hazards.manage`):
- `health-safety.lone-workers.index` (GET `/`)
- `…sessions.store` (POST `/sessions`)
- `…sessions.check-in` (POST `/sessions/{session}/check-in`)
- `…sessions.end` (POST `/sessions/{session}/end`)
- `…sessions.emergency` (POST `/sessions/{session}/emergency`)
- `…alerts.acknowledge` (POST `/alerts/{alert}/acknowledge`)
- `…alerts.resolve` (POST `/alerts/{alert}/resolve`)
- **Missing (must add per README):** `sessions.update` (extend/edit `expected_end_at`, `check_in_interval_minutes`, `activity_description`, `location`) under `permission:hazards.manage`.

`LoneWorkerController::index` currently returns: `sessions` (paginate 25, client flattened to `{id,name}`), `alerts` (canonical `ControlRoomAlert` source='lone_worker' mapped + legacy `LoneWorkerAlert` >1 day old; ids prefixed `cr_`/`legacy_`), `stats {active_sessions, overdue_check_ins, alerts_today, emergency_alerts}`, `sites`, `staff`, `clients`, `filters` (only site_id/status/user_id), `can_manage`. **Needs to add:** `tab`, `tabCounts`, a `hero` block (cluster + NZ-badge counts), `detail` (only when `?session=`/`?alert=`; eager-load `checkIns`, `alerts`, `user`/`site`/`client`), `can:{manage}`, server filters `period` + `q`, alert pagination when `tab=alerts`. Keep all route names/URLs.

`LoneWorkerSession` model (`app/Models/LoneWorkerSession.php`) columns: `user_id, site_id, client_id, started_at, expected_end_at, ended_at, location, location_lat(decimal:7), location_lng(decimal:7), activity_description, check_in_interval_minutes, last_check_in_at, status(active|overdue|emergency|completed), emergency_triggered_at, emergency_notes, created_by, updated_by`. Relations: `user/site/client (belongsTo)`, `checkIns (hasMany LoneWorkerCheckIn)`, `alerts (hasMany LoneWorkerAlert)`. **No `shift_id` yet** — add nullable FK + `belongsTo(Shift)` / `Shift hasOne(LoneWorkerSession)` (audit §3). Default `expected_end_at` = `shift.ends_at`; default `location_lat/lng` = latest `ShiftGpsLog`.

`LoneWorkerCheckIn` (`lone_worker_check_ins`): `lone_worker_session_id, checked_in_at, location_lat, location_lng, status(ok|concern|emergency), notes`.

`startSession` validation (controller :125-135): `user_id` required exists; `site_id`/`client_id` nullable exists; `expected_end_at` required date after:now; `activity_description` nullable string max:2000; `check_in_interval_minutes` nullable int 15–480 (default 60); `location` nullable string max:500; `location_lat` between -90,90; `location_lng` between -180,180. `checkIn` validates `status in:ok,concern,emergency`.

**Alert canonicality:** `ControlRoomAlert` (source='lone_worker') is the operational source of truth; `LoneWorkerAlert` is legacy-compat. Alerts must deep-link to `control-room.alerts.show`; do NOT build parallel triage. ⚠️ Detail `?alert=` lookups must handle the `cr_`/`legacy_` id prefixes the controller emits.

## 10. Data contracts the mock encodes (target shapes)
- **Session:** `{id, user{id,name}, site{id,name}, client{id,name}|null, started_at, expected_end_at, last_check_in_at, status:active|overdue|emergency|completed, activity_description, check_in_interval_minutes, location, location_lat, location_lng, shift_id|null, checkIns[], alerts[]}`.
- **CheckIn (timeline):** `{checked_in_at, status:ok|concern|emergency, notes}` → mapped to timeline kinds ok/start/emergency/end.
- **Alert:** `{id, session{...}|null, worker, site, client|null, type:emergency|overdue_check_in|no_response, triggered_at, status:active|acknowledged|resolved, source:control_room|legacy}`.

## 11. De-duplication guardrails (INTEGRATION_AUDIT)
Three actors: coordinator/H&S (this page), worker (My Day + native app — single check-in tap; do NOT push register/wizard at workers), client (never). Worker check-in lives in My Day (`resources/js/pages/my-day/index.tsx`) POSTing to `sessions.check-in`; auto-end session on shift clock-out. Share data+endpoints, never duplicate the UI. Open product decisions: shift_id FK + lone-shift flag; auto-end default on/off; permissions keep `hazards.*` vs split `lone-workers.*` (record the decision).

Icons (lucide-react): Radio, ShieldAlert, Bell, AlertTriangle, Eye, CheckCircle2, XCircle, Clock, Calendar, MapPin, Navigation, User, Users, Link, Check, Search, Plus, X, ChevronRight, MousePointer2, Activity, RadioTower, Sparkles, HeartPulse, ShieldCheck, Phone, Pencil, FileText, ListChecks, ClipboardCheck, Wrench, BarChart3, LayoutDashboard.
