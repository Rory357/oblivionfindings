#!/usr/bin/env python3
"""Seal the corrected RUN-053 -> RUN-055 chain for fresh RUN-056 review."""

from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
EVIDENCE_DIR = AUDIT_DIR / "evidence" / "benchmark"
PROMPT_PATH = Path(
    r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
)
OUTPUT = EVIDENCE_DIR / "raw-run-056-independent-adjudicator-input-wave-02.json"
GENERATED_AT = "2026-08-25T03:12:00+12:00"

APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
RUN047_SHA256 = "d41cc046de9b7580e937ca1b9d1df7f9237947dcaed5bf0f95772b667d8d9f3e"
RUN053_SHA256 = "835ec0755a5a4b5543f317969e9ba557bc156b137083df925f05d4b753ccde6b"
RUN053_CROSSWALK_SHA256 = "78f1e5c77c3dda80048a7b2d6d03859bc22bfe01afba5547ae20ffe797839dfe"
RUN054_SHA256 = "7e1e2203dd5af9852f69b1ff5ad05a5d031e4d8d12096ee39055129954f01a68"
RUN054_PAYLOAD_SHA256 = "6cd60a87324025e4e63586dd75a5262f5ed06d9081d051882b0d9ad338895b5e"
RUN054_CORRECTION_SHA256 = "1f197b817e5e184efbcf4e7549664008aa3fe331d1b4d4ab5470a3a214364b8b"
RUN055_INPUT_SHA256 = "5422d3b25795189be5fd5070bb18d3e82af17b718fd821f735b5bc0d9c9a57e7"
RUN055_INPUT_PACKET_SET_SHA256 = "ba8e33f2390966ba6c329b1f433d984f8bc167e5e12e62045a3d64035c3050d4"
RUN055_OUTPUT_SHA256 = "666c6de668cc8f1db3661e55da309034992d1e27d904c740159bc4f3039fb275"
RUN055_AGENT_MESSAGE_SHA256 = "24da489b1b10032265ee4b4dca9e3b98b07d4b2bf603c9bceb8e637c34dcd2a1"
RUN055_ROW_SET_SHA256 = "b4e4b410e9a53884a4dbfb9149c86279ecbb9fbdb76d2a4e66066ef81699f204"

FILES = {
    "run_047": "current-upstream-facet-refinement-wave-02.json",
    "run_053": "raw-run-053-agent-a-blind-observed-behaviour-packets-wave-02.json",
    "run_053_crosswalk": "root-run-053-agent-a-source-atom-crosswalk-wave-02.json",
    "run_054": "raw-run-054-fresh-agent-b-neutral-requirements-wave-02.json",
    "run_054_correction": "raw-run-054-agent-b-input-boundary-correction-wave-02.json",
    "run_055_input": "raw-run-055-agent-c-comparison-input-wave-02.json",
    "run_055_output": "raw-run-055-fresh-agent-c-current-comparison-wave-02.json",
}

EXPECTED_HASHES = {
    "run_047": RUN047_SHA256,
    "run_053": RUN053_SHA256,
    "run_053_crosswalk": RUN053_CROSSWALK_SHA256,
    "run_054": RUN054_SHA256,
    "run_054_correction": RUN054_CORRECTION_SHA256,
    "run_055_input": RUN055_INPUT_SHA256,
    "run_055_output": RUN055_OUTPUT_SHA256,
}

LENSES = [
    "authorization_scope",
    "state_read_projection",
    "integrity_audit_provenance",
    "replay_concurrency",
    "privacy_direct_object",
    "collision_exclusions",
]
ALLOWED_RATINGS = {
    "EVIDENCED_MET",
    "EVIDENCED_PARTIAL",
    "EVIDENCED_GAP",
    "EVIDENCED_CONTRADICTED",
    "NOT_EVIDENCED",
    "NOT_APPLICABLE",
}


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def stable_hash(value: object) -> str:
    encoded = json.dumps(
        value,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    ).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


assert sha256_file(PROMPT_PATH) == PROMPT_SHA256
assert sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv") == MATRIX_SHA256
for key, name in FILES.items():
    assert sha256_file(EVIDENCE_DIR / name) == EXPECTED_HASHES[key], key

resolved_tree = subprocess.check_output(
    ["git", "rev-parse", f"{APPLICATION_COMMIT}^{{tree}}"],
    cwd=REPO_DIR,
    text=True,
).strip()
assert resolved_tree == APPLICATION_TREE
changed_since_pin = subprocess.check_output(
    ["git", "diff", "--name-only", f"{APPLICATION_COMMIT}..HEAD"],
    cwd=REPO_DIR,
    text=True,
).splitlines()
assert not [path for path in changed_since_pin if not path.startswith("docs/")]

payloads = {key: load_json(EVIDENCE_DIR / name) for key, name in FILES.items()}
run47 = payloads["run_047"]
run53 = payloads["run_053"]
crosswalk = payloads["run_053_crosswalk"]
run54 = payloads["run_054"]
correction = payloads["run_054_correction"]
run55_input = payloads["run_055_input"]
run55_output = payloads["run_055_output"]

assert run47["run_id"] == "RUN-047"
assert run53["run_id"] == "RUN-053"
assert run54["run_id"] == "RUN-054"
assert run55_input["run_id"] == "RUN-055-INPUT"
assert run55_output["run_id"] == "RUN-055-AGENT-C-COMPARISON-WAVE-02"
assert run54["agent_return_integrity"]["canonical_payload_sha256"] == RUN054_PAYLOAD_SHA256
assert correction["correction_scope"] == "PROVENANCE_WORDING_ONLY"
assert correction["existing_payload"]["canonical_sha256"] == RUN054_PAYLOAD_SHA256
assert correction["existing_payload"]["byte_unchanged"] is True
assert correction["existing_payload"]["semantically_unchanged"] is True
assert correction["existing_payload"]["neutral_rows_changed"] is False
assert correction["corrected_input_boundary"]["members_actually_read"] == ["packets"]
assert correction["corrected_input_boundary"]["additional_files_or_inputs_accessed"] is False
assert run55_input["input_integrity"]["comparison_packet_set_sha256"] == RUN055_INPUT_PACKET_SET_SHA256
assert stable_hash(run55_output["rows"]) == RUN055_ROW_SET_SHA256

expected_ids = {f"A53-{index:03d}" for index in range(1, 25)}
upstream_rows = {(row["feature_id"], row["facet_key"]): row for row in run47["facets"]}
blind_rows = {row["blind_packet_id"]: row for row in run53["packets"]}
crosswalk_rows = {row["blind_packet_id"]: row for row in crosswalk["packets"]}
neutral_rows = {row["opaque_id"]: row for row in run54["rows"]}
input_rows = {row["opaque_id"]: row for row in run55_input["comparison_packets"]}
output_rows = {row["opaque_id"]: row for row in run55_output["rows"]}
assert all(len(rows) == 24 for rows in [upstream_rows, blind_rows, crosswalk_rows, neutral_rows, input_rows, output_rows])
assert set(blind_rows) == set(crosswalk_rows) == set(neutral_rows) == set(input_rows) == set(output_rows) == expected_ids

rating_counts: Counter[str] = Counter()
packets: list[dict[str, Any]] = []
feature_ids: set[str] = set()
anchor_occurrences = 0
acceptance_outcomes = 0
explicit_unknowns = 0

for opaque_id in sorted(expected_ids):
    binding = crosswalk_rows[opaque_id]
    pair = (binding["source_feature_id"], binding["source_facet_key"])
    assert pair in upstream_rows
    upstream = upstream_rows[pair]
    blind = blind_rows[opaque_id]
    neutral = neutral_rows[opaque_id]
    c_input = input_rows[opaque_id]
    c_output = output_rows[opaque_id]
    unit = neutral["specification_units"][0]

    assert binding["facet_record_sha256"] == upstream["facet_record_sha256"]
    assert c_input["target_contract"]["feature_id"] == pair[0]
    assert c_input["target_contract"]["facet_key"] == pair[1]
    assert c_input["neutral_specification"] == neutral
    assert c_output["source_sufficiency"] == neutral["source_sufficiency"]
    assert c_output["unit_id"] == unit["id"]
    assert c_output["drift"]["source_result"] == neutral["target_drift_assessment"]["result"]
    assert c_output["credit"] is False
    assert [row["lens"] for row in c_output["lenses"]] == LENSES
    assert all(row["rating"] in ALLOWED_RATINGS for row in c_output["lenses"])
    assert all(set(row["cite"]).issubset(c_output["anchor_catalog"]) for row in c_output["lenses"])
    assert set(c_output["anchor_catalog"].values()).issubset(
        set(c_input["current_source_evidence"]["packet"]["anchors"])
    )
    expected_outcomes = [row["id"] for row in unit["acceptance_outcomes"]]
    assert [row["id"] for row in c_output["unit"]["outcomes"]] == expected_outcomes
    assert c_output["unknowns"]["count"] == len(unit["explicit_unknowns"])
    if neutral["source_sufficiency"] != "EXACT_OBSERVED_BEHAVIOUR":
        assert c_output["neutral_promotable"] is False
        assert c_output["non_promotable_preserved"] is True

    feature_ids.add(pair[0])
    rating_counts.update(row["rating"] for row in c_output["lenses"])
    anchor_occurrences += len(c_input["current_source_evidence"]["packet"]["anchors"])
    acceptance_outcomes += len(expected_outcomes)
    explicit_unknowns += len(unit["explicit_unknowns"])
    packets.append(
        {
            "opaque_id": opaque_id,
            "reattached_identity": {
                "feature_id": pair[0],
                "facet_key": pair[1],
                "facet_record_sha256": upstream["facet_record_sha256"],
            },
            "upstream_observation": upstream,
            "blind_agent_a_packet": blind,
            "source_atom_crosswalk": binding,
            "neutral_specification": neutral,
            "target_and_current_source_packet": c_input,
            "fresh_agent_c_comparison": c_output,
            "adjudication_boundary": {
                "exact_packet_may_be_semantically_reviewed": neutral["source_sufficiency"] == "EXACT_OBSERVED_BEHAVIOUR",
                "adjacent_packet_must_remain_non_promotable": neutral["source_sufficiency"] != "EXACT_OBSERVED_BEHAVIOUR",
                "formal_mapping_authorized": False,
                "final_no_match_authorized": False,
            },
        }
    )

assert len(feature_ids) == 6
assert sum(rating_counts.values()) == 144
assert acceptance_outcomes == 58
assert explicit_unknowns == 85
assert anchor_occurrences == 155
assert run55_output["counts"]["lens_rating_counts"]["aggregate"] == dict(rating_counts)
assert all(value is True for value in run55_output["validation"].values())
assert all(
    value is False
    for key, value in run55_output["zero_credit_validation"].items()
    if key not in {"formal_edge_count", "final_no_match_count"}
)
assert run55_output["zero_credit_validation"]["formal_edge_count"] == 0
assert run55_output["zero_credit_validation"]["final_no_match_count"] == 0

prompt_lines = PROMPT_PATH.read_text(encoding="utf-8").splitlines()
prompt_extract_ranges = [(5, 7), (15, 19), (207, 213), (261, 264), (322, 329), (435, 440), (622, 622)]
prompt_extracts = [
    {
        "line_start": start,
        "line_end": end,
        "text": "\n".join(prompt_lines[start - 1 : end]),
    }
    for start, end in prompt_extract_ranges
]

manifest_files = {
    key: {
        "path": FILES[key],
        "sha256": EXPECTED_HASHES[key],
        "bytes": (EVIDENCE_DIR / FILES[key]).stat().st_size,
    }
    for key in FILES
}
manifest = {
    "prompt": {"path": str(PROMPT_PATH), "sha256": PROMPT_SHA256, "bytes": PROMPT_PATH.stat().st_size},
    "canonical_matrix": {
        "path": "03-feature-to-benchmark-matrix.csv",
        "sha256": MATRIX_SHA256,
        "rows": 340,
        "credited_rows": 0,
    },
    "application": {"commit": APPLICATION_COMMIT, "tree": APPLICATION_TREE},
    "files": manifest_files,
    "run_054_canonical_agent_payload_sha256": RUN054_PAYLOAD_SHA256,
    "run_055_exact_agent_message_sha256": RUN055_AGENT_MESSAGE_SHA256,
    "run_055_canonical_comparison_row_set_sha256": RUN055_ROW_SET_SHA256,
}

output = {
    "schema_version": 1,
    "run_id": "RUN-056-INPUT",
    "generated_at": GENERATED_AT,
    "role": "SEALED_INPUT_FOR_FRESH_INDEPENDENT_CORRECTED_CHAIN_ADJUDICATOR",
    "responsible_orchestrator_identity": "/root",
    "status": "SEALED_AFTER_FRESH_AGENT_C_OUTPUT_ZERO_CREDIT",
    "sealed_manifest": manifest,
    "manifest_sha256": stable_hash(manifest),
    "prompt_boundary_extracts": prompt_extracts,
    "agent_d_input_boundary": {
        "allowed_file": str(OUTPUT.resolve()),
        "all_other_files": "PROHIBITED",
        "old_run_048_run_050_run_051_payloads": "PROHIBITED_AS_CORRECTED_EVIDENCE",
        "current_checkout_source_git_history_internet_runtime_browser_tests_build_database": "PROHIBITED",
        "writer": "/root only; Agent D returns a structured JSON message and writes nothing",
    },
    "required_adjudication_contract": {
        "rows": 24,
        "feature_summaries": 6,
        "rating_cells_to_review": 144,
        "specification_units_to_review": 24,
        "acceptance_outcomes_to_review": 58,
        "lineage_checks": [
            "Verify every opaque ID reattaches one-to-one through the source-atom crosswalk.",
            "Verify Agent B retained the 252 blind atoms as 165 consumed and 87 unknown without invention.",
            "Apply the signed RUN-054 provenance wording correction without changing its neutral payload.",
            "Verify Agent C used only the sealed RUN-055 input and cited only same-packet supplied anchors.",
            "Review all 144 lens ratings, 24 unit ratings, and 58 acceptance-outcome ratings independently.",
            "Keep 4 partial and 12 insufficient adjacent packets non-promotable.",
            "Preserve target/object drift, explicit unknowns, exclusions, and the single-tenant multi-Site boundary.",
        ],
        "required_row_output": {
            "opaque_id": "exact A53 ID",
            "identity_pair": "exact feature_id and facet_key",
            "lineage_verdict": "PASS or NO_GO with bounded reason",
            "lens_reviews": "six ordered entries with ACCEPT, CORRECT, or REJECT plus rationale",
            "unit_review": "ACCEPT, CORRECT, or REJECT",
            "acceptance_outcome_reviews": "every same-row outcome exactly once",
            "drift_and_unknowns_preserved": "boolean plus reason",
            "promotion_disposition": "must remain non-promoted in this bounded static stage",
            "credit": False,
        },
        "required_feature_output": {
            "feature_id": "one of the six sealed IDs",
            "packet_ids": "complete disjoint packet set for that feature",
            "lineage_status": "PASS or NO_GO",
            "semantic_status": "bounded review result",
            "formal_edges": 0,
            "final_no_matches": 0,
            "credit": False,
        },
        "no_credit_reason": "This sealed static correction validates lineage and comparison only. It does not supply formal full-project triage, exhaustive no-match search, runtime, browser, executed-test, ease, release, Pass 8, or completion evidence.",
    },
    "packets": packets,
    "counts": {
        "canonical_targets": 340,
        "credited_targets": 0,
        "features": len(feature_ids),
        "packets": len(packets),
        "exact_packets": sum(row["source_sufficiency"] == "EXACT_OBSERVED_BEHAVIOUR" for row in output_rows.values()),
        "partial_adjacent_packets": sum(row["source_sufficiency"] == "PARTIAL_ADJACENT_BEHAVIOUR" for row in output_rows.values()),
        "insufficient_adjacent_packets": sum(row["source_sufficiency"] == "INSUFFICIENT_ADJACENT_BEHAVIOUR" for row in output_rows.values()),
        "neutral_atoms": 252,
        "neutral_consumed_atoms": 165,
        "neutral_retained_unknown_atoms": 87,
        "specification_units": 24,
        "acceptance_outcomes": acceptance_outcomes,
        "explicit_unknowns": explicit_unknowns,
        "source_anchor_occurrences": anchor_occurrences,
        "comparison_lens_ratings": sum(rating_counts.values()),
        "comparison_lens_rating_counts": dict(rating_counts),
        "formal_edges": 0,
        "final_no_matches": 0,
    },
    "credit_boundary": {
        "neutral_requirement_credit": False,
        "current_product_comparison_credit": False,
        "target_specific_mapping_credit": False,
        "benchmark_credit": False,
        "final_no_match_credit": False,
        "runtime_credit": False,
        "browser_credit": False,
        "test_execution_credit": False,
        "ease_credit": False,
        "release_credit": False,
        "pass_credit": False,
        "completion_credit": False,
        "audit_complete": False,
    },
    "external_mutations_attestation": "NONE_STATIC_AUDIT_EVIDENCE_ASSEMBLY_ONLY",
}

assert output["counts"]["exact_packets"] == 8
assert output["counts"]["partial_adjacent_packets"] == 4
assert output["counts"]["insufficient_adjacent_packets"] == 12
write_json(OUTPUT, output)
