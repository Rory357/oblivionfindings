#!/usr/bin/env python3
"""Integrate the corrected RUN-053 through RUN-056 clean-spec chain as RUN-057.

RUN-047 remains the shared upstream observation source. RUN-048, RUN-050,
RUN-051, and the former RUN-052 are retained only as immutable historical
diagnostics and are prohibited as corrected comparison inputs. This generator
validates the fresh A/B/C/D chain, emits zero formal edges, and leaves the
frozen 0/340 matrix unchanged.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
EVIDENCE_DIR = AUDIT_DIR / "evidence" / "benchmark"
GENERATED_AT = "2026-08-25T03:47:00+12:00"

APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
CANONICAL_IDENTITY_SHA256 = "f4feae2598622afe346b1163fed2bb842305a8d973a89ec890c02746d99b5999"
CANONICAL_MATRIX_SHA256 = "df6e1b1b357439ad1fd829bebf4e2d33d20d067d515eb945c352e2350a4194a4"
PARENT_RUN_046_SHA256 = "648fd95c9291a094a60bf1dfb007e1da9f58eb9b9889ffaad4fa5d542ecbf1f4"
PROMPT = Path("C:/Users/steph/Downloads/oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
PROMPT_BYTES = 88305

RUN_056_MANIFEST_SHA256 = "4f5edf1e554bfd6d07610c6663c2478d19069d5e880715a2aff08cb5c64b5264"
RUN_054_CANONICAL_PAYLOAD_SHA256 = "6cd60a87324025e4e63586dd75a5262f5ed06d9081d051882b0d9ad338895b5e"
RUN_055_MESSAGE_SHA256 = "24da489b1b10032265ee4b4dca9e3b98b07d4b2bf603c9bceb8e637c34dcd2a1"
RUN_055_ROWS_SHA256 = "b4e4b410e9a53884a4dbfb9149c86279ecbb9fbdb76d2a4e66066ef81699f204"
RUN_056_MESSAGE_SHA256 = "c320c886173492482b280798164611b88c3e29d8eb97b2a827b61179e9f397ff"
RUN_056_ROWS_SHA256 = "e66fde050683c2354a11c656248806b62ad2742c58e88b158c561b25d23714d6"

CURRENT_INPUTS = [
    ("RUN-047", "current-upstream-facet-refinement-wave-02.json", "d41cc046de9b7580e937ca1b9d1df7f9237947dcaed5bf0f95772b667d8d9f3e", 70981),
    ("RUN-053", "raw-run-053-agent-a-blind-observed-behaviour-packets-wave-02.json", "835ec0755a5a4b5543f317969e9ba557bc156b137083df925f05d4b753ccde6b", 37799),
    ("RUN-053-CROSSWALK", "root-run-053-agent-a-source-atom-crosswalk-wave-02.json", "78f1e5c77c3dda80048a7b2d6d03859bc22bfe01afba5547ae20ffe797839dfe", 69666),
    ("RUN-054", "raw-run-054-fresh-agent-b-neutral-requirements-wave-02.json", "7e1e2203dd5af9852f69b1ff5ad05a5d031e4d8d12096ee39055129954f01a68", 147260),
    ("RUN-054-CORRECTION", "raw-run-054-agent-b-input-boundary-correction-wave-02.json", "1f197b817e5e184efbcf4e7549664008aa3fe331d1b4d4ab5470a3a214364b8b", 2672),
    ("RUN-055-INPUT", "raw-run-055-agent-c-comparison-input-wave-02.json", "5422d3b25795189be5fd5070bb18d3e82af17b718fd821f735b5bc0d9c9a57e7", 232684),
    ("RUN-055-OUTPUT", "raw-run-055-fresh-agent-c-current-comparison-wave-02.json", "666c6de668cc8f1db3661e55da309034992d1e27d904c740159bc4f3039fb275", 71099),
    ("RUN-056-INPUT", "raw-run-056-independent-adjudicator-input-wave-02.json", "b611f1e513f1eb8153a6832d179e4708c6b17ae2e326abe6c74e5a0b8746cbc7", 688139),
    ("RUN-056-OUTPUT", "raw-run-056-fresh-independent-corrected-chain-adjudication-wave-02.json", "f1a8fe263ffcf94ea32972a74365ff9f58b32ec0021e50b7f37a3340d02a92dd", 85350),
]
HISTORICAL_INPUTS = [
    {"logical_id": "RUN-048", "path": "raw-run-048-blind-neutral-facet-requirements-wave-02.json", "sha256": "fa9b793ad851d90a1871b0ce7de76906026144fce3e54ff8f502352f72584bd6"},
    {"logical_id": "RUN-050", "path": "raw-run-050-clean-facet-comparison-reconciled-wave-02.json", "sha256": "fa5248dd88b3a43334e9f181bba2f8459274c2c404d984189c39a54d74988159"},
    {"logical_id": "RUN-051", "path": "raw-run-051-independent-facet-adjudication-wave-02.json", "sha256": "983ef988f61523d7b76e7e6c91141cd1321fb61082990e9b54481ea5a2080acb"},
]
HISTORICAL_COMMIT = "93e3036055ea24dc71a7e49afd40c08fd8cf0830"
HISTORICAL_TREE = "e3ac56dbc0082f8759997ddc210a88fd9aa6638c"
HISTORICAL_PARENT = "4155fb506c5f511ba50a0f8a1c453f872653e0e9"
HISTORICAL_ARTIFACTS = [
    {
        "kind": "comparison",
        "path": "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/benchmark/current-facet-neutral-comparison-wave-02.json",
        "blob_sha1": "d573330ba15bdd78ce3f87792ed13f0eb8e482ef",
        "bytes": 256084,
        "sha256": "4dc66b8c7c11bd4d89e361d2f5b8bb8b91fda3cb1f05ed1c1ddc464dbf563b6e",
        "status": "TWENTY_FOUR_FACET_WAVE_INTEGRATED_LINEAGE_NO_GO_ZERO_FORMAL_EDGES",
    },
    {
        "kind": "register",
        "path": "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/benchmark/current-facet-neutral-comparison-agent-register.json",
        "blob_sha1": "18c49397407b47f3a2d34566166b51fc214d5ecd",
        "bytes": 7804,
        "sha256": "d5e6587a1125ffb2456c2b8f20b26f5d602b7282bbe001055cedb6b48ca18633",
        "status": "FIVE_STAGE_FACET_REGISTER_COMPLETE_LINEAGE_NO_GO_ZERO_CREDIT",
    },
]

LENSES = [
    "authorization_scope",
    "state_read_projection",
    "integrity_audit_provenance",
    "replay_concurrency",
    "privacy_direct_object",
    "collision_exclusions",
]
RATINGS = [
    "EVIDENCED_MET",
    "EVIDENCED_PARTIAL",
    "EVIDENCED_GAP",
    "EVIDENCED_CONTRADICTED",
    "NOT_EVIDENCED",
    "NOT_APPLICABLE",
]
DECISIONS = {"ACCEPT", "CORRECT", "REJECT"}
EXPECTED_IDS = [f"A53-{index:03d}" for index in range(1, 25)]
FALSE_KEYS = {
    "neutral_requirement_credit", "current_product_comparison_credit",
    "target_specific_mapping_credit", "mapping_credit", "formal_mapping_credit",
    "benchmark_credit", "final_no_match_credit", "runtime_credit",
    "browser_credit", "test_credit", "test_execution_credit", "ease_credit",
    "release_credit", "pass_credit", "completion_credit", "audit_complete", "credit",
}
ZERO_KEYS = {
    "credited_targets", "credited_rows", "credited_feature_rows", "formal_edges",
    "formal_edge_count", "final_no_matches", "final_no_match_count",
    "promoted_feature_mappings_or_final_no_matches",
}


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def stable_hash(value: object) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: object) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def index_rows(rows: list[dict[str, Any]], key: str) -> dict[str, dict[str, Any]]:
    result = {row[key]: row for row in rows}
    assert len(result) == len(rows), key
    return result


def assert_zero_credit(value: object, path: str = "root") -> None:
    if isinstance(value, dict):
        for key, child in value.items():
            child_path = f"{path}.{key}"
            if key in FALSE_KEYS or (
                key.endswith("_credit") and not key.startswith("no_")
            ):
                assert child in (False, 0, "false"), (child_path, child)
            if key in ZERO_KEYS:
                assert child == 0, (child_path, child)
            assert_zero_credit(child, child_path)
    elif isinstance(value, list):
        for index, child in enumerate(value):
            assert_zero_credit(child, f"{path}[{index}]")


def pinned_history(contract: dict[str, Any]) -> None:
    ref = f"{HISTORICAL_COMMIT}:{contract['path']}"
    blob = subprocess.check_output(
        ["git", "rev-parse", ref], cwd=REPO_DIR, text=True
    ).strip()
    assert blob == contract["blob_sha1"], contract["kind"]
    raw = subprocess.check_output(["git", "show", ref], cwd=REPO_DIR)
    assert len(raw) == contract["bytes"], contract["kind"]
    assert sha256_bytes(raw) == contract["sha256"], contract["kind"]
    payload = json.loads(raw.decode("utf-8"))
    assert payload["run_id"] == "RUN-052"
    assert payload["status"] == contract["status"]
    assert_zero_credit(payload, f"historical.{contract['kind']}")


# Immutable governing pins and historical checkpoint.
assert sha256_file(AUDIT_DIR / "03-feature-to-benchmark-matrix.csv") == CANONICAL_MATRIX_SHA256
assert sha256_file(
    AUDIT_DIR / "evidence" / "source" / "current-canonical-feature-identity-wave-01.json"
) == CANONICAL_IDENTITY_SHA256
assert sha256_file(EVIDENCE_DIR / "current-target-neutral-comparison-wave-01.json") == PARENT_RUN_046_SHA256
assert PROMPT.is_file() and PROMPT.stat().st_size == PROMPT_BYTES
assert sha256_file(PROMPT) == PROMPT_SHA256
assert subprocess.check_output(
    ["git", "rev-parse", f"{APPLICATION_COMMIT}^{{tree}}"],
    cwd=REPO_DIR,
    text=True,
).strip() == APPLICATION_TREE
assert subprocess.check_output(
    ["git", "show", "-s", "--format=%H%n%T%n%P", HISTORICAL_COMMIT],
    cwd=REPO_DIR,
    text=True,
).splitlines() == [HISTORICAL_COMMIT, HISTORICAL_TREE, HISTORICAL_PARENT]
for artifact in HISTORICAL_ARTIFACTS:
    pinned_history(artifact)
for item in HISTORICAL_INPUTS:
    assert sha256_file(EVIDENCE_DIR / item["path"]) == item["sha256"]

# Hash-pin and load every corrected-chain input.
payloads: dict[str, dict[str, Any]] = {}
input_manifest: list[dict[str, Any]] = []
for logical_id, filename, digest, byte_count in CURRENT_INPUTS:
    path = EVIDENCE_DIR / filename
    assert path.is_file() and path.stat().st_size == byte_count, logical_id
    assert sha256_file(path) == digest, logical_id
    payload = load_json(path)
    assert_zero_credit(payload, logical_id)
    payloads[logical_id] = payload
    input_manifest.append(
        {"logical_id": logical_id, "path": filename, "sha256": digest, "bytes": byte_count}
    )

run47 = payloads["RUN-047"]
run53 = payloads["RUN-053"]
crosswalk = payloads["RUN-053-CROSSWALK"]
run54 = payloads["RUN-054"]
correction = payloads["RUN-054-CORRECTION"]
c_input = payloads["RUN-055-INPUT"]
c_output = payloads["RUN-055-OUTPUT"]
d_input = payloads["RUN-056-INPUT"]
d_output = payloads["RUN-056-OUTPUT"]

assert run47["run_id"] == "RUN-047"
assert run53["run_id"] == "RUN-053"
assert crosswalk["crosswalk_id"] == "RUN-053-AGENT-A-BLIND-ATOM-LINEAGE"
assert run54["run_id"] == "RUN-054"
assert correction["run_id"] == "RUN-054-CORRECTION"
assert c_input["run_id"] == "RUN-055-INPUT"
assert c_output["run_id"] == "RUN-055-AGENT-C-COMPARISON-WAVE-02"
assert d_input["run_id"] == "RUN-056-INPUT"
assert d_output["schema_version"] == "RUN-056-D-ADJUDICATION-1"
assert d_output["status"] == "PASS_WITH_ONE_BOUNDED_SEMANTIC_CORRECTION_ZERO_CREDIT"

# Sealed provenance.
assert d_input["manifest_sha256"] == RUN_056_MANIFEST_SHA256
assert stable_hash(d_input["sealed_manifest"]) == RUN_056_MANIFEST_SHA256
sealed = d_input["sealed_manifest"]
assert sealed["prompt"] == {
    "path": str(PROMPT), "sha256": PROMPT_SHA256, "bytes": PROMPT_BYTES
}
assert sealed["canonical_matrix"] == {
    "path": "03-feature-to-benchmark-matrix.csv",
    "sha256": CANONICAL_MATRIX_SHA256,
    "rows": 340,
    "credited_rows": 0,
}
assert sealed["application"] == {"commit": APPLICATION_COMMIT, "tree": APPLICATION_TREE}
assert sealed["run_054_canonical_agent_payload_sha256"] == RUN_054_CANONICAL_PAYLOAD_SHA256
assert sealed["run_055_exact_agent_message_sha256"] == RUN_055_MESSAGE_SHA256
assert sealed["run_055_canonical_comparison_row_set_sha256"] == RUN_055_ROWS_SHA256
assert stable_hash(c_output["rows"]) == RUN_055_ROWS_SHA256
assert stable_hash(d_output["row_adjudications"]) == RUN_056_ROWS_SHA256
assert run54["agent_return_integrity"]["canonical_payload_sha256"] == RUN_054_CANONICAL_PAYLOAD_SHA256
assert correction["correction_scope"] == "PROVENANCE_WORDING_ONLY"
assert correction["existing_payload"] == {
    "row_count": 24,
    "canonical_sha256": RUN_054_CANONICAL_PAYLOAD_SHA256,
    "byte_unchanged": True,
    "semantically_unchanged": True,
    "neutral_rows_changed": False,
}

manifest_key_by_id = {
    "RUN-047": "run_047", "RUN-053": "run_053",
    "RUN-053-CROSSWALK": "run_053_crosswalk", "RUN-054": "run_054",
    "RUN-054-CORRECTION": "run_054_correction", "RUN-055-INPUT": "run_055_input",
    "RUN-055-OUTPUT": "run_055_output",
}
for item in input_manifest[:7]:
    assert sealed["files"][manifest_key_by_id[item["logical_id"]]] == {
        "path": item["path"], "sha256": item["sha256"], "bytes": item["bytes"]
    }

# Exact A53 joins and embedded-copy agreement.
a_by_id = index_rows(run53["packets"], "blind_packet_id")
x_by_id = index_rows(crosswalk["packets"], "blind_packet_id")
b_by_id = index_rows(run54["rows"], "opaque_id")
ci_by_id = index_rows(c_input["comparison_packets"], "opaque_id")
co_by_id = index_rows(c_output["rows"], "opaque_id")
di_by_id = index_rows(d_input["packets"], "opaque_id")
do_by_id = index_rows(d_output["row_adjudications"], "opaque_id")
expected_ids = set(EXPECTED_IDS)
for index in [a_by_id, x_by_id, b_by_id, ci_by_id, co_by_id, di_by_id, do_by_id]:
    assert set(index) == expected_ids

upstream_by_pair = {
    (row["feature_id"], row["facet_key"]): row for row in run47["facets"]
}
assert len(upstream_by_pair) == 24
for opaque_id in EXPECTED_IDS:
    packet = di_by_id[opaque_id]
    identity = packet["reattached_identity"]
    xrow = x_by_id[opaque_id]
    pair = (identity["feature_id"], identity["facet_key"])
    assert xrow["source_feature_id"] == identity["feature_id"]
    assert xrow["source_facet_key"] == identity["facet_key"]
    assert xrow["facet_record_sha256"] == identity["facet_record_sha256"]
    assert upstream_by_pair[pair] == packet["upstream_observation"]
    assert upstream_by_pair[pair]["facet_record_sha256"] == identity["facet_record_sha256"]
    assert a_by_id[opaque_id] == packet["blind_agent_a_packet"]
    assert xrow == packet["source_atom_crosswalk"]
    assert b_by_id[opaque_id] == packet["neutral_specification"]
    assert ci_by_id[opaque_id] == packet["target_and_current_source_packet"]
    assert co_by_id[opaque_id] == packet["fresh_agent_c_comparison"]
    assert ci_by_id[opaque_id]["target_contract"]["feature_id"] == identity["feature_id"]
    assert ci_by_id[opaque_id]["target_contract"]["facet_key"] == identity["facet_key"]
    assert b_by_id[opaque_id]["specification_units"][0]["id"] == co_by_id[opaque_id]["unit_id"]

# Fixed packet and comparison arithmetic.
assert run47["counts"]["candidate_locators_for_later_clean_comparison"] == 12
assert run47["counts"]["bounded_no_candidate_not_final_no_match"] == 12
assert run53["counts"]["exact_observed_behaviour_packets"] == 8
assert run53["counts"]["partial_adjacent_behaviour_packets"] == 4
assert run53["counts"]["insufficient_adjacent_behaviour_packets"] == 12
assert crosswalk["counts"]["stable_blind_atoms_mapped"] == 252
assert crosswalk["counts"]["unmapped_stable_blind_atoms"] == 0
assert run54["validation_summary"]["derivation_class_counts"] == {
    "BENCHMARK_DERIVED": 8,
    "BOUNDED_ADJACENT_PRINCIPLE": 4,
    "NO_EXACT_BENCHMARK_REQUIREMENT": 12,
}
assert run54["validation_summary"]["atom_totals"] == {
    "total": 252, "consumed": 165, "retained_unknown": 87, "excluded": 0
}
cc = c_output["counts"]
assert cc["rows_actual"] == 24
assert cc["specification_units_compared"] == 24
assert cc["acceptance_outcomes_compared"] == 58
assert cc["explicit_unknowns_preserved"] == 85
assert cc["lens_ratings_total"] == 144
c_lens_counts = {
    "EVIDENCED_MET": 17, "EVIDENCED_PARTIAL": 24, "EVIDENCED_GAP": 14,
    "EVIDENCED_CONTRADICTED": 17, "NOT_EVIDENCED": 67, "NOT_APPLICABLE": 5,
}
assert cc["lens_rating_counts"]["aggregate"] == c_lens_counts
assert cc["unit_rating_counts"] == {
    "EVIDENCED_MET": 1, "EVIDENCED_PARTIAL": 10, "EVIDENCED_GAP": 6,
    "EVIDENCED_CONTRADICTED": 7, "NOT_EVIDENCED": 0, "NOT_APPLICABLE": 0,
}
assert cc["acceptance_outcome_rating_counts"] == {
    "EVIDENCED_MET": 15, "EVIDENCED_PARTIAL": 8, "EVIDENCED_GAP": 14,
    "EVIDENCED_CONTRADICTED": 7, "NOT_EVIDENCED": 10, "NOT_APPLICABLE": 4,
}

anchors = [
    anchor
    for row in c_input["comparison_packets"]
    for anchor in row["current_source_evidence"]["packet"]["anchors"]
]
assert len(anchors) == 155 and len(set(anchors)) == 148
anchor_paths: set[str] = set()
for anchor in anchors:
    match = re.fullmatch(r"(.+?):\d+(?:-\d+)?(?:,\d+(?:-\d+)?)*", anchor)
    assert match, anchor
    anchor_paths.add(match.group(1))
assert len(anchor_paths) == 84

# Fresh D independent review: every C cell is covered and the sole correction is bounded.
dc = d_output["counts_and_validation"]
assert dc["features"] == {"expected": 6, "actual": 6, "disjoint": True}
assert dc["rows"] == {"expected": 24, "actual": 24, "unique": 24}
assert dc["neutral_atoms"]["actual"] == 252
assert dc["neutral_atoms"]["consumed_actual"] == 165
assert dc["neutral_atoms"]["retained_unknown_actual"] == 87
assert dc["explicit_unknowns"] == {"expected": 85, "actual": 85}
assert dc["total_semantic_reviews"] == 226
assert dc["rows_with_corrections"] == ["A53-024"]
assert dc["adjacent_rows_promoted"] == 0
assert dc["formal_edges"] == dc["final_no_matches"] == dc["credited_targets"] == 0
assert dc["canonical_targets"] == 340

lens_decisions: Counter[str] = Counter()
unit_decisions: Counter[str] = Counter()
outcome_decisions: Counter[str] = Counter()
final_lens: Counter[str] = Counter()
final_units: Counter[str] = Counter()
final_outcomes: Counter[str] = Counter()
for opaque_id in EXPECTED_IDS:
    crow = co_by_id[opaque_id]
    drow = do_by_id[opaque_id]
    identity = di_by_id[opaque_id]["reattached_identity"]
    assert drow["identity_pair"] == {
        "feature_id": identity["feature_id"], "facet_key": identity["facet_key"]
    }
    assert drow["lineage_verdict"] == "PASS"
    assert [review["lens"] for review in drow["lens_reviews"]] == LENSES
    c_lenses = {item["lens"]: item for item in crow["lenses"]}
    for review in drow["lens_reviews"]:
        assert review["decision"] in DECISIONS
        assert review["c_rating"] == c_lenses[review["lens"]]["rating"]
        assert review["final_rating"] in RATINGS
        if review["decision"] == "ACCEPT":
            assert review["final_rating"] == review["c_rating"]
        lens_decisions[review["decision"]] += 1
        final_lens[review["final_rating"]] += 1
    unit = drow["unit_review"]
    assert unit["decision"] in DECISIONS
    assert unit["c_rating"] == crow["unit"]["rating"]
    if unit["decision"] == "ACCEPT":
        assert unit["final_rating"] == unit["c_rating"]
    unit_decisions[unit["decision"]] += 1
    final_units[unit["final_rating"]] += 1
    c_outcomes = {item["id"]: item for item in crow["unit"]["outcomes"]}
    d_outcomes = {item["id"]: item for item in drow["acceptance_outcome_reviews"]}
    neutral_ids = {
        item["id"]
        for item in b_by_id[opaque_id]["specification_units"][0]["acceptance_outcomes"]
    }
    assert set(c_outcomes) == set(d_outcomes) == neutral_ids
    for outcome_id, review in d_outcomes.items():
        assert review["decision"] in DECISIONS
        assert review["c_rating"] == c_outcomes[outcome_id]["rating"]
        assert review["final_rating"] in RATINGS
        if review["decision"] == "ACCEPT":
            assert review["final_rating"] == review["c_rating"]
        outcome_decisions[review["decision"]] += 1
        final_outcomes[review["final_rating"]] += 1
    assert drow["drift_and_unknowns_preserved"] is True
    assert drow["promotion_disposition"].startswith("NON_PROMOTED_STATIC_ONLY_")
    assert drow["credit"] is False

assert lens_decisions == Counter({"ACCEPT": 144})
assert unit_decisions == Counter({"ACCEPT": 24})
assert outcome_decisions == Counter({"ACCEPT": 57, "CORRECT": 1})
assert final_lens == Counter(c_lens_counts)
assert final_units == Counter(cc["unit_rating_counts"])
expected_final_outcomes = {
    "EVIDENCED_MET": 14, "EVIDENCED_PARTIAL": 9, "EVIDENCED_GAP": 14,
    "EVIDENCED_CONTRADICTED": 7, "NOT_EVIDENCED": 10, "NOT_APPLICABLE": 4,
}
assert final_outcomes == Counter(expected_final_outcomes)
correction_row = do_by_id["A53-024"]
corrected = next(
    item for item in correction_row["acceptance_outcome_reviews"]
    if item["id"] == "AO-A53-024-01"
)
assert (corrected["c_rating"], corrected["decision"], corrected["final_rating"]) == (
    "EVIDENCED_MET", "CORRECT", "EVIDENCED_PARTIAL"
)

feature_groups: dict[str, list[str]] = {}
for opaque_id in EXPECTED_IDS:
    feature_id = di_by_id[opaque_id]["reattached_identity"]["feature_id"]
    feature_groups.setdefault(feature_id, []).append(opaque_id)
feature_summaries = {item["feature_id"]: item for item in d_output["feature_summaries"]}
assert len(feature_summaries) == 6 and set(feature_summaries) == set(feature_groups)
for feature_id, packet_ids in feature_groups.items():
    summary = feature_summaries[feature_id]
    assert summary["packet_ids"] == packet_ids
    assert summary["lineage_status"] == "PASS"
    assert summary["formal_edges"] == summary["final_no_matches"] == 0
    assert summary["credit"] is False

assert d_output["seal_validation"]["file_hash_match"] is True
assert d_output["seal_validation"]["manifest_hash_match"] is True
assert d_output["seal_validation"]["referenced_files_reopened"] is False
assert d_output["file_access_attestation"]["other_repository_or_audit_files_read"] == 0
assert d_output["file_access_attestation"]["writes_or_mutations"] is False

with (AUDIT_DIR / "03-feature-to-benchmark-matrix.csv").open(
    encoding="utf-8", newline=""
) as handle:
    matrix_rows = list(csv.DictReader(handle))
assert len(matrix_rows) == len({row["feature_id"] for row in matrix_rows}) == 340
assert all(row["benchmark_mapping_credit"] == "false" for row in matrix_rows)

historical_checkpoint = {
    "run_id": "RUN-052",
    "status": "SUPERSEDED_HISTORICAL_LINEAGE_NO_GO_ZERO_CREDIT",
    "commit": HISTORICAL_COMMIT,
    "tree": HISTORICAL_TREE,
    "parent": HISTORICAL_PARENT,
    "artifacts": HISTORICAL_ARTIFACTS,
    "historical_disposition": {
        "lineage_status": "NO_GO_AGENT_A_TO_B_SANITIZED_BEHAVIOUR_PACKET_MISSING",
        "formal_edges": 0,
        "canonical_mapping_credit_fraction": "0/340",
    },
    "current_use": "PROVENANCE_ONLY_NOT_A_CORRECTED_COMPARISON_INPUT",
}
records: list[dict[str, Any]] = []
for opaque_id in EXPECTED_IDS:
    packet = di_by_id[opaque_id]
    identity = packet["reattached_identity"]
    drow = do_by_id[opaque_id]
    record = {
        "opaque_id": opaque_id,
        "feature_id": identity["feature_id"],
        "facet_key": identity["facet_key"],
        "facet_record_sha256": identity["facet_record_sha256"],
        "observation_sufficiency": packet["blind_agent_a_packet"]["observation_sufficiency"],
        "upstream_observation": packet["upstream_observation"],
        "blind_agent_a_packet": packet["blind_agent_a_packet"],
        "source_atom_crosswalk": packet["source_atom_crosswalk"],
        "neutral_specification": packet["neutral_specification"],
        "target_and_current_source_packet": packet["target_and_current_source_packet"],
        "fresh_agent_c_comparison": packet["fresh_agent_c_comparison"],
        "fresh_agent_d_adjudication": drow,
        "lineage_status": drow["lineage_verdict"],
        "promotion_disposition": drow["promotion_disposition"],
        "formal_edge_count": 0,
        "final_no_match_count": 0,
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
        "credit": False,
    }
    record["integrated_record_sha256"] = stable_hash(record)
    records.append(record)

decision_counts = {
    "lens_reviews": {key: lens_decisions[key] for key in sorted(DECISIONS)},
    "specification_unit_reviews": {key: unit_decisions[key] for key in sorted(DECISIONS)},
    "acceptance_outcome_reviews": {key: outcome_decisions[key] for key in sorted(DECISIONS)},
    "total_reviews": 226,
    "total_accept": 225,
    "total_correct": 1,
    "total_reject": 0,
}
credit_boundary = {
    "selected_feature_packets_materialized": 24,
    "fresh_c_lens_ratings_materialized": 144,
    "fresh_d_semantic_reviews_materialized": 226,
    "fresh_d_bounded_corrections": 1,
    "credited_targets": 0,
    "credited_rows": 0,
    "formal_edges": 0,
    "final_no_matches": 0,
    "promoted_feature_mappings_or_final_no_matches": 0,
    "neutral_requirement_credit": False,
    "current_product_comparison_credit": False,
    "target_specific_mapping_credit": False,
    "mapping_credit": False,
    "formal_mapping_credit": False,
    "benchmark_credit": False,
    "final_no_match_credit": False,
    "runtime_credit": False,
    "browser_credit": False,
    "test_credit": False,
    "test_execution_credit": False,
    "ease_credit": False,
    "release_credit": False,
    "pass_credit": False,
    "completion_credit": False,
    "audit_complete": False,
}
pins = {
    "prompt_path": str(PROMPT),
    "prompt_sha256": PROMPT_SHA256,
    "prompt_bytes": PROMPT_BYTES,
    "application_commit": APPLICATION_COMMIT,
    "application_tree": APPLICATION_TREE,
    "canonical_identity_sha256": CANONICAL_IDENTITY_SHA256,
    "canonical_matrix_sha256": CANONICAL_MATRIX_SHA256,
    "parent_run_046_sha256": PARENT_RUN_046_SHA256,
}
counts = {
    "canonical_targets": 340,
    "wave_features": 6,
    "selected_facet_packets": 24,
    "candidate_locators": 12,
    "bounded_no_candidates_not_final_no_match": 12,
    "agent_a_exact_packets": 8,
    "agent_a_partial_adjacent_packets": 4,
    "agent_a_insufficient_adjacent_packets": 12,
    "agent_b_benchmark_derived_packets": 8,
    "agent_b_bounded_adjacent_packets": 4,
    "agent_b_no_exact_requirement_packets": 12,
    "neutral_atoms": 252,
    "neutral_consumed_atoms": 165,
    "neutral_retained_unknown_atoms": 87,
    "specification_units": 24,
    "acceptance_outcomes": 58,
    "explicit_unknowns_preserved": 85,
    "source_anchor_occurrences": 155,
    "source_unique_anchor_strings": 148,
    "source_anchor_paths": 84,
    "fresh_c_lens_ratings": 144,
    "fresh_c_lens_rating_counts": c_lens_counts,
    "fresh_c_unit_rating_counts": cc["unit_rating_counts"],
    "fresh_c_acceptance_outcome_rating_counts": cc["acceptance_outcome_rating_counts"],
    "fresh_d_review_decision_counts": decision_counts,
    "fresh_d_final_lens_rating_counts": dict(final_lens),
    "fresh_d_final_unit_rating_counts": dict(final_units),
    "fresh_d_final_acceptance_outcome_rating_counts": dict(final_outcomes),
    "fresh_d_rows_with_corrections": 1,
    "fresh_d_corrected_outcome_ids": ["AO-A53-024-01"],
    "fresh_d_lineage_pass_rows": 24,
    "fresh_d_lineage_pass_features": 6,
    "adjacent_packets_non_promotable": 16,
    "formal_edges": 0,
    "final_no_matches": 0,
    "credited_targets": 0,
    "promoted_feature_mappings_or_final_no_matches": 0,
}

output = {
    "schema_version": 1,
    "run_id": "RUN-057",
    "generated_at": GENERATED_AT,
    "role": "ROOT_CORRECTED_CLEAN_SPEC_CHAIN_DETERMINISTIC_INTEGRATOR",
    "responsible_agent_identity": "/root/run057_integration",
    "status": "CORRECTED_CLEAN_SPEC_CHAIN_INTEGRATED_ZERO_CREDIT",
    "governing_pins": pins,
    "historical_checkpoints": [historical_checkpoint],
    "historical_diagnostic_inputs": HISTORICAL_INPUTS,
    "inputs": input_manifest,
    "agent_return_integrity": {
        "run_054_canonical_agent_payload_sha256": RUN_054_CANONICAL_PAYLOAD_SHA256,
        "run_055_exact_agent_message_sha256": RUN_055_MESSAGE_SHA256,
        "run_055_canonical_row_set_sha256": RUN_055_ROWS_SHA256,
        "run_056_exact_agent_message_sha256": RUN_056_MESSAGE_SHA256,
        "run_056_canonical_row_set_sha256": RUN_056_ROWS_SHA256,
        "run_056_manifest_sha256": RUN_056_MANIFEST_SHA256,
    },
    "stage_lineage": {
        "status": "PASS_CORRECTED_CLEAN_SPEC_CHAIN_STATIC_ONLY_ZERO_CREDIT",
        "run_047_upstream_observation_complete": True,
        "run_053_identity_stripped_agent_a_packets_complete": True,
        "run_053_atom_crosswalk_complete": True,
        "run_054_fresh_agent_b_neutralization_complete": True,
        "run_054_provenance_only_correction_applied": True,
        "run_055_fresh_agent_c_same_packet_static_comparison_complete": True,
        "run_056_fresh_agent_d_independent_adjudication_complete": True,
        "run_056_bounded_semantic_corrections": 1,
        "old_run_048_run_050_run_051_prohibited_as_corrected_inputs": True,
        "formal_edge_eligibility": False,
        "next_action": "Continue formal full-project triage or exhaustive no-match work for these six selected feature IDs and the remaining 334 canonical targets; preserve 0/340 until an independently approved formal edge or final no-match exists.",
    },
    "counts": counts,
    "records": records,
    "feature_summaries": d_output["feature_summaries"],
    "canonical_matrix_disposition": {
        "status": "UNCHANGED_CORRECTED_CHAIN_DIAGNOSTIC_ONLY",
        "sha256": CANONICAL_MATRIX_SHA256,
        "credited_feature_rows": 0,
        "total_feature_rows": 340,
        "promoted_feature_mappings_or_final_no_matches": 0,
        "reason": "The corrected clean-spec chain validates bounded static lineage and comparison only. It is not formal full-project triage, an exhaustive final no-match, runtime or browser proof, executed-test evidence, ease evidence, release evidence, Pass 8 evidence, or completion evidence.",
    },
    "credit_boundary": credit_boundary,
    "external_mutations_attestation": "NONE_STATIC_AUDIT_EVIDENCE_INTEGRATION_ONLY",
}
assert_zero_credit(output)

agent_register = {
    "schema_version": 1,
    "run_id": "RUN-057",
    "generated_at": GENERATED_AT,
    "status": "CORRECTED_A_B_C_D_CHAIN_REGISTER_COMPLETE_ZERO_CREDIT",
    "governing_pins": pins,
    "historical_checkpoints": [historical_checkpoint],
    "historical_diagnostic_inputs": HISTORICAL_INPUTS,
    "inputs": input_manifest,
    "agents": [
        {
            "stage": "UPSTREAM_OBSERVATION",
            "raw_run": "RUN-047",
            "responsible_agent_identity": run47["responsible_agent_identity"],
            "scope": "24 selected feature-facet observations: 12 candidate locators and 12 bounded no-candidates.",
            "boundary": "Official upstream evidence only; current Oblivion source withheld.",
            "status": "PASS_STATIC_OBSERVATION_ONLY",
            "credit": False,
        },
        {
            "stage": "A_IDENTITY_STRIPPED_BEHAVIOUR_PACKETS",
            "raw_runs": ["RUN-053", "RUN-053-CROSSWALK"],
            "responsible_agent_identity": run53["responsible_agent_identity"],
            "scope": "24 blind packets with a root-held 252-atom identity crosswalk: 8 exact, 4 partial-adjacent, and 12 insufficient-adjacent.",
            "boundary": "Agent-facing packets withheld feature/facet identity, upstream refs, current source, old comparison, and credit.",
            "status": "PASS_SANITIZED_PACKET_AND_ATOM_LINEAGE",
            "credit": False,
        },
        {
            "stage": "B_FRESH_NEUTRAL_REQUIREMENTS",
            "raw_runs": ["RUN-054", "RUN-054-CORRECTION"],
            "responsible_agent_identity": run54["responsible_agent_identity"],
            "scope": "24 neutral units and 58 outcomes derived from 252 blind atoms: 165 consumed and 87 retained unknown.",
            "boundary": "Fresh Agent B received only the sanitized Agent A packet; the signed correction changes provenance wording only.",
            "status": "PASS_NEUTRALIZATION_WITH_PROVENANCE_CORRECTION",
            "credit": False,
        },
        {
            "stage": "C_FRESH_PINNED_CURRENT_COMPARISON",
            "raw_runs": ["RUN-055-INPUT", "RUN-055-OUTPUT"],
            "responsible_agent_identity": "/root/run054_fresh_agent_b/run055_fresh_agent_c",
            "scope": "24 current-source comparisons, 144 ordered six-lens ratings, 24 unit ratings, 58 outcome ratings, and 85 preserved unknowns.",
            "boundary": "Fresh Agent C read only the sealed comparison input and cited only same-packet supplied anchors.",
            "status": "PASS_STATIC_COMPARISON_ONLY",
            "credit": False,
        },
        {
            "stage": "D_FRESH_INDEPENDENT_ADJUDICATION",
            "raw_runs": ["RUN-056-INPUT", "RUN-056-OUTPUT"],
            "responsible_agent_identity": "/root/run054_fresh_agent_b/run056_fresh_agent_d",
            "scope": "24 lineage reviews, 144 lens reviews, 24 unit reviews, 58 outcome reviews, and six disjoint feature summaries.",
            "boundary": "Fresh Agent D read only the sealed RUN-056 input; source, history, old comparisons, runtime, browser, tests, build, database, VCS, network, and writes were prohibited.",
            "status": "PASS_WITH_ONE_BOUNDED_SEMANTIC_CORRECTION_ZERO_CREDIT",
            "credit": False,
        },
        {
            "stage": "ROOT_DETERMINISTIC_INTEGRATION",
            "raw_run": "RUN-057",
            "responsible_agent_identity": "/root/run057_integration",
            "scope": "Hash-bind the corrected chain, preserve RUN-052 as immutable history, validate 226 D reviews, and retain the unchanged 340-row zero-credit matrix.",
            "boundary": "Audit artifacts only; zero application, runtime, database, application-browser, test, upstream, or external mutation.",
            "status": "PASS_CORRECTED_CHAIN_INTEGRATED_ZERO_CREDIT",
            "credit": False,
        },
    ],
    "agreement": {
        "selected_feature_facet_packets": 24,
        "duplicate_or_missing_opaque_ids": 0,
        "source_anchor_occurrences_checked": 155,
        "source_unique_anchor_strings_checked": 148,
        "source_anchor_paths_checked": 84,
        "neutral_atoms_checked": 252,
        "neutral_atoms_consumed": 165,
        "neutral_atoms_retained_unknown": 87,
        "fresh_c_lens_ratings_checked": 144,
        "fresh_d_semantic_reviews_checked": 226,
        "fresh_d_bounded_corrections": 1,
        "fresh_d_corrected_outcome_ids": ["AO-A53-024-01"],
        "lineage_status": "PASS_CORRECTED_CLEAN_SPEC_CHAIN_STATIC_ONLY",
        "formal_edges": 0,
        "final_no_matches": 0,
        "canonical_matrix_sha256": CANONICAL_MATRIX_SHA256,
        "canonical_mapping_credit_fraction": "0/340",
        "all_credits_false": True,
    },
    "credit_boundary": credit_boundary,
    "external_mutations_attestation": "NONE_STATIC_AUDIT_EVIDENCE_INTEGRATION_ONLY",
}
assert_zero_credit(agent_register)

write_json(EVIDENCE_DIR / "current-facet-neutral-comparison-wave-02.json", output)
write_json(EVIDENCE_DIR / "current-facet-neutral-comparison-agent-register.json", agent_register)
