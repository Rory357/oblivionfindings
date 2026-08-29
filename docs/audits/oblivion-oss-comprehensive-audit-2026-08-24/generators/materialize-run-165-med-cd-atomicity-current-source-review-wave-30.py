#!/usr/bin/env python3
"""Materialize the bounded RUN165 MED-CD-ATOMICITY-01 current-source review.

This is source evidence only.  It preserves the historical manual-entry defect,
records the current structural atomicity candidate, and requires an exact
operation-level MySQL race before any live reporting disposition can change.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
AUDIT_PREFIX = AUDIT.relative_to(ROOT).as_posix() + "/"

RUN_ID = "RUN-165-MED-CD-ATOMICITY-01-CURRENT-SOURCE-REVIEW-WAVE-30"
STATUS = (
    "CURRENT_MAIN_STRUCTURAL_ATOMICITY_GO_ALREADY_FIXED_CANDIDATE_"
    "EXACT_RUNTIME_GATE_REQUIRED_ZERO_FINDING_OR_COMPLETION_CREDIT"
)
GENERATED_AT = "2026-08-30T03:10:00+12:00"
MATERIALIZER = "generators/materialize-run-165-med-cd-atomicity-current-source-review-wave-30.py"
OUTPUT = "evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json"

APPLICATION_COMMIT = "03b9eca32308815eb0e93e81963daa3570bf3a86"
APPLICATION_TREE = "edffb0d10e6b519e91758da7ca9722e12611e94e"
REVIEWED_SOURCE_CHECKPOINT = "cf0090ec97242776eea30a2875756446f42862f9"
REVIEWED_SOURCE_TREE = "b1c932d1c5c19e9e2ea655da5964dd1c5e9c41f3"
HISTORICAL_APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
HISTORICAL_APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
EFFECTIVE_APPLICATION_COMMIT = "0b1920dade9251d617f3cb0b69da5c0202b5a6bf"
EFFECTIVE_APPLICATION_TREE = "7b2b5688c90e4da28725e70e38e50fd445f1b4c4"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
HISTORICAL_RECORD_SHA256 = "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1"
TEMPORARY_HARNESS = "tests/Feature/Emar/ControlledDrugAtomicityConcurrencyTest.php"
MATERIALIZER_REPOSITORY_PATH = f"{AUDIT_PREFIX}{MATERIALIZER}"
OUTPUT_REPOSITORY_PATH = f"{AUDIT_PREFIX}{OUTPUT}"
EXPECTED_DIRTY_PATHS = {MATERIALIZER_REPOSITORY_PATH, OUTPUT_REPOSITORY_PATH, TEMPORARY_HARNESS}
HARNESS_PIN = ("49bbc43ca9caa470e10992751f3e2b7080cde6cf6ff554994ce85e0956b5d807", "f87f011bd6441f3cafcfc1528378e21f180d6570", 31845, 715)

SOURCE_PINS = {
    "routes/emar.php": ("369d592aa532a988018d7b48f78d97f41500836762a662f8b714838b7dfeb8c9", "f7ea398d5cbfdeaadd7fdab41f417e26ac170ff7", 27108, 456),
    "app/Http/Controllers/Emar/EmarController.php": ("91047e8fa6068860fd133abf228fe0d3092d1c4a4296e58736cfd430686866d6", "c347d69a71b8bdf657129faba8df06d4212968b6", 434064, 8921),
    "app/Services/Medication/MedicationGovernanceScopeService.php": ("ef1733ea67ec8e1b9ece980151555120396145257552691f67909995e4089849", "434cd58b66d6948dc1897a285d41d21ff9059c5e", 64408, 1678),
    "app/Services/Medication/ControlledMedicationTransportWitnessService.php": ("88a10883bdd1e083c94c4b77ffdc284a84fbae08dc6cf90fb828754b6ac671a6", "d250d03b51262e81c231de7f3e41ea1862bff186", 19737, 530),
    "app/Http/Controllers/Concerns/HandlesMedicationSync.php": ("eb67f2f33d8c9358a37697f1db0c62e4b4f50388391481300890ee9093659e95", "d31f44b6498a4229414bd7f9b1d81c25a23cb89e", 7742, 241),
    "app/Models/ClientControlledDrugEntry.php": ("fff5a875dacd533a95b7cd7de9b70a0d637348bed4f177fb47f06120d99584a7", "d769023bc5caad9d126dbdfaa90165873ea53891", 1690, 76),
    "app/Models/ClientMedicationStock.php": ("ebd9831f8ebbffb2627588340e0bec602b9eb26de1c65cd0ab6a5b3d18884e1e", "a390a99acae25df8f063dd228e657a89a66c6951", 3523, 122),
    "app/Models/MedicationIdempotencyResult.php": ("c6af19f2e84b7d34e8ed22255187bcb54bfecddd33049fae968acb6982178cc9", "00ea562298357f812374c0bb44235d193cf4f2eb", 638, 32),
    "database/migrations/2026_08_14_230100_create_medication_idempotency_results.php": ("ffd71b636bd8f03a67e4fe083ef6ad7be8d4e1cf54a098167f00ea1484e876fd", "8a7c252b4fe12635262eb128ce0e995d43c7dce4", 1346, 40),
    "app/Services/EnhancedMarService.php": ("10e4d9b10957ef59e9ae2b052a2b9bd4c683c1fba17c2f31902bd1787ea4a001", "cb1fa641c3b4120555191b89dbf429e79797c492", 92132, 2212),
}

LINEAGE_PINS = {
    "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/materialize-run-164-audit-dashboard-verification-wave-29.py": ("5f9de09fed4dec440095497d21ebaa3e4ae91279899ca8d2e6bd0a7a0019e3ca", "a875985399e04edbbf175e106204d96d52f9edff", 41854, 954),
    "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json": ("d343a1fad55788da33be0745471258acbe9a5ca01739b03205a194fa279ed45b", "026c943bbe286b228e092a0ef0702b52d92827de", 22929, 542),
    "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/audit-dashboard.html": ("04fe2430810557f4fe61630f877efc7f827f6bcb1e265ac470ffd2bf277bcbbd", "6ddb0bd03425679e0e9c1f5748860cdcc6cd17b3", 253337, 78),
    "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-current-audit-dashboard.py": ("0c5ea8d8885ed21ca45fc1a54400757e87cb17d12d27fd6e9a298b8f427d1667", "7b1276d9c4d82af0c7aec7ced8c91cbdd824b5d3", 429489, 3843),
}


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(["git", *args], cwd=ROOT, check=check, capture_output=True)


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").rstrip("\r\n")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256_bytes(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8"))


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


def status_path(row: str) -> str:
    assert len(row) >= 4, row
    path = row[3:]
    if " -> " in path:
        path = path.split(" -> ", 1)[1]
    return path.replace("\\", "/")


def validate_repository_boundary() -> None:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == APPLICATION_COMMIT
    assert git("log", "-1", "--format=%T") == APPLICATION_TREE
    assert git("rev-parse", "main") == APPLICATION_COMMIT
    assert git("rev-parse", "origin/main") == APPLICATION_COMMIT
    assert git("log", "-1", "--format=%T", REVIEWED_SOURCE_CHECKPOINT) == REVIEWED_SOURCE_TREE
    assert all(
        path.replace("\\", "/").startswith(AUDIT_PREFIX)
        for path in git("diff", "--name-only", REVIEWED_SOURCE_CHECKPOINT, "HEAD", "--").splitlines()
    )
    assert git("log", "-1", "--format=%T", EFFECTIVE_APPLICATION_COMMIT) == EFFECTIVE_APPLICATION_TREE
    assert run_git("merge-base", "--is-ancestor", EFFECTIVE_APPLICATION_COMMIT, "HEAD", check=False).returncode == 0
    rows = [row for row in git("status", "--porcelain=v1", "--untracked-files=all").splitlines() if row]
    for row in rows:
        path = status_path(row)
        assert path in EXPECTED_DIRTY_PATHS, (row, path)
    for path in git("diff", "--name-only", "HEAD", "--").splitlines():
        assert path.replace("\\", "/") in EXPECTED_DIRTY_PATHS, path
    for path in git("diff", "--cached", "--name-only", "HEAD", "--").splitlines():
        assert path.replace("\\", "/") in EXPECTED_DIRTY_PATHS, path
    diff_check = run_git("diff", "--check", "HEAD", "--", check=False)
    assert diff_check.returncode == 0 and diff_check.stdout == b"" and diff_check.stderr == b""


def validate_source() -> tuple[dict[str, dict[str, Any]], dict[str, Any]]:
    observed: dict[str, dict[str, Any]] = {}
    for relative, expected in SOURCE_PINS.items():
        record = file_record(relative)
        assert tuple(record[key] for key in ("sha256", "blob_id", "bytes", "lines")) == expected, relative
        assert git("rev-parse", f"HEAD:{relative}") == record["blob_id"]
        observed[relative] = record
    lineage_records: dict[str, dict[str, Any]] = {}
    for relative, expected in LINEAGE_PINS.items():
        record = file_record(relative)
        assert tuple(record[key] for key in ("sha256", "blob_id", "bytes", "lines")) == expected, relative
        assert git("rev-parse", f"HEAD:{relative}") == record["blob_id"]
        lineage_records[relative] = record

    findings = strict_json(AUDIT / "findings.json")
    records = {row["id"]: row for row in findings["records"]}
    historical_record = records["MED-CD-ATOMICITY-01"]
    assert historical_record["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
    assert canonical_sha256(historical_record) == HISTORICAL_RECORD_SHA256
    assert findings["counts"]["provisional_source_claims"] == 10
    assert findings["counts"]["historical_already_fixed"] == 1
    assert findings["counts"]["historical_remediated"] == 1

    historical_controller = run_git("show", f"{HISTORICAL_APPLICATION_COMMIT}:app/Http/Controllers/Emar/EmarController.php").stdout.decode("utf-8")
    historical_slice = historical_controller[
        historical_controller.index("    public function storeCDEntry(Request $request)"):
        historical_controller.index("    public function storeBalanceCheck(Request $request)")
    ]
    assert "DB::transaction" not in historical_slice
    assert historical_slice.index("ClientControlledDrugEntry::create") < historical_slice.index("$stock->update")
    assert "rememberMedicationSyncResponse" in historical_slice

    current_controller = (ROOT / "app/Http/Controllers/Emar/EmarController.php").read_text(encoding="utf-8")
    current_entry_slice = current_controller[
        current_controller.index("    public function storeCDEntry(Request $request)"):
        current_controller.index("    public function storeBalanceCheck(Request $request)")
    ]
    for required in (
        "governanceScope->forMedication",
        "->lockForUpdate()",
        "ClientControlledDrugEntry::create",
        "$stock->update",
        "AuditLogger::logOrFail",
        "rememberIdempotencyResult",
    ):
        assert required in current_entry_slice
    assert current_entry_slice.index("->lockForUpdate()") < current_entry_slice.index("ClientControlledDrugEntry::create")
    assert current_entry_slice.index("ClientControlledDrugEntry::create") < current_entry_slice.index("$stock->update")
    assert current_entry_slice.index("$stock->update") < current_entry_slice.index("AuditLogger::logOrFail")
    assert current_entry_slice.index("AuditLogger::logOrFail") < current_entry_slice.index("rememberIdempotencyResult")

    scope_source = (ROOT / "app/Services/Medication/MedicationGovernanceScopeService.php").read_text(encoding="utf-8")
    medication_slice = scope_source[
        scope_source.index("    public function forMedication("):
        scope_source.index("    public function forStock(")
    ]
    assert "return DB::transaction" in medication_slice and "}, 3);" in medication_slice
    client_lock_index = medication_slice.index("Client::query()->whereKey($clientId)->lockForUpdate()")
    medication_lock_index = medication_slice.index("->where('client_id', $client->id)")
    assert client_lock_index < medication_lock_index < medication_slice.index("lockMutationActor")

    sink_rows = []
    for relative in ("app/Http/Controllers/Emar/EmarController.php", "app/Services/EnhancedMarService.php"):
        for line_number, line in enumerate((ROOT / relative).read_text(encoding="utf-8").splitlines(), 1):
            if "ClientControlledDrugEntry::create" in line:
                sink_rows.append(f"{relative}:{line_number}")
    assert sink_rows == [
        "app/Http/Controllers/Emar/EmarController.php:6450",
        "app/Http/Controllers/Emar/EmarController.php:7124",
        "app/Http/Controllers/Emar/EmarController.php:8189",
        "app/Http/Controllers/Emar/EmarController.php:8439",
        "app/Services/EnhancedMarService.php:2096",
    ]
    harness_record = file_record(TEMPORARY_HARNESS)
    assert tuple(harness_record[key] for key in ("sha256", "blob_id", "bytes", "lines")) == HARNESS_PIN
    materializer_record = file_record(MATERIALIZER_REPOSITORY_PATH)
    return observed, {
        "historical_record": historical_record,
        "sink_rows": sink_rows,
        "lineage_records": lineage_records,
        "temporary_harness_record": harness_record,
        "materializer_record": materializer_record,
    }


def reviewer(lane: str, role: str, verdict: str, coverage: list[str], limitations: list[str]) -> dict[str, Any]:
    value: dict[str, Any] = {
        "reviewer_lane": lane,
        "role": role,
        "verdict": verdict,
        "pinned_reviewed_source_checkpoint": REVIEWED_SOURCE_CHECKPOINT,
        "read_only": True,
        "application_writes": False,
        "runtime_credit": False,
        "browser_credit": False,
        "coverage": coverage,
        "limitations": limitations,
    }
    value["root_materialized_record_sha256"] = canonical_sha256(value)
    return value


def build_receipt(source_records: dict[str, dict[str, Any]], derived: dict[str, Any]) -> dict[str, Any]:
    reviewers = [
        reviewer(
            "/root/run163_builder_analysis",
            "historical/current controlled-drug writer and transaction-boundary reviewer",
            "STRUCTURAL_GO_ALREADY_FIXED_CANDIDATE_RUNTIME_REQUIRED",
            ["all five current controlled-drug entry creation sinks", "historical manual-entry nontransactional slice", "current forMedication transaction and Client-before-medication lock order", "current manual entry stock lock and write ordering"],
            ["source alone cannot prove operation-level concurrency", "adjacent global UUID and overdue-alert risks are outside this bounded claim"],
        ),
        reviewer(
            "/root/run163_artifact_review",
            "adversarial source and exact-evidence boundary reviewer",
            "STRUCTURAL_GO_RUNTIME_GATE_REQUIRED",
            ["historical versus current manual storeCDEntry behavior", "entry, stock, strict audit, and durable replay placement", "single-organisation multi-Site authorization boundary"],
            ["no balance-check, destruction, deadlock-retry, browser, benchmark, Pass, or completion transfer"],
        ),
        reviewer(
            "/root/run165_race_harness_critic",
            "race-design and current-source lock-order critic",
            "SOURCE_AND_HARNESS_DESIGN_GO_EXECUTION_NOT_CREDITED_IN_RUN165",
            ["canonical Client lock boundary", "stock stale-balance and durable replay source paths", "real HTTP-kernel two-process harness design"],
            ["RUN165 grants no runtime outcome", "execution belongs only to a later exact-byte receipt"],
        ),
    ]
    payload: dict[str, Any] = {
        "schema_version": "run-165-med-cd-atomicity-current-source-review-wave-30-v1",
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
            "reviewed_source_checkpoint": REVIEWED_SOURCE_CHECKPOINT,
            "reviewed_source_tree": REVIEWED_SOURCE_TREE,
            "main_commit": APPLICATION_COMMIT,
            "origin_main_commit": APPLICATION_COMMIT,
            "historical_audited_application_commit": HISTORICAL_APPLICATION_COMMIT,
            "historical_audited_application_tree": HISTORICAL_APPLICATION_TREE,
            "effective_application_commit": EFFECTIVE_APPLICATION_COMMIT,
            "effective_application_tree": EFFECTIVE_APPLICATION_TREE,
            "historical_med_cd_atomicity_record_canonical_sha256": HISTORICAL_RECORD_SHA256,
            "materializer": {"path": MATERIALIZER, **derived["materializer_record"]},
            "temporary_untracked_harness": {"path": TEMPORARY_HARNESS, **derived["temporary_harness_record"]},
            "source_files": source_records,
            "run164_lineage_files": derived["lineage_records"],
        },
        "review_process": {
            "reviewers": reviewers,
            "reviewer_lanes": 3,
            "coordinated_not_blind": True,
            "root_materialized_returned_reviews": True,
            "root_was_sole_writer_and_integrator": True,
        },
        "historical_source_condition": {
            "finding_id": "MED-CD-ATOMICITY-01",
            "feature_id": "CAP-MED-CD-REGISTER-BALANCE",
            "audited_at": HISTORICAL_APPLICATION_COMMIT,
            "manual_store_cd_entry_transaction": False,
            "entry_created_before_stock_update": True,
            "cache_replay_published_outside_durable_database_binding": True,
            "historical_source_issue_confirmed": True,
        },
        "current_source_condition": {
            "manual_route": "POST /emar/controlled/entries",
            "controller_action": "EmarController::storeCDEntry",
            "encompassing_retried_transaction": True,
            "transaction_attempts": 3,
            "lock_order": ["canonical Client", "canonical ClientMedication", "mutation actor and witness evidence", "ClientMedicationStock"],
            "inside_transaction": ["stale before-balance decision", "controlled-drug entry create", "stock update", "strict audit log", "durable request replay publication"],
            "current_creation_sink_census": derived["sink_rows"],
            "manual_entry_writer_commits_separately_from_stock_found": False,
            "source_disposition": "ALREADY_FIXED_CANDIDATE_RUNTIME_GATE_REQUIRED",
        },
        "compound_record_boundary": {
            "bounded_clause_adjudicated_here": "manual storeCDEntry register/stock atomicity only",
            "destruction_relationship_clause_adjudicated": False,
            "balance_check_adjudicated": False,
            "sibling_controlled_drug_writers_adjudicated": False,
            "historical_identity_must_be_preserved": True,
            "reporting_may_not_silently_close_residual_scope": True,
        },
        "required_next_gate": {
            "run": "RUN166",
            "requirement": "exact-byte attributable real HTTP-kernel two-process MySQL race covering same UUID same payload, same UUID different payload, and distinct UUID stale before-balance",
            "runtime_outcome_selected_by_run165": False,
        },
        "write_boundary": {
            "observed_changed_paths": sorted(EXPECTED_DIRTY_PATHS),
            "wrote_files": [OUTPUT],
            "materializer_runtime_writes_only_receipt": True,
            "materializer_did_not_write_itself": True,
            "materializer_did_not_write_temporary_harness": True,
            "application_files_written": [],
        },
        "credit_boundary": {
            "independent_current_source_review": True,
            "historical_claim_retirement_authorized": False,
            "runtime": False,
            "application_remediation": False,
            "final_finding": False,
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
    payload["artifact_completion_test_met"] = True
    payload["audit_completion_test_met"] = False
    payload["receipt_self_seal_sha256"] = canonical_sha256(payload)
    return payload


def validate_receipt(payload: dict[str, Any]) -> None:
    assert [key for key, value in payload["credit_boundary"].items() if value] == ["independent_current_source_review"]
    assert payload["write_boundary"]["wrote_files"] == [OUTPUT]
    assert payload["write_boundary"]["materializer_runtime_writes_only_receipt"] is True
    seal = payload["receipt_self_seal_sha256"]
    without_seal = dict(payload)
    del without_seal["receipt_self_seal_sha256"]
    assert seal == canonical_sha256(without_seal)


def main() -> None:
    validate_repository_boundary()
    source_records, derived = validate_source()
    payload = build_receipt(source_records, derived)
    validate_receipt(payload)
    output_path = AUDIT / OUTPUT
    output_bytes = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    output_path.parent.mkdir(parents=True, exist_ok=True)
    temporary = output_path.with_name(f".{output_path.name}.tmp-run165")
    assert not temporary.exists()
    try:
        temporary.write_bytes(output_bytes)
        assert temporary.read_bytes() == output_bytes
        temporary.replace(output_path)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert output_path.read_bytes() == output_bytes
    written = strict_json(output_path)
    assert written == payload
    validate_receipt(written)
    print(json.dumps({
        "run_id": RUN_ID,
        "status": STATUS,
        "output": OUTPUT,
        "sha256": sha256_bytes(output_bytes),
        "receipt_self_seal_sha256": payload["receipt_self_seal_sha256"],
        "runtime_credit": False,
        "audit_complete": False,
    }, sort_keys=True))


if __name__ == "__main__":
    main()
