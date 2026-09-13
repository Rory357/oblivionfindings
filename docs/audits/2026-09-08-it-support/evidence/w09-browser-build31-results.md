# W09 merge wizard scroll/focus recheck — Build31

10 September 2026. W09/F16/A08/E08 remain partial.

Build95906 exited0,3m39s; app-h2tbvntA.js/show-Cdhmwdbt.js; manifest3beceb2f76a05bf4638a078839c229c669f98bb6b396466427f11a4cfa860c8a. Launcher97272 exited0 and ready=true; server10124/token5414bd22366646d6/fingerprint1ecd0895167f60af93fddc3c5f9a3b3dc753109527593b97b7dece5a946aabf5. The six runtime helpers, all migrations and schema hashes matched Build30. Isolation was read back from the loopback runtime before interaction (w09-browser-build31-runtime.json).

Actual desktop Codex in-app tab3, existing1280×720 viewport unchanged, normal restricted4 login. Loaded app script matched the manifest. On /it/tickets/1, opened Merge ticket, selected target4 and filled a synthetic reason. Filling the reason scrolled the wizard body to27 (520px client height/547px scroll height). Review transitioned to scrollTop0; the visible heading was at y120.70–145.20 inside body y99.70–619.80 and focus was on Review this merge. Screenshot inspected inline confirms the complete heading and approved wizard shell.

Keyboard Tab from the review checkbox moved to Back to proposal and scrolled the body to228. Enter returned to choose at scrollTop0 with target4 and the exact reason retained. This exposed a separate focus issue: the removed Back button left focus at the dialog container, rather than the current step heading. The scroll defect is verified fixed; keyboard focus required a follow-up source change.

The unsent proposal was explicitly discarded through Escape -> Discard draft and leave. No merge was submitted. Browser errors/warnings were empty. Cleanup83420 exited0; w09-browser-build31-cleanup-postflight.json independently confirms exact schema and owned directory absent. No real communication, working-database reset, provider configuration or viewport change.

Follow-up: the step layout effect now focuses its heading with preventScroll after resetting only the wizard body. The regression asserts focus after Back. UI17passed/3files/4.56s0 and scoped lint0 (w09-merge-focus-* logs). Existing full types75620 passed before this one-line existing-ref focus addition. Build32 will verify this exact follow-up visually; it is not inferred from Build31.
