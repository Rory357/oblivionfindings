# W05 desktop list and navigation implementation evidence

Updated: 2026-09-09 03:11 UTC / 15:11 NZST.

Status: **Implemented; focused automated checks Verified. Real current-asset browser acceptance remains open.** The user explicitly narrowed the product to a desktop web application. No mobile branch or phone-width acceptance is claimed. This record supplements, and does not replace, the root implementation progress ledger.

## Scope and mappings

- W05 / E04 / F11: My requests means every currently authorized requester/requested-for record once, using the new canonical `summary.my.total`. Filtered and paginated captions say exactly how many records are shown. Queue choices explicitly label their counts as queue totals. Cards/Table lives in the header filter row.
- W05 / F08 / E04: ticket, requester and provisioning lists compose the approved EntityTable/EntityCard shells, shared cells, ListCaption and shared action menu. Native ticket identity links support keyboard activation, Ctrl/Meta/middle-click, browser new-tab and native link context menus; record whitespace opens the shared record menu. Checkbox selection is separate and is only offered for current permitted work. Desktop body overflow stays inside the approved table shell; actual long-title/zoom/rail browser evidence is pending.
- W05 / F09/F15 / E04/E06: ticket selection snapshots versions and never replaces them after a background refresh. Assignment and priority changes preview the selected count and change, collect the required canonical reason, and submit the original versions. Mixed outcomes show updated/unchanged/rejected counts and safe per-selection messages, preserve rejected explanations and stop implicit retries. Review current list is read-only and clears selection; another explicit selection/action is required. Bulk close/wait cannot translate a redirect or partial failure into success or clear the retained evidence. Access loss hides the retained bulk mutation dialog immediately.
- W05 / E04: URL state controls the active workspace, search, filters, sort, layout and page. Browser Back resynchronizes controlled search and cancels an outstanding stale debounce. Layout switching retains the current page/filter and adds a normal history entry. Filtering drops page cursors. Initial remembered queue/default-tab preferences are actor-scoped and cannot override explicit URL filters or Back. Existing saved-filter create/delete controls retain input/errors and require a confirmed success flash; current backend validation remains authoritative.
- W05 / F10: retain every existing view key. Add the backend's `unowned`, exact requester/vendor/approver waiting views and `unmeasured`; the private operational choices are hidden without manage permission. The legacy first-response proxy is honestly labelled Awaiting first reply. A real latest-public-speaker Awaiting IT view remains W07 work.
- W05 / C01 / E04: primary sidebar and its Find catalogue have one Knowledge & Documentation/Guides entry, one Reports entry and one Work planning entry. `ItModuleNavigation` uses the same canonical destinations without the duplicate Help Centre/Knowledge paths. New local `/it/knowledge`, `/it/reports` and `/it/work` adapters preserve query context, enforce the existing permission boundary and redirect to the existing canonical implementation. No second ticket/calendar/documentation/report system was added.

The Knowledge/Reports desk rail entries deliberately remain until their actual dedicated landing replacements work under W17/W21, as required by the plan's compatibility rule. Work planning currently reaches the canonical Mine ticket queue, with full scheduling/handover supplied by W19. These are explicit cross-package release dependencies, not completed dedicated workspaces. Remaining desk rail density must be rechecked with those final landing changes. The old unconditional SLA-clear confetti/success claim was removed because disappearing breached rows does not prove every SLA is healthy.

## Changed files in this slice

- `resources/js/components/lists/entity-table.tsx`, `entity-card.tsx`: opt-in native href/accessible identity labels and independent accessible selection; legacy row/card activation preserved; child keyboard events do not activate parent records; new selection is the visual source of truth.
- `resources/js/components/lists/entity-menu.tsx`: record-specific kebab labels, keyboard menu focus/Arrow/Home/End/Escape and focus return, keyboard context positioning, native anchor context menu preservation.
- New `resources/js/components/it/it-ticket-list.tsx`, `it-provisioning-list.tsx`, `it-bulk-result.tsx`; existing `my-tickets-list.tsx` now uses the canonical list shells.
- `resources/js/pages/it/index.tsx`: scoped count/view/search/history/layout/selection/action adapters and persistent bulk outcomes; preserve existing KB capability/reader, routing and creation integration. Requester Reply/Reopen uses the backend's strict current `can_reply`/`can_reopen` flags. No show/Setup prop changes.
- `resources/js/components/it/it-hero.tsx`: additive typed `my.total` only; existing SLA meter semantics retained.
- `resources/js/components/it/ticket-close-dialog.tsx`, `ticket-waiting-dialog.tsx`, `ticket-triage-reason-dialog.tsx`, `ticket-saved-filters.tsx`: bounded bulk/saved-filter response and draft/error handling. Single-ticket optional props remain compatible.
- `resources/js/components/app-sidebar.tsx`, `app/Domain/It/ItModuleNavigation.php`, `routes/web.php`; new `app/Http/Controllers/It/ItWorkspaceRedirectController.php`.
- Tests: new `resources/js/components/lists/entity-interactions.test.tsx`, new `tests/Feature/It/ItWorkspaceNavigationTest.php`; extend existing `knowledge-access.test.tsx`, `ticket-close-dialog.test.tsx`, `ticket-waiting-dialog.test.tsx`, `ticket-saved-filters.test.tsx`, `app-sidebar.test.ts`.

No protected design guide, production/provider configuration, notification transport, production data or working database schema was changed.

## Actual test results

- `w05-ui-first.txt`: **6 passed / 6 failed**. The six failures were the pre-existing index test mock missing the now-used Inertia `usePage` export. Corrected the mock to the real URL/actor contract; no production access checks weakened.
- `w05-ui-second.txt`: **15 passed / 1 failed**. The remaining failure was a new test's ambiguous legacy-card button selector matching its new labelled kebab; narrowed the assertion to the actual card shell.
- `w05-ui-third.txt`: **32 passed / 7 files / 5.46s**.
- `w05-history-ui.txt`: **8 passed / 1 failed**. The new My requests assertion asked for button instead of the approved rail's semantic tab role; corrected the test.
- `w05-history-ui-final.txt`: **10 passed / 1 file / 5.07s**. Includes real component URL/back debounce cancellation, page-preserving layout, scoped requester search/count, frozen bulk expected-version and retained partial rejection, plus immediate access revocation.
- `w05-ui-final.txt`: **39 passed / 8 files / 5.92s**.
- Final `w05-ui-verified.txt`: **40 passed / 8 files / 7.00s**, actual process exit 0. Targets: canonical shared list interactions, requester links, index access/history/bulk tests, close/wait/saved-filter dialogs, existing routing-dialog tests, primary sidebar/Find catalogue. The JSDOM native navigation notice comes from deliberately leaving a modified link click unprevented; it is not real browser navigation evidence.
- `w05-types-first.txt` and `w05-types-current.txt`: whole-repository TypeScript passed before concurrent W10 source work. `w05-types-final.txt` caught a concurrent W10 LoadingState prop error, reported to its owner and fixed there. Final **`w05-types-verified.txt`: whole-repository `tsc --noEmit --pretty false`, exit 0**.
- **`w05-lint-verified.txt`: focused ESLint over all W05 source and relevant test files, exit 0**. Installed formatter ran over the changed TSX and focused new PHP files. `git diff --check` passed for the slice.
- **`w05-navigation-php.txt`: 3 passed / 32 assertions / 258.87s**, wrapper process exit 0. Fresh unique isolated schema `oblivion_it_support_test_it_d2266625e8f8447f`, array mail/cache/session, sync queue, null broadcast. Tests prove canonical query-preserving redirects, requester and knowledge-only denied Work/Reports, and one navigation destination per workspace. No working database migration/reset occurred.

Reproduce the PHP navigation check only through `evidence/run-isolated-it-tests.ps1 -TestPaths tests/Feature/It/ItWorkspaceNavigationTest.php`. The bare working database is never a test-reset target. Backend bulk/saved-filter/privacy and actual concurrency evidence is separately recorded in `w05-list-backend-results.md`.

## Resumption and remaining verification

W05 source froze at 03:10 UTC; final automated checks completed at 03:11 UTC. No list browser verification against these source changes has happened yet. Root currently owns ticket W06 visual work; this agent next takes the explicitly authorized W06 Setup visual/form slice. These ongoing W06/W10 changes require a coordinated build before any current-asset browser claim.

Next browser gate: verify the exact checkout/manifest and desktop assets; requester My requests count/search/status + Guides entry; technician filtered page 2 ↔ Cards/Table ↔ detail ↔ Back retaining filter and scroll; keyboard native title links and both action-menu entry points; long titles and desktop zoom; selected-page scope and read-only participant rows; reasoned priority/assignment with two editors; mixed stale/blocked/unavailable outcomes and explicit review/reselection; close/wait draft retention; saved-view validation/persistence/deletion; primary sidebar/Find/deep links. Use only synthetic records and isolated notification behavior. E04 and the relevant E06 browser portions remain open, as do the W17/W19/W21 final navigation dependencies.
