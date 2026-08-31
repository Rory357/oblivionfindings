from __future__ import annotations

import ast
import hashlib
import json
import math
import subprocess
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_ROOT = AUDIT_DIR.parents[2]
AUDIT_PREFIX = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/"

SCHEMA_VERSION = "run-191-reviewed-fleet-trip-playback-route-action-reporting-wave-37-v1"
RUN_ID = "RUN-191-REVIEWED-FLEET-TRIP-PLAYBACK-ROUTE-ACTION-REPORTING-WAVE-37"
STATUS = (
    "FLEET_TRIP_PLAYBACK_REVIEWED_OWNERSHIP_REPORTING_MATERIALIZED_"
    "DASHBOARD_RUN192_REQUIRED_ZERO_NEW_OWNERSHIP_CORRECTNESS_RUNTIME_"
    "PUBLICATION_FINDING_OR_COMPLETION_CREDIT"
)
HEAD = "b35d267efd067ac8fab8c4ac8111dad993c65444"
HEAD_TREE = "a3a756d977cae205e8f504eb5e918e0252d3cc58"
HEAD_PARENT = "8b4e5acbbc75db6ea2b966b0cd8d82beff2b4213"
HEAD_SUBJECT = "audit: seal RUN190R playback ownership review"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
CONTINUATION_REQUEST_SHA256 = "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"

SCRIPT_REL = "generators/materialize-run-191-reviewed-fleet-trip-playback-route-action-reporting-wave-37.py"
OUTPUT_REL = "evidence/source/current-run-191-reviewed-fleet-trip-playback-route-action-reporting-wave-37.json"
FINDINGS_REL = "findings.json"
BUILDER_REL = "generators/build-current-audit-dashboard.py"
DASHBOARD_REL = "audit-dashboard.html"
MATRIX_REL = "03-feature-to-benchmark-matrix.csv"

RUN_187_GENERATOR_REL = "generators/materialize-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.py"
RUN_187_REL = "evidence/source/current-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.json"
RUN_188_GENERATOR_REL = "generators/materialize-run-188-audit-dashboard-verification-wave-36.py"
RUN_188_REL = "evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json"
RUN_190_GENERATOR_REL = "generators/integrate-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.py"
RUN_190_REL = "evidence/source/current-run-190-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-wave-37.json"
RUN_190R_GENERATOR_REL = "generators/materialize-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.py"
RUN_190R_REL = "evidence/source/current-run-190r-independent-reviewed-outcome-neutral-fleet-trip-playback-route-action-ownership-overlay-review-wave-37.json"

RUN_192_GENERATOR_REL = "generators/materialize-run-192-audit-dashboard-verification-wave-37.py"
RUN_192_REL = "evidence/browser/current-audit-dashboard-verification-run-192-wave-37.json"

HUMAN_SURFACES = [
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
]
REPORTING_SURFACES = [*HUMAN_SURFACES, FINDINGS_REL, BUILDER_REL]
EXACT_DIRTY_ALLOWLIST = set([*REPORTING_SURFACES, SCRIPT_REL, OUTPUT_REL])

PRESERVED_SURFACES = [
    "02-eight-pass-coverage-ledger.csv",
    MATRIX_REL,
    "04-workflow-usability-scorecard.csv",
    "05-browser-visual-coverage-matrix.csv",
    "06-open-source-benchmark-register.csv",
    "08-cross-module-journeys.md",
    "09-ui-ux-accessibility-visual-consistency.md",
    "10-architecture-data-integration-security.md",
    DASHBOARD_REL,
    "inventory.json",
]

BASELINE_RECORDS = {
    "00-executive-summary.md": {
        "sha256": "e01925e0739d94dd8c276160b1f9587a8790b551d056372371e000e93788bf22",
        "git_blob_id": "07600c307272e0d745b20a1c47de59ddb594bcbc",
        "bytes": 150668,
        "lines": 598,
    },
    "01-repository-module-map.md": {
        "sha256": "aade98ec13ff442f188d1121c7d58d1928424961e26ceadae50d003adc11d53f",
        "git_blob_id": "0bf84f2ca66333a9cb5c128b4bfa310a5f452b38",
        "bytes": 48814,
        "lines": 262,
    },
    "07-module-findings.md": {
        "sha256": "2faa57cc3223844f81adfee9bc6eb6f1fefa550a1c5d95138f9839f5672cb77c",
        "git_blob_id": "73648afa9bad514958a8da5cf28497e3c49bf66b",
        "bytes": 362136,
        "lines": 835,
    },
    "11-prioritised-roadmap.md": {
        "sha256": "623e2fd37ebc521abb66f62e01549130b87b5fc3a2002a59355967cf8b20542f",
        "git_blob_id": "1314db6588564da31f3ba7667f75aec75ca44d7a",
        "bytes": 17304,
        "lines": 76,
    },
    "12-native-build-and-do-not-copy-register.md": {
        "sha256": "24bb7a08bb6e87aaf015018b601f4699784745bbab32138e7e56fcdda074132b",
        "git_blob_id": "0f6eb9cbedd6cb0c406ce782e3960907494c3abf",
        "bytes": 118616,
        "lines": 509,
    },
    "13-unresolved-questions-and-evidence-gaps.md": {
        "sha256": "125182436c75293abc67e4ca59f94bcb4b78961fd87d0496da518000691cfe2d",
        "git_blob_id": "ee1df149c2b8da55f4357920afae0fed31e553f7",
        "bytes": 42765,
        "lines": 111,
    },
    FINDINGS_REL: {
        "sha256": "9c4aae028a358f0d1cb005fa31650ab7c696fb71731fb6961ccc4962f2cac5c9",
        "git_blob_id": "22c2766988b684c8a3c3f6cd8b817ce37741f4b2",
        "bytes": 630225,
        "lines": 11115,
    },
    BUILDER_REL: {
        "sha256": "fd0c4c13d4299934f2347f434b47f349cbc16c45ac39802724b4a11a0eee50c0",
        "git_blob_id": "61797d438347a97ec8c6c559aa4e17a8f2100133",
        "bytes": 717366,
        "lines": 6242,
    },
}

CURRENT_RECORDS = {
    "00-executive-summary.md": {
        "sha256": "4e9533a2e927b738e92a4a108850c3f13b8c7d8b192f36bd980ce08ddcb9610e",
        "git_blob_id": "d71a83f628bc7179a246cf9032033620eec3a0af",
        "bytes": 153614,
        "lines": 608,
    },
    "01-repository-module-map.md": {
        "sha256": "d5c0f04984a42bb8b55afde6ed25d6da685214bb0f03a404ccf1d6349d6c3ca2",
        "git_blob_id": "65b2e74b4536b54b5ee4d660ea6a00de637d9284",
        "bytes": 49235,
        "lines": 262,
    },
    "07-module-findings.md": {
        "sha256": "84132b2b7fbc519ae33b704e6c9ddb0bb9f01a356e01f71fdea083e4e2d41bba",
        "git_blob_id": "fb9875b9d1cfa85bbe69508214995a685cd1df1f",
        "bytes": 363022,
        "lines": 835,
    },
    "11-prioritised-roadmap.md": {
        "sha256": "33c3eee2e06196b487b88e8f44023b440d57e48e676cb4b4c073a6ebcc3ac822",
        "git_blob_id": "9a8ea8dc2b6bca754d3d54f8b4cef1b55886ee2c",
        "bytes": 17611,
        "lines": 76,
    },
    "12-native-build-and-do-not-copy-register.md": {
        "sha256": "3bddd5657c4d5f83ad37ef92b50ee6aef633758fb093f5b83a85a60206825616",
        "git_blob_id": "bc1a3c20cad4f273b6b237840509a53bc3150fec",
        "bytes": 118850,
        "lines": 509,
    },
    "13-unresolved-questions-and-evidence-gaps.md": {
        "sha256": "da58fdcdd7a792c1e5873b95ebffc0ab3a22104e7bd0ff12d1463316fa1ca86a",
        "git_blob_id": "b090ee721e774ee67e0f8d8776544fe0ea0c6d72",
        "bytes": 44396,
        "lines": 115,
    },
    FINDINGS_REL: {
        "sha256": "91ccad95997c802f56c68a3cfc2678ae2364e7bad47c3f11ecaa55f4fc3e4843",
        "git_blob_id": "4b407f1137b121f6d5c0ad123bbd7a8fdb4223ce",
        "bytes": 643616,
        "lines": 11357,
    },
    BUILDER_REL: {
        "sha256": "3fa7cb8be9a12d6e7c53999cb05a04187083ee1e44bf3646690607b10d4dd4aa",
        "git_blob_id": "e52609fcc80802413dd1926fe9b315a5875688b6",
        "bytes": 732053,
        "lines": 6417,
    },
}

LINEAGE_FILES = {
    RUN_187_GENERATOR_REL: "342e5cddf6e8e4150a20e43e7efbfa56abc8754af97055e1a66eb59582dcde65",
    RUN_187_REL: "e84d36fee04b9d39cea9da1d247d92394abf12df4452ffc5d672b9d5cd375412",
    RUN_188_GENERATOR_REL: "863328dbaeff2f039ba19f5f33d4109468a6b15ababfaeadcf4fd016f91e77a9",
    RUN_188_REL: "80e54a76673af5aa8fc00e0738c7e7ee219f17d6bb22d2646e37c1cbd2081a56",
    RUN_190_GENERATOR_REL: "0115154b4472f96977f0d82c286943af7b687240cd23f997d2d5e0a590e18599",
    RUN_190_REL: "88494bb887c78f488df3915c86a8ad47b2176da469aedda3803151b8edd4a708",
    RUN_190R_GENERATOR_REL: "ec87f7eb11ab139278e7247880ae8a4adb8546cc55ce8ed76d2c2ea79603f132",
    RUN_190R_REL: "36376b3e40a2611cf814c2b034ecf0157f5fdae480d7e893a8aa6992286b3b3b",
}

SELF_SEALS = {
    RUN_187_REL: ("receipt_self_seal_sha256", "ed9fe03582bc147a5524bb2810051e0721cfaa65257893d3f18066b7afa39c97"),
    RUN_188_REL: ("receipt_self_seal_sha256", "a3feac39603045a78926b38393c9109afdd13b53b6a6338c1d53ef84f7bdc243"),
    RUN_190_REL: ("self_seal", "16cbb874448ec053f976594cfe031ed1834601d66c8b1ffe7bb79a06336d4142"),
    RUN_190R_REL: ("self_seal", "1a9767955433c71d958c21557e3084ecb5b66a3b7e190324d4fd387f5267e503"),
}

FROZEN_DASHBOARD = {
    "path": DASHBOARD_REL,
    "sha256": "3d65bd82b8bc0f650158c4587f9618a03079f75d51e83496dc7d71addf257d79",
    "git_blob_id": "4c6dc53cc4070e626ff0489f4c80e4177709d4ae",
    "bytes": 314007,
    "lines": 78,
}
BASELINE_RECORD_LIST_SHA256 = "c323094ec2ec143ed1a037f6b3c96f4e796cf2dd7bf9bf407d6a049e2c98ec33"
MATRIX_SHA256 = "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0"
MATRIX_BLOB = "1f5fdab3ae80ae4ec1b9bc4ee47eef695bdd5416"


def git(*args: str, text: bool = True) -> str | bytes:
    completed = subprocess.run(
        ["git", *args],
        cwd=REPO_ROOT,
        check=True,
        capture_output=True,
        text=text,
    )
    return completed.stdout


def duplicate_key_guard(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise AssertionError(f"Duplicate JSON key: {key}")
        result[key] = value
    return result


def reject_constant(value: str) -> None:
    raise AssertionError(f"Non-finite JSON number: {value}")


def parse_json_strict(payload: bytes, label: str) -> dict[str, Any]:
    assert not payload.startswith(b"\xef\xbb\xbf"), f"UTF-8 BOM not allowed: {label}"
    assert b"\r" not in payload, f"CR byte not allowed: {label}"
    assert payload.endswith(b"\n"), f"Final LF required: {label}"
    parsed = json.loads(
        payload.decode("utf-8"),
        object_pairs_hook=duplicate_key_guard,
        parse_constant=reject_constant,
    )
    assert isinstance(parsed, dict), f"Top-level JSON object required: {label}"
    return parsed


def read_json_strict(relative: str) -> dict[str, Any]:
    return parse_json_strict((AUDIT_DIR / relative).read_bytes(), relative)


def read_text_strict(relative: str) -> str:
    payload = (AUDIT_DIR / relative).read_bytes()
    assert not payload.startswith(b"\xef\xbb\xbf"), f"UTF-8 BOM not allowed: {relative}"
    assert b"\r" not in payload, f"CR byte not allowed: {relative}"
    assert payload.endswith(b"\n"), f"Final LF required: {relative}"
    for number, line in enumerate(payload.splitlines(), start=1):
        assert line.rstrip(b" \t") == line, f"Trailing whitespace: {relative}:{number}"
    return payload.decode("utf-8")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def canonical_sha256(value: Any) -> str:
    payload = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return sha256_bytes(payload)


def git_blob_id(payload: bytes) -> str:
    header = f"blob {len(payload)}\0".encode("ascii")
    return hashlib.sha1(header + payload).hexdigest()


def file_record(relative: str, payload: bytes | None = None) -> dict[str, Any]:
    observed = payload if payload is not None else (AUDIT_DIR / relative).read_bytes()
    return {
        "path": relative,
        "sha256": sha256_bytes(observed),
        "git_blob_id": git_blob_id(observed),
        "bytes": len(observed),
        "lines": observed.count(b"\n"),
    }


def record_without_path(record: dict[str, Any]) -> dict[str, Any]:
    return {key: value for key, value in record.items() if key != "path"}


def git_file_at_head(relative: str) -> bytes:
    return git("show", f"{HEAD}:{AUDIT_PREFIX}{relative}", text=False)  # type: ignore[return-value]


def status_paths() -> tuple[set[str], list[str]]:
    lines = str(git("status", "--porcelain=v1", "--untracked-files=all")).splitlines()
    paths: set[str] = set()
    statuses: list[str] = []
    for line in lines:
        assert len(line) >= 4, line
        status = line[:2]
        raw_path = line[3:]
        if " -> " in raw_path:
            raw_path = raw_path.split(" -> ", 1)[1]
        raw_path = raw_path.strip('"').replace("\\", "/")
        assert raw_path.startswith(AUDIT_PREFIX), f"Unexpected dirty path outside audit: {raw_path}"
        relative = raw_path[len(AUDIT_PREFIX):]
        paths.add(relative)
        statuses.append(f"{status} {relative}")
    return paths, sorted(statuses)


def assert_self_seal(receipt: dict[str, Any], field: str, expected: str) -> None:
    payload = dict(receipt)
    observed = payload.pop(field)
    observed_sha256 = observed["sha256"] if isinstance(observed, dict) else observed
    assert observed_sha256 == expected
    assert canonical_sha256(payload) == expected


def completion_gates() -> dict[str, bool]:
    return {str(index): False for index in range(1, 27)}


def assert_finite(value: Any) -> None:
    if isinstance(value, float):
        assert math.isfinite(value)
    elif isinstance(value, dict):
        for child in value.values():
            assert_finite(child)
    elif isinstance(value, list):
        for child in value:
            assert_finite(child)


def atomic_write(relative: str, payload: bytes) -> None:
    target = AUDIT_DIR / relative
    temporary = target.with_name(".tmp-run191-" + target.name)
    assert not temporary.exists(), temporary
    temporary.parent.mkdir(parents=True, exist_ok=True)
    temporary.write_bytes(payload)
    temporary.replace(target)


def main() -> None:
    head_lines = str(git("show", "-s", "--format=%H%n%T%n%P%n%s", "HEAD")).splitlines()
    assert head_lines == [HEAD, HEAD_TREE, HEAD_PARENT, HEAD_SUBJECT]
    assert str(git("branch", "--show-current")).strip() == "main"
    assert str(git("rev-parse", "origin/main")).strip() == ORIGIN_MAIN
    assert str(git("rev-list", "--left-right", "--count", "origin/main...main")).strip() == "0\t52"
    assert str(git("diff", "--cached", "--name-only")).strip() == ""
    assert str(git("diff", "--check")).strip() == ""

    dirty_before, status_before = status_paths()
    expected_before = EXACT_DIRTY_ALLOWLIST if (AUDIT_DIR / OUTPUT_REL).exists() else EXACT_DIRTY_ALLOWLIST - {OUTPUT_REL}
    assert dirty_before == expected_before, (sorted(dirty_before), sorted(expected_before))
    assert all(status.startswith((" M ", "?? ")) for status in status_before)

    for relative in [*REPORTING_SURFACES, SCRIPT_REL]:
        read_text_strict(relative)

    for relative, expected in BASELINE_RECORDS.items():
        assert record_without_path(file_record(relative, git_file_at_head(relative))) == expected
    for relative, expected in CURRENT_RECORDS.items():
        assert record_without_path(file_record(relative)) == expected

    for relative in PRESERVED_SURFACES:
        assert (AUDIT_DIR / relative).read_bytes() == git_file_at_head(relative), relative
    assert file_record(DASHBOARD_REL) == FROZEN_DASHBOARD
    matrix = file_record(MATRIX_REL)
    assert matrix["sha256"] == MATRIX_SHA256
    assert matrix["git_blob_id"] == MATRIX_BLOB

    for relative, expected in LINEAGE_FILES.items():
        assert sha256_file(relative) == expected, relative
    lineage_receipts: dict[str, dict[str, Any]] = {}
    for relative, (field, expected) in SELF_SEALS.items():
        lineage_receipts[relative] = read_json_strict(relative)
        assert_self_seal(lineage_receipts[relative], field, expected)

    run_188 = lineage_receipts[RUN_188_REL]
    verification = run_188["verification"]
    assert verification["viewports_required"] == verification["viewports_verified"] == 4
    assert verification["visible_static_checks_required"] == verification["visible_static_checks_passed"] == 152
    assert verification["navigation_clicks_required"] == verification["navigation_clicks_passed"] == 10
    assert verification["post_materialization_filesystem_resources"] == "471/471"
    assert verification["post_materialization_http_head_resources"] == "471/471"
    assert verification["anchor_elements"] == verification["anchor_elements_rendered_in_browser"] == 888
    assert run_188["credit_boundary"]["exact_audit_dashboard_artifact"] is True
    assert run_188["credit_boundary"]["application_browser"] is False
    assert run_188["audit_completion_test_met"] is False

    run_190 = lineage_receipts[RUN_190_REL]
    overlay = run_190["overlay_source_records"]
    bridges = run_190["new_static_controller_action_bridges"]
    assert len(overlay) == len(bridges) == 1
    assert overlay[0]["overlay_row_sha256"] == "31a2f128dacd47d73377db8422e2d89448909d9f4d98fe8089fa0522cb0ddfb2"
    assert bridges[0]["bridge_row_sha256"] == "a8934922a42c1270c62276c2dc345066372a8ea73fa3ca0875cd3c75020fc5c9"
    assert run_190["pins"]["semantic_consensus_record_sha256"] == "d9f89cb1c19d9894150d1df63fdd85482cdcf0a66fb2fe37297f2757aa810227"
    assert run_190["pins"]["run190_computed_decision_sha256"] == "b3456ba2d566a616dbd314c50c4b9a4f8ed019193ecb60076e668cc046d27a7f"
    assert run_190["combined_counts"]["source_owner_records"] == 667
    assert run_190["combined_counts"]["route_owner_records"] == 310
    assert run_190["combined_counts"]["page_owner_records"] == 357
    assert run_190["combined_counts"]["static_controller_action_bridges"] == 98
    assert run_190["queue_accounting"]["reviewed_queue_surface_rows"] == 121
    assert run_190["queue_accounting"]["pending_unreviewed_queue_surface_rows"] == 386
    assert run_190["queue_boundary"]["next_unresolved_index"] == 86
    assert run_190["queue_boundary"]["next_unresolved_queue_record_sha256"] == (
        "ed12617b478e0a22014fb6c81402e5cf79aa574720e8ef8e2ce93f198a099893"
    )
    assert run_190["identity"]["combined_source_record_key_list_sha256"] == (
        "2e5f82279c71860a6fc2576859fb4351a6e3fbd3010f7c9f2fe598b48facf5a6"
    )
    assert run_190["identity"]["combined_bridge_key_list_sha256"] == (
        "354fd9239de4233eff3e1b20b7df5c2c519e11c8a90b88490cab9513e9f1b42c"
    )
    assert run_190["identity"]["new_overlay_source_records_sha256"] == (
        "20be0f18f623a94231f2a88b2eb62d3e9f4bd785bf33d0822434267997936b38"
    )
    assert run_190["identity"]["new_action_bridges_sha256"] == (
        "5608dd3ed0b33de029adcff9b7e05031b281c8afe956c94feff83d1d1726e605"
    )
    assert run_190["credit_boundary"]["STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD"] is True
    assert run_190["credit_boundary"]["STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION"] is True
    assert all(
        value is False
        for key, value in run_190["credit_boundary"].items()
        if key not in {"STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION"}
    )

    run_190r = lineage_receipts[RUN_190R_REL]
    assert run_190r["pins"]["overlay_row_sha256"] == overlay[0]["overlay_row_sha256"]
    assert run_190r["pins"]["bridge_row_sha256"] == bridges[0]["bridge_row_sha256"]
    assert run_190r["decision"]["independent_reviews"] == 2
    assert run_190r["decision"]["reporting_materialization_authorized"] is True
    assert run_190r["reporting_materialization_authorized"] is True
    assert run_190r["decision"]["decision_record_sha256"] == (
        "dc6490c07f8228036689d9c3210487426d7d0cd3a7e75540a6c377eace39621b"
    )
    assert run_190r["decision"]["accepted_review_record_sha256s"] == [
        "fffe65c879b5e59cdc81d97c9ffd51bb967a4fa958eebbc2b71f1b10efb848c9",
        "2557d71096bc9737d40dda88e52e780e9671abae4342b9c96e9ac30e3f377990",
    ]
    assert run_190r["decision"]["synthesis_record_sha256"] == (
        "f0b19d23eb3e2fc5ddc0aea6d441f558f17731e0b47b4dd0db8cf0bb047851a9"
    )

    baseline_findings = parse_json_strict(git_file_at_head(FINDINGS_REL), f"{HEAD}:{FINDINGS_REL}")
    findings = read_json_strict(FINDINGS_REL)
    assert len(baseline_findings["records"]) == len(findings["records"]) == 15
    assert canonical_sha256(baseline_findings["records"]) == BASELINE_RECORD_LIST_SHA256
    assert findings["records"] == baseline_findings["records"]
    record_ids = [record["id"] for record in findings["records"]]
    assert len(record_ids) == len(set(record_ids)) == 15
    assert findings["audit_status"] == (
        "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_FIVE_HISTORICAL_REMEDIATED_"
        "ZERO_FINAL_FINDING_CREDIT"
    )
    assert findings["generated_on"] == "2026-08-31"

    counts = findings["counts"]
    expected_counts = {
        "retained_claim_records": 15,
        "provisional_source_claims": 8,
        "historical_already_fixed": 2,
        "historical_remediated": 5,
        "bounded_disposition_tests_passed": 155,
        "bounded_disposition_assertions": 2403,
        "static_source_feature_ownership_records": 667,
        "static_source_feature_ownership_route_records": 310,
        "static_source_feature_ownership_page_records": 357,
        "static_controller_action_bridges": 98,
        "bounded_static_source_residual_records": 3262,
        "bounded_static_source_residual_route_records": 2891,
        "direct_exact_queue_records": 507,
        "direct_exact_queue_reviewed": 121,
        "direct_exact_queue_owned": 99,
        "direct_exact_queue_pending_unreviewed": 386,
        "direct_exact_queue_without_ownership": 408,
        "benchmark_mapped": 2,
        "final_no_match": 0,
        "benchmark_unresolved": 338,
        "final_P0": 0,
        "final_P1": 0,
    }
    for key, value in expected_counts.items():
        assert counts[key] == value, key
    assert counts["bounded_static_source_ownership_percent"] == "16.976330"
    assert counts["static_source_feature_ownership_distinct_feature_ids"] == 256
    assert counts["static_source_feature_ownership_route_distinct_feature_ids"] == 64
    assert counts["static_source_feature_ownership_page_distinct_feature_ids"] == 242
    assert counts["static_source_feature_ownership_route_page_feature_overlap"] == 50

    queue = findings["current_direct_exact_route_page_review_queue"]
    assert queue["records"] == 507
    assert queue["reviewed_queue_surfaces"] == 121
    assert queue["owned_queue_surfaces"] == 99
    assert queue["pending_unreviewed"] == 386
    assert queue["without_ownership"] == 408
    assert queue["next_unresolved_index"] == 86
    assert queue["next_unresolved_queue_id"] == "RUN090-ROUTE-0087"
    assert queue["next_unresolved_route_record_id"] == "RUN077-ROUTE-0695"
    assert queue["next_unresolved_route_name"] == "fleet-assets.trips.playback.data"
    assert queue["next_unresolved_action_expression"] == "[FleetTripController::class, 'playback']"

    current_ownership = findings["current_static_source_feature_ownership"]
    assert current_ownership["run_id"] == run_190["run_id"]
    assert current_ownership["reviewed_overlay"]["queue_index_zero_based"] == 85
    assert current_ownership["reviewed_overlay"]["route_name"] == "fleet-assets.trips.playback"
    assert current_ownership["reviewed_overlay"]["controller_action"] == "FleetTripController::show"
    assert current_ownership["reviewed_overlay"]["accepted_route_owner_records"] == 1
    assert current_ownership["reviewed_overlay"]["accepted_page_owner_records"] == 0
    assert current_ownership["reviewed_overlay"]["accepted_controller_action_bridges"] == 1
    assert current_ownership["queue_boundary"]["next_unresolved_index"] == 86
    assert current_ownership["identity"]["combined_source_record_key_list_sha256"] == (
        "2e5f82279c71860a6fc2576859fb4351a6e3fbd3010f7c9f2fe598b48facf5a6"
    )
    assert current_ownership["identity"]["combined_bridge_key_list_sha256"] == (
        "354fd9239de4233eff3e1b20b7df5c2c519e11c8a90b88490cab9513e9f1b42c"
    )

    current_review = findings["current_outcome_neutral_fleet_trip_playback_route_action_ownership_review"]
    assert current_review["run_id"] == run_190r["run_id"]
    assert current_review["decision"]["independent_reviews"] == 2
    assert current_review["decision"]["complete_synthesis_reporting_materialization_authorized"] is True
    assert current_review["decision"]["new_source_ownership_credit"] is False
    assert current_review["verified_queue_boundary"]["next_unresolved_index"] == 86

    history = findings["current_audit_artifact_verification_history"]["run_188"]
    assert history["viewports"] == "4/4"
    assert history["visible_boundary_checks"] == "152/152"
    assert history["navigation"] == "10/10"
    assert history["unique_local_resources"] == "471/471"
    assert history["anchor_elements"] == "888/888"
    assert history["dashboard_sha256"] == FROZEN_DASHBOARD["sha256"]
    assert history["run_192_dashboard_verification_required"] is True

    required_human_phrases = {
        "00-executive-summary.md": [
            "667 records = 310 routes + 357 pages",
            "fleet-assets.trips.playback.data",
            "RUN-191 changes reporting sources only",
            "Fresh RUN-192 dashboard generation",
        ],
        "01-repository-module-map.md": [
            "667 source owners (310 route + 357 page)",
            "index 86",
            "fresh RUN-192 generation",
        ],
        "07-module-findings.md": [
            "RUN-190 alone integrates index 85",
            "RUN-190R authorizes RUN-191 reporting only",
            "fleet-assets.trips.playback.data",
            "fresh RUN-192 verification",
        ],
        "11-prioritised-roadmap.md": [
            "RUN-191 reports current static",
            "fresh RUN-192 generation",
        ],
        "12-native-build-and-do-not-copy-register.md": [
            "RUN-190 alone integrates index 85",
            "RUN-190R authorizes RUN-191 reporting only",
            "sibling playback.data at index 86 remains pending",
            "fresh RUN-192 generation and verification",
        ],
        "13-unresolved-questions-and-evidence-gaps.md": [
            "RUN-190 adds only index 85",
            "RUN-191 reporting checkpoint",
            "RUN-192 is the required fresh rebuild",
        ],
    }
    for relative, phrases in required_human_phrases.items():
        text = read_text_strict(relative)
        for phrase in phrases:
            assert phrase in text, (relative, phrase)

    builder_source = read_text_strict(BUILDER_REL)
    ast.parse(builder_source, filename=BUILDER_REL)
    compile(builder_source, BUILDER_REL, "exec")
    required_builder_phrases = [
        RUN_188_REL,
        RUN_190_REL,
        RUN_190R_REL,
        OUTPUT_REL,
        RUN_192_GENERATOR_REL,
        RUN_192_REL,
        "reviewed_fleet_daily_check_overlay = findings_register[\"current_static_source_feature_ownership\"]",
        "667 owners",
        "121 reviewed / 386 pending",
        "index 86",
    ]
    for phrase in required_builder_phrases:
        assert phrase in builder_source, phrase
    assert FROZEN_DASHBOARD["sha256"] == sha256_file(DASHBOARD_REL)

    credit_boundary = {
        "live_findings_register_and_reporting_status": True,
        "queue_advance": False,
        "new_source_ownership": False,
        "new_route_ownership": False,
        "new_page_ownership": False,
        "new_controller_action_bridge": False,
        "dashboard_artifact": False,
        "correctness": False,
        "approved_site_or_privacy": False,
        "application_runtime": False,
        "database": False,
        "build_execution": False,
        "application_browser": False,
        "executed_product_tests": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "final_finding": False,
        "release": False,
        "publication": False,
        "pass": False,
        "feature_or_module_completion": False,
        "gate_4": False,
        "audit_complete": False,
    }
    assert [key for key, value in credit_boundary.items() if value] == [
        "live_findings_register_and_reporting_status"
    ]

    baseline_records = [dict(path=relative, **record) for relative, record in BASELINE_RECORDS.items()]
    current_records = [file_record(relative) for relative in REPORTING_SURFACES]
    receipt: dict[str, Any] = {
        "schema_version": SCHEMA_VERSION,
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-31",
        "architecture_rule": (
            "One operating organisation across multiple Sites. Exact permissions, approved Sites, "
            "canonical ownership, direct-object concealment and privacy are the boundaries; no tenant design."
        ),
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": HEAD_TREE,
            "checkpoint_parent": HEAD_PARENT,
            "checkpoint_subject": HEAD_SUBJECT,
            "branch": "main",
            "origin_main_observed_without_refetch": ORIGIN_MAIN,
            "origin_main_behind": 0,
            "origin_main_ahead": 52,
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_request_sha256": CONTINUATION_REQUEST_SHA256,
            "continuation_request_is_not_governing_prompt": True,
            "generator": file_record(SCRIPT_REL),
            "lineage_file_sha256s": LINEAGE_FILES,
            "lineage_self_seals": {relative: expected for relative, (_, expected) in SELF_SEALS.items()},
            "baseline_findings_ordered_record_sha256": BASELINE_RECORD_LIST_SHA256,
            "baseline_findings_record_count": 15,
            "matrix_sha256": MATRIX_SHA256,
            "matrix_git_blob_id": MATRIX_BLOB,
            "frozen_dashboard": FROZEN_DASHBOARD,
        },
        "reporting_transition": {
            "reported_integrated_run": run_190["run_id"],
            "reported_independent_review_run": run_190r["run_id"],
            "selected_queue_index": 85,
            "selected_route_name": "fleet-assets.trips.playback",
            "selected_controller_action": "FleetTripController::show",
            "reported_existing_route_owner_records": 1,
            "reported_existing_controller_action_bridges": 1,
            "run_191_new_ownership_records": 0,
            "next_unresolved_index": 86,
            "next_unresolved_route_name": "fleet-assets.trips.playback.data",
            "next_unresolved_controller_action": "FleetTripController::playback",
        },
        "reporting_snapshot": {
            "combined_counts": {
                "source_owner_records": 667,
                "route_owner_records": 310,
                "page_owner_records": 357,
                "static_controller_action_bridges": 98,
                "bounded_source_denominator": 3929,
                "bounded_source_residual": 3262,
                "bounded_source_ownership_percent": "16.976330",
                "route_universe": 3218,
                "route_residual": 2891,
                "page_universe": 711,
                "page_residual": 345,
                "feature_union": 256,
                "route_feature_ids": 64,
                "page_feature_ids": 242,
                "route_page_feature_overlap": 50,
            },
            "queue_accounting": {
                "direct_exact_queue_records": 507,
                "reviewed_queue_surface_rows": 121,
                "pending_unreviewed_queue_surface_rows": 386,
                "owner_queue_surface_rows": 99,
                "queue_surfaces_without_ownership": 408,
            },
            "finding_and_execution_accounting": {
                "finding_records": 15,
                "provisional_findings": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 5,
                "bounded_execution_tests": 155,
                "bounded_execution_assertions": 2403,
                "final_P0": 0,
                "final_P1": 0,
            },
            "benchmark_accounting": {
                "benchmark_mapped": 2,
                "benchmark_targets": 340,
                "final_no_match_or_NCM": 0,
                "benchmark_unresolved": 338,
            },
        },
        "review_authorization": {
            "independent_review_count": 2,
            "reporting_materialization_authorized": True,
            "review_record_sha256s": run_190r["decision"]["accepted_review_record_sha256s"],
            "synthesis_record_sha256": run_190r["decision"]["synthesis_record_sha256"],
            "decision_record_sha256": run_190r["decision"]["decision_record_sha256"],
            "new_ownership_credit_authorized": False,
            "downstream_credit_authorized": False,
        },
        "identity": {
            "combined_source_record_key_list_sha256": (
                "2e5f82279c71860a6fc2576859fb4351a6e3fbd3010f7c9f2fe598b48facf5a6"
            ),
            "combined_bridge_key_list_sha256": (
                "354fd9239de4233eff3e1b20b7df5c2c519e11c8a90b88490cab9513e9f1b42c"
            ),
            "reviewed_key_list_sha256": (
                "2329f613c5310950191a5206fd764a78afc9e6f5bf0d502d0a65751a580f1393"
            ),
            "reviewed_key_list_canonical_json_sha256": (
                "5d45c0c6b47770e42d68f6d1ee44c82774346b5d8909648c85ce74b793c02c8e"
            ),
            "new_overlay_source_records_sha256": (
                "20be0f18f623a94231f2a88b2eb62d3e9f4bd785bf33d0822434267997936b38"
            ),
            "new_bridge_collection_sha256": (
                "5608dd3ed0b33de029adcff9b7e05031b281c8afe956c94feff83d1d1726e605"
            ),
            "overlay_row_sha256": overlay[0]["overlay_row_sha256"],
            "bridge_row_sha256": bridges[0]["bridge_row_sha256"],
            "next_unresolved_queue_record_sha256": (
                "ed12617b478e0a22014fb6c81402e5cf79aa574720e8ef8e2ce93f198a099893"
            ),
        },
        "outcome_conservation": {
            "source_equation": "3929 = 667 owners + 3262 residual",
            "owner_equation": "667 = 310 routes + 357 pages",
            "route_equation": "3218 = 310 owners + 12 shared + 5 alias + 0 dead + 2891 residual",
            "page_equation": "711 = 357 owners + 9 shared + 0 alias + 0 dead + 345 residual",
            "feature_equation": "256 = 64 route + 242 page - 50 overlap",
            "queue_equation": "507 = 121 reviewed + 386 pending",
            "reviewed_equation": "121 = 99 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
            "without_ownership_equation": "408 = 386 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
            "finding_equation": "15 = 8 provisional + 2 historical already fixed + 5 historical remediated",
            "all_equations_reconciled": True,
        },
        "noninheritance_boundary": {
            "sibling_playback_data_route_ownership": False,
            "page_or_frontend_ownership": False,
            "caller_redirect_service_model_helper_or_test_ownership": False,
            "remediation_or_correctness": False,
            "approved_site_permission_privacy_or_direct_object": False,
            "runtime_database_build_or_browser": False,
            "benchmark_finding_completion_or_publication": False,
        },
        "dashboard_forward_gate": {
            "required_run": "RUN-192",
            "run_188_historical_dashboard_verified": True,
            "run_188_dashboard_sha256": FROZEN_DASHBOARD["sha256"],
            "run_191_dashboard_bytes_preserved": True,
            "dashboard_html_changed_by_run_191": False,
            "run_191_builder_executed": False,
            "run_192_generator_path": RUN_192_GENERATOR_REL,
            "run_192_receipt_path": RUN_192_REL,
            "run_192_paths_intentionally_unhashed_until_materialized": True,
            "run_192_fresh_generation_and_four_viewport_verification_required": True,
        },
        "reporting_transaction": {
            "baseline_reporting_surfaces": baseline_records,
            "materialized_reporting_surfaces": current_records,
            "preserved_surfaces": [file_record(relative) for relative in PRESERVED_SURFACES],
            "dashboard_builder_source_validated_not_executed": True,
            "dashboard_byte_identical_to_head": True,
            "exact_dirty_paths_before_receipt": sorted(EXACT_DIRTY_ALLOWLIST - {OUTPUT_REL}),
            "exact_dirty_paths_after_receipt": sorted(EXACT_DIRTY_ALLOWLIST),
        },
        "credit_boundary": credit_boundary,
        "completion_gates": completion_gates(),
        "completion_boundary": {
            "residual_semantic_complete": False,
            "execution_complete": False,
            "benchmark_complete": False,
            "pass_8_complete": False,
            "run_192_dashboard_complete": False,
            "feature_or_module_complete": False,
            "gate_4_complete": False,
            "publication_complete": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": False,
        "audit_completion_test_met": False,
        "wrote_files": [OUTPUT_REL],
    }
    assert_finite(receipt)
    assert all(value is False for value in receipt["completion_gates"].values())
    assert receipt["reporting_snapshot"]["combined_counts"]["source_owner_records"] == 667
    assert receipt["reporting_snapshot"]["queue_accounting"]["reviewed_queue_surface_rows"] == 121
    assert receipt["dashboard_forward_gate"]["required_run"] == "RUN-192"
    assert receipt["dashboard_forward_gate"]["dashboard_html_changed_by_run_191"] is False
    assert all(value is False for value in receipt["completion_boundary"].values())
    observed_seal = canonical_sha256(receipt)
    receipt["receipt_self_seal_sha256"] = observed_seal
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    atomic_write(OUTPUT_REL, encoded)

    reparsed = read_json_strict(OUTPUT_REL)
    assert reparsed == receipt
    assert_self_seal(reparsed, "receipt_self_seal_sha256", observed_seal)
    assert sha256_file(DASHBOARD_REL) == FROZEN_DASHBOARD["sha256"]
    assert str(git("diff", "--cached", "--name-only")).strip() == ""
    assert str(git("diff", "--check")).strip() == ""
    dirty_after, status_after = status_paths()
    assert dirty_after == EXACT_DIRTY_ALLOWLIST
    assert all(status.startswith((" M ", "?? ")) for status in status_after)

    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "receipt_sha256": sha256_file(OUTPUT_REL),
                "receipt_self_seal_sha256": observed_seal,
                "dashboard_sha256": sha256_file(DASHBOARD_REL),
                "dirty_paths": sorted(dirty_after),
            },
            ensure_ascii=False,
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
