# eMAR P01 v1 — Record a dose (all entry points)

**Status: design candidate for inspection by the review session ("Codex eMAR audit re-review"), then Stephan’s exact-version approval.** Not approved, not implemented.

- Version: v1, 29 September 2026 (NZDT). Branch `claude/goofy-noyce-ae9936`, based on `origin/main` `dab15310e`.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of every source, build and asset file). Approval applies to those hashes only.
- Design only: no application code, routes, schema, seeders, configuration, DESIGN.md or design_styles changed. Nothing here calls an application API.
- Contract: the approved **P00 v5** (`ff3bff860`) states, wording and dialogs, reused and restyled with the real components. Settings are **P11** (linked, not designed). Follow-ups/handover **P08a**, controlled checks **P07a**, the person record **P02**, stock and photo capture **P06** — linked only.
- Scope note from the review session (29 Sep) applied: `RecordAdministrationDialog`, `ClientMedicationTools`, `pages/clients/medical.tsx` and `pages/operations/clients/medical.tsx` are being deleted on `claude/remove-orphaned-med-panels` and are treated as gone. The client profile’s live dialog is `emar-dialog`.

## Build method (Mockup-design-rules-checklist §1)

A Vite + React preview built exactly like the Fleet PKG-02B v13 preview: `vite.config.mjs` aliases `@` to `resources/js` and uses `@tailwindcss/vite`, so every primitive is the app’s real component with the real tokens. Real components used include `PageHeader` + meter blocks + `PageHeaderRail`, `EntityTable` + `entity-menu` + `ListCaption` + cells, `WizardShell` + `ReviewCard` + `WizardSuccessPane`, `Dialog` (laid out as the Fleet Settings `Modal`), `ConfirmDialog`, `StatusBadge`, `DateTimeField` (PKG-01), `Popover` + `Command`, `Select`, `Checkbox`, `EmptyState`, `ErrorState`, `SkeletonTable`, `LaravelPagination`, `FilePreviewDialog`, `Breadcrumbs`, `TierTwoTabs`, the real **My Day header** and **day list** (`MyDayHeader`, `WorkSchedule`), the real **shared calendar** (`SiteCalendar` with the My Calendar sources) and the real **MAR grid** (`MarGrid`).

Two additions, both mockup-only and documented in the files:

- `src/inertia-shim.tsx` stands in for `@inertiajs/react` so components that import `Link`/`router`/`usePage`/`useForm` render without a server (links become hash routes; nothing is posted).
- `src/styles.css` imports `resources/css/app.css` and adds explicit Tailwind `@source` lines (this worktree sits under the parent repo’s ignored `.claude/`, so automatic detection skipped the components — P11 v2 hit the same).

The app shell chrome (ink top bar and sidebar) is reproduced with the shell tokens, as the Fleet preview does, because `AppLayout` needs live Inertia props. It collapses to the icon rail below 1024 px so 200 % zoom has no horizontal scroll.

## Open it

```
node docs/emar-design/P01/v1/serve.mjs
```

Then open http://127.0.0.1:4381/ (port 4371 belongs to the P11 preview). The hatched bar is the **mockup viewer, not product UI**: *Signed in as* (Priya Shah support worker · Daniel Ahn support worker · Jordan Tipene house lead · Rangi Parata provider manager), *Scenario* (19) and *Allergy rule* (Warn — current; Block unless the prescriber confirmed — later). Records made in the preview survive persona switches (so a manager’s approval reaches the worker) and reset on reload. The synthetic clock is fixed at **Monday 28 September 2026, 9:12 am NZDT**, the same moment as P00.

The **contract page** (`#/p01/contract`, “The contract” in the viewer) lists the rules, maps every entry point and links to every state.

To rebuild: `npm ci` in this worktree, then `node node_modules/vite/bin/vite.js build --config docs/emar-design/P01/v1/vite.config.mjs`. Evidence: `node docs/emar-design/P01/v1/tools/verify.mjs` (headless Chrome over the DevTools protocol; writes `screenshots/` and `screenshots/report.json`).

## What P01 decides

### 1. One recording contract (`src/contract.ts`, `src/record-dialog.tsx`)

Every entry point opens **one** WizardShell dialog — *Safety checks → Record outcome → Review & sign* (the P00 step names) — and only the locked “Opened from” context changes.

| Entry point | Today (verified in code) | P01 |
|---|---|---|
| Meds today › Schedule — also the MAR and the `/emar` dashboard (`RecordDoseWizard`) | No photo, instructions, covert or rules; amount fixed to the order; offline silently does nothing | The shared dialog |
| Meds today › As-needed — also PRN records (`PrnWizard`) | Allergy only at review; false success when blocked (fixed in P0) | The shared dialog, as-needed variant |
| Guided round (`GuidedRoundDialog`) | One tick for identity; no time, allergy or co-signer; window always overridden | A round walker on the Rounds view that opens the shared dialog dose by dose (“Next in the round”) — the separate round dialog retires; `/emar/rounds?guided=` keeps working |
| MAR one-click “Mark given” (`dose-context-menu`) | Posts “given” with no safety display for non-CD, non-witness, non-observation doses | Offered **only for simple doses** (list below); otherwise “Record dose” and “Why no one-click Mark given?” |
| Client profile › Record dose (`emar-dialog`) | Its own 9 reasons; no amount; no scheduled time; refuses every offline save | Choose the dose, then the shared dialog; `emar-dialog` retires |
| Fleet transport › Administer (`transport-medication-dialogs`) | Given only; no time, reasons, allergies or observations; prescribed dose as given | The shared dialog with the transport locked and the pack check kept; one record whichever screen makes it. The row action says **Record**, like every other entry point. The transport header is Fleet’s own `FleetCompactHero`, as on the live page |
| My Day (`/my-day/medications/*/administer|refuse|snooze`, unmounted `stream-context-menu`) | Live routes with no screen (NF-14) | **Retired** (recommended, Q1). My Day shows counts and links; recording opens the shared dialog |
| Mobile API (`/api/medications/*`) | Its own validation | Must take the same requirements and outcomes (API contract; no screen — web only, D7) |

**MAR “Mark given” is offered only when all are true:** due now inside today’s window with no outcome; nothing blocks it; your competency is current and not restricted; not controlled, no witness, no rule reading or second person; support “Administer”; a fixed amount; not covert; allergies recorded (or none known) with no match; the pack photo hasn’t changed brand. It records the ordered amount at the current time through the same server check.

### 2. Inside the dialog

- **Identity:** the person’s private photo (or “No photo on file — check identity using: Not configured”), preferred and legal name, house, born, NHI (test), support mode and preferences.
- **Medicine:** the staff photo of the supplied pack with its date and pack (opens in the real `FilePreviewDialog`), “Pack or brand changed — check the label”, “No photo — check the label”, and “Check the label — the picture is a guide only” on every photo.
- **Instructions, support mode, covert plan, and the Medication rules’ readings and second person** (EM-25) — e.g. Aroha’s insulin: blood sugar reading (no range invented) plus a second person by witness PIN.
- **Allergy status** — recorded / no allergies recorded / couldn’t be loaded / none known — and the specific match line. Rule **Warn** (current) shows “Possible allergy match — check before giving”; **Block unless the prescriber confirmed** (later) blocks “given” with the P00 wording.
- **Outcomes by support mode:** Given · Taken with prompting · Took it without a prompt · Taken with assistance · Given after re-offer (linked to the refusal, NF-11) · Refused (with a follow-up owner and time) · Withheld (reason) · Away (where). **A refusal or withhold can always be recorded while a safety block is active** (NF-06).
- **Time given** with the approved DateTimeField; never in the future; outside today’s window (30 min before to 60 min after — config kept, D4) needs a reason; **no “Give now if safe”** (EM-24). Early doses aren’t recordable until the window opens.
- **Amount given (Stephan-approved):** prefilled as ordered with the unit locked; a variable order is chosen, never defaulted; “Record a different amount” (less: reason plus a second person if one is available, otherwise “Not confirmed by a second person” and a house-lead follow-up — never blocks); “More than ordered was given” only as “this already happened”, creating the medication error and one linked incident; “Prescriber asked for a different dose?” as a short in-dialog step (prescriber, time, read-back) with a lead countersign pending by the end of the next day.
- **Second person by 6-digit witness PIN** (searchable picker of colleagues clocked in now, with why each is or isn’t eligible). Wrong PIN: “4 tries left before … locks for 15 minutes”; locked: unlocks at 9:27 am or a house or clinical lead resets it. **Forgotten-PIN fallback** (not for controlled drugs): the dose is marked “second person not verified”, the named colleague answers “I was there / I wasn’t there” within 30 minutes, and “I wasn’t there” or no answer gives the house lead a follow-up.
- **Controlled drug with no eligible witness:** roster evidence, “Ask a manager for a witness override”, and the manager’s **one-screen approval** (roster, house, person, medicine, start and end, reason; decline with a reason the worker sees). Doses under it are marked “No witness — override by …” with a house-lead follow-up.
- **Every blocked reason names why and the next step** (NF-07): not clocked in, not on this person’s shift, house outside your access, competency expired or restricted (showing who on shift can give it), order awaiting verification, covert authorisation missing, no eligible witness, allergy not confirmed, as-needed limit reached.

### 3. States

Sending (row and dialog), Recorded (success pane with “Next due”), Not recorded (values kept), Not confirmed (check the chart; trying again won’t duplicate), Already recorded (nothing new saved), Saved on this device (offline — Stephan D7), offline item refused when sent (app-wide banner), order changed mid-round, loading, no work left, couldn’t load, out of date, no access (page) vs not found (record), validation that keeps values and focuses the first error, the discard guard, and focus return to the trigger (or the same row’s new action).

### 4. My Day, rostered tasks and My Calendar

- **My Day** is the real screen: the real header (its “Meds today” meter now uses the same counts as Meds today) and the real day list. The new **Medicines card** shows Due now · Late · Needs help (the Meds today numbers, each opening Meds today; “Recorded” lives once, in My Day’s header meter), the doses due or late now, the worker’s own follow-ups, any “Were you there?” answer, and Open meds / Open follow-ups — no separate “mark as given” path.
- **Rostered tasks:** one task per dose time per house for everyone rostered on a covering shift; it completes by itself when every dose has an outcome; a lead can assign it to one person (P00 v4/v5). Shown in My Day’s list and as the All Tasks provider rows (All Tasks keeps its own design).
- **My Calendar:** the real shared calendar; only the Meds source changes, to the medication slots on your rostered shifts, opening Meds today.

## Review fixes before Stephan (29 September 2026)

The review session inspected `e0a9600ce` and asked for five fixes, all made in this version:

1. My Day no longer repeats a number: the Medicines card drops “Recorded” (Q10 closed by the rule).
2. Transport: the two link tiles dressed as meters are gone; the header is Fleet’s real `FleetCompactHero` as on the live page; the links are buttons in the Medicines section; no two-line subline or repeated count.
3. Transport row action says “Record” (pack check kept); the Fleet wording question is in Q9.
4. Meds today subline is one line at 1440 and 1280: “Mon 28 Sep 2026 · Kōwhai House · shift 7:00 am–3:00 pm” (the day stays, per P00 v4). At 1280 the header’s action cluster leaves 332 px, so the time zone moved to the header’s filter row, where “Updated 9:12 am NZDT” shows on every view. Three other captions that truncated at 1280 were shortened (Due now “3 people · by 10:00 am”, Follow-ups “Oldest 11:30 pm Sunday”, My eligibility “To 14 Mar 2027”).
5. The Late meter caption is “Oldest due 8:00 am”.

Also found while re-checking: the top bar’s full date touched the search at 1280, so the shell replica now follows the shell guide’s date rule (full date from 1320 px, “Mon 28 Sep” from 1140 px). The harness now records each header’s subline line count and any truncated meter caption.

Added to the questions: DateTimeField’s always-on “Clear date and time” (Q8) and the allergy tones (Q11).

## Verification (29 September 2026)

- `tools/verify.mjs`: **182 captures** — all 84 states at 1440 × 900 and the 49 core states also at 1280 × 800 and 200 % zoom (720 × 450 CSS px at device scale 2). **Horizontal overflow 0 and no console errors on all 182**; every scripted flow completed (`screenshots/report.json`).
- Keyboard (real key events over CDP): Enter on a row’s Record opens the dialog; Tab is trapped inside it; Escape closes it and focus returns to the same Record button; the keyboard menu key on a focused row opens the same menu as ⋯ and right-click. (Headless Chrome doesn’t generate the context-menu event from Shift+F10; the handler treats any keyboard-origin context menu the same way.)
- In the browser pane at 1440: record → recorded → Done → focus on the row; validation; witness picker and wrong PIN; no-witness → request → manager approves → the dose records under the override; MAR “Mark given” and the “why not” item; My Day as Priya and as Daniel (the “Were you there?” item); lead’s All Tasks follow-up; My Calendar; client profile; transport.
- `tsc` and the app’s ESLint config: clean for `src/`.

## Open questions for Stephan (by decision)

1. **NF-14 — My Day routes:** retire `/my-day/medications/*/administer|refuse|snooze` and the unmounted stream menu (recommended), or align them to the contract? Snooze is per-user and doesn’t carry over at handover.
2. **D8 / D12 — a medication rule needs a second person and nobody eligible is on shift** (e.g. insulin at Kōwhai House when alone): v1 follows the rule and blocks “given” with the coordinator on call. Should it instead record “Not confirmed by a second person” with a house-lead follow-up, as for a partial dose?
3. **D4 — timing:** confirm the outside-the-window reasons (person out or asleep · waiting for a second person · staff supporting someone else · other), that early doses aren’t recordable before the window opens, and that refusal follow-up and as-needed effect-check times are entered by the worker until the clinical lead sets rules.
4. **D5 / NotGivenReason:** “Safety concern — not safe to give” maps to the existing `withheld` value with the note. Add a dedicated value instead?
5. **D6 — support vocabulary:** on a Prompt medicine, is “Took it without a prompt” the right outcome for self-management that day?
6. **P01 — MAR “Mark given” eligibility:** confirm the list in §1.
7. **NF-26 — My Day privacy:** v1 keeps My Day’s rule (only people the worker may view). Show the pointer “You have medication work for people not shown here — open Meds today” when that applies?
8. **Shared components (no edit made):** `entity-menu`’s `MenuItem` has no disabled state, so the P00 “disabled item with its reason” can’t be shown; v1 omits the item and adds “Why can’t I record this?” / “Why no one-click Mark given?”. `WizardShell` keeps its 248 px rail above 640 CSS px, so at 200 % the dialog body is narrower. `DateTimeField` always shows “Clear date and time” once it has a value, including on the required Given time (`date-time-field.tsx` has no prop to hide it) — proposed: a `clearable={false}` prop at build. Change any of these later?
9. **Fleet owner — transport parity:** confirm transport doses record against the scheduled slot, with refusal and withhold available on the trip. v1 labels the row action “Record”, like every other entry point; should Fleet keep its word “Administer”?
10. ~~My Day card count~~ — **closed by the standing rule** (checklist B, “a number lives once”): Recorded is dropped from the card; it stays in My Day’s header meter.
11. **Allergy tones (P00 v5 approved them):** the recorded-allergy list is the solid critical banner, but a real health-profile match in Warn mode is the amber card — so a list with no match is louder than an actual match. Should a match always use the critical surface, even in Warn mode? (Not changed in v1.)

Still open from earlier packages (not changed here): D1, D3 (insulin and the unsupervised flag), D9, D10, D11, D13, and the on-call contact per house (shown as “Not configured”).

## Approval requested

After the review session’s inspection, please approve **eMAR P01 v1** exactly as identified by the hashes in `VERSION.txt`, or list the changes for a v2.
