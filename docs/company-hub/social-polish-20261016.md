# Company Hub consistency and social feed polish — 16 October 2026

User approved all seven proposed improvements and requested a Facebook-like social feel for shout-outs. Three read-only audits (guides/uploads, Hub consistency/navigation, social contracts) informed the implementation. Existing permissions, shared navigation configuration and canonical saves are retained. No database migration or new sharing audience.

## Delivered source changes

1. Event editor: shorter expanding description, date and time alongside one another using the existing Rory controls, existing secondary audience/reminder settings retained.
2. Invitation preview: card presentation, explicit preview label, attachments/pending uploads and a non-interactive registration preview alongside existing cover, host, dates, location and agenda.
3. Uploads/documents: replacement transfer percentage and checking/saving phases, authenticated historical file preview links, current filename and batch position in the media-library upload dialog. Existing cancellation, receipts, version/conflict protection and download/replacement workflows retained.
4. Guides: active contents section tracks reading position, existing owner/review/related resources and PDF preview retained; document reader now calls out overdue review dates using the NZ calendar day.
5. Social feed: composer, author identity header, recognition message and recipient, read-more for long posts, immediate independent reactions with rollback on unconfirmed completion, visible totals, latest-comment preview, compact expanding reply box with Ctrl/Command+Enter, copy-link action. Reactions are counts, not unique people. Existing flat reply conversations and their deliberate permissions remain; no invented nested-reply or reactor-identity API.
6. Navigation: optional shared rail cap, set to eight only in Hub rails; retains the active destination, all overflow destinations, published grouping/order/visibility and editor configuration. No navigation record was republished.
7. Consistency: missing shared empty-note styling added; checkbox/radio/range accents and range focus styling align with the app. Hero heights remain stable across tabs as previously requested.

## Checks

- 81 tests passed across eight suites on the first run; after shared-button cleanup and one added upload-phase case, the affected two suites passed all 28 tests. 82 distinct cases overall. Logs: company-hub-social-polish-tests-20261016.log and company-hub-social-polish-final-tests-20261016.log.
- Scoped TypeScript check passed. Scoped lint had two raw-button warnings, repaired by reusing Button; final affected-file lint passed with zero warnings. Temporary typecheck config removed.
- Review caught and fixed: pending reply removal must stay mounted while comments collapse; guide tracking must retain all intersecting sections and use pixel root margins; replacement upload status must be distinct from history refresh. Regression suite passed 40 cases across three affected files. Total distinct focused cases: 84.
- First build succeeded (99512, 8m 24s), but emitted code inspection showed it predated those review fixes. Its visual review also exposed an oversized filter panel above the feed. The final feed uses a compact search/count/filter bar with detailed filters on demand, and a shorter heading/composer. Final recognition suite: 17 passed.
- Final build 7130 finished with actual exit 0 in 7m 28s. Browser loaded app-D_Z4v7Au.js from the correct host; no horizontal overflow or console errors. Existing large-chunk and font-reference build warnings remain.
- Final browser verification: compact filter bar expands/collapses; reply entry preserves an unsent draft through collapse/reopen (test text cleared, never posted); guide contents follows both clicked headings and subsequent scrolling. The earlier event walkthrough verified Rory dates, adjacent date/time controls, invitation preview and discard without saving. Recognition composer opened the existing review-before-sharing wizard. No test recognition, comments or events published.
- Evidence: evidence/social-feed-final-20261016.png, evidence/social-feed-interactions-20261016.png, evidence/social-feed-complete-20261016.png, evidence/social-polish-event-preview-20261016.png. Temporary verification tabs 23, 24 and 25 closed. Original user tabs 1, 5 and 19 untouched. No database, appearance preference, commit, push or deployment changes. No build worker from this task remains.
