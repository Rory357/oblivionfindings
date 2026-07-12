# HR Outstanding UI/UX Goal Ledger

> Append-only execution record for the autonomous HR production-readiness task.

## Goal

Audit and complete the genuinely deferred or outstanding HR production-readiness and UI/UX work, verify it end to end, and leave a clean committed branch ready for the parent integration task.

## Safety baseline — 2026-07-13

- Worktree: `C:\Users\steph\.codex\worktrees\43ca\oblivionfindings`
- Branch: `codex/hr-outstanding-ui-ux`
- Required base: `4d3948c1fdf7a95cda36a17023a082a85c35f0f2`
- Verified initial `HEAD`: `4d3948c1fdf7a95cda36a17023a082a85c35f0f2`
- Verified initial `origin/main`: `4d3948c1fdf7a95cda36a17023a082a85c35f0f2`
- Initial status: clean, detached before creating the task-local branch.
- Normal root checkout was observed only through `git worktree list`; it was not edited, staged, reset, cleaned, stashed, or otherwise touched.
- Prohibited throughout: merge, push, deploy, production access/write, migration/seeding, SSH, PR, other-checkout edits, Client Profile scope, generated route/package-lock churn.

## Canonical audit disposition

| Candidate | Current evidence | Disposition |
|---|---|---|
| Wellbeing clarity and undo | Service scopes undo to `staff_user_id + actor_user_id`; controller checks manage permission and tenant. No explicit undo regression exists and the toast does not explain the boundary or expose live-region semantics. | Implement tests and presentation clarity only; retain confidentiality and no Control Room path. |
| Generic approvals clarity | Native queues are correctly federated with owning links. Generic rows still render raw class names and raw timestamps; the two independent bare empty rows create a weak all-empty experience. | Implement presenter/date/empty-state clarity; retain native engines and generic-chain ownership. |
| E-signature requester outcomes | Signer request/reminder notices exist. Sign/decline requester notices do not. Request and sender-side action endpoints validate global IDs without proving HR-tenant ownership. | Treat tenancy as a proven P0/P1 authorization gap; implement guards and privacy-minimal same-tenant outcome notices. |
| Analytics hero | Existing generic hero repeats Headcount/Turnover/Tenure/Compliance in the immediately following KPI grid. | Add specialised HR hero and remove duplicate KPI grid only. |
| Headcount hero | Existing generic hero repeats Headcount/FTE/Vacancies/Attrition risk in the immediately following KPI grid. | Add specialised HR hero and remove duplicate KPI grid only. |
| Succession hero | Existing stats/actions are useful but remain inline generic hero markup. | Extract a specialised HR hero without duplicating or changing the workspace. |
| Approved architecture boundaries | D-7, D-10, D-11 and D-1 native queue ownership are guarded in current source/tests. | Retain; no implementation. |
| Prior combined live closeout | `docs/client-hr-live-gap-closeout.md` records complete production proof and cleanup. | Historical evidence only; do not reopen or relabel. |

## TDD evidence log

No functional implementation has started. RED and GREEN commands, outputs, counts, and commit hashes will be appended per slice.

## Verification log

No completion claim has been made. Aggregate terminal and browser evidence will be appended after implementation.
