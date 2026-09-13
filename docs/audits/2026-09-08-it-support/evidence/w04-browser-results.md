# W04 desktop browser evidence — 9 September 2026

Used the Codex in-app browser at `https://oblivionfindings.test` on the current checkout. Primary viewport remained 1440 × 900 after the user's desktop-only clarification. No mobile verification was performed in this slice. Synthetic restricted technician 230 was the actor. Existing original records were preserved.

## Assets and fixture evidence

First build passed in 4m17s (`w03-w04-local-build.txt`), manifest SHA-256 `25b7b2462127fe8919d50556a913f14dff1379c3cdaaad47e8ef3b8351e75638`. DOM confirmed `app-B3K48RKK.js`. A second build passed in 3m58s (`w03-w04-local-build-picker-followup.txt`), manifest `82e2676bb2b2c19ff99a1bfb75538036ba941c69ca3b05969a2f0a6f6edf529b`; a subsequent real reload confirmed `app-eTyglvnK.js` and the clarified report coverage wording. Later W05 and Reports recovery edits require another build before browser acceptance.

Reviewed fixture creation added only tickets 20–26 at 02:11:41 UTC. `w04-browser-fixture-created.json` records all preservation invariants. The default read-only fixture helper later reconciled current permitted rows and aggregates in `w04-browser-live-reconciliation.json`. This is synthetic clock evidence, not a production SLA result.

## Actual browser results

- Header: 16 open, 1 at risk, 2 breached, 6 of 16 fully measured (38%), 1 paused and 9 in an unmeasured state. Coverage was 6 full, 2 partial and 8 with no measured clocks. A desktop screenshot confirmed legibility and no horizontal clipping.
- The breached header link completed navigation to exactly synthetic tickets 14 and 22. Initial pre-navigation DOM was discarded; the completed filtered result was inspected.
- Detail 20 showed both clocks on track. Detail 21 showed the response at risk and resolution on track. Detail 22 retained the response breach after a late first reply while the resolution clock remained on track.
- Detail 23 showed both clocks met. Detail 24 showed first response met and resolution paused. Detail 25 explained both missing clocks. Detail 26 showed missing response evidence and a measured resolution clock. Each result was read from the rendered, completed page, including the independent clock descriptions and actual deadlines/completion times.
- Reports for 11 August–9 September reconciled 16 open, 10 unassigned, 1 at risk, 2 breached, 100% compliance from 1 of 1 fully measured settled tickets and 6/2/8 open measurement coverage. Restricted Device context remained concealed. The header's rolling 30-day cohort and the report's selected calendar cohort are labelled separately.
- Export was clicked using the actual rendered summary URL. No downloaded artifact was located under the checked Downloads filename pattern. **Browser download contents are not verified**; separate PHP CSV assertions passed.
- Expired-session recovery used normal logout in a second in-app tab. In the original Reports page, keyboard Enter on the seven-day filter triggered an actual authentication failure. The completed page withheld the report body and Export and displayed retry. After normal same-actor login, keyboard Enter on Try again restored the seven-day report and reconciled totals.

The expired-session journey exposed misleading generic connection text and a loading heading after failure. The focused Reports follow-up adds session/access-specific explanations and a normal sign-in link; 12 UI tests and scoped ESLint passed. That newer source still needs a coordinated build and browser retest.

## Remaining acceptance

The actual working database has no SLA watchdog run and the browser correctly displays **Unverified / No successful check recorded**. No fabricated successful run was inserted. Automated stopped/stale/recovery tests passed; real browser watchdog stop/recovery remains open for an isolated runtime or authorized real scheduler verification in W26. W09 reopening policy, W17 performance and complete reporting, browser export contents and integrated E03 remain open. W04 is not marked Verified as a whole package.
