#!/usr/bin/env python3
"""Integrate the first clean target-specific benchmark comparison wave.

The integration preserves the 340 frozen feature IDs and the canonical matrix
byte-for-byte. It validates the Agent A -> red-team -> blind Agent B -> clean
Agent C -> independent Agent D lineage, records the composite-target facet
overlay, and awards no mapping, benchmark, final-no-match, or completion credit.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
GENERATED_AT = "2026-08-24T23:30:00+12:00"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
CANONICAL_IDENTITY_SHA256 = "f4feae2598622afe346b1163fed2bb842305a8d973a89ec890c02746d99b5999"
CANONICAL_MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
GOVERNING_PROMPT = Path("C:/Users/steph/Downloads/oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
GOVERNING_PROMPT_BYTES = 88305

RAW_RUNS = [
    {
        "run_id": "RUN-039",
        "file": "evidence/benchmark/raw-run-039-target-upstream-behaviour-wave-01.json",
        "sha256": "1675bc983ab446b94ed0b175791f81c2c3548a90a3d004e8fb5d1ecde8bfd3db",
        "role": "UPSTREAM_BEHAVIOUR_OBSERVER_AGENT_A",
    },
    {
        "run_id": "RUN-040",
        "file": "evidence/benchmark/raw-run-040-current-product-source-packets-wave-01.json",
        "sha256": "4d9ea76a79e023e69c996ae8685f19651a0e309a1ffb217a889d7058f6505602",
        "role": "CURRENT_PRODUCT_SOURCE_ANALYST_AGENT_C_PRE_NEUTRAL_COMPARISON",
    },
    {
        "run_id": "RUN-041",
        "file": "evidence/benchmark/raw-run-041-current-source-red-team-wave-01.json",
        "sha256": "aef801877a0392df9a84ee77b8a4c573170de68f6841162aea61b655c057ed7c",
        "role": "CURRENT_SOURCE_SCOPE_RED_TEAM",
    },
    {
        "run_id": "RUN-042",
        "file": "evidence/benchmark/raw-run-042-neutral-requirements-wave-01.json",
        "sha256": "e17b9aed0268ca22e2a13c843791d3ca65ce818607a9a10a40429b232beab995",
        "role": "NEUTRAL_REQUIREMENTS_WRITER_AGENT_B",
    },
    {
        "run_id": "RUN-043",
        "file": "evidence/benchmark/raw-run-043-current-neutral-comparison-wave-01.json",
        "sha256": "fd4cb0fca2ca444f36235dab554cf60c9d33e2be6b4cd79e4f4262ad316c2e7c",
        "role": "CLEAN_CURRENT_PRODUCT_NEUTRAL_COMPARATOR_AGENT_C",
    },
    {
        "run_id": "RUN-044",
        "file": "evidence/benchmark/raw-run-044-current-source-facet-reconciliation-wave-01.json",
        "sha256": "b63de4b47c8703de72550c6c94db9bbe86e5c4aca5bdd891bf42e9f3f1114457",
        "role": "CURRENT_SOURCE_COMPOSITE_FACET_RECONCILIATION_AGENT",
    },
    {
        "run_id": "RUN-045",
        "file": "evidence/benchmark/raw-run-045-wave-01-independent-adjudication.json",
        "sha256": "734a0fd3f6c8468a237e0d29af902ef6d622dc4dd2c17981ec4a19f2e9723664",
        "role": "INDEPENDENT_POST_RECONCILIATION_WAVE_ADJUDICATOR_AGENT_D",
    },
]

TARGET_IDS = [
    "CAP-CLIN-OBSERVATION-REGISTER-RECORD",
    "CAP-CLIN-EVENT-REGISTER-RECORD",
    "CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE",
    "CAP-INC-INCIDENT-REVIEW-CLOSURE",
    "CAP-MED-CD-REGISTER-BALANCE",
    "CAP-FIN-ALLOCATION-MATCH-HISTORY",
]
GO_IDS = [
    "CAP-CLIN-OBSERVATION-REGISTER-RECORD",
    "CAP-CLIN-EVENT-REGISTER-RECORD",
    "CAP-INC-INCIDENT-REVIEW-CLOSURE",
]
DEFERRED_IDS = [
    "CAP-HR-EMPLOYEE-PROFILE-LIFECYCLE",
    "CAP-MED-CD-REGISTER-BALANCE",
    "CAP-FIN-ALLOCATION-MATCH-HISTORY",
]


def read_json(relative: str) -> dict[str, Any]:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def write_json(relative: str, payload: object) -> None:
    path = AUDIT_DIR / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def stable_hash(value: object) -> str:
    raw = json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()


def result_map(payload: dict[str, Any], key: str = "results") -> dict[str, dict[str, Any]]:
    rows = payload[key]
    assert isinstance(rows, list)
    mapped = {row["feature_id"]: row for row in rows}
    assert len(mapped) == len(rows)
    return mapped


def assert_credit_zero(value: Any) -> None:
    if isinstance(value, dict):
        for key, child in value.items():
            if key.endswith("_credit"):
                assert child in (False, 0, "false"), (key, child)
            assert_credit_zero(child)
    elif isinstance(value, list):
        for child in value:
            assert_credit_zero(child)


def assert_source_anchor(anchor: str) -> None:
    assert isinstance(anchor, str) and anchor.strip()
    source_spec, separator, line_spec = anchor.partition(":")
    matches = sorted(REPO_DIR.glob(source_spec)) if "*" in source_spec else [REPO_DIR / source_spec]
    assert matches and all(path.is_file() for path in matches), anchor
    if not separator:
        return
    assert re.fullmatch(r"\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*", line_spec), anchor
    line_numbers = [int(value) for value in re.findall(r"\d+", line_spec)]
    for path in matches:
        line_count = len(path.read_text(encoding="utf-8").splitlines())
        assert max(line_numbers) <= line_count, (anchor, line_count)


assert sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv") == CANONICAL_MATRIX_SHA256
assert GOVERNING_PROMPT.is_file()
assert GOVERNING_PROMPT.stat().st_size == GOVERNING_PROMPT_BYTES
assert sha256_file(GOVERNING_PROMPT) == GOVERNING_PROMPT_SHA256
assert sha256_file(
    AUDIT_DIR / "evidence/source/current-canonical-feature-identity-wave-01.json"
) == CANONICAL_IDENTITY_SHA256

raw: dict[str, dict[str, Any]] = {}
for contract in RAW_RUNS:
    path = AUDIT_DIR / contract["file"]
    assert path.is_file()
    assert sha256_file(path) == contract["sha256"], contract["run_id"]
    payload = read_json(contract["file"])
    assert payload["run_id"] == contract["run_id"]
    if contract["role"] is not None:
        assert payload["role"] == contract["role"]
    assert_credit_zero(payload)
    raw[contract["run_id"]] = payload

run39 = raw["RUN-039"]
run40 = raw["RUN-040"]
run41 = raw["RUN-041"]
run42 = raw["RUN-042"]
run43 = raw["RUN-043"]
run44 = raw["RUN-044"]
run45 = raw["RUN-045"]

assert run39["input_pins"]["canonical_identity_sha256"] == CANONICAL_IDENTITY_SHA256
assert run39["input_pins"]["canonical_matrix_sha256"] == CANONICAL_MATRIX_SHA256
assert run39["input_pins"]["application_commit_not_inspected_by_agent_a"] == APPLICATION_COMMIT
assert run39["external_mutations_attestation"] == "NONE_READ_ONLY_OFFICIAL_SOURCE_REVIEW"
assert result_map(run39).keys() == result_map(run40).keys() == result_map(run41).keys()
assert list(result_map(run39)) == TARGET_IDS
assert run39["counts"]["targets"] == 6
assert run39["counts"]["candidate_locator_packets"] == 5
assert run39["counts"]["bounded_no_candidate_packets"] == 1

assert run40["source_pin"]["application_commit"] == APPLICATION_COMMIT
assert run40["source_pin"]["application_tree"] == APPLICATION_TREE
assert run40["source_pin"]["canonical_identity_sha256"] == CANONICAL_IDENTITY_SHA256
assert run40["methodology"]["upstream_project_evidence_inspected"] is False
assert run40["counts"]["source_packets"] == 6

scope = result_map(run41)
assert run41["source_pin"]["application_commit"] == APPLICATION_COMMIT
assert run41["source_pin"]["application_tree"] == APPLICATION_TREE
assert run41["counts"]["targets_reviewed"] == 6
assert Counter(row["verdict"] for row in scope.values()) == {
    "GO_BOUNDED": 3,
    "NO_GO_RECONCILE_FACETS": 3,
}
assert [feature_id for feature_id in TARGET_IDS if scope[feature_id]["verdict"] == "GO_BOUNDED"] == GO_IDS
assert [feature_id for feature_id in TARGET_IDS if scope[feature_id]["verdict"] != "GO_BOUNDED"] == DEFERRED_IDS

neutral = result_map(run42)
assert list(neutral) == GO_IDS
assert run42["input_boundary"]["upstream_behaviour_raw_sha256"] == RAW_RUNS[0]["sha256"]
assert run42["input_boundary"]["identity_envelope_withheld"] is True
assert run42["input_boundary"]["upstream_repository_identity_received"] is False
assert run42["input_boundary"]["current_oblivion_source_received"] is False
assert run42["orchestration_gate"]["scope_red_team_raw_sha256"] == RAW_RUNS[2]["sha256"]
assert run42["orchestration_gate"]["go_targets_forwarded"] == GO_IDS
assert run42["orchestration_gate"]["deferred_targets"] == DEFERRED_IDS
assert run42["counts"]["neutralized_packets"] == 3

comparison = result_map(run43)
assert list(comparison) == GO_IDS
assert run43["inputs"]["current_source_packets_sha256"] == RAW_RUNS[1]["sha256"]
assert run43["inputs"]["scope_red_team_sha256"] == RAW_RUNS[2]["sha256"]
assert run43["inputs"]["neutral_requirements_sha256"] == RAW_RUNS[3]["sha256"]
assert run43["methodology"]["project_identities_seen"] is False
assert run43["methodology"]["upstream_repository_evidence_seen"] is False
assert [row["feature_id"] for row in run43["deferred"]] == DEFERRED_IDS
assert run43["counts"]["bounded_comparisons"] == 3
assert run43["counts"]["direct_full_edges"] == 0

facets = result_map(run44, key="targets")
assert list(facets) == sorted(DEFERRED_IDS)
assert run44["application_pin"] == {
    "commit_sha": APPLICATION_COMMIT,
    "tree_sha": APPLICATION_TREE,
}
assert run44["generated_at"] == "2026-08-24T23:07:00+12:00"
assert run44["responsible_agent_identity"] == "/root/benchmark_triage_032 with /root/benchmark_triage_032/hr_facet_overlay"
assert {row["run_id"]: row["sha256"] for row in run44["input_bindings"]} == {
    "RUN-040": RAW_RUNS[1]["sha256"],
    "RUN-041": RAW_RUNS[2]["sha256"],
}
assert run44["denominator"] == {
    "frozen_feature_count": 340,
    "feature_ids_changed": False,
    "overlay_only": True,
}
assert run44["validation"]["target_count"] == 3
assert run44["validation"]["facet_count"] == 18
assert run44["validation"]["run_041_required_facet_count"] == 18
assert run44["validation"]["required_facet_crosswalk_count"] == 18
assert run44["validation"]["unmapped_required_facets"] == 0
assert run44["validation"]["all_credit_flags_false"] is True
assert sum(len(row["facets"]) for row in facets.values()) == 18
for feature_id, row in facets.items():
    assert [item["facet_ordinal"] for item in row["facets"]] == list(
        range(1, len(row["facets"]) + 1)
    )
    required = scope[feature_id]["required_facets"]
    crosswalk = row["required_facet_crosswalk"]
    assert [item["run_041_required_facet"] for item in crosswalk] == required
    assert len(crosswalk) == len(required)
    overlay_keys = {item["facet_key"] for item in row["facets"]}
    covered_overlay_keys: set[str] = set()
    for item in crosswalk:
        mapped_keys = {value.strip() for value in item["overlay_facet"].split("+")}
        assert mapped_keys.issubset(overlay_keys)
        covered_overlay_keys.update(mapped_keys)
    assert covered_overlay_keys == overlay_keys
    for facet in row["facets"]:
        assert facet["evidence_class"] == "STATIC_SOURCE_ONLY"
        for anchor in facet["anchors"]:
            assert_source_anchor(anchor)

for row in run40["results"]:
    for anchor in row["anchors"]:
        assert_source_anchor(anchor)
for row in run41["results"]:
    for anchor in row["anchor_corrections"]:
        assert_source_anchor(anchor)
for row in run43["results"]:
    for anchor in row["anchors"]:
        assert_source_anchor(anchor)

adjudication = result_map(run45)
assert list(adjudication) == TARGET_IDS
assert run45["inputs"]["application_commit"] == APPLICATION_COMMIT
assert run45["inputs"]["application_tree"] == APPLICATION_TREE
assert run45["inputs"]["canonical_identity_sha256"] == CANONICAL_IDENTITY_SHA256
assert run45["inputs"]["canonical_matrix_sha256"] == CANONICAL_MATRIX_SHA256
assert run45["responsible_agent_identity"] == "/root/run046_redteam"
assert run45["lineage_validation"]["status"] == "PASS"
assert run45["lineage_validation"]["remaining_stage_conflicts"] == 0
assert run45["source_anchor_validation"]["status"] == "PASS"
assert run45["source_anchor_validation"]["anchor_occurrences_checked"] == 210
assert run45["source_anchor_validation"]["missing_paths"] == 0
assert run45["source_anchor_validation"]["invalid_or_out_of_range_line_specs"] == 0
assert run45["facet_closure"]["status"] == "PASS_SOURCE_FACET_CLOSURE_ONLY_NO_FORMAL_EDGE"
assert run45["facet_closure"]["required_facet_count"] == 18
assert run45["facet_closure"]["crosswalk_count"] == 18
assert run45["facet_closure"]["unmapped_required_facets"] == 0
assert {row["feature_id"] for row in run45["facet_closure"]["targets"]} == set(DEFERRED_IDS)
assert run45["counts"]["targets"] == 6
assert run45["counts"]["required_facets"] == 18
assert run45["counts"]["crosswalked_facets"] == 18
assert run45["counts"]["unmapped_required_facets"] == 0
assert run45["counts"]["no_go"] == 6
assert run45["counts"]["formal_edges"] == 0
assert {row["run_id"]: row["sha256"] for row in run45["inputs"]["raw_runs"]} == {
    row["run_id"]: row["sha256"] for row in RAW_RUNS[:6]
}
assert all(row["formal_edge_count"] == 0 for row in adjudication.values())
assert run45["canonical_matrix_disposition"] == {
    "status": "UNCHANGED_GUARDED_OVERLAY_ONLY",
    "sha256": CANONICAL_MATRIX_SHA256,
    "promoted_feature_mappings_or_final_no_matches": 0,
}

with (AUDIT_DIR / "03-feature-to-benchmark-matrix.csv").open(
    encoding="utf-8", newline=""
) as handle:
    matrix_rows = list(csv.DictReader(handle))
matrix_by_id = {row["feature_id"]: row for row in matrix_rows}
assert len(matrix_rows) == len(matrix_by_id) == 340
assert all(feature_id in matrix_by_id for feature_id in TARGET_IDS)
assert all(matrix_by_id[feature_id]["benchmark_mapping_credit"] == "false" for feature_id in TARGET_IDS)

upstream = result_map(run39)
current = result_map(run40)
records: list[dict[str, Any]] = []
for feature_id in TARGET_IDS:
    record: dict[str, Any] = {
        "feature_id": feature_id,
        "user_job": upstream[feature_id]["user_job"],
        "feature_class": matrix_by_id[feature_id]["feature_class"],
        "upstream_packet": {
            "packet_id": upstream[feature_id]["packet_id"],
            "packet_status": upstream[feature_id]["packet_status"],
            "identity_envelope": upstream[feature_id]["identity_envelope"],
            "sanitized_behaviour": upstream[feature_id]["sanitized_behaviour"],
        },
        "current_source_packet": {
            "canonical_owner": current[feature_id]["canonical_owner"],
            "anchors": current[feature_id]["anchors"],
            "strengths": current[feature_id]["strengths"],
            "gaps": current[feature_id]["gaps"],
        },
        "scope_red_team": {
            "verdict": scope[feature_id]["verdict"],
            "reason": scope[feature_id]["reason"],
        },
        "neutral_requirements": neutral.get(feature_id),
        "current_neutral_comparison": comparison.get(feature_id),
        "facet_reconciliation": facets.get(feature_id),
        "independent_adjudication": adjudication[feature_id],
        "formal_edge_count": 0,
        "target_specific_mapping_credit": False,
        "benchmark_credit": False,
        "final_no_match_credit": False,
        "runtime_credit": False,
        "test_execution_credit": False,
        "completion_credit": False,
    }
    record["integrated_record_sha256"] = stable_hash(record)
    records.append(record)

output = {
    "schema_version": 1,
    "run_id": "RUN-046",
    "generated_at": GENERATED_AT,
    "status": "FIRST_SIX_TARGET_CLEAN_COMPARISON_WAVE_INTEGRATED_ZERO_FORMAL_EDGES",
    "source_pin": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "canonical_identity_sha256": CANONICAL_IDENTITY_SHA256,
        "canonical_matrix_guard_sha256": CANONICAL_MATRIX_SHA256,
    },
    "inputs": {"raw_runs": RAW_RUNS},
    "stage_lineage": {
        "status": "PASS",
        "upstream_identity_withheld_from_agent_b": True,
        "current_source_withheld_from_agent_b": True,
        "upstream_identity_withheld_from_agent_c": True,
        "scope_gate_hash_bound": True,
        "post_reconciliation_independent_adjudication": True,
        "source_anchor_validation_pass": True,
        "required_facet_crosswalk_pass": True,
    },
    "counts": {
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
    },
    "records": records,
    "canonical_matrix_disposition": {
        "status": "UNCHANGED_GUARDED_OVERLAY_ONLY",
        "sha256": CANONICAL_MATRIX_SHA256,
        "reason": "The independent adjudicator approved zero formal edges; evidence remains in this overlay until a clean target-specific edge or documented final no-match passes.",
    },
    "credit_boundary": {
        "neutral_requirement_packets_materialized": 3,
        "current_source_comparison_packets_materialized": 3,
        "facet_reconciliation_packets_materialized": 18,
        "target_specific_mapping_credit": 0,
        "benchmark_credit": 0,
        "final_no_match_credit": 0,
        "runtime_credit": 0,
        "browser_credit": 0,
        "test_execution_credit": 0,
        "ease_credit": 0,
        "release_credit": 0,
        "completion_credit": 0,
        "audit_complete": False,
    },
}

agent_register = {
    "schema_version": 1,
    "run_id": "RUN-046",
    "generated_at": GENERATED_AT,
    "status": "CLEAN_STAGE_AND_INDEPENDENT_ADJUDICATION_REGISTER_COMPLETE",
    "governing_prompt": {
        "path": GOVERNING_PROMPT.as_posix(),
        "sha256": GOVERNING_PROMPT_SHA256,
        "bytes": GOVERNING_PROMPT_BYTES,
    },
    "root_integrator": {
        "responsible_agent_identity": "/root",
        "repository_commit_scope": f"Oblivion application {APPLICATION_COMMIT} / tree {APPLICATION_TREE}; audit overlay on current main",
        "architecture_rule": "Only the root integrator writes normalized audit artifacts; preserve canonical owners, Site boundaries, and the frozen 340-ID denominator.",
        "scope": "Validate and integrate RUN-039 through RUN-045 into RUN-046 without changing the canonical matrix.",
        "pass_lens": "Deterministic hash, lineage, denominator, facet-crosswalk, anchor, credit, and idempotence integration.",
        "evidence_schema_and_count": "RUN-046 wave JSON with 6 records plus one 8-stage provenance register.",
        "no_write_rule": "No application-source, runtime, database, browser-application, upstream-repository, or external-system mutation.",
        "completion_test": "Every raw hash and source anchor validates; 18/18 required facets crosswalk; independent adjudication includes RUN-044; matrix hash remains unchanged; outputs regenerate byte-identically.",
        "unresolved_gaps": "Six NO-GO target actions, 334 unprocessed canonical targets, seven project-level observer blockers, and every runtime/ease/release/completion gate remain open.",
    },
    "agents": [
        {
            "stage": "A_UPSTREAM_BEHAVIOUR",
            "raw_run": "RUN-039",
            "raw_file": RAW_RUNS[0]["file"],
            "raw_sha256": RAW_RUNS[0]["sha256"],
            "boundary": "Official immutable upstream source only; current source not inspected.",
            "responsible_agent_identity": "/root/benchmark_triage_031",
            "repository_commit_scope": "Official upstream repositories at the immutable commits recorded in each RUN-039 identity envelope; Oblivion source excluded.",
            "architecture_rule": "Record upstream behavior and ownership only; do not neutralize, compare, select, map, or copy.",
            "scope": "Six exact canonical target jobs; exclude all seven RUN-038 retained-partial projects.",
            "pass_lens": "Upstream behavior, root licence, edition boundary, canonical owner, and failure semantics locator review.",
            "evidence_schema_and_count": "6 behavior packets: 5 candidate locators and 1 bounded no-candidate packet.",
            "no_write_rule": "Official-source read only; no clone, install, execution, repository write, or external mutation.",
            "completion_test": "One bounded packet for each of the six feature IDs with immutable source identity and every credit false.",
            "unresolved_gaps": "Locator packets are not exact target mappings; the bounded medication no-candidate packet is not a final no-match.",
        },
        {
            "stage": "C_PRECOMPARISON_CURRENT_SOURCE",
            "raw_run": "RUN-040",
            "raw_file": RAW_RUNS[1]["file"],
            "raw_sha256": RAW_RUNS[1]["sha256"],
            "boundary": "Pinned current source only; upstream identities and conclusions not inspected.",
            "responsible_agent_identity": "/root/benchmark_triage_033",
            "repository_commit_scope": f"Oblivion application {APPLICATION_COMMIT} / tree {APPLICATION_TREE}; upstream repositories excluded.",
            "architecture_rule": "Identify the canonical native owner, Site and direct-object boundary, state, side effects, transaction, locks, replay, and test locators without benchmark inference.",
            "scope": "Six exact current-source target packets.",
            "pass_lens": "Current source ownership and control-strength inventory before clean comparison.",
            "evidence_schema_and_count": "6 current-source packets with corrected existing anchors and explicit zero runtime/test/mapping credit.",
            "no_write_rule": "Read-only source inspection; no runtime, tests, database, browser application, edits, or external access.",
            "completion_test": "One pinned packet per target with canonical owner, anchors, strengths, gaps, and every credit false.",
            "unresolved_gaps": "Source locators were not executed; composite-target ownership and exact benchmark relation remained unresolved.",
        },
        {
            "stage": "SCOPE_RED_TEAM",
            "raw_run": "RUN-041",
            "raw_file": RAW_RUNS[2]["file"],
            "raw_sha256": RAW_RUNS[2]["sha256"],
            "boundary": "Current source scope and owner collisions only; 3 GO and 3 deferred.",
            "responsible_agent_identity": "/root/benchmark_triage_032",
            "repository_commit_scope": f"Oblivion application {APPLICATION_COMMIT} / tree {APPLICATION_TREE}; upstream repositories excluded.",
            "architecture_rule": "Do not neutralize a target when materially different native owners or authority/replay seams are combined.",
            "scope": "Adversarial scope decision for the six target packets.",
            "pass_lens": "Owner collision, action-facet, Site, concealment, transaction, and replay red team.",
            "evidence_schema_and_count": "6 scope verdicts: 3 GO_BOUNDED and 3 NO_GO_RECONCILE_FACETS with 18 required deferred facets.",
            "no_write_rule": "Read-only current-source analysis; no upstream inspection, runtime, tests, database, browser application, or edits.",
            "completion_test": "Every target receives GO or NO-GO, exact corrected anchors, scope guards or required facets, and zero credit.",
            "unresolved_gaps": "Three composite targets required a machine-closed facet overlay before neutralization.",
        },
        {
            "stage": "B_BLIND_NEUTRAL_REQUIREMENTS",
            "raw_run": "RUN-042",
            "raw_file": RAW_RUNS[3]["file"],
            "raw_sha256": RAW_RUNS[3]["sha256"],
            "boundary": "Sanitized behavior only; upstream identities and current source withheld.",
            "responsible_agent_identity": "/root/neutralizer_wave_01",
            "repository_commit_scope": "No repository access; only sanitized RUN-039 behavior fields and the hash-bound RUN-041 GO/defer gate.",
            "architecture_rule": "Rewrite observations into product-neutral actor needs, states, safeguards, recovery, and negative acceptance; never identify or copy a project.",
            "scope": "The three RUN-041 GO_BOUNDED target packets only.",
            "pass_lens": "Blind neutral requirement extraction and collision exclusion.",
            "evidence_schema_and_count": "3 neutral packets plus 3 explicitly deferred composite IDs.",
            "no_write_rule": "No filesystem, repository, web, runtime, test, database, or external-system access.",
            "completion_test": "Identity envelope and current source remain withheld; every GO target has a neutral packet and every deferred target remains named; zero credit.",
            "unresolved_gaps": "Site, direct-object, audit, replay, concurrency, and ownership facts not established by sanitized behavior stay unknown.",
        },
        {
            "stage": "C_CLEAN_CURRENT_COMPARISON",
            "raw_run": "RUN-043",
            "raw_file": RAW_RUNS[4]["file"],
            "raw_sha256": RAW_RUNS[4]["sha256"],
            "boundary": "Neutral requirements plus pinned current source; upstream identities withheld.",
            "responsible_agent_identity": "/root/benchmark_triage_033",
            "repository_commit_scope": f"Oblivion application {APPLICATION_COMMIT} / tree {APPLICATION_TREE}; upstream project identities excluded.",
            "architecture_rule": "Compare neutral facets to current source as MET, STRONGER, GAP, or CONTRADICTED while preserving native ownership.",
            "scope": "The three clean neutral packets; HR, medication, and finance remain deferred.",
            "pass_lens": "Clean current comparison, native-preserve contract, negative acceptance, and provisional outcome.",
            "evidence_schema_and_count": "3 current-neutral comparison packets and 3 explicit deferred composite records.",
            "no_write_rule": "Read-only pinned-source comparison; no upstream identity, runtime, tests, database, browser application, or edits.",
            "completion_test": "Every neutral facet receives an evidence rating and anchor, native refinements and negative cases are bounded, and all credits remain false.",
            "unresolved_gaps": "All three comparisons remain refinement-only or ownership-boundary NO-GO candidates.",
        },
        {
            "stage": "COMPOSITE_FACET_RECONCILIATION",
            "raw_run": "RUN-044",
            "raw_file": RAW_RUNS[5]["file"],
            "raw_sha256": RAW_RUNS[5]["sha256"],
            "boundary": "Current source only; 18 ordered facets under 3 unchanged IDs with complete RUN-041 crosswalk.",
            "responsible_agent_identity": "/root/benchmark_triage_032 with /root/benchmark_triage_032/hr_facet_overlay",
            "repository_commit_scope": f"Oblivion application {APPLICATION_COMMIT} / tree {APPLICATION_TREE}; upstream repositories excluded.",
            "architecture_rule": "Preserve frozen IDs but materialize every required native action or missing-capability facet without merging authority, Site, state, transaction, or replay claims.",
            "scope": "Finance 5, HR 7, and medication 6 facets from the RUN-041 deferred set.",
            "pass_lens": "Composite-facet closure, corrected anchors, contradictions, external-owner exclusions, and zero-credit overlay.",
            "evidence_schema_and_count": "3 target overlays, 18 ordered facets, and an 18-row machine-readable RUN-041 crosswalk with zero omissions.",
            "no_write_rule": "Read-only current-source analysis; no upstream inspection, runtime, tests, database, browser application, or edits.",
            "completion_test": "All 18 RUN-041 required facets crosswalk to explicit overlay facets, anchors exist within range, frozen IDs remain unchanged, and every credit is false.",
            "unresolved_gaps": "Facet reconciliation is source evidence only and does not authorize neutral requirements, mapping, or final no-match credit.",
        },
        {
            "stage": "D_INDEPENDENT_ADJUDICATION",
            "raw_run": "RUN-045",
            "raw_file": RAW_RUNS[6]["file"],
            "raw_sha256": RAW_RUNS[6]["sha256"],
            "boundary": "Independent prompt adjudication of RUN-039 through RUN-044; 6 NO-GO and zero credits.",
            "responsible_agent_identity": "/root/run046_redteam",
            "repository_commit_scope": f"Governing prompt plus RUN-039 through RUN-044 at Oblivion application {APPLICATION_COMMIT} / tree {APPLICATION_TREE}.",
            "architecture_rule": "Award a formal edge only when clean lineage, exact owner/facet semantics, source anchors, and prompt evidence gates all pass.",
            "scope": "All six targets, including independent closure review of all 18 RUN-044 deferred facets.",
            "pass_lens": "Lineage, anchor, facet closure, equivalence, disqualifier, next-action, and zero-credit adjudication.",
            "evidence_schema_and_count": "6 target verdicts plus 18-facet closure adjudication and exact RUN-039 through RUN-044 hashes.",
            "no_write_rule": "Read-only independent adjudication; no browser, web, app runtime, tests, database, repository edits, or external mutation.",
            "completion_test": "RUN-044 is hash-bound and adjudicated, 18/18 facets are closed, every target has a deterministic verdict and next action, and every credit remains false.",
            "unresolved_gaps": "Six target next actions and the remaining 334-target denominator stay open.",
        },
    ],
    "agreement": {
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
    },
    "credit_boundary": output["credit_boundary"],
}

write_json("evidence/benchmark/current-target-neutral-comparison-wave-01.json", output)
write_json(
    "evidence/benchmark/current-target-neutral-comparison-agent-register.json",
    agent_register,
)
