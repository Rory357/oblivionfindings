# Email-to-ticket via Exchange + Gmail OAuth — Build Loop

Takes email-in (S11–S12, questions #5) from the push-webhook stub to the real
**OAuth mailbox poller** the user chose ("Exchange OAuth, same as the others, +
Gmail"). Reuses the app's existing OAuth stack rather than forking it.

## Reuse map ("the same through the application")
- `App\Contracts\CalendarOAuthToken` — token interface (access/refresh/needsRefresh/store).
- `App\Models\CalendarSyncConnection` — org-level encrypted-token connection (provider,
  `scopes`, `account_email`, status). The pattern the mailbox connection mirrors.
- `App\Services\MicrosoftGraphService` — Graph client + token refresh (`.default` scope);
  **already has `sendMail`** — we ADD mail-read.
- `App\Services\GoogleCalendarService` — Google OAuth client; Gmail-read is analogous.
- `App\Jobs\SyncCalendarJob` — the scheduled poll pattern the mailbox poller mirrors.
- Socialite Microsoft/Google providers + `config/services.php` creds (shared app registration).

## Scope note (the one real infra dependency)
The calendar connection consented **calendar** scopes. Reading mail needs
`Mail.Read` (Graph) / `gmail.readonly` (Google) added to the shared app
registration, and a **dedicated `support@…` mailbox** connected (its own
delegated consent) — a shared inbox is a distinct mailbox, not the calendar
account's own. Same machinery, its own connection row. Nothing goes live until
that consent + connection exist; everything below is built and unit-tested
against faked HTTP so it's ready the moment it's connected.

## Verify commands
- Backend: `~/.config/herd/bin/php84/php.exe artisan test tests/Feature/It --compact`
- Frontend: `npm run types` · `npm run build` · `npm run lint`

## Build order (one checkbox each; verify → tick → commit → stop)
- [x] **E0.** Seed this plan (pass 0).
- [x] **E1.** Extracted `App\Domain\It\InboundEmailIngestor` (`ingest(array $message): ItInboundEmail` — sender match, `IT-…` subject threading, new `source=email` ticket, `it_inbound_emails` log). Webhook controller is now a thin transport shell (secret gate + validation → ingestor); S12 tests untouched in behaviour, +1 direct-ingestor test. Suite **141 green** (1203 assertions), Pint clean.
- [x] **E2.** `MicrosoftGraphService` mail-read: `listUnreadMessages(mailbox)` (unread-only inbox pull via `$filter=isRead eq false`, normalised to the ingestor shape + `graph_id`, HTML stripped to text, malformed rows dropped) + `markRead(mailbox, id)` (PATCH `isRead=true`) — both on the service's existing token-refresh client. 3 `Http::fake` Pest. Suite **144 green** (1222 assertions).
- [x] **E3.** `ItMailboxConnection` (migration `it_mailbox_connections` — unique per tenant+provider, encrypted tokens, scopes, status, `mailbox_email` + `last_polled_at`; model implements `CalendarOAuthToken`, `mailboxEmail()` falls back to the consenting account). 5 Pest incl. encryption-at-rest and the connection driving `MicrosoftGraphService` directly. Suite **149 green** (1236 assertions).
- [x] **E4.** `PollItMailboxJob`: connected mailboxes → unread via Graph → `InboundEmailIngestor` → markRead, with a `message_id` dedupe (a failed markRead can't duplicate tickets next poll), per-connection error stamping (`status=error`+`last_error`, other connections unaffected), `last_polled_at` on success. Scheduled hourly in `console.php` (inert until a connection exists); google rows skipped until E5. 3 Pest (mail→ticket+PATCH, dedupe, skip rules with zero HTTP). Suite **152 green** (1246 assertions).
- [x] **E5.** Gmail read: new `GoogleGmailService` (sibling of `GoogleCalendarService`, same token/refresh shape, Gmail base URL) — `listUnreadMessages` (list `is:unread in:inbox` → fetch full → normalise RFC headers + base64url bodies, text/plain over stripped-HTML over snippet) + `markRead` (remove `UNREAD` label). Poller now provider-blind via `match` (Graph|Gmail); renamed the normalised key `graph_id`→`remote_id` across E2/E4 accordingly. Gmail reads the CONNECTED account's own inbox (connect the support account itself). 3 service Pest + google end-to-end poller test (⚠️ fixture gotcha: `&nbsp;` decodes to U+00A0, not a space). Suite **156 green** (1270 assertions).
- [x] **E6a.** Backend: `ItMailboxOAuthController` (redirect/callback/disconnect, mirrors `CalendarSyncOAuthController`; reconnect preserves the delegated mailbox) + `ItMailboxSettingsController` (per-provider status payload, delegated-mailbox update, poll-now dispatch) + routes under the `integrations.manage_tenant_secrets` gate (`settings/it-mailbox/*`). **Scope correction vs the plan:** markRead WRITES, so consent is `Mail.ReadWrite(.Shared)` / `gmail.modify` — not the read-only scopes. 6 Pest (authz, mocked-Socialite callback stores the row, disconnect, mailbox set/validate/clear, error-before-connect, poll-now). Suite **162 green** (1310 assertions).
- [x] **E6b.** UI: `settings/it-mailbox` page ("Support Mailbox" in the settings sidebar, Integrations group, same `integrations.manageTenantSecrets` nav gate as Calendar Sync) — per-provider cards (Connected/Error/Not-connected badges, account · effective mailbox · last poll, last-error box), delegated-mailbox field on the Microsoft card, Connect/Disconnect, Poll-now. Semantic `status-*` tokens (not the sibling page's legacy palette classes). types ✓ · lint 0/0 · build ✓.
- [ ] **E7.** DoD sweep: full `tests/Feature/It` + types + build + lint; update questions #5 + memory.

## Decisions
- **Connection model:** a **dedicated `support@…` mailbox connection** on the shared
  `CalendarOAuthToken` pattern (default). Alternative: extend `CalendarSyncConnection`
  to one unified connection with calendar+mail scopes — say the word to switch.
- **Ingress:** OAuth poller is primary; the S12 `/api/it/email/inbound` webhook stays
  as an optional secondary push ingress (both feed the one `InboundEmailIngestor`).
