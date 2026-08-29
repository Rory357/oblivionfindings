#!/usr/bin/env python3
"""Materialize the bounded RUN162 MED-CD-SCOPE-01 remediation receipt.

The evidence below records work already performed by the root runtime lane. This
program does not rerun tests, touch a database, start a browser, mutate
application source, or change a finding status. It writes only its deterministic
JSON receipt.
"""
from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()

RUN_ID = "RUN-162-MED-CD-SCOPE-01-REMEDIATION-WAVE-29"
STATUS = (
    "REPRODUCED_CURRENT_SCOPE_DEFECTS_REMEDIATED_PUBLISHED_AND_BOUNDED_VERIFIED_"
    "REPORTING_NOT_YET_AUTHORIZED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
MATERIALIZER = "generators/materialize-run-162-med-cd-scope-remediation-wave-29.py"
OUTPUT = "evidence/runtime/current-run-162-med-cd-scope-remediation-wave-29.json"

GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
HISTORICAL_APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
EFFECTIVE_APPLICATION_BASE_COMMIT = "4f57ad4202df90ded375961437879822a908627b"
EFFECTIVE_APPLICATION_BASE_TREE = "ee79b8d2733d09da2fd97992ac2a04e862159505"
BASE_COMMIT = "500f4ab9bc7b2952a80913f1fb2d71cab5946005"
APPLICATION_COMMIT = "0b1920dade9251d617f3cb0b69da5c0202b5a6bf"
APPLICATION_TREE = "7b2b5688c90e4da28725e70e38e50fd445f1b4c4"
APPLICATION_PATCH_ID = "09bc6f401235fa70b5f9da90aef226b2b7aa2d73"
HISTORICAL_RECORD_SHA256 = "dd86bf94f3b4d894e95c56c95a9409ce803b8d82d108cdd3c42f3343e348cd21"

CHANGED_PATHS = {
    "app/Console/Commands/EscalateOverdueControlledChecks.php": (13, 3),
    "app/Http/Controllers/Emar/EmarController.php": (27, 15),
    "app/Services/MedicationOverviewService.php": (5, 2),
    "tests/Feature/Emar/ControlledDrugsTest.php": (72, 0),
    "tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php": (23, 0),
    "tests/Feature/Emar/MedicationOverviewServiceTest.php": (35, 0),
    "tests/Feature/Emar/StockManagementTest.php": (59, 0),
}


def run_git(*args: str) -> str:
    completed = subprocess.run(
        ["git", *args], cwd=ROOT, check=True, capture_output=True, text=True,
    )
    return completed.stdout.strip()


def git_bytes(revision: str, relative: str) -> bytes:
    completed = subprocess.run(
        ["git", "show", f"{revision}:{relative}"],
        cwd=ROOT,
        check=True,
        capture_output=True,
    )
    return completed.stdout


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def canonical_sha256(value: Any) -> str:
    payload = json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":"),
    ).encode("utf-8")
    return sha256_bytes(payload)


def working_file_record(relative: str) -> dict[str, Any]:
    payload = (AUDIT / relative).read_bytes()
    assert payload.endswith(b"\n") and b"\r\n" not in payload
    return {
        "path": relative,
        "sha256": sha256_bytes(payload),
        "git_blob_id": hashlib.sha1(
            f"blob {len(payload)}\0".encode("ascii") + payload
        ).hexdigest(),
        "bytes": len(payload),
        "lines": payload.count(b"\n"),
    }


def committed_file_record(relative: str) -> dict[str, Any]:
    payload = git_bytes(APPLICATION_COMMIT, relative)
    return {
        "path": relative,
        "sha256": sha256_bytes(payload),
        "git_blob_id": run_git("rev-parse", f"{APPLICATION_COMMIT}:{relative}"),
        "bytes": len(payload),
        "lines": payload.count(b"\n"),
        "insertions": CHANGED_PATHS[relative][0],
        "deletions": CHANGED_PATHS[relative][1],
    }


def build_receipt() -> dict[str, Any]:
    assert run_git("rev-parse", f"{APPLICATION_COMMIT}^") == BASE_COMMIT
    assert run_git("show", "-s", "--format=%T", APPLICATION_COMMIT) == APPLICATION_TREE
    assert run_git("show", "-s", "--format=%s", APPLICATION_COMMIT) == (
        "fix(emar): enforce canonical controlled drug scope"
    )
    changed = run_git(
        "diff", "--name-only", BASE_COMMIT, APPLICATION_COMMIT,
    ).splitlines()
    assert changed == list(CHANGED_PATHS)
    intervening_paths = run_git(
        "diff", "--name-only", EFFECTIVE_APPLICATION_BASE_COMMIT, BASE_COMMIT,
    ).splitlines()
    assert intervening_paths
    assert all(
        path.startswith("docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/")
        for path in intervening_paths
    )

    red_cases = [
        {
            "id": "RUN162-RED-DISCREPANCY-VALIDATION-ORACLE",
            "test": "MedicationGovernanceAuthorizationTest::test_med_cd_scope_discrepancy_resolution_conceals_foreign_objects_before_validation",
            "pre_fix_observation": "foreign existing ID returned 302 validation response while missing ID returned 404",
            "expected_contract": "foreign existing and missing direct IDs both return the exact concealed 404 body before payload validation, with no mutation or audit effect",
        },
        {
            "id": "RUN162-RED-CONTROLLED-PROJECTION",
            "test": "ControlledDrugsTest::test_med_cd_scope_reconciliation_ignores_noncanonical_client_medication_balance_checks",
            "pre_fix_observation": "same-Site wrong-client row replaced the canonical last-check timestamp: expected 2026-08-21, observed 2026-08-28",
            "expected_contract": "last-check aggregation includes only rows whose Client and client_medication ownership reconcile canonically",
        },
        {
            "id": "RUN162-RED-STOCK-PROJECTION",
            "test": "StockManagementTest::test_med_cd_scope_stock_register_ignores_noncanonical_balance_checks_and_discrepancies",
            "pre_fix_observation": "same-Site wrong-client balance was projected: expected 11, observed 99",
            "expected_contract": "stock balance, witness, timestamp, and discrepancy projections exclude noncanonical rows",
        },
        {
            "id": "RUN162-RED-OVERVIEW-SUPPRESSION",
            "test": "keeps med cd scope balance checks due when today entry has noncanonical ownership",
            "pre_fix_observation": "same-Site wrong-client today row suppressed the canonical medication due item",
            "expected_contract": "checked-today exclusion is canonical before due-item projection",
        },
        {
            "id": "RUN162-RED-SCHEDULED-ESCALATION-SUPPRESSION",
            "test": "ControlledDrugsTest::test_med_cd_scope_overdue_command_ignores_noncanonical_recent_balance_checks",
            "pre_fix_observation": "same-Site wrong-client recent row suppressed the expected overdue alert",
            "expected_contract": "internal all-Site scheduling retains canonical ownership even when no reader Site filter is supplied",
        },
    ]

    receipt: dict[str, Any] = {
        "schema_version": "run-162-med-cd-scope-remediation-wave-29-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-29",
        "architecture_boundary": "One operating organisation across multiple Sites; exact permissions, approved-Site access, canonical ownership, direct-object concealment, privacy, and consent are the boundaries.",
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "historical_audited_application_commit": HISTORICAL_APPLICATION_COMMIT,
            "historical_med_cd_scope_record_canonical_sha256": HISTORICAL_RECORD_SHA256,
            "effective_application_base_commit": EFFECTIVE_APPLICATION_BASE_COMMIT,
            "effective_application_base_tree": EFFECTIVE_APPLICATION_BASE_TREE,
            "effective_application_base_to_vcs_parent_audit_root_only": True,
            "effective_application_base_to_vcs_parent_path_count": len(intervening_paths),
            "application_parent": BASE_COMMIT,
            "application_commit": APPLICATION_COMMIT,
            "repository_tree_at_application_commit": APPLICATION_TREE,
            "tree_scope": "FULL_REPOSITORY_TREE_AT_APPLICATION_COMMIT",
            "stable_patch_id": APPLICATION_PATCH_ID,
            "application_commit_subject": "fix(emar): enforce canonical controlled drug scope",
            "application_remote_publication_observed": {
                "remote": "origin",
                "branch": "main",
                "published_commit": APPLICATION_COMMIT,
                "push_exit_code": 0,
                "verification_command": "git ls-remote origin refs/heads/main",
                "verification_exit_code": 0,
                "remote_observed_tip": APPLICATION_COMMIT,
                "force_push": False,
            },
            "materializer": working_file_record(MATERIALIZER),
            "application_source_and_regression_tests": [
                committed_file_record(path) for path in CHANGED_PATHS
            ],
        },
        "issue_first_disposition": {
            "finding_id": "MED-CD-SCOPE-01",
            "historical_claim_reproduced_on_historical_pin": True,
            "current_main_genuine_related_defects_reproduced_before_fix": len(red_cases),
            "new_discovery_stopped_after_confirmation": True,
            "stable_branch": "codex/med-cd-scope-01",
            "isolated_worktree": "C:/w/mcd162",
            "verdict": "REPRODUCED_AND_REMEDIATED_CURRENT_MAIN",
            "red_cases": red_cases,
            "red_execution_boundary": {
                "all_five_cases_executed_red_before_their_fix": True,
                "exact_historical_shell_command_text_retained": False,
                "nonfabrication_note": "The exact earlier red-run shell strings were not retained; this receipt records the executed test identities and observed mismatches without reconstructing commands.",
            },
        },
        "remediation": {
            "summary": "Apply the existing canonical medication-row scope before controlled, stock, overview, and scheduled last-check/discrepancy projections, and authorize a discrepancy object before validating its payload.",
            "production_files": 3,
            "regression_test_files": 4,
            "changed_paths": 7,
            "insertions": 234,
            "deletions": 20,
            "routes_pages_components_copy_or_layout_changed": False,
            "third_party_source_assets_wording_or_layout_copied": False,
            "single_tenant_multi_site_boundary_preserved": True,
        },
        "runtime_execution": {
            "root_builder_lane_only": True,
            "disposable_database": {
                "configured_name": "oa162_audit_final",
                "testcase_managed_effective_schema_prefix": "oa162_",
                "production_or_user_data_used": False,
                "notifications_webhooks_or_integrations_sent": False,
            },
            "advanced_main_focused_command": {
                "command": "$env:DB_DATABASE='oa162_audit_final'; php artisan test --compact tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php tests/Feature/Emar/ControlledDrugsTest.php tests/Feature/Emar/StockManagementTest.php tests/Feature/Emar/MedicationOverviewServiceTest.php --filter='med.cd.scope'",
                "exit_code": 0,
                "tests": 5,
                "assertions": 48,
                "duration_seconds": 149.85,
            },
            "earlier_isolated_worktree_focused_replay": {
                "exit_code": 0,
                "tests": 5,
                "assertions": 48,
                "duration_seconds": 144.63,
            },
            "broader_bounded_execution": {
                "files": [
                    "tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php",
                    "tests/Feature/Emar/ControlledDrugsTest.php",
                    "tests/Feature/Emar/StockManagementTest.php",
                    "tests/Feature/Emar/DestructionsTest.php",
                    "tests/Feature/Emar/MedicationOverviewServiceTest.php",
                ],
                "directly_related_controller_and_command_tests_passed": 102,
                "combined_passed": 108,
                "combined_assertions": 1454,
                "combined_failed": 2,
                "baseline_failures": [
                    "it surfaces an INR-out-of-range item...",
                    "severity sort raises ValueError because no critical INR item exists",
                ],
                "baseline_replay_at_base_commit": {
                    "file": "tests/Feature/Emar/MedicationOverviewServiceTest.php",
                    "same_failures": 2,
                    "passed": 5,
                    "assertions": 48,
                    "classification": "BASE_REPRODUCED_FAILURES_NOT_ATTRIBUTED_TO_RUN162_FULL_SUITE_GREEN_FALSE",
                },
                "full_suite_or_coverage_credit": False,
            },
            "format_command": {
                "command": "vendor/bin/pint --test app/Console/Commands/EscalateOverdueControlledChecks.php app/Http/Controllers/Emar/EmarController.php app/Services/MedicationOverviewService.php tests/Feature/Emar/ControlledDrugsTest.php tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php tests/Feature/Emar/MedicationOverviewServiceTest.php tests/Feature/Emar/StockManagementTest.php",
                "exit_code": 0,
                "result": "passed",
            },
            "syntax_command": {
                "command": "$paths=@('app/Console/Commands/EscalateOverdueControlledChecks.php','app/Http/Controllers/Emar/EmarController.php','app/Services/MedicationOverviewService.php','tests/Feature/Emar/ControlledDrugsTest.php','tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php','tests/Feature/Emar/MedicationOverviewServiceTest.php','tests/Feature/Emar/StockManagementTest.php'); foreach($path in $paths){ php -l $path }",
                "exit_code": 0,
                "files_passed": 7,
            },
            "diff_check": {"command": "git diff --check", "exit_code": 0},
        },
        "cleanup_evidence": {
            "schema_query_command": "php artisan tinker --% --execute=\"echo json_encode(DB::select('SELECT schema_name FROM information_schema.schemata WHERE LEFT(schema_name,6) = \\\'oa162_\\\' ORDER BY schema_name'));\"",
            "schema_query_exit_code": 0,
            "matching_schema_count": 0,
            "matching_schemas": [],
            "owned_php_process_count": 0,
            "owned_listener_count": 0,
            "main_worktree_clean_before_audit_writes": True,
            "isolated_worktree_clean_before_audit_writes": True,
        },
        "independent_static_reviews": {
            "reviewer_count": 3,
            "unanimous_verdict": "GO",
            "reviewers": [
                {
                    "lane": "/root/run162_scope_adversarial",
                    "verdict": "GO",
                    "scope": "current diff and adjacent controlled-drug read projections",
                    "executed_tests": False,
                    "wrote_files": False,
                },
                {
                    "lane": "/root/run162_scope_source",
                    "verdict": "GO",
                    "scope": "source construction, command container resolution, canonical helper semantics, and minimality",
                    "executed_tests": False,
                    "wrote_files": False,
                },
                {
                    "lane": "/root/run162_scope_history_tests",
                    "verdict": "GO",
                    "scope": "historical defect, command regression red/green validity, and noninheritance",
                    "executed_tests": False,
                    "wrote_files": False,
                },
            ],
            "exact_receipt_review_completed": False,
            "retirement_reporting_authorized": False,
        },
        "noninheritance_boundary": {
            "med_rbac_disposition_recredited": False,
            "med_cd_atomicity_disposition_or_concurrency_credit": False,
            "benchmark_mapping_or_NCM_changed": False,
            "ownership_inventory_or_matrix_changed_by_run_162": False,
            "application_browser_or_ease_credit": False,
            "final_finding_or_feature_completion_credit": False,
        },
        "credit_boundary": {
            "historical_condition_confirmed": True,
            "current_related_defects_reproduced": True,
            "application_remediation": True,
            "bounded_runtime": True,
            "application_commit_integrated": True,
            "application_commit_published": True,
            "finding_retirement_reporting": False,
            "application_browser": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "ease": False,
            "full_feature_or_module": False,
            "pass": False,
            "release": False,
            "final_finding": False,
            "completion": False,
            "audit_complete": False,
        },
        "completion_boundary": {
            "framework_route_reachability_complete": False,
            "semantic_assurance_complete": False,
            "execution_complete": False,
            "coverage_complete": False,
            "benchmark_complete": False,
            "pass_8_complete": False,
            "final_reconciliation_complete": False,
            "no_live_agent_gate_complete": False,
            "full_crosswalk_complete": False,
            "gate_4_complete": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{MATERIALIZER}", f"{PREFIX}/{OUTPUT}"],
    }
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["issue_first_disposition"]["verdict"] == (
        "REPRODUCED_AND_REMEDIATED_CURRENT_MAIN"
    )
    assert receipt["independent_static_reviews"]["retirement_reporting_authorized"] is False
    assert [key for key, value in receipt["credit_boundary"].items() if value] == [
        "historical_condition_confirmed",
        "current_related_defects_reproduced",
        "application_remediation",
        "bounded_runtime",
        "application_commit_integrated",
        "application_commit_published",
    ]
    assert all(value is False for value in receipt["completion_boundary"].values())


def main() -> None:
    receipt = build_receipt()
    validate(receipt)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert encoded.endswith(b"\n") and b"\r\n" not in encoded
    output = AUDIT / OUTPUT
    output.write_bytes(encoded)
    assert output.read_bytes() == encoded
    reloaded = json.loads(output.read_text(encoding="utf-8"))
    assert reloaded == receipt
    validate(reloaded)
    print(json.dumps({
        "run_id": RUN_ID,
        "status": STATUS,
        "materializer_sha256": working_file_record(MATERIALIZER)["sha256"],
        "receipt_sha256": sha256_bytes(encoded),
        "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "focused_tests": 5,
        "focused_assertions": 48,
        "retirement_reporting_authorized": False,
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
