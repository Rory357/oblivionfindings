# Health & Safety Dashboard — Prototype Implementation Digest

> Exhaustive extraction from `Health & Safety Dashboard.dc.html` (1063 lines) + `support.js` (the dc-runtime; no app logic) + `HANDOFF.md`. This is the implementation bible: exact copy, values, structure, behaviour. Region scope = **New Zealand only** (WorkSafe NZ · HSWA 2015 · Hazardous Substances Regs 2017 · Ngā Paerewa NZS 8134:2021 · ACC; metrics are **LTIFR / TRIFR**, never TRIR/RIDDOR/COSHH).
>
> **Note on `support.js`:** it is the generic `dc-runtime` (template compiler + React bootstrap), NOT this dashboard's logic. All dashboard behaviour lives in the `<script data-dc-script>` block at the bottom of the `.dc.html` (the `class Component extends DCLogic`). Citations below are to the HTML file unless noted.

---

## 0. Tokens, chrome & data-props (context the engineer needs first)

**Root CSS variables** (set inline on the root `div`, line 27). Map each to the app's semantic token (right column) per HANDOFF — do **not** ship the literals.

| Prototype var | oklch literal | Semantic token |
|---|---|---|
| `--background` | `oklch(0.98 0.006 277)` | `--background` |
| `--foreground` | `oklch(0.15 0.015 277)` | `--foreground` |
| `--card` | `oklch(1 0 0)` (#fff) | `--card` |
| `--muted` / `--muted-2` | `oklch(0.96 …)` / `oklch(0.965 …)` | `--muted` / `--muted-2` |
| `--muted-foreground` | `oklch(0.42 0.015 277)` | `--muted-foreground` |
| `--border` | `oklch(0.92 0.010 277)` | `--border` |
| `--primary` | `oklch(0.511 0.262 277)` | `--primary` |
| `--primary-600` | `oklch(0.46 0.25 277)` | darker primary |
| `--primary-foreground` | `oklch(1 0 0)` | `--primary-foreground` |
| `--accent` | `oklch(0.95 0.03 282)` | `--accent` |
| `--sidebar` / `--sidebar-border` | `oklch(0.975 …)` / `oklch(0.92 …)` | `--sidebar` / `--sidebar-border` |
| `--ss` / `--ss-bg` / `--ss-fg` (success) | `oklch(0.45 0.15 150)` / `oklch(0.94 0.05 150)` / `oklch(0.30 0.10 150)` | `--status-success` / `-bg` / `-foreground` |
| `--sw` / `--sw-bg` / `--sw-fg` (warning) | `oklch(0.46 0.13 75)` / `oklch(0.95 0.06 80)` / `oklch(0.34 0.11 70)` | `--status-warning` / `-bg` / `-foreground` |
| `--sc` / `--sc-bg` / `--sc-fg` (critical) | `oklch(0.48 0.22 25)` / `oklch(0.95 0.05 25)` / `oklch(0.34 0.15 25)` | `--status-critical` / `-bg` / `-foreground` |

**Hero-only accent dots** (used inside the hero's translucent tiles, NOT the same as `--ss/--sw/--sc`): green `oklch(0.82 0.16 150)`, amber `oklch(0.85 0.14 80)`, grey `oklch(0.8 0.04 277)`, red `oklch(0.78 0.2 25)`.

**Keyframes** (line 20-23): `hsPulse` (presence dot), `hsSheet` (modal rise: `translateY(10px) scale(.99)` → `0 / 1`), `hsPop` (success check scale .8→1), `hsFade` (panel fade `translateY(4px)`→0).

**Font:** Instrument Sans (400/500/600/700). Root `font-size:14px`.

**Editable data-props** (line 789): `siteName` (text, default `"Maple House"`), `orgName` (text, default `"Kōwhai Care Group"`), `accent` (color, default `#5b4bdb`, options `#5b4bdb`/`#2a6fdb`/`#1f8a5b`/`#b0341f`). The `accent` prop overrides `--primary` at runtime (`setRoot`, line 842). Org name uses a macron — `Kōwhai`.

**Sidebar (left rail, 248px, line 30-59)** — not part of the 6 requested sections but present. Brand: shield-check medallion + "Health & Safety" / "WorkSafe NZ · HSWA 2015". Nav groups + items:
- **Overview:** Dashboard (active), Events & incidents, Corrective actions.
- **Registers:** Risk assessments, Hazardous substances, Emergency drills, First aid & restraints, Lone workers.
- **Governance:** Board reports, WorkSafe register.
- Footer user chip: avatar `SR` (cyan `oklch(0.55 0.13 200)`), "Sarah Reid" / "H&S Advisor · Manager".

**Top header bar (line 63-72):** breadcrumb "Health & Safety › **Command centre**"; right = a single bell icon button with a critical-tone notification dot.

**Page container:** `max-width:1280px`, centered, `padding:24px 28px 72px`, `gap:20px` between sections.

All worklist rows carry `data-tag`, `data-title`, `data-meta`, and `data-rows` (a JSON array-of-pairs) — these feed both the detail modal and the context menu. They are reproduced verbatim in §5.

---

## 1. HERO (command centre)

Full-width rounded (20px) gradient banner, white text. Gradient (line 77): `linear-gradient(135deg, oklch(0.511 0.262 277 / .94), oklch(0.48 0.255 280), oklch(0.43 0.235 286))`. Shadow `0 24px 60px -28px oklch(0.511 0.262 277 / .6)`. Three decorative translucent circles. Inner padding `24px 28px`, vertical `gap:18px`.

### 1.1 Eyebrow pill (line 87-90)
Animated green dot (`hsPulse`) + text:
> `Safety system · synced · 16 Jun, 9:42am`

Uppercase, letter-spacing `.07em`, on `oklch(1 0 0 / .12)` pill.

### 1.2 Action row (right, line 91-99)
Exactly **two** buttons (there is NO separate quickActions icon strip in the prototype — HANDOFF lists `quickActions` only as an optional `PageHero` capability):
1. **`＋ Report`** — white pill, primary-600 text, `+` (plus) icon. `onClick → openReport`. Exact label: `Report`.
2. **`Export board summary ▾`** — translucent (`oklch(1 0 0 / .12)`), white text, download icon + chevron-down. `onClick → noop`. Exact label: `Export board summary`.

### 1.3 Title block (line 103-122)
- 84px circular medallion, shield-check icon (4px translucent ring).
- `h1` (29px/700): **`Health & Safety command centre`**
- Meta line (line 109-112): **underlined** `{{ siteName }}` (default "Maple House") then ` · {{ orgName }} · 4 sites · PCBU duty-holder view`. Renders as:
  > **Maple House** · Kōwhai Care Group · 4 sites · PCBU duty-holder view

### 1.4 Compliance badges (5, line 114-120)
Pill chips, icon + label (tone via translucent bg/border, never colour alone):

| # | Exact label | Tone | Icon |
|---|---|---|---|
| 1 | `WorkSafe notifiable · 0 awaiting` | success (green) | check |
| 2 | `Ngā Paerewa NZS 8134:2021 · Certified` | success (green) | shield |
| 3 | `Hazardous substances · 2 SDS expiring` | **warning (amber)** | warning-triangle |
| 4 | `Fire · Drills current` | success (green) | flame |
| 5 | `First aid · Cover OK` | success (green) | first-aid kit |

### 1.5 Stat tiles — two clusters (line 124-178)
Two labelled translucent clusters. Each tile is a `<button>` carrying a `data-target` (its register destination) and a tone dot. Tile value 25px/700; caption `oklch(1 0 0 / .6)`.

**Cluster A — `Lagging · outcomes`** (chart-down icon):

| Label | Value | Caption | Dot colour | data-target |
|---|---|---|---|---|
| `Incidents` | `3` | `30 days · 1 high` | amber `oklch(0.85 0.14 80)` | `incidents` |
| `LTIFR` | `4.2` | `per M hrs` | amber | `ltifr` |
| `TRIFR` | `9.8` | `per M hrs` | grey `oklch(0.8 0.04 277)` | `trifr` |
| `Days LTI-free` | `47` | `last 30 Apr` | green `oklch(0.82 0.16 150)` | `lti` |

**Cluster B — `Leading · proactive`** (clock icon):

| Label | Value | Caption | Dot colour | data-target |
|---|---|---|---|---|
| `Near-miss` | `3.1×` | `: incident` | green | `nearmiss` |
| `Actions on time` | `86%` | `30-day` | amber | `ca` |
| `Train / audit` | `92%` | `compliance` | green | `training` |
| `Open hazards` | `5` | `2 high risk` | amber | `hazards` |

All tiles `onClick → noop` in the prototype (production: jump to the register named by `data-target`).

### 1.6 Footer band (line 180-211) — top-bordered, two rows
**Row 1 left — Period control** (label `Period`, then 4 pills from `periodItems`, line 935-940):

| key | Exact label |
|---|---|
| `week` | `This week` |
| `30d` | `30 days` |
| `quarter` | `Quarter` |
| `custom` | `Custom range` |

Active pill = `rgba(255,255,255,.22)` bg + `.4` border; inactive = `.09`/`.18`. `onClick → pickPeriod` (reads `data-period`). **Default `week`.** This is a *range* control, not a day-stepper.

**Row 1 right — Site filter + Role lens:**
- Site filter button: building icon + label **`All sites`** + chevron. `onClick → noop`.
- Role lens toggle (label `Lens`, 3 segmented buttons from `roleItems`, line 942-946):

| key | Exact label |
|---|---|
| `governance` | `Governance` |
| `manager` | `Manager` |
| `frontline` | `Frontline` |

Active = white bg (`rgba(255,255,255,.95)`) + primary-600 text; inactive = transparent + `rgba(255,255,255,.85)`. `onClick → pickRole`. **Default `manager`** (state, line 834).

**Row 2 — "this week" summary strip** (line 199-209). Label `This week`, then 5 metrics each with a tone dot, separated by faint `·`:

| Metric text | Dot |
|---|---|
| `3 incidents` | amber `oklch(0.85 0.14 80)` |
| `1 WorkSafe-notifiable` | **red `oklch(0.78 0.2 25)`** |
| `5 hazards open` | amber |
| `2 drills due` | amber |
| `lone-workers all checked in` | green `oklch(0.82 0.16 150)` |

---

## 2. TABS + ROLE LENS

### 2.1 Tab strip (line 216-231)
`role="tablist"`, rounded card, 4 tabs. Each tab = icon chip + label (+ optional count badge). Tone per tab (`TONES`, line 921): Overview=`--primary`, Leading=`--ss` (success), Lagging=`--sc` (critical), Compliance=`--primary`. Active tab tints btn `color-mix(in oklch, {tone} 12%, transparent)` and fills the chip with the tone.

| Order | Label | Icon | Count badge |
|---|---|---|---|
| 1 | `Overview` | grid/dashboard | — |
| 2 | `Leading` | trending-up | — |
| 3 | `Lagging` | chart-down | **`3`** (muted pill) |
| 4 | `Compliance` | shield-check | **`2`** (muted pill) |

`onClick → pickTab` (reads `data-tab`). **Default `overview`.** Switching is client-side state.

### 2.2 Role-lens banner (line 234-239) — dashed banner under the tabs, search icon, one of three texts:

- **Governance** (`roleGov`):
  > **Governance lens** — board-level posture prioritised: LTIFR / TRIFR trend, notifiable-event status, certification & compliance %. Operational worklists are de-emphasised.
- **Manager** (`roleMgr`, default):
  > **Manager lens** — the incident & investigation pipeline and overdue corrective actions are surfaced first, then trends and registers.
- **Frontline** (`roleFront`):
  > **Frontline lens** — active hazard alerts, lone-worker check-ins and the quick "Report" launcher are prioritised for shift-level safety.

> In the prototype the lens only swaps this banner text (it does NOT re-order the body). HANDOFF G3 notes production should also re-weight/scope server-side.

### 2.3 Tab body contents (top → bottom, exact section headers)

**OVERVIEW** (`tabOverview`, line 244-360) — default. Two-up grids then a full-width chart:
1. **`Overdue corrective actions`** card. Sub: `3 past due · right-click a row for actions`. Critical clock icon. Link: `View register →`. 3 rows (see §5).
2. **`Site safety league`** card. Sub: `Incidents · open hazards (30d)`. Building icon. 4 site bars (see §3.8).
3. **`WorkSafe-notifiable events`** card. Sub: `HSWA 2015 · notified / awaiting`. Link: `Register →`. 2 rows.
4. **`Expiring soon`** card. Sub: `Risk assessments · SDS · drills · competencies`. Calendar icon. 4 rows.
5. **`Incident & near-miss trend`** full-width chart (mini). Legend: Incidents / TRIFR / LTIFR. Month axis Jul→May (see §3.1).

**LEADING** (`tabLeading`, line 363-413):
1. 4 KPI cards (left-border accent), in order: **`Near-miss : incident`** `3.1×` (`target ≥ 3 — strong reporting culture`, ss border) · **`Actions closed on time`** `86%` (`30-day · target ≥ 90%`, sw border) · **`Training & audit`** `92%` (`compliance across 4 sites`, ss) · **`Worker participation`** `78%` (`HSR engagement · 2 committees`, ss).
2. Two-up: **`Near-miss : incident ratio`** donut+legend · **`Hazard burn-down`** line.
3. Three-up (`1fr 1fr 1.4fr`): **`Drill compliance`** gauge `86%` · **`Training compliance`** gauge `92%` · **`Open hazards`** worklist (link `Register →`, 3 rows).

**LAGGING** (`tabLagging`, line 416-475):
1. 4 KPI cards: **`Incidents (30d)`** `3` (`1 high-severity`, sw) · **`LTIFR`** `4.2` (`lost-time / million hrs`, sw) · **`TRIFR`** `9.8` (`total recordable / M hrs`, default border) · **`Days LTI-free`** `47` (`last lost-time 30 Apr`, ss).
2. Full-width **`Incident trend with LTIFR & TRIFR`** chart (12-month, dashed gridlines). Same legend.
3. Two-up: **`Severity breakdown (30d)`** donut · **`Incidents by category`** horizontal bars.
4. Full-width **`Open investigations`** card (warning search icon, link `View all →`, 2 rows).

**COMPLIANCE** (`tabCompliance`, line 478-509):
1. 5 status cards (top-border accent), each = icon chip + title + status line + sub:
   - `WorkSafe notifiable` (ss) — `0 awaiting notification` / `HSWA 2015 · records kept ≥ 5 years`
   - `Ngā Paerewa` (ss) — `Certified` / `NZS 8134:2021 · next audit Mar 2027`
   - `Hazardous substances` (sw) — `2 SDS expiring` / `Hazardous Substances Regs 2017`
   - `Fire safety` (ss) — `Drills current` / `1 drill due in 9 days (Rata)`
   - `First-aid cover` (ss) — `Cover OK` / `Certified first-aiders on every shift`
2. **`Expiring registers`** card. Sub: `Risk assessments · SDS · drills · competencies`. 4 rows in a 2-col grid (condensed copies of the overview rows).
3. **`Governance & board exports`** card. Sub: `One-click reports for the board & WorkSafe`. 4 export tile-buttons (see §6).

---

## 3. CHARTS (exact data + appearance)

All charts are inline SVG (no chart lib). Colours below are the literal `var(--…)` used in the markup. Bar/line geometry is given as drawn so values can be reverse-derived; the **legend/figure copy is the load-bearing data**.

### 3.1 Incident & near-miss trend — Overview (mini, line 349-357) and Lagging (line 433-443)
- Type: 12 monthly **bars** (incidents) + 2 **polylines** (TRIFR, LTIFR).
- **Bar fill:** `color-mix(in oklch, var(--primary) 70%, transparent)` (Overview) / `65%` (Lagging).
- **TRIFR line:** `var(--sc)` (critical), `stroke-width:2.5`, round caps/joins.
- **LTIFR line:** `var(--sw)` (warning), `stroke-width:2.5`.
- **Legend (both):** ▪ `Incidents` (primary square) · ▬ `TRIFR` (sc bar) · ▬ `LTIFR` (sw bar).
- **Overview month axis** (6 labels): `Jul · Sep · Nov · Jan · Mar · May`.
- **Lagging month axis** (12 labels): `Jul · Aug · Sep · Oct · Nov · Dec · Jan · Feb · Mar · Apr · May · Jun`.
- Lagging adds 2 dashed gridlines (`stroke-dasharray="3 4"`) at y=120 and y=60; baseline solid at y=180.
- **Bar heights (Lagging, viewBox 0 0 560 200, baseline y=180):** 96, 64, 80, 48, 112, 80, 64, 96, 48, 80, 64, 48 (px tall). Overview (viewBox …170, baseline 156): 96, 64, 80, 48, 112, 80, 64, 96, 48, 80, 64, 48.
- **TRIFR polyline points (Lagging):** `44,72 88,70 132,74 176,66 220,76 264,70 308,80 352,72 396,82 440,78 484,86 528,80`.
- **LTIFR polyline points (Lagging):** `44,128 88,130 132,124 176,132 220,126 264,134 308,138 352,130 396,140 440,136 484,142 528,138`.
- (Overview uses the same shape shifted 1px: TRIFR `43,70 …`, LTIFR `43,108 …`.)
- Lower y = higher value. Visually: incidents bounce around a baseline, peaking mid-series (the 112 bar); TRIFR sits high (~9-10), LTIFR mid (~4); both roughly flat with a slight upward drift to the right.

### 3.2 Near-miss : incident ratio donut — Leading (line 375-382)
- Donut: track `var(--muted)` width 14, arc `var(--ss)` width 14, round cap. `r=48`, circumference `301.6`, `stroke-dashoffset="73"` (≈ **76% of ring filled**), rotated -90°.
- Centre figure: **`3.1×`** (22px/700) with sub `ratio` (9px).
- Side legend: **`31`** near misses reported · **`10`** recordable incidents · then (ss-coloured): `A high ratio means hazards are caught before harm.`

### 3.3 Hazard burn-down line — Leading (line 386-391)
- Single `var(--primary)` polyline, width 2.5, with a 4px end-dot. viewBox `0 0 300 130`, baseline y=116.
- **Points:** `14,30 60,44 106,40 152,62 198,70 244,90 286,96` (descending = burning down).
- Caption: `14 open → 5 open over 6 weeks · closing faster than raised`.

### 3.4 Drill compliance gauge — Leading (line 397)
- Ring `r=48` width 12, arc `var(--ss)`, circumference 301.6, `stroke-dashoffset="42"` (≈ **86% filled**).
- Centre: **`86%`** (24px/700). Card title `Drill compliance`.

### 3.5 Training compliance gauge — Leading (line 401)
- Same geometry, `stroke-dashoffset="24"` (≈ **92% filled**), arc `var(--ss)`.
- Centre: **`92%`**. Card title `Training compliance`.

### 3.6 Severity breakdown donut — Lagging (line 449-454)
- 3 stacked arcs on `r=48` width 16 (no track), each `transform="rotate(-90 60 60)"`:
  - `var(--ss)` `stroke-dasharray="151 151"` (offset 0) — **Minor / moderate**.
  - `var(--sw)` `stroke-dasharray="90 212"` offset `-151` — **Serious**.
  - `var(--sc)` `stroke-dasharray="60 242"` offset `-241` — **Critical**.
- Legend (with counts): ▪ `Minor / moderate · 5` (ss) · ▪ `Serious · 3` (sw) · ▪ `Critical · 2` (sc).

### 3.7 Incidents by category — horizontal bars, Lagging (line 459-464)
Track `var(--muted)`, fill `var(--primary)`, height 7px.

| Label | Count | Bar width |
|---|---|---|
| `Slips, trips & falls` | `4` | 80% |
| `Manual handling` | `3` | 60% |
| `Behaviour / aggression` | `2` | 40% |
| `Sharps / exposure` | `1` | 20% |

### 3.8 Site safety league — horizontal bars, Overview (line 280-285)
Track `var(--muted)`, height 7px. Tone-coloured fill.

| Site | Counts (right label) | Bar width | Fill tone |
|---|---|---|---|
| `Maple House` | `2 inc · 3 haz` | 80% | `var(--sc)` (critical) |
| `Rata House` | `1 inc · 1 haz` | 38% | `var(--sw)` (warning) |
| `Kauri House` | `0 inc · 1 haz` | 18% | `var(--sw)` (warning) |
| `Kōwhai House` | `0 inc · 0 haz` | 6% | `var(--ss)` (success) |

> Chart count = **8** distinct charts (trend appears twice — mini on Overview, full on Lagging). Per HANDOFF these become recharts in production.

---

## 4. ALL 9 WIZARDS

**Wizard chrome (shared, line 575-782):** modal overlay `rgba(0,0,0,.5)`, sheet `width:min(94vw,980px)`, `hsSheet` rise. Left **248px rail** = shield medallion + `{{wizTitle}}` / `{{wizSub}}`, numbered step dots (→ ✓ when complete), then a **Completeness %** meter at the bottom. Main column = header `Step {n} of {total} · {step label}` + ✕, a 3px progress strip (`progressPct = (step+1)/total`), scroll body, footer (Back / Cancel / Continue). **Continue is disabled (`background:var(--muted)`, `cursor:not-allowed`) until `canContinue()` passes** (line 1024-1029). Stepper dots are clickable only up to the current step (`gotoStep`, line 856: `if (i <= step)`).

**Continue label** (line 1030): `Continue` on non-last steps; on the last step → `Submit report` (incident) / `Confirm check-in` (lone) / `Save & submit` (all others).

**Definitions** (`WIZ`, line 810-820 — exact step titles; `FIELDS`, line 821-830 — exact field lists for the generic wizards). Only the **incident** wizard has fully-built field UI; the other 8 render a **generic step body** that lists their fields (from `FIELDS`) as read-only rows with the note: *"Full field schema for this wizard is in the handoff. The **Report incident / near-miss** flow is built out end-to-end as the reference."* (line 640). For those, `canContinue()` always returns `true` (line 891) — i.e. **no per-field validation is wired in the prototype** for wizards 2-9; validation rules below are the HANDOFF's intended spec.

**Picker/input style helpers (line 900-917):** `TilePicker` = `tileSt` (active = primary border + 8% tint + inset ring); `ChipMulti`/single-chip = `chipSt` (active = primary border/tint/text); `Segmented` = `segSt` (active = white card + shadow).

### 4.1 Report incident / near-miss — REFERENCE FLOW (fully built)
`WIZ.incident`: title `Report incident / near-miss`, sub `Events register`. **6 steps.**

| # | Step title | Field | Type | Options (exact) | Validation (`canContinue`, line 889-897) |
|---|---|---|---|---|---|
| 1 | `Type & people` | `Event type` * | TilePicker (2-col, label+desc) | `TYPE_OPTS` (line 790): **Near miss** "No harm — but could have" · **Injury** "A person was hurt" · **Work-related illness** "Exposure / condition" · **Property / equipment** "Damage, no injury" · **Behaviour / aggression** "Challenging behaviour" · **Security / intruder** "Unauthorised access" | `type` set **AND** ≥1 person |
| 1 | | `People involved` * | ChipMulti | `WHO_OPTS` (line 798): `Staff member` · `Client / resident` · `Visitor` · `Contractor` · `Member of public` | |
| 2 | `What happened` | `Site / location` * | single-chip (`SITE_OPTS`) | `Maple House` · `Rata House` · `Kauri House` · `Kōwhai House` | `site` set **AND** `desc` non-empty |
| 2 | | `Date` | Field date (`<input type=date>`, default `2026-06-16`) | — | |
| 2 | | `Time` | Field time (`<input type=time>`, default `09:20`) | — | |
| 2 | | `Description` * | textarea (placeholder `Describe what happened, factually…`) | — | |
| 3 | `Severity & harm` | `Severity` * | Segmented | `SEV_OPTS` (line 806): `Minor` · `Moderate` · `Serious` · `Critical` | `severity` set **AND** `harm` set |
| 3 | | `Degree of harm` * | TilePicker (2-col) | `HARM_OPTS` (line 799): `No harm` (`none`) · `First aid only` (`first_aid`) · `Medical treatment` (`medical`) · `Hospitalisation` (`hospital`) · `Death` (`death`) | |
| 4 | `Immediate actions` | `Actions taken` * | ChipMulti | `ACTION_OPTS` (line 807): `Made area safe` · `First aid given` · `Called 111` · `Manager notified` · `Equipment isolated` · `Client reassured` | ≥1 action chip **OR** action free-text non-empty |
| 4 | | (free text) | textarea (placeholder `Any other immediate action…`) | — | |
| 4 | | `Create a corrective action` | toggle (default **ON** — `createCA:true`) | sub: "Assign follow-up to prevent recurrence" | |
| 4 | | `Link to client / staff (optional)` | Field text (placeholder `Search a client or staff member…`) | — | |
| 5 | `WorkSafe check` | *(auto-determination — see below)* | — | conditional panel | **If notifiable:** `Site preserved` checked **AND** `notifyWho` non-empty. **If not:** always valid |
| 6 | `Review & submit` | `ReviewCard` of all fields | read-only rows | — | always valid |

**WorkSafe notifiable determination** (`isNotifiable()`, line 888):
```
return ['hospital','death'].includes(rec.harm) || rec.severity === 'Critical';
```
i.e. flagged notifiable when **harm ∈ {Hospitalisation, Death}** OR **severity = Critical**. (HANDOFF G2: production should classify the 3 HSWA categories server-side.)

**Step-5 NOTIFIABLE panel (red, line 728-741):**
- Heading (sc): `Meets the HSWA notifiable threshold` (warning-triangle icon).
- Body: *"Based on the severity / harm recorded, this is a **WorkSafe notifiable event**. You must notify WorkSafe as soon as possible, **preserve the site** until told otherwise, and keep records for at least 5 years."*
- Required checkbox `Site preserved *` (sub: "Scene secured / not disturbed (except to make safe)"). `togglePreserve`.
- Required text `Notify WorkSafe — who is notifying? *` (placeholder `e.g. Sarah Reid (H&S Advisor)`).
- Optional text `WorkSafe reference (if already lodged)` (placeholder `WS-26-XXXX`).

**Step-5 NOT-NOTIFIABLE panel (green, line 744-750):**
- Heading (ss): `Does not meet the notifiable threshold` (check icon).
- Body: *"This event is recorded in the events register for your own records (kept ≥ 5 years). If severity or harm changes on investigation, the notifiable status is re-assessed automatically."*
- Tip: *"set **Harm** to "Hospitalisation" or "Death", or **Severity** to "Critical", on the previous step to see the notifiable path."*

**Review rows (step 6, `reviewRows`, line 973-984):** Type · People involved · Site / location · What happened · Severity · Harm · Immediate actions (chips + free text joined) · **WorkSafe notifiable** (`Yes — notification required` / `No — recorded only`) · Corrective action (`Will be created` / `—`) · Linked record. Empty values render `—`.

**Success pane (incident, line 988-992):**
- Title (notifiable): `Incident logged & flagged to WorkSafe`; (not): `Incident report submitted`.
- Blurb (notifiable): *"Recorded in the events register and flagged as a WorkSafe notifiable event — tracked to notification and investigation. Records are retained for at least 5 years (HSWA 2015)."*
- Blurb (not, CA on): *"Recorded in the events register. A corrective action was created and assigned for follow-up."* — (CA off): *"…No corrective action was requested."*

### 4.2 Log hazard + risk assessment
`WIZ.hazard`: title `Log hazard + risk assessment`, sub `Hazard register`. **4 steps.** Chooser tile subtitle: "L × C matrix · controls".

| # | Step title | Fields (`FIELDS.hazard`, line 822) |
|---|---|---|
| 1 | `Hazard` | `Hazard name` · `Category (slip/trip, manual handling, chemical…)` · `Site / location` · `Reported by` |
| 2 | `Likelihood × consequence` | `Likelihood (1–5)` · `Consequence (1–5)` · `Risk score = L × C` · `Risk band: Low / Medium / High / Extreme` |
| 3 | `Controls` | `Existing controls` · `Hierarchy of control: Eliminate → Substitute → Engineer → Admin → PPE` · `Additional controls required` · `Residual risk band` |
| 4 | `Review` | (review) |

### 4.3 Record first-aid treatment
`WIZ.firstaid`: title `Record first-aid treatment`, sub `First-aid register`. **4 steps.** Chooser subtitle: "Treatment + follow-up".

| # | Step title | Fields (`FIELDS.firstaid`, line 823) |
|---|---|---|
| 1 | `Person` | `Person (staff / client / visitor)` · `Date & time` · `Location` |
| 2 | `Injury & treatment` | `Injury / ailment` · `Treatment given` · `First-aider` |
| 3 | `Follow-up / notifiable` | `Follow-up required` · `Referred to GP / ED` · `HSWA notifiable-event check` |
| 4 | `Review` | (review) |

### 4.4 Log restraint event
`WIZ.restraint`: title `Log restraint event`, sub `Restraint register`. **5 steps.** Chooser subtitle: "Justification + debrief".

| # | Step title | Fields (`FIELDS.restraint`, line 824) |
|---|---|---|
| 1 | `Client` | `Client` · `Date & time` · `Site` |
| 2 | `Type & duration` | `Restraint type (physical / environmental / chemical)` · `Duration` · `Staff involved` |
| 3 | `Justification & debrief` | `Least-restrictive rationale` · `Debrief with client` · `Debrief with staff` |
| 4 | `Link to plan` | `Link to behaviour-support / restraint plan` |
| 5 | `Review` | (review) |

### 4.5 Record emergency drill
`WIZ.drill`: title `Record emergency drill`, sub `Drills register`. **4 steps.** Chooser subtitle: "Participants + findings".

| # | Step title | Fields (`FIELDS.drill`, line 825) |
|---|---|---|
| 1 | `Type & site` | `Drill type (fire / evacuation / lockdown)` · `Site` · `Date & time` |
| 2 | `Participants` | `Participants (staff / clients)` · `Roll-call complete` · `Evacuation time` |
| 3 | `Findings & actions` | `Findings` · `Actions raised & owners` |
| 4 | `Review` | (review) |

### 4.6 Injury → Return-to-work (ACC)
`WIZ.rtw`: title `Report injury → Return-to-work`, sub `Injuries / ACC`. **4 steps.** Chooser tile label `Injury → Return-to-work`, subtitle "ACC · modified duties".

| # | Step title | Fields (`FIELDS.rtw`, line 826) |
|---|---|---|
| 1 | `Injury` | `Injured worker` · `Injury & body part` · `ACC claim #` |
| 2 | `Capacity assessment` | `Capacity assessment` · `Medical certificate` · `Fit-for-work status` |
| 3 | `RTW plan & duties` | `RTW plan` · `Modified duties` · `Review date` |
| 4 | `Review` | (review) |

### 4.7 Add hazardous substance
`WIZ.substance`: title `Add hazardous substance`, sub `Hazardous substances`. **5 steps.** Chooser subtitle: "SDS · storage · controls".

| # | Step title | Fields (`FIELDS.substance`, line 827) |
|---|---|---|
| 1 | `Substance` | `Substance name` · `Supplier` · `HSNO / EPA classification` |
| 2 | `SDS upload` | `Safety Data Sheet (SDS) upload` · `SDS issue date` · `SDS review date` |
| 3 | `Storage location` | `Storage location` · `Quantity held` · `Segregation requirements` |
| 4 | `Exposure controls` | `Exposure controls (Hazardous Substances Regs 2017)` · `PPE required` · `Monitoring / health checks` |
| 5 | `Review` | (review) |

### 4.8 Lone-worker check-in
`WIZ.lone`: title `Lone-worker check-in`, sub `Lone workers`. **1 step** (single-surface). Chooser subtitle: "Quick check-in / escalate". Last-step Continue label = `Confirm check-in`. `genReview` is suppressed for `lone` (line 987).

| # | Step title | Fields (`FIELDS.lone`, line 828) |
|---|---|---|
| 1 | `Check-in / escalate` | `Worker & site` · `Check-in status` · `Next check-in due` · `Escalate to on-call` |

### 4.9 Worker participation / committee
`WIZ.participation`: title `Worker participation / committee`, sub `Worker participation`. **4 steps.** Chooser tile label `Worker participation`, subtitle "HSR / committee minutes".

| # | Step title | Fields (`FIELDS.participation`, line 829) |
|---|---|---|
| 1 | `Meeting` | `Meeting type (HSR / committee / consultation)` · `Date` · `Site / scope` |
| 2 | `Attendance` | `Attendees` · `HSRs present` · `Quorum met` |
| 3 | `Minutes & actions` | `Minutes` · `Actions raised & owners` · `Next meeting` |
| 4 | `Review` | (review) |

**Generic success (wizards 2-9, line 988):** title = `{title sans leading verb} saved` (e.g. "hazard + risk assessment saved", "first-aid treatment saved"). Blurb: *"Saved to the register. The dashboard will refresh in place (Inertia partial reload) — no full page navigation."*

**Step-count summary:** incident **6** · hazard **4** · firstaid **4** · restraint **5** · drill **4** · rtw **4** · substance **5** · lone **1** · participation **4**.

---

## 5. WORKLISTS + ROW INTERACTIONS

### 5.1 Report chooser modal (the `＋ Report` launcher, line 511-532)
Overlay; header icon (primary `+`) + title **`What would you like to report?`** + sub **`Every workflow stays on this page — no navigating away.`** + ✕. 9 tiles (grid, `minmax(220px,1fr)`); first tile (incident) is primary-highlighted (`border:1px solid var(--primary); background:var(--accent)`):

| `data-wiz` | Tile label | Tile subtitle |
|---|---|---|
| `incident` | `Incident / near-miss` | `Auto WorkSafe notifiable check` |
| `hazard` | `Hazard + risk assessment` | `L × C matrix · controls` |
| `firstaid` | `First-aid treatment` | `Treatment + follow-up` |
| `restraint` | `Restraint event` | `Justification + debrief` |
| `drill` | `Emergency drill` | `Participants + findings` |
| `rtw` | `Injury → Return-to-work` | `ACC · modified duties` |
| `substance` | `Hazardous substance` | `SDS · storage · controls` |
| `lone` | `Lone-worker check-in` | `Quick check-in / escalate` |
| `participation` | `Worker participation` | `HSR / committee minutes` |

`onClick → pickWiz` (reads `data-wiz`, calls `startWiz` → resets `rec` to `freshRec()`).

### 5.2 Worklist titles + "View register" link text (per card)

| Card | Tab | Link text |
|---|---|---|
| Overdue corrective actions | Overview | `View register →` |
| WorkSafe-notifiable events | Overview | `Register →` |
| Expiring soon | Overview | *(no link)* |
| Open hazards | Leading | `Register →` |
| Open investigations | Lagging | `View all →` |
| Site safety league | Overview | *(no link)* |

### 5.3 Row anatomy + sample data
Every row: status pill (tone + label) + title + sub-line (+ owner avatar + due date where present). Full `data-rows` payloads (these are exactly what the detail modal + context menu show).

**Overdue corrective actions (3 rows, line 257-271):**
| Pill (tone) | Title | Sub-line | Owner avatar | Due | data-rows |
|---|---|---|---|---|---|
| `2d overdue` (critical) | `Replace worn stair tread — Maple House` | `From incident #2271 · slip hazard` | `JP` (blue) | `14 Jun` | Action: Replace worn stair tread · Site: Maple House · Owner: J. Patel · Raised from: Incident #2271 (slip) · Due: 14 Jun 2026 · Status: Overdue — 2 days · Priority: High |
| `5d overdue` (critical) | `Update lone-worker procedure` | `From risk-assessment review` | `SR` (cyan) | `11 Jun` | Action: Revise lone-worker check-in SOP · Site: All sites · Owner: S. Reid · Raised from: Risk assessment review · Due: 11 Jun 2026 · Status: Overdue — 5 days · Priority: High |
| `1d overdue` (**warning**) | `Service ceiling hoist — Rata House` | `From pre-use equipment check` | `MC` (pink) | `15 Jun` | Action: Service ceiling hoist (room 4) · Site: Rata House · Owner: M. Cole · Raised from: Pre-use equipment check · Due: 15 Jun 2026 · Status: Overdue — 1 day · Priority: Medium |

**WorkSafe-notifiable events (2 rows, line 300-307):**
| Pill (tone) | Title | Sub-line | data-rows |
|---|---|---|---|
| `Notified` (success) | `Fall from height — contractor` | `Ref WS-26-4471 · 14 Jun` | Event: Fall from height (≈2.4m) · Person: Contractor — roofing · Site: Rata House · Classification: Notifiable injury (HSWA s.24) · WorkSafe notified: 14 Jun 2026, 11:20 — ref WS-26-4471 · Site preserved: Yes · Investigation: Open · day 2 |
| `Awaiting` (critical) | `Hospitalisation >48h — resident` | `Notify WorkSafe — action required` | Event: Hospitalisation following fall · Person: Resident — Maple House · Site: Maple House · Classification: Notifiable injury — admitted >48h · WorkSafe notified: Awaiting — due within required timeframe · Site preserved: Yes · Investigation: Pending |

**Expiring soon (4 rows, line 319-334):**
| Pill (tone) | Title | Sub-line | data-tag |
|---|---|---|---|
| `Expired 2d` (critical) | `SDS: Chlorine tablets` | `Hazardous substances` | SDS |
| `6 days` (warning) | `Risk assessment: Moving & handling` | `Review due 22 Jun` | Risk assessment |
| `12 days` (warning) | `Competency: Restraint (3 staff)` | `Refresher required` | Competency |
| `9 days` (**muted/grey**) | `Fire drill — Rata House` | `Next due 25 Jun` | Drill |
(full data-rows include Framework/Location/dates/Status per line 319-331.)

**Open hazards (Leading, 3 rows, line 406-408):**
| Pill | Title | data-rows (L×C) |
|---|---|---|
| `High` (critical) | `Wet floor — entrance lobby` | Site: Maple House · L×C: 4 × 3 = 12 (High) · Controls: Mats, signage, mop roster · Status: Open — controls in place |
| `High` (critical) | `Cluttered fire egress — Rata` | Site: Rata House · L×C: 3 × 4 = 12 (High) · Controls: Clearance roster, weekly check · Status: Open |
| `Med` (warning) | `Faulty kettle — staff room` | Site: Kauri House · L×C: 2 × 3 = 6 (Medium) · Controls: Removed from use, PAT booked · Status: Open |

**Open investigations (Lagging, 2 rows, line 470-471):**
| Pill | Title | Sub-line | Owner | data-rows |
|---|---|---|---|---|
| `Day 4/10` (warning) | `Medication error — client harm` | `Maple House · linked CA-26-118` | `CL` | Site: Maple House · Lead: Clinical lead · Opened: 12 Jun 2026 · Target close: 22 Jun 2026 (day 4 of 10) · Linked CA: CA-26-118 |
| `Review` (accent/primary) | `Slip near wet area — Kauri House` | `Awaiting manager review` | `SR` | Site: Kauri House · Lead: H&S advisor · Opened: 9 Jun 2026 · Status: Awaiting review · Linked CA: CA-26-114 |

### 5.4 Right-click context menu (`ShiftContextMenu` idiom, line 535-546)
Triggered by `onContextMenu → openRowMenu` (line 875). Fixed-position card (`width:270px`, clamped to `left:min(x,1180)` / `top:min(y,560)`, `hsPop` animation). Header = tag chip (`{{ctx.tag}}`) + meta line (`{{ctx.meta}}`). Items, in exact order:

| Item | Icon | Colour | Action |
|---|---|---|---|
| `View detail` | eye | default | `ctxDetail` → opens HsDetailDialog |
| `View client` | user-round | default | `closeCtx` (stub) |
| `View staff` | users | default | `closeCtx` (stub) |
| `Open corrective action` | check-square | **primary** | `closeCtx` (stub) |
| *(divider)* | | | |
| `Export` | download | default | `closeCtx` (stub) |

> Confirms the requested set verbatim: **View detail · View client · View staff · Open corrective action · Export** (Export below a divider).

### 5.5 Detail modal (`HsDetailDialog`, line 548-572)
Triggered by `onClick → openDetail` (line 881) or via the context menu's `View detail`. Overlay; sheet `width:min(94vw,540px)`. Header = tag chip + `{{detail.title}}` + `{{detail.meta}}` + ✕. Body = the `detail.rows` (label/value pairs from `data-rows`) rendered as `ReviewRow`s (label left muted, value right bold, bottom-bordered). **Options action bar** (footer, muted bg, line 564-569):

| Button | Style |
|---|---|
| `Open corrective action` | primary (check-square icon) |
| `Edit` | outline |
| `Export` | outline |
| `Close` | ghost, right-aligned (`margin-left:auto`) → `closeDetail` |

All except Close are `noop` in the prototype.

---

## 6. EXACT MISC COPY

**Governance & board exports — 4 tiles (Compliance tab, line 500-505):** each = icon chip + title + sub.
| Title | Subtitle |
|---|---|
| `Board safety summary` | `PDF · quarterly` |
| `WorkSafe register` | `Notifiable events · CSV` |
| `Investigation outcomes` | `Closed investigations` |
| `CA traceability` | `Action → source audit` |
| `Risk-assessment register` | `All active assessments` |
> Note: 5 buttons total (the first `Board safety summary` is at line 500-501, then the 4 above) — header says **`Governance & board exports`** / `One-click reports for the board & WorkSafe`.

**Generic wizard body copy (non-incident, line 627-641):**
- Step header sub appends: `{wizSub} · complete this step to continue.`
- Fields panel header: `Fields on this step`.
- Review banner (last step, ss-tone): *"Ready to submit. On submit the record is saved to the register and the dashboard refreshes in place (Inertia partial reload) — never a full navigation."*
- Footer note: *"Full field schema for this wizard is in the handoff. The **Report incident / near-miss** flow is built out end-to-end as the reference — including the WorkSafe notifiable-event check."*

**Wizard footer buttons (line 770-774):** `Back` (only when `step > 0`), `Cancel`, `Continue` (label varies — see §4 intro).

**Success pane buttons (line 586-587):** `Record another` (outline, → `recordAnother` resets) · `Done` (primary, → `closeWiz`).

**Required-field marker:** red asterisk `*` (`color:var(--sc)`) next to required labels.

**Empty-state / no-data text:** none present — the prototype ships fully populated with the sample data above. (No "no incidents yet" style empty states exist to copy.)

**Modal/animation timings (HANDOFF §Interactions):** overlay fade ~150ms; sheet rise/scale ~220ms `cubic-bezier(.2,.8,.2,1)`; step fade/translate ~300ms; success check pop ~300ms. Transform/opacity only.

---

## Appendix — initial state & key handlers (`Component`, line 832-1058)
- **Initial state (line 834):** `role:'manager'`, `period:'week'`, `tab:'overview'`, `reportOpen:false`, `wiz:null`, `step:0`, `done:false`, `rec:freshRec()`, `ctx:null`, `detail:null`.
- **`freshRec()` (line 839-840):** `{ type:'', who:[], whoName:'', date:'2026-06-16', time:'09:20', site:'', desc:'', severity:'', harm:'', actions:[], actionText:'', createCA:true, linkClient:'', preserveSite:false, notifyWho:'', notifyRef:'' }`.
- **`completeness` (line 964-969):** generic = `step/steps.length`; incident = fraction of 8 booleans (type, who, site, desc, severity, harm, actions-or-text, notifiable-satisfied) — note it's **8 checks for a 6-step wizard** (severity+harm and the notifiable gate count separately).
- **`wizNext` (line 858-862):** on last step sets `done:true`; otherwise advances only if `canContinue()`.
- Z-index stack: detail/report `70`, wizard `75`, context-menu backdrop `79` / menu `80`.

### Surprises / deltas vs HANDOFF
1. **No `quickActions` icon strip** exists in the prototype hero — only `＋ Report` and `Export board summary ▾`. HANDOFF lists `quickActions` as an available `PageHero` slot, but the design doesn't populate it. Don't invent one unless asked.
2. **Only the incident wizard has real fields + validation.** Wizards 2-9 render a *generic placeholder body* listing their field names; `canContinue()` returns `true` for all non-incident steps (line 891). The per-step validation rules in the HANDOFF table (e.g. hazard "L×C", restraint "≥1") are **intended spec, not implemented** in the prototype — build them in React.
3. **Role lens is cosmetic in the prototype** — switching Governance/Manager/Frontline only swaps the dashed banner sentence; it does **not** re-order or re-weight the tab bodies. HANDOFF G3 expects real re-weighting server-side.
4. **The "Days LTI-free" hero caption is `last 30 Apr`** while the Lagging KPI card says `last lost-time 30 Apr` — same date, slightly different wording. Two trend charts also differ subtly (mini = 6 axis labels / 70% bar mix; full = 12 labels / 65% mix + dashed gridlines).
5. **Completeness meter uses 8 checks for the 6-step incident wizard** (line 967) — it can read ~88% on step 5 even before the WorkSafe gate, because severity & harm are two of the eight. Mirror this if pixel-matching the meter.
6. **`whoName` is in `freshRec` but unused** — vestigial; ignore.
7. Org name is **`Kōwhai Care Group`** (macron ō) and one site is **`Kōwhai House`** — preserve the macrons.
8. The notifiable row classification text references **`HSWA s.24`** and "admitted >48h" — specific NZ statutory phrasing worth keeping.
