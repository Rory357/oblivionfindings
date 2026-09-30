# P08a v1 — Mockup design-rules checklist (self-check with evidence)

Checklist: `docs/emar-audit-2026-09-28/claude-second-review/Mockup-design-rules-checklist.md` (29 September 2026), plus the findings earlier packages had to fix (`Mockup-session-brief.md` §5).

Evidence files are in `screenshots/`, named `{1440|1280|zoom200}-{state}.png`. For every capture, `screenshots/report.json` records:
- horizontal overflow;
- console errors;
- whether the scripted steps completed;
- the header subline’s line count;
- truncated meter captions and truncated table cells.

The totals are in §4. Source paths are relative to `src/`. Numbers in brackets, such as (`10`), are screenshot states.

Legend: **Pass** · **Pass (note)** passes with a stated limitation · **N/A** is outside P08a’s scope.

## 1. Build method

| Item | Result | Evidence |
|---|---|---|
| Vite + React preview on the real components: `@` points to `resources/js`, plus `@tailwindcss/vite` | Pass | `vite.config.mjs`, `index.html`, `serve.mjs` and `tsconfig.json` are copied from P07a v1 (itself P01’s). `src/inertia-shim.tsx` is P01 v1’s, unchanged. `src/styles.css` has explicit `@source` lines, because the worktree is under the ignored `.claude/`. |
| Dependencies installed in this worktree | Pass | `npm ci` in `intelligent-antonelli-87bad1`. No junction. |
| Real primitives, never hand-rolled | Pass | These are all the real components:<br>• `PageHeader`, the meter blocks, the donut, `PageHeaderRail`, filter selects, search, status chip (`pages/meds-today.tsx`, `pages/safety.tsx`);<br>• `WizardShell`, `WizardStepPane`, `ReviewCard`, `ReviewRow`, `WizardSuccessPane` (`refusal-dialog.tsx`, `handover-dialogs.tsx`);<br>• `Dialog` in the Fleet Settings `Modal` layout (`modal.tsx`), and `ConfirmDialog`;<br>• `EntityTable`, `ListCaption`, `PersonDisc`, `EntityContextMenu` and `compactMenu` (`rows.tsx`);<br>• `StatusBadge`, `EmptyState`, `ErrorState`, `SkeletonTable`;<br>• `Select`, `Switch`, `Input`, `Textarea`, `Command`;<br>• `DateTimeField` (PKG-01) with the approved time picker;<br>• `Breadcrumbs`, `Card`, `Table`. |
| Synthetic data only; no application API; fixed clock in Pacific/Auckland | Pass | `data.ts`; `clock.ts` (Monday 28 Sep 2026, 9:12 am NZDT). The shim never sends anything, and `serve.mjs` answers GET and HEAD only. |
| No edits to shared components | Pass | `git status` shows only `docs/emar-design/P08a/v1/**`. The `.claude/launch.json` preview entry is gitignored and local. |

## 2. Checklist

### A. Page top

| Item | Result | Evidence |
|---|---|---|
| `PageHeader`, not `PageHero` | Pass | Meds today is P01 v1’s approved header (`01`). Safety & oversight is P11 v5’s (`50`, `70`). It replaces today’s `/emar/handovers` PageHero (AUDIT 3.5). |
| A plain title, one StatusBadge chip, and a one-line fact subline | Pass | “Meds today” with “On shift”. “Safety & oversight” with “1 house” or “2 houses”. `report.json` `header.sublineLines` is 1 on every capture that has a header. |
| No greetings and no LIVE or refreshed eyebrows | Pass | The refresh time is a filter-row chip (“Updated 9:12 am NZDT”). |
| One meter row of 4–6 blocks, each linked, with real data, the graph form, and “n/a” / “Unavailable” | Pass | Meds today keeps P01’s six. Safety › Follow-ups has six, with On time (done on time, last 7 days) as a donut. Handovers has five, or four without controlled-medicine access (`79`). Escalations shows “Off · Not configured” until Delivery is on (`62`). Loading shows skeleton meters (`90`, `97`); couldn’t load shows “Unavailable” (`92`). |
| Search and primary filters inside the header; nothing between the header and the content | Pass (note) | Filters: who, type, house and state, plus the refresh chip. The one thing between the header and the list is the handover notice (`01`, `73`). It is a status notice about this shift, not a filter or KPI, the same as P07a’s override notice. |
| `PageHeaderRail`: one line, 8 views or fewer, overflow to “More”, the Find chip, no shadow | Pass | Meds today has 7 views plus Find. Safety & oversight has 7 plus Find. |
| Breadcrumbs rooted at Home | Pass | Home › Meds today for frontline staff. Home › Medication › Safety & oversight for leads. |

### B. Layout

| Item | Result | Evidence |
|---|---|---|
| Only the shell gutter; `gap-5` between sections | Pass | `shell.tsx` main uses `px-5 pb-5 gap-5`. The views add no outer padding. |
| Full-width body with no `max-w` cap | Pass | All views |
| A number lives once, in the meter row | Pass | No KPI cards. List captions give only “N open” or “N shown”, and the overdue count within that section. |
| Desktop only; checked at 1440, 1280 and 200 %; no horizontal page scroll | Pass | `report.json` shows overflow 0 on every capture. At 200 % the shell uses its icon rail, and wide tables scroll inside their own container. |

### C. Lists

| Item | Result | Evidence |
|---|---|---|
| EntityTable contracts: identity first, kebab last | Pass | Follow-ups in Meds today, Safety & oversight and All Tasks (`rows.tsx`); the Handovers register |
| ⋯ and the same menu on right-click (plus Shift+F10); clicking a row opens it | Pass (note) | One `MenuItem[]` feeds both the kebab and `EntityContextMenu` (`05`). `report.json` → `keyboard` records the menu opened from a focused row. As P01 and P07a recorded, headless Chrome doesn’t synthesise the context-menu event for Shift+F10, so the harness also tries the menu key. Clicking a row opens its detail and history. |
| Server-style pagination | N/A (note) | The lists are bounded: one shift at one house or a lead’s houses, and handovers for the last 24 hours. The build pages them with the real `LaravelPagination` if they grow. |
| Empty, loading and error states use the shared components | Pass | `EmptyState` (`91`, and in empty sections), `SkeletonTable` (`90`, `97`), `ErrorState` (`92`) |

### D. Dialogs

| Item | Result | Evidence |
|---|---|---|
| Add or edit with 2 or more sections uses WizardShell (stepper, “Step x of y”, review, success pane) | Pass | Follow up a refusal (`20`–`27`), the handover (`72`–`76`, non-sequential for reading) and the outgoing draft (`77`, `78`) |
| Simple dialogs follow the shell/body split, width tokens and footer (the Fleet Settings `Modal`) | Pass | `modal.tsx` is P01 v1’s, unchanged. Used for: Did it help? (`10`–`15`), detail and history (`30`, `31`, `34`), reassign (`32`), the as-needed dose (`33`), Were you there? (`40`), sign-off (`51`, `52`), countersign (`53`), heads-up (`61`), not found (`96`) |
| Consequential saves use confirm-dialog and state the effect; destructive actions use the destructive variant | Pass (note) | The refusal review ends with “When you save” (`26`). `ConfirmDialog` is the discard guard. Its destructive confirm renders purple on main until PR #15; this isn’t worked around. |
| Never a browser `prompt` or `confirm` | Pass | None |
| Errors keep values and focus the first error; closing returns focus to the opener | Pass | `11`, `15`, `25` and `52` keep values and focus the first error. Escape returns focus to “Check effect” (`report.json` keyboard `afterEscape`). |

### E. Controls

| Item | Result | Evidence |
|---|---|---|
| On/off settings use Switch | Pass | “Someone else needs to know” (`13`); something else offered, GP told, whānau told (`24`) |
| Growing lists use a searchable picker | Pass | Reassign uses `Command` over people rostered now (`32`) |
| Uploads use FileDropzone + StagedFileCard | N/A | No uploads |
| Dates and times use the PKG-01 DateTimeField, with the time zone visible | Pass | “Check again at” and “Try again at”, with the Pacific/Auckland legend and the approved time picker (`15`, `15b`, `21`) |
| Buttons aren’t restyled | Pass | Only `Button` variants and the PageHeader button family. `className` carries only `frontline-tap` and spacing. Jump links use `variant="link"` with a lucide `ArrowUpRight`. |

### F. Tokens, status and accessibility

| Item | Result | Evidence |
|---|---|---|
| Semantic tokens only: no raw palette, hex or `dark:` pairs | Pass | ESLint (the app config) on `src/**`: see §4 |
| Every status uses StatusBadge; safety uses the fixed pairs | Pass | `FuBadge` is `StatusBadge`: overdue (critical), due (info), couldn’t check (warning), waiting (neutral), done (success), done late (success, with a warning line), saved on this device (warning). |
| Colour is never the only signal | Pass | Every state has a label and an icon, and the overdue time is written out (“9 h 42 min overdue”). |
| lucide icons only; icon-only buttons have an `aria-label` | Pass | The header glass buttons, the bell and messages. The emoji-like “↗” was replaced by lucide `ArrowUpRight`. |
| Visible focus ring; 44 px tap targets on frontline actions | Pass | `frontline-tap` on row actions, tiles and dialog actions |

### G. Language

| Item | Result | Evidence |
|---|---|---|
| NZ English, sentence case, plain words; no codes as titles, raw enums or developer words | Pass | Types have plain names (“As-needed effect check”, “Refusal follow-up”, “Were you there?”). Package codes appear only in design notes, toasts naming another package, the viewer bar and the contract page. They were removed from dialog copy (the countersign caption and the refusal success pane). |
| “Not configured”, “Not available” or “Unknown” instead of fake zeros | Pass | Reminders and escalation show “Off · Not configured” (`30`, `50`). The on-call contact shows `NotConfigured` (`13`). Meters show “Unavailable” (`92`). |
| Every blocked action says why and who can unblock it | Pass | “For the house lead — house leads and clinical leads close it” (`54`); “Only Daniel Ahn can answer” (`42`); off-shift staff aren’t offered in reassign, and the dialog says why (`32`); the end-of-shift limit on “check again at” (`15`). |
| No invented clinical values or regulator names | Pass | Doses and amounts are synthetic ORDER data. The rules are Main’s answers and approved packages. |

### H. Honesty

| Item | Result | Evidence |
|---|---|---|
| No dead or decorative actions or meters; unbuilt things are hidden | Pass (note) | Actions owned by other packages say so in a toast (“… — outside this preview”): recording a dose (P01), the person record (P02), stock (P06), controlled checks (P07a), errors (P08b). That is the mockup’s boundary, not product behaviour. The other views are link-only cards naming their package. |
| Synthetic data clearly labelled | Pass | The hatched viewer bar reads “mockup viewer — not product UI · Synthetic data”. |
| Views approved earlier are reused unchanged unless an approved change says otherwise | Pass | P01’s header, `Modal`, offline banner and “Were you there?” (`40`). P11 v5’s Safety & oversight header and rail. P07a’s override follow-up is opened by its own dialog (`55`). |

## 3. Brief §5 findings, checked first

| Finding | Result |
|---|---|
| A number lives once | Pass: no KPI cards |
| One-line subline; no truncated meter captions | Pass: `report.json` |
| No link tiles dressed as meters | Pass |
| No codes in product copy | Pass (see G) |
| Real button variants only; `link` for ↗ jumps | Pass: `variant="link"` with a lucide `ArrowUpRight` (`72`) |
| Honest states | Pass |
| Controlled-drug concealment everywhere, shown with a persona | Pass: Hana Kereama (`65`, `79`, `82`). No override follow-up in lists, meters, tasks or the handover lens; no Controlled counts meter. A direct link shows “We can’t show this record”. |
| House scope: no access (page) is distinct from not found (record) | Pass: Safety & oversight as a support worker (`95`) vs a Rimu House follow-up for Priya (`96`). The manager sees both houses (`60`, `71`). |
| Loosening a safety setting is destructive | N/A: P08a has no settings. Delivery is P11’s. |
| Destructive ConfirmDialog renders purple on main | Noted; not worked around |
| Reference frames match origin/main | Pass: `origin/main` `d9dc17fa5`. P01’s header is used as approved. `/emar/handovers` and `/emar/prn` were read live (AUDIT §5) and aren’t copied. |
| Every claim about today’s code has `file:line`, marked verified or reported | Pass: AUDIT.md |
| No invented clinical values or regulator names | Pass |

## 4. Verification (30 September 2026, final build)

**Harness** (`tools/verify.mjs`): **149 captures**. That is all 65 states at 1440 × 900, and the 42 core states again at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2). Across all 149:
- horizontal overflow 0;
- console errors 0;
- every scripted step completed;
- the header subline is one line;
- no meter caption is truncated;
- no table cell is truncated (`[role=row] .truncate`).

The first run found clipped identity cells: “Several people”, house names at 1280, and handover names. It also found one clipped meter caption at 200 %. These were fixed by:
- a 160 px identity column, with the house as the identity for house-level rows;
- a separate House column in the Handovers register;
- a shorter caption.

Review of that run’s screenshots also led to:
- tables sized to fit at 1280;
- wording fixes (no package codes or decision references in dialog copy, no repeated names, “you, once you acknowledge”);
- the refusal review’s “When you save”;
- the owner-set time following the acknowledgement.

**Keyboard** (real key events over CDP, `report.json` → `keyboard`):
- Enter on Tama’s overdue “Check effect” opens “Did it help?”.
- Tab cycles inside the dialog: the outcome tiles → Cancel → Record effect → Close → the tiles again.
- Escape closes the unedited dialog and returns focus to “Check effect”.
- The keyboard menu key on a focused row opens the same menu as ⋯ and right-click: Check effect · View details and history · Reassign · View the as-needed dose · Open Tama’s medication record.

**`tsc`** (`tsconfig.json` in this folder): 0 errors in `docs/emar-design/P08a/**`. It reports 1 error in a shared file, `components/breadcrumbs.tsx:36`: a type mismatch against the mockup-only Inertia shim, which is copied unchanged from P01 v1. The shared file is unchanged.

**ESLint** (the app’s config, `--no-ignore`) on `src/**`: 19 files, 0 errors, 0 warnings.

**Live references** (brief step 3), read on oblivionfindings.test as Demo Admin: `/emar/handovers` and `/emar/prn` (AUDIT §5).

**Load rules:** every heavy command (`tsc`, ESLint, `vite build`, the harness) started only when a `.ps1` check showed free RAM over 4 GB and at most one other Pest run. A guard stopped only this session’s harness processes (node and Chrome) if free RAM fell below 3 GB. It fired once while a queued run was still waiting (2.6 GB free), and the run was queued again. One early `tsc` started while two Pest runs were going, because that check didn’t gate the command; the check was fixed straight away.
