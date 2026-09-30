# eMAR P01 v2 — approval record

## What was approved

- **Package:** P01 “Record a dose (all entry points)”, version **v2**.
- **Exact version:** commit `d96e29a52` on branch `claude/goofy-noyce-ae9936`. The approved files are the 47 listed in [`VERSION.txt`](VERSION.txt); VERSION.txt itself has SHA-256 `fc8f7af0e137b615d8748f912dfb623ae1b08136636e33466743f394e6a5132b`. This file sits beside them and is not part of the approved design.
- **Approved by:** Stephan, 30 September 2026 (NZDT), in the review session (“Codex eMAR audit re-review”). He wrote “approve”. On the approvals page the item read “P01 v2: approve, both as recommended”. The review session relayed this to the design session the same day.
- **Why a v2:** after approving v1 (`3ac640485`, see [`../v1/APPROVAL.md`](../v1/APPROVAL.md)), Stephan told the design session: “approved if you find any issues gaps please recitify them. just keep in mind the button sizes”.
- **Before approval:** the review session inspected `09692fbdf` and passed it, confirming:
  - `sha256sum -c`: 47 OK;
  - Q2, Q7 and Q8 as drawn;
  - the `.frontline-tap` measurement on origin/main.

  Its one change, moving the preview port from 4382 (P02's) to 4384, is `d96e29a52`. It needed no re-inspection.

**v2 is frozen.** Any further change goes in `P01/v3/` and needs its own approval. v2 replaces v1 as the version the build follows. v1’s answers table still applies where v2 doesn’t change it.

## Stephan’s answers

| # | Question | Answer |
|---|---|---|
| Q-v2-1 | `.frontline-tap` renders at 38.5 px, not 44 px, because the app’s root font is 14 px | **Yes — fix it app-wide** with `min-height` and `min-width: max(44px, 2.75rem)`, as previewed in `src/styles.css`. All 34 files that use it grow to 44 px. |
| Q-v2-2 | Shared targets still under 44 px: `EntityTable` ⋯, `Dialog` ✕, `LaravelPagination`, `SiteCalendar` source chips, `ErrorState` “Try again”, `Command` search field | **Yes — resize them in a separate shared-component pass.** |

The review session is raising both as a separate fix task. A session to fix `.frontline-tap` was also started from this design session’s suggestion.

## What the build follows

- **The design:** v2 view-for-view, walked beside the build at 1440 px before calling it done. That is v1 as approved, plus v2’s changes, which are listed in [`README.md`](README.md) under “What changed from v1”:
  - Q2, Q7, Q8 (DateTimeField) and Q11 drawn;
  - the order-change amount;
  - “who can give it” taken from the roster;
  - house-lead task titles;
  - ½ amounts;
  - follow-ups carrying their person;
  - one 44 px frontline button size.
- **Build changes previewed in the mockup** (`src/styles.css`, not app code):
  - the `.frontline-tap` fix (Q-v2-1), to be done by the separate fix task;
  - `DateTimeField` `clearable={false}` on required times (Q8).
- **Still build items from v1’s Q8:**
  - an `entity-menu` disabled item with its reason;
  - the `WizardShell` rail behaviour at 200 %.
- **Package order:** P01 is built after PIN-1 (the witness PIN) and the first Settings (P11) build step. The forgotten-PIN fallback, “I was there / I wasn’t there” and the witness-override requests are PIN-2, built alongside P01/P08a.
- **Retiring code and the mobile API:** as in v1’s approval record.
