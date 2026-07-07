# IT & Provisioning → Full IT Ticketing System — Gap Analysis & Build Checklist

> Seeded by pass 0 of the loop (2026-07-07). Loop protocol: take the **first unchecked item**,
> implement it as one small verifiable slice, verify, tick with a one-line note, commit, stop.
> Schema only from §P of the loop prompt; anything beyond → `IT_TICKETING_QUESTIONS.md` (repo root).

## Verify commands

- `php artisan test` (Pest — scoped: `php artisan test tests/Feature/It`) — ⚠️ junctioned-vendor worktree: PHP tests autoload the PARENT's app/; verify in parent or copy vendor + dump-autoload first.
- `npm run build` · `npm run types` · `npm run lint`
- `npm run test` (vitest) · `npm run visual:test` (Playwright, axe available)

## Audit findings (pass 0 — all confirmed against the code)

1. **Self-service is impossible.** `routes/web.php:144–154`: the outer group is `permission:it.view` (gates even `GET /it`) and `it.tickets.store` sits inside the nested `permission:it.manage` group. `it.view`/`it.manage` are granted to admin/provider_manager/hr only (RbacSeeder L417–418, grants L628; grant migration `2026_07_02_100002`). Support workers 403 on everything — confirmed by the existing test ("the /it hub is gated on it.view").
2. **No ticket detail surface.** No show route, no thread, no comments, no history. `resolveTicket` (controller L252–269) takes no resolution note; nothing records what fixed it. All actions live in row context menus.
3. **No SLA anything.** `it_tickets` has no due/response columns (migration `2026_07_02_100001`), no breach state, no escalation, no scheduled command. `routes/console.php` is where commands schedule (`app(Schedule::class)` blocks, ~L70+).
4. **`stats()` is thin but correct** (controller L353–376): requests pending/in_progress all-time + done_30d, tickets open + urgent. No unassigned, no SLA states, no awaiting-reply, no per-view counts. Needs a real summary payload.
5. **Queues are unpaginated** — `requestRows`/`ticketRows` (L276–350) do `get()->map` full-table arrays. Needs server-side pagination + summary endpoint for hero counts.
6. **Hero is hand-rolled** — `it/index.tsx:267` `bg-gradient-to-br from-primary/90 via-primary to-primary/80`, static (non-link) chips. Not the golden band kit (`people-hero.tsx` shape).
7. **Ticket feature gaps:** flat 4 categories, no subcategory, no attachments, no watchers, no reopen, no auto-close, no CSAT, no merge, no saved views, no bulk, no reports, no KB, no email-in. Variant maps at `it/index.tsx:84–104` are semantic maps *feeding* `StatusBadge` — acceptable pattern, keep/extend.
8. **Provisioning gaps:** no priority, no due date; `notes` is a single final-outcome field overwritten at fulfil.
9. **Notifications:** only `ItProvisioningCancelledNotification` (app/Domain/Hr/Notifications) exists. Requesters hear nothing on create/assign/resolve. No ticket notifications at all.
10. **Tests:** 4 happy-path tests in `tests/Feature/It/ItProvisioningTest.php` (gate, checklist bridge, fulfil→task-complete, ticket lifecycle). No policy/authz matrix, no SLA, no comment coverage. These 4 stay green forever.
11. **Attachment infra answer (§P.4):** NO generic polymorphic attachment table exists — every module has its own model (`HsAttachment`, `PrivacyAttachment`, `ClientIncidentAttachment`, `ClinicalAttachment`, …). The shared piece is the controller concern `app/Http/Controllers/Concerns/ServesPrivateAttachments.php` (private disk, mime allowlist, CSP sandbox — closes stored-XSS) + frontend `components/ui/file-dropzone.tsx`. **Decision: build `it_attachments` (morphs: ticket|comment|kb_article) per §P.4 and serve downloads via `ServesPrivateAttachments`.**
12. **Cross-loop bridge (sacred):** `OnboardingService::createItProvisioningRequests()` (L524–568) — IT-category tasks, equipment excluded (asset path), idempotent per task, Schema::hasTable-guarded, never blocks checklist creation. `fulfil` completes the linked task via `completeTask()` in a DB transaction (controller L114–137); `cancel` annotates the task + notifies the checklist creator best-effort (L163–183).
13. **Tenancy pattern:** `ResolvesHrTenant` trait (`resolveHrTenantIdForUser`, `assertHrTenantAccess`, `rejectForeignTenantRecipient`) + `forTenant` scopes on both models. Keep on every new query/mutation.
14. **No FormRequests in the module** — all inline `$request->validate()`. New mutations get FormRequests per the non-negotiables.

## Build order (master checklist)

- [x] 1. §A audit pass → seed this gap doc (pass 0). *(2026-07-07: findings above; attachment decision recorded — docs/IT_TICKETING_GAP_ANALYSIS.md)*
- [x] 2. §P.9 + §B permissions: `it.request` migration/seeder, sidebar gate, route regrouping (`it.tickets.store` out of manage), `ItTicketPolicy` + authz tests. *(2026-07-07: `2026_07_07_100001` grant migration + RbacSeeder augment (staff roles only — client/next_of_kin excluded, see IT_TICKETING_QUESTIONS.md §1); routes regrouped `it.request|it.view`; ItTicketPolicy (view/comment/reopen/update/resolve/close/delete); controller capability-split payloads + `myTicketRows` + triage-fields-agent-only on store; `can.it.request` in HandleInertiaRequests (cache v4); sidebar OR-gate; minimal My-tickets tab + requester hero chips + assignee field hidden for requesters (full §B UI lands in item 5). Existing gate test updated to the new spec (worker 200 + requester payload; roleless 403); 10/10 Pest, types/lint/build clean.)*
- [x] 3. §P.1–3, 5, 8, 10 migrations + model/relationship/constant updates (+ factories) — schema lands early, in one pass, tested. *(2026-07-07: `2026_07_07_100002_extend_it_ticketing_schema` — it_tickets +17 cols (reference/subcategory/source/asset+prov FKs/SLA clocks/lifecycle/CSAT, tenant-unique reference, 2 composite indexes, backfill source=agent + per-tenant IT-%06d refs), it_ticket_comments, it_ticket_events (morphs, created_at-only), it_ticket_watchers (unique pivot), provisioning +priority/due_date; ItTicket rebuilt (STATUSES+waiting, SOURCES/SLA_STATES consts, relations comments/events/watchers/asset/provisioningRequest, HasFactory), ItTicketComment+publicOnly, ItTicketEvent+static record(), provisioning events/linkedTickets, morphMap aliases it_ticket/it_provisioning_request; ItTicketFactory+ItTicketCommentFactory. 15/15 IT Pest green. §P.6 SLA→item 8, §P.7 KB→item 14, §P.4 attachments→item 6.)*
- [x] 4. Reference generation + backfill; controller pagination + the new server summary payload + saved-view params. *(2026-07-07: ItTicket `creating` hook + `nextReference()` (max-based per tenant) + `createWithReference()` bounded retry on the tenant-unique index; storeTicket stamps source/reference + `created` event, flash carries the ref; queues → `paginate(15)` with named page params (`tickets_page`/`requests_page`) + `withQueryString`+`through`; saved-view param `view` (all_open/unassigned/mine/breaching/breached/awaiting_reply(v1 proxy=no first response)/waiting/recently_resolved) via `applyTicketView`; `stats` → full `summary` (tickets counts+by_status+per-view, provisioning incl. overdue/pending_over_7d, per-user `my`; requesters get `my` only); it/index.tsx consumes paginators + LaravelPagination + summary-fed hero chips/badges, rows show reference. 19/19 IT Pest, types/lint/build clean. Backfill of pre-existing rows shipped with item 3's migration.)*
- [x] 5. §B My tickets tab + Raise-a-ticket quick modal + created-receipt notification. *(2026-07-07: `RaiseTicketDialog` (it-wizards.tsx) — single-step review-free, plain-language category tiles + urgency Segmented→priority, "+ Add more details" disclosure, success pane shows the stamped reference via new `flash.it_ticket` (HandleInertiaRequests); `TicketCreatedNotification` (app/Notifications/It, database+mail, reference+title ONLY — description never leaves the app) receipt→requester + urgent_alert→it.manage agents minus actor (`usersWithItManage` mirrors HrNotificationService query, org-or-NULL tenant scope); My-tickets rows gain StatusDots progress + "Waiting on you" warning badge; requester hero CTA + tab CTA open the quick modal. Deferred to their own items (would be dead UI now): attachment drop→6, KB search/suggestions→14, CSAT slot→15. 22/22 IT Pest; types/lint/build clean.)*
- [ ] 6. §E ticket workspace: show route/policy payloads → `TicketThread` (comments API, internal notes) → properties rail (assign/status/priority/category/asset/watchers) → timeline → drawer.
- [ ] 7. §N3 Resolve modal + close/reopen routes + auto-close command + `TicketResolved`/`TicketReopened` notifications.
- [ ] 8. §G SLA: policies table/seeder/editor → stamping + waiting-pause → `it:check-sla` + notifications + queue/hero chips.
- [ ] 9. §F2 queue rebuild: standard table, toolbar, saved views, bulk, pagination.
- [ ] 10. §C hero (golden band + summary chips + right-rail toggle).
- [ ] 11. §F1 Overview tab.
- [ ] 12. §H provisioning uplift (priority/due, manual wizard, linked tickets, bulk, events).
- [ ] 13. §N2 agent Log & triage wizard rebuild (SLA preview, on-behalf-of, asset link).
- [ ] 14. §I Knowledge tab + KB modal + deflection + helpful votes.
- [ ] 15. §K CSAT (prompt, store, rail display).
- [ ] 16. §L Reports tab (server aggregates + recharts + export).
- [ ] 17. §O right-click everywhere + default-tab persistence.
- [ ] 18. §S delight + axe pass + screenshot diff of every tab/modal/workspace vs gold standards; final DoD sweep.

## Decisions / questions

- **§P.4 attachments:** no clean generic infra → build `it_attachments` with `attachable` morphs, serve via `ServesPrivateAttachments` (decision recorded in finding 11; within pre-approved schema).
- Deferred decisions live in `IT_TICKETING_QUESTIONS.md` (repo root) — created when the first one arises.
