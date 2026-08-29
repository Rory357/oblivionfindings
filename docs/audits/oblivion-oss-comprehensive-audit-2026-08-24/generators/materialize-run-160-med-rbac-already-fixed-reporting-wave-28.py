#!/usr/bin/env python3
"""Materialize the bounded RUN160 MED-RBAC already-fixed reporting receipt.

The eight reporting surfaces are deliberately pre-materialized and hash-pinned.
This program validates them and writes only its deterministic JSON receipt. It
does not rebuild the dashboard, run tests, start a browser, touch a database,
or mutate application source.
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

RUN_ID = "RUN-160-MED-RBAC-01-ALREADY-FIXED-REPORTING-WAVE-28"
STATUS = (
    "LIVE_REGISTER_RECONCILED_11_PROVISIONAL_PLUS_1_HISTORICAL_ALREADY_FIXED_"
    "73_TESTS_1481_ASSERTIONS_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
)
MATERIALIZER = "generators/materialize-run-160-med-rbac-already-fixed-reporting-wave-28.py"
OUTPUT = "evidence/source/current-run-160-med-rbac-already-fixed-reporting-wave-28.json"

CHECKPOINT_COMMIT = "bbf587870909d8f3f0ba4de89bb7a50eeab8a3e3"
CHECKPOINT_TREE = "3abec0f477db34e008ffa0afd150cb0abdc4a8d3"
CHECKPOINT_PARENT = "4f57ad4202df90ded375961437879822a908627b"
CURRENT_APPLICATION_COMMIT = "4f57ad4202df90ded375961437879822a908627b"
CURRENT_APPLICATION_TREE = "ee79b8d2733d09da2fd97992ac2a04e862159505"
HISTORICAL_AUDIT_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
HISTORICAL_AUDIT_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
GOVERNING_PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

SURFACES = (
    "findings.json",
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "07-module-findings.md",
    "11-prioritised-roadmap.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "generators/build-current-audit-dashboard.py",
)

BASELINE_OUTPUTS = {
    "findings.json": {"sha256": "acdbbeba11e342ba2f6feb6c16854a8b0f116d37a73b0f6f3eb768c7e5b1faf2", "blob_id": "7c7216aade53e1b4075bbcb3080319d69a172bdc", "bytes": 518270, "lines": 9610},
    "00-executive-summary.md": {"sha256": "1749878cddd5a57bda17a261b3f40065aea51c0a8b03a996d0d4016133f86a2d", "blob_id": "a8b41f6f06edb33848b296ef6f01fd2583bf68f6", "bytes": 108142, "lines": 474},
    "01-repository-module-map.md": {"sha256": "e31bf7b7edd2ddbb3f6f01e0f8e89c6437d23a83962aa5f817eabb4a22d1584b", "blob_id": "3afc1c6498cbe80922c44b6b8dadbfe898417460", "bytes": 31351, "lines": 212},
    "07-module-findings.md": {"sha256": "bfcbd82a9bb0286725171552865e6c16990955c69106b1b6ade39d32157de974", "blob_id": "a59e1c8bd547ee8a0413116af149d8c17365b30f", "bytes": 343737, "lines": 802},
    "11-prioritised-roadmap.md": {"sha256": "e5c2f41bf98d3415de97d18d853f1d7c351b337ba544fbf8c81330ec63dcf02d", "blob_id": "fd4890134271ebe7f8034d74a8c3dec3f8f3c6bc", "bytes": 10101, "lines": 59},
    "12-native-build-and-do-not-copy-register.md": {"sha256": "f9605259003663545015c185c0fda34351635faf46e4cffe427f4ce5e8158ac2", "blob_id": "866dbd1d722a9eb40135e0e58c4b29d309dc919c", "bytes": 113529, "lines": 499},
    "13-unresolved-questions-and-evidence-gaps.md": {"sha256": "d2bbc7824e99c99427f29668449692b8db0bea068805c289e1734f348672773e", "blob_id": "d2ad5b5d16f71dc4de5e7567033ca1bae8bb0344", "bytes": 27786, "lines": 85},
    "generators/build-current-audit-dashboard.py": {"sha256": "ad8da48d9308c7b0ce2df076e44f8d748d6e47132b9b02f4d98449434b8851f7", "blob_id": "2059768eed7430630063e3daa3213e942540ab6b", "bytes": 364303, "lines": 3145},
}

EXPECTED_OUTPUTS = {
    "findings.json": {"sha256": "fd27711496bb381b79ed6c42c7bbd4abedccdbd0d90f5059aab75075ea822b02", "blob_id": "fd69f61fbc01f927cd0e73ee1d1d39059b9c1254", "bytes": 523609, "lines": 9651},
    "00-executive-summary.md": {"sha256": "22defb3ef6738740d03d097c5b0f7c2f5cba74df387ad7b02bac00a9a8cae18f", "blob_id": "cb7bb0cb3806a81ea86328126f84f7a4ad30d990", "bytes": 111652, "lines": 484},
    "01-repository-module-map.md": {"sha256": "0304defc69c33481f1163f639f579fc283856f80446d0d5bfa570174e77fa4a7", "blob_id": "c82eeb84db39b9e17f4111c47b7ce078d727afbd", "bytes": 32538, "lines": 216},
    "07-module-findings.md": {"sha256": "2e9ef38f862b895d35040ed01a71fafd52b520b657a0406d00d20ed6b21b435a", "blob_id": "b21e0b92fff22b5faea0070052cde1e8d669b600", "bytes": 344896, "lines": 798},
    "11-prioritised-roadmap.md": {"sha256": "4080b931d24c755d33b7af4085023361b2961e8db26f1dc3480e4b29109157b6", "blob_id": "10aaee98d77b0cbba6647bebb9c630db55afa48b", "bytes": 10565, "lines": 62},
    "12-native-build-and-do-not-copy-register.md": {"sha256": "ae92657fb6914cbe9f447d59c29a1f9ca03bbfe2e3e54a744da6fa3040528b44", "blob_id": "85370a7b02e216c9591bbbc1c713b7fe0e5e53f8", "bytes": 113788, "lines": 499},
    "13-unresolved-questions-and-evidence-gaps.md": {"sha256": "0a5d005f450549f29f1a29b998841d6304546500eb7aaa67641f1756856352c3", "blob_id": "0325a6f708e0d5867faed9a0511178d1d3820216", "bytes": 29410, "lines": 87},
    "generators/build-current-audit-dashboard.py": {"sha256": "0d23faac294a3dc950788e0c8614c0b8473f9ddb960be2e9e13440626d91c865", "blob_id": "9b89784b41177a09405a8094cc7c507e6965d594", "bytes": 387187, "lines": 3417},
}

PRESERVED = {
    "audit-dashboard.html": {"sha256": "1b0747372d70254f9761177c151fb8dba38090d4a2fae919a0ed0ee91431e2b3", "blob_id": "6cec2d70cc6c4c7596e8d5f1c096b3419cbf8524", "bytes": 235542, "lines": 77},
    "02-eight-pass-coverage-ledger.csv": {"sha256": "ee4dc3126113884b4b8661dc3a3d13ac6a61b9661b2cace58fe82dcbe1d2a4a6", "blob_id": "15fa304f839cacd35005848f3841dd740d2cf4cc", "bytes": 29396, "lines": 39},
    "03-feature-to-benchmark-matrix.csv": {"sha256": "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0", "blob_id": "1f5fdab3ae80ae4ec1b9bc4ee47eef695bdd5416", "bytes": 557989, "lines": 341},
    "04-workflow-usability-scorecard.csv": {"sha256": "ea6879340229541c198b5ac654bde6d26d38eaefdd29ff66e1026263f9546faa", "blob_id": "b3afe88d2e9e62bbc2dcd3f1a5bf4af2fb82c4fa", "bytes": 855259, "lines": 301},
    "05-browser-visual-coverage-matrix.csv": {"sha256": "564224d295f8a2d3bad6001b74743fb0a1d75eb41315a817264307353b74dd84", "blob_id": "0bafaae0142327cfa6a6cdeda90b9163562fc654", "bytes": 8973775, "lines": 2813},
    "06-open-source-benchmark-register.csv": {"sha256": "5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884", "blob_id": "f96ef7ac1f6e19f614bdce8d663cfd08ec795995", "bytes": 350420, "lines": 99},
    "inventory.json": {"sha256": "46cd688dd9543b186a608e950754abe9e30389a792156719f8a999130dfca5fa", "blob_id": "4f7cca5ec277edeac52c553818e35545219711c5", "bytes": 2580297, "lines": 96004},
    "08-cross-module-journeys.md": {"sha256": "ef4471ba75ac9080e4565989e4b038bf7d0ad306cad1984019882457517c853c", "blob_id": "d48c9022d124186fe301a757e3ec8f9d86371031", "bytes": 53981, "lines": 309},
    "09-ui-ux-accessibility-visual-consistency.md": {"sha256": "27fa04e15cbd0eedb92514835884d0344db09f279a2295cea94ae0d1071a6e7c", "blob_id": "b919b27b7c64abd6faf9bdaa97783f6e64ac107d", "bytes": 9449, "lines": 123},
    "10-architecture-data-integration-security.md": {"sha256": "ca5667b1c042024f32f320254baf063dd4bcd2c4b12972cf2aac29c02d782b22", "blob_id": "8e78b5d3cb2467db37a8186ff07ab2c1608ee05d", "bytes": 18532, "lines": 191},
}

LINEAGE = {
    "generators/materialize-run-158-audit-dashboard-verification-wave-27.py": {"sha256": "e5d2bb3dd0a0cfd3db1f24ea859813c107b10767cf4e22f12aa8842d37103e49", "blob_id": "09cb906d08310313db61a2fef8c194bbf3a62f47", "bytes": 38017, "lines": 977},
    "evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json": {"sha256": "4b3cf785c5d9f4f0f0263b90ddc722818d1d8fdb4e9bf89bd44f1fec117752fb", "blob_id": "3268066ca3204e9c9d3233c2497ce88183b54d85", "bytes": 19841, "lines": 527},
    "generators/materialize-run-159-med-rbac-already-fixed-adjudication-wave-28.py": {"sha256": "cfd37697847c57a5e8116adb5836945daf21208fb00d0885abf7f3d594379ae7", "blob_id": "3f0965f58ea4855f76288d662616b0ad6b7d9964", "bytes": 23846, "lines": 472},
    "evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json": {"sha256": "bc666ded05774b03b849743436cec47cbdb260c8ab763cf502e71c804af7fd8e", "blob_id": "116664410ebeb4fa97ed93e7badbd7537c9a4b5d", "bytes": 17319, "lines": 379},
    "generators/materialize-independent-run-159-med-rbac-adjudication-review-wave-28.py": {"sha256": "bc1ef82dfe6459b726acf2567d6d976dbefb8cf869441e32eb0cb02c626a6b5e", "blob_id": "1d028d1f90876453e88d578ee9b70b06cc2fd311", "bytes": 10808, "lines": 249},
    "evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json": {"sha256": "be0651adf9edfbf7694ac535908cf43a5631675bcf6d5264add68fe947437d18", "blob_id": "531218a89947b42bf9137a0d588d29c617ee96f0", "bytes": 3368, "lines": 78},
}

PROVISIONAL_IDS = {
    "MED-CD-SCOPE-01", "MED-CD-ATOMICITY-01", "GOV-EXECUTIVE-VISIBILITY-01",
    "GOV-BOARD-PACK-VISIBILITY-01", "GOV-RESOLUTION-QUORUM-01",
    "HS-REGISTER-SITE-SCOPE-01", "PRIV-REPORT-DOMAIN-RBAC-01",
    "SAFE-INTAKE-CANONICAL-SCOPE-01", "SAFE-ALERT-DEDUP-IDENTITY-01",
    "SAFE-PROJECTION-DURABILITY-01", "SET-API-WEBHOOK-DESTINATION-01",
}
RECORD_ORDER = [
    "MED-RBAC-01", "MED-CD-SCOPE-01", "MED-CD-ATOMICITY-01",
    "GOV-EXECUTIVE-VISIBILITY-01", "GOV-BOARD-PACK-VISIBILITY-01",
    "GOV-RESOLUTION-QUORUM-01", "HS-REGISTER-SITE-SCOPE-01",
    "PRIV-REPORT-DOMAIN-RBAC-01", "SAFE-INTAKE-CANONICAL-SCOPE-01",
    "SAFE-ALERT-DEDUP-IDENTITY-01", "SAFE-PROJECTION-DURABILITY-01",
    "SET-API-WEBHOOK-DESTINATION-01",
]
BASE_RECORD_HASHES = {
    "MED-RBAC-01": "aa35c543ac25d15d074b344abd6ce8750975717f6c6e229d36986256c5a301ea",
    "MED-CD-SCOPE-01": "dd86bf94f3b4d894e95c56c95a9409ce803b8d82d108cdd3c42f3343e348cd21",
    "MED-CD-ATOMICITY-01": "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1",
    "GOV-EXECUTIVE-VISIBILITY-01": "316f7b85d61e16da4eeeb17c6a5b50a8ccdacbe4c443ec86370226268af4d175",
    "GOV-BOARD-PACK-VISIBILITY-01": "78292106d28b8ee8bf8e050aa89741d79b54522cff844a1b482c4b556c5c4c3f",
    "GOV-RESOLUTION-QUORUM-01": "eaf59bfe06b52f012c1a82bbb9a63139208f9840af7a84a26545bca8c81b30dd",
    "HS-REGISTER-SITE-SCOPE-01": "369da912ef9004ea3a7696280dcdf04051e6dca14087f0c6b185986ef1b9ec02",
    "PRIV-REPORT-DOMAIN-RBAC-01": "d0c2d60c324469933b989e4dfc1060c395521a9132c95b5939a231f3a34a2ac5",
    "SAFE-INTAKE-CANONICAL-SCOPE-01": "57e33e6c75f33ff2449e5504a7ee8fd6c3e22588d7eb373c2b36bdc5765ee42b",
    "SAFE-ALERT-DEDUP-IDENTITY-01": "360386fe1222c75437c2f6140f0860679f67c63f4fe1e95fe5e8bdcc985030a8",
    "SAFE-PROJECTION-DURABILITY-01": "6476e684b7ad18453a7dda24545353aefc5816eea537e4b0124df7c09bc71f1e",
    "SET-API-WEBHOOK-DESTINATION-01": "ad3ad1b1ca4f26020ee468f544506f2aa5c0fb2228ff5b908d1815680da12474",
}
CURRENT_MED_RBAC_RECORD_SHA256 = "3aeac2fd6d69cc84cae814773912eea1bcc9417c3daedb8f08d1ac7d959069cb"


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(["git", *args], cwd=ROOT, check=check, stdout=subprocess.PIPE, stderr=subprocess.PIPE)


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").strip()


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256_bytes(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8"))


def strict_json_bytes(payload: bytes, label: str) -> dict[str, Any]:
    def no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"{label}: duplicate key {key!r}"
            result[key] = value
        return result

    parsed = json.loads(payload.decode("utf-8"), object_pairs_hook=no_duplicates)
    assert isinstance(parsed, dict), f"{label}: top-level object required"
    return parsed


def strict_json(relative: str) -> dict[str, Any]:
    return strict_json_bytes((AUDIT / relative).read_bytes(), relative)


def metrics(payload: bytes) -> dict[str, Any]:
    header = b"blob " + str(len(payload)).encode("ascii") + b"\0"
    return {
        "sha256": sha256_bytes(payload),
        "blob_id": hashlib.sha1(header + payload).hexdigest(),
        "bytes": len(payload),
        "lines": len(payload.splitlines()),
    }


def assert_lf_file(path: Path) -> bytes:
    payload = path.read_bytes()
    assert payload.endswith(b"\n"), f"{path}: final LF required"
    assert b"\r\n" not in payload, f"{path}: CRLF forbidden"
    assert not payload.startswith(b"\xef\xbb\xbf"), f"{path}: BOM forbidden"
    assert all(line.rstrip(b" \t") == line for line in payload.splitlines()), f"{path}: trailing whitespace"
    return payload


def file_record(relative: str) -> dict[str, Any]:
    return metrics(assert_lf_file(AUDIT / relative))


def status_lines() -> set[str]:
    return set(run_git("status", "--porcelain=v1", "--untracked-files=all").stdout.decode("utf-8").splitlines())


def expected_status(include_receipt: bool) -> set[str]:
    expected = {f" M {PREFIX}/{relative}" for relative in SURFACES}
    expected.add(f"?? {PREFIX}/{MATERIALIZER}")
    if include_receipt:
        expected.add(f"?? {PREFIX}/{OUTPUT}")
    return expected


def validate_prewrite_status() -> None:
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", "HEAD^") == CHECKPOINT_PARENT
    assert git("rev-parse", "main") == git("rev-parse", "origin/main") == CHECKPOINT_COMMIT
    assert git("rev-parse", f"{CURRENT_APPLICATION_COMMIT}^{{tree}}") == CURRENT_APPLICATION_TREE
    assert git("rev-parse", f"{HISTORICAL_AUDIT_COMMIT}^{{tree}}") == HISTORICAL_AUDIT_TREE
    assert set(git("diff", "--name-only", "HEAD", "--").splitlines()) == {
        f"{PREFIX}/{relative}" for relative in SURFACES
    }
    assert run_git("diff", "--check").stdout == b""
    assert set(git("diff", "--name-only", f"{CURRENT_APPLICATION_COMMIT}..{CHECKPOINT_COMMIT}").splitlines()) == {
        f"{PREFIX}/generators/materialize-run-159-med-rbac-already-fixed-adjudication-wave-28.py",
        f"{PREFIX}/evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json",
        f"{PREFIX}/generators/materialize-independent-run-159-med-rbac-adjudication-review-wave-28.py",
        f"{PREFIX}/evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json",
    }
    output_exists = (AUDIT / OUTPUT).is_file()
    assert status_lines() == expected_status(include_receipt=output_exists)
    assert not list(AUDIT.rglob("__pycache__"))


def validate_surface_pins() -> dict[str, dict[str, Any]]:
    outputs: dict[str, dict[str, Any]] = {}
    for relative in SURFACES:
        repository_path = f"{PREFIX}/{relative}"
        baseline_blob = git("rev-parse", f"{CHECKPOINT_COMMIT}:{repository_path}")
        assert baseline_blob == BASELINE_OUTPUTS[relative]["blob_id"]
        baseline_payload = run_git("cat-file", "blob", baseline_blob).stdout
        assert metrics(baseline_payload) == BASELINE_OUTPUTS[relative]
        current = file_record(relative)
        assert current == EXPECTED_OUTPUTS[relative]
        outputs[relative] = {"baseline": BASELINE_OUTPUTS[relative], "current": current}
    for relative, expected in PRESERVED.items():
        assert file_record(relative) == expected
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == expected["blob_id"]
    return outputs


def validate_lineage() -> tuple[dict[str, Any], dict[str, Any], dict[str, Any]]:
    for relative, expected in LINEAGE.items():
        assert file_record(relative) == expected
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == expected["blob_id"]

    run158 = strict_json("evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json")
    assert run158["run_id"] == "RUN-158-AUDIT-DASHBOARD-VERIFICATION-WAVE-27"
    assert run158["status"] == "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT"
    verification = run158["verification"]
    assert verification["viewports_verified"] == verification["viewports_required"] == 4
    assert verification["exact_visible_static_boundary_check_count"] == 50
    assert verification["navigation_targets"] == "10/10"
    assert verification["post_materialization_local_resources"] == "387/387"
    assert verification["console_warnings"] == verification["console_errors"] == verification["page_errors"] == 0
    assert run158["pins"]["dashboard_html"] == PRESERVED["audit-dashboard.html"]
    assert [key for key, value in run158["credit_boundary"].items() if value] == ["exact_audit_dashboard_artifact"]
    assert all(value is False for value in run158["completion_boundary"].values())

    run159 = strict_json("evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json")
    assert run159["run_id"] == "RUN-159-MED-RBAC-01-ALREADY-FIXED-ADJUDICATION-WAVE-28"
    assert run159["status"] == "ALREADY_FIXED_UNANIMOUS_CURRENT_SOURCE_REVIEW_AND_BOUNDED_MYSQL_TESTS_HISTORICAL_CLAIM_RETIREMENT_AUTHORIZED_ZERO_FINAL_FINDING_OR_COMPLETION_CREDIT"
    assert run159["pins"]["governing_prompt_sha256"] == GOVERNING_PROMPT_SHA256
    assert run159["pins"]["application_commit"] == CURRENT_APPLICATION_COMMIT
    assert run159["pins"]["application_tree"] == CURRENT_APPLICATION_TREE
    assert run159["pins"]["main_commit"] == run159["pins"]["origin_main_after_fetch_prune"] == CURRENT_APPLICATION_COMMIT
    assert run159["review_process"]["independent_read_only_lanes"] == 3
    assert run159["review_process"]["unanimous_verdict"] == "ALREADY_FIXED"
    reviewers = run159["review_process"]["reviewers"]
    assert len(reviewers) == 3
    assert len({row["reviewer_lane"] for row in reviewers}) == 3
    assert all(row["verdict"] == "ALREADY_FIXED" for row in reviewers)
    assert all(row["current_source_scope"] == "STATIC_READ_ONLY_NO_TEST_OR_BROWSER_EXECUTION" for row in reviewers)
    assert all(row["writes"] is row["tests_executed"] is row["browser_executed"] is False for row in reviewers)
    assert all(row["cross_reviewer_coordination"] is row["other_reviewer_outputs_read"] is False for row in reviewers)
    disposition = run159["historical_and_current_disposition"]
    assert disposition["record_action_authorized"] == "RETIRE_PROVISIONAL_CURRENT_SOURCE_CLAIM_PRESERVE_HISTORICAL_IDENTITY"
    assert disposition["current_orders_manage_only_bypass_reproduced"] is False
    assert disposition["current_final_finding"] is False
    assert disposition["new_finding_id_required"] is False
    assert disposition["application_remediation_required"] is False
    assert disposition["application_source_changed_by_run_159"] is False
    totals = run159["runtime_execution"]["totals"]
    assert (totals["commands"], totals["tests_passed"], totals["tests_failed"], totals["assertions"], totals["duration_seconds"]) == (3, 73, 0, 1481, "450.72")
    database = run159["runtime_execution"]["database"]
    assert database["all_run159_schema_residue_absent"] is True
    assert database["post_run_effective_schema_prefix_match_count"] == database["post_run_configured_base_present"] == 0
    assert run159["runtime_execution"]["post_cleanup_php_processes"] == run159["runtime_execution"]["post_cleanup_php_listeners"] == 0
    assert run159["runtime_execution"]["browser_executed"] is False
    assert run159["bounded_acceptance"]["operation_level_concurrent_same_UUID_race"] == "NOT_EXECUTED_OR_CREDITED"
    assert run159["bounded_acceptance"]["representative_signed_in_application_browser"] == "NOT_EXECUTED_OR_CREDITED"
    assert run159["non_inherited_open_gaps"] == [
        "MED-CD-SCOPE-01 remains a separate provisional source claim",
        "MED-CD-ATOMICITY-01 remains a separate provisional source claim",
        "operation-level concurrent same-UUID same/different-payload races remain unexecuted",
        "full medication module and cross-module journeys remain unexecuted",
        "signed-in application browser, ease, benchmark, release, Pass, and audit completion remain open",
    ]
    assert [key for key, value in run159["credit_boundary"].items() if value] == [
        "historical_condition_source_confirmed", "current_source_already_fixed_adjudication",
        "bounded_med_rbac_test_execution", "provisional_current_source_claim_retirement_authorized",
    ]
    assert all(value is False for value in run159["completion_boundary"].values())

    run159r = strict_json("evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json")
    assert run159r["run_id"] == "RUN-159R-INDEPENDENT-MED-RBAC-01-ADJUDICATION-RECEIPT-REVIEW-WAVE-28"
    assert run159r["status"] == "GO_EXACT_RUN159_ARTIFACT_REVIEW_RETIREMENT_REPORTING_AUTHORIZED_ZERO_DOWNSTREAM_CREDIT"
    assert run159r["decision"]["verdict"] == "GO"
    assert run159r["decision"]["blocking_discrepancies"] == 0
    assert run159r["decision"]["retirement_reporting_authorized"] is True
    assert run159r["decision"]["application_remediation_authorized"] is False
    assert run159r["decision"]["final_finding_authorized"] is False
    assert run159r["decision"]["gate_4_complete"] is False
    assert run159r["decision"]["audit_complete"] is False
    assert run159r["pins"]["producer_receipt"]["sha256"] == LINEAGE["evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json"]["sha256"]
    assert [key for key, value in run159r["credit_boundary"].items() if value] == ["independent_exact_artifact_review_for_retirement_reporting"]
    return run158, run159, run159r


def validate_findings() -> tuple[dict[str, Any], dict[str, Any]]:
    findings = strict_json("findings.json")
    baseline_findings = strict_json_bytes(
        run_git("show", f"{CHECKPOINT_COMMIT}:{PREFIX}/findings.json").stdout,
        "baseline findings.json",
    )
    assert list(baseline_findings) == list(findings)
    changed_top_level_keys = {
        key for key in findings
        if canonical_sha256(findings[key]) != canonical_sha256(baseline_findings[key])
    }
    assert changed_top_level_keys == {
        "schema_version", "audit_status", "pins", "denominators", "counts",
        "credit_boundary", "reconciliation", "records",
    }
    assert findings["schema_version"] == "oblivion_audit_findings_v2_mixed_current_status"
    assert findings["audit_status"] == "ELEVEN_PROVISIONAL_ONE_HISTORICAL_ALREADY_FIXED_ZERO_FINAL_FINDING_CREDIT"
    assert findings["denominators"] == {
        "canonical_features": 340,
        "human_features": 300,
        "system_data_features": 40,
        "canonical_submodules": None,
        "historical_discovery_claim_records": 12,
        "current_provisional_source_claims": 11,
        "historical_already_fixed_records": 1,
    }
    assert findings["pins"]["application_commit"] == HISTORICAL_AUDIT_COMMIT
    assert findings["pins"]["application_tree"] == HISTORICAL_AUDIT_TREE
    assert findings["pins"]["current_adjudicated_application_commit"] == CURRENT_APPLICATION_COMMIT
    assert findings["pins"]["current_adjudicated_application_tree"] == CURRENT_APPLICATION_TREE
    assert findings["pins"]["run_159_med_rbac_adjudication_sha256"] == LINEAGE["evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json"]["sha256"]
    assert findings["pins"]["run_159r_independent_artifact_review_sha256"] == LINEAGE["evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json"]["sha256"]
    assert set(findings["pins"]) - set(baseline_findings["pins"]) == {
        "current_adjudicated_application_commit", "current_adjudicated_application_tree",
        "run_159_med_rbac_adjudication_sha256", "run_159r_independent_artifact_review_sha256",
    }
    assert all(
        findings["pins"][key] == value
        for key, value in baseline_findings["pins"].items()
    )
    counts = findings["counts"]
    baseline_counts = baseline_findings["counts"]
    assert baseline_counts["provisional_source_claims"] == baseline_counts["provisional_P1"] == 12
    assert set(counts) - set(baseline_counts) == {
        "retained_claim_records", "historical_already_fixed",
        "bounded_tests_executed", "bounded_test_assertions",
    }
    assert {
        key for key in baseline_counts if baseline_counts[key] != counts[key]
    } == {"provisional_source_claims", "provisional_P1"}
    assert (counts["retained_claim_records"], counts["provisional_source_claims"], counts["provisional_P1"], counts["historical_already_fixed"]) == (12, 11, 11, 1)
    assert (counts["bounded_tests_executed"], counts["bounded_test_assertions"]) == (73, 1481)
    assert counts["final_P0"] == counts["final_P1"] == 0
    assert counts["complete_prompt_finding_schema"] == counts["browser_observed"] == 0
    assert (counts["benchmark_mapped"], counts["final_no_match"], counts["benchmark_unresolved"]) == (2, 0, 338)
    assert (counts["static_source_feature_ownership_records"], counts["static_source_feature_ownership_route_records"], counts["static_source_feature_ownership_page_records"], counts["static_controller_action_bridges"]) == (664, 307, 357, 95)

    assert [row["id"] for row in baseline_findings["records"]] == RECORD_ORDER
    assert [row["id"] for row in findings["records"]] == RECORD_ORDER
    baseline_record_hashes = {
        row["id"]: canonical_sha256(row) for row in baseline_findings["records"]
    }
    current_record_hashes = {
        row["id"]: canonical_sha256(row) for row in findings["records"]
    }
    assert baseline_record_hashes == BASE_RECORD_HASHES
    assert current_record_hashes == {
        **BASE_RECORD_HASHES,
        "MED-RBAC-01": CURRENT_MED_RBAC_RECORD_SHA256,
    }
    records = {row["id"]: row for row in findings["records"]}
    assert len(records) == len(findings["records"]) == 12
    fixed = records["MED-RBAC-01"]
    assert fixed["record_status"] == "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING"
    assert fixed["pattern_implementation"]["status"] == "NOT_ADJUDICATED_FOR_THIS_RETAINED_HISTORICAL_RECORD"
    assert fixed["historical_provenance"]["application_commit"] == HISTORICAL_AUDIT_COMMIT
    assert fixed["historical_provenance"]["canonical_pre_adjudication_record_sha256"] == BASE_RECORD_HASHES["MED-RBAC-01"]
    assert fixed["current_adjudication"]["application_commit"] == CURRENT_APPLICATION_COMMIT
    assert fixed["current_adjudication"]["application_tree"] == CURRENT_APPLICATION_TREE
    assert fixed["current_adjudication"]["verdict"] == "ALREADY_FIXED"
    assert fixed["current_adjudication"]["application_remediation_required"] is False
    assert fixed["current_adjudication"]["separate_med_cd_scope_or_atomicity_inherited"] is False
    assert fixed["current_behaviour"]["runtime_observed"] is True
    assert fixed["evidence"]["tests_executed"] == 73 and fixed["evidence"]["assertions"] == 1481
    assert fixed["better_oblivion_design"]["status"] == "NOT_REQUIRED_ALREADY_FIXED_CURRENT_MAIN_NO_REMEDIATION_PROPOSED"
    assert {"emar.pharmacy_orders.store", "emar.pharmacy_orders.update", "emar.pharmacy_orders.advance"}.issubset(set(fixed["route_url"]["route_names"].split("; ")))

    provisional = {row["id"] for row in findings["records"] if row["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"}
    assert provisional == PROVISIONAL_IDS
    assert all(row["completion_credit"] is False for row in findings["records"])
    assert all(all(value is False for value in row["credit"].values()) for row in findings["records"])
    assert findings["reconciliation"]["retained_record_count"] == 12
    assert findings["reconciliation"]["current_provisional_count"] == 11
    assert findings["reconciliation"]["historical_already_fixed_count"] == 1
    return counts, fixed


def validate_reporting_surfaces() -> None:
    per_surface_tokens = {
        "00-executive-summary.md": ("RUN-160", "11 current provisional P1", "73 tests", "RUN-161"),
        "01-repository-module-map.md": ("RUN-160", "11 current provisional", "73", "MED-CD-SCOPE-01"),
        "07-module-findings.md": ("Historical frozen discovery pin", "Current MED-RBAC adjudication pin", "already fixed", "11 current provisional"),
        "11-prioritised-roadmap.md": ("11 current provisional claims", "Historical adjudication removed from the active queue", "MED-CD-SCOPE-01"),
        "12-native-build-and-do-not-copy-register.md": ("Claim record", "already fixed on current main", "no new native design or remediation proposed"),
        "13-unresolved-questions-and-evidence-gaps.md": ("Current mixed claim status", "73 bounded `MED-RBAC-01` tests", "11 current provisional", "RUN-161"),
    }
    for relative, tokens in per_surface_tokens.items():
        text = (AUDIT / relative).read_text(encoding="utf-8")
        for token in tokens:
            assert token in text, f"{relative}: missing {token!r}"

    builder = (AUDIT / "generators/build-current-audit-dashboard.py").read_text(encoding="utf-8")
    ast.parse(builder)
    builder_tokens = (
        'read_json_strict("findings.json")', "historical_fixed_findings", "len(provisional_findings)",
        '<a href="#checkpoint">RUN-160</a>', '<a href="#findings">Finding status</a>',
        "RUN-158–160 current adjudication checkpoint", "Fresh RUN-161 audit-dashboard verification required",
        "current-audit-dashboard-verification-run-161-wave-28.json", ".tmp-run160-dashboard",
        "$finding_count current provisional P1 + $historical_fixed_count historical already-fixed",
        "$bounded_tests tests / $bounded_assertions assertions",
        "RUN-159 establishes bounded current-source MED-RBAC test execution only",
        "all(value is False for value in row[\"credit\"].values())",
        "separate_med_cd_scope_or_atomicity_inherited", MATERIALIZER, OUTPUT,
    )
    for token in builder_tokens:
        assert token in builder, f"builder missing {token!r}"
    assert ".tmp-run157-dashboard" not in builder
    assert (AUDIT / "audit-dashboard.html").read_bytes() == run_git("cat-file", "blob", PRESERVED["audit-dashboard.html"]["blob_id"]).stdout


def build_receipt(
    outputs: dict[str, dict[str, Any]],
    run158: dict[str, Any],
    run159: dict[str, Any],
    run159r: dict[str, Any],
    counts: dict[str, Any],
    fixed: dict[str, Any],
) -> dict[str, Any]:
    false_credit = (
        "application_source_mutation", "application_remediation", "new_benchmark_mapping",
        "new_final_no_match_or_NCM", "final_finding", "final_P0", "final_P1",
        "application_browser", "responsive_application", "visual_or_workflow",
        "run_159_bounded_test_evidence_reexecuted_or_recredited", "runtime_execution_by_run_160",
        "database_execution_by_run_160", "executed_tests_by_run_160", "build",
        "full_test_suite", "test_coverage", "module_completion", "benchmark_mapping",
        "final_no_match_or_NCM", "origin_currency", "remote_currency", "publication_or_push",
        "current_source_review_recredit", "run_159r_exact_review_recredit",
        "ease", "release", "pass", "feature_completion", "gate_4", "completion", "audit_complete",
    )
    receipt: dict[str, Any] = {
        "schema_version": "run-160-med-rbac-already-fixed-reporting-wave-28-v1",
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-29",
        "scope": "REPORT_RUN159_MED_RBAC_ALREADY_FIXED_FACTS_AND_RECONCILE_LIVE_REGISTER_ONLY_NO_RUN160_EXECUTION_OR_RECREDIT",
        "architecture_boundary": {
            "operating_organisations": 1,
            "multiple_Sites": True,
            "tenant_authorization": False,
            "authorization_boundary": "roles, permissions, approved Sites, canonical ownership, direct-object denial, and privacy",
        },
        "pins": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "reporting_baseline_commit": CHECKPOINT_COMMIT,
            "reporting_baseline_tree": CHECKPOINT_TREE,
            "reporting_baseline_parent": CHECKPOINT_PARENT,
            "current_application_commit": CURRENT_APPLICATION_COMMIT,
            "current_application_tree": CURRENT_APPLICATION_TREE,
            "historical_audit_commit": HISTORICAL_AUDIT_COMMIT,
            "historical_audit_tree": HISTORICAL_AUDIT_TREE,
            "materializer": {"path": MATERIALIZER, **file_record(MATERIALIZER)},
            "committed_lineage": LINEAGE,
            "run_158_receipt_sha256": LINEAGE["evidence/browser/current-audit-dashboard-verification-run-158-wave-27.json"]["sha256"],
            "run_159_receipt_sha256": LINEAGE["evidence/runtime/current-run-159-med-rbac-already-fixed-adjudication-wave-28.json"]["sha256"],
            "run_159r_receipt_sha256": LINEAGE["evidence/runtime/current-run-159r-independent-med-rbac-adjudication-review-wave-28.json"]["sha256"],
        },
        "reporting_surfaces": {
            "changed": outputs,
            "count": len(SURFACES),
            "preserved": PRESERVED,
            "application_source_changed": False,
            "dashboard_builder_executed": False,
            "dashboard_html_changed": False,
        },
        "finding_register": {
            "retained_historical_claim_identities": counts["retained_claim_records"],
            "current_provisional_source_claims": counts["provisional_source_claims"],
            "current_provisional_P1": counts["provisional_P1"],
            "historical_already_fixed": counts["historical_already_fixed"],
            "historical_already_fixed_id": fixed["id"],
            "current_final_P0": counts["final_P0"],
            "current_final_P1": counts["final_P1"],
            "run_159_bounded_MED_RBAC_tests_reported": counts["bounded_tests_executed"],
            "run_159_bounded_MED_RBAC_assertions_reported": counts["bounded_test_assertions"],
            "tests_executed_or_recredited_by_run_160": False,
            "benchmark_mapped": counts["benchmark_mapped"],
            "final_no_match_or_NCM": counts["final_no_match"],
            "benchmark_unresolved": counts["benchmark_unresolved"],
        },
        "adjudication_boundary": {
            "finding_id": "MED-RBAC-01",
            "historical_condition_preserved": True,
            "current_verdict": run159["review_process"]["unanimous_verdict"],
            "independent_current_source_review_lanes": run159["review_process"]["independent_read_only_lanes"],
            "bounded_tests_passed": run159["runtime_execution"]["totals"]["tests_passed"],
            "bounded_assertions": run159["runtime_execution"]["totals"]["assertions"],
            "exact_receipt_review": run159r["decision"]["verdict"],
            "application_remediation_required": False,
            "application_source_changed": False,
            "current_orders_manage_only_bypass_reproduced": False,
            "current_final_finding": False,
        },
        "noninheritance_boundary": {
            "MED_CD_SCOPE_01_retired_or_credited": False,
            "MED_CD_ATOMICITY_01_retired_or_credited": False,
            "other_provisional_claims_promoted": False,
            "run_158_dashboard_proof_transferred_to_run_160": False,
            "bounded_MED_RBAC_tests_inherited_as_full_suite_or_coverage": False,
            "historical_P1_included_in_current_P1_or_final_counts": False,
        },
        "dashboard_forward_gate": {
            "run_158_exact_superseded_artifact_verified": run158["artifact_completion_test_met"],
            "run_160_dashboard_source_changed": True,
            "run_160_dashboard_html_materialized": False,
            "run_160_dashboard_browser_verified": False,
            "fresh_run_161_required": True,
            "required_viewports": ["1440x900", "1280x800", "1024x768", "390x844"],
        },
        "mutation_attestation": {
            "run_160_change_set_is_exactly_eight_pre_materialized_reporting_surfaces_plus_materializer_and_receipt": True,
            "materializer_runtime_writes_only_receipt": True,
            "application_source_changed": False,
            "matrix_or_benchmark_register_changed": False,
            "task_scripts_inventory_or_coverage_ledgers_changed": False,
            "dashboard_html_changed": False,
            "browser_server_php_database_or_external_system_started_by_run_160": False,
            "tests_or_coverage_executed_by_run_160": False,
        },
        "credit_boundary": {
            "AUDIT_REPORTING_REFRESH_FOR_MED_RBAC_ALREADY_FIXED_DISPOSITION": True,
            **{key: False for key in false_credit},
        },
        "completion_boundary": {
            key: False for key in (
                "framework_route_reachability_complete", "semantic_assurance_complete",
                "execution_complete", "coverage_complete", "benchmark_complete",
                "pass_8_complete", "final_reconciliation_complete", "no_live_agent_gate_complete",
                "full_crosswalk_complete", "gate_4_complete", "audit_complete",
            )
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{relative}" for relative in (*SURFACES, MATERIALIZER, OUTPUT)],
    }
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_receipt(expected: dict[str, Any]) -> None:
    actual = strict_json(OUTPUT)
    assert actual == expected
    seal = actual.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(actual)
    actual["receipt_self_seal_sha256"] = seal
    assert actual["pins"]["materializer"] == {"path": MATERIALIZER, **file_record(MATERIALIZER)}
    assert [key for key, value in actual["credit_boundary"].items() if value] == ["AUDIT_REPORTING_REFRESH_FOR_MED_RBAC_ALREADY_FIXED_DISPOSITION"]
    assert all(value is False for value in actual["completion_boundary"].values())
    assert status_lines() == expected_status(include_receipt=True)
    assert not list(AUDIT.rglob("__pycache__"))


def main() -> None:
    validate_prewrite_status()
    outputs = validate_surface_pins()
    validate_reporting_surfaces()
    run158, run159, run159r = validate_lineage()
    counts, fixed = validate_findings()
    receipt = build_receipt(outputs, run158, run159, run159r, counts, fixed)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    assert encoded.endswith(b"\n") and b"\r\n" not in encoded
    assert not encoded.startswith(b"\xef\xbb\xbf")
    assert all(line.rstrip(b" \t") == line for line in encoded.splitlines())
    (AUDIT / OUTPUT).write_bytes(encoded)
    assert (AUDIT / OUTPUT).read_bytes() == encoded
    validate_receipt(receipt)
    print(json.dumps({
        "status": STATUS,
        "schema_version": receipt["schema_version"],
        "materializer_sha256": receipt["pins"]["materializer"]["sha256"],
        "receipt_sha256": sha256_bytes(encoded),
        "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
        "retained_records": 12,
        "current_provisional": 11,
        "historical_already_fixed": 1,
        "bounded_tests": 73,
        "bounded_assertions": 1481,
        "fresh_run_161_required": True,
        "gate_4_complete": False,
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
