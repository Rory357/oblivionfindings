# Company Hub checkpoint — 13 October 2026

The user requested saving this chat's Company Hub work to local `main` and GitHub `main`, then explicitly requested pausing the remaining implementation to conserve usage. This is a work checkpoint, **not a completion or production-readiness claim**. Resume only when requested.

## Authoritative scope and reference

- Full approved scope and detailed implementation/verification history: [delivery ledger](approved-mockup-delivery.md).
- Approved interactive mockup and its supporting source fragments are preserved in [approved-mockup](approved-mockup/company-hub-designer.html). The HTML already embeds the combined source; supporting JavaScript is retained for requirement comparison. Mockup data is fictional. Runtime production uses the canonical application records.
- The app is single organisation with roles, approved sites, current staff, ownership and privacy checks. See `docs/architecture/single-tenant-application.md`.
- Desktop only. Keep the compact editable hero, stable translucent Rory navigation and agreed light/dark page backgrounds.

## Finished and verified before this checkpoint

The shared hub, page designer and templates, draft/review/publish/schedule/expiry/history, media and navigation management, canonical news/recognition/events/announcements/services, profiles, discovery/search, saved/following/updates, editorial tools, page hero parity and guide reader are implemented. Detailed evidence and limitations are in the ledger. Guide/checklist slice: 19 interface cases, 5 feature cases / 143 assertions, and 1 shape case / 1 assertion passed; scoped lint/format and local build passed. Whole-app types had two unrelated existing errors in workforce restriction settings tests and the handovers hero.

## Latest partial work: department spaces

Source is now wired for Overview / Updates / Resources / Our team, canonical owner/contact projection, team-only directory and related published pages, preview tabs, version-aware team following, and New update through the existing creator. New updates inherit teams and audience from the current authorised published source; they remain private drafts. Existing topics and unrelated followed teams are preserved. `HubDepartmentTest.php` covers source/published projection, privacy/current-site/current-staff boundaries, draft creation and stale follow context.

This slice still needs its focused interface tests and built staff/editor browser acceptance. The attempted interface-test file patch was atomic and failed before creating `department.test.tsx`; that file does not exist yet. Do not claim the full department slice verified merely because the prior guide suite passes. The last successful frontend production build predates this department change.

Checkpoint checks: 19 existing focused interface cases pass; latest scoped lint and formatting pass. Final type check still reports only the two established unrelated errors. Focused department backend rerun is tracked by session 12561 / `storage/logs/company-hub-department-backend-final.log` while checkpoint preparation proceeds. Confirm its terminal result before treating it as evidence. No new departmental browser acceptance is claimed.

## Remaining approved work

1. Finish department-space verification, including stable hero geometry, all four tabs, team following/unfollowing and team-linked draft creation. Confirm source mockup fidelity, including its New update behaviour.
2. Campaign shared milestones (`done`, `active`, `next`) and curated authorised events/guides/stories; preserve separate personal participation activities.
3. Designer dynamic service/team/directory-finder blocks, block visibility and minimum height. Hidden blocks must be excluded by server projections, search, media and interaction access as well as rendering; retain complete immutable editor/history snapshots.
4. Moderation recovery: permission-bound hidden-comment review/restore. Track author removal, moderator hiding and inherited parent hiding separately; never resurrect independently hidden children or leak hidden text to ordinary readers.
5. Recognition: shared editor-selected spotlight, team filtering and canonical team recipients. Reuse HR kudos and recipient privacy checks.
6. Complete the control-by-control comparison against all approved mockup sources and final end-to-end verification. No full-goal completion until every approved requirement is proven.

## Working notes

- Tests create per-process isolated MySQL databases, often taking 10–25 minutes before output. Preserve a live process handle; never restart only because it is quiet.
- `node_modules` and PHP dependency paths may require the normal approved sandbox escalation to run formatters/test tooling. Initial non-escalated latest checks failed for dependency access, not application assertions.
- No remote deployment or full production acceptance has been performed.
- Browser was restored to Support Worker 1, original Hub Home tab; temporary walkthrough tab was closed. Guide and hero preview changes were undone and saved original values confirmed. No roles, credentials, appearance preferences or published content changed.
- Other unrelated workforce/rostering/timesheet work and audit artifacts exist in the main checkout. Preserve them; they are outside this chat's Company Hub checkpoint.
