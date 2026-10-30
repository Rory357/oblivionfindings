# Final staff evidence reconciliation — 15 October 2026

Scope: families **1–4, 11–15, 19–23, 36–37**, retaining the full approved **37 families and 22 completion items**. This is a bounded reconciliation of current source, actual existing log endings and the application's browser checkpoints in [RESUME-HERE.md](RESUME-HERE.md). Only this document was written; no application changes, tests, builds, browser operations or data operations were performed.

**Decision:** no additional demonstrated implementation defect remains in this staff subset. The old S1–S5 findings and subsequent five finite follow-ups are repaired. The evidence supports the implemented functionality and canonical privacy/persistence contracts; it does not establish that every role/state permutation was repeated in a browser. Sparse operational content is not a missing implementation, and acceptance does not require fabricated live posts, acknowledgements, preferences or publications.

Snapshot: the newest checkpoint is **23:55 NZ**. Build 65148 completed successfully and browser verification served **app-Bl0KnTwj.js**. Root's final build **8067** is recorded as live for two subsequent presentation repairs: event-title positioning and suppression of an empty discussion panel. Its actual terminal and narrow fresh-browser verification remain pending at this snapshot. The existing light/dark appearance request is unanswered; this is an approval dependency for appearance comparison, separate from implementation. Do not repeat that request or change appearance without the answer.

## Evidence interpretation

- Source is the production application. The effective design reference is [interaction-mockups/README.md](interaction-mockups/README.md), including its connected and later refinement overlays; prototype simulator checks are not production evidence.
- Existing real-browser evidence is taken from the explicit application checkpoints and named screenshots below. Application showcase records are visibly fictional and command-guarded; they establish presentation and local interaction, while isolated canonical feature tests establish actual writes and denials.
- Passing log endings were inspected. An initial failed run plus its precise passing repair is described as passing evidence for the distinct selected cases, not as a newly green combined run. Overlapping UI selections are never summed. There is no claim of a fresh whole-directory regression run.
- Authorization remains one organisation, current staff, approved sites, canonical ownership and permissions. Existing private guide **12** and document root **5** are explicitly approved private verification content and must remain unpublished. Original user tabs and writing remain protected.

## Family reconciliation

### 1 — Give a shout-out · completion item 16

**Implemented/proven:** the shared recognition wizard supports person/multiple-person/canonical-team selection, contextual search, message-first writing, optional details, review/edit, leave protection and an exact confirmed destination. Source: `resources/js/components/recognition/recognition-wizard.tsx`, `_recognition-submission.ts` and the shared receipt handling. Evidence **R** covers exact receipt identity, team fanout, current actor/staff/site/access, rollback and uncertain/failed sending.

**Browser:** actual composer selection → message focus/live card → optional value/message-only review → edit/discard is recorded with `evidence/shoutout-composer-20261014.png`. The later product-controls browser also checked native-Back cancel/discard and retained selection without sending.

**Remaining:** no source gap. Actual operational sending was deliberately not repeated; canonical feature evidence supplies that contract.

### 2 — Recognition reading, reactions, replies and spotlight · item 16

**Implemented/proven:** `_recognition.tsx`, `_recognition-card.tsx` and shared recognition replies provide a consistent compact Home/card treatment, exact selected reader, labelled reactions, retained collapsed reply writing, author removal and permission/version-bound spotlight removal/Undo. Evidence **R**, plus saved-controls evidence **N**, covers the repaired receipt-to-reader and Undo paths.

**Browser:** Home → board/exact recognition 1, reply collapse/reopen retaining a local draft, native return to Updates, compact Home replies and the full board were checked. `recognition-home-redesign-20261014.jpg`, `recognition-board-delivery-20261015.jpg` and post-restart evidence document the implemented layout and empty console/overflow checks.

**Remaining:** no source gap. There was no current live spotlight to remove, so spotlight removal/Undo remains focused-test evidence rather than a manufactured live curation.

### 3 — Celebrations and individual HR birthday sharing · items 15, 16, 22

**Implemented/proven:** celebrations use permitted day/month disclosure; `_birthday-settings.tsx` provides independent per-person staged choices, explicit versioned saves, account-bound receipts, conflict retention and permission redaction. Canonical `HubCelebrations`/birthday services preserve existing choices. Evidence **B** covers the actor/version/site boundary and rollback; saved-controls/birthday UI evidence covers staging and conflicts.

**Browser:** staged local toggle → Done → Cancel retains choice → Discard → reopen unchanged was verified (`birthday-sharing-staged-save-20261014.jpg`). Post-restart `birthday-editor-ready-20261015.jpg` confirms the approved version migration is active and existing choices were preserved, without a preference save.

**Remaining:** no source gap or outstanding migration for this feature. Additional real birthday preference changes are unnecessary for acceptance.

### 4 — Discussion and moderation · items 13, 22

**Implemented/proven:** `_discussion.tsx`, `_moderation.tsx` and canonical conversation services implement readable side-panel discussion, reply/reaction, retained local writing, author removal distinct from moderator hiding, and authorized hidden-comment review/restore. Evidence **M** covers current publication/account access, unavailable parents and hidden-text boundaries. The final source suppresses an otherwise empty participation card, preserves real feedback and authorized moderation, and independently guards sample commands.

**Browser:** real guide discussion was opened/closed without writing during nested guide/profile return. Recognition reply retention is separately browser-proven. The populated sample Idea also rendered its discussion. No populated moderator restore was performed; denial/restore behavior is feature-tested.

**Remaining:** root's narrow post-build check must confirm the empty poll conversation panel is absent after build 8067. This is a known presentation repair awaiting verification, not an unimplemented moderation system.

### 11 — Announcements and exact-version acknowledgement · items 8, 20, 22

**Implemented/proven:** `_announcement.tsx` and `index.tsx` provide full-page semantic reading, attachments, explicit current-version confirmation and safe return. GET/POST/receipt identity is actor-bound; opening is not acknowledgement. `HubReadingAttention` counts **all** active authorized required notices with current-version receipts, independently of search, pins and feed limits; only authoritative zero displays caught-up. An exact accepted acknowledgement refreshes attention. Evidence **A** includes 53-notice/chunk boundaries, changed editions, current access and the confirmation UI.

**Browser:** the delivered Home authoritative caught-up state, More discovery/unread cues and styled Updates filtering were checked (`home-reading-attention-20261015.png`, `hub-more-discovery-20261015.png`, `updates-styled-filter-20261015.png`). The current accessible data had no populated required announcement.

**Remaining:** a populated long-announcement/attachment/acknowledgement browser action is not recorded. The implemented reader and security contract have direct tests; this is a data-limited evidence qualification, not a reason to create or acknowledge a real notice solely for acceptance.

### 12 — Guides, personal checklists and formatted documents · items 1–12, 21, 22

**Implemented/proven:** `_knowledge.tsx`, `_rich-text.tsx`, `_document-upload.tsx`, `document.tsx`, `_pdf-reader.tsx`, `document-editor.tsx`, `HubKnowledge`, `HubRichText` and document services implement contents/owner/review/checklist behavior; semantic headings/bold/lists/tables/links; direct PDF/Office uploads; bounded queue/progress/cancel/recovery; integrated PDF reading/find/zoom; Office download fallback; exact metadata; private replacement/history/restore and release separation. Evidence **D/K/T** covers actor/version/audience/lifecycle/privacy, meaningful malformed receipts and drafts, upload recovery, cached PDF privacy and hidden content.

**Browser:** the approved private guide 12 was uploaded with PDF v1, replaced by v2, rendered as two pages, advanced to page 2, zoomed to 125%, searched for beta, and restored v1 as immutable version 3/media 7. Alpha search, exact selected-file metadata, return to the guide editor and unpublished status were verified (`document-version-restore-20261015.png`, `pdf-preview-search-20261015.png`, `pdf-restored-preview-20261015.png`). Rich headings/prose/bullets/table including a blank cell were then created, saved/reloaded and reopened in publication/saved-draft v9 preview (`private-guide-saved-formatting-20261015.png`, `private-guide-formatted-preview-20261015.png`).

**Remaining:** no demonstrated source gap. The earlier browser list-loss defect and Herd PDF worker extension failure are repaired and browser-verified. Office fallback and queue failure/cancel permutations have tests; they were not additionally exercised with operational uploads. Private browser evidence does not claim publication or a live published-document restore.

### 13 — Corrections and owner review · items 12, 21, 22

**Implemented/proven:** `_resource-reports.tsx`, `_resource-review.tsx`, `_resource-care.tsx` and canonical report/review services retain report writing/status/history and reporter-visible owner responses, label earlier editions, support review dates/reopen, and enforce actor/page/report versions and publication locks. The date uses the shared Hub calendar. Evidence **B** includes report authorization, earlier revisions, response/version/reopen, pagination, review dates and rollback.

**Browser:** Report a correction opened/closed on the actual published guide; focus returned correctly during the nested resource/profile journey. No live correction was submitted or resolved, and a populated existing report/response was not available in that walkthrough.

**Remaining:** no demonstrated source gap. Populated response history remains a qualified browser omission with direct feature coverage; no new live correction is required to manufacture it.

### 14 — Colleague discovery and work profile · item 15

**Implemented/proven:** `_people.tsx`, `_compact-people.tsx` and `_person-profile.tsx` implement role/team/site/name/expertise discovery, authorized work contacts/reporting/content links, Copy email and a usable denied/unavailable clipboard fallback. Evidence **P** covers current staff/site/permission withdrawal, reporting privacy, expertise discovery and clipboard rejection/missing API.

**Browser:** the actual published guide → available Demo Admin work profile → guide → original resource search/result round trip was verified (`resource-reading-return-20261014.jpg`). New populated team rendering also displayed three fictional people without canonical fake-person routes. Copy behavior is focused-test evidence rather than a claimed browser clipboard execution.

**Remaining:** no source gap; the previous missing Copy email finding is closed. Available work data is authoritative; empty contacts are not replaced with invented addresses.

### 15 — Shared profile editing · items 15, 22

**Implemented/proven:** `_person-profile.tsx` and `HubWorkProfiles` provide own introduction/help topics, pending-skill capture, preview/back, exact-version save, leave protection, and redaction/withdrawal after current permission changes. Evidence **P** tests conflict/account changes, obsolete responses and direct-object denial, including editors being unable to edit another person's shared profile.

**Browser:** authorized profile reading and nested return are recorded. A distinct current own-profile edit/save browser action is not recorded in the newest checkpoints.

**Remaining:** no demonstrated implementation gap. The save/privacy contract is directly tested; do not change someone's real profile merely to repeat it visually.

### 19 — Grouped search and exact destinations · items 9, 19, 22

**Implemented/proven:** `_search.tsx`, `_reader-discovery.ts`, command search and `HubResourceDocuments` provide grouped authorized results/preview, exact story/person/idea/recognition/document destinations, filename/PDF-text search and honest incomplete-PDF fallback. Return metadata survives actual component swaps via guarded `onBeforeUpdate`, actor-bound safe history and destination-mounted one-shot restoration. Evidence **D/T/S** covers warmed-cache privacy, no extracted-text/path exposure, exact-file identity, nested/anchored return and unavailable results. Sample search links remain in showcase.

**Browser:** author search/list and resource search/type/cards → selected reader → restored filter/layout/focus/scroll were checked; real guide/profile nested return was also verified. The private PDF reader's alpha/beta find results are browser-proven.

**Remaining:** no source gap. Published global PDF-content search is feature-tested; the private PDF find check does not falsely stand in for publishing that file or querying the published global index.

### 20 — Saved content and activity · items 10, 19, 22

**Implemented/proven:** `_bookmarks.tsx`, `_saved-documents.tsx`, `_home-saved.tsx`, `_updates.tsx` and canonical bookmarks/reads support exact stable document roots alongside pages/events, remove/Undo/recovery, authorized hydration, recent chronology and distinct seen/acknowledged state. Home's compact three-item summary updates from current saved state and preserves the authored grid width. Shared unread cues receive confirmed mark/Undo keys without losing Undo; late old-source completions cannot overwrite newer state. Evidence **D/T/N** covers document-only Saved, replacement privacy, recurring events, inaccessible roots, actor changes and the actual stale-response race.

**Browser:** the 73px Home Saved strip and correct View all destination were checked (`home-saved-compact-20261015.png`). Exact recognition reading returned to Updates without an unintended seen write. More/Updates showed real unread cues and the styled type filter. Populated file saves and remove/Undo were tested, not written against live content just for screenshots.

**Remaining:** no source gap. Old statements that Home or central Saved omit documents, or that recents are missing, are superseded. The compact strip is an intentional response to the user's oversized-UI feedback, not an accidental missing fixed sidebar.

### 21 — Interests · items 19, 22

**Implemented/proven:** `_interests.tsx` and `HubDiscovery` implement personal topic/team chips, explicit actor/version saves, exact returned selections, preserved unrelated preferences, conflict reload and retained local writing. Evidence **I** includes account/version/current staff/site/team-visibility denial and rollback; personal-control UI coverage tests retention and independent settings.

**Browser:** notification navigation/Manage interests surfaces are recorded, but no distinct populated interests-save browser action is claimed.

**Remaining:** no demonstrated source gap. Persisted selection and privacy have direct tests; changing current follows to manufacture a browser save is unnecessary.

### 22 — Notifications · items 19, 22

**Implemented/proven:** `_notification-preferences.tsx` and canonical notification preferences/delivery services implement independent personal recognition/reply/event/news settings, normalized defaults/versioned saves, conflict retention and protected departure. Required reading remains independent of optional opt-outs; queued delivery rechecks current eligibility/preferences. Evidence **N** retains the initially passing cases plus the exact repaired schema-pending case.

**Browser:** actual native Back with a local News toggle → Cancel retains it → Back/Discard closes panels → reopen confirms saved News true and Save disabled (`notification-navigation-guard-20261014.jpg`). No real preference write occurred.

**Remaining:** no source gap. This existing browser flow plus meaningful save/delivery tests is sufficient representative evidence; no need to repeat every notification category in operational data.

### 23 — Personal shortcuts · items 19, 22

**Implemented/proven:** `_shortcuts.tsx` provides personal add/edit, canonical presets, safe internal/HTTPS destinations, first/last reorder limits, remove/Undo, dirty/pending departure protection and retained failed writing. Its actor-keyed panel uses existing canonical personal preferences, independently of shared navigation. Evidence **N** includes ten focused shortcut cases for the actual local actions/recovery and metadata retention.

**Browser:** Home's personal-entry/navigation surfaces are recorded. No separate full shortcut add/reorder/save browser action is claimed in the newest checkpoint.

**Remaining:** no demonstrated source gap. The missing browser repetition is an evidence qualification; it does not justify altering a person's current saved shortcuts.

### 36 — Resource/Guide discovery and collections · items 1, 2, 6, 9, 10, 12, 19

**Implemented/proven:** `_resource-library.tsx`, `_reader-discovery.ts`, `_collections.tsx`, `HubFeed` and document discovery provide a Guides landing; Create guide/Upload documents/Add useful link entries; team/type/category/collection/owner/topic filters; cards/list; Saved/Recent; file metadata/ownership/review; clear recovery; and permission-bound collections. Typed ordinary guides are included. Exact documents and tagged recents remain separate from parent guides. File projection now also includes visible News/team/campaign attachments; canonical event attachment handling is retained. Evidence **D/T/C** covers authorized projections, searches, collections and hidden/private/expired/current-access cases.

**Browser:** actual Resources/Company guides entry points and upload workspace were checked (`company-guides-library-20261015.png`, `document-upload-workspace-20261015.png`). Showcase resource filters/display/return and populated team Resources 2 were verified. Exact private document metadata and editor reopening were verified as above.

**Remaining:** no demonstrated source gap. A sparse published library is a content condition, not proof of an unfinished library. Add useful link intentionally starts the existing information-guide/Links-block journey, not a new external-resource store.

### 37 — Newsroom/story reading · items 8, 11, 13, 21, 22

**Implemented/proven:** `_discovery.tsx`, `_news-cards.tsx`, formatted block rendering, `_discussion.tsx`, reader navigation and canonical publishing provide author/topic/team search, cards/list, full-page reading, exact saves/discussion and return. News File attachments now reuse exact document metadata/reader/search/Saved/recents and preserve the live edition during private replacement. Shared revision snapshots render file/video descriptions. Evidence **D/T/S/M** covers discovery, discussion and attachment privacy; authoring lifecycle evidence is shared with the authoring reconciliation.

**Browser:** actual built showcase author/list search → long story → return retaining presentation/focus/scroll is recorded (`news-discovery-20261014.png`), with later populated team/campaign story links verified. The private guide proves rich writing save/reopen; no fictional real News publication was made.

**Remaining:** no demonstrated newsroom source gap. Published News attachment persistence is feature-tested; the private PDF browser trial is not described as a live News publication.

## Inspected execution evidence

All paths below are under `storage/logs/`; counts describe their selections, not the number of new tests or a sum across reruns.

- **R — Recognition:** `company-hub-recognition-reviewed-tests.log`: **49 / 3 UI files**. `company-hub-recognition-receipt-agent-runtime-384m-20261015.log`: **7 / 254 assertions**, exit 0.
- **B — Birthdays/corrections:** `company-hub-birthday-resource-review-agent-runtime-384m-20261015.log`: **9 / 164**, exit 0, superseding the audit-key-order fixture failure. `company-hub-designer-agent-resource-reports-final.log`: **11 / 176**.
- **M — Conversations:** `company-hub-discussion-panel-reviewed-tests.log`: **18 / 2 UI files**. `company-hub-moderation-agent-backend-final.log`: **12 / 134**. Latest `company-hub-browser-position-discussion-ui-20261015.log`: **22 / 2 files**, covering the final event/discussion presentation repairs.
- **A — Announcements/attention:** `company-hub-announcement-actor-agent-runtime-384m-20261014.log`: **10 / 165**, exit 0. `company-hub-attention-confirmation-ui-20261015.log`: **14 / 2 files**. The four `HubReadingAttentionTest` cases passed in `company-hub-attachments-attention-agent-runtime-384m-20261015.log`; that combined run was **5 passed / 8 failed**, not green. Its eight attachment failures were fixture-only and all passed in `company-hub-attachments-repair-agent-runtime-384m-20261015.log`: **8 / 398**, exit 0. Thus all **13 distinct selected cases** have passing evidence across the two runs.
- **D — Documents:** `company-hub-document-combined-agent-384m-20261015.log`: **25 passed / 6 failed**; exact repair `company-hub-document-repair-agent-384m-20261015.log`: **10 / 132**, including four overlapping PDF cases. All **31 distinct selected feature cases** have passing evidence: lifecycle 12, discovery 7, rich guides 3, command search 3, bookmarks 6. `company-hub-document-reads-agent-runtime-384m-20261015.log`: **8 / 107**, exit 0. Exact selected-media details: `company-hub-selected-media-details-agent-384m-20261015.log`: **5 / 49**, exit 0. PDF/upload unit logs are `company-hub-document-pdf-unit-20261015.log` and `company-hub-documents-upload-unit-agent-20261015.log`.
- **K — Knowledge/formatting:** `company-hub-knowledge-backend.log`: **5 / 143**; `company-hub-knowledge-ui-final.log`: **18 / 3 files**. `company-hub-rich-text-unit-384m-20261015.log`: **50 / 122**. `company-hub-browser-list-formatting-tests-final-20261015.log`: **51 / 3 files**, after the browser-shaped nested-list repair.
- **T — Current document/navigation integration:** `company-hub-completion-prebuild-ui-20261015.log`: **45 / 4 files**; `company-hub-document-connected-final-20261015.log`: **52 / 4 files**; `company-hub-document-reading-edges-20261015.log`: **41 / 2 files**; `company-hub-home-saved-tests-final-20261015.log`: **33 / 3 files**. Shared unread integration: `company-hub-unread-shared-ui-20261015.log`: **46 / 3 files** and exact race regression `company-hub-unread-race-ui-20261015.log`: **20 / 1 file**. These overlap.
- **P — Profiles:** `company-hub-work-profiles-backend.log`: **9 / 173** across its selected profile/search cases. `company-hub-profile-copy-fallback-tests-20261015.log`: **15 / 1 file**, including missing/denied clipboard API.
- **I — Interests:** `company-hub-interests-actor-agent-backend.log`: **8 passed / 1 failed**; its failure was the existing publishing discovery fixture violating readiness, not an interest denial. The exact case passes in `company-hub-interests-actor-agent-discovery-repair.log`: **1 / 10**. The old interface-run missing `usePage` mock is not a current application issue; latest department/composer/layout integration passes in the populated-preview repair below.
- **N — Personal controls:** `company-hub-notification-review-ui-tests.log`: **40 / 2 files**; `company-hub-shortcuts-panel-final-tests.log`: **10 / 1 file**; `company-hub-saved-controls-reviewed-tests.log`: **86 / 7 files**; `company-hub-reading-review-tests.log`: **38 / 3 files**. Notification backend initially **13 passed / 1 failed** (schema mock fixture); `company-hub-notification-preferences-agent-pending-final.log` passes that exact remaining case **1 / 11**, exit 0.
- **S — Discovery/current integration:** `company-hub-search-preview-tests.log`: **21 / 2 files**; `company-hub-attention-discovery-ui-final-20261015.log`: **63 / 6 files**. `company-hub-populated-preview-repair-ui-20261015.log`: **49 / 4 files** after narrow new-fixture expectation repairs; earlier passing event/attendance/campaign/search cases remain separate. `company-hub-staff-selects-ui-20261015.log`: **63 / 5 files**; the current Hub production native-select scan was recorded as zero.
- **C — Collections:** `company-hub-collections-final-ui-tests.log`: **14 / 2 files**; `company-hub-collection-return-tests.log`: **10 / 1 file**; `company-hub-designer-agent-collections-final.log`: **8 / 187** across its collection/host-link selection.

## Approved 22-item completion reconciliation

- **1–2, Guides landing/clear entry points:** implemented, focused-tested and browser-checked; ordinary typed guides and the existing custom Company guides navigation are preserved.
- **3–7, PDF/Office/upload handling/metadata/replacement/history:** implemented with current actor/edition/lifecycle tests. Real private PDF upload → replace → reader/find/zoom → restore → reopened metadata is browser-proven. Office is download fallback, not a promised inline Office editor; no further operational upload is needed to repeat tested failures.
- **8, Consistent appropriate attachments:** exact document integration now covers News/team/campaign as well as guide/page, with all eight focused attachment cases passing. Event attachments retain canonical event behavior. Announcement files retain their authorized reader contract.
- **9–10, PDF search/exact-file saves:** implemented and privacy-tested, including hidden/expired/warmed-cache denial, stable roots, central Saved, Home summary and document recents. Private reader find has actual browser evidence; published global PDF search and document bookmark mutations have isolated feature evidence.
- **11–12, Formatted writing/ownership/review:** rich lists/tables save/reopen is browser-proven; plain legacy bodies remain compatible. Canonical owner/contact/review/correction boundaries are implemented/tested. Actual content completeness depends on what the organisation authors; missing contacts are not invented.
- **13, News:** discovery/reading/discussion/attachments and canonical publication remain implemented. Existing representative browser discovery/return plus feature evidence are retained.
- **14, Events:** canonical event implementation and populated sample reader/waitlist/gallery evidence are shared with the community reconciliation. Only final post-build event-title positioning remains to verify here; do not reopen every live RSVP/attendance permutation.
- **15–16, People/teams/contacts/recognition:** implemented with representative profile/team/recognition browser coverage and actor/site/privacy tests. Copy email fallback is covered; no real messages or profile changes are required for extra screenshots.
- **17–18, Ideas/polls/campaigns/services:** remain in the full goal, owned by community evidence. The latest actual browser includes local Idea preview/cancel, progress, team content, poll ballot/results and campaign/curation; canonical tests establish persistence. Existing service-row/Learning resources browser evidence remains valid.
- **19–20, Saved/following/updates/search/return and announcements:** implemented with exact identity, safe nested return, authoritative required-reading attention, stable Undo and stale-source guards. Empty operational announcement data limits the populated browser example, not the available feature.
- **21, Exact saved versions/descriptions/review:** current private saved-draft v9 and document immutable restore/history were browser-checked; shared file/video descriptions are rendered and lifecycle contracts are feature-tested. Broader authoring evidence is reconciled separately.
- **22, Common states and desktop appearances:** meaningful tests cover loading/empty/error/retry/stale/current-permission states. Representative built browser flows cover actual layout and return. The pending light/dark appearance approval is the explicit remaining appearance dependency; it is not an implementation defect or blanket requirement to repeat all 37 families in every permutation.

## Finite closeout

For this staff subset, finish the already-running build's actual terminal check and the two already-scoped browser confirmations (event-title positioning and empty poll conversation removal). Retain the pending appearance request without duplicating it. Reconcile the other agents' family evidence before any whole-goal completion claim.

No new staff implementation ticket is justified by this reconciliation. The explicitly qualified missing browser examples above remain honestly unclaimed; direct security/persistence tests and representative connected browser flows are credible evidence for their respective contracts. Do not revive superseded historical asks or create live organisational content to erase those qualifications.
