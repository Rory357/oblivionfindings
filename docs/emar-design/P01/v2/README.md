# eMAR P01 v2 — Record a dose (all entry points)

**Status: design candidate for inspection by the review session ("Codex eMAR audit re-review"), then Stephan’s exact-version approval.** Not approved, not implemented.

- Version: v2, 30 September 2026 (NZDT). Branch `claude/goofy-noyce-ae9936`. Built on the approved **v1** (`3ac640485`, approval record `f5104956a` in [`../v1/APPROVAL.md`](../v1/APPROVAL.md)); v1 stays frozen.
- Why v2: Stephan approved v1 and asked, “if you find any issues gaps please rectify them. just keep in mind the button sizes” (30 September 2026). v2 draws his approved carry-overs (Q2, Q7, Q8 in part, Q11), fixes the gaps found while doing that, and puts every worker button on one 44 px size.
- Exact file identity: [`VERSION.txt`](VERSION.txt) (SHA-256 of every source, build and asset file). Approval applies to those hashes only.
- Design only: no application code, routes, schema, seeders, configuration, DESIGN.md or design_styles changed. Nothing here calls an application API. Two proposed build changes are *previewed* in `src/styles.css` and labelled there (see “Button sizes”).
- Everything not listed under “What changed from v1” is v1 as approved: see [`../v1/README.md`](../v1/README.md) §1–§4 for the recording contract, the dialog, the states and the My Day, rostered-task and My Calendar design.

## Open it

```
node docs/emar-design/P01/v2/serve.mjs
```

Then open http://127.0.0.1:4384/ (4381 is v1, 4371 is P11, 4382 is P02, 4383 is P07a). The Code tab’s Browser pane can open it too (`emar-p01-v2` in `.claude/launch.json`). The hatched bar is the **mockup viewer, not product UI**: *Signed in as*, *Scenario* (now 20: “My Day doesn’t show someone” is new) and *Allergy rule*. The synthetic clock is fixed at **Monday 28 September 2026, 9:12 am NZDT**. The contract page is `#/p01/contract`.

To rebuild: `npm ci` in this worktree, then `node node_modules/vite/bin/vite.js build --config docs/emar-design/P01/v2/vite.config.mjs`. Evidence: `node docs/emar-design/P01/v2/tools/verify.mjs` (writes `screenshots/` and `screenshots/report.json`).

## What changed from v1

### Stephan’s approved answers, now drawn

1. **Q2 — a medication rule needs a second person and nobody eligible is on shift** (scenario *Alone on shift*, Aroha’s insulin): “given” is no longer stopped.
   - The notice reads “Nobody else on shift can confirm this dose”. It says, from the roster, that nobody else has current competency and a witness PIN, and what happens next.
   - Review & sign shows “Second person: Not confirmed by a second person · Jordan Tipene (house lead) gets a follow-up”.
   - The saved record, and the dose row everyone rostered sees, carry “Not confirmed by a second person — nobody else on shift (from the roster) · follow-up for the house lead”.
   - The house lead gets “Not confirmed by a second person — Aroha” in their All Tasks, due by the end of the next shift. It goes through the same path as v1’s partial dose not confirmed, as Stephan asked (“as for a partial dose”).
   - Controlled drugs are unchanged: no eligible witness still leads to “Ask a manager for a witness override”.
   - *Gap fixed at the same time:* v1’s notice said only refusal, withhold or absence could be recorded, but its validation didn’t actually stop “given”. The words and the behaviour now agree.
2. **Q11 — a real allergy match is always the critical surface:**
   - Mele’s amoxicillin in Warn mode now shows the red “Possible allergy match — check before giving” card in the dialog, and a red line on the row.
   - The wording and the Warn behaviour are unchanged (“given” can still be recorded).
3. **Q7 — My Day pointer** (scenario *My Day doesn’t show someone*, which hides Grace):
   - My Day’s Medicines card shows “You have medication work for people not shown here — open Meds today” above Open meds.
   - My Day’s privacy rule is kept throughout: Grace isn’t named or counted anywhere on My Day. That covers the residents strip, the header meter, the card’s counts, the due-or-late list, the worker’s follow-ups, “Were you there?” items, and the rostered tasks’ people and counts.
   - A rostered task with hidden work adds “more in Meds today”.
   - Meds today is unchanged: it stays roster-scoped.
4. **Q8 — `DateTimeField` `clearable={false}` on required times:** previewed. Every time in the recording dialog is required, so the 28 px “Clear date and time” link no longer appears there. The `entity-menu` disabled item and the `WizardShell` rail at 200 % stay build items, as approved.

### Other gaps found and fixed

5. **Order changed mid-round showed the old amount.**
   - v1’s banner said the new order is 750 mg (1½ tablets), but the dialog, the rows and the MAR still used 500 mg (1 tablet). In that scenario the new order now drives everything:
     - the medicine card and amount read “1½ tablets (750 mg)”;
     - the rows read “1½ tablets · Morning and evening”;
     - the MAR and dose details match.
   - MAR “Mark given” isn’t offered for a dose whose order changed (“Order changed at 9:05 am — check the new instructions”).
   - The round and the dialog now give the same times: changed at 9:05 am, verified at 9:08 am.
6. **“Who can give it” was typed in, not taken from the roster.**
   - The competency-expired and restricted panels listed Daniel Ahn and Jordan Tipene. But Mere Kahu is also clocked in with current competency.
   - The sentence is now built from the roster: “**Daniel Ahn**, **Mere Kahu** and **Jordan Tipene** (from the roster and who is clocked in)”. If nobody is available, it tells the worker to contact the coordinator on call.
7. **Amounts such as 1½** now read “1½ tablets” everywhere (v1 would have shown “1.5 tablets” on rows and the MAR).
8. **Follow-ups carry the person they’re about,** so My Day can hide them under its privacy rule.
9. **The house lead’s task titles lost the person’s name.** At 1440, v1’s All Tasks row read “Check a partial dose not confirmed by a second…”, which cut off the name. It is now “Partial dose not confirmed — Aroha”, and the new one is “Not confirmed by a second person — Aroha”. The subline still gives the medicine, time and who recorded it.

### Button sizes

Stephan: “just keep in mind the button sizes”.

- **v1 mixed three sizes.** At the app’s 14 px base font, `size="sm"` buttons were 28 px, default buttons 31.5 px, and buttons with the app’s `.frontline-tap` 38.5 px.
- **v2 uses one frontline size.** Every button a worker presses in P01 is the default Button with `.frontline-tap`, including:
  - dialog footers and row Record buttons;
  - the amount stepper (icon buttons);
  - “Record a different amount” and “Prescriber asked for a different dose?” (now outline buttons, not text links);
  - Select triggers;
  - My Day’s count chips;
  - the round walker, the success pane and the witness-override screens.
- **Found while doing it — `.frontline-tap` isn’t 44 px in the app.**
  - `resources/css/app.css` sets `min-height: 2.75rem` and comments it as 44 px. The app’s root font is 14 px (`--base-font-size`), so it renders at **38.5 px** everywhere it is used (34 files).
  - v2 previews the build fix in `src/styles.css`: `min-height` and `min-width: max(44px, 2.75rem)`. That gives 44 px at the default text size and still grows with a larger text-size preference. See Q-v2-1.
- **The harness now measures it.** `verify.mjs` records every visible Button, Select trigger and date/time picker in the page body or a dialog that is under 44 px, per capture. The result is in `report.json` → `buttonsUnder44`.
- **Shared components that stay small.** They aren’t P01’s to resize, and they are not previewed (Q-v2-2):

  | Component | Where it shows in P01 | Size at 14 px base |
  |---|---|---|
  | `EntityTable` row menu ⋯ (`EntityKebab`, `h-8 w-8`) | Every list row | 28 × 28 px |
  | `Dialog` close ✕ | Every dialog | 28 × 28 px (Cancel and Escape are 44 px / keyboard) |
  | `LaravelPagination` (`size="sm"`) | Activity | 28 px high |
  | `SiteCalendar` source chips | My Calendar | 27 px high |
  | `TierTwoTabs` on the existing MAR and client-profile pages | Reference frames | 35 px high |
  | `ErrorState` “Try again” | Meds today › Couldn’t load | 32 px high |
  | `Command` search field in the colleague and as-needed pickers | Popover pickers | 35 px high (a text field) |

  The PageHeader’s own buttons, filters and view toggle are sized by the PageHeader family, so they are left as the other hubs have them.
- **Exception kept:** the shell’s “Report incident” copies the real app header (`size="sm"`).

## Verification (30 September 2026)

- `tools/verify.mjs` made **197 captures** of the final build:
  - all 91 states at 1440 × 900;
  - the 53 core states also at 1280 × 800 and at 200 % zoom (720 × 450 CSS px at device scale 2).

  All 197 have horizontal overflow 0 and no console errors, and every scripted flow completed (`screenshots/report.json`). The run served the preview on port 4382, so `report.json` records that address. After the review, the port moved to 4384 because 4382 belongs to P02. Only `serve.mjs`, `tools/verify.mjs` and these docs changed; the build and screenshots are the same.
  - New states: 28b–28e (Q2), 38b–38c (the changed order) and 91b (the My Day pointer).
  - The header subline is one line on every P01 page at 1440 and 1280. The two-line sublines are only on the MAR and client-profile reference frames, which are those pages’ existing headers, the same as in v1.
  - No meter caption truncates at 1440 or 1280. At 200 % only the real My Day header’s own captions truncate, as in v1.
- **Tap sizes:** `report.json` → `buttonsUnder44` lists 16 controls, and all of them belong to the shared components in the table above (pagination, calendar source chips, ErrorState’s “Try again”, the picker search fields; the two search fields show with blank text). The run wrote each capture’s list; the combined list was written into `report.json` from those rows, because this run’s script saved the file before combining them (fixed in `tools/verify.mjs`). Every P01 button, Select trigger and date/time picker is 44 px or more at all three sizes.
- **Keyboard** (real key events over CDP):
  - Enter on a row’s Record opens the dialog.
  - Tab stays inside it (✕ → photo → Cancel → Continue → the steps → ✕).
  - Escape closes it, and focus returns to the same Record button.
  - The keyboard menu key on a focused row opens the same menu as ⋯ and right-click.
- `tsc` (the app’s config with the preview’s paths) is clean. ESLint with the app’s config reports 0 errors and 0 warnings on the 18 source files.

## Open questions for Stephan

- **Q-v2-1 — fix `.frontline-tap` app-wide?** It is 38.5 px, not 44 px, because the app’s root font is 14 px.
  - *Recommended:* fix the shared utility at build (`max(44px, 2.75rem)`), as previewed. All 34 files that use it grow by about 5.5 px.
  - *The alternative:* a new eMAR-only class, leaving the other screens as they are.
- **Q-v2-2 — the small shared targets in the table above:** size them for frontline lists in a separate shared-component pass (for example an `EntityTable` kebab size prop, a 44 px dialog close, and a frontline pagination size)? Or leave them? P01 doesn’t depend on it: every one has a 44 px or keyboard alternative on the same screen.

Carried from v1 and unchanged: Q8’s remaining build items (`entity-menu` disabled item, `WizardShell` rail at 200 %), and the earlier-package items D1, D3, D9, D10, D11, D13 and the on-call contact per house (“Not configured”).

## Approval requested

After the review session’s inspection, please approve **eMAR P01 v2** exactly as identified by the hashes in `VERSION.txt`, or list the changes.
