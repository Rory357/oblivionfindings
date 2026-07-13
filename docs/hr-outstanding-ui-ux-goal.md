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

### E-signature tenancy and requester outcomes

- RED — `ESignatureAuthorizationTest`: foreign-tenant document request expected 404 but returned 302; 1 failed, 3 pre-existing `.env` warnings, 8 pending, 5 assertions, 218.77s.
- RED — `ESignatureRequestTest` notification filter: `SignatureOutcomeNotification` expected once but sent zero times; 1 failed, 2 pending, 2 assertions, 257.57s.
- RED — signer-side tenant filter: the foreign-tenant signature ID remained in the signer's pending collection; 1 failed, 10 assertions, 229.75s.
- GREEN — complete `ESignatureAuthorizationTest` + `ESignatureRequestTest`: **13 passed / 51 assertions**, 323.59s. This proves request/sender/signer tenant boundaries, same-tenant sign/decline notices, self-notification suppression, repeat-transition idempotency, existing signer download, and existing manager request behavior.

### HR frontend clarity and specialised heroes

- RED — `hr-outstanding-ui-ux.test.tsx`: **4 failed / 4** because signature dates were raw, approval item/date/empty-state contracts were absent, Wellbeing undo lacked accessible boundary copy, and the three specialised hero components did not exist.
- GREEN — focused Vitest source contracts: **4 passed / 4**, 192ms after correcting one quote-only assertion and one Prettier line-break assertion.
- Wellbeing server characterization tests were written before the UI change to prove actor-scoped undo and foreign-tenant rejection. They remain pending execution in the aggregate PHP bundle because the continuation sandbox stopped exposing the installed Herd PHP executable after the e-signature GREEN run.

## Verification log

- PHP syntax for the initial e-signature controller/service/notification implementation: 3 files clean. PHP syntax/Pint for later controller/test edits remains pending with the same sandbox runtime boundary.
- TypeScript first failed only on absent ignored Wayfinder modules. Temporary ignored `routes` + `wayfinder` output was copied read-only from the live root into this worktree because the sandbox denied `php artisan wayfinder:generate`; the next full `tsc --noEmit --pretty false` run exited 0.
- Scoped Prettier check over the new hero/test files and modern changed pages: exit 0. Full-file formatting was intentionally not retained for legacy Approvals/Wellbeing because it created thousands of unrelated lines of churn; only the changed hunks remain.
- Scoped ESLint over all 11 changed/new frontend files with `--max-warnings 0`: exit 0, no output.
- Client build with a temporary local Vite config that omits only the PHP-spawning Wayfinder plugin: **4,947 modules transformed**, exit 0, 2m53s. The standard config was attempted first and failed before transformation solely because sandboxed Node could not spawn `php artisan wayfinder:generate --with-form`.
- SSR build with the same temporary config: **1,599 modules transformed**, exit 0, 14.13s.
- Focused Vitest contract: 4/4 green. `git diff --check`: exit 0.
- No completion claim is made yet. Focused/aggregate PHP, standard Wayfinder-backed builds when the runtime is available, browser proof, final diff review, ledger closeout, process cleanup, and clean committed status remain required.

## Continuation verification — 2026-07-13

- A fresh scoped ESLint run over all changed/new frontend files completed with exit 0 and zero warnings. A fresh full `tsc --noEmit` completed with exit 0 after the hero and clarity work.
- The client and SSR production bundles recorded in this section transformed **4,947 client modules** and **1,599 SSR modules**, both exit 0.
- Local SSR rendered the real compiled Analytics page and exposed the specialised `Workforce analytics` hero without the removed duplicate KPI row. A synthetic HR harness was prepared for desktop-web inspection.
- Browser acceptance could not execute under the continuation permission profile: direct Playwright reached the installed Chromium binary but Windows returned `spawn EPERM`; the mandatory in-app browser fallback then failed because its launcher could not access `C:\Users\steph\AppData`. No escalation was requested. This is a tooling boundary, not browser acceptance evidence; no browser-complete claim is made.
- The same permission change prevents access to the installed Herd PHP executable, so the new service-invariant assertion and the Approvals/Wellbeing aggregate PHP tests could not be rerun. The last genuine backend GREEN remains the e-signature **13 tests / 51 assertions** run above; the later service-invariant test is source-reviewed but unexecuted.
- A later Vitest rerun was also prevented when esbuild could no longer resolve the worktree config across the newly restricted parent-directory boundary. The earlier focused GREEN remained the last genuine frontend contract result until permissions resumed.
- Temporary Vite/Vitest/browser harness files were removed. The preview, SSR, browser, and helper processes started by this task were stopped; ports 4179 and 13714 had no listeners at cleanup.
- Final source review found no Client Profile changes, shared primitive edits, package-lock changes, generated-route churn, ownership migration, Wellbeing-to-Control-Room path, deployment code, or production operation.

## Honest remaining boundary

The implementation is complete in the working tree, but the branch cannot yet be called integration-ready until the managed environment again permits (1) the focused/aggregate PHP and scoped Pint/Wayfinder gates, (2) real desktop-web browser acceptance, and (3) writes to this worktree's Git metadata for staging and commits. These are exact verification/packaging boundaries; previously completed HR release work and approved one-owner architecture decisions remain closed.

## Permission-resumed final verification — 2026-07-13

The managed permission boundary was lifted and the prior blocker was fully retired. Verification resumed from the same `codex/hr-outstanding-ui-ux` worktree without touching the root checkout.

- PHP syntax: all 8 changed backend/test files passed `php -l`.
- Scoped Pint: initial check identified style-only fixes in changed files; scoped formatting was applied and the final `--test` run passed.
- Wayfinder: `php artisan wayfinder:generate --with-form` completed successfully with no tracked generated-route churn.
- Post-format focused HR aggregate: **38 tests / 300 assertions**, exit 0, 278.55s. This single final run covers e-signature authorization/outcomes, approvals ownership/tenant seams, Wellbeing actor/tenant/confidentiality behavior, and Analytics/Headcount/Succession server props/actions.
- Frontend contracts were rerun during the permission-resumed pass; the final desktop-only count is recorded in the closeout below.
- Scoped Prettier: all selected modern changed files matched. Legacy Approvals/Wellbeing whole-file debt remained deliberately untouched; every changed frontend file passed zero-warning ESLint.
- Full TypeScript: `npm run types`, exit 0 after the final source change.
- Corrected desktop-only frontend contracts: **2 files / 10 tests**, exit 0 after all mobile/WebView-specific work was removed.
- Standard desktop client build with Wayfinder: **4,947 modules**, exit 0, final corrected build 3m07s.
- Standard desktop SSR build with Wayfinder: **1,599 modules**, exit 0, final corrected build 47.37s.
- A later user scope correction confirmed this is a desktop-web-only application. Mobile-card branches, mobile touch-target changes, their tests, and their acceptance claims were removed before commit. The desktop clarity, date, hero, authorization, notification, and accessibility work remains.
- Desktop browser GREEN: Playwright against the exact corrected compiled SSR pages passed **5 desktop surfaces** at 1440×1000. It proved the specialised Analytics/Headcount/Succession heroes, friendly approval labels, en-NZ approval/signature dates, and no horizontal overflow, console errors, or page errors.
- Visual inspection: Analytics, Approvals, and Signatures desktop screenshots were reviewed. The hero hierarchy, tables, labels, dates, and actions rendered as intended. Chart bodies in the synthetic SSR harness are intentionally unhydrated; chart behavior is not relabelled as browser-verified.
- Cleanup: the temporary SSR/browser harness files were removed and every preview, SSR, Playwright, and helper process started by this task was stopped.
- Final ownership review: native approval queues remain canonical; HR/governance reviews remain separate; H&S acknowledgements remain H&S-owned/read-only in HR; confidential Wellbeing remains outside Control Room. No Client Profile, package-lock, shared primitive, deployment, production, or root-checkout change exists.

This later section supersedes the temporary blocker record above. The implementation and required local verification are complete; commit hashes and final clean-state proof are recorded in the branch handoff after logical commits are created.

## Implementation commits

- `c21cb7d1` — `fix(hr): secure e-signature workflows`
- `69791b18` — `feat(hr): clarify desktop workflows and insights`
- The documentation closeout is the commit containing this section.
