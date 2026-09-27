# PKG-04 publication candidate

27 September 2026, Pacific/Auckland. Stephan explicitly authorised committing the completed Fleet workspace, merging to local main and publishing GitHub main. This supersedes the historical implementation-only restrictions in the local release notes. Sol remains archived.

## Candidate and reconciliation

- Worktree: `C:/Users/steph/.codex/worktrees/1eb2/oblivionfindings`.
- Branch: `codex/pkg04-fleet-completion`.
- Current-main base: `ba5bff2e8b6c22796369443f1cdac918039950dd`, verified equal to origin/main before integration.
- Implementation commit: `bf373ff97`; merge with current main: `d85adebc0`; compact filter-row correction: `b0ccaa7a9`; reminder privacy regression update: `42375729aa688fcdf4d9683339784873aa466943`.
- The location-service conflict retains both the fleet-wide projection and Main's newer Overview position method. Navigation tests retain Main's formatting and identical exact-name semantics. Appointment Undo, reminder privacy and all other current-main changes remain present.
- The older test expecting a generic foreign-site Maintenance reminder was corrected to Main's stricter hidden-reminder contract. The same test now explicitly checks that the fleet-wide calendar withholds that reminder from central oversight and exposes it to its authorised site manager. No application permission was relaxed.
- The published change includes production source, tests, this report and selected final evidence. Frozen v1-v9 references and temporary preview servers, credentials, database helpers, generated bundles and intermediate/failing logs remain local; they are not production dependencies.

## Verification of the reconciled candidate

- Frontend: **79 tests across 15 files passed**, including Fleet, navigation, shared calendar, canonical record commands and newer Overview tests (`implementation/integration-vitest.log`).
- Backend: **44 tests, 796 assertions passed** across Fleet scope/privacy, Vehicle Calendar and Overview contracts (`implementation/integration-final-phpunit.log`). The initial run found the stale reminder expectation described above; the corrected full rerun passed.
- Backend tests use a fresh process-isolated database and schema-only snapshot of the dedicated synthetic preview (917 tables, 1105 migrations). An evidence-only bootstrap prevents pruning sibling databases and uses the main branch's supported schema timeout setting. Only the preview received main's two existing migrations; no operational database was changed.
- Full TypeScript checking passed. Strict changed-file ESLint passed with zero warnings, including the final compact filter-row class adjustment. PHP syntax and `git diff main HEAD --check` passed.
- Final production build passed in **4m 31s**, with only the existing large-chunk advisory. The row adjustment was made before this build began; subsequent changes were test/evidence only.
- Actual Laravel preview checks passed with zero runtime errors: 1152/1440 layout, map layers/filters/menus/compact hover, inherited criteria clearing, filtered export, Timeline handoff, canonical source returns, Week/Month moves, quarter-hour range preservation, Auckland/DST bounds, protected gestures and reviewed save/Undo. Simulated feeds are limited to the documented stale, protected and Month-overflow cases.
- The synthetic booking was restored to its original times through reviewed Undo; required pending approval remains. The build/preview is served from this worktree at `http://localhost:8767/fleet-assets/vehicles?view=map` (also 127.0.0.1).

Final browser logs are `implementation/integration-browser-{layout,regression,protected,workflows}.log`. Source hashes are `implementation/integration-source-hashes.json`. The updated Map and Week screenshots accompany the candidate. Older audit sections describe historical intermediate results and are superseded by this record for integration checks.

## Remaining boundaries

Main's exact-candidate technical decision is requested before the local-main fast-forward and remote push. Existing dirty Main records must be preserved. This candidate adds no migration, deployment command or operational activation.

GitHub main's pre-existing test run [36290861559](https://github.com/Rory357/oblivionfindings/actions/runs/36290861559), on base `ba5bff2e8`, is failing across the broader repository (including Compliance and Control Room). This predates PKG-04 publication; full CI is not claimed green. Deployment, operational acceptance and large-fleet load verification remain separate from the completed desktop implementation and targeted integration checks.

## Main review corrections

Main requested two bounded corrections to historical candidate `25b1a8f18`. Both are implemented by the same owner; main remains unchanged until the renewed exact-candidate decision.

- **T04-01:** Fleet aggregate IDs are now namespaced by vehicle after private busy-ID redaction. The canonical per-vehicle IDs, record IDs and source links remain intact. The regression creates same-date WoF and check reminders for two vehicles, verifies four unique stable aggregate IDs across refresh, and verifies unchanged individual-source IDs.
- **T04-02:** Pure moves use elapsed canonical duration and resolve the proposed Auckland start before calculating the end instant. Month and timed-grid moves share this conversion; resize retains its separate intent. Timed-grid intent carries the requested wall time even when the browser would normalise a skipped hour. Repeated starts require an explicit first/second occurrence choice; nonexistent starts are rejected. Derived start/end offsets reach the existing reviewed booking wizard, including repeated end times. Manual changes, source versions, reason, authoritative save and reviewed Undo remain under the canonical workflow.
- The reported 2027 autumn example now proposes 01:30–04:30 on 5 April, preserving 180 minutes. The actual corrected callback was executed from and onto both transitions under UTC and Auckland; the helper regression also covers both repeated occurrences and skipped-time denial. These are recorded in `implementation/review-fixes-callback.log` and `implementation/browser/dst-callback-results.json`.
- Updated automated results: **91 frontend tests / 15 files passed; 45 backend tests / 811 assertions passed; full TypeScript clean; strict changed-file ESLint clean; production build passed in 4m 3s**. No additional schema or permission change was made.

The final reviewed UI evidence is recorded in the `review-fixes-*.log` files and `browser/dst-ui-results.json`. Future DST UI dates are explicitly simulated in the real built application; those tests do not save. Separate real canonical save/Undo regression checks the dedicated synthetic booking and restores its original times.

## Final published-Main reconciliation

The earlier candidate and base above are historical. Transport was published while the two review findings were being corrected. The final candidate incorporates published Main `49e0be5b1c1716aeb4e681529bb71fdce2a7abc0` through merge `ca0554a57943bd66d469df1c661d44679c0ba0ae`; both local Main and origin/main were rechecked against that base. No local-only PKG-03 changes were imported.

- The shared calendar keeps Transport's fourth `move`/`resize` callback argument. Fleet's requested Auckland wall-time intent is a separate fifth argument, preserving both consumers. The booking source page retains Transport's source actions and Fleet's return context.
- The combined backend run identified a stale Transport fixture: its allocation request omitted the client ID required by the published booking controller. The fixture now supplies that ID and separately verifies that a missing ID is denied. Foreign-room denial and the full allocation/retry/reassignment workflow remain tested. No application authorization was changed.
- **102 frontend tests across 17 files passed**, including Fleet, Overview, Transport and shared calendar/command consumers (`implementation/final-main-vitest.log`).
- **59 backend tests with 1,430 assertions passed**, including Fleet scope, Vehicle Calendar, Overview and Transport (`implementation/final-main-phpunit-corrected.log`). The dedicated preview received the existing Transport migration; the isolated test snapshot contains 918 tables and 1,106 migration records. No operational database was changed.
- Full TypeScript, strict changed-file ESLint and PHP syntax checks passed. The production build passed in **5m 58s**, with the existing large-chunk advisory. Application source remained unchanged throughout the build and browser checks; the subsequent correction was test-only.
- The actual callback DST probe and final built UI pass from/onto spring and autumn transitions in UTC and Auckland, including repeated-hour choice, explicit offsets and skipped-hour rejection. One full browser run timed out locating the Day fixture; the isolated Day check and complete unchanged-app rerun both passed. The final `browser/dst-ui-results.json` contains all 14 scenarios; `implementation/final-main-dst-ui-rerun.log` records that run.
- Final built-application map/filter/menu/layout, Week/Month move proposals, protected presses, quarter-hour range, Auckland bounds and stale-feed checks passed (`implementation/final-main-browser-regression.log` and `final-main-browser-protected.log`).
- The final canonical save and reviewed Undo passed, restoring the synthetic booking's original times and retaining required pending approval. Source returns, keyboard menus, Day resize/cancel, simulated Month overflow and Agenda/Timeline passed in the same run (`implementation/final-main-browser-workflows.log`, `browser/parity-results.json`). No synthetic operational booking was left moved.
- `implementation/integration-source-hashes.json` records the 36 changed application/test files against this published Main base. Temporary browser probes, preview credentials, runtime setup and intermediate failing logs remain local.

Main's renewed exact-candidate technical decision is required before publication; the superseded candidate's review is not treated as approval. The existing broader CI and deployment/operational-acceptance boundaries above still apply.
