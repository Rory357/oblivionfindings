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
- End SHA: `19892c89a66d5670d004ffd93ea56f6e0635455c`
- Files changed: `docs/client-hr-live-gap-closeout.md`
- Tests: worktree, branch, clean baseline, dependency links, and testing environment verified before feature edits.
- Live URLs: none
- Smoke marker and record IDs: none
- Cleanup: not applicable
- Commit SHA: `19892c89a66d5670d004ffd93ea56f6e0635455c`
- Remaining: Slices 1-4, aggregate gates, integration, push, deployment, scheduled-interval observation, and single-session Chrome proof.

### Slice 1 — Client tab canonicalisation

- Start SHA: `19892c89a66d5670d004ffd93ea56f6e0635455c`
- End SHA: pending
- Ownership decision: legacy aliases stay in `_groups.ts`; the canonical care-plan workspace remains `care_plans`.
- Files changed: `resources/js/pages/operations/clients/tabs/_groups.ts`, `resources/js/test/client-profile-navigation.test.tsx`, `tests/e2e/operations-client-profile-phase-1.spec.ts`, and this ledger.
- Root cause: the alias canonicaliser called the browser History API directly, bypassing Inertia's history-state ownership. The visible URL changed, but Inertia navigation state was not updated safely.
- Fix: route alias `replace`/`push` operations through the supported Inertia router while retaining the full query and preserving scroll/state.
- Red proof: with the prior direct-History implementation restored temporarily, the focused Vitest run reported **1 failed / 13 passed** because the legacy alias made zero `router.replace` calls.
- Green tests and exact counts: focused Vitest **14 passed**; targeted Chromium desktop alias scenario **1 passed**; Prettier check passed for all 3 changed TS/TSX files; ESLint passed with zero warnings; `npm run types` passed; production client build passed (`4943` modules transformed).
- Browser URLs: local `/operations/clients/{id}?tab=support_plan&dialog=quick_note&record=99&source=legacy` canonicalised to the same URL with `tab=care_plans`; Back returned to the previous dashboard URL, Forward restored the canonical URL and open dialog, reload retained the canonical deep link, and the recent-client link used `tab=care_plans`.
- Console/network evidence: the targeted Playwright case asserted zero console errors and zero `>=400` responses for the target client route.
- Full-file classification: **1 passed / 2 failed**. The new alias case passed. Both pre-existing note-capture cases could not find the permission-gated quick-note action after canonical global setup failed on the existing duplicate `EMP0003` unique key in `SystemUsersSeeder`; the same two failures reproduced with reseeding skipped. This is a local fixture/seeder blocker, not an alias regression.
- Smoke marker and record IDs: exact local synthetic clients `5-38`, alternating `Playwright Profile` and `Recent Playwright Client`.
- Cleanup: deleted exactly client IDs `5-38` from the local testing database through model deletion; verified `remaining=0` for that ID set.
- Commit SHA: pending
- Remaining: post-deployment Chrome acceptance and exact slice commit SHA recording.

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
