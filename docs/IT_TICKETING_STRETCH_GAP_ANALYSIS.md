# IT Ticketing — Stretch / Deferred Items — Build Loop

Continuation of the completed [IT_TICKETING_GAP_ANALYSIS.md](IT_TICKETING_GAP_ANALYSIS.md)
(all 18 items shipped + merged to `main` `9c95c2d3`). This loop clears the five
stretch items that were deferred in [IT_TICKETING_QUESTIONS.md](../IT_TICKETING_QUESTIONS.md)
(#2 business-hours SLA, #4 email-in / merge / approvals / external fulfilment)
plus the automated axe pass (#3).

**Same rules as the parent loop:** one checklist item per pass; FormRequest +
policy + `forTenant` + audit + Pest in the same pass; **schema only from §P-S
below** — anything more → questions file. Reuse the kit (wizard shell, StatusBadge,
tokens — no raw hex). Never break the 88 existing `tests/Feature/It` tests, the
onboarding→provisioning bridge, or the SLA engine's current behaviour.

## Verify commands
- Backend: `~/.config/herd/bin/php84/php.exe artisan test tests/Feature/It --compact`
- Frontend: `npm run types` · `npm run build` · `npm run lint`
- New files only through Pint: `~/.config/herd/bin/php84/php.exe vendor/bin/pint <files>`

## Feasibility triage (decided this pass)

| # | Item | Feasibility | Schema | Note |
|---|------|-------------|--------|------|
| A | **Business-hours SLA calendar** | ✅ full build | §P-S1 | NZ default Mon–Fri 08:00–17:00 `Pacific/Auckland` + holiday list; `null` = today's 24/7 behaviour, so nothing regresses |
| B | **Ticket merge** | ✅ full build | §P-S2 | fold duplicate's thread/events/watchers into a target, close source as `merged` |
| C | **Approval workflows** | ✅ full build | §P-S3 | category-triggered; a manager approves before an agent may resolve/fulfil |
| D | **Email-to-ticket ingestion** | ⚙️ app-side buildable; **infra-blocked to go live** | §P-S4 | build a provider-agnostic inbound webhook + parser + reply-threading; the concrete mail provider + MX + signing secret is a deploy decision → questions #5 |
| E | **External fulfilment integration** | ⛔ needs a target system named | tbd | no system named yet; propose a generic signed outbound webhook + status callback → questions #6. **DECISION pass before any build.** |

## §P-S — pre-approved stretch schema (build EXACTLY this; more → questions file)

**S1 — business hours (item A):** add to `it_sla_policies`
- `business_hours` `json` nullable — `{mon:[["08:00","17:00"]], …, sat:[], sun:[]}`; `null` ⇒ 24/7 (unchanged).
- `holiday_dates` `json` nullable — `["2026-12-25", …]` counted as non-working.

**S2 — ticket merge (item B):** add to `it_tickets`
- `merged_into_ticket_id` `unsignedBigInteger` nullable, FK→`it_tickets.id` `nullOnDelete`, indexed.
- `merged_at` `timestamp` nullable.
- Merge itself is recorded via the existing `it_ticket_events` (new event key `merged`) — **no new event table.**

**S3 — approvals (item C):** new table `it_ticket_approvals`
- `id`, `tenant_id` (indexed), `it_ticket_id` FK cascade, `requested_by`/`approver_id` FK→users (approver nullable until decided), `status` `string` default `pending` (`pending|approved|rejected`), `reason` `text` nullable, `decided_at` `timestamp` nullable, timestamps.
- add to `it_tickets`: `requires_approval` `boolean` default `false` (queue badge / gate flag).

**S4 — email-in (item D):** 
- add to `it_tickets`: `source` `string` default `web` (`web|email|api`).
- new table `it_inbound_emails` — `id`, `tenant_id`, `it_ticket_id` nullable FK nullOnDelete, `from_email`, `subject`, `message_id` (indexed), `in_reply_to` nullable, `body_preview` `text` nullable, `status` `string` default `processed` (`processed|unmatched|rejected`), `received_at`, timestamps. (Audit log of ingestion; body stored as preview only, privacy §8.)

**Item E schema is intentionally NOT pre-approved** — it waits on the DECISION pass (S13).

## Build order (⇒ the checklist; one checkbox each, split if too big)

- [x] **S0. Planning** — this doc + feasibility triage + §P-S schema + questions #5/#6 (pass 0).
- [x] **S1.** Business-hours SLA: migration (`business_hours`,`holiday_dates`) + `App\Support\It\BusinessHours` calculator (working-minutes add/between, weekend+holiday aware) + `ItSlaPolicy::calendarFor()` hook + 10 Pest. Null calendar ⇒ continuous 24/7 (unchanged). Suite **98 green**.
- [x] **S2.** Wired `BusinessHours` into `stampSlaDueDates()` (both due dates), `->utc()` on store. Null calendar = byte-identical continuous time (existing tests unchanged). 2 Pest: Fri-16:30 ticket rolls to next working day + a holiday skip. Suite **100 green**. (⚠️ `travelTo()` in tests must use a UTC instant — a NZ-tz travel value stamps 12h off; production `now()` is UTC so unaffected.)
- [x] **S3.** `it:check-sla` at-risk now measures **working minutes** (calendar set) vs wall-clock (null) — a Friday ticket no longer trips at-risk over the idle weekend. Finding: breach (`now≥due`) and the resolve met-check (`now≤due`) were **already correct** since S2 bakes business hours into the due dates — only at-risk used a raw wall-clock ratio. 1 Pest walks Sat→ok, Mon 08:50→at_risk, Mon 09:30→breached. Suite **101 green**.
- [x] **S4a.** SLA editor backend: `UpdateSlaPoliciesRequest` + `updateSlaPolicies` now take an optional tenant calendar (enabled + open/close + working-days + holidays), build the `business_hours` map and write it to all 4 rows ("apply to all"); disabled clears to null (24/7). `slaCalendar()` flattens the stored map back to the editor's single-window view (payload `slaCalendar`). 4 Pest (write-all, clear, close≤open rejected, working-day required). Suite **105 green**.
- [x] **S4b.** SLA editor UI: added a second "Business hours" step to `SlaPolicyDialog` — enable toggle, open/close time inputs, working-day selector chips, holiday list; step-1 copy swaps 24/7 ⇄ calendar. Calendar held in local state, merged into the save via `form.transform` (grid form untouched). "runs 24/7 for now" note replaced. types + lint + build clean. **✅ item A (business-hours SLA) complete.**
- [x] **S5.** Ticket merge schema: migration (`merged_into_ticket_id` self-FK nullOnDelete, `merged_at`) + `mergedInto`/`mergedTickets`/`isMerged()` on the model + `ItTicketPolicy@merge(user, source, target)` encapsulating every guard (agent-only, no self-merge, no re-merging a merged source, no closed target, same tenant). 5 policy Pest. Suite **110 green**.
- [x] **S6.** Merge action: `MergeTicketRequest` (authorises via the S5 policy) + `ItTicketController@merge` (route `it.tickets.merge`, it.manage group) — folds the source's comments + watchers onto the target, closes the source as merged, records a `merged` marker on both. **Refinement:** audit *events* stay immutable on each ticket (merging must not rewrite a ticket's own history in a compliance app) — only the conversation + watchers move. Reopen now refuses a merged source. 2 round-trip Pest. Suite **112 green**. ✅ item B backend done.
- [x] **S7a.** Merge payload: `show()` now carries `mergeTargets` (agent-only recent open tickets, excl. self/merged, capped 50), `ticket.merged_into` (survivor ref for the banner) and `can.merge` (agent + live source). 1 Inertia-prop Pest (agent sees the live candidate + can.merge; requester gets neither). Suite **113 green**.
- [x] **S7b.** Merge UI: a "Merge…" header action (agent-only, `can.merge`) opens `MergeTicketDialog` (search box + selectable target list, client-filtered by ref/title, confirm posts `it.tickets.merge`); a "This ticket was merged into TCK-…" banner links a merged source to its survivor. types + lint + build clean. **✅ item B (ticket merge) complete.**
- [x] **S8.** Approvals foundation: migration (`it_ticket_approvals` + `requires_approval` on tickets), `ItTicketApproval` model, `config/it.php` approval categories (account, hardware), `ItTicket::categoryNeedsApproval()`/`approvalState()` + `approvals()` relation. Authz: `ItTicketPolicy@requestApproval` (agent, category needs it, no live request) + `ItTicketApprovalPolicy@decide` (agent, pending, **not your own** — separation of duties). 8 policy Pest. Suite **121 green**.
- [x] **S9a.** Approval flow: `requires_approval` set at creation from the category; `requestApproval` + `decideApproval` actions (`RequestApprovalRequest`/`DecideApprovalRequest`, routes `it.tickets.approvals.request` + `it.approvals.decide`); `TicketApprovalNotification` (ref+title only) → the other agents on request, the requester on decision; `approval_requested`/`approval_approved`/`approval_rejected` events. 5 flow Pest (flag-at-creation, request+notify, approve, reject, own-request 403). Suite **126 green**.
- [x] **S9b.** Approval gate: `resolveTicket` and the `updateTicket` status→resolved path both refuse a `requires_approval` ticket until `approvalState()==='approved'` (pending/rejected block). 5 gate Pest. **Decision:** narrowed `config('it.approval.categories')` to `['account']` only — gating every `hardware` ticket (mostly repairs) was too much friction and broke fixtures; access grants are what genuinely need sign-off (config-tunable to widen later). Suite **131 green**. ✅ item C backend done (foundation + flow + gate).
- [x] **S10a.** Approval payload: `show()` carries `ticket.requires_approval`, `ticket.approval` (latest — status/requester/approver/reason/requested+decided dates) and `can.requestApproval`/`can.decideApproval` (the latter only on a pending request the viewer may decide). 1 Inertia-prop Pest (agent→request; other agent→decide; requester→neither). Suite **132 green**.
- [x] **S10b.** Approval rail on the workspace: a "Manager approval" card with a status badge (needed/awaiting/approved/rejected), "Request approval" (agents), "Approve"/"Reject" (a different manager), and a who/when line. types + lint + build clean. (Queue-row badge deferred — minor.) **✅ item C (approval workflows) complete.**
- [x] **S11.** Email-in schema: `it_inbound_emails` ingestion log (table + `ItInboundEmail` model, ticket link, processed/unmatched/rejected) + `email` added to `ItTicket::SOURCES`. Source already rides the `show()` payload; a visible "via email" source chip is folded into S14 polish. 3 model Pest. Suite **135 green**.
- [ ] **S12.** Email-in: `POST /it/email/inbound` (unauthenticated, shared-secret verified) + provider-agnostic parser → new ticket or threaded reply (match ticket reference in subject) + ingestion log; Pest with a simulated payload (create + reply + unmatched).
- [ ] **S13.** External fulfilment: **DECISION pass** — write the recommendation (generic signed webhook + callback) to questions #6; if I can pick a defensible default, seed a §P-S5 and build the abstraction; else mark ⛔ blocked and move on. No silent schema.
- [ ] **S14.** Delight + accessibility: run `npm run visual:test` (axe) if reachable on this build (else re-note in questions #3); static a11y pass on every new surface; final DoD sweep (full suite + types + build + lint).

## Decisions / questions
Blockers and chosen defaults live in [IT_TICKETING_QUESTIONS.md](../IT_TICKETING_QUESTIONS.md):
#5 (mail provider for email-in), #6 (external fulfilment target). Everything else
ships on documented NZ defaults.
