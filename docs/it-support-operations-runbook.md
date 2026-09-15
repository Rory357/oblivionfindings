# IT & Support — operations runbook (W26)

Who this is for: whoever is on point for the IT & Support module in production
(deploy owner, IT lead). It covers the automations that must run, what "healthy"
looks like, how to recognise and recover the common failures, and which
operational decisions are still open gates. Everything here refers to existing
surfaces and commands; nothing requires a separate offline tool.

Single operating organisation, approved Sites, existing roles and permissions
throughout. Never run destructive operations against production data to
"test" a step — use the disposable fixtures described under drills.

## 1. Where to look first

| Question | Where |
|---|---|
| Are the automations running? | `/it/setup?tab=operations` → Operations audit (each schedule shows last run, last success, freshness state: fresh / stale / unmeasured) |
| Is mail arriving / leaving? | Same page: mailbox coverage, delivery failures, retry controls; Settings → IT mailbox for connection state |
| Did a scheduled ticket fail to create? | `/tasks` for the plan owner (source "Recurring ticket failures"), and the plan card on `/it/setup?tab=automation` |
| Is a template about to go stale? | `/tasks` for the template owner (source "IT automation reviews") |
| What would routing do right now? | `/it/setup?tab=queues` → Dry-run routing |
| Why is a ticket where it is? | Ticket detail → routing block ("Why this routing") |

Freshness rules: a schedule is **unmeasured** until its first recorded run,
**fresh** while the last success is within its expected cadence, **stale**
otherwise. Stale on any of the minute-cadence automations for more than ten
minutes means the scheduler or queue worker is down — see §3.

## 2. Automations (canonical list: `ItAutomationScheduleCatalog`)

All run in Pacific/Auckland, `withoutOverlapping`, `onOneServer`. Every run is
recorded from Laravel scheduler events into `it_automation_runs`, which is what
the Operations audit reads — the web view never depends on `routes/console.php`
having loaded.

| Key | Command / job | Cadence | What it does | If it stops |
|---|---|---|---|---|
| `it.check-sla` | `it:check-sla` | hourly | Recomputes SLA states, escalates at-risk/breached, flags urgent-unassigned | Health meters go stale; nothing breaches silently — the audit shows "stale" |
| `it.close-resolved` | `it:close-resolved` | 07:10 daily | Closes tickets resolved 7+ days with no pushback (requester reopen window preserved) | Resolved tickets stay open; harmless, catch-up on next run |
| `it.prune-knowledge-uploads` | `it:prune-knowledge-uploads` | 03:40 daily | Abandons stale unfinished Knowledge uploads and disposes their bytes; disposes abandoned bytes; disposes quarantined bytes after the longer window | Private disk grows; no user impact. Safe to run manually |
| `it.run-recurrence` | `it:run-recurrence` | every 15 min | Creates one routed ticket per due recurrence occurrence; bounded catch-up records skips | Due occurrences queue up; on restart the most recent per plan becomes work, older ones are recorded as skipped (no storm) |
| `it.poll-mailbox` | `PollItMailboxJob` | hourly | Ingests support mail through the shared intake path | Mail stops arriving; see §4 |
| `it.dispatch-notifications` | `it:dispatch-notifications` | every minute | Recovers pending ticket notifications | Notifications delay; nothing is lost (durable outbox) |
| `it.retry-attachment-cleanup` | `it:retry-attachment-cleanup --limit=100` | every 5 min | Retries pending attachment cleanup operations | Orphaned bytes until it resumes |
| `it.check-approval-deadlines` | `it:check-approval-deadlines --limit=100` | every minute | Expires / escalates ticket approvals | Approvals overstay; tasks still visible |
| `it.expire-provisioning-approvals` | `it:expire-provisioning-approvals --limit=200` | every 10 min | Expires stale provisioning approvals and re-routes | Provisioning approvals overstay |

Adding a definition: add it to `ItAutomationScheduleCatalog::DEFINITIONS` and
bump the expected list in `tests/Feature/It/ItServiceOperationsTest.php`
("existing IT schedules are named once…"). That test is the contract that
every automation is named once, recorded, and runs without overlap.

## 3. Scheduler / queue worker down

Symptoms: Operations audit shows several schedules **stale** at once;
`it.dispatch-notifications` older than a few minutes.

1. Confirm the host cron is invoking `php artisan schedule:run` every minute
   and the queue worker (`monitoring` and default queues) is alive.
2. Restart the worker / cron. Do not run `it:*` commands by hand in a loop.
3. Watch the audit: minute-cadence rows should turn fresh within two minutes.
4. Backlog behaviour is safe by construction: recurrence claims each
   occurrence once (unique run rows), notifications are a durable outbox,
   approvals and SLA re-evaluate on their next run. Expect a burst of catch-up
   activity, not duplicates.

## 4. Support mailbox problems

Symptoms: connection shows error or the audit's mailbox coverage stops
advancing; "last successful ingestion" ages.

1. Settings → IT mailbox: read the state. `error` with a refresh/expiry reason
   means the delegated grant lapsed — an administrator reconnects with the
   same mailbox scope. Do not reconnect as a personal account.
2. An empty inbox and a transport failure are reported separately; a healthy
   "0 new" is not the same as "cannot read".
3. After reconnecting, the next poll resumes from the retained checkpoint;
   message-ID records prevent re-creating tickets already ingested.
4. Unknown senders stay quarantined until an operator decides; do not widen
   sender authorization to "fix" a quiet inbox.

Outbound: failed deliveries appear on the ticket and in the audit with retry;
provider rejections are recorded as failed, never as accepted. Internal notes
never leave the application.

## 5. Recurring tickets

- A failed occurrence appears in the plan owner's `/tasks` with the failure
  detail (typical causes: the template's Site was archived, a service was
  retired, the owner lost approval). Fix the plan template or pause the plan;
  the next successful occurrence clears the task automatically.
- Editing a plan applies to future occurrences only; recorded runs and their
  tickets are never rewritten.
- Retire is terminal; create a new plan rather than resurrecting one.

## 6. Knowledge files and retention

- Unfinished uploads are visible to the author in the editor's unfinished
  list with resume/dismiss controls; the daily prune abandons what is left
  after the retention window and disposes bytes. Quarantined bytes are held
  longer as evidence, then disposed; rows are kept as history, every disposal
  is audited (`it.knowledge.file.retention_pruned`).
- Ready files are never touched by retention. Removing a saved file is an
  authored document change (replacement series), not an operations task.
- The malware scanner is required for uploads to become ready; if it is
  unavailable, files stay private as `scan_unavailable` and can be retried
  when it returns — nothing is silently published.

## 7. Reply templates, macros and routing

- Template review dates surface to their owners as tasks a week ahead;
  archived templates and macros cannot be used but keep their history.
- Macros apply through the same guards as manual changes; a stale ticket,
  archived template or missing queue blocks the macro with an explanation —
  fix the macro in Setup → Automation rather than the ticket.
- Before changing queue rules, use Dry-run routing to see what would move.

## 8. Permissions on deploy

Deploys run migrations, **never seeders**. New permission-gated features must
ship a grant migration (see `2026_09_13_200000_grant_vendor_vault_permissions`
and `2026_09_09_000006_add_knowledge_and_credential_audit_permissions`). If a
feature 403s for every role after a deploy, that is the first thing to check.

## 9. Recovery drills (disposable fixtures only)

Run these in an isolated environment with disposable data, never against the
working database:

1. **Scheduler outage:** stop the worker for 45 minutes with an active
   15-minute recurrence plan; restart; expect exactly one new ticket and two
   `skipped` run rows on the plan.
2. **Replay:** run `it:run-recurrence` twice for the same due time; expect no
   second ticket (unique occurrence claim).
3. **Retention:** seed reserved/abandoned/quarantined Knowledge files with
   backdated `created_at`, run `it:prune-knowledge-uploads`, confirm only the
   expected bytes are gone and every row remains with an audit record.
4. **Mailbox expiry:** revoke the test grant, poll, confirm the connection
   reports the error (not an empty inbox), reconnect, confirm the next poll
   ingests without duplicates.

The application-level tests that encode these behaviours:
`ItRecurrencePlanTest`, `ItKnowledgeUploadRetentionTest`, `ItMailboxPollTest`,
`ItServiceOperationsTest`.

## 10. Open operational gates (decisions the application cannot make)

- **Backup custody and restore proof:** encrypted backups, key custody and a
  demonstrated restore of ticket/document/vault fixtures are an
  infrastructure responsibility; the application provides the fixtures and
  invariants above, not the storage guarantees.
- **Provider accounts:** approved Microsoft/Gmail test mailboxes for the
  full inbound/outbound acceptance scenarios; not configured locally.
- **Escalation latency:** the SLA watchdog's urgent-unassigned threshold is
  hourly; agree the maximum acceptable escalation latency before relying on
  it for critical work.
- **Retention windows:** the Knowledge upload defaults (14 days unfinished,
  30 days quarantine) are command options; confirm them against the
  organisation's records policy.
