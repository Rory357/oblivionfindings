from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_ROOT = AUDIT_DIR.parents[2]
AUDIT_PREFIX = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/"

RUN_ID = "RUN-187-MON-METRIC-REPLAY-DEDUPE-01-REMEDIATION-REPORTING-WAVE-36"
STATUS = (
    "MONITORING_METRIC_REPLAY_DEDUPE_HISTORICAL_REMEDIATION_REPORTING_MATERIALIZED_"
    "DASHBOARD_RUN188_REQUIRED_ZERO_STATIC_DEPLOYMENT_PUBLICATION_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
HEAD = "50878f2d3008e17979e049d08d66d4b2254499fa"
HEAD_TREE = "9a9e45ea62624bdeca3a6f1d3683dd23735764de"
HEAD_PARENT = "f938c6d989f5fef052f08b9f1012116fb5cf2f69"
HEAD_SUBJECT = "audit: seal RUN186 metric replay remediation"
FROZEN_DASHBOARD_SHA256 = "3c339da7e05349a7bd5cbed9ec4986e3b4871eb04d497d26078e92268a67e5e7"
BASELINE_FINDINGS_SHA256 = "28622b14799477cfa37069bffd16f500f429ff5013ac418ed75394486cb24bc3"
BASELINE_RECORD_LIST_SHA256 = "15b85c4ddd6ab297aa74ec8042e0606ccf593dff53b0bbc672a38e9b0990e19e"

SCRIPT_REL = "generators/materialize-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.py"
OUTPUT_REL = "evidence/source/current-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.json"
FINDINGS_REL = "findings.json"
BUILDER_REL = "generators/build-current-audit-dashboard.py"
DASHBOARD_REL = "audit-dashboard.html"

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

RUN_185_REL = "evidence/browser/current-audit-dashboard-verification-run-185-wave-35.json"
RUN_186_GENERATOR_REL = "generators/materialize-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.py"
RUN_186_REL = "evidence/runtime/current-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.json"
RUN_186R_GENERATOR_REL = "generators/materialize-independent-run-186-monitoring-metric-replay-dedupe-remediation-review-wave-36.py"
RUN_186R_REL = "evidence/runtime/current-run-186r-independent-monitoring-metric-replay-dedupe-remediation-review-wave-36.json"

RUN_185_SHA256 = "e6965bba3f25b80e6ce70aa3656802956bed935d79aaf46576e1420f0c65e07c"
RUN_186_GENERATOR_SHA256 = "983b003dc149c966cdea9c59dd3cd4a766f4a5f0382e881f90b9d0cde9b86cee"
RUN_186_SHA256 = "bf2cd03ca2ab7aeb6a9d1093b3c08aba5a1bc29342cc4fda6fa57ef286c2f1e5"
RUN_186R_GENERATOR_SHA256 = "e081b3807c81f8f8d9c6982faddf63b548db650437cde13ff424730181361026"
RUN_186R_SHA256 = "035271d7bfcd4256a59f01e9953f9cd8074466c0389f74ce82325a46ee6a6af7"

OPTION_A_PREREQUISITES = [
    "quiesce old monitoring workers",
    "reconcile pending or incoherent rows",
    "apply migration 000110",
    "start new workers only after cutover reconciliation",
]

INITIAL_PATHS = [
    "app/Domain/Monitoring/Data/ObservationInput.php",
    "app/Domain/Monitoring/Models/MetricPointReceipt.php",
    "app/Domain/Monitoring/Models/MonitorObservation.php",
    "app/Domain/Monitoring/Services/MetricIngestService.php",
    "app/Domain/Monitoring/Services/MonitorCheckRunner.php",
    "app/Domain/Monitoring/Services/MonitoringObservationIngestor.php",
    "database/migrations/2026_08_30_000100_govern_monitoring_metric_projection_replays.php",
    "tests/Feature/Monitoring/MetricRetentionTest.php",
    "tests/Feature/Monitoring/RunMonitorCheckTest.php",
]
CORRECTIVE_PATHS = [
    "app/Domain/Monitoring/Models/MetricCurrentSummary.php",
    "app/Domain/Monitoring/Models/MetricPointReceipt.php",
    "app/Domain/Monitoring/Models/MetricSeries.php",
    "app/Domain/Monitoring/Services/MetricIngestService.php",
    "database/migrations/2026_08_30_000100_govern_monitoring_metric_projection_replays.php",
    "database/migrations/2026_08_30_000110_govern_monitoring_metric_projection_cutover.php",
    "tests/Feature/Monitoring/MetricRetentionTest.php",
]


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


def parse_json_strict(payload: bytes, label: str) -> dict[str, Any]:
    assert not payload.startswith(b"\xef\xbb\xbf"), f"UTF-8 BOM not allowed: {label}"
    assert b"\r" not in payload, f"CR byte not allowed: {label}"
    assert payload.endswith(b"\n"), f"Final LF required: {label}"
    parsed = json.loads(payload.decode("utf-8"), object_pairs_hook=duplicate_key_guard)
    assert isinstance(parsed, dict), f"Top-level JSON object required: {label}"
    return parsed


def read_json_strict(relative: str) -> dict[str, Any]:
    return parse_json_strict((AUDIT_DIR / relative).read_bytes(), relative)


def read_text_strict(relative: str) -> str:
    payload = (AUDIT_DIR / relative).read_bytes()
    assert not payload.startswith(b"\xef\xbb\xbf"), f"UTF-8 BOM not allowed: {relative}"
    assert b"\r" not in payload, f"CR byte not allowed: {relative}"
    assert payload.endswith(b"\n"), f"Final LF required: {relative}"
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


def file_record(relative: str) -> dict[str, Any]:
    payload = (AUDIT_DIR / relative).read_bytes()
    return {
        "path": relative,
        "sha256": sha256_bytes(payload),
        "git_blob_id": git_blob_id(payload),
        "bytes": len(payload),
        "lines": payload.count(b"\n"),
    }


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


def assert_self_seal(receipt: dict[str, Any], expected: str) -> None:
    payload = dict(receipt)
    observed = payload.pop("receipt_self_seal_sha256")
    assert observed == expected
    assert canonical_sha256(payload) == expected


def completion_gates() -> dict[str, bool]:
    return {str(index): False for index in range(1, 27)}


def main() -> None:
    head_lines = str(git("show", "-s", "--format=%H%n%T%n%P%n%s", "HEAD")).splitlines()
    assert head_lines == [HEAD, HEAD_TREE, HEAD_PARENT, HEAD_SUBJECT]
    assert str(git("diff", "--cached", "--name-only")).strip() == ""
    assert str(git("diff", "--check")).strip() == ""

    expected_head_delta = {
        "A\tdocs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/runtime/current-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.json",
        "A\tdocs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/runtime/current-run-186r-independent-monitoring-metric-replay-dedupe-remediation-review-wave-36.json",
        "A\tdocs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-independent-run-186-monitoring-metric-replay-dedupe-remediation-review-wave-36.py",
        "A\tdocs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.py",
    }
    observed_head_delta = set(str(git("diff-tree", "--no-commit-id", "--name-status", "-r", "HEAD")).splitlines())
    assert observed_head_delta == expected_head_delta

    dirty_before, status_before = status_paths()
    expected_before = EXACT_DIRTY_ALLOWLIST if (AUDIT_DIR / OUTPUT_REL).exists() else EXACT_DIRTY_ALLOWLIST - {OUTPUT_REL}
    assert dirty_before == expected_before, (sorted(dirty_before), sorted(expected_before))
    assert all(status.startswith((" M ", "?? ")) for status in status_before)

    dashboard_before = sha256_file(DASHBOARD_REL)
    assert dashboard_before == FROZEN_DASHBOARD_SHA256

    for relative in [*HUMAN_SURFACES, FINDINGS_REL, BUILDER_REL, SCRIPT_REL]:
        read_text_strict(relative)

    baseline_findings_payload = git_file_at_head(FINDINGS_REL)
    assert sha256_bytes(baseline_findings_payload) == BASELINE_FINDINGS_SHA256
    baseline_findings = parse_json_strict(baseline_findings_payload, f"{HEAD}:{FINDINGS_REL}")
    findings = read_json_strict(FINDINGS_REL)
    assert len(baseline_findings["records"]) == 14
    assert canonical_sha256(baseline_findings["records"]) == BASELINE_RECORD_LIST_SHA256
    assert findings["records"][:14] == baseline_findings["records"]
    assert [row["id"] for row in findings["records"][:14]] == [row["id"] for row in baseline_findings["records"]]
    assert len(findings["records"]) == 15
    assert findings["audit_status"] == "EIGHT_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_FIVE_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
    assert findings["generated_on"] == "2026-08-31"

    finding = findings["records"][-1]
    assert finding["id"] == "MON-METRIC-REPLAY-DEDUPE-01"
    assert finding["record_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
    assert finding["feature_id"] is None
    assert finding["candidate_feature_id"] is None
    assert finding["related_feature_ids"] == []
    assert finding["feature_identity_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
    assert finding["feature_id_role"] == "NO_CANONICAL_OR_CANDIDATE_FEATURE_ASSOCIATION_ZERO_STATIC_OWNERSHIP_CREDIT"
    assert finding["route_url"]["ownership_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
    assert finding["option_a_deployment_boundary"]["prerequisites_in_order"] == OPTION_A_PREREQUISITES
    assert finding["option_a_deployment_boundary"]["poisoned_subsecond_evidence_requires_operator_reconciliation"] is True
    assert finding["option_a_deployment_boundary"]["verified_in_production"] is False
    assert finding["option_a_deployment_boundary"]["migration_deployment_credit"] is False
    assert finding["option_a_deployment_boundary"]["release_or_publication_credit"] is False
    assert finding["completion_credit"] is False
    assert all(value is False for value in finding["credit"].values())
    assert all(value is False for key, value in finding["current_adjudication"].items() if key.endswith(("_inherited", "_authorized")))

    counts = findings["counts"]
    assert counts["retained_claim_records"] == 15
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 5
    assert counts["bounded_disposition_tests_passed"] == 155
    assert counts["bounded_disposition_assertions"] == 2403
    assert counts["monitoring_metric_replay_dedupe_focused_tests"] == 56
    assert counts["monitoring_metric_replay_dedupe_focused_assertions"] == 472
    assert counts["monitoring_metric_replay_initial_superseded_tests"] == 49
    assert counts["monitoring_metric_replay_initial_superseded_assertions"] == 392
    assert counts["monitoring_metric_replay_initial_post_merge_verdict"] == "NO_GO_ZERO_DENOMINATOR_CREDIT"
    assert counts["monitoring_metric_replay_option_a_deployment_verified"] is False
    assert counts["final_P0"] == counts["final_P1"] == 0

    assert counts["static_source_feature_ownership_records"] == 666
    assert counts["static_source_feature_ownership_route_records"] == 309
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 97
    assert 309 + 357 == 666
    assert counts["direct_exact_queue_records"] == 507
    assert counts["direct_exact_queue_reviewed"] == 120
    assert counts["direct_exact_queue_pending_unreviewed"] == 387
    assert counts["direct_exact_queue_owned"] == 98
    assert counts["direct_exact_queue_without_ownership"] == 409
    assert findings["current_static_source_feature_ownership"]["queue_boundary"]["next_unresolved_index"] == 85
    assert counts["benchmark_mapped"] == 2
    assert findings["denominators"]["canonical_features"] == 340
    assert findings["current_benchmark_mapping"]["mapped_targets"] == 2
    assert findings["current_benchmark_mapping"]["final_no_matches_or_NCMs"] == 0
    assert findings["current_benchmark_mapping"]["unresolved_targets"] == 338
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    assert findings["current_static_source_feature_ownership"]["combined_counts"]["bounded_static_source_denominator"] == 3929
    assert counts["bounded_static_source_residual_records"] == 3263
    assert counts["bounded_static_source_ownership_percent"] == "16.950878"

    reconciliation = findings["reconciliation"]
    assert reconciliation["retained_record_count"] == 15
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 5
    assert reconciliation["every_non_null_primary_feature_id_in_canonical_matrix"] is True
    assert reconciliation["records_without_primary_or_candidate_feature_id"] == ["MON-METRIC-REPLAY-DEDUPE-01"]

    assert sha256_file(RUN_185_REL) == RUN_185_SHA256
    run_185 = read_json_strict(RUN_185_REL)
    assert_self_seal(run_185, "d49f27d1ed2f7f4f53c366d711653bbbb0fc541ba2d7c08f2780d4319193e776")
    assert run_185["verification"]["viewports_verified"] == 4
    assert run_185["verification"]["visible_static_checks_passed"] == 117
    assert run_185["verification"]["navigation_clicks_passed"] == 10
    assert run_185["verification"]["unique_local_resources"] == 463
    assert run_185["verification"]["anchor_elements"] == 868

    expected_artifact_hashes = {
        RUN_186_GENERATOR_REL: RUN_186_GENERATOR_SHA256,
        RUN_186_REL: RUN_186_SHA256,
        RUN_186R_GENERATOR_REL: RUN_186R_GENERATOR_SHA256,
        RUN_186R_REL: RUN_186R_SHA256,
    }
    assert all(sha256_file(path) == digest for path, digest in expected_artifact_hashes.items())
    run_186 = read_json_strict(RUN_186_REL)
    run_186r = read_json_strict(RUN_186R_REL)
    assert_self_seal(run_186, "9d21a45a215a9d48a82d093817aba6807ef6ed73b130894ac385a41e18e527ff")
    assert_self_seal(run_186r, "0176cbf5e4756c4da3cfbcd91728db53756b7cd755a4db68dc8dade59daeff56")
    assert run_186["run_id"] == "RUN-186-MON-METRIC-REPLAY-DEDUPE-01-REMEDIATION-WAVE-36"
    assert run_186r["run_id"] == "RUN-186R-INDEPENDENT-MON-METRIC-REPLAY-DEDUPE-01-REMEDIATION-REVIEW-WAVE-36"
    assert run_186r["decision"]["verdict"] == "GO"
    assert run_186r["decision"]["blocking_discrepancies"] == 0
    assert run_186r["decision"]["authorized_live_reporting_run"] == "RUN-187"
    assert run_186r["decision"]["authorized_feature_id"] is None
    assert run_186r["decision"]["authorized_candidate_feature_id"] is None
    assert run_186r["decision"]["authorized_feature_identity_status"] == "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
    assert run_186r["decision"]["authorized_resulting_lineage"] == {
        "retained_claim_records": 15,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 5,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert run_186r["decision"]["option_a_deployment_boundary"]["prerequisites"] == OPTION_A_PREREQUISITES
    assert run_186r["decision"]["option_a_deployment_boundary"]["poisoned_subsecond_evidence_requires_operator_reconciliation"] is True
    assert run_186r["decision"]["option_a_deployment_boundary"]["verified_in_production"] is False

    pins = run_186["pins"]
    assert pins["application_baseline_commit"] == "a900f078c9c05f587f6f7884f5fe715076891416"
    assert pins["application_baseline_tree"] == "852126934a18a1364244a35f7789263779e47485"
    assert pins["initial_fix_commit"] == "f521bc0b87222e56b4822e7cb9c935486e279e76"
    assert pins["initial_fix_tree"] == "7a1862f2aab2844ca568061d3f9ee78201026cbd"
    assert pins["initial_stable_patch_id"] == "16e3886ad0985b4af853d34ede90e2b5e273af51"
    assert pins["initial_merge_commit"] == "778c00a5d09511aee1a836a689d7bb1b56ce4ff6"
    assert pins["initial_merge_tree"] == "e66c50a3b514967eec70e4774a312bff376bb66a"
    assert pins["corrective_fix_commit"] == "c82f57779baf623c4e94ac4619b11c1b675d0230"
    assert pins["corrective_fix_tree"] == "095cd7b1940988be334979af22008c635fdcaf58"
    assert pins["corrective_stable_patch_id"] == "18c4df4897f2562e5c797f7de4fb075b607de24b"
    assert pins["corrective_merge_commit"] == "18652d545c788f1dcdbe57662e5b1e5472d6cae7"
    assert pins["corrective_merge_tree"] == "095cd7b1940988be334979af22008c635fdcaf58"
    assert pins["current_local_main_commit"] == "f938c6d989f5fef052f08b9f1012116fb5cf2f69"
    assert pins["current_local_main_tree"] == "70b2339300278bc0c20e32ed091f74b442bea76d"
    assert [row["path"] for row in pins["initial_fix_records"]] == INITIAL_PATHS
    assert [row["path"] for row in pins["corrective_fix_records"]] == CORRECTIVE_PATHS
    assert len({*INITIAL_PATHS, *CORRECTIVE_PATHS}) == 12

    runtime = run_186["delegated_runtime_execution"]
    assert runtime["initial_green_runs"]["later_no_go"] is True
    assert runtime["initial_green_runs"]["denominator_credit"] is False
    assert runtime["final_corrected_post_merge_full_focused"]["tests"] == 56
    assert runtime["final_corrected_post_merge_full_focused"]["assertions"] == 472
    assert runtime["final_corrected_post_merge_full_focused"]["unique_bounded_disposition_denominator_credit"] is True
    assert runtime["final_corrected_isolated_full_focused"]["denominator_credit"] is False
    assert runtime["unique_bounded_accounting"] == {
        "prior": {"tests": 99, "assertions": 1931},
        "increment": {"tests": 56, "assertions": 472},
        "proposed_after_run_186r_go": {"tests": 155, "assertions": 2403},
    }
    assert len(run_186["completion_gates"]) == len(run_186r["completion_gates"]) == 26
    assert not any(row["complete"] for row in run_186["completion_gates"])
    assert not any(row["complete"] for row in run_186r["completion_gates"])
    assert all(value is False for value in run_186["completion_boundary"].values())
    assert all(value is False for value in run_186r["completion_boundary"].values())

    history = findings["current_audit_artifact_verification_history"]["run_185"]
    assert history["viewports"] == "4/4"
    assert history["visible_boundary_checks"] == "117/117"
    assert history["navigation"] == "10/10"
    assert history["unique_local_resources"] == "463/463"
    assert history["anchor_elements"] == "868/868"
    assert history["superseded_by_run_187_reporting_sources"] is True
    assert history["run_188_dashboard_verification_required"] is True

    required_surface_snippets = [
        "MON-METRIC-REPLAY-DEDUPE-01",
        "RUN-188",
        "quiesce old monitoring workers",
    ]
    for relative in HUMAN_SURFACES:
        text = read_text_strict(relative)
        assert all(snippet in text for snippet in required_surface_snippets), relative
        assert "155/2,403" in text or "155 tests / 2,403 assertions" in text, relative
        assert "poisoned subsecond evidence requires operator reconciliation" in text.lower(), relative
    executive_text = read_text_strict("00-executive-summary.md")
    assert "15 = 8 provisional + 2 historical already fixed + 5 historical remediated" in executive_text
    assert "RUN-185 verifies the exact RUN-184-generated artifact" in executive_text
    assert "separate fresh RUN-188 viewport/link/anchor/console verification" in executive_text
    assert "current-run-186r-independent-monitoring-metric-replay-dedupe-remediation-review-wave-36.json" in executive_text
    assert "current-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.json" in executive_text
    assert "the rebuilt HTML requires separate fresh RUN-185 viewport/link/anchor/console verification" not in executive_text
    module_findings_text = read_text_strict("07-module-findings.md")
    assert "15 retained identities = 8 current provisional P1 + 2 historical already fixed + 5 historical remediated" in module_findings_text
    assert "## Claim records — 8 current provisional + 2 historical already fixed + 5 historical remediated" in module_findings_text
    assert "14 retained identities = 8 current provisional P1 + 2 historical already-fixed + 4 historical remediated" not in module_findings_text
    assert "## Claim records — 8 current provisional + 2 historical already fixed + 4 historical remediated" not in module_findings_text
    assert "2/340 mapped, 0 final no-match/NCM, 338 unresolved" in read_text_strict("12-native-build-and-do-not-copy-register.md")
    unresolved_text = read_text_strict("13-unresolved-questions-and-evidence-gaps.md")
    assert "4/4 viewports, 117/117 visible checks, 10/10 navigation, 463/463 resources, 868 anchors" in unresolved_text
    assert "RUN-001 through RUN-187 represented at the current reporting checkpoint" in unresolved_text
    assert "verify the rebuilt dashboard through RUN-188" in unresolved_text
    assert "RUN-001 through RUN-184 represented at the current reporting checkpoint" not in unresolved_text

    builder = read_text_strict(BUILDER_REL)
    builder_required = [
        'run_187_reporting = read_json_strict("evidence/source/current-run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36.json")',
        'assert metric_finding["feature_id"] is None',
        'assert metric_finding["candidate_feature_id"] is None',
        'finding_claim_labels["MON-METRIC-REPLAY-DEDUPE-01"] = metric_finding["impact"]',
        '"MON-METRIC-REPLAY-DEDUPE-01"',
        '"UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"',
        '"Fresh RUN-188 audit-dashboard verification required"',
        'generators/materialize-run-188-audit-dashboard-verification-wave-36.py',
        'evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json',
    ]
    assert all(snippet in builder for snippet in builder_required)

    reporting_records = [file_record(path) for path in REPORTING_SURFACES]
    payload: dict[str, Any] = {
        "schema_version": "run-187-monitoring-metric-replay-dedupe-remediation-reporting-wave-36-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-31",
        "architecture_boundary": "Single operating organisation across multiple Sites; Site access, canonical ownership, exact permissions, consent and privacy remain the boundaries.",
        "pins": {
            "reporting_input_commit": HEAD,
            "reporting_input_tree": HEAD_TREE,
            "reporting_input_parent": HEAD_PARENT,
            "reporting_input_subject": HEAD_SUBJECT,
            "reporting_materializer": file_record(SCRIPT_REL),
            "dashboard_builder": file_record(BUILDER_REL),
            "baseline_findings": {
                "path": FINDINGS_REL,
                "sha256": BASELINE_FINDINGS_SHA256,
                "ordered_record_list_sha256": BASELINE_RECORD_LIST_SHA256,
                "records": 14,
            },
            "updated_findings": file_record(FINDINGS_REL),
            "frozen_run_185_dashboard": file_record(DASHBOARD_REL),
            "run_185_receipt": file_record(RUN_185_REL),
            "run_186_generator": file_record(RUN_186_GENERATOR_REL),
            "run_186_receipt": file_record(RUN_186_REL),
            "run_186r_generator": file_record(RUN_186R_GENERATOR_REL),
            "run_186r_receipt": file_record(RUN_186R_REL),
            "application_lineage": {
                "baseline_commit": pins["application_baseline_commit"],
                "baseline_tree": pins["application_baseline_tree"],
                "initial_fix_commit": pins["initial_fix_commit"],
                "initial_fix_tree": pins["initial_fix_tree"],
                "initial_merge_commit": pins["initial_merge_commit"],
                "initial_merge_tree": pins["initial_merge_tree"],
                "corrective_fix_commit": pins["corrective_fix_commit"],
                "corrective_fix_tree": pins["corrective_fix_tree"],
                "corrective_merge_commit": pins["corrective_merge_commit"],
                "corrective_merge_tree": pins["corrective_merge_tree"],
                "current_local_main_commit": pins["current_local_main_commit"],
                "current_local_main_tree": pins["current_local_main_tree"],
                "origin_main_observed": pins["origin_main_observed"],
                "published": False,
            },
            "initial_paths": INITIAL_PATHS,
            "corrective_paths": CORRECTIVE_PATHS,
            "current_issue_union_paths": sorted({*INITIAL_PATHS, *CORRECTIVE_PATHS}),
            "reporting_records": reporting_records,
        },
        "reporting_transition": {
            "finding_id": "MON-METRIC-REPLAY-DEDUPE-01",
            "authorized_by_run_186r": True,
            "status_after": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
            "feature_id": None,
            "candidate_feature_id": None,
            "related_feature_ids": [],
            "feature_identity_status": "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW",
            "static_ownership_credit": False,
            "counts_before": {
                "retained_claim_records": 14,
                "provisional_source_claims": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 4,
                "final_P0": 0,
                "final_P1": 0,
            },
            "counts_after": {
                "retained_claim_records": 15,
                "provisional_source_claims": 8,
                "historical_already_fixed": 2,
                "historical_remediated": 5,
                "final_P0": 0,
                "final_P1": 0,
            },
        },
        "bounded_execution_accounting": {
            "prior": {"tests": 99, "assertions": 1931},
            "counted_once": {"tests": 56, "assertions": 472},
            "unique_total": {"tests": 155, "assertions": 2403},
            "initial_49_392_denominator_credit": False,
            "root_red_reproduction_denominator_credit": False,
            "intermediate_or_stopped_runs_denominator_credit": False,
            "final_isolated_replay_denominator_credit": False,
            "dns_63_186_denominator_credit": False,
            "facility_execution_denominator_credit": False,
        },
        "option_a_deployment_boundary": {
            "accepted_option": "A_CANONICAL_WHOLE_SECOND_MIXED_WORKER_BRIDGE",
            "prerequisites_in_order": OPTION_A_PREREQUISITES,
            "poisoned_subsecond_evidence_requires_operator_reconciliation": True,
            "verified_in_production": False,
            "migration_deployment_credit": False,
            "publication_credit": False,
            "final_finding_or_completion_credit": False,
        },
        "preservation_boundary": {
            "dashboard_sha256": FROZEN_DASHBOARD_SHA256,
            "dashboard_html_changed": False,
            "static_ownership": {"owners": 666, "routes": 309, "pages": 357, "controller_action_bridges": 97},
            "queue": {"total": 507, "reviewed": 120, "pending": 387, "owned": 98, "without_ownership": 409, "next_zero_based_index": 85, "advanced": False},
            "benchmark": {"mapped": 2, "total": 340, "final_no_match_or_NCM": 0, "unresolved": 338},
            "source_denominator": {"total": 3929, "owned": 666, "residual": 3263, "percent": "16.950878"},
            "P0": 0,
            "P1": 0,
        },
        "run_185_history": {
            "viewports": "4/4",
            "visible_checks": "117/117",
            "navigation": "10/10",
            "resources": "463/463",
            "anchors": 868,
            "credit": "EXACT_SUPERSEDED_RUN184_DASHBOARD_ONLY",
        },
        "dashboard_forward_gate": {
            "required_run": "RUN-188",
            "generator": "generators/materialize-run-188-audit-dashboard-verification-wave-36.py",
            "receipt": "evidence/browser/current-audit-dashboard-verification-run-188-wave-36.json",
            "dashboard_html_changed_by_run_187": False,
            "fresh_four_viewport_verification_required": True,
            "forward_paths_intentionally_unhashed": True,
        },
        "reporting_transaction": {
            "exact_ten_path_allowlist": sorted(EXACT_DIRTY_ALLOWLIST),
            "reporting_surfaces": REPORTING_SURFACES,
            "new_generator": SCRIPT_REL,
            "new_receipt": OUTPUT_REL,
            "materializer_wrote_only": [OUTPUT_REL],
            "strict_utf8_lf_text_validated": True,
            "strict_duplicate_key_json_validated": True,
            "dashboard_preserved_byte_for_byte": True,
        },
        "credit_boundary": {
            "live_findings_register_and_reporting_status": True,
            "canonical_or_candidate_feature_identity": False,
            "static_route_or_page_feature_ownership": False,
            "static_controller_action_bridge": False,
            "queue_advance": False,
            "application_runtime_reexecution": False,
            "application_browser": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "migration_deployment": False,
            "release": False,
            "publication": False,
            "final_finding": False,
            "pass": False,
            "feature_or_module_completion": False,
            "gate_4": False,
            "audit_complete": False,
        },
        "completion_gates": completion_gates(),
        "completion_boundary": dict(run_186["completion_boundary"]),
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [OUTPUT_REL],
    }
    assert len(payload["completion_gates"]) == 26
    assert not any(payload["completion_gates"].values())
    assert all(value is False for value in payload["completion_boundary"].values())
    assert {key for key, value in payload["credit_boundary"].items() if value} == {"live_findings_register_and_reporting_status"}

    payload["receipt_self_seal_sha256"] = canonical_sha256(payload)
    output_bytes = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    (AUDIT_DIR / OUTPUT_REL).parent.mkdir(parents=True, exist_ok=True)
    (AUDIT_DIR / OUTPUT_REL).write_bytes(output_bytes)

    parsed_output = read_json_strict(OUTPUT_REL)
    output_without_seal = dict(parsed_output)
    observed_seal = output_without_seal.pop("receipt_self_seal_sha256")
    assert observed_seal == canonical_sha256(output_without_seal)
    assert parsed_output == payload

    dashboard_after = sha256_file(DASHBOARD_REL)
    assert dashboard_after == dashboard_before == FROZEN_DASHBOARD_SHA256
    assert str(git("diff", "--cached", "--name-only")).strip() == ""
    assert str(git("diff", "--check")).strip() == ""
    dirty_after, status_after = status_paths()
    assert dirty_after == EXACT_DIRTY_ALLOWLIST
    assert all(status.startswith((" M ", "?? ")) for status in status_after)

    print(json.dumps({
        "run_id": RUN_ID,
        "receipt_sha256": sha256_file(OUTPUT_REL),
        "receipt_self_seal_sha256": observed_seal,
        "dashboard_sha256_before": dashboard_before,
        "dashboard_sha256_after": dashboard_after,
        "dirty_paths": sorted(dirty_after),
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
