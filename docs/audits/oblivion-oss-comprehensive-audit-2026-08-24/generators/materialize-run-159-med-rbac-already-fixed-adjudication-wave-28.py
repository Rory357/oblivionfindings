#!/usr/bin/env python3
"""Materialize the bounded RUN159 MED-RBAC-01 adjudication receipt.

The receipt preserves the historical source defect, binds the independently
reviewed current-source result, and records the root-observed focused MySQL
test lane and cleanup.  It does not mutate application source or reporting
surfaces and awards no benchmark, browser, ease, Pass, release, final-finding,
feature-completion, or audit-completion credit.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()

RUN_ID = "RUN-159-MED-RBAC-01-ALREADY-FIXED-ADJUDICATION-WAVE-28"
STATUS = (
    "ALREADY_FIXED_UNANIMOUS_CURRENT_SOURCE_REVIEW_AND_BOUNDED_MYSQL_TESTS_"
    "HISTORICAL_CLAIM_RETIREMENT_AUTHORIZED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
GENERATED_AT = "2026-08-29T18:15:00+12:00"
MATERIALIZER = "generators/materialize-run-159-med-rbac-already-fixed-adjudication-wave-28.py"
OUTPUT = "evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json"

APPLICATION_COMMIT = "4f57ad4202df90ded375961437879822a908627b"
APPLICATION_TREE = "ee79b8d2733d09da2fd97992ac2a04e862159505"
HISTORICAL_AUDIT_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
INTEGRATED_REMEDIATION_MERGE = "cd5d34e6b8aa7e494808745041ec1dfa187dc101"
REJECTED_SIBLING = "9899d6147c1fb263c24376f0361bc97098e4b7d5"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
HISTORICAL_RECORD_SHA256 = "aa35c543ac25d15d074b344abd6ce8750975717f6c6e229d36986256c5a301ea"

SOURCE_PINS = {
    "routes/emar.php": ("369d592aa532a988018d7b48f78d97f41500836762a662f8b714838b7dfeb8c9", "f7ea398d5cbfdeaadd7fdab41f417e26ac170ff7", 27108, 456),
    "routes/api_medications.php": ("a3797d887efd1fbe088ee707a3397ed3b7d4d3462306de18a81cfb7ddb57fa4a", "a586f6d0c05763133356a5278723b574a030add0", 8150, 149),
    "routes/clients.php": ("4bf8696bcebecb5262ac1fdc9868ced5f0878ccf336c2328ac37b1e26c83c8d3", "1f083c4012691f08b136cbbb6edb6baac20d47b7", 14266, 260),
    "routes/operations.php": ("832faa98204cc3ad7b507747a0fdc589b78f8c6d40faa9eb8573ee1362c876cd", "409a0b18db3487e19f0e1a64b2054e553c0f4dc1", 83646, 1336),
    "routes/fleet-assets.php": ("4be79ba4a0957f81f3e99de8eea7f29a398f8a115957bd44af06dbbf78fe2c4c", "f0b2b8c199ada1d8ef8bdb41c99bfc2ac02f93d2", 28332, 351),
    "routes/web.php": ("5894a96a99997c984047b3aa9aef793c34c3d2d67fdac091e1022bcc3c05837e", "cb6aec6dd2c44079a678ee1b92bcd1bddf2079fd", 25614, 400),
    "app/Services/Medication/MedicationGovernanceScopeService.php": ("ef1733ea67ec8e1b9ece980151555120396145257552691f67909995e4089849", "434cd58b66d6948dc1897a285d41d21ff9059c5e", 64408, 1678),
    "app/Http/Controllers/Emar/EmarController.php": ("91a9d112be6be2b803b7fe9175e0610e29a0b983b17ffcc73c70a218df110a9a", "da2291eab95fdb9605c9eb74d6b69492893afe85", 433620, 8909),
    "app/Models/User.php": ("0d184ebe6a28395b34b195751ffb62390f4c32828c982103ad613430bc4e59ae", "fea4665a309992d9098e1e080f4bf11d63fcc675", 13911, 494),
    "database/seeders/RbacSeeder.php": ("c0a3723cfdbdaf665546a10157db5e4c1651c4c3d67adb2495305b9e0bcadbc1", "5ffcc3afa6c6bbf826c2f6961a59df57f7c78c98", 88950, 1024),
    "tests/Feature/Emar/MedicationControlledOrderMutationAuthorizationTest.php": ("c8e9c9a23baab2cff333bd6e4513e8b10950a5d770535086886332944c86aa37", "b5442285878b61fbd7ef5e95a2a48f8f709655ca", 16483, 408),
    "tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php": ("d02c4a2d39f7a4e26aafa318eb58a78a3485ce6ce2f1f1553bbcbaa1b5cf1124", "254cf45babc4f4770a48cb2f041c72bc435d5158", 107360, 2440),
    "tests/Feature/Emar/MedicationRbacAuthorizationTest.php": ("187fd80fbb7a49ea3fd4d190ef1bf3470517b0a50e9a18b6eca9b74bf7ed356d", "1b972cd67ad1b81e427cc31d439290fc8a58da22", 87922, 1959),
    "tests/Architecture/MedicationExactCapabilityUiBoundaryTest.php": ("708b712733f4226002230615dd9fab114258ac6f48cf6c7341e2a4d268cecb25", "3e365abcbb402d2a5ead605fe36695d273a60b89", 6066, 102),
    "tests/TestCase.php": ("89872e92712993d42361d9d444a80a92ec5f633eedfc2179c78447adf337330f", "ecf3cf2da728f686b8c9d87161cf345d72dbd271", 20547, 603),
}


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(["git", *args], cwd=ROOT, check=check, capture_output=True)


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").rstrip("\r\n")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256_bytes(
        json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    )


def strict_json(path: Path) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        value: dict[str, Any] = {}
        for key, item in pairs:
            assert key not in value, (path, key)
            value[key] = item
        return value

    result = json.loads(path.read_bytes(), object_pairs_hook=hook)
    assert isinstance(result, dict)
    return result


def file_record(relative: str) -> dict[str, Any]:
    payload = (ROOT / relative).read_bytes()
    assert payload.endswith(b"\n") and b"\r\n" not in payload
    assert not payload.startswith(b"\xef\xbb\xbf")
    return {
        "sha256": sha256_bytes(payload),
        "blob_id": git("hash-object", "--", relative),
        "bytes": len(payload),
        "lines": len(payload.decode("utf-8").splitlines()),
    }


def expected_status(include_output: bool) -> set[str]:
    rows = {f"?? {PREFIX}/{MATERIALIZER}"}
    if include_output:
        rows.add(f"?? {PREFIX}/{OUTPUT}")
    return rows


def validate_repository_boundary() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == APPLICATION_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == APPLICATION_TREE
    assert git("rev-parse", "main") == APPLICATION_COMMIT
    assert git("rev-parse", "origin/main") == APPLICATION_COMMIT
    assert set(git("status", "--porcelain=v1", "--untracked-files=all").splitlines()) in (
        expected_status(False), expected_status(True)
    )
    assert git("diff", "--name-only", "HEAD", "--") == ""
    assert git("diff", "--cached", "--name-only", "HEAD", "--") == ""
    diff_check = run_git("diff", "--check", "HEAD", "--", check=False)
    assert diff_check.returncode == 0 and diff_check.stdout == b"" and diff_check.stderr == b""


def validate_source() -> dict[str, dict[str, Any]]:
    observed: dict[str, dict[str, Any]] = {}
    for relative, expected in SOURCE_PINS.items():
        record = file_record(relative)
        assert tuple(record[key] for key in ("sha256", "blob_id", "bytes", "lines")) == expected, relative
        assert git("rev-parse", f"HEAD:{relative}") == record["blob_id"]
        observed[relative] = record

    historical_routes = run_git("show", f"{HISTORICAL_AUDIT_COMMIT}:routes/emar.php").stdout.decode("utf-8")
    assert "Route::middleware('permission:medications.orders.manage')->group(function ()" in historical_routes
    for route_name in (
        "emar.controlled.entries.store",
        "emar.controlled.balance_check.store",
        "emar.destructions.store",
        "emar.stock.receive",
        "emar.stock.adjust",
    ):
        assert route_name in historical_routes

    current_routes = (ROOT / "routes/emar.php").read_text(encoding="utf-8")
    assert "Route::middleware('permission:medications.controlled.record')->group(function ()" in current_routes
    assert "Route::middleware('permission:medications.stock.update')->group(function ()" in current_routes
    current_user = (ROOT / "app/Models/User.php").read_text(encoding="utf-8")
    alias_slice = current_user[current_user.index("$aliases = ["):]
    assert "medications.orders.manage" not in alias_slice

    assert run_git("merge-base", "--is-ancestor", HISTORICAL_AUDIT_COMMIT, "HEAD", check=False).returncode == 0
    assert run_git("merge-base", "--is-ancestor", INTEGRATED_REMEDIATION_MERGE, "HEAD", check=False).returncode == 0
    assert run_git("merge-base", "--is-ancestor", REJECTED_SIBLING, "HEAD", check=False).returncode == 1

    findings = strict_json(AUDIT / "findings.json")
    records = {row["id"]: row for row in findings["records"]}
    assert canonical_sha256(records["MED-RBAC-01"]) == HISTORICAL_RECORD_SHA256
    assert records["MED-RBAC-01"]["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
    assert findings["counts"]["provisional_source_claims"] == 12
    assert findings["counts"]["provisional_P1"] == 12
    return observed


def reviewer_record(
    lane: str,
    role: str,
    confidence: str,
    coverage: list[str],
    remaining_gaps: list[str],
) -> dict[str, Any]:
    value: dict[str, Any] = {
        "reviewer_lane": lane,
        "role": role,
        "verdict": "ALREADY_FIXED",
        "confidence": confidence,
        "pinned_application_commit": APPLICATION_COMMIT,
        "historical_defect_reproduced_in_source": True,
        "current_orders_manage_only_bypass_found": False,
        "current_source_scope": "STATIC_READ_ONLY_NO_TEST_OR_BROWSER_EXECUTION",
        "coverage": coverage,
        "remaining_nonfinding_gaps": remaining_gaps,
        "writes": False,
        "tests_executed": False,
        "browser_executed": False,
        "cross_reviewer_coordination": False,
        "other_reviewer_outputs_read": False,
    }
    value["root_materialized_record_sha256"] = canonical_sha256(value)
    return value


def materialize(source_records: dict[str, dict[str, Any]]) -> dict[str, Any]:
    reviewers = [
        reviewer_record(
            "/root/med_rbac_source_verifier",
            "complete production write-sink and canonical-scope verifier",
            "HIGH",
            [
                "all medication mutation route families and legacy aliases",
                "exact controlled, stock, administration, and witness capabilities",
                "canonical Client, medication, stock, order, discrepancy, and destruction ownership",
                "direct-object concealment, replay binding, lock/recheck order, and write sinks",
            ],
            ["malformed-body foreign/nonexistent equivalence remains advisable", "no runtime or browser execution in reviewer lane"],
        ),
        reviewer_record(
            "/root/med_rbac_history_tests",
            "remediation lineage and static regression-test sufficiency verifier",
            "HIGH",
            [
                "rejected sibling, original chain, main-line replay, exact-capability alignment, and merge lineage",
                "125-path claimed remediation union unchanged from integrated merge to current main",
                "allowed, wrong-permission, wrong-Site, direct-ID, global-Site, witness, replay, and lock-order test inventory",
            ],
            ["operation-level concurrent same-UUID same/different-payload race not focused in existing tests", "no runtime or browser execution in reviewer lane"],
        ),
        reviewer_record(
            "/root/med_rbac_adversarial",
            "adversarial permission, role, route, direct-ID, witness, and mass-assignment bypass reviewer",
            "HIGH_0_93",
            [
                "all seeded roles and medication capability aliases",
                "global-Site plus orders.manage counterexample",
                "route, legacy alias, controller callsite, model fillable, generic stock, and administration ingress bypass probes",
                "revocation-under-lock and witness substitution boundaries",
            ],
            ["no seeded clinical_lead end-to-end test across every CD/stock route", "future policy-only route hardening risk", "no runtime or browser execution in reviewer lane"],
        ),
    ]

    tests = [
        {
            "command": "php artisan test --compact --stop-on-failure tests/Feature/Emar/MedicationControlledOrderMutationAuthorizationTest.php tests/Feature/Emar/MedicationGovernanceAuthorizationTest.php",
            "exit_code": 0,
            "tests_passed": 43,
            "assertions": 786,
            "duration_seconds": "234.56",
        },
        {
            "command": "php artisan test --compact --stop-on-failure tests/Feature/Emar/MedicationRbacAuthorizationTest.php",
            "exit_code": 0,
            "tests_passed": 28,
            "assertions": 655,
            "duration_seconds": "215.53",
        },
        {
            "command": "php artisan test --compact tests/Architecture/MedicationExactCapabilityUiBoundaryTest.php",
            "exit_code": 0,
            "tests_passed": 2,
            "assertions": 40,
            "duration_seconds": "0.63",
        },
    ]
    assert sum(row["tests_passed"] for row in tests) == 73
    assert sum(row["assertions"] for row in tests) == 1481

    payload: dict[str, Any] = {
        "schema_version": "run-159-med-rbac-already-fixed-adjudication-wave-28-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "generated_at": GENERATED_AT,
        "architecture_boundary": {
            "operating_organisations": 1,
            "multiple_Sites": True,
            "tenant_authorization": False,
            "authorization_boundary": "approved Site access, exact roles and permissions, canonical ownership, direct-object denial, witness qualification, and privacy",
        },
        "pins": {
            "governing_prompt_sha256": PROMPT_SHA256,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "main_commit": APPLICATION_COMMIT,
            "origin_main_after_fetch_prune": APPLICATION_COMMIT,
            "historical_audited_application_commit": HISTORICAL_AUDIT_COMMIT,
            "integrated_remediation_merge": INTEGRATED_REMEDIATION_MERGE,
            "rejected_nonancestor_sibling": REJECTED_SIBLING,
            "historical_med_rbac_record_canonical_sha256": HISTORICAL_RECORD_SHA256,
            "source_and_test_files": source_records,
        },
        "review_process": {
            "reviewers": reviewers,
            "independent_read_only_lanes": 3,
            "unanimous_verdict": "ALREADY_FIXED",
            "cross_reviewer_coordination": False,
            "reviewer_outputs_read_by_other_reviewers": False,
            "root_materialized_returned_reviews": True,
            "root_was_sole_writer_and_integrator": True,
        },
        "historical_and_current_disposition": {
            "finding_id": "MED-RBAC-01",
            "feature_id": "CAP-MED-CD-REGISTER-BALANCE",
            "historical_condition_at_a049": "REAL_BROAD_ORDERS_MANAGE_ROUTE_GROUP_REACHED_CONTROLLED_AND_STOCK_MUTATIONS",
            "current_condition_at_4f57ad4": "ALREADY_FIXED_EXACT_CONTROLLED_AND_STOCK_CAPABILITY_SEPARATION_WITH_BOUNDED_SITE_AND_DIRECT_ID_TEST_COVERAGE",
            "current_orders_manage_only_bypass_reproduced": False,
            "current_final_finding": False,
            "new_finding_id_required": False,
            "application_remediation_required": False,
            "application_source_changed_by_run_159": False,
            "record_action_authorized": "RETIRE_PROVISIONAL_CURRENT_SOURCE_CLAIM_PRESERVE_HISTORICAL_IDENTITY",
        },
        "current_enforcement": {
            "orders_manage_group": "order, review, round, and medication lifecycle work only",
            "controlled_mutation_group": "permission:medications.controlled.record",
            "stock_mutation_group": "permission:medications.stock.update",
            "global_Site_permissions_replace_action_authority": False,
            "canonical_scope_owner": "app/Services/Medication/MedicationGovernanceScopeService.php",
            "defence_in_depth": [
                "route middleware exact capability",
                "controller exact capability recheck",
                "locked current actor and assignment reauthorization",
                "canonical Client and approved-Site ownership",
                "privacy-safe direct-object concealment",
                "distinct qualified present witness for controlled effects",
                "authorization and witness checks before replay lookup",
                "transactional stock, register, audit, and replay publication",
            ],
        },
        "runtime_execution": {
            "scope": "BOUNDED_MED_RBAC_01_MYSQL_AND_STATIC_UI_CAPABILITY_TESTS_ONLY",
            "dependency_setup": {
                "command": "composer install --no-interaction --prefer-dist --no-progress",
                "exit_code": 0,
                "purpose": "hydrate task-local ignored vendor dependencies",
                "tracked_files_changed": False,
            },
            "sanitized_environment": {
                "APP_ENV": "testing",
                "DB_CONNECTION": "mysql",
                "configured_DB_DATABASE_base": "oblivion_audit_run159_med_rbac_01a04c0b",
                "CACHE_STORE": "array",
                "SESSION_DRIVER": "array",
                "QUEUE_CONNECTION": "sync",
                "MAIL_MAILER": "array",
                "credentials_recorded": False,
            },
            "database": {
                "configured_base_name": "oblivion_audit_run159_med_rbac_01a04c0b",
                "configured_base_pre_creation_nonexistence_verified": True,
                "configured_base_created_for_run": True,
                "effective_test_schema_pattern": "oblivion_audit_run159_med_rbac_01a04c0b_<processToken>",
                "effective_schema_names_retained_in_receipt": False,
                "test_harness_isolation_owner": "tests/TestCase.php:121-176",
                "test_harness_behavior": "rewrites the configured base to a per-process schema, recreates it, loads the schema, prunes stale siblings, and registers shutdown cleanup",
                "automatic_shutdown_cleanup_owner": "tests/TestCase.php:186-212",
                "configured_base_pre_drop_present": 1,
                "configured_base_pre_drop_table_count": 0,
                "configured_base_post_explicit_drop_present": 0,
                "post_run_effective_schema_prefix_query_exit_code": 0,
                "post_run_effective_schema_prefix_match_count": 0,
                "post_run_configured_base_present": 0,
                "all_run159_schema_residue_absent": True,
            },
            "commands": tests,
            "totals": {"commands": 3, "tests_passed": 73, "tests_failed": 0, "assertions": 1481, "duration_seconds": "450.72"},
            "cleanup_attempts": [
                {
                    "attempt": 1,
                    "exit_code": 1,
                    "result": "NO_DATABASE_MUTATION_BOOTSTRAP_FAILED_BEFORE_CONNECTION",
                    "reason": "vendor/autoload.php was omitted before bootstrap/app.php",
                },
                {
                    "attempt": 2,
                    "exit_code": 0,
                    "result": "EXACT_GUARDED_DISPOSABLE_DATABASE_DROPPED_AND_ABSENCE_VERIFIED",
                },
            ],
            "post_cleanup_php_processes": 0,
            "post_cleanup_php_listeners": 0,
            "browser_executed": False,
        },
        "materializer_execution": {
            "observed_pre_seal_failures": [
                {
                    "runtime": "Windows Store python alias",
                    "exit_code": 9009,
                    "result": "NO_MATERIALIZATION_PYTHON_RUNTIME_NOT_FOUND",
                },
                {
                    "runtime": "Codex bundled Python",
                    "exit_code": 1,
                    "result": "NO_MATERIALIZATION_STATIC_ALIAS_LOCATOR_ASSERTION_CORRECTED",
                },
            ],
            "successful_bundled_python_materializations_before_commit": "AT_LEAST_TWO",
            "deterministic_replay_byte_identical": True,
            "failed_attempts_changed_application_or_audit_outputs": False,
        },
        "bounded_acceptance": {
            "orders_only_denied_without_effects": True,
            "dedicated_capability_positive_cases": True,
            "wrong_Site_and_direct_ID_concealment": True,
            "global_Site_never_replaces_action_authority": True,
            "controlled_witness_distinct_current_site_qualified_and_credential_confirmed": True,
            "serial_replay_binding_and_authority_recheck": True,
            "exact_UI_capability_boundary_static_test": True,
            "operation_level_concurrent_same_UUID_race": "NOT_EXECUTED_OR_CREDITED",
            "representative_signed_in_application_browser": "NOT_EXECUTED_OR_CREDITED",
        },
        "non_inherited_open_gaps": [
            "MED-CD-SCOPE-01 remains a separate provisional source claim",
            "MED-CD-ATOMICITY-01 remains a separate provisional source claim",
            "operation-level concurrent same-UUID same/different-payload races remain unexecuted",
            "full medication module and cross-module journeys remain unexecuted",
            "signed-in application browser, ease, benchmark, release, Pass, and audit completion remain open",
        ],
        "credit_boundary": {
            "historical_condition_source_confirmed": True,
            "current_source_already_fixed_adjudication": True,
            "bounded_med_rbac_test_execution": True,
            "provisional_current_source_claim_retirement_authorized": True,
            "application_remediation": False,
            "final_finding": False,
            "final_P1": False,
            "benchmark_mapping": False,
            "final_no_match_or_NCM": False,
            "application_browser": False,
            "ease": False,
            "full_feature_or_module": False,
            "pass": False,
            "release": False,
            "completion": False,
            "audit_complete": False,
        },
        "completion_boundary": {
            "all_340_benchmark_targets_resolved": False,
            "all_required_browser_cells_observed": False,
            "all_task_scripts_executed": False,
            "all_eight_passes_complete": False,
            "gate_4_complete": False,
            "audit_complete": False,
        },
    }
    payload["review_process"]["review_set_sha256"] = canonical_sha256(reviewers)
    payload["runtime_execution"]["command_set_sha256"] = canonical_sha256(tests)
    payload["artifact_completion_test_met"] = True
    payload["audit_completion_test_met"] = False
    return payload


def main() -> None:
    validate_repository_boundary()
    source_records = validate_source()
    payload = materialize(source_records)
    output_path = AUDIT / OUTPUT
    output_bytes = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    output_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = output_path.with_name(f".{output_path.name}.tmp-run159")
    assert not temporary.exists()
    try:
        temporary.write_bytes(output_bytes)
        assert temporary.read_bytes() == output_bytes
        temporary.replace(output_path)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert output_path.read_bytes() == output_bytes
    assert strict_json(output_path) == payload
    assert set(git("status", "--porcelain=v1", "--untracked-files=all").splitlines()) == expected_status(True)
    print(json.dumps({
        "run_id": RUN_ID,
        "status": STATUS,
        "output": OUTPUT,
        "sha256": sha256_bytes(output_bytes),
        "reviewers": 3,
        "tests_passed": 73,
        "assertions": 1481,
        "audit_complete": False,
    }, sort_keys=True))


if __name__ == "__main__":
    main()
