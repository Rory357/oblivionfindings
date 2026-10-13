# Native personal ticket conversation

This independent deployment serves one operating organisation across approved Sites. Obli is a personal helper, including for technicians: every detail, write and receipt recovery checks the current session/account/role, requester ownership, current approved Site and canonical IT visibility inside one transaction. Merged tickets are concealed. Sensitive tickets retain canonical participant visibility; personal ownership never grants staff fields or internal notes.

## HTTP contract

`GET /api/obli/tickets/{id}?before={comment-id}` returns 200:

```json
{"ticket":{"id":123,"reference":"IT-123","title":"Printer","status":"open","updated_at":"2026-10-08T10:00:00+00:00","path":"/it/tickets/123","description":"Paper light is on","description_truncated":false,"lock_version":1,"can_reply":true},"comments":[{"id":42,"body":"Please check the tray","body_truncated":false,"author":"Support","created_at":"2026-10-08T10:00:00+00:00"}],"next_before":null}
```

Only public comments are returned. The latest page contains at most ten comments, ordered chronologically by ID. `next_before` is the lowest returned ID when older comments remain; use it with `before` to fetch the older page. Pages can contain fewer than ten comments to keep their encoded JSON below 120,000 bytes, within the native 128 KiB cap. Existing descriptions over 12,000 characters and comment bodies over 5,000 characters are truncated with explicit flags. These flags must be surfaced when displaying an excerpt. No attachments, internal notes, watchers, email addresses, staff context or receipt delivery metadata are exposed. `can_reply` follows canonical open statuses; resolved and closed tickets cannot receive a new reply.

`POST /api/obli/tickets/{id}/replies` accepts exactly:

```json
{"request_uuid":"018f0000-0000-7000-8000-000000000091","expected_version":1,"body":"I checked the cable."}
```

UUID is required, expected version is an integer >=1, and body is a required string of at most 5,000 characters. The adapter supplies the current actor, Obli channel and `is_internal=false`. Canonical IT interaction commands own comments, version changes, audit, receipts and notification outbox. No separate writer exists. Body normalization is PHP `trim`: ASCII space/tab/CR/LF/NUL/vertical-tab at its edges; other Unicode whitespace is retained.

First commit returns 201; exact immutable replay returns 200. `GET /api/obli/tickets/{id}/replies/{request_uuid}` returns 200 for an already committed own public Obli reply and performs no write. Both return exactly:

```json
{"ticket":{"id":123,"reference":"IT-123","title":"Printer","status":"open","updated_at":"2026-10-08T10:00:01+00:00","path":"/it/tickets/123","description":"Paper light is on","description_truncated":false,"lock_version":2,"can_reply":true},"comment":{"id":43,"body":"I checked the cable.","body_truncated":false,"author":"Requester","created_at":"2026-10-08T10:00:01+00:00"},"request_uuid":"018f0000-0000-7000-8000-000000000091","committed_version":2}
```

Ticket fields describe the current ticket; committed_version belongs to the original command. Later ticket changes or settlement do not prevent authorized receipt recovery. Changed requester/Site, merged tickets, current role/session denial or a comment made internal conceal the reply, even for technicians.

## Rejection and uncertainty

Expected POST errors contain only `code` and a generic bounded `message`: `version_conflict` (409, canonical version rejection before this command writes), `idempotency_conflict` (409, UUID already bound to another immutable intent), `invalid_request` (422, adapter validation), and `reply_rejected` (422, canonical rejected reply). Exception details are never returned. Other authentication, authorization, missing-record and transport errors retain the existing protected Obli response contract.

Recovery 404 proves neither rollback nor permission to create another reply. Preserve the same request UUID, expected version and exact body after a lost response; repeated POST uses that immutable intent. Receipts are checked before expected version, so an exact previously committed replay is not rejected by a later ticket version. An idempotency conflict requires checking the original intent, not assigning a new UUID automatically.


## Native Windows helper

My tickets now opens an in-helper conversation. Lists page 30 at a time; history pages contain at most ten public messages, shown oldest-to-newest within the selected page. Earlier/Newer controls replace the current page, bounding retained message content. Latest updates returns to the newest conversation page. Statuses are presented in plain language and timestamps in en-NZ/New Zealand time. Shortened legacy text is labelled and Open in Oblivion uses only the validated deployment origin and exact ticket path.

Replies use a separate editable draft, Review reply and Send reply. Reviewing sends nothing. The reviewed ticket/version/body/request UUID stay fixed once submission is attempted. Unknown outcomes offer Check delivery (read-only receipt lookup) and Retry same reply (same immutable POST); editing and switching tickets cannot create a second intent silently. A definitive version/validation rejection preserves the draft and requires current ticket details and a new review. Local validation runs before freezing the draft. Recovered receipts must match the exact ticket, request UUID, reviewed body and an advanced committed version before the draft is cleared.

Drafts are still memory-only. An unsent draft can be explicitly discarded. Sign-out, Windows session lock/logoff, expiry and loss/change of the trusted service connection clear personal ticket/reply state, including review headings. In-flight results are bound to the exact current session and generation and cannot restore signed-out content. Native tokens stay confined to the fixed HTTPS Obli API, with no redirects, a 128 KiB response cap and bounded requests. Strict parsing rejects duplicate/unknown fields, wrong ticket or request identities, malformed pagination, invalid status/version, unsafe origin/path and invalid Unicode. Unicode field bounds count scalars to match the PHP contract.

No ticket text or reply is automatically sent to an AI provider. Existing selected-message sharing creates a separate reviewed new-ticket draft and refuses to replace a pending reply draft. The native helper never receives internal notes, attachments or unrestricted technician ticket access.

## Development verification and limits

The native suite now has 35 check groups, including actual HTTP-client tests with injected responses and WPF conversation/review/unknown-outcome renders. It verifies unchanged retries, read-only recovery, current version re-review, no-send review, late-response privacy, direct-object denial presentation, list/history cursors, draft discard, private-state clearing and strict response contracts. Rendered at 454px and 380px using the existing Orbit theme and artwork; this is the app's own WPF tree, not installed desktop acceptance. Exact final backend, PHP-to-C# and build runs are recorded in CHECKPOINT.md.

Still required: approved installed Windows sign-in/MFA and actual HTTPS server journeys, multiple logged-in Windows users and OS lock/logoff acceptance, real notification delivery and operational failure witnesses. Persistent/offline drafts, ticket attachments/evidence, selected summaries, broader configurable helper features and all other staff/technician AI/RMM and wider network/security/care/tracking/reconstruction requirements remain part of the single final-release gate. No supported installer was signed or published, no real ticket/provider request was made and no workstation service was installed by these checks.


Final development evidence on 8 October 2026: 19 isolated PHP feature tests / 219 assertions; 35 native helper check groups with actual exported PHP page/detail/receipt parsing; Windows x64/ARM64 self-contained builds. Parent reviewed both backend agents' changes. Normal POST schedules the canonical notification dispatcher after commit/response; recovery GET does not. Real notification delivery and installed HTTPS/OS-session acceptance remain unverified. See the final checkpoint for process IDs, elapsed bootstrap evidence and exact limits.
