# Approved Company Hub audit completion — 16 October 2026

User approved all 18 findings and extra social features. Scope: desktop, one organisation across approved sites, one shared design, current role/permission/privacy boundaries. Preserve existing drafts and unrelated shared-checkout work.

## Acceptance matrix

Complete means implemented with the stated evidence, not every permutation performed against live company data.

| Status | Approved finding | Implementation and evidence |
| --- | --- | --- |
| Complete | 1. Full recognition history | Server search, category/team/recipient filters, order and continuation across the authorised source. Bounded pages, stale-response rejection and Show more. Discovery/social tests. |
| Complete | 2. Saved past/distant events | Saved identities resolve independently of the upcoming feed, including ended/moved recurrence. Isolated history fixtures and pure recurrence checks. |
| Complete | 3. Audience participation/moderation | Eligible public readers can comment; private participant restrictions remain. Author withdrawal, moderator hide/restore and stale-version checks. Social backend/conversation tests. |
| Complete | 4. Clear navigation | Distinct Resources/Guides/Pages, selected guide parent and contextual Following/Updates/Saved destinations. Actual Guides browser check. |
| Complete | 5. Internal links | Hub view/filter/return context retained through shared browse links. Resource/discovery/return tests. |
| Complete | 6. Document workflow | Compact metadata, owner, category, collection, review, audience, approval, submit/publish, replacement/history workspace. Changed-field saves preserve legacy values. 29 UI cases, isolated publication/receipt/version tests and actual private workspace in both themes. |
| Complete | 7. Compact hierarchy | Tighter hero spacing, compact saved content, coordinated document heading/back link and no duplicated generic social action row. Current browser screenshots. |
| Complete | 8. Guides heading/navigation | One Company guides content heading and correct selected parent, retaining shared hero. Actual browser proof. |
| Complete | 9. Helpful states | Initial/filtered empty, loading/retry, unavailable and stale response states. Browse/conversation/space failure tests and actual sparse Home/Teams. |
| Complete | 10. Sparse Home | Compact saved row, honest content placeholders and collapsed editor-only setup with useful next actions. Actual Home screenshot; no fabricated company news. |
| Complete | 11. Social feed | One composer, people-first cards, independent reactions, expandable integrated replies, copy link, quiet spotlight/sidebar. Unsent drafts survive collapse and block filter/spotlight replacement. Actual unsent browser trial, then cleared. |
| Complete | 12. People connections | Protected owned profile photographs, safe initials fallback and work-profile/recognition links. Current audience/permission checks; no new chat grant. Isolated photo ownership tests. |
| Complete | 13. Teams/Campaigns/Ideas | Space search/category/order/followed filters and continuation; full Ideas server browsing/exact selected record. Three new space checks cover continuation/context, follow-failure recovery and correct role-gated team template. Actual Teams sort changed to A–Z. |
| Complete | 14. Designer clarity | Quiet idle controls, clear selected block/settings and retained drag/resize. Actual desktop light/dark inspection without changing draft. |
| Complete | 15. PDF reading | Selectable text, all highlighted occurrences, match list and next/previous across pages, Unicode offsets/page units. Actual two-page sample: four matches, match 3 on page 2, drag selected “verification”. Reader/search tests. |
| Complete | 16. Ownership/review | Authorised owner links and nonblocking unavailable-owner/unset/overdue review guidance. 37 database-free cases/97 assertions and document tests. No invented policies/dates. |
| Complete | 17. Bounded discovery | Pagination before projection, batched file lookup, reused authorised page summaries. Fixture: 205 ideas; six file blocks with one media lookup and one page materialisation. Full snapshots still contribute to initial payload; no universal constant-size/production-load claim. |
| Complete | 18. Current acceptance | Functional checks, additive local activation, successful final build and representative current desktop light/dark browser review complete. Evidence and deployment limits below. |
| Complete | Extra: mentions | Authorised picker, audience revalidation and delivery-time permission/preferences. Mentions do not expand audiences. Picker browser-inspected; isolated notification/denial tests. |
| Complete | Extra: private pictures | JPEG/PNG/WebP validation, local previews, owned private storage, protected reading, rollback cleanup. Four pictures/5 MB each with dimension bounds. UI and isolated upload/access tests. |
| Complete | Extra: threaded replies | Canonical parent identity, bounded nesting, contextual notifications and content-free removed/hidden placeholders. Conversation/backend tests. |
| Complete | Extra: author editing | Author-only versioned edits, edited state, conflict refresh and retained uncertain writing. Four focused conversation cases and canonical backend tests. |

## Executed checks

- Broad Hub/recognition UI: **689 passed / 3 failed out of 692**. Hero-summary and PDF Unicode defects repaired; gallery test updated to current accessible label. Affected recheck: **56 passed across six files**. Not one clean 692-case rerun.
- Final freeze: **36 passed across four files**, `storage/logs/company-hub-freeze-check-20261016.log`. Additional spaces: **3 passed**, `company-hub-spaces-check-20261016.log`. Agent selections overlap: documents29, shared composer/attachments34, discovery/Ideas/Saved41; do not sum as unique cases.
- Combined isolated social/documents: **156 passed / 2 failed / 1,898 assertions**, actual exit1. Two obsolete assertions (public-reader reply eligibility and JSON key order) corrected without product changes. Narrow rerun: **2 passed / 34 assertions**, actual exit0. All158 selected cases have passing evidence across two runs; not one clean full-suite run. All ten new social scenarios passed.
- Discovery: **4 passed / 61 assertions**, actual exit0. Pure recurrence: **2 passed / 5 assertions**. Database-free governance: **37 passed / 97 assertions**.
- Scoped Hub TypeScript, root/agent lint and whitespace passed. Whole-repository TypeScript exceeded its4GB heap and is **not** counted as passing.
- Full build67660: actual exit0,11m17s, `company-hub-audit-polish-build-retry-20261016.log`; live browser served `app-DSPb7jnd.js`. Earlier4GB build failed from heap exhaustion. Final contrast-only build34482 passed, actual exit0,7m55s: `company-hub-audit-contrast-build-20261016.log`. Fresh browser verified `app-C-YctHCL.js`; final CSS formatting and whitespace checks passed.
- Local additive migration `2026_10_16_140000_extend_canonical_recognition_social.php` applied, actual exit0; ten recognition routes registered. Prerequisite already applied. No commit, push or production deployment.

## Actual browser evidence

Checked as Demo Admin in an owned verification tab. Ordinary-reader/denial cases are isolated test evidence, not a second live-account walkthrough. Authorised private sample guide12 remains unpublished.

- [Social light](evidence/audit-social-light-20261016.jpg): expanded conversation; unsent text retained through collapse/reopen, then cleared. Dirty filters/spotlight locked. Composer recipient, mention choices and image entry inspected, then cancelled without posting.
- [Document light](evidence/audit-documents-light-20261016.jpg) / [dark](evidence/audit-documents-dark-20261016.jpg): details/sharing/review/history together, accurate private status. Styled type selector inspected without saving changes.
- [PDF matches](evidence/audit-pdf-matches-20261016.jpg): two actual pages, four occurrences, selected page-two match and successful drag selection.
- [Guides](evidence/audit-guides-light-20261016.jpg), [Home](evidence/audit-home-light-20261016.jpg), [designer light](evidence/audit-designer-light-20261016.jpg) / [dark](evidence/audit-designer-dark-20261016.jpg), [Teams dark](evidence/audit-teams-dark-20261016.jpg): current layouts. Teams really is empty; populated filtering/follow behaviour is test evidence.
- Dark social inspection found low-contrast Reply hover/expanded text. Scoped correction uses the shared readable accent token; refreshed final build confirmed readable Reply text/background in both themes. [Final dark social proof](evidence/audit-social-dark-20261016.jpg). Other inspected social accents, document selectors and designer settings were readable. Original Light preference restored and confirmed after settings reload.
- Inspected browser console reported no errors; sampled Guides/social views had no horizontal overflow. Sampled routes only.

## Verification boundary

No live post, reply, mention notification, invitation, vote or registration was manufactured for testing. Delivery, schedule activation/expiry, stale accounts, permission withdrawal and duplicate prevention were tested in isolation. Deployment-host cron/queues and real outbound delivery are not certified by local tests. No OCR, inline Office editor, mobile redesign or unrelated global performance rewrite is claimed.

Detailed contracts: [social](social-expansion-backend-20261016.md), [documents](document-expansion-backend-20261016.md), [discovery](discovery-expansion-backend-20261016.md). Earlier approved document/event/authoring work remains in [the prior completion addendum](completion-evidence-addendum-20261016.md); historical pending statements do not override this current register.
