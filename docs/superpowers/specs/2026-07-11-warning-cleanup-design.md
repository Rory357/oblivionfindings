# Warning Cleanup Design

## Goal

Reduce the current JavaScript dependency and ESLint warning inventories to zero
without disabling the existing guardrails, forcing unrelated major dependency
upgrades, or changing product behaviour.

## Verified baseline

- `npm audit --package-lock-only` reports 7 vulnerable packages: 1 low,
  1 moderate, 3 high, and 2 critical.
- The direct vulnerable packages are `concurrently@9.2.1` and `vite@7.3.2`;
  current compatible patch releases are available within the ranges already
  declared by `package.json`.
- ESLint reports 737 warnings in 172 files: 708 `no-restricted-syntax`
  guardrail findings and 29 `react-hooks/exhaustive-deps` findings.
- The guardrail findings cover raw Tailwind colour classes, raw clickable
  buttons, and card-like panel markup. The hook findings require behavioural
  review because changing an effect dependency list can change when it runs.

## Design

### Dependency advisories

Refresh only the affected dependency graph to safe releases allowed by the
existing semver ranges. Update `package-lock.json` and change `package.json`
only if an affected direct dependency needs an explicit safe minimum. Do not
use `npm audit fix --force`, and do not bundle unrelated major upgrades.

The dependency slice is complete only when a clean install succeeds and
`npm audit --package-lock-only` reports zero vulnerabilities.

### React hook warnings

Review each of the 29 `react-hooks/exhaustive-deps` findings in context.
Stabilize callbacks or derived values with the existing React patterns where
needed, include genuine dependencies, and remove unnecessary effects when a
value can be derived directly. A narrowly scoped disable is acceptable only
for a deliberately one-shot effect whose contract is documented inline and
whose behaviour is covered by a focused test.

### UI guardrail warnings

Keep `no-restricted-syntax` enabled. Replace raw colour utilities with the
semantic tokens documented in `design_styles/DESIGN_TOKENS.md` when the semantic meaning
is known. Replace plain buttons and card-shaped panels with the shared UI kit
when those primitives preserve the existing interaction and layout.

Some bespoke controls, data visualizations, maps, floor-plan tools, and
selector surfaces intentionally require custom markup or categorical colour.
Those cases will receive a narrow, explanatory ESLint disable at the smallest
useful scope instead of a global rule suppression. This makes every remaining
exception deliberate and reviewable while preserving the guardrail for new
code.

No mobile-specific redesign is included. The cleanup preserves the existing
desktop behaviour and responsive contracts.

## Change isolation

Work is performed on `codex/complete-warning-cleanup` in the isolated
`complete-warning-cleanup` worktree. Dependency remediation, hook fixes, and
UI guardrail remediation are separate commits so regressions can be traced and
reviewed independently.

## Verification

The implementation must satisfy all of the following from a clean dependency
install:

1. `npm audit --package-lock-only` reports 0 vulnerabilities.
2. ESLint exits with 0 errors and 0 warnings; a `--max-warnings=0` gate prevents
   accidental advisory output from being treated as success.
3. `npm run types` passes.
4. `npm test` passes.
5. `npm run build` passes.
6. `npx vite build --ssr` passes.
7. `git diff --check` passes and the worktree is clean after commits.

After these local gates pass, fast-forward or merge the branch into `main`,
push it, deploy `/var/www/oblivionfindings`, and verify from the terminal that
the server is at the expected commit, the production manifest exists, and
`npm audit --package-lock-only` is clean on the deployed dependency graph.

## Failure handling

- If a compatible patch update does not clear an advisory, stop and document
  the exact dependency chain before considering a major upgrade or override.
- If a hook fix changes tests or observable behaviour, retain the original
  contract and redesign the effect rather than suppressing the failure.
- If replacing custom markup with a shared primitive changes layout or
  interaction, keep the custom control and document the intentional exception.
- Do not hide warning output through global config changes, blanket disables,
  or a reduced lint scope.

## Completion boundary

Completion means both inventories are zero, the release gates pass, the final
commit is on pushed `main`, and the staging server is aligned to that commit.
It does not include unrelated dependency modernization or UI redesign.
