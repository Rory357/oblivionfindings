# Stuck Device command approval

## Trigger

Use this runbook when a command remains `awaiting_approval`, `awaiting_step_up`, or `awaiting_change` and the required action is time-sensitive. Commands are deliberately short-lived; an expired request is not extended or edited in place.

## Diagnose the exact prerequisite

1. Open the exact Device **Management** tab and review **Command history**.
2. Read **Next action**, expiry, requester, Site, expected state, and any linked IT Change visible to your current role.
3. Confirm the state:
    - `awaiting_step_up`: the requester must complete current password confirmation and resume the same request flow before expiry.
    - `awaiting_approval`: a different current reviewer needs `securityDevices.commands.approve`, access to the exact Site and Device, the source-domain permission, and the required management/sensitivity permission.
    - `awaiting_change`: the linked IT Change must be approved, include the exact Device and Site, and have a current maintenance window that both requester and reviewer may inspect.
4. Confirm the Device still has fresh state and the same canonical assignment. Changed Site, ownership, privacy, provider capability, or risk policy invalidates the old approval path.

## Recovery

- Ask an eligible independent reviewer to use **Review** on the retained request. The requester cannot self-approve.
- Correct role or Site assignment only through the normal access-governance process. Do not grant a temporary global role merely to clear one request.
- If the request expires, is rejected, or its signed conditions change, preserve it as evidence and create a new request after the underlying issue is resolved.
- Do not alter request parameters, Device, Site, reviewer decision, signature, or expiry in the database. Approval records are immutable.
- Break glass is not an approval workaround. It is available only for explicitly allowed capabilities, current command administrators with MFA and fresh step-up, and a named independent post-use reviewer.

## Escalation and closure

Escalate missing reviewer coverage to the Security & Devices owner and access-governance owner. Escalate an unavailable change window to the IT change owner. Close with the command UUID, Site, waiting state and duration, exact prerequisite corrected, reviewer or change reference, final decision, and the command **Export audit evidence** file. Never include passwords, approval narratives, credential material, or raw provider data in the ticket.
