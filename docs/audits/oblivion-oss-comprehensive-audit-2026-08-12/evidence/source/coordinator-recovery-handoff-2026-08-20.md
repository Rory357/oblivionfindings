# Coordinator recovery handoff — 20 August 2026

Generated from live Git, worktree, task and process inspection on 2026-08-20. This is coordination evidence only; it does not award audit, runtime, benchmark, visual or remediation-completion credit.

## Canonical repositories

- Audit checkout: `<local-user>/Herd\oblivionfindings`, branch `codex/backup-local-plans-2026-08-10`, audited source `081ef198f9f992f224e8c0c9fba33df33dde40be`.
- Clean integration worktree: `<local-user>/.config\superpowers\worktrees\oblivionfindings\codex-ticketing-main-integration`.
- Current integrated `main` and `origin/main`: `9b420035007a86dcb2f45805f959817c3448884f`.
- Integrated in order: AUTH email verification, TASK-WATCH-002, FIN-GL-REVERSAL-01 including signed GST correction, VIS-EMAR-CLINICAL-LEAD-MOBILE-OVERFLOW-01, SITE-CHECK-002.

## Live execution wave

- Sole heavy lane: `MED-ORDER-ERASURE-01` in worktree `7e90`.
- Coordinator proved `HEAD = origin/main = merge-base = 9b420035...`, no incoming paths, and supplied two global process snapshots four seconds apart with `BLOCKED_COUNT=0`.
- Read-only Terra xhigh reviews running in parallel:
  - `FIN-PAYMENT-ALLOCATION-01` against worktree `9c49`.
  - `PRIV-DSR-LIFECYCLE-01` against worktree `982b`.
- Next serialized PHP lane: `INTEG-WEBHOOK-001`, then `HS-ASSURANCE-01`, then `INCIDENT-ALERT-LIFECYCLE-01` unless an independent review or exact overlap requires reordering.
- No other task may infer PHP, Composer, Pest, PHPUnit, Artisan, Wayfinder, npm, Vite, TypeScript or build authority from an idle process tree.

## Protected active worktrees

The following worktrees contain source that must not be reclaimed, pruned, cleaned, removed or reused until their task explicitly releases it:

`2913`, `4f81`, `5536`, `5fe2`, `6fa5`, `6970`, `7365`, `797b`, `7e90`, `819e`, `8b4e`, `982b`, `9c49`, `b11a`, `d564`, `e230`, `f310`.

Special recovery notes:

- `9159` / `RESP-STATE-01`: published branch `origin/codex/resp-state-01` exists at `c0ca6249...`, but the bounded post-review correction worktree was reclaimed and must be reconstructed from task history before any new verification.
- `8b4e` / `SAFE-TERMINAL-SYNC-01`: source exists at a detached HEAD and must be put on the intended protected branch before publication work.
- Local WIP commits/stashes are protection checkpoints, not completion or merge evidence.

## Exact overlap families

- Finance: `PaymentMatchController.php`, `PaymentMatchingService.php`, finance permission seeders and matching tests overlap FIN-PAYMENT-MATCH, FIN-EFTPOS and FIN-PAYMENT-ALLOCATION. Review and integrate sequentially.
- Safeguarding: concern controller/model/policy/dialog/routes overlap SAFE-TERMINAL, SAFE-SENSITIVITY and SAFE-EVID. Review and integrate sequentially.
- Medication: `EmarController.php` overlaps MED-ORDER and MED-RBAC. MED-ORDER must rebase after any MED-RBAC integration decision.
- H&S/Incident: `IncidentControllerTest.php` and existing Control Room/H&S E2E journeys overlap HS-ASSURANCE and INCIDENT-ALERT-LIFECYCLE. Final integration order requires a SOL architecture review.

## Audit checkpoint

- Capability register: `902`.
- Materialized benchmark artifact currently reports `422 / 902` decided and `480` unproved, but the independent evidence-quality packet requires two Wave-8 NCM withdrawals. Conservative pending-regeneration projection: `420 / 902` decided and `482` unproved.
- Visual final-ID ownership: `8,151 / 8,753`, `602` unresolved.
- Material-state ownership: `3,934 / 4,312`, `378` unresolved.
- Surface mapping: `2,985 / 3,024` routes and `945 / 962` pages accepted.
- Substantive project triage: `97 / 97` complete. Dashboard state is now derived from numerator/denominator so 100% renders teal with a complete label.
- Audit-wide executable test gate: `0 / 867`; representative task execution: `0 / 788`; modules with all eight passes: `0 / 25`.
- The audit remains blocked/not complete. Focused remediation suites do not change those denominators.

## Goal and operating boundary

- The app's formal goal remains the original comprehensive evidence-backed audit and is recorded as `usageLimited`; the goal API does not permit changing its objective while unfinished.
- The approved working plan extends that goal with recovery, protected remediation coordination, dashboard correction, serialized verification and dependency-safe integration.
- Exactly one heavy PHP/MySQL lane at a time. Every authorized failure stops the lane, cleans the exact schema/processes, and requires a fresh task-specific grant.
- Independent reviewers are read-only. Remediation tasks do not edit audit artifacts. The coordinator alone owns dashboard, handoff and sequential integration updates.
