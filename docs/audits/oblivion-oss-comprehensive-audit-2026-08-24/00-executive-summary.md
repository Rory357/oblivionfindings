# Oblivion Findings current-source restart toward the comprehensive audit

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
- 963 non-test TSX files in the current Inertia resolver: 711 matched backend render roots, 227 unrendered imported paths, and 25 unrendered/unimported paths. That first partition required the RUN-010 manual adjudication recorded below before a source denominator could be accepted.
- 62 grouped capability candidates across Clients, Care & Clinical, eMAR, Incidents & Safeguarding, HR, Workforce, Frontline Workspaces, and Operations: 54 H, eight D, and zero M candidates. This is not the final canonical feature denominator.
- Three provisional eMAR P1 source claims—`MED-RBAC-01`, `MED-CD-SCOPE-01`, and `MED-CD-ATOMICITY-01`—require independent current-source review and the matching role/Site, runtime, failure, or concurrency gates before they can become final findings.

Static backend, migration, test, and page-visual locators were also reconciled. Their directory, declaration, history, and callsite scopes remain deliberately separate and receive no execution or completion credit.

## Second formal source wave

RUN-004 through RUN-006 also returned read-only and reported no file writes. They add 110 grouped candidates across Finance, Governance, Health & Safety, Privacy, Safeguarding, Complaints & Feedback, Sites & Locations, Fleet & Assets, Security Devices, IT & Support, Integrations, Control Room, and Public & Settings Platform: 91 H, 18 D, and one bounded-negative M candidate.

The two discovery waves now contain 172 unique grouped rows: 145 H, 26 D, and one M. This remains a partial discovery register, not a frozen feature denominator. The protected-disclosure M row records that no dedicated owner was found in the bounded static search; it is not proof that no external or undocumented process exists.

Nine additional P1 source claims were retained provisionally, bringing the current provisional register to 12. The new claims cover governance meeting/resolution visibility, board-pack distribution, quorum/decision snapshots, H&S register Site scope, privacy report permission separation, safeguarding intake provenance, safeguarding alert identity and projection durability, and outbound webhook destination safety. None is a final finding, verified exploit, remediated issue, or closed gate.

## First current benchmark wave

Three separate no-write agents completed the bounded observer, neutralizer, and native-comparator roles, bringing the fresh run to nine reconciled formal assignments at that stage. RUN-010 and the corrected independent RUN-011 page reconciliation below bring the current total to 11. The prompt's numerical minimum of eight and the planned current-run target of 11 are met, but fresh Pass 8 reviewers, all-results-represented proof, and the final no-live-agent gate remain open.

The historical project register remains 98 physical rows: 97 projects in the prompt denominator plus supplemental `frappe/frappe`. All 98 rows passed committed-local structural checks, but zero of the 97 prompt projects was refreshed against current upstream activity, ref reachability, licence, edition boundary, or behaviour on 24 August 2026. The historical rows are provenance, not current project-triage credit.

RUN-007 returned 30 provisional observer relations across 29 of the 172 grouped candidates. RUN-008 challenged 15 samples—five semantic/UI collisions failed, five retained only a partial neutral requirement, and five survived a narrow static requirement—and identified nine collision/composite groups. RUN-009 completed eight high-risk comparison packets: zero copied-baseline classifications, two stronger-native-control observations, four Oblivion-specific gaps, and two domains with no credible comparison at the packet level.

None of those slices selects a final current benchmark or establishes a feature-level `No credible match`. The candidate denominator is not frozen, current upstream evidence is absent, and zero current mappings are promoted. No runtime, application-browser, test, compliance, remediation, or completion credit follows.

## Static Inertia page adjudication

RUN-010 reproduced the 963-path resolver partition and classified every one of the 25 previously unrendered/unimported TSX candidates: 20 are alias/generated/legacy paths superseded by canonical routes or surfaces, three are dead/unreachable candidates, two are debug/starter demo paths, and zero is a current page root. It separately reconciled all 11 backend render literals with no matching file: four occur in retired unreachable methods and seven in unrouted stubs.

The accepted **committed-source file-backed Inertia page-root denominator is 711**. Of the 252 non-roots, 227 are imported support/components and 25 are the adjudicated candidates. All 963 resolver TSX paths are partitioned for static render/import identity, and the 25-candidate sublane has no unresolved row. The 11 missing render literals remain backend liabilities with zero page or runtime credit. Final prompt classification of the 711 roots and reachability remain open.

An independent reconciliation could not reproduce RUN-010's reported global enumeration of 3,392 JS/TS source files, so that count is quarantined and supports no denominator or credit. Two training-record stubs also retain exact nearest-current-surface anchors without claiming an established replacement owner.

RUN-011 initially returned NO-GO on five bounded evidence defects, including the unreproduced source count and over-broad wording. After correction, the same independent no-write reviewer returned GO across 54 checks. This closes only the bounded static file identity question and brings the fresh run to 11 reconciled formal no-write assignments. Framework-expanded route reachability, build resolution, route/page-to-feature mapping, signed-in application observation, deployment identity, and release behavior remain unproved; no runtime, build, test, database, network, or application-browser work occurred.

## Current official New Zealand source baseline

The six official-source families required by the governing prompt were refreshed from current official pages: the Health Information Privacy Code 2020 including its 1 May 2026 indirect-collection amendment, HISO 10029:2022, the NZ FHIR Base Implementation Guide, Ngā Paerewa NZS 8134:2021, the Code of Health and Disability Services Consumers' Rights, and WorkSafe's HSWA guidance. The structured evidence separates each official source fact from the audit inference and the qualified specialist decision still required.

The official HISO PDF asset was identified, but direct retrieval returned 403, so no complete HISO control mapping is claimed. WorkSafe's page records passed amendments that take effect on 1 April 2027; they are tracked as future-effective and are not treated as current law at the 24 August 2026 audit date. No legal, clinical, certification, interoperability, security, or compliance credit is awarded from this source baseline alone.

## Evidence created in this batch

- `inventory.json`: the full current committed-file census, including Git object IDs, byte sizes, and conservative path categories.
- `evidence/source/current-source-census.json`: the exact 5,438-path direct-tree reconciliation.
- `evidence/source/audit-run-manifest.json`: prompt/source pins, writer boundary, and zero-credit execution boundary.
- `evidence/source/current-static-semantic-census.json`: route, page, backend, async, migration, test, and bounded page-visual source locators with explicit denominator limits.
- `evidence/source/current-feature-discovery-wave-01.json`: the 62 grouped source-discovery candidates and provisional finding register.
- `evidence/source/formal-source-wave-01-agent-register.json`: normalized RUN-001 through RUN-003 returns, no-write attestations, and orchestrator reconciliation.
- `evidence/source/current-feature-discovery-wave-02.json`: 110 additional grouped source-discovery candidates and nine provisional finding rows.
- `evidence/source/formal-source-wave-02-agent-register.json`: normalized RUN-004 through RUN-006 returns and the six-assignment snapshot toward the planned target of 11.
- `evidence/source/current-page-adjudication-wave-01.json`: all 25 page/support candidates, all 11 missing render targets, exact classifications and ownership anchors, and the bounded 711-page source denominator.
- `evidence/source/current-page-agent-register.json`: normalized RUN-010 and corrected RUN-011 returns, no-write attestations, initial NO-GO traceability, replacement GO, and the cumulative 11-assignment state.
- `evidence/official-sources/nz-source-baseline-2026-08-24.json`: current official NZ source facts, audit inferences, specialist decisions, and explicit completion limits.
- `evidence/benchmark/current-benchmark-wave-01.json`: normalized RUN-007 through RUN-009 project-register, observer, neutralizer, collision, comparator, and zero-credit evidence.
- `evidence/benchmark/current-benchmark-agent-register.json`: reconciled benchmark-agent returns, no-write attestations, the corrected RUN-009 follow-up, and the cumulative 9-assignment state.
- `01-repository-module-map.md`: human-readable module/capability map for discovery wave 01.
- `02-repository-module-map-wave-02.md`: human-readable module/capability map for discovery wave 02.
- `03-feature-to-benchmark-matrix.csv`: interim 172-row grouped-candidate matrix with all unfinished route/page, benchmark, ease, and P1–P8 cells labelled explicitly and zero completion credit.
- `06-open-source-benchmark-register.csv`: the 98 historical rows carried forward with an explicit 97-plus-one denominator and zero current upstream refresh or mapping credit.
- `audit-dashboard.html`: responsive progress dashboard generated only from current structured evidence.
- `generators/build-current-source-census.py`: the deterministic static collector. It reads committed Git objects and writes only inside this audit directory.
- `generators/integrate-source-wave-01.py`: deterministic normalization for the first formal semantic/source wave and dashboard.
- `generators/integrate-source-wave-02.py`: deterministic normalization for the second formal source wave.
- `generators/integrate-benchmark-wave-01.py`: deterministic normalization for the first current benchmark wave and interim required CSVs.
- `generators/integrate-page-adjudication-wave-01.py`: deterministic normalization for the RUN-010 page/support and missing-render adjudication.
- `generators/build-current-audit-dashboard.py`: aggregate dashboard builder over the normalized current evidence.

The collector was rerun twice with identical output hashes. It did not boot Laravel, access a database, run tests or a build, use a browser, or mutate an external system.

## Immediate work order

1. Preserve the 711-file static page-root denominator and finish framework-route reachability, route/page-to-feature mapping, and canonical backend/data/test ownership; the semantic/runtime graph remains partial.
2. Continue module discovery and adjudicate the H/D/M feature universe; use the historical 904 register only as a crosswalk.
3. Reconstruct current route/page ownership, module maps, task scripts, eight journeys, visual states, and all eight pass ledgers.
4. Refresh all 97 prompt projects from official upstream evidence and finish one verified benchmark or documented `No credible match` for every feature after the canonical denominator is frozen; the first A/B/C evidence wave grants zero mapping credit.
5. Run browser and runtime lanes only when their environment, build identity, roles, fixtures, and non-mutation boundary are proven.
6. Freeze artifacts, dispatch fresh independent Pass 8 review, integrate only through the orchestrator, validate all literal completion gates, and update the dashboard from current structured evidence.

No remediation is authorised or implemented by this audit run.
