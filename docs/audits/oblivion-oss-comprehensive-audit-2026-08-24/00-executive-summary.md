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
- 62 grouped capability candidates across Clients, Care & Clinical, eMAR, Incidents & Safeguarding, HR, Workforce, Frontline Workspaces, and Operations: 54 H, eight D, and zero M candidates. At that stage, this was not the final canonical feature denominator.
- Three provisional eMAR P1 source claims—`MED-RBAC-01`, `MED-CD-SCOPE-01`, and `MED-CD-ATOMICITY-01`—require independent current-source review and the matching role/Site, runtime, failure, or concurrency gates before they can become final findings.

Static backend, migration, test, and page-visual locators were also reconciled. Their directory, declaration, history, and callsite scopes remain deliberately separate and receive no execution or completion credit.

## Second formal source wave

RUN-004 through RUN-006 also returned read-only and reported no file writes. They add 110 grouped candidates across Finance, Governance, Health & Safety, Privacy, Safeguarding, Complaints & Feedback, Sites & Locations, Fleet & Assets, Security Devices, IT & Support, Integrations, Control Room, and Public & Settings Platform: 91 H, 18 D, and one bounded-negative M candidate.

At that stage, the two discovery waves contained 172 unique grouped rows: 145 H, 26 D, and one M. This was a partial discovery register, not a frozen feature denominator. The protected-disclosure M row recorded that no dedicated owner was found in the bounded static search; it was not proof that no external or undocumented process exists.

Nine additional P1 source claims were retained provisionally, bringing the current provisional register to 12. The new claims cover governance meeting/resolution visibility, board-pack distribution, quorum/decision snapshots, H&S register Site scope, privacy report permission separation, safeguarding intake provenance, safeguarding alert identity and projection durability, and outbound webhook destination safety. None is a final finding, verified exploit, remediated issue, or closed gate.

## First current benchmark wave

Three separate no-write agents completed the bounded observer, neutralizer, and native-comparator roles, bringing the fresh run to nine reconciled formal assignments at that stage. RUN-010 through RUN-016 brought the RUN-016 snapshot to 16. Later RUN-017 through RUN-030 canonical-identity evidence and RUN-031 through RUN-034 upstream-project observer evidence are recorded below. The prompt's numerical minimum of eight and the planned current-run target of 11 are met, but assignment count is not completion: full triage/execution gates, fresh Pass 8 reviewers, all-results-represented proof, and the final no-live-agent gate remain open.

Literal reconciliation corrected the project denominator. The prompt contains 98 GitHub URL occurrences representing 95 unique repositories because `glpi-project/glpi`, `netbox-community/netbox`, and `opf/openproject` each appear in two categories. The 98-row physical carry-forward register contains those 95 exact repositories plus three historical extras: `Bahmni/openmrs-module-ipd`, `medplum/medplum-provider`, and supplemental observer project `frappe/frappe`. RUN-015 and the root collector obtained authenticated read-only official GitHub repository metadata for 95/95 unique prompt repositories and all 98 occurrence-weighted entries: 95 successful public records, four HTTP 200 receipts, one archived repository, zero disabled repositories, and zero canonical-identity changes. At that stage this was metadata-prerequisite coverage only, and full upstream triage, repository-root licence, edition boundary, maintenance quality, exact behaviour, selection, and mapping credit remained 0/95 and 0/98. The three historical extras were not refreshed, and the superseded 97-plus-one composition receives no credit.

At committed audit head `e24d65310976df4e34ea0d2120b8048b69cf1661`, RUN-031 through RUN-033 completed three disjoint official-source observer partitions and RUN-034 integrated their pinned raw evidence. The union covers exactly 95/95 prompt repositories and all 98 occurrence-weighted entries: 79 records compute as `COMPLETE_OBSERVER_TRIAGE` and 16 remain `PARTIAL_BLOCKED`. Seventeen later observer snapshots have a different default-branch head from the earlier RUN-015 metadata snapshot; they are recorded only as different later observations, with no ancestry, reachability, release, or equivalence inference. This is project-level observer coverage, not formal upstream full-triage credit.

RUN-007 returned 30 provisional observer relations across 29 of the 172 grouped candidates. RUN-008 challenged 15 samples—five semantic/UI collisions failed, five retained only a partial neutral requirement, and five survived a narrow static requirement—and identified nine collision/composite groups. RUN-009 completed eight high-risk comparison packets: zero copied-baseline classifications, two stronger-native-control observations, four Oblivion-specific gaps, and two domains with no credible comparison at the packet level.

None of the RUN-007 through RUN-009 historical slices selects a final current benchmark or establishes a feature-level `No credible match`. RUN-030 froze the separate 340-target static canonical denominator, and RUN-031 through RUN-038 completed project-level observer materialization and blocker review, yielding 88 complete observer-only and seven partial records without formal upstream full-triage credit. Generated RUN-039 through RUN-046 then executed the first six-target target-specific wave. Three scope-GO targets materialize three blind neutral-requirement packets and three clean current-source comparison packets; the three deferred composite targets materialize 18 current-source-only facets under unchanged IDs: seven HR, six medication, and five finance. RUN-046 records stage-lineage `PASS` and six independent `NO_GO` dispositions, so it creates zero formal edges and leaves the guarded 340-row matrix unchanged at 0/340 mappings or final no-matches. Formal upstream full-triage, target mapping, final-no-match, runtime, application-browser, executed-test, ease, release, and completion credit therefore remain zero.

## Static Inertia page adjudication

RUN-010 reproduced the 963-path resolver partition and classified every one of the 25 previously unrendered/unimported TSX candidates: 20 are alias/generated/legacy paths superseded by canonical routes or surfaces, three are dead/unreachable candidates, two are debug/starter demo paths, and zero is a current page root. It separately reconciled all 11 backend render literals with no matching file: four occur in retired unreachable methods and seven in unrouted stubs.

The accepted **committed-source file-backed Inertia page-root denominator is 711**. Of the 252 non-roots, 227 are imported support/components and 25 are the adjudicated candidates. All 963 resolver TSX paths are partitioned for static render/import identity, and the 25-candidate sublane has no unresolved row. The 11 missing render literals remain backend liabilities with zero page or runtime credit. Final prompt classification of the 711 roots and reachability remain open.

An independent reconciliation could not reproduce RUN-010's reported global enumeration of 3,392 JS/TS source files, so that count is quarantined and supports no denominator or credit. Two training-record stubs also retain exact nearest-current-surface anchors without claiming an established replacement owner.

RUN-011 initially returned NO-GO on five bounded evidence defects, including the unreproduced source count and over-broad wording. After correction, the same independent no-write reviewer returned GO across 54 checks. This closes only the bounded static file identity question and brings the fresh run to 11 reconciled formal no-write assignments. Framework-expanded route reachability, build resolution, route/page-to-feature mapping, signed-in application observation, deployment identity, and release behavior remain unproved; no runtime, build, test, database, network, or application-browser work occurred.

## Expanded static coverage wave

RUN-012 through RUN-014 returned read-only and reported no writes, bringing the reconciled formal-assignment count to 14 at that stage. RUN-012 accounted for all 38 route PHP files, 3,217 static route callsites, 3,245 fluent name callsites, and 162 named navigation/tab source files. It found 14 owner-backed route/navigation families omitted from the first 172 grouped candidates: 12 H, one D, and one M. They cover admin/Today dashboards, notifications, Catering, Compliance, Portal, Respite, Roadmap, cross-module Reporting, and the internal quality checklist. RUN-012 therefore established a 186-row discovery floor—157 H, 27 D, and two M—which RUN-017 through RUN-030 later split, merged, excluded, and reconciled into the frozen static canonical denominator described below.

RUN-013 parsed all 1,761 production JS/TS files with TypeScript 5.9.3 and zero parse diagnostics. Its separate static universes contain 57 hero definitions and 659 hero instances, 473 overlay definitions and 1,211 overlay instances, 115 declarative primitive trigger tags, 689 direct inline opening-handler sites, and 138 local named-handler sites. RUN-016 materialized the required 49-column visual matrix as 2,812 unique source rows and visual IDs with SHA-256 `564224d295f8a2d3bad6001b74743fb0a1d75eb41315a817264307353b74dd84`. Definitions, state transitions, excluded close/change bindings, inline hero candidates, and inline ARIA dialogs remain separate supporting ledgers to avoid overlap. The exact machine rerun also corrected linkage-only arithmetic drift in the earlier prose handoff; primary RUN-013 universe counts and hashes were unchanged. All 2,812 rows remain source-inferred and browser-blocked, with zero rendered visual, role/Site, viewport, screenshot, interaction, accessibility, route-execution, or final-feature credit.

RUN-014 established source-only denominators of 561 controller-directory paths, 735 service entries, 782 model paths, 75 policy paths, 126 queued job paths, 14 event paths, 12 listeners, 29 observers, 978 migrations, and 1,381 PHP test files containing 9,895 lexical test cases. Exact anchors in the prior 172 candidates cover only 62 controller paths and 54 service entries and cover zero model, policy, async-owner, notification, or migration paths. Migration filenames and the committed schema dump are history locators rather than database truth, and lexical tests were not executed.

RUN-015 completed the official GitHub metadata prerequisite described above, and RUN-016 completed the static visual-row materialization contract. Both agents reported no repository writes; only the root orchestrator wrote the collectors, generators, matrices, and normalized evidence. The cumulative formal-assignment count at the RUN-016 snapshot was 16. Later RUN-017 through RUN-034 evidence does not close the finalization gate.

The new `02-eight-pass-coverage-ledger.csv` contains 38 provisional route-file ownership rows so Pass gaps are measurable. It is not the canonical module/submodule denominator: every row remains incomplete, and no module has all eight passes.

## Canonical static feature identity freeze

RUN-017 through RUN-029 performed partitioned, adversarial, owner, report-catalog, integrator, and denominator-red-team reconciliation. RUN-030 deterministically integrated the pinned evidence and froze **340 current-source static canonical targets: 300 H, 40 D, and zero M**. Of the 186 discovery sources, 185 map through 362 Layer-A edges to 338 targets. The bounded protected-disclosure source is excluded because no current canonical owner was established; that exclusion does not prove that no external or undocumented process exists. The report-catalog layer contributes 14 relations across nine targets, two of them new, producing the 340-target global denominator.

Three independent reconstructions agree, with zero remaining identity conflicts. The normalized Layer-A edge SHA-256 is `131fe9434e94d6158f7349c0522f42a40cf878fb3f7c4a2b7b71d0cc5e4831c0`, and the global target class/module row SHA-256 is `f33d53cf3c9ed7520b683686520eaca9903e50713f438768a8a70819f1c787ac`. Static linkage gaps remain explicit: 120 targets lack a route anchor, 226 lack a page anchor, and 116 lack both.

This freezes static identity only. Framework route execution, current-build browser coverage, executed tests, benchmark mappings or final no-matches, task/ease evidence, release evidence, Pass completion, and audit completion all retain zero credit.

## Current official New Zealand source baseline

The six official-source families required by the governing prompt were refreshed from current official pages: the Health Information Privacy Code 2020 including its 1 May 2026 indirect-collection amendment, HISO 10029:2022, the NZ FHIR Base Implementation Guide, Ngā Paerewa NZS 8134:2021, the Code of Health and Disability Services Consumers' Rights, and WorkSafe's HSWA guidance. The structured evidence separates each official source fact from the audit inference and the qualified specialist decision still required.

The official HISO PDF asset was identified, but direct retrieval returned 403, so no complete HISO control mapping is claimed. WorkSafe's page records passed amendments that take effect on 1 April 2027; they are tracked as future-effective and are not treated as current law at the 24 August 2026 audit date. No legal, clinical, certification, interoperability, security, or compliance credit is awarded from this source baseline alone.

## Runtime and deployed-build identity gates

A sanitized local safety assessment found PHP 8.4.16 and test-oriented local settings, but `vendor/autoload.php` is absent. The repository's combined setup script would install dependencies, generate a key, force database migrations, install and build frontend dependencies, and install device-integration configuration. It was not executed because those state-changing steps are outside this read-only audit lane without a separately bounded runtime grant. No Laravel boot, framework route list, schema query, migration, test, queue job, or application build receives credit.

The existing signed-in deployed session at `https://oblivionfindings.com/my-day` was inspected without interaction. Its Inertia version and deployed asset names were recorded, but no authoritative commit/tree or release marker was present. The local `public/build/manifest.json` is not tracked at the application source pin and names different assets. That establishes only that deployed-current-source identity is unproved; it does not identify which commit built either asset set. Current-source application browser coverage therefore remains 0.

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
- `evidence/source/current-route-navigation-gap-wave-01.json`: all route-file classifications, navigation and literal-render denominators, collisions, and 14 owner-backed additions.
- `evidence/source/current-visual-static-census-wave-01.json`: production TS/TSX hero, overlay, material-state, trigger, corrected static-linkage denominators, and the 2,812-row materialization reference with zero rendered credit.
- `evidence/source/current-visual-matrix-materialization-wave-01.json`: normalized 49-column matrix counts, hashes, ownership/target/candidate partitions, and zero-execution boundary.
- `evidence/source/current-visual-matrix-agent-register.json`: reconciled RUN-016 return, executable no-write reproduction, and the cumulative 16-assignment state.
- `evidence/source/current-backend-data-test-census-wave-01.json`: backend, data-history, async, policy, migration, schema-dump, and static test denominators and review locators.
- `evidence/source/current-feature-discovery-wave-03.json`: the 14 route/navigation gap additions and 186-row provisional discovery floor.
- `evidence/source/current-static-coverage-agent-register.json`: normalized RUN-012 through RUN-014 returns and the cumulative 14-assignment state.
- `evidence/source/current-canonical-feature-identity-wave-01.json`: the RUN-030 186-source, 362-edge, 340-target static canonical identity registry, exact hashes, linkage gaps, and zero-credit boundaries.
- `evidence/source/current-canonical-identity-agent-register.json`: independent RUN-027 through RUN-029 agreement and deterministic RUN-030 integration, with zero remaining identity conflicts and no downstream credit.
- `evidence/official-sources/nz-source-baseline-2026-08-24.json`: current official NZ source facts, audit inferences, specialist decisions, and explicit completion limits.
- `evidence/benchmark/current-benchmark-wave-01.json`: normalized RUN-007 through RUN-009 project-register, observer, neutralizer, collision, comparator, and RUN-015 metadata-only evidence with zero mapping/completion credit.
- `evidence/benchmark/current-benchmark-agent-register.json`: reconciled benchmark-agent returns, no-write attestations, the corrected RUN-009 follow-up, and the cumulative 9-assignment state.
- `evidence/benchmark/current-benchmark-metadata-agent-register.json`: reconciled RUN-015 return, official-API receipt boundary, no-write attestation, and the cumulative 15-assignment state.
- `evidence/benchmark/current-github-project-metadata-snapshot.json`: authenticated read-only official GitHub metadata for 95/95 unique prompt repositories and 98/98 occurrence-weighted entries, with explicit zero full-triage/behaviour/licence-root/edition/mapping credit.
- `evidence/benchmark/current-prompt-project-denominator-reconciliation.json`: literal 98-occurrence/95-unique prompt reconciliation, repeated repositories, historical extras, the official metadata snapshot reference, and the superseded 97-plus-one claim.
- `evidence/benchmark/raw-run-031-upstream-project-triage-partition-01.json`, `raw-run-032-upstream-project-triage-partition-02.json`, and `raw-run-033-upstream-project-triage-partition-03.json`: three disjoint, source-pinned observer partitions covering exact lowercase lexical ordinals 1–95 and occurrence weights 33, 33, and 32, with no target mapping or benchmark/completion credit.
- `evidence/benchmark/current-upstream-project-triage-wave-01.json`: deterministic RUN-034 normalization of all 95 project observer records, including 79 complete observer triages, 16 explicit partial blockers, 17 later metadata-head differences with no ancestry inference, preserved raw provenance, and zero formal upstream full-triage or downstream credit.
- `evidence/benchmark/current-upstream-project-triage-agent-register.json`: exact raw paths and hashes, disjoint-union agreement, partition status counts, and an explicit `NOT_RECORDED_IN_RAW_ARTIFACT` external-mutation attestation boundary.
- `evidence/benchmark/current-upstream-partial-resolution-wave-01.json`: deterministic RUN-038 review of all 16 explicit RUN-034 blockers; nine resolve at the observer-only evidence boundary and seven remain partial, yielding 88 complete observer-only and seven partial records with every downstream credit still zero.
- `evidence/benchmark/current-upstream-partial-resolution-agent-register.json`: exact RUN-035–037 raw paths and hashes, disjoint 16/16 review coverage, 9/7 decision agreement, read-only external-mutation attestations, and the unchanged zero-credit boundary.
- `evidence/benchmark/current-target-neutral-comparison-wave-01.json`: deterministic RUN-046 integration of six exact canonical targets, three blind neutral-requirement packets, three clean current-source comparisons, 18 source-only facets across three unchanged composite IDs, stage-lineage `PASS`, six independent NO-GO dispositions, zero formal edges, and an unchanged guarded 0/340 mapping/final-no-match matrix.
- `evidence/benchmark/current-target-neutral-comparison-agent-register.json`: exact RUN-039 through RUN-045 raw paths and hashes, full per-stage provenance, withheld-input boundaries, 3/3 scope-GO-to-neutralization/comparison agreement, 3/3 deferred-to-facet-overlay agreement, 18/18 required-facet crosswalk agreement, lineage `PASS`, six NO-GO verdicts, zero remaining stage conflicts, and every downstream credit false.
- `evidence/runtime/current-runtime-safety-assessment.json`: sanitized environment and setup-boundary evidence with zero runtime credit.
- `evidence/browser/deployed-build-identity-assessment.json`: read-only deployed/local asset identity assessment with zero current-source application-browser credit.
- `evidence/browser/current-audit-dashboard-verification-runtime-gates-01.json`: responsive audit-dashboard and local evidence-link verification after the runtime/build gate update.
- `evidence/browser/current-audit-dashboard-verification-benchmark-denominator-01.json`: responsive dashboard and local evidence-link verification after correcting the prompt project denominator.
- `evidence/browser/current-audit-dashboard-verification-static-wave-01.json`: responsive dashboard and local evidence-link verification after RUN-012 through RUN-014 reconciliation.
- `evidence/browser/current-audit-dashboard-verification-metadata-visual-wave-01.json`: responsive desktop/mobile dashboard, 27/27 local evidence-link, and deterministic generator verification after RUN-015/RUN-016 integration.
- `01-repository-module-map.md`: human-readable module/capability map for discovery wave 01.
- `02-repository-module-map-wave-02.md`: human-readable module/capability map for discovery wave 02.
- `02-eight-pass-coverage-ledger.csv`: 38 provisional route-file ownership rows with explicit P1–P8 gaps and zero completed row.
- `03-feature-to-benchmark-matrix.csv`: 340-row canonical static identity matrix with P1 identity frozen only; route/page gaps and all benchmark, ease, executed-test, runtime, browser, release, P2–P8, and completion credit remain explicit and zero.
- `05-browser-visual-coverage-matrix.csv`: 2,812 unique source-inferred hero, overlay, and trigger rows; every browser/runtime/final-feature field remains explicitly blocked or zero-credit.
- `06-open-source-benchmark-register.csv`: 95 exact prompt-repository rows plus three historical extras, with prompt occurrence weights totalling 98, current official GitHub metadata, RUN-031 through RUN-034 observer-triage fields, and RUN-035 through RUN-038 blocker-review fields for the 95 prompt repositories; formal upstream full-triage, mapping, and completion credit remain zero.
- `audit-dashboard.html`: responsive progress dashboard generated only from current structured evidence.
- `generators/build-current-source-census.py`: the deterministic static collector. It reads committed Git objects and writes only inside this audit directory.
- `generators/integrate-source-wave-01.py`: deterministic normalization for the first formal semantic/source wave and dashboard.
- `generators/integrate-source-wave-02.py`: deterministic normalization for the second formal source wave.
- `generators/integrate-benchmark-wave-01.py`: deterministic normalization for the first current benchmark wave and interim required CSVs.
- `generators/collect-current-github-project-metadata.py`: guarded read-only official GitHub metadata collector; its pinned snapshot is integrated separately from full upstream triage.
- `generators/generate-visual-matrix.cjs`: fail-fast TypeScript AST, import/render/route, candidate-link, and row materialization generator with exact count/hash assertions.
- `generators/integrate-visual-matrix-wave-01.py`: deterministic validation and normalization for the RUN-016 matrix and agent evidence.
- `generators/integrate-page-adjudication-wave-01.py`: deterministic normalization for the RUN-010 page/support and missing-render adjudication.
- `generators/integrate-canonical-feature-identity-wave-01.py`: deterministic RUN-030 input-hash, edge, target, class, module, gap, register, and matrix integration.
- `generators/integrate-upstream-project-triage-wave-01.py`: deterministic RUN-031 through RUN-034 raw-hash, denominator, ordinal, occurrence-weight, input-pin, provenance, zero-credit, register, and idempotent-output integration.
- `generators/integrate-upstream-partial-resolution-wave-01.py`: deterministic RUN-035 through RUN-038 raw-hash, exact issue-disposition, official immutable-locus, effective observer-status, register, zero-credit, and idempotent-output integration.
- `generators/integrate-target-neutral-comparison-wave-01.py`: deterministic RUN-039 through RUN-046 lineage, hash, six-target, neutral-packet, comparison-packet, facet, independent-adjudication, zero-edge, matrix-guard, and idempotent-output integration.
- `generators/build-current-audit-dashboard.py`: aggregate dashboard builder over the normalized current evidence.

The collector was rerun twice with identical output hashes. It did not boot Laravel, access a database, run tests or a build, use a browser, or mutate an external system.

## Immediate work order

1. Preserve the frozen 340-target, 300 H / 40 D / zero M denominator and its exact hashes; use the historical 904 register only as a crosswalk.
2. Resolve the static linkage gaps—120 targets without route anchors, 226 without page anchors, and 116 without either—then finish safe framework-route reachability, route/page-to-feature mapping, and canonical backend/data/test ownership. The semantic/runtime graph remains partial.
3. Reconstruct current route/page ownership, module maps, task scripts, eight journeys, and all eight pass ledgers; use the completed 2,812-row static visual matrix as the source universe for later safe current-build rendered role/Site/viewport/state coverage.
4. Build on the completed 16/16 blocker review, effective 88 complete observer-only / seven retained-partial split, and the RUN-046 six-target NO-GO wave. Preserve the seven project-level blockers, resolve each RUN-046 adjudicator next action, and rerun the deferred or incomplete target facets through the required blind-neutralization, clean-current-comparison, and independent-adjudication stages. Then continue across the other 334 canonical targets. Complete one independently approved benchmark edge or documented `No credible match` for every one of the 340 targets; packet materialization, facet reconciliation, and lineage `PASS` are not mapping or completion credit.
5. Run browser and runtime lanes only when their environment, build identity, roles, fixtures, and non-mutation boundary are proven.
6. Freeze artifacts, dispatch fresh independent Pass 8 review, integrate only through the orchestrator, validate all literal completion gates, and update the dashboard from current structured evidence.

No remediation is authorised or implemented by this audit run.
