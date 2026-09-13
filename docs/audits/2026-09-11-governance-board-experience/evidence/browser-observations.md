# Desktop browser observations — Governance audit, 11 September 2026

These are audit observations, not implementation acceptance. All records and identities below are synthetic. No administrator account was used for the recorded board journeys. Record IDs belong only to the disposable audit database identified in runtime-state.json. Sources, runtime probes and browser evidence are distinguished deliberately.

## Environment and provenance

- Checkout: C:/Users/steph/Herd/oblivionfindings; baseline HEAD 5fa7c6a4db50fa1783200abf4d930f72046ef93e.
- Herd's https://oblivionfindings.test/governance/dashboard returned 504. The audit used http://127.0.0.1:8776 with the same checkout/public directory, normal Laravel login and CSRF, a separate session cookie, fake mail, synchronous test queues, and the isolated oblivion_gov_audit_20260911_9888 database. The audit router is preserved alongside this file. No production host was operated.
- PHP 8.4.16; browser Chrome through the installed browser connector. Initial tab was lost during the usage interruption; resumed tab ID 1051435981. The original fixture process had stopped. The disposable database was reused after checking its exact name.
- Browser-observed application entry script: /build/assets/app-DKNHidxV.js. Manifest timestamp was 21:32:16 NZ time, after baseline HEAD. Exact source/build/design hashes are in asset-and-design-hashes.json. This proves the retained asset identity, not a fresh build from source; no fresh build was run.
- Ordinary member, chair and secretary journeys were inspected at 1366×768 in dark appearance. Finance committee detail/pack work was inspected at 1920×1080. A later light/reduced-motion overview check encountered a blank render; see B12 and its limits. Do not infer full light/dark or accessibility coverage.
- Screenshots were visually inspected in the tool. Chrome's tab content-export operation returned unsupported, so no durable screenshot files are claimed. This file preserves the observed content and steps. Final implementation/review must retain its own reproducible screenshots and current build proof.

## B01 — Member entry and overview: misleading priorities (GOV-F01/F07/F08/F18)

Persona: standard board_member, user 1, BoardMember 1, display name Audit Alex Morgan. A different user/BoardMember has the same display name. Starting route after normal login defaulted to a 403 at the general dashboard; opening /governance/dashboard provided the board entry. This recovery should not be confused with an admin login.

At 1366×768 the first view was dominated by the legacy “Executive & Board Cockpit” hero and repeated summary metrics, followed by long priority cards. Two “Open actions” summaries showed 15; the priority area showed ten critical and nine overdue entries. Next-meeting preparation and the pack were farther down the page. The source limits and canonical fixture/probe counts establish that these are sampled mixed workflow items, not the full member workload.

“My Next Actions” included action 25, assigned to the different member with the same name, as directly assigned personal work. Action 24, actually owned by the current member, and the member's blocked action 26 were absent. The record-count and duplicate-name fixtures are intentional; these observations are not derived merely from similar names in source.

The earlier chair-only “AUDIT PRIVATE executive employment discussion” appeared in overview priority/calendar/next-meeting preparation text. The meeting register exposed only the normal board meeting. The overview's pack summary said none was available because it selected the inaccessible earlier executive meeting. The normal board meeting had a pack. Current-member executive access independently returned false in the service probe.

The risk tab displayed “All tracked risks are within appetite” from an empty workflow feed. The original fixture's risk helper initially calculated low residual values; that initial zero is **not** evidence of a defect. Corrected likelihood/impact/control fixtures and probe-results.json establish 12 above-appetite canonical risks versus ten aggregated entries. Source inspection separately establishes the unsupported healthy wording from filtered emptiness.

Completion/return: member could navigate to the normal meeting, but the overview did not reliably identify personal obligations or the correct next accessible meeting. No timed human comprehension claim is made.

## B02 — Member meeting preparation and Workflow tab (GOV-F10)

Starting from the normal meeting, opened /governance/meetings/1 and its Workflow tab. The checklist showed seven done and three remaining, with “Record Attendance” as the next action. Following it reached an Attendance view with no editing control for the ordinary member. No RSVP control was available. The Agenda contained one item; the “View pack” destination was present.

Impact: a preparation journey points a member at secretariat attendance administration without a usable personal next step. Read-only attendance itself is not a defect. The required correction separates member RSVP/read/conflict preparation from authorised secretariat workflow. The meeting remains the return context.

## B03 — Member decision and vote receipt (GOV-F01/F04/F11)

Opened the resolution register. It listed three records, including the draft “AUDIT PRIVATE executive resolution” and its private executive parent. “Awaiting your vote” showed one despite an additional open resolution without a deadline; source/probes identify the date-filter omission.

Opened /governance/resolutions/1, “Audit equipment renewal decision”. The detail displayed the decision context, options “Renew now” and “Defer”, and the recommendation “Renew in two stages”. Selected For and submitted the vote using the normal UI. A durable “Your Vote FOR” receipt appeared, dated 9/11/2026 10:51:28 pm; the resolution remained Open. Header totals still displayed For 0 / Against 0 after submission. A successful vote is not decision approval or implementation. Preserve the useful paper detail and receipt while repairing authoring and state refresh.

Direct navigation to /governance/resolutions/2 displayed the private resolution detail for the unauthorised ordinary member. This is observed direct-child disclosure, corroborated by the access service denying that member access to its executive parent. No vote was cast on that private record.

## B04 — Performance direct route (GOV-F02)

As the ordinary member, reached the synthetic CEO performance-review detail, showing the review subject/cycle/status/timeline. This establishes route and record access without assignment. The raw overall-assessment field was **not rendered in the observed page body**; its inclusion in the controller's serialised model is a separate source finding. Do not present raw-assessment browser display as observed.

## B05 — Secretary attendance administration (GOV-F10)

Normal logout/login as board_secretary, user 3. Opened meeting 1 → Attendance → Record Attendance. The dialog exposed six roster members, including the observer and both identical display names, preselected Present. Cancelled without saving. This confirms the authorised administration control exists while the member lacks it; it does not validate quorum, attendance persistence or an observer's right to vote.

## B06 — Chair approved minutes (GOV-F03)

Normal logout/login as board_chair, user 2. Opened the approved version-1 minutes of meeting 1. An Edit control remained available; no signing control was visible in the inspected view. No browser edit/sign was submitted. Transaction-rolled-back runtime probes separately confirmed approved content could be replaced while remaining approved, and sign could set signed status without signer/time fields. The browser and runtime observations are distinct.

## B07 — Finance committee appointment and budget detail (GOV-F04/F13)

The original compatibility fixture used BoardMember.board_role=treasurer, which returned canVote=false despite the user's vote permission. This is preserved in the probe evidence. For a realistic finance-committee journey, the fixture was changed to the existing member board role and given an actual active chair membership of the “Audit finance committee”, with term dates, while retaining the standard board_member permission role. No new permission grant or invented application role was added. See fixture-extra-result.json.

Normal login as this finance-committee member, user 4. At 1920×1080 opened approved budget 1. Its approved amount 100,000 was visible, with no manage/edit control for this role. This fixture has no budget lines; its incompleteness is not a production budget-calculation defect. Delegated threshold enforcement is a source finding, not a live financial approval performed in the browser.

## B08 — Board pack download and implicit reading acknowledgement (GOV-F06)

As the finance-committee member opened pack 1. The initial detail showed Read 0 / Downloads 0 and a Download pack action, with no explicit reading-acknowledgement control. Reloading showed Read 1. The Show.tsx on-mount POST to /read corroborates the implicit write and silently swallowed error; opening the page does not establish that the pack was read.

Clicked Download pack. Reloaded detail showed Downloads 1. The distinct download counter worked. The fixture uses a tiny synthetic PDF stored at the actual private disk root after correcting the audit fixture's initial absolute path. That initial fixture-path mistake is not counted as a product defect. Downloaded bytes, PDF accessibility and published-version immutability were not verified by this browser interaction; source inspection supports the separate regeneration/immutability finding.

## B09 — Privacy and failure checks not claimed as browser coverage

Executive access checks, raw performance serialisation, voting arithmetic/deadlines/eligibility, approved-minute mutation, action generation, strategy approval and compliance recurrence also used source or disposable runtime probes. Those probes use transactions/rollback where documented; they are not proof of complete UI journeys. Concurrent votes, duplicate-close races, revoked permissions across queued delivery, network failure/stale responses and all download/search/report/calendar projections require implementation tests and independent browser verification.

## B10 — Preserved capabilities and limitations

Confirmed useful UI elements include ordinary-member meeting/agenda access, contextual decision detail, a successful individual vote receipt, secretary attendance controls, chair minute status, finance-committee budget reading and pack/download engagement tracking. Most broader capability classifications are “present but incomplete/confusing” or “unverified”, not “verified end to end”. The audit's 162 passing backend tests do not establish usable desktop journeys.

No representative human timed sessions, 200% zoom pass, complete keyboard/focus-return audit, accessible print verification, full role CRUD matrix or post-implementation acceptance was performed. The new Sites calendar adapter is a required specification, not an implemented or browser-tested change. Its five-view and existing Sites global/site/profile regression gates remain Not run.

## B11 — CEO/executive contribution blocked by the standard role (GOV-F17)

Normal logout/login as the standard ceo role, user 5, with no board appointment and no additional grants. Successful login reached /my-day, displaying “Audit Ceo” and a sidebar without Governance. Direct navigation to /governance/ceo-reports returned a visible 403 Forbidden. This confirms an unsupported executive contribution journey with the actual seeded role. No report was authored and no broader permission was granted as a workaround. The required correction is bounded author/reviewer capability and assigned-record access under GOV-W18, not automatic access to board-private material.

## B12 — Post-pack timeline crashes the overview (GOV-F26)

After B08, at 1920×1080 changed the synthetic finance actor's appearance to Light and enabled Reduce motion through Settings → Appearance → Save preferences, then opened /governance/dashboard. The page remained blank after reload, with document title Laravel and empty rendered body text. Settings still rendered normally. Repeated the overview check after disabling Reduce motion, then after restoring Dark; the overview remained blank. Thus no theme/reduced-motion cause is claimed, and no successful wide overview visual pass is claimed.

Captured console at 2026-09-11T11:26:47.336Z: `TypeError: l.slice is not a function or its return value is not iterable`, at Mt in /build/assets/CockpitLayout-Cm_4BPPj.js:2:2522. The retained chunk's function maps directly to GovernanceTimeline, where timeline.events.slice is called. The read-only audit-timeline-probe.php exited 0 and produced timeline-probe-results.json: ordinary member and finance member each get one event keyed 1 (JSON object); chair gets keys 0,1 (JSON array). The presenter filters private pack activity but preserves collection keys. This establishes an actual array-contract defect after routine activity, not a fixture or preference inference.

Required recovery: keep the correct privacy filtering, reset list indexes server-side, validate list contracts client-side and isolate a failing panel so the overview retains its other usable sections. Regression must include one filtered newest event and one retained older event, not only empty/all-visible fixtures. Linked GOV-W08/W09, GOV-A08/A09/A25.
