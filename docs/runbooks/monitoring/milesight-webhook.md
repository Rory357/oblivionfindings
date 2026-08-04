# Milesight signed webhook operations

## Purpose and boundary

Use this runbook to connect Milesight Development Platform device-data events to Oblivion Findings native Monitoring. The callback verifies the Milesight signature, resolves an existing canonical Device to exactly one active Site, verifies that the Milesight application is mapped to that Site, and stages a signed event through the common monitoring outbox. It does not probe the device from the web request and does not retain the raw provider payload.

This is a single-application deployment. Access is controlled by roles, permissions, canonical Site access, Device ownership and direct-object denial.

## Prerequisites

- The Oblivion Findings callback URL is publicly reachable over HTTPS with a valid certificate.
- The Milesight OAuth connection has passed **Test connection**.
- Each required Milesight application is mapped to one active Site.
- Each reporting Milesight device has been synced into the canonical Device registry and resolves to exactly one active Site through its current assignment.
- The mapped application ID on the Device matches the active Site mapping.
- The configured replay store is Redis and the monitoring event worker is supervised.
- The server clock is synchronized. Signed requests outside the 60-second verification window are rejected.

## Enable and verify

1. Open **Security & Devices > Integrations > Milesight**.
2. In **Real-time monitoring webhook**, copy the read-only callback URL.
3. In the same Milesight Development Platform application used for the OAuth inventory connection, enable Webhook and add that callback URL.
4. Obtain the Milesight application webhook secret. Back in Oblivion Findings, paste it into **Webhook secret** and choose **Enable webhook**. This secret is separate from the OAuth client secret.
5. Use Milesight's **Test** action. A valid Webhook Test callback is authenticated, acknowledged with HTTP 200 and ignored for event projection; it must not create a false Device event.
6. Generate or wait for a real mapped Device Data event. Confirm that **Last verified Device event** advances, the event enters native Monitoring, and any configured alert/ticket policy behaves as expected.

Accepted Device Data batches are acknowledged with HTTP 200. A batch is limited to 100 events and is staged transactionally before acknowledgement.

## Expected security behavior

- The four required headers are `x-msc-request-signature`, `x-msc-webhook-uuid`, `x-msc-request-timestamp`, and `x-msc-request-nonce`.
- The signature is HMAC-SHA256 over the exact timestamp plus nonce using the separate webhook secret.
- A nonce can be accepted once. Redis retains the replay reservation for the configured replay window.
- Missing, ambiguous, inactive or cross-Site Device/application mappings fail closed before any event is staged.
- Only bounded normalized sensor values enter Monitoring. Secret-like keys, oversized bodies, excessive nesting, duplicate event identities and batches over 100 are rejected.
- The secret is encrypted at rest. The UI exposes only configured state and the last four characters.

Never paste the OAuth client secret or webhook secret into a ticket, chat, screenshot, log, export or test fixture. Do not copy a raw webhook body into operational records.

## Troubleshooting

| Result | Meaning | Operator action |
| --- | --- | --- |
| `200` | Authenticated test/device callback accepted, or an already-staged event safely acknowledged | For a Test event, send a real mapped Device Data event before expecting **Last verified Device event** to advance. |
| `401` | Missing/invalid signature, expired timestamp or replayed nonce | Confirm the webhook secret belongs to the same Milesight application, check clock synchronization and use a new Milesight Test request. Rotate if compromise is suspected. |
| `422` | Invalid or unsafe payload, unknown Device, ambiguous Site ownership, or application/Site mapping mismatch | Sync inventory, inspect the canonical Device assignment and correct the application mapping. Do not bypass the mapping check. |
| `503` | Redis replay protection is unavailable | Restore Redis and verify the configured replay store. Intake intentionally fails closed. |
| `500` | Event staging failed | Check the value-free operational error category, database health, outbox health and supervised monitoring workers. Do not log the request body or secret. |

If a valid callback receives `200` but no projected event appears, inspect the signed monitoring outbox, event consumer checkpoint and dead-letter workspace using the existing monitoring delivery runbook. A Milesight Webhook Test is intentionally not projected.

## Rotate or disable

Rotate during a controlled window because only the current secret is accepted:

1. Rotate or reveal the new webhook secret in the same Milesight application.
2. Immediately replace the secret in **Security & Devices > Integrations > Milesight**.
3. Run Milesight **Test**, then confirm a real Device Data event.
4. Review rejected-callback counts for unexpected use of the old secret.

To disable intake, first remove the callback URI or disable Webhook in Milesight, then choose **Disable webhook** in Oblivion Findings. Disabling webhook verification does not remove the OAuth inventory connection or previously synced Devices.
