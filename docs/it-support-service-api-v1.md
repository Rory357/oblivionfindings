# IT & Support service API v1

The service API lets an approved system create and follow IT work without sharing a human session or personal access token. An IT manager creates each identity under **IT & Support → Setup → API identities**, chooses its execution account, operations, work types, sites, fields, expiry, request limit, and signature policy, then copies the credential shown once.

## Authentication and safe storage

Send the one-time credential as a bearer token:

```http
Authorization: Bearer ofi_<public-id>_<secret>
Accept: application/json
```

The application stores only a SHA-256 digest of the high-entropy secret. It cannot display or recover the credential after the creation response. Revoke and replace an identity if its credential is lost or exposed.

Every `POST` also requires a unique `Idempotency-Key` between 8 and 100 characters. Repeating the same method, path, body, identity, and key returns the original response with `X-Idempotent-Replay: true`. Reusing the key for a different body returns `409 idempotency_conflict`.

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

The baseline response contains only the work ID/reference, title, type, status, workflow state, priority, safe site/service/asset context, and timestamps. Description, routing/ownership, SLA, and resolution fields appear only when the identity has the corresponding read field. Internal comments, sensitive tickets, raw device configuration, credentials, clinical readings, tracking/media data, and command capability are never returned by this API.

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
  "to": "in_progress",
  "reason": "Recovery is being verified.",
  "next_action": "Observe for ten minutes."
}
```

Waiting transitions require `waiting_party` and `reason`. Settlement transitions require the same approvals, required tasks, resolution code, and resolution summary as the agent UI. Invalid lifecycle moves return `422 transition_denied`; the API cannot bypass canonical workflow gates.

## Response and error rules

- `201`: work item or public comment created.
- `200`: read or transition completed.
- `400`: missing/invalid idempotency key.
- `401`: invalid, expired, revoked, unsigned, incorrectly signed, or stale credential request.
- `403`: the identity lacks the route ability.
- `404`: the work item is outside the identity's Site, work-type, or sensitivity boundary, or does not exist.
- `409`: idempotency conflict or the original request is still running.
- `422`: validation or lifecycle denial.
- `429`: the identity-specific per-minute limit was exceeded; respect `Retry-After`.

Authenticated request records retain the method, path, request digest, safe response snapshot, status, identity, and ticket link. They do not retain the bearer credential or raw request body. Domain actions also write the normal organisation-scoped audit trail.
