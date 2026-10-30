# Additional audit round 1 — workflow completeness

Baseline: request-to-assessment/allocation/approval/check/checkout/departure/return/completion completed in the browser. Completion appeared beside the trip summary, above the fold. Original baseline screenshots retained.

Findings corrected:
- Equipment confirmation used a shared string default. It now starts unchecked and transition guards require explicit booleans. Partial returns retain an exception.
- Passenger selection could retain another site. Changing passenger/site now resets dependent support fields and enforces the matching context at submission.
- Pending bookings had a confirmed-plan heading. Proposed/rejected/confirmed labels and the independent/not-required approval route are now explicit.
- Departure remained on the booking screen. Successful driver departure now opens the actual new journey.
- Empty overview had blank queue cards. It now explains the scope/filter result.
- Date validation accepted impossible calendar dates. Dates now validate their calendar components.

Verification: normal-flow replay plus round-1 browser probes and source-transition invariant checks. This is synthetic interaction evidence, not API/integration coverage.
