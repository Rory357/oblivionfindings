# IT & Support service API v1

The service API lets an approved system create and follow IT work without sharing a human session or personal access token. An IT manager creates each identity under **IT & Support → Setup → API identities**, chooses its execution account, operations, work types, sites, fields, expiry, request limit, and signature policy, then copies the credential shown once.

## Authentication and safe storage

Send the one-time credential as a bearer token:

```http
Authorization: Bearer ofi_<public-id>_<secret>
Accept: application/json
```

The application stores only a SHA-256 digest of the high-entropy secret. It cannot display or recover the credential after the creation or rotation response. Rotate an active identity if its credential is lost or exposed; the old credential stops working and its public ID and audit history remain. Revocation permanently disables the identity. An expired identity requires an explicit expiry change before rotation.

### Identity management commands

The desktop setup interface uses authenticated JSON requests with CSRF protection. Identity management is separate from the service bearer-token transport. Issue uses `POST /it/setup/api-identities`; grant edits use `PATCH /it/setup/api-identities/{id}`; rotation and revocation use `POST` to `/{id}/rotate` and `/{id}/revoke`. Every command supplies a stable UUID `request_uuid` and the current `viewer_user_id`. Existing-record changes also supply the reviewed `expected_version`, taken from `configuration_version`. Normal API use updates `last_used_at` without advancing that configuration version. Grant edits preserve the execution account, creator and public ID.

The credential appears only in a successful direct JSON issue/rotation response with `Cache-Control: no-store, private`. It is never placed in session flash, Inertia page/history state, list responses, command records or audit metadata. Repeating a confirmed UUID returns its nonsecret result; `credential_unavailable: true` means a newly generated secret cannot be recovered. After a lost response, `POST /it/setup/api-identities/commands/recover` with the same UUID and viewer distinguishes a confirmed outcome from `not_found`. Retry only the original frozen request after `not_found`; do not silently issue a new rotation. A confirmed rotation whose secret was lost requires a new explicit rotation. The matching `/commands/cancel` endpoint records cancellation only if no command committed, and otherwise returns the committed nonsecret result. Cancellation does not undo an issued identity or a completed rotation.

All command and recovery results require current management and Site authority. A changed viewer or lost access conceals the prior draft, identity and credential. Stale configuration receives `409 identity_changed`; review refreshed safe metadata before submitting a new intent. The wizard and browser acceptance for this management lifecycle are tracked separately in the implementation progress record until verified.

Every mutation (`POST`, `PATCH`, `PUT`, or `DELETE` on a supported route) requires a unique `Idempotency-Key` between 8 and 100 characters. Repeating the same method, path, exact body, identity, and key recovers the existing outcome with `X-Idempotent-Replay: true`. Successful work-item replays present its currently authorized fields; they do not reapply an old mutation. Reusing a key for different content returns `409 idempotency_conflict` after current record authorization. Revoked access cannot be recovered using an old key.

The API always returns JSON, including validation and conflict responses when the caller omits `Accept`. An explicit `500 command_not_applied` means its domain transaction rolled back and the same intent/key may be retried. An unknown historical outcome requires reconciliation; do not create a new key to bypass it.

## Signed requests

When signatures are required, send:

```http
X-OF-Timestamp: <current Unix timestamp>
X-OF-Signature: v1=<lowercase HMAC-SHA256 hex>
```

Build the canonical string with literal newline separators:

```text
<timestamp>\n
<UPPERCASE HTTP method>\n
<request path beginning with />\n
<exact Idempotency-Key, or empty for a read>\n
<lowercase SHA-256 hex of the exact raw request body>
```

Calculate the HMAC-SHA256 with the credential's secret portion as the key. Timestamps outside the five-minute window are rejected. Do not include the host or query string in the canonical path.

## Endpoints

### Create a work item

`POST /api/v1/it/work-items`

```json
{
  "title": "WAN edge unreachable",
  "description": "Five consecutive TCP probes failed.",
  "category": "network",
  "priority": "high",
  "work_type": "incident",
  "site_id": 42
}
```

The identity's configured field, work-type, and Site allowlists are enforced server-side. Supported intake fields are `title`, `description`, `category`, `subcategory`, `priority`, `impact`, `urgency`, `work_type`, `site_id`, `it_service_id`, and `asset_id`. An administrator must explicitly enable each field; title, category, priority, and work type are always required.

Creation uses the canonical ticket reference, SLA stamping, routing, ownership, event, and audit services. The source is recorded as `system`, and the named execution account is the requester/actor.

### Read safe status and context

`GET /api/v1/it/work-items/{id}`

The baseline response contains the work ID/reference, title, type, status, workflow state, priority, `lock_version`, and timestamps. Site/service/asset context, description, routing/ownership, SLA, and resolution fields appear only with the corresponding read grant and current authorization. Sensitive tickets additionally require `work:sensitive` and the execution account's matching permission. Internal comments, raw device configuration, credentials, clinical readings and tracking/media data are not returned.

### Update ticket triage

`PATCH /api/v1/it/work-items/{id}` requires `work:update` and an explicit `allowed_fields.update` grant for each property. Existing identities receive no update fields automatically.

```json
{
  "expected_version": 7,
  "category": "network",
  "priority": "high",
  "priority_reason": "The approved service is unavailable to the on-site team."
}
```

Supported properties are `category`, `subcategory`, `impact`, `urgency` and `priority`. A raw priority requires `priority_reason`; it retains the current impact/urgency assessment and records an override when appropriate. To release an override, send `release_priority_override: true` and `priority_reason`, with the `priority` field grant, and omit raw `priority`. Impact/urgency changes use the existing priority matrix. Ownership, Site scope, lifecycle, draft data and arbitrary fields cannot be changed by this endpoint.

Use the latest authorized `lock_version` as `expected_version`. A stale proposal returns `409 stale_ticket` without changing the record or disclosing a private current snapshot. Read the current record, review the proposed change, then submit a new intent/key with its current version. Repeating an already committed key returns its existing outcome even after a later change.

### Add or remove related work

`POST /api/v1/it/work-items/{id}/relationships` requires `work:link` and current authorization for both tickets.

```json
{
  "target_ticket_id": 43,
  "source_version": 8,
  "target_version": 3,
  "action": "add",
  "relationship": "related_ticket"
}
```

`action` is `add` or `remove`; `relationship` is `related_ticket` or `duplicate_ticket`. These are internal references, not a merge or membership in specialized work. The source must be open and original, the target unmerged, and both reviewed versions current. The canonical command changes reciprocal links and versions atomically. Its response is the currently authorized source work item. Read either ticket separately for its current version; replays never restore a link removed by a later command. This route cannot link arbitrary assets, credentials, devices or other record types.

### Append a public comment or evidence note

`POST /api/v1/it/work-items/{id}/comments`

```json
{
  "body": "Monitoring evidence: TCP 443 recovered for three consecutive checks."
}
```

This endpoint always creates a public comment. It cannot create an internal note or upload an attachment.

### Send a lifecycle callback

`POST /api/v1/it/work-items/{id}/transitions`

```json
{
  "expected_version": 9,
  "to": "in_progress",
  "reason": "Recovery is being verified.",
  "next_action": "Observe for ten minutes."
}
```

Every transition requires `expected_version`. Waiting transitions require `waiting_party` and `reason`. Settlement transitions require the same approvals, required tasks, resolution code, resolution summary and verification evidence as the agent UI (`resolution_verification`). Invalid lifecycle moves return `422 transition_denied`; the API cannot bypass canonical workflow gates or claim another actor/requester-confirmation source.

## Response and error rules

- `201`: work item or public comment created.
- `200`: read, update, relationship or transition completed.
- `400`: missing/invalid idempotency key.
- `401`: invalid, expired, revoked, unsigned, incorrectly signed, or stale credential request.
- `403`: the identity lacks the route ability.
- `404`: the work item is outside the identity's Site, work-type, or sensitivity boundary, or does not exist.
- `409`: stale reviewed version, idempotency conflict or the original request is still running.
- `422`: validation or lifecycle denial.
- `429`: the identity-specific per-minute limit was exceeded; respect `Retry-After`.

Authenticated request records retain the method, path, request digest, safe response snapshot, status, identity, and ticket link. They do not retain the bearer credential or raw request body. Domain actions also write the normal organisation-scoped audit trail.
