# P05 v1 — Mockup design-rules checklist (self-check with evidence)

The checklist is `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026). I checked it together with:
- the findings earlier packages had to fix (`Mockup-session-brief.md` §5);
- Main’s P03/P04 fixes: explanations in neutral text, colour on the badge only;
- Main’s P07b fixes: empty states worded per section, and no success tone on a loss or a failure.

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records:
- horizontal overflow;
- console errors;
- whether the scripted steps completed;
- the header subline’s line count;
- truncated meter captions and truncated table cells.

Numbers in brackets, such as (`30`), are screenshot states. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P05’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| A Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `serve.mjs` and `tsconfig.json` are copied from P07b v1.1, with port 4392. `src/inertia-shim.tsx` is P01 v1’s, unchanged. `src/styles.css` has explicit `@source` lines, including Fleet Settings’ `_ui` and the client tabs. |
| Dependencies installed in this worktree | Pass | `npm ci` in `intelligent-antonelli-87bad1`. |
| Real primitives, never hand-rolled | Pass | These are all the real components:<br>• `PageHeader` family and `PageHeaderRail`;<br>• `EntityTable`, `ListCaption`, `PersonDisc`, `EntityChip`, `EntityContextMenu` and `compactMenu`;<br>• `WizardShell` — sequential for Book and Record, the viewer for the review — with `WizardStepPane`, `ReviewCard`/`ReviewRow` and `WizardSuccessPane`;<br>• the Fleet Settings `Modal`, and Fleet’s own `Modal`, `Notice` and `Sections` in the P11 frame;<br>• `ConfirmDialog`, `StatusBadge`, `EmptyState`, `ErrorState` and `SkeletonTable`;<br>• `Select`, `Input`, `Textarea`, `Checkbox`;<br>• `FileDropzone` + `StagedFileCard`;<br>• the PKG-01 `DateTimeField` and the approved `DatePicker`;<br>• `InfoCard`, `TierTwoTabs`, `Breadcrumbs`, `Card` and `Table`;<br>• **the real `ActionsReviewsTab`**, unchanged. |
| Synthetic data only; no application API; a fixed clock in Pacific/Auckland | Pass | `data.ts` holds the data. `clock.ts` fixes the clock at Monday 28 September 2026, 9:12 am NZDT. The shim never sends anything, and `serve.mjs` answers only GET and HEAD. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P05/v1/**`. |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | The Reviews view (`01`) replaces today’s PageHero (AUDIT 1.3). The record (`15`), Settings (`70`) and the client profile (`80`) are their approved frames. |
| A plain title, one StatusBadge chip, a one-line fact subline | Pass (note) | `01`: one line. The person record and client profile use P02’s approved two-line subline (identity, then house) (`15`, `80`). The P11 frame’s subline wraps at 1280, because this shell’s sidebar is 256 px and P11’s own is 218 px (`1280-70`). |
| No greetings and no LIVE or refreshed eyebrows | Pass | The as-at time is a filter chip; out of date is a notice with “Try again” (`93`). |
| One meter row of 4–6 blocks, each linked, with real data, and “—” or “Unavailable” | Pass | Six meters on the Reviews view (Main, Q1), each opening its view. Loading shows “—” (`90`); couldn’t load shows the ErrorState (`92`). |
| Search and primary filters inside the header; nothing between the header and the content | Pass (note) | House (managers), Kind and the as-at chip sit in the header. The tier-2 strip (To do · Changes · Booked · Recorded · Cancelled or closed) sits under it, as P02’s record strip does. The out-of-date notice is the only other thing between. |
| `PageHeaderRail`: one line, 8 views or fewer, the Find chip | Pass | P04’s rail, unchanged: Orders · To check · Covert · Reconciliation · Medication reviews, plus Find. |
| Breadcrumbs rooted at Home | Pass | Home › Medication › Orders & reviews › Medication reviews |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | The shell is P07b’s. |
| A full-width body with no `max-w` cap | Pass | All views |
| A number lives once, in the meter row | Pass | “Due in 30 days” replaces today’s 7-day card beside a 30-day list (AUDIT 7.10). Captions give “N shown”. |
| Desktop only; checked at 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json`: overflow 0 on every capture. |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts: identity first, kebab last | Pass | Covers every list: reviews (`01`, `10`–`12`), changes (`08`), the person’s reviews (`15`) and the P11 change history (`74`). |
| ⋯ and the same menu on right-click (plus the menu key); clicking a row opens it | Pass (note) | `02`, `13`, and `report.json` → `keyboard`. Blocked actions stay in the menu and explain why: a regular review’s “Cancel” (`64`), and “can’t do this” with who can (`96`, `60`). `MenuItem` has no disabled state. |
| Server-style pagination | N/A (note) | Today’s list is capped at 250 rows (AUDIT 1.2). The build pages it (build note 1). |
| Empty, loading and error states use the shared components | Pass | EmptyState worded per section (“Nothing overdue”, “No open changes”, …) (`91`, `12`); SkeletonTable (`90`); ErrorState (`92`). |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Adding or editing with 2 or more sections uses WizardShell | Pass | Book a review (`30`–`35`) and Record the outcome (`40`–`50`). The review is the WizardShell viewer (`22`–`27`). |
| Simple dialogs follow the shell/body split, width tokens and footer | Pass | Covers these dialogs:<br>• the change (`52`–`55`), the prescriber’s decision (`56`–`58`), Enter the change (`59`);<br>• Move (`62`, `63`), Cancel (`64`, `65`), the appointment (`66`), How often (`67`, `68`);<br>• P11’s Review changes (`73`, `75`);<br>• can’t do this (`60`, `96`), not found (`97`), already recorded (`98`). |
| Consequential saves state the effect; destructive actions use the destructive variant | Pass | Record the outcome’s “When you save” (`48`). Cancelling a triggered review uses the destructive button and a destructive `ConfirmDialog` (`65`). P11’s Review changes warns “Loosens this check” and turns destructive when the interval gets longer (`73`). |
| Never a browser `prompt` or `confirm` | Pass | None |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | Values are kept and the first error is focused in `31`, `32`, `44`, `57` and `63`. Discard guards protect Book and Record. Escape closes the untouched Record wizard, and focus returns to “Record the outcome” (`report.json` keyboard). |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | N/A (note) | The one new setting is a number of months, in P11’s NumberInput pattern (`70`). |
| Growing lists use a searchable picker | N/A (note) | The pickers are short and bounded — people at the persona’s houses, the known clinicians, the owners — so they use `Select`. |
| Uploads use FileDropzone + StagedFileCard | Pass | The clinician’s written review (Record, step 4) and the prescriber’s written decision (`56`). |
| Dates and times use the approved pickers with the time zone visible | Pass | The due date, the new date and “watch until” use the approved `DatePicker` (`34`, `62`, `45`). When it happened, the appointment and when they decided use the PKG-01 `DateTimeField` (`40`, `66`, `58`). |
| Buttons aren’t restyled | Pass | Only `Button` variants and the PageHeader button family. The stored-file line is a `Button variant="outline"` with layout classes. |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only | Pass | ESLint (the app config) on `src/**`: see §4 |
| Every status uses StatusBadge | Pass | Review states (Overdue, Outcome to record, Due in N days, Booked, Recorded, Cancelled, Closed automatically) and change steps. |
| Colour is never the only signal; no success tone for a failure | Pass | Every state has words. “Not agreed” and “Watched — done” are neutral; only “Order changed” (a completed change) is success. |
| lucide icons only; icon-only buttons have an `aria-label` | Pass | Yes |
| A visible focus ring; 44 px tap targets on frontline actions | Pass | `frontline-tap` on every tile (`ui.tsx` TilePicker). |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | Permission keys and package codes appear only in design notes, viewer toasts and the contract page. Product copy says “someone else who checks orders”, “the phone rule”, “house leads, clinical leads, coordinators and managers”. |
| “Not configured”, “Not available” or “Unknown” instead of fake zeros | Pass | Examples: “Not booked with a clinician yet”, “Default — not yet reviewed”, “Outcome to add”. No “GP accept %”. |
| Every blocked action says why and who can unblock it | Pass | Examples:<br>• a regular review can’t be cancelled, with “Move it instead” (`64`);<br>• support workers can’t record (`96`);<br>• a clinical lead can’t decide a controlled change (`60`);<br>• a second regular review is refused, naming the booked one (`32`). |
| No invented clinical values or authority claims | Pass | The cadence claims and “HQSC expectation” are gone (AUDIT 4). Clinicians and practices are marked synthetic. The drug burden index and falls are only the clinician’s own figures. |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions; unbuilt things are hidden | Pass (note) | P04’s other views, P02’s other Clinical views and P11’s other sections say whose they are. “Continue in Orders” and the follow-up are linked (deviation 2). |
| Synthetic data clearly labelled | Pass | The viewer bar; the clinicians and practices carry “(synthetic)”. |
| Views approved earlier are reused unchanged unless an approved change says otherwise | Pass | P04’s hub header and rail; P02’s record header (via P03) and client-profile frame; P11’s Settings frame and group/row pattern; the real Actions & Reviews tab. |

## 3. Brief §5 findings, checked first

| Finding | Result |
|---|---|
| A number lives once | Pass |
| One-line subline; no truncated meter captions | Pass (note) — P05’s own captions are one line and untruncated. P11’s caption “5 not configured · the rest are defaults” truncates at 1280 and 200 %, as it does in P11 v5’s own 1280 capture. It’s P11’s copy, kept unchanged and reported to Main. |
| No link tiles dressed as meters | Pass |
| No codes in product copy | Pass (see §G) |
| Real button variants only | Pass |
| Honest states | Pass |
| Controlled-drug concealment everywhere, shown with a persona | Pass. For Hana (no controlled keys), a controlled medicine reads “Controlled medicine”, with no name, strength or dose and no action (`09`, `17`, `50`). In Actions & Reviews it reads “A controlled medicine” (`82`). |
| House scope: no access vs not found | Pass. Ben’s review from Kōwhai House is “We can’t show this record” (`97`); a support worker opening “record” gets “can’t do this” (`96`). |
| Loosening or destroying is destructive | Pass (`65`, `73`) |
| Reference frames match origin/main | Pass. `origin/main` is `2e1d38a8a`. |
| Every claim about today’s code has `file:line`, marked verified or inferred | Pass: see AUDIT.md |

## 4. Verification (1 October 2026, final build)

**Harness** (`tools/verify.mjs`): **151 captures**. That is all 77 states at 1440 × 900, and the 37 core states again at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2). Across all of them:
- horizontal overflow is 0;
- there are 0 console errors;
- every scripted step completed;
- no P05 meter caption or table cell is truncated (P11’s one caption is noted in §3).

Earlier runs found these problems, and this version fixes them:
- **A crash in Book a review.** Choosing a clinician before “where” read an empty value on the review step, which is built with every step. It’s guarded now.
- **Truncated names at 1280 and 200 %.** Medicine names (“Levothyroxine 50 microgram tablet”), a person (“Tamati James Walker”) and a review kind (“Triggered — back from hospital or respite”). The strength and trigger moved to the subline, and the person column is wider.
- **Names lowercased.** “email to dr lena chen” and “the person, whānau or gp asked” — only the first letter is lowercased now.
- **Codes in product copy.** “(P04’s phone rule)”, “(P08a)”, “(P03)”, “orders.verify” and “orders.manage” are now plain words.
- **An action that only led to a refusal.** For Hana, the redacted controlled row offered “Record the decision”. It now offers nothing.
- **“1 change wait”.** Now “1 change waits”.
- **“Booked · not booked with a clinician yet”** in Actions & Reviews. Now “due Mon 16 Nov · no appointment yet”.
- **A harness bug.** `fbtn('Continue')` clicked a dropdown whose value read “Continue”. The footer is now searched first, and dropdown triggers are skipped.

**Keyboard** (`report.json` → `keyboard`): real key events, as Jordan Tipene.
- Enter on “Record the outcome” (Mele’s R-27) opens the wizard, and Tab cycles inside it.
- Escape closes the untouched wizard, and focus returns to “Record the outcome”.
- The menu key on Sam’s review row opens: Open the review · Record the outcome · Book the appointment · Move the review · Cancel the review · Open Sam’s medication record · Change how often.

**`tsc`** (`tsconfig.json`): no errors in `src/`. The 2 errors in shared files (`breadcrumbs.tsx:36`, `file-dropzone.tsx:223`) come from P01’s Inertia shim types.

**ESLint** (the app config, `--no-ignore`, `src/**/*.{ts,tsx}`): 19 files, 0 errors, 0 warnings. A separate check found no unused imports.
