# Failed or uncertain Device command

## Trigger and safety rule

Use this runbook when a command is `failed`, `uncertain`, `mismatch`, or remains in execution after its expiry. **Never repeat an uncertain or side-effecting command merely because no success response arrived.** An accepted provider request, an interrupted connection, or an issued collector configuration may already have changed the Device.

Oblivion Findings is one application across many Sites. Work from the exact Device, Site, command UUID, and execution route; never introduce a tenant scope or copy a command to another Site.

## Triage in the application

1. Open **Security & Devices**, choose the Device category, open the exact Device, then select **Management**.
2. In **Command history**, record the status, expected state, execution route, latest safe attempt result, latest reconciliation, expiry, and next action. Do not copy request narratives into a general ticket.
3. Distinguish the route:
    - **Oblivion central runtime**: check the `monitoring-commands` worker and provider health.
    - **Remote Site collector**: check collector heartbeat, configuration sequence, backlog age, contiguous acknowledgement, and Site path. Follow [Collector outage and revocation](../monitoring/collector-outage-and-revocation.md) when the collector is unavailable.
    - **Provider rejected or rate-limited**: follow [Provider outage](../monitoring/provider-outage.md); do not add an application retry loop.
4. Confirm whether a fresh observation exists after the attempt. A provider acknowledgement is not proof that the expected Device state occurred.

## Recovery decision

- `failed` before a provider accepted the action: correct the recorded cause. Create a new signed request only if the action is still required and all current governance checks pass.
- `uncertain`: obtain fresh state from native monitoring, the same typed provider, or an authorised on-Site check. Do not dispatch a duplicate while state is unknown.
- `mismatch`: treat the observed state as authoritative, assess safety and service impact, and open or link the IT incident/change required for remediation.
- collector configuration issued but no ordered result returned: leave the attempt uncertain until its original result returns or independent fresh state establishes the outcome. Never resequence or discard buffered collector evidence.
- expiry before collector configuration was issued: the request is expired and did not enter the collector execution contract. Reconfirm need, current state, and approvals before creating a new request.

## Safe runtime checks

```bash
supervisorctl status oblivion-monitoring-commands:*
php artisan queue:monitor monitoring-commands --max=100 --json
php artisan queue:failed
```

These checks do not authorise replay. A failed Laravel job is evidence to investigate; a side-effecting Device command is never blindly retried from `queue:retry`.

## Closure evidence

From the command row select **Export audit evidence**. Store the governed JSON with the linked IT incident or change under its normal access controls. Record the Device, Site, command UUID, route, final attempt state, fresh observed state, reconciliation outcome, incident/change reference, and recovery time. The export intentionally excludes narratives, credentials, signatures, raw provider payloads, and collector identity material.
