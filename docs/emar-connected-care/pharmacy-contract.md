# Connected pharmacy ordering contract

This is a single-organisation application. Current staff permissions, approved houses, canonical person/medicine ownership, and person privacy govern all browser requests. No pharmacy endpoint is supplied by a browser. Partners are approved in server deployment configuration. The `oblivion-json-v1` transport is a generic bridge requiring a real partner agreement; it does not claim Toniq compatibility. Default configuration is disabled with no partners.

## JSON routes for the existing Stock and Settings hubs

All browser responses have `Cache-Control: private, no-store`. Success responses include `success: true`. Domain errors have `{success:false,code,message}`. Standard permission/site/person denials remain 403/404; standard input validation is 422.

The Settings GET renders Inertia `emar/pharmacy/index` with these props for a normal browser request; `Accept: application/json` returns the JSON contract below.

| Method and route | Request | Successful response |
| --- | --- | --- |
| GET `/emar/pharmacy-connections` | none | `{enabled,installed,connections,partners,sites,can_manage,unavailable_reason}` |
| POST `/emar/pharmacy-connections` | `{name,partner_key,site_ids,enabled}` | 201 `{connection}` |
| PUT `/emar/pharmacy-connections/{connection}` | same plus `expected_version` | 200 `{connection}` |
| GET `/emar/stock/pharmacy-orders/{order}/connection` | none | `{enabled,connections,dispatch,can_send,can_retry,can_cancel,can_resolve_unknown,local_order_closed,notice}` |
| POST `/emar/stock/pharmacy-orders/{order}/dispatch` | `{connection_id,request_uuid}` | 202 `{dispatch,duplicate}` |
| POST `/emar/stock/pharmacy-orders/{order}/dispatch/{dispatch}/retry` | `{request_uuid,expected_state}` | 202 `{dispatch,duplicate}` |
| POST `/emar/stock/pharmacy-orders/{order}/dispatch/{dispatch}/cancel` | `{request_uuid,expected_state}` | 200 `{dispatch,duplicate}` |
| POST `/emar/stock/pharmacy-orders/{order}/dispatch/{dispatch}/resolve` | `{request_uuid,expected_state:"unknown",confirmed_not_received:true,reference}` | 200 `{dispatch,duplicate}` |

`connection` is `{id,name,partner_key,site_ids,enabled,version,protocol_label}`. `partners` are `{key,label,protocol_label,site_ids}`; no destination URL, account mapping, credentials, or credential-presence details are returned. `sites` are `{id,name}`.

`dispatch` is null or `{id,uuid,connection_id,order_id,state,label,result_code,attempt_count,queued_at,sending_at,sent_at,acknowledged_at,supplier_reference,acknowledgment_outcome,acknowledgment_applied,acknowledgment_code,vendor_cancellation_available:false}`. It never contains the encrypted person/medicine snapshot or snapshot hash. Poll the order JSON endpoint during queued/sending/sent/unknown states.

Permissions: Settings management requires `medications.view`, `medications.settings.manage`, and `medications.pharmacy.connect.manage`. Send/retry/cancel/resolve requires `medications.view`, `medications.stock.update`, `medications.pharmacy.send`, current employment, current house access, and per-person medication access. Controlled medication also requires both `medications.controlled.view` and `medications.controlled.record`; direct identifiers otherwise return 404.

The Settings reader also admits the dedicated pharmacy connection manager without general Settings management, as a read-only workspace (`can_manage:false`). The connected-services directory `/emar/connections` requires a verified staff session, medication view, and at least one exact connected-service management capability (or orders management for discovery). It contains no clinical record props.

## Delivery truth and actions

| State | Meaning and UI action |
| --- | --- |
| `queued` | Waiting to send; Stop before sending is allowed. No contact evidence exists yet. |
| `sending` | A worker has claimed this send. Do not offer another send or supplier cancellation. |
| `sent` | The configured endpoint returned 2xx. Await an authenticated pharmacy response; this does not prove acceptance. |
| `accepted` | Signed pharmacy evidence accepted this exact dispatched snapshot. Physical stock still needs a counted receipt. |
| `rejected` | Signed pharmacy evidence declined this snapshot. Preserve it and create a corrected new supply order when appropriate. |
| `failed` | A definitive non-delivery or a recorded pharmacy check confirms no receipt. Retry only if enabled by current authority and the partner’s agreed idempotency support. |
| `unknown` | Timeout, redirect, ambiguous HTTP result, worker crash, or commit uncertainty. Contact the pharmacy; do not resend automatically. |
| `cancelled` | Delivery was stopped before any outbound request, or authorization/snapshot was revoked during preflight. Create a newly checked supply order if needed. |

The unknown-resolution form must explicitly say “I checked with the pharmacy and they confirmed they did not receive this order” and require the call/secure-message reference. Saving that check changes the dispatch to `failed`; it never sends automatically and never records pharmacy acceptance. A signed later acknowledgment remains authoritative if it contradicts that manual check. Retry preserves the original dispatch UUID as the partner idempotency key.

The Stop action only stops `queued`/`failed` dispatches. Local supply cancellation/close-short remains available under existing rules and does not cancel an order already at the pharmacy. Show “This closes our supply record. Contact the pharmacy separately to cancel their order.” when external delivery is sending/sent/accepted/unknown. Physical receipt and dispensing evidence remain under existing Stock/CD workflows. Content changes are frozen while an external order is in flight. A timeout cannot be relabeled as manual contact without first resolving its delivery.

The HTTP 2xx result records secure-message contact evidence and changes draft→submitted. A signed accepted receipt may record submitted→confirmed, including timeout recovery with proven contact; it never dispenses or receives stock. Delayed acknowledgments after a person/medicine/connection change are retained as supplier evidence with `acknowledgment_applied:false`; they do not change clinical supply status. Later dispensing/receipt states never regress.

## Error codes

409: `dispatch_exists`, `idempotency_conflict`, `dispatch_changed`, `supply_not_sendable`, `retry_not_safe`, `supplier_cancellation_required`, `resolution_required`, `partner_binding_immutable`, `connection_changed`, `acknowledgment_conflict`, `acknowledgment_not_expected`.

422: `connection_disabled`, `installation_required`, `partner_not_ready`, `destination_not_approved`, `site_not_approved`, `person_identifier_missing`, `stock_unit_missing`, `snapshot_changed`, `action_invalid`, `acknowledgment_invalid`. 401: `acknowledgment_unauthenticated`.

## Partner protocol and activation requirements

POST to the exact deployment-approved HTTPS endpoint, with TLS verification, fresh public-DNS checks, DNS pinning, redirects and proxies disabled, a bounded timeout, and no automatic HTTP retries. `Authorization: Bearer <deployment secret>` is a header only. `Idempotency-Key` is the stable dispatch UUID; `X-Pharmacy-Protocol` is `oblivion-json-v1`.

The JSON contains `protocol`, `dispatch_uuid`, the approved `site_reference`, `person:{nhi,name,date_of_birth}`, `medicine:{name,dosage,route,frequency,nzulm_code,controlled}`, and `supply:{pharmacy_name,quantity,unit,needed_by,notes}`. Identity and the counted stock unit must exist before send. Secrets are never in payloads, browser responses, or audit events. Response bodies are never read or retained. Only the partner’s explicitly agreed `definitive_failure_statuses` can classify a non-2xx response as a safe failure; 408/409/425/429 and 5xx always remain unknown.

The partner sends POST `/api/emar/pharmacy-connections/{connection}/acknowledgments` with raw JSON `{event_id,dispatch_uuid,outcome:"accepted"|"rejected",supplier_reference}`. Headers: `X-Pharmacy-Timestamp` is Unix seconds; `X-Pharmacy-Signature` is `v1=` followed by lower-case hex HMAC-SHA256 of `<timestamp>.<numeric connection ID>.<exact raw JSON>` using the separate deployment acknowledgment secret. Timestamps must be within five minutes. Event IDs are durable and unique per connection; identical repeats return `{received:true,duplicate:true,applied,code}`, changed repeats return 409. The signature does not use a browser login or CSRF token. Final responses cannot reverse accepted/rejected outcomes.

Production activation still needs the actual supplier/API documentation, an agreed payload/recipient and NHI mapping, the approved endpoint and house accounts, idempotency guarantees, definitive refusal status semantics, acknowledgment authentication/provisioning, and an operational pharmacy check/cancellation process. Add those only through reviewed deployment configuration. This change does not provision credentials, call a real supplier, or migrate a production database.

Root integration: include `routes/emar-pharmacy-connect.php`; register the two exact permissions without widening baseline roles; schedule `app(PharmacyDispatchService::class)->recover()` every minute with overlap protection. Recovery pumps orphaned queued work and marks expired sending claims unknown; it never retries unknown deliveries.
