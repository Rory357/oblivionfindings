# P02 v1 — Mockup design-rules checklist (self-check with evidence)

Checklist: `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 Sep 2026) plus the brief’s §5 review findings. Evidence files are in `screenshots/` as `{1440|1280|zoom200}-{state}.png` (198 captures: 94 states at 1440, 52 core states also at 1280 and 200 %; overflow 0, console errors 0, every step completed); `screenshots/report.json` records, for every capture, horizontal overflow, console errors, whether the scripted steps completed, each header’s subline line count and any truncated meter caption. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** = passes with a stated limitation · **N/A** = not in P02’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| Vite + React preview in `docs/emar-design/P02/v1/`, scaffold copied from P01 v1 (`vite.config.mjs` with `@` → `resources/js` and `@tailwindcss/vite`, `index.html`, `main.tsx`, `serve.mjs`, `styles.css` with explicit `@source`, `tools/verify.mjs`, contract page, CHECKLIST, VERSION) | Pass | Those files; `styles.css` keeps only the `components` and `lib` sources P02 needs |
| Dependencies installed in this worktree | Pass | `npm ci` in `reverent-sanderson-35a6f1` (641 packages); no junction |
| Real primitives, never hand-rolled | Pass | PageHeader + meter blocks + donut + filter pills + view toggle + PageHeaderRail with Find (`pages/record.tsx`), TierTwoTabs, TabSearchPalette, EntityTable + entity-menu + ListCaption, `ui/table` for the chart grid, WizardShell + ReviewCard + WizardSuccessPane, Dialog (P01’s Fleet-Settings `Modal`), ConfirmDialog, Switch, StatusBadge, DateTimeField and DatePicker (PKG-01), Select, Checkbox, EmptyState, ErrorState, SkeletonTable/SkeletonCard, LaravelPagination, FilePreviewDialog, Breadcrumbs |
| Recording is P01’s dialog, not redesigned | Pass | `src/p01/*` are P01 v1’s eight recording files **byte-identical** to `3ac640485` (hashes match P01’s VERSION.txt — see VERSION.txt); `1440-07-chart-record-opens-p01-dialog.png` (“Opened from: MAR chart”) |
| Synthetic only; no application API; fixed clock | Pass | `data.ts`; P01’s `clock.ts` (Monday 28 Sep 2026, 9:12 am NZDT); the P01 Inertia shim never sends; `serve.mjs` is GET/HEAD only |
| No edits to shared components | Pass | `git diff --stat` touches only `docs/emar-design/P02/v1/**`. Limits recorded as questions (README) |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | `1440-01-chart-day.png` (today’s page is a PageHero — AUDIT 1.1) |
| Plain title, one StatusBadge chip, fact subline | Pass (note) | “Aroha Mere Ngata” + “Active”. The subline is **two lines**, as `PAGE_HEADER_STYLE_GUIDE.md` §4 requires for record profiles and as the live Fleet vehicle profile and client profile do (identity line · record line). The brief’s one-line rule came from index pages (P01 Meds today). `report.json` `header.sublineLines` = 2 on the record and profile; 0 truncated meter captions at 1440 and 1280; at 200 % only the client-profile frame’s real Medications caption truncates (live wording; `zoom200-120…`, `-121…`, `-126…`, `-127…`) |
| No greetings, no LIVE/refreshed eyebrows | Pass | Replaces today’s “LIVE MEDICATION CHART” eyebrow; the refresh time is a filter-row chip “As at 9:12 am · Pacific/Auckland” (Fleet pattern) |
| One meter row of 4–6 blocks; each links; real data; graph form; n/a and Unavailable | Pass | 6 blocks: Due now · Late · Recorded today (donut) · Medicines · Allergies · INR (Syringe driver for Grace). Each switches to its view. `1440-111-no-medicines.png` (n/a), `1440-112-couldnt-load.png` (Unavailable), `1440-110-loading.png` (—, “Loading…”); concealed count “+1 controlled — hidden” (`1440-100…`) |
| Search and primary filters inside the header; nothing between header and content | Pass | “Find in this record…” search (opens the section palette, `1440-118-find-in-record.png`); every rail view has real filter pills: Day/Week + day · limit-reached · type + needs attention · support · show resolved · range · range + outcome. Only the tier-2 strip sits below the band (record-page rule) |
| `PageHeaderRail` connected tabs, one line, ≤ 8, Find chip, no shadow | Pass | 6 sections + Find; at 1280 still one line (`1280-01…`) |
| Breadcrumbs rooted at Home | Pass | Support worker: Home › Meds today › Aroha Mere Ngata; leads: Home › Medication › MAR & medicines › Aroha Mere Ngata; profile: Home › Clients › Aroha Mere Ngata |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` | Pass | `shell.tsx` `main` `px-5 pb-5 gap-5`; sections `gap-5` |
| Full-width body, no `max-w` cap | Pass | All pages |
| A number lives once | Pass | Counts live in the meters; lists carry “N of N shown” captions only. The profile MAR tab drops today’s four tiles that repeated the header’s Medications meter (AUDIT 5.1) |
| Desktop only; 1440, 1280, 200 %; no horizontal page scroll | Pass | `report.json`: overflow 0 on every capture. Wide tables (chart, week) scroll inside their own container |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts; identity first, kebab last | Pass | Medicines, Stopped, Photos, Support, Allergies, Chart alerts, Interactions, INR, Checks, Observations, Doses, Corrections, All changes. The chart is a time grid on `ui/table` (medicine first, ⋯ last) |
| ⋯ menu + same menu on right-click (+ Shift+F10); row click opens | Pass (note) | One `MenuItem[]` per row feeds ⋯ and `EntityContextMenu` (`1440-11-chart-row-menu.png`). Every chart **cell** has its own menu (`1440-05…`, `-06…`); keyboard: the menu key on a focused cell opens it (`report.json` → `keyboard`). Headless Chrome doesn’t synthesise Shift+F10’s context-menu event; the handler treats any keyboard-origin context menu the same (as P01) |
| Server-style pagination | Pass | Doses: `LaravelPagination` (`1440-81-history-page-2.png`) |
| Empty / loading / error use the shared components | Pass | `EmptyState` (`111`, `32`, `42`, `67`), `SkeletonTable` / `SkeletonCard` (`110`), `ErrorState` (`112`, `124`) |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Add/edit with 2+ sections uses WizardShell | Pass | Request a correction (what · why · review · success: `83`–`85`); Start a syringe driver (`70`); Medicine details as a sectioned detail viewer with `headerLabel` (`21`–`24`) |
| Simple dialogs follow the Fleet Settings `Modal` anatomy and width tokens | Pass | P01’s `Modal` at 480 / 720: dose record (`82`), review a correction (`87`), chart alert (`46`), pause (`48`), Record INR (`63`), driver check (`69`), print (`105`), allergy review (`127`) |
| Consequential saves use ConfirmDialog stating the effect; destructive variant where a check is loosened | Pass (note) | “Pause dose alerts?” and “Stop showing this alert…” say **“Loosens this check”** and use `variant="destructive"` (`49`, `50`); “Approve this correction?” states the effect (`88`); “Mark as entered in error” is a destructive button (`66`). The real ConfirmDialog renders destructive purple (known bug, PR #15) — used as-is |
| Never a browser prompt/confirm | Pass | None |
| Errors keep values and focus the first error; focus returns to the opener | Pass | `47`, `65` (values kept, focus on the first error); Escape from P01’s dialog returns focus to the chart cell (`report.json` keyboard); P02 dialogs restore the opener (`store.tsx` `returnFocus`) |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | Pass | “Due and late dose alerts” and “Shown when the chart opens” (`45`, `46`) |
| Growing lists use a searchable picker | Pass (note) | Record INR with several anticoagulant orders uses Popover + Command, never pre-selected (`63b`, `63c`), as does “link afterwards” (`66b`); the section palette is searchable (`118`). Witness choice in the driver wizard is the on-shift list (P01’s full picker is the recording dialog) |
| Uploads use FileDropzone | N/A | Photos are taken at stock receipt (P06); P02 displays them with `FilePreviewDialog` (`27`) |
| Dates/times use the PKG-01 components, timezone visible | Pass | Correction time and driver start: DateTimeField (“Pacific/Auckland”); INR dates: the PKG-01 DatePicker |
| Buttons not restyled | Pass | `Button` variants only; `link` for ↗ jumps; `ghost` for “Show every medicine” resets |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only | Pass | ESLint (app config) clean for `src/` except documented native controls |
| StatusBadge for every status; safety uses fixed pairs | Pass | DoseBadge (P01), severity, correction, INR, alert statuses; allergy match on the chart is the **critical** surface (P01 Q11, `1440-10-chart-allergy-match-critical.png`) |
| Colour never the only signal | Pass | Labels + icons on every state |
| lucide only; icon-only buttons labelled | Pass | Glass icon buttons, kebabs, remove-allergy button |
| Focus ring; 44 px frontline targets | Pass | Chart cells and Record buttons use `frontline-tap` |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, plain words, no codes in product copy | Pass | Codes (NF-23, EM-12, D6, P03…) appear only in design-note chips and the contract page |
| Honest states, never a false “No known allergies” | Pass | “No allergies recorded for Tama — this doesn’t mean Tama has none”; “Allergy record couldn’t be loaded”; “Not reviewed”; “No target range recorded”; “Next test: Not set”; on-call contact “Not configured” |
| Blocked actions say why and who can unblock | Pass | Alerts switch for a support worker (“ask Jordan Tipene”), INR and driver checks, allergy review, own correction (two-person rule), controlled records (“The house lead or Rangi Parata can tell you more”) |
| No invented clinical values or regulator names | Pass (note) | INR targets, dose instructions and the syringe-driver rate are synthetic order data “from the prescriber / from the plan”; no ranges are judged for readings; interactions are “as recorded by the pharmacy”, with “not a database” |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions; unbuilt hidden | Pass (note) | Actions owned by other packages say so in a toast (“… — outside this preview”), the mockup boundary P01 used |
| Synthetic data labelled | Pass | Hatched viewer bar; photos “synthetic placeholder” |
| Reference frames mirror origin/main | Pass (note) | Client profile header, two meter rows, group rail and Health & safety tabs copied from `pages/operations/clients/show.tsx` @ `ddb8d3af4` (the RecentClientsStrip under the tabs is omitted); record page mirrors `fleet-assets/vehicles/show.tsx` + `vehicle-header.tsx` |
| Approved views reused unchanged | Pass | P01 v1 dialog and wording byte-identical; P00 allergy and blocked wording; P11 v4 concealment wording (“Details need controlled-medicine access”) |

## 3. Brief §5 findings checked first

| Finding | Result |
|---|---|
| A number lives once | Pass — see B |
| One-line subline / no truncated captions | Pass (note) — two lines on profile pages by the header guide; 0 truncated captions at 1440 and 1280 (report.json) |
| No link tiles dressed as meters | Pass — every meter shows data |
| No codes in product copy | Pass |
| Real button variants only | Pass |
| Honest states | Pass |
| Controlled-drug concealment everywhere | Pass — chart rows and week rows, medicines, support, photos, alerts, interactions, INR/driver (driver hidden whole), history, corrections, all changes, captions, search palette (sections only), dialogs (notice only), printout (“left out”), profile MAR tab: `100`–`105`, `71`, `122` |
| House scope; no access vs not found | Pass — `114`, `115`, `116`, `117` |
| Loosening is destructive, “Loosens this check” | Pass — `49`, `50` |
| Destructive ConfirmDialog renders purple | Noted — real component used (PR #15) |
| Reference frames mirror origin/main | Pass — fetched `ddb8d3af4` |
| Grounding marked verified / reported | Pass — AUDIT.md |
| Blocked actions say why and who | Pass |
| No invented clinical values or regulator names | Pass (note) |
