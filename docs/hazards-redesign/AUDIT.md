

## ===== prototype-design =====

I've now read the entire prototype file (all 1321 lines). I have a complete understanding of all three surfaces, the detail modal, all workflow modals, and the client read-only panel. Let me produce the exhaustive spec.

# Hazards Module — Exhaustive Build Spec

Source of truth: `Hazards Module.dc.html` (hand-rolled React prototype). This documents WHAT to build, surface by surface. Recreate chrome with the app's real kits (hs-hero-kit, TabStrip, WizardShell, FileDropzone, ctx-menu, etc.). All copy below is quoted verbatim from the prototype unless marked.

---

## 0. Cross-cutting domain model & constants

These drive every surface. Build them as backend enums/config + a shared risk service.

### Reference number format
`ref(id)` => `HZ-2026-####` (e.g. `HZ-2026-0101`), id zero-padded to 4.

### Hazard types (`HAZARD_LABELS`)
`slip_trip_fall`→"Slip / trip / fall"; `hot_water_temperature`→"Hot water temperature"; `medication_storage_access`→"Medication storage access"; `fire_electrical`→"Fire / electrical"; `manual_handling`→"Manual handling"; `security_behaviour`→"Behavioural / security"; `outdoor_garden`→"Outdoor / gardening"; `cleaning_chemicals`→"Cleaning chemicals storage"; `bathroom_safety`→"Bathroom safety"; `security_access`→"Security / visitor access"; `office_ergonomics`→"Office ergonomics"; `emergency_exits`→"Emergency exits"; `equipment_guarding`→"Equipment guarding"; `ppe_availability`→"PPE availability"; `safety`→"General safety". Plus `other` (free-text via `custom_type`).

### Severity (`SEVERITIES`, `SEV_LABEL`, `SEV_TONE`)
`low`→"Low"(success); `medium`→"Medium"(warning); `high`→"High"(critical); `critical`→"Critical"(critical).

### Likelihood (`LIKELIHOODS`, `LIKELIHOOD_LABEL`)
`rare`→"Rare"; `unlikely`→"Unlikely"; `possible`→"Possible"; `likely`→"Likely"; `almost_certain`→"Almost certain".

### Risk rating (`RISK_LABEL`, `RISK_TONE`)
`low`→"Low"(success); `medium`→"Medium"(warning); `high`→"High"(critical); `extreme`→"Extreme"(critical).

### Risk matrix (`RISK_MATRIX`, computed via `riskOf(sev, lik)`)
Rows = severity, cols = likelihood:
| Severity ↓ / Likelihood → | rare | unlikely | possible | likely | almost_certain |
|---|---|---|---|---|---|
| **low** | low | low | medium | medium | high |
| **medium** | low | medium | medium | high | high |
| **high** | medium | medium | high | high | extreme |
| **critical** | high | high | extreme | extreme | extreme |

### Due-date policy (`dueDaysFor(risk)`)
extreme→1 day, high→7, medium→30, low→90. Used to pre-fill suggested due date.

### Status (`STATUS_META`) — lifecycle order: open → in_progress → mitigated → closed
- `open`→label "Open", tone info, icon alertTriangle
- `in_progress`→label "In progress", tone live, icon clock
- `mitigated`→label "Mitigated", tone primary, icon shieldCheck
- `closed`→label "Closed", tone success, icon checkCircle

### Hierarchy of controls (`CONTROL_LEVELS`, NZ HSWA 2015 order, numbered 1–6)
1. `elimination` "Elimination" — "Remove the hazard completely"
2. `substitution` "Substitution" — "Replace with something safer"
3. `isolation` "Isolation" — "Separate people from the hazard"
4. `engineering` "Engineering" — "Guards, barriers, physical controls"
5. `administrative` "Administrative" — "Procedures, training, signage"
6. `ppe` "PPE" — "Personal protective equipment"

### Corrective-action control types (Add-action dropdown — note: a SUBSET, no isolation)
`elimination`, `substitution`, `engineering`, `administrative`, `ppe`.

### Sites (`SITES`) & site types
8 seed sites. `SITE_TYPE_LABEL`: `house`→"House", `facility`→"Facility", `head_office`→"Head office". Seed: Tāwharau House (house), Aroha House (house), Manaaki Lodge (house), Kōtuku House (house), Rātā House (house), Whareora Respite (facility), Pukeko Day Facility (facility), Head Office — Newtown (head_office). Each has `suburb`.

### Staff (`STAFF`) — 7
Aroha Ngata (H&S Lead), Mere Wikitera (Service Manager), James Tukariri (House Coordinator), Sophie Chen (Facilities Officer), Hemi Walker (Support Lead), Priya Naidu (Quality & Compliance), Tom Fletcher (Maintenance).

### Computed flag predicates (drive flags + hero counts)
- `isOverdue` = has due_date < today AND status in {open, in_progress}.
- `isDueSoon` = status in {open, in_progress} AND 0 ≤ (due − today) ≤ 7 days.
- `isUnassigned` = no assignee AND status not in {closed, mitigated}.
- `isCriticalOpen` = (risk_rating === extreme OR severity === critical) AND status not in {closed, mitigated}.

### Tones (for chips/dots — map to existing token tones)
success, warning, critical, info, live, primary, neutral. (Status-tokens already exist in app.)

### Hazard record shape (fields to persist — from `buildHazard`)
`id, reference_number, site_id, site_name, site_type, site_suburb, hazard_type, hazard_label, custom_type, severity, likelihood, risk_rating, description, immediate_action, immediate_applied(bool), status, location, witnesses, assigned_to, assigned_to_id, reported_by, due_date, created_at, closed_at, worksafe(bool), resolution_summary, resolution_evidence[], residual(rating), residual_severity, residual_likelihood, control_hierarchy[], photos[], documents[], actions[], history[]`. Corrective action shape: `{ref, title, type, assignee, status(open|in_progress|done), due, completed_by, completion_notes, completed_at}`. Evidence item shape: `{name, kind:'image'|'doc', thumb|size}`.

### WorkSafe auto-flag rule
On create, `worksafe` is set true when `risk_rating === 'extreme'`. (Seed data also has manual worksafe flags on some hot-water/fire/scald hazards.)

---

## SURFACE 1 — GLOBAL REGISTER (`Compliance → Hazards`, route `/compliance/hazards`)

Layout: hero, then TabStrip, then table card. Breadcrumb: `Compliance › Hazards`. Sidebar nav item "Hazards" (icon shieldAlert) carries a count badge = open + in_progress.

### 1A. Hero

**Ribbon (top of hero)** — a mini stage nav. Leading "H&S" button (layoutDashboard icon), then chevron-separated stages: `Report & respond` (shieldAlert), `Investigate` (clipboard), `Resolve` (wrench), `Analyse` (barChart). Current stage = **Resolve** (highlighted pill); others are buttons.

**Eyebrow / status pills (two):**
1. Green pulsing-dot pill: `Hazard register · synced {HH:MM}` (live time).
2. mapPin pill: `NZ · Ngā Paerewa NZS 8134:2021`.

**Medallion:** circular, shieldAlert icon (size 34), white translucent ring.

**H1:** `Homes & Sites Hazards`
**Description:** `Every physical and environmental hazard across our homes and facilities — logged, risk-rated against the WorkSafe matrix, driven through controls and closed through review.`

**"Board reports" popover** (button top-right of hero header: fileText icon, label "Board reports", chevronDown). Opens a 250px popover listing 5 reports (each fileText icon; clicking flashes "Generating: {name}"):
1. `Board safety summary`
2. `WorkSafe-notifiable register`
3. `Hazard risk register`
4. `Corrective-action traceability`
5. `Ngā Paerewa evidence pack`

**Two stat clusters** (each tile is a button; clicking sets the named tab). Tile = label + big number + caption + tone dot.

Cluster 1 — title `Live · open register` (icon activity):
| Tile label | Value source | Caption | Tone | Links to tab |
|---|---|---|---|---|
| Open | count status=open | "awaiting triage" | neutral | open |
| In progress | count status=in_progress | "controls underway" | live | in_progress |
| Overdue | count isOverdue | "past due date" / "all on time" (if 0) | critical | overdue |
| Critical open | count isCriticalOpen | "high / extreme risk" | critical | critical |

Cluster 2 — title `Needs attention` (icon alertTriangle):
| Tile label | Value source | Caption | Tone | Links to tab |
|---|---|---|---|---|
| Due ≤ 7d | count isDueSoon | "closing window" | warning | overdue |
| Unassigned | count isUnassigned | "needs an owner" / "all owned" (if 0) | warning | open |
| Mitigated | count status=mitigated | "awaiting closure" | primary | closed |
| Closed | count status=closed | "this period" | success | closed |

**Compliance badges (5)** — pill row. Each: icon + label, tone-coloured:
1. WorkSafe: icon = alertTriangle if worksafe>0 else checkCircle; tone warning if worksafe>0 else success; label `WorkSafe-notifiable · {N} awaiting` (N = count of worksafe AND status≠closed).
2. `Ngā Paerewa NZS 8134:2021 · Certified` — shieldCheck, success (static boolean).
3. `Hazardous Substances Regs 2017 · 2 SDS expiring` — alertTriangle, warning (static count "2").
4. `Fire · Drills current` — flame, success (static boolean).
5. `First aid · Cover OK` — heartPulse, success (static boolean).

### 1B. Hero FOOTER filter bar
Border-topped strip with these controls (left→right):
- **Period** label + 4 segmented pills: `This week` (week), `30 days` (30d, default active), `Quarter` (quarter), `Custom` (custom). (Visual filter; not wired to data filtering in prototype but build as period scope.)
- **Site** select — option per site (all 8 by name); "All" default.
- **Type** select — options: `Houses` (house), `Facilities` (facility), `Head office` (head_office); "All" default. (site_type)
- **Severity** select — Low/Medium/High/Critical; "All".
- **Risk** select — Low/Medium/High/Extreme; "All".
- **Assignee** select — option per staff member (7); "All".
- **Due** select — `Overdue` (overdue), `Due ≤ 7d` (due_soon); "All".
- **Search** input (right-aligned, search icon) placeholder `Search hazards…` — matches reference_number, hazard_label, description, site_name (case-insensitive).
- **Clear** button (x icon, label "Clear") — only shown when any filter active; resets all filters incl. q.

### 1C. Hero right-click context menu (`openHeroCtx`)
Header tag `REGISTER` (primary), meta `Homes & Sites Hazards`. Items in order:
1. `Log hazard` (plus, primary tone) → opens create wizard.
2. `Export CSV` → flash "Exported {N} hazards to CSV".
3. `Board reports` sub "governance pack" (fileText) → opens board popover.
4. — separator —
5. `Go to site register` (building) → switches to Site surface.

### 1D. TabStrip (`renderTabs`)
6 tabs, each icon + label + count badge. Order:
1. `All` (clipboard, primary) — count all in base set.
2. `Open` (alertTriangle, info) — status=open.
3. `In progress` (clock, live) — status=in_progress.
4. `Overdue` (flame, critical) — isOverdue.
5. `Critical` (shieldAlert, critical) — isCriticalOpen.
6. `Closed` (checkCircle, success) — status in {closed, mitigated}.

Counts come from base set (filters applied, tab NOT applied). Tab "closed" includes mitigated.

### 1E. Table card (`renderTableCard`)
Header: shieldAlert medallion + dynamic title + `· {N} shown`. Title by tab: All→"All hazards", open→"Open hazards", in_progress→"In progress", overdue→"Overdue hazards", critical→"Critical open", closed→"Closed & mitigated". Right side hint: mousePointer icon + `Right-click a row for the full lifecycle`. Empty state (`renderEmpty`): shieldAlert tile + "No hazards here" + "Nothing matches this tab and filters."

**Columns (7):** `Ref / When`, `Hazard`, `Site`, `Severity`, `Risk`, `Status`, `Flags`.

Cell contents:
- **Ref / When**: line 1 = relative created time (`fmtWhen`: "Today HH:MM" / "Yesterday HH:MM" / "Nd ago HH:MM" / "DD Mon HH:MM"), bold; line 2 = reference_number, muted.
- **Hazard**: risk-tone dot + hazard_label (bold) + description (1-line truncated, muted).
- **Site**: small square icon (home for house, building for facility/head_office) + site_name (bold) + site_type label (muted).
- **Severity**: chip, SEV_TONE + SEV_LABEL.
- **Risk**: chip, RISK_TONE + `{RISK_LABEL} risk`.
- **Status**: chip with icon, STATUS_META tone/label/icon.
- **Flags**: zero or more flag chips (or "—").

**Row whole-row click** → opens detail modal (Overview). Row is keyboard-focusable (Enter/Space opens).

### 1F. Flag badges (`renderRow`) — all possible flags, in render order
1. `Overdue` (critical, flame) — if isOverdue. **Else** `Due ≤7d` (warning, clock) — if isDueSoon. (mutually exclusive)
2. `Unassigned` (warning, userPlus) — if isUnassigned.
3. `WorkSafe` (critical, shieldAlert) — if worksafe AND status≠closed.
4. `Awaiting closure` (primary, shieldCheck) — if status=mitigated.
5. `{N} action` (info, listChecks) — if any corrective action not done; N = count of not-done actions.

### 1G. Row right-click context menu (`openRowCtx`)
Header tag = RISK_LABEL of the hazard (risk tone), meta = `{ref} · {hazard_label}`. Items in order, with status gating:
1. `View hazard` sub={ref} (primary) — always.
2. `Reassign`/`Assign` (userPlus) — if status≠closed (label "Reassign" if already assigned, else "Assign") → opens detail with assign pane.
3. `Start progress` sub "open → in progress" (play) — only if status=open.
4. `Mark mitigated` sub "in progress → mitigated" (shieldCheck) — only if status=in_progress.
5. `Add corrective action` (listChecks) — if status≠closed → opens actions section + add_action pane.
6. `Record review` (clipboard) — if status≠closed.
7. `Close hazard` (critical tone, checkCircle) — if status≠closed.
8. — separator —
9. `Copy link` (copy) → flash "Link copied to clipboard".
10. `Open full page` sub `/hazards/{id}` (externalLink) → flash deep link.

---

## DETAIL MODAL (`renderDetail`) — shared by all 3 surfaces

Full-screen overlay, centred panel `min(95vw,1000px) × min(90vh,780px)`. Left rail (250px) + main column. ESC closes. Backdrop click closes.

**Rail header:** shieldAlert square + reference_number + `{hazard_label} · {SEV_LABEL}`.

**Rail sections (5)** — each shows label + blurb + icon, with a numbered/checked step indicator (active highlighted, prior = done):
1. `Overview` — blurb "Hazard & origin" (fileText)
2. `Risk` — blurb `{RISK_LABEL} rating` (gauge)
3. `Corrective actions` — blurb "{N} logged" or "none" (listChecks)
4. `Evidence` — blurb "{N} file(s)" or "photos & docs" (camera). evCount = photos + documents + resolution_evidence.
5. `History` — blurb "Audit trail" (history)

**Rail footer (read-only only):** muted box, eye icon, `Read-only — opened from a client profile. Manage from the register.`

**Main header:** left = `Section {i} of {5}` (or "Workflow") · current section/pane title; right = x close button. Thin progress bar under header = (idx+1)/5.

**Footer bar (always):** LEFT = status summary chips: Severity chip, `{Risk} risk` chip, Status chip (icon), and if worksafe a `WorkSafe-notifiable` (critical, shieldAlert) chip. RIGHT = Options buttons (see gating below).

### Detail footer Options (gated)
**Read-only mode:** single button `Open in register` (primary, externalLink) → switches to global surface filtered to the hazard's site.

**Editable mode (`!ro`), when not inside a pane:**
- `Open full page` (ghost, externalLink) — always → flash deep link.
- `Reassign`/`Assign` (outline, userPlus) — if status≠closed.
- `Start progress` (outline, play) — if status=open.
- `Mark mitigated` (outline, shieldCheck) — if status=in_progress.
- `Add action` (outline, listChecks) — if status≠closed (also jumps to actions section).
- `Record review` (outline, clipboard) — if status≠closed.
- `Close hazard` (outline w/ critical border+text, checkCircle) — if status≠closed.

When a workflow pane is open, the footer Options are replaced by the pane's own Cancel/confirm buttons.

### Section 1 — Overview (`secOverview`)
In order:
1. **Lifecycle stepper** (`lifecycleStepper`): 4 nodes Open(alertTriangle) → In progress(clock) → Mitigated(shieldCheck) → Closed(checkCircle), connectors between. Reached nodes filled to their tone, current node solid, future nodes muted.
2. **WorkSafe banner** (only if worksafe): critical-bg box, shieldAlert; title `WorkSafe-notifiable hazard`; body `This hazard meets the threshold for notification to WorkSafe NZ (HSWA 2015). Preserve the scene where required and keep records for at least 5 years.`
3. **Description** block (SectionLabel "Description", fileText) + paragraph.
4. **Immediate action taken** (if present): SectionLabel (activity) + paragraph.
5. **Photos (N)** (if any): SectionLabel (camera) + evidence gallery (read-only thumbnails).
6. **Meta grid** (2-col, in muted card): `Site` ({site_name} · {type}, home), `Location` (mapPin), `Hazard type` (shieldAlert), `Reported by` (user), `Assigned to` (userPlus — avatar+name OR "Unassigned" in warning), `Logged` (date, calendar), `Due` (date + " · overdue" in critical if overdue, clock), `Witnesses` (if present, user).
7. **Resolution** block (only if status=closed): success-bordered box, checkCircle, label "Resolution", resolution_summary text, resolution evidence gallery (if any), `Closed {date}` footnote.

### Section 2 — Risk (`secRisk`)
1. **Risk stat tiles** (row): `Severity` (SEV value+tone), `Likelihood` (value, neutral), `Risk rating` (value+tone), and if residual exists `Residual` (value+tone). Each = label + dot + value.
2. **WorkSafe risk matrix** (SectionLabel gauge, sub-copy `Severity × Likelihood. The current rating is highlighted.`): `renderRiskMatrix` — 5×4 grid, **rows = severity top→bottom: critical, high, medium, low**; **cols = likelihood: rare, unlikely, possible, likely, almost_certain**. Each cell shows the computed RISK_LABEL, coloured by risk tone. The current sev×lik cell has a 2px solid border highlight. Read-only here (no onPick).
3. **Controls applied** (if control_hierarchy set): SectionLabel (shieldCheck) + primary chips with check icon, one per control level label.
4. **Residual risk box** (if residual + residual_severity): bordered card, gauge, `Residual risk after controls: {chip}` + `({SEV} × {Likelihood})`.
5. **H&S officer banner** (if risk_rating high or extreme): warning box, alertTriangle, `H&S Officer assignment required.` + `High and extreme-risk hazards must be owned by a nominated H&S officer and resolved within {1 day | 7 days}.`

### Section 3 — Corrective actions (`secActions`)
- Header: SectionLabel "Corrective actions" (listChecks) + `Add action` button (primary, plus) — shown only if not read-only AND status≠closed.
- **Action list** — each action card: status icon (checkCircle if done else wrench) tinted by action tone (done=success, in_progress=live, open=info); title (bold) + status chip (`Completed`/`In progress`/`Open`); meta line `{ref} · {Title-cased type}`, owner (user icon + name), `Due {DD Mon}` (calendar). If done with completion_notes: success box `Completed by {name}: {notes}`. If not read-only AND status≠closed AND action not done: `Mark complete` button (outline, checkCircle) → opens complete_action pane.
- Empty state: dashed box "No corrective actions yet."

### Section 4 — Evidence (`secEvidence`)
canAdd = not read-only AND status≠closed.
1. **Photos**: SectionLabel (camera). If canAdd → uploader (title "Add hazard photos", hint "JPG, PNG"). Else → gallery, or empty "No photos on this hazard."
2. **Supporting documents**: SectionLabel (fileText). If canAdd → uploader (title "Add documents", hint "PDF, DOC — reports, SDS, certificates"). Else → gallery or "No documents on this hazard."
3. **Resolution evidence** (only if present): SectionLabel (checkCircle), sub "Captured at closure.", gallery.

Uploader supports drag-drop + click; images render as 72px thumbnails with remove (x) button; docs render as file chips with name + size + remove.

### Section 5 — History (`secHistory`)
SectionLabel "Audit trail" (history). Vertical timeline, **newest first** (reversed). Each entry: tone-tinted round icon + connector line; title (bold) + relative time (`fmtWhen`); optional note (muted); `by {actor}` line.

History entry types generated (`buildHazard` + mutations): "Hazard reported" (shieldAlert, neutral); "Immediate action recorded" (activity, info); "Assigned to {name}"/"Reassigned to {name}" (userPlus, info); "Moved to In progress" (play, live); "Marked Mitigated" (shieldCheck, primary, note lists controls + residual); "Corrective action added" (listChecks, warning); "Corrective action completed" (checkCircle, success); "Review recorded" (clipboard, info); "Photos added"/"Documents added" (camera, info); "Hazard closed" (checkCircle, success).

---

## WORKFLOW MODALS / PANES

All panes render inside the detail modal main column (`paneWrap`: StepHead icon+title+blurb, fields, right-aligned buttons). Each has a `Cancel` button (returns to section) + a confirm button. Required fields marked `*`.

### W1. Log hazard — CREATE WIZARD (`renderCreate`)
Standalone modal `min(95vw,940px) × min(90vh,720px)`, left rail with 5 steps + live risk-rating chip in rail footer (once severity+likelihood set). Header `Step {n} of 5 · {label}`, progress bar, footer Back / Continue (→ "Log hazard" on last). Advance is gated (`createCanAdvance`) with block messages.

Rail steps: `Site & type` (Where & what, home) · `Risk rating` (Severity × likelihood, gauge) · `Detail` (Description & action, fileText) · `Assign & due` (Owner & date, userPlus) · `Review` (Confirm & log, checkCircle).

**Step 1 — Site & type** (StepHead "Where is the hazard?" / "Hazards are recorded against a home or facility.")
- `Site` select* (all sites, `Name — Type`). Placeholder "Select a site".
- After a site is chosen: **recommended quick-add chips** (`SiteRecommendedHazards` by site type) — heading `Common hazards for a {type} — tap to quick-add`. Each chip = label + hint; clicking sets hazard_type (active chip = primary border + check). Plus an `Other / not listed` chip (hint "Type your own hazard type.").
  - **House** recs: Slip / trip hazards ("Loose mats, wet floors, cluttered walkways."), Hot water temperature ("Scald risk above 50°C in bathrooms or kitchens."), Medication storage access ("Unlocked cabinet, key control, or access concern."), Fire / electrical ("Overloaded sockets, damaged leads, expired alarms."), Manual handling ("Transfers, lifting, equipment, or room layout risk."), Behavioural / security ("Entry, privacy, aggression, lone-worker concerns."), Outdoor / gardening hazards ("Uneven paths, tools, weeds, poor lighting."), Cleaning chemicals storage ("Storage, labels, and locked access."), Bathroom safety ("Grab rails, non-slip surfaces, shower access.").
  - **Facility** recs: Slip / trip hazards, Fire / electrical, Manual handling ("Transfers, lifting, equipment, room layout."), Equipment guarding ("Missing guards, lockout gaps, damaged controls."), Cleaning chemicals storage, PPE availability ("Missing, expired, or unsuitable PPE.").
  - **Head office** recs: Slip / trip hazards, Fire / electrical, Security / visitor access ("Reception, contractor access, privacy, lone-worker."), Office ergonomics ("Workstation setup, lighting, repetitive strain."), Emergency exits ("Blocked exits, signage, assembly points.").
- If `Other` selected: `Describe the hazard type` text input* (placeholder "e.g. Window restrictor missing on first floor").
- Block msg: "Choose a site and a hazard type".

**Step 2 — Risk rating** (StepHead "Rate the risk" / "Severity × likelihood gives the risk rating from the WorkSafe matrix.")
- `Severity` select* (placeholder "How bad if it happens?") + `Likelihood` select* (placeholder "How likely?") in 2-col grid.
- **Clickable risk matrix** (`renderRiskMatrix` with onPick): same 5×4 grid; clicking a cell sets BOTH severity and likelihood; selected cell highlighted. Heading `Risk matrix — tap a cell to set both`.
- Live result card (once both set): big `{Risk} risk` + `Suggested resolution within {N} day(s)` (+ ` · H&S officer assignment required` if high/extreme).
- Setting severity+likelihood auto-fills suggested due date (if due empty).
- Block msg: "Select a severity and likelihood".

**Step 3 — Detail** (StepHead "Describe the hazard" / "What is the hazard, where is it, and what was done straight away?")
- `Description` textarea* (placeholder "Describe the hazard, where it is, and who is exposed.").
- `Location` text input (placeholder "e.g. Main bathroom, rear corridor, garden path").
- `Photos` uploader (title "Add photos of the hazard", hint "JPG, PNG — helps the assigned owner").
- `Immediate action taken` textarea (placeholder "What did you do right away to make it safe?").
- Checkbox: `Immediate action has been applied and the area is safe`.
- `Witnesses` textarea (placeholder "Names and contact details of any witnesses (optional).").
- Block msg: "Add a description".

**Step 4 — Assign & due** (StepHead "Assign an owner" / blurb: if high/extreme risk → "This is a {risk}-risk hazard — an H&S officer must own it."; else "Optional, but assigning an owner speeds resolution.")
- `Owner` select (staff `Name — Role`).
- `Resolution due date` date input.
- Info note: "Suggested due date is pre-filled from the {risk} risk rating ({N} day(s)). Adjust if needed."

**Step 5 — Review & log** (StepHead "Review & log" / "Confirm the details. The hazard is created with status Open.")
- 4 summary cards each with an `Edit` link jumping to that step: **Site & type** (Site, Type), **Risk** (Severity, Likelihood, Risk rating chip), **Detail** (description paragraph, Location, Photos count, Witnesses, "Immediate: …"), **Owner & due** (Owner or "Unassigned", Due).
- Submit (`Log hazard`): creates hazard, status=open, worksafe = (risk===extreme), reporter "You", builds history. Flash `Hazard {ref} logged at {site}`.

### W2. Assign (`assign` pane) — title "Assign hazard"
Blurb: "Nominate an owner and confirm the resolution due date. High and extreme-risk hazards must have an owner." Fields: `Owner` select* (staff `Name — Role`, placeholder "Select a staff member"), `Due date` date. Buttons: Cancel / `Save assignment`. Validation: owner required ("Select an assignee"). Logs "Assigned to"/"Reassigned to".

### W3. Start progress (`start` pane) — title "Start progress"
Blurb: "Move this hazard from Open to In progress to show controls are being implemented." Info note: "The status changes to In progress and is recorded in the audit trail with your note." Field: `Note` textarea (placeholder "What controls are being put in place?"). Buttons: Cancel / `Move to In progress`. Sets status=in_progress.

### W4. Mark mitigated (`mitigate` pane) — title "Mark mitigated"
Blurb: "Record the controls applied (hierarchy of controls) and the residual risk after them. The hazard moves to Mitigated, awaiting a closure review." Fields:
- `Controls applied — hierarchy of controls`* — **multiselect control picker** (`controlPicker`): all 6 CONTROL_LEVELS as toggle rows (number badge → check when on, label + description, primary highlight when selected).
- `Residual severity` select* + `Residual likelihood` select* (2-col).
- Live residual card: once both set, `Residual risk after controls: {RISK_LABEL}` tinted by residual tone; else info note "Set residual severity and likelihood to calculate the residual risk after controls."
Buttons: Cancel / `Mark mitigated`. Validation: ≥1 control ("Select at least one control from the hierarchy") and both residual fields ("Set the residual severity and likelihood"). Sets status=mitigated + residual + control_hierarchy.

### W5. Add corrective action (`add_action` pane) — title "Add corrective action"
Blurb: "Log a corrective action against this hazard. It becomes trackable in the corrective-action register." Fields: `Action title` text input* (placeholder "e.g. Replace threshold strip and re-seal vinyl"), `Control type` select (Elimination/Substitution/Engineering/Administrative/PPE — default engineering) + `Due date` date (2-col), `Owner` select (staff names, placeholder "Assign to"). Buttons: Cancel / `Add action`. New ref auto-generated `CA-###`. Validation: title required.

### W6. Complete action (`complete_action` pane) — title "Complete corrective action"
Opened from a specific action's "Mark complete". Blurb: `{action title} — record completion notes and mark it done.` Info note: "Marks this corrective action completed, recorded against you in the audit trail. Once every action is complete the hazard can be closed." Field: `Completion notes` textarea (placeholder "What was done to complete this action?"). Buttons: Cancel / `Mark complete`. Sets action.status=done, completed_by="You", completed_at=now, returns to actions section.

### W7. Record review (`review` pane) — title "Record review"
Blurb: "Capture a review note — a periodic check, control-effectiveness review, or sign-off. It is logged to the audit trail." Field: `Review notes` textarea* (placeholder "What was reviewed and what did you find?"). Buttons: Cancel / `Record review`. Validation: notes required. Appends history only (no status change), returns to history section.

### W8. Close (`close` pane) — title "Close hazard"
Blurb: "Closing requires a resolution summary. Outstanding corrective actions should be completed first." Contents:
- **Close gate** card with two gate rows: `Hazard reviewed` (always ok=true) and corrective-actions gate — ok if every action done → `All corrective actions completed`, else `{N} corrective action(s) still open` (warning).
- If gate not ok: warning note "You can still close this hazard, but outstanding corrective actions remain open in the register." (close is allowed regardless).
- `Resolution summary` textarea* (placeholder "How was the hazard resolved? What controls are now in place and verified?").
- `Resolution evidence` uploader (title "Attach photos or documents", hint "Proof the hazard is resolved (optional)").
Buttons: Cancel / `Close hazard` (primary, critical background). Validation: summary required ("A resolution summary is required"). Sets status=closed, closed_at=now, stores summary + resolution_evidence.

---

## SURFACE 2 — SITE SURFACE (`Sites → {site} → Hazards` tab)

Breadcrumb: `Sites › {site name} › Hazards`. Scoped to one site (`siteCtxId`, default Whareora Respite).

**Site header card:** home medallion + site name (h1) + site-type chip (primary); suburb line (mapPin); `Open in register` button (outline, externalLink) → global surface filtered to this site.

**Site profile tabs** (the Hazards tab is the active one — others flash a placeholder): `Overview`, `Checklists`, `Inspections`, `Hazards` (active), `Documents`.

**Embedded hazards section** ("Hazards at this home"):
- Header: shieldAlert medallion + "Hazards at this home" + `· {N} open`; sub-copy `Same register chrome, scoped to {site}. Right-click or click any row.`; right buttons: `View all` (outline → global filtered to site) and `Log hazard` (primary, plus → create wizard pre-set to this site).
- **Table** — columns (6): `Ref`, `Hazard`, `Severity`, `Risk`, `Status`, `Flags`. Shows open+in_progress rows (or first 5 if none open).
- Site row cells (`renderSiteRow`): Ref = reference_number (bold) + created day (`fmtDay`, muted); Hazard = risk dot + label + truncated description; Severity chip; Risk chip (`{RISK_LABEL}` — no " risk" suffix here); Status chip; Flags.
- **Site row flags** (subset): Overdue OR Due ≤7d; Unassigned; WorkSafe (status≠closed). (No "Awaiting closure" / "N action" flags on site rows.)
- Row click → opens the SAME detail modal (editable). Right-click → same `openRowCtx` menu as global.

---

## SURFACE 3 — CLIENT SURFACE (read-only Risk management)

Breadcrumb: `Operations › Clients › Tania Reweti › Risk management`. Client = "Tania Reweti", lives at the client's home (`clientHomeId`, default Manaaki Lodge).

**Client header card:** avatar + name (h1) + `Lives at {home} · {suburb}` (home icon).

**Client profile tabs** (Risk management active): `Overview`, `Care plan`, `Risk management` (active), `Health`, `Notes`.

**Personal risk assessments block** (placeholder context section): h2 `Personal risk assessments`; body `Behaviour support, mobility and medication risk plans for Tania are managed here. (Prototype shows the hazards section below.)` — In the real build this is the existing client risk content; the hazards panel is appended below it.

**Read-only hazards section** — title `Site / environmental hazards` + `Read-only` chip (neutral); sub-copy `Hazards logged at {home} — managed by the H&S team, shown here for context. Actions deep-link to the register.`; `Open register` button (outline, externalLink → global filtered to the home). Shows hazards at the client's home where status ≠ closed (open + in_progress + mitigated). Empty: "No open hazards at this home."

**Compact client row** (`renderClientRow`): risk-tone dot (10px) + [hazard_label (bold) / `{ref} · {description}` truncated] + Risk chip (`{RISK_LABEL}`) + Status chip (icon) + `Due {DD Mon}` (right-aligned, critical+bold if overdue) + chevronRight. Click → opens detail modal in **read-only mode**.

**Client row right-click** (`openReadonlyCtx`) — header tag = RISK_LABEL (risk tone), meta `{ref} · read-only`. Items in order:
1. `View hazard` sub "read-only" (eye, primary) → opens detail read-only.
2. — separator —
3. `Open in register` sub `/compliance/hazards` (externalLink) → switches to global surface filtered to the hazard's site; flash "Opened in register".
4. `Copy link` (copy) → flash "Link copied to clipboard".

(No edit/lifecycle actions — read-only enforced. Detail modal opened from here shows the read-only rail footer note and only the `Open in register` footer button.)

---

## Behavioural / interaction notes for the build
- **Modal-first**: every workflow (create, assign, start, mitigate, add/complete action, review, close) opens as a modal/pane — no separate pages. Deep-links (`/hazards/{id}`, full page) are stubbed as flashes in the prototype; wire to real routes.
- **ESC** closes the topmost layer in order: context menu → create wizard → detail modal → board popover.
- **Toasts** (`flash`) confirm every mutation; copy quoted inline above (e.g. "Hazard {ref} closed", "Corrective action {ref} added", "Hazard marked Mitigated · residual {Risk}").
- **Lifecycle gating** is strict: Start only from open; Mark mitigated only from in_progress; all edit actions hidden when closed or read-only. Close is allowed even with open actions (warned, not blocked).
- **Risk auto-computation** everywhere severity+likelihood are set (create wizard live chip, matrix cells, mitigate residual).
- **Same detail modal** serves global (editable), site (editable), and client (read-only) — gate by `detailReadonly`.

This is the complete set of surfaces, fields, copy, gating, and flag logic in the prototype. File read in full (1321 lines).

## ===== hero-and-row-kits =====

Both files are short and fully read. Here is the exact API reference.

---

# `hs-hero-kit.tsx` — API Reference

Path: `resources/js/pages/health-safety/components/hs-hero-kit.tsx`

All components render on the **dark app-primary gradient hero**. Semantic tokens only. NZ frameworks only (LTIFR/TRIFR, never TRIR).

## Type: `Tone` (exported)

```ts
export type Tone = 'success' | 'warning' | 'critical' | 'neutral';
```

## `DOT_CLASS` (exported const)

`Record<Tone, string>` — dot background classes used on-dark (medallion/cluster/summary dots).

| Key | Value |
|---|---|
| `success` | `bg-status-success` |
| `warning` | `bg-status-warning` |
| `critical` | `bg-status-critical` |
| `neutral` | `bg-primary-foreground/50` |

## `DELTA_TEXT` (module-private, NOT exported)

`Record<Tone, string>` — text colour for per-tile delta line. Listed for completeness; not importable.

| Key | Value |
|---|---|
| `success` | `text-status-success` |
| `warning` | `text-status-warning` |
| `critical` | `text-status-critical` |
| `neutral` | `text-primary-foreground/70` |

## `fmt(value, suffix?)` (exported function)

```ts
export function fmt(value: number | null | undefined, suffix = ''): string
```

- `value` (required): `number | null | undefined`
- `suffix` (optional, default `''`): `string`
- **Renders/returns:** em-dash `'—'` for null/undefined, otherwise `` `${value}${suffix}` ``. Usage: shared KPI formatter for hero stat values.

## `HeroShell` (exported component)

```ts
function HeroShell({ children, footer }: { children: ReactNode; footer?: ReactNode })
```

| Prop | Type | Required | Default | Notes |
|---|---|---|---|---|
| `children` | `ReactNode` | yes | — | Main hero content |
| `footer` | `ReactNode` | no | — | Footer band; rendered only when `footer != null` |

Renders: gradient banner (`from-primary/90 via-primary to-primary/80`), 3 decorative orbs, drop-shadow, content in a `flex flex-col gap-5 p-6 md:p-7`; optional border-top footer band below. Usage: outermost wrapper for any H&S hero.

## `HeroStatusPill` (exported component)

```ts
function HeroStatusPill({ children }: { children: ReactNode })
```

| Prop | Type | Required | Default |
|---|---|---|---|
| `children` | `ReactNode` | yes | — |

Renders: pill with animated green `status-success` ping dot + uppercase eyebrow label (the `children`). Usage: top eyebrow, e.g. "Safety system · synced just now".

## `HeroMedallion` (exported component)

```ts
function HeroMedallion({ icon: Icon }: { icon: LucideIcon })
```

| Prop | Type | Required | Default |
|---|---|---|---|
| `icon` | `LucideIcon` | yes | — |

Renders: 72–80px circular bordered icon medallion, hidden below `sm`. Usage: hero identity icon (e.g. `BarChart3`).

## `HeroClusterTile` (exported component)

```ts
function HeroClusterTile({
    href, label, value, caption, tone, delta, deltaTone = 'neutral',
}: {
    href?: string;
    label: string;
    value: string;
    caption: string;
    tone: Tone;
    delta?: string;
    deltaTone?: Tone;
})
```

| Prop | Type | Required | Default | Notes |
|---|---|---|---|---|
| `href` | `string` | no | — | If set, renders as an Inertia `<Link>` with hover; else a static `<div>` |
| `label` | `string` | yes | — | Uppercase KPI label |
| `value` | `string` | yes | — | Big 25px tabular-nums value |
| `caption` | `string` | yes | — | Sub-caption line |
| `tone` | `Tone` | yes | — | Drives the leading dot colour (`DOT_CLASS[tone]`) |
| `delta` | `string` | no | — | Optional ▲/▼ trend line under value; omitted entirely when falsy |
| `deltaTone` | `Tone` | no | `'neutral'` | Colour of `delta` line (`DELTA_TEXT[deltaTone]`) |

Renders: one KPI tile inside a `HeroCluster`. Usage: a single leading/lagging metric; pass `href` to make it a register link.

## `HeroCluster` (exported component)

```ts
function HeroCluster({ title, icon: Icon, children }: { title: string; icon: LucideIcon; children: ReactNode })
```

| Prop | Type | Required | Default |
|---|---|---|---|
| `title` | `string` | yes | — |
| `icon` | `LucideIcon` | yes | — |
| `children` | `ReactNode` | yes | — |

Renders: labelled cluster card (uppercase title + icon) wrapping a `grid grid-cols-2 sm:grid-cols-4` of tiles. Usage: groups tiles, e.g. "Lagging · outcomes" / "Leading · proactive".

## `BadgeTone` (module-private type)

```ts
type BadgeTone = 'success' | 'warning' | 'critical';
```
Not exported (note: no `neutral`). Drives `CHIP_CLASS` / `CHIP_ICON` (both module-private).

`CHIP_CLASS` (private):

| Key | Value |
|---|---|
| `success` | `border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90` |
| `warning` | `border-status-warning/50 bg-status-warning/25 text-primary-foreground` |
| `critical` | `border-status-critical/50 bg-status-critical/25 text-primary-foreground` |

`CHIP_ICON` (private):

| Key | Value |
|---|---|
| `success` | `text-primary-foreground/80` |
| `warning` | `text-status-warning` |
| `critical` | `text-status-critical` |

## `HeroComplianceBadges` (exported component)

```ts
function HeroComplianceBadges({
    worksafeAwaiting, sdsExpiring, drillsDue = 0, drillsOverdue = 0,
    ngaPaerewaCertified = true, firstAidOk = true,
}: {
    worksafeAwaiting: number;
    sdsExpiring: number;
    drillsDue?: number;
    drillsOverdue?: number;
    ngaPaerewaCertified?: boolean;
    firstAidOk?: boolean;
})
```

| Prop | Type | Required | Default | Notes |
|---|---|---|---|---|
| `worksafeAwaiting` | `number` | yes | — | `>0` → warning chip "WorkSafe notifiable · N awaiting"; else success |
| `sdsExpiring` | `number` | yes | — | `>0` → warning "Hazardous substances · N SDS expiring"; else success |
| `drillsDue` | `number` | no | `0` | Drills due-soon → warning (Fire chip) |
| `drillsOverdue` | `number` | no | `0` | Drills past cadence → critical; **outranks** `drillsDue` |
| `ngaPaerewaCertified` | `boolean` | no | `true` | `true` → "Certified" success; else "Review due" warning |
| `firstAidOk` | `boolean` | no | `true` | `true` → "Cover OK" success; else "Cover gaps" warning |

Renders: the five canonical NZ compliance chips (WorkSafe, Ngā Paerewa NZS 8134:2021, Hazardous substances/SDS, Fire drills, First aid) as tinted pills in a `mt-3 flex flex-wrap gap-2`. Fed by counts/booleans, never pre-formatted strings. Usage: hero compliance row, identical across both H&S heroes.

## `HeroSegItem` (exported type)

```ts
export type HeroSegItem = {
    key: string;
    label: string;
    popover?: ReactNode; // pill variant only — render item as a popover trigger
};
```

## `HeroSegmented` (exported component)

```ts
function HeroSegmented({
    label, items, value, onChange, ariaLabel, variant = 'segmented',
}: {
    label?: string;
    items: readonly HeroSegItem[];
    value: string;
    onChange: (key: string) => void;
    ariaLabel: string;
    variant?: 'pill' | 'segmented';
})
```

| Prop | Type | Required | Default | Notes |
|---|---|---|---|---|
| `label` | `string` | no | — | Uppercase caption; `mr-1` in pill variant, `ml-1` (sibling) in segmented |
| `items` | `readonly HeroSegItem[]` | yes | — | Buttons; a pill item with `popover` renders a Popover trigger |
| `value` | `string` | yes | — | Active key (`aria-pressed`) |
| `onChange` | `(key: string) => void` | yes | — | Click handler (note: a `popover` pill does NOT call `onChange`) |
| `ariaLabel` | `string` | yes | — | `aria-label` on the `role="group"` |
| `variant` | `'pill' \| 'segmented'` | no | `'segmented'` | `pill` = standalone pills (period; one item may open a popover); `segmented` = bordered box rendered as a fragment with sibling label (lens) |

Renders: the shared period/lens control on-dark; keyboard + `aria-pressed`. Usage: hero period picker (`pill`) or role-lens toggle (`segmented`).

**eslint-disable comments present (verbatim):**

Pill popover trigger (line 300):
```
{/* eslint-disable-next-line no-restricted-syntax -- segmented period pill on the dark hero; not a shadcn Button. */}
```
Pill plain button (line 312):
```
// eslint-disable-next-line no-restricted-syntax -- segmented period pill on the dark hero; not a shadcn Button.
```
Segmented toggle button (line 334):
```
// eslint-disable-next-line no-restricted-syntax -- segmented toggle on the dark hero; not a shadcn Button.
```

## `HeroSummaryMetric` (exported component)

```ts
function HeroSummaryMetric({ tone, children }: { tone: Tone; children: ReactNode })
```

| Prop | Type | Required | Default |
|---|---|---|---|
| `tone` | `Tone` | yes | — |
| `children` | `ReactNode` | yes | — |

Renders: one dot-led metric (`DOT_CLASS[tone]` dot + content) for the summary strip. Usage: a single inline stat inside `HeroSummaryStrip`.

## `HeroSummaryStrip` (exported component)

```ts
function HeroSummaryStrip({
    label, children, collapsed = false, onToggle, toggleLabel = 'summary',
}: {
    label?: string;
    children: ReactNode;
    collapsed?: boolean;
    onToggle?: () => void;
    toggleLabel?: string;
})
```

| Prop | Type | Required | Default | Notes |
|---|---|---|---|---|
| `label` | `string` | no | — | Uppercase caption |
| `children` | `ReactNode` | yes | — | The metrics; hidden when `collapsed` |
| `collapsed` | `boolean` | no | `false` | Hides children |
| `onToggle` | `() => void` | no | — | When present, renders the "Hide/Show {toggleLabel}" button (analytics); absent → always shown (dashboard) |
| `toggleLabel` | `string` | no | `'summary'` | Word after Hide/Show in the toggle |

Renders: dot-led summary strip with border-top; optional Sparkles "Hide/Show" toggle (`aria-pressed={!collapsed}`). Usage: bottom hero summary row.

**eslint-disable comment present (verbatim)** — summary toggle button (line 387):
```
// eslint-disable-next-line no-restricted-syntax -- onDark summary toggle, custom hero-footer affordance
```

## Note on `RoleLensBanner`

**Not present / not exported in this file.** A grep-level fact: the file's exported symbols are exactly `Tone`, `DOT_CLASS`, `fmt`, `HeroShell`, `HeroStatusPill`, `HeroMedallion`, `HeroClusterTile`, `HeroCluster`, `HeroComplianceBadges`, `HeroSegItem`, `HeroSegmented`, `HeroSummaryMetric`, `HeroSummaryStrip`. The kit's header comment mentions a "shared `RoleLensBanner`" conceptually, but it lives elsewhere (per memory, in the dashboard/analytics composition), not in `hs-hero-kit.tsx`.

---

# `register-row-kit.tsx` — API Reference

Path: `resources/js/pages/health-safety/components/register-row-kit.tsx`

Neutral, presentational ROW primitives (light surface) shared by H&S governance registers. Semantic tokens only.

## Type: `Tone` (exported)

```ts
export type Tone = 'success' | 'warning' | 'critical' | 'neutral';
```
Deliberately identical to `hs-hero-kit`'s `Tone` so the two compose without casts.

## `TONE_BG` (exported const) — full map

`Record<Tone, string>` — tinted background + text for severity/priority chips.

| Key | Value |
|---|---|
| `success` | `bg-status-success-bg text-status-success` |
| `warning` | `bg-status-warning-bg text-status-warning` |
| `critical` | `bg-status-critical-bg text-status-critical` |
| `neutral` | `bg-muted text-muted-foreground` |

## `TONE_DOT` (exported const) — full map

`Record<Tone, string>` — dot background for status dots.

| Key | Value |
|---|---|
| `success` | `bg-status-success` |
| `warning` | `bg-status-warning` |
| `critical` | `bg-status-critical` |
| `neutral` | `bg-muted-foreground` |

## `titleCase(s)` (exported function)

```ts
export function titleCase(s: string): string
```
- `s` (required): `string`
- Returns: replaces `_`/`-` with spaces and upper-cases the first letter of each word. Usage: humanise enum keys (e.g. `corrective_action` → `Corrective Action`).

## `ENTITY_TONE` (module-private const)

`string[]` (4 entries) — solid avatar colours, indexed by `entityTone`. Not exported.

```ts
['bg-primary text-primary-foreground',
 'bg-status-info text-primary-foreground',
 'bg-status-success text-primary-foreground',
 'bg-status-critical text-primary-foreground']
```

## `initials(label)` (exported function)

```ts
export function initials(label: string | null | undefined): string
```
- `label` (required): `string | null | undefined`
- Returns: `'HS'` if falsy; else first letters of first two words, or first two chars of a single word, upper-cased. Usage: avatar initials for a register row.

## `entityTone(id)` (exported function)

```ts
export function entityTone(id: number): string
```
- `id` (required): `number`
- Returns: a deterministic avatar tone class from `ENTITY_TONE` via `id % 4`, so a row keeps its colour. Usage: stable per-row avatar colour.

## `FlagBadge` (exported component)

```ts
function FlagBadge({
    icon: Icon, children, tone, title,
}: {
    icon: LucideIcon;
    children: ReactNode;
    tone: 'critical' | 'warning' | 'success' | 'info' | 'neutral';
    title: string;
})
```

| Prop | Type | Required | Default | Notes |
|---|---|---|---|---|
| `icon` | `LucideIcon` | yes | — | Leading 3×3 icon |
| `children` | `ReactNode` | yes | — | Chip label |
| `tone` | `'critical' \| 'warning' \| 'success' \| 'info' \| 'neutral'` | yes | — | Note: own 5-value union (adds `info`, no `neutral`-vs-`Tone` reuse). Falls back to `bg-muted text-muted-foreground` |
| `title` | `string` | yes | — | Native `title` tooltip |

Tone class map (inline in component):

| `tone` | classes |
|---|---|
| `critical` | `bg-status-critical-bg text-status-critical` |
| `warning` | `bg-status-warning-bg text-status-warning` |
| `success` | `bg-status-success-bg text-status-success` |
| `info` | `bg-status-info-bg text-status-info` |
| `neutral` | `bg-muted text-muted-foreground` |

Renders: compact tinted flag chip (icon + label, bold 11px). Usage: Flags/Governance column chips (Overdue, Verify, No owner, parent-event stage, …).

## `RegisterTableHeader` (exported component)

```ts
function RegisterTableHeader({
    icon: Icon, title, subtitle, hint, hintIcon: HintIcon,
}: {
    icon: LucideIcon;
    title: string;
    subtitle?: string;
    hint?: string;
    hintIcon?: LucideIcon;
})
```

| Prop | Type | Required | Default | Notes |
|---|---|---|---|---|
| `icon` | `LucideIcon` | yes | — | Accent-tiled header icon |
| `title` | `string` | yes | — | Bold `<h2>` |
| `subtitle` | `string` | no | — | Rendered as `· {subtitle}` next to title |
| `hint` | `string` | no | — | Right-aligned hint text; whole hint block omitted if absent |
| `hintIcon` | `LucideIcon` | no | — | Icon before `hint`; rendered only if both `hint` and `hintIcon` |

Renders: card-header strip — accent-tiled title (+ optional subtitle) on the left, optional hint (+ icon) on the right, with bottom border. Usage: table card header, e.g. hint "Right-click or ⋮ for the full lifecycle".

**No eslint-disable comments** anywhere in `register-row-kit.tsx`.

---

## Cross-file note
Both files independently declare and export `type Tone = 'success' | 'warning' | 'critical' | 'neutral';` — intentionally identical so hero and row kits compose without casts. `FlagBadge`'s `tone` is a separate, wider 5-value union (`+info`) and does **not** reuse `Tone`.

## ===== gold-standard-pages =====

I have everything needed across all files and their shared dependencies. Here is the complete pattern documentation.

---

# Gold-standard H&S register page patterns

Primary source: `resources/js/pages/health-safety/events/index.tsx` (732 lines). Secondary: `corrective-actions/index.tsx`, `analytics.tsx`. Shared kits: `pages/health-safety/components/hs-hero-kit.tsx`, `pages/health-safety/components/register-row-kit.tsx`, `components/rostering/{shift-context-menu,tab-strip,entity-filter}.tsx`, `components/health-safety/event-detail-dialog.tsx`.

The two registers (`events` and `corrective-actions`) are near-identical twins — copy `events/index.tsx` as your base. Below, every snippet is verbatim from the files.

---

## 1. Full import block of `events/index.tsx`

Lines 1–66, verbatim (note the leading file-doc comment that every register carries):

```tsx
/* H&S Events register — the governance convergence view. Every incident type
 * lands here as an HsEvent for investigation, corrective action, WorkSafe
 * notification and gated closure. Shares the gold-standard `hs-hero-kit` hero
 * chrome + rostering TabStrip/EntityFilter/ShiftContextMenu with /incidents,
 * /safeguarding, /fleet-assets/incidents and its sibling Corrective-actions
 * register so the whole safety workflow reads as one product and can't drift
 * apart. Row helpers come from the neutral register-row-kit. ShiftContextMenu +
 * detail-as-modal workflow preserved. NZ-only, web-only. */
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { ShiftContextMenu, EntityFilter, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import {
    EventDetailDialog,
    EVENT_CATEGORY_LABELS,
    type EventActionKey,
    type EventDetail,
    type EventSectionKey,
} from '@/components/health-safety/event-detail-dialog';
import {
    HeroShell,
    HeroStatusPill,
    HeroMedallion,
    HeroCluster,
    HeroClusterTile,
    HeroSegmented,
    fmt,
    type Tone,
} from '@/pages/health-safety/components/hs-hero-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import {
    FlagBadge,
    RegisterTableHeader,
    TONE_BG,
    TONE_DOT,
    titleCase,
    initials,
    entityTone,
} from '@/pages/health-safety/components/register-row-kit';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Flame,
    FlaskConical,
    Hand,
    HeartPulse,
    LayoutList,
    Link2,
    ListChecks,
    MousePointer2,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Truck,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
```

Key import facts to mirror:
- The rostering primitives (`ShiftContextMenu, EntityFilter, TabStrip` + their types) come from the **barrel** `@/components/rostering`.
- Hero chrome comes from `@/pages/health-safety/components/hs-hero-kit` (note: a **page-relative** path, not `@/components`). `Tone` and `fmt` are exported from there.
- Row primitives (`FlagBadge, RegisterTableHeader, TONE_BG, TONE_DOT, titleCase, initials, entityTone`) come from `@/pages/health-safety/components/register-row-kit`. **`Tone` is exported by BOTH kits and they are the same union** (`'success' | 'warning' | 'critical' | 'neutral'`) — import it from `hs-hero-kit` only, to avoid a duplicate-identifier error.
- If your row uses `cn()` for conditional row classes (overdue tint etc.), add `import { cn } from '@/lib/utils';` — corrective-actions does this (line 48); events does not because it has no conditional row styling.

---

## 2. Hero composition (`HeroShell` + footer filter bar)

`HeroShell` takes `children` (main hero body) and a `footer` prop (the filter bar, rendered in a bordered band below). Structure from events (lines 329–466). The skeleton:

```tsx
<HeroShell
    footer={
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            <HeroSegmented label="Period" variant="pill" ariaLabel="Date range" items={RANGE_ITEMS} value={activeRange} onChange={onRange} />
            {sites?.length ? (
                <EntityFilter label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
            ) : null}
            {/* ...native <select> filters wrapped in <label> ... */}
            {/* ...toggle button(s)... */}
            <div className="relative ml-auto">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                <input
                    type="search"
                    placeholder="Search events…"
                    defaultValue={filters.q ?? ''}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || null });
                    }}
                    className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                />
            </div>
            {hasFilters ? (
                // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
                <button
                    type="button"
                    onClick={clearFilters}
                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                >
                    <X className="h-3 w-3" /> Clear
                </button>
            ) : null}
        </div>
    }
>
    <WorkflowRibbon current="investigate" />

    <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="flex items-start gap-4">
            <HeroMedallion icon={ShieldCheck} />
            <div className="flex flex-col gap-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    <HeroStatusPill>Safety events · governance register</HeroStatusPill>
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-semibold tracking-[0.04em] text-primary-foreground/85 uppercase">
                        <Activity className="h-3.5 w-3.5" /> Every incident type converges here
                    </span>
                </div>
                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Health &amp; Safety events</h1>
                <p className="max-w-xl text-sm text-primary-foreground/70">
                    The governance hub. Every safety event … closed through a gate.
                </p>
            </div>
        </div>

        {/* top-right CTA: Board-reports Popover (see §8) or a single Button */}
    </div>

    {/* stat clusters */}
    <div className="grid gap-3 lg:grid-cols-2">
        <HeroCluster title="Live · open governance" icon={Activity}>
            <HeroClusterTile href="/health-safety/events?tab=open" label="Open" value={fmt(live.open)} caption="newest today" tone="neutral" />
            {/* ...3 more tiles... */}
        </HeroCluster>
        <HeroCluster title="Needs attention" icon={AlertTriangle}>
            <HeroClusterTile href="/health-safety/events?tab=worksafe" label="WorkSafe due" value={fmt(at.worksafe_due)} caption={at.worksafe_due > 0 ? 'notify ASAP' : 'none pending'} tone="critical" />
            {/* ...3 more tiles... */}
        </HeroCluster>
    </div>
</HeroShell>
```

Component contracts (from `hs-hero-kit.tsx`):
- `HeroShell({ children, footer? })` — renders the gradient banner; children are stacked `flex flex-col gap-5`, footer is a bordered band below.
- `HeroMedallion({ icon })` — circular icon, hidden below `sm`.
- `HeroStatusPill({ children })` — the green-dot animated eyebrow pill.
- `HeroCluster({ title, icon, children })` — a 2×4 grid box of tiles.
- `HeroClusterTile({ href?, label, value, caption, tone, delta?, deltaTone? })` — `href` makes it a link tile (registers always pass `href`). `tone: Tone`.
- `fmt(value, suffix?)` — returns `'—'` for null/undefined, else `` `${value}${suffix}` ``.

The events hero body order is: `WorkflowRibbon` → title row (medallion + eyebrow + h1 + blurb, with CTA on the right) → two-up stat clusters. The `WorkflowRibbon current="..."` is an H&S-specific lifecycle ribbon (events uses `"investigate"`, corrective-actions uses `"resolve"`) — include it if your register is part of that workflow, otherwise drop it.

---

## 3. How filters drive `router.get` (helper shapes + options)

Three distinct helpers, each with deliberately different Inertia options. From events (lines 222–238):

```tsx
const go = (next: Partial<Filters>) =>
    router.get('/health-safety/events', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

const setTab = (id: string) => router.get('/health-safety/events', { ...filters, tab: id }, { preserveScroll: true });
```

```tsx
const clearFilters = () =>
    router.get('/health-safety/events', { tab }, { preserveState: true, preserveScroll: true, replace: true });
```

Rules to copy exactly:
- **`go`** (the everyday filter mutator): spreads `...filters` then `...next`, and uses `{ preserveState: true, preserveScroll: true, replace: true }`. `replace: true` keeps filter tweaks out of the browser history stack.
- **`setTab`**: only `{ preserveScroll: true }` — **no** `preserveState`, **no** `replace` (a tab change is a real navigation and resets row state / re-fetches everything). Spreads `...filters` so other filters survive the tab switch.
- **`clearFilters`**: resets to just `{ tab }` (drops every other filter), with `replace: true`.
- A boolean toggle is just `go({ worksafe: filters.worksafe ? null : true })`. Setting a filter to `null` removes it.

The segmented "Period" range pills use a derived `activeRange` + an `onRange` handler that calls `go` (events lines 252–272):

```tsx
const activeRange = !filters.from
    ? 'week'
    : filters.from === daysAgoStr(7)
      ? 'week'
      : filters.from === daysAgoStr(30)
        ? '30d'
        : filters.from === daysAgoStr(90)
          ? 'quarter'
          : 'custom';
const onRange = (key: string) => {
    if (key === 'all') {
        go({ from: null, to: null });
        return;
    }
    if (key === 'custom') {
        return;
    }
    const map: Record<string, number> = { week: 7, '30d': 30, quarter: 90 };
    go({ from: daysAgoStr(map[key]), to: todayStr() });
};
```

With the `todayStr` / `daysAgoStr` browser-local helpers (events lines 196–204):

```tsx
const todayStr = () => {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};
const daysAgoStr = (n: number) => {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};
```

`analytics.tsx` is a useful variant: it keeps a memoised `params` object and a generic `reload` (lines 533–542):

```tsx
const params = useMemo(
    () => ({ period: filters.period, from: filters.from, to: filters.to, site_id: filters.site_id, lens: filters.lens }),
    [filters],
);
const reload = (next: Record<string, string | number | null>) => {
    router.get('/health-safety/analytics', { ...params, ...next }, { preserveState: true, preserveScroll: true, replace: true });
};
```

Both styles are equivalent; the `go(next: Partial<Filters>)` form (events) is the cleaner one to copy for a register because it's typed against your `Filters`.

---

## 4. `openEvent` / `closeDetail` — detail-over-list via Inertia partial reload

This is the signature "detail-as-modal" pattern. Events (lines 217–235):

```tsx
export default function HsEventsIndex({ events, tab, tabCounts, hero, filters, sites, detail, can }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection, setPendingSection] = useState<EventSectionKey>('overview');
    const [pendingAction, setPendingAction] = useState<EventActionKey | null>(null);

    const go = (next: Partial<Filters>) =>
        router.get('/health-safety/events', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const setTab = (id: string) => router.get('/health-safety/events', { ...filters, tab: id }, { preserveScroll: true });

    // Detail-over-list: fetch only the `detail` prop and open the dialog without
    // navigating away; closing drops the param so `detail` comes back null.
    const openEvent = (id: number, opts?: { section?: EventSectionKey; action?: EventActionKey }) => {
        setPendingSection(opts?.section ?? 'overview');
        setPendingAction(opts?.action ?? null);
        router.get('/health-safety/events', { ...filters, event: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const closeDetail = () =>
        router.get('/health-safety/events', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });
```

And the dialog render at the bottom of the page (events lines 482–487):

```tsx
{ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

{detail ? (
    <EventDetailDialog key={detail.id} detail={detail} open onClose={closeDetail} initialSection={pendingSection} initialAction={pendingAction} />
) : null}
```

Exact mechanics to copy:
- **Open**: `router.get(url, { ...filters, event: id }, { preserveState: true, preserveScroll: true, only: ['detail'] })`. The `only: ['detail']` is the key — it does a **partial reload fetching only the `detail` prop** from the controller, so the list, hero and tab data are untouched and there's no flash. The `?event={id}` query param is what the controller reads to hydrate `detail`.
- **Close**: same call **without** `event` (`{ ...filters }`), still `only: ['detail']`. Dropping the param makes the controller return `detail: null`, which unmounts the dialog.
- The intended section/action are **not** sent to the server — they're stashed in local state (`pendingSection` / `pendingAction`) right before the `router.get`, then passed to `EventDetailDialog` as `initialSection` / `initialAction`.
- `key={detail.id}` forces a fresh dialog instance per record (resets internal dialog state when you open a different row).
- `open` is passed bare (always `true` when `detail` exists; the `{detail ? … : null}` guard controls mounting).

`EventDetailDialog` prop contract (from `event-detail-dialog.tsx` lines 299–313):

```tsx
export function EventDetailDialog({
    detail, open, onClose,
    initialSection = 'overview',
    initialAction = null,
    initialActionTarget = null,
    openedFrom = null,
}: {
    detail: EventDetail;
    open: boolean;
    onClose: () => void;
    initialSection?: EventSectionKey;
    initialAction?: EventActionKey | null;
    initialActionTarget?: …;  // deep-link to one corrective action's pane
})
```

with the section/action unions:

```tsx
export type EventSectionKey = 'overview' | 'investigation' | 'actions' | 'risk' | 'timeline' | 'evidence';
export type EventActionKey = 'close' | 'worksafe_notify' | 'worksafe_acknowledge' | 'investigation' | 'add_action';
```

**Corrective-actions adds a third piece — deep-linking to a specific child action's workflow pane** (`complete` / `verify` / `return`). It carries an extra `pendingActionTarget` state and passes `initialActionTarget`. Lines 257–286:

```tsx
const [pendingActionTarget, setPendingActionTarget] = useState<{ actionId: number; pane: ActionPane } | null>(null);

const openEvent = (
    id: number,
    opts?: { section?: EventSectionKey; action?: EventActionKey; actionTarget?: { actionId: number; pane: ActionPane } },
) => {
    setPendingSection(opts?.section ?? 'actions');
    setPendingAction(opts?.action ?? null);
    setPendingActionTarget(opts?.actionTarget ?? null);
    router.get('/health-safety/corrective-actions', { ...filters, event: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
};
// Deep-link a row straight onto a lifecycle pane (Complete / Verify / Return)
const openActionPane = (action: ActionRow, pane: ActionPane) => {
    if (!action.event) return;
    openEvent(action.event.id, { section: 'actions', actionTarget: { actionId: action.id, pane } });
};
const closeDetail = () => {
    setPendingActionTarget(null);
    router.get('/health-safety/corrective-actions', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });
};
```

(`type ActionPane = 'complete' | 'verify' | 'return';`, line 135.) Note `closeDetail` here also resets `pendingActionTarget`. Only adopt this layer if your register's rows are children that open a *parent's* detail modal on a specific pane.

---

## 5. `TabStrip` usage (badges + tab change)

Build a typed `TABS: RosterTabItem[]` and feed `tabCounts` into each `badge`. Events lines 242–250:

```tsx
const TABS: RosterTabItem[] = [
    { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
    { id: 'open', label: 'Open', icon: AlertTriangle, tone: 'info', badge: tabCounts.open || undefined },
    { id: 'investigating', label: 'Investigating', icon: Search, tone: 'primary', badge: tabCounts.investigating || undefined },
    { id: 'corrective_actions', label: 'Corrective actions', icon: ListChecks, tone: 'warning', badge: tabCounts.corrective_actions || undefined },
    { id: 'worksafe', label: 'WorkSafe-notifiable', icon: ShieldAlert, tone: 'critical', badge: tabCounts.worksafe || undefined },
    { id: 'monitoring', label: 'Monitoring', icon: Activity, tone: 'success', badge: tabCounts.monitoring || undefined },
    { id: 'closed', label: 'Closed', icon: CheckCircle2, tone: 'success', badge: tabCounts.closed || undefined },
];
```

Render (events line 469):

```tsx
<TabStrip value={tab} items={TABS} onChange={setTab} ariaLabel="Safety event views" />
```

Contract (`tab-strip.tsx` lines 13–19, 36–48):

```tsx
export type RosterTabItem = {
    id: string;
    label: string;
    icon: ComponentType<{ className?: string }>;
    tone: RosterTabTone;   // 'primary' | 'warning' | 'success' | 'info' | 'violet' | 'critical'
    badge?: ReactNode;
};

export function TabStrip({ value, onChange, items, className, ariaLabel = 'Roster views' }: {
    value: string;
    onChange: (next: string) => void;
    items: RosterTabItem[];
    className?: string;
    ariaLabel?: string;
})
```

Wiring facts:
- **Badge gating**: `badge: tabCounts.x || undefined` — the `|| undefined` hides the badge when the count is `0` (TabStrip only renders a chip when `badge` is truthy).
- `onChange={setTab}` — `setTab` is the dedicated handler (`{ preserveScroll: true }` only, §3), **not** `go`.
- `value={tab}` is the server-provided active tab prop (top-level, separate from `filters.tab`).
- The TabStrip itself owns roving-tabindex arrow-key navigation internally — you don't add keyboard handling.

---

## 6. The table row — `<tr>` with onClick/onContextMenu/tabIndex/onKeyDown/className + cells

Verbatim row from `EventTable` (events lines 617–724). This is the canonical clickable/right-clickable/keyboard-accessible row:

```tsx
return (
    <tr
        key={ev.id}
        onClick={() => onOpen(ev.id)}
        onContextMenu={(e) => onRowCtx(e, ev)}
        tabIndex={0}
        aria-label={`Open event ${ev.reference_number}`}
        onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                onOpen(ev.id);
            }
        }}
        className="cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
    >
        <td className="px-4 py-3 align-top whitespace-nowrap">
            <div className="text-xs font-bold text-foreground" title={when.title}>
                {when.main}
            </div>
            <div className="mt-0.5 text-[11px] font-semibold text-muted-foreground">{ev.reference_number}</div>
        </td>
        <td className="max-w-[280px] px-4 py-3 align-top">
            <div className="flex items-start gap-2">
                <span className={`h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sev.tone]}`} />
                <span className="min-w-0">
                    <span className="block text-xs font-bold text-foreground">{category}</span>
                    <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">{eventContext(ev)}</span>
                </span>
            </div>
        </td>
        {/* ...Source & category cell (icon chip), Site/Client cell (avatar)... */}
        <td className="px-4 py-3 align-top">
            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[sev.tone]}`}>{sev.label}</span>
        </td>
        <td className="px-4 py-3 align-top">
            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${stage.cls}`}>
                <StageIcon className="h-3 w-3" />
                {stage.label}
            </span>
        </td>
        <td className="px-4 py-3 align-top">
            <div className="flex flex-wrap items-center gap-1.5 text-muted-foreground">
                {ev.worksafe_notifiable ? (
                    <FlagBadge icon={ShieldAlert} tone={ev.flags.worksafe_pending ? 'critical' : ev.worksafe_status === 'acknowledged' ? 'success' : 'warning'} title={…}>
                        {ev.flags.worksafe_pending ? 'Pending' : titleCase(ev.worksafe_status ?? 'Notifiable')}
                    </FlagBadge>
                ) : null}
                {/* ...more conditional FlagBadges... */}
                {/* em-dash fallback when no flags apply: */}
                {!ev.worksafe_notifiable && !ev.flags.investigation_overdue && … ? (
                    <span className="text-xs text-muted-foreground">—</span>
                ) : null}
            </div>
        </td>
    </tr>
);
```

The row's per-row derived values come right before the `return` (events lines 608–616):

```tsx
const sev = SEV[ev.severity] ?? SEV.low;
const stage = STAGE[ev.status] ?? STAGE.open;
const StageIcon = stage.icon;
const mod = ev.source ? SOURCE_MODULE[ev.source.type] : null;
const ModIcon = mod?.icon ?? Link2;
const when = formatWhenCompact(ev.occurred_at ?? ev.reported_at);
const category = EVENT_CATEGORY_LABELS[ev.event_category] ?? titleCase(ev.event_category);
const entityName = ev.client_name ?? ev.site_name ?? ev.staff_name ?? 'Unassigned';
```

The exact, mandatory pieces:
- **`className`** for an always-clickable row: `"cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"` — hover tint + visible focus ring.
- **`tabIndex={0}`**, **`aria-label`**, and an **`onKeyDown`** that fires on `Enter`/`Space` (with `e.preventDefault()`) — keyboard-equivalent to the click.
- **`onContextMenu={(e) => onRowCtx(e, ev)}`** — the right-click handler (`onRowCtx` itself calls `e.preventDefault()`, §7).
- **Tone tokens**: a severity/priority **dot** is `` `h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sev.tone]}` ``; the **severity pill** is `` `inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[sev.tone]}` ``. `TONE_DOT` / `TONE_BG` are keyed by `Tone`.
- **Avatar cell** uses `entityTone(id)` for a deterministic colour + `initials(name)` (events lines 666–672):
  ```tsx
  <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-md text-[10px] font-bold ${entityTone(ev.id)}`}>
      {initials(entityName)}
  </span>
  ```
- **`FlagBadge`** for the flags column — `FlagBadge({ icon, children, tone, title })`, `tone: 'critical' | 'warning' | 'success' | 'info' | 'neutral'` (note this is a **wider** union than `Tone` — it adds `'info'`). Always provide a `title` (it's the hover tooltip + a11y text).

**Conditional row tint variant** — corrective-actions uses `cn()` to tint overdue rows (lines 562–581):

```tsx
<tr
    key={action.id}
    onClick={open}
    onContextMenu={(e: ReactMouseEvent) => {
        e.preventDefault();
        onMenu(action, e.clientX, e.clientY);
    }}
    tabIndex={action.event ? 0 : -1}
    aria-label={action.event ? `Open parent event for action ${action.reference_number}` : undefined}
    onKeyDown={(e) => {
        if (action.event && (e.key === 'Enter' || e.key === ' ')) {
            e.preventDefault();
            open();
        }
    }}
    className={cn(
        action.event ? 'cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring' : '',
        action.is_overdue ? 'bg-status-critical-bg/40' : '',
    )}
>
```

Note how it gracefully degrades when a row is non-interactive (`action.event` is null → `tabIndex={-1}`, no `aria-label`, no cursor/hover classes).

The table wrapper + header pattern (events lines 593–606): `<div className="overflow-x-auto"><table className="w-full min-w-[1040px] text-sm">`, with `<thead className="bg-muted/70">` and column `<th className="px-4 py-3">` cells, and `<tbody className="divide-y divide-border">`. The whole table is inside a card section (events lines 474–477):

```tsx
<section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
    <RegisterTableHeader icon={Shield} title={tableTitle} subtitle="the convergence view" hint="Right-click a row for governance actions" hintIcon={MousePointer2} />
    <EventTable rows={events.data} onRowCtx={openRowCtx} onOpen={openEvent} />
</section>
```

Empty state lives inside the table component (events lines 583–591):

```tsx
if (!rows.length) {
    return (
        <div className="px-4 py-16 text-center">
            <Shield className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
            <p className="font-medium text-muted-foreground">No events here</p>
            <p className="mt-1 text-sm text-muted-foreground/70">Nothing matches this tab and filters.</p>
        </div>
    );
}
```

---

## 7. Row + hero `ShiftContextMenu` wiring (`ShiftCtxState`, item building, gating)

State shape (`shift-context-menu.tsx` lines 7–27):

```tsx
export type ShiftCtxItem =
    | { sep: true }
    | {
          sep?: false;
          icon: ReactNode;
          label: string;
          sub?: string;
          kbd?: string;
          tone?: 'primary' | 'critical';
          onClick?: () => void;
      };

export type ShiftCtxState = {
    x: number;
    y: number;
    tag: string;
    tagBg?: string;
    tagColor?: string;
    meta: string;
    items: ShiftCtxItem[];
};
```

State + render: `const [ctx, setCtx] = useState<ShiftCtxState | null>(null);` and at page bottom `{ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}`. `ShiftContextMenu({ ctx, onClose })` self-positions (clamps to viewport) and self-dismisses on outside-click / Escape.

Events builds the menu inline in the row handler (lines 275–307) — **gating is plain `if` pushes onto an `items` array**:

```tsx
const openRowCtx = (e: ReactMouseEvent, ev: EventRow) => {
    e.preventDefault();
    const sev = SEV[ev.severity] ?? SEV.low;
    const items: ShiftCtxItem[] = [
        { icon: <Shield className="h-3.5 w-3.5" />, label: 'View event', sub: ev.reference_number, tone: 'primary', onClick: () => openEvent(ev.id) },
        { icon: <Search className="h-3.5 w-3.5" />, label: 'Investigation', onClick: () => openEvent(ev.id, { section: 'investigation' }) },
        { icon: <ListChecks className="h-3.5 w-3.5" />, label: 'Corrective actions', onClick: () => openEvent(ev.id, { section: 'actions' }) },
    ];
    if (ev.source?.url) {
        items.push({ icon: <Link2 className="h-3.5 w-3.5" />, label: 'View originating record', sub: ev.source.label, onClick: () => router.visit(ev.source!.url!) });
    }
    if (ev.worksafe_notifiable && can.manage && ev.worksafe_status !== 'acknowledged') {
        if (ev.worksafe_status === 'notified') {
            items.push({ icon: <ShieldCheck className="h-3.5 w-3.5" />, label: 'Record WorkSafe acknowledgement', onClick: () => openEvent(ev.id, { action: 'worksafe_acknowledge' }) });
        } else {
            items.push({ icon: <ShieldAlert className="h-3.5 w-3.5" />, label: 'Record WorkSafe notification', tone: 'critical', onClick: () => openEvent(ev.id, { action: 'worksafe_notify' }) });
        }
    } else if (ev.worksafe_notifiable) {
        items.push({ icon: <ShieldAlert className="h-3.5 w-3.5" />, label: 'WorkSafe', sub: titleCase(ev.worksafe_status ?? 'pending'), onClick: () => openEvent(ev.id, { section: 'overview' }) });
    }
    if (can.manage && ev.status !== 'closed' && !ev.has_investigation) {
        items.push({ icon: <Search className="h-3.5 w-3.5" />, label: 'Start investigation', onClick: () => openEvent(ev.id, { action: 'investigation' }) });
    }
    if (can.manage && ev.status !== 'closed') {
        items.push({ icon: <ListChecks className="h-3.5 w-3.5" />, label: 'Add corrective action', onClick: () => openEvent(ev.id, { action: 'add_action' }) });
    }
    if (can.manage && ev.status !== 'closed') {
        items.push({ icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Close event', tone: 'critical', onClick: () => openEvent(ev.id, { action: 'close' }) });
    }
    items.push({ sep: true }, { icon: <Link2 className="h-3.5 w-3.5" />, label: 'Open full page', onClick: () => router.visit(`/health-safety/events/${ev.id}`) });

    setCtx({ x: e.clientX, y: e.clientY, tag: sev.label.toUpperCase(), meta: `${ev.reference_number} · ${EVENT_CATEGORY_LABELS[ev.event_category] ?? titleCase(ev.event_category)}`, items });
};
```

Patterns to copy:
- Handler signature `(e: ReactMouseEvent, row) => { e.preventDefault(); … }`.
- Icons are JSX nodes at `className="h-3.5 w-3.5"`.
- `tone: 'primary'` highlights the main/open action; `tone: 'critical'` for destructive (Close, Return for rework).
- `sub` is the muted second line; `{ sep: true }` is a divider.
- **Gating is `if (can.manage && row.status !== 'closed') items.push(…)`** — every write action is guarded by the `can.*` permission flag plus a status check. The menu always opens; only the allowed items appear.
- Final `setCtx({ x: e.clientX, y: e.clientY, tag: <UPPERCASE badge>, meta: <ref · title>, items })`. `tag` is the corner badge (severity/priority, upper-cased); `meta` is the header line.

**Corrective-actions factors this into a reusable `menuItems()` builder so the right-click AND the kebab button share one payload** (lines 322–358). This is the better pattern to copy if you also want a kebab affordance:

```tsx
const menuItems = (action: ActionRow): ShiftCtxItem[] => {
    if (!action.event) return [];
    const base = `/health-safety/events/${action.event.id}/corrective-actions/${action.id}`;
    const canWrite = can.manage && action.event.status !== 'closed';

    const lifecycle: ShiftCtxItem[] = [];
    if (canWrite) {
        if (action.status === 'open') {
            lifecycle.push({ icon: <Play className="h-3.5 w-3.5" />, label: 'Start action', tone: 'primary', onClick: () => router.post(`${base}/start`, {}, { preserveScroll: true }) });
        } else if (action.status === 'in_progress') {
            lifecycle.push({ icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Mark complete…', tone: 'primary', onClick: () => openActionPane(action, 'complete') });
        } else if (action.status === 'completed') {
            if (action.can_verify) {  // separation-of-duties: hidden for the completer; server also gates
                lifecycle.push({ icon: <ShieldCheck className="h-3.5 w-3.5" />, label: 'Verify…', tone: 'primary', onClick: () => openActionPane(action, 'verify') });
            }
            lifecycle.push({ icon: <RotateCcw className="h-3.5 w-3.5" />, label: 'Return for rework…', tone: 'critical', onClick: () => openActionPane(action, 'return') });
        } else if (action.status === 'verified') {
            lifecycle.push({ icon: <Lock className="h-3.5 w-3.5" />, label: 'Close action', onClick: () => router.post(`${base}/close`, {}, { preserveScroll: true }) });
        }
    }

    const tail: ShiftCtxItem[] = [
        { icon: <ListChecks className="h-3.5 w-3.5" />, label: 'Open corrective actions', sub: action.reference_number, tone: 'primary', onClick: () => openEvent(action.event!.id, { section: 'actions' }) },
        { icon: <Eye className="h-3.5 w-3.5" />, label: 'View parent event', sub: action.event.reference_number, onClick: () => openEvent(action.event!.id, { section: 'overview' }) },
        ...(canWrite ? [{ icon: <Plus className="h-3.5 w-3.5" />, label: 'Add corrective action', onClick: () => openEvent(action.event!.id, { action: 'add_action' }) } satisfies ShiftCtxItem] : []),
        { icon: <Link2 className="h-3.5 w-3.5" />, label: 'Open event full page', onClick: () => router.visit(`/health-safety/events/${action.event!.id}`) },
    ];

    return lifecycle.length ? [...lifecycle, { sep: true }, ...tail] : tail;
};

const openMenu = (action: ActionRow, x: number, y: number) => {
    const priority = PRI[action.priority] ?? PRI.medium;
    setCtx({ x, y, tag: priority.label.toUpperCase(), meta: `${action.reference_number} · ${action.title}`, items: menuItems(action) });
};
```

Notes: `openMenu(action, x, y)` takes raw coords, so both the row (`onMenu(action, e.clientX, e.clientY)`) and the kebab (`onMenu(action, r.left, r.bottom)` from `getBoundingClientRect()`) call it. The conditional spread `...(canWrite ? [{…} satisfies ShiftCtxItem] : [])` is the idiom for an optionally-included item inside an array literal. The hero CTA can open the same menu — there's no separate "hero ShiftContextMenu"; it's the one `ctx` state, opened from wherever.

`analytics.tsx` shows the **tinted-tag** variant (passes `tagBg`/`tagColor`, lines 565–576) if you want a coloured corner badge instead of the default:

```tsx
setCtx({ x: e.clientX, y: e.clientY, tag, tagBg: `var(--status-${tone}-bg)`, tagColor: `var(--status-${tone})`, meta: target.label, items });
```

---

## 8. `BOARD_REPORTS` constant + Board-reports `Popover`

Constant (events lines 186–193):

```tsx
/** The five governance board reports surfaced from the hero CTA popover. */
const BOARD_REPORTS = [
    { label: 'Board summary', href: '/health-safety/reports/board-summary' },
    { label: 'WorkSafe register', href: '/health-safety/reports/worksafe-register' },
    { label: 'Investigation outcomes', href: '/health-safety/reports/investigation-outcomes' },
    { label: 'Corrective-action traceability', href: '/health-safety/reports/corrective-action-traceability' },
    { label: 'Risk-assessment register', href: '/health-safety/reports/risk-assessment-register' },
];
```

The popover, placed as the top-right hero CTA (events lines 427–448):

```tsx
<Popover>
    <PopoverTrigger asChild>
        <Button size="sm" className="border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20">
            <FileText className="mr-1.5 h-4 w-4" /> Board reports
            <span aria-hidden className="ml-1">▾</span>
        </Button>
    </PopoverTrigger>
    <PopoverContent align="end" className="w-64 p-1.5">
        {BOARD_REPORTS.map((report) => (
            // eslint-disable-next-line no-restricted-syntax -- popover menu item (report link), not a form control
            <button
                key={report.href}
                type="button"
                onClick={() => router.visit(report.href)}
                className="flex w-full items-center gap-2.5 rounded-md p-2.5 text-left text-sm font-medium transition-colors hover:bg-muted"
            >
                <FileText className="h-4 w-4 shrink-0 text-primary" />
                {report.label}
            </button>
        ))}
    </PopoverContent>
</Popover>
```

Facts:
- Trigger is a shadcn `Button` (`size="sm"`) styled translucent-on-dark via `border-primary-foreground/25 bg-primary-foreground/10 … hover:bg-primary-foreground/20`, wrapped in `PopoverTrigger asChild`.
- `PopoverContent align="end" className="w-64 p-1.5"`.
- Each item is a plain `<button onClick={() => router.visit(href)}>` (carries the sanctioned eslint-disable, §9).

**Variant** — corrective-actions uses a single `Button` instead of a popover (lines 431–439), gated on `can.viewReports`:

```tsx
{can.viewReports ? (
    <Button size="sm" className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => router.visit(TRACEABILITY_REPORT)}>
        <FileText className="mr-1.5 h-4 w-4" /> Traceability report
    </Button>
) : null}
```

**Variant** — analytics uses `{ name, label }` shape with `window.open(reportUrl(r.name), '_blank')` for export-led behaviour (lines 104–110, 671–696):

```tsx
const GOV_REPORTS: { name: string; label: string }[] = [
    { name: 'board-summary', label: 'Board summary' },
    { name: 'worksafe-register', label: 'WorkSafe register' },
    // …
];
```

For a register, copy the events `{ label, href }` + `router.visit` form.

---

## 9. Sanctioned `eslint-disable` / `no-restricted-syntax` comments (verbatim, with surrounding lines)

The repo lints against raw `<button>` (must use the shadcn `Button`). These styled-on-dark / menu-item buttons are sanctioned with a one-line reason. All occurrences, verbatim with context:

**events/index.tsx — onDark WorkSafe toggle (lines 368–380):**
```tsx
{/* eslint-disable-next-line no-restricted-syntax -- onDark WorkSafe toggle on the hero footer; not a shadcn Button. */}
<button
    type="button"
    aria-pressed={!!filters.worksafe}
    onClick={() => go({ worksafe: filters.worksafe ? null : true })}
    className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none ${
```

**events/index.tsx — onDark clear affordance (lines 393–399):**
```tsx
{hasFilters ? (
    // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
    <button
        type="button"
        onClick={clearFilters}
        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground"
    >
```

**events/index.tsx — board-report popover item (lines 435–441):**
```tsx
{BOARD_REPORTS.map((report) => (
    // eslint-disable-next-line no-restricted-syntax -- popover menu item (report link), not a form control
    <button
        key={report.href}
        type="button"
        onClick={() => router.visit(report.href)}
```

**corrective-actions/index.tsx — onDark clear affordance (lines 397–403):** identical comment to the events clear button:
```tsx
{hasFilters ? (
    // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
    <button
        type="button"
        onClick={clearFilters}
```

**corrective-actions/index.tsx — kebab row affordance (lines 692–702):**
```tsx
{action.event ? (
    // eslint-disable-next-line no-restricted-syntax -- icon-only row affordance; opens the shared ShiftContextMenu
    <button
        type="button"
        aria-label={`Lifecycle actions for ${action.reference_number}`}
        onClick={(e) => {
            e.stopPropagation();
            const r = e.currentTarget.getBoundingClientRect();
            onMenu(action, r.left, r.bottom);
        }}
        className="inline-grid h-7 w-7 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
    >
```

**analytics.tsx — additional sanctioned forms** (for reference; the translucent hero pill is the relevant one for a register):
- Line 673: `{/* eslint-disable-next-line no-restricted-syntax -- translucent action pill on the dark hero; not a shadcn Button. */}`
- Line 685: `// eslint-disable-next-line no-restricted-syntax -- governance report row; opens the dated report in a new tab.`
- Line 133: `// eslint-disable-next-line no-restricted-syntax -- compact scorecard metric cell, not a content card`
- Line 256: `// eslint-disable-next-line no-restricted-syntax -- sortable column header trigger, not a standard Button`
- Line 364: `{/* eslint-disable-next-line no-restricted-syntax -- heatmap intensity cell, custom shaded surface */}`

Rule: any raw `<button>`/`<div>`-as-card needs `// eslint-disable-next-line no-restricted-syntax -- <reason>` immediately above it. Use the JSX-comment form `{/* … */}` when the button is the child of a JSX expression (e.g. inside `PopoverTrigger asChild` or a `{… ? (` ternary that opens with the comment), and the plain `// …` line form when it sits directly after an opening `(` in a `.map`.

---

## 10. The Inertia page-props TypeScript interface

The full `Props` type from events (lines 72–123). This is the shape your controller must serialise and your page must destructure:

```tsx
type EventRow = {
    id: number;
    reference_number: string;
    event_category: string;
    severity: string;
    status: string;
    occurred_at: string | null;
    reported_at: string | null;
    site_name: string | null;
    client_name: string | null;
    staff_name: string | null;
    worksafe_notifiable: boolean;
    worksafe_status: string | null;
    investigation_required: boolean;
    source: { type: string; id: number; label: string; url: string | null; unwired: boolean } | null;
    flags: {
        investigation_overdue: boolean;
        awaiting_verification: number;
        worksafe_pending: boolean;
        unwired: boolean;
    };
    has_investigation: boolean;
    has_open_actions: boolean;
};

type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };

type Filters = {
    q: string | null;
    tab: string;
    severity: string | null;
    category: string | null;
    source: string | null;
    site_id: number | null;
    worksafe: boolean | null;
    from: string | null;
    to: string | null;
};

type Props = {
    events: Paginated<EventRow>;
    tab: string;
    tabCounts: Record<string, number>;
    hero: {
        live: { open: number; investigating: number; corrective_action: number; monitoring: number };
        attention: { investigation_due: number; awaiting_verification: number; worksafe_due: number; closed_period: number };
    };
    filters: Filters;
    sites: Array<{ id: number; name: string }>;
    detail: EventDetail | null;
    can: { manage: boolean };
};
```

The canonical top-level prop set every register exposes:
- **`<rows>: Paginated<RowT>`** (named `events` / `actions`) — `{ data, links, last_page }`. Corrective-actions' `Paginated` also adds `total`, `from`, `to` (lines 116–123) for the "Showing X–Y of N" footer:
  ```tsx
  type Paginated<T> = {
      data: T[];
      links: { url: string | null; label: string; active: boolean }[];
      last_page: number;
      total: number;
      from: number | null;
      to: number | null;
  };
  ```
- **`tab: string`** — active tab (separate from `filters.tab`).
- **`tabCounts: Record<string, number>`** — per-tab badge counts.
- **`hero: { live: {…}; attention: {…} }`** — two clusters of integer KPIs (the keys map 1:1 to your `HeroClusterTile`s).
- **`filters: Filters`** — the typed current-filter object that `go`/`clearFilters` spread.
- **`sites: Array<{ id: number; name: string }>`** — feeds `EntityFilter` (its `EntityFilterOption` is `{ id, name, description? }`, compatible).
- **`detail: EventDetail | null`** — the partial-reload payload for the modal (`null` when no `?event=`). `EventDetail` is imported from `event-detail-dialog`.
- **`can: { manage: boolean; … }`** — permission flags gating context-menu items and the report CTA. Corrective-actions extends it: `can: { manage: boolean; viewReports?: boolean }` (line 148).

Destructure them straight in the component signature: `export default function HsEventsIndex({ events, tab, tabCounts, hero, filters, sites, detail, can }: Props) {`.

---

## Quick file-map of what to copy

| Need | Source (canonical) |
|---|---|
| Whole page skeleton, hero, table, ctx-menu | `resources/js/pages/health-safety/events/index.tsx` |
| Reusable `menuItems()` builder + kebab + conditional row tint + "Showing X–Y of N" pager | `resources/js/pages/health-safety/corrective-actions/index.tsx` |
| Hero `footer` with multiple `HeroSegmented` + custom-range popover; memoised `reload`; tinted ctx tag | `resources/js/pages/health-safety/analytics.tsx` |
| Hero component contracts (`HeroShell/HeroCluster/HeroClusterTile/HeroSegmented/HeroMedallion/HeroStatusPill/fmt/Tone/HeroSegItem`) | `resources/js/pages/health-safety/components/hs-hero-kit.tsx` |
| Row primitives (`TONE_BG/TONE_DOT/FlagBadge/RegisterTableHeader/titleCase/initials/entityTone`) | `resources/js/pages/health-safety/components/register-row-kit.tsx` |
| `ShiftCtxItem/ShiftCtxState/ShiftContextMenu` | `resources/js/components/rostering/shift-context-menu.tsx` |
| `RosterTabItem/TabStrip` | `resources/js/components/rostering/tab-strip.tsx` |
| `EntityFilterOption/EntityFilterProps/EntityFilter` | `resources/js/components/rostering/entity-filter.tsx` |
| Detail-dialog contract (`EventDetailDialog`, `EventSectionKey`, `EventActionKey`, `EVENT_CATEGORY_LABELS`) | `resources/js/components/health-safety/event-detail-dialog.tsx` |

## ===== detail-dialog-twin =====

I now have the complete picture: the full `event-detail-dialog.tsx` (both pages), both same-folder helpers (`risk-matrix.tsx`, `event-timeline.tsx`), and the chrome contract (`wizard/shell.tsx`, `wizard/primitives.tsx`). I have everything needed to produce the exact structural blueprint.

---

# HazardDetailDialog — Structural Blueprint (twin of `EventDetailDialog`)

Source studied (all absolute):
- `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\hardcore-zhukovsky-3609cb\resources\js\components\health-safety\event-detail-dialog.tsx` (1759 lines, read in full)
- `…\resources\js\components\health-safety\risk-matrix.tsx`
- `…\resources\js\components\health-safety\event-timeline.tsx`
- `…\resources\js\components\wizard\shell.tsx` (WizardShell + ReviewCard/ReviewRow)
- `…\resources\js\components\wizard\primitives.tsx` (Field, InfoCard, SelectInput, StepHead, Segmented, etc.)

---

## 1. Imports (copy these exactly)

```tsx
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, type WizardStep } from '@/components/wizard/shell';
import { Field, InfoCard, SelectInput, StepHead } from '@/components/wizard/primitives';
import { RiskMatrix } from '@/components/health-safety/risk-matrix';
import { formatDateTime } from '@/lib/datetime';
import { Link, router, useForm } from '@inertiajs/react';
import { /* lucide icons */ type LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState, type ComponentType, type FormEvent, type MutableRefObject, type ReactNode } from 'react';
```

Sub-component import paths available to reuse:
- **RiskMatrix** — `@/components/health-safety/risk-matrix` (props: `likelihood`, `consequence`, `residualLikelihood?`, `residualConsequence?`, `compact?` — all display-only). The Event dialog renders it `compact`.
- **EventTimeline** — `@/components/health-safety/event-timeline`. It is **event-shaped** (props `reportedAt/occurredAt/closedAt/investigations/correctiveActions`). For Hazards, either reuse it as-is by mapping hazard milestones into those props, or write a sibling `hazard-timeline.tsx` with the same internal structure (vertical line + dot + `TimelineEntry[]` sorted chronologically). The component is small (~155 lines) and trivially clonable.
- There is **no shared attachment uploader** wired into this dialog — the Evidence section is **read-only** (lists `attachments[]` with a download `<a>`). If you need upload, `feedback_premium_file_dropzone` notes `components/ui/file-dropzone.tsx` (FileDropzone/AttachmentUploader) is the shared uploader used by Incidents/Safeguarding evidence.
- **No external timeline/matrix beyond these.** Everything else (StageTracker, GateRow, EmptyState, etc.) is defined locally inside the dialog file.

---

## 2. Props interface (verbatim contract pattern)

The dialog's public props (lines 299–318):

```tsx
export function EventDetailDialog({
    detail,
    open,
    onClose,
    initialSection = 'overview',
    initialAction = null,
    initialActionTarget = null,
    openedFrom = null,
}: {
    detail: EventDetail;
    open: boolean;
    onClose: () => void;
    initialSection?: EventSectionKey;
    initialAction?: EventActionKey | null;
    /** Deep-link to a single corrective action's workflow pane (Complete / Verify /
     *  Return), opened from the register row menu (prompt E). */
    initialActionTarget?: { actionId: number; pane: 'complete' | 'verify' | 'return' } | null;
    /** Set when arrived via a source module's "Open in Health & Safety" jump. */
    openedFrom?: string | null;
}) {
```

Twin for hazards:

```tsx
export function HazardDetailDialog({
    detail,
    open,
    onClose,
    initialSection = 'overview',
    initialAction = null,
    initialActionTarget = null,
    openedFrom = null,
}: {
    detail: HazardDetail;
    open: boolean;
    onClose: () => void;
    initialSection?: HazardSectionKey;
    initialAction?: HazardActionKey | null;
    initialActionTarget?: { actionId: number; pane: 'complete' | 'verify' | 'return' } | null;
    openedFrom?: string | null;
}) {
```

### The data contract type (`EventDetail`, lines 146–180) — the part you mirror

This is the load-bearing type; it mirrors the controller's `buildEventDetail()`. Reproduced verbatim so you can shape `HazardDetail` against it:

```tsx
export type EventDetail = {
    id: number;
    reference_number: string;
    event_category: string;
    severity: string;
    status: string;
    occurred_at: string | null;
    reported_at: string | null;
    description: string | null;
    site: { id: number; name: string } | null;
    client: { id: number; name: string } | null;
    staff: { id: number; name: string } | null;
    asset: { id: number; name: string } | null;
    worksafe_notifiable: boolean;
    worksafe_status: string | null;
    worksafe_reference: string | null;
    worksafe_notified_at: string | null;
    worksafe_acknowledged_at: string | null;
    worksafe_method: string | null;
    worksafe_site_preserved: boolean;
    worksafe_reason: string | null;
    investigation_required: boolean;
    control_room_alert: { id: number; severity: string; status: string } | null;
    closed_at: string | null;
    closure_summary: string | null;
    created_by_name: string | null;
    source: EventSource | null;
    investigations: EventInvestigation[];
    corrective_actions: EventCorrectiveAction[];
    risk_assessments: EventRiskAssessment[];
    attachments: EventAttachment[];
    close_gate: { investigation_ok: boolean; actions_ok: boolean; blockers: string[] };
    assignable_staff: Array<{ id: number; name: string }>;
    can: { manage: boolean };
};
```

Supporting nested types you also clone (full definitions, lines 58–144): `EventInvestigation`, `EventCorrectiveAction` (note `can_verify: boolean` — the separation-of-duties gate), `EventRiskAssessment` (likelihood/consequence/residual_* fed straight into `<RiskMatrix>`), `EventAttachment` (with `download_url`), `EventSource` (`{ type, id, label, url, unwired }` — the originating-record jump). 

Key gating fields to keep in `HazardDetail`:
- `can: { manage: boolean }` — drives every write surface.
- `status` — `'closed'` short-circuits all actions (`canAct = d.can.manage && d.status !== 'closed'`).
- `close_gate: { …; blockers: string[] }` — non-empty `blockers` reddens/annotates the Close button.
- per-corrective-action `can_verify` — gates the Verify button (a different person must verify).

### Section + action key unions (lines 182–185)

```tsx
export type EventSectionKey = 'overview' | 'investigation' | 'actions' | 'risk' | 'timeline' | 'evidence';
/** Event-level launchers (Options bar / row menu). Per-item workflow panes are
 *  opened from inside the Investigation / Corrective-actions sections. */
export type EventActionKey = 'close' | 'worksafe_notify' | 'worksafe_acknowledge' | 'investigation' | 'add_action';
```

For a hazard you'd likely have e.g. `HazardSectionKey = 'overview' | 'risk' | 'controls' | 'actions' | 'reviews' | 'timeline' | 'evidence'` and `HazardActionKey = 'close' | 'reassess' | 'add_control' | 'add_action' | …` — same shape, different members.

---

## 3. WizardShell usage (the chrome)

`WizardShell` (defined `…/wizard/shell.tsx` lines 27–210) renders: a 248px stepper rail (collapses below `sm`), a "Step x of y · {label}" header with a close X, a 3px progress strip, a scroll-contained body (`children`), and a muted footer band split into `footerStart` (left) / `footerEnd` (right). It owns the `Dialog`, focus-trap, and a11y. Width is `min(94vw, 980px)`; body height `min(88vh, 760px)`.

The exact call (lines 435–482):

```tsx
return (
    <WizardShell
        open={open}
        onClose={onClose}
        title={`Event ${d.reference_number}`}
        description={`${cat} — ${stage.label}`}
        railIcon={ShieldAlert}
        railTitle={d.reference_number}
        railSub={`${cat} · ${sev.label}`}
        steps={SECTIONS as readonly WizardStep[]}
        stepIndex={stepIndex}
        onStepClick={(i) => setSection(SECTIONS[i].key)}
        pct={null}
        footerStart={footerStart}
        footerEnd={footerEnd}
    >
        {pane ? (
            <PaneRenderer pane={pane} d={d} onDone={() => setPane(null)} />
        ) : (
            <>
                {/* openedFrom InfoCard + the active section */}
            </>
        )}
    </WizardShell>
);
```

Props to mirror:
- `title` / `description` — screen-reader only (visually hidden `DialogTitle`/`DialogDescription`).
- `railIcon` — single Lucide icon for the rail medallion (use a hazard-appropriate one, e.g. `AlertTriangle` / `TriangleAlert`).
- `railTitle` / `railSub` — `reference_number` and a `category · severity` subline.
- `steps` — your `SECTIONS` array cast to `readonly WizardStep[]`. **`WizardStep` shape is `{ key; label; blurb; icon }`** (shell.tsx lines 20–25), which is exactly the section descriptor shape, so the cast is safe.
- `stepIndex` — index of the active section.
- `onStepClick(i)` — set the active section from the clicked rail index.
- `pct={null}` — detail dialogs pass `null` so the completeness bar in the rail is hidden (it only renders when `pct != null`). The header's thin 3px strip still fills proportionally to `stepIndex`.
- `footerStart` / `footerEnd` — the two footer slots (see §6).
- (`success` prop exists but detail dialogs don't use it — that's for the create-wizard green-check pane.)

---

## 4. Rail / section navigation mechanism

State + section descriptor array (lines 319, 365–373):

```tsx
const [section, setSection] = useState<EventSectionKey>(initialActionTarget ? 'actions' : initialSection);
// …
const SECTIONS: { key: EventSectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
    { key: 'overview',      label: 'Overview',           blurb: 'Governance & origin',                                          icon: FileText },
    { key: 'investigation', label: 'Investigation',      blurb: activeInv ? titleCase(activeInv.status) : 'none yet',           icon: Search },
    { key: 'actions',       label: 'Corrective actions', blurb: openActions > 0 ? `${openActions} open` : `${d.corrective_actions.length} total`, icon: ListChecks },
    { key: 'risk',          label: 'Risk',               blurb: d.risk_assessments.length ? `${d.risk_assessments.length} assessed` : 'none', icon: Activity },
    { key: 'timeline',      label: 'Timeline',           blurb: 'Audit trail',                                                  icon: History },
    { key: 'evidence',      label: 'Evidence',           blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`, icon: Paperclip },
];
const stepIndex = Math.max(0, SECTIONS.findIndex((s) => s.key === section));
```

- `blurb` is **dynamic** — it reflects live counts/status (e.g. "3 open", "2 assessed"). Mirror that for hazard sections (e.g. controls count, next-review date, open-actions).
- The rail draws itself from `steps`; you only feed it the array + `stepIndex` + `onStepClick`. Completed steps (index `< stepIndex`) get a green check automatically — for a non-linear detail view that's cosmetic and fine.

Body switch (lines 463–478) — a flat chain of ternaries, one per section:

```tsx
{section === 'overview' ? <OverviewSection d={d} cat={cat} stage={stage} /> : null}
{section === 'investigation' ? <InvestigationSection d={d} canAct={canAct} onPane={setPane} /> : null}
{section === 'actions' ? (
    <ActionsSection
        d={d}
        openActions={openActions}
        awaitingVerification={awaitingVerification}
        canAct={canAct}
        onPane={setPane}
        rowRefs={actionRowRefs}
        highlightActionId={highlightActionId}
    />
) : null}
{section === 'risk' ? <RiskSection d={d} /> : null}
{section === 'timeline' ? <TimelineSection d={d} /> : null}
{section === 'evidence' ? <EvidenceSection d={d} /> : null}
```

Note `onPane={setPane}` is how a section opens a workflow pane (see §5/§6); `canAct` is threaded so sections hide write controls in read-only/closed state.

---

## 5. Pane mechanism (workflow form takes over the body)

### Pane state model (lines 265–293)

A discriminated union of every workflow form. Event-level panes are launched from the Options bar; per-item panes carry the target id:

```tsx
type ActivePane =
    | { kind: 'close' }
    | { kind: 'worksafe_notify' }
    | { kind: 'worksafe_acknowledge' }
    | { kind: 'inv_start' }
    | { kind: 'inv_findings'; investigationId: number }
    | { kind: 'inv_complete'; investigationId: number }
    | { kind: 'inv_return'; investigationId: number }
    | { kind: 'ca_add' }
    | { kind: 'ca_complete'; actionId: number }
    | { kind: 'ca_verify'; actionId: number }
    | { kind: 'ca_return'; actionId: number };

function paneFromAction(action: EventActionKey | null): ActivePane | null {
    switch (action) {
        case 'close': return { kind: 'close' };
        case 'worksafe_notify': return { kind: 'worksafe_notify' };
        case 'worksafe_acknowledge': return { kind: 'worksafe_acknowledge' };
        case 'investigation': return { kind: 'inv_start' };
        case 'add_action': return { kind: 'ca_add' };
        default: return null;
    }
}
```

Pane state hook (line 320):
```tsx
const [pane, setPane] = useState<ActivePane | null>(() =>
    initialActionTarget ? { kind: CA_TARGET_PANE[initialActionTarget.pane], actionId: initialActionTarget.actionId } : paneFromAction(initialAction),
);
```

### How the pane suppresses the body and the bar

Two places:
1. **Body** — inside `WizardShell`, `pane ? <PaneRenderer …/> : <>…sections…</>`. When a pane is set, the entire section area is replaced by the pane form.
2. **Footer bar** — `footerEnd` short-circuits to `null` when a pane is active (line 397: `const footerEnd = pane ? null : (…)`). So the pane owns its own Cancel/Submit buttons and the Options bar disappears. `footerStart` (the severity/stage chips) stays visible the whole time.

### PaneRenderer (router, lines 678–707) — resolves the target record then dispatches

```tsx
function PaneRenderer({ pane, d, onDone }: { pane: ActivePane; d: EventDetail; onDone: () => void }) {
    const inv = 'investigationId' in pane ? (d.investigations.find((i) => i.id === pane.investigationId) ?? null) : null;
    const ca  = 'actionId' in pane ? (d.corrective_actions.find((a) => a.id === pane.actionId) ?? null) : null;

    switch (pane.kind) {
        case 'close':                return <CloseEventPane d={d} onDone={onDone} />;
        case 'worksafe_notify':      return <WorksafeNotifyPane d={d} onDone={onDone} />;
        case 'worksafe_acknowledge': return <WorksafeAcknowledgePane d={d} onDone={onDone} />;
        case 'inv_start':            return <StartInvestigationPane d={d} onDone={onDone} />;
        case 'inv_findings':         return inv ? <RecordFindingsPane d={d} inv={inv} onDone={onDone} /> : null;
        case 'inv_complete':         return inv ? <CompleteInvestigationPane d={d} inv={inv} onDone={onDone} /> : null;
        case 'inv_return':           return inv ? <ReturnInvestigationPane d={d} inv={inv} onDone={onDone} /> : null;
        case 'ca_add':               return <AddCorrectiveActionPane d={d} onDone={onDone} />;
        case 'ca_complete':          return ca ? <CompleteActionPane d={d} ca={ca} onDone={onDone} /> : null;
        case 'ca_verify':            return ca ? <VerifyActionPane d={d} ca={ca} onDone={onDone} /> : null;
        case 'ca_return':            return ca ? <ReturnActionPane d={d} ca={ca} onDone={onDone} /> : null;
    }
}
```

`onDone` is always `() => setPane(null)` (passed from the WizardShell child), which closes the pane back to the section view.

---

## 6. The Options footer bar (verbatim, lines 391–433)

```tsx
const canAct = d.can.manage && d.status !== 'closed';
const blockers = d.close_gate?.blockers ?? [];

// Options bar — suppressed while a pane owns the body + its own buttons. Write
// actions appear only when they can run (no stubs). Investigation / corrective-
// action workflows live inline in their sections (and the row menu).
const footerEnd = pane ? null : (
    <div className="flex flex-wrap items-center gap-2">
        <Link
            href={`/health-safety/events/${d.id}`}
            className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
        >
            <ExternalLink className="h-4 w-4" /> Open full page
        </Link>
        {d.can.manage && d.worksafe_notifiable && d.worksafe_status !== 'acknowledged' ? (
            d.worksafe_status === 'notified' ? (
                <Button size="sm" variant="outline" onClick={() => setPane({ kind: 'worksafe_acknowledge' })}>
                    <ShieldCheck className="mr-1.5 h-4 w-4" /> Record acknowledgement
                </Button>
            ) : (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPane({ kind: 'worksafe_notify' })}
                    className="border-status-critical/40 text-status-critical hover:text-status-critical"
                >
                    <ShieldAlert className="mr-1.5 h-4 w-4" /> Record WorkSafe notification
                </Button>
            )
        ) : null}
        {canAct ? (
            <Button
                size="sm"
                variant="outline"
                onClick={() => setPane({ kind: 'close' })}
                title={blockers.length ? `Closure blocked: ${blockers.join(' ')}` : undefined}
                className={blockers.length ? 'border-status-critical/40 text-status-critical hover:text-status-critical' : ''}
            >
                <CheckCircle2 className="mr-1.5 h-4 w-4" /> Close event
            </Button>
        ) : null}
    </div>
);
```

How buttons are defined / gated / suppressed (the rules to copy):
- **Suppressed during a pane** — the whole `footerEnd` is `null` when `pane` is truthy.
- **"Open full page"** is always present — a plain `<Link>` to the deep-link route `/health-safety/events/{id}` (your hazard deep-link route).
- **Each action button is individually gated** by a boolean guard before it renders (`d.can.manage && …`, `canAct`). No disabled stubs — an action appears only when it can run (per `feedback_hide_unbuilt_actions`). A button that *exists but is blocked* (e.g. Close with open `blockers`) stays clickable but is reddened + given a `title` explaining the block; the pane then enforces the override.
- **Clicking a button sets a pane** (`onClick={() => setPane({ kind: '…' })}`), which simultaneously swaps the body and hides the bar.
- `footerStart` (lines 375–389) is the always-on status strip — severity chip, stage chip, and (if notifiable) a WorkSafe chip. Mirror with hazard chips (e.g. risk-level chip, status chip, "review overdue" chip).

`footerStart` verbatim for the pattern:

```tsx
const footerStart = (
    <div className="flex flex-wrap items-center gap-2 text-xs">
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${sev.chip}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${sev.dot}`} /> {sev.label}
        </span>
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium ${stage.chip}`}>
            <stage.icon className="h-3 w-3" /> {stage.label}
        </span>
        {/* notifiable chip … */}
    </div>
);
```

Per-row workflow buttons (inside sections, **not** the footer) follow the same `onClick={() => onPane({ kind: '…', actionId })}` pattern — see `CorrectiveActionControls` (lines 1181–1230) and `InvestigationControls` (971–999). Some trivial transitions skip the pane and `router.post` directly (e.g. `Start`, `Submit for review`, `Close action` — line 983, 1191, 1216).

---

## 7. `initialAction` → opens straight onto a pane

Three coordinated mechanisms:

**(a) Initial state** derives the pane from props on first mount (line 320, shown above): `initialActionTarget` (deep-link to a specific corrective action) wins, else `paneFromAction(initialAction)`.

**(b) `initialActionTarget` also forces the section** (line 319): `useState(initialActionTarget ? 'actions' : initialSection)`.

**(c) Re-sync effect** (lines 346–356) — because the register keys the dialog by **event id**, opening a *different* action on the *same* event does **not** remount the component. This effect re-derives section+pane when the incoming prop *values* change, without clobbering the user's in-dialog navigation:

```tsx
useEffect(() => {
    if (initialActionTarget) {
        setSection('actions');
        setPane({ kind: CA_TARGET_PANE[initialActionTarget.pane], actionId: initialActionTarget.actionId });
        setHighlightActionId(initialActionTarget.actionId);
    } else {
        setSection(initialSection);
        setPane(paneFromAction(initialAction));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- sync only on incoming prop-value changes; the local setters are stable and intentionally excluded
}, [initialActionTarget?.actionId, initialActionTarget?.pane, initialSection, initialAction]);
```

**(d) Deep-link scroll/highlight** (lines 295–297, 324–338) — `CA_TARGET_PANE = { complete: 'ca_complete', verify: 'ca_verify', return: 'ca_return' }`. A `useRef<Record<number, HTMLDivElement|null>>` holds per-row refs; an effect scrolls the target row into view and rings it for 2.2s:

```tsx
useEffect(() => {
    const targetId = initialActionTarget?.actionId;
    if (targetId == null) return;
    const node = actionRowRefs.current[targetId];
    if (!node) return;
    node.scrollIntoView({ block: 'center', behavior: 'smooth' });
    setHighlightActionId(targetId);
    const timer = window.setTimeout(() => setHighlightActionId(null), 2200);
    return () => window.clearTimeout(timer);
}, [initialActionTarget?.actionId, pane]);
```

Carry all four over verbatim if your hazard register also deep-links per-action panes; if not, you can drop (b)/(c)/(d) and keep just `paneFromAction(initialAction)`.

---

## 8. Read-only mode handling

There is no boolean `readOnly` prop — read-only is **derived** and threaded down:

```tsx
const canAct = d.can.manage && d.status !== 'closed';
```

- `canAct` is passed to `InvestigationSection`, `ActionsSection`, and used to gate the footer Close button and the per-row control clusters.
- Inside sections, every write affordance is wrapped `{canAct ? <Button…/> : null}` (e.g. "Start investigation" 1446, "Add corrective action" 1583/1593, "Seed action" 1498).
- `CorrectiveActionControls` *also* guards independently (line 1184): `if (!d.can.manage || d.status === 'closed') return null;` — defense in depth so a row never shows controls in a closed/unmanaged hazard.
- The WorkSafe footer cluster gates on `d.can.manage` directly (not `canAct`) because acknowledging a notification can be valid even on an otherwise-closed record — note the subtle distinction when porting.
- When `can.manage` is false, the dialog degrades to a clean read-only governance view: all sections still render their data (Overview, Risk, Timeline, Evidence are read-only by nature), just with no action buttons and no Options bar actions beyond "Open full page".

Mirror exactly: compute `const canAct = d.can.manage && d.status !== 'closed';` once, thread it to sections, and double-guard control clusters.

---

## 9. How mutating panes POST + refresh in place + close

Every pane uses Inertia `useForm`, posts to a REST-ish action route, and on success calls `onDone()` (= `setPane(null)`). **The refresh is implicit**: an Inertia `router`/`useForm` POST returns a fresh Inertia page response, so the controller re-sends updated props and the parent page (and thus `detail`) re-renders with the new state — no manual reload call. `preserveScroll: true` (and `preserveState: true` on the corrective-action panes) keep the modal mounted and scrolled while props swap underneath.

The universal success guard handles the "blocked-but-302" case (a gate failure comes back as `flash.error` on a 302, **not** a 422 — see `reference_inertia_flash_error`): only close the pane if there's no flash error, otherwise stay open so the user can correct/override.

### One full example pane (verbatim) — `CloseEventPane` (lines 489–556)

This is the richest pane (gate checklist + conditional override field) and the best template:

```tsx
function CloseEventPane({ d, onDone }: { d: EventDetail; onDone: () => void }) {
    const gate = d.close_gate;
    const blocked = (gate?.blockers.length ?? 0) > 0;
    const form = useForm<{ closure_summary: string; override_reason: string }>({ closure_summary: '', override_reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // A blocked closure comes back on a 302 as flash.error (not 422) — keep the
        // pane open so the user can record an override reason.
        form.post(`/health-safety/events/${d.id}/close`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead
                icon={CheckCircle2}
                title="Close event"
                blurb="A required investigation must be complete and every corrective action verified — or close with a logged override. A closure summary is always required."
            />

            {/* eslint-disable-next-line no-restricted-syntax -- closure gate checklist surface */}
            <div className="flex flex-col gap-2 rounded-xl border border-border bg-card/70 p-3">
                <GateRow ok={gate?.investigation_ok ?? true} label="Required investigation complete" />
                <GateRow ok={gate?.actions_ok ?? true} label="All corrective actions verified or closed" />
            </div>

            {blocked ? (
                <InfoCard icon={AlertTriangle} tone="crit">
                    This event does not meet the closure gate yet. You can still close it by recording an override reason — the override is logged for the audit trail.
                </InfoCard>
            ) : null}

            <Field label="Closure summary" required error={form.errors.closure_summary}>
                <Textarea
                    rows={4}
                    value={form.data.closure_summary}
                    onChange={(e) => form.setData('closure_summary', e.target.value)}
                    placeholder="How was this event resolved? What did the investigation and corrective actions conclude?"
                />
            </Field>

            {blocked ? (
                <Field label="Override reason" required hint="Logged" error={form.errors.override_reason}>
                    <Textarea
                        rows={3}
                        value={form.data.override_reason}
                        onChange={(e) => form.setData('override_reason', e.target.value)}
                        placeholder="Why is this event being closed despite the open gate?"
                    />
                </Field>
            ) : null}

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Close event
                </Button>
            </div>
        </form>
    );
}
```

### Pane anatomy (every pane follows this skeleton)

1. Signature: `function XxxPane({ d, onDone }: { d: EventDetail; onDone: () => void })` — per-item panes add `inv` or `ca`.
2. `const form = useForm<{ … }>({ …defaults… });` (defaults often seeded from existing record, e.g. `RecordFindingsPane` line 855, or `todayInput()` for dates, line 585).
3. `const submit = (e: FormEvent) => { e.preventDefault(); form.post(URL, { preserveScroll: true, [preserveState: true], onSuccess: (page) => { if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone(); } }); };`
4. JSX: `<form onSubmit={submit} className="flex flex-col gap-4">` → `<StepHead icon title blurb />` → optional `<InfoCard tone>` → `<Field label required hint error={form.errors.X}>` wrapping `<Input>/<Textarea>/<SelectInput>` → footer `<div className="flex justify-end gap-2">` with a `type="button"` Cancel (`onClick={onDone}`) and a `type="submit"` button `disabled={form.processing}` (richer panes show `<Loader2 className="… animate-spin" />` while processing, e.g. line 1058).

### POST route conventions (mirror with `/health-safety/hazards/{id}/…`)

| Pane | Route (verbatim) | Extras |
|---|---|---|
| Close | `POST /health-safety/events/${d.id}/close` | `preserveScroll` |
| WorkSafe notify | `POST …/${d.id}/worksafe/notify` | `preserveScroll` |
| WorkSafe ack | `POST …/${d.id}/worksafe/acknowledge` | |
| Start investigation | `POST …/${d.id}/investigations` | |
| Record findings | `POST …/${d.id}/investigations/${inv.id}/findings` | `form.transform()` strips empty rows (lines 865–871) |
| Complete inv. | `POST …/${d.id}/investigations/${inv.id}/complete` | |
| Return inv. | `POST …/${d.id}/investigations/${inv.id}/return` | |
| Submit for review (no pane) | `router.post(`${base}/submit`, {}, { preserveScroll: true })` | inline button |
| Seed action (no pane) | `router.post(…/investigations/${inv.id}/seed-action, { recommendation_index: i }, { preserveScroll: true })` | inline button |
| Add corrective action | `POST …/${d.id}/corrective-actions` | `preserveScroll, preserveState` |
| Complete action | `POST …/${d.id}/corrective-actions/${ca.id}/complete` | `preserveScroll, preserveState` |
| Verify action | `POST …/${d.id}/corrective-actions/${ca.id}/verify` | `preserveScroll, preserveState` |
| Return action | `POST …/${d.id}/corrective-actions/${ca.id}/return` | `preserveScroll, preserveState` |
| Start action (no pane) | `router.post(`${base}/start`, {}, { preserveScroll, preserveState })` | inline |
| Close action (no pane) | `router.post(`${base}/close`, {}, { preserveScroll, preserveState })` | inline |

Two router idioms appear: **`useForm().post`** for forms with fields; **`router.post(url, {}, {…})`** for one-click stateless transitions.

---

## 10. Local sub-components inside the dialog file (clone these too)

All defined within `event-detail-dialog.tsx`; none are imported:
- **`StageTracker`** (1236–1262) — horizontal lifecycle pill chain driven by `STAGE_ORDER`/`STAGE` maps (each stage = `{ label, chip, dot, icon }`), with `ChevronRight` separators and `aria-current="step"`. Rendered at the top of Overview. For hazards, replace `STAGE_ORDER` with your hazard lifecycle.
- **`InvestigationGate`** (1411–1435) — same pattern for the investigation sub-lifecycle (`INV_ORDER`/`INV_STAGE`).
- **`GateRow`** (558–569) — green-check / red-triangle checklist line (used in the close gate).
- **`OverviewSection`** (1268–1330) — two `<ReviewCard>`s (Event / Context) full of `<ReviewRow>`s, plus `OriginatingRecordCard`, the "What happened" description card, and a closure card. **ReviewCard/ReviewRow come from `@/components/wizard/shell`** and are the canonical read-only field display (`ReviewRow` shows an em-dash for empty values).
- **`OriginatingRecordCard`** (1334–1369) — renders `EventSource` as a clickable jump (or a dashed disabled card when `unwired`/no url).
- **`WorkSafeBanner`** + **`DutyChip`** (1378–1409) — regulatory banner; hazard equivalent might be a "hazard register / WorkSafe notifiable hazard" banner or omit.
- **`InvestigationSection`** (1437–1530), **`Finding`** (1532–1539), **`CauseList`** (1541–1553) — investigation cards + findings rendering + per-recommendation "Seed action" button.
- **`ActionsSection`** (1555–1649) — corrective-action list with per-row refs (`rowRefs`), the deep-link highlight ring, status/priority chips (`CA_STATUS`, `PRIORITY`), overdue styling, and the separation-of-duties footnote. Uses `MutableRefObject<Record<number, HTMLDivElement|null>>`.
- **`CorrectiveActionControls`** (1181–1230) / **`InvestigationControls`** (971–999) — per-row workflow button clusters (the inline-pane launchers).
- **`RiskSection`** (1651–1697) + **`RiskScore`** (1699–1708) — renders each `EventRiskAssessment` with inherent→residual scores and the imported `<RiskMatrix compact … />`.
- **`TimelineSection`** (1710–1720) — thin wrapper over the imported `<EventTimeline … />`.
- **`EvidenceSection`** (1722–1746) — read-only attachment list (FileText icon + name/size/uploader + download `<a href={download_url}>`).
- **`EmptyState`** (1748–1756) — dashed-border empty placeholder used by every section with no rows.

Module-level token maps to clone (lines 191–256): `EVENT_CATEGORY_LABELS`, `SEV`, `STAGE_ORDER`/`STAGE`, `INV_ORDER`/`INV_STAGE`, `CA_STATUS`, `PRIORITY`, plus helpers `titleCase`, `fmtSize`, and `todayInput` (578–582). All colours are semantic tokens (`bg-status-success-bg`, `text-status-critical`, `bg-primary/10`, `--live` etc.) — never colour-only; every flag pairs an icon + `title`.

Default export at the bottom: `export default EventDetailDialog;` (line 1758) — so the hazard twin should `export default HazardDetailDialog;` as well.

---

### Quick build order for the twin
1. Define `HazardDetail` + nested types (mirror §2) against your `buildHazardDetail()` controller payload.
2. Define `HazardSectionKey` / `HazardActionKey`, the `ActivePane` union, `paneFromAction`, and (if deep-linking) `CA_TARGET_PANE` + the highlight refs/effects.
3. Copy the token maps + `titleCase/fmtSize/todayInput` helpers.
4. Build `SECTIONS`, `footerStart`, `footerEnd`, and the `WizardShell` call (§3/§4/§6) verbatim with hazard labels/routes.
5. Port each `*Section`, `StageTracker`/`GateRow`/`EmptyState`/`OriginatingRecordCard`, and the `PaneRenderer` + panes (§5/§9), swapping routes to `/health-safety/hazards/{id}/…`.
6. Reuse `RiskMatrix` directly; reuse `EventTimeline` by mapping props, or clone it as `hazard-timeline.tsx`.

## ===== modal-and-rostering-kits =====

I now have everything needed: the wizard form posts the entire `SiteWizardForm` (including the `documents: SiteDocumentDraft[]` array) via Inertia `useForm` with `forceFormData: true`, which auto-serializes nested File arrays into multipart form-data. That contrasts with `AttachmentUploader`, which posts files one-at-a-time to a single-file endpoint.

I have all the information required. Producing the API reference now.

---

# Wizard, Upload, Pagination & Rostering — API Reference

All paths are absolute under `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\hardcore-zhukovsky-3609cb`.

---

## 1. `resources/js/components/wizard/shell.tsx`

The shared multi-step wizard dialog chrome, extracted from the Add Client wizard (`resources/js/components/clients/add-client-dialog.tsx` — the reference contract for every popup workflow). Provides a 248px stepper rail (collapses below `sm`), a "Step x of y" header with close button, a 3px progress strip, a scrollable body, a muted footer band, and a green-check success pane. Dialog is fixed at `min(94vw, 980px)` wide and the body at `min(88vh, 760px)` tall.

### `type WizardStep`
```ts
export type WizardStep = {
    key: string;
    label: string;
    blurb: string;                                   // sub-label shown under the step label in the rail
    icon: ComponentType<{ className?: string }>;
};
```

### `WizardShell`
The outer dialog + rail + header + footer. You render your own per-step body as `children`.
```ts
function WizardShell(props: {
    open: boolean;
    onClose: () => void;
    /** Screen-reader dialog title/description (visually hidden, sr-only). */
    title: string;
    description: string;
    railIcon: ComponentType<{ className?: string }>; // medallion icon top of the rail
    railTitle: string;                               // bold rail heading
    railSub: string;                                 // small muted rail sub-heading
    steps: readonly WizardStep[];
    stepIndex: number;                               // current step (0-based); drives header, progress bar, rail highlight
    onStepClick: (index: number) => void;            // rail step button → jump to step
    pct?: number | null;                             // optional completeness % → renders rail progress meter; null/undefined hides it
    pctLabel?: string;                               // default 'Completeness'
    footerStart?: ReactNode;                         // left side of footer (e.g. Back button / hint)
    footerEnd?: ReactNode;                           // right side of footer (e.g. Cancel + Continue)
    /** When set, REPLACES the whole shell body (rail + steps). Pass your success pane here. */
    success?: ReactNode;
    children?: ReactNode;                            // the active step's body
}): JSX.Element
```
Usage notes: rail steps before `stepIndex` show a green check; the active one is highlighted. Header auto-renders `Step {stepIndex+1} of {steps.length} · {label}`. The progress strip width is computed automatically from `stepIndex`/`steps.length` (independent of `pct`). Closing via the X or backdrop calls `onClose`.

### `WizardStepPane`
Per-step body wrapper that adds a 300ms fade/slide-in (motion-safe only). Wrap each step's content.
```ts
function WizardStepPane(props: { children: ReactNode }): JSX.Element
```

### `WizardSuccessPane`
Centered green-check success screen. Pass it to `WizardShell`'s `success` prop.
```ts
function WizardSuccessPane(props: {
    title: string;
    blurb: ReactNode;
    actions: ReactNode;                              // buttons row (e.g. "View" / "Add another" / "Close")
}): JSX.Element
```

### `ReviewCard`
A card for the review step, with an optional "Edit" link that jumps back to the owning step.
```ts
function ReviewCard(props: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    onEdit?: () => void;                             // shows a "✎ Edit" link when provided
    span?: boolean;                                  // sm:col-span-2 (full width in a 2-col grid)
    children: ReactNode;
}): JSX.Element
```

### `ReviewRow`
A label/value line inside a `ReviewCard`. Empty values (`null`/`''`) render as an em-dash.
```ts
function ReviewRow(props: {
    label: string;
    value?: ReactNode;                               // null | '' → renders "—"
}): JSX.Element
```

---

## 2. `resources/js/components/wizard/primitives.tsx`

Field/section/input primitives, also extracted from the Add Client wizard. Tokens-only colours.

### Exported chrome constants (string class names — for hand-rolled wizards that want pixel-identical chrome)
```ts
export const WIZARD_RAIL_CLASS: string;            // 248px sidebar, app-sidebar surface
export const WIZARD_PROGRESS_TRACK_CLASS: string;  // 'h-[3px] shrink-0 bg-muted'
export const WIZARD_PROGRESS_BAR_CLASS: string;    // 'h-full bg-primary transition-[width] duration-300'
export const WIZARD_FOOTER_CLASS: string;          // footer band (Back / Cancel / Continue)
```

### `type IconType`
```ts
export type IconType = ComponentType<{ className?: string }>;
```

### `FieldErr`
Inline field-error line (AlertTriangle + critical text). Renders nothing when `children` is falsy.
```ts
function FieldErr(props: { children?: ReactNode }): JSX.Element | null
```

### `Field`
Standard labelled field wrapper: optional label (with required `*` and inline hint) + your control + error line.
```ts
function Field(props: {
    label?: string;
    required?: boolean;                              // adds red "*"
    hint?: string;                                   // small muted note next to the label
    error?: string;                                  // rendered via FieldErr below the control
    span?: boolean;                                  // sm:col-span-2
    children: ReactNode;                             // the input control
}): JSX.Element
```

### `SubHead`
Small uppercase section divider with an icon. Spans the full grid (`col-span-full`).
```ts
function SubHead(props: { icon: IconType; children: ReactNode }): JSX.Element
```

### `StepHead`
The big step header: icon medallion + title + blurb. Place at the top of each step pane.
```ts
function StepHead(props: { icon: IconType; title: string; blurb: string }): JSX.Element
```

### `InfoCard`
Tinted callout box (spans full grid). Three tones.
```ts
function InfoCard(props: {
    icon: IconType;
    tone?: 'info' | 'warn' | 'crit';                // default 'info'
    children: ReactNode;
}): JSX.Element
```

### `SelectInput`
Thin wrapper around the shadcn `Select` returning the raw string value. Empty string → shows placeholder.
```ts
function SelectInput(props: {
    value: string;
    onChange: (v: string) => void;
    placeholder: string;
    options: { value: string; label: string }[];
}): JSX.Element
```

### `Segmented<T extends string>`
Segmented (pill) control; single select. `aria-pressed` on the active segment.
```ts
function Segmented<T extends string>(props: {
    value: T;
    onChange: (v: T) => void;
    options: { value: T; label: string; icon?: IconType }[];
}): JSX.Element
```

### `ChipMulti`
Multi-select rounded chips (toggle in/out of a `string[]`).
```ts
function ChipMulti(props: {
    values: string[];
    onChange: (v: string[]) => void;
    options: string[];
}): JSX.Element
```

### `TilePicker`
Large tile radio-picker (2 or 3 columns); single select keyed by `key`.
```ts
function TilePicker(props: {
    value: string;
    onChange: (v: string) => void;
    options: {
        key: string;
        label: string;
        description?: string;
        icon?: IconType;
        accent?: string;                             // tailwind text-color class for the icon when inactive
        meta?: string;                               // highlighted line under the description (e.g. eligibility)
    }[];
    cols?: 2 | 3;                                    // default 2
}): JSX.Element
```

### `Ring`
SVG completeness ring (used in review steps).
```ts
function Ring(props: { pct: number; size?: number }): JSX.Element   // size default 56
```

---

## 3. `resources/js/components/ui/file-dropzone.tsx` — premium drag-drop upload

This file is the shared upload kit (used by Add Site, Safeguarding evidence, Incident attachments, Fleet incident attachments). There are **two wiring patterns**:

- **`AttachmentUploader`** — for "record already exists" modals. Self-contained: stages files, then **POSTs each file individually** to a single-file endpoint via Inertia `router.post`.
- **`FileDropzone` + `StagedFileCard`** (raw) — for wizards that collect files as part of a larger form and submit them **all at once** with the rest of the payload (Add Site does this).

### `formatFileSize` (helper)
```ts
function formatFileSize(bytes: number): string      // '' for 0/falsy, then 'B' / 'KB' / 'MB'
```

### `FileDropzone`
Chrome-only drag-and-drop zone. It does **not** hold state or upload — it just emits the chosen `File[]` via `onFiles`. Click/Enter/Space opens the native file picker; supports drag-enter/over/leave/drop; clears its hidden input after each emit so the same file can be re-picked.
```ts
function FileDropzone(props: {
    onFiles: (files: File[]) => void;               // called with Array.from(FileList) on pick/drop
    accept?: string;                                 // native <input accept="">
    multiple?: boolean;                              // default true
    title?: string;                                  // default 'Drag & drop files here'
    hint?: string;                                   // default 'PDF, Word, images'; small line under the CTA
    disabled?: boolean;                              // default false; greys out + blocks interaction
}): JSX.Element
```

### `StagedFileCard`
A premium card for one staged `File`: image thumbnail (for `image/*`, via `URL.createObjectURL`, auto-revoked on unmount) or a file glyph, name, formatted size, a remove (trash) button, and an optional metadata row rendered as `children` (e.g. title/category/expiry inputs, or a note + sensitive checkbox).
```ts
function StagedFileCard(props: {
    file: File;
    onRemove: () => void;
    children?: ReactNode;                            // optional per-file metadata controls
}): JSX.Element
```

### `AttachmentUploader`
End-to-end uploader for existing-record modals. Internally stages files (`FileDropzone` + `StagedFileCard`), optionally collects a per-file **note** and/or **sensitive** flag, then on "Upload N files" **uploads sequentially** — each file POSTs to `endpoint` as multipart `FormData`, and drops out of the staged list on success so the remainder reads as a progress queue. On any error it stops and shows a generic retry message.
```ts
function AttachmentUploader(props: {
    endpoint: string;                                // single-file POST target (one request per file)
    /** Form field name for an optional per-file note (omit/null to hide the note input). */
    noteField?: string | null;                       // default null
    /** Optional per-file sensitive toggle: { field, label }. Submits '1'/'0'. */
    sensitive?: { field: string; label: string } | null;  // default null
    accept?: string;
    hint?: string;
}): JSX.Element
```

**Internal staged shape** (not exported): `{ id: number; file: File; note: string; sensitive: boolean }`.

**What it POSTs per file** (multipart `FormData` via `router.post(endpoint, fd, { preserveScroll: true, preserveState: true, ... })`):
```
file       = <File>
<noteField>      = <note string>          // only if noteField set
<sensitive.field> = '1' | '0'             // only if sensitive set
```
Server contract therefore is a **single-file** endpoint keyed on `file`, plus whatever `noteField`/`sensitive.field` you name. There is no progress bar — files visibly disappear from the list as each request resolves.

---

### How existing callers wire it

**Incident attachments — `resources/js/components/incidents/incident-detail-dialog.tsx` (line ~520)** — simplest form, no note/sensitive, gated by draft + permission:
```tsx
import { AttachmentUploader } from '@/components/ui/file-dropzone';

// Attachments are only mutable while the incident is a draft (server guardrail).
const canEdit = d.status === 'draft' && d.can.update;
return (
    <div className="flex flex-col gap-3">
        {canEdit ? (
            <AttachmentUploader
                endpoint={`/incidents/${d.id}/attachments`}
                hint="PDF, Word, images — up to 10 MB each"
            />
        ) : null}
        {/* existing d.attachments rendered below */}
    </div>
);
```
Each file POSTs to `/incidents/{id}/attachments` with just `file`.

**Safeguarding evidence — `resources/js/components/safeguarding/concern-dialog.tsx` (line ~1140)** — full form with note + per-file sensitive flag:
```tsx
import { AttachmentUploader } from '@/components/ui/file-dropzone';

const canEdit = !!d.can?.update;
return (
    <div className="flex flex-col gap-3">
        {canEdit ? (
            <AttachmentUploader
                endpoint={`/safeguarding/${d.id}/attachments`}
                noteField="notes"
                sensitive={{ field: 'is_sensitive', label: 'Sensitive — restrict to cleared staff' }}
                hint="PDF, Word, images — up to 10 MB each"
            />
        ) : null}
        {/* … */}
    </div>
);
```
Each file POSTs to `/safeguarding/{id}/attachments` with `file`, `notes`, and `is_sensitive` (`'1'`/`'0'`). (Fleet uses the same `AttachmentUploader` pattern in `resources/js/components/fleet/fleet-incident-dialog.tsx`.)

**Add Site — `resources/js/components/sites/add-site-dialog.tsx`** — uses the **raw `FileDropzone` + `StagedFileCard`** instead, because documents are part of the create wizard and are submitted together with the whole site, not to a single-file endpoint. It keeps its own staged-file state on the Inertia form and submits via `forceFormData`.

Document draft type (line 167):
```ts
export type SiteDocumentDraft = {
    file: File;
    title: string;
    category: string;
    expiry_date: string;
};
// on the wizard form: documents: SiteDocumentDraft[]  (initialised to [])
```
Stage/edit/remove helpers (line ~1309):
```tsx
const addFiles = (files: File[]) => {
    const drafts: SiteDocumentDraft[] = files.map((file) => ({
        file,
        title: file.name.replace(/\.[^.]+$/, ''),    // default title = filename w/o extension
        category: 'compliance',
        expiry_date: '',
    }));
    set('documents', [...data.documents, ...drafts]);
};
const update = (i: number, patch: Partial<SiteDocumentDraft>) =>
    set('documents', data.documents.map((d, idx) => (idx === i ? { ...d, ...patch } : d)));
const remove = (i: number) =>
    set('documents', data.documents.filter((_, idx) => idx !== i));
```
Render (line ~1334) — note the per-file metadata row passed as `StagedFileCard` children:
```tsx
<FileDropzone onFiles={addFiles} hint="PDF, Word, images — up to 50 MB each" />
{data.documents.length > 0 ? (
    <div className="grid gap-2">
        {data.documents.map((d, i) => (
            <StagedFileCard key={i} file={d.file} onRemove={() => remove(i)}>
                <div className="grid gap-2 sm:grid-cols-[1.4fr_1fr_1fr]">
                    <Input value={d.title} onChange={(e) => update(i, { title: e.target.value })} placeholder="Title" />
                    {/* category select … */}
                    <Input value={d.expiry_date} onChange={(e) => update(i, { expiry_date: e.target.value })} className="h-8" />
                </div>
            </StagedFileCard>
        ))}
    </div>
) : null}
```
Submit (line ~492) — the entire form (including the `documents` File array) is posted in one request; Inertia's `forceFormData: true` auto-serializes the nested `File`s into multipart form-data:
```tsx
const form = useForm<SiteWizardForm>(initialForm());   // includes documents: SiteDocumentDraft[]
// …
form.post('/sites', {
    forceFormData: true,
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { /* setDone / resetAll */ },
    onError: (errs) => { /* jump to step of first error */ },
});
```

**Pattern summary:** use `AttachmentUploader` when the parent record already exists and you have a single-file POST endpoint (one request per file, optional note/sensitive). Use raw `FileDropzone` + `StagedFileCard` when files are gathered as part of a larger `useForm` payload and submitted together (`forceFormData: true`), carrying arbitrary per-file metadata in your own draft type.

---

## 4. `resources/js/components/ui/laravel-pagination.tsx`

Renders Laravel's paginator `links` array as shadcn `Button`s; prev/next become chevron icon buttons, the rest are numbered. Navigates with Inertia `router.get(url, {}, { preserveState })`. **Returns `null`** when there are ≤3 links (just prev/·/next, i.e. a single page) or when `lastPage <= 1`.

```ts
// internal link shape (from Laravel paginator ->links / ->withQueryString())
interface PaginationLink { url: string | null; label: string; active: boolean; }

function LaravelPagination(props: {
    links: PaginationLink[];                         // paginator.links (with HTML entity labels « / »)
    lastPage?: number;                               // optional; <=1 hides the control
    className?: string;
    preserveState?: boolean;                         // default true; passed to router.get
}): JSX.Element | null
```
Usage:
```tsx
<LaravelPagination links={incidents.links} lastPage={incidents.last_page} />
```

---

## 5. `resources/js/components/rostering/index.ts` — re-exported components

`index.ts` is a barrel re-exporting the whole rostering kit. The four requested symbols and their source files:

| Symbol | Source file |
|---|---|
| `ShiftContextMenu`, `type ShiftCtxItem`, `type ShiftCtxState` | `resources/js/components/rostering/shift-context-menu.tsx` |
| `TabStrip`, `type RosterTabItem` (+ `type RosterTabTone`) | `resources/js/components/rostering/tab-strip.tsx` |
| `EntityFilter` (+ `type EntityFilterOption`) | `resources/js/components/rostering/entity-filter.tsx` |

### 5a. `ShiftContextMenu` — `shift-context-menu.tsx`
A portal-rendered right-click context menu (fixed-positioned, 280px, auto-flips to stay in viewport via `offsetWidth/Height` measurement, caps height to viewport with internal scroll, closes on outside-click / Escape). You build the items list yourself.

```ts
export type ShiftCtxItem =
    | { sep: true }                                  // a separator row
    | {
          sep?: false;
          icon: ReactNode;
          label: string;
          sub?: string;                              // small second line
          kbd?: string;                              // keyboard-shortcut badge on the right
          tone?: 'primary' | 'critical';             // tints the row + icon chip
          onClick?: () => void;                      // fires, then auto-closes
      };

export type ShiftCtxState = {
    x: number;                                        // anchor (usually mouse clientX)
    y: number;                                        // anchor (usually mouse clientY)
    tag: string;                                      // small uppercase badge in the header
    tagBg?: string;                                   // inline style background for the badge
    tagColor?: string;                                // inline style text colour for the badge
    meta: string;                                     // muted header line (e.g. who/when)
    items: ShiftCtxItem[];
};

function ShiftContextMenu(props: {
    ctx: ShiftCtxState;                              // render only when you have a ctx (open state)
    onClose: () => void;
}): React.ReactPortal
```
Usage snippet (open on right-click, store ctx as nullable state):
```tsx
import { ShiftContextMenu, type ShiftCtxState } from '@/components/rostering';
import { Eye, Pencil, Trash2 } from 'lucide-react';

const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

<tr
    onContextMenu={(e) => {
        e.preventDefault();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'INC-0123',
            meta: 'Reported 2d ago · Jane Doe',
            items: [
                { icon: <Eye className="h-4 w-4" />, label: 'View', onClick: () => open(row.id) },
                { icon: <Pencil className="h-4 w-4" />, label: 'Edit', tone: 'primary', onClick: () => edit(row.id) },
                { sep: true },
                { icon: <Trash2 className="h-4 w-4" />, label: 'Delete', tone: 'critical', kbd: 'Del', onClick: () => del(row.id) },
            ],
        });
    }}
/>

{ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
```

### 5b. `TabStrip` — `tab-strip.tsx`
A roving-tabindex tablist (Arrow/Home/End keyboard nav, `role="tab"`/`aria-selected`), each tab with an icon chip, a tone, and an optional badge. Controlled by `value`/`onChange`.

```ts
export type RosterTabTone =
    | 'primary' | 'warning' | 'success' | 'info' | 'violet' | 'critical';

export type RosterTabItem = {
    id: string;
    label: string;
    icon: ComponentType<{ className?: string }>;
    tone: RosterTabTone;                             // colour of the active state + chip
    badge?: ReactNode;                               // optional count pill on the right
};

function TabStrip(props: {
    value: string;                                   // active tab id
    onChange: (next: string) => void;
    items: RosterTabItem[];
    className?: string;
    ariaLabel?: string;                              // default 'Roster views'
}): JSX.Element
```
Usage snippet:
```tsx
import { TabStrip, type RosterTabItem } from '@/components/rostering';
import { List, AlertTriangle, CheckCircle2 } from 'lucide-react';

const TABS: RosterTabItem[] = [
    { id: 'all',    label: 'All',      icon: List,          tone: 'primary' },
    { id: 'open',   label: 'Open',     icon: AlertTriangle, tone: 'warning', badge: 4 },
    { id: 'closed', label: 'Closed',   icon: CheckCircle2,  tone: 'success' },
];

const [tab, setTab] = useState('all');
<TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Incident views" />
```

### 5c. `EntityFilter` — `entity-filter.tsx`
A searchable single-select filter pill (Popover + `cmdk` Command). Shows "{allLabel} · {count}" when nothing is selected, the entity name + a clear (X) button when one is. Selecting `null` = "All". Has an `onDark` variant for placement on a coloured hero.

```ts
export type EntityFilterOption = {
    id: number;
    name: string;
    description?: string | null;                     // optional second line + searchable text
};

export type EntityFilterProps = {
    label: string;                                    // singular noun, e.g. 'Client'
    allLabel: string;                                 // e.g. 'All clients'
    items: EntityFilterOption[];
    value: number | null;                             // selected id, or null for "All"
    onChange: (next: number | null) => void;
    onDark?: boolean;                                 // default false; light-on-dark styling for hero placement
    className?: string;
    /** Plural for placeholders/empty states. Defaults to label+'s'; override for uncountables ('staff'). */
    pluralLabel?: string;
};

function EntityFilter(props: EntityFilterProps): JSX.Element
```
Usage snippet:
```tsx
import { EntityFilter, type EntityFilterOption } from '@/components/rostering';

const clients: EntityFilterOption[] = props.clients.map((c) => ({
    id: c.id, name: c.name, description: c.site_name,
}));
const [clientId, setClientId] = useState<number | null>(null);

<EntityFilter
    label="Client"
    allLabel="All clients"
    items={clients}
    value={clientId}
    onChange={setClientId}
/>

// uncountable noun → override the plural:
<EntityFilter label="Staff" allLabel="All staff" pluralLabel="staff"
    items={staff} value={staffId} onChange={setStaffId} onDark />
```

---

### Quick import reference
```ts
// wizard
import {
    WizardShell, WizardStepPane, WizardSuccessPane, ReviewCard, ReviewRow, type WizardStep,
} from '@/components/wizard/shell';
import {
    Field, FieldErr, SubHead, StepHead, InfoCard, SelectInput, Segmented, ChipMulti, TilePicker, Ring,
    type IconType, WIZARD_RAIL_CLASS, WIZARD_PROGRESS_TRACK_CLASS, WIZARD_PROGRESS_BAR_CLASS, WIZARD_FOOTER_CLASS,
} from '@/components/wizard/primitives';

// upload
import { FileDropzone, StagedFileCard, AttachmentUploader, formatFileSize } from '@/components/ui/file-dropzone';

// pagination
import { LaravelPagination } from '@/components/ui/laravel-pagination';

// rostering (barrel)
import {
    ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState,
    TabStrip, type RosterTabItem, type RosterTabTone,
    EntityFilter, type EntityFilterOption,
} from '@/components/rostering';
```

## ===== hazard-backend =====

I now have everything needed. The permission set is fully confirmed: `hazards.view`, `hazards.create`, `hazards.assign`, `hazards.close`, `hazards.manage`, `hazards.manage_types` all exist in `RbacSeeder`. The `routes/sites.php` SiteHazard surface uses only view/create/assign/close (no `manage`). I have all files mapped completely. Producing the final report.

---

# Hazards Backend — Complete Map

All paths are absolute under `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\hardcore-zhukovsky-3609cb\`.

> **Key contract-vs-reality flags up front** (read these before building):
> 1. **`SiteHazardAction` has exactly the 8 columns the contract names** — plus auto `id`/`created_at`/`updated_at`. There is **no** `tenant_id`-less variant; `tenant_id` IS present and fillable but **was never added by the migration** (see flag in §2). It has **no** `due_date`, `priority`, `sort_order`, `notes`, or `evidence`.
> 2. **`status` enum mismatch (model scopes vs DB enum):** the DB `status` enum is `['open','in_progress','mitigated','closed','reopened']`, but `scopeClosed()`/`isOpen()` only reference `open/in_progress/mitigated/closed` — **`reopened` is unhandled** by helpers/scopes (treated as neither open nor closed).
> 3. **Route permission gates:** `routes/sites.php` uses `hazards.view`, `hazards.create`, `hazards.assign`, `hazards.close`. There is **no `hazards.manage` gate in `routes/sites.php`** — `update` is gated on `hazards.create`, not `hazards.manage`. (`hazards.manage` exists as a permission and is used by the separate `routes/health-safety.php` hazard surface — see §7.)
> 4. **`tenant_id` exists on `site_hazards` (migration line 13) but NOT on `site_hazard_actions`** — the actions migration never created a `tenant_id` column, yet the model lists it as `$fillable`. Writing `tenant_id` to a `SiteHazardAction` will throw a "column not found" SQL error.

---

## 1. `app/Models/SiteHazard.php`

Traits: `HasFactory`, **`AuditableChanges`** (`App\Models\Concerns\AuditableChanges`), `SoftDeletes`. Table: `site_hazards`.

### `$fillable`
```php
protected $fillable = [
    'site_id',
    'tenant_id',
    'reference_number',
    'hazard_type',
    'custom_hazard_type',
    'severity',
    'likelihood',
    'risk_rating',
    'description',
    'photo_paths',
    'immediate_action_taken',
    'immediate_action_applied',
    'reported_by_user_id',
    'assigned_to_user_id',
    'assigned_at',
    'status',
    'status_changed_at',
    'status_changed_by_user_id',
    'resolution_summary',
    'resolution_evidence',
    'closed_at',
    'closed_by_user_id',
    'due_date',
    'review_date',
    'linked_inspection_id',
    'linked_checklist_run_id',
    'warning_sent_at',
    'overdue_notified_at',
    'control_hierarchy',
    'residual_risk_rating',
    'residual_likelihood',
    'residual_severity',
    'control_effectiveness',
    'control_review_date',
];
```

### `$casts`
```php
protected $casts = [
    'photo_paths' => 'array',
    'resolution_evidence' => 'array',
    'immediate_action_applied' => 'boolean',
    'assigned_at' => 'datetime',
    'status_changed_at' => 'datetime',
    'closed_at' => 'datetime',
    'due_date' => 'date',
    'review_date' => 'date',
    'warning_sent_at' => 'datetime',
    'overdue_notified_at' => 'datetime',
    'control_hierarchy' => 'array',
    'control_review_date' => 'date',
];
```

### Relations
- `site()` → `belongsTo(Site::class)` (FK `site_id`)
- `reportedBy()` → `belongsTo(User::class, 'reported_by_user_id')`
- `assignedTo()` → `belongsTo(User::class, 'assigned_to_user_id')`
- `statusChangedBy()` → `belongsTo(User::class, 'status_changed_by_user_id')`
- `closedBy()` → `belongsTo(User::class, 'closed_by_user_id')`
- `actions()` → `hasMany(SiteHazardAction::class, 'hazard_id')`

### Scopes
```php
public function scopeOpen($query)      // whereIn('status', ['open', 'in_progress'])
public function scopeClosed($query)    // whereIn('status', ['mitigated', 'closed'])
public function scopeHighRisk($query)  // whereIn('risk_rating', ['high', 'extreme'])
public function scopeOverdue($query)   // due_date < today AND status in ['open','in_progress']
public function scopeAssignedTo($query, int $userId)  // where('assigned_to_user_id', $userId)
```

### Helper methods
```php
public function isOpen(): bool             // in_array(status, ['open','in_progress'])
public function isOverdue(): bool          // due_date && due_date < today && isOpen()
public function requiresAssignment(): bool // risk_rating in ['high','extreme'] && !assigned_to_user_id
```

### Status enum values (from DB migration — see §6)
`open`, `in_progress`, `mitigated`, `closed`, `reopened` — default `open`.
> Note: model scopes/helpers do **not** account for `reopened` (it is neither "open" nor "closed" per the scopes).

---

## 2. `app/Models/SiteHazardAction.php`

Traits: `HasFactory` only (no SoftDeletes, no AuditableChanges). Table: `site_hazard_actions`.

### `$fillable` — exactly these 8
```php
protected $fillable = [
    'tenant_id',            // ⚠️ NOT created by the migration (see §6) — fillable but no DB column
    'hazard_id',
    'action_description',
    'status',
    'assigned_to_user_id',
    'completed_at',
    'completed_by_user_id',
    'completion_notes',
];
```

### `$casts`
```php
protected $casts = [
    'completed_at' => 'datetime',
];
```

### Relations
- `hazard()` → `belongsTo(SiteHazard::class, 'hazard_id')`
- `assignedTo()` → `belongsTo(User::class, 'assigned_to_user_id')`
- `completedBy()` → `belongsTo(User::class, 'completed_by_user_id')`

### Columns that actually exist (per migration §6)
`id`, `hazard_id`, `action_description`, `status` (enum `pending`/`in_progress`/`completed`, default `pending`), `assigned_to_user_id`, `completed_at`, `completed_by_user_id`, `completion_notes`, `created_at`, `updated_at`.
**Confirmed matches the contract** (action_description, status, assigned_to_user_id, completed_at, completed_by_user_id, completion_notes). **No** `tenant_id` column despite `$fillable` (bug/flag).

---

## 3. `app/Services/Sites/SiteHazardRiskCalculator.php`

### Public API
- `calculate(string $severity, string $likelihood): string` → `self::MATRIX[$severity][$likelihood] ?? 'medium'`
- `requiresAssignment(string $riskRating): bool` → `in_array($riskRating, ['high', 'extreme'])`
- `suggestedDueDays(string $riskRating): int` (see below)
- `static severities(): array` → `['low', 'medium', 'high', 'critical']`
- `static likelihoods(): array` → `['rare', 'unlikely', 'possible', 'likely', 'almost_certain']`
- `static riskRatings(): array` → `['low', 'medium', 'high', 'extreme']`

> Note: `calculate`, `requiresAssignment`, `suggestedDueDays` are **instance** methods; `severities`/`likelihoods`/`riskRatings` are **static**.

### `suggestedDueDays` mapping
```php
match ($riskRating) {
    'extreme' => 1,
    'high'    => 7,
    'medium'  => 30,
    'low'     => 90,
    default   => 30,
};
```

### Risk matrix (severity rows × likelihood columns → rating)

| severity \ likelihood | rare | unlikely | possible | likely | almost_certain |
|---|---|---|---|---|---|
| **low** | low | low | medium | medium | high |
| **medium** | low | medium | medium | high | high |
| **high** | medium | medium | high | high | extreme |
| **critical** | high | high | extreme | extreme | extreme |

Raw constant:
```php
private const MATRIX = [
    'low'      => ['rare'=>'low',    'unlikely'=>'low',    'possible'=>'medium',  'likely'=>'medium',  'almost_certain'=>'high'],
    'medium'   => ['rare'=>'low',    'unlikely'=>'medium', 'possible'=>'medium',  'likely'=>'high',    'almost_certain'=>'high'],
    'high'     => ['rare'=>'medium', 'unlikely'=>'medium', 'possible'=>'high',    'likely'=>'high',    'almost_certain'=>'extreme'],
    'critical' => ['rare'=>'high',   'unlikely'=>'high',   'possible'=>'extreme', 'likely'=>'extreme', 'almost_certain'=>'extreme'],
];
```
> Note: severity input domain is `low/medium/high/critical` but the **output** rating domain is `low/medium/high/extreme` (note `critical`→input only, `extreme`→output only).

---

## 4. `app/Support/SiteRecommendedHazards.php`

Single entry point `static forType(?string $type): array`, switching on site type:
```php
match ($type) {
    'head_office' => self::headOffice(),
    'facility'    => self::facility(),
    default       => self::house(),   // 'house' / null / anything else
};
```
Each chip is `['key' => ..., 'label' => ..., 'hint' => ...]` (via `item($key, $label, $hint)`).

### `house` (default) — 9 chips
| key | label | hint |
|---|---|---|
| `slip_trip_fall` | Slip / trip hazards | Loose mats, wet floors, cluttered walkways. |
| `hot_water_temperature` | Hot water temperature | Scald risk above 50C in bathrooms or kitchens. |
| `medication_storage_access` | Medication storage access | Unlocked cabinet, key control, or access concern. |
| `fire_electrical` | Fire / electrical | Overloaded sockets, damaged leads, expired alarms. |
| `manual_handling` | Manual handling | Transfers, lifting, equipment, or room layout risk. |
| `security_behaviour` | Behavioural / security | Entry, privacy, aggression, or lone-worker concerns. |
| `outdoor_garden` | Outdoor / gardening hazards | Uneven paths, tools, weeds, or poor lighting. |
| `cleaning_chemicals` | Cleaning chemicals storage | COSHH-style storage, labels, and locked access. |
| `bathroom_safety` | Bathroom safety | Grab rails, non-slip surfaces, shower access. |

### `head_office` — 5 chips
| key | label | hint |
|---|---|---|
| `slip_trip_fall` | Slip / trip hazards | Loose mats, wet floors, cluttered walkways. |
| `fire_electrical` | Fire / electrical | Overloaded sockets, damaged leads, expired alarms. |
| `security_access` | Security / visitor access | Reception, contractor access, privacy, or lone-worker concern. |
| `office_ergonomics` | Office ergonomics | Workstation setup, lighting, or repetitive strain risk. |
| `emergency_exits` | Emergency exits | Blocked exits, signage, or assembly point issues. |

### `facility` — 6 chips
| key | label | hint |
|---|---|---|
| `slip_trip_fall` | Slip / trip hazards | Loose mats, wet floors, cluttered walkways. |
| `fire_electrical` | Fire / electrical | Overloaded sockets, damaged leads, expired alarms. |
| `manual_handling` | Manual handling | Transfers, lifting, equipment, or room layout risk. |
| `equipment_guarding` | Equipment guarding | Missing guards, lockout gaps, or damaged safety controls. |
| `cleaning_chemicals` | Cleaning chemicals storage | COSHH-style storage, labels, and locked access. |
| `ppe_availability` | PPE availability | Missing, expired, or unsuitable PPE for facility tasks. |

> Note: chip `key` values are free-form strings, **not** constrained by the DB `hazard_type` column (which is just `string(50)`, no enum). E.g. `security_behaviour`/`security_access` are recommended-chip keys, not enum members.

---

## 5. `app/Observers/SiteHazardObserver.php`

Implements `ShouldHandleEventsAfterCommit`. Constructor-injects `ComprehensiveAlertBridgeService $bridge` and `HsEventService $hsEventService`. **Yes — it raises both H&S events and Control-Room alerts.**

### `creating()`
- Generates `reference_number` if empty → format `HAZ-{year}-{0000}` via `SiteHazard::whereYear('created_at', $year)->count() + 1` (⚠️ count-based — race-prone under concurrency, and `unique` constraint on `reference_number` means a collision throws).
- If `severity` + `likelihood` set → computes `risk_rating` via `SiteHazardRiskCalculator::calculate()`.
- If `due_date` empty and `risk_rating` set → `due_date = now()->addDays(suggestedDueDays(risk_rating))`.

### `created()`
1. If `risk_rating` ∈ `[high, extreme]` → `autoAssignHealthSafetyOfficer()` (finds first `User` with role `health_safety_officer`, force-fills `assigned_to_user_id` + `assigned_at` via `saveQuietly()`; **no** assignment notification on auto-assign).
2. `AuditLogger::log('hazard.created', $hazard)`.
3. `recordHsEvent($hazard)` — **records an `HsEvent` for ALL hazards** (not just high/extreme):
   - `event_category` = `HsEvent::CATEGORY_HAZARD`
   - `severity` = `risk_rating ?? 'low'`
   - `source` = the hazard model; `site_id`, `staff_id` = `reported_by_user_id`, `organization_id` = `tenant_id`, `created_by` = `reported_by_user_id`, `occurred_at`/`reported_at` = `created_at`
   - wrapped in try/catch → logs `SiteHazardObserver: HsEvent creation failed` on error (non-fatal).
4. If `risk_rating` ∈ `[high, extreme]` → `dispatchBridge($hazard)` → Control-Room alert.

### `updating()`
- If `severity`/`likelihood` dirty → recompute `risk_rating` via calculator.

### `updated()`
- **status changed** → `AuditLogger::log('hazard.status_changed', …, ['from'=>orig, 'to'=>new])`; queues `status_changed_at = now()`, `status_changed_by_user_id = auth()->id()`; if new status ∈ `[mitigated, closed]` also queues `closed_at = now()`, `closed_by_user_id = auth()->id()`.
- **assigned_to_user_id changed** (and non-null) → sends `HazardAssignedNotification` to `assignedTo`; queues `assigned_at = now()`.
- **risk_rating changed** → `AuditLogger::log('hazard.risk_changed', …)`; `syncHsEventSeverity()` (re-syncs the linked HsEvent severity); **if escalated INTO `[high, extreme]` from a non-high/extreme value** → `dispatchBridge($hazard, escalation: true)`.
- All queued `$updates` written via `forceFill($updates)->saveQuietly()` (avoids re-triggering observer).

### Control-Room bridge (`dispatchBridge`)
- Maps severity: `extreme` → `'critical'`, else → `'high'`.
- Calls `$this->bridge->bridgeOperationalAlert('hazard_identified', $severity, [...payload...])` with `site_hazard_id`, `reference_number`, `site_id`, `hazard_type`, `risk_rating`, `description`, `reported_by_user_id`, `severity_escalation` (bool).
- On success → `linkAlertToHsEvent()` links the returned alert id to the hazard's HsEvent via `HsEventService::linkControlRoomAlert()`.
- HsEvent lookup uses `HsEvent::buildIdempotencyKey(get_class($hazard), $hazard->getKey(), HsEvent::CATEGORY_HAZARD)` → `HsEvent::where('idempotency_key', $key)->first()`.
- All bridge/link steps try/catch with `Log::error`/`Log::warning` (non-fatal).

> Where it's registered: search `AppServiceProvider` for `SiteHazard::observe(SiteHazardObserver::class)` if you need to confirm wiring (not in scope here, but the observer is `ShouldHandleEventsAfterCommit` so it fires after DB commit).

---

## 6. Migrations — exact columns & types

### `database/migrations/2026_02_08_000003_create_site_hazards_tables.php`

**Table `site_hazards`:**
| column | type / definition |
|---|---|
| `id` | bigint PK |
| `site_id` | FK→`sites`, `cascadeOnDelete` |
| `tenant_id` | foreignId, **nullable**, indexed (no FK constraint) |
| `reference_number` | `string(50)`, **unique** |
| `hazard_type` | `string(50)`, indexed |
| `custom_hazard_type` | `string`, nullable |
| `severity` | `enum('low','medium','high','critical')`, indexed |
| `likelihood` | `enum('rare','unlikely','possible','likely','almost_certain')` |
| `risk_rating` | `enum('low','medium','high','extreme')`, indexed |
| `description` | `text` (NOT NULL) |
| `photo_paths` | `json`, nullable |
| `immediate_action_taken` | `text`, nullable |
| `immediate_action_applied` | `boolean`, default `false` |
| `reported_by_user_id` | FK→`users` (NOT NULL) |
| `assigned_to_user_id` | FK→`users`, nullable, `nullOnDelete` |
| `assigned_at` | `timestamp`, nullable |
| `status` | `enum('open','in_progress','mitigated','closed','reopened')`, default `open`, indexed |
| `status_changed_at` | `timestamp`, nullable |
| `status_changed_by_user_id` | FK→`users`, nullable, `nullOnDelete` |
| `resolution_summary` | `text`, nullable |
| `resolution_evidence` | `json`, nullable |
| `closed_at` | `timestamp`, nullable |
| `closed_by_user_id` | FK→`users`, nullable, `nullOnDelete` |
| `due_date` | `date`, nullable, indexed |
| `review_date` | `date`, nullable |
| `warning_sent_at` | `timestamp`, nullable |
| `overdue_notified_at` | `timestamp`, nullable |
| `linked_inspection_id` | foreignId, nullable (no FK constraint) |
| `linked_checklist_run_id` | foreignId, nullable (no FK constraint) |
| `deleted_at` | softDeletes |
| `created_at` / `updated_at` | timestamps |

Composite indexes: `(site_id, status, severity)`, `(assigned_to_user_id, status)`, `(due_date, status)`.

**Table `site_hazard_actions`:**
| column | type / definition |
|---|---|
| `id` | bigint PK |
| `hazard_id` | FK→`site_hazards`, `cascadeOnDelete` |
| `action_description` | `text` (NOT NULL) |
| `status` | `enum('pending','in_progress','completed')`, default `pending` |
| `assigned_to_user_id` | FK→`users`, nullable, `nullOnDelete` |
| `completed_at` | `timestamp`, nullable |
| `completed_by_user_id` | FK→`users`, nullable, `nullOnDelete` |
| `completion_notes` | `text`, nullable |
| `created_at` / `updated_at` | timestamps |

> ⚠️ **No `tenant_id` column on `site_hazard_actions`** (despite the model's `$fillable`). No `deleted_at` (no soft deletes).

### `database/migrations/2026_03_28_200001_enhance_site_hazards_control_hierarchy.php`

`Schema::table('site_hazards', …)` adds (all nullable):
| column | type | placed after |
|---|---|---|
| `control_hierarchy` | `json` | `immediate_action_applied` |
| `residual_risk_rating` | `string` | `control_hierarchy` |
| `residual_likelihood` | `string` | `residual_risk_rating` |
| `residual_severity` | `string` | `residual_likelihood` |
| `control_effectiveness` | `string` | `residual_severity` |
| `control_review_date` | `date` | `control_effectiveness` |

`down()` drops all six.

### Confirmation of the contract's column checklist (on `site_hazards`)
- `control_hierarchy` ✅ (json, added in 2026_03_28 migration)
- `residual_severity` ✅, `residual_likelihood` ✅, `residual_risk_rating` ✅ (all `string`, 2026_03_28 migration)
- `status_changed_at` ✅, `status_changed_by_user_id` ✅ (base migration)
- `photo_paths` ✅ (json, base migration)
- `resolution_evidence` ✅ (json, base migration)
- `closed_at` ✅, `closed_by_user_id` ✅ (base migration)
- `tenant_id` ✅ (base migration, nullable, indexed, no FK)
- Also present (not in checklist but exist): `control_effectiveness`, `control_review_date`, `warning_sent_at`, `overdue_notified_at`, `review_date`, `linked_inspection_id`, `linked_checklist_run_id`, `immediate_action_taken`, `immediate_action_applied`, `custom_hazard_type`, `resolution_summary`.

### Third hazard-named migration (out of scope — separate feature)
`database/migrations/2026_03_28_100002_create_hazardous_substance_tables.php` — **does NOT touch `site_hazards` or `site_hazard_actions`** (grep confirmed zero references). It is a distinct "hazardous substances" (COSHH register) feature. Ignore for the SiteHazard backend.

---

## 7. `routes/sites.php` — route blocks & gates

Controller: `App\Http\Controllers\Sites\SiteHazardController` (imported line 16).

### (a) Site-scoped hazard routes — index / create / store
Inside group: `Route::prefix('sites/{site}')->middleware('permission:sites.viewAny')->group(...)` (opened line 75), which is itself inside `Route::middleware(['auth','verified'])->group(...)` (line 57). So effective stack = `auth` + `verified` + `permission:sites.viewAny` + the per-route permission. Full URLs are `/sites/{site}/hazards…`.

```php
// Hazards  (lines 126–134)
Route::get('/hazards', [SiteHazardController::class, 'index'])
    ->name('sites.hazards.index');                        // gate: auth+verified+sites.viewAny ONLY
Route::get('/hazards/create', [SiteHazardController::class, 'create'])
    ->name('sites.hazards.create')
    ->middleware('permission:hazards.create');
Route::post('/hazards', [SiteHazardController::class, 'store'])
    ->name('sites.hazards.store')
    ->middleware('permission:hazards.create');
```
> Note: `index` has **no** `hazards.view` gate — only the inherited `sites.viewAny`.

### (b) Hazard item routes — show / update / assign / close (NOT site-scoped)
These sit AFTER the `sites/{site}` group closes (line 446), still inside the outer `auth`+`verified` group. URLs are `/hazards/{hazard}…` (no site prefix). Effective stack = `auth` + `verified` + per-route permission.

```php
// Hazard routes (not site-scoped)   (lines 448–460)
Route::get('/hazards/{hazard}', [SiteHazardController::class, 'show'])
    ->name('sites.hazards.show')
    ->middleware('permission:hazards.view');
Route::put('/hazards/{hazard}', [SiteHazardController::class, 'update'])
    ->name('sites.hazards.update')
    ->middleware('permission:hazards.create');            // ⚠️ gated on hazards.create, NOT hazards.manage
Route::post('/hazards/{hazard}/assign', [SiteHazardController::class, 'assign'])
    ->name('sites.hazards.assign')
    ->middleware('permission:hazards.assign');
Route::post('/hazards/{hazard}/close', [SiteHazardController::class, 'close'])
    ->name('sites.hazards.close')
    ->middleware('permission:hazards.close');
```

### (c) Global compliance hazards register
Also after the `sites/{site}` group, inside the outer `auth`+`verified` group:
```php
// Global Hazards page   (lines 490–493)
Route::get('/compliance/hazards', [SiteHazardController::class, 'globalIndex'])
    ->name('compliance.hazards')
    ->middleware('permission:hazards.view');
```
URL: `/compliance/hazards`. Method on controller: `globalIndex`.

### Controller method → route summary
| Method | Verb + URL | Route name | Permission gate (beyond auth+verified) |
|---|---|---|---|
| `globalIndex` | GET `/compliance/hazards` | `compliance.hazards` | `hazards.view` |
| `index` | GET `/sites/{site}/hazards` | `sites.hazards.index` | `sites.viewAny` (inherited) only |
| `create` | GET `/sites/{site}/hazards/create` | `sites.hazards.create` | `sites.viewAny` + `hazards.create` |
| `store` | POST `/sites/{site}/hazards` | `sites.hazards.store` | `sites.viewAny` + `hazards.create` |
| `show` | GET `/hazards/{hazard}` | `sites.hazards.show` | `hazards.view` |
| `update` | PUT `/hazards/{hazard}` | `sites.hazards.update` | `hazards.create` (**not** `hazards.manage`) |
| `assign` | POST `/hazards/{hazard}/assign` | `sites.hazards.assign` | `hazards.assign` |
| `close` | POST `/hazards/{hazard}/close` | `sites.hazards.close` | `hazards.close` |

### Permissions that actually exist (`database/seeders/RbacSeeder.php` lines 446–451)
```
hazards.view          – View hazards
hazards.create        – Log new hazards
hazards.assign        – Assign hazards
hazards.close         – Close/resolve hazards
hazards.manage        – Edit and update hazards
hazards.manage_types  – Manage hazard type catalog
```
> `hazards.manage` is a **real permission**, but in `routes/sites.php` the SiteHazard surface never uses it (edit/update is gated on `hazards.create`). `hazards.manage` IS used heavily by the **separate** hazard surface in `routes/health-safety.php` (lines ~31–267: `hazards.view`/`hazards.create`/`hazards.manage` groups). If the redesign expects `hazards.manage` to gate hazard edits, that gate is currently only enforced on the H&S route surface, not the Sites one. Worth reconciling.

---

## Cross-cutting flags for the redesign
1. **`SiteHazardAction.tenant_id` is fillable but has no DB column** → any code mass-assigning `tenant_id` on an action will SQL-error. Either add a migration or drop it from `$fillable`.
2. **`reopened` status is orphaned** in `SiteHazard` scopes/helpers (`scopeOpen`/`scopeClosed`/`isOpen` ignore it). A reopened hazard won't appear in open or closed filters and `isOverdue()` returns false for it.
3. **`reference_number` generation is count-based** (`HAZ-{year}-{count+1}`) under a `unique` constraint → concurrent creates can collide and throw. Consider a sequence/locking strategy.
4. **`index` (`/sites/{site}/hazards`) lacks a `hazards.view` gate** — only inherits `sites.viewAny`. The item `show` route does require `hazards.view`. Inconsistent.
5. **Two parallel hazard backends share the `hazards.*` permission family**: `routes/sites.php` (SiteHazard / `SiteHazardController`) and `routes/health-safety.php` (a larger surface using `hazards.manage`). Confirm which one the redesign targets so gates and controllers don't diverge.
6. **`severity` input domain (`critical`) ≠ `risk_rating` output domain (`extreme`)** — keep these straight in any UI dropdowns; the calculator's `severities()` returns `…,'critical'` while `riskRatings()` returns `…,'extreme'`.

## ===== cross-module-gaps =====

I have confirmed all findings. The role at line 769 is **Team Lead** (block starts at 764), preceded by an HR-manager-like role (726-738) and Auditor (740). The first-aid "Cover OK" badge is hardcoded `status="Cover OK" tone="success"` — confirming there's no real first-aid cover source. Let me compile the comprehensive report.

# Hazards Module Rebuild — Cross-Module Integration & Gap Audit

## 1. CLIENT → SITE resolution & the client risk-management tab

### 1a. `risk-management.tsx` current structure (where to add the read-only hazards section)
**File:** `C:\Users\steph\Herd\oblivionfindings\.claude\worktrees\hardcore-zhukovsky-3609cb\resources\js\pages\operations\clients\tabs\risk-management.tsx`

It is a **self-contained tab component** `RiskManagementTab` (default export), props: `{ clientId, risks?: ClientRiskItem[], canCreate, canUpdate, canDelete, onAddRisk, onEditRisk }`. `ClientRiskItem = { id, label, severity, controls, review_date, active }`. Structure top-to-bottom:
- **Stat strip** (4 tiles): Active risks / Critical / Reviews overdue / Review in 30d (lines 178–212).
- **Filter + add bar** (`Card`): status `Select` (active/inactive/all), severity `Select`, and a gated "Add risk" button (lines 214–259).
- **Risk cards** list with client-side `useMemo` filter+sort (severity order `critical>high>medium>low`); per-card Edit/Delete; delete uses `router.delete('/operations/clients/{clientId}/risks/{id}')` with `window.confirm` (lines 261–391).

This is **client clinical/behavioural risks** (model `ClientRisk`), conceptually distinct from facility/site `SiteHazard`. **Where to inject the read-only "Hazards at this home" section:** add a new optional prop (e.g. `homeHazards?: ClientHomeHazard[]` + `siteName?: string`) and render a new read-only `Card` block, cleanest placed **after the stat strip and before the filter+add bar** (after line 212). It must be read-only (no add/edit) — link out to `/sites/{siteId}/hazards` (current) or the new modal.

### 1b. Client → current home/site relationship (server-side)
**Direct FK.** `Client` resolves to its site via a plain `belongsTo`:
- `app\Models\Client.php:127` — `public function site() { return $this->belongsTo(Site::class); }` (column `site_id`).
- Related: `room()` → `belongsTo(SiteHouseRoom::class, 'room_id')` (`Client.php:132`), `houseGeofence()` (`:137`), `serviceContext()` (`:142`).

There is **no "current placement/admission" history model** — `client.site_id` IS the current home (single-valued). `SiteController::show` confirms the inverse `Site::clients` relation and selects `site_id` on clients (`SiteController.php:271-279`).

### 1c. Controller rendering the tab & where to inject the prop
**`app\Http\Controllers\ClientController.php` `show()` (line 340).** It eager-loads `'risks'` (line 362) and renders the full client profile via Inertia (the tabs, including `RiskManagementTab`, live under `operations/clients/show.tsx`). The client already loads `'site:id,name'` (line 347) and `'room:...'`.

> Note there is **also** a standalone `ClientRiskController::index` (`app\Http\Controllers\ClientRiskController.php`) that renders a separate `operations/clients/risks` page with `risks` + `ClientSafetyPayload::forClient($client)` + `can.{create,update}` (gated on `risks.create` / `risks.update`). The tab is fed from `ClientController::show`, but the **risk CRUD verbs/permissions live on `ClientRiskController`** (routes `/operations/clients/{client}/risks...`).

**To inject "hazards at this home":** in `ClientController::show`, after the client is loaded, resolve `$client->site_id` and pass a new prop, e.g.:
```php
'homeHazards' => $client->site_id
    ? SiteHazard::where('site_id', $client->site_id)->open()
        ->with('assignedTo:id,name')->latest()->limit(N)->get()->map(...)
    : [],
```
Then thread it through `operations/clients/show.tsx` into `<RiskManagementTab homeHazards=... siteName={client.site?.name} />`. **Reuse** `SiteHazard::open()` scope (see §5) — do not re-derive open status.

---

## 2. NZ COMPLIANCE BADGES — what already exists vs. gaps

The hero badge **UI** already exists and is the canonical component to reuse:
**`resources\js\pages\health-safety\components\hs-hero-kit.tsx` → `HeroComplianceBadges` (lines 177–247).** Its props are exactly the shape requested: `worksafeAwaiting: number`, `sdsExpiring: number`, `drillsDue?: number`, `drillsOverdue?: number`, `ngaPaerewaCertified?: boolean`, `firstAidOk?: boolean`. It renders the 5 canonical chips (WorkSafe notifiable, Ngā Paerewa NZS 8134:2021, Hazardous substances SDS, Fire drills, First aid) with tone logic (overdue=critical outranks due=warning). **REUSE THIS — do not rebuild the badge row.**

Per-field source audit (what to call vs. derive):

| nzBadges field | Already computed? | Source to reuse |
|---|---|---|
| `worksafe_awaiting` | **YES** | `HsAnalyticsService::worksafeTotals(...)['awaiting']` (`HsAnalyticsService.php:537`), backed by `App\Domain\Governance\Models\NotifiableIncident`. Also surfaced in periodSummary (`:772`) and monthly trend (`:137`). Classification logic in `app\Services\HealthSafety\NotifiableEventClassifier.php`. |
| `drills_due` / `drills_overdue` | **PARTIAL** | `HsAnalyticsService::drillStatusBySite()` (`HsAnalyticsService.php:626`) returns `site_id => compliant|due_soon|overdue` from `EmergencyDrill` (completed within 6 months). It yields **per-site status strings**, NOT the org-wide due/overdue **counts** the badge wants. Gap: add a small count helper (e.g. count sites where status `due_soon` / `overdue`) — reuse `drillStatusBySite()`, don't re-query `EmergencyDrill`. Note analytics.tsx currently passes only `drillsOverdue` and hardcodes `sdsExpiring={0}` (`analytics.tsx:709`). |
| `sds_expiring` | **NOT computed (gap)** | Model exists: `App\Models\SafetyDataSheet` with `review_date` (date cast) and `scopeCurrent()` (status='current') — but **no expiry-window scope**. `HazardousSubstance` has `scopeActive/Controlled/RequiresTracking` but no SDS-expiry rollup. **You must derive**: e.g. `SafetyDataSheet::whereNotNull('review_date')->whereBetween('review_date',[today, today+Nd])->count()` (or `< today` for expired). The dashboard-tabs.tsx derives a proxy client-side from an `expiring[]` prop filtered by `type==='sds'` (`dashboard-tabs.tsx:266`) — there's no canonical server method. |
| `nga_paerewa_certified` | **NOT computed (gap)** | No model/service computes Ngā Paerewa (NZS 8134:2021) certification status anywhere. `HeroComplianceBadges` **defaults `ngaPaerewaCertified=true`** and every caller relies on that default (it's effectively a static "Certified" chip today). If you want it real, it's a **new** data source (likely a site/org certification record); otherwise keep the default. |
| `first_aid_ok` | **NOT computed (gap)** | `App\Models\FirstAidRecord` is a **treatment-incident log** (`treatment_date`, ambulance calls, `relatedIncident`) — it has **no first-aider certification/expiry/cover fields and no scopes**. `FirstAidController` only counts recent treatments (`FirstAidController.php:40-43`). The dashboard "First-aid cover" card is **hardcoded** `status="Cover OK" tone="success"` (`dashboard-tabs.tsx:299-305`), and the badge **defaults `firstAidOk=true`**. So "first aid cover OK" is **not real anywhere** — deriving it would need a first-aider-certification source that does not yet exist. |

**Recommendation:** Build one small server method (e.g. on a HazardsModule summary service or extend `HsModuleSummaryService`) that returns the `nzBadges` array, **delegating** `worksafe_awaiting`→`HsAnalyticsService::worksafeTotals`, `drills_*`→`drillStatusBySite`, and computing only `sds_expiring` (new SafetyDataSheet query). Leave `nga_paerewa_certified`/`first_aid_ok` as honest defaults unless you add their backing data — do **not** fabricate them.

---

## 3. SITES SHOW page — no collision; keep `show.tsx`

- **Live file:** `SiteController::show` renders `return inertia('sites/show', [...])` — **lowercase** (`SiteController.php:410`).
- **Disk + git:** Glob found only `resources\js\pages\sites\show.tsx`. `git ls-files` confirms only `resources/js/pages/sites/show.tsx` (and `sites/hazards/show.tsx`) are tracked — **there is NO `sites/Show.tsx`**. No casing collision exists. **Keep `sites/show.tsx`; nothing to delete.**
- **Placeholder Hazards tab** (the inert one to replace) is at **`resources\js\pages\sites\show.tsx:2135-2169`**:
```tsx
{/* Hazards Tab */}
<TabsContent value="hazards">
    <Card>
        <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Hazards Register</CardTitle>
            <Button asChild><Link href={`/sites/${site.id}/hazards`}>View All Hazards</Link></Button>
        </CardHeader>
        <CardContent>
            <div className="py-8 text-center text-muted-foreground">
                <ShieldAlert ... />
                <p>Logged hazards and risk assessments</p>
                ... <Link href={`/sites/${site.id}/hazards`}>View Hazards</Link>
                ... <Link href={`/sites/${site.id}/hazards?action=add`}>Log Hazard</Link>
            </div>
        </CardContent>
    </Card>
</TabsContent>
```
It's a **dead placeholder** that only links out (no real register inline). The tab trigger is registered at `show.tsx:1147` (`{ value: 'hazards', label: 'Hazards', icon: ShieldAlert }`), and a hero quick-action `review_hazards` navigates to `/sites/${site.id}/hazards` (`show.tsx:1001-1002`). This is the prime spot to render the real per-site hazards section/modal in the rebuild.

---

## 4. PERMISSIONS — `hazards.*` exist; which roles; seeder caveat

**All six permissions are seeded** in `database\seeders\RbacSeeder.php:446-451`, group `hazards`, module `Compliance`:
`hazards.view`, `hazards.create`, `hazards.assign`, `hazards.close`, `hazards.manage` (edit/update), `hazards.manage_types`.

> Note the prompt mentioned `hazards.manage` — that exists. There is **no** distinct `hazards.delete` permission; deletion isn't a separate perm in this module.

**Role grants:**
- **`admin`** — gets **everything**: `$admin?->permissions()->sync(Permission::pluck('id'))` (`RbacSeeder.php:532`). So admins have all `hazards.*`.
- **Team Lead** (`RbacSeeder.php:764-769`): `hazards.view, hazards.create, hazards.manage, hazards.assign` (no `close`).
- **Health & Safety Officer** (`:791-797`): `hazards.view, create, manage, assign, close` (full).
- **Maintenance Coordinator** (`:799-802`): `hazards.view` only.
- **Auditor / HR-manager blocks** (around `:726-759`): **no** hazards perms.
- Test seeders grant the set: `DuskDatabaseSeeder.php:90`.

`HandleInertiaRequests.php:441-445` already exposes these to the frontend as `can.hazards.{view,create,assign,close,manageTypes}` (note: it exposes `manageTypes` but **not** `manage` — if the rebuild needs a `can.hazards.manage` boolean, add it there).

**⚠️ Deploy caveat (confirmed in project memory `reference_deploy_seeders.md`):** permissions are **seeded, not migrated**, and deploys **skip seeders**. Any *new* hazards permission you add (or new role grant) will **403 on the server** until `RbacSeeder`/`*PermissionsSeeder --force` is run. The existing six already exist in prod, but a rebuild that introduces, say, `hazards.delete` or a `can.hazards.manage` gate must run the seeder post-deploy.

---

## 5. EXISTING HAZARD FRONTEND to replace/retire

All four are the **old pattern** (navigate-away pages, no modal-first, client-side filtering, `DropdownMenu` three-dot menus that mostly just re-navigate) — i.e. the surfaces to rip out for a modal-first rebuild:

| File | Pattern / what it does |
|---|---|
| `resources\js\pages\compliance\hazards\index.tsx` | **Global cross-site register.** `PageHero` + 4 stats; big `Card` filter block (search/site/type/status/severity/assignee/due/risk); **all filtering is client-side `useMemo`** (`filteredHazards`, lines 138-163) even though the controller also accepts filters; rows are `cursor-pointer` divs that `router.visit('/hazards/{id}')`; per-row `DropdownMenu` (Open/Assign/Close all just navigate to `/hazards/{id}`; Copy link); **CSV export built in-browser** (`handleExportCSV`, lines 178-206); "Log Hazard" opens a `Dialog` that just picks a site then `router.visit('/sites/{id}/hazards/create')` (navigate-away). Rendered by `SiteHazardController::globalIndex` (`compliance/hazards/index`). |
| `resources\js\pages\sites\hazards\index.tsx` | **Per-site register.** `PageHero`/`PageLayout` + 4 stat `Card`s; filter `Card` that does **server-side** `router.get('/sites/{id}/hazards', ...)` (preserveState); paginated `hazards.data`; rows navigate to `/hazards/{id}`; same three-dot `DropdownMenu` (navigate-only); empty-state shows `recommendedHazards` suggestion list (`SiteRecommendedHazards::forType`). Rendered by `SiteHazardController::index`. |
| `resources\js\pages\sites\hazards\create.tsx` | **Full-page 4-step form** (NOT a modal): Step1 hazard-type tile picker (icon grid + custom), Step2 risk assessment (severity/likelihood tile buttons + **live RISK_MATRIX visual** + calculated rating), Step3 location/photos(stub dropzone)/witnesses, Step4 immediate action. Uses `useForm` → `post('/sites/{id}/hazards')`; Cancel link back. Heavy duplicated matrix/severity constants. |
| `resources\js\pages\sites\hazards\show.tsx` | **Full-page detail** (`max-w-4xl`, NOT modal): risk-coloured header, **4-step WORKFLOW_STEPS timeline** (open→in_progress→mitigated→closed), risk-matrix card, details, photos, assignment card, immediate-action, resolution. Two `Dialog`s for **Assign** (`post('/hazards/{id}/assign')`) and **Close** (`post('/hazards/{id}/close')`), gated on `canAssign`/`canClose`. Rendered by `SiteHazardController::show`. |

Backend they sit on: `app\Http\Controllers\Sites\SiteHazardController.php` (index/create/store/show/update/assign/close/globalIndex), model `app\Models\SiteHazard.php`, calculator `app\Services\Sites\SiteHazardRiskCalculator.php` (`severities/likelihoods/riskRatings/requiresAssignment/suggestedDueDays`), suggestions `app\Support\SiteRecommendedHazards`, and child `SiteHazardAction` (model has `actions(): HasMany` at `SiteHazard.php:98`). **Reuse the model + calculator + actions relation**; replace the 4 page files.

**`SiteHazard` model essentials to reuse (don't re-derive):** scopes `open()` (open+in_progress, `:104`), `closed()` (`:109`), `highRisk()` (`:114`), `overdue()` (`:119`), `assignedTo($userId)` (`:125`); helpers `isOpen()/isOverdue()/requiresAssignment()`; relations `site/reportedBy/assignedTo/statusChangedBy/closedBy/actions`.

---

## 6. ALL other references to the hazard pages (so nothing breaks)

Routes (`routes\sites.php`): per-site `sites.hazards.{index,create,store,show,update,assign,close}` (`:127-133`, `:449-460`), global `compliance.hazards` → `globalIndex` gated `permission:hazards.view` (`:491-493`). The detail/assign/close routes are bare `/hazards/{hazard}` (NOT under `/sites/...`).

Frontend links pointing at these pages (must be repointed if URLs/modal change):
- **Sidebar:** `resources\js\components\app-sidebar.tsx:1220-1225` — "Hazards" → `/compliance/hazards` (gated `can.hazards.view`), under the "H&S Management" group.
- **Sites index:** `resources\js\pages\sites\index.tsx:1395` row action → `/sites/{id}/hazards?action=add`; `:1511` a "Hazards" filter label; `:1722` a "Hazards" table column (open-hazard count).
- **Site show:** `resources\js\pages\sites\show.tsx` — the placeholder tab links (`:2141,:2153,:2160`) + hero quick-action `review_hazards`→`/sites/{id}/hazards` (`:1001`).
- **H&S command-centre hero:** `resources\js\pages\health-safety\components\command-centre-hero.tsx:295` — `HeroClusterTile href="/compliance/hazards"` ("Open hazards" tile).
- **H&S analytics page:** `resources\js\pages\health-safety\analytics.tsx` uses `HeroComplianceBadges` (`:65,:709`) and "Open hazards" linking to `/compliance/hazards` (via charts/hero).
- **Sites overdue-actions report:** `resources\js\pages\sites\reports\overdue-actions.tsx:182` — "View" → `/hazards/{id}` (overdue-hazard list).
- **H&S report launcher / wizard-configs / charts:** `resources\js\pages\health-safety\components\wizard-configs.tsx` and `charts.tsx` reference `/hazards` / open-hazards.
- **Server cross-refs:** `SiteController::index` filters/counts open hazards via `whereHas('hazards', open())` and `open_hazards_count` (`SiteController.php:126,213`); `HsKpiService::openHazards()` + `hazardBurndown()` query `SiteHazard` (`HsKpiService.php:232,324`); `HsAnalyticsService` open-hazard rollups & exports.

**Backward-compat:** if the rebuild moves hazards behind a modal (e.g. `/compliance/hazards?hazard={id}` or `/sites/{id}?tab=hazards&hazard={id}`), keep `/hazards/{id}`, `/sites/{id}/hazards`, and `/compliance/hazards` resolving (redirect or honor query param) so the ~12 link sites above + the sidebar don't 404.

---

## REUSE-FIRST summary (don't duplicate)
1. **Badge UI:** `HeroComplianceBadges` in `hs-hero-kit.tsx` — already the 6-field shape. Feed it; don't rebuild.
2. **WorkSafe-awaiting count:** `HsAnalyticsService::worksafeTotals()` + `NotifiableEventClassifier`.
3. **Drill status:** `HsAnalyticsService::drillStatusBySite()` (EmergencyDrill, 6-month cadence) — just roll its strings into due/overdue counts.
4. **Hazard model/scopes/calculator/actions:** `SiteHazard` (`open/closed/overdue/highRisk` scopes), `SiteHazardRiskCalculator`, `SiteHazardAction`, `SiteRecommendedHazards`.
5. **Client→site:** `Client::site()` (`site_id` FK) — single current home, no placement history.
6. **Client-context H&S summary** (if you want HsEvents-at-client too): `HsModuleSummaryService::forClient()` / `forSite()`.
7. **Upload UI** (for hazard photos, replacing the stub dropzone): shared `components/ui/file-dropzone.tsx` (`FileDropzone/AttachmentUploader`), per project memory `reference_premium_file_dropzone.md`.

## Concrete GAPS to fill
- **`sds_expiring`**: no server method — derive from `SafetyDataSheet.review_date` (model has `review_date` date cast + `scopeCurrent`, but no expiry-window scope). Add a scope/count; don't bolt onto unrelated services.
- **`nga_paerewa_certified`** and **`first_aid_ok`**: **no backing data anywhere** — both are static `true` defaults today (and the dashboard first-aid card is hardcoded "Cover OK"). Either keep honest defaults or introduce real sources (Ngā Paerewa cert record; first-aider certification/expiry — `FirstAidRecord` is a treatment log, not a cover model). Do **not** fabricate.
- **One `nzBadges` aggregator** assembling the 6 fields in a single read-only call (delegating to the above), so the new hero matches the existing H&S heroes exactly.
- **Read-only "hazards at this home" section** in `risk-management.tsx` + the prop wired through `ClientController::show` → `operations/clients/show.tsx`.
- **Replace the 4 legacy hazard pages** (navigate-away/full-page) with the modal-first rebuild, and **repoint/keep-compat** the ~12 link sites + sidebar in §6.
- **Permissions:** add `can.hazards.manage` to `HandleInertiaRequests` if the rebuild gates on it (currently exposes `manageTypes` not `manage`); run `RbacSeeder --force` on deploy for any new perm/grant.
- **`sites/show.tsx` Hazards tab placeholder** (`:2135-2169`) is inert — replace with the real inline register/modal.