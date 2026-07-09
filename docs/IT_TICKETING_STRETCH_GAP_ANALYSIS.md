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
- [ ] **S2.** Wire the calculator into `stampSlaDueDates()` (respond/resolve due) so targets land on working time; Pest: a Fri-17:00 ticket with 8×working-hours lands next working day, and a null-calendar policy is unchanged.
- [ ] **S3.** Teach `it:check-sla` at-risk/breach + `resolveTicket()` met-check to measure working minutes; Pest on breach timing across a weekend/holiday.
- [ ] **S4.** Admin editor UI: business-hours + holidays on the SLA-targets surface (per-tenant, "apply to all policies"); FormRequest + policy + Pest; copy replaces the "runs 24/7" note when a calendar is set.
- [ ] **S5.** Ticket merge: migration (`merged_into_ticket_id`,`merged_at`) + model rels + `ItTicketPolicy@merge` + Pest (authz + self/closed-target guards).
- [ ] **S6.** Merge action: `MergeTicketRequest` + controller — reparent conversation + events + watchers to target, add `merged` events both sides, close source, block re-open of a merged source; Pest round-trip.
- [ ] **S7.** Merge UI: workspace action (duplicate-search dialog from the kit) + "Merged into TCK-… " banner on source + redirect; types/build/lint.
- [ ] **S8.** Approvals: migration (`it_ticket_approvals` + `requires_approval`) + model + config default categories + `ItTicketApprovalPolicy` + Pest.
- [ ] **S9.** Approvals backend: request/approve/reject FormRequests + controller; **gate resolve/fulfil while a pending/rejected approval stands**; notifications (reference+title only, §8); Pest incl. the gate.
- [ ] **S10.** Approvals UI: approval rail on the workspace (request / approve / reject), requester sees status, queue badge; types/build/lint.
- [ ] **S11.** Email-in: migration (`source`, `it_inbound_emails`) + model + `source` surfaced on the ticket payload/badge; Pest.
- [ ] **S12.** Email-in: `POST /it/email/inbound` (unauthenticated, shared-secret verified) + provider-agnostic parser → new ticket or threaded reply (match ticket reference in subject) + ingestion log; Pest with a simulated payload (create + reply + unmatched).
- [ ] **S13.** External fulfilment: **DECISION pass** — write the recommendation (generic signed webhook + callback) to questions #6; if I can pick a defensible default, seed a §P-S5 and build the abstraction; else mark ⛔ blocked and move on. No silent schema.
- [ ] **S14.** Delight + accessibility: run `npm run visual:test` (axe) if reachable on this build (else re-note in questions #3); static a11y pass on every new surface; final DoD sweep (full suite + types + build + lint).

## Decisions / questions
Blockers and chosen defaults live in [IT_TICKETING_QUESTIONS.md](../IT_TICKETING_QUESTIONS.md):
#5 (mail provider for email-in), #6 (external fulfilment target). Everything else
ships on documented NZ defaults.
