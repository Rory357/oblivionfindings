#!/usr/bin/env python3
"""Normalize the three RUN-079 cyclic independent route/page reviews."""

from __future__ import annotations

import hashlib
import json
import os
import subprocess
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
CHECKPOINT_COMMIT = "87826adc6fb8c9f0b1ca5ea99dcdc06e32bbd6d0"
CHECKPOINT_TREE = "d1eb36fabc0f5150c81f2140e834347dca87dd25"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MANIFEST_REL = "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json"
MANIFEST_SHA256 = "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be"
PRODUCER_REL = "evidence/source/current-route-page-classification-wave-07.json"
PRODUCER_SHA256 = "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97"
PRODUCER_NORMALIZER_REL = "generators/normalize-route-page-classification-wave-07.py"
PRODUCER_NORMALIZER_SHA256 = "1aa28e6bcbcfe09a7dda2d539448b99e8435f9906aa40c8c43d9b559ea1e655a"
OUTPUT_REL = "evidence/source/current-route-page-independent-review-wave-07.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL
GENERATED_ON = "2026-08-25T12:45:00+12:00"

PRODUCERS = {
    "A": {
        "run_id": "RUN-078A-ROUTE-PAGE-CLASSIFICATION-PRODUCER",
        "path": "evidence/source/raw-run-078a-route-page-classification-wave-07.json",
        "sha256": "2a55b9a7848d41472d19aeb64dab5167c0d8f7f89244b0d6d9d9b353a2c963be",
        "generator": "generators/build-run-078a-route-page-classification-wave-07.py",
        "generator_sha256": "854cfe57444ac2e932431b1afa05baa9c10dac237bc7bb1f0f5932f1c552dc12",
        "counts": {
            "route_decisions": 1073,
            "name_decisions": 1105,
            "page_decisions": 237,
            "residual_scoped_decisions": 4,
            "route_name_gap_decisions": 82,
        },
    },
    "B": {
        "run_id": "RUN-078B-ROUTE-PAGE-CLASSIFICATION-WAVE-07",
        "path": "evidence/source/raw-run-078b-route-page-classification-wave-07.json",
        "sha256": "529e6dcbf5edbf1bb1a78c947b287931140cdfc68cef825610ebf525198a3bd7",
        "generator": "generators/build-run-078b-route-page-classification-wave-07.py",
        "generator_sha256": "d7b2f091ff313cba0a215ee654c37ee3c885205a1e008199e5413df17dcc9f1b",
        "counts": {
            "route_decisions": 1073,
            "name_decisions": 1068,
            "page_decisions": 237,
            "residual_scoped_decisions": 4,
            "route_name_gap_decisions": 81,
        },
    },
    "C": {
        "run_id": "RUN-078C-ROUTE-PAGE-CLASSIFICATION",
        "path": "evidence/source/raw-run-078c-route-page-classification-wave-07.json",
        "sha256": "18648cadca47d4dcea8db3b3ee1e044be93ab77903cce9734a4e15171ffd226e",
        "generator": "generators/build-run-078c-route-page-classification-wave-07.py",
        "generator_sha256": "76889b0502a3d309106cbe85cd64dd700f05ecee846f624cf907d6afbe6867aa",
        "counts": {
            "route_decisions": 1072,
            "name_decisions": 1072,
            "page_decisions": 237,
            "residual_scoped_decisions": 4,
            "route_name_gap_decisions": 81,
        },
    },
}

REVIEWS = {
    "RUN-079A": {
        "run_id": "RUN-079A-CORRECTED-PARTITION-B-INDEPENDENT-REVIEW-WAVE-07",
        "reviewer_partition_id": "A",
        "reviewed_partition_id": "B",
        "path": "evidence/source/raw-run-079a-independent-route-page-review-wave-07.json",
        "sha256": "52c6117b4858cf7a486c0b2764887778c3a8fe106b5789443a8bf562a59687f7",
        "counts": {
            "route_decisions": 1073,
            "name_decisions": 1068,
            "page_decisions": 237,
            "residual_scoped_decisions": 4,
            "route_name_gap_decisions": 81,
            "route_classifications": {"EXPLICIT_UNMAPPED_SENTINEL": 1027, "OWNER": 46},
            "page_classifications": {"Evidence gap": 156, "Reviewed": 81},
            "residual_statuses": {"ESTABLISHED": 2, "RETAIN_NOT_ESTABLISHED": 2},
            "route_name_gap_statuses": {"ESTABLISHED": 19, "RETAIN_NOT_ESTABLISHED": 62},
        },
    },
    "RUN-079B": {
        "run_id": "RUN-079B-CORRECTED-C-INDEPENDENT-REVIEW",
        "reviewer_partition_id": "B",
        "reviewed_partition_id": "C",
        "path": "evidence/source/raw-run-079b-independent-route-page-review-wave-07.json",
        "sha256": "7a57397122ffa2784f51772288097e8af6f7cedb02153dd7fa6bdeaeb520e233",
        "counts": {
            "route_decisions": 1072,
            "name_decisions": 1072,
            "page_decisions": 237,
            "residual_scoped_decisions": 4,
            "residual_scoped_cells": 4,
            "route_name_gap_decisions": 81,
            "route_classifications": {
                "ALIAS_OR_REDIRECT": 1,
                "EXPLICIT_UNMAPPED_SENTINEL": 961,
                "OWNER": 109,
                "SHARED_RELATION": 1,
            },
            "page_classifications": {"Evidence gap": 156, "Reviewed": 81},
            "route_name_gap_statuses": {"ESTABLISHED": 29, "RETAIN_NOT_ESTABLISHED": 52},
        },
    },
    "RUN-079C": {
        "run_id": "RUN-079C-CYCLIC-INDEPENDENT-REVIEW-OF-CORRECTED-A",
        "reviewer_partition_id": "C",
        "reviewed_partition_id": "A",
        "path": "evidence/source/raw-run-079c-independent-route-page-review-wave-07.json",
        "sha256": "a82a04add97e56187d2db968e684649b6bcd8c41eef90924cb7d57a3ed970b94",
        "counts": {
            "route_decisions": 1073,
            "name_decisions": 1105,
            "page_decisions": 237,
            "residual_scoped_decisions": 4,
            "residual_scoped_cells": 7,
            "route_name_gap_decisions": 82,
            "route_classifications": {
                "EXPLICIT_UNMAPPED_SENTINEL": 1015,
                "OWNER": 56,
                "SHARED_RELATION": 2,
            },
            "page_classifications": {"Evidence gap": 81, "Reviewed": 156},
            "residual_field_statuses": {"RETAIN_NOT_ESTABLISHED": 7},
            "route_name_gap_statuses": {"ESTABLISHED": 30, "RETAIN_NOT_ESTABLISHED": 52},
        },
    },
}

INVALID_ARRAY_KEYS = {
    "invalid_route_decisions",
    "invalid_name_decisions",
    "invalid_page_decisions",
    "invalid_residual_scoped_decisions",
    "invalid_route_name_gap_decisions",
}
EXPECTED_TOTAL_COUNTS = {
    "partitions": 3,
    "route_decisions": 3218,
    "name_decisions": 3245,
    "page_decisions": 711,
    "residual_scoped_decisions": 12,
    "route_name_gap_decisions": 244,
    "route_classifications": {
        "ALIAS_OR_REDIRECT": 1,
        "EXPLICIT_UNMAPPED_SENTINEL": 3003,
        "OWNER": 211,
        "SHARED_RELATION": 3,
    },
    "page_prompt_classifications": {"Evidence gap": 393, "Reviewed": 318},
    "residual_field_statuses": {"ESTABLISHED": 2, "RETAIN_NOT_ESTABLISHED": 13},
    "route_name_gap_statuses": {"ESTABLISHED": 78, "RETAIN_NOT_ESTABLISHED": 166},
    "benchmark_mapping_credit": 0,
    "runtime_credit": 0,
    "application_browser_credit": 0,
    "executed_test_credit": 0,
    "pass_credit": 0,
    "completion_credit": 0,
}


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def git_text(*args: str) -> str:
    return subprocess.check_output(
        ["git", *args], cwd=REPO_DIR, text=True, encoding="utf-8"
    ).strip()


def current_generator_sha256() -> str:
    return sha256_bytes(Path(__file__).resolve().read_bytes())


def all_false(values: dict) -> bool:
    return bool(values) and all(value is False for value in values.values())


def partition_rows(rows: list[dict], partition_id: str) -> list[dict]:
    selected = [row for row in rows if row["partition_id"] == partition_id]
    assert selected
    return selected


def count_partition(producer: dict, partition_id: str) -> dict:
    routes = partition_rows(producer["route_decisions"], partition_id)
    names = partition_rows(producer["name_decisions"], partition_id)
    pages = partition_rows(producer["page_decisions"], partition_id)
    residuals = partition_rows(producer["residual_scoped_decisions"], partition_id)
    route_name_gaps = partition_rows(producer["route_name_gap_decisions"], partition_id)
    return {
        "route_decisions": len(routes),
        "name_decisions": len(names),
        "page_decisions": len(pages),
        "residual_scoped_decisions": len(residuals),
        "residual_scoped_cells": sum(len(row["missing_field_decisions"]) for row in residuals),
        "route_name_gap_decisions": len(route_name_gaps),
        "route_classifications": dict(sorted(Counter(row["classification"] for row in routes).items())),
        "page_classifications": dict(sorted(Counter(row["prompt_classification"] for row in pages).items())),
        "residual_field_statuses": dict(
            sorted(
                Counter(
                    decision["status"]
                    for row in residuals
                    for decision in row["missing_field_decisions"].values()
                ).items()
            )
        ),
        "route_name_gap_statuses": dict(
            sorted(Counter(row["route_name_decision"]["status"] for row in route_name_gaps).items())
        ),
    }


def validate_review_counts(review_counts: dict, derived: dict) -> None:
    for key in (
        "route_decisions",
        "name_decisions",
        "page_decisions",
        "residual_scoped_decisions",
        "route_name_gap_decisions",
        "route_classifications",
        "page_classifications",
        "route_name_gap_statuses",
    ):
        assert review_counts[key] == derived[key], key
    if "residual_scoped_cells" in review_counts:
        assert review_counts["residual_scoped_cells"] == derived["residual_scoped_cells"]
    residual_key = "residual_field_statuses" if "residual_field_statuses" in review_counts else "residual_statuses"
    if residual_key in review_counts:
        assert review_counts[residual_key] == derived["residual_field_statuses"]


def main() -> None:
    assert git_text("branch", "--show-current") == "main"
    assert git_text("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git_text("rev-parse", f"{CHECKPOINT_COMMIT}^{{tree}}") == CHECKPOINT_TREE
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    product_diff = subprocess.run(
        ["git", "diff", "--quiet", CHECKPOINT_COMMIT, "--", "app", "routes", "resources/js", "tests"],
        cwd=REPO_DIR,
        check=False,
    )
    assert product_diff.returncode == 0

    assert sha256_file(MANIFEST_REL) == MANIFEST_SHA256
    assert sha256_file(PRODUCER_NORMALIZER_REL) == PRODUCER_NORMALIZER_SHA256
    assert sha256_file(PRODUCER_REL) == PRODUCER_SHA256
    manifest = read_json(MANIFEST_REL)
    producer = read_json(PRODUCER_REL)

    assert manifest["pins"]["application_commit"] == APPLICATION_COMMIT
    assert manifest["credit_boundary"] == producer["credit_boundary"]
    assert manifest["completion_boundary"] == producer["completion_boundary"]
    assert all_false(manifest["credit_boundary"])
    assert all_false(manifest["completion_boundary"])
    assert producer["run_id"] == "RUN-078-ROUTE-PAGE-CLASSIFICATION-NORMALIZATION"
    assert producer["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
    assert producer["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
    assert producer["pins"]["application_commit"] == APPLICATION_COMMIT
    assert producer["pins"]["application_tree"] == APPLICATION_TREE
    assert producer["pins"]["manifest_sha256"] == MANIFEST_SHA256
    assert producer["pins"]["generator"] == PRODUCER_NORMALIZER_REL
    assert producer["pins"]["generator_sha256"] == PRODUCER_NORMALIZER_SHA256
    assert producer["counts"] == EXPECTED_TOTAL_COUNTS
    assert all_false(producer["credit_boundary"])
    assert all_false(producer["completion_boundary"])
    assert producer["review_gate"] == {
        "all_three_producer_partitions_present": True,
        "all_assigned_ids_have_exactly_one_decision": True,
        "independent_cyclic_review_complete": False,
        "integration_authorized": False,
    }

    producer_pins = {row["partition_id"]: row for row in producer["producers"]}
    assert set(producer_pins) == set(PRODUCERS)
    derived_counts: dict[str, dict] = {}
    producer_inputs: list[dict] = []
    for partition_id, expected in PRODUCERS.items():
        assert sha256_file(expected["path"]) == expected["sha256"]
        assert sha256_file(expected["generator"]) == expected["generator_sha256"]
        raw = read_json(expected["path"])
        assert raw["run_id"] == expected["run_id"]
        assert raw["partition_id"] == partition_id
        assert raw["pins"]["manifest_sha256"] == MANIFEST_SHA256
        assert raw["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
        assert raw["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
        assert raw["pins"]["application_commit"] == APPLICATION_COMMIT
        assert raw["pins"]["application_tree"] == APPLICATION_TREE
        assert raw["pins"]["partition_id"] == partition_id
        assert raw["credit_boundary"] == manifest["credit_boundary"]
        assert all_false(raw["credit_boundary"])
        assert raw["wrote_files"] is True
        assert raw["write_scope"] == [expected["generator"], expected["path"]]
        assert raw["outside_scope_files_written"] == []

        summary = producer_pins[partition_id]
        assert summary["run_id"] == expected["run_id"]
        assert summary["path"] == expected["path"]
        assert summary["sha256"] == expected["sha256"]
        assert summary["generator"] == expected["generator"]
        assert summary["generator_sha256"] == expected["generator_sha256"]
        assert summary["counts"] == expected["counts"]
        derived_counts[partition_id] = count_partition(producer, partition_id)
        for key, value in expected["counts"].items():
            assert derived_counts[partition_id][key] == value
        producer_inputs.append(
            {
                "partition_id": partition_id,
                "run_id": expected["run_id"],
                "path": expected["path"],
                "sha256": expected["sha256"],
                "generator": expected["generator"],
                "generator_sha256": expected["generator_sha256"],
                "counts": expected["counts"],
            }
        )

    assert set(manifest["review_contract"]["independent_reviewer_required_bindings"]) == {
        "manifest_sha256",
        "checkpoint_commit",
        "application_commit",
        "partition_id",
        "every producer decision",
        "all-false credit boundary",
        "wrote_files=false",
        "attestation",
    }
    assert manifest["review_contract"]["cyclic_review"] == (
        "A reviews B, B reviews C, C reviews A; root alone normalizes and integrates."
    )

    normalized_reviews: list[dict] = []
    reviewers_seen: set[str] = set()
    reviewed_seen: set[str] = set()
    invalid_array_count = 0
    for review_id, expected in REVIEWS.items():
        assert sha256_file(expected["path"]) == expected["sha256"]
        raw = read_json(expected["path"])
        reviewer = expected["reviewer_partition_id"]
        reviewed = expected["reviewed_partition_id"]
        assert raw["schema_version"] == 1
        assert raw["run_id"] == expected["run_id"]
        assert raw["status"] == "GO"
        assert raw["reviewer_partition_id"] == reviewer
        assert raw["reviewed_partition_id"] == reviewed
        assert reviewer != reviewed
        reviewers_seen.add(reviewer)
        reviewed_seen.add(reviewed)

        producer_expected = PRODUCERS[reviewed]
        assert raw["pins"] == {
            "manifest_sha256": MANIFEST_SHA256,
            "producer_generator_sha256": producer_expected["generator_sha256"],
            "producer_raw_sha256": producer_expected["sha256"],
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
        }
        assert raw["counts"] == expected["counts"]
        validate_review_counts(raw["counts"], derived_counts[reviewed])

        invalid_keys = {key for key in raw if key.startswith("invalid_")}
        assert INVALID_ARRAY_KEYS.issubset(invalid_keys)
        for key in invalid_keys:
            assert raw[key] == [], (review_id, key)
            invalid_array_count += len(raw[key])
        completion_test = raw["completion_test"]
        assert completion_test["all_assigned_decisions_valid"] is True
        assert completion_test["independent_review_complete"] is True
        assert completion_test["producer_packet_accepted"] is True
        assert completion_test["audit_completion_awarded"] is False
        assert completion_test["downstream_credit_awarded"] is False
        assert raw["credit_boundary"] == manifest["credit_boundary"]
        assert all_false(raw["credit_boundary"])
        assert raw["wrote_files"] is False
        assert raw["write_scope"] == []
        assert raw["outside_scope_files_written"] == []
        assert isinstance(raw["attestation"], str) and raw["attestation"].strip()

        normalized_reviews.append(
            {
                "review_id": review_id,
                "run_id": expected["run_id"],
                "status": "GO",
                "reviewer_partition_id": reviewer,
                "reviewed_partition_id": reviewed,
                "path": expected["path"],
                "sha256": expected["sha256"],
                "reviewed_producer_path": producer_expected["path"],
                "reviewed_producer_sha256": producer_expected["sha256"],
                "reviewed_producer_generator": producer_expected["generator"],
                "reviewed_producer_generator_sha256": producer_expected["generator_sha256"],
                "counts": expected["counts"],
                "invalid_decisions": 0,
                "wrote_files": False,
                "credit_awarded": False,
            }
        )

    assert reviewers_seen == set(PRODUCERS)
    assert reviewed_seen == set(PRODUCERS)
    assert {
        (row["reviewer_partition_id"], row["reviewed_partition_id"])
        for row in normalized_reviews
    } == {("A", "B"), ("B", "C"), ("C", "A")}
    assert invalid_array_count == 0

    generator_rel = f"generators/{Path(__file__).name}"
    payload = {
        "schema_version": 1,
        "run_id": "RUN-079-ROUTE-PAGE-INDEPENDENT-REVIEW-NORMALIZATION",
        "status": "THREE_PART_CYCLIC_INDEPENDENT_REVIEW_GO_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": GENERATED_ON,
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "manifest_path": MANIFEST_REL,
            "manifest_sha256": MANIFEST_SHA256,
            "normalized_producer_path": PRODUCER_REL,
            "normalized_producer_sha256": PRODUCER_SHA256,
            "producer_normalizer": PRODUCER_NORMALIZER_REL,
            "producer_normalizer_sha256": PRODUCER_NORMALIZER_SHA256,
            "generator": generator_rel,
            "generator_sha256": current_generator_sha256(),
            "raw_producers": producer_inputs,
            "raw_reviews": [
                {
                    "review_id": row["review_id"],
                    "path": row["path"],
                    "sha256": row["sha256"],
                    "reviewer_partition_id": row["reviewer_partition_id"],
                    "reviewed_partition_id": row["reviewed_partition_id"],
                }
                for row in normalized_reviews
            ],
        },
        "cyclic_review": {
            "required_mapping": {"A": "B", "B": "C", "C": "A"},
            "observed_mapping": {
                row["reviewer_partition_id"]: row["reviewed_partition_id"]
                for row in normalized_reviews
            },
            "self_review_count": 0,
        },
        "counts": {
            "review_artifacts": 3,
            "go_reviews": 3,
            "reviewer_partitions": 3,
            "reviewed_partitions": 3,
            "route_decisions_reviewed": EXPECTED_TOTAL_COUNTS["route_decisions"],
            "name_decisions_reviewed": EXPECTED_TOTAL_COUNTS["name_decisions"],
            "page_decisions_reviewed": EXPECTED_TOTAL_COUNTS["page_decisions"],
            "residual_scoped_decisions_reviewed": EXPECTED_TOTAL_COUNTS["residual_scoped_decisions"],
            "residual_scoped_cells_reviewed": sum(
                row["residual_scoped_cells"] for row in derived_counts.values()
            ),
            "route_name_gap_decisions_reviewed": EXPECTED_TOTAL_COUNTS["route_name_gap_decisions"],
            "invalid_decisions": 0,
            "review_artifacts_wrote_files": 0,
            "route_classifications": EXPECTED_TOTAL_COUNTS["route_classifications"],
            "page_prompt_classifications": EXPECTED_TOTAL_COUNTS["page_prompt_classifications"],
            "residual_field_statuses": EXPECTED_TOTAL_COUNTS["residual_field_statuses"],
            "route_name_gap_statuses": EXPECTED_TOTAL_COUNTS["route_name_gap_statuses"],
            "benchmark_mapping_credit": 0,
            "runtime_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
        },
        "reviews": normalized_reviews,
        "review_gate": {
            "all_manifest_producer_and_review_hashes_exact": True,
            "all_producer_pins_exact": True,
            "cyclic_mapping_a_to_b_b_to_c_c_to_a_exact": True,
            "all_three_review_statuses_go": True,
            "all_review_wrote_files_false": True,
            "all_review_write_scopes_empty": True,
            "all_invalid_decision_arrays_empty": True,
            "all_assigned_decisions_reviewed": True,
            "exact_counts_and_pins_match": True,
            "all_credit_boundaries_false": True,
            "independent_cyclic_review_complete": True,
            "static_matrix_field_integration_authorized": True,
            "other_downstream_integration_authorized": False,
        },
        "completion_boundary": manifest["completion_boundary"],
        "credit_boundary": manifest["credit_boundary"],
        "attestation": "Root-only deterministic normalization of three fresh cyclic independent read-only reviews. Authorization is limited to static matrix field integration of accepted decisions; framework reachability, runtime, build, application browser, executed tests, benchmark mapping, ease, Pass, release, completion and audit-complete credit remain zero.",
    }
    assert all_false(payload["completion_boundary"])
    assert all_false(payload["credit_boundary"])
    assert payload["review_gate"]["static_matrix_field_integration_authorized"] is True
    assert payload["review_gate"]["other_downstream_integration_authorized"] is False

    encoded = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    assert json.loads(encoded.decode("utf-8")) == payload
    candidate_sha = sha256_bytes(encoded)
    if OUTPUT_PATH.exists():
        assert sha256_bytes(OUTPUT_PATH.read_bytes()) == candidate_sha
    temporary = OUTPUT_PATH.with_name(OUTPUT_PATH.name + ".tmp-run079-normalize")
    assert not temporary.exists()
    try:
        temporary.write_bytes(encoded)
        assert json.loads(temporary.read_text(encoding="utf-8")) == payload
        os.replace(temporary, OUTPUT_PATH)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert sha256_bytes(OUTPUT_PATH.read_bytes()) == candidate_sha
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_REL,
                "sha256": candidate_sha,
                "generator_sha256": payload["pins"]["generator_sha256"],
                "counts": payload["counts"],
                "review_gate": payload["review_gate"],
            },
            separators=(",", ":"),
        )
    )


if __name__ == "__main__":
    main()
