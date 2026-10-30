# Transport modal correction — published-base reconciliation

27 September 2026. This supplements the previously reviewed `modal-location-correction/REVIEW.md`; that packet remains intact.

## Exact source and reconciliation

- Published Main base: `262bf39cc932c7b0343d291f22cf7a62c6e41bd0`, tree `7e1d58ce4f1defe4870fcbea481c720876a59e11`. GitHub Main matched this revision immediately before the isolated merge.
- Previously reviewed correction: `b5da039626fc1bb0c1be189a1d9511425b1275d8`.
- Combined merge: `df2c514e57e49115f04de75f7ef86b3fe22f15e0`, with the two revisions above as parents.
- Final source: `c942a8369d9a32218dd57862ff1ade56642fe730`. Its only subsequent source change bounds the Transport list's address subline, discovered during the combined visual check.
- `source-manifest.json` binds the 20 changed source files relative to the published base, their Git blobs and SHA-256 hashes, and the final production asset manifest. No unpublished sibling changes or local-only PKG-03 changes were imported.

The shared DatePicker was the only conflict. The resolution starts with the published implementation and retains optional `allowClear`, required-date protection, explicit non-submit button types, custom triggers, draft reset and confirmation semantics. Adaptive placement adds a trigger ref and measures available horizontal space; a popup uses vertical placement when neither side fits. The published optional-date Clear and required-date Cancel tests remain. Two added regressions exercise a custom trigger ref, deferred confirmation, non-submitting form behaviour and an empty required date.

The automatically merged Fleet routes retain the published Asset Profile routes; the correction's only route delta is the existing scoped POST address-search endpoint. Guide changes in the merge are exactly those already published by Main. This correction adds no guide edits, schema changes, service configuration, `.env` changes or frozen-preview edits.

The visual audit found a full public-library address wrapping beyond the canonical 50-pixel list row. Its secondary line now truncates within the cell, with the complete address retained in the title and existing record details. This is scoped to Transport; the shared table contract is unchanged.

## Verification

The browser uses this worktree's built application at `http://127.0.0.1:8765` and the existing guarded disposable database `oblivion_findings_pkg05_browser_test_54600`. No operational data, Main checkout or `.com` deployment was changed. Browser drafts were cancelled, staged evidence was discarded, and rescheduling checks did not send a booking command.

- **24 frontend tests / five files pass:** shared DatePicker, Transport, and the published Fleet booking-request regressions. The final source was tested again after the row correction.
- **Six backend tests / 114 assertions pass:** three address permission/provider tests (20 assertions), linked-source retry/version/key requirements (14), time-only move and reviewed undo preservation (9), and linked booking-to-Transport action routing (71). These are focused checks against the guarded existing test schema, not a full backend or concurrency certification.
- Full repository TypeScript passes. An initial run overlapped Wayfinder route generation and reported temporarily missing generated modules; the stable post-build check passed. Scoped ESLint, PHP Pint and source patch whitespace checks pass.
- Final production build passes with the existing bundle-size warning. This is not a claim that every repository CI gate is green.
- **76 action-modal layout checks** across 13 available actions at 1366×768 and 390×844: visible footer actions, reachable body content, review/discard states, Site key storage choices and draft retention.
- **18 quick-view/evidence checks** at 1366×768, 1280×800 and 390×844: all five sections remain accessible; ten staged long filenames and long notes do not create horizontal scrolling or hide the save action.
- **16 nested date/time checks** at 1366×768, 1024×768, 768×1024 and 390×844: popups remain within the viewport, their apply actions are reachable, and Escape leaves the parent modal open.
- **Eight real custom-trigger date-filter checks** at the same four sizes: viewport bounds, required-date protection, cancellation and Escape. A confirmed From change updates the date range and is then reset through read-only navigation.
- **Six rescheduling step/viewport checks**: the time and review steps fit, and a reason remains required.
- Long-address row rendering is checked at laptop, tablet and phone widths; the text stays within its row and retains the full address.
- All **105 frozen v6 entries** match their committed manifest hashes.

The modal, nested-picker and rescheduling matrix ran on combined merge `df2c514e`; the final source changes only the unrelated address subline in the list. The final build receives fresh custom-date and long-address rendering checks. Individual browser results and provenance are recorded in `browser-results.json`; screenshots and check logs accompany this packet. The earlier report retains the additional 1280-pixel action matrix, longer-action layout simulations, saved public-library request readback and `.com` read-only findings. Approval was not separately opened because the fixture actor cannot approve their own booking. No claim of live `.com` verification of this candidate is made.

## Main handoff

Main has already reviewed the prior correction without confirmed blocking findings and requested this exact published-base reconciliation. This packet is for the final combined review gate. No Main-write or publication slot has been assumed.

The user's requested wider-module note has been sent to Main: check filled forms on short laptops and phones, long labels/addresses/files, fixed visible actions, reachable scrollable bodies, nested dropdown/calendar bounds, keyboard/Escape behaviour, searchable growing directories and permitted saved locations with explicit external address search/manual fallback. Main confirmed those checks were included in the remaining module briefs.
