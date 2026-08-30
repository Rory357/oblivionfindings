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
OUTPUT_REL = "evidence/source/current-run-167-med-cd-atomicity-reporting-wave-30.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL

REPORTING_INPUT_COMMIT = "47242053b960ae4af6c669ad24fa013497df0ae8"
REPORTING_INPUT_TREE = "65fe18971ff4e5b0475186b99001d96a22ae178b"
REPORTING_INPUT_PARENT = "bbd9b05b03da6d98deed033471412a05cc31d6d7"
REVIEWED_SOURCE_COMMIT = "cf0090ec97242776eea30a2875756446f42862f9"
REVIEWED_SOURCE_TREE = "b1c932d1c5c19e9e2ea655da5964dd1c5e9c41f3"
EFFECTIVE_APPLICATION_COMMIT = "0b1920dade9251d617f3cb0b69da5c0202b5a6bf"
EFFECTIVE_APPLICATION_TREE = "7b2b5688c90e4da28725e70e38e50fd445f1b4c4"
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
RUN_164_DASHBOARD_SHA256 = "04fe2430810557f4fe61630f877efc7f827f6bcb1e265ac470ffd2bf277bcbbd"
BASELINE_FINDINGS_SHA256 = "2e044b2d480890e4e1b60d71120103f602947821bc8745aa116037c90f97f645"
BASELINE_ATOMICITY_RECORD_SHA256 = "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1"
CURRENT_ATOMICITY_RECORD_SHA256 = "ebc201ff9af763264c037389ad51a71e07a5e82ad5aa72661fbd40a0dc370ee6"

ARTIFACT_PINS = {
    "generators/materialize-run-164-audit-dashboard-verification-wave-29.py": (
        "5f9de09fed4dec440095497d21ebaa3e4ae91279899ca8d2e6bd0a7a0019e3ca",
        "a875985399e04edbbf175e106204d96d52f9edff",
        41854,
        954,
    ),
    "evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json": (
        "d343a1fad55788da33be0745471258acbe9a5ca01739b03205a194fa279ed45b",
        "026c943bbe286b228e092a0ef0702b52d92827de",
        22929,
        542,
    ),
    "generators/materialize-run-165-med-cd-atomicity-current-source-review-wave-30.py": (
        "ccc8d49b793ca1980272f93ca77c870e29de72256100d4a5595cc114a4d010ea",
        "a8fec73f84b8e6c1208647839bdc47904db6758a",
        22581,
        419,
    ),
    "evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json": (
        "83257b5689f69885be2ed53bee8c0250b62d0e159f5b71dc0382282bb12a81c0",
        "07c446cbf89cf5fdcc435e2fa814e48a69a2e925",
        13240,
        288,
    ),
    "generators/materialize-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.py": (
        "662a8629a6ffda759e1d471b56230d1944ab2663f6f292cdf74604c22342a845",
        "34963e6c93d1fd3a975432c84bd8975a187efc19",
        30738,
        579,
    ),
    "evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json": (
        "c334495fa7cf7303ae70dccff475b7a9583b927bf06ee822f1a13bd347a84a46",
        "bed3743d2191c86d4526236b78bea896a0b60574",
        21939,
        485,
    ),
    "evidence/runtime/harnesses/run-166-controlled-drug-atomicity-concurrency-test.php.txt": (
        "49bbc43ca9caa470e10992751f3e2b7080cde6cf6ff554994ce85e0956b5d807",
        "f87f011bd6441f3cafcfc1528378e21f180d6570",
        31845,
        715,
    ),
    "generators/materialize-independent-run-166-med-cd-atomicity-adjudication-review-wave-30.py": (
        "c68a95e98c277eaab39c046aa0972c905e22ad4809108112194fdc84202989b1",
        "11c0becd82ef53e2819bdf60085c3e8eecb90829",
        19652,
        381,
    ),
    "evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json": (
        "fa899d56b13b584ccd103996c6a46407b105f968b0d769eae5be69a5f47b7d21",
        "b0c2c1d95ab0d5fa68285d80bae337912c2659f1",
        9134,
        195,
    ),
}

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

AUDIT_PREFIX = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/"
EXPECTED_DIRTY_WITHOUT_RECEIPT = sorted(
    [f"{AUDIT_PREFIX}{path}" for path in REPORTING_SURFACES]
    + [f"{AUDIT_PREFIX}{SCRIPT_REL}"]
)
EXPECTED_DIRTY_WITH_RECEIPT = sorted(
    EXPECTED_DIRTY_WITHOUT_RECEIPT + [f"{AUDIT_PREFIX}{OUTPUT_REL}"]
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
assert run_git("rev-parse", "main") == REPORTING_INPUT_COMMIT
assert run_git("rev-parse", "origin/main") == REPORTING_INPUT_COMMIT
assert run_git("diff", "--cached", "--name-only") == ""

dirty_lines = sorted(
    line
    for line in run_git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
    if line
)
dirty_names = sorted(line[3:] for line in dirty_lines)
assert dirty_names in (EXPECTED_DIRTY_WITHOUT_RECEIPT, EXPECTED_DIRTY_WITH_RECEIPT), dirty_lines
reporting_repository_paths = {f"{AUDIT_PREFIX}{path}" for path in REPORTING_SURFACES}
untracked_repository_paths = {f"{AUDIT_PREFIX}{SCRIPT_REL}", f"{AUDIT_PREFIX}{OUTPUT_REL}"}
assert all(line.startswith(" M ") for line in dirty_lines if line[3:] in reporting_repository_paths)
assert all(line.startswith("?? ") for line in dirty_lines if line[3:] in untracked_repository_paths)
assert run_git("diff", "--check") == ""

for relative in PRESERVED_PATHS:
    repository_relative = f"{AUDIT_PREFIX}{relative}"
    assert run_git("diff", "--name-only", "HEAD", "--", repository_relative) == "", relative
    assert run_git("status", "--porcelain=v1", "--untracked-files=all", "--", repository_relative) == "", relative

assert sha256_file("audit-dashboard.html") == RUN_164_DASHBOARD_SHA256

for path, (expected_sha256, expected_blob, expected_bytes, expected_lines) in ARTIFACT_PINS.items():
    metrics = text_file_metrics(path)
    assert metrics["sha256"] == expected_sha256, path
    assert metrics["git_blob_id"] == expected_blob, path
    assert metrics["bytes"] == expected_bytes, path
    assert metrics["lines"] == expected_lines, path

findings_repository_path = f"{AUDIT_PREFIX}findings.json"
baseline_findings_raw = git_show_bytes(REPORTING_INPUT_COMMIT, findings_repository_path)
assert sha256_bytes(baseline_findings_raw) == BASELINE_FINDINGS_SHA256
baseline_findings = strict_json_bytes(baseline_findings_raw, f"{REPORTING_INPUT_COMMIT}:findings.json")
current_findings = read_json("findings.json")

expected_changed_top_level_keys = [
    "audit_status",
    "generated_on",
    "pins",
    "denominators",
    "counts",
    "credit_boundary",
    "reconciliation",
    "records",
]
assert list(baseline_findings) == list(current_findings)
assert [
    key
    for key in baseline_findings
    if canonical_sha256(baseline_findings[key]) != canonical_sha256(current_findings[key])
] == expected_changed_top_level_keys

baseline_ids = [row["id"] for row in baseline_findings["records"]]
current_ids = [row["id"] for row in current_findings["records"]]
assert baseline_ids == current_ids
assert len(current_ids) == len(set(current_ids)) == 12
baseline_record_hashes = canonical_record_hashes(baseline_findings)
current_record_hashes = canonical_record_hashes(current_findings)
assert baseline_record_hashes["MED-CD-ATOMICITY-01"] == BASELINE_ATOMICITY_RECORD_SHA256
assert current_record_hashes["MED-CD-ATOMICITY-01"] == CURRENT_ATOMICITY_RECORD_SHA256
assert {
    finding_id: record_hash
    for finding_id, record_hash in current_record_hashes.items()
    if finding_id != "MED-CD-ATOMICITY-01"
} == {
    finding_id: record_hash
    for finding_id, record_hash in baseline_record_hashes.items()
    if finding_id != "MED-CD-ATOMICITY-01"
}

assert baseline_findings["audit_status"] == "TEN_PROVISIONAL_ONE_HISTORICAL_ALREADY_FIXED_ONE_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
assert current_findings["audit_status"] == "NINE_PROVISIONAL_TWO_HISTORICAL_ALREADY_FIXED_ONE_HISTORICAL_REMEDIATED_ZERO_FINAL_FINDING_CREDIT"
assert current_findings["generated_on"] == "2026-08-30"
assert current_findings["architecture_rule"] == "One operating organisation across multiple Sites; Site access, exact action permissions, ownership, consent and privacy are the boundaries."

pins = current_findings["pins"]
assert pins["governing_prompt_sha256"] == GOVERNING_PROMPT_SHA256
assert pins["audit_checkpoint_parent"] == "35a5228b26c54684718495c33281b24c0992de02"
assert pins["med_cd_atomicity_adjudicated_application_commit"] == REVIEWED_SOURCE_COMMIT
assert pins["med_cd_atomicity_adjudicated_application_tree"] == REVIEWED_SOURCE_TREE
assert pins["run_165_med_cd_atomicity_source_review_sha256"] == ARTIFACT_PINS["evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json"][0]
assert pins["run_166_med_cd_atomicity_adjudication_sha256"] == ARTIFACT_PINS["evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json"][0]
assert pins["run_166_atomicity_harness_snapshot_sha256"] == ARTIFACT_PINS["evidence/runtime/harnesses/run-166-controlled-drug-atomicity-concurrency-test.php.txt"][0]
assert pins["run_166r_independent_artifact_review_sha256"] == ARTIFACT_PINS["evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json"][0]
assert pins["run_166_repository_commit"] == REPORTING_INPUT_PARENT
assert pins["run_166_repository_tree"] == "f5e2f69d3ab02c42583daef8eb62f8732a12a584"

denominators = current_findings["denominators"]
assert denominators["historical_discovery_claim_records"] == 12
assert denominators["current_provisional_source_claims"] == 9
assert denominators["historical_already_fixed_records"] == 2
assert denominators["historical_remediated_records"] == 1

counts = current_findings["counts"]
assert counts["retained_claim_records"] == 12
assert counts["provisional_source_claims"] == counts["provisional_P1"] == 9
assert counts["historical_already_fixed"] == 2
assert counts["historical_remediated"] == 1
assert counts["final_P0"] == counts["final_P1"] == 0
assert counts["bounded_disposition_tests_passed"] == 78
assert counts["bounded_disposition_assertions"] == 1529
assert counts["bounded_disposition_sum_basis"] == "73/1481 MED-RBAC plus 5/48 focused MED-CD-SCOPE only; excludes RUN-166 MED-CD-ATOMICITY commands and every overlapping supporting execution"
assert counts["med_cd_atomicity_claim_specific_test_functions"] == 3
assert counts["med_cd_atomicity_claim_specific_assertions"] == 146
assert counts["med_cd_atomicity_race_subscenarios"] == 3
assert counts["med_cd_atomicity_supporting_tests"] == 43
assert counts["med_cd_atomicity_supporting_assertions"] == 716
assert counts["benchmark_mapped"] == 2
assert counts["final_no_match"] == 0
assert counts["benchmark_unresolved"] == 338

records_by_id = {row["id"]: row for row in current_findings["records"]}
assert sum(row["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING" for row in records_by_id.values()) == 9
assert sum(row["record_status"] == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING" for row in records_by_id.values()) == 2
assert sum(row["record_status"] == "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING" for row in records_by_id.values()) == 1
atomicity_record = records_by_id["MED-CD-ATOMICITY-01"]
assert atomicity_record["record_status"] == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
assert atomicity_record["historical_provenance"]["canonical_pre_adjudication_record_sha256"] == BASELINE_ATOMICITY_RECORD_SHA256
adjudication = atomicity_record["current_adjudication"]
assert adjudication["verdict"] == "ALREADY_FIXED"
assert adjudication["scope"] == "manual POST /emar/controlled/entries register and stock atomicity only"
assert adjudication["application_commit"] == REVIEWED_SOURCE_COMMIT
assert adjudication["application_tree"] == REVIEWED_SOURCE_TREE
assert adjudication["effective_application_commit"] == EFFECTIVE_APPLICATION_COMMIT
assert adjudication["effective_application_tree"] == EFFECTIVE_APPLICATION_TREE
assert adjudication["application_remediation_required"] is False
assert adjudication["application_source_changed_by_adjudication"] is False
assert adjudication["product_test_integrated_by_adjudication"] is False
assert adjudication["residual_compound_scope_inherited"] is False
assert atomicity_record["current_behaviour"]["runtime_observed"] is True
assert atomicity_record["evidence"]["test_functions_executed"] == 3
assert atomicity_record["evidence"]["assertions"] == 146
assert atomicity_record["evidence"]["race_subscenarios"] == 3
assert atomicity_record["evidence"]["supporting_tests_not_aggregated"] == 43
assert atomicity_record["evidence"]["supporting_assertions_not_aggregated"] == 716
assert atomicity_record["evidence"]["invalid_attempts_credit"] == 0
assert atomicity_record["residual_unadjudicated_scope"] == {
    "manual_store_cd_entry_register_stock_atomicity_adjudicated": True,
    "store_balance_check_adjudicated": False,
    "destruction_relationship_checks_adjudicated": False,
    "delivery_stock_adjustment_loss_report_or_sibling_writer_adjudicated": False,
    "forced_transient_deadlock_retry_adjudicated": False,
    "stress_or_repeated_schedule_adjudicated": False,
    "rollback_test_balance_check_half_grants_balance_check_credit": False,
    "supporting_43_test_716_assertion_overlap_grants_denominator_credit": False,
    "must_remain_explicit_without_inherited_credit": True,
}
assert all(row["completion_credit"] is False for row in records_by_id.values())
assert all(all(value is False for value in row["credit"].values()) for row in records_by_id.values())

run_164 = read_json("evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json")
run_165 = read_json("evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json")
run_166 = read_json("evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json")
run_166r = read_json("evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json")

assert run_164["run_id"] == "RUN-164-AUDIT-DASHBOARD-VERIFICATION-WAVE-29"
assert run_164["verification"]["viewports_verified"] == run_164["verification"]["viewports_required"] == 4
assert run_164["pins"]["dashboard_html"]["sha256"] == RUN_164_DASHBOARD_SHA256
assert {key for key, value in run_164["credit_boundary"].items() if value} == {
    "audit_dashboard_run_164_corrections",
    "exact_audit_dashboard_artifact",
}

assert run_165["run_id"] == "RUN-165-MED-CD-ATOMICITY-01-CURRENT-SOURCE-REVIEW-WAVE-30"
assert run_165["pins"]["reviewed_source_checkpoint"] == REVIEWED_SOURCE_COMMIT
assert run_165["pins"]["reviewed_source_tree"] == REVIEWED_SOURCE_TREE
assert run_165["compound_record_boundary"]["bounded_clause_adjudicated_here"] == "manual storeCDEntry register/stock atomicity only"
assert run_165["current_source_condition"]["source_disposition"] == "ALREADY_FIXED_CANDIDATE_RUNTIME_GATE_REQUIRED"
assert {key for key, value in run_165["credit_boundary"].items() if value} == {"independent_current_source_review"}

assert run_166["run_id"] == "RUN-166-MED-CD-ATOMICITY-01-ALREADY-FIXED-ADJUDICATION-WAVE-30"
assert run_166["historical_and_current_disposition"]["verdict"] == "ALREADY_FIXED"
assert run_166["historical_and_current_disposition"]["bounded_clause"] == "manual POST /emar/controlled/entries register and stock atomicity"
claim_totals = run_166["runtime_execution"]["claim_specific_totals"]
assert claim_totals["test_functions_passed"] == 3
assert claim_totals["assertions_across_command_outputs"] == 146
assert claim_totals["race_subscenarios"] == 3
assert run_166["runtime_execution"]["supporting_governance_command"]["tests_passed"] == 43
assert run_166["runtime_execution"]["supporting_governance_command"]["assertions"] == 716
assert run_166["runtime_execution"]["cleanup"]["matching_schema_count"] == 0
assert run_166["runtime_execution"]["cleanup"]["owned_php_processes"] == 0
assert run_166["runtime_execution"]["cleanup"]["owned_php_listeners"] == 0
assert run_166["runtime_execution"]["cleanup"]["owned_barrier_files"] == 0
assert run_166["compound_record_boundary"]["residual_scope_must_remain_explicit_after_reporting"] is True
assert {key for key, value in run_166["credit_boundary"].items() if value} == {
    "historical_condition_source_confirmed",
    "current_source_already_fixed_adjudication",
    "bounded_med_cd_atomicity_runtime_execution",
    "provisional_claim_retirement_authorized",
}

assert run_166r["run_id"] == "RUN-166R-INDEPENDENT-MED-CD-ATOMICITY-01-ADJUDICATION-REVIEW-WAVE-30"
assert run_166r["decision"]["verdict"] == "GO"
assert run_166r["decision"]["blocking_discrepancies"] == 0
assert run_166r["decision"]["retirement_reporting_authorized"] is True
assert run_166r["decision"]["authorized_reporting_status"] == atomicity_record["record_status"]
assert run_166r["decision"]["required_post_reporting_counts"]["provisional_source_claims"] == 9
assert run_166r["decision"]["required_post_reporting_counts"]["historical_already_fixed"] == 2
assert {key for key, value in run_166r["credit_boundary"].items() if value} == {"independent_exact_artifact_review_for_retirement_reporting"}

for payload, expected_seal in (
    (run_164, "0405b5be4c38f75b803da2776b975e816fa27cabe2483c2cafd5c6a04ce55c74"),
    (run_165, "8de90b3d923add5d4e1601561c9abb2b19e39b68aff79d74072b1edac1031212"),
    (run_166, "11f66f0c27fe4143a94451f140a8ae3a293617a6d1357f9180540a0641e05fea"),
    (run_166r, "4f4b301aaec50ad2c716cafd1c5c6516aab5a63561bb00746f91af6bd555ab67"),
):
    payload_without_seal = dict(payload)
    seal = payload_without_seal.pop("receipt_self_seal_sha256")
    assert canonical_sha256(payload_without_seal) == seal == expected_seal
    assert all(value is False for value in payload["completion_boundary"].values())

reporting_texts = {
    path: (AUDIT_DIR / path).read_text(encoding="utf-8")
    for path in REPORTING_SURFACES
    if path.endswith((".md", ".json"))
}
reporting_text = "\n".join(reporting_texts.values())
for prohibited in (
    "MED-CD-ATOMICITY-01 remains current provisional",
    "MED-CD-ATOMICITY-01</span> remains current provisional",
    "MED-CD-ATOMICITY-01 closed",
    "MED-CD-ATOMICITY-01 final finding",
    "RUN-166/R remediates MED-CD-ATOMICITY-01",
    "RUN-166R alone changes the live register",
):
    assert prohibited not in reporting_text
for required in (
    "9 current provisional",
    "2 historical already-fixed",
    "3 claim-specific test functions",
    "146 assertions",
    "43-test / 716-assertion",
    "RUN-167 alone",
    "fresh RUN-168",
    "balance-check",
    "sibling-writer",
    "forced transient-deadlock",
):
    assert required in reporting_text, required

assert "9 current provisional" in reporting_texts["00-executive-summary.md"]
assert "9 current provisional" in reporting_texts["01-repository-module-map.md"]
assert "9 current provisional" in reporting_texts["07-module-findings.md"]
assert "historical already-fixed P1 issue identity" in reporting_texts["11-prioritised-roadmap.md"]
assert "historical manual-entry source issue already fixed" in reporting_texts["12-native-build-and-do-not-copy-register.md"]
assert "RUN-167 alone changes the live arithmetic" in reporting_texts["13-unresolved-questions-and-evidence-gaps.md"]

builder_source = (AUDIT_DIR / "generators/build-current-audit-dashboard.py").read_text(encoding="utf-8")
compile(builder_source, str(AUDIT_DIR / "generators/build-current-audit-dashboard.py"), "exec")
assert 'read_json_strict("evidence/source/current-run-167-med-cd-atomicity-reporting-wave-30.json")' in builder_source
assert "RUN-167-MED-CD-ATOMICITY-01-ALREADY-FIXED-REPORTING-WAVE-30" in builder_source
assert "current-audit-dashboard-verification-run-168-wave-30.json" in builder_source
assert "Fresh RUN-168 audit-dashboard verification required" in builder_source
assert "9 current provisional P1 + 2 historical already-fixed + 1 historical remediated" in builder_source
assert "3 claim-specific test functions / 146 assertions / 3 synchronized two-process races" in builder_source

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
    "schema_version": "run-167-med-cd-atomicity-reporting-wave-30-v1",
    "run_id": "RUN-167-MED-CD-ATOMICITY-01-ALREADY-FIXED-REPORTING-WAVE-30",
    "status": "MED_CD_ATOMICITY_HISTORICAL_ALREADY_FIXED_REPORTING_MATERIALIZED_DASHBOARD_RUN168_REQUIRED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT",
    "materialized_on": "2026-08-30",
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
        "reviewed_source_commit": REVIEWED_SOURCE_COMMIT,
        "reviewed_source_tree": REVIEWED_SOURCE_TREE,
        "effective_application_commit": EFFECTIVE_APPLICATION_COMMIT,
        "effective_application_tree": EFFECTIVE_APPLICATION_TREE,
        "run_164_receipt_sha256": ARTIFACT_PINS["evidence/browser/current-audit-dashboard-verification-run-164-wave-29.json"][0],
        "run_165_receipt_sha256": ARTIFACT_PINS["evidence/source/current-run-165-med-cd-atomicity-current-source-review-wave-30.json"][0],
        "run_166_receipt_sha256": ARTIFACT_PINS["evidence/runtime/current-run-166-med-cd-atomicity-already-fixed-adjudication-wave-30.json"][0],
        "run_166_harness_sha256": ARTIFACT_PINS["evidence/runtime/harnesses/run-166-controlled-drug-atomicity-concurrency-test.php.txt"][0],
        "run_166r_receipt_sha256": ARTIFACT_PINS["evidence/runtime/current-run-166r-independent-med-cd-atomicity-adjudication-review-wave-30.json"][0],
        "reporting_materializer": text_file_metrics(SCRIPT_REL),
        "baseline_findings": {
            "sha256": sha256_bytes(baseline_findings_raw),
            "med_cd_atomicity_record_canonical_sha256": BASELINE_ATOMICITY_RECORD_SHA256,
        },
        "current_findings": text_file_metrics("findings.json"),
        "dashboard_generator": text_file_metrics("generators/build-current-audit-dashboard.py"),
        "unchanged_run_164_dashboard": text_file_metrics("audit-dashboard.html"),
    },
    "lineage_roles": {
        "run_164": "verifies only the exact now-superseded RUN-163 audit-dashboard artifact",
        "run_165": "establishes source-only bounded manual-entry already-fixed candidacy without runtime outcome",
        "run_166": "establishes the bounded manual-entry source/runtime ALREADY_FIXED disposition without application or product-test change",
        "run_166r": "independently authorizes retirement reporting for the bounded manual-entry clause only",
        "run_167": "alone changes the live findings register and human-readable reporting status",
        "run_168": "required fresh audit-dashboard rebuild and four-viewport verification",
    },
    "reporting_transition": {
        "finding_id": "MED-CD-ATOMICITY-01",
        "authorized_by_run_166r": True,
        "authorized_scope": "manual POST /emar/controlled/entries register and stock atomicity only",
        "status_before": "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING",
        "status_after": "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING",
        "counts_before": {
            "retained_claim_records": 12,
            "provisional_source_claims": 10,
            "historical_already_fixed": 1,
            "historical_remediated": 1,
            "final_P0": 0,
            "final_P1": 0,
        },
        "counts_after": {
            "retained_claim_records": 12,
            "provisional_source_claims": 9,
            "historical_already_fixed": 2,
            "historical_remediated": 1,
            "final_P0": 0,
            "final_P1": 0,
        },
        "claim_specific_runtime_denominator": {
            "test_functions": 3,
            "assertions": 146,
            "race_subscenarios": 3,
            "supporting_tests_not_aggregated": 43,
            "supporting_assertions_not_aggregated": 716,
            "added_to_existing_78_test_1529_assertion_total": False,
        },
        "unchanged_non_target_record_count": 11,
        "unchanged_non_target_record_hashes": {
            finding_id: current_record_hashes[finding_id]
            for finding_id in sorted(current_record_hashes)
            if finding_id != "MED-CD-ATOMICITY-01"
        },
        "baseline_target_record_canonical_sha256": BASELINE_ATOMICITY_RECORD_SHA256,
        "current_target_record_canonical_sha256": current_record_hashes["MED-CD-ATOMICITY-01"],
        "changed_top_level_findings_keys": expected_changed_top_level_keys,
        "reporting_surface_paths": REPORTING_SURFACES,
    },
    "residual_compound_scope": {
        "store_balance_check": "UNADJUDICATED",
        "destruction_relationship_checks": "UNADJUDICATED",
        "delivery_adjustment_loss_and_sibling_writers": "UNADJUDICATED",
        "forced_transient_deadlock_retry": "UNEXECUTED",
        "stress_or_repeated_schedules": "UNEXECUTED",
        "inherited_credit": False,
    },
    "reporting_manifest": reporting_manifest,
    "preservation_boundary": {
        "paths": preserved_manifest,
        "all_preserved_byte_identical_to_reporting_input_commit": True,
        "dashboard_sha256_unchanged": RUN_164_DASHBOARD_SHA256,
        "ownership_counts_664_307_357_95_unchanged": True,
        "queue_counts_118_reviewed_389_pending_unchanged": True,
        "benchmark_2_of_340_mapped_0_of_340_ncm_338_unresolved_unchanged": True,
    },
    "dashboard_forward_gate": {
        "required_run": "RUN-168",
        "dashboard_html_changed_by_run_167": False,
        "unchanged_dashboard_sha256": RUN_164_DASHBOARD_SHA256,
        "fresh_four_viewport_verification_required": True,
        "required_viewports": ["1440x900", "1280x800", "1024x768", "390x844"],
        "future_receipt_link_is_unhashed_to_avoid_cycle": True,
    },
    "publication_boundary": {
        "run_167_audit_artifact_push_or_publication_performed_by_materializer": False,
        "run_167_publication_claim": False,
    },
    "noninheritance_boundary": {
        "balance_check_or_destruction_or_sibling_writer": False,
        "forced_deadlock_retry_or_stress": False,
        "application_source_or_product_tests": False,
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
temporary_path = OUTPUT_PATH.with_name(f".{OUTPUT_PATH.name}.tmp-run167")
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
    "unchanged_non_target_records": written["reporting_transition"]["unchanged_non_target_record_count"],
    "dashboard_sha256_unchanged": RUN_164_DASHBOARD_SHA256,
}, ensure_ascii=False, sort_keys=True))
