# Self-hosted Chat updates

LiveKit carries voice. Laravel Reverb carries generic Chat refresh signals; the application continues to authorise and return all messages, files, membership and call details. The browser uses a same-origin WebSocket with no hosted Pusher account, cloud fallback or stats endpoint. This is one organisation, with current user, site and conversation access checks.

## Preparation and activation

The environment and Nginx files alongside this document are examples, not installed configuration. Supply a generated public application key and a separate high-entropy secret through the deployment secret store. Keep the secret server-side. Use the same credentials in the app and Reverb, bind the server and publishing API to loopback, and allow only the actual browser hostnames. An empty origin list denies connections. The current example assumes Reverb and the application proxy share one host; distributed hosting requires a separately reviewed protected internal connection.

Supervise `php artisan reverb:start` and the Laravel queue worker. Reload their configuration and restart them after credential/configuration changes. The named `chat` broadcast connection does not change the application's default broadcaster. Queue dispatch follows commit; the current API refresh and durable message cursor remain the fallback if a wake-up is lost. Keep the application scheduler running for existing delivery and call reconciliation.

Terminate TLS at the existing application proxy. Only `/chat-live/app/` is forwarded to Reverb, with the prefix removed. Do not expose its `/apps` publishing API. The security policy permits the current origin's WebSocket only when both shared Chat and realtime flags are enabled. Verify trusted-proxy scheme/host handling on the real deployment. No domain, firewall or server changes have been made by preparing these examples.

The browser authenticates only its own private channel through the session/CSRF-protected Chat endpoint. Current eligibility is rechecked periodically, and every content API rechecks current permissions. An already subscribed revoked browser may remain physically connected until its client disconnects, but server recipient selection excludes revoked users and signals carry no care content. Client-originated broadcast events are disabled.

## Verification and limitations

On 16 October 2026 a temporary loopback Reverb process passed signed private subscription, socket-bound signature, recipient isolation, generic payload, origin rejection and client-event denial checks. Actual browser components connected through a temporary same-origin proxy, recovered 110 fictional messages, reconnected after a forced socket interruption and received a subsequent update while retaining the draft. These checks used generated temporary credentials and fictional care APIs; they do not certify a production proxy, authenticated multi-user browser flows or voice audio.

Before activation, validate Nginx syntax/TLS and the exact production origins, queue failure/restart, current-authority revocation, concurrent users, socket and directory capacity, polling load, proxy downtime and monitor/alert ownership. Additional membership/settings notification coverage and change-stream retention remain on the implementation checklist. Preserve the third-party notices. Resolve release-relevant dependency advisories against the deployed package inventory.

For rollback, disable `CHAT_REALTIME_ENABLED` in application configuration and reload it. Clients revalidate within the configured one-minute lease or upon reconnect, then fall back to current API polling. Stop the supervised Reverb process if immediate socket closure is required. Retain messages, change records and migrations. Calling has separate activation and shutdown controls.
