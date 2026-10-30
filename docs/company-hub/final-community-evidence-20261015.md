# Final community evidence reconciliation — 15 October 2026

Scope: approved interaction families **5–10 and 16–18**, checked against `interaction-mockups/README.md`, its effective community/reading/connected/journey-finish overlays, current production source, existing terminal logs and the newest `RESUME-HERE.md` checkpoint (23:55 NZ). This supersedes the older open browser matrix in `current-acceptance-community-20261015.md`; it does not reduce the approved scope.

Path key: frontend files are under `resources/js/pages/company-hub/`; Hub services under `app/Domain/CompanyHub/Services/`; canonical HR services under `app/Domain/Hr/Services/`; `HubEventController` under `app/Http/Controllers/CompanyHub/`; feature tests under `tests/Feature/CompanyHub/`; named logs under `storage/logs/`. Evidence images are under this document's `evidence/` directory.

Evidence has distinct roles: isolated canonical tests prove persistence, version/actor checks and denial; component tests prove guarded interactions; the built application supplies representative browser/layout evidence. Browser14 used explicitly fictional, read-only application fixtures: no operational registrations, votes, ideas, publications or notifications were created. Prior actual private-event save/reopen/archive remains the runtime authoring evidence. Repeating those operational mutations is not a remaining acceptance requirement.

## 5 — Event reading, RSVP, registration and waitlist

- **Implemented/security:** `_events.tsx`, `_event-reader.tsx`, `_event-detail.tsx` and `HubEventController::show` use the reloadable canonical occurrence reader. RSVP/going/waiting/decline, retained cancelled details, personal reminder, booking cutoff, date/series calendar download and this/all/following cancellation remain canonical. Current staff, audience, approved sites, actor/version/date and current occurrence are rechecked; following-split selection follows the returned identity.
- **Passing evidence:** `company-hub-event-reader-agent-backend.log` **4/188**; cancellation occurrence repair **3/46**; booking repair **9/74**. Relevant tests: `HubEventReaderTest`, `HubEventCancellationTest`, `HubEventBookingActionsTest`. Earlier passing recurrence/privacy cases remain valid alongside their narrow repairs.
- **Browser:** browser14 displayed the full future event, waitlist position2 and safely disabled sample commands, then retained event-browse filters/focus on return. `evidence/event-waitlist-populated-20261015.png` records the pre-fix heading-position issue, not acceptance of its correction. **Open:** only the post-build heading-position recheck below.

## 6 — Event authoring, recurrence, review, scheduling and attendance

- **Implemented/security:** `_event-workspace.tsx`, `_event-drafts.tsx`, `_event-attendance.tsx`, `HubEventPublishing` and canonical calendar services retain private drafts, review/withdraw, independent publication scheduling, cancel-to-edit, exact reviewed files and the existing live edition until release. This/all/following reuse the canonical recurrence graph. Due work uses the existing Hub command; eligibility and queued delivery are rechecked. Attendance is organiser/date/version-bound; offer-place confirms the oldest eligible waiter; CSV contains authorised roster fields and neutralises spreadsheet formulas.
- **Passing evidence:** scheduling repair **12/171**, booking repair **9/74**, plus the calendar-notification initial/repair/final rollback chain (`company-hub-calendar-notifications-agent-rollback.log`: **1/13**). `HubEventSchedulingTest`, `HubEventLifecycleTest`, `HubEventEditorFilesTest` and `HubEventNotificationIntegrationTest` retain privacy, exact snapshot, recurrence, deduplication and atomic rollback coverage. Do not count overlapping runs twice.
- **Browser:** actual private event creation, preview, save, reopen and archive were verified earlier (`evidence/event-page-editor-20261014.png`, `event-reader-current-20261014.png`). Browser14 showed the populated three-person roster, two attended and a Sam filter; export/write stayed unavailable in the sample. **Open:** no demonstrated missing contract; actual dispatcher execution or another live roster mutation is not required for this reconciliation.

## 7 — Recap and gallery

- **Implemented/security:** `_event-story.tsx`, `_event-gallery.tsx`, `HrCalendarHubContent` and `HubEventMedia` retain edited recap title/body, authored gallery order, albums, per-photo caption/attribution and independent authorised library copies. Past-only projection uses the effective completion boundary; future/private photos and inaccessible stories/source media remain excluded. **Thank the team** opens the existing recognition flow with no attendance-derived recipients or automatic post, subject to capability/preview/busy guards.
- **Passing evidence:** event-media final **6 passing/1 fixture failure**, followed by the exact repaired case **1/25**; all seven distinct feature cases have passing evidence. Event-media units **7/57**; gallery and recap/appreciation component selections are recorded separately, including `company-hub-event-recap-appreciation-tests.log` **26 passes**.
- **Browser:** browser14 verified the past recap, three photos/two albums, photo details retained across Next, Back to recap and Back to events, with Photos & recaps/2026 and focus restored. `evidence/event-gallery-populated-20261015.png`. **Open:** none demonstrated; the read-only sample does not claim a recognition send or media publication.

## 8 — Idea write, preview, share and success

- **Implemented/security:** `_idea-composer.tsx` and canonical `HubIdeas::create` preserve category/team/message semantics, Write/Preview/Edit, pending/dirty departure protection and uncertain-outcome locking. Success requires the exact saved actor and inserted Idea ID; canonical refresh resolves that record rather than matching its text. The existing non-idempotent store is not blindly retried after an unknown result.
- **Passing evidence:** `company-hub-designer-agent-idea-create-acknowledgement.log` **4/80**, plus the reviewed composer/creation regressions and current populated-preview component selection. Canonical receipt, actor/current access, redirect compatibility and rollback are covered.
- **Browser:** browser14 performed local Write → Preview → Back to editing → Cancel → Discard, with Share disabled and no real submission. `evidence/idea-preview-populated-20261015.png`. **Open:** none demonstrated; canonical Share persistence is established by the isolated tests, not claimed as a browser write.

## 9 — Idea owner, routing and dated progress

- **Implemented/security:** `HubIdeas`, `_idea-progress.tsx` and `_community.tsx` provide canonical owner/team/status, dated append-only progress and discussion. Actor/version and current owner/team visibility are enforced; routing does not become a private audience. Authoritative selected-Idea projection handles entries outside ordinary search limits and withdraws unavailable records. Independent discussion/progress writing survives confirmed refresh; stale/unknown writes cannot silently replace it.
- **Passing evidence:** `company-hub-ideas-agent-backend-current.log` **12/279**; progress JSON acknowledgement **1/18**; existing selected-Idea, refresh and draft-preservation component regressions remain applicable.
- **Browser:** browser14 showed owner/team, two dated progress entries and discussion. `evidence/idea-progress-populated-20261015.png`. **Open:** none demonstrated; another operational owner assignment/progress post is not needed to replace the earlier empty-board browser limitation.

## 10 — Polls

- **Implemented/security:** `_poll.tsx`, authoring settings and `HubPolls` use the current visible published block/question identity, one current personal choice and exact closing time. Results are withheld before the viewer votes unless closed, including for editors. Close/reopen requires current actor/revision/state and a future reopening deadline; changed questions do not inherit old votes, and hidden/private/withdrawn content cannot be mutated.
- **Passing evidence:** lifecycle repair **8 passes** with its exact denial repair **1/22** (`company-hub-poll-denial-agent-final.log`); current poll/participation and styled-control component selections remain passing. The final presentation repair selection is `company-hub-browser-position-discussion-ui-20261015.log` **22 passes/2files**.
- **Browser:** browser14 showed the unvoted ballot and current-viewer closed results **30/50/20%, 10 responses**, without voting or managing the poll. `evidence/polls-populated-20261015.png`. **Open:** post-build removal of the observed empty ordinary-page conversation card, below.

## 16 — Team spaces

- **Implemented/security:** `_department.tsx`, `HubDepartment` and canonical publishing provide Overview/Updates/Resources/Our team, authorised colleagues and personal follow/unfollow. New update uses the current published teams/audience in a new owned private draft. Hero copying additionally requires source edit permission and uses independent files with rollback compensation; reader-only source access does not grant media-copy permission.
- **Passing evidence:** use the later `company-hub-department-resume-backend.log` **4/89**, not the superseded failing `department-backend-final` log. The campaign/hero **10/254** selection also covers copy permissions/independence/rollback. Current populated-preview component selection **49 passes/4files** includes Team/demo navigation guards.
- **Browser:** browser14 verified populated Updates2, Resources2 and Our team3 (`evidence/team-populated-20261015.png`). The earlier empty-local-team limitation is superseded for representative rendering; following/update persistence remains canonical test evidence. **Open:** none demonstrated.

## 17 — Campaigns

- **Implemented/security:** `HubCampaign`, `_campaign-reader.tsx`, `_campaign-settings.tsx` and `_campaign-curation.tsx` preserve published shared done/active/next milestones and target date separately from personal activities. Ordered canonical stories/resources/exact upcoming event occurrences are validated on authoring/publication and redacted from both reader cards and raw reference arrays when unavailable. Personal activity never changes shared progress.
- **Passing evidence:** `company-hub-campaign-agent-backend-final.log` **10/254** comprises six campaign and four source-image cases; current campaign/poll and styled-select component coverage remains applicable. News/team/campaign File attachments additionally pass the latest **8/398** integration selection without granting draft access.
- **Browser:** browser14 verified three shared milestones, two personal activities, curated resources/events, correct campaign-editor destination and the styled milestone menu. `evidence/campaign-populated-20261015.png`, `campaign-curation-populated-20261015.png`. **Open:** none demonstrated; sample completion/publication stayed disabled.

## 18 — Canonical service handoffs

- **Implemented/security:** `HubServices`, `_staff-services.tsx` and `_services.tsx` expose complete clickable canonical HR, learning/resources, profile, maintenance and IT routes subject to current roles/sites/staff. IT catalogue/request/public-reply/resolution uses the existing service system; there is no second Hub request store. Work contacts are current and site-visible; permission withdrawal is rechecked.
- **Passing evidence:** `company-hub-staff-services-backend.log` **7/125**, service component **21 passes**, canonical request lifecycle `company-hub-service-lifecycle-backend.log` **1/81**, and the later passing updated Learning resources regression. Current category menu is covered by the **48-pass/5file** community-controls selection.
- **Browser:** actual Services rows and Learning resources → Hub Resources were verified (`evidence/staff-services-rows-20261015.jpg`). **Open:** none demonstrated; another operational service ticket/reply is not required to establish the already tested canonical handoff.

## Exact remaining acceptance

1. **Build8067 and two browser-discovered presentation fixes:** root owns the live build/log `company-hub-browser-position-build-20261015.log`. After actual successful completion, verify event heading/focus clears the sticky app/Hub rail and a poll page with no ordinary conversation controls has no empty discussion card. Current source includes `preventScroll` + aligned scroll target in `_event-reader.tsx` and the empty-panel guard in `_participation.tsx`; the 22-case component selection passes. The earlier screenshots predate these two fixes.
2. **Existing appearance comparison:** still awaits the outstanding user popup response. No new appearance/role matrix or repeated permission request is introduced here; do not switch appearance without the answer.

No additional missing approved community functionality or backend contract was demonstrated in this reconciliation. Representative populated browser gaps from the older matrix are now satisfied; persistence and security evidence is not replaced by fictional screenshots, and screenshots are not claimed to prove operational writes. This is bounded evidence for the full goal, not a declaration that all37families/22items are complete.

Audit actions: read source/checkpoints/log endings and confirm referenced screenshot files exist; wrote this document only. No new tests, builds, browser session, database action, notification or application-source edit. This agent owns no worker. One operating organisation across approved sites remains the authorization model.
