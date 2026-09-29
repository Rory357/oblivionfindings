# P01 v2 — Mockup design-rules checklist (self-check with evidence)

**Checklist:** `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 Sep 2026).

**Evidence:**
- Screenshots are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`.
- `screenshots/report.json` records, for every capture:
  - horizontal overflow;
  - console errors;
  - whether the scripted steps completed;
  - the header subline's line count and any truncated meter caption;
  - new in v2, every worker control under 44 px (`small`, and `buttonsUnder44` for the whole run).
- There are 197 captures: 91 states at 1440, and 53 core states also at 1280 and 200 %. All have overflow 0 and no console errors, and every step completed.
- Source paths are relative to `src/`.

v2 is the approved v1 plus the changes listed in the README. Rows marked *(v2)* changed from v1's checklist; every other row's evidence is the same state, now captured from v2.

Legend: **Pass** · **Pass (note)** = passes with a stated limitation · **N/A** = not in P01 scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| Vite + React preview in `docs/emar-design/P01/v2/`, scaffold copied from Fleet PKG-02B v13 (`vite.config.mjs` with `@` → `resources/js` and `@tailwindcss/vite`, `index.html`, `main.tsx`, `serve.mjs`) | Pass | `vite.config.mjs`, `index.html`, `src/main.tsx`, `serve.mjs` (port 4382) |
| Real primitives, never hand-rolled: PageHeader, Switch, Dialog, WizardShell, StatusBadge, EntityTable, FileDropzone, DateTimeField | Pass (note) | As v1. Switch and FileDropzone: N/A (no settings or uploads in P01). |
| Synthetic only; no application API; fixed clock in Pacific/Auckland | Pass | `src/data.ts`, `src/clock.ts` (Monday 28 Sep 2026, 9:12 am NZDT); the shim never sends; `serve.mjs` is GET/HEAD only |
| No edits to shared components *(v2)* | Pass (note) | `git diff --stat origin/main` touches only `docs/emar-design/P01/**` (plus the git-ignored `.claude/launch.json` entry). `src/styles.css` *previews* two build changes in the preview only, each labelled in the file: the `.frontline-tap` 44 px fix (Q-v2-1) and Q8's `clearable={false}` on required times (approved). |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | `1440-01-meds-today-schedule.png` |
| Plain title, one StatusBadge chip, one-line fact subline | Pass | As v1; `report.json` `header.sublineLines` = 1 at 1440 and 1280 |
| No greetings, no LIVE/refreshed eyebrows | Pass | As v1 |
| One meter row of 4–6 blocks; every block links; real data; n/a and Unavailable | Pass | As v1: `1440-80-loading.png`, `-81-no-work-left.png`, `-82-couldnt-load.png`. No truncated captions at 1440 or 1280 (`report.json`). |
| Search and primary filters inside the header | Pass | As v1 |
| `PageHeaderRail` connected tabs, ≤ 8, overflow to More, Find chip | Pass | `1440-01…`, `1280-01…`, `1440-08-follow-ups-link-only.png` |
| Breadcrumbs rooted at Home | Pass | As v1 |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | `shell.tsx` |
| Full-width body, no `max-w` cap | Pass | All pages |
| A number lives once *(v2)* | Pass | As v1. In *My Day doesn't show someone*, the Medicines card, My Day's header meter and the rostered tasks count only the people My Day shows. Nothing is added twice: the pointer carries no number (`1440-91b-my-day-person-not-shown.png`). |
| Desktop only; 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json`: overflow 0 on every capture |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable / EntityCard contracts; identity first, kebab last | Pass | As v1 |
| ⋯ menu and the same menu on right-click (plus the keyboard menu key); row click opens | Pass (note) | `1440-09-row-context-menu.png`; `report.json` → `keyboard` (as v1) |
| Server-style pagination | Pass (note) | `1440-07-activity-paginated.png` (real `LaravelPagination`) |
| Empty, loading, error states use the shared components | Pass | `80`, `81`, `82` |
| Row text keeps the identifying part visible *(v2)* | Pass | The house lead's task titles lead with the fact and end with the person, short enough to show the name at 1440: `1440-28e-countersign-nobody-lead-follow-up.png`, `1440-96-all-tasks-house-lead.png` |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Add/edit with 2+ sections uses WizardShell | Pass | `1440-10…`, `-13…`, `-14-record-review.png`, `-16-record-success.png` |
| Simple dialogs follow the Fleet Settings `Modal` | Pass | `1440-59-why-no-witness.png`, `-60…`, `-61…`, `-63-my-eligibility.png` |
| Consequential saves use confirm-dialog; destructive variant | Pass | As v1 (`1440-62-manager-decline.png`) |
| Never a browser prompt or confirm | Pass | None used |
| Errors keep values and focus the first error; focus returns to the opener | Pass | `1440-12…`, `-20-wrong-pin.png`; `report.json` keyboard |
| Q2: nobody to confirm a rule-required dose *(v2)* | Pass | Notice with roster evidence (`1440-28b-countersign-nobody-on-shift.png`); review row (`1440-28c-countersign-nobody-review.png`); recorded with the warning line (`1440-28d-countersign-nobody-recorded.png`); the house lead's task (`1440-28e-countersign-nobody-lead-follow-up.png`). Controlled drugs are unchanged (`1440-59-why-no-witness.png`, `-60…`). |
| Order changed mid-round *(v2)* | Pass | The change banner, the amount and the rows use the new order, 1½ tablets (750 mg): `1440-38-order-changed-mid-round.png`, `-38b-order-changed-new-amount.png`, `-38c-order-changed-round-rows.png` |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | N/A | No settings in P01 (P11) |
| Growing lists use a searchable picker | Pass | `1440-19-witness-picker.png`, `-41-as-needed-picker.png` |
| Uploads use FileDropzone | N/A | No upload in P01 (P06) |
| Dates and times use the PKG-01 DateTimeField, timezone visible *(v2)* | Pass | As v1. The Q8 `clearable={false}` is previewed on the dialog's required times, so no 28 px “Clear date and time” shows (`1440-13-record-step2-given.png`). |
| Buttons not restyled per page *(v2)* | Pass | One frontline size: the default `Button` with the app's `.frontline-tap`, on every worker button. There is no `size="sm"` anywhere a worker acts (the only one left is the shell's Report incident, which copies the app header). Text-link amount choices became outline buttons. ESLint: 0 errors, 0 warnings on 18 files. |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only | Pass | ESLint clean on `src/**` |
| Every status uses StatusBadge; safety uses the fixed pairs *(v2)* | Pass | A real allergy match is the critical pair in the dialog and on the row, even in Warn (Q11): `1440-29-allergy-warn.png` |
| Colour never the only signal | Pass | As v1 |
| lucide icons only; icon-only buttons have `aria-label` | Pass | As v1 |
| Visible focus ring; 44 px tap targets on frontline actions *(v2)* | Pass (note) | Every Button, Select trigger and date/time picker that P01 places in the page body or a dialog is at least 44 px at 1440, 1280 and 200 %. `report.json` → `buttonsUnder44` lists only the shared components’ own controls. This relies on the previewed `.frontline-tap` fix: in the app today it is 38.5 px (Q-v2-1). The shared targets still under 44 px are in the README table: EntityTable ⋯ 28 px, dialog ✕ 28 px, pagination 28 px, calendar source chips 27 px, TierTwoTabs 35 px, ErrorState “Try again” 32 px, and the picker search field 35 px. Each has a 44 px or keyboard alternative on the same screen (Q-v2-2). |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes in product copy | Pass | As v1 |
| “Not configured” / “Not available” / “Unknown” instead of fake zeros | Pass | As v1 |
| Every blocked action says why and who can unblock it *(v2)* | Pass | `1440-51…` to `-59…`. “Who can give it” is built from the roster (Daniel Ahn, Mere Kahu and Jordan Tipene): `1440-55-why-competency-expired.png`, `-56-why-restricted.png` |
| No invented clinical values | Pass | As v1 |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions; unbuilt things hidden | Pass (note) | As v1 |
| Synthetic data clearly labelled | Pass | Hatched viewer bar |
| Reference frames owned by other modules | Pass | As v1 (transport, All Tasks, MAR, client profile) |
| Views approved earlier reused unchanged unless an approved change says otherwise *(v2)* | Pass | v1 as approved (`3ac640485`). The changes are Stephan's approved Q2, Q7, Q8 (DateTimeField) and Q11, the gaps he asked to be fixed, and the button sizes he asked for, all listed in the README. |
| Privacy rule kept *(v2)* | Pass | My Day names and counts only people the worker may view there. The pointer names nobody (`1440-91b-my-day-person-not-shown.png`). |

## 3. Process

- Designer self-check: this file, screenshots at 1440, 1280 and 200 %, and `report.json`.
- Next: the review session inspects v2 beside v1 at 1440, then Stephan approves the exact version.
