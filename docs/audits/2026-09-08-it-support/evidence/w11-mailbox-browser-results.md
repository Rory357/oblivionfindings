# W11 desktop mailbox browser evidence

11 September 2026. Browser checks below are a settings/paging/ACK slice. Whole W11 and E10 remain incomplete.

## Build40 environment

Codex in-app browser, unchanged desktop viewport (screenshots1221×846); no resize. Owned tabs15/16, both closed after verification. URL `http://127.0.0.1:8766/settings/it-mailbox`, exact checkout `C:/Users/steph/Herd/oblivionfindings`. Proof `w11-browser-build40-runtime-proof.json` matches manifest `57ad833fd6e01dfa50b21f52b898c088aadfb2c8ed5184a671bf5d666f6cfa5f`, app `app-DaGFDFjm.js`, mailbox `it-mailbox-xT9T0ey5.js`. Isolated schema `oblivion_it_draft_browser_1330a382400c4ac3`, array mail, sync queue, real CSRF/session checks, synthetic HTTP providers and all other HTTP blocked. Production provider configuration was absent.

## Actual browser journeys

- Normal sign-in as synthetic operator7. Approved header/rail and full-width provider detail panel rendered. Screenshot inspection found overlapping long metric labels; this is a confirmed UI defect, not accepted conformance.
- Dirty mailbox plus same-provider header metric opened the approved discard confirmation. Confirming it left Save/Discard enabled and polling disabled: the retained panel kept its draft while the parent cleared the dirty flag. A regression subsequently reproduced exact retained draft `discard-me@example.test` instead of saved `support@example.test`.
- Denied synthetic mailbox address returned the safe provider-permission validation message; saved mailbox stayed unchanged and the draft remained editable. Approved `w11-shared@demo.test` saved after the provider read-access check, version2, with no completed-poll claim.
- Microsoft27-message poll returned Needs attention, one awaiting acknowledgement, no completed poll, and a retry time. The fixture applied the first message's read flag but returned503. Other messages completed. After the real retry delay, Reload enabled polling; retry completed with zero pending acknowledgements and a last-completed timestamp.
- Google27-message poll completed with zero pending counts and a last-completed timestamp.
- Independent read-only reconciliation proves27 inbound rows/27 tickets per provider,54 synthetic tickets total. Microsoft27 processing attempts/28 ACK attempts; Google27/27. Both synthetic unread sets empty. The failed Microsoft message was read once and acknowledged twice. Evidence `w11-browser-build40-mailbox-reconciliation.json`; no live-provider delivery claim.
- Disconnect confirmation was visually inspected; initial focus was Cancel. Keyboard Return cancelled and retained the connection. During its exit animation the title briefly changed to the discard-dialog wording; retained as a minor visual follow-up, not a data mutation.
- Two actual browser editors: first saved version3; second version2 write was rejected with current-state review required. Reload showed version3 and saved address without writing; explicit Use saved mailbox adopted it and restored actions.
- Logout in one tab followed by a stale write in the other concealed both providers, counts and draft. Normal same-operator sign-in plus Reload with current access restored saved data with no draft replay.
- Actual synthetic disconnect returned Not connected and authorizations1; read-only `w11-browser-build40-after-disconnect.json` confirms54 tickets/all54 inbound rows retained, only Microsoft's connection removed and its inbound FK nulled.
- Synthetic restricted technician4 signed in normally; direct settings URL returned403 Forbidden with no mailbox content.
- Browser console error queries returned empty lists in both owned tabs. This is limited to captured browser errors, not a claim that every runtime state is fault-free.

## Fixes and verification boundary

Same-provider header/filter navigation now remounts the provider editor only after the approved leave action, so confirmed discard actually replaces the draft. Metric labels shortened to Authorizations / To process / To acknowledge / Quarantined. No design guide or shared header styling changed.

Focused corrected UI12 tests/2files passed3.92s; full TypeScript and scoped ESLint exit0. Preserve initial regression harness missing-import failure (`w11-header-discard-regression-before.txt`) and corrected reproduction failure (`w11-header-discard-regression-confirmed.txt`). Final log `w11-header-discard-regression-final.txt`.

Build40 cleanup49023 exited0, exact schema/token directory removed and working Herd environment unchanged. Evidence `w11-browser-build40-cleanup.txt` and `w06-draft-browser-cleanup-1330a382400c4ac3.json`.

Build41 is being generated for a fresh current-asset visual/discard retest. Until that browser retest completes, the two UI corrections are implemented with unit/type/lint verification only. Remaining whole W11 criteria are listed in the implementation plan and settings/paging reports; no package/release gate is closed here.

## Build41 closure — supersedes the pending retest above

Build41 passed3m13s, app `app-kNtA0mJt.js`, mailbox `it-mailbox-BfDtiBEP.js`, manifest `825aa72ccee696cfd66aead2b85c8ffaa722e8d801081fc70a6c848edb87a4d1`. New owned runtime `283574d57b794e1a`, reviewed fingerprint `822ce91d96276259bad63979d3c8b09558a020da751a4ef96c81703d8e4035f1`. Runtime proof matches the checkout/build/schema/array-mail/real-CSRF boundaries. All seven helper hashes match Build40; app/test source snapshot `w11-mailbox-build41-source-hashes.json`.

Actual in-app tab17, same desktop size, normal operator sign-in: screenshot confirms all four meter labels fit without overlap. Same-provider header action with an unsaved draft opened the approved confirmation; keyboard Return on Cancel retained the draft and triggered confirmation again on the next navigation. Confirming discard disabled Save/Discard, enabled Poll and removed the stale leave guard. A second test produced a pending Microsoft ACK solely to retain that provider under Needs attention: filtering with an unsaved draft required confirmation; confirmed discard kept Microsoft visible, excluded Google and disabled Save/Discard. Screenshot confirms the retained editor shows the saved mailbox. Captured console errors were empty. Tab17 closed.

The header-label and same-provider/filter discard corrections are now browser Verified for these actual journeys. Whole W11/E10 remain In progress. Build41 exact runtime teardown is running; require terminal/postflight before recording it removed. No additional provider lifecycle acceptance is inferred from the filter fixture.

Final teardown56481 terminal0: exact Build41 schema and owned directory removed, working Herd environment unchanged; independent directory absence confirmed. Log `w11-browser-build41-cleanup.txt`, owned cleanup manifest `w06-draft-browser-cleanup-283574d57b794e1a.json`. No owned browser tab/server/build/test remains active. All10 protected design hashes unchanged (`w11-mailbox-build41-protected-design-check.json`). Next W11 work is canonical email identity/reference and command integration; the minor disconnect title change during exit animation remains explicitly open for the next settings UI change.
