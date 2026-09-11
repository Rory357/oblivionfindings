# IT & Support: daily-use readiness audit

Date: **8 September 2026**. Checkout: `C:\Users\steph\Herd\oblivionfindings`; reviewed HEAD `c4817a1df`. Live application: https://oblivionfindings.test. **Audit only; no application or design-guide changes.**

This is a **web application only**. Phone-width observations and acceptance criteria refer to responsive web pages in a browser. No native mobile application, separate mobile product, or MSP/multi-tenant architecture is proposed.

## 1. Readiness assessment

**Not ready for the organisation to rely on every day.** There is a substantial service-management foundation, and a supervised technician workflow works. However, request submission reliability, truthful operational indicators, email processing, configured ownership, and responsive list usability have not reached daily-use acceptance. These should precede a large feature expansion.

The successful browser journey is meaningful: a technician created IT-000007, assigned it, added a required task, posted a public reply and an internal note, completed the task, resolved the ticket with a public explanation, reopened it with a reason, resolved it again and explicitly closed it. Its activity history records the transitions. An incomplete required task correctly prevented resolution. Requester privacy also passed two focused checks: a worker could see their own conversation without its internal note, and direct navigation to another requester's ticket returned 404.

The most consequential gaps are:

- A requester submission ended in **504 Gateway Time-out**. No new ticket existed in the subsequent read-only check. The next navigation required sign-in. This is an observed local failure, not a proven application-code root cause; session, PHP and proxy behaviour need a controlled retest.
- The desk said **“SLA healthy”** and **“Every open ticket is comfortably within SLA”**, although one open ticket had August deadlines and no response, five others had no deadlines, and the SLA automation had no recorded run.
- Email and monitoring intake use different creation/interaction paths from browser tickets. Important routing and lifecycle behaviour is therefore absent from those paths.
- Microsoft and Google mailbox connections are not configured locally. Separately, the existing SSO configuration page contains provider/provisioning Save buttons without save handlers. A polished settings surface does not establish working configuration.
- There are no configured teams or queues in this fixture. New technician work can have an assignee but no accountable owner. At 390 px, technician ticket titles become unreadable fragments and SLA/age content overlaps.
- Escape discards an unsaved ticket draft without a warning. A blocked resolution has no persistent inline explanation of the required task that prevented it.

**Recommendation:** improve the existing services and shared UI incrementally. Do not rewrite ticketing, introduce another sidebar, duplicate Assets/Devices, build a second credential store, or treat route/test existence as evidence of completion. A controlled pilot becomes reasonable only after Phase 1 below passes; email and automatic ticket intake require Phase 2 acceptance before relying on those channels.

## 2. Scope, architecture and evidence standard

The boundary is one operating organisation across approved sites. Follow [the architecture contract](C:/Users/steph/Herd/oblivionfindings/docs/architecture/single-tenant-application.md): roles, permissions, active approved sites, canonical ownership, direct-object denial and privacy. Existing tenant/organisation columns are not a new product boundary and were not changed. Microsoft's Entra directory/tenant identifier is an identity-provider setting, not a reason to add application tenancy.

Design review used [DESIGN.md](C:/Users/steph/Herd/oblivionfindings/DESIGN.md), the page-header, navigation, list, popup and app-shell guides in `design_styles/`, and the live `/sites` reference. The approved dense IT header can use eight meters in two rows; **that arrangement itself is not a finding**. Rail wrapping, responsive legibility, inconsistent detail pages and misleading meter content are the findings.

### Actual users and boundaries

- Demo Admin, existing user 62, role `admin`: `it.request`, `it.view` and `it.manage` all allowed; approved site 1 in the runtime check. Used to exercise technician work, not to prove a restricted technician role behaves correctly.
- Support Worker 1, existing user 68, role `support_worker`: `it.request` allowed; `it.view` and `it.manage` denied; approved sites 1 and 9008. Used for requester browser checks. No account, permission, password or site assignment was changed.
- [`ItWorkAccessService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItWorkAccessService.php) combines requester/requested-for participation with staff permissions, approved sites, assignment, ownership and team/queue membership. Organisation-wide work and sensitive work have separate permission checks. [`ItTicketPolicy`](C:/Users/steph/Herd/oblivionfindings/app/Policies/ItTicketPolicy.php) governs replies, resolution, merge, approvals and reopening. These are valuable controls to retain; the audit is not a complete authorization penetration test.

### Evidence labels

**Working** means the described slice was demonstrated, with stated limits. **Partial** means useful implementation exists but the journey has gaps. **Missing** means no implementation was found in the focused IT paths and UI examined, not a repository-wide proof of absence. **Blocked** means configuration or access prevented verification. **Unverified** means not exercised end to end. Supporting tests were read, not treated as passing executions.

Browser evidence is in [the evidence directory](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence). Some early `.txt` captures contain accessibility-tree diffs only; the later main-content captures and screenshots carry fuller context. Findings below explicitly separate browser observations, source findings and proposed behaviour.

### Host and asset verification

- Herd's parked roots include `C:\Users\steph\Herd`; no named Sites override for `oblivionfindings` was found. Its Nginx host configuration uses the Herd/Valet server for `oblivionfindings.test`.
- Read-only application bootstrap returned this repository's `base_path` and environment `local`. There was no `public/hot` override.
- Live DOM loaded `/build/assets/app-B4I8JQ8g.js` and `app-pjifONb0.css`, matching this checkout's `public/build/manifest.json`. The IT manifest entry is `assets/index-Cc4VZsgq.js`. Manifest timestamp was 8 September, 15:22 NZ; current IT source/header files predated it. The fresh browser showed the current eight-meter header and Find control.
- This combination supports the host/checkout/current-assets match; a build was not necessary. An older already-open browser view differed, so fresh in-app navigation was used for conclusions.

## 3. Feature completeness inventory

Each entry includes the specific remaining gap. Source links describe implementation evidence, not execution results.

### Intake and daily ticket work

1. **Technician creation — Working for the tested path.** `/it` → Log ticket → three-step wizard → IT-000007 → full detail. Site, category, normal priority and assignee persisted; SLA deadlines were stamped. [Created-ticket evidence](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/created-ticket.txt); [`ItTicketIntakeService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItTicketIntakeService.php:42). On-behalf-of creation, attachments and alternate-scope cases remain unverified.
2. **Staff self-service — Partial; submission acceptance blocked by an observed timeout.** Clear “Get help”, everyday categories and plain-language urgency choices are good. Own-ticket browsing and privacy work. A minimal disposable submission timed out, with no ticket found afterward. There is no affected-site selector for a worker approved at multiple sites; the service chooses `defaultSiteId`. [Requester evidence](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/requester-desktop.txt); [`RaiseTicketDialog`](C:/Users/steph/Herd/oblivionfindings/resources/js/components/it/it-wizards.tsx:1517); intake service line 55.
3. **Incident and service requests — Partial.** Incident creation was exercised. The published “Request managed network access” catalogue item and its business-reason form opened; submission/approval/fulfilment was not exercised. [`ItCatalogSubmissionService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItCatalogSubmissionService.php) is the reuse point for governed forms and workflows. Distinguish “something is broken” from “I need something”.
4. **Teams, queues and assignment — Partial; setup absent locally.** Assignment to Demo Admin worked. Setup displayed zero teams and queues; rows said “Queue not configured” and “Team not configured”. Routing rules, active teams, default queues and service ownership exist in [`ItTicketRoutingService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItTicketRoutingService.php). Reassignment and real configured routing were not demonstrated. Lack of fixture configuration is not lack of feature code.
5. **Priority, impact, urgency, category, service and site — Partial.** Technician priority/category/site/service controls exist; requester urgency is understandable. The model supports impact/urgency, but the normal ticket editor does not offer a usable impact × urgency decision. Requester site selection is absent. Normal properties cannot manually choose a responsible queue/team; routing derives those fields. See [`ItTicketTriageService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItTicketTriageService.php) and [ticket detail](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/it/tickets/show.tsx).
6. **Search, sort, filters, saved views and bulk work — Partial / partly Unverified.** Search and many scoped filter controls, sortable headings and row selection are present. [`ItSavedTicketFilterService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItSavedTicketFilterService.php) and bulk routes exist. Saved-view persistence and bulk mixed-permission outcomes were not executed. Custom list rows lack the shared list's caption/card toggle and obvious semantic title links. Mobile row legibility fails.
7. **Public replies and internal notes — Working for browser happy paths; Partial across channels.** Both were posted on IT-000007. The requester could not see the internal fixture note on IT-000001. First response was stamped after the public agent reply. Browser lifecycle checks live in [`ItTicketInteractionService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItTicketInteractionService.php); inbound email bypasses it. The actor being both requester and technician also produces confusing author/reopen wording.
8. **Attachments — Partial / Unverified.** Browser upload controls, MIME/count/size validation and canonical attachment storage/download authorization exist. [`StoreItTicketRequest`](C:/Users/steph/Herd/oblivionfindings/app/Http/Requests/It/StoreItTicketRequest.php:19), [`ItAttachmentStorageService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItAttachmentStorageService.php). No file upload, failed upload, download-denial or malware-handling demonstration was run. Mail adapters do not implement equivalent attachment ingestion.
9. **Watchers and notifications — Partial / Unverified delivery.** Watch and watcher controls exist; notification recipients are scoped in the domain services. No live notification delivery was attempted. Local mail was `array` and the queue `sync`; “accepted” in this environment does not prove external delivery. Delivery ledger and retry UI are useful existing capabilities.
10. **SLA targets, business hours, waiting and escalation — Partial.** Browser-created deadlines and first-response stamping worked. Priority policies, business-hour calculation, requester/vendor waits and an hourly watchdog exist. Stored health can be stale or unmeasured, and the UI asserts health anyway. Business-hours/holiday/pause arithmetic, reprioritization and after-hours escalation need focused tests. [`ItTicket`](C:/Users/steph/Herd/oblivionfindings/app/Models/ItTicket.php:357), [`CheckItSlaStates`](C:/Users/steph/Herd/oblivionfindings/app/Console/Commands/CheckItSlaStates.php), [`ItAutomationScheduleCatalog`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItAutomationScheduleCatalog.php).
11. **Tasks, dependencies and approvals — Partial.** Required task creation/completion and the unresolved-task resolution guard were demonstrated. Dependency/evidence enforcement and approval transitions have services and focused tests, but no approver browser journey was executed. [`ItWorkTaskService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItWorkTaskService.php), [`ItTicketApprovalService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItTicketApprovalService.php). Block reasons need to be visible where the user acts.
12. **Linked work, canonical assets/devices and merging — Partial.** Asset/device selectors and linked context exist; merge dialog and source service exist. No merge was committed and no canonical device was linked during this audit. [`ItTicketLinkService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItTicketLinkService.php), [`ItTicketMergeService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItTicketMergeService.php). Duplicate suggestions and a visible “existing incident” decision before creating another ticket are not established.
13. **Concurrent editing — Partial backend safeguards; missing user collision handling.** Row locks and transactional state checks protect transitions. Normal property updates carry no expected version, and no editor-presence/collision warning was found. Sequential stale updates can overwrite a field even when each transaction is valid. No two-technician browser collision was simulated.
14. **Resolution, closure, confirmation and reopening — Working for technician path; Partial overall.** Public resolution note, required-task guard, reasoned reopen and explicit close all worked. Settled conversation became read-only; automatic closure is described as seven days but its scheduler was not run. Requester confirmation/CSAT/reopening were not executed. The standard resolve path uses the existing code or `restored`, with no meaningful resolution-code choice; full detail does not expose the optional draft-KB success action. [`ItWorkTransitionService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItWorkTransitionService.php), [closed-ticket evidence](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/ticket-closed.txt).
15. **Time entries, timers and effort — Missing in the examined IT workspace.** No ticket work-log/timer capability was found. Existing workforce timesheets are not evidence of ticket effort accounting. Add lightweight internal effort visibility only if operationally useful; MSP billing is outside this scope.
16. **Reusable replies, technician templates and macros — Missing/Partial.** Catalogue forms, provisioning workflows and knowledge insertion are reuse points. No governed canned-reply or multi-action macro library was found in normal ticket handling. Knowledge insertion currently tells the reader to search for an article instead of providing its canonical link.
17. **Scheduling, dispatch, reminders and recurring tickets — Missing/Partial.** Task due dates and scheduled change windows exist; dedicated technician bookings, cover, ticket recurrence and reminder workflows were not found. Existing calendar/task infrastructure should be assessed before adding another scheduling store.

### Operations, integrations and knowledge

18. **Problems, Changes and Major Incidents — Partial; lifecycle Unverified.** All three registers opened with seeded records. Changes showed an overdue maintenance window; Major Incidents showed an overdue next update and named commander/communications owner. Their domain services provide substantial workflow depth. These pages still use different header/list patterns. Do not call these modules missing; validate their distinct transitions and links in a later focused pass.
19. **Reporting, exports and audit — Partial.** Reports rendered workload, demand, ageing, service/device context, data quality, CSAT and outcome sections. CSV links are backed by scoped export code with CSV-cell sanitation. Files were not downloaded or reconciled. The header and report body can show different snapshots after another tab changes a ticket. Activity history worked on the new ticket; reporting definitions and unknown SLA coverage need correction. [`ItReportsController`](C:/Users/steph/Herd/oblivionfindings/app/Http/Controllers/It/ItReportsController.php:40), [report evidence](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/reports-desktop.txt).
20. **Automation configuration and failure visibility — Partial.** `/it/setup` → Operations audit exposes coverage gaps, delivery failures, retries and three scheduled automations. All schedules showed “Awaiting run”. This is not proof that the host scheduler or production workers run. API identities and scoped service-account services exist; no identity was created or used. Durable monitoring/listener failure review needs to join this operational view.
21. **SSO setup — Partial with confirmed source gaps.** The SSO page opens, and group mapping has controller/routes. Provider/provisioning forms have local state and Save buttons without handlers; the controller supplies mappings/roles/stats, not the displayed provider configuration. Authentication routes and identity integration exist separately. Real provider sign-in and settings persistence were not tested. See Finding F04.
22. **Microsoft 365 / Gmail mailboxes — Blocked locally, Partial in source.** Both show “Not connected” and missing OAuth configuration. Delegated mailbox/token storage, polling, deduplication, quarantine and delivery records exist. Threading, failures, attachments, outbound semantics and intake parity have gaps detailed below. SSO success would not certify these paths.
23. **Automatic Security & Devices tickets — Partial.** A registered listener handles infrastructure offline/recovery events with canonical device/site links and open-ticket deduplication. It requires a Control Room alert and directly creates tickets without the shared routing/intake path. No synthetic monitoring event was injected. Fleet-to-IT and nonurgent direct-to-IT paths are not complete in the examined code.
24. **Knowledge articles — Partial.** One published article appeared in the technician list; its actions opened the four-step editor. Draft/review/publish/retire, audience, owner, service, review date and feedback counters exist. Title click did not open a reader from the technician register. Published content can be edited in place; no immutable revisions/reviewed publication copy was found. Requester reader/feedback was not completed in the browser.
25. **Structured IT documentation — Missing as an integrated workspace.** Existing articles, services, Sites, vendors and canonical assets/devices are useful foundations. Structured systems/applications/runbooks, documentation relationships, revisions, attachments/diagrams, review queues and renewal workflows are not delivered together. Existing known errors belong to Problem Management and should be linked, not recreated.
26. **Sites credential vault — Partial; browser secret operations Unverified.** `/vendors` is the canonical global entry, with site-specific credentials underneath. The local view contained zero vendors/credentials. Encryption, hidden secret fields, permission/site checks, reauthentication, reveal audit and a 30-second reveal timeout exist in code. No secret was entered, revealed, copied or captured. Copy auditing is best-effort in the frontend, and “rotation” can merely mark a date. See the shared-vault assessment below.
27. **Responsive web, keyboard access and design coherence — Partial.** Desktop overview is closer to the approved Sites header; ticket detail and specialized registers diverge. At 390 px both ticket lists are too compressed; the technician list is unusable for scanning titles. The wizard reflows into a readable narrow dialog, but Escape loses its draft. A full keyboard/focus/contrast audit was not performed; custom clickable rows and compact controls need specific acceptance checks.

## 4. Prioritized findings

Priority here is delivery order: **P1 daily-use/channel blocker**, **P2 significant workflow or consistency problem**, **P3 enhancement**. It is not a security vulnerability severity score.

### F01 — P1: requester submission did not complete

**Observed:** Support Worker 1 submitted a minimal normal-priority printer request. The UI remained on “Raising…” and eventually displayed a 504 response. A subsequent safe query showed only the closed audit ticket 7 beyond the original six; no second audit record existed. The next navigation returned to login. [Screenshot](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/requester-submit-504.png).

**Impact:** staff cannot know whether IT received the request, and retrying risks duplicate work in ambiguous outcomes. **Qualification:** this session spanned interruptions; the failure is not yet reproduced under a fresh uninterrupted session, and cannot be confidently attributed to ticket code rather than local session/PHP/Herd behaviour. A filtered log inspection did not establish a causally matched stack trace.

**Correction/acceptance:** trace a fresh request with a correlation ID through validation, transaction, response and notification delivery; keep notification transport out of the response-critical path where appropriate. A fresh requester must receive a reference or an actionable failure with the draft intact. A timeout retry with the same submission identity must produce exactly one ticket. Do not declare readiness merely because technician creation succeeds.

### F02 — P1: SLA health claims conceal unknown and overdue work

**Observed and source-confirmed:** ticket 5 remained open with first response due `2026-08-11T00:10Z`, resolution due `2026-08-13T00:10Z`, no first response and `sla_state=ok`. Tickets 1–4 and 6 had no deadlines. The header/overview still asserted universal health. [Runtime evidence](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/runtime.json); [`summary`](C:/Users/steph/Herd/oblivionfindings/app/Http/Controllers/It/ItProvisioningController.php:1067), [`it-hero`](C:/Users/steph/Herd/oblivionfindings/resources/js/components/it/it-hero.tsx:66), [`it-overview`](C:/Users/steph/Herd/oblivionfindings/resources/js/components/it/it-overview.tsx:145).

**Impact:** the service desk can overlook an actual overdue request and represent unmeasured work as safe. Legacy unstamped records are not automatically proven breaches, but must be “unmeasured”. The headline compliance denominator also uses all resolved tickets instead of the supplied measured count.

**Correction:** show coverage and calculation freshness; use one clock verdict for detail, queues and reports; distinguish met/breached/unmeasured; configure and verify scheduler heartbeat. Reconcile baseline clocks with an explicit policy for old records rather than inventing historical compliance. The hourly watchdog's urgent-unassigned threshold of 30 minutes also needs an agreed maximum escalation latency.

### F03 — P1: email paths do not preserve normal ticket lifecycle

[`InboundEmailIngestor`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/InboundEmailIngestor.php:55) directly creates comments and new tickets. It does not call the browser interaction/intake services. New email tickets therefore miss normal routing/ownership and receipt behaviour; replies bypass settled-conversation checks, requester-wait resumption and the usual communication side effects. Browser replies enforce these in [`ItTicketInteractionService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItTicketInteractionService.php).

**Impact:** the same message has different operational meaning depending on whether staff send it through the browser or email. **Correction:** use a common authorized command boundary with channel-specific identity and idempotency adapters. Explicitly decide whether mail to settled work requests reopening or starts related work; never silently append a public comment to a closed ticket. This is a source-confirmed gap; live mail was not sent.

### F04 — P1 for self-service setup: SSO Save controls are unwired

[`sso-config.tsx`](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/settings/sso-config.tsx:305), lines 453 and 597, render Save Microsoft/Google/Provisioning buttons without handlers or enclosing submission logic. [`SsoConfigController`](C:/Users/steph/Herd/oblivionfindings/app/Http/Controllers/Settings/SsoConfigController.php:13) returns group-mapping information, not the provider configuration prop used by those forms. Group-mapping persistence is separate and does exist.

**Impact:** administrators can type configuration into plausible controls without saving it, and displayed connection state is not a reliable provider-health result. **Correction:** connect these forms to the canonical settings/auth configuration with masked secrets, validation and explicit save/test feedback, or make deployment-managed fields clearly read-only. Keep authentication, support-mailbox authorization and outbound delivery distinct in the same Settings system. Do not add a second SSO settings page.

### F05 — P1 for email: provider failures can look like a healthy empty inbox

[`MicrosoftGraphService`](C:/Users/steph/Herd/oblivionfindings/app/Services/MicrosoftGraphService.php) and [`GoogleGmailService`](C:/Users/steph/Herd/oblivionfindings/app/Services/GoogleGmailService.php) return an empty message list on certain non-success responses. [`PollItMailboxJob`](C:/Users/steph/Herd/oblivionfindings/app/Jobs/PollItMailboxJob.php:98) ignores the Boolean mark-read result and stamps `last_polled_at`/clears errors after the loop. Thrown errors instead mark a connection `error`, while later runs select `connected` only.

**Impact:** mail can stop arriving without a truthful failure signal, or a transient error can require reconnecting. The bounded unread fetch of 25 without paging, especially oldest-first Microsoft fetching with repeated mark-read failure, can starve subsequent messages.

**Correction:** distinguish an empty inbox from transport failure; retain checkpoints and retryable state; honor provider retry delays; paginate; report consecutive failures and last successful ingestion. Test expired/revoked tokens, refresh failure, throttling, mark-read failure and recovery without duplicate tickets.

### F06 — P1: automatic tickets lack the required direct-to-IT route and ownership

[`DeviceEventObserver`](C:/Users/steph/Herd/oblivionfindings/app/Observers/DeviceEventObserver.php:153) processes a Control Room signal before publishing the device event. [`CreateOrUpdateMonitoringTicket`](C:/Users/steph/Herd/oblivionfindings/app/Listeners/It/CreateOrUpdateMonitoringTicket.php:31) only handles `it_infrastructure` offline/online events and requires an alert for failures. It directly creates a system ticket without shared routing/service ownership. Existing Fleet signals use an idempotent outbox and enter Control Room; no direct Fleet-to-IT handoff was found in the examined paths.

**Impact:** nonurgent technical issues cannot reliably bypass the Control Room queue, and created work can lack an accountable team/owner. **Correction:** add an explicit classification/handoff policy atop existing events and outboxes, with one canonical linked technical work item per issue episode. Preserve urgent operational response in Control Room. See section 6 for the event policy.

### F07 — P1 deployment prerequisite: accountable ownership is not configured

Zero active teams/queues were shown in Setup and runtime metadata. New ticket 7 had Demo Admin as assignee but no owner, team or queue. This is primarily configuration/readiness debt, not proof of broken routing code. Populate the existing setup with a fallback queue, responsible team, service owners, cover and escalation recipient, then demonstrate assignment/absence/escalation on representative sites. Make “unassigned”, “no accountable team”, and “configuration missing” distinct. Never display “queue healthy” just because its fixture count is zero.

### F08 — P1 for phone-width web use: lists do not remain readable

At 390 × 844, [the technician header](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/it-tickets-mobile.png) takes more than a screen before rows. [Rows](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/it-tickets-mobile-rows.png) reduce titles to narrow fragments and overlap SLA/age text. [Requester rows](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/requester-mobile.png) truncate almost all distinguishing titles. [Detail](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/ticket-mobile.png) stacks more successfully but truncates its title and pushes properties/tasks below a long conversation.

Use the approved responsive list/card patterns; preserve readable title, reference, status, accountable person and next-action/SLA information. Keep the approved compact visual controls, while providing usable hit areas and focus. Acceptance must include 390 px and desktop browser journeys, without adding a separate app.

### F09 — P1/P2: drafts and blocked transitions need reliable feedback

After entering a title in Log ticket, Escape dismissed the wizard; reopening showed an empty title and no discard warning. [Empty reopened draft](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/ticket-draft-lost.png). Required-task resolution was correctly rejected, but the dialog returned without a persistent explanation; the note remained. [Resolution feedback capture](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence/required-task-resolution-feedback.png). Backend guard: [`ItWorkTransitionService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItWorkTransitionService.php:336); controller flash error: [`resolveTicket`](C:/Users/steph/Herd/oblivionfindings/app/Http/Controllers/It/ItProvisioningController.php:680).

Implement dirty-close confirmation/recoverable drafts and a persistent error summary with a direct link to blocking tasks/approvals. Review composer success callbacks on domain-error redirects so “sent” and body clearing only follow a committed comment. That composer risk is source-derived, not reproduced here. Single-step “Completeness 100%” while required fields are empty is misleading and should follow the popup contract.

### F10 — P2: “Awaiting reply” measures first response, not whose turn it is

The queue/filter uses `first_responded_at IS NULL` ([controller line 729](C:/Users/steph/Herd/oblivionfindings/app/Http/Controllers/It/ItProvisioningController.php:729)); the summary does likewise at 1069. Once an agent has replied, a later requester question will not enter this queue. Track last public speaker and next-action responsibility independently of the first-response SLA; exclude internal notes and automated acknowledgements. Keep “not yet first-responded” as a separate analytical measure.

### F11 — P2: counts, refresh and list affordances are inconsistent

Requester “My tickets” showed 0 above five records because its badge is `summary.my.waiting` ([index line 532](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/it/index.tsx:532)). A second tab retained old header counts while Reports fetched current data. Fresh reload reconciled those snapshots; this is refresh coherence, not proven database corruption. The technician rail wraps into two rows around the current desktop width, disconnecting the active tab from the bottom edge. Custom lists omit the shared count caption and view toggle; ticket titles are not obvious keyboard-operable links.

Use documented count semantics and invalidate/refetch related summary/report props after mutations or on returning to the page. Bring header, breadcrumb, filters, rail and list behaviour into the existing shared contracts, using `/sites` as the reference. Do not shrink everything to force seven destinations into one rail.

### F12 — P2: staff cannot report an issue at a different approved site

The tested worker has two approved sites. The form has no site field and `ItTicketIntakeService` ignores requester-supplied site in favour of `defaultSiteId`. This enforces a boundary but also mislocates cross-site work. Offer only approved active sites, default sensibly, and visibly confirm where help is needed; preserve direct-object denial for all other sites.

### F13 — P2: published knowledge has no safe revision boundary

[`ItKbLifecycleService::update`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItKbLifecycleService.php:62) fills and saves article content irrespective of published state. The audit records changed field names, not an immutable content revision. Editing can therefore change a reviewed article without publishing a new reviewed version. Keep published readers on the last approved revision while edits are reviewed; provide compare/restore and persistent article URLs. Clarify whether site audience restricts technicians too: current agent KB loading is broad, whereas requester publishing is scoped. Do not put restricted documentation in it until that policy is explicit and verified.

### F14 — P2 before shared-vault expansion: copy and rotation claims are too strong

[`_dialogs.tsx`](C:/Users/steph/Herd/oblivionfindings/resources/js/pages/sites/credentials/_dialogs.tsx:548) attempts copy audit but does not check the response and still writes the already-revealed value to the clipboard after failure. The original reveal is audited, but a complete copy audit trail is not guaranteed. [`SiteCredentialController`](C:/Users/steph/Herd/oblivionfindings/app/Http/Controllers/Sites/SiteCredentialController.php:218) can mark a credential rotated without changing it; re-encrypting storage also does not rotate the external password.

Separate reveal/copy intent, successful browser copy feedback, external rotation attestation and encryption-key maintenance. Require a recorded authorization/audit event before the product offers copy; a browser cannot prevent users copying a value already displayed. Require a meaningful rotation workflow/evidence. Password-only reauthentication also needs an SSO-compatible step-up design before relying on the vault for SSO-only staff.

### F15 — P2: concurrent editors can overwrite a newer field without warning

[`ItTicketTriageService`](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/Services/ItTicketTriageService.php) locks and validates the current row, but the normal patch request includes no expected version. This protects atomicity, not stale intent. Use a version/check token on changed fields, return current values on conflict, retain drafts and provide an explicit reapply/refresh decision. Advisory “another technician is viewing/replying” can follow; it does not replace server conflict checks.

### F16 — P2: handoffs and outcomes are harder to use than their backend depth suggests

Quick drawer → full detail is required for much triage/work. Detail uses an older header and breadcrumb without Home, while specialized registers use yet another pattern. Resolve success from full detail omits the optional draft-KB action supported elsewhere. Resolution code is effectively fixed on the common path. Keep one canonical ticket route, deliberate quick-peek/full-work roles, direct links to blocked work and resolution documentation, and clear “waiting on whom / next update / accountable owner” language.

## 5. Microsoft 365 and Gmail: reuse and completion plan

### What already exists

- Keep **Settings → SSO** (`/settings/sso`) for sign-in/provisioning/group mapping. Repair its provider/provisioning persistence or clearly describe deployment-managed values.
- Keep **Settings → IT mailbox** (`/settings/it-mailbox`) as the canonical support-mailbox connection surface. Link to it from IT Setup; do not fork it. [`ItMailboxOAuthController`](C:/Users/steph/Herd/oblivionfindings/app/Http/Controllers/Settings/ItMailboxOAuthController.php) uses Microsoft/Gmail OAuth and encrypted hidden tokens in [`ItMailboxConnection`](C:/Users/steph/Herd/oblivionfindings/app/Models/ItMailboxConnection.php:49).
- Microsoft uses delegated read/write/shared-mailbox scopes and offline access; Gmail uses mailbox modify scope. These are distinct from sign-in scopes and do not establish the configured outbound sender. Verify permitted mailbox identity and consent scope explicitly, using approved provider accounts in a later integration test.
- Reuse `PollItMailboxJob`, inbound-message records, reference resolver, quarantine and the existing `ItEmailDeliveryService` ledger/callback/retry UI. Unknown/inactive senders are quarantined; sender authorization and ambiguous reference checks exist. Cache locking plus message-ID records provide useful duplicate protection.

### Remaining channel contract

1. **Connect:** show credential configuration versus consent versus mailbox access versus last successful ingestion separately. Do not imply SSO connection proves support mail works. Validate shared mailbox access with a safe diagnostic; reconnect must retain intended mailbox scope.
2. **Receive:** create through shared ticket intake, with canonical sender/site, routing, receipt, safe source diagnostics and idempotency. Support multiple approved sites without trusting an unvalidated header. Unknown senders stay quarantined until an authorized operator decides; do not automatically create users or widen access.
3. **Thread:** current ingestion uses a subject IT reference; normalized `In-Reply-To` is not used to establish a thread. Add provider message-ID / References chains with strict audience checks and a safe fallback for subject references. Handle forwards, multiple references, merged tickets, automated replies and bounced messages deliberately.
4. **Attachments:** provider adapters currently retrieve body content without the browser attachment pipeline. Add bounded MIME/size/count handling, safe filenames, scanning/quarantine policy, protected download and retention. Never turn a mail attachment into a public URL.
5. **Send:** choose the actual support sender/reply-to and retain provider IDs. [`TicketRepliedNotification`](C:/Users/steph/Herd/oblivionfindings/app/Notifications/It/TicketRepliedNotification.php) intentionally sends a link notification rather than the full reply. Decide whether that privacy-preserving behaviour is the desired product contract; do not market it as full email conversation without implementing one. Internal notes must never enter staff mail.
6. **Transport correctness:** [`MicrosoftGraphTransport`](C:/Users/steph/Herd/oblivionfindings/app/Mail/MicrosoftGraphTransport.php) uses an Identity, takes the first recipient, and ignores the Boolean result of `sendMail`; missing identity can return without sending. This is a source risk for deployments using that transport, not proof the local `array` mailer lost a message. Provider rejection must produce a truthful failed delivery, not accepted status. Verify recipient, reply-to, headers and attachment semantics.
7. **Recovery:** handle 401/403, revoked/expired refresh grants, 429, timeouts, paging and acknowledgement failure without abandoning the connection or replaying ticket creation. Make retry safe and show the last success, oldest unprocessed message and current error. Existing delivery timestamps/order guards are worth retaining.

**Integration acceptance:** for each provider, use an approved nonproduction mailbox to receive a new request, send a reply, receive a changed-subject response, attach a permitted file, quarantine an unknown sender, process a duplicate once, recover from expiry/throttling, handle a bounce and reconnect. Test a settled/merged ticket and two approved sites. No live provider action was performed in this audit.

## 6. Automatic work: Fleet, Security & Devices and Control Room

### Existing path and limits

Device events pass through `DeviceEventObserver` → Control Room signal processing → `DeviceSignalPublished` → registered `CreateOrUpdateMonitoringTicket`. The listener is queued after commit on `monitoring`. It preserves canonical site/device links, a source alert and diagnostic evidence, derives priority from severity, and reuses an existing open ticket for the same alert/device/site. An online event with matching monitoring correlation marks recovery context; it **does not automatically resolve the ticket**, which is appropriate when a technician must verify the fix.

[`FleetSignalService`](C:/Users/steph/Herd/oblivionfindings/app/Services/Fleet/FleetSignalService.php:14) already writes an idempotent signal and durable outbox. [`SignalProcessingService::ingestFromFleetSignal`](C:/Users/steph/Herd/oblivionfindings/app/Services/ControlRoom/SignalProcessingService.php:1449) resolves canonical asset/site context and has privacy-aware Fleet context. Reuse this delivery reliability and provenance. Control Room “ticket-options” settings refer to its own alert configuration; their name is not evidence of an IT-ticket handoff.

### Proposed decision policy

- **Immediate operational/safety response → Control Room first:** loss of a system currently needed for safe support, panic/fire/duress events, a critical security alarm, or a vehicle issue posing immediate safety risk. Control Room owns immediate coordination and acknowledgement. Create/link IT work only when there is a technical repair task; route mechanical Fleet maintenance to its owner. An “urgent” printer request is not automatically a safety incident.
- **Actionable nonurgent technical issue → IT directly:** a sustained noncritical infrastructure outage, a failed device integration, repeat print failure, certificate nearing expiry or a tracker connectivity fault without immediate safety impact. Apply duration/threshold, maintenance-window and approved-capability rules before creating work. These examples are proposed policy, not claims that current collectors emit every signal.
- **Informational/duplicate/transient/maintenance-suppressed → no new ticket:** retain telemetry and correlation evidence in the source module. Update an existing linked episode as appropriate. Recovery alone should not create a new problem ticket.
- **Escalation:** if operational impact becomes urgent, promote/link the existing technical work into Control Room response without creating a second independent repair ticket. Provide a visible operator action, “Create/link IT follow-up”, with scope-filtered candidates, safe context preview and accountable destination.

### Processing contract

Use source kind + canonical issue/episode identity + approved site/capability as the idempotency boundary, not just event time or human title. Decide how recurrence after closure starts a new episode versus governed reopening; the current open-ticket-only match is not a complete recurrence policy. Add a correlation window/hold-down for flapping, bounded retries, failed-work visibility, a dead-letter/replay operation and a recorded suppression reason. Replaying the same intent must reuse its result.

Route every resulting ticket through the same classification, service, queue/team/owner and notification decisions as other channels. Record source event, canonical asset/device and safe diagnostic summary; recheck permissions at rendering and download. Do not copy Fleet locations, care events, access credentials or raw restricted payloads into a broadly visible ticket. Keep operator, technical-owner and communications roles distinct.

Source recovery should append dated evidence and suggest verification. A technician resolves with evidence; reopening/recurrence follows explicit policy. A ticket update must not emit the originating fault again. Distinguish monitoring recovered, service verified restored, incident resolved and ticket closed.

**Acceptance scenarios:** sustained nonurgent fault → one routed IT ticket with no Control Room alarm; urgent operational fault → acknowledged Control Room alert plus one linked repair ticket; duplicate burst/flapping → one episode; privacy-restricted context → safe summary only; retry after process crash → no duplicate; recovery → evidence, no premature closure; recurring fault after closure → explicit new-episode/reopen decision; operator handoff → direct navigable links both ways.

## 7. Navigation, documentation and one shared vault

### Proposed navigation

Keep the existing **single main app sidebar**, with permission-aware IT & Support children:

- **Service desk:** Overview, Tickets, Service catalogue and My tickets. Saved operational views belong within Tickets. Consider showing staff “Get help / My requests / Guides” labels while technicians retain the richer workspace.
- **Knowledge & Documentation:** a dedicated destination replacing the Knowledge service-desk tab. Its header/connected rail can expose Library, Systems & services, Sites & assets, Credentials and Reviews/renewals as permissions allow. Use search and filters rather than a second sidebar. Move the destination once, preserving old links/redirects.
- **Problems**, **Changes**, **Major incidents:** retain current canonical records and routes; align their headers and link related work from ticket detail.
- **Reports:** a dedicated destination if its depth warrants it; remove the equivalent service-desk tab when moved. Do not keep two competing report destinations.
- **Setup:** teams, queues, services, catalogue/workflows, automation/API identities and Operations audit. Link to existing Settings for mailbox, SSO and outbound email configuration.

The exact rail composition should be tested against the approved Sites layout at real browser widths. Moving Knowledge and possibly Reports reduces crowding without replacing familiar ticket workflows.

### Documentation model and content design

Extend current article lifecycle with immutable revisions, reviewed publication, stable URLs, tags, templates, owners and review queues. Add structured records for applications/systems and service runbooks only where existing records do not own that information. Refer to canonical Sites, Assets, Security & Devices, vendors and known errors. Define explicit relationships such as supports service, installed at site, supplied by vendor, affected by incident and documented by article.

Show practical runbooks: symptoms, scope, safe checks, prerequisites, escalation contact, validation, rollback and related incidents. Separate staff-facing guidance from internal operational detail. Store diagrams and protected attachments with version, ownership and audience; do not require users to paste network credentials into articles. Link licence/warranty/renewal metadata to the owning asset/vendor/procurement record where available; new documentation relationships should not create a second purchasing ledger.

Search should rank a usable current guide and a canonical record, show owner/review date, and exclude inaccessible content before ranking/snippets. A ticket should open the exact article used for resolution and record the revision; “search for this title in Knowledge” is insufficient. Draft-from-resolution must scrub internal notes, personal information and credentials, then require review before staff publication.

### Shared vault assessment

Reuse the canonical [`SiteCredential`](C:/Users/steph/Herd/oblivionfindings/app/Models/SiteCredential.php) store and credential services behind Sites and the new documentation workspace. `/vendors` and site credential routes already provide a shared foundation. An IT entry should be another authorized view/link to the same record, never a copied secret.

**Sound foundations:** encrypted values through [`SiteCredentialEncryptionService`](C:/Users/steph/Herd/oblivionfindings/app/Services/Sites/SiteCredentialEncryptionService.php), hidden serialization fields, site and `credentials.reveal` checks, row-locked reveal, required reauthentication where configured, audit-before-return, TOTP gates and 30-second UI concealment. Reveal audit records metadata, not the secret. No real-secret operation was exercised because the local register was empty.

**Before broader use:**

- Resolve copy-audit failure semantics; avoid claiming every copy is recorded when the current browser path ignores failure.
- Separate changing an external credential, attesting an external change and re-encrypting its stored value. Show who verified rotation and when; date-only “rotated” is not proof.
- Provide SSO-compatible step-up; current password revalidation may not serve staff without a usable local password. Do not weaken reauthentication to make SSO work.
- Define record-level/team/classification access for sensitive infrastructure beyond broad site visibility. Keep metadata, username, reveal, edit, rotation and audit-review rights distinguishable. Verify cross-site links never widen access.
- Define retirement, recovery/version retention, encrypted backup restore and application-key recovery procedures. Current encryption does not by itself prove operational recovery or independent key management.
- Do not index secrets, include them in article revisions/exports, place them in notification content, or expose them to assistive AI. Link masked credential metadata from systems/runbooks with a separate authorized reveal action.

**Shared-vault acceptance:** the same credential ID appears from Sites and permitted IT documentation; denying site or reveal permission denies both paths; reveal/reauth/copy events are recorded without secret values; hidden values auto-conceal; rotation requires evidence; an SSO-only authorized technician can complete proper step-up; backup restoration is tested using disposable encrypted fixtures.

## 8. Prioritized feature backlog

Every item below states beneficiary/problem, reuse/new work, UI location, dependencies/boundaries and an end-to-end acceptance outcome. Items are web-application features. Sequence them through section 9, rather than implementing them all at once.

### A. Daily-use essentials

**A01. Reliable, recoverable request submission.** Staff need certainty that help was requested. Extend existing intake/wizard with submission identity, structured errors and recoverable draft. **UI:** Get help/Log ticket and success detail link. **Dependencies/boundaries:** F01 diagnosis, transactional intake, notification dispatch, existing requester/site authorization. **Accept:** slow/failing submission followed by retry yields one ticket, a stable reference and preserved text; no transport failure falsely reports success.

**A02. Responsive accessible ticket work.** Staff and technicians need readable browser pages at narrow widths and keyboard access. Reuse Rory's header/list/card/modal components. **UI:** lists, drawer, detail and forms. **Dependencies/boundaries:** no new design system or native app; semantic links, focus, sufficient hit areas and existing permissions. **Accept:** at 390 px and desktop, users can identify a ticket, open it, reply, complete work and resolve without overlapping content; the same journey works with keyboard alone.

**A03. Clear accountable ownership and fallback routing.** Technicians/managers need every request to reach someone. Extend current teams/queues/services setup with a readiness check and unmatched-work fallback. **UI:** Setup, ticket ownership block, unowned queue. **Dependencies/boundaries:** active approved staff/site/team membership, service owners and cover policy. **Accept:** browser/email/system intake each yields a queue, responsible team and accountable owner, or an explicit monitored routing exception; inactive staff cannot silently retain new assignments.

**A04. Truthful SLA clocks and freshness.** Staff/managers need reliable commitments. Extend policies/watchdog and one shared verdict/coverage calculation. **UI:** meters, rows, detail, reports, Operations audit. **Dependencies/boundaries:** business calendar, pause policy, scheduler heartbeat, unknown legacy clocks. **Accept:** overdue, unmeasured, paused and met fixtures agree everywhere, including after holidays and scheduler interruption; health becomes unknown/stale when measurement stops.

**A05. Next-action and reply ownership.** Technicians need to find conversations requiring action. Extend public-comment metadata with last speaker and next responsible party. **UI:** Awaiting IT, Waiting for requester/vendor/approval, due-next-update views. **Dependencies/boundaries:** unified email/browser communication, internal/public separation. **Accept:** after a first agent reply, a subsequent requester message re-enters Awaiting IT; internal notes and automated receipts do not change whose turn it is.

**A06. Drafts, validation and transition explanations.** Everyone needs protection from accidental loss. Extend wizards/composer with dirty guards, recovery and persistent error summaries. **UI:** modal close/Escape, upload, reply and resolve. **Dependencies/boundaries:** scoped draft storage, retention and expiry; never persist secret values in generic drafts. **Accept:** Escape offers keep/discard, a failed reply retains text, and a required-task/approval block names the blocker and links to it.

**A07. Approved-site-aware staff intake and impact triage.** Cross-site staff need to identify the actual place affected; technicians need consistent priority. Extend current form and triage. **UI:** Get help site confirmation; optional technician impact/urgency. **Dependencies/boundaries:** approved active sites only, critical-service policy; source events cannot self-authorize wider scope. **Accept:** a worker approved at two sites chooses the affected one, cannot select a third, and the resulting queue/SLA reflect that choice.

**A08. Coherent resolution and requester confirmation.** Staff need to know what changed and how to say it still fails. Extend existing resolve/reopen/close/CSAT path with resolution codes, verification and “Still need help”. **UI:** same canonical ticket and success panel. **Dependencies/boundaries:** required tasks/approvals, requester reopen window, seven-day closure policy. **Accept:** resolution records code and useful public summary; requester confirms or reopens with reason; reopening appears in audit and next-action queues; scheduler closes only eligible work.

**A09. Collision-safe edits.** Technicians need protection when two people act together. Add expected-version conflict handling to current transaction boundaries. **UI:** property editor and composer, then optional editor-presence hint. **Dependencies/boundaries:** server reauthorization on commit; drafts retained on conflict. **Accept:** two editors changing the same field cannot silently overwrite; the stale editor sees current value and can explicitly reapply without losing unrelated changes.

**A10. Operational health and deployment readiness.** IT leads need to know whether automation is running. Extend Operations audit with scheduler/worker freshness, routing coverage, channel backlog and actionable failure links. **UI:** Setup → Operations audit and limited header warning. **Dependencies/boundaries:** no secrets/raw payloads in diagnostics; authorized retry/replay. **Accept:** stop a test worker or expire a test grant, see a timely warning, restore it and demonstrate backlog recovery without duplicates.

### B. Essential ticketing and integration completion

**B01. One command boundary for every intake channel.** Staff/technicians need consistent tickets. Refactor adapters to reuse current intake/interaction/transition services. **UI:** existing tickets with source/provenance context. **Dependencies/boundaries:** distinct authenticated human/mail/service actors; canonical site and privacy checks; idempotent receipts. **Accept:** equivalent browser, approved email and monitoring submissions produce the same ownership/SLA/audit invariants and safe error states.

**B02. Complete Microsoft/Gmail mailbox operation.** Staff need reliable email support. Extend current connectors, ingestion and delivery ledger. **UI:** existing IT mailbox Settings, ticket delivery status, Operations audit. **Dependencies/boundaries:** approved provider test accounts, bounded scopes, safe attachments, sender/audience authorization. **Accept:** all provider scenarios in section 5 pass, including duplicate, bounce, expiry, paging and changed-subject replies; internal notes never leave the application.

**B03. Honest SSO/settings administration.** Administrators need configuration that actually persists. Wire or clearly mark existing provider/provisioning controls; reuse group mappings and auth integration. **UI:** `/settings/sso`, linked from Setup. **Dependencies/boundaries:** masked secrets, restricted configuration permission, validated redirect/domain/role rules. **Accept:** authorized save survives refresh and drives a safe connection check; unauthorized users cannot edit; group changes cannot silently grant excess IT or vault access.

**B04. Urgent Control Room versus direct IT routing.** Operators and technicians need the correct first responder. Extend existing signals/outboxes with the section 6 classifier and create/link handoff. **UI:** Control Room alert action and IT linked context. **Dependencies/boundaries:** approved sites/devices/capabilities, privacy projections and canonical source records. **Accept:** nonurgent technical fault creates one routed IT item; urgent operational event stays in Control Room with linked repair work; replay and recovery never form a loop.

**B05. Reusable safe replies and ticket templates.** Technicians need less repeated typing. Add a governed template library over current knowledge/catalogue foundations. **UI:** composer insert menu and Log ticket presets. **Dependencies/boundaries:** public versus internal templates, owner/review date, validated placeholders with no secret substitution. **Accept:** a technician previews and inserts the correct site/service guidance, edits it and sends it; invalid placeholders block sending rather than leaking raw data.

**B06. Task/approval journey completion.** Technicians and approvers need clear dependencies and cover. Extend existing task and approval services, including delegated absence cover and reminders. **UI:** ticket work panel and existing personal task/approval surfaces. **Dependencies/boundaries:** eligible approvers, self-approval rules, cancellation and historical decisions. **Accept:** a catalogue request waits for an eligible approval, unlocks dependent tasks, records evidence and permits resolution only after required work; rejection and reassignment remain auditable.

**B07. Duplicate suggestions and safe related-work handling.** Technicians need fewer parallel investigations. Extend current link/merge services with scoped similarity suggestions and a preview. **UI:** intake/triage and merge dialog. **Dependencies/boundaries:** same audience for merges, canonical references, redirects, attachment/notification privacy. **Accept:** two same-issue tickets can be linked or safely merged, public history remains accessible to the right requester, old references resolve correctly and repeat email cannot recreate a child.

**B08. Trustworthy reports and export reconciliation.** Managers need clear denominators rather than attractive charts. Extend existing reporting with freshness, coverage, reopen rate, backlog age and stable definitions. **UI:** Reports and its drilldowns. **Dependencies/boundaries:** access-scoped aggregates/CSV, event history, timezone and date semantics. **Accept:** a small known dataset reconciles chart, drilldown and export; unmeasured SLA work is separate; changing a ticket updates related counts coherently.

**B09. Technician time and effort log.** Technicians/managers need practical capacity evidence. Add lightweight time entries and optional timer, reusing identities and task context. **UI:** ticket work panel; personal effort summary. **Dependencies/boundaries:** internal effort, editing/audit rules, no duplicate payroll or MSP billing. **Accept:** start/pause/manual correction produces one auditable duration linked to ticket/task, survives navigation and appears in workload reporting without exposing private internal notes to requesters.

**B10. Follow-up reminders and recurring requests.** Technicians need scheduled maintenance without ticket storms. Add recurrence/next-action scheduling atop existing scheduler/task infrastructure. **UI:** ticket Follow up and Setup templates; existing calendar where suitable. **Dependencies/boundaries:** timezone, exceptions, active owner, idempotent due occurrence and cancellation. **Accept:** one due recurrence creates one routed ticket; a failed/replayed job cannot create another; paused plans and closed services produce no work.

### C. Knowledge, documentation and vault

**C01. Dedicated Knowledge & Documentation workspace.** Staff/technicians need discoverable guidance without crowding Tickets. Extend current knowledge into the sidebar destination described above. **UI:** one main-sidebar child with shared header/search/rail. **Dependencies/boundaries:** canonical URLs and redirects, staff/internal audiences, existing design contracts. **Accept:** old knowledge links resolve, a staff user finds a published guide and a technician finds its internal runbook without duplicate navigation or a second sidebar.

**C02. Reviewed revisions and content recovery.** Authors/reviewers need safe publishing. Extend the article model with immutable revisions and reviewed publication references. **UI:** article history, compare, submit/review/publish and review queue. **Dependencies/boundaries:** author/reviewer rights, audit and retention. **Accept:** editing a published article leaves readers on the approved version until review; a reviewer compares and publishes; restore creates a traceable revision.

**C03. Structured systems and service runbooks.** Technicians need dependable context for unfamiliar systems. Add only missing documentation entities/templates, linking existing services/sites/assets/devices/vendors. **UI:** Systems & services and record documentation sections. **Dependencies/boundaries:** canonical ownership and permission-safe relationship projection. **Accept:** from an incident, open the affected system, see current owner/dependencies/runbook, follow the canonical device link, and return to the same ticket without duplicated inventory.

**C04. Protected diagrams and documentation attachments.** Technicians need topology and supporting files. Extend protected attachment handling to documentation with versioning. **UI:** article/system attachments and diagram preview. **Dependencies/boundaries:** safe content rendering, size/type limits, permission checks on original and preview, retention. **Accept:** a permitted diagram is uploaded/revised/viewed; an unauthorized user cannot retrieve either preview or original through a guessed link; old revision remains traceable.

**C05. Canonical supplier, licence, warranty and renewal links.** IT/procurement need notice before coverage expires. Reuse vendor/asset/commercial owners; add missing renewal metadata and reminders after dependency review. **UI:** documentation Renewals and linked asset/vendor records. **Dependencies/boundaries:** procurement/finance access distinct from staff guides, named renewal owner. **Accept:** an expiring licence raises one owner reminder/task, links to its canonical agreement and records renewed evidence without duplicating the purchase record.

**C06. One vault through Sites and IT.** Authorized technicians need secrets where the runbook references them. Extend the current credential capability with canonical links, stronger access policy, reliable audit and SSO step-up. **UI:** masked Credentials view and runbook credential references. **Dependencies/boundaries:** all section 7 vault prerequisites; no plaintext in search/AI/export. **Accept:** the same ID works from both entry points with identical denial/reveal behaviour and complete secret-free audit records; external rotation is distinguished from storage-key work.

**C07. Documentation quality and review ownership.** IT leads need stale guidance to become assigned work. Extend existing owners/review dates with review queues, required fields, broken-link and no-owner checks. **UI:** Reviews dashboard and record badges. **Dependencies/boundaries:** meaningful quality rules, review cadence and eligible replacement owner. **Accept:** an overdue runbook reaches its owner, a reviewer confirms/corrects it, and the review clears without overwriting prior evidence; an empty library is not labelled healthy.

**C08. Resolution-to-knowledge feedback loop.** Staff/technicians benefit when a fix prevents repeat tickets. Extend draft-from-resolution and helpful/deflection data with canonical article/revision links. **UI:** consistent resolution success action and ticket composer suggestions. **Dependencies/boundaries:** scrub internal/private content, reviewer approval, honest deflection attribution. **Accept:** a resolved ticket produces a safe reviewed guide; a later requester opens it and records that it solved the issue; metrics link to actual interactions rather than guessed avoided tickets.

### D. High-value improvements and later ideas

**D01. Capacity and dispatch planning.** IT coordinators need coverage and realistic commitments. Extend existing calendar/availability before introducing a new booking store. **UI:** technician work planning and ticket appointment. **Dependencies/boundaries:** approved availability data, travel/site context only when permitted, absence cover. **Accept:** an appointment assigns an available technician, avoids an existing booking, notifies through approved channels and can be rescheduled with retained history.

**D02. Explainable automation rules and dry runs.** Administrators need safe routing/macros. Extend existing queue rules into versioned condition/action rules with preview. **UI:** Setup → Automation, ticket “why routed here”. **Dependencies/boundaries:** allowlisted actions, change audit, loop/rate limits, least-privilege service identity. **Accept:** a dry run explains which tickets would change; approved execution is idempotent, failures are visible and disabling a rule stops future actions.

**D03. Service health and staff-facing outage notices.** Staff need to know an issue is already being handled. Extend existing services/major-incident communications with audience-safe status. **UI:** Get help banner, catalogue service card, service detail. **Dependencies/boundaries:** approved publication, named communications owner, Control Room/IT separation. **Accept:** a confirmed outage offers the existing incident and expected next update, staff subscribe without seeing restricted diagnostics, and restoration updates the same notice.

**D04. Proactive risk-to-work review.** IT leads need action before avoidable outages. Extend existing monitoring/renewal signals for supported certificate, backup, integration-health or device-health cases. **UI:** proactive work view and canonical source links. **Dependencies/boundaries:** collector capability and data-quality evidence, thresholds/maintenance suppression, episode deduplication. **Accept:** a sustained validated risk produces one assigned preventative task; transient noise is recorded/suppressed; acknowledgement and correction link to the source evidence.

**D05. Assistive AI with sources and human control.** Technicians benefit from concise handovers and suggested guidance. New optional assistance should summarize permitted conversation, suggest reviewed articles and draft replies. **UI:** composer/triage assist panel inside the existing workspace. **Dependencies/boundaries:** explicitly approved provider/data policy; filter access before retrieval; exclude vault secrets; untrusted mail/document instructions cannot authorize tools. **Accept:** every factual suggestion links to permitted evidence, uncertain content is labelled, a human reviews before send/change, and revoked access removes the source from suggestions. Autonomous remediation is not a daily-use prerequisite.

**D06. Shift handover and continuity.** Technicians need the next colleague to know the state. Extend next-action, task and work-log data into a handover summary. **UI:** My work / team queue handover. **Dependencies/boundaries:** approved team audience, pending promises and escalation coverage; no parallel ticket copy. **Accept:** an incoming technician sees owner, last public update, blocker, next commitment and relevant links, and acknowledges transfer without losing history.

**D07. Service improvement and recurring-problem candidates.** IT leads need to reduce repeat demand. Extend Problem Management with scoped recurrence trends and linked incident clusters. **UI:** Problems and Reports. **Dependencies/boundaries:** reliable categories/services/outcomes first, transparent aggregation, human confirmation. **Accept:** repeated incidents suggest one problem; a technician validates the relationship, records a workaround/fix and measures recurrence afterward without auto-merging unrelated requests.

**D08. Recovery and support continuity drills.** IT operators need confidence when the application or provider is unavailable. Add documented runbooks and targeted operational checks, not a separate offline app. **UI:** internal documentation and Operations audit evidence links. **Dependencies/boundaries:** approved encrypted backups, key custody, retention and authorized incident communications. **Accept:** restore disposable ticket/document/vault fixtures in an isolated environment, reconcile queued events once and record recovery evidence without exposing secrets.

### Benchmark rationale

These are capability benchmarks, not a recommendation to purchase or reproduce the vendors' MSP architecture. ConnectWise emphasizes combined ticketing, time entry, scheduling/dispatch and escalation; those inform B09/D01 rather than billing or tenant management. [ConnectWise PSA help desk](https://www.connectwise.com/platform/psa/help-desk).

ManageEngine's automation guidance covers routing rules, assignment and technician/approver absence; those inform A03, B06 and D02. Its depth should be introduced behind clear defaults in Oblivion. [ServiceDesk Plus automation](https://www.manageengine.com/products/service-desk/automation/).

IT Glue emphasizes structured documentation, relationships, version control and password-management integration; those inform C01–C08 and the canonical shared-vault approach. Vendor security marketing is not evidence of Oblivion's security. [IT Glue features](https://www.itglue.com/features/). Official pages were checked during this audit; no broad vendor comparison or pricing research was performed.

## 9. Dependency-ordered implementation roadmap

### Phase 1 — reliable daily browser work

**Scope:** A01–A10, F01/F02/F07–F12/F15/F16; fix observed feedback/count defects and align the core list/detail/wizard with the approved design. Configure the existing fallback queue, team, owner and SLA calendar. Preserve working transaction/privacy controls. Diagnose the requester timeout before assuming a code fix.

**Exit criteria:** fresh worker and restricted technician accounts complete create → triage → reply → task/approval block → work → resolve → requester confirmation/reopen → close. Verify at desktop and 390 px in the web browser. Another requester/site is denied; internal notes stay private. Draft failure and simultaneous edit cases preserve input. Every open fixture is measured or explicitly unmeasured; overdue work never appears universally healthy. Scheduler freshness and an accountable fallback are demonstrated. A small known dataset reconciles list/header counts. Use focused service/feature tests for changed invariants, not a blanket suite as a substitute for the browser journey.

### Phase 2 — consistent essential ticketing and channel operation

**Dependency:** Phase 1 command, scope, clock and ownership behaviour is stable. **Scope:** B01–B10, SSO settings persistence, provider contract, operational failure recovery and section 6 cross-module routing. Complete task/approval/merge/attachment verification and reporting reconciliation. Keep each provider/event source as a separately reviewable implementation slice.

**Exit criteria:** approved Microsoft and Gmail test mailboxes pass the section 5 scenarios. Browser/email/system intake yields equivalent invariants. Nonurgent technical faults go directly to IT; urgent operational faults create/link acknowledged Control Room work. Duplicate bursts, failure/replay, source recovery and post-closure recurrence pass without loops. Watcher/attachment/merge privacy is demonstrated. Delivery rejection is visible; reconnect/retry works. CSV and report counts agree. SSO Save either demonstrably persists and drives auth configuration or clearly declares deployment-managed settings.

### Phase 3 — documentation and canonical vault

**Dependency:** canonical ticket/service/asset links and access boundaries from Phases 1–2. **Scope:** C01–C08; first move navigation without duplicating destinations, then add revisions/review, structured relationships, protected files and shared-vault entry points. Extend the vault only after copy audit, step-up, rotation and recovery requirements are settled.

**Exit criteria:** staff can find a published guide; technicians can navigate a ticket → current runbook → canonical system/device → authorized masked credential → back to ticket. Published edits require a new reviewed revision; restore works. Both vault entry points use one ID and identical denial/reveal behaviour. Review/renewal reminders have owners and evidence. No secret appears in search, logs, reports, article content or AI input. Old Knowledge links redirect coherently and the service-desk rail no longer duplicates the workspace.

### Phase 4 — productivity and proactive service

**Dependency:** reliable operational data and content quality. **Scope:** D01–D04, D06–D08 plus refinement of macros, time and recurrence. Implement one measurable productivity workflow at a time.

**Exit criteria:** a booked job respects availability and cover; recurring maintenance creates exactly one due ticket; a dry-run rule explains its impact; service notices reduce duplicate requests through an actual subscription/link flow; a handover transfers accountable next action. Measure time-to-own, overdue next updates, reopened work and repeat incidents from reconciled data, rather than promising an unsupported improvement percentage.

### Phase 5 — optional assistive AI

**Dependency:** reviewed knowledge, clear permissions, safe provider policy and reliable feedback data. **Scope:** D05, initially read/suggest/draft only.

**Exit criteria:** permissions constrain retrieved content, sources are cited, unknowns are explicit, secrets are excluded and every external reply or record change requires the existing authorized human action. Evaluate against a small representative set of local/sanitized tickets, including malicious instructions inside incoming content and revoked-access cases. Do not make daily service availability depend on the AI provider.

## 10. Verification record and remaining limits

### Browser work actually performed

All application navigation, interaction and screenshots used the **Codex in-app browser**. No terminal-driven browser automation substituted for these walks.

- `/sites`: live header/list reference, desktop screenshot.
- `/it`: technician Overview, Tickets, quick drawer, full detail, Knowledge list/editor, Reports, Service catalogue and its published request form. `/it?tab=knowledge`, `?tab=reports`, `?tab=catalog` reached through visible tabs. Earlier guessed `kb`/`catalogue` parameters fell back to Overview and were not counted as verified target pages.
- `/it/tickets/1`: technician and requester views compared; requester internal-note exclusion verified.
- `/it/tickets/7`: disposable technician lifecycle completed, with required task, public/internal communication, blocked premature resolution, successful resolution, reopen, second resolution and final close. Direct requester navigation returned 404.
- `/it/setup`: Teams and Operations audit; zero teams/queues, one published catalogue item, disconnected mailbox coverage, queued/bounced/accepted local delivery records and three schedules awaiting a run.
- `/settings/it-mailbox`: Microsoft/Gmail disconnected and OAuth configuration unavailable. `/settings/sso`: existing configuration UI inspected; no provider account connected and no secret entered.
- `/it/problems`, `/it/changes`, `/it/major-incidents`: seeded registers and their current states. Full specialized lifecycle/approval actions were not exercised.
- `/vendors`: canonical vault/vendor entry opened; zero credential records. No secret reveal/copy/rotation was attempted.
- Requester `/it`: five own tickets, incorrect My tickets badge, plain-language Get help form and optional detail; one submission timed out. No duplicate submission was attempted after the failure; a read-only query checked the outcome.
- Responsive browser width **390 × 844**: technician list, detail, wizard and requester list captured. Initial viewport calls affected a different selected tab; those desktop-sized attempts were not counted as responsive verification. Effective 390 px was subsequently measured in the target tab. Temporary viewport override was reset afterward. These checks are web responsive checks only.
- Keyboard: Escape draft-dismissal behaviour tested. Full focus order, screen-reader operation, contrast ratios, all touch targets and browser zoom combinations remain unverified. Requester timeout capture had no captured console warning/error entries; that does not diagnose the server timeout or prove all routes console-clean.

### Local changes and safety limits

The only application record deliberately created was **IT-000007**, clearly titled as a disposable audit record, with one required work task and audit-only comments. It is **closed**. The failed requester submission did not appear in the subsequent ticket query. Existing six tickets were read, not edited. Local notifications generated by normal ticket actions used the confirmed `array` mailer; no external mail, connector consent, live polling, permission changes, secret operations or production writes were performed. Demo Admin was restored as the signed-in account after requester checks.

Repository changes are confined to this new dated report/evidence directory. Existing application files, migrations and design guides were not changed. The initial working tree was clean; final status was checked for unintended tracked changes. The read-only runtime helper and its JSON outputs intentionally contain only safe local metadata, selected ticket clocks/ownership and the two fixture users' permission flags; no credential material is included.

### Tests and source verification

**Tests actually run: none. Builds/typechecks/broad suites run: none.** Browser observations and focused source checks were sufficient to establish the listed findings; integration and unexercised transitions remain expressly unverified.

Supporting test reads included [`ItTicketWorkspaceTest`](C:/Users/steph/Herd/oblivionfindings/tests/Feature/It/ItTicketWorkspaceTest.php:341) for requester projection, internal-note rules, settled replies and waiting resumption; [`ItMailboxPollTest`](C:/Users/steph/Herd/oblivionfindings/tests/Feature/It/ItMailboxPollTest.php:72) for successful polling, duplicates and disconnected providers; and [`ItWorkTaskTest`](C:/Users/steph/Herd/oblivionfindings/tests/Feature/It/ItWorkTaskTest.php) for ordered tasks, dependencies/evidence, auditing and settled-state boundaries. Test names/source are supporting design evidence, not a passing test report.

### Access, configuration and decisions still needed

- Fresh-session reproduction and request tracing for the requester timeout; determine session/PHP/Herd versus application cause without broad infrastructure changes.
- Restricted technician, approver and manager browser fixtures for cross-site/sensitive work, reassignment, approval, merge, file download and CSAT; existing admin testing cannot certify least privilege.
- Approved Microsoft/Gmail test accounts and chosen support sender/reply-to policy; provider credentials remain unconfigured locally. Decide notification-only email versus full conversation transport explicitly.
- Operational queue/team/service owners, fallback coverage, business hours/holidays, escalation latency, requester confirmation and auto-close policy.
- Agreed urgent Control Room classification and source event/capability coverage. Mechanical Fleet work must stay with Fleet unless there is a technical IT task. No live or synthetic device/Fleet event was injected here.
- Knowledge audience rules for technicians, reviewers and site-scoped content; documentation record ownership; vault SSO step-up, rotation, retention and encrypted recovery procedures.
- Production scheduler/worker execution, delivery callbacks, backups, restore, retention, monitoring and supported-browser verification. Local fixture success does not certify those operational dependencies.

The highest-value next implementation is a small, demonstrable daily web journey with truthful ownership, clocks and feedback. Broader integrations and documentation should then extend those same boundaries, with the feature backlog used as a sequenced plan rather than a collection of additional controls.
