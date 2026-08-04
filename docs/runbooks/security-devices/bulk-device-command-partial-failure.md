# Bulk Device command partial failure

## Trigger and interpretation

Use this runbook when a bulk Device management review contains exclusions, failed children, uncertain children, reconciliation mismatches, or a mixture of outcomes. A bulk result is never converted into blanket success: every included Device has its own signed request, approval, attempt, and reconciliation.

## Triage

1. Open **Security & Devices**, select the relevant category and **Management**, then open the retained bulk review.
2. Confirm the workspace, capability, requester, exact target count, Sites, impact, prerequisites, expected state, and partial-result rule.
3. Separate rows into:
    - excluded before request creation;
    - awaiting step-up, approval, or change;
    - queued or executing;
    - reconciled and matched;
    - failed before a confirmed side effect;
    - uncertain or mismatched after possible execution.
4. Use each row's Device link to inspect its own command history. Never infer one Device's outcome from another Device in the batch.

## Recovery

- Leave successful, reconciled children closed. Do not repeat them while addressing other rows.
- Correct an exclusion or governance prerequisite, then create a new request only for the still-required visible Devices.
- Follow [Failed or uncertain Device command](failed-or-uncertain-device-command.md) independently for every uncertain or mismatched child.
- For a provider rate limit or outage, contain the affected provider and Site without stopping unrelated Sites or providers.
- If any target becomes inaccessible, the retained batch and export are concealed rather than returning a partial list that leaks target identity or count. Restore legitimate access through governance; do not query around the boundary.
- Bulk break glass is unavailable. An emergency action must use an individually governed capability only where policy explicitly permits it.

## Export and closure

Select **Download result ledger** on the bulk review. The CSV is protected against spreadsheet formula injection and contains only currently authorised safe result fields. For detailed immutable evidence, select **Export audit evidence** on each affected child command.

Close with batch UUID, workspace, Sites, included/excluded counts, outcome counts, affected child command UUIDs, corrections, fresh reconciliation evidence, and incident/change references. Do not attach credentials, request narratives, private tracking, clinical detail, CCTV/media, raw provider data, or hidden target counts.
