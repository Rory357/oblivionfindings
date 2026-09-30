# P07b v1 — Mockup design-rules checklist (self-check with evidence)

The checklist is `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026). I checked it together with:
- the findings earlier packages had to fix (`Mockup-session-brief.md` §5);
- Main’s P03 and P04 fixes: explanations in neutral text, colour on the badge only.

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records:
- horizontal overflow;
- console errors;
- whether the scripted steps completed;
- the header subline’s line count;
- truncated meter captions and truncated table cells.

Numbers in brackets, such as (`30`), are screenshot states. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P07b’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| A Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `serve.mjs` and `tsconfig.json` are copied from P06 v1 (P01’s lineage), with port 4390. `src/inertia-shim.tsx` is P01 v1’s, unchanged. `src/styles.css` has explicit `@source` lines. |
| Dependencies installed in this worktree | Pass | `npm ci` in `intelligent-antonelli-87bad1`. No junction. |
| Real primitives, never hand-rolled | Pass | These are all the real components:<br>• `PageHeader` with meter blocks, filter selects and buttons, search, status chip and `PageHeaderRail` (`pages/register.tsx`, `pages/safety.tsx`);<br>• `WizardShell` — sequential for Resolve, Report a loss and Return for destruction; the viewer (`sequential={false}`) for the medicine’s register — with `WizardStepPane`, `ReviewCard`, `ReviewRow` and `WizardSuccessPane`;<br>• `Dialog` in the Fleet Settings `Modal` layout (`modal.tsx`), and `ConfirmDialog`;<br>• `EntityTable`, `ListCaption`, `PersonDisc`, `EntityContextMenu` and `compactMenu`;<br>• `StatusBadge`, `EmptyState`, `ErrorState` and `SkeletonTable`;<br>• `Checkbox`, `Select`, `Input`, `Textarea` and `Label`;<br>• `FileDropzone` + `StagedFileCard`;<br>• `DateTimeField` (PKG-01);<br>• **PIN-1’s `WitnessPinInput`** (on main);<br>• `Breadcrumbs`, `Card` and `Table`. |
| Synthetic data only; no application API; a fixed clock in Pacific/Auckland | Pass | `data.ts` holds the data. `clock.ts` fixes the clock at Monday 28 September 2026, 9:12 am NZDT. The shim never sends anything, and `serve.mjs` answers only GET and HEAD. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P07b/v1/**`. |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | The controlled register (`01`) replaces today’s PageHero with seven tabs (AUDIT 1.1). Safety & oversight (`15`) is P08a’s frame. |
| A plain title, one StatusBadge chip, a one-line fact subline | Pass | `01`, `04`, `15`. The two-house subline is one line at 1280 (`1280-04`). |
| No greetings and no LIVE or refreshed eyebrows | Pass | The as-at time is a filter chip. Out of date is a notice with “Try again” (`93`). |
| One meter row of 4–6 blocks, each linked, with real data, and “—” or “Unavailable” | Pass | Register: six meters. Witness overrides: four. Each meter opens its view. Loading shows “—” (`90`); couldn’t load shows the ErrorState (`92`). |
| Search and primary filters inside the header; nothing between the header and the content | Pass (note) | House, Show and the as-at chip sit in the header’s filter row. The only thing between is a status notice with its action: out of date (`93`) or the `/emar/destructions` redirect (`14`). |
| `PageHeaderRail`: one line, 8 views or fewer, the Find chip | Pass | Register · Discrepancies · Losses · Destructions, plus Find (Main, Q1). Safety & oversight keeps P08a’s rail (`15`, `18`). |
| Breadcrumbs rooted at Home | Pass | Home › Medication › Stock & controlled drugs › Controlled register. Home › Medication › Safety & oversight. |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | The shell is P06’s. |
| A full-width body with no `max-w` cap | Pass | All views |
| A number lives once, in the meter row | Pass | No KPI cards. Captions give “N shown”. The balance appears once per medicine. |
| Desktop only; checked at 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json`: overflow 0 on every capture. The Witness overrides table fits at 1280 (`1280-15`). |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts: identity first, kebab last | Pass | Covers these lists:<br>• medicines and recent entries (`01`, `09`);<br>• discrepancies — Open · With a manager · Closed (`10`);<br>• losses — Open · Closed (`12`);<br>• destructions — Waiting for the pharmacist’s receipt · Destroyed · Voided (`13`);<br>• witness overrides (`15`). |
| ⋯ and the same menu on right-click (plus the menu key); clicking a row opens it | Pass (note) | `03`, and `report.json` → `keyboard`. The harness uses the menu key, because headless Chrome doesn’t synthesise Shift+F10. |
| Server-style pagination | N/A (note) | Today’s lists show the selected day only (AUDIT 1.1). The lists here are bounded by the persona’s houses; the build pages them (build note 12). |
| Empty, loading and error states use the shared components | Pass | `EmptyState` (`91`, and each empty section), `SkeletonTable` (`90`), `ErrorState` (`92`) |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Adding or editing with 2 or more sections uses WizardShell (stepper, “Step x of y”, review, success pane) | Pass | Resolve (`30`–`37`), Report a loss (`40`–`42`), Return for destruction (`50`–`53`). The medicine’s register is the WizardShell viewer (`20`, `21`). |
| Simple dialogs follow the shell/body split, width tokens and footer (the Fleet Settings `Modal`) | Pass | Covers these dialogs:<br>• void an entry (`22`–`25`), set the class (`26`), breakage (`27`), the discrepancy (`28`);<br>• the loss (`43`), add to the investigation (`44`), close the loss (`45`–`45c`);<br>• the pharmacist’s receipt (`55`, `56`), void a destruction (`57`), the destruction (`58`);<br>• the override (`60`–`62`);<br>• can’t do this (`46`, `96`), not found (`97`), a count isn’t voided (`98`). |
| Consequential saves state the effect; destructive actions use the destructive variant | Pass | Void an entry and void a destruction use the destructive button (`22`, `57`). Both, and closing a loss, confirm with a destructive `ConfirmDialog`, the component’s default (`25`). Each resolve outcome states its balance effect on the review step (`34`). |
| Never a browser `prompt` or `confirm` | Pass | None |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | Values are kept and the first error is focused in `23`, `41`, `45b` and `56`. Discard guards protect the wizards. Escape closes the untouched Resolve and returns focus to “Resolve” (`report.json` keyboard). |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | N/A (note) | On-site destruction is an organisation setting in P11. Here it shows as a disabled tile with the reason (`51`). |
| Growing lists use a searchable picker | N/A (note) | The pickers are short and bounded: witnesses at the house, and the persona’s controlled medicines. They use `Select`, with ineligible witnesses disabled and the reason shown (`24`). |
| Uploads use FileDropzone + StagedFileCard | Pass | The optional photo on Return for destruction (`51`) |
| Dates and times use the PKG-01 DateTimeField with the time zone visible | Pass | The pharmacist’s receipt (`55`) |
| Buttons aren’t restyled | Pass | Only `Button` variants and the PageHeader button family. `className` carries layout only. |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only | Pass | ESLint (the app config) on `src/**`: see §4 |
| Every status uses StatusBadge | Pass | These all use it:<br>• entry states (Voided);<br>• class (Class A/B/C; “Class not set — was ‘Schedule N’”);<br>• discrepancy, loss, destruction and override states. |
| Colour is never the only signal | Pass | Every state has a label. Voided entries are struck through *and* badged. Explanations are plain or muted text under the badge (Main’s P03/P04 fix). |
| lucide icons only; icon-only buttons have an `aria-label` | Pass | Yes |
| A visible focus ring; 44 px tap targets on frontline actions | Pass | `frontline-tap` on the outcome, reason and method tiles (`ui.tsx` TilePicker). |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | Examples: “Recount matched”, “Unexplained loss”, “Waiting for the pharmacist’s receipt”, “Sign-off overdue”. Record references (D-14, L-7, DS-21, OV-7) are identifiers, as incident numbers are today. Package codes appear only in design notes, viewer toasts, link-only cards and the contract page. |
| “Not configured”, “Not available” or “Unknown” instead of fake zeros | Pass | “Class not set — was ‘Schedule 3’” instead of a guessed class (Q9). “Not told” for notifications. “Unavailable” when loading fails. |
| Every blocked action says why and who can unblock it | Pass | Examples:<br>• the person who counted can’t resolve, with the reason (`36`);<br>• support workers can’t void (`96`) or close a loss (`46`), each naming who can;<br>• on-site destruction isn’t allowed by the organisation (`51`);<br>• a theft can’t close until the police are recorded (`45b`, `45c`);<br>• the clinical lead without controlled view (`05`, `17`). |
| No invented clinical values or regulator names | Pass (note) | Quantities and people are synthetic. The pharmacist and registration number are marked “(synthetic)”. Medicines Control (Ministry of Health) and the Misuse of Drugs Act 1975 are the real NZ bodies and law named in Main’s answers. |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions; unbuilt things are hidden | Pass (note) | P07a’s count and sign-off, and the incident in Medication errors, say so in a viewer toast. Safety & oversight’s other views say where they are designed (`18`). |
| Synthetic data clearly labelled | Pass | The viewer bar; the pharmacist is “(synthetic)”. |
| Views approved earlier are reused unchanged unless an approved change says otherwise | Pass | P08a’s Safety & oversight frame; P02’s hub pattern and concealment; P07a’s cadence and discrepancy ownership; P06’s controlled receipt (“Received”); PIN-1’s witness PIN. |

## 3. Brief §5 findings, checked first

| Finding | Result |
|---|---|
| A number lives once | Pass |
| One-line subline; no truncated meter captions | Pass. The harness found three problems in the first run, fixed in this version: truncated captions (“Returned, not yet received”, “By the end of the next shift”), a two-line two-house subline, and a truncated medicine name in the overrides table (§4). |
| No link tiles dressed as meters | Pass |
| No codes in product copy | Pass |
| Real button variants only | Pass |
| Honest states | Pass |
| Controlled-drug concealment everywhere, shown with a persona | Pass. The whole page is controlled, so people without controlled view get a no-access card on the register and on Witness overrides: Hana Kereama (`05`, `17`). |
| House scope: no access vs not found | Pass. Support workers get “can’t do this” with who can (`96`). Ben’s oxycodone at Rimu House shows “We can’t show this record” to Kōwhai House (`97`). |
| Loosening or destroying is destructive | Pass: void an entry, void a destruction, close a loss (`25`, `57`) |
| Destructive ConfirmDialog is red | Pass. PR #15 is on main at this version’s base. |
| Reference frames match origin/main | Pass. `origin/main` is `21bfb4ce4`. |
| Every claim about today’s code has `file:line`, marked verified or inferred | Pass: see AUDIT.md (re-based to `21bfb4ce4`) |
| No invented clinical values | Pass |

## 4. Verification (30 September 2026, final build)

**Harness** (`tools/verify.mjs`): **135 captures**. That is all 65 states at 1440 × 900, and the 35 core states again at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2). Across all of them:
- horizontal overflow is 0;
- there are 0 console errors;
- every scripted step completed;
- the header subline is one line;
- no meter caption or table cell is truncated.

Earlier runs found these problems, and this version fixes them:
- Truncated at 200 %: the meter captions “Returned, not yet received” and “By the end of the next shift”. They are now “Not yet received” and “By next shift’s end”.
- **Witness overrides table at 1280:** it was wider than the page (its “Open” button was cut off) and truncated “Methylphenidate”. It now fits.
- **The two-house subline** wrapped at 1280. It now reads “Controlled medicines at … · times in NZDT”; the append-only rule is in the Recent entries caption.
- **“Class not set — was ‘Schedule 3’”** wrapped in the class column. The column is wider.
- **Changes showed as “-1”.** They now use a real minus sign (“−1”).
- **Loss notifications (Q5):**
  - A loss couldn’t record that the police or Medicines Control were told after the report. “Add to the investigation” can now record either, with the police event number (`44`).
  - Close the loss shows who has been told.
  - A theft can’t close until the police are recorded (`45b`, `45c`). The unchosen finding tiles no longer turn red with that error.
- **“A count isn’t voided”** said “It may have been done already”. It now says counts stay as recorded, and that a difference is corrected by resolving its discrepancy (`98`).
- **Recent entries** now sort newest first across medicines.

**Two runs hit a stopped server.** Partway through two runs, the app stopped the preview server, so the captures after that point were Chrome’s error page. The harness now:
- fails any capture where the preview didn’t render;
- runs against its own read-only copy of `serve.mjs`.

The final run below is complete.

**Keyboard** (`report.json` → `keyboard`): real key events, as Jordan Tipene on Discrepancies.
- Enter on “Resolve” for D-14 opens the resolve wizard, and Tab cycles inside it.
- Escape closes the untouched wizard, and focus returns to “Resolve”.
- The menu key on a focused register row opens: Open the register for this medicine · Record a breakage or spillage · Report a loss · Return for destruction · Change the class.

**`tsc`** (`tsconfig.json`): no errors in `src/`. The 2 errors in shared files (`breadcrumbs.tsx:36`, `file-dropzone.tsx:223`) come from P01’s Inertia shim types.

**ESLint** (the app config, `--no-ignore`, `src/**/*.{ts,tsx}`): 17 files, 0 errors, 0 warnings. A separate check found no unused imports.

## 5. v1.1 — Main’s inspection fixes (30 September 2026)

**The fixes:**
1. **Empty states** are worded per section and filter, not built from a template. For example “No discrepancies with a manager” (`10`) and “No declined overrides” (`16b`, new).
2. **Outcome tones.** A discrepancy’s outcome badge is warning for a loss and neutral otherwise (`10`). Closed losses (`12`) and completed destructions (`13`) are neutral, not green.

**Clinical leads — deviation 5 → (B).** Clinical leads hold no controlled keys. Hana’s persona label and the design note say so (`05`, `17`), and the preview behaves the same.

**Harness:** the touched states were re-run with `--only`: `05`, `10`–`17` (with the new `16b`), `28`, `43`, `58` and `100`. That is 30 captures, with 0 problems.
- A partial run now replaces only its own captures in `report.json` and records itself under `reruns`.
- `report.json` therefore holds 136 captures: 66 states at 1440, plus 35 each at 1280 and 200 %. All pass.

**`tsc` and ESLint:** clean for `src/` (17 files, 0 problems; no unused imports).
