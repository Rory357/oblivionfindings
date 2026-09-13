# IT & Support — feature-complete implementation and UI/UX handoff plan

**Prepared:** 8 September 2026; finalized 9 September 2026. **Status:** implementation in progress; actual package and verification state is recorded in implementation-progress.md.

**Repository:** `C:\Users\steph\Herd\oblivionfindings`

**Baseline audit:** [IT & Support daily-use readiness audit](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/audit.md). Baseline reviewed commit: `c4817a1df`. Evidence: [audit evidence directory](C:/Users/steph/Herd/oblivionfindings/docs/audits/2026-09-08-it-support/evidence).

This document is the implementation brief for a **new session**. Read it completely, then execute the dependency-ordered work packages. Preserve the audit as historical evidence and keep implementation progress separately. Do not repeat the entire audit before starting useful work.

## 1. Outcome and binding scope

Deliver a complete, usable internal service desk for one organisation across its sites: request/detection → intake → triage → accountable ownership → communication → approvals/work → verification/resolution → confirmation/reopening → reporting and audit. Complete the existing features as well as the missing capabilities listed here. UI/UX quality is part of completion, not a final coat of styling.

The user explicitly requires:

- **Desktop web application only — user clarification, 9 September 2026.** The user's later instruction supersedes this plan's earlier mobile/390px/narrow-viewport acceptance references. Verify the desktop browser at its existing size, including keyboard use, readable layouts and recovery; do not resize the browser or create mobile-specific flows. Do not create a native mobile application, another worker home, a second app shell or a separate offline application.
- **Follow Rory's existing design rules exactly. Do not edit DESIGN.md or any file in design_styles/.** Do not change a rule to make an implementation pass. Do not add a competing IT design system or an override guide.
- **Feature completeness.** All non-AI scope in this plan is required. “High-value” describes delivery order, not permission to omit it. A pilot milestone is not final completion. No route-only, mock-only, happy-path-only or decorative implementation qualifies.
- **AI preparation only in this workstream.** Complete layouts, interaction states, module context contracts and integration seams for the later whole-web-app AI programme. Do not build or enable that programme's model execution, external retrieval, autonomous actions or provider configuration here. Label the result “AI integration foundation complete”; do not claim the AI capability itself is implemented.
- Extend existing canonical services and records. Do not rebuild ticketing, Sites, Fleet/Assets, Security & Devices, Control Room, procurement or credential storage.
- Preserve existing uncommitted work and unrelated features. This plan authorizes implementation when handed to an implementation session; writing this plan made no application changes. It does not authorize production deployment, real emails, live integration changes, credential disclosure or broad permission expansion.

Follow [AGENTS.md](C:/Users/steph/Herd/oblivionfindings/AGENTS.md) and [the single-organisation architecture boundary](C:/Users/steph/Herd/oblivionfindings/docs/architecture/single-tenant-application.md). Authorization uses roles, permissions, approved active sites, canonical record ownership and privacy. Do not introduce tenant selection, tenant-scoped transports or cross-tenant fixtures. Do not remove legacy tenant/organisation columns without a separately authorized dependency audit and migration.

### What “feature complete” means

Every work package must satisfy all applicable dimensions:

1. **Real operation:** persisted data, working controls, canonical routes, sensible defaults and valid empty states. Create/read/update/retire or cancel/recover operations exist where the feature needs them; “CRUD everywhere” is not a substitute for domain lifecycle.
2. **Whole journey:** every state has a next action, accountable party and authorized exit. Errors, rejection, retry, cancellation, expiry and recovery work as well as success.
3. **Access:** server enforcement and frontend visibility agree, including direct identifiers, search, exports, attachments, notifications and background actors. Admin success alone is insufficient.
4. **UI:** approved primitives, readable responsive layout, keyboard/focus support, consistent status/date language, draft protection and actionable validation.
5. **Reliability:** transactions, idempotency, concurrency protection and operational failure visibility appropriate to the feature. No false “saved”, “sent”, “healthy” or “complete” states.
6. **Operations:** configuration, migration/backfill, queue/scheduler behaviour, retention, rollback and recovery are documented and verified where relevant.
7. **Evidence:** focused automated checks plus actual browser journeys; external integration verification uses authorized test accounts. Missing external access remains an explicit verification block, not a completed box.

Completion states in the implementation ledger: **Planned → In progress → Implemented → Verified**. Use **Blocked** with the exact dependency and remaining work. Never equate Implemented with Verified. The final release gate requires all required packages Verified, with the explicit AI boundary above. Do not silently shrink the scope to meet a session or time limit.

## 2. Start-of-session instructions

1. Inspect `git status`, branch, HEAD and applicable repository instructions. The audit directory was already untracked when this plan was written; preserve it. Revalidate source locations if HEAD has moved. Do not reset or overwrite user changes.
2. Read the audit's findings and verification limits. The requester 504 is an **isolated observation during an interrupted session**, not a proven code root cause. Reproduce under a fresh session before prescribing a fix. Teams/queues and mailboxes were unconfigured; distinguish configuration work from implementation defects.
3. Read the protected design sources in section 3. Hash them at session start and compare after each milestone. Changes made separately by the user become the new starting baseline; do not revert them to this plan's older values.
4. Create an implementation ledger under this dated audit directory, recording W00–W27, audit references, owner/session, changed files, migrations, exact checks, browser evidence, blockers and the next executable step. Keep this plan's scope intact; document evidence-based corrections to assumptions.
5. Verify `https://oblivionfindings.test/sites` and `/it` with the Codex in-app browser. Check the existing session before asking for login help. Verify Herd host, checkout and current assets; do not reuse the audit's bundle hashes as current proof.
6. Use existing fixtures or isolated disposable test fixtures. The old audit record IT-000007 was closed. Do not reopen or change unrelated records as convenient test data. Confirm mail/queue configuration before actions that can notify; use a test sink for local testing.
7. Begin W00–W04. Continue independent work if a provider account or business-policy decision is unavailable, but keep dependent acceptance blocked. Do not repeatedly ask the user to approve routine reversible implementation already covered by this plan.

No commit, push, merge, deployment, production migration, live OAuth consent or real message sending is implied by the plan alone. Prepare reviewable local changes and exact operational instructions before any necessary external approval.

## 3. Rory design compliance: implementation contract

### Read-only authorities

- [DESIGN.md](C:/Users/steph/Herd/oblivionfindings/DESIGN.md)
- [PAGE_HEADER_STYLE_GUIDE.md](C:/Users/steph/Herd/oblivionfindings/design_styles/PAGE_HEADER_STYLE_GUIDE.md)
- [NAVIGATION_STYLE_GUIDE.md](C:/Users/steph/Herd/oblivionfindings/design_styles/NAVIGATION_STYLE_GUIDE.md)
- [LIST_STYLE_GUIDE.md](C:/Users/steph/Herd/oblivionfindings/design_styles/LIST_STYLE_GUIDE.md)
- [POPUP_STYLE_GUIDE.md](C:/Users/steph/Herd/oblivionfindings/design_styles/POPUP_STYLE_GUIDE.md)
- [APP_SHELL_STYLE_GUIDE.md](C:/Users/steph/Herd/oblivionfindings/design_styles/APP_SHELL_STYLE_GUIDE.md)

Also read the existing button, loader and token guides when changing those surfaces. Prefer explicit superseding revisions and the named shared implementation over older examples/checklist snippets in the same guide. For example, the explicit popup width rule uses inline pixel caps; old utility-width examples are not a reason to ignore it. A genuine unresolved rule conflict must be documented with the exact passages; do not invent a per-page exception or edit the guides.

### Required anatomy and behaviour

**Shell:** reuse the existing application shell and one main sidebar. It owns the 20px outer gutter; page wrappers must not add another. Use the existing 20px section/card gap and Home-rooted shell breadcrumbs. Preserve the neutral page ground, ink shell, branding and dark-mode tokens. IT work must not redesign global chrome.

**Headers:** use [PageHeader](C:/Users/steph/Herd/oblivionfindings/resources/js/components/page/page-header.tsx), not `PageHero` or a custom banner. Identity and one fact subline, scoped search and one primary action; real linked meter blocks; compact right-aligned filters inside the band; connected tabs at its bottom. Profile pages use the profile variant and the prescribed secondary strip. A ticket fact line contains ticket facts, not invented location/address fields just to fill a slot.

**Meters:** use live backend values, honest denominators and meaningful drilldowns. Never invent a sparkline or represent unknown data as zero/healthy. The existing eight-meter, two-row IT hub is explicitly approved; retain it where the information remains useful. Fix content and responsive behaviour rather than treating the two rows as a design violation. Operational failure counts are appropriate; generic readiness/onboarding rings or completion columns on entity lists are forbidden.

**Rail:** retain one optical line with centres 23px above the band bottom, through the shared rail. Active tabs merge with the page ground; no header shadow that breaks the seam. Every rail retains Find. Use the shared palette/overflow handling for constrained widths rather than wrapping active tabs away from the seam or shrinking labels to illegibility. Changes required in the shared primitive must preserve geometry and be checked against Sites. Do not hand-adjust one IT tab. Secondary strips are neutral until active, use positional tones, and never gain pin controls.

**Filters:** keep the guide's 23px visual filter controls and per-view meaningful filters. Cards/Table lives in that row. Wider selections belong in a contextual filter panel opened from the header, not a second bar below it. No inactive controls that appear to filter but do nothing. Preserve URL and user-owned saved-view state across navigation.

**Lists:** compose [EntityTable](C:/Users/steph/Herd/oblivionfindings/resources/js/components/lists/entity-table.tsx), [EntityCard](C:/Users/steph/Herd/oblivionfindings/resources/js/components/lists/entity-card.tsx), the cell library and ListCaption. Identity first, kebab last, one shared `MenuItem[]` for kebab and right-click. Preserve all existing authorized actions. Use real semantic links and a separate selection affordance. Show “N of N shown” using the filtered/paginated dataset. Wide tables scroll within their own shell; the page never scrolls sideways.

**Responsive web:** default to a real card branch below `md`, fed by the same authorized data and action definitions. Do not merely hide enough columns to force a table into 390px. Ticket cards show readable title/reference, state, priority/next action, assignee/accountable team and SLA or explicit unmeasured status. Keep primary work accessible without hovering. Header content may reflow as allowed by the guide; do not clip it with arbitrary heights or remove required anatomy to hit a guessed size target.

**Touch and keyboard:** retain the approved compact visual chrome while supplying the prescribed ≥44px frontline hit areas without overlap. Where a dense filter cannot achieve this safely, expose it through the existing responsive filter/dialog pattern; do not change the design rule. Test Tab, Shift+Tab, Enter, Space, Escape, focus return and context menus. Use existing focus and safe-area helpers. Status needs text/icon as well as colour. Test at 200% zoom and reduced motion.

**Dialogs:** entity add/edit or multi-section work uses [WizardShell](C:/Users/steph/Herd/oblivionfindings/resources/js/components/wizard/shell.tsx), shared steps/review/success components and the same prefilled body for edit. Use the specified simple-dialog pattern for short confirmations. Preserve free step navigation, field-based completeness, server error routing to the offending step and dirty-close confirmation. Do not label an empty single-step form 100% complete. Apply canonical widths `min(92vw, 480px/720px/900px/1100px)` as appropriate; body scroll must preserve footer access. Multi-section detail viewers are not disguised forms.

**Feedback:** field validation stays inline; transition conflicts get a persistent explanation and link to the blocking task/approval. A toast supplements, never replaces, required feedback. Use one success notification owner to avoid duplicated flash/local toasts. Use matching skeletons for known layouts and the shared loader/ring for indeterminate or inline progress. Draft states say Saved/Saving/Could not save truthfully.

**Tokens and copy:** existing semantic tokens, typography helpers, lucide icons, StatusBadge/status vocabulary and shared NZ date/time helpers. No raw palette classes, hardcoded brand colours, per-page button restyling or invented tokens. Use “Get help”, “My requests”, “Waiting for you”, “Waiting for IT” and “Still need help” for staff; keep technician detail available. Presentation wording must not rename backend enums casually.

## 4. Target workspaces and screen-level specification

New URLs below are **proposed**; check route-name/path collisions before adding them. Existing API endpoints must retain their method and response contract. Compatibility redirects must preserve permitted query filters and the destination record, not discard the user's context.

### Main sidebar: IT & Support

1. **Service desk** — existing `/it`. Technician rail: Overview, Tickets, Provisioning, Service catalogue, My requests. Remove Knowledge and Reports from this rail only when their replacement destinations work. Staff see the relevant My requests/catalogue views and Get help, with a normal link to their published guides.
2. **Work planning** — proposed `/it/work`. My work, team schedule, effort and handover, plus scheduled work plans. Integrate existing calendars/tasks rather than maintaining duplicate personal tasks.
3. **Knowledge & Documentation** — proposed `/it/knowledge`. Library, Systems & services, Sites & assets, Credentials, Reviews & renewals. Staff only see authorized published guidance, with a simple “Guides” presentation where appropriate. No secondary sidebar.
4. **Problems**, **Changes**, **Major incidents** — preserve existing canonical routes/records.
5. **Reports** — proposed `/it/reports` page, reusing `/it/reports/data` and `/it/reports/export`. Remove the duplicate desk tab and redirect old deep links.
6. **Setup** — existing `/it/setup`: teams/queues/services, catalogue/workflows, automation, API identities and operations. Link to existing `/settings/sso`, `/settings/it-mailbox` and canonical outbound email settings; do not clone their forms.

Update both [ItModuleNavigation](C:/Users/steph/Herd/oblivionfindings/app/Domain/It/ItModuleNavigation.php) and the actual primary sidebar/search/navigation consumers, consolidating data where practical. The current helper has multiple knowledge destinations; removing only one JSX tab is insufficient. Deep links from notifications, saved views and Find must agree.

### Requester journey

`/it` opens My requests. Header: Get help as primary action; accurate open/waiting/resolved meters with drilldowns; status filter and scoped search. Body: readable requests, clear “Waiting for you” work first when relevant, and catalogue/guides entry points without a duplicate primary button.

Get help asks what happened/what is needed, affected approved site, everyday category and practical impact. Optional detail and protected files remain easy to add. Never ask staff to choose an IT queue, technician or internal service ID. Show acknowledgement/reference and “Open request”. The detail presents the public conversation, who is helping, next expected update, uploaded files and an obvious reply/confirmation/reopen action. Internal notes, technician effort and restricted diagnostics stay out.

### Technician list and detail

Tickets has saved views, search, sort, filters, Cards/Table, authorized selection/bulk actions and accurate counts. Default actionable views: unowned, mine, my team, awaiting IT, awaiting requester/vendor/approval, at risk, breached and unmeasured. Explain a queue match and show a routed owner; do not conflate assignee with accountability.

Keep `/it/tickets/{ticket}` as the canonical work record. Header: reference/title/state, scoped search, one current primary action and overflow for less frequent operations. Linked meters can expose reply/SLA, required work, pending approval and related context only when backed by real values. Main detail sections: Conversation, Work, Details & links, Activity. Use the guide's hierarchy rather than adding another standalone toolbar.

Conversation remains dominant; the desktop properties area is secondary. On narrow screens move essential ownership/next-action context before the thread and expose Work directly through the record navigation. Replies use an unmistakable public/internal choice and preserve separate drafts. Work holds tasks, evidence, approvals, effort and scheduling. Details links canonical service/site/asset/device/source issue. Activity is permission-filtered and shows meaningful changes, not raw payloads.

Quick drawer is a concise preview with a safe reply and “Open ticket”. Preserve the underlying list filter/scroll position. It must not become an alternate, inconsistently authorized implementation of every lifecycle action. Opening full detail or changing tabs must not silently destroy the drawer draft.

### Setup, specialist work and documentation

Setup lists real missing configuration, failed deliveries and stale workers, with direct corrective actions permitted by the user's role. It is an operational console, not a generic onboarding score. Problems, Changes and Major Incidents reuse the same header/list/dialog rules while keeping their distinct lifecycle fields and command responsibilities.

Documentation readers show owner, audience, reviewed publication, related records and review date. Editors show working revision and published revision separately. Systems/runbooks reference canonical vendors/assets/devices and masked credential records. Credentials uses the existing vault service and record ID from both Sites and IT.

AI assistance occupies a contextual panel/dialog inside these screens, not a new sidebar. Its exact states and interface boundary are in W25.

## 5. Shared domain invariants and policy decisions

### Implementation invariants

- Reuse intake, triage, interaction, transition, routing, task, approval, merge, link and delivery services under `app/Domain/It`. Channel adapters normalize input and actor identity; they do not write lifecycle records directly. Do not impersonate a privileged staff account for monitoring/mail.
- Commit the canonical state and its audit event atomically. Persist dispatch intent where delivery could otherwise be lost; queue after commit. Use existing outbox/run-ledger patterns before introducing another framework.
- A submission/command carries a stable idempotency identity scoped to actor/channel and operation, with a database uniqueness boundary and request fingerprint. A duplicate returns its existing authorized result; reuse with conflicting content is rejected. A cache lock alone is insufficient across crashes/replays.
- Use expected versions or equivalent stale-intent checks for editable fields. Transactions protect atomic writes, while versions protect the user's older view. Recheck authorization and lifecycle inside the write boundary.
- First-response SLA, last public speaker, next responsible party, operational ownership and resolution outcome are different facts. Do not infer one from another.
- Keep time in canonical storage, use the established business calendar and NZ presentation helpers, and test daylight-saving/holiday boundaries. Paused business time must not accidentally become arbitrary wall-clock extension.
- Canonical asset/device/source links retain identifiers and permission-safe provenance. Copy only authorized diagnostic summaries, never management interfaces or unrestricted raw source payloads.
- Sensitive flags, site audience and ownership apply to all projections, queries, searches, counts, exports, notifications, drafts and downloads. Access revocation must affect existing sessions and queued work at use time.

### Decisions to record early, without blocking independent work

**DP01 — ownership:** default recommendation is a named fallback IT team/queue with an accountable service/team owner, optional individual assignee and absence cover. The organisation supplies the real people; fixtures must not create real assignments.

**DP02 — SLA:** preserve existing configured targets until reviewed. Define response/resolution calendars, pause reasons, reprioritization, reopened clocks and escalation latency explicitly. Missing historical clocks remain unmeasured unless an evidence-based backfill is authorized. Do not invent historical compliance.

**DP03 — communication:** recommended initial contract is a public reply sent from the approved support identity and threaded back to the same ticket, with an explicit privacy option for link-only notifications. Pick and document the production policy; both provider implementations must truthfully advertise their supported mode. Internal notes are never external messages.

**DP04 — settled work:** preserve the current governed requester reopen window initially. Mail to settled work enters the same reopen/related-request decision rather than appending silently. Resolution, confirmation and auto-close policy need a named operational owner.

**DP05 — critical response:** safety/urgent operational coordination belongs to Control Room; sustained nonurgent technical repair goes directly to IT. Mechanical Fleet maintenance remains Fleet-owned unless a technical IT task is identified. Operational impact, not a sender's word “urgent”, determines the boundary.

**DP06 — sensitive documentation/vault:** recommend explicit metadata/read/edit/review/reveal/audit capabilities with site and record classification. Existing `it.view` must not automatically gain vault or all-site restricted-document access. Use approved SSO step-up; do not weaken authentication as a convenience.

**DP07 — delivery verification:** authorized Microsoft/Gmail test accounts, support identity and test recipients are needed for live provider acceptance. Build/test with isolated provider fakes meanwhile; leave live verification blocked until permitted. Retention, scanner and encrypted backup/key-custody policy also need operational owners.

**DP08 — AI:** no provider activation in this programme. Agree only module-owned context/action descriptors and a disabled capability response. The future whole-app programme owns provider choices, retrieval policy, execution, quotas and deployment.

## 6. Work packages and dependency order

All boxes below start unchecked. Each package should be split into reviewable changes: domain/data contract, UI wiring, and focused verification where needed. Do not combine all packages into one enormous change. “Files” names the starting points, not a mandate to edit every file named.

### W00 — Baseline, traceability and safe test environment

- [ ] **Deliver:** record current tree/assets, read-only design hashes, actual roles, enabled channels and safe fixture setup. Establish an acceptance ledger for all packages and audit IDs. Reproduce F01 with a fresh uninterrupted session; trace request, validation, transaction, response and delivery timing. Record whether the original issue is reproduced, corrected or still unverified.
- **Depends:** none. **Files:** audit/evidence, routes, test configuration and existing IT fixtures. Preserve test configuration secrets; do not paste them into the ledger.
- **Accept:** the exact tested host/checkout is known, notifications cannot escape the test environment, baseline fixture IDs are recorded, and each remaining package has a concrete next step. No broad re-audit, seeding reset or repository-wide test run is required.

### W01 — Authorization and privacy contract

- [ ] **Deliver:** document and enforce requester, restricted technician, service/team lead, approver, documentation author/reviewer, credential reader/revealer and integration administrator capabilities. Reuse existing permissions; add only genuinely missing fine-grained capabilities. Keep site/record checks centralized and align query scopes with mutation policies.
- **Depends:** W00. **Files:** `ItWorkAccessService`, `ItProvisioningAccessService`, `ItTicketPolicy`, relevant form requests/presenters and setup/credential policies.
- **Accept:** same-site other-requester and unapproved-site direct IDs are denied; assigned/shared-team exceptions follow policy; sensitive data is absent from payloads, exports and notification recipients. Revocation during an open form is enforced on submit. Use restricted test fixtures, not only admin. New permissions do not automatically widen existing staff access.

### W02 — Reliable intake, idempotency and recoverable errors

- [ ] **Deliver:** repair the established cause of F01; unify channel-neutral creation and mutation contracts; add persistent idempotency and expected-version handling. Separate committed ticket success from notification failure. Give validation, authentication expiry, conflict, timeout and unknown-outcome responses distinct UI behaviour. Provide a safe way to recover the original result after a lost response.
- **Depends:** W00–W01. **Files:** `ItTicketIntakeService`, `ItTicketTriageService`, `ItProvisioningController`, `StoreItTicketRequest`, wizards and relevant notification/outbox code.
- **Accept:** staff and technicians create persisted work; a double click, timeout retry and concurrent identical command create exactly one record. A conflicting retry cannot overwrite it. Failed validation/expired session preserve the draft without sending private text to logs; successful submission yields a stable reference and navigable record. Tests cover after-commit dispatch failure, not just successful HTTP status.

### W03 — Triage, approved site, routing and ownership

- [ ] **Deliver:** approved-site choice for staff; coherent impact/urgency-to-priority triage; service/category rules; named accountable queue/team/owner; fallback and cover; authorized manual reassignment/override with a reason and routing explanation. Define whether later classification re-routes and preserve an intentional override until explicitly released.
- **Depends:** W01–W02. **Files:** `ItTicketRoutingService`, `ItTicketTriageService`, `ItServiceManagementSetupService`, routing presenter, intake forms and ownership UI.
- **Accept:** browser, email and system adapters can invoke the same routing decision; a worker chooses between two approved sites but not a third. Unmatched rules produce monitored fallback work, inactive staff do not receive new work, and reassignment is audited. Configure real owners only with supplied operational choices; zero configured queues is a visible gap, not green health.

### W04 — SLA clocks, waiting, breaches and escalation

- [ ] **Deliver:** one authoritative clock verdict and coverage/freshness definition across header, list, detail, reports and scheduler. Complete business hours, holidays, approved pause reasons, requester/vendor/approval waits, escalation, reprioritization and reopen accounting. Record original policy and policy changes; retain breach history even after resolution.
- **Depends:** W02–W03. **Files:** `ItTicket`, SLA policy/business-hours code, `CheckItSlaStates`, `ItAutomationScheduleCatalog`, report queries and `it-hero`/`it-overview`.
- **Accept:** unmeasured is distinct from healthy; a stopped watchdog shows stale status; response and resolution can breach independently; first reply does not erase a prior breach. Waiting across a weekend/holiday and NZ daylight-saving change produces agreed deadlines. Escalation reaches eligible cover within the configured maximum latency. A known fixture dataset agrees in every surface.

### W05 — Navigation and list conformance

- [ ] **Deliver:** section 4 navigation, compatible old links, semantic ticket identities, shared card/table rendering, count caption, Cards/Table switch, all existing menu actions, functional search/sort/filter/saved views and explicit bulk selection scope. Move Knowledge/Reports only once their landing routes work; use temporary canonical redirects rather than two competing implementations.
- **Depends:** W01, W03–W04; coordinate new destinations with W17/W21. **Files:** `ItModuleNavigation`, `app-sidebar.tsx`, IT index/header, shared list/rail consumers and saved-filter service.
- **Accept:** no duplicate sidebar/knowledge/report destinations; Find and all meter links work; My requests count means what its label says. Back navigation restores filters/scroll; permission changes remove inaccessible saved results. At 390px titles and next actions are readable; desktop keeps the approved rail seam. Kebab and right-click expose the same permitted actions. Bulk action preview reports selected count and per-item rejection without leaking hidden records.

### W06 — Coherent ticket detail and form experience

- [ ] **Deliver:** section 4 detail anatomy using PageHeader/profile navigation; scoped search, readable title, primary next action and correctly placed properties/work. Consolidate create/edit bodies in approved WizardShell/simple dialogs. Add dirty-close confirmation, scoped recoverable drafts, field-based completeness, accessible validation, state-appropriate skeletons and success follow-up links.
- **Depends:** W02–W05. **Files:** `pages/it/tickets/show.tsx`, `it-wizards.tsx`, ticket drawer, existing autosave/draft helpers and shared UI primitives.
- **Accept:** Escape, backdrop, route navigation and full-page handoff cannot silently lose entered work. Draft restore offers Resume/Discard and respects access/session changes. Required-task or approval failures name the blocker inline and preserve input. Keyboard focus moves to errors and returns to the trigger on close. No doubled shell padding, old PageHero banner or overlapping narrow-screen content remains on covered pages.

### W07 — Conversation, files, watchers and delivery-aware replies

- [ ] **Deliver:** one interaction boundary for public replies/internal notes; last public speaker and next responsible party; separate audience-aware drafts; attachments through protected canonical storage; watch/unwatch/add/remove eligible watchers; clear sender identity and delivery state. Fix success callbacks so rejected writes never clear the composer or say sent.
- **Depends:** W01–W04, W06. **Files:** `ItTicketInteractionService`, ticket-thread/drawer, attachment service/controllers, activity presenter, `ItEmailDeliveryService` and IT notifications.
- **Accept:** a later requester reply returns to Awaiting IT after the first agent response; internal notes/receipts do not move that queue. Unauthorized users cannot retrieve a file or its preview through a copied URL. Oversize/disallowed/failed upload is recoverable, scanner failure/quarantine is explicit, revoked watchers stop receiving new data, and comment commit versus delivery failure is distinguishable. Settled work cannot acquire an ungoverned reply.

### W08 — Tasks, dependencies and approvals

- [ ] **Deliver:** complete task creation/edit/order/assignment, required/optional flags, prerequisites, evidence, due date, completion, reopen and cancellation semantics. Complete approval request, decision, rejection, expiry/reminder and eligible cover without bypassing self-approval or site rules. Show dependency blockers and pending approver in the work panel and existing personal tasks surface.
- **Depends:** W01, W03–W07. **Files:** `ItWorkTaskService`, `ItTicketApprovalService`, transition service, work-task controller and existing task provider integration.
- **Accept:** cycles are rejected; dependent work stays blocked until prerequisites pass; replacing/cancelling a dependency has explicit consequences. An ineligible actor cannot approve by direct ID. A rejected or expired approval is visible; an approved catalogue request unlocks the correct tasks; required unfinished work prevents resolution with a direct corrective link. Historical decisions remain immutable/audited.

### W09 — Resolution, confirmation, reopening, related work and merge

- [ ] **Deliver:** meaningful resolution code, public explanation, verification/evidence, linked known error/article and consistent draft-from-resolution action. Complete requester confirmation, CSAT, governed reopen/closure and seven-day auto-close policy. Finish related-ticket types, scoped duplicate suggestions and merge preview/canonical redirect behaviour.
- **Depends:** W04, W06–W08; documentation publication link completes with W21. **Files:** interaction/transition/merge/link services, policies, resolve/reopen/CSAT UI and close scheduler.
- **Accept:** ticket→work→resolve→requester still-needs-help→reopen→resolve→confirm/close works for restricted roles. Reopen reason audience matches the displayed wording when actor is also requester. CSAT is recorded once with permitted revision policy. Merge protects conversation audiences, files/watchers/approvals and references; old subject references/deep links reach the authorized canonical record. No auto-close hides unfinished work; resolution quality appears in reports.

### W10 — SSO settings that persist and describe real state

- [ ] **Deliver:** wire Microsoft, Google and provisioning settings to the existing canonical settings/auth configuration; read it back accurately, validate it and mask stored secrets. Preserve working group mapping. Separate deployment-managed configuration, saved configuration, consent and verified connection status. Keep Settings as the only place to manage sign-in credentials.
- **Depends:** W01–W02 and DP07 for live checks. **Files:** `settings/sso-config.tsx`, `SsoConfigController`, `SsoGroupController`, settings routes and existing authentication/config resolvers.
- **Accept:** each Save has a real authorized backend action and survives refresh; failed save preserves nonsensitive fields and explains the error. Blank secret input means keep existing, not erase. Explicit replacement/removal is deliberate and audited without values. Provider/domain/role mappings cannot broaden staff access accidentally. Test denied configuration, invalid callback/config, group removal and approved provider sign-in. Do not claim local save proves real provider consent.

### W11 — Microsoft/Gmail inbound channel completion

- [ ] **Deliver:** reuse `ItMailboxConnection`, OAuth controller, mailbox settings, polling and inbound ledger; repair typed provider failures, token refresh, status recovery, paging, message-ID uniqueness, acknowledgement retries and quarantine. Normalize provider message/References/In-Reply-To information; use shared intake/reply commands, routing and settled-work policy. Implement bounded protected attachments and loop/bounce/auto-reply handling.
- **Depends:** W01–W04, W07, W09–W10. **Files:** `PollItMailboxJob`, `InboundEmailIngestor`, `MicrosoftGraphService`, `GoogleGmailService`, mailbox controllers/model and reference resolver.
- **Accept:** for each provider, new mail creates one correctly routed ticket, changed-subject reply threads correctly, duplicate/replayed mail is processed once, merged references resolve safely, and settled-work mail follows reopen/related-request policy. Unknown/inactive senders and ambiguous references quarantine without broadening access. More than 25 unread messages drain without starvation. Refresh expiry, 403, 429, timeout and mark-read failure show truthful state and recover without reconnecting unnecessarily. Unauthorized mailbox access cannot be configured merely by typing an address.

### W12 — Outbound support identity, replies and delivery recovery

- [ ] **Deliver:** implement the agreed full-reply/link-only policy through existing outbound settings and delivery ledger. Correct transport handling of provider rejection, missing identity, recipients, reply-to, threading headers and approved files. Retain provider IDs and truthful queued/sending/accepted/delivered/bounced/failed semantics; accepted is not delivered. Handle callback ordering and retry of an already-accepted/ambiguous send safely.
- **Depends:** W07, W10–W11. **Files:** `ItEmailDeliveryService`, IT notifications, `MicrosoftGraphTransport`, existing Gmail/outbound transports, callback and retry controllers.
- **Accept:** test recipients receive the selected public content from the support identity, replies return to the correct mailbox/ticket, internal notes never send, and partial-recipient failure is visible. A provider's false/error result cannot become accepted. Duplicate/out-of-order callbacks do not regress final state; transient retry does not intentionally re-send accepted messages. A lost acknowledgement is reported as uncertain and reconciled where supported, not promised exactly-once delivery when the provider cannot guarantee it.

### W13 — API identities, channel provenance and failure operations

- [ ] **Deliver:** complete the existing scoped IT API/service-identity lifecycle, capability restrictions, issue/rotate/revoke flow, rate limits, idempotency, validation, safe audit and failed-ingress review/replay. Join mailbox, delivery and automatic-ticket failures in the existing Operations audit without creating a second scheduler or unrelated operations platform.
- **Depends:** W01–W04, W11–W12. **Files:** `ItApiWorkItemService`, `ItServiceIdentityCredentialService`, API controllers/field policies, automation recorder and setup UI.
- **Accept:** revoked/expired credentials stop working immediately; a forged site/device/sensitive capability is denied; replay of a failed authorized intent creates one result with provenance. Secrets appear only in the authorized creation/rotation flow and never in logs/list responses. Operators see last success, error category, attempt count, oldest pending work and a permitted recovery action. API read/update/link operations are verified, not only create.

### W14 — Fleet/Devices/Control Room automatic work and handoff

- [ ] **Deliver:** a source-capability inventory and deterministic routing policy that extends existing `DeviceSignalPublished`, monitoring listener, Fleet signal/outbox and Control Room processing. Add direct nonurgent technical intake, urgent Control Room→IT create/link handoff, safe diagnostic preview, canonical source links, responsible service/team and updates/recovery. Define issue episodes, maintenance suppression, flapping hold-down, retry/replay and recurrence after closure.
- **Depends:** W01–W04, W07–W09, W13; DP05. **Files:** `DeviceEventObserver`, `CreateOrUpdateMonitoringTicket`, `FleetSignalService`, `DispatchFleetSignalOutbox`, `SignalProcessingService`, ticket context/link presenters and the actual Control Room/asset/device actions.
- **Accept:** sustained nonurgent technical fault produces one routed IT ticket and no unnecessary Control Room alarm; urgent operational failure remains acknowledged in Control Room with one linked technical work item. An operator can link existing work rather than duplicate it. Duplicate bursts/replays do not multiply tickets; privacy-restricted source data stays out of ticket projections. Recovery records evidence and requests verification rather than auto-closing. A fault after closure follows an explicit episode/reopen rule. A ticket update cannot re-emit its own original fault.
- **Source coverage:** enumerate existing actionable Fleet/asset and device events before promising each automatic trigger. For missing technical-health events, implement an owning-module event adapter only if its source data exists; otherwise record the exact collector/source dependency. Mechanical service, driver safety and care incidents remain in their owning operational workflow with IT follow-up only when warranted. Unsupported source capability is never a fake healthy integration.

### W15 — Service catalogue and full provisioning lifecycle

- [ ] **Deliver:** finish catalogue authoring/version/publish/unpublish, permitted audiences/sites, field types and validation, requested-for permissions, attachment fields, preview and submission. Complete the current provisioning templates and request lifecycle for joiner/mover/leaver or other configured workflows, including approvals, dependencies, owner, failure/retry/cancel, fulfilment evidence and employee/asset/account links. Preserve the requested catalogue/template version on submitted work.
- **Depends:** W01–W04, W06–W09, W13. **Files:** catalogue management/submission/field-option services, provisioning template/workflow/lifecycle/access services, setup/index/request views and relevant HR event/task adapters.
- **Accept:** a published service request produces the right ticket/workflow once, collects required fields, waits for eligible approval, completes real work/evidence and updates the requester. A withdrawn item cannot accept new submissions but existing requests retain their original contract. Changing a template cannot silently rewrite active tasks. Partial fulfilment/failure/retry and leaver cancellation/reversal follow explicit rules. A checked box alone must not pretend an external account was provisioned: use a verified adapter or a labelled manual evidence step.

### W16 — Complete Problems, Changes, Major Incidents and service visibility

- [ ] **Deliver:** finish existing specialized records using their shared ticket identity, not parallel repair tickets. Split into three reviewable slices plus service-health publication:
  - Problems: incident relationships, recurrence evidence, investigation, root cause, workaround/known error, permanent fix/change link, review and closure/reopen.
  - Changes: standard/normal/emergency classification, risk/impact, approver, affected service/assets, maintenance window/conflict detection, implementation and rollback plans, execution evidence, validation, failed/backed-out outcomes and post-change review.
  - Major incidents: declaration/severity, commander/communications owner, linked affected work/services/sites, timed updates, audience-safe publication, restoration verification, review and closure.
  - Service visibility: authorized staff outage notice/subscription and next-update promise derived from approved service/incident state, not unrestricted monitoring payloads.
- **Depends:** W01–W09, W14–W15; coordinate work scheduling with W19 and known-error content with W21. **Files:** existing problem/change/major-incident services/controllers/pages and service presentation/notifications.
- **Accept:** each complete lifecycle succeeds for the right role and rejects invalid jumps. A failed change records rollback; overlapping windows are surfaced; overdue incident updates reach cover; a known error links to the reviewed workaround. An outage is discoverable during Get help, staff can subscribe instead of raising a duplicate, and restoration updates the same notice. Shared ticket status and specialized state cannot diverge silently.

### W17 — Reconciled reporting, audit and exports

- [ ] **Deliver:** dedicated Reports page backed by existing access-scoped report/export services. Publish definitions for backlog, channel demand, time-to-own, first response, resolution, waiting, breached/met/unmeasured SLA, reopened work, first-contact resolution, CSAT, task/approval ageing, effort, service demand and automation outcomes. Add freshness and usable drilldowns. Keep current-state counts distinct from historical period metrics.
- **Depends:** W03–W04, W07–W09, W13, W15–W16; effort measures finish with W19. **Files:** `ItReportsController`, report components, summary/overview queries, event/activity presenters and export helpers.
- **Accept:** a small known dataset reconciles chart→filtered list→CSV; timezone/date boundaries are consistent; denominator and excluded/unmeasured records are disclosed. Header/report refresh is coherent after another tab's update. CSV formula injection, unauthorized scope, large export bounds and filename/content type are tested. Internal content is not added to staff exports. No fabricated trends, empty compliance percentages or metrics without an actual source.

### W18 — Reusable replies, templates and explainable macros/rules

- [ ] **Deliver:** governed reusable public/internal replies, technician ticket presets and multi-action macros, extending existing catalogue/knowledge/routing where useful. Add ownership/review, safe placeholders, preview, permissions and version history. Extend routing into allowlisted event/condition/action rules with dry run, precedence, stop/continue semantics, execution audit and bounded retries.
- **Depends:** W02–W04, W07–W09, W13, W15; link reviewed knowledge when W21 lands. **UI:** composer insert menu, Log ticket presets, Setup→Automation; “Why this routing?” on a ticket.
- **Accept:** a technician previews and edits a safe reply; invalid/secret placeholders cannot send. A macro's preview names all resulting changes; a stale or unauthorized target is rejected safely. Rule dry run explains matched and skipped records without mutation, duplicate execution is idempotent, disabling a rule prevents future execution, and a rule cannot recursively trigger itself into a ticket storm. No arbitrary shell/script runner is introduced.

### W19 — Effort, technician planning, dispatch and handover

- [ ] **Deliver:** auditable manual time entries and optional timer with start/pause/stop/correct, linked to ticket/task and protected from overlaps/duplicate submission. Add internal capacity/effort summaries, appointments and dispatch using existing calendar/availability/task sources. Provide team cover, reschedule/cancel, conflict handling and structured handover with next commitment and acknowledgement.
- **User clarification, 9 September 2026:** a ticket supports multiple assigned technicians/resources and multiple scheduled work blocks, including a different assignee, start/end time and work status for each block. Keep one accountable ticket owner. Project each person's allocation into the existing personal work/calendar and team planning views; reuse canonical staff, task, availability and calendar records. Changing or removing an allocation must reconcile those projections, preserve audit history and handle concurrent edits, overlaps, absence cover, rescheduling and cancellation. This is internal single-organisation dispatch, with approved-Site and existing permission boundaries.
- **Depends:** W01–W03, W07–W09, W17; integrate maintenance windows from W16. **UI:** ticket Work, proposed Work planning, existing personal calendar/task projections. Technician effort stays internal and does not become MSP billing or a duplicate payroll timesheet.
- **Accept:** timer survives normal navigation/session interruption through explicit recovery; a second session cannot double-count it. Correction has a reason/history. Booking a technician detects conflicting commitments and absence, rescheduling updates the same canonical appointment, and a handover transfers accountable next action with history. Requesters see agreed visit/update information, not private effort notes or HR availability detail.

### W20 — Recurring work, reminders and proactive support

- [ ] **Deliver:** recurrence plans with timezone, start/end, exceptions, owner, ticket template, next occurrence, pause/resume/retire and run history. Add reminders/escalation for next updates, approvals, appointments, reviews and renewals through the existing scheduler. Add supported proactive source cases such as certificate expiry, integration failure or validated device-health risk through W14, with source coverage explicit.
- **Depends:** W03–W04, W13–W14, W18–W19; documentation/renewal adapters finish with W22–W23.
- **Accept:** each due occurrence creates one correctly owned work item despite restart/replay; edit applies to future occurrences without mutating past work. Paused/retired plans stop; missed-run catch-up is bounded; holidays and daylight-saving transitions are deterministic. An ongoing fault/renewal does not generate a ticket every poll. Source noise/maintenance suppression is auditable, and correcting the source updates the linked work appropriately.

### W21 — Dedicated knowledge, reviewed revisions and resolution reuse

- [ ] **Deliver:** move the existing Knowledge destination to section 4's workspace with stable reader URLs and compatibility links. Extend article lifecycle with immutable revisions, working draft versus published revision, compare/restore, reviewer attribution, audience/site classification, owners/tags/templates and safe full-text search. Complete helpful/deflection feedback and resolution→draft→review→publication with a canonical revision link back to the ticket.
- **Depends:** W01, W05–W09; publish navigation only once the reader works. **Files:** `ItKbArticle`, `ItKbLifecycleService`, `ItKbController`, current KB projections/editor and new workspace route/page composed from shared primitives.
- **Accept:** technicians can read without entering Edit; staff see only permitted published content. Editing published content does not change what readers see until approved. Search and snippets filter permissions before display, direct article/revision IDs are protected, and restore creates traceable history. Draft-from-resolution strips internal/private/secret material for review; a later user opens the exact guide and records a real solved/helpful interaction. No guessed “tickets deflected” figures.

### W22 — Structured documentation, relationships and protected diagrams

- [ ] **Deliver:** structured documentation for systems/applications/services and repeatable runbooks, with typed templates/required fields, owner, audience, versions, review dates and links. Reuse Sites, services, vendors, assets/devices and Problem Management records. Add protected documentation files/diagrams with revision, safe preview/download, search/tagging and relationship navigation. Add broken-link/no-owner/stale-review checks with real correction actions.
- **Depends:** W01, W07, W14–W16, W21. **UI:** Systems & services, Sites & assets, record documentation sections and Reviews. **Data:** add missing documentation entities/relationships only after mapping existing canonical owners; no second inventory/CMDB that competes with Assets/Devices.
- **Accept:** ticket→service/system→runbook→canonical site/device→back works with consistent permission checks. A diagram revision retains history; unauthorized preview/original URLs fail. Runbook states symptoms, prerequisites, safe checks, escalation, validation and rollback. Removing/retiring a linked source does not leave misleading active relationships. Review reminders reach the right owner and clear through recorded review, not by merely opening the page.

### W23 — Suppliers, contacts, licences, warranties and renewals

- [ ] **Deliver:** link canonical vendor/contact/asset/commercial records into documentation; add missing licence/warranty/support-contract/domain/certificate renewal metadata with named owner, dates, evidence, review and reminder lifecycle. Support notice periods and active/renewed/retired states; record which source owns authoritative terms and dates.
- **Depends:** W01, W20–W22. **UI:** Reviews & renewals and linked asset/vendor/system records. Financial terms remain behind their existing permission boundary; staff guides do not reveal them.
- **Accept:** a renewal due date creates one owner follow-up, links to the canonical agreement/asset and records renewed evidence; changing a date updates future reminders without duplicate work. Retirement cancels future reminders while preserving history. Contacts resolve to the existing directory, and external supplier communication requires the normal explicit authorized-send action. No duplicate purchasing ledger or copied vendor master data.

### W24 — One shared vault with complete security and lifecycle

- [ ] **Deliver:** reuse `SiteCredential`, `SiteCredentialController` and `SiteCredentialEncryptionService` from both Sites and IT. Complete masked metadata, granular authorization, reveal/copy audit, step-up authentication including SSO-only staff, concealment, TOTP safeguards, version/retirement/recovery policy and evidenced rotation. Distinguish external password change, attested change and encryption-key maintenance.
- **Depends:** W01, W10, W21–W23; DP06–DP07. **UI:** Credentials view and masked references from runbooks; no duplicate secret fields in documents.
- **Accept:** both entry points operate on the same ID with identical access denial; reveal is audited before returning a secret and fails closed when audit cannot persist. The product authorizes/logs copy before offering it and handles browser clipboard failure honestly; it cannot promise to prevent manual copying of an already-visible value. Conceal after the established timeout and on context changes. Rotation cannot become “complete” from a date-only button. Permission loss, SSO step-up failure, retired record, concurrent edit and encrypted test-backup restore all have demonstrated outcomes. No secrets in screenshots, generic drafts, logs, search, exports or AI context.
- **Migration:** retain existing ciphertext compatibility and verify decryptability with disposable records before/after changes. Do not bulk rotate real credentials or rewrite keys as part of a UI migration. Any external rotation requires the normal separate operational authorization.

### W25 — Complete AI layout and integration foundation, disabled by default

- [ ] **Deliver:** production-quality responsive UI components, typed capability/context/result contracts, authorization projections, disabled/unavailable behaviour and isolated preview fixtures. This is the user's explicit boundary; model execution and the whole-app AI system remain future work.
- **Depends:** W01–W02, W06–W09, W17, W21–W24. Inspect the existing [LlmClient](C:/Users/steph/Herd/oblivionfindings/app/Services/Llm/LlmClient.php), [LlmServiceProvider](C:/Users/steph/Herd/oblivionfindings/app/Providers/LlmServiceProvider.php), RAG controllers and [global-query-bar](C:/Users/steph/Herd/oblivionfindings/resources/js/components/global-query-bar.tsx). They exist for other scopes and do not prove an app-wide IT assistant already exists. Do not route IT secrets/content through the client-care RAG pipeline or copy legacy tenancy assumptions into new contracts.

**Surfaces to prepare:**

1. **Ticket summary/handover:** contextual “Assist” action opens a shared in-page panel or approved dialog. Desktop can show it alongside work without becoming a competing sidebar; narrow web uses the existing dialog treatment. Show what record/revision/audience is selected, what content can be used, source references, generated time and “Draft suggestion” status.
2. **Reply draft:** choose a public/internal audience first. Future result offers Insert into draft, Replace selected text with explicit confirmation when needed, or Discard. It never sends automatically. Existing typed text is preserved; stale suggestions cannot replace a newer draft silently.
3. **Suggested guidance/triage:** display permitted reviewed article links and proposed category/priority/next action with evidence and missing-context explanation. Applying a future suggestion uses the existing authorized, versioned command; no assistant-specific bypass.
4. **Documentation assistance:** prepare draft-from-resolution, summarization and completeness-review layouts with cited article/ticket revisions. Publication still uses human author/reviewer actions. Credentials have no AI action, prompt field or context-export toggle.

**Contract to prepare:** a module adapter exposes supported capability identifiers, enabled/disabled reason, authorized record references and revision/version, audience, permitted source descriptors and an allowlisted action proposal schema. Actor identity and capabilities are derived server-side, not trusted from a browser payload. Future results carry source references, evidence timestamp, content version, output text/proposed fields, uncertainty/unavailable information and an operation ID. Revalidate source access before rendering/opening a reference and target access/version before applying a proposal. Never serialize the entire ticket model, raw provider payload or vault value into a generic context object. Mark email/document/source content as untrusted data: instructions inside it cannot grant permission, change policy or authorize an action. Include this requirement in the future adapter checklist and test contract.

**UI states to build and verify with isolated fixtures:** not configured, not permitted, ready, working, partial result, complete, no relevant evidence, provider unavailable, cancelled, retryable failure, context changed and access revoked. Use shared skeleton/loader/error components; support stop/retry and retain drafts. Avoid fake confidence percentages. A source link must navigate to a real authorized record/revision when the future adapter is active.

**Production behaviour until whole-app AI exists:** capability remains disabled and no provider/network job is dispatched. Hide routine AI actions for ordinary users when disabled; an authorized configuration/preview surface can state “AI assistance is not connected” without a dead active button. Development/test previews are clearly labelled, isolated and unavailable as fabricated production responses. Do not build provider settings, prompts containing live data, vector indexing, execution queues, autonomous remediation or a second global AI platform here.

**Accept:** all layouts/states work at desktop and 390px with keyboard/focus; disabled paths make zero provider calls; capability denial excludes context; secret and disallowed classified fixtures never enter allowed DTOs; mock result insertion uses draft actions without sending or publishing; stale result/revoked source is rejected. Deliver a short adapter contract and integration checklist so the later whole-app AI session can connect an approved implementation without redesigning IT screens. This completes the AI foundation only, and must be named that way in final reporting.

### W26 — Operational deployment, retention and recovery readiness

- [ ] **Deliver:** complete runbooks/configuration checks for scheduler/workers, provider recovery, failed-job/outbox replay, attachment quarantine, backups, retention, audit access, credential key custody and migrations. Instrument truthful last-success/lag/failure signals in Operations audit with privacy-safe correlation IDs. Use existing scheduler/monitoring/backup facilities where available.
- **Depends:** W02–W04, W11–W24. **UI:** existing Operations audit plus internal documentation, not a new global console. Operational capability and necessary deployment configuration are part of completeness even when code is finished.
- **Accept:** a stopped test worker is detected, recovery drains pending work once, a provider outage does not block unrelated browser intake, and an authorized replay is traceable. Restore isolated ticket/document/attachment/vault fixtures and reconcile related records. Verify retention respects documented audit/recovery requirements. Rollback instructions identify what can be reversed safely and what requires a forward data repair. No actual production rollout is performed without separate authorization.

### W27 — Integrated feature-completeness and design acceptance

- [ ] **Deliver:** close every acceptance scenario in section 9, every audit finding and all 36 audit backlog items through the mapping in section 10. Resolve newly discovered defects in this bounded IT scope, preserve required menu actions and prove no unsupported controls remain. Collect concise actual browser evidence and exact targeted check results.
- **Depends:** W00–W26. **Accept:** all required packages are Verified; the AI foundation passes its narrower contract; provider/operational blocks are resolved before claiming complete readiness. Verify protected design files are unchanged from the implementation session's starting baseline. Record any remaining externally blocked verification candidly, with an exact next action; do not relabel it complete or delete it from scope.

## 7. Data, migration and compatibility plan

Prefer additive, reversible schema changes and narrow service refactors. Proposed concepts such as command receipts, versions, clock/response metadata, effort entries, recurrence occurrences and document revisions require checking existing tables first. Names here describe capabilities, not a demand to create duplicate tables.

1. **Inventory before migration:** document authoritative IDs, existing constraints, retained soft-deleted/merged records, legacy organization-context requirements and which values are historical evidence. Do not modify canonical reference formats casually.
2. **Introduce schema safely:** add nullable/backfillable fields and supporting indexes, implement dual-compatible reads where necessary, then migrate bounded batches. Add uniqueness constraints only after a report identifies and safely resolves existing conflicts. Backfills must be restartable with dry-run counts and audit evidence.
3. **Ticket history:** preserve references, requester/assignee history, replies/internal flags, files, watchers, approvals, source provenance and merge destinations. Reconstruct new next-action/clock fields only from sufficient evidence; mark ambiguous historical values unknown. Do not fabricate historical first-response or SLA success.
4. **Documentation:** migrate each current article to a preserved baseline revision with its existing publication/audience attributes. New drafts must not silently republish historical content. Keep old KB paths and feedback links functional through canonical redirects or adapters.
5. **Credentials:** link existing IDs. A new UI or documentation relationship must not duplicate/re-encrypt secrets unnecessarily. Test encrypted data compatibility and restore before changing lifecycle storage. Keep the existing access boundary until the replacement is explicitly verified.
6. **Links and API contracts:** preserve existing `/it/tickets/{id}`, saved queries, notification links, callback endpoints and API response compatibility. New Knowledge/Reports routes must not intercept the existing report data/export endpoints. Catalog JSON/submission routes must not unexpectedly start returning page HTML.
7. **Deploy in dependency order:** schema compatible with old readers → updated domain services → UI/routes → controlled backfill → verified constraints → remove temporary compatibility only after old references are accounted for. Do not leave permanent double-write lifecycles that can diverge.
8. **Rollback/recovery:** retain an isolated pre-migration snapshot and row-count/invariant reconciliation. Prefer feature disablement/forward repair where deleting a migration would lose new user work. Rollback instructions must distinguish code rollback from irreversible data loss; never run reset/fresh seeding against the working application.

## 8. Delivery milestones — all remain in scope

**M1: coherent daily browser service.** W00–W09, plus enough W13/W26 health visibility to operate it safely. Exit: staff and restricted technician journeys pass, deadlines/counts/ownership are truthful, drafts/collisions/privacy are safe, and approved responsive UI is verified. This is a pilot milestone, not final completion.

**M2: complete essential channels and service delivery.** W10–W17, finishing the W05/W09 dependencies. Exit: real approved Microsoft/Gmail tests, API/replay and Control Room/Fleet/Devices handoffs pass; catalogue/provisioning and all specialized lifecycles work; reports/exports reconcile. Missing provider access must be resolved for this milestone's verification.

**M3: technician productivity and proactive operations.** W18–W20 with their source integrations. Exit: usable macros/rule preview, timer/effort, booking/cover/handover, reminders and idempotent recurrence, with source-backed proactive work. These are required enhancements, not optional ideas left after claiming feature completeness.

**M4: documentation and shared vault.** W21–W24, completing review/renewal and resolution-to-knowledge dependencies. Exit: reviewed versions, structured runbooks/relationships/protected diagrams, canonical commercial links and the shared vault pass their complete access/lifecycle/recovery journeys. Navigation is consolidated at this point.

**M5: AI-ready web layouts and operational release evidence.** W25–W27. Exit: AI foundation is complete and disabled, whole-app integration handoff is documented, operational recovery is demonstrated, and every required acceptance item is verified. Model functionality is explicitly reserved for the separate whole-web-app AI programme.

Within a milestone, use bounded changes such as “requester site and idempotency”, “shared SLA verdict”, “ticket list card/table”, “public conversation parity” or “Graph failure handling”. Finish a vertical slice with its relevant UI and tests before moving to another; do not build all backend placeholders and defer every usable screen until the end.

## 9. Verification strategy and release acceptance

Verification belongs to each vertical slice. Reuse and extend the current tests rather than treating the audit's source inspection, an old screenshot, or a test filename as evidence that the new behaviour passes. Record actual results against the checkout tested. A failed or skipped required check remains open.

### Safe, focused automated verification

- Inspect [docs/testing.md](C:/Users/steph/Herd/oblivionfindings/docs/testing.md), `phpunit.xml`, `package.json`, `playwright.config.ts` and relevant fixture setup before running checks. Confirm the resolved test database is isolated and disposable before any test using database refresh/migrations. An environment named testing is not sufficient proof. Never expose configuration secrets in evidence.
- Run the smallest meaningful PHP/Pest groups covering changed domain behaviour and authorization. Add tests for real invariants: duplicate commands, stale writes, restricted actors, delivery failure, scheduler interruption and lifecycle boundaries. Do not write tests that merely repeat implementation details or snapshots of reversible copy changes.
- Extend focused frontend tests for draft recovery, conflict/error states, accessible interactions and authorized actions where those behaviours warrant tests. Use existing repository-local tooling; do not introduce another test framework or download tools implicitly.
- The current default `lint` and `format` scripts mutate broad paths. Do not use them for an unrelated repository-wide rewrite. Run the existing linter/formatter on changed files with the intended check/write mode. A global type check is useful before the final gate; distinguish pre-existing failures from regressions without suppressing them.
- The current IT/security Playwright suite spans multiple specifications and desktop projects. Inspect global setup and fixture side effects before execution; it does not establish 390px coverage. Extend appropriate canonical specifications and use a minimal authorized IT fixture scope. Browser automation must follow the session's permitted tools; terminal-driven Playwright is not a substitute for the required Codex in-app browser walkthrough.
- Build once when the changed frontend needs compiled asset verification, and again only after changes that invalidate that result. Avoid repeatedly rebuilding or running the entire repository suite after already-passing narrow changes. Broaden checks for changed shared primitives, cross-module contracts, or unresolved failures.

Existing PHP starting points under `tests/Feature/It/` include `ItTicketSelfServiceTest.php`, `ItTicketWorkspaceTest.php`, `ItTicketQueueTest.php`, `ItTicketTriageTest.php`, `ItTicketAuthzTest.php`, `ItWorkAccessServiceTest.php`, `ItWorkAccessControllerTest.php`, `ItTicketAttachmentTest.php`, `ItTicketApprovalTest.php`, `ItWorkTaskTest.php`, `ItWorkTransitionTest.php`, `ItTicketLifecycleTest.php`, `ItTicketMergeTest.php`, `ItTicketBulkTest.php`, `ItSavedTicketFilterTest.php` and `ItTicketCsatTest.php`.

Use the corresponding SLA/business-hours, mailbox/polling, inbound/Graph/Gmail, secure API, monitoring/Control Room, catalogue/provisioning, Problems/Changes/Major Incidents, reports, KB and setup tests for those packages. For new capabilities, add focused tests beside these families. Revalidate names against the current checkout. Browser specifications starting points are [it-service-management-acceptance.spec.ts](C:/Users/steph/Herd/oblivionfindings/tests/e2e/it-service-management-acceptance.spec.ts) and [it-service-management-navigation.spec.ts](C:/Users/steph/Herd/oblivionfindings/tests/e2e/it-service-management-navigation.spec.ts).

### Required browser evidence

Use the Codex in-app browser against the verified current local host and assets. Start from actual navigation, use real controls and inspect the saved result after refresh. Record role, approved sites, fixture references, route, viewport, expected/actual result and screenshot or concise observation. Do not store passwords, tokens, secret values or unrestricted private payloads.

Verify the critical requester and technician journeys at **1440px and 390 × 844px**, and check constrained desktop layouts at **1280px**. Narrow-screen evidence is for the web application. Exercise keyboard-only use, visible focus, 200% zoom, dark/light themes and reduced motion on the affected shared patterns. Validate permission-denied, empty, loading, error, stale and retry states as well as populated success. If a shared header/list/dialog primitive changes, include Sites as a regression reference without redesigning it. Masked vault views can be captured; revealed secrets cannot.

### End-to-end acceptance scenarios

Each scenario has a persisted outcome, permission check and failure/recovery variant. These supplement the package-specific criteria; they do not replace them.

- [ ] **E01 — Request and recovery (W00–W03, W06):** a requester approved at two sites selects the affected site, submits once despite a lost response/retry, sees the stable reference and can reopen the saved page. Invalid input, expired authentication and an unapproved third site are handled without losing safe draft content or creating unauthorized work.
- [ ] **E02 — Accountable triage (W01, W03):** browser, email and automatic intake reach the same routing policy. Unmatched work enters a visible monitored fallback; manual override, absent/inactive assignee and replacement cover remain traceable.
- [ ] **E03 — SLA truth (W04, W17, W26):** measured, paused, breached, met and unmeasured fixtures reconcile across header/list/detail/report/export. Holidays, NZ daylight-saving, reprioritization and reopening preserve the agreed history. A stopped watchdog visibly becomes stale and recovers.
- [ ] **E04 — Work navigation (W05–W06):** search/filter/sort/saved views, linked meters, pagination, card/table, selection and bulk outcomes work. Canonical links and browser Back preserve context. Long titles, 390px width, keyboard and zoom remain usable; all displayed actions work for the current actor.
- [ ] **E05 — Public and internal conversation (W07, W11–W12):** technician reply, internal note, later requester reply and watcher changes have the correct audience and next responsible party. Upload rejection/quarantine and delivery failure retain work and show truthful status. A copied download URL does not bypass authorization.
- [ ] **E06 — Collision and draft protection (W02, W06–W08):** two open editors cannot silently overwrite a newer field. Failed replies, blocked resolution, Escape and navigation retain recoverable work. Restoring a draft after access revocation does not reveal or submit inaccessible content.
- [ ] **E07 — Required work and approval (W08, W15):** eligible approval unlocks dependent tasks, required evidence is recorded and resolution is blocked until complete. Rejection, expiry, cover, cancellation and reopen work; cycles, ineligible direct decisions and disallowed self-approval are rejected.
- [ ] **E08 — Resolution and canonical history (W09, W21):** resolve, requester confirmation/CSAT, still-needs-help, reopen and eligible auto-close preserve public explanation and audit. Link/merge preview protects audiences and old references. A safe resolution draft can become reviewed knowledge.
- [ ] **E09 — SSO administration (W10):** an authorized save persists, blank secret preserves its value, invalid configuration fails honestly and group removal updates access. Approved real provider sign-in and denial are verified; local save alone does not pass provider verification.
- [ ] **E10 — Microsoft and Gmail intake (W11):** separately for both providers, drain a batch exceeding 25 messages; thread a changed-subject reply; replay duplicates; handle merged/settled references, inactive sender, ambiguous match, attachments, auto-replies and bounces. Token expiry, throttling, permission denial, network failure and mark-read failure recover without duplicate tickets.
- [ ] **E11 — Outbound delivery (W12):** approved test recipients receive the chosen public reply/link mode from the correct support identity and reply to the same ticket. Internal notes never send. Reject/partial failure/bounce, delayed or duplicate callback, and uncertain acknowledgement have accurate states and safe recovery.
- [ ] **E12 — Service identities and ingress operations (W13):** issue, use, rotate and revoke a scoped test identity. Verify read/update/link as well as create, denied site/device/capability, rate limits and idempotent authorized replay. Diagnostics contain useful errors without credentials or unrestricted payloads.
- [ ] **E13 — Automatic technical work (W14, W20):** a supported nonurgent technical episode creates one owned IT record; an urgent operational event remains coordinated in Control Room with linked IT work. Replays, flapping, maintenance suppression, recovery and a new later episode behave correctly. Source/access boundaries hold and updates do not form loops.
- [ ] **E14 — Catalogue and provisioning (W15):** create/review/publish/version a service, request it for an eligible person, approve and fulfill the correct task workflow. Joiner/mover/leaver cases, rejection, cancellation, partial failure and later revision preserve the original request evidence. Manual fulfillment is labelled as manual; automation needs actual adapter evidence.
- [ ] **E15 — Specialized service lifecycles (W16):** an incident links to an investigated problem/known error and verified fix; a change passes risk/approval/scheduling/implementation/verification or failure/rollback; a major incident records command, timed communications, restoration and review. Published outage notices and subscription updates reveal only permitted content.
- [ ] **E16 — Reports and improvement (W17, W19):** a known dataset reconciles scoped drilldowns and CSV for backlog, response/resolution coverage, reopen/CSAT, effort and repeat demand. Empty, unmeasured and stale data are labelled. Recurring-problem candidates require human confirmation and retain their source evidence.
- [ ] **E17 — Templates and automation (W18):** author/review/version, preview and insert an audience-appropriate reply/preset. Invalid placeholders block unsafe execution. Rule dry run explains matches/actions; execution, partial failure, retry, precedence, rate limits and disablement behave as specified.
- [ ] **E18 — Effort, dispatch and continuity (W19):** start/pause/resume a timer, navigate away and correct a manual entry without duplicate duration. Book/reschedule against approved availability, handle overlap/absence/cover and acknowledge handover with next commitment intact. Requesters cannot read internal effort or handover notes.
- [ ] **E19 — Scheduled and proactive work (W20, W23):** due recurrence/reminder/renewal creates one owned result through retries and scheduler catch-up. Timezone boundaries, exceptions, pause, cancellation and retired service prevent ticket storms. Proactive risks have real supported source evidence and clear suppression/history.
- [ ] **E20 — Knowledge and structured documentation (W21–W23):** staff find an approved guide, authors edit a separate draft, reviewers compare/publish/restore a revision and overdue reviews reach owners. Runbooks link canonical systems/assets/suppliers; protected diagram versions, broken links, licence/warranty renewal and old KB URLs work. No draft or restricted relationship leaks through search/export/preview.
- [ ] **E21 — Shared vault (W24):** the same credential ID is accessed from Sites and IT with identical scoped policy and required step-up. Reveal is audited before disclosure; copy intent and browser success/failure are truthful; concealment and session loss work. Unauthorized access is denied, external rotation is distinct from storage-key maintenance, and disposable encrypted fixtures can be restored without secret evidence capture.
- [ ] **E22 — AI foundation only (W25):** the layout/state fixtures demonstrate permitted context, cited-source placement, draft review, cancellation, stale data and revocation. Disabled production capability makes no provider/network/job calls; vault values and restricted content never enter context. No enabled button claims model functionality exists. Typed handoff contracts are ready for the later whole-app programme.
- [ ] **E23 — Recovery and final regression (W26–W27):** recover isolated ticket/document/vault fixtures, replay outstanding authorized events once and reconcile counts/links. Exercise worker/provider outage runbooks, queue freshness and retention. Complete restricted-role browser regression and compare protected design hashes against the implementation session baseline.

### Final release gate

- [ ] W00–W27 are Verified; E01–E23 and every applicable package criterion have dated evidence.
- [ ] All F01–F16 and A01–D08 mappings below are closed, with D05 explicitly closed only as the user-requested AI foundation. No live AI claim is made.
- [ ] Every current and newly introduced visible control has a working authorized outcome. No dead settings Save, inaccessible narrow-screen task, unexplained blocker, fabricated meter, abandoned route or mock production success remains.
- [ ] Required provider, permission, recurrence and recovery verification is complete. A missing account, unavailable scanner/collector, or untested restore remains a specific block; a fake passing adapter does not close it.
- [ ] Migrations/backfills are reconciled, operational ownership is named, failure/retry paths work, runbooks and rollback instructions are reviewable, and required focused checks pass.
- [ ] Rory source files are unchanged by this implementation; shared-component regressions have been checked.
- [ ] A final implementation report states what changed, verified commands/journeys, residual risks and release prerequisites. Local readiness does not imply production deployment has occurred.

If a necessary feature gap is discovered while implementing these journeys, add a bounded work item with dependency, acceptance and evidence to the ledger and complete it. Do not use new ideas to postpone the existing requirements or expand into unrelated product modules.

## 10. Audit-to-plan traceability

The audit remains the source of the original observations. These mappings ensure nothing is lost; close an ID only after all its relevant criteria are verified. Several observations share one underlying service change, so fixes should be reused rather than duplicated.

### All 16 findings

- **F01 — Requester submission:** W00, W02, W06. Preserve the audit's uncertainty about the observed 504 until fresh diagnosis establishes the cause.
- **F02 — Misleading SLA health:** W04, W17, W26.
- **F03 — Email lifecycle bypass:** W02, W07, W11.
- **F04 — Unwired SSO saves:** W10.
- **F05 — Provider errors appear healthy:** W11–W13, W26.
- **F06 — Missing direct automatic routing/ownership:** W03, W14.
- **F07 — Accountable ownership unconfigured:** W03, W26; real owner configuration and implementation are separate evidence.
- **F08 — Unreadable narrow browser lists:** W05–W06, W27.
- **F09 — Draft and transition feedback:** W02, W06–W08.
- **F10 — Awaiting reply versus first response:** W07, W17.
- **F11 — Counts/refresh/list inconsistencies:** W05, W17.
- **F12 — Affected approved-site selection:** W03.
- **F13 — No safe publication revision:** W21–W22.
- **F14 — Overstated vault copy/rotation:** W24.
- **F15 — Concurrent edit overwrites:** W02, W06–W07.
- **F16 — Usable handoffs and outcomes:** W05–W06, W09, W16.

### All 36 backlog items

- **A01** reliable submission → W00, W02, W06; **A02** responsive accessible work → W05–W06, W27.
- **A03** ownership/fallback → W03; **A04** truthful SLA/freshness → W04, W17, W26.
- **A05** whose-turn/next action → W07; **A06** drafts/validation/blockers → W02, W06–W07.
- **A07** approved-site/impact triage → W03; **A08** resolution/confirmation → W09.
- **A09** collision-safe edits → W02, W06–W07; **A10** operational readiness → W13, W26.
- **B01** common intake commands → W02, W07, W11, W14; **B02** Microsoft/Gmail operation → W10–W12.
- **B03** persistent honest SSO → W10; **B04** Control Room/direct IT routing → W14.
- **B05** replies/templates → W18; **B06** tasks/approvals/cover → W08, W15.
- **B07** duplicates/related work → W09; **B08** reconciled reports → W17.
- **B09** effort → W19; **B10** reminders/recurrence → W20.
- **C01** dedicated knowledge → W05, W21; **C02** reviewed revisions → W21.
- **C03** structured runbooks → W22; **C04** protected diagrams/files → W07, W22.
- **C05** commercial/renewal links → W23; **C06** shared vault → W24.
- **C07** documentation quality/owners → W22–W23; **C08** resolution-to-knowledge → W09, W21.
- **D01** capacity/dispatch → W19; **D02** explainable rules → W18, W20.
- **D03** service health/notices → W16; **D04** proactive risk-to-work → W14, W20.
- **D05** assistive AI → W25 **layout/contracts/states only, superseding the audit's live-AI suggestion under the user's explicit instruction**. Actual model functionality belongs to the later whole-app programme.
- **D06** shift handover → W19; **D07** service improvement/problem candidates → W16–W17; **D08** recovery/continuity → W26.

### Existing capability coverage

Retain and finish existing requester/technician intake, queue/routing, SLA/business hours, tasks, approvals, public/internal interactions, attachments, watchers, saved views/bulk actions, resolution/reopen/close/CSAT and merge/link in W01–W09. SSO, provider channels, secure API/service identities and monitoring handoffs are covered in W10–W14. Catalogue/provisioning, Problems/Changes/Major Incidents and reports are covered in W15–W17. Existing KB and Sites credentials are extended canonically by W21–W24. Setup/operations, access and navigation apply across the packages, not just to newly added screens.

At W00, cross-check this coverage against the audit's capability inventory and the current route/action list. If implementation has added another IT capability since the audit, include its lifecycle and UI in the ledger; do not ignore it merely because its filename was absent from this snapshot.

## 11. New-session execution brief and progress record

Copy the following into the new session with this file available:

> Implement `C:\Users\steph\Herd\oblivionfindings\docs\audits\2026-09-08-it-support\implementation-plan.md`. Read the entire plan, its baseline audit, repository instructions and Rory design sources first. This is a single-organisation web application. Do not edit DESIGN.md or design_styles/. Complete the existing IT & Support features and all required enhancements in W00–W27; UI/UX, failures, authorization, recovery and browser verification are part of each feature. AI work is limited to the complete disabled layout/context/action foundation in W25 for the future whole-web-app AI programme. Inspect and preserve the current working tree. Create or resume an evidence ledger, begin the next dependency-ready work, finish reviewable vertical slices and keep progressing through the required milestones. Reuse canonical services/records and the approved UI primitives. Use the Codex in-app browser for real journeys on verified current assets. Do not re-audit everything or ask for routine reversible implementation approval. Where real provider access or an operational choice is required, finish independent work and record the exact remaining dependency. Do not claim feature completeness until the final release gate passes. Do not deploy, send real communications or alter live provider configuration without authorization for that external action.

Suggested ledger path: `docs/audits/2026-09-08-it-support/implementation-progress.md`. Keep each package record compact but concrete:

- Package and audit/scenario IDs; Planned/In progress/Implemented/Verified/Blocked state; current session/date.
- Actual scope delivered, current branch/HEAD and changed files. Record assumptions corrected from the original snapshot.
- Migration/backfill and compatibility impact; relevant role/site/privacy boundary.
- Exact verification commands and outcomes, browser host/assets/role/viewport and evidence references. Separate observed results from source-inspected expectations.
- Remaining defects, provider/business dependencies, necessary release approval and the next executable step.

At a session boundary, leave one explicit resumption note: **last completed package/slice; unfinished edits; next dependency-ready slice; current failures; external blockers; safe fixture identifiers; last verified checkout/assets**. Do not stop at a milestone and relabel the rest optional. A new session should resume this record without repeating completed verification unless code or environment changes invalidate it.

## 12. Planning verification and limits

This handoff was prepared from the dated audit, repository source locations, the single-organisation architecture instructions, protected Rory guides and the current test/tool configuration. It is a plan, not evidence that the application has been fixed. No application code, design rules, database records, provider settings or live AI functionality were changed while writing it. No new application tests, build or browser journey was run for the document itself.

Document verification checks: all 28 work packages have deliverables, dependencies and acceptance; all 16 findings and 36 backlog items have a mapping; the AI boundary is explicit; referenced local links resolve; continuation placeholders are removed; and the protected guide hashes match the planning baseline. The implementation session must establish its own current baseline and produce the application evidence described above.
