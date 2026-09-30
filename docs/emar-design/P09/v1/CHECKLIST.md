# P09 v1 — Mockup design-rules checklist (self-check with evidence)

The checklist is `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026). I checked it together with:
- the findings earlier packages had to fix (`Mockup-session-brief.md` §5);
- Main’s P03/P04 fixes: explanations in neutral text, colour on the badge only;
- Main’s P07b fixes: empty states worded per section, and no success tone on a loss or a failure;
- Main’s P08b instructions: the full row pattern, the approved date/time pickers, harm badges by severity, empty states per section;
- Main’s P09 instructions: every number’s definition on the contract page, “Not applicable” states, the export-permission-denied state, the purpose prompt on identifiable exports, and the event-log failure state.

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records:
- horizontal overflow;
- console errors;
- whether the scripted steps completed;
- the header subline’s line count;
- truncated meter captions and truncated table cells.

Numbers in brackets, such as (`30`), are screenshot states. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P09’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| A Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `serve.mjs` and `tsconfig.json` are copied from P08b v1.1, with port 4394. `src/styles.css` adds an `@source` for `pages/reporting`. |
| Dependencies installed in this worktree | Pass | `npm ci` in `intelligent-antonelli-87bad1`. |
| Real primitives, never hand-rolled | Pass | These are all the real components:<br>• `PageHeader` family and `PageHeaderRail`;<br>• `EntityTable` (with its footer row), `ListCaption`, `PersonDisc`, `EntityChip`, `EntityContextMenu`, `compactMenu`;<br>• **`LaravelPagination`** for the audit trail;<br>• **the shared report builder’s `ReportWorkspace`**, unchanged;<br>• the Fleet Settings `Modal`, and Fleet’s own `Modal`, `Notice` and `Sections` in the P11 frame;<br>• `ConfirmDialog`, `StatusBadge`, `EmptyState`, `ErrorState`, `SkeletonTable`;<br>• `Select`, `Input`, `Textarea`, `Switch`, `Segmented` (P11’s Choice), `ReviewCard`/`ReviewRow`;<br>• the approved `DatePicker`;<br>• `TierTwoTabs`, `Breadcrumbs`, `Card`, `Alert` and `Table`. |
| Synthetic data only; no application API; a fixed clock in Pacific/Auckland | Pass (note) | `data.ts` holds the data; dose slots come from one deterministic rule. `clock.ts` fixes the clock at Monday 28 September 2026, 9:12 am NZDT. The builder’s requests are answered inside the browser (`builder-stub.ts`, deviation 2); nothing is sent. `serve.mjs` answers only GET and HEAD. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P09/v1/**`. |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | The hub (`01`) replaces today’s PageHero reports page (AUDIT 1.9). Settings (`90`) and the P08b frame (`80`) are approved frames; the builder (`70`) is the real workspace’s own header. |
| A plain title, one StatusBadge chip, a one-line fact subline | Pass (note) | `01`, `30`, `50`: one line at every size. The P11 frame’s subline wraps at 1280, as in P05’s and P08b’s frames. |
| No greetings and no LIVE or refreshed eyebrows | Pass | Today’s “Live reporting · refreshed” is gone (AUDIT 1.9). The as-at time is a filter chip; out of date is a notice with “Try again” (`103`). |
| One meter row of 4–6 blocks, each linked, with real data, and “—” or “Unavailable” | Pass | Each view’s own meters (2–5). Loading shows “—” (`100`); couldn’t load shows the ErrorState (`102`). A zero denominator reads **“Not applicable”** with why (`09`). |
| Search and primary filters inside the header; nothing between the header and the content | Pass | Period, house, person, audit kind and the as-at chip sit in the header; the tier-2 strip is under it. |
| `PageHeaderRail`: one line, 8 views or fewer, the Find chip | Pass | Standard reports · Report builder · Audit trail · Print & exports, plus Find. |
| Breadcrumbs rooted at Home | Pass | Home › Medication › Reports & audit › {view} |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | The shell is P08b’s. |
| A full-width body with no `max-w` cap | Pass | All views |
| A number lives once, in the meter row | Pass | Each number is worked out once (model.ts `DEFS`) and listed on the contract page (`110`); the table footers repeat the meter row’s totals exactly (`03`). |
| Desktop only; checked at 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json`: overflow 0 on every capture. |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts: identity first, kebab last | Pass | Every list: people (`01`), rounds (`08`), controlled (`11`), errors (`13`), stock (`16`), events (`30`), unrecorded doses (`36`), exports (`50`), P11 change history (`96`). |
| ⋯ and the same menu on right-click (plus the menu key); clicking a row opens it | Pass | `02`, `39`, and `report.json` → `keyboard`. Unavailable actions are left out of menus (Jordan’s person menu has no “Make MAR”). |
| Server-style pagination | Pass | The audit trail pages 50 at a time with the real `LaravelPagination` — “1–50 of 655”, page 2 (`30`, `31`). |
| Empty, loading and error states use the shared components; worded per section | Pass | For example “Every round that ended was completed”, “No unrecorded doses in this period”, “No as-needed doses given”, “No medication errors in this period” (`101`); SkeletonTable (`100`); ErrorState (`102`). |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Adding or editing with 2 or more sections uses WizardShell | N/A (note) | P09’s dialogs are single-section: an export (`54`), an event (`34`), the chain check (`35`), a period (`20`), closing with SAC (`82`). |
| Simple dialogs follow the shell/body split, width tokens and footer | Pass | The Fleet Settings `Modal` throughout; P11’s Review changes (`93`). |
| Consequential saves state the effect; destructive actions use the destructive variant | Pass | The export dialog says what’s in it and that it’s recorded (`54`, `56`). Closing confirms with the default variant, as P08b does (`85`). |
| Never a browser `prompt` or `confirm` | Pass | None |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | `21`, `55`, `84`. **The event-log failure keeps everything and says “Couldn’t save — try again”** (`60`, `87`). Escape returns focus to “Make it” (`report.json` keyboard). |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | Pass | “Add SAC ratings when an error is closed” (`92`). |
| Growing lists use a searchable picker | N/A (note) | The pickers are short and bounded — people at the persona’s houses, controlled medicines, months. |
| Uploads use FileDropzone + StagedFileCard | N/A | No uploads. |
| Dates and times use the approved pickers with the time zone visible | Pass | The custom period and the round-sheet day use the approved `DatePicker` (`20`, `62`); periods and times say NZ. |
| Buttons aren’t restyled | Pass | Only `Button` variants and the PageHeader button family. |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only | Pass | ESLint (the app config) on `src/**`: see §4 |
| Every status uses StatusBadge | Pass | “Not applicable”, round results, stock status, competency, follow-up states, harm, SAC. |
| Colour is never the only signal; no success tone for harm or a failure | Pass | Every state has words. Harm by severity as P08b (`13`); only “Competency current” is success (`19`). |
| lucide icons only; icon-only buttons have an `aria-label` | Pass | Yes |
| A visible focus ring; 44 px tap targets on frontline actions | Pass | Tiles carry `frontline-tap`; pagination uses the real component’s `frontline-hit`. |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | Permission keys appear only in the README, design notes and viewer toasts. “Fingerprint” instead of hash; “Coordinators and provider managers” instead of keys. |
| “Not configured”, “Not available” or “Unknown” instead of fake zeros | Pass | “Not applicable” with its reason (`04`, `09`); the governance target “Not configured” (`13`). |
| Every blocked action says why and who can unblock it | Pass | Examples:<br>• no reports for a support worker (`06`);<br>• no audit trail for a house lead (`38`);<br>• the controlled breakdown for Hana (`12`);<br>• export denied, with who can (`51`, `58`, `59`). |
| No invented clinical values or authority claims | Pass | CQC, NICE, Ngā Paerewa and “immutable” claims are gone (AUDIT 1.9, 3.10). The SAC scheme is named only where the organisation turns it on. The retention regulation is cited in the build note, not the product. |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions; unbuilt things are hidden | Pass (note) | The MAR, follow-ups and P08b’s wizard say where they go (toasts). Files don’t download in the preview; the toast says so. |
| Synthetic data clearly labelled | Pass | The viewer bar; fixtures marked synthetic. |
| Views approved earlier are reused unchanged unless an approved change says otherwise | Pass | P11’s Settings frame; P08b’s close dialog with only the SAC addition (Main, Q11); the real builder workspace. |

## 3. Brief §5 findings, checked first

| Finding | Result |
|---|---|
| A number lives once | Pass |
| One-line subline; no truncated meter captions | Pass (note) — P09’s own captions are one line and untruncated at every size. P11’s caption “5 not configured · the rest are defaults” truncates at 1280 and 200 %, as in P11 v5, P05 and P08b (P11 B1 note: “5 not configured”). |
| No link tiles dressed as meters | Pass |
| No codes in product copy | Pass (see §G) |
| Real button variants only | Pass |
| Honest states | Pass |
| Controlled-drug concealment everywhere, shown with a persona | Pass. For Hana (no controlled keys): totals include controlled doses (`05`); the controlled breakdown is locked (`12`); audit rows are redacted, with the row kept (`32`); stock leaves controlled lines out (`18`); the builder has no controlled source (`72`); errors name “Controlled medicine” (`14`). |
| House scope: no access vs not found | Pass. An event id that doesn’t exist at your houses is “We can’t show this record” (`105`); a role without the audit trail is “can’t do this” (`38`). |
| Loosening or destroying is destructive | Pass. No P09 setting loosens a check: retention offers only 10 years or longer. |
| Reference frames match origin/main | Pass. `origin/main` is `33fb7a3c9`. |
| Every claim about today’s code has `file:line`, marked verified or inferred | Pass: see AUDIT.md |

## 4. Verification (1 October 2026, final build)

**Harness** (`tools/verify.mjs`): **170 captures**. That is all 76 states at 1440 × 900, and the 47 core states again at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2). Across all of them:
- horizontal overflow is 0;
- there are 0 console errors;
- every scripted step completed;
- no P09 meter caption or table cell is truncated (P11’s one caption is noted in §3).

The harness runs the flows end to end:
- make an export with a purpose, then find it in Exports made (`57`);
- close MED-0044 with SAC 4 and see it in the errors report (`86`);
- turn SAC on in Settings, save, then close MED-0049 choosing SAC 1 or 2 (`97`);
- the event log failing on an export and on a close (`60`, `87`);
- run the real builder’s preview over the medication domain (`71`).

Earlier runs found these problems, and this version fixes them:
- **Page overflow at 200 %:** a long list caption can’t wrap. Captions are short now, with the explanation on a line under the table.
- **Truncated meter captions** (“Every dose recorded, some after”, “Twice a day at Kōwhai House”, “Checked 9:12 am · 2 houses”, “MAR, register, round sheet”, …). All shortened.
- **Truncated medicine names** (“Melatonin 3 mg modified-release tablet”). The strength moved to the subline.
- **“Every round that ended was completed” when none had ended.** It now reads “Not applicable — No round has ended yet in this period”.
- **“Each one followed up” was fixed text.** It’s now worked out, and says how many aren’t followed up.
- **A duplicate “What’s in it” row** in the export dialog. Removed.
- **The builder grouped by an unknown “date”.** Each medication source now gives the builder its own date.
- **The shim’s narrow types** failed `tsc` for the shared shell, pagination and the builder. The shim’s parameter types are wider now (deviation 2).

**Keyboard** (`report.json` → `keyboard`): real key events, as Rangi Parata.
- Enter on “Make it” (Doses) opens the export dialog, and Tab cycles inside it.
- Escape closes the dialog, and focus returns to “Make it”.
- The menu key on Aroha’s row opens: Open Aroha’s MAR for this period · Open their unrecorded doses · Make Aroha’s MAR (PDF).

**`tsc`** (`tsconfig.json`): no errors in `src/`. The 1 error in a shared file (`breadcrumbs.tsx:36`) comes from P01’s Inertia shim types.

**ESLint** (the app config, `--no-ignore`, `src/**/*.{ts,tsx}`): 22 files, 0 errors, 0 warnings. A separate check found no unused imports.

## 5. v1.1 — Main’s inspection fix (1 October 2026)

**The fix:** “Medication error reported — MED-0048” was timed 9:00 pm on Mon 28 Sep, after the 9:12 am clock. Every report event now takes P08b’s report time (MED-0048 at 8:40 am).

**The scan:** every fixture timestamp was checked against 9:12 am and against P07b and P08b where the records are shared — dose events, counts, discrepancies, losses, destructions, error reports, exports made, unrecorded doses and SAC closes. Nothing is later than now; the newest event is DS-21 at 8:45 am (`30`, `34`). The details are in the README (“Main’s inspection of v1”). Because the fixtures changed, **the whole harness was re-run**: see §4.

**Recorded:** D2, D4, D5, D7 accepted; D6 → the new `medications.audit.export` (README build note 5).

**Found by the re-run and fixed:** with the P07b-aligned fixtures the audit trail has 14 pages, and 14 page buttons overflowed at 200 %. The preview now sends the windowed link list Laravel’s paginator sends (first and last pages, the pages either side, and “…”). **Harness:** 170 captures, 0 problems.
