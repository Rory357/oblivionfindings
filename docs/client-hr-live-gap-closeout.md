# Client Profile and HR Live Gap Closeout

**Status:** Active
**Started:** 2026-07-12 (Pacific/Auckland)
**Branch:** `codex/client-hr-live-gap-closeout`
**Worktree:** `C:\Users\steph\Herd\oblivionfindings-client-hr-live-gap-closeout`
**Starting revision:** `b16e934d72fa8bafa0a86882c4772f6b20a56289`
**Starting `origin/main`:** `b16e934d72fa8bafa0a86882c4772f6b20a56289`

## Safety and ownership decisions

- The dirty detached root checkout is out of scope and must remain untouched.
- The dedicated `main` worktree is clean but currently carries five unpushed Fleet commits ending at `ab7f3636`; those commits must be preserved and reconciled before the final explicit merge.
- Client tab aliases remain owned by `resources/js/pages/operations/clients/tabs/_groups.ts`.
- Care & Support Plan remains owned by the canonical `care_plans` workspace.
- HR team ownership is not yet decided. Record the source audit and canonical owner here before editing Slice 2.
- Durable synthetic HR data must extend the existing HR demo seeding path. Transient live lifecycle fixtures use one unique marker and must be removed.
- Control Room escalation remains owned by `AutoEscalateControlRoomQueues`.

## Slice ledger

### Startup

- Start SHA: `b16e934d72fa8bafa0a86882c4772f6b20a56289`
- End SHA: pending
- Files changed: `docs/client-hr-live-gap-closeout.md`
- Tests: not started
- Live URLs: none
- Smoke marker and record IDs: none
- Cleanup: not applicable
- Commit SHA: pending
- Remaining: Slices 1-4, aggregate gates, integration, push, deployment, scheduled-interval observation, and single-session Chrome proof.

### Slice 1 — Client tab canonicalisation

- Start SHA: pending
- End SHA: pending
- Ownership decision: legacy aliases stay in `_groups.ts`; the canonical care-plan workspace remains `care_plans`.
- Files changed: pending
- Red proof: pending
- Green tests and exact counts: pending
- Browser URLs: pending
- Console/network evidence: pending
- Commit SHA: pending
- Remaining: all acceptance rows.

### Slice 2 — HR team ownership and Calendar configuration

- Start SHA: pending
- End SHA: pending
- Ownership decision: pending source audit before edits
- Files changed: pending
- Red proof: pending
- Green tests and exact counts: pending
- Browser URLs: pending
- Smoke marker and record IDs: pending
- Cleanup: pending
- Commit SHA: pending
- Remaining: all acceptance rows.

### Slice 3 — Fixture-gated HR browser matrix

- Start SHA: pending
- End SHA: pending
- Ownership decision: exercise canonical HR lifecycle workflows and existing demo ownership; do not create parallel implementations.
- Files changed: pending
- Baseline counts: pending
- Smoke marker and record IDs: pending
- Mail-driver safety: pending
- Browser URLs and results: pending
- Cleanup and final counts: pending
- Commit SHA: pending
- Remaining: all acceptance rows.

### Slice 4 — Control Room scheduled escalation

- Start SHA: pending
- End SHA: pending
- Ownership decision: keep escalation in `AutoEscalateControlRoomQueues`; apply only required callback capture changes.
- Files changed: pending
- Red proof: pending
- Green tests and exact counts: pending
- Scheduled-interval server evidence: pending
- Commit SHA: pending
- Remaining: all acceptance rows.

## Release gates

- Focused and aggregate tests: pending
- PHP syntax: pending
- Pint: pending
- Prettier: pending
- ESLint zero-warning: pending
- Wayfinder: pending
- TypeScript: pending
- Client build: pending
- SSR build: pending
- `git diff --check`: pending

## Integration and deployment

- Latest upstream reconciliation: pending
- Feature commit(s): pending
- Merge commit: pending
- Local `main`: pending
- `origin/main`: pending
- `git ls-remote`: pending
- Deployed SHA: pending
- Server cleanliness, migrations, manifest, queue, login and logs: pending

## Final acceptance status

- Client browser row: Partial — exact post-deployment Chrome evidence not yet captured.
- HR L1: Partial — exact fixture-backed post-deployment Chrome evidence not yet captured.
- Control Room scheduled interval: Partial — deployed interval not yet observed.

