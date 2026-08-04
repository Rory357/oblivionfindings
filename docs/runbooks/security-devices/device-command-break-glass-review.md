# Device command break-glass review

## Purpose and boundary

Every break-glass Device command requires one permanent post-use review by the different command administrator named when the emergency request was declared. Review becomes available only after an execution attempt completes. It does not change or erase the execution evidence.

Break glass never bypasses Site, Device, source-domain, privacy, capability, current-state, configured MFA, fresh identity confirmation, expiry, signature, or reconciliation controls. Capabilities whose policy forbids break glass remain unavailable.

## Reviewer procedure

1. Open the break-glass notification and confirm it resolves to the exact Device **Management** history. Do not use a copied direct link if your Site or source-domain access has changed.
2. Verify the requester, Device, Site, capability, declaration time, reviewer notification time, expiry, execution route, expected state, attempt result, and fresh reconciliation evidence.
3. Review the emergency reason only inside the governed Device surface. Do not paste it into chat, email, a general ticket, or the audit export.
4. Select **Complete post-use review** and choose exactly one outcome:
    - **Confirmed appropriate**: the emergency use was necessary, proportionate, and followed policy.
    - **Follow-up required**: the use was legitimate but needs a control, training, process, or technical follow-up.
    - **Incident required**: suspected misuse, an unauthorised outcome, missing evidence, or material safety/security impact requires the incident process.
5. Enter a factual review summary of 20-1000 characters. Do not include credentials, clinical detail, private location history, raw media, or provider payloads.

## Exceptions

- If the command is still running or has no completed attempt, do not fabricate a review; follow [Failed or uncertain Device command](failed-or-uncertain-device-command.md).
- If the named reviewer lost Site, source-domain, or command-admin access, preserve the overdue review and escalate to the Security & Devices owner. Do not change the signed reviewer binding in the database.
- If the outcome is **Incident required**, create the appropriate IT, security, privacy, safeguarding, or health-and-safety record and retain only the minimum necessary cross-module link.

## Closure evidence

Confirm the history shows the reviewer, outcome, reviewed time, and no remaining review-due count for that command. Select **Export audit evidence** and retain the governed JSON with the incident/change when applicable. The export records the immutable review event and deliberately omits the emergency and review narratives.
