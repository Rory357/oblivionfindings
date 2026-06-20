The grep confirms there is NO file/document upload anywhere in the mock — no `input type="file"`, no dropzone, no attachments section, no evidence field. The "progress" hits are all wizard/detail step progress bars, and "document" appears only in the "Identity documents" chip option. This is a critical finding for section 8.

I have everything needed. Here is the complete buildable spec.

---

# Privacy Command Centre — Build Spec (from `Privacy Dashboard.dc.html`)

> Source mock is a self-contained `DCLogic` React component. Design tokens (`--primary: oklch(0.511 0.262 277)` = indigo/violet, `--s-success/warning/critical/info` + `-bg` pairs) map to existing app tokens — **do not copy raw oklch**; reuse the H&S `hs-hero-kit` chrome. `TODAY = 2026-06-20`. Dates `Intl.DateTimeFormat('en-NZ', {day:'numeric',month:'short',year:'numeric'})`. Numbers `toLocaleString('en-NZ')`. Domain enums and route names (`privacy.requests.store`, `privacy.breaches.store`, `privacy.legal-holds.store`, `privacy.retention.store`, `privacy.dpia.store`, `privacy.reports.export`, `requests.export`, `requests.show`) are embedded in the mock and are the contract.

---

## 1. HERO

**Container**: rounded-18px gradient banner (violet 135deg), white text, `onContextMenu → openHeroCtx`. Decorative translucent circles (non-interactive).

**Medallion** (top-left, 76px circle, 4px translucent ring): **shield icon** — `ICONS.shield` = `<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>` (plain shield, Lucide `Shield`). Stroke white, 36px.

**Two status pills** (top, above h1):
1. Green pulsing-dot pill (animated `pdPing` ping dot, success green): **"Privacy & data protection · synced just now"** (uppercase, letter-spacing .07em).
2. Shield-check pill (`ICONS.shieldCheck` = shield + checkmark): **"Privacy Act 2020 · OPC"** (uppercase).

**H1**: **"Privacy Dashboard"** (27px, 700).
**Description** (max-36rem, white/72%): *"The command centre for the whole privacy module — access & correction requests, notifiable breaches, legal holds, retention and DPIAs. Right-click anywhere for quick actions."*

**Right-side CTA cluster** (`position:relative`):
- Primary solid-white button (violet text), `plus` icon → **"New privacy request"** → `onClick: newRequest` → `openWizard('request')`.
- Secondary translucent button, `file` icon → **"Compliance reports ▾"** → `toggleReports`.
- **Compliance reports popover** (`reportsOpen`, 248px card, anchored right, `pdIn` animation, items each violet icon + label, hover → `--muted`):
  | Icon | Label | Action (toast) |
  |---|---|---|
  | `file` | **OPC breach register** | "Export: OPC breach register" |
  | `clock` | **Access-request SLA report** | "Export: SLA report" |
  | `lock` | **Retention compliance** | "Export: retention compliance" |
  | `shieldCheck` | **Full compliance report** | "Export: full compliance report" |
  (each also sets `reportsOpen:false`)

### TWO HeroClusters (grid, `minmax(320px,1fr)`)

**Cluster A — "Live · this period"** (eyebrow icon `activity`). 4-col grid of tiles. Each tile: dot + uppercase label, big tabular value (25px), caption. Hover bg `rgba(255,255,255,.2)`.

| Tile label | Value | Caption | Dot tone | Links to |
|---|---|---|---|---|
| New requests | 6 | this month | neutral white/50% | tab `requests` |
| In progress | 12 | active | neutral white/50% | tab `requests` |
| Completed | 9 | this period | success green | tab `requests` |
| Breaches | 4 | logged | warning amber | tab `breaches` |

**Cluster B — "Needs attention"** (eyebrow icon `alert` = triangle-exclamation). 3-col grid (6 tiles), value 22px.

| Tile label | Value | Caption | Dot tone | Links to |
|---|---|---|---|---|
| Overdue | 1 | 20 wd passed | critical red | tab `requests` |
| OPC notify | 2 | asap | critical red | tab `breaches` |
| Subject notify | 1 | due | warning amber | tab `breaches` |
| Active holds | 2 | preserving | neutral white/50% | tab `legal_holds` |
| High-risk DPIA | 2 | in review | critical red | tab `dpia` |
| Retention | 1 | review due | warning amber | tab `retention` |

### HeroComplianceBadges row (5 NZ chips; `nzChip(icon,label,tone)`)

| Icon | Label text | Tone | Driven by |
|---|---|---|---|
| `shieldCheck` | **Privacy Act 2020 · Compliant** | success | static/boolean overall-compliant |
| `alert` | **OPC-notifiable · 2 open** | warning | count of breaches where `opc && !opcNotified` |
| `clock` | **Overdue access requests · 1** | critical | count of requests where `isOverdue(r)` |
| `scale` | **Active legal holds · 2** | success | count of holds `status==='active'` |
| `lock` | **Retention policies · 3 active** | success | count of retention `status==='active'` |

### Footer controls (border-top, inside hero)

- **"Period"** label + 4 pills (`setPeriod`): **This month** (`month`, default-active), **Quarter** (`quarter`), **Year** (`year`), **All** (`all`). Active = `rgba(255,255,255,.25)`.
- **Site filter** rounded pill button with `search` icon + chevron: **"All sites · 6"** (onClick = `noop` in mock — wire to real site filter).
- **Search input** (`margin-left:auto`, 208px, leading search icon): placeholder **"Search privacy records…"** → `onSearch` (filters worklist live by ref/subject/email/nature/etc.).
- **Clear** button (only when `hasFilters = search || period!=='month'`): `x` icon + **"Clear"** → `clearFilters` (resets search + period to `month`).

---

## 2. RIGHT-CLICK MENUS

Menu chrome (`menuAt`): 280px card, header row = colored **tag** pill (uppercase, tone-colored) + **meta** text; items are `24px icon-tile + label (+ optional sub)`; `sep:true` → divider. Auto-repositions to stay in viewport. Backdrop click or right-click closes.

### Hero context menu (`openHeroCtx`) — tag **"NEW"** (primary), meta "Privacy command centre"
1. `file` **New privacy request** → `openWizard('request')`
2. `alert` **Log data breach** *(critical tone)* → `openWizard('breach')`
3. `scale` **New legal hold** → `openWizard('hold')`
4. `lock` **New retention policy** → `openWizard('retention')`
5. `shieldCheck` **New DPIA** → `openWizard('dpia')`
6. — separator —
7. `download` **Export compliance report** · sub `privacy.reports.export` → toast "Compliance report queued for export"
8. `list` **Go to Requests** → tab `requests`
9. `alert` **Go to Breaches** → tab `breaches`

### Per-row menus

**Requests** (`reqCtx`) — tag = status label, meta = `{ref} · {type.label}`. `open = status ∉ {completed,rejected,withdrawn}`:
1. `eye` **View request** · sub=ref *(primary)* → `openDetailReq(id)`
2. *(if open && !verified)* `fingerprint` **Verify identity** → opens detail + `verify` action
3. *(if open)* `clock` **Extend deadline** → detail + `extend`
4. *(if open)* `check` **Mark complete** → detail + `complete`
5. *(if open)* `ban` **Refuse request** *(critical)* → detail + `refuse`
6. `download` **Export data package** · sub `requests.export` → toast "Data package generated"
7. — sep — `link` **Copy link** → toast "Link copied"; `file` **Open full page** → toast "Navigates to requests.show"

**Breaches** (`breachCtx`) — tag=status, meta=`{ref} · {affected} affected`:
1. `eye` **View breach** · sub=ref *(primary)*
2. *(if `opc && !opcNotified`)* `shieldAlert` **Notify OPC** · sub "as soon as practicable" *(critical)* → sets `opcNotified=true, status='notified'`, toast "OPC notified — {ref}"
3. *(if `subjectNotify`)* `mail` **Notify affected subjects** → clears `subjectNotify`, toast "Affected individuals notified"
4. *(if status≠resolved)* `check` **Resolve breach** → `status='resolved', opc=false, subjectNotify=false`
5. — sep — `link` **Copy link**

**Legal holds** (`holdCtx`) — tag=status, meta=`{ref} · {hold_type}`:
1. `eye` **View hold** · sub=ref *(primary)*
2. `edit` **Edit hold** → `openWizard('hold')`
3. *(if active)* `ban` **Release hold** *(critical)* → `status='released'`

**DPIA** (`dpiaCtx`) — tag=risk (uppercase), meta=`{ref} · {name}`:
1. `eye` **View DPIA** · sub=ref *(primary)*
2. `edit` **Edit DPIA** → `openWizard('dpia')`
3. *(if `in_review`)* `check` **Approve DPIA** → `status='approved'`
4. *(if `in_review`)* `send` **Send for review** → toast

**Retention** (`retentionCtx`) — tag=status, meta=policy name:
1. `eye` **View policy** *(primary)*
2. `edit` **Edit policy** → `openWizard('retention')`
3. `refresh` **Run review** → toast "Retention review run"

**Deletion logs** (`delCtx`) — tag=status, meta=`{ref} · {model}`:
1. `eye` **View log** · sub=ref *(primary)*
2. *(if `scheduled`)* `trash` **Execute deletion** · sub "{records} records" *(critical)* → `status='executed'`, toast "Deletion executed — {records} records"

---

## 3. TABS (exact order)

`role=tablist`, each tab = icon-chip + label + count badge. Tone-colored when active.

| key | Label | Icon | Tone | Badge count (mock) |
|---|---|---|---|---|
| `overview` | **Overview** | `grid` | primary | 5 |
| `requests` | **Requests** | `file` | info | 5 |
| `breaches` | **Breaches** | `alert` | critical | 3 |
| `legal_holds` | **Legal holds** | `scale` | warning | 2 |
| `retention` | **Retention** | `lock` | primary | 3 |
| `dpia` | **DPIA** | `shieldCheck` | success | 2 |
| `deletion_logs` | **Deletion logs** | `trash` | warning | 4 |

`overview` and `requests` share the same request-backed worklist (Overview title "Privacy request worklist"; Requests title "Privacy requests").

---

## 4. WORKLIST COLUMNS (per tab)

Worklist card: header (icon-tile + title + "· subtitle", and "Right-click a row for actions" hint), table (`min-width:880px`), footer ("Showing N of M" + Prev/1/Next pagination — Prev disabled, Next is `noop`). Rows: click→open, right-click→ctx, keyboard Enter/Space→open. Cell renderers: `isStack` (main + sub), `isBadge` (tone pill), `isEntity` (avatar initials + name/email), `isFlags` (flag chips or "—").

| Tab | Title · subtitle | Columns |
|---|---|---|
| **Overview** | Privacy request worklist · IPP 6 / IPP 7 · 20 working days | **Reference, Type, Subject, Status, Due, Assigned to** |
| **Requests** | Privacy requests · (same subtitle) | same as Overview |
| **Breaches** | Data breaches · notifiable breach register | **Reference, Breach, Status, Notification** |
| **Legal holds** | Legal holds · preservation orders | **Reference, Reason, Type, Status, Review** |
| **Retention** | Retention policies · data lifecycle rules | **Policy, Retention, Status, Next review** |
| **DPIA** | Privacy impact assessments · DPIA register | **Reference, Assessment, Type, Risk, Status** |
| **Deletion logs** | Deletion logs · last 30 days | **Reference, Scope, Records, When, Status** |

**Cell detail per tab** (for fidelity):
- **Requests**: Reference=ref (sub=relation) · Type=`REQ_TYPE` badge · Subject=avatar+name+email · Status=`REQ_STATUS` badge · Due=date, sub "overdue"(red) / "statutory 20 wd" · Assigned=assignee or "Unassigned"(muted).
- **Breaches**: Reference=ref (sub "Discovered {date}") · Breach=nature (sub "{affected} individuals") · Status badge · Notification=flag chips: "OPC due"(critical) if `opc&&!opcNotified`, "OPC notified"(success) if notified, "Subjects due"(warning) if `subjectNotify`; "—" if none.
- **Legal holds**: ref (sub=authority) · reason · type (info badge) · Active/Released badge (warning/neutral) · review date (sub "review").
- **Retention**: policy (sub=model) · "{years} year(s)" (sub=basis) · Active/Draft badge (success/neutral) · review date, sub "review due"(red if ≤today) / "scheduled".
- **DPIA**: ref (sub=process) · name · type `replace(_," ")` info badge · "{risk} risk" badge (critical if high/very_high, warning medium, success low) · Approved/In review badge.
- **Deletion logs**: ref (sub=model) · scope · records `toLocaleString` (sub "records") · date · Executed/Scheduled badge (neutral/warning).

**Enums** (drive badges — use verbatim):
- `REQ_STATUS`: received→"Received"(warn), under_review→"Under review"(warn), identity_verification→"Identity check"(info), in_progress→"In progress"(info), completed→"Completed"(success), rejected→"Refused"(critical), withdrawn→"Withdrawn"(neutral).
- `REQ_TYPE`: access→"Access · IPP 6"(info), rectification→"Correction · IPP 7"(info), erasure→"Deletion"(warn), restriction→"Restriction"(warn), portability→"Portability"(info), objection→"Objection"(warn), automated_decision→"Automated decision"(critical).
- `BREACH_STATUS`: discovered(critical), under_investigation→"Investigating"(warn), contained(info), notified→"OPC notified"(info), resolved(success).

---

## 5. DETAIL-AS-MODAL

Only the **privacy REQUEST** detail is fully built (`buildDetail`). Breach/hold/DPIA/retention/deletion "View" actions are **toast stubs** in the mock (e.g. "Breach detail — {ref}") — **these detail dialogs need to be designed/built net-new** mirroring the request dialog pattern.

**Request detail dialog** (940×720 modal, left rail + main column + footer Options bar; `pdIn`; backdrop click closes; progress bar reflects section index):
- **Rail header**: violet `file` icon tile + railTitle=ref + railSub=type.label.
- **Left-rail sections** (numbered icon pills, active=violet):

| # | Section key | Label | Blurb | Icon | Cards shown (each card = title + ReviewRows) |
|---|---|---|---|---|---|
| 1 | `overview` | **Overview** | "Request & origin" | `file` | **Request** (Reference, Type, Received, Assigned to) · **Origin** (Channel="Web form", Relationship) · **Details** (Summary, full-width) |
| 2 | `subject` | **Subject & verification** | "Verified"/"Pending" | `fingerprint` | **Data subject** (Name, Email, Relationship) · **Identity verification** (Status [green if verified/amber if pending], Method, Basis="IPP 6 — confirm before release") |
| 3 | `timeline` | **Timeline & deadline** | "Overdue"/"On track" | `clock` | **Statutory deadline** full-width (Received, Due date [red if overdue], Basis="IPP 6 · 20 working days", Status) |
| 4 | `history` | **History** | "Audit trail" | `refresh` | **Audit trail** timeline (events: Request received → [Identity verified if verified] → Assigned to {assignee} → [Request completed / Request refused "IPP 6(4) — third-party info"]) |

- **Footer left — status chips** (`footChip`): status label, type label, + "Overdue · 20 wd" (critical) if overdue.
- **Footer right — Options bar** ("OPTIONS" label + buttons; `open = status ∉ {completed,rejected,withdrawn}`):
  1. `link` **Open full page** (ghost) → toast "Navigates to requests.show"
  2. *(if open && !verified)* `fingerprint` **Verify identity** *(primary, solid violet)* → `verify` action
  3. *(if open)* `clock` **Extend** → `extend`
  4. *(if open)* `check` **Complete** → `complete`
  5. *(if open)* `ban` **Refuse** *(critical, red border/text)* → `refuse`
  6. `download` **Export package** → `export`

(Deep-link: opening detail via a row ctx action passes `{action}` and auto-fires that action modal after 60ms via `openDetailReq(id,{action})`.)

---

## 6. THE FIVE CREATE WIZARDS

Shared shell (940×780): left rail (icon + railTitle/railSub + numbered steps with check-on-complete + **Completeness %** bar at bottom) · header "Step N of M · {label}" + progress bar · step-head (icon tile + title + blurb) · 2-col field grid (tiles/chips/area/subhead/info span full width) · footer (Back | Cancel · [review:] Save & add another + {verb} / [else:] Continue). **Success pane** on submit: green check medallion + sparkle, `successTitle` = verb past-tense + "!", `successBlurb` = "The {noun} was saved and the worklist refreshed. POST → {store}", buttons **Add another** / **Done**.

**Validation**: required fields validated per-step on Continue; on final submit, all steps validated and wizard jumps to first bad step. Field types: `tiles`=TilePicker (single-select, can have `desc` and `cols`), `chips`=ChipMulti (multi-select toggle), `select`=SelectInput (prepends "Select…"), `text`/`email`/`date`=Field (input), `area`=Field-textarea, `subhead`=section label, `info`=callout box (tone warn/crit/default). `required` shows red `*`. `hint` shows muted inline.

### 6.1 Request — rail `file` "New request / IPP 6 / IPP 7", verb **"Create request"**, store `privacy.requests.store`
Prefills: `received='2026-06-20'`, `due=+20 working days`, `relation='Client'`, `verify_method='Not yet verified'`.

**Step 1 — Request** ("Type & received date", `file`):
- `request_type` — **Request type** — TilePicker, **required**, 2-col:
  - access → "Access · IPP 6" / "See personal information we hold"
  - rectification → "Correction · IPP 7" / "Correct inaccurate information"
  - erasure → "Deletion" / "Delete personal information"
  - portability → "Portability" / "Export / transfer to another provider"
  - objection → "Objection" / "Object to a particular use"
  - restriction → "Restriction" / "Limit how information is used"
- `received` — **Received date** — date, **required**.

**Step 2 — Data subject** ("Who is asking", `users`):
- `subject_name` — **Subject name** — text, **required**, ph "Full name of the person".
- `subject_email` — **Subject email** — email, **required**, ph "email@example.co.nz".
- `relation` — **Relationship to organisation** — SelectInput: Client / Family / whānau / Ex-staff / Guardian / EPOA / Other.
- *(subhead `fingerprint` "Identity")*
- `verify_method` — **Verification method** — SelectInput (span): Not yet verified / RealMe / Drivers licence / Passport / In person / Known to organisation.

**Step 3 — Scope & assignment** ("Detail, owner & deadline", `list`):
- *(info `clock`)*: "Statutory deadline is auto-set to +20 working days from the received date (IPP 6). Editable below with a recorded reason."
- `details` — **Request details** — textarea, ph "What is being requested, and any specific records…".
- `assignee` — **Assigned to** — SelectInput: Mere Tait / Sina Faleolo / David Brooke / Hemi Walker / Olivia Reed.
- `due` — **Statutory due date** — date, hint "Auto +20 working days (IPP 6) — editable". *(Changing `received` recomputes `due`.)*

**Step 4 — Review & submit** ("Confirm and create", `check`, review).

### 6.2 Breach — rail `alert` "Log breach / Notifiable breach", verb **"Log breach"**, store `privacy.breaches.store`
Prefills: `discovered_at='2026-06-20'`, `serious_harm='Assessing'`.

**Step 1 — What happened** ("Nature & discovery", `alert`):
- `nature_of_breach` — **Nature of breach** — textarea, **required**, ph "Describe what happened…".
- `discovered_at` — **Discovered at** — date, **required**.

**Step 2 — Impact** ("Who & what is affected", `users`):
- `affected` — **Approx. individuals affected** — text(number), ph "0".
- `categories` — **Affected data categories** — ChipMulti: Contact details / Health / NHI / Financial / Identity documents / Support notes / Photographs.
- `likely_consequences` — **Likely consequences** — textarea, ph "Risk of harm to affected individuals…".

**Step 3 — Response** ("Containment & notification", `shieldCheck`):
- `measures_taken` — **Measures taken** — textarea, ph "Containment & remediation steps…".
- *(info `shieldAlert`, tone warn)*: "If the breach is likely to cause serious harm it is notifiable — notify the Privacy Commissioner via NotifyUs, as soon as practicable."
- `serious_harm` — **Serious-harm threshold met** — SelectInput (span): Assessing / Yes — OPC-notifiable / No.

**Step 4 — Review & log** ("Confirm and log", `check`, review).

### 6.3 Legal hold — rail `scale` "New legal hold / Preservation order", verb **"Create hold"**, store `privacy.legal-holds.store`

**Step 1 — Basis** ("Type & reason", `scale`):
- `hold_type` — **Hold type** — TilePicker, **required**, 3-col: Litigation / Investigation / Regulatory / Audit / Other.
- `reason` — **Reason** — textarea, **required**, ph "Why must this data be preserved…".

**Step 2 — Scope** ("Records & authority", `list`):
- `legal_authority` — **Legal authority** — text, ph "e.g. Employment Relations Authority".
- `review_date` — **Review date** — date.

**Step 3 — Review & create** (`check`, review).

### 6.4 Retention policy — rail `lock` "New retention policy / Lifecycle rule", verb **"Create policy"**, store `privacy.retention.store`

**Step 1 — Policy** ("Name & scope", `lock`):
- `policy_name` — **Policy name** — text, **required**, ph "e.g. Client records".
- `model_type` — **Applies to (model)** — text, **required**, ph "e.g. Client".
- `description` — **Description** — textarea, ph "What this policy covers…".

**Step 2 — Periods** ("Retain, archive, delete", `calendar`):
- `retention_period_years` — **Retention period (years)** — text(number), **required**, ph "7".
- `archive_after_years` — **Archive after (years)** — text(number), ph "optional".
- `legal_basis` — **Legal basis** — text, ph "e.g. Privacy Act 2020 IPP 9".

**Step 3 — Review & create** (`check`, review).

### 6.5 DPIA — rail `shieldCheck` "New DPIA / Impact assessment", verb **"Create DPIA"**, store `privacy.dpia.store`

**Step 1 — Assessment** ("Project & type", `shieldCheck`):
- `assessment_name` — **Assessment name** — text, **required**, ph "e.g. New client portal".
- `project_or_process` — **Project or process** — text, **required**, ph "What is being assessed".
- `assessment_type` — **Assessment type** — TilePicker, **required**, 2-col: New project / Process change / System upgrade / Periodic review.

**Step 2 — Processing** ("Purpose & basis", `list`):
- `processing_purpose` — **Processing purpose** — textarea, **required**, ph "Why personal data is processed…".
- `legal_basis` — **Legal basis** — text, **required**, ph "e.g. Privacy Act 2020 IPP 1–4".

**Step 3 — Risk** ("Rating & mitigation", `alert`):
- `overall_risk_level` — **Overall risk level** — TilePicker, **required**, 4-col: Low / Medium / High / Very high.
- `mitigation` — **Mitigation measures** — textarea, ph "How risks are reduced…".

**Step 4 — Review & create** (`check`, review).

> **Review-row label map** (`REVIEW_LABEL`) for the review step is fully specified in the mock (line 836) — reuse for consistent review labels (e.g. `nature_of_breach`→"Nature", `serious_harm`→"Serious harm", `overall_risk_level`→"Risk level").

---

## 7. LIFECYCLE ACTION MODALS (`buildAction`)

Small 460px modal: icon tile (violet, or critical-red for destructive) + title + blurb; fields stacked; footer **Cancel** + colored confirm. `submitAction` mutates the request + toast. Field types: `isSelect`/`isText`(+inputType)/`isArea`.

| kind | Icon · tone | Title | Blurb | Fields | Confirm btn |
|---|---|---|---|---|---|
| `verify` | `fingerprint` · primary | **Verify identity** | "Confirm the requester's identity before processing (IPP 6)." | **Verification method** SelectInput: RealMe verified / Drivers licence / Passport / In person / Known to organisation | **Confirm verification** (violet) → `status=in_progress, verified=true, method=…` |
| `extend` | `clock` · primary | **Extend deadline** | "Extend the statutory due date with a recorded reason." | **New due date** date; **Reason for extension** textarea | **Extend deadline** (violet) → sets `due` |
| `complete` | `check` · primary | **Mark complete** | "Close the request and record completion notes." | **Completion notes** textarea | **Mark complete** (violet) → `status=completed` |
| `refuse` | `ban` · **critical** | **Refuse request** | "Record the reason and legal basis for refusal." | **Reason** textarea; **Legal basis** text | **Refuse request** (**red**) → `status=rejected` |
| `export` | `download` · primary | **Export data package** | "Generate a JSON data package for this request." | *(none)* | **Generate package** (violet) → toast "Data package generated" |

> **Critical destructive action — "Execute deletion"**: in the mock this is **NOT** a modal — it fires directly from the Deletion-logs row ctx menu (`delCtx`, critical tone, sub "{records} records") and immediately sets `status='executed'` + toast. **Build recommendation**: promote this to a proper critical confirm modal (red icon tile, irreversible-warning blurb naming the record count + model, typed-confirm or explicit "Execute deletion" red button) since it is an irreversible bulk delete. The breach **Notify OPC** and hold **Release hold** are likewise direct ctx actions with no confirm in the mock — consider confirms.

---

## 8. DOCUMENT / FILE UPLOAD — **CRITICAL FINDING**

**The mock contains NO file/document upload UI anywhere.** Verified by full read + grep: there is no `<input type="file">`, no drag-drop zone, no attachments/evidence section, no file list, no progress bar (the only "progress" elements are the wizard/detail **step** progress bars), no remove/preview affordance. The word "document" appears once, only as the **"Identity documents"** chip option in the breach *Affected data categories* field. "Export data package" / "Export package" generate an outbound JSON file (download) but accept no upload.

**This is a gap the user explicitly wants filled** ("premium document upload feature, feature-complete"). A net-new, reusable premium uploader must be designed and dropped into the surfaces where the privacy domain logically requires evidence/attachments. Recommended placements (none exist in mock — all must be built):

1. **Request → Subject & verification** (and the **Verify identity** action modal): upload **identity verification documents** (RealMe confirmation, driver licence/passport scan). This is the strongest fit — IPP 6 requires confirming identity before release.
2. **Request → Scope/Details** and **Export package**: attach/assemble the **disclosed records / response pack** that gets exported to the requester.
3. **Breach wizard → Response (or a new Evidence step)**: upload **breach evidence** (screenshots of the misdirected email, incident reports, NotifyUs confirmation receipt).
4. **Legal hold → Scope**: upload the **legal authority document** (court order, ERA/HDC notice referenced in `legal_authority`).
5. **DPIA → a supporting-docs section**: upload **DPIA supporting documents** (the assessment doc, sign-off, vendor security attestations).
6. **Deletion logs**: attach **certificate of destruction** evidence post-execution.

**Premium uploader spec to build** (match app token system, no oklch literals): a full-width dropzone card — dashed border, `upload`-style icon, "Drag & drop or **browse**" affordance, file-type + max-size hint line (e.g. "PDF, JPG, PNG · up to 10 MB"); a **file list** below showing per-file row = type icon + filename + size + status; **upload progress** (per-file determinate bar) → success check / error state with retry; **remove (×)** per file; multi-file; client-side type/size validation with inline error in the wizard's `f.error` style (red triangle + message). Reuse the wizard's `info`-callout style for guidance, and the critical-tone styling for validation errors. Since this is the headline feature, build it as a standalone reusable component wired to a real upload endpoint + an `attachments`/`privacy_attachments` polymorphic store (mirroring how H&S/Safeguarding handle `*Attachment` evidence per the codebase memory), not a mock stub.

---

## 9. Micro-interactions, empty states, details not to miss

- **Toast**: dark pill, bottom-center, green-check medallion, auto-dismiss **2600ms** (`pdIn` in). Single toast at a time (clears prior timeout). Many actions are toast-only stubs in the mock (breach/hold/DPIA/retention/deletion "View", Copy link, Open full page, Run review) — these map to real navigations/endpoints.
- **Animations**: `pdPing` (hero status ping dot), `pdIn` (modals/popover/toast slide-up-scale), 0.3–0.4s width transitions on progress/completeness bars. Custom thin scrollbars (`.pd-scroll`).
- **Hover states**: rows → `--muted` 55%; hero tiles → white/20%; ctx items → `--accent`; popover/close buttons → `--muted`.
- **Keyboard a11y**: rows are `tabindex=0` with Enter/Space → open (`rowKey`). Close buttons have `aria-label`. `role=tablist/tab/menu/menuitem/separator` set.
- **Overdue logic** (`isOverdue`): `due < TODAY && status ∉ {completed,rejected,withdrawn}` → red due date + "overdue" sub + "Overdue · 20 wd" footer chip + drives the hero "Overdue" tile and critical NZ badge.
- **Retention "review due"**: `review <= TODAY` → red "review due".
- **Wizard completeness %** (`wCompletion`): non-subhead/info fields filled ÷ total, shown in rail bar (independent of step progress).
- **`Save & add another`** (review step) and success-pane **`Add another`** both re-open a fresh wizard of the same domain (toast "…ready for another").
- **Empty states**: pagination always shows page "1"; "Showing N of M". No explicit zero-row empty-state markup in mock — **add an empty state per worklist** (icon + "No {domain} match your filters" + Clear) since search can yield zero rows. No loading skeletons in mock — add for async.
- **Site filter** ("All sites · 6") and **Prev** pagination are inert (`noop`) — wire to real scoping/paging.
- **Detail/breach/hold/DPIA/retention/deletion detail dialogs** beyond the request are stubs — full detail modals for those domains are unbuilt and need design parity with the request dialog.
- **Sidebar/user chrome** (Manaaki / "Care operations", user "Mere Tait · Privacy Officer", ⌘K pill, breadcrumb "Privacy / Dashboard") is app shell — already exists; do not rebuild.

**Seed/demo data** (8 requests, 4 breaches, 3 holds, 4 retention, 4 DPIAs, 4 deletions) is fully enumerated in the mock (lines 594–633) — useful as factory/seeder fixtures and to validate every badge/flag branch (e.g. PRR-2035 is overdue; DBL-1184/DBL-1171 are OPC-due; DBL-1171 also subject-notify; DEL-547 is the only `scheduled` deletion → the Execute-deletion path).