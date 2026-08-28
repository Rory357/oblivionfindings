#!/usr/bin/env python3
"""Materialize the bounded RUN157 medication source-receipt reporting receipt.

The four reporting surfaces are deliberately pre-materialized and hash-pinned.
This program validates them and writes only its JSON receipt.  It does not run
the dashboard builder and awards no medication, runtime, browser, finding,
origin-currency, publication, or completion credit.
"""
from __future__ import annotations

import ast
import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()

RUN_ID = (
    "RUN-157-REVIEWED-MEDICATION-GOVERNANCE-SOURCE-MAIN-RECEIPT-"
    "REPORTING-WAVE-27"
)
STATUS = (
    "REPORTING_MATERIALIZED_REVIEWED_MEDICATION_SOURCE_RECEIPT_TWO_CHECKPOINT_"
    "358_OF_359_THREE_PROVISIONAL_REFERENCES_ZERO_REMEDIATION_FINDING_OR_"
    "COMPLETION_CREDIT"
)
MATERIALIZER = (
    "generators/materialize-run-157-reviewed-medication-governance-source-main-"
    "receipt-reporting-wave-27.py"
)
OUTPUT = (
    "evidence/source/current-run-157-reviewed-medication-governance-source-main-"
    "receipt-reporting-wave-27.json"
)

CHECKPOINT_COMMIT = "81abe37faa126f98ce47c7ca90cf569fe9c43c0d"
CHECKPOINT_TREE = "19f262b9cf14714c3ea204a9ed78c4d590098b41"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
HISTORICAL_MERGE = "cd5d34e6b8aa7e494808745041ec1dfa187dc101"
EFFECTIVE_APPLICATION = "c5c0ad0903d2e2e2229d5d0090fc0a69a2206f0f"
RUN156_CHECKPOINT = "86b232cb14967c63ff345ac5208ec6d4c379f24f"
RUN156_COMMIT = "33ee55b84944fab3e52eee3c3e303c4c30eb4a44"
OBSERVED_LOCAL_ORIGIN_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"

SURFACES = (
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "generators/build-current-audit-dashboard.py",
)

BASELINE_OUTPUTS = {
    "00-executive-summary.md": {
        "sha256": "11f449632cab9da5919bbb24077be791c4ee6f03fb6c7e527d151f4bec9ac878",
        "blob_id": "9024281f34e88d81ade8178c165d9361db0c2ffa",
        "bytes": 104119,
        "lines": 464,
    },
    "01-repository-module-map.md": {
        "sha256": "8ec132f9e16c194ad605c7c559702674e68c907015b295a50b8929168a810586",
        "blob_id": "4c8e82d4da85910100602b89962c30d834c0a485",
        "bytes": 29167,
        "lines": 206,
    },
    "13-unresolved-questions-and-evidence-gaps.md": {
        "sha256": "ce1f10a42c66b469fec8d36e69d02f740418faebaea1b4fc738183ecb0bc619d",
        "blob_id": "c1df75b4e700f8507445b6a2cf35c5a7f5fd2e77",
        "bytes": 26056,
        "lines": 83,
    },
    "generators/build-current-audit-dashboard.py": {
        "sha256": "11efb61559203bb965e73862bf4279d555b1122e669f7f7038cfe7c12ec1cf2e",
        "blob_id": "64304bbd708c0eb617413b10270de997f465676a",
        "bytes": 341686,
        "lines": 2909,
    },
}

EXPECTED_OUTPUTS = {
    "00-executive-summary.md": {
        "sha256": "1749878cddd5a57bda17a261b3f40065aea51c0a8b03a996d0d4016133f86a2d",
        "blob_id": "a8b41f6f06edb33848b296ef6f01fd2583bf68f6",
        "bytes": 108142,
        "lines": 474,
    },
    "01-repository-module-map.md": {
        "sha256": "e31bf7b7edd2ddbb3f6f01e0f8e89c6437d23a83962aa5f817eabb4a22d1584b",
        "blob_id": "3afc1c6498cbe80922c44b6b8dadbfe898417460",
        "bytes": 31351,
        "lines": 212,
    },
    "13-unresolved-questions-and-evidence-gaps.md": {
        "sha256": "d2bbc7824e99c99427f29668449692b8db0bea068805c289e1734f348672773e",
        "blob_id": "d2ad5b5d16f71dc4de5e7567033ca1bae8bb0344",
        "bytes": 27786,
        "lines": 85,
    },
    "generators/build-current-audit-dashboard.py": {
        "sha256": "ad8da48d9308c7b0ce2df076e44f8d748d6e47132b9b02f4d98449434b8851f7",
        "blob_id": "2059768eed7430630063e3daa3213e942540ab6b",
        "bytes": 364303,
        "lines": 3145,
    },
}

PRESERVED = {
    "findings.json": {
        "sha256": "acdbbeba11e342ba2f6feb6c16854a8b0f116d37a73b0f6f3eb768c7e5b1faf2",
        "blob_id": "7c7216aade53e1b4075bbcb3080319d69a172bdc",
    },
    "audit-dashboard.html": {
        "sha256": "7b01f79ed706dc407da445345ea6a5da1a9c0c774657341a3986cb58d0d37f64",
        "blob_id": "0792fcfea39c485b42039891415d0cd98bc66cd0",
    },
    "03-feature-to-benchmark-matrix.csv": {
        "sha256": "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
        "blob_id": "1f5fdab3ae80ae4ec1b9bc4ee47eef695bdd5416",
    },
    "06-open-source-benchmark-register.csv": {
        "sha256": "5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884",
        "blob_id": "f96ef7ac1f6e19f614bdce8d663cfd08ec795995",
    },
}

RUN155_GENERATOR = "generators/materialize-run-155-audit-dashboard-verification-wave-26.py"
RUN155_RECEIPT = "evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json"
RUN156_GENERATOR = "generators/materialize-run-156-medication-governance-source-main-receipt-wave-27.py"
RUN156_RECEIPT = "evidence/source/current-run-156-medication-governance-source-main-receipt-wave-27.json"
RUN156R_GENERATOR = "generators/materialize-independent-medication-governance-source-main-receipt-review-wave-27.py"
RUN156R_RECEIPT = "evidence/source/current-run-156r-independent-medication-governance-source-main-receipt-review-wave-27.json"

LINEAGE = {
    RUN155_GENERATOR: {
        "sha256": "1f2bd52237f28cb11f79e4fa65d1f0a82889fd313fbee08d4e222816a7147139",
        "blob_id": "8b9604e3c316be98c33bc0e1d97e2aea4f0fba9c",
        "bytes": 23854,
        "lines": 366,
    },
    RUN155_RECEIPT: {
        "sha256": "576605975af18a35be413e48e4da042e6bae706fc2438c9e7cfa89b5c9394fe3",
        "blob_id": "1f8e21521f4247f39cd23dff258909e4bbcc96ce",
        "bytes": 17688,
        "lines": 429,
    },
    RUN156_GENERATOR: {
        "sha256": "e611f494567ce966e5c678a9579bb26278da0a87d814b649ccf973b102bcd4ea",
        "blob_id": "0caeb16bf63e0d6b4cd084c539a6d74c303d6cfb",
        "bytes": 35600,
        "lines": 779,
    },
    RUN156_RECEIPT: {
        "sha256": "56094f7e83acf8000d0b680d751cc3d27e8627916eef45173002b43207091e76",
        "blob_id": "38e69aa0897cc8b8f7d55363f5bc1ed491411095",
        "bytes": 16444,
        "lines": 330,
    },
    RUN156R_GENERATOR: {
        "sha256": "fc2498be1f1e6539c1dcb898e424c47599588388522e8496f82ef70f3754b915",
        "blob_id": "451638c20d15424ac1d49cffbf3814c6696b0a2c",
        "bytes": 25584,
        "lines": 607,
    },
    RUN156R_RECEIPT: {
        "sha256": "01945390f1d2c8a70dfcef6ea7327aa9f63c84f543dec5a6d8c67c7625dd032a",
        "blob_id": "1fe1f8d9d59a8729cb9c19f71a33b48d59df1e99",
        "bytes": 13268,
        "lines": 277,
    },
}

MED_RECORD_HASHES = {
    "MED-RBAC-01": "aa35c543ac25d15d074b344abd6ce8750975717f6c6e229d36986256c5a301ea",
    "MED-CD-SCOPE-01": "dd86bf94f3b4d894e95c56c95a9409ce803b8d82d108cdd3c42f3343e348cd21",
    "MED-CD-ATOMICITY-01": "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1",
}

RUN156_SECTION_SHA256 = {
    "two_checkpoint_rule": "28de01209af61fa1dfae7c485e080e75262628e5b8debbd7fed12c7a61a51a58",
    "historical_merge_checkpoint": "80c167084d6ac68480dd13ce113b12432a45202888eb0731decb967d38165630",
    "historical_merge_payload": "68c789280775cbecfe7e87af9c66484769f360bab0b397c26925a472d2384055",
    "effective_application_checkpoint": "5b65370071992929c407b1ecbc98dc8c53285f5c8cfbbcfe51b2491a27084b7d",
    "post_merge_my_day_delta": "b40db725132636b4bf80e02f23e304296a99e6d921dea3e010d54720c9db7f43",
    "later_audit_only_lineage": "04810f7090ebde1801d66b15b2e7019a38fe1551bd0167418e2f8399d508ed5f",
    "provisional_finding_reference_boundary": "15697f6a3f597821a968f109a17eff025238cf01c84927ae46b9aca77fa48e0b",
    "local_only_origin_attestation": "e4fec7df0eaafe95cc462d251e4fb5d3dc5f10969e718c82aa15ccd90ced8ce4",
    "credit_boundary": "f826771e88ea7bb8ec1806668c6eab8ebaaea0078781e38a73dc3bbd40c225f9",
    "completion_boundary": "28cde4feb2cc6a2422df2e105235986f0cb89faec712384e2dfb32f811c53dd2",
}

RUN156R_SECTION_SHA256 = {
    "review_process_disclosure": "364ad622b5d05f6df15a17f32c1371a5498ae8ad76a77c9dec5c027c50adfa54",
    "review_records": "96f41b7725420ff0bea46db895b97cfc7ad686c80a3fb4da060c5a8bda89ab2f",
    "decision": "01345cb20db64cd5d718627c72de94a051933c7cff470f733f5e596c3ea25eaa",
    "noninheritance_boundary": "d9e0c8d2a3687ca3991ec6c051621f9558cd2ad6184d1a78ad3d28597f3e51e4",
    "credit_boundary": "55955676b60a618da5f5ea46404758d45212601d1264d5b3d8b0fd3bc6906812",
    "completion_boundary": "28cde4feb2cc6a2422df2e105235986f0cb89faec712384e2dfb32f811c53dd2",
}


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        ["git", *args], cwd=ROOT, check=check, capture_output=True
    )


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").rstrip("\r\n")


def git_lines(*args: str) -> list[str]:
    value = git(*args)
    return [] if not value else value.splitlines()


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256_bytes(
        json.dumps(
            value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
        ).encode("utf-8")
    )


def strict_json_bytes(payload: bytes, label: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key {key!r} in {label}"
            result[key] = value
        return result

    value = json.loads(payload, object_pairs_hook=hook)
    assert isinstance(value, dict), label
    return value


def strict_json(relative: str) -> dict[str, Any]:
    return strict_json_bytes((AUDIT / relative).read_bytes(), relative)


def assert_lf_file(path: Path) -> bytes:
    payload = path.read_bytes()
    assert payload.endswith(b"\n"), path
    assert b"\r\n" not in payload, path
    assert not payload.startswith(b"\xef\xbb\xbf"), path
    assert all(line.rstrip(b" \t") == line for line in payload.splitlines()), path
    return payload


def file_record(relative: str) -> dict[str, Any]:
    payload = assert_lf_file(AUDIT / relative)
    return {
        "sha256": sha256_bytes(payload),
        "blob_id": git("hash-object", "--", str(AUDIT / relative)),
        "bytes": len(payload),
        "lines": len(payload.decode("utf-8").splitlines()),
    }


def status_lines() -> set[str]:
    return set(git_lines("status", "--porcelain=v1", "--untracked-files=all"))


def expected_status(include_receipt: bool) -> set[str]:
    result = {f" M {PREFIX}/{relative}" for relative in SURFACES}
    result.add(f"?? {PREFIX}/{MATERIALIZER}")
    if include_receipt:
        result.add(f"?? {PREFIX}/{OUTPUT}")
    return result


def validate_prewrite_status() -> None:
    current = status_lines()
    assert current in (
        expected_status(include_receipt=False),
        expected_status(include_receipt=True),
    ), sorted(current)
    assert not list(AUDIT.rglob("__pycache__"))


def validate_surface_pins() -> dict[str, dict[str, Any]]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE

    for relative, expected in BASELINE_OUTPUTS.items():
        repository_path = f"{PREFIX}/{relative}"
        baseline = run_git("show", f"HEAD:{repository_path}").stdout
        assert sha256_bytes(baseline) == expected["sha256"], relative
        assert git("rev-parse", f"HEAD:{repository_path}") == expected["blob_id"]
        assert len(baseline) == expected["bytes"]
        assert len(baseline.decode("utf-8").splitlines()) == expected["lines"]

    outputs = {relative: file_record(relative) for relative in SURFACES}
    assert outputs == EXPECTED_OUTPUTS
    assert all(
        outputs[relative]["sha256"] != BASELINE_OUTPUTS[relative]["sha256"]
        for relative in SURFACES
    )
    assert set(git_lines("diff", "--name-only", "HEAD", "--")) == {
        f"{PREFIX}/{relative}" for relative in SURFACES
    }
    diff_check = run_git(
        "diff", "--check", "HEAD", "--", *(f"{PREFIX}/{relative}" for relative in SURFACES),
        check=False,
    )
    assert diff_check.returncode == 0 and diff_check.stdout == b"" and diff_check.stderr == b""

    for relative, expected in PRESERVED.items():
        payload = (AUDIT / relative).read_bytes()
        assert sha256_bytes(payload) == expected["sha256"], relative
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == expected["blob_id"]
    return outputs


def validate_lineage() -> tuple[dict[str, Any], dict[str, Any], dict[str, Any]]:
    for relative, expected in LINEAGE.items():
        assert file_record(relative) == expected, relative
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == expected["blob_id"]

    run155 = strict_json(RUN155_RECEIPT)
    assert run155["run_id"] == "RUN-155-AUDIT-DASHBOARD-VERIFICATION-WAVE-26"
    assert run155["status"] == (
        "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT"
    )
    verification = run155["verification"]
    assert verification["viewports_verified"] == 4
    assert verification["navigation_targets"] == "10/10"
    assert verification["unique_local_links"] == 379
    assert verification["exact_visible_static_boundary_check_count"] == 38
    assert all(verification["exact_visible_static_boundary_checks"].values())
    assert run155["pins"]["dashboard_html"]["sha256"] == PRESERVED["audit-dashboard.html"]["sha256"]
    assert [key for key, value in run155["credit_boundary"].items() if value] == [
        "exact_audit_dashboard_artifact"
    ]
    assert all(value is False for value in run155["completion_boundary"].values())

    run156 = strict_json(RUN156_RECEIPT)
    assert run156["run_id"] == "RUN-156-MEDICATION-GOVERNANCE-SOURCE-MAIN-RECEIPT-WAVE-27"
    assert run156["status"] == (
        "TWO_CHECKPOINT_MEDICATION_SOURCE_INTEGRATION_RECEIPT_358_OF_359_"
        "MERGE_PATHS_EFFECTIVE_ONE_SUPERSEDED_ZERO_OUTCOME_CREDIT"
    )
    for key, expected in RUN156_SECTION_SHA256.items():
        assert canonical_sha256(run156[key]) == expected, key
    assert run156["pins"]["receipt_checkpoint_commit"] == RUN156_CHECKPOINT
    assert run156["pins"]["historical_merge_commit"] == HISTORICAL_MERGE
    assert run156["pins"]["effective_application_commit"] == EFFECTIVE_APPLICATION
    rule = run156["two_checkpoint_rule"]
    assert rule["historical_merge_tree_is_not_presented_as_the_effective_application_tree"] is True
    assert rule["all_359_historical_merge_payload_blobs_claimed_current"] is False
    assert rule["effective_source_uses_358_unchanged_merge_payload_blobs_and_one_superseding_blob"] is True
    payload = run156["historical_merge_payload"]["first_parent_payload"]
    assert (payload["paths"], payload["added_paths"], payload["modified_paths"]) == (359, 87, 272)
    effective = run156["effective_application_checkpoint"]
    assert (
        effective["historical_merge_payload_paths"],
        effective["historical_merge_payload_blobs_unchanged"],
        effective["historical_merge_payload_blobs_superseded"],
    ) == (359, 358, 1)
    assert effective["superseded_merge_payload_paths"] == [
        "resources/js/pages/my-day/index.tsx"
    ]
    assert run156["post_merge_my_day_delta"]["path_count"] == 3
    later = run156["later_audit_only_lineage"]
    assert later["commits_after_effective_application_checkpoint"] == 3
    assert later["cumulative_changed_paths"] == 12
    assert later["all_later_paths_inside_exact_audit_root"] is True
    assert later["non_audit_tracked_entries"] == 12784
    assert later["effective_and_receipt_checkpoint_non_audit_manifests_equal"] is True
    assert [key for key, value in run156["credit_boundary"].items() if value] == [
        "GIT_SOURCE_INTEGRATION_RECEIPT"
    ]
    assert all(value is False for value in run156["completion_boundary"].values())

    run156r = strict_json(RUN156R_RECEIPT)
    assert run156r["run_id"] == (
        "RUN-156R-INDEPENDENT-MEDICATION-GOVERNANCE-SOURCE-MAIN-RECEIPT-"
        "REVIEW-WAVE-27"
    )
    assert run156r["status"] == (
        "GO_THREE_PART_TWO_CHECKPOINT_SOURCE_RECEIPT_REVIEW_REPORTING_ONLY_"
        "ZERO_REMEDIATION_OR_DOWNSTREAM_CREDIT"
    )
    for key, expected in RUN156R_SECTION_SHA256.items():
        assert canonical_sha256(run156r[key]) == expected, key
    disclosure = run156r["review_process_disclosure"]
    assert disclosure["review_record_materializer"] == "/root"
    assert disclosure["review_records_materialized_by_one_generator"] is True
    assert disclosure["cross_reviewer_coordination_occurred"] is True
    assert disclosure["blind_or_isolated_reviews_claimed"] is False
    assert len(disclosure["reviewer_evidence_lanes"]) == 3
    decision = run156r["decision"]
    assert decision == {
        "verdict": "GO",
        "independent_reviews": 3,
        "distinct_reviewer_lanes": 3,
        "blind_or_isolated_reviews": False,
        "discrepancies": 0,
        "reporting_materialization_authorized": True,
        "gate_4_complete": False,
        "audit_complete": False,
    }
    assert [key for key, value in run156r["credit_boundary"].items() if value] == [
        "INDEPENDENT_SOURCE_RECEIPT_REVIEW_FOR_REPORTING"
    ]
    assert all(value is False for value in run156r["completion_boundary"].values())
    return run155, run156, run156r


def validate_findings(run156: dict[str, Any]) -> dict[str, Any]:
    findings = strict_json("findings.json")
    assert sha256_bytes((AUDIT / "findings.json").read_bytes()) == PRESERVED["findings.json"]["sha256"]
    assert len(findings["records"]) == 12
    counts = findings["counts"]
    assert counts["provisional_P1"] == 12
    assert counts["final_P0"] == counts["final_P1"] == 0
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["static_source_feature_ownership_records"] == 664
    assert counts["static_source_feature_ownership_route_records"] == 307
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 95

    records = {row["id"]: row for row in findings["records"]}
    references = run156["provisional_finding_reference_boundary"]
    assert references["reference_count"] == 3
    assert references["historical_audited_application_pin"] == APPLICATION_COMMIT
    assert references["finding_register_mutated_by_run_156"] is False
    assert references["finding_or_priority_promotion_authorized"] is False
    assert {row["id"]: row["canonical_record_sha256"] for row in references["records"]} == MED_RECORD_HASHES
    for finding_id, expected_hash in MED_RECORD_HASHES.items():
        record = records[finding_id]
        assert canonical_sha256(record) == expected_hash
        assert record["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
        assert record["priority_status"] == "PROVISIONAL_NOT_FINAL_PRIORITY_COUNT"
        assert record["frontend_anchor"]["application_commit"] == APPLICATION_COMMIT
        assert record["backend_anchor"]["application_commit"] == APPLICATION_COMMIT
        assert record["completion_credit"] is False
        assert all(value is False for value in record["credit"].values())
        assert record["benchmark"]["status"] == "NOT_MAPPED_AND_NO_FINAL_NO_MATCH_CURRENT_AUDIT"
        assert record["benchmark_outcome"] == "NOT_ADJUDICATED_CURRENT_AUDIT"
        assert record["independent_review"]["status"] == "NOT_COMPLETED"
    return counts


def validate_reporting_surfaces() -> None:
    common_tokens = (
        "RUN-156",
        "RUN-157",
        "RUN-158",
        APPLICATION_COMMIT,
        HISTORICAL_MERGE,
        EFFECTIVE_APPLICATION,
        "resources/js/pages/my-day/index.tsx",
        "358",
        "unfetched local remote-tracking observation only",
        "2/340",
        "0/340",
        "338",
        "12 provisional",
    )
    for relative in SURFACES[:3]:
        text = (AUDIT / relative).read_text(encoding="utf-8")
        for token in common_tokens:
            assert token in text, f"{relative}: missing {token!r}"
    assert "one operating organisation across multiple Sites" in (
        AUDIT / SURFACES[0]
    ).read_text(encoding="utf-8")
    module_map = (AUDIT / SURFACES[1]).read_text(encoding="utf-8")
    assert "single-tenant, multi-Site architecture" in module_map
    assert "approved Site scope" in module_map
    assert "one operating organisation across multiple Sites" in (
        AUDIT / SURFACES[2]
    ).read_text(encoding="utf-8")

    builder = (AUDIT / SURFACES[3]).read_text(encoding="utf-8")
    required_builder_tokens = (
        '<a href="#checkpoint">RUN-157</a>',
        "RUN-155–157 medication-governance source receipt",
        "$medication_record_items",
        "$run156r_commit",
        "Fresh RUN-158 audit-dashboard verification required",
        "evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json",
        "RUN-071–157 evidence lineage",
        "through RUN-156R and reported in RUN-157",
        ".tmp-run157-dashboard",
        RUN155_GENERATOR,
        RUN155_RECEIPT,
        RUN156_GENERATOR,
        RUN156_RECEIPT,
        RUN156R_GENERATOR,
        RUN156R_RECEIPT,
        MATERIALIZER,
        OUTPUT,
    )
    for token in required_builder_tokens:
        assert token in builder, f"builder missing {token!r}"
    assert ".tmp-run154-dashboard" not in builder
    assert "Fresh RUN-155 audit-dashboard verification required" not in builder
    assert "RUN-071–154 evidence lineage" not in builder
    ast.parse(builder)
    ast.parse((AUDIT / MATERIALIZER).read_text(encoding="utf-8"))


def local_origin_observation() -> dict[str, Any]:
    assert git("rev-parse", "refs/remotes/origin/main") == OBSERVED_LOCAL_ORIGIN_MAIN
    local_behind, local_ahead = (
        int(value)
        for value in git(
            "rev-list",
            "--left-right",
            "--count",
            "refs/remotes/origin/main...HEAD",
        ).split()
    )
    assert (local_ahead, local_behind) == (181, 0)
    return {
        "boundary": "LOCAL_REMOTE_TRACKING_OBSERVATION_ONLY",
        "scope_wording": (
            "unfetched local remote-tracking observation only; no current remote "
            "state, publication, or push is verified"
        ),
        "observed_local_remote_tracking_ref": "origin/main",
        "observed_local_remote_tracking_ref_sha": OBSERVED_LOCAL_ORIGIN_MAIN,
        "producer_observed_at_checkpoint_commit": RUN156_CHECKPOINT,
        "producer_checkpoint_local_ahead": 179,
        "producer_checkpoint_local_behind": 0,
        "reporting_observed_at_checkpoint_commit": CHECKPOINT_COMMIT,
        "reporting_checkpoint_local_ahead": local_ahead,
        "reporting_checkpoint_local_behind": local_behind,
        "fetch_performed": False,
        "remote_currency_verified": False,
        "publication_or_push_verified": False,
    }


def build_receipt(
    outputs: dict[str, dict[str, Any]],
    run155: dict[str, Any],
    run156: dict[str, Any],
    run156r: dict[str, Any],
    findings_counts: dict[str, Any],
) -> dict[str, Any]:
    materializer_record = file_record(MATERIALIZER)
    origin = local_origin_observation()
    false_credit = (
        "git_source_integration_receipt_recredit",
        "independent_source_receipt_review_recredit",
        "application_source_mutation",
        "medication_semantic_adjudication",
        "remediation_or_defect_closure",
        "finding",
        "final_finding",
        "priority_promotion",
        "runtime",
        "database",
        "build",
        "application_browser",
        "responsive_application",
        "visual_or_workflow",
        "executed_tests",
        "test_coverage",
        "coverage_completion",
        "benchmark_mapping",
        "final_no_match_or_NCM",
        "origin_currency_correctness",
        "origin_currency_coverage",
        "remote_currency",
        "publication_or_push",
        "ease",
        "release",
        "pass",
        "feature_completion",
        "completion",
        "gate_4",
        "audit_complete",
    )
    receipt: dict[str, Any] = {
        "schema_version": (
            "run-157-reviewed-medication-governance-source-main-receipt-"
            "reporting-wave-27-v1"
        ),
        "run_id": RUN_ID,
        "generated_on": "2026-08-29",
        "status": STATUS,
        "architecture_rule": {
            "operating_organisations": 1,
            "multiple_sites": True,
            "multi_tenant": False,
            "authorization_boundary": (
                "APPROVED_SITES_ROLES_PERMISSIONS_CANONICAL_OWNERSHIP_"
                "DIRECT_OBJECT_CONCEALMENT_PRIVACY"
            ),
        },
        "scope": (
            "Reporting-only materialization of the independently reviewed RUN156 "
            "two-checkpoint local Git/source receipt; no medication outcome is selected."
        ),
        "pins": {
            "reporting_checkpoint_commit": CHECKPOINT_COMMIT,
            "reporting_checkpoint_tree": CHECKPOINT_TREE,
            "historical_audited_application_commit": APPLICATION_COMMIT,
            "historical_audited_application_tree": APPLICATION_TREE,
            "historical_merge_commit": HISTORICAL_MERGE,
            "effective_application_commit": EFFECTIVE_APPLICATION,
            "run_156_generation_checkpoint_commit": RUN156_CHECKPOINT,
            "run_156_producer_commit": RUN156_COMMIT,
            "run_156r_commit": CHECKPOINT_COMMIT,
            "observed_local_remote_tracking_ref": "origin/main",
            "observed_local_remote_tracking_ref_sha": OBSERVED_LOCAL_ORIGIN_MAIN,
            "committed_lineage": {
                relative: {"path": relative, **record}
                for relative, record in LINEAGE.items()
            },
            "materializer": {"path": MATERIALIZER, **materializer_record},
        },
        "baseline_outputs": BASELINE_OUTPUTS,
        "outputs": outputs,
        "verified_run_155_dashboard_boundary": {
            "run_id": run155["run_id"],
            "status": run155["status"],
            "dashboard_sha256": PRESERVED["audit-dashboard.html"]["sha256"],
            "viewports_verified": 4,
            "visible_boundary_checks": 38,
            "navigation_targets": "10/10",
            "unique_local_links": 379,
            "superseded_exact_artifact_only": True,
            "proof_transfers_to_run_157_dashboard_or_application": False,
        },
        "verified_run_156_two_checkpoint_receipt": {
            "run_id": run156["run_id"],
            "status": run156["status"],
            "historical_merge_payload_paths": 359,
            "historical_merge_added_paths": 87,
            "historical_merge_modified_paths": 272,
            "effective_unchanged_merge_payload_blobs": 358,
            "effective_superseded_merge_payload_blobs": 1,
            "sole_superseded_path": "resources/js/pages/my-day/index.tsx",
            "post_merge_my_day_delta_paths": 3,
            "later_audit_only_commits": 3,
            "later_audit_only_changed_paths": 12,
            "non_audit_tracked_entries": 12784,
            "all_359_payload_blobs_claimed_current": False,
            "historical_merge_tree_presented_as_effective_tree": False,
            "producer_positive_credit_keys": ["GIT_SOURCE_INTEGRATION_RECEIPT"],
        },
        "verified_run_156r_review": {
            "run_id": run156r["run_id"],
            "status": run156r["status"],
            "verdict": "GO",
            "distinct_review_lanes": 3,
            "cross_reviewer_coordination_disclosed": True,
            "blind_or_isolated_reviews": False,
            "single_record_materializer": "/root",
            "discrepancies": 0,
            "reporting_materialization_authorized": True,
            "gate_4_complete": False,
            "audit_complete": False,
            "review_positive_credit_keys": [
                "INDEPENDENT_SOURCE_RECEIPT_REVIEW_FOR_REPORTING"
            ],
        },
        "verified_medication_reference_boundary": {
            "reference_count": 3,
            "historical_audited_application_commit": APPLICATION_COMMIT,
            "canonical_record_sha256": MED_RECORD_HASHES,
            "record_status": "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING",
            "priority_status": "PROVISIONAL_NOT_FINAL_PRIORITY_COUNT",
            "reference_only": True,
            "semantic_adjudication_or_rebase": False,
            "promotion_remediation_verification_or_closure": False,
            "all_record_credit_fields_false": True,
            "independent_semantic_current_source_review_complete": False,
        },
        "reporting_assertions": {
            "source_owner_records": findings_counts["static_source_feature_ownership_records"],
            "route_owner_records": findings_counts["static_source_feature_ownership_route_records"],
            "page_owner_records": findings_counts["static_source_feature_ownership_page_records"],
            "controller_action_bridges": findings_counts["static_controller_action_bridges"],
            "bounded_static_source_residual_records": 3265,
            "queue_reviewed": 118,
            "queue_pending": 389,
            "queue_without_ownership": 411,
            "provisional_findings": 12,
            "benchmark_mapped": "2/340",
            "final_no_match_or_ncm": "0/340",
            "benchmark_unresolved": 338,
            "all_counts_unchanged_by_run_157": True,
        },
        "local_only_origin_attestation": origin,
        "reporting_boundary": {
            "tracked_reporting_surfaces_changed": list(SURFACES),
            "tracked_reporting_surface_count": 4,
            "dashboard_builder_source_changed": True,
            "dashboard_builder_executed": False,
            "dashboard_html_changed": False,
            "run_155_remains_last_exact_dashboard_verification": True,
            "fresh_run_158_dashboard_verification_required": True,
            "findings_changed": False,
            "matrix_changed": False,
            "benchmark_register_changed": False,
            "application_source_changed": False,
        },
        "noninheritance_boundary": {
            "historical_merge_completion_subject_inherited_as_outcome": False,
            "all_359_merge_payload_blobs_claimed_current": False,
            "my_day_fix_recredited_as_medication_remediation": False,
            "provisional_medication_records_promoted_or_rebased": False,
            "run_156_receipt_credit_recredited": False,
            "run_156r_review_credit_recredited": False,
            "run_155_browser_proof_transferred": False,
            "local_origin_observation_inherited_as_remote_currency": False,
        },
        "mutation_attestation": {
            "run_157_change_set_is_exactly_six_audit_paths": True,
            "materializer_runtime_writes_only_receipt": True,
            "pre_materialized_surface_edits_rewritten_by_materializer": False,
            "application_source_changed": False,
            "findings_changed": False,
            "matrix_or_benchmark_register_changed": False,
            "dashboard_html_changed": False,
            "browser_server_php_database_or_external_system_started": False,
            "tests_or_coverage_executed": False,
        },
        "credit_boundary": {
            "REPORTING_REFRESH_FOR_REVIEWED_MEDICATION_SOURCE_RECEIPT": True,
            **{key: False for key in false_credit},
        },
        "completion_boundary": {
            key: False
            for key in (
                "semantic_assurance_complete",
                "execution_complete",
                "coverage_complete",
                "benchmark_complete",
                "pass_8_complete",
                "final_reconciliation_complete",
                "no_live_agent_gate_complete",
                "gate_4_complete",
                "audit_complete",
            )
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            f"{PREFIX}/{relative}" for relative in (*SURFACES, MATERIALIZER, OUTPUT)
        ],
    }
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_receipt(expected: dict[str, Any]) -> None:
    raw = assert_lf_file(AUDIT / OUTPUT)
    actual = strict_json_bytes(raw, OUTPUT)
    assert actual == expected
    seal = actual.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(actual)
    actual["receipt_self_seal_sha256"] = seal
    assert actual["pins"]["materializer"]["sha256"] == sha256_bytes(
        (AUDIT / MATERIALIZER).read_bytes()
    )
    assert actual["pins"]["materializer"]["blob_id"] == git(
        "hash-object", "--", str(AUDIT / MATERIALIZER)
    )
    assert [key for key, value in actual["credit_boundary"].items() if value] == [
        "REPORTING_REFRESH_FOR_REVIEWED_MEDICATION_SOURCE_RECEIPT"
    ]
    assert all(value is False for value in actual["completion_boundary"].values())
    assert status_lines() == expected_status(include_receipt=True)
    assert not list(AUDIT.rglob("__pycache__"))


def main() -> None:
    validate_prewrite_status()
    outputs = validate_surface_pins()
    validate_reporting_surfaces()
    run155, run156, run156r = validate_lineage()
    findings_counts = validate_findings(run156)
    receipt = build_receipt(outputs, run155, run156, run156r, findings_counts)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert encoded.endswith(b"\n") and b"\r\n" not in encoded
    assert not encoded.startswith(b"\xef\xbb\xbf")
    assert all(line.rstrip(b" \t") == line for line in encoded.splitlines())
    (AUDIT / OUTPUT).write_bytes(encoded)
    assert (AUDIT / OUTPUT).read_bytes() == encoded
    validate_receipt(receipt)
    print(
        json.dumps(
            {
                "status": STATUS,
                "schema_version": receipt["schema_version"],
                "materializer_sha256": receipt["pins"]["materializer"]["sha256"],
                "receipt_sha256": sha256_bytes(encoded),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "tracked_reporting_surfaces": 4,
                "status_paths": 6,
                "positive_credit_keys": [
                    "REPORTING_REFRESH_FOR_REVIEWED_MEDICATION_SOURCE_RECEIPT"
                ],
                "fresh_run_158_required": True,
                "gate_4_complete": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
