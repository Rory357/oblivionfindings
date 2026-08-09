# IT & Provisioning → Full IT Ticketing System — LOOP PROMPT (anchored on `/it`)

> One prompt for the whole job, **pasted to Claude Code on every loop iteration**. Unlike the design-only `*_REDESIGN_PROMPT.md`s, this one is **full-stack**: Claude Code owns migrations, models, services, routes, policies, notifications, commands, UI and tests — there is **no backend handover doc**. Work the loop protocol in §0 exactly: small verifiable passes, one checklist item per pass, verify, tick, commit, stop.
> **Schema rule:** the only schema you may create is what §P pre-approves. Anything beyond §P → write it up in **`IT_TICKETING_QUESTIONS.md`** (repo root) and move on. **No silent schema.**

**Page (canonical):** `/it` (route `it.index`, Inertia page `it/index`) · NEW `/it/tickets/{ticket}` (detail)
**Frontend files:** `resources/js/pages/it/index.tsx` (~624 lines), `resources/js/components/it/it-wizards.tsx` (~616 lines — `CreateTicketWizard`, `FulfilRequestDialog`, `AssignDialog`, `ItWizard` dispatcher); sidebar entry `resources/js/components/app-sidebar.tsx` ≈L571–580 (id `it-provisioning`, icon `Server`, gate `can?.it?.view`)
**Backend:** `app/Http/Controllers/It/ItProvisioningController.php` (index/assign/fulfil/cancel/storeTicket/updateTicket/resolveTicket + private `requestRows`/`ticketRows`/`stats`/`tenantUserOptions`); routes `routes/web.php` ≈L142–155 (`it.index`, `it.provisioning.assign|fulfil|cancel`, `it.tickets.store|update|resolve`)
**Models:** `app/Models/ItTicket.php` (CATEGORIES `hardware|account|network|other`, PRIORITIES `low|normal|high|urgent`, STATUSES `open|in_progress|resolved|closed`), `app/Models/ItProvisioningRequest.php` (TYPES `account|access|equipment|other`, STATUSES `pending|in_progress|done|cancelled`, `inferTypeFromTitle()`); migrations `2026_07_02_100001_create_it_provisioning_tables.php`, `2026_07_02_100002_grant_it_permissions.php`
**Permissions today:** `it.view` + `it.manage` (RbacSeeder ≈L417–418) → granted to **admin, provider_manager, hr only**
**Cross-loop (do not break):** `app/Domain/Hr/Services/OnboardingService.php` — `createItProvisioningRequests()` ≈L524–568 auto-raises requests from IT-category onboarding tasks (equipment excluded → asset path); `fulfil` auto-completes the linked task via `OnboardingService::completeTask()` in a DB transaction; `cancel` fires `app/Domain/Hr/Notifications/ItProvisioningCancelledNotification.php` (type `it_provisioning_cancelled`) to the checklist creator
**Assets register (canonical):** `app/Models/Asset.php` + `AssetController` family (fleet-assets). **Never create a parallel IT asset register** — link to this one.
**Tests:** `tests/Feature/It/ItProvisioningTest.php` (Pest — 4 tests: gate, checklist bridge, fulfil→task-complete, ticket lifecycle). These stay green forever.
**Wireframe history:** `docs/IT_PROVISIONING_WIREFRAME.md` (built 100%; §5 external integrations deferred — keep deferred).
**Gold-standard references to clone (exact paths, verified):**
- Modal kit: `resources/js/components/wizard/shell.tsx` + `resources/js/components/wizard/primitives.tsx`, HR entry `resources/js/components/hr/wizard.ts` (`useWizard` + whole kit); pattern markers in `resources/js/components/clients/add-client-dialog.tsx` (STEPS array, `validateStep()`, `stepForError()`, `WizardSuccessPane`, Save & add another, `forceFormData`); premium reference `resources/js/components/hr/leave-request-dialog.tsx` (`railExtra`, live preview, `WizardStepPane`, toast + confetti)
- Golden hero band: `resources/js/components/hr/people-hero.tsx` (`HERO_STYLE` shape — stat-as-link chips, quick actions, "needs you" chips, right-rail donut⇄ring with `localStorage`); slot hero `resources/js/components/page/page-hero.tsx`. **Never import `my-hr-hero.tsx`/`MyHrClockCard`.**
- Right-click: `resources/js/components/hr/leave-context-menu.tsx` (`useLeaveContextMenu`, typed items, tones, dividers, kbd) — already imported by `it/index.tsx`
- Tabs: `resources/js/components/hr/hr-tabs.tsx` (`HrTabs` + `useHrTab` `?tab=`, `onItemContextMenu`, `decorations`) — already used by `/it`
- Status: `resources/js/components/ui/status-badge.tsx` · People picker: `resources/js/components/hr/people-picker.tsx` · Tokens: `resources/css/app.css` (`--status-*`, `--shadow-hero`; **no raw hex — ESLint blocks it**)
- Charts: `recharts` (already a dependency) · Toasts: sonner (`<Toaster>` mounted in `resources/js/app.tsx`)

---

## 0. LOOP PROTOCOL (read first, every pass)

**Verify commands (confirmed in `package.json` / repo):** `php artisan test` (Pest) · `npm run build` · `npm run types` · `npm run lint` · `npm run test` (vitest) · `npm run visual:test` (Playwright, `@axe-core/playwright` available).

1. **Pass 0 (only if `docs/IT_TICKETING_GAP_ANALYSIS.md` does not exist):** run the §A audit, then seed `docs/IT_TICKETING_GAP_ANALYSIS.md` with the **Build order** checklist from the end of this prompt (one `- [ ]` per numbered item, in order, plus a "Verify commands" header and a "Decisions/questions" section). Commit `it: seed ticketing gap analysis`. **Stop.**
2. **Every other pass:** open `docs/IT_TICKETING_GAP_ANALYSIS.md`, take the **first unchecked item**, and implement it as one small, verifiable slice. If an item is too big for one pass, split it into sub-checkboxes in the doc first, then do the first sub-item.
3. **Verify before ticking:** backend touched → `php artisan test` (all of `tests/Feature/It` plus any touched suite) with **new/updated Pest tests written in the same pass**; frontend touched → `npm run build` + `npm run types` + `npm run lint`, and screenshot the touched surface (Playwright) — diff it against the gold-standard pages (`/hr/people`, `/hr/leave`, `/meds/today`). Never tick a red item.
4. **Tick + commit:** mark the item `- [x]` with a one-line note (what changed, files), commit `it: <slice>`. One item per commit. **Stop after the commit** — the loop restarts you.
5. **Blocked?** Write the question + your recommendation to `IT_TICKETING_QUESTIONS.md`, mark the item `⛔ blocked (see questions)` in the gap doc, and move to the next item. Never invent schema, permissions, or product decisions beyond this prompt.
6. **Red-tree rule:** if the suite is already failing when your pass starts, fixing that **is** your pass.
7. **Never break:** the 4 existing Pest tests, the onboarding→provisioning bridge, `routes/web.php` route names already referenced by the UI, or tenancy scoping (`forTenant`).

## 1. Mission

`/it` today is one agent-only page: two filtered tables (provisioning + tickets), five hand-rolled hero chips, three modals, seven routes. Solid bones — the onboarding bridge is genuinely good — but it is a **queue, not a helpdesk**. There are no ticket details, no conversation, no history, no SLAs, no self-service, no knowledge, no reporting. Worst of all: **`POST /it/tickets` is gated `it.manage`, so the people who actually break things — support workers mid-shift — cannot raise a ticket at all.** A worker whose phone dies at 6am in someone's home currently has to find a manager and ask them to type it in.

Rebuild it into a **full IT ticketing system in the mould of Freshservice / Jira Service Management**, fitted to a NZ supported-living provider:

- **Everyone on staff** can raise a ticket in under 30 seconds and track it in **My tickets** — then get back to the person they're supporting.
- **Agents** get a triage cockpit: queues with saved views, a real **ticket workspace** (thread with public replies + internal notes, activity timeline, properties rail), **SLA timers with at-risk/breach escalation**, bulk actions, watchers, linked assets, CSAT, a **knowledge base** that deflects repeat questions, and a **Reports** tab.
- **Provisioning keeps its onboarding loop** untouched and gains priority, due dates and ticket-linking.

Same quality bar as the gold standards: golden hero band, `HrTabs` hub, wizard-kit modals, `StatusBadge`, right-click everywhere, toasts, skeletons, empty states that teach.

## 2. Non-negotiables

1. **Self-service is the point.** New permission `it.request` ("Raise and track your own IT tickets") granted to **every role** (incl. `support_worker`) via a grant migration mirroring `2026_07_02_100002` + `RbacSeeder`. `it.view`/`it.manage` stay agent-side. Requesters see **only their own tickets** — enforce with an `ItTicketPolicy`, not UI hiding.
2. **Full-stack in this loop.** You build the backend yourself — FormRequest per mutation (none exist in this module today), tenant scoping via `forTenant` on every query, audit via the existing `AuditableChanges` trait + `it_ticket_events`, Pest feature tests in the same pass. Schema only from §P.
3. **One hub, one detail.** `/it` stays the single `?tab=` surface (`HrTabs` + `useHrTab`); the only new page is `/it/tickets/{ticket}`. No route sprawl.
4. **Reuse the kit — never hand-roll.** Wizards from `@/components/hr/wizard`; heroes from the golden-band pattern; colours only via tokens/`StatusBadge` (delete the hand-rolled `requestStatusVariant`/`ticketStatusVariant`/`priorityVariant` maps in `it/index.tsx` only if they wrap `StatusBadge` incorrectly — semantic variant maps that *feed* `StatusBadge` are fine).
5. **Web-only desktop app.** Mouse + keyboard: right-click, hover actions, keyboard nav. No phone frames, no clock in the hero.
6. **Information-gathering = modals; working = the detail page.** No inline forms, no full-page create routes.
7. **Don't fork the bridge.** `OnboardingService::createItProvisioningRequests()` and the fulfil→`completeTask()` transaction are the source of truth. Extend, never duplicate.
8. **Frontline privacy.** Tickets will mention the people we support ("Hoist controller at Aroha House…"). Internal notes are agent-only **in the server payload**, not just visually. Notification emails carry the ticket reference + title only — never body text or client details.
9. **NZ locale.** `en-NZ`, `Pacific/Auckland`, NZD. Plain-language priority labels for requesters (§E1).
10. **Destructive actions confirm via `alert-dialog`;** every action toasts; no native `confirm()`/`alert()`.
11. **No dead UI.** Every button/badge/filter/menu item hits a real route or doesn't ship.
12. **Accessibility is a floor:** WCAG AA, colour always paired with text/icon, focus-visible, `motion-reduce:*` guards; run the axe Playwright checks on new surfaces.

## A. Audit pass (pass 0 — confirm, then seed the gap doc)

Open `/it` next to `/hr/people` and `/hr/leave`; read the controller, models, migration, wizards, tests. Paste findings into `docs/IT_TICKETING_GAP_ANALYSIS.md`. **Known findings to confirm (from the pre-audit):**

- [ ] `it.tickets.store` sits inside the `permission:it.manage` group, and the **outer group gates even `GET /it` on `permission:it.view`** (`routes/web.php` L144–154) → staff can't raise tickets and can't even open the page. The single worst gap.
- [ ] **No ticket detail surface at all** — no show route, no thread, no history; every action lives in a row context menu. `resolveTicket` takes no resolution note; nothing records *what fixed it*.
- [ ] **No SLA anything:** no due/response columns, no breach state, no escalation, no scheduler.
- [ ] `stats()` (controller L353–376) is correct as far as it goes (pending/in-progress all-time, `done_30d` windowed — verified) but far too thin for the new hero/Overview: no unassigned, no SLA states, no awaiting-reply, no per-view counts. Build a real summary payload.
- [ ] Queues are **unpaginated** (`get()->map` full-table arrays) — fine at 20 tickets, dead at 2,000. Server-side pagination + a summary endpoint for hero counts.
- [ ] Hero is a hand-rolled `primary/90→80` gradient with static chips (not links), not the golden band kit.
- [ ] Tickets: flat 4 categories, no subcategory, no attachments, no watchers, no reopen, no auto-close, no CSAT, no merge, no saved views, no bulk, no reports, no KB, no email-in.
- [ ] Provisioning: no priority, no due date; `notes` is a single final-outcome field.
- [ ] Notifications: only `ItProvisioningCancelledNotification` exists — requesters hear nothing on create/assign/resolve.
- [ ] Tests: 4 happy-path tests; no policy/authz matrix, no SLA, no comment coverage.
- [ ] Check whether a generic attachment/document infra exists that fits tickets (HR has `HrDocument`, H&S/fleet may have their own). Record the answer in the gap doc **before** building §P.4 — reuse if clean, else build `it_attachments`.

## B. Permission model & self-service (headline #1)

- New `it.request` permission (group `it`, module `Operations`) + grant migration to **all roles**; seed in `RbacSeeder` alongside `it.view`/`it.manage` (≈L417–418, role grants ≈L627–628, 768).
- Sidebar gate becomes `can?.it?.request || can?.it?.view` (`app-sidebar.tsx` ≈L571–580; keep id `it-provisioning`, label "IT & Provisioning").
- `/it` becomes permission-aware: requesters (only `it.request`) land on **My tickets** as their default tab and never see agent tabs/props; agents get the full hub. Enforce in the controller (props per capability), not just tab hiding.
- **`ItTicketPolicy`:** `view` = agent (`it.view`) or own ticket (`requester_user_id`); `comment` = same, but `is_internal` requires `it.manage`; `reopen` = requester within 7 days of `resolved_at` or any agent; mutations = `it.manage`. Route-model-bind + `authorize` everywhere; internal notes filtered out of requester payloads server-side.
- **My tickets tab:** card/list of own tickets (reference, title, status timeline dots, "awaiting your reply" flag, last update), big **Raise a ticket** CTA, KB search box ("Before you raise…"), CSAT prompt slot for freshly resolved tickets, empty state that teaches ("Broken phone? Locked out? Raise it here — IT sees it instantly").
- **Raise-a-ticket modal (requester, 30-second path):** single step + review-free submit — title*, category `TilePicker` (plain words: "Device or hardware" / "Account or sign-in" / "Wi-Fi or network" / "Something else"), urgency `Segmented` in plain language mapped to priority: *"Stops me supporting someone right now"* → `urgent` · *"Blocking my work"* → `high` · *"Annoying but I can work"* → `normal` · *"Whenever"* → `low`; optional description + attachment behind a "More details" disclosure. Requester, site, role auto-filled from the profile — never ask what the system knows. Success pane: reference + "We'll email you" + KB suggestions matching the title.

## C. Hero — golden band, IT lens

Clone the `people-hero.tsx` band **shape** (stat-as-link chips, quick actions, needs-you chips, right-rail toggle) with the IT/Operations accent — do not import HR's amber or the clock. Driven by a **server summary** (all rows, not the page):

- **Agent chips:** Open tickets · Unassigned · **Breaching soon** · **Breached** · Awaiting reply · Pending provisioning · Fulfilled/Resolved 30d — each deep-links to the filtered queue (`?tab=tickets&view=breaching`).
- **Needs-you chips:** *N unassigned urgent*, *N SLA at risk*, *N waiting on you*, *N provisioning pending >7d*.
- **Quick actions:** Raise ticket (everyone) · Log & triage (agents) · New KB article · Reports.
- **Right rail:** donut (tickets by status) ⇄ ring (30-day SLA compliance %), persisted `localStorage` `it.heroRight`.
- **Requester view** gets the compact variant: My open · Awaiting my reply · Resolved 30d + Raise CTA.

## D. Tab shell

`HrTabs` + `useHrTab('overview')`, one URL, deep-linkable, badges from the server summary:

1. **Overview** (agents, default for agents, §F1) — command dashboard.
2. **Tickets** (agents, §F) — the queue. Badge = open count.
3. **My tickets** (everyone with `it.request`; default for requesters, §B). Badge = awaiting-your-reply.
4. **Provisioning** (agents, §H) — existing queue, uplifted. Badge = pending + in_progress.
5. **Knowledge** (§I) — agents manage; requesters browse/search.
6. **Reports** (agents, §M).

Per tab: real empty state + skeleton, right-click on rows and on the tab strip (default-tab persisted `it.defaultTab` via `decorations`), toasts on every action.

## E. Ticket workspace — `/it/tickets/{ticket}` + quick-peek drawer (headline #2)

Build **both** a detail page and a right-hand drawer (opened from any queue/My-tickets row) sharing one `TicketThread` component. This is where tickets get worked; today it doesn't exist.

**Header (compact golden band):** reference (`IT-000123`, copy-on-click) · title (inline-editable for agents) · `StatusBadge` · priority badge · **SLA chip** (live "response due in 2h 14m" / "resolution due …" countdown; `--status-warning` when at-risk, `--status-critical` when breached, hidden when met — always with text, never colour-only) · requester card (avatar, name, role, site).

**Left — conversation:** chronological thread of public replies and **internal notes** (distinct tinted background + "Internal" chip; **stripped from requester payloads server-side**), attachments inline, system events collapsed between messages ("Chane assigned Ari · 2h"). Composer at bottom: `Segmented` **Reply ⇄ Internal note** (requesters get reply only), attachment drop, `Ctrl+Enter` send. First **public agent reply** stamps `first_responded_at` (§G). A "Suggest from Knowledge" affordance searches KB as the agent types and can insert a link.

**Right — properties rail:** status (guarded transitions), priority (change re-computes SLA due dates from the policy, §G), category + subcategory, assignee (`PeoplePicker`, "Assign to me" shortcut), watchers (avatar stack + watch/unwatch), **linked asset** (picker over `app/Models/Asset.php`, shows the asset's other open tickets), linked provisioning request (when converted/raised from one), requester profile, CSAT result (after submission), created/updated/closed timestamps (`en-NZ`, relative + absolute).

**Timeline lane** (toggle within the workspace): every `it_ticket_events` row — created, assigned, status/priority changes, SLA at-risk/breach, watcher added, reopened, merged — actor + timestamp.

**Actions (header + right-click):** Assign to me · Assign… · **Resolve** (modal: resolution note* saved as final public reply, "notify requester" toggle on by default, "draft KB article from this" shortcut) · Close · **Reopen** (agents anytime; requesters within 7 days) · Watch/Unwatch · Copy link/reference · Delete (admin-only, `alert-dialog`).

**Drawer:** same `TicketThread` + condensed rail for fast triage without leaving the queue; "Open full page" in the drawer header.

## F. Tickets queue (agents) + Overview

**F1. Overview tab** — mirror the `/hr/leave` dashboard shape: KPI row (Open · Unassigned · Breaching soon · Breached · Avg first response 30d · CSAT 30d); **"Needs attention" lanes:** *SLA at risk/breached* (ticket, assignee, time left → Open/Assign), *Unassigned by priority*, *Awaiting agent reply*, *Aging >7d*; recent activity feed from `it_ticket_events`; every item deep-links into the filtered queue.

**F2. Queue tab.** Replace the hand-rolled table with the `/hr/people`-standard table:

- **Saved views** as chips above the toolbar: **All open · Unassigned · Mine · Breaching soon · Awaiting reply · Waiting on requester · Recently resolved** (server-side `view` param; counts on each chip; default view persisted `it.ticketsView`).
- **Toolbar:** debounced search (reference, title, requester), filters: status, priority, category, assignee, SLA state, date range.
- **Columns:** Reference · Ticket (title + requester subline) · Assignee · Priority · **SLA** (due-in/breached chip) · Status · Updated · Age · `⋯`. Sortable; server-side pagination (`LaravelPagination`).
- **Bulk select** → assign, set priority, set status, close — one confirm, one toast, N `it_ticket_events`.
- Row click → drawer; double-click/⌘-click → detail page. Right-click per §O. Skeleton + taught empty state.

## G. SLA engine

- **`it_sla_policies`** (§P.6): per tenant per priority — `first_response_minutes`, `resolution_minutes`. Seed defaults: urgent 60/240 · high 240/1440 · normal 1440/4320 · low 4320/10080. Tiny admin editor (§N7).
- **Stamping:** on create (and on priority change), compute `first_response_due_at` + `resolution_due_at` from the policy. `first_responded_at` = first public agent reply. Status `waiting` (on requester) **pauses** the resolution clock: accumulate paused time in `sla_paused_minutes`, resume on requester reply (auto-flip back to `in_progress`).
- **`sla_state`** on the ticket: `ok → at_risk → breached → met` (met when responded/resolved inside target). `at_risk` = ≤25% of the window remaining.
- **Scheduler:** `php artisan it:check-sla` (register hourly in `routes/console.php` — confirm where existing commands schedule; e.g. the onboarding email command): transitions `sla_state`, notifies assignee on at-risk, assignee + all `it.manage` users on breach, escalates **unassigned urgent >30m** to admins. Idempotent — one notification per transition (guard with `it_ticket_events`).
- **v1 clocks run 24/7.** Business-hours calendars = stretch; note it in `IT_TICKETING_QUESTIONS.md` for Chane.
- **Statuses:** add `waiting` to `ItTicket::STATUSES` (display "Waiting on requester") → `open | in_progress | waiting | resolved | closed`. **Auto-close** resolved tickets after 7 days (`it:close-resolved`, daily) with a closing event; requester reopen inside that window flips to `open` and bumps `reopened_count`.

## H. Provisioning tab uplift (bridge stays sacred)

Keep every existing behaviour (auto-raise from onboarding, fulfil→task-complete transaction, cancel notification, equipment exclusion). Add:

- `priority` + `due_date` (§P.8) — set at creation (manual) or inferred `normal`/start-date for onboarding-raised rows; overdue tint + hero chip.
- **Convert/link:** "Raise linked ticket" on a request (e.g. the new laptop arrived broken) → ticket pre-filled, `provisioning_request_id` set; the request shows its linked ticket chip and vice-versa.
- **Manual "New provisioning request" wizard** (§N5b) — today rows only come from onboarding; agents need ad-hoc ones (employee `PeoplePicker`, type tiles, item, assignee, due, notes).
- Bulk assign/fulfil; CSV export; activity events on assign/fulfil/cancel into the shared events table (polymorphic per §P.3); pagination + StatusBadge parity with the tickets queue.

## I. Knowledge base (lite but real)

- **`it_kb_articles`** (§P.7): title, slug, category (reuse ticket categories), markdown body, status `draft|published`, author, `view_count`, `helpful_yes`/`helpful_no`.
- **Knowledge tab:** agents get card/table (Title · Category · Status · Views · Helpful% · Updated · `⋯`) + **KB article modal** (§N6). Requesters get search + category browse of **published** articles, article view with "Was this helpful?" (one vote per user per article — localStorage guard is fine v1).
- **Deflection:** Raise-a-ticket modal searches published titles as the requester types ("These might fix it now"); ticket workspace composer suggests articles to agents; Resolve modal offers "Draft KB article from this resolution" (pre-fills the modal).

## J. Notifications (database + mail, mirroring `ItProvisioningCancelledNotification`)

`TicketCreatedNotification` (requester receipt with reference; + alert to `it.manage` users for `urgent`) · `TicketAssignedNotification` (assignee) · `TicketRepliedNotification` (requester on public agent reply; assignee + watchers on requester reply) · `TicketResolvedNotification` (requester, with CSAT link) · `TicketSlaNotification` (at-risk/breach per §G) · `TicketReopenedNotification` (assignee). Rules: never notify the actor about their own action; watchers get replied/resolved/reopened; **subjects = reference + title only** (privacy, §2.8); every mail's action button deep-links (`/it/tickets/{id}` or My tickets).

## K. CSAT

On resolve: requester gets the notification link + a prompt card on My tickets — 1–5 stars + optional comment, one-shot (`csat_submitted_at` guards), editable until closed. Stored on the ticket (§P.1); shown in the workspace rail and aggregated in Reports. Keep it two clicks.

## L. Reports tab (agents)

Server-computed aggregates (one `reports` endpoint/prop — never ship full tables to the client): created vs resolved trend (30/90d, recharts area) · open by priority + by category (donuts) · **SLA compliance %** trend + current · avg first-response & resolution times · CSAT average + response rate · volume by site/role (top requesters) · agent workload (open per assignee) · provisioning throughput (raised vs fulfilled, avg days). Date-range picker · CSV export per card. Empty states for young tenants.

## M. (reserved — merged into §L to keep section letters aligned with the gap doc; do not renumber)

## N. Wizards & modals — exact Add-Client pattern (full, not thin)

All on `WizardShell`/`useWizard` + `validateStep` + `stepForError` + `WizardSuccessPane` + toast; destructive via `alert-dialog`; uploads `forceFormData`.

1. **Raise a ticket (requester)** — §B single-step quick path. The one deliberate exception to multi-step: speed *is* the spec here.
2. **Log & triage (agent)** — rebuild `CreateTicketWizard`: **Details** (title, description, category+subcategory tiles, requester `PeoplePicker` — agents log on behalf of others, attachment) → **Triage** (priority with **live SLA preview** "resolution due Thu 14:00", assignee, asset link, watchers) → **Review**. Success: open ticket / log another.
3. **Resolve modal** — resolution note* (posts as final public reply), notify toggle, "draft KB from this".
4. **Assign dialog** — keep, extend for bulk (N selected shown in `railExtra`).
5. **Fulfil / Cancel provisioning** — keep, polish copy; **5b. New provisioning request** wizard per §H.
6. **KB article modal** — **Basics** (title, category, status) → **Content** (markdown editor + live preview pane) → **Review**. Save & add another.
7. **SLA policy editor** — small admin modal off Reports/Overview (per-priority minutes grid, seeded defaults shown), `it.manage` + admin only.

## O. Right-click everywhere (`useLeaveContextMenu`, already imported)

- **Ticket row (agent):** Open · Quick peek · Assign to me · Assign… · Priority ▸ · Resolve · Close · Watch · Copy reference · Copy link.
- **My-tickets row:** Open · Reply · Reopen (≤7d) · Copy reference.
- **Provisioning row:** Fulfil · Assign · Raise linked ticket · Cancel (critical) · Copy link.
- **KB row:** Edit · Publish/Unpublish · Duplicate · Preview · Delete (critical).
- **Tab strip:** Set as default view (persist `it.defaultTab`, star via `decorations`) · Open.

No dead items; everything toasts and hits a real route.

## P. Schema — PRE-APPROVED (build exactly this; anything more → questions file)

1. **`it_tickets` — add columns:** `reference` string unique-per-tenant (`IT-` + zero-padded per-tenant sequence; backfill existing rows in the migration; generate race-safe — unique index + retry), `subcategory` nullable string, `source` string default `portal` (`portal|agent|system`), `asset_id` FK→`assets` nullable nullOnDelete, `provisioning_request_id` FK→`it_provisioning_requests` nullable nullOnDelete, `first_response_due_at`/`resolution_due_at`/`first_responded_at` nullable datetimes, `sla_state` string default `ok`, `sla_paused_minutes` unsigned int default 0, `waiting_since` nullable datetime, `closed_at` nullable datetime, `reopened_count` unsigned tinyint default 0, `csat_score` unsigned tinyint nullable, `csat_comment` text nullable, `csat_submitted_at` nullable datetime. Index `(tenant_id, sla_state)`, `(tenant_id, assigned_to_user_id, status)`.
2. **`it_ticket_comments`:** tenant_id, ticket_id FK cascadeOnDelete, author_user_id FK, body text, `is_internal` bool default false, timestamps. Index `(ticket_id, created_at)`.
3. **`it_ticket_events`:** tenant_id, `subject_type`/`subject_id` morphs (tickets **and** provisioning requests share it), actor_user_id nullable FK, `type` string (`created|assigned|status_changed|priority_changed|sla_at_risk|sla_breached|watcher_added|reopened|fulfilled|cancelled|…`), `payload` json nullable, created_at. Index `(subject_type, subject_id, created_at)`.
4. **Attachments:** first check the audit finding (§A last item). If no clean generic infra: **`it_attachments`** — tenant_id, `attachable` morphs (ticket|comment|kb_article), path, original_name, mime, size, uploaded_by FK, timestamps. Private disk, download via signed/authorized route only.
5. **`it_ticket_watchers`:** ticket_id FK cascadeOnDelete, user_id FK cascadeOnDelete, timestamps, unique `(ticket_id, user_id)`.
6. **`it_sla_policies`:** tenant_id, priority string, first_response_minutes, resolution_minutes, timestamps, unique `(tenant_id, priority)`. Seeder with §G defaults.
7. **`it_kb_articles`:** tenant_id, title, slug (unique per tenant), category string, body longtext, status string default `draft`, author_user_id FK, view_count/helpful_yes/helpful_no unsigned ints default 0, timestamps.
8. **`it_provisioning_requests` — add:** `priority` string default `normal`, `due_date` nullable date.
9. **Permissions migration:** create `it.request`, grant to **all** roles (mirror `2026_07_02_100002` idempotent pattern) + `RbacSeeder` update.
10. **`ItTicket::STATUSES`** → `['open','in_progress','waiting','resolved','closed']`; no data migration needed (new status only).

## Q. Routes (all inside the existing `/it` group; names follow the current convention)

**Requester-reachable (gate `permission:it.request`, ownership via `ItTicketPolicy`):** re-gate the outer group so `GET /it` (`it.index`) accepts `it.request` **or** `it.view` (props per capability, §B) · `POST /it/tickets` → **move out of the manage group** (`it.tickets.store`; FormRequest maps requester quick-fields) · `GET /it/tickets/{ticket}` `it.tickets.show` (agents full, requester own + internal notes stripped) · `POST /it/tickets/{ticket}/comments` `it.tickets.comments.store` (`is_internal` requires `it.manage`) · `POST /it/tickets/{ticket}/reopen` `it.tickets.reopen` · `POST /it/tickets/{ticket}/csat` `it.tickets.csat` · `GET /it/attachments/{attachment}` `it.attachments.download` (authorized) · `POST /it/kb/{article}/helpful` `it.kb.helpful`.
**Agent (`permission:it.manage`):** `PATCH /it/tickets/{ticket}` (extend: status incl. `waiting`, subcategory, asset_id, watchers?) · `POST /it/tickets/{ticket}/resolve` (extend: resolution note) · `POST /it/tickets/{ticket}/close` · `POST /it/tickets/{ticket}/watch` + `/unwatch` · `POST /it/tickets/bulk` `it.tickets.bulk` (ids + action) · `POST /it/attachments` (scoped store) · `POST /it/provisioning` `it.provisioning.store` (manual, §H) · provisioning `PATCH` for priority/due · KB CRUD `it.kb.store|update|destroy` (+ publish toggle in update) · `PUT /it/sla-policies` `it.sla.update` · `GET /it/reports/data` `it.reports.data` (or Inertia partial props — match how `/hr/leave` serves its dashboard).
Everything else already exists — keep names stable.

## R. Tests (Pest, `tests/Feature/It/…` — write with each slice, not at the end)

- **Authz matrix:** support_worker can raise + see own only; cannot see others' tickets, agent tabs, or internal notes (assert absent from the **payload**); `it.view` reads, `it.manage` mutates; policy covers show/comment/reopen.
- **Lifecycle:** create stamps reference (unique under parallel creates), SLA due dates from policy; priority change restamps; first public agent reply sets `first_responded_at`; `waiting` pauses/resumes the clock; resolve requires note + fires notification; auto-close after 7d; reopen inside window bumps `reopened_count`, outside 403.
- **SLA command:** at-risk then breach transitions each notify exactly once; unassigned-urgent escalation.
- **Comments/attachments:** internal flag gated; attachment download authorized (requester-own / agent, cross-tenant 403).
- **Watchers/notifications:** watcher notified on reply/resolve; actor never self-notified.
- **CSAT:** one-shot, requester-only.
- **Provisioning:** the 4 existing tests stay green; priority/due set; manual store; linked-ticket round trip.
- **KB:** drafts hidden from requesters; helpful counter.
- **Reports/summary:** summary payload counts (unassigned, SLA states, per-view) match seeded fixtures; requester summary never leaks other users' counts.

## S. Premium polish & delight

`WizardStepPane` transitions; confetti on a 5-star CSAT and on clearing the breach queue; skeletons on every list; optimistic toasts; keyboard nav (`Esc`, `Enter`, arrow-rove menus, `Ctrl+Enter` send); hover-lift cards; relative + absolute `en-NZ` dates; live SLA countdowns (minute tick, no re-fetch); plain-language everything on the requester side; "X of Y within SLA this month" microcopy on Reports; empty states that teach the next action.

## Scope calls (from Chane's brief — don't re-litigate)

- **Full ticketing parity** with the popular helpdesks is the target: self-service portal, threads with internal notes, SLA + escalation, watchers, attachments, bulk, saved views, KB, CSAT, reports. All in scope for this loop.
- **Full-stack in-loop:** Claude Code implements backend + frontend itself. No `*_BACKEND_HANDOVER.md`.
- **Provisioning ↔ onboarding bridge preserved** exactly; provisioning gains priority/due/link/manual-create only.
- **Stretch (question-file first, do NOT start unprompted):** email-to-ticket ingestion (needs a mailbox/driver decision), business-hours SLA calendars, ticket merge, approval workflows, external fulfilment integrations (wireframe §5 stays deferred).

## Definition of done

- Any staff member can raise a ticket in one step and track it in **My tickets**; agents cannot-see/requesters-cannot-see boundaries are policy-enforced and tested.
- `/it` is a six-tab hub (Overview · Tickets · My tickets · Provisioning · Knowledge · Reports) with the golden-band hero fed by an all-time server summary; `/it/tickets/{ticket}` + drawer carry thread, internal notes, timeline, properties rail, SLA chip.
- SLA policies stamp every ticket; `it:check-sla` escalates at-risk/breach; `waiting` pauses the clock; resolved auto-closes after 7d; reopen works both sides.
- Provisioning keeps its bridge (existing tests green) and gains priority/due/manual-create/linked tickets.
- KB deflects at raise-time and drafts from resolutions; CSAT lands on resolve; Reports aggregates server-side.
- Every mutation: FormRequest + policy + tenant scope + event row + notification (where §J says) + Pest coverage. No dead UI, no raw hex, no `confirm()`, WCAG AA + axe on new surfaces.
- `docs/IT_TICKETING_GAP_ANALYSIS.md` fully ticked; `IT_TICKETING_QUESTIONS.md` holds every deferred decision; clean `php artisan test`, `npm run build`, `npm run types`, `npm run lint`.

## Build order (⇒ the master checklist for `docs/IT_TICKETING_GAP_ANALYSIS.md`, one checkbox each)

1. §A audit pass → seed the gap doc (pass 0).
2. §P.9 + §B permissions: `it.request` migration/seeder, sidebar gate, route regrouping (`it.tickets.store` out of manage), `ItTicketPolicy` + authz tests.
3. §P.1–3, 5, 8, 10 migrations + model/relationship/constant updates (+ factories) — schema lands early, in one pass, tested.
4. Reference generation + backfill; controller pagination + the new server summary payload + saved-view params.
5. §B My tickets tab + Raise-a-ticket quick modal + created-receipt notification.
6. §E ticket workspace: show route/policy payloads → `TicketThread` (comments API, internal notes) → properties rail (assign/status/priority/category/asset/watchers) → timeline → drawer.
7. §N3 Resolve modal + close/reopen routes + auto-close command + `TicketResolved`/`TicketReopened` notifications.
8. §G SLA: policies table/seeder/editor → stamping + waiting-pause → `it:check-sla` + notifications + queue/hero chips.
9. §F2 queue rebuild: standard table, toolbar, saved views, bulk, pagination.
10. §C hero (golden band + summary chips + right-rail toggle).
11. §F1 Overview tab.
12. §H provisioning uplift (priority/due, manual wizard, linked tickets, bulk, events).
13. §N2 agent Log & triage wizard rebuild (SLA preview, on-behalf-of, asset link).
14. §I Knowledge tab + KB modal + deflection + helpful votes.
15. §K CSAT (prompt, store, rail display).
16. §L Reports tab (server aggregates + recharts + export).
17. §O right-click everywhere + default-tab persistence.
18. §S delight + axe pass + screenshot diff of every tab/modal/workspace vs gold standards; final DoD sweep.

Work top to bottom. One item (or split sub-item) per pass. Verify, tick, commit, stop.
