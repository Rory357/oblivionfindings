# P07a v1 — Mockup design-rules checklist (self-check with evidence)

Checklist: `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026), plus the findings earlier packages had to fix (`Mockup-session-brief.md` §5).

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records:
- horizontal overflow;
- console errors;
- whether the scripted steps completed;
- the header subline’s line count;
- any truncated meter captions.

The totals are in the Verification section at the end. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P07a’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `src/main.tsx`, `serve.mjs`, all copied from P01 v1. `src/inertia-shim.tsx` is copied from P01 v1 unchanged. `src/styles.css` has explicit `@source` lines. |
| Dependencies installed in this worktree | Pass | `npm ci` in `intelligent-antonelli-87bad1` (641 packages). No junction. |
| Real primitives, never hand-rolled | Pass | These are all the real components:<br>• `PageHeader`, the meter blocks, the donut, `PageHeaderRail`, filter selects and search (`pages/meds-today.tsx`);<br>• `WizardShell`, `ReviewCard`, `ReviewRow`, `WizardSuccessPane` (`count-dialog.tsx`, `movement-dialog.tsx`, `followup-dialog.tsx`);<br>• `Dialog` in the Fleet Settings `Modal` layout (`dialogs.tsx`);<br>• `ConfirmDialog`;<br>• `EntityTable`, `ListCaption`, the entity cells, `EntityContextMenu` and `compactMenu`;<br>• `StatusBadge`, `EmptyState`, `ErrorState`, `SkeletonTable`, `LaravelPagination`;<br>• `Popover` + `Command`;<br>• `Select`, `Checkbox`, `Input`, `Textarea`;<br>• `DateTimeField` (PKG-01);<br>• `Breadcrumbs`, `Alert`, `Table`. |
| Synthetic data only; no application API; fixed clock in Pacific/Auckland | Pass | `data.ts`; `clock.ts` (Monday 28 Sep 2026, 2:48 pm NZDT). The shim never sends anything, and `serve.mjs` answers GET and HEAD only. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P07a/v1/**`. The `.claude/launch.json` preview entry is gitignored and local. |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | `1440-01-controlled-checks.png`. This is P01 v1’s approved header, unchanged. |
| A plain title, one StatusBadge chip, and a one-line fact subline | Pass | “Meds today” with the chip “On shift” (or “Not clocked in”, or “Not rostered today” for the manager) and the subline “Mon 28 Sep 2026 · Kōwhai House · shift 7:00 am–3:00 pm”. `report.json` `header.sublineLines` is 1 at 1440 and 1280. |
| No greetings and no LIVE or refreshed eyebrows | Pass | The refresh time is a filter-row chip. |
| One meter row of 4–6 blocks, each linked, with real data, the graph form, and “n/a” / “Unavailable” | Pass | P01’s six blocks, computed from the doses the persona may see. Recorded is a donut. Needs help links to Controlled checks when there’s no witness (`1440-40-nobody-can-witness.png`). `1440-82-couldnt-load.png` shows Unavailable; `1440-80-loading.png` shows the skeleton meters. `header.truncatedCaptions` is empty at 1440 and 1280. |
| Search and primary filters inside the header; nothing between the header and the content | Pass | Controlled checks has Everything / Needs doing now, Person (or House for the manager), Register range, and the refresh chip (`1440-05-needs-doing-now.png`). |
| `PageHeaderRail`: one line, 8 views or fewer, overflow to “More”, the Find chip, no shadow | Pass | 7 views plus Find, or 6 for Tomasi (`1440-90-no-cd-access-schedule.png`). At 1280 the shared rail moves the overflow into “More”. |
| Breadcrumbs rooted at Home | Pass | Home › Meds today for frontline staff; Home › Medication › Meds today for leads (`1440-50-house-lead-view.png`). |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | `shell.tsx` main uses `px-5 pb-5 gap-5`. The views add no outer padding. |
| Full-width body with no `max-w` cap | Pass | All views |
| A number lives once, in the meter row | Pass | Controlled checks has no KPI cards. List captions give only “N shown” and the cadence. The Controlled checks count sits on its rail tab. |
| Desktop only; checked at 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json` shows overflow 0 on every capture. At 200 % the shell uses its icon rail, and wide tables scroll inside their own container. |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts: identity first, kebab last | Pass | All five sections, the Schedule reference frame, and the history dialog |
| ⋯ and the same menu on right-click (plus Shift+F10); clicking a row opens it | Pass (note) | Each row has one `MenuItem[]` feeding both the kebab and `EntityContextMenu` (`1440-06-row-context-menu.png`). `report.json` → `keyboard` records the menu opened from a focused row. As P01 recorded, headless Chrome doesn’t synthesise the context-menu event for Shift+F10, so the harness also tries the menu key. Clicking a row opens the count, the history, the override, the answer or the discrepancy. |
| Server-style pagination | Pass | The Register uses the real `LaravelPagination` at 10 per page (`1440-04-register-page-2.png`) |
| Empty, loading and error states use the shared components | Pass | `EmptyState` (`81`, and in empty sections), `SkeletonTable` (`80`), `ErrorState` (`82`) |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Add or edit with 2 or more sections uses WizardShell (stepper, “Step x of y”, review, success pane) | Pass | Count (`10`–`20`), Record a movement (`70`–`74`), and the house lead’s follow-up (`51`–`55`) |
| Simple dialogs follow the shell/body split, width tokens and footer (the Fleet Settings `Modal`) | Pass | `dialogs.tsx` `Modal` is P01 v1’s, unchanged. Used for: ask to witness (`30`), answer (`31`), why can’t I count (`41`), override request (`42`), manager approval (`44`), discrepancy (`65`), history (`76`), eligibility (`61`) |
| Consequential saves use confirm-dialog and state the effect; destructive actions use the destructive variant | Pass (note) | `ConfirmDialog` handles the discard guards (`25`) and “Cancel this request?” (`variant="default"`: not destructive). “Decline request” uses `variant="destructive"` (`45`). The known purple destructive ConfirmDialog bug is on main (PR #15); it isn’t worked around. |
| Never a browser `prompt` or `confirm` | Pass | None |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | `11-count-validation` and `75-movement-validation`. A wrong PIN keeps every value and focuses the PIN (`21`). A changed register keeps the other counts (`23`). Escape returns focus to “Start the 3:00 pm count” (`report.json` keyboard `afterEscape`). |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | N/A | P07a draws no settings. The new setting is specified for P11’s view (README §10). |
| Growing lists use a searchable picker | Pass | Witness picker (`16`); ask-to-witness list (`30`) |
| Uploads use FileDropzone + StagedFileCard | N/A | No uploads |
| Dates and times use the PKG-01 DateTimeField, with the time zone visible | Pass | Movement “When” (`70`); override start and end (`44`), with the Pacific/Auckland legend |
| Buttons aren’t restyled | Pass | Only `Button` variants and the PageHeader button family. `className` carries only `frontline-tap` and spacing. |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only: no raw palette, hex or `dark:` pairs | Pass | ESLint (the app config) on `src/**`: see Verification |
| Every status uses StatusBadge; safety uses the fixed pairs | Pass | `CountBadge` and state badges are `StatusBadge`. Discrepancy and override notices use the `status-critical` and `status-warning` pairs. |
| Colour is never the only signal | Pass | Every state has a label plus an icon. Picker reasons are written out in words. |
| lucide icons only; icon-only buttons have an `aria-label` | Pass | Header glass buttons, the bell, messages |
| Visible focus ring; 44 px tap targets on frontline actions | Pass | `frontline-tap` on row actions, section actions and tiles |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | The discrepancy reference appears only as a muted suffix (“Aroha · CD-2026-014”), never as a title. Package codes appear only in design notes, toasts naming another package, and the contract page. |
| “Not configured”, “Not available” or “Unknown” instead of fake zeros | Pass | The cadence shows “Not configured” (`63`). The coordinator on call shows `NotConfigured` (`41`). Meters show “Unavailable” (`82`). |
| Every blocked action says why and who can unblock it | Pass | Why can’t I count (`41`, `67`); the “Nobody on shift can witness” notice names Jordan Tipene from 3:00 pm; “Can’t witness yet” names the assessor (`61`); every picker entry gives its reason (`16`). |
| No invented clinical values or regulator names | Pass | Balances and amounts are synthetic stock data. The cadence is Stephan’s. The 30-minute due window reuses the dose window and is put to Stephan as Q2. |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions or meters; unbuilt things are hidden | Pass (note) | Actions owned by other packages say so in a toast (“… — outside this preview”): recording a dose (P01), the person record (P02), the handover (P08a), errors (P08b), the controlled register (P07b). That is the mockup’s boundary, not product behaviour. The other Meds today views are link-only cards naming their package. |
| Synthetic data clearly labelled | Pass | The hatched viewer bar reads “mockup viewer — not product UI · Synthetic data”. |
| Views approved earlier are reused unchanged unless an approved change says otherwise | Pass (note) | P01’s header, `Modal`, override request and approval, and witness picker. The PIN wording follows PIN-1, not P01 (declared deviation 1, Q1). |

## 3. Brief §5 findings, checked first

| Finding | Result |
|---|---|
| A number lives once | Pass: no KPI cards |
| One-line subline; no truncated meter captions | Pass: `report.json` |
| No link tiles dressed as meters | Pass |
| No codes in product copy | Pass (see G) |
| Real button variants only; `link` for ↗ jumps | Pass: there are no ↗ jumps in P07a |
| Honest states | Pass |
| Controlled-drug concealment everywhere, shown with a persona | Pass: Tomasi Vea (`90`–`93`). No tab, no rows, no counts, no search results (`91`), page-level no access (`92`), record not found (`93`). |
| House scope: no access (page) is distinct from not found (record) | Pass: `92` vs `93`. The manager sees only their two houses (`95`). |
| Loosening a safety setting is destructive | N/A here; specified for P11 (README §10) |
| Destructive ConfirmDialog renders purple on main | Noted; not worked around |
| Reference frames match origin/main | Pass: `origin/main` was fetched first (`9b006825d`). P01’s header is used as approved. Today’s register was looked at live (AUDIT §5) and isn’t copied. |
| Every claim about today’s code has `file:line`, marked verified or reported | Pass: AUDIT.md |
| No invented clinical values or regulator names | Pass |

## 4. Verification (30 September 2026, final build)

**Harness** (`tools/verify.mjs`): **180 captures**. That is all 72 states at 1440 × 900, and the 54 core states again at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2). Across all 180:
- horizontal overflow 0;
- console errors 0;
- every scripted step completed;
- the header subline is one line;
- no meter caption is truncated;
- no table cell is truncated. The harness now also checks `[role=row] .truncate`, the check P11 v2 needed.

**Keyboard** (real key events over CDP, `report.json` → `keyboard`):
- Enter on “Start the 3:00 pm count” opens the count.
- Tab cycles inside the dialog: Close → the three counts → Cancel → Continue → the step rail → Close.
- Escape closes the unedited dialog and returns focus to “Start the 3:00 pm count”.
- The keyboard menu key on a focused row opens the same menu as ⋯ and right-click: Count this medicine · Ask someone to witness · View counts and movements · Record a movement · Open Grace’s medication record.

**In the browser pane** at 1440 (port 4385), no console errors.

**`tsc`** (`tsconfig.json` in this folder): 0 errors in `docs/emar-design/P07a/**`. It reports 3 errors in shared files, `components/breadcrumbs.tsx:36` and `components/ui/laravel-pagination.tsx:48,63,77`. They are type mismatches against the mockup-only Inertia shim, which is copied unchanged from P01 v1; the shared files are unchanged.

**ESLint** (the app’s config) on `src/**`: 0 errors and 0 warnings. One “use Card” warning was fixed by using the real `Card`.

**Live references** (brief step 3), checked at 1440 as Demo Admin on oblivionfindings.test:
- today’s controlled register and balance-check dialog (AUDIT §5);
- the Meds today frame is P01 v1’s approved header;
- the lists follow the Fleet list contract (`EntityTable`, `ListCaption`, kebab plus right-click).
