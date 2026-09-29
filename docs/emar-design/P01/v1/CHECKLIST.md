# P01 v1 — Mockup design-rules checklist (self-check with evidence)

Checklist: `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 Sep 2026). Evidence files are in `screenshots/` as `{1440|1280|zoom200}-{state}.png`; `screenshots/report.json` records, for every capture, horizontal overflow, console errors and whether the scripted steps completed (182 captures: 84 states at 1440, 49 core states also at 1280 and 200 %; overflow 0, errors 0, all steps completed). Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** = passes with a stated limitation · **N/A** = not in P01 scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| Vite + React preview in `docs/emar-design/P01/v1/`, scaffold copied from Fleet PKG-02B v13 (`vite.config.mjs` with `@` → `resources/js` and `@tailwindcss/vite`, `index.html`, `main.tsx`, `serve.mjs`) | Pass | `vite.config.mjs`, `index.html`, `src/main.tsx`, `serve.mjs` |
| Dependencies installed in this worktree | Pass | `npm ci` in `goofy-noyce-ae9936` (641 packages) |
| Real primitives, never hand-rolled: PageHeader, Switch, Dialog, WizardShell, StatusBadge, EntityTable, FileDropzone, DateTimeField | Pass (note) | PageHeader/meters/rail (`pages/meds-today.tsx`), WizardShell + ReviewCard + WizardSuccessPane (`record-dialog.tsx`), Dialog as the Fleet Settings `Modal` (`dialogs.tsx` `Modal`), StatusBadge (`ui.tsx` `DoseBadge`), EntityTable + entity-menu (`pages/*.tsx`), DateTimeField (`record-dialog.tsx`, `dialogs.tsx`). **Switch and FileDropzone: N/A** — P01 has no on/off settings (P11) and no uploads (photo capture is P06). Also real: My Day header and day list, SiteCalendar, MarGrid, FilePreviewDialog, Breadcrumbs, TierTwoTabs, ConfirmDialog, Popover + Command, EmptyState, ErrorState, SkeletonTable, LaravelPagination |
| Synthetic only; no application API; fixed clock in Pacific/Auckland | Pass | `src/data.ts`, `src/clock.ts` (Monday 28 Sep 2026, 9:12 am NZDT); `src/inertia-shim.tsx` never sends; `serve.mjs` is GET/HEAD only |
| No edits to shared components | Pass | `git diff --stat origin/main` touches only `docs/emar-design/P01/v1/**`. Limitations recorded as questions (README Q8) |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | `1440-01-meds-today-schedule.png`; `pages/meds-today.tsx` |
| Plain title, one StatusBadge chip, one-line fact subline | Pass | “Meds today” + “On shift” (“Not clocked in” in `1440-50-not-clocked-in.png`) + “Mon 28 Sep 2026 · Kōwhai House · shift 7:00 am–3:00 pm” — one line at 1440 and 1280 (review fix 4; measured); the day is stated (Stephan, P00 v4); the NZDT zone shows in the header’s filter row on every view |
| No greetings, no LIVE/refreshed eyebrows | Pass | Replaces today’s “Kia ora …” PageHero. The refresh time is a filter-row control, not an eyebrow |
| One meter row of 4–6 blocks; every block links; real data; graph form where one exists; n/a and Unavailable | Pass | Captions fit at 1440 and 1280 (Late “Oldest due 8:00 am”, review fix 5; others shortened — `report.json` `header.truncatedCaptions` is empty at 1440 and 1280. At 200 % only the real My Day header’s own two captions truncate — the shared component, not changed). 6 blocks: Due now · Late · Needs help · Recorded (donut) · Follow-ups · My eligibility (opens My eligibility). `1440-81-no-work-left.png` (Recorded **n/a**), `1440-82-couldnt-load.png` (**Unavailable**, “—”), `1440-80-loading.png` (skeleton). Counts come from the one shared schedule (`store.tsx` `useCounts`) |
| Search and primary filters inside the header; nothing between header and content | Pass | Search “Search people or medicines…”; every rail view has real filter pills (states, group by, rounds state, person, range, outcome) plus the refresh chip |
| `PageHeaderRail` connected tabs, one line, ≤ 8, overflow to More, Find chip, no shadow | Pass | 7 views + Find (`1440-01…`); at 1280 the shared rail folds Stock alerts and Activity into “More” (`1280-01…`). Follow-ups / Controlled checks / Stock alerts are link-only tabs for P08a / P07a / P06 (`1440-08-follow-ups-link-only.png`) |
| Breadcrumbs rooted at Home | Pass | Real `Breadcrumbs`: Home › Meds today (support worker); Home › Medication › Meds today (leads); Home › My Day; Home › All Tasks; Home › Clients › Aroha Mere Ngata; Home › Fleet & Assets › Transport Logs › Transport #12 (as on the live transport page) |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | `shell.tsx` main `px-5 pb-5 gap-5`, crumbs `py-2.5`; pages add no outer padding |
| Full-width body, no `max-w` cap | Pass | All pages |
| A number lives once, in the meter row | Pass | Meds today: no body KPI cards. My Day: the Medicines card shows Due now / Late / Needs help; “Recorded” lives once, in My Day’s header meter (review fix 1) |
| Desktop only; 1440, 1280 and 200 %; no horizontal page scroll | Pass | Top bar date follows the shell guide (full ≥ 1320 px, “Mon 28 Sep” 1140–1320 px, hidden below). `report.json`: overflow 0 on all 182 captures. At 200 % the shell uses its icon rail (`zoom200-01…`); wide tables scroll inside their own container |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable / EntityCard contracts; identity first, kebab last | Pass | Schedule, Rounds, As-needed, Activity, transport, All Tasks rows (`EntityTable`) |
| ⋯ menu and the same menu on right-click (plus Shift+F10); row click opens | Pass (note) | One `MenuItem[]` per row feeds the kebab and `EntityContextMenu` (`1440-09-row-context-menu.png`). Keyboard: the menu key on a focused row opens the same menu (`report.json` → `keyboard`). Headless Chrome doesn’t synthesise the context-menu event for Shift+F10; the handler treats every keyboard-origin context menu alike. Row click opens Record / Why / Review / detail (`doses.tsx` `useRowOpen`). The MAR grid’s own cells open the same menu (`1440-101-mar-mark-given-menu.png`) |
| Server-style pagination | Pass (note) | Activity uses the real `LaravelPagination` (`1440-07-activity-paginated.png`). The Schedule is one shift’s doses and isn’t paginated |
| Empty, loading, error states use the shared components | Pass | `EmptyState` (`81`), `SkeletonTable` (`80`), `ErrorState` (`82`) |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Add/edit with 2+ sections uses WizardShell (stepper, “Step x of y”, review, success pane) | Pass | The recording dialog: `1440-10…`, `-13…`, `-14-record-review.png`, `-16-record-success.png`. Offline-queued and “more than ordered” deliberately don’t use the green success pane (nothing is on the chart yet / it’s an error) — toast and the “Recorded and reported” dialog instead |
| Simple dialogs follow the shell/body split, width tokens and footer layout (Fleet Settings `Modal`) | Pass | `dialogs.tsx` `Modal` mirrors `pages/fleet-assets/settings/_ui.tsx` at 480 / 720 px: `1440-59-why-no-witness.png`, `-60…`, `-61-manager-one-screen-approval.png`, `-63-my-eligibility.png` |
| Consequential saves use confirm-dialog stating the effect; destructive variant | Pass | `ConfirmDialog`: “Discard this record?” (dirty close) and “Say you weren’t there?”; “Decline request” uses `variant="destructive"` (`1440-62-manager-decline.png`) |
| Never a browser prompt or confirm | Pass | None used |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | `1440-12-record-step2-validation.png`; wrong PIN returns to the outcome step with values kept and focus on the PIN (`1440-20-wrong-pin.png`). Escape returns focus to the row’s Record button; after saving, Done focuses the same row’s new “View” (`doses.tsx` `returnFocusFor`, `report.json` keyboard) |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | N/A | No settings in P01 (P11); the Medication rules settings are linked, not designed |
| Growing lists use a searchable picker | Pass | Colleague picker (Popover + Command) `1440-19-witness-picker.png`; as-needed picker `1440-41-as-needed-picker.png` |
| Uploads use FileDropzone + StagedFileCard | N/A | No upload in P01 (photo capture at stock receipt is P06). Viewing a medicine photo uses the real `FilePreviewDialog` |
| Dates and times use the PKG-01 DateTimeField, timezone visible | Pass | Given time, follow-up time, effect-check time, prescriber’s instruction time, override start/end (`1440-13…`, `-27…`, `-61…`) — “Pacific/Auckland” legend |
| Buttons not restyled per page | Pass | Only `Button` variants and the PageHeader button family. ESLint: 0 warnings (intentional native buttons carry the documented disable comments: shell chrome, tile picker per POPUP guide, photo thumbnail) |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only; no raw palette, hex or `dark:` pairs | Pass | ESLint clean on `src/**`. The only literal colours are in the synthetic photo PNG generator (image content) |
| Every status uses StatusBadge; safety uses the fixed pairs | Pass | `ui.tsx` `DoseBadge`, `EntityStatusChip`; allergy and safety notices use `status-critical` / `status-warning` pairs |
| Colour never the only signal | Pass | Every state has a label and icon; blocked lines have a lock icon and text |
| lucide icons only; icon-only buttons have `aria-label` | Pass | Header glass buttons, sidebar icon rail (`aria-label` + `title`), steppers |
| Visible focus ring; 44 px tap targets on frontline actions | Pass | `frontline-tap` on row actions, tiles and My Day buttons; shared focus rings |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | Reason list in plain words from the existing values (`contract.ts`); decision and package codes appear only in design notes and the contract page, never in product copy |
| “Not configured” / “Not available” / “Unknown” instead of fake zeros; never “No known allergies” when nothing is recorded | Pass | `NotConfigured` chip (on-call contact, late-dose instruction, re-offer rule); “No allergies recorded for Tama — this doesn’t mean Tama has none”; “Allergy record couldn’t be loaded for Grace” |
| Every blocked action says why and who can unblock it | Pass | `1440-51…` to `-59…` (all NF-07 reasons) |
| No invented clinical values or regulator names | Pass | Order amounts and limits are labelled synthetic order data; the blood sugar field has no range (“follow Aroha’s plan”) |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions or meter blocks; unbuilt things hidden | Pass (note) | Actions that belong to other packages or global chrome say so in a toast (“… — outside this preview”), which is the mockup boundary, not product behaviour. Link-only tabs are the brief’s instruction and are labelled |
| Synthetic data clearly labelled | Pass | Hatched viewer bar: “mockup viewer — not product UI · Synthetic data”; photos say “synthetic placeholder” |
| Reference frames owned by other modules | Pass | Transport copies main’s migrated header (`transports/show.tsx` after `cbd9b3ccf`/`75d5f46b8`: profile PageHeader, no meters, Medication Transit and Pre-Transport Check links), with no meter tiles invented (review fix 2); its row action says “Record” (fix 3) |
| Views approved earlier reused unchanged unless an approved change says otherwise | Pass (note) | P00 v5 states, wording and dialogs reused (blocked reasons, allergy notices, amount paths, witness override, “Recorded and reported”, app-wide banners, My Day card, rostered tasks), restyled with the real components as the brief asks. P01 additions are listed in the README |

## 3. Process

- Designer self-check: this file, screenshots at 1440, 1280 and 200 %, `report.json`.
- Next: the review session inspects side by side with the Fleet references at 1440 (Fleet Vehicles/Maintenance for lists and records, the Fleet Settings `Modal`, PKG-01 DateTimeField), opening every dialog. Only then Stephan.
