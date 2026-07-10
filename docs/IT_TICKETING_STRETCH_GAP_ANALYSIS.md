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
| D | **Email-to-ticket ingestion** | ✅ built; deployment setup remains | §P-S4 | provider-agnostic ingestion plus Exchange/Gmail OAuth polling; go-live scopes, callbacks and mailbox connection are in questions #5 |
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
- [x] **S12.** Email-in webhook: `POST /api/it/email/inbound` (API routes → stateless, no session/CSRF; shared-secret `X-IT-Inbound-Secret`, inert until `IT_INBOUND_MAIL_SECRET` set). Provider-agnostic parser: `IT-…` in subject → thread a public reply; else a known staff sender opens a `source=email` ticket; unknown senders logged `unmatched`, never auto-ticketed. Every message hits `it_inbound_emails`. 5 Pest (bad/absent secret, new, threaded, unmatched). Suite **140 green**. ✅ item D app-side done; the follow-up E-loop selected and built Exchange/Gmail OAuth polling, while this webhook remains optional secondary ingress.
- ⛔ **S13.** External fulfilment: **DECISION pass done — BLOCKED (see [questions #6](../IT_TICKETING_QUESTIONS.md))**. No target system was named, so building a signed webhook that fires into the void would be a consumer-less integration (YAGNI) — not built. Recommendation recorded (generic HMAC outbound webhook + status callback → a fresh §P-S5 on your word). **The only stretch item not shipped.** No silent schema.
- [x] **S14.** Delight + a11y + DoD sweep. Delight: "via email" source chip on the workspace header (closes the S11 badge deferral). Static WCAG-AA pass on the new surfaces — business-hours step (Checkbox in `<label>`, time inputs `aria-label`, working-day chips `aria-pressed`+focus-visible, holiday-remove `aria-label`), merge dialog (search `aria-label`, rows `aria-pressed`+focus-visible), approval rail (kit Buttons + StatusBadge pair colour with text) — colour never the only signal. **Updated 2026-07-10:** production assets were served directly from this worktree at the required desktop viewport; agent and support-worker IT surfaces had zero runtime console errors and zero settled axe WCAG A/AA violations, with keyboard focus trapped inside the Raise a ticket dialog. **Final sweep: `tests/Feature/It` 140 passed (1200 assertions) · types ✓ · build ✓ · lint 0 errors.**

---

## ✅ STRETCH LOOP COMPLETE (2026-07-10)

All buildable stretch items shipped on `claude/it-ticketing-stretch`; the S-loop grew the suite 88 → **140**, and the completed OAuth E-loop grew it again to **162**.
- **A · Business-hours SLA** (S1–S4b) ✅ calculator, stamping, at-risk, admin editor.
- **B · Ticket merge** (S5–S7b) ✅ schema, policy, fold, payload, dialog + banner.
- **C · Approval workflows** (S8–S10b) ✅ schema, policies, request/decide, resolve gate, rail.
- **D · Email-to-ticket** (S11–S12 + E0–E7) ✅ log, parser, threading, optional webhook, Exchange/Gmail OAuth poller and mailbox settings; go-live setup is in questions #5.
- **E · External fulfilment** (S13) ⛔ **blocked** — needs a named target system or a "build the generic webhook" go-ahead (questions #6).

## Decisions / questions
Blockers and chosen defaults live in [IT_TICKETING_QUESTIONS.md](../IT_TICKETING_QUESTIONS.md):
#5 (email-in deployment runbook), #6 (external fulfilment target). Everything else
ships on documented NZ defaults.
