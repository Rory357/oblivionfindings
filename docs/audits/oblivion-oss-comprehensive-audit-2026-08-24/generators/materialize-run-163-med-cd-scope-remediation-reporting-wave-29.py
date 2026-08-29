from __future__ import annotations

import hashlib
import json
import os
import subprocess
from pathlib import Path
from typing import Any


SCRIPT_PATH = Path(__file__).resolve()
AUDIT_DIR = SCRIPT_PATH.parent.parent
REPO_ROOT = next(parent for parent in SCRIPT_PATH.parents if (parent / ".git").exists())
SCRIPT_REL = SCRIPT_PATH.relative_to(AUDIT_DIR).as_posix()
OUTPUT_REL = "evidence/source/current-run-163-med-cd-scope-remediation-reporting-wave-29.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL

REPORTING_INPUT_COMMIT = "adc80d437781bc5f2f4a3f072e86b51fb10a1c7d"
REPORTING_INPUT_TREE = "8519736eab3386008563bd5fc7786941ab2d21f2"
REPORTING_INPUT_PARENT = "0b1920dade9251d617f3cb0b69da5c0202b5a6bf"
APPLICATION_COMMIT = REPORTING_INPUT_PARENT
APPLICATION_TREE = "7b2b5688c90e4da28725e70e38e50fd445f1b4c4"
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
RUN_161_RECEIPT_SHA256 = "dc62fe1a6242dc42e0f9f75b278a0fbf042a667279ca3a4fdabb279d361613e3"
RUN_162_RECEIPT_SHA256 = "21564caa435927d89d994a091383409e627c44170304f6ff2a5d5c897c858958"
RUN_162R_RECEIPT_SHA256 = "7a1decaccfde2246163daef3dbec285b6a5a1a5019d2411615cc7e003660ff78"
RUN_161_DASHBOARD_SHA256 = "c27d0535885c68984b96bf1fbbb91f65f303a8ed8b9255742df9d8f0788370b3"
BASELINE_SCOPE_RECORD_SHA256 = "dd86bf94f3b4d894e95c56c95a9409ce803b8d82d108cdd3c42f3343e348cd21"

REPORTING_SURFACES = [
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
    "generators/build-current-audit-dashboard.py",
]

PRESERVED_PATHS = [
    "02-eight-pass-coverage-ledger.csv",
    "03-feature-to-benchmark-matrix.csv",
    "04-workflow-usability-scorecard.csv",
    "05-browser-visual-coverage-matrix.csv",
    "06-open-source-benchmark-register.csv",
    "08-cross-module-journeys.md",
    "09-ui-ux-accessibility-visual-consistency.md",
    "10-architecture-data-integration-security.md",
    "inventory.json",
    "task-scripts",
    "audit-dashboard.html",
]

EXPECTED_DIRTY_WITHOUT_RECEIPT = sorted(
    [f"docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/{path}" for path in REPORTING_SURFACES]
    + [f"docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/{SCRIPT_REL}"]
)
EXPECTED_DIRTY_WITH_RECEIPT = sorted(
    EXPECTED_DIRTY_WITHOUT_RECEIPT
    + [f"docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/{OUTPUT_REL}"]
)


def duplicate_rejecting_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    result: dict[str, Any] = {}
    for key, value in pairs:
        if key in result:
            raise AssertionError(f"Duplicate JSON key: {key}")
        result[key] = value
    return result


def strict_json_bytes(raw: bytes, label: str) -> dict[str, Any]:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CRLF not allowed: {label}"
    assert raw.endswith(b"\n"), f"Final LF required: {label}"
    for index, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace at {label}:{index}"
    value = json.loads(raw.decode("utf-8"), object_pairs_hook=duplicate_rejecting_pairs)
    assert isinstance(value, dict), f"JSON object required: {label}"
    expected = (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert raw == expected, f"Exact pretty-JSON round trip failed: {label}"
    return value


def read_json(relative: str) -> dict[str, Any]:
    return strict_json_bytes((AUDIT_DIR / relative).read_bytes(), relative)


def git_show_bytes(commit: str, repository_relative: str) -> bytes:
    result = subprocess.run(
        ["git", "show", f"{commit}:{repository_relative}"],
        cwd=REPO_ROOT,
        check=True,
        capture_output=True,
    )
    return result.stdout


def run_git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=REPO_ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.rstrip()


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def canonical_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return sha256_bytes(raw)


def git_blob_id(relative: str) -> str:
    repository_relative = (AUDIT_DIR / relative).relative_to(REPO_ROOT).as_posix()
    return run_git("hash-object", "--", repository_relative)


def text_file_metrics(relative: str) -> dict[str, Any]:
    raw = (AUDIT_DIR / relative).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {relative}"
    assert b"\r" not in raw, f"CRLF not allowed: {relative}"
    assert raw.endswith(b"\n"), f"Final LF required: {relative}"
    for index, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"Trailing whitespace at {relative}:{index}"
    return {
        "path": relative,
        "sha256": sha256_bytes(raw),
        "git_blob_id": git_blob_id(relative),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def canonical_record_hashes(payload: dict[str, Any]) -> dict[str, str]:
    return {row["id"]: canonical_sha256(row) for row in payload["records"]}


assert run_git("rev-parse", "HEAD") == REPORTING_INPUT_COMMIT
assert run_git("rev-parse", "HEAD^{tree}") == REPORTING_INPUT_TREE
assert run_git("rev-parse", "HEAD^") == REPORTING_INPUT_PARENT
assert run_git("diff", "--cached", "--name-only") == ""

dirty_paths = sorted(line for line in run_git("status", "--porcelain=v1", "--untracked-files=all").splitlines() if line)
dirty_names = sorted(line[3:] for line in dirty_paths)
assert dirty_names in (EXPECTED_DIRTY_WITHOUT_RECEIPT, EXPECTED_DIRTY_WITH_RECEIPT), dirty_paths
assert all(line.startswith(" M ") for line in dirty_paths if line[3:] in {
    f"docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/{path}" for path in REPORTING_SURFACES
})
assert all(line.startswith("?? ") for line in dirty_paths if line[3:] in {
    f"docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/{SCRIPT_REL}",
    f"docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/{OUTPUT_REL}",
})
assert run_git("diff", "--check") == ""

for relative in PRESERVED_PATHS:
    repository_relative = f"docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/{relative}"
    assert run_git("diff", "--name-only", "HEAD", "--", repository_relative) == "", relative
    assert run_git("status", "--porcelain=v1", "--untracked-files=all", "--", repository_relative) == "", relative

assert sha256_file("audit-dashboard.html") == RUN_161_DASHBOARD_SHA256

baseline_findings_path = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/findings.json"
baseline_findings_raw = git_show_bytes(REPORTING_INPUT_COMMIT, baseline_findings_path)
baseline_findings = strict_json_bytes(baseline_findings_raw, f"{REPORTING_INPUT_COMMIT}:findings.json")
current_findings = read_json("findings.json")
baseline_record_hashes = canonical_record_hashes(baseline_findings)
current_record_hashes = canonical_record_hashes(current_findings)
assert set(baseline_record_hashes) == set(current_record_hashes)
assert baseline_record_hashes["MED-CD-SCOPE-01"] == BASELINE_SCOPE_RECORD_SHA256
assert current_record_hashes["MED-CD-SCOPE-01"] != BASELINE_SCOPE_RECORD_SHA256
assert {
    finding_id: record_hash
    for finding_id, record_hash in current_record_hashes.items()
    if finding_id != "MED-CD-SCOPE-01"
} == {
    finding_id: record_hash
    for finding_id, record_hash in baseline_record_hashes.items()
    if finding_id != "MED-CD-SCOPE-01"
}

assert baseline_findings["audit_status"] == "ELEVEN_PROVISIONAL_ONE_HISTORICAL_ALREADY_FIXED_ZERO_FINAL_FINDING_CREDIT"
assert current_findings["audit_status"] == "TEN_PROVISIONAL_ONE_HISTORICAL_ALREADY_FIXED_ONE_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
assert current_findings["architecture_rule"] == "One operating organisation across multiple Sites; Site access, exact action permissions, ownership, consent and privacy are the boundaries."
assert current_findings["counts"]["retained_claim_records"] == 12
assert current_findings["counts"]["provisional_source_claims"] == 10
assert current_findings["counts"]["historical_already_fixed"] == 1
assert current_findings["counts"]["historical_remediated"] == 1
assert current_findings["counts"]["bounded_disposition_tests_passed"] == 78
assert current_findings["counts"]["bounded_disposition_assertions"] == 1529
assert current_findings["counts"]["bounded_disposition_sum_basis"] == "73/1481 MED-RBAC plus 5/48 focused MED-CD-SCOPE; excludes the overlapping broader RUN-162 execution"
assert current_findings["counts"]["med_rbac_bounded_tests"] == 73
assert current_findings["counts"]["med_rbac_bounded_test_assertions"] == 1481
assert current_findings["counts"]["med_cd_scope_focused_tests"] == 5
assert current_findings["counts"]["med_cd_scope_focused_test_assertions"] == 48
assert current_findings["counts"]["final_P0"] == current_findings["counts"]["final_P1"] == 0
assert current_findings["counts"]["benchmark_mapped"] == 2
assert current_findings["counts"]["final_no_match"] == 0
assert current_findings["counts"]["benchmark_unresolved"] == 338

records_by_id = {row["id"]: row for row in current_findings["records"]}
assert len(records_by_id) == len(current_findings["records"]) == 12
assert sum(row["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING" for row in records_by_id.values()) == 10
assert sum(row["record_status"] == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING" for row in records_by_id.values()) == 1
assert sum(row["record_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING" for row in records_by_id.values()) == 1
scope_record = records_by_id["MED-CD-SCOPE-01"]
assert scope_record["record_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
assert scope_record["historical_provenance"]["canonical_pre_adjudication_record_sha256"] == BASELINE_SCOPE_RECORD_SHA256
assert scope_record["current_adjudication"]["verdict"] == "REPRODUCED_AND_REMEDIATED"
assert scope_record["current_adjudication"]["application_commit"] == APPLICATION_COMMIT
assert scope_record["current_adjudication"]["repository_tree"] == APPLICATION_TREE
assert scope_record["current_adjudication"]["separate_med_cd_atomicity_inherited"] is False
assert records_by_id["MED-CD-ATOMICITY-01"]["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
assert all(row["completion_credit"] is False for row in records_by_id.values())
assert all(all(value is False for value in row["credit"].values()) for row in records_by_id.values())

run_161 = read_json("evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json")
run_162 = read_json("evidence/runtime/current-run-162-med-cd-scope-remediation-wave-29.json")
run_162r = read_json("evidence/runtime/current-run-162r-independent-med-cd-scope-remediation-review-wave-29.json")
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-161-wave-28.json") == RUN_161_RECEIPT_SHA256
assert sha256_file("evidence/runtime/current-run-162-med-cd-scope-remediation-wave-29.json") == RUN_162_RECEIPT_SHA256
assert sha256_file("evidence/runtime/current-run-162r-independent-med-cd-scope-remediation-review-wave-29.json") == RUN_162R_RECEIPT_SHA256
assert run_161["verification"]["viewports_verified"] == run_161["verification"]["viewports_required"] == 4
assert run_161["pins"]["dashboard_html"]["sha256"] == RUN_161_DASHBOARD_SHA256
assert run_162["pins"]["application_commit"] == APPLICATION_COMMIT
assert run_162["pins"]["repository_tree_at_application_commit"] == APPLICATION_TREE
assert run_162["issue_first_disposition"]["current_main_genuine_related_defects_reproduced_before_fix"] == 5
assert run_162["runtime_execution"]["advanced_main_focused_command"]["tests"] == 5
assert run_162["runtime_execution"]["advanced_main_focused_command"]["assertions"] == 48
assert run_162["runtime_execution"]["broader_bounded_execution"]["directly_related_controller_and_command_tests_passed"] == 102
assert run_162["runtime_execution"]["broader_bounded_execution"]["combined_passed"] == 108
assert run_162["runtime_execution"]["broader_bounded_execution"]["combined_assertions"] == 1454
assert run_162["runtime_execution"]["broader_bounded_execution"]["combined_failed"] == 2
assert run_162["runtime_execution"]["broader_bounded_execution"]["baseline_replay_at_base_commit"]["classification"] == "BASE_REPRODUCED_FAILURES_NOT_ATTRIBUTED_TO_RUN162_FULL_SUITE_GREEN_FALSE"
assert run_162["credit_boundary"]["finding_retirement_reporting"] is False
assert run_162r["decision"]["verdict"] == "GO"
assert run_162r["decision"]["blocking_discrepancies"] == 0
assert run_162r["decision"]["retirement_reporting_authorized"] is True
assert run_162r["decision"]["authorized_reporting_status"] == scope_record["record_status"]
assert run_162r["decision"]["authorized_live_count_delta"] == {
    "retained_claim_records": 0,
    "current_provisional_source_claims": -1,
    "historical_already_fixed_records": 0,
    "historical_remediated_records": 1,
    "final_P0": 0,
    "final_P1": 0,
    "benchmark_mapped": 0,
    "final_no_match_or_NCM": 0,
    "benchmark_unresolved": 0,
}

run_162_without_seal = dict(run_162)
run_162_seal = run_162_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_162_without_seal) == run_162_seal
run_162r_without_seal = dict(run_162r)
run_162r_seal = run_162r_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(run_162r_without_seal) == run_162r_seal

reporting_text = "\n".join((AUDIT_DIR / path).read_text(encoding="utf-8") for path in REPORTING_SURFACES if path.endswith((".md", ".json")))
for prohibited in (
    "RUN-162/R retires MED-CD-SCOPE-01",
    "RUN-162/R closes MED-CD-SCOPE-01",
    "RUN-162/R remediates MED-CD-SCOPE-01",
    "MED-CD-SCOPE-01 closed",
    "MED-CD-SCOPE-01 final finding",
):
    assert prohibited not in reporting_text
assert "RUN-162 establishes" in reporting_text
assert "RUN-162R independently authorizes" in reporting_text
assert "RUN-163 alone" in reporting_text
assert "10 current provisional" in reporting_text
assert "1 historical already-fixed" in reporting_text or "one historical already-fixed" in reporting_text
assert "1 historical remediated" in reporting_text or "one historical remediated" in reporting_text
assert "fresh RUN-164" in reporting_text or "Fresh RUN-164" in reporting_text

builder_source = (AUDIT_DIR / "generators/build-current-audit-dashboard.py").read_text(encoding="utf-8")
compile(builder_source, str(AUDIT_DIR / "generators/build-current-audit-dashboard.py"), "exec")
assert "current-audit-dashboard-verification-run-164-wave-29.json" in builder_source
assert "read_json_strict(\"evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json\")" not in builder_source

reporting_manifest = [text_file_metrics(path) for path in REPORTING_SURFACES]
preserved_manifest = []
for path in PRESERVED_PATHS:
    target = AUDIT_DIR / path
    if target.is_file():
        preserved_manifest.append(text_file_metrics(path))
    else:
        repository_relative = target.relative_to(REPO_ROOT).as_posix()
        preserved_manifest.append({
            "path": path,
            "git_tree_id": run_git("rev-parse", f"HEAD:{repository_relative}"),
            "working_tree_diff": "NONE",
            "untracked_entries": 0,
        })

receipt: dict[str, Any] = {
    "schema_version": "run-163-med-cd-scope-remediation-reporting-wave-29-v1",
    "run_id": "RUN-163-MED-CD-SCOPE-01-REMEDIATION-REPORTING-WAVE-29",
    "status": "MED_CD_SCOPE_HISTORICAL_REMEDIATION_REPORTING_MATERIALIZED_DASHBOARD_RUN164_REQUIRED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT",
    "materialized_on": "2026-08-29",
    "architecture_rule": {
        "operating_organisations": 1,
        "multiple_sites": True,
        "multi_tenant": False,
        "authorization_boundary": "Site access, exact action permissions, canonical ownership, consent, privacy, and direct-object denial",
    },
    "pins": {
        "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
        "reporting_input_commit": REPORTING_INPUT_COMMIT,
        "reporting_input_tree": REPORTING_INPUT_TREE,
        "reporting_input_parent": REPORTING_INPUT_PARENT,
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "run_161_receipt_sha256": RUN_161_RECEIPT_SHA256,
        "run_162_receipt_sha256": RUN_162_RECEIPT_SHA256,
        "run_162r_receipt_sha256": RUN_162R_RECEIPT_SHA256,
        "reporting_materializer": text_file_metrics(SCRIPT_REL),
        "baseline_findings": {
            "sha256": sha256_bytes(baseline_findings_raw),
            "med_cd_scope_record_canonical_sha256": BASELINE_SCOPE_RECORD_SHA256,
        },
        "current_findings": text_file_metrics("findings.json"),
        "dashboard_generator": text_file_metrics("generators/build-current-audit-dashboard.py"),
        "unchanged_run_161_dashboard": text_file_metrics("audit-dashboard.html"),
    },
    "lineage_roles": {
        "run_161": "verifies only the exact RUN-160 audit-dashboard artifact",
        "run_162": "establishes MED-CD-SCOPE reproduction, narrow application/test remediation, bounded runtime, integration, and application-commit publication",
        "run_162r": "independently authorizes retirement reporting only",
        "run_163": "alone changes the live findings register and reporting status",
        "run_164": "required fresh audit-dashboard rebuild and four-viewport verification",
    },
    "reporting_transition": {
        "finding_id": "MED-CD-SCOPE-01",
        "authorized_by_run_162r": True,
        "status_before": "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING",
        "status_after": "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING",
        "counts_before": {
            "retained_claim_records": 12,
            "provisional_source_claims": 11,
            "historical_already_fixed": 1,
            "historical_remediated": 0,
            "final_P0": 0,
            "final_P1": 0,
        },
        "counts_after": {
            "retained_claim_records": 12,
            "provisional_source_claims": 10,
            "historical_already_fixed": 1,
            "historical_remediated": 1,
            "final_P0": 0,
            "final_P1": 0,
        },
        "unchanged_non_target_record_count": 11,
        "unchanged_non_target_record_hashes": {
            finding_id: current_record_hashes[finding_id]
            for finding_id in sorted(current_record_hashes)
            if finding_id != "MED-CD-SCOPE-01"
        },
        "baseline_target_record_canonical_sha256": BASELINE_SCOPE_RECORD_SHA256,
        "current_target_record_canonical_sha256": current_record_hashes["MED-CD-SCOPE-01"],
        "reporting_surface_paths": REPORTING_SURFACES,
    },
    "claim_specific_runtime_accounting": {
        "med_rbac": {"tests": 73, "assertions": 1481, "run": "RUN-159"},
        "med_cd_scope_focused": {"tests": 5, "assertions": 48, "run": "RUN-162"},
        "reconciliation_arithmetic_only": {"tests": 78, "assertions": 1529},
        "overlapping_broader_run_162": {
            "directly_related_controller_and_command_tests_passed": 102,
            "combined_passed": 108,
            "combined_assertions": 1454,
            "combined_failed": 2,
            "base_reproduced_failures_not_attributed_to_run_162": True,
            "full_suite_green": False,
        },
    },
    "reporting_manifest": reporting_manifest,
    "preservation_boundary": {
        "paths": preserved_manifest,
        "all_preserved_byte_identical_to_reporting_input_commit": True,
        "ownership_counts_664_307_357_95_unchanged": True,
        "queue_counts_118_reviewed_389_pending_unchanged": True,
        "benchmark_2_of_340_mapped_0_of_340_ncm_338_unresolved_unchanged": True,
    },
    "dashboard_forward_gate": {
        "required_run": "RUN-164",
        "dashboard_html_changed_by_run_163": False,
        "unchanged_dashboard_sha256": RUN_161_DASHBOARD_SHA256,
        "fresh_four_viewport_verification_required": True,
        "required_viewports": ["1440x900", "1280x800", "1024x768", "390x844"],
        "future_receipt_link_is_unhashed_to_avoid_cycle": True,
    },
    "publication_boundary": {
        "run_162_application_commit_publication_observed": True,
        "run_163_audit_artifact_push_or_publication_performed_by_materializer": False,
        "run_163_publication_claim": False,
    },
    "noninheritance_boundary": {
        "med_cd_atomicity_transaction_retry_rollback_lock_order_fractional_or_operation_concurrency": False,
        "application_browser": False,
        "full_suite_or_coverage": False,
        "benchmark_or_final_no_match_NCM": False,
        "final_finding": False,
        "feature_module_or_pass_completion": False,
    },
    "credit_boundary": {
        "live_findings_register_and_reporting_status": True,
        "application_source_or_tests": False,
        "application_runtime_reexecution": False,
        "application_browser": False,
        "benchmark_mapping": False,
        "final_no_match_or_NCM": False,
        "ease": False,
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
    "wrote_files": [OUTPUT_REL],
}
receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
output_bytes = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")

OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
temporary_path = OUTPUT_PATH.with_name(f".{OUTPUT_PATH.name}.tmp-run163")
assert not temporary_path.exists(), f"Refusing stale temp file: {temporary_path}"
try:
    with temporary_path.open("xb") as handle:
        handle.write(output_bytes)
        handle.flush()
        os.fsync(handle.fileno())
    assert temporary_path.read_bytes() == output_bytes
    os.replace(temporary_path, OUTPUT_PATH)
finally:
    if temporary_path.exists():
        temporary_path.unlink()

assert OUTPUT_PATH.read_bytes() == output_bytes
written = strict_json_bytes(OUTPUT_PATH.read_bytes(), OUTPUT_REL)
written_without_seal = dict(written)
written_seal = written_without_seal.pop("receipt_self_seal_sha256")
assert canonical_sha256(written_without_seal) == written_seal
print(json.dumps({
    "run_id": written["run_id"],
    "status": written["status"],
    "receipt_sha256": sha256_bytes(output_bytes),
    "receipt_self_seal_sha256": written_seal,
    "reporting_surfaces": len(REPORTING_SURFACES),
    "dashboard_sha256_unchanged": RUN_161_DASHBOARD_SHA256,
}, ensure_ascii=False, sort_keys=True))
