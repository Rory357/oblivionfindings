# PKG-02A — Locate source-review observations

Owner MAIN ASTRA. Revision 1, 2026-09-21. **Read-only technical findings for the next concrete contract; no next-feature application-write release.** Existing approval of v3 and sole Astra Extra High ownership remains settled. Main's r2/r3 review releases the narrow realtime test-fixture correction separately.

Main independently read the following current 2b9f sources while Designer prepared the Locate contract:

- CommandAssignmentFingerprint binds assignment ID, target, type and assigned_at; it does not bind consent, collection or client care evidence.
- DeviceManagementAuthorizationService uses general assignmentAuthorisesClient consent and the authorised_client_care audience label. That label alone does not run the exact client-profile care relationship gate.
- GovernedCommandDispatchService rechecks generic device/capability/current-assignment governance. Its assertDispatchable accepts Ready/Queued, and applyPreconditionFailure ignores other statuses.
- FrameRouter::claimQueuedCommand locks QueclinkDevice and QueclinkPendingCommand, checks queue/expiry/serial/transmission provenance, then calls GovernedCommandLifecycleService::markSent. It does not perform the new client's exact profile/privacy check. markSent advances linked Accepted records to Running. FrameRouter returns outbound bytes after that transaction to the socket-writing caller.
- CommandStatus does not permit Accepted/Running → Blocked. A delivery-time denial must coordinate a legal terminal outcome for request, attempt and pending command plus audit. Returning early through the existing pre-dispatch failure method would leave these records unresolved.

The Designer's initial server-HMAC namespace proposal inside the existing signed idempotency key is a proposal, not approved architecture. The contract must compare it briefly with explicit immutable signed context on the existing canonical request. Neither requires a duplicate command lifecycle. If using the namespace, specify canonical versioned encoding, length bound, trusted construction and reserved-prefix rejection, exact actor/client/device/assignment/consent/collection binding, stable retry identity after step-up, changed-payload conflicts, signature/HMAC checks at delivery and backward compatibility for ordinary commands. Missing or malformed client context cannot silently fall back to generic authorization.

The contract must identify exact routes/files and the lock order through creation, queue, worker, provider enqueue and delivery. In particular, introducing consent/Device/Client/care locks inside the existing provider-first lock chain must not introduce an inverse acquisition order. Tests must exercise both orders of revocation versus the atomic authorization/delivery-claim decision, with current locking reads where required. This establishes a bounded authorization decision; it must not be described as recall of bytes already authorized for socket delivery.

Retain canonical capability/reason/step-up/expiry/idempotency, current status semantics and separately measured location evidence. ACK is not a new location. Protect request and status disclosure with current exact client/site/actor/assignment consent checks and stale-response cancellation. No real socket, collector, queue worker or provider transmission is permitted during synthetic verification. Shared services require their affected existing regressions and compatibility checks.

Main sent these concrete observations to the same verified Designer turn01a0c139-dbaa-7912-bfc8-445340f903e1. The next step is review of its concrete contract, not another user approval of the already approved design. No new operating/privacy policy has been proposed or accepted.
