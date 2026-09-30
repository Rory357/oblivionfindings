# P06 v1 — Mockup design-rules checklist (self-check with evidence)

The checklist is `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026). It is checked together with the findings earlier packages had to fix (`Mockup-session-brief.md` §5), and Main’s P03 and P04 fixes (explanations in neutral text; colour on the badge).

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records:
- horizontal overflow;
- console errors;
- whether the scripted steps completed;
- the header subline’s line count;
- truncated meter captions and truncated table cells.

Numbers in brackets, such as (`30`), are screenshot states. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P06’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| A Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `serve.mjs` and `tsconfig.json` are copied from P04 v1 (P01’s lineage). `src/inertia-shim.tsx` is P01 v1’s, unchanged. `src/styles.css` has explicit `@source` lines. |
| Dependencies installed in this worktree | Pass | `npm ci` in `intelligent-antonelli-87bad1`. No junction. |
| Real primitives, never hand-rolled | Pass | These are all the real components:<br>• `PageHeader` with the meter blocks (and P01’s donut), `PageHeaderRail`, filter selects and buttons, search, glass and primary buttons, status chip (`pages/hub.tsx`, `pages/meds-today.tsx`);<br>• `WizardShell` — sequential for Receive and Count; the viewer for the stock item — with `WizardStepPane`, `ReviewCard`, `ReviewRow`, `WizardSuccessPane`;<br>• `Dialog` in the Fleet Settings `Modal` layout (`modal.tsx`), and `ConfirmDialog`;<br>• `EntityTable`, `ListCaption`, `PersonDisc`, `EntityContextMenu`, `compactMenu`;<br>• `StatusBadge`, `EmptyState`, `ErrorState`, `SkeletonTable`;<br>• `Checkbox`, `Select`, `Input`, `Textarea`;<br>• `FileDropzone` + `StagedFileCard`;<br>• `DateTimeField` (PKG-01);<br>• `Breadcrumbs`, `Card`, `Table`. |
| Synthetic data only; no application API; a fixed clock in Pacific/Auckland | Pass | `data.ts` holds the data. `clock.ts` fixes the clock at Monday 28 September 2026, 9:12 am NZDT. The shim never sends anything, and `serve.mjs` answers only GET and HEAD. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P06/v1/**`. |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | Stock & controlled drugs (`01`) replaces today’s PageHero “Medication stock for {site}” (AUDIT 1.1). Meds today is P01’s approved page top (`18`). |
| A plain title, one StatusBadge chip, a one-line fact subline | Pass | `01`, `06`, `18` |
| No greetings and no LIVE or refreshed eyebrows | Pass | The as-at time is a filter chip; out of date is a notice with “Try again” (`93`). |
| One meter row of 4–6 blocks, each linked, with real data, and “—” or “Unavailable” | Pass | Six meters, each opening its view. Loading shows “—” (`90`); couldn’t load shows the ErrorState (`92`). |
| Search and primary filters inside the header; nothing between the header and the content | Pass (note) | House, Show and the as-at chip sit in the header’s filter row. The only thing between is the out-of-date notice, a status notice with its action (`93`). |
| `PageHeaderRail`: one line, 8 views or fewer, the Find chip | Pass | Stock · Deliveries & orders · Counts · Expiring · Removals (+ Controlled with controlled view), plus Find (Main, Q1). |
| Breadcrumbs rooted at Home | Pass | Home › Medication › Stock & controlled drugs; frontline Home › Meds today |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | The shell is P04’s. |
| A full-width body with no `max-w` cap | Pass | All views |
| A number lives once, in the meter row | Pass | No KPI cards. Captions give “N shown” and concealed counts. |
| Desktop only; checked at 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json`: overflow 0 on every capture. |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts: identity first, kebab last | Pass | Stock, recent movements, each orders section, counts, expiring bands, removals, and every Stock alerts section. |
| ⋯ and the same menu on right-click (plus the menu key); clicking a row opens it | Pass (note) | `05`, and `report.json` → `keyboard`. The harness uses the menu key (headless Chrome doesn’t synthesise Shift+F10). |
| Server-style pagination | N/A (note) | Today’s lists are capped at 40 / 400 rows and unpaginated (AUDIT 8). The lists here are bounded by the persona’s houses; the build pages them (build note 12). |
| Empty, loading and error states use the shared components | Pass | `EmptyState` (`91`, and each empty section), `SkeletonTable` (`90`), `ErrorState` (`92`) |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Adding or editing with 2 or more sections uses WizardShell (stepper, “Step x of y”, review, success pane) | Pass | Receive (`30`–`40`), Count (`55`–`59`). The stock item is the WizardShell viewer (`21`–`25`). |
| Simple dialogs follow the shell/body split, width tokens and footer (the Fleet Settings `Modal`) | Pass | New order (`45`), the order (`46`–`48`), what the pharmacy dispensed (`49`, `50`), cancel / close short (`51`, `52`), sign off (`60`–`62`), adjust or remove (`65`–`67`), going out / coming back (`70`–`72`), can’t do this (`41`, `96`), not found (`97`, `98`), nothing to receive (`99`) |
| Consequential saves state the effect; destructive actions use the destructive variant | Pass (note) | “When you save” on Receive (`34`). Cancel, close short and removals use the destructive button and a destructive `ConfirmDialog` (`51`, `66`). That confirm renders purple on main until PR #15; not worked around. |
| Never a browser `prompt` or `confirm` | Pass | None |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | `32`, `37`, `38`, `50`, `56`, `67` keep values and focus the first error. Discard guards protect the wizards (and an untouched receipt closes without one). Escape returns focus to “Receive” (`report.json` keyboard). |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | N/A (note) | P06 shows its settings read-only as “Default — not yet reviewed”; they are switched in P11. |
| Growing lists use a searchable picker | N/A (note) | The pickers are short and bounded: people at the persona’s houses, their chart’s medicines, the persona’s receivable orders, staff on shift. They use `Select`. |
| Uploads use FileDropzone + StagedFileCard | Pass | The pack photo (`33`, `34`) |
| Dates and times use the PKG-01 DateTimeField with the time zone visible | Pass (note) | Dispensed (`49`), went out / came back (`70`–`72`). Expiry is month/year as printed on NZ packs (deviation 5). |
| Buttons aren’t restyled | Pass | Only `Button` variants and the PageHeader button family. `className` carries only layout and `frontline-tap`. |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only | Pass | ESLint (the app config) on `src/**`: see §4 |
| Every status uses StatusBadge | Pass | Stock states (10, `StockBadge`); order states (7, `OrderStateBadge`); pack states (Use next, Open, Expired, Used up); count results; expiry bands |
| Colour is never the only signal | Pass | Every state has a label and an icon; explanations are plain text under the badge (Main’s P03/P04 fix). |
| lucide icons only; icon-only buttons have an `aria-label` | Pass | Yes |
| A visible focus ring; 44 px tap targets on frontline actions | Pass | `frontline-tap` on the tiles and on Stock alerts’ Receive and “Record it coming back”. |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | “Running low”, “Use first”, “Expired pack”, “Count to sign off”, “Delivery due”. Package codes appear only in design notes, viewer toasts, link-only cards and the contract page. |
| “Not configured”, “Not available” or “Unknown” instead of fake zeros | Pass | “None yet” before anything arrives; “Batch not printed on the pack”; “Default — not yet reviewed” for settings; “Unavailable” when loading fails. |
| Every blocked action says why and who can unblock it | Pass | “The house lead receives it, with a witness” (`18`, `41`); “The house lead orders it” (`18`); support workers get “You can’t order from the pharmacy” (`96`). |
| No invented clinical values or regulator names | Pass | Quantities, batches and expiries are synthetic. |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions; unbuilt things are hidden | Pass (note) | Controlled counts and the register say so in a viewer toast (P07a / P07b). Meds today’s other views are link-only cards. |
| Synthetic data clearly labelled | Pass | The viewer bar; pack photos are labelled “Synthetic pack photo”. |
| Views approved earlier are reused unchanged unless an approved change says otherwise | Pass | P01’s Meds today page top (via P07a); P02’s hub pattern and concealment; P03’s self-managed rule; P07a’s movement pattern. |

## 3. Brief §5 findings, checked first

| Finding | Result |
|---|---|
| A number lives once | Pass |
| One-line subline; no truncated meter captions | Pass |
| No link tiles dressed as meters | Pass |
| No codes in product copy | Pass |
| Real button variants only | Pass |
| Honest states | Pass |
| Controlled-drug concealment everywhere, shown with a persona | Pass. Hana Kereama (`07`, `13`, `98`) sees controlled medicines left out and counted in the caption and meter, and “We can’t show this record” on a direct link. Controlled pack photos are shown only with controlled view. |
| House scope: no access vs not found | Pass. Support workers get “You can’t order from the pharmacy” (`96`). Ben’s amlodipine shows “We can’t show this record” to Kōwhai House (`97`). |
| Loosening or destroying is destructive | Pass: cancel, close short, removals (`51`, `66`) |
| Destructive ConfirmDialog renders purple on main | Noted; not worked around |
| Reference frames match origin/main | Pass. `origin/main` is `ada567669`. |
| Every claim about today’s code has `file:line`, marked verified or reported | Pass: see AUDIT.md |
| No invented clinical values | Pass |

## 4. Verification (30 September 2026, final build)

**Harness** (`tools/verify.mjs`): **144 captures**. That is all 70 states at 1440 × 900, and the 37 core states again at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2). Across all 144:
- horizontal overflow is 0;
- there are 0 console errors;
- every scripted step completed;
- the header subline is one line;
- no meter caption or table cell is truncated.

Earlier runs found, and this version fixes:
- two meter captions truncated at 1280 and 200 % (“Below 7 days or reorder level”, “1 expired · take out of use”) — now “First: Losartan” and “1 expired · remove”;
- the receive form pre-filled the counted quantity from the pharmacy’s label under “Count them — don’t copy the label” — now only batch and expiry are pre-filled, to check against the label;
- the photo step asked whether the pack “looks different from last time” when there was no earlier photo — now shown only when there is one; the footer reads “Continue without a photo”;
- recording something coming back showed a disabled “Going out” tile with a red line — the direction picker is now hidden, and the title says “Coming back”;
- removing an expired pack said “on hand goes from 60 to 60” — now “the expired pack comes off the list; on hand stays 60”;
- an untouched receipt asked to discard on Escape — it now closes (the wizard compares with its starting state);
- small copy: singular units (“−1 tablet”), “None yet” before anything arrives, an accurate To receive caption, and the scheduled-counts line.

One run was stopped by this session’s memory guard (free RAM under 3 GB from other sessions’ full-repo `tsc`); the final run was clean.

**Keyboard** (`report.json` → `keyboard`): real key events. Enter on “Receive” (Priya Shah, the cefalexin delivery in Stock alerts) opens the receive wizard; Tab cycles inside it; Escape closes the untouched receipt and focus returns to “Receive”. The menu key on a focused stock row opens: Open the stock item · Receive a delivery · Count it · Going out or coming back · Order from the pharmacy · Adjust or remove.

**`tsc`** (`tsconfig.json`): no errors in `src/`. The 2 errors in shared files (`breadcrumbs.tsx:36`, `file-dropzone.tsx:223`) come from P01’s Inertia shim types.

**ESLint** (the app config, `--no-ignore`, `src/**/*.{ts,tsx}`): 16 files, 0 errors, 0 warnings. A separate check found no unused imports.
