# Independent progress-review evidence

Review completed 12 September 2026. These files accompany the [dated review](../../../astra-progress-review-2026-09-12.md).

- head.txt, working-tree-before.txt and gemini-progress-before-review.md / acceptance-before-review.md preserve the starting state and original implementer claims.
- implementation-before.patch / implementation-after.patch cover 87 tracked implementation files and compare equal. Untracked reviewed files have final hashes in reviewed-source-hashes.json. source-drift.json records unrelated concurrent changes.
- governance-suite.txt and build.txt are command streams; successful types.txt is empty. run-summary.json records actual tool exit codes. Probe exit 0 means the script completed, not that the application passed the reproduced cases.
- probe-results.json, extra-probe-results.json and extra-probes.txt retain the observations. The named cases identify service/model probes separately from HTTP requests; browser-observations.md describes actual normal-login UI journeys.
- review-disposition.json, package-check.json and the canonical progress/acceptance files show the current independent outcomes. Earlier Gemini claims remain historical evidence.
- cleanup-check.json confirms both disposable database families and temporary build are gone. The owned preview and browser tab were closed. stop-runtime.flag is deliberately retained as the stop/cleanup marker.

The PHP files are audit reproduction helpers, not application code or a replacement for the required regression suite. review-runtime.php creates its own disposable database through Tests/TestCase.php, uses real board roles and fake communications, and holds that database for browser work until stop-runtime.flag exists. Reusing it requires inspecting the current TestCase isolation, removing ONLY this audit's stop flag before launch, rebuilding separate preview assets, and stopping/cleaning the new owned fixture afterward. runtime-state.json contains stale disposable IDs after cleanup and is not a live connection target. Do not run the generic Playwright setup on the normal database.

update-review-ledgers.ps1 is the one-time document transformation used in this review; do not rerun it against updated Gemini work. It is preserved for transparency, not as a scheduled process.
