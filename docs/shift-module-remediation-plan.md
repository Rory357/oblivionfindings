## Shift Module Remediation Plan

### Goal

Turn the shift stack into a supported-living operations engine instead of a single-client appointment workflow.

### Delivery Order

#### Phase 1: Stabilize live workflows

Purpose: remove the most misleading and unsafe operational behaviour without waiting for the full data-model redesign.

- Harden shift lifecycle rules.
  - Do not allow a shift to complete before it has actually started.
  - Stop backfilling fake start times during completion.
  - Create or update draft timesheets from completed shifts consistently.
- Make attendance safer.
  - Decouple clock access from `timesheets.create` only.
  - Stop silently auto-matching the first shift in a broad window.
  - Require explicit shift choice when multiple assigned shifts could match.
- Replace note-based handover capture with structured shift handovers.
  - Store handover records in `shift_handovers`.
  - Record who acknowledged the handover.
  - Show structured handovers in the shift workflow.
- Repair open-shift / job-board flow.
  - Align controller, schema, and UI around `required_skills`, `notes`, `claimed_by`, `approved_by`, and `user_id`.
  - Add search, status filters, approval, and assignment wiring.

#### Phase 2: Unify operational capture

Purpose: make the shift workspace the place where frontline work is actually executed.

- Merge fragmented note paths into one canonical shift documentation flow.
- Embed medication/admin tasks, forms, transport, and incidents directly into the shift workspace.
- Make handover, incidents, incomplete tasks, and exceptions visible in one shift timeline.
- Add manager exception review for missed documentation, missed meds, and incomplete tasks.

#### Phase 3: Fix rostering and recurrence

Purpose: make scheduling behave like supported-living operations instead of simple calendar cloning.

- Support recurring open shifts and role-based demand, not only recurring assigned shifts.
- Add recurrence edits for one occurrence, future occurrences, and date range.
- Add roster exceptions, pauses, and template application rules.
- Use real assignment constraints:
  - availability
  - site fit
  - qualifications
  - medication competence
  - fatigue / overlap rules
  - client compatibility
  - travel distance

#### Phase 4: Rebuild the core data model

Purpose: move from single-client appointments to service coverage.

- Introduce a first-class shift occurrence / staffing-slot model.
- Support:
  - site or house coverage shifts
  - multiple assigned staff
  - multiple participating clients
  - sleepover and on-call attributes
  - role and staffing ratio requirements
- Replace blanket client-overlap validation with policy-driven overlap rules.

#### Phase 5: Close the operational loop

Purpose: make the module manager-ready, auditor-ready, and payroll-ready.

- Unify attendance, HR time entries, and operations timesheets behind one authoritative time ledger.
- Repair payroll export against the real timesheet schema and approval flow.
- Expand reporting with:
  - coverage gaps
  - open-shift ageing
  - missed handovers
  - missed meds/admins
  - exception approvals
  - scheduled vs actual variance
- Strengthen audit evidence for reassignments, overrides, approvals, and backdated edits.

### Architectural Guardrails

- Do not add more feature flags or UI surfaces that bypass the shift workflow.
- Do not keep parallel note, handover, or timekeeping sources of truth.
- Prefer backward-compatible fallbacks for permission checks while normalising on the newer operation-specific permissions.
- Ship each phase with focused tests before moving to the next phase.
