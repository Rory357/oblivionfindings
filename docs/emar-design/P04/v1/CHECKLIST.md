# P04 v1 — Mockup design-rules checklist (self-check with evidence)

The checklist is `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026). It is checked together with the findings earlier packages had to fix (`Mockup-session-brief.md` §5), and Main’s P03 fixes.

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records:
- horizontal overflow;
- console errors;
- whether the scripted steps completed;
- the header subline’s line count;
- truncated meter captions and truncated table cells.

Numbers in brackets, such as (`20`), are screenshot states. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P04’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| A Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `serve.mjs` and `tsconfig.json` are copied from P03 v1 (P01’s lineage). `src/inertia-shim.tsx` is P01 v1’s, unchanged. `src/styles.css` has explicit `@source` lines. |
| Dependencies installed in this worktree | Pass | `npm ci` in `intelligent-antonelli-87bad1`. No junction. |
| Real primitives, never hand-rolled | Pass | These are all the real components:<br>• `PageHeader` with the meter blocks, `PageHeaderRail`, filter selects and buttons, search, primary button and status chip (`pages/hub.tsx`);<br>• `WizardShell` — sequential for Enter an order or change, Covert and Reconcile; the non-sequential viewer for the order — with `WizardStepPane`, `ReviewCard`, `ReviewRow`, `WizardSuccessPane`;<br>• `Dialog` in the Fleet Settings `Modal` layout (`modal.tsx`), and `ConfirmDialog`;<br>• `EntityTable`, `ListCaption`, `PersonDisc`, `EntityChip`, `EntityContextMenu`, `compactMenu`;<br>• `StatusBadge`, `EmptyState`, `ErrorState`, `SkeletonTable`;<br>• `Switch`, `Checkbox`, `Select`, `Input`, `Textarea`;<br>• `FileDropzone` + `StagedFileCard`;<br>• `DateTimeField` (PKG-01);<br>• `Breadcrumbs`, `Card`, `Table`. |
| Synthetic data only; no application API; a fixed clock in Pacific/Auckland | Pass | `data.ts` holds the data. `clock.ts` fixes the clock at Monday 28 September 2026, 9:12 am NZDT. The shim never sends anything, and `serve.mjs` answers only GET and HEAD. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P04/v1/**`. |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | Orders & reviews (`01`) replaces today’s PageHero “Prescriptions & orders · live” (AUDIT 4.1). |
| A plain title, one StatusBadge chip, a one-line fact subline | Pass | “Orders & reviews”, the house-count chip, and one subline (`01`, `06`). |
| No greetings and no LIVE or refreshed eyebrows | Pass | The as-at time is a filter chip; out of date is a notice with “Try again” (`93`). |
| One meter row of 4–6 blocks, each linked, with real data, and “—” or “Unavailable” | Pass | Six meters: To check · Written confirmation · Ending in 14 days · Covert · Reconciliation · Current orders; each opens its view. Loading shows “—” (`90`); couldn’t load shows the ErrorState (`92`). |
| Search and primary filters inside the header; nothing between the header and the content | Pass (note) | House, Show and the as-at chip sit in the header’s filter row. The only thing between is the out-of-date notice, a status notice with its action (`93`). |
| `PageHeaderRail`: one line, 8 views or fewer, the Find chip | Pass | Orders · To check (count) · Covert · Reconciliation · Medication reviews, plus Find (Main, Q7). |
| Breadcrumbs rooted at Home | Pass | Home › Medication › Orders & reviews |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | The shell is P03’s. The views add no outer padding. |
| A full-width body with no `max-w` cap | Pass | All views |
| A number lives once, in the meter row | Pass | There are no KPI cards. List captions give only “N shown” and concealed counts. |
| Desktop only; checked at 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json` shows overflow 0 on every capture. |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts: identity first, kebab last | Pass | Orders, recent changes, each To check section, earlier covert authorisations and reconciliations. |
| ⋯ and the same menu on right-click (plus the menu key); clicking a row opens it | Pass (note) | `05`, and `report.json` → `keyboard`. The harness uses the menu key, because headless Chrome doesn’t synthesise Shift+F10 (as recorded for P01, P03, P07a and P08a). |
| Server-style pagination | N/A (note) | Today all orders load in one query (AUDIT 4.1). The lists here are bounded by the persona’s houses; the build pages them with `LaravelPagination`. |
| Empty, loading and error states use the shared components | Pass | `EmptyState` (`91`, and each empty view), `SkeletonTable` (`90`), `ErrorState` (`92`) |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Adding or editing with 2 or more sections uses WizardShell (stepper, “Step x of y”, review, success pane) | Pass | Enter an order or change (`30`–`39`), covert review (`70`–`77`), reconcile medicines (`80`–`87`). The order itself is the WizardShell viewer (`20`–`26`). |
| Simple dialogs follow the shell/body split, width tokens and footer (the Fleet Settings `Modal`) | Pass | Check (`40`–`48`), written confirmation (`50`–`52`), the prescriber’s allergy confirmation (`55`, `56`), P08a’s phone countersign (`57`), stop (`60`), stop covert giving (`78`), the signed-off reconciliation (`88`), can’t do this (`96`), not found (`97`, `98`), nothing to check (`99`) |
| Consequential saves state the effect; destructive actions use the destructive variant | Pass (note) | “When you save” on the entry and covert reviews (`33`, `38`, `76`); “What happens at sign-off” (`83`). Stop an order and stop covert giving use the destructive button and a destructive `ConfirmDialog` (`61`, `79`). That confirm renders purple on main until PR #15; this is not worked around. |
| Never a browser `prompt` or `confirm` | Pass | None |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | `31`, `36`, `39`, `42`, `51`, `56`, `81` keep values and focus the first error. Discard guards protect the wizards. Escape returns focus to “Check version 1” (`report.json` keyboard). |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | Pass | “Given when needed” on an order (`32`) |
| Growing lists use a searchable picker | N/A (note) | The pickers are short and bounded: people at the persona’s houses, prescribers, and staff on shift as the read-back witness. They use `Select`. |
| Uploads use FileDropzone + StagedFileCard | Pass | The prescription (`30`), the written confirmation (`52`), the GP’s covert authorisation (`75`, `76`), the reconciliation’s list (`86`) |
| Dates and times use the PKG-01 DateTimeField with the time zone visible | Pass | Received (`30`, `50`), Confirmed (`55`), Assessed (`70`), with the Pacific/Auckland legend |
| Buttons aren’t restyled | Pass | Only `Button` variants and the PageHeader button family are used. `className` carries only layout. (The first run’s red ghost “Stop covert giving…” became a plain outline button.) |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only | Pass | ESLint (the app config) on `src/**`: see §4 |
| Every status uses StatusBadge | Pass | Order states (8, `OrderBadge`); versions (Current, Replaced, Checked alone, Sent back, Waiting to be checked); covert (Authorised, Review due soon, Review overdue — blocked); reconciliation (Open, Signed off; on our chart, not on the GP list, allergy) |
| Colour is never the only signal | Pass | Every state has a label and an icon, and its reason is written out as plain text under the badge (Main’s P03 fix 1). |
| lucide icons only; icon-only buttons have an `aria-label` | Pass | Yes |
| A visible focus ring; 44 px tap targets on frontline actions | Pass | `frontline-tap` is on the tiles. |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | “Waiting to be checked”, “Sent back”, “Written confirmation due”, “Second check due”, “Prescriber must confirm”. Package codes appear only in design notes, viewer toasts and the contract page. |
| “Not configured”, “Not available” or “Unknown” instead of fake zeros | Pass | Allergies “Not recorded — ask the prescriber” or “Couldn’t be loaded — check the health profile” (never “none”); “Not on the chart yet”; “Unavailable” when loading fails. |
| Every blocked action says why and who can unblock it | Pass | “You entered this version — someone else checks it”, naming who can (`44`); the allergy block, with “Record the prescriber’s confirmation” for those who can (`48`); support workers get “You can’t check orders” (`96`). |
| No invented clinical values or regulator names | Pass | Doses and instructions are synthetic order data from the prescriber. PPPR Act terms (welfare guardian, EPOA) are Main’s. |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions; unbuilt things are hidden | Pass (note) | Actions owned by other packages (the person’s medication record) say so in a viewer toast. Medication reviews is link-only (P05, design note). |
| Synthetic data clearly labelled | Pass | The viewer bar |
| Views approved earlier are reused unchanged unless an approved change says otherwise | Pass | P02’s hub pattern and concealment rule; P03’s “Administer until support is set”; P08a’s “Countersign a phone instruction”, copied view for view (`57`). Main’s approved change to that dialog is build note 15, not previewed. |

## 3. Brief §5 findings, checked first

| Finding | Result |
|---|---|
| A number lives once | Pass |
| One-line subline; no truncated meter captions | Pass (the first run found three long captions; they were shortened) |
| No link tiles dressed as meters | Pass |
| No codes in product copy | Pass. The first draft had “(P03)”, “(P06)” and “(P01)” in captions; they moved to design notes. |
| Real button variants only | Pass |
| Honest states | Pass |
| Controlled-drug concealment everywhere, shown with a persona | Pass. Hana Kereama (`07`, `13`, `87b`, `98`) sees:<br>• cross-person lists with controlled orders left out and counted in the caption (P02 rule);<br>• recent changes with controlled changes counted;<br>• a direct link to a controlled order showing “We can’t show this record”;<br>• in a reconciliation, controlled medicines counted, and sign-off left to someone with access. |
| House scope: no access vs not found | Pass. Support workers get “You can’t check orders” (`96`). Ben’s order shows “We can’t show this record” to Kōwhai House (`97`). |
| Loosening a safety setting is destructive | Pass: stop an order, stop covert giving (`61`, `79`) |
| Destructive ConfirmDialog renders purple on main | Noted; not worked around |
| Reference frames match origin/main | Pass. `origin/main` is `1b4b6e23e`, including Main’s allergy fix (`bbc7705ad`); the preview uses its wording. |
| Every claim about today’s code has `file:line`, marked verified or reported | Pass: see AUDIT.md |
| No invented clinical values | Pass |

## 4. Verification (30 September 2026, final build)

**Harness** (`tools/verify.mjs`): **187 captures**. That is all 87 states at 1440 × 900, and the 50 core states again at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2). Across all 187:
- horizontal overflow is 0;
- there are 0 console errors;
- every scripted step completed;
- the header subline is one line;
- no meter caption or table cell is truncated.

The first run found three long meter captions (“Open · due before 8:00 pm today”, “Next review 1 December 2026”, “Review overdue · 25 September 2026”). They now read “Due by 8:00 pm today”, “Next review 1 Dec 2026” and “Overdue · 25 Sep 2026”.

The self-review of that run also changed:
- the allergy line in To check is plain text, with the colour on the section and the badge (Main’s P03 fix 1);
- entering a medicine the person already has warns and offers “Enter a change instead” (`36b`), without revealing a hidden controlled order;
- checking alone has no “send it back” to yourself, and the notice names who does the second check (`45`);
- the blocked check offers “Record the prescriber’s confirmation” to those who can (`48`);
- the allergy dialog picks the prescriber from the same list as Enter an order, and its guidance is a warning, not an error (`55`);
- “Stop covert giving…” is a plain outline button, not a recoloured ghost button (`70`–`77`);
- reviewer commentary (“today’s countersign…”, dispensing moving to P06, medication reviews in P05) moved into design notes.

**Keyboard** (`report.json` → `keyboard`): real key events. Enter on “Check version 1” (Hana Kereama, Mele’s omeprazole) opens the check dialog; Tab cycles inside it (the decision tiles → Cancel → Save the check → Close); Escape closes it and focus returns to “Check version 1”. The menu key on a focused order row opens the row menu: Open the order · Check it alone — nobody else can · Enter a change · Stop this order · Open Mele’s medication record.

**`tsc`** (`tsconfig.json`): no errors in `src/`. The 2 errors in shared files (`breadcrumbs.tsx:36`, `file-dropzone.tsx:223`) come from P01’s Inertia shim types, as in the earlier packages.

**ESLint** (the app config, `--no-ignore`, `src/**/*.{ts,tsx}`): 15 files, 0 errors, 0 warnings.
