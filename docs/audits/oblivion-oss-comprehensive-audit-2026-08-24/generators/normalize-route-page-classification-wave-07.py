#!/usr/bin/env python3
"""Normalize the three RUN-078 route/page classification producer artifacts."""

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
OUTPUT_REL = "evidence/source/current-route-page-classification-wave-07.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL
GENERATED_ON = "2026-08-25T12:05:00+12:00"
ALLOWED_PREDECESSOR_OUTPUT_SHA256S = {
    "8374eae9691abf1e8107dc283cb382b147606c506ee4c82744e4a57eb5b0d5bc",
}

PRODUCERS = {
    "A": {
        "path": "evidence/source/raw-run-078a-route-page-classification-wave-07.json",
        "generator": "generators/build-run-078a-route-page-classification-wave-07.py",
    },
    "B": {
        "path": "evidence/source/raw-run-078b-route-page-classification-wave-07.json",
        "generator": "generators/build-run-078b-route-page-classification-wave-07.py",
    },
    "C": {
        "path": "evidence/source/raw-run-078c-route-page-classification-wave-07.json",
        "generator": "generators/build-run-078c-route-page-classification-wave-07.py",
    },
}


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def git_text(*args: str) -> str:
    return subprocess.check_output(
        ["git", *args], cwd=REPO_DIR, text=True, encoding="utf-8"
    ).strip()


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def current_generator_sha256() -> str:
    return sha256_bytes(Path(__file__).resolve().read_bytes())


def require_string_list(value: object, label: str) -> list[str]:
    assert isinstance(value, list), label
    assert all(isinstance(item, str) and item for item in value), label
    assert len(value) == len(set(value)), label
    return value


def decision_map(
    rows: list[dict], id_key: str, expected_ids: list[str], required_keys: list[str]
) -> dict[str, dict]:
    assert isinstance(rows, list)
    mapped: dict[str, dict] = {}
    for row in rows:
        assert isinstance(row, dict)
        assert set(required_keys).issubset(row), (id_key, set(required_keys) - set(row))
        row_id = row[id_key]
        assert isinstance(row_id, str) and row_id
        assert row_id not in mapped, row_id
        mapped[row_id] = row
    assert set(mapped) == set(expected_ids), (id_key, set(expected_ids) - set(mapped), set(mapped) - set(expected_ids))
    return mapped


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

    manifest = read_json(MANIFEST_REL)
    assert manifest["pins"]["checkpoint_commit"] == "a2e5392b2a97d6548a93fc0897f782d05e404a83"
    assert manifest["pins"]["application_commit"] == APPLICATION_COMMIT
    review_contract = manifest["review_contract"]
    required_top = set(review_contract["producer_required_top_level_keys"])
    required_pin_bindings = set(review_contract["producer_required_pin_bindings"])
    allowed_route_classifications = set(review_contract["allowed_ownership_classifications"])
    allowed_page_classifications = set(review_contract["allowed_page_prompt_classifications"])
    canonical_feature_ids = {row["feature_id"] for row in manifest["canonical_targets"]}
    assert len(canonical_feature_ids) == 340

    route_by_id = {
        row["route_record_id"]: row
        for row in manifest["route_universe"]["primary_route_facade_callsites"]
    }
    route_by_id.update(
        {
            row["route_record_id"]: row
            for row in manifest["route_universe"]["route_like_sentinels"]
        }
    )
    name_by_id = {
        row["name_record_id"]: row
        for row in manifest["route_universe"]["fluent_name_callsites"]
    }
    page_by_id = {
        row["page_record_id"]: row for row in manifest["page_universe"]["page_roots"]
    }
    residual_by_id = {
        row["feature_id"]: row for row in manifest["residual_scoped_gaps"]["records"]
    }
    route_name_gap_by_id = {
        row["feature_id"]: row for row in manifest["route_name_gaps"]["records"]
    }

    normalized_routes: list[dict] = []
    normalized_names: list[dict] = []
    normalized_pages: list[dict] = []
    normalized_residuals: list[dict] = []
    normalized_route_name_gaps: list[dict] = []
    producer_summaries: list[dict] = []

    for partition_row in manifest["partitions"]["records"]:
        partition = partition_row["partition_id"]
        producer_spec = PRODUCERS[partition]
        producer_path = producer_spec["path"]
        producer = read_json(producer_path)
        assert required_top.issubset(producer), (partition, required_top - set(producer))
        assert producer["partition_id"] == partition
        assert producer["wrote_files"] is True
        assert producer.get("write_scope") == [producer_spec["generator"], producer_path]
        assert producer.get("outside_scope_files_written") == []
        assert required_pin_bindings.issubset(producer["pins"])
        assert producer["pins"]["manifest_sha256"] == MANIFEST_SHA256
        assert producer["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
        assert producer["pins"]["checkpoint_tree"] == CHECKPOINT_TREE
        assert producer["pins"]["application_commit"] == APPLICATION_COMMIT
        assert producer["pins"]["application_tree"] == APPLICATION_TREE
        assert producer["pins"]["partition_id"] == partition
        assert producer["credit_boundary"] == manifest["credit_boundary"]
        assert not any(producer["credit_boundary"].values())

        expected_route_ids = partition_row["route_record_ids"] + partition_row["route_like_sentinel_ids"]
        expected_name_ids = partition_row["name_record_ids"]
        expected_page_ids = partition_row["page_record_ids"]
        expected_residual_ids = partition_row["residual_feature_ids"]
        expected_route_name_gap_ids = partition_row["route_name_gap_feature_ids"]

        route_map = decision_map(
            producer["route_decisions"],
            "route_record_id",
            expected_route_ids,
            review_contract["producer_required_route_decision_keys"],
        )
        name_map = decision_map(
            producer["name_decisions"],
            "name_record_id",
            expected_name_ids,
            review_contract["producer_required_name_decision_keys"],
        )
        page_map = decision_map(
            producer["page_decisions"],
            "page_record_id",
            expected_page_ids,
            review_contract["producer_required_page_decision_keys"],
        )
        residual_map = decision_map(
            producer["residual_scoped_decisions"],
            "feature_id",
            expected_residual_ids,
            review_contract["producer_required_residual_scoped_decision_keys"],
        )
        route_name_gap_map = decision_map(
            producer["route_name_gap_decisions"],
            "feature_id",
            expected_route_name_gap_ids,
            review_contract["producer_required_route_name_gap_decision_keys"],
        )

        for route_id in expected_route_ids:
            decision = route_map[route_id]
            assert decision["classification"] in allowed_route_classifications
            reviewed_ids = require_string_list(decision["reviewed_feature_ids"], route_id)
            assert set(reviewed_ids).issubset(canonical_feature_ids)
            if decision["classification"] == "EXPLICIT_UNMAPPED_SENTINEL":
                assert reviewed_ids == []
            require_string_list(decision["source_anchors"], route_id)
            assert isinstance(decision["rationale"], str) and decision["rationale"].strip()
            assert route_id in route_by_id
            normalized_routes.append({"partition_id": partition, **decision})

        for name_id in expected_name_ids:
            decision = name_map[name_id]
            source_row = name_by_id[name_id]
            confirmed = decision["relationship_classification_confirmed"]
            assert confirmed in {True, source_row["relationship_classification"]}
            reviewed_ids = require_string_list(decision["reviewed_feature_ids"], name_id)
            assert set(reviewed_ids).issubset(canonical_feature_ids)
            require_string_list(decision["source_anchors"], name_id)
            assert isinstance(decision["rationale"], str) and decision["rationale"].strip()
            normalized_names.append({"partition_id": partition, **decision})

        for page_id in expected_page_ids:
            decision = page_map[page_id]
            assert decision["prompt_classification"] in allowed_page_classifications
            reviewed_ids = require_string_list(decision["reviewed_feature_ids"], page_id)
            assert set(reviewed_ids).issubset(canonical_feature_ids)
            require_string_list(decision["source_anchors"], page_id)
            assert isinstance(decision["rationale"], str) and decision["rationale"].strip()
            assert page_id in page_by_id
            normalized_pages.append({"partition_id": partition, **decision})

        for feature_id in expected_residual_ids:
            decision = residual_map[feature_id]
            expected_fields = residual_by_id[feature_id]["missing_fields"]
            assert set(decision["missing_field_decisions"]) == set(expected_fields)
            for field, field_decision in decision["missing_field_decisions"].items():
                assert field in {"route_paths", "page_files", "backend_anchors", "test_anchors"}
                assert field_decision["status"] in {"ESTABLISHED", "RETAIN_NOT_ESTABLISHED"}
                assert isinstance(field_decision.get("rationale"), str) and field_decision["rationale"].strip()
                if field_decision["status"] == "ESTABLISHED":
                    assert isinstance(field_decision.get("value"), str)
                    assert field_decision["value"].strip() and field_decision["value"] != "NOT_ESTABLISHED_CURRENT_AUDIT"
                    if field_decision["value"].startswith("NOT_"):
                        assert field == "page_files" and field_decision["value"] == "NOT_APPLICABLE"
                    require_string_list(field_decision.get("source_anchors"), f"{feature_id}:{field}")
                else:
                    assert field_decision.get("value") in {None, "NOT_ESTABLISHED_CURRENT_AUDIT"}
            require_string_list(decision["source_anchors"], feature_id)
            assert isinstance(decision["rationale"], str) and decision["rationale"].strip()
            normalized_residuals.append({"partition_id": partition, **decision})

        for feature_id in expected_route_name_gap_ids:
            decision = route_name_gap_map[feature_id]
            route_name_decision = decision["route_name_decision"]
            assert route_name_decision["status"] in {"ESTABLISHED", "RETAIN_NOT_ESTABLISHED"}
            assert isinstance(route_name_decision.get("rationale"), str) and route_name_decision["rationale"].strip()
            if route_name_decision["status"] == "ESTABLISHED":
                assert isinstance(route_name_decision.get("value"), str)
                assert route_name_decision["value"].strip() and route_name_decision["value"] != "NOT_ESTABLISHED_CURRENT_AUDIT"
                require_string_list(route_name_decision.get("source_anchors"), feature_id)
            else:
                assert route_name_decision.get("value") in {None, "NOT_ESTABLISHED_CURRENT_AUDIT"}
            require_string_list(decision["source_anchors"], feature_id)
            assert isinstance(decision["rationale"], str) and decision["rationale"].strip()
            assert feature_id in route_name_gap_by_id
            normalized_route_name_gaps.append({"partition_id": partition, **decision})

        producer_summaries.append(
            {
                "partition_id": partition,
                "run_id": producer["run_id"],
                "path": producer_path,
                "sha256": sha256_file(producer_path),
                "generator": producer_spec["generator"],
                "generator_sha256": sha256_file(producer_spec["generator"]),
                "counts": {
                    "route_decisions": len(route_map),
                    "name_decisions": len(name_map),
                    "page_decisions": len(page_map),
                    "residual_scoped_decisions": len(residual_map),
                    "route_name_gap_decisions": len(route_name_gap_map),
                },
            }
        )

    assert len(normalized_routes) == 3218
    assert len(normalized_names) == 3245
    assert len(normalized_pages) == 711
    assert len(normalized_residuals) == 12
    assert len(normalized_route_name_gaps) == 244
    assert len({row["route_record_id"] for row in normalized_routes}) == 3218
    assert len({row["name_record_id"] for row in normalized_names}) == 3245
    assert len({row["page_record_id"] for row in normalized_pages}) == 711
    assert len({row["feature_id"] for row in normalized_residuals}) == 12
    assert len({row["feature_id"] for row in normalized_route_name_gaps}) == 244

    payload = {
        "schema_version": 1,
        "run_id": "RUN-078-ROUTE-PAGE-CLASSIFICATION-NORMALIZATION",
        "status": "THREE_PART_PRODUCER_STATIC_CLASSIFICATION_NORMALIZED_PENDING_INDEPENDENT_REVIEW_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": GENERATED_ON,
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "manifest_path": MANIFEST_REL,
            "manifest_sha256": MANIFEST_SHA256,
            "generator": f"generators/{Path(__file__).name}",
            "generator_sha256": current_generator_sha256(),
            "raw_producers": producer_summaries,
        },
        "counts": {
            "partitions": 3,
            "route_decisions": len(normalized_routes),
            "name_decisions": len(normalized_names),
            "page_decisions": len(normalized_pages),
            "residual_scoped_decisions": len(normalized_residuals),
            "route_name_gap_decisions": len(normalized_route_name_gaps),
            "route_classifications": dict(sorted(Counter(row["classification"] for row in normalized_routes).items())),
            "page_prompt_classifications": dict(sorted(Counter(row["prompt_classification"] for row in normalized_pages).items())),
            "residual_field_statuses": dict(
                sorted(
                    Counter(
                        field_decision["status"]
                        for row in normalized_residuals
                        for field_decision in row["missing_field_decisions"].values()
                    ).items()
                )
            ),
            "route_name_gap_statuses": dict(
                sorted(Counter(row["route_name_decision"]["status"] for row in normalized_route_name_gaps).items())
            ),
            "benchmark_mapping_credit": 0,
            "runtime_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
        },
        "producers": producer_summaries,
        "route_decisions": normalized_routes,
        "name_decisions": normalized_names,
        "page_decisions": normalized_pages,
        "residual_scoped_decisions": normalized_residuals,
        "route_name_gap_decisions": normalized_route_name_gaps,
        "review_gate": {
            "all_three_producer_partitions_present": True,
            "all_assigned_ids_have_exactly_one_decision": True,
            "independent_cyclic_review_complete": False,
            "integration_authorized": False,
        },
        "completion_boundary": manifest["completion_boundary"],
        "credit_boundary": manifest["credit_boundary"],
        "attestation": "Root-normalized producer evidence only. No independent review, matrix integration, framework reachability, runtime, build, application browser, executed tests, benchmark mapping, ease, Pass, release, or completion credit is awarded.",
    }
    assert not any(payload["completion_boundary"].values())
    assert not any(payload["credit_boundary"].values())

    encoded = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    assert json.loads(encoded.decode("utf-8")) == payload
    candidate_sha = sha256_bytes(encoded)
    if OUTPUT_PATH.exists():
        existing_sha = sha256_bytes(OUTPUT_PATH.read_bytes())
        assert existing_sha in ALLOWED_PREDECESSOR_OUTPUT_SHA256S | {candidate_sha}
    temporary = OUTPUT_PATH.with_name(OUTPUT_PATH.name + ".tmp-run078-normalize")
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
                "counts": payload["counts"],
            },
            separators=(",", ":"),
        )
    )


if __name__ == "__main__":
    main()
