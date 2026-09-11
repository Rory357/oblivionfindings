# W09 final merge wizard keyboard recheck — Build32

10 September 2026. This focused scroll/focus correction is implemented and browser verified. Whole W09 remains partial.

Build17969 exited0 in3m38s, app-Bn2avRDK.js; manifest30aacd6b5baaf998bc8c9feda713b60e9d11afb10a4927f2771f6e9c71b795ea. Fingerprint1a763406ae0ff9790af566085e9a8c43312fc93f178a89ddbfbffcfac5d67c6c. Launcher33290 exited0, readiness=true, token d7b66111467f443c. The six runtime helpers, migrations and schema hashes match31. Runtime isolation was read back and saved in w09-browser-build32-runtime.json before browser interaction. The earlier standalone concurrency run had completed and removed its schema before this browser import started.

Actual Codex in-app tab3, normal restricted4 sign-in, /it/tickets/1. The DOM loaded app-Bn2avRDK.js. The existing viewport was now2663×1216 (Build31 had been1280×720); no agent resize call was made and the current viewport was left as found. Do not interpret these as controlled breakpoint comparisons.

Opened Merge ticket, selected target4 and entered a synthetic reason. Review placed focus on the Review this merge H2 with body scrollTop0, body bounds y284.5–931. Keyboard Tab from the acknowledgement checkbox reached Back to proposal and scrolled the body to101. Enter returned to choose, body scrollTop0, active element H2 text Choose the surviving ticket. Its visible counterpart was fully within the body at y305.5–330. Selected target4 and exact reason were retained. Screenshot inspected inline. Tab from the focused step heading moved to Search merge targets, proving useful keyboard continuation.

An initial CSS locator for all sr-only H2s matched both the dialog title and current step heading; it made no change. Narrowing to the observed tabindex=-1 current-step heading allowed the actual keyboard check. This was a tool strictness issue, not an application failure.

Escape then explicit Discard draft and leave closed the unsent proposal. No merge was submitted in this run. Browser warning/error log returned an empty array. Build31 retains the earlier1280×720 scroll evidence and Build30 retains actual merge/data/privacy journeys; this run confirms the final one-line keyboard-focus follow-up.

Automated correction evidence: focused UI17passed/3files/4.56s0 and scoped lint0; full types passed before the existing-ref focus addition. Source hashes: w09-merge-focus-concurrency-source-hashes.json. No DESIGN.md/design_styles edit, working database reset, provider action or real communication.

Cleanup35731 exited0. w09-browser-build32-cleanup.txt records exact server/schema/storage removal and unchanged Herd environment; w09-browser-build32-cleanup-postflight.json independently confirms schema_absent=true and owned_directory_absent=true. No owned Build32 process or disposable schema is left running.
