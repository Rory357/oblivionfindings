# PKG-02A — Main first frozen-slice review

Owner MAIN ASTRA. Revision 1, 2026-09-21. **Exact source/build identity and sampled browser behaviour verified; first-slice authorization-race verification/correction remains. No next-slice or integration approval.**

Reviewed candidate in Designer worktree C:/Users/steph/.codex/worktrees/2b9f/oblivionfindings, branch codex/pkg-02a-client-location-design, baseline 2302ca33a95616e442a78e957ddce82d8db98669. The same Designer owns all application/test changes. Its subsequent correction turn 01a0c106-0f19-7242-9102-c77fad861a87 is independently verified gpt-6-astra/xhigh in both fields at 2026-09-20T22:53:14.968Z. Maintenance custody hold and no-Sol ownership remain.

## Exact evidence verified

Main independently hashed every entry in Designer implementation/source-build-manifest.json: **27 application/test files, 1,613 built files and three dependency-lock/test-harness entries, zero mismatches**, including byte lengths where recorded. The manifest hash is E81F1842A90B57EE47C4E026E2C1F2D2A565ADE88096575BCC1C0D643AC5C911. The checkpoint document matches B3E153CDCA86B7FF688C350509AF17E704A515C2C37CBADB285F8D5FF9493F03; public/build/manifest.json matches BC6A08E5443AC80999FAE2573FAC31496337733E48E118CB350416EE4A3B8AC2.

Main independently rehashed all 103 frozen v1/v2/v3 artifact entries with zero mismatches. DESIGN.md, design_styles/POPUP_STYLE_GUIDE.md and design_styles/WORK_RECORD_STYLE_GUIDE.md still match the protected published C3D733AC, 3D41375A and 66908EF2 identities. No guide or frozen preview edit was made by Main. This review identifies a historical frozen candidate; subsequently released corrections require a new candidate manifest.

Main read the full checkpoint and realtime-regression documents, corrected services/controller and source-review tests. Local result logs confirm 36 backend tests/525 assertions and the final six current/history/retention tests/126 assertions completed OK, with missing-environment warnings recorded. These overlap and must not be summed as unique tests. The final frontend log records 20 tests across four files passing. The final build log records success in 3m7s with the existing >500 kB chunk advisory. Full TypeScript and scoped ESLint success remain Designer-reported with empty output logs; Main did not independently execute those commands or rerun backend/frontend suites. The post-test controller change removes unused imports/helpers; Main inspected that diff. No claim of repository-wide green follows.

## Resolution of the prior source findings

- Initial profile and draft lookup now share ClientLocationAccessService::eligibleBoundaries, excluding another client's direct/pivot asset boundaries. Tests inspect both initial/fallback profile payloads and the draft lookup. The first interim disclosure finding is resolved in the inspected candidate.
- The zone editor blocks a stale/unavailable linked source until explicit review, current-source selection or a custom-copy action. The server requires source_change_reviewed for replacement of the persisted linked identity and retains revision/conflict guards. Focused frontend/backend cases cover those paths. The silent conversion finding is resolved in the inspected candidate.
- The current presenter uses bounded canonical observations or a consistent timestamped Device metadata tuple, with assignment/collection/consent/retention bounds and a post-read access check. The independent latest-address lookup is removed. Main inspected the telemetry writer's simultaneous metadata/coordinate update and focused tests for old, missing, invalid, future, mismatched and retention-only positions. The original current-provenance concern is addressed in these inspected paths; quality absent from the canonical history response remains explicitly unknown.

## Independent browser sample

Main read the pre-write browser schema proof, guarded router/environment and synthetic identity record. Environment/setup hashes match the proof; public/hot is absent. A read-only owner-context process query verified Herd PHP 8.4.16 serving 127.0.0.1:4335. The ordinary sandbox process query was denied; the escalated read succeeded, with no approval-review rejection. The environment guard fixes the exact 2b9f root and synthetic schema oblivion_findings_pkg02a_2b9f_browser. No real data or command transport was exercised by Main.

Main opened a separate hidden tab at http://127.0.0.1:4335/operations/clients/1?tab=location. The rendered app-DLYx89hx.js and app-BDj4Xw48.css match the inspected build manifest. The signed-in identity is the synthetic reviewer, and the page displays Casey Example. Main independently sampled:

- Saved Community walking area revision 2, complete polygon framing on reopen, and retained Mon/Wed/Fri 09:00–11:00, 21–30 September dates and 25 September exception.
- A temporary Tuesday edit followed by Cancel, explicit Discard changes, and DOM focus returned to Edit draft; no save was submitted.
- Real protected history loading returned two synthetic stored observations. Selecting the 9:24 observation changed the historical map heading while the latest 9:54 observation remained unchanged; Return to latest restored the current view.
- Visible map actions and Escape, pristine Draw close, desktop 1280×800 and 1440×900 rendering, and the compact 640×400 wizard's scrollable content/reachable footer. No new browser warning/error was recorded in this sample.

Main reset the temporary viewport and closed its own tab. Main did not independently repeat database persistence writes, access-withdrawal fixture mutation, pointer drawing/dragging, all failure injection, every role, right-click/Shift+F10 parity, or actual 200% browser zoom. Those Designer-reported cases and their limits remain in its checkpoint; compact resizing is not zoom or mobile-scope proof.

## Remaining review condition and released correction

**Care-assignment revocation during save requires deterministic verification.** ClientLocationZoneDraftService locks consent, Device, Client/Site, device assignment, RBAC and HR evidence. It does not lock the client_user relationship used by ClientPolicy::view and ClientProfileSectionAccess for an actor relying on assigned-client/assigned-asset access. The existing ClientAssignmentController::update directly calls supportWorkers()->sync without the same parent/User mutex. The migration gives this pivot its own primary key and unique client/user key.

Source therefore leaves a possible REPEATABLE READ interleaving: relationship removal completes after a transaction's ordinary snapshot starts, and both ordinary access rechecks still see the old relationship. Main has not executed that race and does not claim an observed exploit. The Designer must demonstrate the existing database lock path serializes it, or protect the exact relationship using a current locking read and appropriate authorization evidence, then test the interleaving and retry/rollback behaviour. This is a narrow first-slice correction/test release, not permission to redesign client assignment or broaden role semantics. Retain canonical consent → Device → Client/Site → device-assignment ordering and reconcile additional evidence locks with actual writers.

The two FleetRealtimePrivacy positive assertions still fail in repeated candidate runs. Main inspected the exact existing fixture/authorizer/consent chain: Fleet Tracking is outside the resident-location allowlist. That is a source-supported explanation, not a pristine whole-HEAD execution. A bounded executed diagnostic may establish the exact rejection reason and unchanged dependency chain; no allowlist widening, rewritten regression expectation, pass waiver or broad baseline assertion is authorized. Later assertions not reached by the failures remain unverified.

Finish this authorization verification/correction and bounded regression classification, preserve the E81F historical checkpoint, and return a new exact manifest if source changes. No command endpoint, outing mutation, operational evaluator/activation/promotion, recipient grant, merge/push, package completion or next feature slice is released. Approved v3 scope and all later policy/publication/Stephan acceptance gates remain intact.
