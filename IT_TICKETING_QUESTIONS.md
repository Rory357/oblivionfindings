# IT Ticketing — deferred decisions & questions for Chane

Questions the loop could not settle from the prompt alone, each with the
decision taken (so shipping never blocked) and what to change if you disagree.

## 1. `it.request` and the external portal personas (decided — staff only)

§B/§P.9 say grant `it.request` to "all roles", but the mission line is
"everyone on **staff**". The `client` and `next_of_kin` roles are external
portal personas (they get a dedicated portal sidebar and a four-permission
grant set) — giving them the internal IT helpdesk would leak staff tooling
into the family portal.

**Decision:** granted to every role EXCEPT `client` and `next_of_kin`, in both
`RbacSeeder` and migration `2026_07_07_100001_grant_it_request_permission`
(dynamic over the roles table, so niche/exec roles are covered). A Pest test
pins the exclusion. If you DO want portal users raising IT tickets, delete the
two names from the exclusion lists in those files and drop the
"external portal personas hold no it.request grant" test.

## 2. Business-hours SLA calendar (built — 24/7 remains the fallback)

The stretch loop added installation-wide `business_hours` and `holiday_dates` to
`it_sla_policies`, wired working-time arithmetic into SLA stamping and at-risk
detection, and added the calendar controls to the SLA editor. A null or empty
calendar deliberately preserves the original continuous 24/7 behaviour.

## 3. Accessibility — static review plus desktop browser/axe proof complete

The static WCAG-AA review covers the workspace, drawer, thread/composer,
reports, CSAT raters, KB reader and the six-tab hub:
colour is never the only signal (`StatusBadge`/`SlaChip` always pair an icon +
word; the SLA chip is `neutral|warning|critical` with text, never a bare
colour); every `⋯` row menu, `Select`, checkbox, search box and sort header
carries an `aria-label`; the star raters are keyboard-operable `radiogroup`s
and `CsatStars` is `role="img"` with a label; all `animate-*` (skeletons,
confetti, hover-lift) are `motion-reduce:*`-guarded and `fireConfetti` no-ops
under `prefers-reduced-motion`. Two focus-name gaps found and fixed this pass:
the workspace copy-reference chip gained an `aria-label` + `focus-visible`
ring, and the "Copy link to this ticket" text button gained a `focus-visible`
state.

**Verified 2026-07-10:** served the production build directly from
`claude/it-ticketing-stretch` at a 1440×1000 desktop viewport. The agent hub and
support-worker My tickets / Raise a ticket surfaces rendered with zero runtime
console errors. Axe reported zero WCAG A/AA violations after transitions
settled. Radix's modal focus trap retained all 16 sampled Tab moves inside the
dialog. Gradient contrast and Radix focus guards remain manual-review results,
not automated violations.

## 4. Stretch items left deferred (per the brief's "Scope calls" — question-file first)

Not started (the brief says do NOT start these unprompted): **email-to-ticket
ingestion** (needs a mailbox + inbound-mail driver decision), **ticket merge**,
**approval workflows**, **external fulfilment integrations** (wireframe §5,
kept deferred). Business-hours SLA calendars are item 2 above. Say the word on
any of these and it becomes a fresh §P-scoped slice.

**Update 2026-07-09 — picked up.** All of these (plus item 2, business-hours SLA,
and item 3, the axe run) are now being built in a follow-up loop, tracked in
[docs/IT_TICKETING_STRETCH_GAP_ANALYSIS.md](docs/IT_TICKETING_STRETCH_GAP_ANALYSIS.md).
Merge, approvals and business-hours SLA are full builds on documented NZ defaults.
Email-in is fully built with Exchange and Gmail OAuth; deployment setup remains
(see #5). External fulfilment stays blocked on a named target system (see #6).

## 5. Email-to-ticket — Exchange + Gmail OAuth built; deployment setup remains

**DECIDED (2026-07-10 — the user): Microsoft Exchange (OAuth) + Gmail (OAuth)**,
using the existing calendar-sync OAuth stack already in the app:
`App\Contracts\CalendarOAuthToken`, `App\Models\CalendarSyncConnection` (encrypted
access/refresh token store + refresh-window logic), `App\Services\MicrosoftGraphService`
(token refresh against `login.microsoftonline.com` + Graph client; **already has
`sendMail`**), `App\Services\GoogleCalendarService`, the Socialite Microsoft/Google
providers (`config/services.php`), and the `App\Jobs\SyncCalendarJob` poll pattern.

**✅ BUILT (2026-07-10, E0–E7 — docs/IT_EMAIL_OAUTH_GAP_ANALYSIS.md fully ticked):**
`InboundEmailIngestor` (shared ingestion), Graph + Gmail mail-read services,
`ItMailboxConnection` (encrypted-token org connection), hourly `PollItMailboxJob`
(dedupe on message_id, per-connection error stamping), and the **Settings →
Support Mailbox** page (connect/disconnect via the calendar-sync-style OAuth flow,
delegated-mailbox field, poll-now). **To go live, an admin needs to (one-time):**
1. Add the mail scopes to the shared app registrations — Microsoft
   `Mail.ReadWrite` + `Mail.ReadWrite.Shared` (admin consent), Google
   `gmail.modify` (note: markRead WRITES, so read-only scopes are not enough).
2. Register the exact OAuth redirect URIs for the deployed `APP_URL`:
   `<APP_URL>/settings/it-mailbox/callback/microsoft` and
   `<APP_URL>/settings/it-mailbox/callback/google`.
3. Visit **Settings → Support Mailbox → Connect** and authorise: for Exchange,
   any account with delegated access to `support@…` (then set that mailbox in
   the field); for Gmail, the support account itself.
4. Confirm the Laravel scheduler and queue worker are running; the hourly
   schedule dispatches `PollItMailboxJob` to the queue.

The hourly poll (or Poll now) then starts turning unread mail into tickets.
No MX/DNS changes, no webhook secret needed (the S12 webhook remains available as
an optional push ingress via `IT_INBOUND_MAIL_SECRET`).

## 6. External fulfilment integration — which system? (blocked — decision needed)

Wireframe §5's "external fulfilment" was never bound to a named system. Before any
build I need to know what we'd integrate with — e.g. a procurement / asset-vendor
portal, an MSP/RMM (NinjaOne, Atera, etc.), or nothing external at all.

**Recommendation (default if you want it generic):** a signed **outbound webhook** on
provisioning/ticket status change (POST the reference + status + minimal payload to an
installation-wide configured URL, HMAC-signed) plus an inbound **status callback** to close
the loop — no vendor lock-in. If that's acceptable I'll seed a §P-S5 and build it;
otherwise it stays ⛔ blocked pending the target system. **No silent schema** either way.

**S13 decision-pass outcome (2026-07-10):** no target system was named during the
loop, so external fulfilment stays ⛔ **blocked** — building a signed webhook that
fires into the void is infrastructure with no consumer (YAGNI), so I did not. This
is the ONLY stretch item not shipped. To unblock, reply with EITHER a named system
(procurement portal / MSP-RMM / identity provider) OR "build the generic signed
webhook" — the latter becomes a fresh §P-S5 slice (outbound HMAC webhook on
ticket/provisioning status change + an inbound status callback).
