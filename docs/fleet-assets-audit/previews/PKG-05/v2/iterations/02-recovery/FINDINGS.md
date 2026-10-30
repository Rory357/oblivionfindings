# Additional audit round 2 — permissions, custody and recovery

Coverage includes changed-payload retry, denied linked sources, per-file upload recovery, role boundaries and original handover retention.

Gaps corrected:
- Partial return had no later receiving path. Added an explicit named-receiver receipt for outstanding items, linked to but never replacing the original receipt.
- Reconciliation could be asserted before missing items were received. It now requires actual receipt evidence, retains the original dispute, and closes only the assigned exception.
- A reconciled dispute remained permanently counted as outstanding. Work counts now derive from actual missing items, pending acknowledgement and open exceptions; historical disputes remain visible.
- Unexpected-site return observations had no direct owned recovery. The form can now retain those observations as a separate exception without claiming a valid return or changing the journey.

These are synthetic source handoffs. Exact durable reconciliation/receipt adapter contracts require a separately approved implementation. No approval or custody authority is invented for the real application.
