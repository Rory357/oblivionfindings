# P10 v1 — Mockup design-rules checklist (self-check with evidence)

The checklist is `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026). I checked it together with:
- the findings earlier packages had to fix (`Mockup-session-brief.md` §5);
- Main’s P03/P04 fixes: explanations in neutral text, colour on the badge only;
- Main’s P07b fixes: empty states worded per section, and no success tone on a loss or a failure;
- Main’s P08b instructions: the full row pattern, the approved date/time pickers, harm tones by severity;
- Main’s P09 instructions and v1.1 fix: every fixture timestamp at or before 9:12 am and consistent with the shared records;
- Main’s P10 instructions: the amber strip at 10 minutes, nothing green that is a risk, empty states worded per section, the contract page listing every state, personas identical to earlier packages.

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records horizontal overflow, console errors, whether the scripted steps completed, the header subline’s line count, and truncated meter captions and table cells. Numbers in brackets, such as (`30`), are screenshot states. Source paths are relative to `src/`.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P10’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| A Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `serve.mjs` and `tsconfig.json` are P09 v1.1’s, with port 4395. |
| Real primitives, never hand-rolled | Pass | All real: the `PageHeader` family and `PageHeaderRail`; `EntityTable`, `ListCaption`, `PersonDisc`, `EntityChip`, `EntityContextMenu`, `compactMenu`; **`WizardShell`**, `WizardStepPane`, `WizardSuccessPane`, `ReviewCard`/`ReviewRow`; the Fleet Settings `Modal` and Fleet’s `Modal`, `Notice` and `Sections` in the P11 frame; `ConfirmDialog` (default for “I’m done”, discard and finish; destructive for ending someone else’s); **PIN-1’s `WitnessPinInput`**; the approved **`DateTimeField`** (PKG-01); **`FileDropzone`**; `StatusBadge`, `EmptyState`, `ErrorState`, `SkeletonTable`; `Select`, `Input`, `Textarea`, `Checkbox`, `Segmented` (P11’s Choice); `TilePicker`; `Table`, `Card`, `Alert`, `Breadcrumbs`, `TierTwoTabs`. |
| Synthetic data only; no application API; a fixed clock in Pacific/Auckland | Pass | `data.ts` (P09 v1.1’s fixtures plus P10’s grants, flag, on-call contacts and downtime); `clock.ts` fixes Monday 28 September 2026, 9:12 am NZDT. `serve.mjs` answers only GET and HEAD. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P10/v1/**`. The Inertia shim’s `Link` `href` is optional, as Inertia’s own (a shim, inside `P10/v1/src`). |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | Emergency access (`01`) replaces today’s PageHero page; Downtime & paper records (`91`); the frames (`70`, `85`, `110`, `120`) are the approved packages’ headers. |
| A plain title, one StatusBadge chip, a one-line fact subline | Pass (note) | `01`, `04`, `91`: one line at every size. The P11 frame’s subline wraps at 1280, as in P09’s approved frame (deviation 10). |
| No greetings and no LIVE or refreshed eyebrows | Pass | Today’s “Append-only audit · retained”, “Auto-revoke on” and the ring countdown are gone. The as-at time is a filter chip; out of date is a notice with “Try again” (`13`). |
| One meter row of 4–6 blocks, each linked, real data, “—” or “Unavailable” | Pass | Emergency access: Running now · To review · Repeat use · This month, each opening its view (`01`); Downtime: To enter · To confirm · Downtimes (`91`). Loading “—” (`11`); couldn’t load: the ErrorState (`12`). |
| Tones by meaning: amber for ending soon and repeat use, red only for overdue | Pass | Running now turns amber within 10 minutes (`08`); To review turns red only when one is overdue (`04`); Repeat use amber (`01`). |

### B. Lists

| Item | Result | Evidence |
|---|---|---|
| `EntityTable` with the full row pattern — ⋯, right-click and the menu key open the same menu; the row opens | Pass | `02` (right-click), the keyboard walk (§4, the menu key), ⋯ on every list. The row opens the grant — or its review, when you can review it. |
| Unavailable actions are left out of the menu, never shown and refused | Pass | Rangi’s own grants have no “Review it” (`05`); the auditor’s menu has no “End their access” (`03` shows Hana’s). A deep link says why (`45`, `46`, `60`, `63`). |
| A `ListCaption` before every list, short enough at 200 % | Pass | “Running now · 1 · each covers one person”, “To review · 2 · due 2 days after each ends”, “Every grant · 6 shown · newest first”, “Paper records · 3 · 3 to enter”. |
| Empty states worded per section | Pass | “No one is using emergency access right now” — with the start route for holders, “you’re told” for reviewers (`10`); “Nothing to review — Every grant that has ended has been reviewed”; “Emergency access hasn’t been used yet”; “No downtime recorded at Kōwhai House” with what to do (`102`). |
| The person rule, no hidden-row counts; controlled concealment | Pass | Grants at a house you can’t see don’t appear and aren’t counted; a controlled medicine without controlled view reads “Controlled medicine” (paper records, the pack’s first page). |
| Badges: nothing green that is a risk | Pass | Running (info; warning within 10 minutes) · To review (neutral) · Review overdue (critical) · Justified (success — the grant was needed) · **Not justified (warning)** · Acknowledged (neutral). Paper records: To enter (warning) · Waiting to confirm (info) · Witness to confirm (warning) · Entered from paper (neutral). |

### C. Dialogs and wizards

| Item | Result | Evidence |
|---|---|---|
| The real WizardShell for starting it, sequential, with the discard guard | Pass | `21`–`29b`; discard (`35`, non-destructive ConfirmDialog). |
| The Fleet `Modal` for single-purpose dialogs | Pass | The grant (`40`), review (`42`), correction (`47`), extend (`50`), end (`56`), acknowledge (`61`), record frame (`74`), pack (`115`), declare (`100`), paper entry (`93`). |
| Validation keeps the values and focuses the first error | Pass | `23`, `28`, `43`, `51`, `57`, `94`, `95`, `101`. |
| Approved pickers | Pass | `DateTimeField` (Pacific/Auckland) for the given-at time, the downtime’s start and end, and the time on the paper; `Select` for people; `TilePicker` for outcomes, reasons, people and purposes; P11’s `Choice` for lengths and settings. |
| Destructive tone only where it ends something someone else holds | Pass | “End their access” (destructive button and ConfirmDialog, `58`); “I’m done”, discard and finish use the default tone (`54`). |
| Offline, “Couldn’t save — try again”, and nothing lost | Pass | Start offline (`33`), event log down while starting (`36`) and reviewing (`49`); the pack offline (`117`); a controlled dose offline, and a dose saved on the device (`81`). |

### D. States (Main’s P10 row: grant expired mid-task; revoked; review justified / not justified; misuse flag)

| State | Result | Evidence |
|---|---|---|
| Grant expired mid-task | Pass | The chart (`78`), the record dialog keeping the entry (`76`), Start it again with the draft kept (`77`), and To review (`04`, scenario “ran out”). |
| Revoked — ended by someone else | Pass | EA-8 (`41`); ending Rangi’s access (`56`–`59`). |
| Review justified / not justified; corrected | Pass | `42`–`44`, `41`, `47`, `48`. |
| Misuse flag | Pass | `01` (meter), `07`, `61`, `62`, `63`. |
| Second person required, nobody here; no on-call contact | Pass | `30`, `31`. |
| Not told about emergency access | Pass | `09` (the page), `72` (the chart), `80` (a start link). |
| The downtime pack with and without controlled pages | Pass | `115`, `116`. |
| Paper records: own, for someone else, adding, finishing | Pass | `92`–`99`, `104`. |
| Loading · empty · couldn’t load · out of date · offline · event log down | Pass | `11`, `10`, `12`, `13`, `33`, `85`, `36`, `49`. |

### E. Copy

| Item | Result | Evidence |
|---|---|---|
| No codes or permission keys in product copy | Pass | Keys and package codes appear only in design notes and the contract page. |
| No authority claims nothing checks | Pass | No “RN+”, “Registered Nurse”, “48 hours”, “Auto-revoke” or “Append-only”. The scope is said plainly: “This covers Aroha only — not a round or anyone else.” |
| Plain explanations, neutral text; colour on the badge only | Pass | Blocked states name the real route and the on-call contact (`71`, `72`). |
| Hide unbuilt | Pass | Nothing reads “coming soon”; P09’s Standard reports and builder, P02’s other views and P11’s other sections are link-only, and say so. |

### F. Keyboard and zoom

| Item | Result | Evidence |
|---|---|---|
| Enter opens, Tab stays inside, Escape closes and returns focus | See §4 | The keyboard walk from Mele’s chart. |
| 200 % zoom without horizontal overflow | See §4 | Every core state at 720×450 CSS px. |

## 3. Notes

- **Fixtures:** every timestamp is at or before 9:12 am today. The shared records are P09 v1.1’s, unchanged: EA-9 is P09’s E-bg-1; the downtime is P09’s F-15; Aroha’s 8:00 am metformin and every other dose come from P09’s event log; P10’s only new dose is Rangi’s losartan at 9:02 am under EA-13 (P09 leaves today’s 9:00 am slots unrecorded until their window ends). The daily report covers Sunday 27 September.
- **The P11 frame’s header** caption truncates at 1280 and 200 %, as in P09’s approved frame (deviation 10).

## 4. Harness results

**The run:** `tools/verify.mjs` against its own read-only server on port 4495 — **273 captures**: all 111 states at 1440 × 900, and the 81 core states also at 1280 × 800 and at 200 % zoom (720 × 450 CSS px, device scale 2).

| Measure | Result |
|---|---|
| Horizontal overflow | 0 in every capture |
| Console errors | 0 |
| Scripted steps | All completed — including the full wizard to “started”, both review paths, the correction, extend, “I’m done”, ending someone else’s (reason and destructive confirm), acknowledging, recording under the grant and after it ran out (“Start it again” keeps the draft), the pack (with and without controlled pages, and offline), entering paper records (own, for Ana, outside the downtime), recording a downtime, and saving P10’s settings |
| Header subline | One line everywhere except the P11 frame at 1280 (P11’s reference header, as in P09’s approved frame) |
| Truncated meter captions | Only P11’s “5 not configured · the rest are defaults” at 1280 and 200 % — P11’s reference header, unchanged (deviation 10) |
| Truncated table cells | 0 (the paper records’ sublines wrap — fixed during the run) |
| Re-run | 11 states after two copy fixes: the grant timeline’s end line read “Ended by Hana Kereama, Thu 17 Sep” (now “Ended by Hana Kereama — “Mere Kahu is on shift…””), and a quoted reason ended “.”.” |

**Keyboard (real key events):** from Mele’s chart, Enter on “Start emergency access” opens the wizard; ten Tabs stay inside it (Close · Cancel · Continue · the first step, repeating); Escape closes it untouched and focus returns to the button. On Running now, the menu key on EA-13’s row opens: Open the grant · I’m done — end it now · Open Aroha’s MAR.
