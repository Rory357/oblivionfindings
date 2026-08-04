# Native Queclink TCP listener

## Scope and safety boundary

Use this runbook for the supported `gv500cg` vehicle-tracker and `gl30m` personal-tracker families that connect directly to Oblivion Findings. This is native TCP intake, not a Queclink cloud API integration. Do not configure, test, or claim a cloud endpoint, cloud sync, cloud health, or cloud event capability.

Oblivion Findings is one application across many Sites. Every paired tracker must resolve to one canonical Device and one authorised Site. Never introduce a tenant selector or move a provider identity between Sites to bypass access checks.

Never put an IMEI, public hostname, IP address, device password, credential value, lease identifier, raw frame, raw device command, acknowledgement payload, or protected configuration in a terminal transcript, ticket, screenshot, or evidence export. The authenticated provisioning-readiness response must contain only a readiness state, family label, and safe instructions; it never returns the hostname, credential, or device command.

## Deployment and status

Run these commands from the application release directory. The first two checks are read-only:

```bash
php artisan queclink:install --check
php artisan queclink:status --json
```

`queclink:status --json` includes the configured public hostname for an authorised operator. Do not copy its complete output into an incident or release record; retain only the service state, port, bounded Device counts, frame count, and last-frame time.

The normal approved Linux/systemd deployment path is:

```bash
sudo -E php artisan queclink:install
systemctl is-active oblivion-queclink.service
journalctl -u oblivion-queclink --since "-15 min" --no-pager
```

The installer writes and restarts `oblivion-queclink.service`, running `php artisan queclink:listen` on the configured TCP port. Re-running it after an application or port change is supported. Use `--no-firewall` only when the local firewall is managed separately. The installer does not replace the approved perimeter firewall, NAT, source restriction, or change record.

On a non-systemd host, supervise the command printed by the installer rather than leaving an interactive shell responsible for production intake:

```bash
php artisan queclink:listen --port=<approved-port>
```

The supported settings are `QUECLINK_LISTENER_PORT` and `QUECLINK_PUBLIC_HOSTNAME`, overridden in the application by `queclink.listener.port` and `queclink.public_hostname`. Record only whether each setting is present and approved, never its value.

## Protected provisioning readiness

1. Open `/security-devices/integrations/queclink` with `securityDevices.integrations.manage` permission.
2. Confirm the listener reports active and the approved port is correct.
3. Select **Provisioning readiness** for the exact supported family. The authenticated route is `/security-devices/integrations/queclink/provisioning?family=gv500cg` or `family=gl30m`.
4. Require `ready_for_secure_provisioning` and the expected `vehicle_tracker` or `personal_tracker` family label. Stop if the response contains any provider target, hostname, credential, or command content.
5. Retrieve and apply the protected server configuration only through the approved secure device-management process. Do not derive it from this runbook, browser tools, logs, or a copied command.
6. Wait for the real tracker to connect. Do not create a synthetic heartbeat or location to make readiness appear current.

## Pairing and authentic observation

1. In **Security & Devices > APIs & Integrations > Queclink**, confirm the genuine tracker appears in **Pending** after its first authentic frame.
2. Select the intended vehicle, staff member, or client. Client and personal-tracking assignment requires the applicable current consent and privacy policy.
3. Claim the tracker. The workflow creates or reuses the canonical Device, resolves its Site from the canonical assignment, and records the audited provider link. Stop on an ambiguous identity, missing Site, duplicate active assignment, or direct-object denial.
4. Confirm the tracker is **Paired**, connection state is current, and the bounded last-frame time advances after an authentic heartbeat.
5. Wait for an authentic location report. Verify it through the canonical Device Profile and the applicable `/security-devices/tracking` workspace. Treat absent, stale, privacy-blocked, or out-of-window evidence as unknown—not healthy and not proof of command completion.

## Governed Device Management lifecycle

The Queclink workspace preserves protected legacy/provider-console history, but cannot retry it. Start every new or repeated action from:

```text
/security-devices/devices/<device-id>?section=management
```

1. Review the canonical Device, Site, current observation, supported capability, reason, risk and required IT Change or approval.
2. Create a fresh governed request for `tracking.location_refresh`, `configuration.refresh`, `configuration.apply`, or `device.reboot`. Availability requires an active Site-scoped `queclink` credential reference with purpose `device_management` and the matching `command:<capability>` lease capability.
3. Complete step-up, approval and dispatch in Device Management. The application obtains a one-use lease, erases and releases it after command construction, and serialises native tracker work.
4. Do not treat provider acceptance or an acknowledgement as completion. Require the capability-specific fresh observation: a governed location, protected configuration readback, matching profile state, or a new listener session after restart.
5. Close only when Device Management records the expected reconciliation outcome and immutable audit evidence. Follow [Failed or uncertain Device command](../security-devices/failed-or-uncertain-device-command.md) for failed, expired, uncertain, or mismatched work.

## Listener outage, backlog and ordered recovery

Use safe checks first:

```bash
systemctl is-active oblivion-queclink.service
php artisan queclink:install --check
php artisan queclink:status --json
supervisorctl status oblivion-monitoring-commands:*
php artisan queue:monitor monitoring-commands --max=100 --json
```

Preserve listener journals, protected frame history, governed requests, pending-command state, reconciliation records, queue evidence and audit records. Do not delete rows, edit timestamps, clone a pending command, replay a side-effecting queue job, or mark a tracker healthy manually.

After correcting the approved network, port, runtime or dependency fault, recover only the affected service:

```bash
sudo systemctl restart oblivion-queclink.service
systemctl is-active oblivion-queclink.service
php artisan queclink:status --json
```

If the unit or port contract changed, run the approved `sudo -E php artisan queclink:install` path instead of hand-editing the unit. Allow the tracker to reconnect and accept authentic buffered reports in their original order. Let the scheduled governed lifecycle expire requests whose evidence window elapsed; it must not repeat their action. Reconcile fresh state, then create a new governed request only when the action is still required and all current checks pass.

## Credential rotation, revocation and re-pairing

For routine rotation or suspected compromise, follow [Credential compromise containment and rotation](../security-devices/credential-compromise-containment-and-rotation.md). Rotate the external secret first, rotate the exact Site-scoped reference in **Settings & audit**, keep it suspended until **Test reference** passes, and verify containment without entering secret material:

```bash
php artisan security-devices:verify-credential-containment <site-id> '<reference-key>' --require-active
```

Expected safe result: `Credential containment verified` and no outstanding prior leases. Confirm a newly approved Device Management request can acquire the replacement reference; never validate rotation by exposing or copying a device command.

For a lost, replaced, wrongly assigned, or deliberately re-enrolled tracker:

1. Stop new governed work and reconcile any in-flight request.
2. **Release** the paired tracker to remove its active canonical Device/assignment link and return it to **Pending**. Use **Reject** for a tracker that must not be claimed; **Restore** moves an authorised rejected tracker back to Pending.
3. Revoke or rotate the affected Site credential reference when compromise or device-password exposure is possible.
4. Complete protected provisioning readiness again, wait for an authentic reconnect, and claim the tracker to the intended canonical Device/Site target. Never reuse a stale browser object or copy the former assignment.
5. Confirm one active assignment, current consent where required, a fresh heartbeat/location, and no duplicate canonical Device before restoring service.

## Closure evidence

Record the release version, approved change/incident, Site and canonical Device references, supported family, service state, approved port, start/stop/recovery times, bounded connected/pending/frame counts, first authentic heartbeat/location times, governed command UUID, final reconciliation outcome, credential-reference version/test state, and pairing audit IDs.

Do not attach complete status JSON, listener journals, browser network payloads, raw history, or screenshots containing identifiers or endpoint settings. Close with explicit confirmation that no cloud API was used, no raw command was replayed, no duplicate Device was created, and Site/privacy boundaries remained intact.
