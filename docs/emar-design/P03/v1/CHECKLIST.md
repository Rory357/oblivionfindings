# P03 v1 — Mockup design-rules checklist (self-check with evidence)

The checklist is `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026). It is checked together with the findings earlier packages had to fix (`Mockup-session-brief.md` §5).

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records:
- horizontal overflow;
- console errors;
- whether the scripted steps completed;
- the header subline’s line count;
- truncated meter captions and truncated table cells.

Numbers in brackets, such as (`20`), are screenshot states. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P03’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| A Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `serve.mjs` and `tsconfig.json` are copied from P08a v1 (itself P01’s lineage). `src/inertia-shim.tsx` is P01 v1’s, unchanged. `src/styles.css` has explicit `@source` lines. |
| Dependencies installed in this worktree | Pass | `npm ci` in `intelligent-antonelli-87bad1`. No junction. |
| Real primitives, never hand-rolled | Pass | These are all the real components:<br>• `PageHeader` with the meter blocks, donut, `PageHeaderRail`, filter selects, search, search trigger and status chip (`pages/register.tsx`, `pages/record.tsx`);<br>• `TierTwoTabs` (P02’s `SubTabs`);<br>• `WizardShell`, `WizardStepPane`, `ReviewCard`, `ReviewRow`, `WizardSuccessPane` (`dialogs.tsx`);<br>• `Dialog` in the Fleet Settings `Modal` layout (`modal.tsx`), and `ConfirmDialog`;<br>• `EntityTable`, `ListCaption`, `PersonDisc`, `EntityChip`, `EntityContextMenu`, `compactMenu`;<br>• `StatusBadge`, `EmptyState`, `ErrorState`, `SkeletonTable`;<br>• `ToggleGroup`, `Checkbox`, `Select`, `Input`, `Textarea`;<br>• `FileDropzone` + `StagedFileCard`;<br>• `DateTimeField` (PKG-01);<br>• `Breadcrumbs`, `Card`, `Table`. |
| Synthetic data only; no application API; a fixed clock in Pacific/Auckland | Pass | `data.ts` holds the data. `clock.ts` fixes the clock at Monday 28 September 2026, 9:12 am NZDT. The shim never sends anything, and `serve.mjs` answers only GET and HEAD. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P03/v1/**`. |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | The register uses P02’s hub header (`01`); the record uses P02’s record header (`20`). Together they replace today’s PageHero (AUDIT 1.1). |
| A plain title, one StatusBadge chip, a one-line fact subline | Pass (note) | The hub subline is one line. The record subline is two lines: P02’s approved identity subline, unchanged. |
| No greetings and no LIVE or refreshed eyebrows | Pass | Today’s “Self-administration oversight · live” eyebrow is gone. The as-at time is a filter chip. |
| One meter row of 4–6 blocks, each linked, with real data, the graph form, and “n/a” or “Unavailable” | Pass (note) | Both are P02’s meter rows, with P02’s reference numbers (deviation 1 and 5). Loading shows “—” (`90`) and couldn’t-load shows “Unavailable” (`92`). |
| Search and primary filters inside the header; nothing between the header and the content | Pass | House, Show, Support and the as-at chip sit in the header’s filter row. The Support plan notices are status notices about this person, and each carries its action. |
| `PageHeaderRail`: one line, 8 views or fewer, the Find chip | Pass | The hub has 4 views; the record has 6 sections plus Find. |
| Breadcrumbs rooted at Home | Pass | Home › Medication › MAR & medicines › …; for frontline staff, Home › Meds today › person. |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | The shell is P08a’s. The views add no outer padding. |
| A full-width body with no `max-w` cap | Pass | All views |
| A number lives once, in the meter row | Pass | There are no KPI cards. List captions give only “N shown” and concealed counts. |
| Desktop only; checked at 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json` shows overflow 0 on every capture. |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts: identity first, kebab last | Pass | Used for the register, recent changes, by medicine, earlier assessments and changes. |
| ⋯ and the same menu on right-click (plus the menu key); clicking a row opens it | Pass (note) | See `05` and `27`, and `report.json` → `keyboard`. The harness uses the menu key, because headless Chrome doesn’t synthesise Shift+F10 (as recorded for P01, P07a and P08a). |
| Server-style pagination | N/A (note) | The lists are bounded: one register row per person at the persona’s houses. The build pages them with `LaravelPagination` if they grow. |
| Empty, loading and error states use the shared components | Pass | `EmptyState` (`91`, `31`, `38`), `SkeletonTable` (`90`, `98`), `ErrorState` (`92`) |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Adding or editing with 2 or more sections uses WizardShell (stepper, “Step x of y”, review, success pane) | Pass | Assess or reassess (`50`–`60`); record the agreement (`65`–`69`) |
| Simple dialogs follow the shell/body split, width tokens and footer (the Fleet Settings `Modal`) | Pass | One medicine’s support (`70`–`74`), a change the person asked for (`80`–`85`), an earlier assessment (`32`), can’t do this (`95`) and not found (`97`) |
| Consequential saves state the effect; destructive actions use the destructive variant | Pass (note) | “When you save” appears on both wizards (`57`, `69`). The review marks “Loosens staff support” (`57`). Setting a new medicine above Administer asks for a destructive `ConfirmDialog` confirmation, “Loosens staff support” (`72`). That confirm renders purple on main until PR #15; this is not worked around. |
| Never a browser `prompt` or `confirm` | Pass | None |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | `51`, `53`, `66`, `74` and `83` keep values and focus the first error. The discard guard is shown in `59`. Escape returns focus to “Reassess” (`report.json` keyboard). |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | N/A | P03 has no on/off settings. |
| Growing lists use a searchable picker | N/A (note) | The pickers are short and bounded: the person’s own medicines, and staff on shift as witness. They use `Select`. |
| Uploads use FileDropzone + StagedFileCard | Pass | The signed agreement form (`65`) |
| Dates and times use the PKG-01 DateTimeField with the time zone visible | Pass | “When” on a change the person asked for (`80`), with the Pacific/Auckland legend |
| Buttons aren’t restyled | Pass | Only `Button` variants and the PageHeader button family are used. `className` carries only `frontline-tap` and spacing. |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only | Pass | ESLint (the app config) on `src/**`: see §4 |
| Every status uses StatusBadge | Pass | `PlanBadge` states: Reassess now (critical), Review date passed (warning), No assessment (warning), Reassess soon (info) and Up to date (success) |
| Colour is never the only signal | Pass | Every state has a label and an icon, and its reason is written out. |
| lucide icons only; icon-only buttons have an `aria-label` | Pass | Yes |
| A visible focus ring; 44 px tap targets on frontline actions | Pass | `frontline-tap` is on the toggle items and the tiles. |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | The five scores are in plain words (“Understanding and memory”, “Hands and grip”…). “Category n” is gone. Package codes appear only in design notes, toasts and the contract page. |
| “Not configured”, “Not available” or “Unknown” instead of fake zeros | Pass | “Not set” is used for support, “Not assessed”, “No doses due yet”, and “Unavailable” when loading fails. |
| Every blocked action says why and who can unblock it | Pass | For support workers: `95`. On the tiles: “More independence needs a reassessment”, “Controlled medicines are Assist or Administer”, and “The assessment allows up to …” (`70`, `71`). |
| No invented clinical values or regulator names | Pass | The scores and checks are today’s fields. PPPR Act terms (welfare guardian, EPOA) are Main’s. |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions; unbuilt things are hidden | Pass (note) | Actions owned by other packages (recording, the medicine detail, other record sections) say so in a toast. |
| Synthetic data clearly labelled | Pass | The viewer bar |
| Views approved earlier are reused unchanged unless an approved change says otherwise | Pass | P02’s hub and record frames, P01’s support words, and P00’s review-date wording are reused. The P01/P02 chip word “Independent” → “Self-managed” is a build note (Q1). |

## 3. Brief §5 findings, checked first

| Finding | Result |
|---|---|
| A number lives once | Pass |
| One-line subline; no truncated meter captions | Pass (the record’s two lines are P02’s approved subline) |
| No link tiles dressed as meters | Pass |
| No codes in product copy | Pass. “(P04)” was removed from the covert-plan subline and the assessment note. |
| Real button variants only | Pass |
| Honest states | Pass |
| Controlled-drug concealment everywhere, shown with a persona | Pass. Hana Kereama (`07`, `25`, `41`, `60`, `97`) sees:<br>• cross-person lists with controlled rows left out and counted in the caption (P02 rule);<br>• redacted rows in the person’s own record;<br>• wizard rows that keep their support, and a count;<br>• a direct link to a controlled medicine showing “We can’t show this record”. |
| House scope: no access vs not found | Pass. For support workers, “You can’t assess support” says who can (`95`). Ben shows “We can’t show this record” to Kōwhai House (`96`). |
| Loosening a safety setting is destructive | Pass: “Loosens staff support” (`57`, `72`) |
| Destructive ConfirmDialog renders purple on main | Noted; not worked around |
| Reference frames match origin/main | Pass. `origin/main` is `31d597415`. P02’s approved frames are used. Today’s `/emar/self-admin` was read live (AUDIT §5). |
| Every claim about today’s code has `file:line`, marked verified or reported | Pass: see AUDIT.md |
| No invented clinical values | Pass |

## 4. Verification (30 September 2026, final build)

**Harness** (`tools/verify.mjs`): **133 captures**. That is all 67 states at 1440 × 900, and the 33 core states again at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2). Across all 133:
- horizontal overflow 0;
- console errors 0;
- every scripted step completed;
- no meter caption is truncated;
- no table cell is truncated.

The hub subline is one line. The record’s subline is two lines, because it is P02’s approved identity subline.

The first runs found clipped cells: medicine names with their strength, the concealed row’s subline, and change times with names attached. The fixes:
- the medicine name is the identity, with strength and time on the subline;
- the concealed subline wraps;
- the day and time are split across the identity’s two lines;
- the person and the author each get their own column.

Screenshot review also led to four more changes:
- one medicine’s support can’t become more independent outside a reassessment (Q5, P02);
- the saved pane counts the changed medicines;
- notices show only where they’re actionable;
- a package code was removed from the product copy.

**Keyboard** (real key events over CDP, `report.json` → `keyboard`):
- Enter on Grace’s “Reassess” in the register opens the wizard.
- Tab moves through Close → the wishes tiles → the “who took part” checkboxes → the guardian’s name, and stays inside the dialog.
- Escape closes the unedited wizard, and focus returns to “Reassess”.
- The menu key on a focused register row opens the same menu as ⋯ and right-click: Open the support plan · View the assessment · Reassess · Record a change the person asked for.

**`tsc`** (`tsconfig.json` in this folder): 0 errors in `docs/emar-design/P03/**`. It reports 2 errors in shared files, `components/breadcrumbs.tsx:36` and `components/ui/file-dropzone.tsx:223`. Both are type mismatches against the mockup-only Inertia shim (its `router.post` takes no arguments), and the shared files are unchanged.

**ESLint** (the app’s config, `--no-ignore`) on `src/**`: 15 files, 0 errors, 0 warnings.

**Live reference:** `/emar/self-admin`, read only (AUDIT §5).

**Load rules:** every heavy command waited for more than 4 GB free RAM and at most one other Pest run, checked with a `.ps1` script. A guard would stop only this session’s node and Chrome harness if free RAM fell below 3 GB. It stopped one run at 3.07 GB free, and that run was queued again.
