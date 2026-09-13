# Dedicated IT workspaces — desktop browser pass, 13 September 2026

This is a bounded verification pass, not W00–W27 completion. Source and evidence changed the next implementation actions; this goal continuation made progress.

## Runtime and checks

- Build `w21-workspace-build-20260913.log` passed, exit 0, 5,217 modules, 3m42s. Manifest SHA256: `07324e3cc8c3bc9e7e4d52eb0f51ea8692945b4267471ef08c9f912c7023576f`.
- Guarded WorkspaceFixtures runtime `4175971dd78f4e95`, fingerprint `2631d40dd86a26d90b0824a3ecc5bd3d340a9f40cf6d77380560c13f1a4561ba`, bootstrap session98259 exit0, owned PHP server9856 at `http://127.0.0.1:8766`.
- Identity endpoint confirmed the saved checkout, exact disposable schema/assets, ordinary CSRF/login, array mail, synchronous queue and disabled SSR. No working DB migration or provider configuration.
- Ordinary synthetic technician, author and reviewer logins. In-app browser tab2 only, default dimensions, no resizing. Screenshots inspected through the browser tool. The 87 recorded source/asset hashes were unchanged throughout this pass. Browser error/warning log was empty when checked.

## Successful observed journeys

- `/it` has only Overview, Tickets, Service catalogue and My requests in its rail. Dedicated sidebar destinations open `/it/provisioning`, `/it/reports` and `/it/knowledge`.
- Provisioning has its own title/search/queue metrics/filters and empty-state workflow content. Full joiner/mover/leaver functionality was not exercised by these fixtures.
- Reports has its own page. Selecting 7 days updates `/it/reports?days=7`, displayed range and all observed export links consistently. Full report reconciliation and worker-timezone period boundaries remain open.
- Problems register supports Table/Cards and native record links. The existing Problem investigation was edited through Details → Review → Save; the saved root cause appeared on its read view.
- Changes register/detail and reviewed edit are available. Native keyboard entry of a New Zealand maintenance window produced the correct review labels and saved display: 15 September 2026, 10:00–10:30 am. Browser provider fill alone did not commit native datetime input state; keyboard entry was used. This is not evidence of a backend timezone defect.
- Major Incidents register/detail shows commander, separate communications lead, next update, impacts and canonical ticket links. A synthetic internal command note was reviewed and saved.
- Knowledge author role has only the Knowledge destination and author commands. Library shows 27 typed documents, owners, services, review dates and proposal status. Reader displayed published content while a proposal remained separate. The editor showed the proposal, structured procedure/verification and canonical service relationship.
- Author changed the proposal, reviewed and saved it, then sent it for review. A separate reviewer had Review & publish/Revisions actions and no Edit/New commands. Reviewer compared proposal with current publication, approved and confirmed publication. The reader then showed the changed approved body; proposal status was removed.
- Page2 displayed records25–27 and its final system document opened with structured purpose and service relationship.

## Defects found — corrections and recheck required

1. Switching a specialist register to Cards can scroll the PageHeader’s inner overflow container, hiding its title/search/actions behind the fixed app header. Screenshot and DOM rectangles confirmed the title at y40 while the clipped hero began near y94. Apply the existing IT `overflow-clip!` pattern to specialist headers and recheck visually.
2. Publishing an internal command note advanced the promised audience-update deadline from11:49 to11:55. Internal notes must retain that deadline; add the missing assertion to the existing cadence regression and fix the domain condition.
3. Working-copy and revision date serialization supplied `2026-10-12T00:00:00.000000Z` to a date-only control. The editor date was visibly blank and history said Not set, although the publication/library retained12 October. Normalize calendar dates for snapshots/presentation without rewriting immutable historical records; recheck editor/history/save.
4. The publication confirmation briefly changed to Discard proposal while closing because nullable confirmation state fell through to the discard labels. No discard was clicked. Keep action identity stable and show/lock processing correctly through the command.
5. Closing the reader and immediately paging retained `article=1` in the old pagination URL, reopening the closed reader on page2. The close request already removes the parameter, but racing another navigation cancels it. Pagination/filter destinations must omit the closed selection and preserve the current page on reader close.

Title text was visually present in the editor. The browser’s read-only DOM input-value observation returned empty while the screenshot and review showed the title; no title-loss defect is claimed.

## Correction source and checks

After the owned runtime was removed, all five corrections were saved. Specialist headers use the existing `overflow-clip!` pattern. Major incident internal command notes preserve the audience deadline. Knowledge normalizes date-only presentation and new snapshots without rewriting immutable historical ciphertext. Publication confirmation retains its action identity and disables cancellation during the command. Reader close preserves the current page, and Knowledge pagination excludes the selected article parameter.

The correction UI group passes 27 cases across four files; scoped ESLint, Prettier and PHP Pint pass. Typecheck identified a test-only `ByRoleOptions.exact` error, now removed; its rerun is pending. The two-file isolated backend correction run and rebuilt desktop verification remain pending. These source fixes are not yet claimed as browser verified.

## Cleanup result

Owned tab2 closed. Exact StopAndRemove session71981 exited0. Independent postflight exited0 and confirmed the exact schema and owned directory absent. See `w21-workspace-browser-cleanup-20260913.log` and `w21-workspace-browser-postflight-20260913.json`. No owned browser runtime remains. Unrelated port8767 preview and the working Herd database were preserved.
