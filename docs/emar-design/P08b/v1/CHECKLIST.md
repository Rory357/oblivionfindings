# P08b v1 — Mockup design-rules checklist (self-check with evidence)

The checklist is `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026). I checked it together with:
- the findings earlier packages had to fix (`Mockup-session-brief.md` §5);
- Main’s P03/P04 fixes: explanations in neutral text, colour on the badge only;
- Main’s P07b fixes: empty states worded per section, and no success tone on a loss or a failure;
- Main’s P08b instructions: the full row pattern, the approved date/time pickers, harm badges by severity with nothing green that is harm, and empty states worded per section.

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records:
- horizontal overflow;
- console errors;
- whether the scripted steps completed;
- the header subline’s line count;
- truncated meter captions and truncated table cells.

Numbers in brackets, such as (`30`), are screenshot states. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P08b’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| A Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `serve.mjs` and `tsconfig.json` are copied from P05 v1.1, with port 4393. `src/inertia-shim.tsx` is P01 v1’s, unchanged. `src/styles.css` has explicit `@source` lines, including Fleet Settings’ `_ui`. |
| Dependencies installed in this worktree | Pass | `npm ci` in `intelligent-antonelli-87bad1`. |
| Real primitives, never hand-rolled | Pass | These are all the real components:<br>• `PageHeader` family and `PageHeaderRail`;<br>• `EntityTable`, `ListCaption`, `PersonDisc`, `EntityChip`, `EntityContextMenu` and `compactMenu`;<br>• `WizardShell` — sequential for Report and Triage, the viewer for the report — with `WizardStepPane`, `ReviewCard`/`ReviewRow` and `WizardSuccessPane`;<br>• the Fleet Settings `Modal`, and Fleet’s own `Modal`, `Notice` and `Sections` in the P11 frame;<br>• `ConfirmDialog`, `StatusBadge`, `EmptyState`, `ErrorState` and `SkeletonTable`;<br>• `Select`, `Input`, `Textarea`, `Checkbox`, and `Segmented` (P11’s Choice);<br>• the PKG-01 `DateTimeField` and the approved `DatePicker`;<br>• `InfoCard`, `TierTwoTabs`, `Breadcrumbs`, `Card`, `Alert` and `Table`. |
| Synthetic data only; no application API; a fixed clock in Pacific/Auckland | Pass | `data.ts` holds the data. `clock.ts` fixes the clock at Monday 28 September 2026, 9:12 am NZDT. The shim never sends anything, and `serve.mjs` answers only GET and HEAD. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P08b/v1/**`. |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | Medication errors (`01`) replaces today’s PageHero (AUDIT 1.3). Settings (`86`) is P11’s approved frame; the Incidents queue (`80`) is a frame (deviation 1). |
| A plain title, one StatusBadge chip, a one-line fact subline | Pass (note) | `01`, `03`: one line at every size (“at your 2 houses” for managers of both). The P11 frame’s subline wraps at 1280, as in P05’s frame: this shell’s sidebar is 256 px and P11’s own is 218 px (`1280-86`). |
| No greetings and no LIVE or refreshed eyebrows | Pass | Today’s “Medication-safety register · live” is gone (AUDIT 6). The as-at time is a filter chip; out of date is a notice with “Try again” (`97`). |
| One meter row of 4–6 blocks, each linked, with real data, and “—” or “Unavailable” | Pass | Six meters for managers (Main, Q1), each opening its view (`01`); two for support workers (`04`). Loading shows “—” (`93`); couldn’t load shows the ErrorState (`96`). |
| Search and primary filters inside the header; nothing between the header and the content | Pass (note) | Search, Export, Report an error, the house filter (managers of two houses) and the as-at chip sit in the header. The tier-2 strip sits under it, as in P05. The out-of-date notice is the only other thing between. |
| `PageHeaderRail`: one line, 8 views or fewer, the Find chip | Pass | P08a’s rail, unchanged: Overview · Follow-ups · Witness overrides · Medication errors · Handovers · Staff eligibility · Emergency access, plus Find. |
| Breadcrumbs rooted at Home | Pass | Home › Medication › Safety & oversight › Medication errors |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | The shell is P05’s. |
| A full-width body with no `max-w` cap | Pass | All views |
| A number lives once, in the meter row | Pass | Each meter’s number comes from one rule in `model.ts`; the Trends view uses the same rules (Closed is 8 for Rangi in the meter and in Trends, `13`). Captions give “N shown”. |
| Desktop only; checked at 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json`: overflow 0 on every capture. |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts: identity first, kebab last | Pass | Covers every list: errors (`01`, `08`, `12`), actions (`09`), incidents (`10`, `11`), the Incidents queue (`80`) and the P11 change history (`91`). |
| ⋯ and the same menu on right-click (plus the menu key); clicking a row opens it | Pass (note) | `02`, `14`, `15`, and `report.json` → `keyboard`. Unavailable actions are left out of the menu, as approved mockups do: Hana’s controlled row offers only “Open the report” and the person’s record. A deep link to an action you can’t take says why and who can (`55`, `73`, `79`, `102`). |
| Server-style pagination | Pass (note) | Closed says “1–N of N · pages of 50 from the server” (`12`); build note 1 retires `limit(300)`. |
| Empty, loading and error states use the shared components; worded per section | Pass | EmptyState per section: “Nothing to triage”, “Nothing being investigated”, “No open actions”, “No incidents ready to close”, “No incidents waiting”, “Nothing closed in the last 90 days”, and for a support worker “You haven’t reported any medication errors” (`94`, `95`). SkeletonTable (`93`); ErrorState (`96`). No “A quiet register is a good sign”. |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Adding or editing with 2 or more sections uses WizardShell | Pass | Report a medication error (`20`–`36`) and Triage (`50`–`54`). The report is the WizardShell viewer (`40`–`49`). |
| Simple dialogs follow the shell/body split, width tokens and footer | Pass | Covers these dialogs:<br>• note (`56`, `57`), action (`58`, `59`), mark done (`60`), telling the person (`62`, `63`), reopen (`82`), add an account (`83`);<br>• close the error (`65`–`72`), not ready (`74`), review and close an incident (`76`–`78`), export (`84`, `85`);<br>• P11’s Review changes (`89`, `90`);<br>• can’t do this (`55`, `73`, `79`, `102`), not found (`100`, `101`). |
| Consequential saves state the effect; destructive actions use the destructive variant | Pass (note) | Report’s review step says what’s shown outside, whether an incident is made and when it’s triaged (`26`). Close says what happens to the incident on each path (`66`, `70`) and confirms (`67`, `71`). Closing isn’t destructive, so its confirm is the default variant (deviation 5); discarding a wizard uses the red default. P11’s Review changes turns destructive when the triage time gets longer. |
| Never a browser `prompt` or `confirm` | Pass | None |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | Values are kept and the first error is focused (`21`, `57`). Discard guards protect Report and Triage. Escape closes the untouched Triage wizard, and focus returns to “Triage it” (`report.json` keyboard). |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | N/A (note) | The one new setting is a choice of three, in P11 v5’s Choice (Segmented) pattern (`86`). |
| Growing lists use a searchable picker | N/A (note) | The pickers are short and bounded — people at the persona’s houses, the person’s current orders, the owners — so they use `Select` or checkboxes. |
| Uploads use FileDropzone + StagedFileCard | N/A | No uploads in P08b; today’s attachments have no UI (AUDIT 8). |
| Dates and times use the approved pickers with the time zone visible | Pass | When it happened and when the person was told use the PKG-01 `DateTimeField` with “Pacific/Auckland” (`22`, `63`). The investigation and action due dates use the approved `DatePicker` (`51`, `59`). |
| Buttons aren’t restyled | Pass | Only `Button` variants and the PageHeader button family. |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only | Pass | ESLint (the app config) on `src/**`: see §4 |
| Every status uses StatusBadge | Pass | Stages (To triage, Triage overdue, Investigating, Investigation overdue, Actions — N open, Telling the person to record, Ready to close, Closed), harm, action states and incident states. |
| Colour is never the only signal; no success tone for harm or a failure | Pass | Every state has words. **Harm by severity:** near miss info; reached with no harm or not known neutral; minor and moderate warning; severe and death critical — never green (`model.ts` `harmTone`). Only a done action is success (`42`). |
| lucide icons only; icon-only buttons have an `aria-label` | Pass | Yes |
| A visible focus ring; 44 px tap targets on frontline actions | Pass | `frontline-tap` on every tile (`ui.tsx` TilePicker). |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | Permission keys and package codes appear only in design notes, viewer toasts and the contract page. Product copy says “house leads, clinical leads, coordinators and managers”, “coordinators and provider managers”, “controlled-medicine access”. The governance count isn’t named by its code in the product. |
| “Not configured”, “Not available” or “Unknown” instead of fake zeros | Pass | “Harm not known yet”, “Not triaged yet”, “Not recorded yet”, “Default — not yet reviewed”. |
| Every blocked action says why and who can unblock it | Pass | Examples:<br>• a clinical lead can’t triage a controlled error — a house lead with controlled-medicine access does (`55`, `46`);<br>• the reporter never closes their own report (`73`);<br>• what’s left before closing (`74`);<br>• a house lead doesn’t close incidents — coordinators and provider managers do (`66`, `79`);<br>• an auditor can’t report (`102`). |
| No invented clinical values or authority claims | Pass | The unsourced NZ-practice line and “with an audit entry” are gone (AUDIT 6). Harm is two plain questions; the HQSC scheme isn’t claimed (Q2). No reassurance copy. |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions; unbuilt things are hidden | Pass (note) | P08a’s other views and P11’s other sections say whose they are. The person’s record, Delivery, Roles and “Open the incident” in the Incidents frame say where they go (toasts). |
| Synthetic data clearly labelled | Pass | The viewer bar; every account carries “(Synthetic.)”. |
| Views approved earlier are reused unchanged unless an approved change says otherwise | Pass | P08a’s frame; P11’s Settings frame and group/row pattern; P07b’s incident titles. |

## 3. Brief §5 findings, checked first

| Finding | Result |
|---|---|
| A number lives once | Pass |
| One-line subline; no truncated meter captions | Pass (note) — P08b’s own captions are one line and untruncated at every size. P11’s caption “5 not configured · the rest are defaults” truncates at 1280 and 200 %, as in P11 v5 and P05; it’s P11’s copy (P11 B1 build note: “5 not configured”). |
| No link tiles dressed as meters | Pass |
| No codes in product copy | Pass (see §G) |
| Real button variants only | Pass |
| Honest states | Pass |
| Controlled-drug concealment everywhere, shown with a persona | Pass. For Hana (no controlled keys): the list row reads “Controlled medicine” (`05`), the report hides the medicine, accounts and notes and offers no action (`46`, `47`), triage is refused (`55`), the duplicate prompt reads “An open report about this person at this dose time” (`31`), and the export leaves the controlled report’s medicine and words out (`85`). Outside the report, only the summary is shown (`40`, `43`, `80`). The “don’t name the medicine” prompt appears for Priya, who has controlled access (`29`). |
| House scope: no access vs not found | Pass. Ben’s report from Kōwhai House is “We can’t show this record” (`100`); a support worker opening someone else’s report gets the same (`101`); an auditor reporting gets “can’t do this” (`102`). |
| Loosening or destroying is destructive | Pass (`89` warns and turns destructive when the triage time gets longer; discard confirms are red) |
| Reference frames match origin/main | Pass. `origin/main` is `33fb7a3c9`. |
| Every claim about today’s code has `file:line`, marked verified or inferred | Pass: see AUDIT.md |

## 4. Verification (1 October 2026, final build)

**Harness** (`tools/verify.mjs`): **201 captures**. That is all 91 states at 1440 × 900, and the 55 core states again at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2). Across all of them:
- horizontal overflow is 0;
- there are 0 console errors;
- every scripted step completed;
- no P08b meter caption or table cell is truncated (P11’s one caption is noted in §3).

The harness runs each close path end to end: mark A-31 done, close MED-0045 as Jordan (the incident becomes “Ready to close — medication error closed”, `68`) and as Rangi (the incident is reviewed and closed, `72`).

Earlier runs found these problems, and this version fixes them:
- **Truncated meter captions** (“Next due the end of tomorrow (Tue 29 Sep)”, “For coordinators and provider managers”, “Happened in the last 90 days”). Now “Due Tue 29 Sep”, “For a manager to close”, “Last 90 days”.
- **Truncated incident titles and action text** at 1280 and 200 %. The id is the row’s name now, and the title or action wraps on the line below.
- **A two-line subline** for managers of both houses. Now “at your 2 houses”.
- **The triage wizard asked to discard on Escape when nothing had changed**, because the owner was filled in. It now compares with what it opened with.
- **A component made during render** in Trends (ESLint `react-hooks/static-components`). Removed.

**Keyboard** (`report.json` → `keyboard`): real key events, as Jordan Tipene.
- Enter on “Triage it” (Grace’s MED-0048) opens the wizard, and Tab cycles inside it.
- Escape closes the untouched wizard, and focus returns to “Triage it”.
- The menu key on Grace’s row opens: Open the report · Triage it · Record telling the person · Add your account · Open Grace’s medication record.

**`tsc`** (`tsconfig.json`): no errors in `src/`. The 1 error in a shared file (`breadcrumbs.tsx:36`) comes from P01’s Inertia shim types.

**ESLint** (the app config, `--no-ignore`, `src/**/*.{ts,tsx}`): 18 files, 0 errors, 0 warnings. A separate check found no unused imports.

## 5. v1.1 — Main’s inspection decisions (1 October 2026)

**D3, reversed:** a controlled error alerts all the configured recipients, the clinical lead included; the alert carries the neutral summary only.
- The bell counts every error waiting for triage at your houses — Hana’s shows 1 (MED-0048) (`05`), and it opens the redacted report (`46`, `47`); triage stays refused for her (`55`).
- Settings › Error triage › “Who’s alerted until then” reads “the house lead and the clinical lead for the house” (`86`).

**Recorded:** D4 (support workers reach “Your reports” from Meds today — README build note 18), build note 8 (a one-off review list of existing incidents with copied free text) and D5 (non-destructive close confirm).

**Harness:** the touched states were re-run with `--only` on the v1.1 build: `05`, `31`, `46`, `47`, `55`, `85` and `86`–`91` — 30 captures, 0 problems. The other captures are from the v1 build; v1.1 changes nothing they show except the viewer bar’s “v1.1”. `report.json` holds 201 captures, 0 with problems.
