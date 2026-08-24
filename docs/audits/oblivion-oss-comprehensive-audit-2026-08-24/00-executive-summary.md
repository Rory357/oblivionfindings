# Oblivion Findings comprehensive audit — current-source restart

Status: **IN PROGRESS — not comprehensive or complete**

## Outcome

The 12 August audit bundle is preserved as historical provenance, but it cannot be relabelled as a current-`main` audit. Its source commit, `081ef198f9f992f224e8c0c9fba33df33dde40be`, is not an ancestor of the current application base. A direct committed-tree comparison to `a0493442b9e392d324055c35bf25b69421dc2d35` changes 5,438 paths, including 5,434 paths outside `docs/audits/**`.

This fresh run therefore starts with a new deterministic current-source census. It grants no inherited current runtime, browser, workflow, journey, test, ease-score, benchmark-mapping, Pass-8, or completion credit.

## Pinned boundary

- Repository branch at restart: `main`
- Audit-output parent commit: `cf578b357e3662e1f6902478a5623f1f54414fb2`
- Application source commit: `a0493442b9e392d324055c35bf25b69421dc2d35`
- Application source tree: `f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1`
- Diff from application source to audit-output parent outside `docs/audits/**`: zero paths
- Superseded audit source/current merge base: `229be24b9f6d22805b29e17339c57d05eb160017`
- Governing prompt SHA-256: `4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f`
- Architecture rule: single tenant, multiple Sites; assess Site access, role/action capability, canonical ownership, direct-object concealment, and privacy boundaries—not tenant isolation.

## Current raw source census

These are deterministic path counts, not semantic feature or runtime denominators.

| Source family | Current paths |
|---|---:|
| All tracked files at the application source pin | 8,454 |
| Route PHP files | 38 |
| Inertia page-tree files | 1,058 |
| Page-tree TSX files | 1,007 |
| Shared component-tree files | 784 |
| PHP paths under a `Controllers` directory | 561 |
| PHP paths under a `Models` directory | 782 |
| PHP paths under a `Policies` directory | 75 |
| PHP paths under a `Services` directory | 725 |
| PHP paths under a `Jobs` directory | 126 |
| Event/listener paths | 14 / 12 |
| Migrations | 978 |
| `tests/` paths | 1,509 |
| Frontend test/spec paths | 203 |

The direct old-tree/current-tree change set is 2,372 additions, 2,991 modifications, and 75 deletions. Twenty-three route files and 987 page-tree files differ. Those figures require a fresh route, page, backend, data, test, visual, feature, ownership, and journey census.

## First semantic source wave

Three formal read-only assignments returned and reported no file writes. The orchestrator reconciled their current-source evidence into a partial semantic census and a grouped capability-discovery register:

- 3,217 static route declaration callsites across the 38 route files. This is a source locator, not a framework/provider runtime-route denominator.
- 963 non-test TSX files in the current Inertia resolver: 711 matched backend render roots, 227 unrendered imported paths, and 25 unrendered/unimported paths. The 25 require manual adjudication before a page denominator can be frozen.
- 62 grouped capability candidates across Clients, Care & Clinical, eMAR, Incidents & Safeguarding, HR, Workforce, Frontline Workspaces, and Operations: 54 H, eight D, and zero M candidates. This is not the final canonical feature denominator.
- Three provisional eMAR P1 source claims—`MED-RBAC-01`, `MED-CD-SCOPE-01`, and `MED-CD-ATOMICITY-01`—require independent current-source review and the matching role/Site, runtime, failure, or concurrency gates before they can become final findings.

Static backend, migration, test, and page-visual locators were also reconciled. Their directory, declaration, history, and callsite scopes remain deliberately separate and receive no execution or completion credit.

## Evidence created in this batch

- `inventory.json`: the full current committed-file census, including Git object IDs, byte sizes, and conservative path categories.
- `evidence/source/current-source-census.json`: the exact 5,438-path direct-tree reconciliation.
- `evidence/source/audit-run-manifest.json`: prompt/source pins, writer boundary, and zero-credit execution boundary.
- `evidence/source/current-static-semantic-census.json`: route, page, backend, async, migration, test, and bounded page-visual source locators with explicit denominator limits.
- `evidence/source/current-feature-discovery-wave-01.json`: the 62 grouped source-discovery candidates and provisional finding register.
- `evidence/source/formal-source-wave-01-agent-register.json`: normalized RUN-001 through RUN-003 returns, no-write attestations, and orchestrator reconciliation.
- `01-repository-module-map.md`: human-readable module/capability map for discovery wave 01.
- `audit-dashboard.html`: responsive progress dashboard generated only from current structured evidence.
- `generators/build-current-source-census.py`: the deterministic static collector. It reads committed Git objects and writes only inside this audit directory.
- `generators/integrate-source-wave-01.py`: deterministic normalization for the first formal semantic/source wave and dashboard.

The collector was rerun twice with identical output hashes. It did not boot Laravel, access a database, run tests or a build, use a browser, or mutate an external system.

## Immediate work order

1. Finish manual page/support adjudication, framework-route evidence, and canonical backend/data/test ownership; the current semantic wave is partial.
2. Continue module discovery and adjudicate the H/D/M feature universe; use the historical 904 register only as a crosswalk.
3. Reconstruct current route/page ownership, module maps, task scripts, eight journeys, visual states, and all eight pass ledgers.
4. Revalidate the 97-project benchmark register through separate observer, neutralizer, and native-comparator assignments.
5. Run browser and runtime lanes only when their environment, build identity, roles, fixtures, and non-mutation boundary are proven.
6. Freeze artifacts, dispatch fresh independent Pass 8 review, integrate only through the orchestrator, validate all literal completion gates, and update the dashboard from current structured evidence.

No remediation is authorised or implemented by this audit run.
