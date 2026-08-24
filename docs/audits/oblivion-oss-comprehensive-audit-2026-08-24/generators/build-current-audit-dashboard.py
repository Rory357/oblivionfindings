#!/usr/bin/env python3
"""Build the current audit progress dashboard from normalized evidence JSON."""

from __future__ import annotations

import hashlib
import html
import json
from collections import Counter
from pathlib import Path
from string import Template


AUDIT_DIR = Path(__file__).resolve().parents[1]


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def sha256_file(relative: str) -> str:
    path = AUDIT_DIR / relative
    assert path.is_file()
    return hashlib.sha256(path.read_bytes()).hexdigest()


wave1 = read_json("evidence/source/current-feature-discovery-wave-01.json")
wave2 = read_json("evidence/source/current-feature-discovery-wave-02.json")
wave3 = read_json("evidence/source/current-feature-discovery-wave-03.json")
semantic = read_json("evidence/source/current-static-semantic-census.json")
benchmark = read_json("evidence/benchmark/current-benchmark-wave-01.json")
pages = read_json("evidence/source/current-page-adjudication-wave-01.json")
route_gap = read_json("evidence/source/current-route-navigation-gap-wave-01.json")
visual = read_json("evidence/source/current-visual-static-census-wave-01.json")
visual_matrix = read_json("evidence/source/current-visual-matrix-materialization-wave-01.json")
backend = read_json("evidence/source/current-backend-data-test-census-wave-01.json")
runtime = read_json("evidence/runtime/current-runtime-safety-assessment.json")
deployment = read_json("evidence/browser/deployed-build-identity-assessment.json")
canonical = read_json("evidence/source/current-canonical-feature-identity-wave-01.json")
identity_agents = read_json("evidence/source/current-canonical-identity-agent-register.json")
project_triage = read_json("evidence/benchmark/current-upstream-project-triage-wave-01.json")
project_triage_agents = read_json("evidence/benchmark/current-upstream-project-triage-agent-register.json")
partial_resolution = read_json("evidence/benchmark/current-upstream-partial-resolution-wave-01.json")
partial_resolution_agents = read_json("evidence/benchmark/current-upstream-partial-resolution-agent-register.json")
target_comparison = read_json("evidence/benchmark/current-target-neutral-comparison-wave-01.json")
target_comparison_agents = read_json("evidence/benchmark/current-target-neutral-comparison-agent-register.json")
assert sha256_file("evidence/source/current-canonical-feature-identity-wave-01.json") == "f4feae2598622afe346b1163fed2bb842305a8d973a89ec890c02746d99b5999"
assert sha256_file("evidence/source/current-canonical-identity-agent-register.json") == "21ebd8b004b5ade11aa01281958cda2be2ca966d1fb7c46576e039fab5f47baf"
assert sha256_file("03-feature-to-benchmark-matrix.csv") == "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
assert sha256_file("evidence/benchmark/current-upstream-project-triage-wave-01.json") == "ea0bb6bde44aa8f227d6e4133788e8fcb08c3069e2aecab4e0bc194cee2f3651"
assert sha256_file("evidence/benchmark/current-upstream-project-triage-agent-register.json") == "686ae0f32abe1d890ed89228c46bbb8eb0a28b4ff16f91dad31d0b2e34f44811"
assert sha256_file("evidence/benchmark/current-upstream-partial-resolution-wave-01.json") == "6c7c5eb6532a4ec4bcc45ebcb4f2cfd9ff7af6156c68064db58ce5dfeeb2e305"
assert sha256_file("evidence/benchmark/current-upstream-partial-resolution-agent-register.json") == "80144bcc32db3d8830e2247d2fe803c2e259900165ae9b7eaf2228838d230071"
assert sha256_file("evidence/benchmark/current-target-neutral-comparison-wave-01.json") == "648fd95c9291a094a60bf1dfb007e1da9f58eb9b9889ffaad4fa5d542ecbf1f4"
assert sha256_file("evidence/benchmark/current-target-neutral-comparison-agent-register.json") == "dfd29c6896e2401234726a6be4bb98685d19b1ed9bcdd145751aa51dcba23104"
assert sha256_file("06-open-source-benchmark-register.csv") == "cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91"

candidates = wave1["candidates"] + wave2["candidates"] + wave3["candidates"]
candidate_ids = [row["candidate_id"] for row in candidates]
assert len(candidates) == 186
assert len(set(candidate_ids)) == 186

targets = canonical["targets"]
target_ids = [row["feature_id"] for row in targets]
class_counts = Counter(row["feature_class"] for row in targets)
assert canonical["run_id"] == "RUN-030"
assert identity_agents["run_id"] == "RUN-030"
assert identity_agents["status"] == "CANONICAL_IDENTITY_INDEPENDENCE_AND_INTEGRATION_REGISTER_COMPLETE"
assert canonical["generated_at"] == identity_agents["generated_at"]
assert len(targets) == len(set(target_ids)) == 340
assert class_counts == {"H": 300, "D": 40}
assert canonical["counts"]["source_candidates"] == 186
assert canonical["counts"]["mapped_sources"] == 185
assert canonical["counts"]["excluded_sources"] == 1
assert canonical["counts"]["layer_a_edges"] == 362
assert canonical["counts"]["layer_a_targets"] == 338
assert canonical["counts"]["layer_b_catalog_relations"] == 14
assert canonical["counts"]["layer_b_catalog_targets"] == 9
assert canonical["counts"]["layer_b_new_targets"] == 2
assert canonical["counts"]["canonical_targets"] == 340
assert canonical["counts"]["classes"] == {"H": 300, "D": 40, "M": 0}
assert identity_agents["agreement"]["independent_reconstructions"] == 3
assert identity_agents["agreement"]["remaining_identity_conflicts"] == 0
assert identity_agents["agreement"]["canonical_targets"] == 340
assert identity_agents["agreement"]["classes"] == {"H": 300, "D": 40, "M": 0}
assert canonical["static_evidence_gaps"]["targets_missing_route_anchor"] == 120
assert canonical["static_evidence_gaps"]["targets_missing_page_anchor"] == 226
assert canonical["static_evidence_gaps"]["targets_missing_both_route_and_page_anchor"] == 116
assert canonical["completion_gate"]["canonical_static_identity_frozen"] is True
for credit in (
    "runtime_credit", "browser_credit", "test_execution_credit", "benchmark_credit",
    "ease_credit", "release_credit", "completion_credit",
):
    assert canonical["completion_gate"][credit] == 0
assert canonical["completion_gate"]["audit_complete"] is False
assert all(
    target[credit] == 0
    for target in targets
    for credit in (
        "runtime_credit", "browser_credit", "test_execution_credit",
        "benchmark_credit", "ease_credit", "completion_credit",
    )
)
assert runtime["pins"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert runtime["pins"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert runtime["sanitized_environment_observations"]["vendor_autoload_exists"] is False
assert runtime["setup_boundary"]["setup_executed"] is False
assert runtime["gate_result"]["framework_route_denominator_established"] is False
assert runtime["gate_result"]["current_schema_snapshot_established"] is False
assert runtime["gate_result"]["tests_executed"] == 0
assert runtime["gate_result"]["runtime_credit"] == 0
assert deployment["pins"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert deployment["pins"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert deployment["identity_assessment"]["deployed_commit_or_tree_proven"] is False
assert deployment["gate_result"]["deployment_identity_established"] is False
assert deployment["gate_result"]["current_source_application_pages_observed"] == 0
assert deployment["gate_result"]["current_source_routes_observed"] == 0
assert deployment["gate_result"]["current_source_workflows_executed"] == 0
assert deployment["gate_result"]["browser_credit"] == 0
benchmark_register = benchmark["project_register_current_audit"]
assert benchmark_register["current_upstream_full_triage_unique_repository_completions"] == 0
assert benchmark_register["current_upstream_full_triage_prompt_occurrence_completions"] == 0
assert benchmark_register["current_project_triage_completion_credit"] == 0
for credit in (
    "upstream_full_triage_credit", "exact_behaviour_credit", "edition_boundary_credit",
    "root_licence_confirmation_credit", "maintenance_quality_credit",
    "selection_or_outcome_credit", "feature_mapping_credit", "benchmark_completion_credit",
):
    assert benchmark_register["metadata_credit_boundary"][credit] == 0
assert benchmark["current_feature_gate"]["verified_benchmark_or_documented_no_credible_match"] == 0
assert project_triage["run_id"] == "RUN-034"
assert project_triage_agents["run_id"] == "RUN-034"
assert project_triage["generated_at"] == project_triage_agents["generated_at"]
assert project_triage["project_universe"]["repositories"] == 95
assert project_triage["project_universe"]["prompt_occurrences"] == 98
assert project_triage["counts"]["observer_records"] == 95
triage_projects = project_triage["projects"]
assert len(triage_projects) == 95
assert len({row["canonical_repository"] for row in triage_projects}) == 95
assert sum(row["prompt_occurrence_count"] for row in triage_projects) == 98
assert project_triage["counts"]["reported_statuses"] == {
    "COMPLETE_OBSERVER_REPORTED": 79,
    "PARTIAL_BLOCKED_REPORTED": 16,
}
assert project_triage["counts"]["observer_statuses"] == {
    "COMPLETE_OBSERVER_TRIAGE": 79,
    "PARTIAL_BLOCKED": 16,
}
assert project_triage["counts"]["metadata_head_relationships"] == {
    "DIFFERENT_LATER_OBSERVATION_NO_ANCESTRY_INFERENCE": 17,
    "SAME_AS_BASELINE": 78,
}
assert Counter(row["computed_observer_triage_status"] for row in triage_projects) == {
    "COMPLETE_OBSERVER_TRIAGE": 79,
    "PARTIAL_BLOCKED": 16,
}
assert Counter(row["metadata_head_relationship"] for row in triage_projects) == {
    "DIFFERENT_LATER_OBSERVATION_NO_ANCESTRY_INFERENCE": 17,
    "SAME_AS_BASELINE": 78,
}
assert all(
    row["canonical_target_mapping_status"] == "NOT_PERFORMED_OBSERVER_ONLY"
    and row["target_specific_mapping_credit"] is False
    and row["benchmark_credit"] is False
    and row["benchmark_completion_credit"] is False
    and row["completion_credit"] is False
    for row in triage_projects
)
assert project_triage["counts"]["formal_upstream_full_triage_credit"] == 0
assert project_triage["counts"]["canonical_targets"] == len(targets) == 340
assert project_triage["counts"]["feature_benchmark_mappings_or_final_no_matches"] == 0
assert project_triage_agents["agreement"]["disjoint_union_repositories"] == 95
assert project_triage_agents["agreement"]["prompt_occurrence_weight"] == 98
assert project_triage_agents["agreement"]["remaining_partition_collisions"] == 0
assert project_triage_agents["agreement"]["project_universe_sha256"] == project_triage["project_universe"]["sha256"]
assert all(
    row["external_mutations_attestation"] == "NOT_RECORDED_IN_RAW_ARTIFACT"
    for row in project_triage_agents["agents"]
)
for evidence in (project_triage, project_triage_agents):
    assert evidence["credit_boundary"]["observer_project_evidence_materialized"] == 95
    for credit in (
        "upstream_full_triage_credit", "neutral_requirement_credit",
        "current_product_comparison_credit", "target_specific_mapping_credit",
        "benchmark_completion_credit", "runtime_credit", "browser_credit",
        "ease_credit", "release_credit", "completion_credit",
    ):
        assert evidence["credit_boundary"][credit] == 0
    assert evidence["credit_boundary"]["audit_complete"] is False

expected_triage_raw = {
    "evidence/benchmark/raw-run-031-upstream-project-triage-partition-01.json": "e6b8e4c9ef95f546397ea0a4e640840503d24fd42aac09abe688f952313032be",
    "evidence/benchmark/raw-run-032-upstream-project-triage-partition-02.json": "dc6006728e0ebbaf40febb3139619e9e01f9b6e8a5d46ef12f0667a885f9cc49",
    "evidence/benchmark/raw-run-033-upstream-project-triage-partition-03.json": "f58f77c34136c4dfdc93e83aaa741a79ca067c709bbe8d0b15e646bfc7ab3a16",
}
triage_raw_partitions = project_triage["inputs"]["raw_partitions"]
assert {row["raw_file"]: row["raw_sha256"] for row in triage_raw_partitions} == expected_triage_raw
assert {row["raw_file"]: row["raw_sha256"] for row in project_triage_agents["agents"]} == expected_triage_raw
assert [row["run_id"] for row in triage_raw_partitions] == ["RUN-031", "RUN-032", "RUN-033"]
assert sum(row["repository_count"] for row in triage_raw_partitions) == 95
assert sum(row["occurrence_weight"] for row in triage_raw_partitions) == 98
assert all(sha256_file(path) == expected_sha256 for path, expected_sha256 in expected_triage_raw.items())
assert partial_resolution["run_id"] == "RUN-038"
assert partial_resolution_agents["run_id"] == "RUN-038"
assert partial_resolution["generated_at"] == partial_resolution_agents["generated_at"]
assert partial_resolution["inputs"]["base_wave_sha256"] == sha256_file(
    "evidence/benchmark/current-upstream-project-triage-wave-01.json"
)
assert partial_resolution["inputs"]["canonical_matrix_guard_sha256"] == sha256_file(
    "03-feature-to-benchmark-matrix.csv"
)
assert partial_resolution["counts"]["base_partial_records"] == 16
assert partial_resolution["counts"]["reviewed_partial_records"] == 16
assert partial_resolution["counts"]["resolution_decisions"] == {
    "RESOLVED_OBSERVER_EVIDENCE": 9,
    "RETAIN_PARTIAL": 7,
}
assert partial_resolution["counts"]["effective_observer_statuses"] == {
    "COMPLETE_OBSERVER_TRIAGE": 88,
    "PARTIAL_BLOCKED": 7,
}
resolution_records = partial_resolution["records"]
assert len(resolution_records) == 16
assert [row["ordinal"] for row in resolution_records] == [
    3, 4, 19, 20, 22, 23, 39, 46, 48, 58, 71, 74, 76, 83, 85, 90,
]
assert Counter(row["resolution_status"] for row in resolution_records) == {
    "RESOLVED_OBSERVER_EVIDENCE": 9,
    "RETAIN_PARTIAL": 7,
}
assert all(
    row[credit] is False
    for row in resolution_records
    for credit in (
        "target_specific_mapping_credit", "benchmark_credit", "completion_credit",
        "neutral_requirement_credit", "current_product_comparison_credit",
        "final_no_match_credit", "benchmark_completion_credit",
    )
)
assert partial_resolution_agents["agreement"]["disjoint_partial_records_reviewed"] == 16
assert partial_resolution_agents["agreement"]["remaining_unreviewed_run_034_partial_records"] == 0
expected_resolution_raw = {
    "evidence/benchmark/raw-run-035-upstream-partial-resolution-partition-01.json": "baaf5056749c26c8f4a251c63738200006f895de6244a87cd72db184f9417ad6",
    "evidence/benchmark/raw-run-036-upstream-partial-resolution-partition-02.json": "f74676e9520fe1eeaf68abf3a9c1a62ad9532b0142858ac7b575d4c403c351c8",
    "evidence/benchmark/raw-run-037-upstream-partial-resolution-partition-03.json": "c29546b9373ac6354cfe1379648f1afee70fa0fb30aab5a5692df63ff5ac1724",
}
assert {
    row["raw_file"]: row["raw_sha256"]
    for row in partial_resolution["inputs"]["raw_partitions"]
} == expected_resolution_raw
assert {
    row["raw_file"]: row["raw_sha256"]
    for row in partial_resolution_agents["agents"]
} == expected_resolution_raw
assert all(
    sha256_file(path) == expected_sha256
    for path, expected_sha256 in expected_resolution_raw.items()
)
assert partial_resolution["credit_boundary"]["partial_records_reviewed"] == 16
for evidence in (partial_resolution, partial_resolution_agents):
    for credit in (
        "upstream_full_triage_credit", "neutral_requirement_credit",
        "current_product_comparison_credit", "target_specific_mapping_credit",
        "benchmark_completion_credit", "runtime_credit", "browser_credit",
        "ease_credit", "release_credit", "completion_credit",
    ):
        assert evidence["credit_boundary"][credit] == 0
    assert evidence["credit_boundary"]["audit_complete"] is False
assert target_comparison["run_id"] == "RUN-046"
assert target_comparison_agents["run_id"] == "RUN-046"
assert target_comparison["generated_at"] == target_comparison_agents["generated_at"]
assert target_comparison["source_pin"]["application_commit"] == canonical["source_pin"]["application_commit"]
assert target_comparison["source_pin"]["application_tree"] == canonical["source_pin"]["application_tree"]
assert target_comparison["source_pin"]["canonical_identity_sha256"] == sha256_file(
    "evidence/source/current-canonical-feature-identity-wave-01.json"
)
assert target_comparison["source_pin"]["canonical_matrix_guard_sha256"] == sha256_file(
    "03-feature-to-benchmark-matrix.csv"
)
assert target_comparison["stage_lineage"] == {
    "status": "PASS",
    "upstream_identity_withheld_from_agent_b": True,
    "current_source_withheld_from_agent_b": True,
    "upstream_identity_withheld_from_agent_c": True,
    "scope_gate_hash_bound": True,
    "post_reconciliation_independent_adjudication": True,
    "source_anchor_validation_pass": True,
    "required_facet_crosswalk_pass": True,
}
target_counts = target_comparison["counts"]
assert target_counts == {
    "wave_targets": 6,
    "candidate_locator_packets": 5,
    "bounded_no_candidate_packets": 1,
    "current_source_packets": 6,
    "scope_go_bounded": 3,
    "scope_deferred_composites": 3,
    "blind_neutral_requirement_packets": 3,
    "clean_current_comparisons": 3,
    "facet_reconciliation_targets": 3,
    "facet_reconciliation_facets": 18,
    "required_facet_crosswalk_rows": 18,
    "unmapped_required_facets": 0,
    "source_anchor_occurrences_validated": 210,
    "independent_no_go_verdicts": 6,
    "formal_edges": 0,
    "canonical_targets": 340,
    "promoted_feature_mappings_or_final_no_matches": 0,
}
target_records = target_comparison["records"]
assert len(target_records) == len({row["feature_id"] for row in target_records}) == 6
assert all(row["feature_id"] in target_ids for row in target_records)
assert all(
    row[credit] is False
    for row in target_records
    for credit in (
        "target_specific_mapping_credit", "benchmark_credit", "final_no_match_credit",
        "runtime_credit", "test_execution_credit", "completion_credit",
    )
)
assert all(row["formal_edge_count"] == 0 for row in target_records)
assert target_comparison["canonical_matrix_disposition"]["status"] == "UNCHANGED_GUARDED_OVERLAY_ONLY"
assert target_comparison["canonical_matrix_disposition"]["sha256"] == sha256_file(
    "03-feature-to-benchmark-matrix.csv"
)
assert target_comparison_agents["agreement"] == {
    "wave_targets": 6,
    "scope_go_equals_neutralized_equals_compared": 3,
    "scope_deferred_equals_facet_overlay_targets": 3,
    "facet_count": 18,
    "required_facet_crosswalk_count": 18,
    "unmapped_required_facets": 0,
    "stage_lineage_status": "PASS",
    "source_anchor_validation_status": "PASS",
    "source_anchor_occurrences_checked": 210,
    "post_reconciliation_independent_adjudication": True,
    "independent_no_go_verdicts": 6,
    "formal_edges": 0,
    "remaining_stage_conflicts": 0,
}
assert target_comparison_agents["governing_prompt"] == {
    "path": "C:/Users/steph/Downloads/oblivion-open-source-benchmark-and-8-pass-audit-prompt.md",
    "sha256": "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f",
    "bytes": 88305,
}
required_stage_provenance = {
    "responsible_agent_identity", "repository_commit_scope", "architecture_rule",
    "scope", "pass_lens", "evidence_schema_and_count", "no_write_rule",
    "completion_test", "unresolved_gaps",
}
assert len(target_comparison_agents["agents"]) == 7
assert all(
    required_stage_provenance.issubset(row)
    and all(isinstance(row[field], str) and row[field].strip() for field in required_stage_provenance)
    for row in target_comparison_agents["agents"]
)
assert required_stage_provenance.issubset(target_comparison_agents["root_integrator"])
expected_target_raw = {
    "evidence/benchmark/raw-run-039-target-upstream-behaviour-wave-01.json": "1675bc983ab446b94ed0b175791f81c2c3548a90a3d004e8fb5d1ecde8bfd3db",
    "evidence/benchmark/raw-run-040-current-product-source-packets-wave-01.json": "4d9ea76a79e023e69c996ae8685f19651a0e309a1ffb217a889d7058f6505602",
    "evidence/benchmark/raw-run-041-current-source-red-team-wave-01.json": "aef801877a0392df9a84ee77b8a4c573170de68f6841162aea61b655c057ed7c",
    "evidence/benchmark/raw-run-042-neutral-requirements-wave-01.json": "e17b9aed0268ca22e2a13c843791d3ca65ce818607a9a10a40429b232beab995",
    "evidence/benchmark/raw-run-043-current-neutral-comparison-wave-01.json": "fd4cb0fca2ca444f36235dab554cf60c9d33e2be6b4cd79e4f4262ad316c2e7c",
    "evidence/benchmark/raw-run-044-current-source-facet-reconciliation-wave-01.json": "b63de4b47c8703de72550c6c94db9bbe86e5c4aca5bdd891bf42e9f3f1114457",
    "evidence/benchmark/raw-run-045-wave-01-independent-adjudication.json": "734a0fd3f6c8468a237e0d29af902ef6d622dc4dd2c17981ec4a19f2e9723664",
}
assert {
    row["file"]: row["sha256"]
    for row in target_comparison["inputs"]["raw_runs"]
} == expected_target_raw
assert {
    row["raw_file"]: row["raw_sha256"]
    for row in target_comparison_agents["agents"]
} == expected_target_raw
assert all(
    sha256_file(path) == expected_sha256
    for path, expected_sha256 in expected_target_raw.items()
)
for evidence in (target_comparison, target_comparison_agents):
    for credit in (
        "target_specific_mapping_credit", "benchmark_credit", "final_no_match_credit",
        "runtime_credit", "browser_credit", "test_execution_credit", "ease_credit",
        "release_credit", "completion_credit",
    ):
        assert evidence["credit_boundary"][credit] == 0
    assert evidence["credit_boundary"]["audit_complete"] is False
assert visual_matrix["matrix"]["rows"] == 2812
assert visual_matrix["credit_boundary"]["browser"] == 0

module_labels = sorted({row["module"] for row in targets})
assert len(module_labels) == 29
findings = wave1["provisional_findings"] + wave2["provisional_findings"]
assert len(findings) == 12

finding_rows = "".join(
    "<tr><td class=\"mono\">{}</td><td>{}</td><td class=\"partial\">independent review pending</td></tr>".format(
        html.escape(row["finding_id"]),
        html.escape(row["source_claim"]),
    )
    for row in findings
)

module_rows = "".join(
    "<tr><td>{}</td><td>{}</td><td>{}</td><td>{}</td><td>{}</td></tr>".format(
        html.escape(module),
        sum(1 for row in targets if row["module"] == module and row["feature_class"] == "H"),
        sum(1 for row in targets if row["module"] == module and row["feature_class"] == "D"),
        sum(1 for row in targets if row["module"] == module and row["feature_class"] == "M"),
        sum(1 for row in targets if row["module"] == module),
    )
    for module in module_labels
)


TEMPLATE = Template(r"""<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Oblivion Findings current-source audit</title>
  <style>
    :root{color-scheme:light;--ink:#172033;--muted:#5f6b7d;--line:#dce2ec;--panel:#fff;--bg:#f4f6fb;--brand:#5b55f6;--warn:#a04800;--warnbg:#fff2db;--shadow:0 8px 24px rgba(27,35,58,.07)}
    *{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}a{color:#413ad8;text-decoration-thickness:1px;text-underline-offset:3px}a:focus-visible{outline:3px solid #8d88ff;outline-offset:3px;border-radius:4px}
    header{background:linear-gradient(135deg,#1c2140 0%,#3f399f 100%);color:#fff;padding:28px max(20px,calc((100vw - 1180px)/2)) 32px}.eyebrow{font-size:.78rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#cbc9ff}.hero{display:flex;gap:24px;align-items:end;justify-content:space-between}.hero h1{font-size:clamp(1.8rem,4vw,3rem);line-height:1.08;margin:7px 0 8px;max-width:820px}.hero p{margin:0;color:#e5e4ff;max-width:780px}.badge{display:inline-flex;white-space:nowrap;align-items:center;border:1px solid #f2c675;background:#3e2b18;color:#ffe5b5;border-radius:999px;padding:9px 13px;font-weight:800}
    nav{background:#fff;border-bottom:1px solid var(--line);position:sticky;top:0;z-index:4;overflow:auto}nav div{max-width:1180px;margin:auto;display:flex;gap:20px;padding:11px 20px;white-space:nowrap}nav a{color:#39445a;font-weight:700;text-decoration:none}main{max-width:1180px;margin:0 auto;padding:24px 20px 64px}.notice{background:var(--warnbg);border-left:5px solid #e58d22;padding:14px 16px;border-radius:10px;margin-bottom:22px;color:#633000}
    .cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.card,.panel{background:var(--panel);border:1px solid var(--line);box-shadow:var(--shadow);border-radius:14px}.card{padding:17px}.card strong{display:block;font-size:1.65rem;line-height:1.15}.card span{display:block;color:var(--muted);margin-top:5px}.card small{display:block;margin-top:9px;color:#717d90}.panel{min-width:0;padding:20px;margin-top:20px}.panel h2{font-size:1.25rem;margin:0 0 5px}.panel>p{color:var(--muted);margin:0 0 16px}.table-wrap{max-width:100%;overflow-x:auto;border:1px solid var(--line);border-radius:10px}table{width:100%;border-collapse:collapse;min-width:680px}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:top}th{background:#f7f8fc;color:#414d62;font-size:.82rem}tr:last-child td{border-bottom:0}.zero{color:#a03920;font-weight:800}.partial{color:var(--warn);font-weight:800}.split{display:grid;grid-template-columns:1.15fr .85fr;gap:20px}.split>*{min-width:0}.list{margin:0;padding-left:20px}.list li+li{margin-top:8px}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.88em;overflow-wrap:anywhere}.footer{color:var(--muted);font-size:.88rem;margin-top:24px}
    @media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.split{grid-template-columns:1fr}.hero{align-items:flex-start;flex-direction:column}.badge{align-self:flex-start}}@media(max-width:520px){header{padding:22px 16px 26px}main{padding:18px 14px 48px}.cards{grid-template-columns:1fr 1fr;gap:10px}.card{padding:14px}.card strong{font-size:1.35rem}.panel{padding:16px}.badge{white-space:normal}nav div{padding-inline:16px}}
  </style>
</head>
<body>
  <header><div class="eyebrow">Oblivion Findings · comprehensive audit restart</div><div class="hero"><div><h1>Fresh current-source audit</h1><p>Evidence is pinned to application commit <span class="mono">$application_short</span>. Historical percentages remain provenance only.</p></div><div class="badge">IN PROGRESS · NOT COMPREHENSIVE</div></div></header>
  <nav aria-label="Audit sections"><div><a href="#progress">Progress</a><a href="#pages">Pages</a><a href="#static-census">Static census</a><a href="#runtime">Runtime gates</a><a href="#benchmarks">Benchmarks</a><a href="#modules">Modules</a><a href="#findings">Provisional findings</a><a href="#gaps">Gaps</a></div></nav>
  <main>
    <div class="notice" role="status"><strong>No completion claim:</strong> RUN-030 freezes 340 current-source static canonical targets (300 H · 40 D · 0 M). RUN-034–038 materialize observer evidence for 95/95 prompt repositories and review all 16 blockers, yielding 88 complete observer-only and 7 partial. RUN-039–046 then complete the clean staged comparison chain for only 6/340 targets: 3 blind neutral packets, 3 clean current-source comparisons, 18/18 required composite-source facets, 6 independent NO-GO verdicts, and 0 formal edges. The canonical matrix remains byte-identical with 0/340 mappings or final no-matches; runtime, rendered application-browser, executed-test, benchmark, ease, release, Pass, and audit-completion credit remain zero.</div>
    <section id="progress" class="cards" aria-label="Current audit progress">
      <div class="card"><strong>8,454</strong><span>tracked source paths</span><small>committed-tree census</small></div><div class="card"><strong>$route_calls</strong><span>static route callsites</span><small>not runtime routes</small></div><div class="card"><strong>$page_root_count</strong><span>static Inertia page roots</span><small>$resolver_count paths partitioned; prompt gate open</small></div><div class="card"><strong>$canonical_count</strong><span>canonical static targets</span><small>$h_count H · $d_count D · $m_count M</small></div>
      <div class="card"><strong>$mapped_sources / $source_count</strong><span>discovery sources mapped</span><small>one bounded source excluded</small></div><div class="card"><strong class="partial">$finding_count</strong><span>provisional P1 claims</span><small>none final</small></div><div class="card"><strong class="zero">0</strong><span>current runtime tests</span><small>vendor absent; setup not run</small></div><div class="card"><strong class="zero">0</strong><span>current-source browser routes</span><small>deployment identity unproved</small></div>
    </section>
    <section id="pages" class="panel"><h2>Current static Inertia page partition</h2><p>RUN-010 partitioned every resolver TSX path for render/import identity. The 711-file denominator is committed-source evidence only; final prompt classification remains open.</p><div class="table-wrap"><table><thead><tr><th>Partition</th><th>Count</th><th>Current static identity</th></tr></thead><tbody><tr><td>Existing literal backend render roots</td><td>$page_root_count</td><td class="partial">static file-backed page roots</td></tr><tr><td>Unrendered but imported</td><td>$support_count</td><td class="partial">support/components, not page roots</td></tr><tr><td>Unrendered/unimported aliases or legacy</td><td>$legacy_count</td><td class="partial">10 Redirect/legacy + 10 Duplicate</td></tr><tr><td>Unrendered/unimported dead or demo</td><td>$dead_demo_count</td><td class="partial">3 Dead/unreachable + 2 Out of product scope</td></tr><tr><td>Missing backend render literals</td><td>$missing_target_count</td><td class="zero">retired/unrouted liabilities; zero page credit</td></tr><tr><td><strong>Resolver TSX partitioned</strong></td><td><strong>$resolver_count</strong></td><td class="partial">963/963 static render/import identity; prompt gate open</td></tr></tbody></table></div></section>
    <div class="split"><section class="panel"><h2>Evidence waves represented</h2><p>RUN-001 through RUN-046 are represented by audit artifacts; none grants application runtime, browser, executed-test, benchmark, or completion credit.</p><ul class="list"><li>RUN-001–016: census, discovery, page/static visual, and benchmark-metadata foundations</li><li>RUN-017–022: frontline/platform identity adjudication and blocked-owner reconciliation</li><li>RUN-023–025: cross-scope and remaining-owner arbitration</li><li>RUN-026: report-catalog identity adjudication</li><li>RUN-027: medical-profile owner tie-break</li><li>RUN-028–029: denominator integration and independent red team</li><li>RUN-030: deterministic materialization of 362 Layer-A edges and 340 canonical targets</li><li>RUN-031–033: disjoint upstream observer-triage partitions covering 95 repositories and 98 occurrence-weighted entries</li><li>RUN-034: normalized observer-only triage integration with 79 complete and 16 partial records</li><li>RUN-035–037: disjoint official-source reviews of every partial observer record</li><li>RUN-038: deterministic blocker-resolution integration with 9 resolved observer-only, 7 retained partial, and zero downstream credit</li><li>RUN-039–045: six-target upstream packets, pinned current-source packets, scope red team, blind neutralization, clean comparison, 18-facet composite overlay, and independent adjudication</li><li>RUN-046: deterministic lineage and hash integration; 6 NO-GO, 0 formal edges, and unchanged 0/340 mapping credit</li></ul></section><section class="panel"><h2>Execution credit</h2><p>Static, observer, and source-comparison evidence are not runtime evidence.</p><ul class="list"><li><span class="zero">0</span> framework route executions</li><li><span class="zero">0</span> current application tests</li><li><span class="zero">0</span> rendered current-build visual instances</li><li><span class="zero">0</span> current-build application browser routes</li><li><span class="zero">0</span> benchmark mappings promoted</li><li><span class="zero">0</span> completed Pass 1–8 modules</li></ul></section></div>
    <section id="static-census" class="panel"><h2>Expanded static coverage wave</h2><p>RUN-030 freezes canonical static identity; rendered coverage, schema truth, runtime, benchmark, ease, release, and completion gates remain open.</p><div class="table-wrap"><table><thead><tr><th>Static universe</th><th>Denominator</th><th>Current boundary</th></tr></thead><tbody><tr><td>Discovery sources / Layer-A edges</td><td>$mapped_sources of $source_count / $layer_a_edges</td><td class="partial">one bounded source excluded; $layer_a_targets Layer-A targets</td></tr><tr><td>Canonical targets</td><td>$canonical_count / $h_count H · $d_count D · $m_count M</td><td class="partial">static identity frozen; no downstream credit</td></tr><tr><td>Missing route / page / both anchors</td><td>$route_gap_count / $page_gap_count / $both_gap_count</td><td class="partial">static anchor completion remains open</td></tr><tr><td>Route source files / callsites</td><td>$route_file_count / $route_calls</td><td class="partial">all files classified; no framework expansion</td></tr><tr><td>Named navigation/tab source files</td><td>$nav_file_count</td><td class="partial">definitions, not runtime-visible links</td></tr><tr><td>Hero definitions / instances</td><td>$hero_definitions / $hero_instances</td><td class="partial">static AST; 0 rendered</td></tr><tr><td>Overlay definitions / instances</td><td>$overlay_definitions / $overlay_instances</td><td class="partial">static AST; 0 rendered</td></tr><tr><td>Declarative / direct / named triggers</td><td>$declarative_triggers / $direct_triggers / $named_triggers</td><td class="partial">row-level static locators; 0 interactions</td></tr><tr><td>Required visual matrix rows</td><td>$visual_matrix_rows</td><td class="partial">49 columns complete; every row browser-blocked</td></tr><tr><td>Models / policies / service entries</td><td>$models / $policies / $services</td><td class="partial">directory/declaration census, not ownership completion</td></tr><tr><td>Jobs / events / listeners</td><td>$jobs / $events / $listeners</td><td class="partial">static owners, no queue execution</td></tr><tr><td>Migrations / lexical PHP test cases</td><td>$migrations / $php_test_cases</td><td class="partial">history and locators; 0 database/test execution</td></tr></tbody></table></div></section>
    <section id="runtime" class="panel"><h2>Runtime and deployed-build identity gates</h2><p>Both checks are evidence about whether execution is safe and attributable. Neither grants application execution credit.</p><div class="table-wrap"><table><thead><tr><th>Gate</th><th>Observed fact</th><th>Result</th></tr></thead><tbody><tr><td>Local PHP/runtime</td><td>PHP $php_version; test-oriented settings; <span class="mono">vendor/autoload.php</span> absent</td><td class="zero">Laravel not booted; 0 runtime tests</td></tr><tr><td>Repository setup</td><td>Combined setup includes dependencies, forced migration, frontend build, and device configuration</td><td class="zero">State-changing setup not run</td></tr><tr><td>Signed-in deployment</td><td>Inertia component <span class="mono">$deployed_component</span> and deployed assets recorded read-only</td><td class="zero">No authoritative commit/tree marker</td></tr><tr><td>Local build manifest</td><td>Present locally but not tracked at the application source pin</td><td class="zero">Cannot identify the deployed build</td></tr></tbody></table></div></section>
    <section id="benchmarks" class="panel"><h2>Current benchmark wave</h2><p>The prompt denominator remains 98 URL occurrences / 95 unique repositories. RUN-034–038 materialize the complete observer denominator and resolve the original blockers to 88 complete observer-only / 7 partial. RUN-039–046 then process the first 6 exact canonical targets through separated upstream, source, blind-neutral, clean-comparison, facet-reconciliation, and independent-adjudication stages. Lineage passes, but all 6 adjudications are NO-GO and approve 0 formal edges; formal upstream full triage and all 340 target mappings or final no-matches remain open.</p><div class="table-wrap"><table><thead><tr><th>Evidence slice</th><th>Count</th><th>Current credit</th></tr></thead><tbody><tr><td>Prompt-listed URL occurrences</td><td>$prompt_occurrences</td><td class="partial">$prompt_unique unique repositories; three repeated</td></tr><tr><td>Physical carry-forward register rows</td><td>$register_physical</td><td class="partial">95 exact prompt repos + $extra_rows historical extras</td></tr><tr><td>Official GitHub metadata prerequisite</td><td>$metadata_unique / $prompt_unique</td><td class="partial">$metadata_occurrences / $prompt_occurrences weighted entries; metadata only</td></tr><tr><td>Observer project triage</td><td>$triage_observer_unique / $prompt_unique</td><td class="partial">$triage_observer_occurrences / $prompt_occurrences weighted entries; $triage_complete complete observer-only · $triage_partial partial</td></tr><tr><td>Partial-record review</td><td>$partial_reviewed / 16</td><td class="partial">$partial_resolved resolved observer-only · $partial_retained retained partial; zero downstream credit</td></tr><tr><td>Observer metadata-head relation</td><td>$triage_same_head same / $triage_different_head different</td><td class="partial">later observations only; no ancestry inferred</td></tr><tr><td>Formal full project triage</td><td>$triage_unique / $prompt_unique</td><td class="zero">$triage_occurrences / $prompt_occurrences weighted entries</td></tr><tr><td>Formal behaviour / root licence / edition / selection credit</td><td>0 / 0 / 0 / 0</td><td class="zero">no substantive benchmark credit</td></tr><tr><td>Historical provisional candidate relations</td><td>$observer_records records / $observer_unique candidates</td><td class="partial">locator relations only</td></tr><tr><td>Historical neutralizer / native comparator packets</td><td>$neutralizer_count / $comparator_count</td><td class="partial">challenge packets only</td></tr><tr><td>First target comparison evidence wave</td><td>$target_wave_targets / $canonical_count</td><td class="partial">$target_candidate_packets candidate locators · $target_no_candidate_packets bounded no-candidate; overlay only</td></tr><tr><td>Scope-gated clean comparisons</td><td>$target_compared compared / $target_deferred composite</td><td class="partial">comparison packets only; zero mapping credit</td></tr><tr><td>Composite facet reconciliation</td><td>$target_facet_targets targets / $target_facets facets</td><td class="partial">7 HR · 6 medication · 5 finance; 18/18 required facets; static-source overlay only</td></tr><tr><td>Independent adjudication</td><td>$target_no_go NO-GO / $target_edges formal edges</td><td class="zero">0 promoted mappings or final no-matches; matrix unchanged</td></tr><tr><td>Promoted feature mappings or final no-matches</td><td>$promoted_count</td><td class="zero">0 / 340 canonical targets; zero mappings or final no-matches credited</td></tr></tbody></table></div></section>
    <section id="modules" class="panel"><h2>Canonical static feature modules</h2><p>$module_count module labels across $canonical_count canonical static targets. Module completion credit remains zero.</p><div class="table-wrap"><table><thead><tr><th>Module label</th><th>H</th><th>D</th><th>M</th><th>Total</th></tr></thead><tbody>$module_rows</tbody></table></div></section>
    <section id="findings" class="panel"><h2>Provisional current-source P1 claims</h2><p>None is a final finding, verified exploit, remediated issue, or closed gate.</p><div class="table-wrap"><table><thead><tr><th>ID</th><th>Static concern</th><th>Status</th></tr></thead><tbody>$finding_rows</tbody></table></div></section>
    <section id="gaps" class="panel"><h2>Literal completion gates still open</h2><div class="split"><ul class="list"><li>Framework route reachability and route/page-to-feature mapping</li><li>Static anchor completion: $route_gap_count targets lack route anchors, $page_gap_count lack page anchors, and $both_gap_count lack both</li><li>Complete backend/data/test ownership</li><li>Full current project behaviour/licence/edition triage and one final mapping or documented no-match per frozen feature</li><li>Task scripts and ten ease dimensions per H feature</li></ul><ul class="list"><li>Eight cross-module journeys at required viewports</li><li>Rendered hero, overlay, trigger, and material-state coverage from the completed static matrix</li><li>Safe current-build application browser/runtime lanes</li><li>Every module through Passes 1–8</li><li>Fresh Pass 8, final artifact freeze, reconciliation, and no-live-agent gate</li></ul></div></section>
    <section class="panel"><h2>Evidence files</h2><ul class="list"><li><a href="00-executive-summary.md">Executive summary</a></li><li><a href="01-repository-module-map.md">Current repository and page map</a></li><li><a href="02-repository-module-map-wave-02.md">Wave 02 module map</a></li><li><a href="02-eight-pass-coverage-ledger.csv">Provisional eight-pass route-file ledger</a></li><li><a href="03-feature-to-benchmark-matrix.csv">340-row canonical static feature matrix</a></li><li><a href="05-browser-visual-coverage-matrix.csv">2,812-row static visual matrix</a></li><li><a href="06-open-source-benchmark-register.csv">Current carry-forward benchmark register</a></li><li><a href="evidence/source/current-canonical-feature-identity-wave-01.json">RUN-030 canonical feature identity</a></li><li><a href="evidence/source/current-canonical-identity-agent-register.json">RUN-030 identity agent register</a></li><li><a href="evidence/source/current-static-semantic-census.json">Initial semantic census JSON</a></li><li><a href="evidence/source/current-route-navigation-gap-wave-01.json">Route/navigation reconciliation</a></li><li><a href="evidence/source/current-visual-static-census-wave-01.json">Visual static census</a></li><li><a href="evidence/source/current-visual-matrix-materialization-wave-01.json">Visual matrix materialization evidence</a></li><li><a href="evidence/source/current-visual-matrix-agent-register.json">Visual matrix agent register</a></li><li><a href="evidence/source/current-backend-data-test-census-wave-01.json">Backend/data/test census</a></li><li><a href="evidence/source/current-static-coverage-agent-register.json">Static coverage agent register</a></li><li><a href="evidence/source/current-page-adjudication-wave-01.json">Page adjudication evidence</a></li><li><a href="evidence/source/current-page-agent-register.json">Page agent register</a></li><li><a href="evidence/source/current-feature-discovery-wave-01.json">Feature wave 01 JSON</a></li><li><a href="evidence/source/current-feature-discovery-wave-02.json">Feature wave 02 JSON</a></li><li><a href="evidence/source/current-feature-discovery-wave-03.json">Feature wave 03 gap additions</a></li><li><a href="evidence/benchmark/current-benchmark-wave-01.json">Benchmark wave evidence</a></li><li><a href="evidence/benchmark/current-benchmark-agent-register.json">Benchmark agent register</a></li><li><a href="evidence/benchmark/current-benchmark-metadata-agent-register.json">Benchmark metadata agent register</a></li><li><a href="evidence/benchmark/current-github-project-metadata-snapshot.json">Official GitHub metadata snapshot</a></li><li><a href="evidence/benchmark/current-prompt-project-denominator-reconciliation.json">Prompt project denominator reconciliation</a></li><li><a href="evidence/benchmark/current-upstream-project-triage-wave-01.json">RUN-034 upstream observer triage</a></li><li><a href="evidence/benchmark/current-upstream-project-triage-agent-register.json">RUN-034 upstream triage agent register</a></li><li><a href="evidence/benchmark/current-upstream-partial-resolution-wave-01.json">RUN-038 partial-record review</a></li><li><a href="evidence/benchmark/current-upstream-partial-resolution-agent-register.json">RUN-038 partial-review agent register</a></li><li><a href="evidence/runtime/current-runtime-safety-assessment.json">Runtime safety assessment</a></li><li><a href="evidence/browser/deployed-build-identity-assessment.json">Deployed build identity assessment</a></li><li><a href="13-unresolved-questions-and-evidence-gaps.md">Unresolved evidence gaps</a></li></ul></section>
    <section class="panel"><h2>RUN-039–046 target comparison evidence</h2><p>Clean-stage packets and deterministic integration for the first six-target wave; all formal edges and downstream credits remain zero.</p><ul class="list"><li><a href="evidence/benchmark/raw-run-039-target-upstream-behaviour-wave-01.json">RUN-039 upstream behaviour locators</a></li><li><a href="evidence/benchmark/raw-run-040-current-product-source-packets-wave-01.json">RUN-040 current-source packets</a></li><li><a href="evidence/benchmark/raw-run-041-current-source-red-team-wave-01.json">RUN-041 scope red-team</a></li><li><a href="evidence/benchmark/raw-run-042-neutral-requirements-wave-01.json">RUN-042 blind neutral requirements</a></li><li><a href="evidence/benchmark/raw-run-043-current-neutral-comparison-wave-01.json">RUN-043 clean current-neutral comparisons</a></li><li><a href="evidence/benchmark/raw-run-044-current-source-facet-reconciliation-wave-01.json">RUN-044 composite facet reconciliation</a></li><li><a href="evidence/benchmark/raw-run-045-wave-01-independent-adjudication.json">RUN-045 independent adjudication</a></li><li><a href="evidence/benchmark/current-target-neutral-comparison-wave-01.json">RUN-046 integrated comparison overlay</a></li><li><a href="evidence/benchmark/current-target-neutral-comparison-agent-register.json">RUN-046 comparison agent register</a></li></ul></section>
    <p class="footer">Generated deterministically at $generated_at. Audit artifacts only; no application remediation is authorised.</p>
  </main>
</body>
</html>
""")


dashboard = TEMPLATE.substitute(
    application_short=canonical["source_pin"]["application_commit"][:12],
    route_calls=f"{semantic['routes']['static_route_declaration_callsites']:,}",
    resolver_count=f"{semantic['inertia_pages']['resolver_non_test_tsx']:,}",
    page_root_count=f"{pages['recommended_denominator']['value']:,}",
    support_count=f"{pages['reproduction']['resolver_partition']['unrendered_imported']:,}",
    legacy_count=pages["candidate_class_counts"]["alias/generated/legacy"],
    dead_demo_count=pages["candidate_class_counts"]["dead/unreachable candidate"] + pages["candidate_class_counts"]["test/demo/story"],
    missing_target_count=len(pages["missing_render_target_adjudication"]),
    canonical_count=f"{len(targets):,}",
    source_count=canonical["counts"]["source_candidates"],
    mapped_sources=canonical["counts"]["mapped_sources"],
    layer_a_edges=canonical["counts"]["layer_a_edges"],
    layer_a_targets=canonical["counts"]["layer_a_targets"],
    h_count=class_counts["H"],
    d_count=class_counts["D"],
    m_count=canonical["counts"]["classes"]["M"],
    route_gap_count=canonical["static_evidence_gaps"]["targets_missing_route_anchor"],
    page_gap_count=canonical["static_evidence_gaps"]["targets_missing_page_anchor"],
    both_gap_count=canonical["static_evidence_gaps"]["targets_missing_both_route_and_page_anchor"],
    finding_count=len(findings),
    module_count=len(module_labels),
    module_rows=module_rows,
    finding_rows=finding_rows,
    register_physical=benchmark["project_register_current_audit"]["physical_unique_rows"],
    prompt_occurrences=benchmark["project_register_current_audit"]["prompt_listed_url_occurrences"],
    prompt_unique=benchmark["project_register_current_audit"]["prompt_unique_repositories"],
    extra_rows=benchmark["project_register_current_audit"]["historical_extra_rows_structurally_validated_local_only"],
    metadata_unique=benchmark["project_register_current_audit"]["current_official_github_metadata_unique_repository_coverage"],
    metadata_occurrences=benchmark["project_register_current_audit"]["current_official_github_metadata_prompt_occurrence_coverage"],
    triage_observer_unique=project_triage["project_universe"]["repositories"],
    triage_observer_occurrences=project_triage["project_universe"]["prompt_occurrences"],
    triage_complete=partial_resolution["counts"]["effective_observer_statuses"]["COMPLETE_OBSERVER_TRIAGE"],
    triage_partial=partial_resolution["counts"]["effective_observer_statuses"]["PARTIAL_BLOCKED"],
    partial_reviewed=partial_resolution["counts"]["reviewed_partial_records"],
    partial_resolved=partial_resolution["counts"]["resolution_decisions"]["RESOLVED_OBSERVER_EVIDENCE"],
    partial_retained=partial_resolution["counts"]["resolution_decisions"]["RETAIN_PARTIAL"],
    triage_same_head=project_triage["counts"]["metadata_head_relationships"]["SAME_AS_BASELINE"],
    triage_different_head=project_triage["counts"]["metadata_head_relationships"]["DIFFERENT_LATER_OBSERVATION_NO_ANCESTRY_INFERENCE"],
    triage_unique=benchmark["project_register_current_audit"]["current_upstream_full_triage_unique_repository_completions"],
    triage_occurrences=benchmark["project_register_current_audit"]["current_upstream_full_triage_prompt_occurrence_completions"],
    observer_records=benchmark["observer"]["mapping_records"],
    observer_unique=benchmark["observer"]["unique_current_candidates"],
    neutralizer_count=len(benchmark["neutralizer"]["adjudications"]),
    comparator_count=len(benchmark["native_comparator"]["comparison_packets"]),
    target_wave_targets=target_counts["wave_targets"],
    target_candidate_packets=target_counts["candidate_locator_packets"],
    target_no_candidate_packets=target_counts["bounded_no_candidate_packets"],
    target_compared=target_counts["clean_current_comparisons"],
    target_deferred=target_counts["scope_deferred_composites"],
    target_facet_targets=target_counts["facet_reconciliation_targets"],
    target_facets=target_counts["facet_reconciliation_facets"],
    target_no_go=target_counts["independent_no_go_verdicts"],
    target_edges=target_counts["formal_edges"],
    promoted_count=benchmark["current_feature_gate"]["verified_benchmark_or_documented_no_credible_match"],
    php_version=runtime["sanitized_environment_observations"]["php_version"],
    deployed_component=deployment["deployed_observation"]["inertia_component"],
    route_file_count=route_gap["route_denominator"]["route_php_files"],
    nav_file_count=route_gap["navigation_denominator"]["named_navigation_tab_source_files"],
    hero_definitions=f"{visual['heroes']['definitions']:,}",
    hero_instances=f"{visual['heroes']['instances']:,}",
    overlay_definitions=f"{visual['overlays']['definitions']:,}",
    overlay_instances=f"{visual['overlays']['instances']:,}",
    declarative_triggers=f"{visual['triggers']['declarative_primitive_tags']:,}",
    direct_triggers=f"{visual['triggers']['direct_inline_opening_handler_sites']:,}",
    named_triggers=f"{visual['triggers']['local_named_handler_reference_sites']:,}",
    visual_matrix_rows=f"{visual_matrix['matrix']['rows']:,}",
    models=f"{backend['module_arithmetic']['models']['total']:,}",
    policies=f"{backend['module_arithmetic']['policies']['total']:,}",
    services=f"{backend['module_arithmetic']['service_entry_union']['total']:,}",
    jobs=f"{backend['module_arithmetic']['jobs']['total']:,}",
    events=f"{backend['module_arithmetic']['events']['total']:,}",
    listeners=f"{backend['module_arithmetic']['listeners']['total']:,}",
    migrations=f"{backend['migration_filename_primary_mapping']['total']:,}",
    php_test_cases=f"{backend['tests_static']['lexical_cases']:,}",
    generated_at=target_comparison["generated_at"],
)

(AUDIT_DIR / "audit-dashboard.html").write_text(dashboard.rstrip() + "\n", encoding="utf-8", newline="\n")
