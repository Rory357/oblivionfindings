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
