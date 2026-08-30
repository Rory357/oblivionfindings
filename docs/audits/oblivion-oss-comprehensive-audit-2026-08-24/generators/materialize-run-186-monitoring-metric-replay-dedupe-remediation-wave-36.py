#!/usr/bin/env python3
"""Materialize bounded RUN186 Monitoring metric-replay remediation evidence.

This producer records already-completed source adjudication, the initial
remediation and post-merge NO-GO, the isolated corrective remediation, local
integration, and the final bounded verification. It does not run PHP, touch a
database, start a browser, mutate application source, publish commits, change
live reporting, or adjudicate static ownership or feature identity.
"""
from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


SCRIPT = Path(__file__).resolve()
AUDIT = SCRIPT.parent.parent
ROOT = next(parent for parent in SCRIPT.parents if (parent / ".git").exists())
PREFIX = AUDIT.relative_to(ROOT).as_posix()
SCRIPT_REL = SCRIPT.relative_to(AUDIT).as_posix()
OUTPUT_REL = (
    "evidence/runtime/"
    "current-run-186-monitoring-metric-replay-dedupe-remediation-wave-36.json"
)
OUTPUT = AUDIT / OUTPUT_REL
REVIEW_SCRIPT_REL = (
    "generators/materialize-independent-run-186-monitoring-metric-replay-"
    "dedupe-remediation-review-wave-36.py"
)
REVIEW_OUTPUT_REL = (
    "evidence/runtime/current-run-186r-independent-monitoring-metric-replay-"
    "dedupe-remediation-review-wave-36.json"
)

RUN_ID = "RUN-186-MON-METRIC-REPLAY-DEDUPE-01-REMEDIATION-WAVE-36"
STATUS = (
    "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_AFTER_CORRECTIVE_NO_GO_"
    "NOT_FINAL_FINDING_BOUNDED_VERIFIED_NOT_PUBLISHED_LIVE_REPORTING_NOT_YET_"
    "AUTHORIZED_ZERO_STATIC_OWNERSHIP_OR_COMPLETION_CREDIT"
)
RECORD_STATUS = (
    "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING"
)
GOVERNING_PROMPT_SHA256 = (
    "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
)
CONTINUATION_PROMPT_SHA256 = (
    "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
)

BASE = "a900f078c9c05f587f6f7884f5fe715076891416"
BASE_TREE = "852126934a18a1364244a35f7789263779e47485"
INITIAL_FIX = "f521bc0b87222e56b4822e7cb9c935486e279e76"
INITIAL_FIX_TREE = "7a1862f2aab2844ca568061d3f9ee78201026cbd"
ADVANCED_MAIN = "badd86d566f3354e455b92f12ab683ce6d29c965"
ADVANCED_MAIN_TREE = "cdeba8f19c278fcaf11c6dd0b26ff7814bc1aed9"
INITIAL_MERGE = "778c00a5d09511aee1a836a689d7bb1b56ce4ff6"
INITIAL_MERGE_TREE = "e66c50a3b514967eec70e4774a312bff376bb66a"
CORRECTIVE_FIX = "c82f57779baf623c4e94ac4619b11c1b675d0230"
CORRECTIVE_FIX_TREE = "095cd7b1940988be334979af22008c635fdcaf58"
CORRECTIVE_MERGE = "18652d545c788f1dcdbe57662e5b1e5472d6cae7"
CORRECTIVE_MERGE_TREE = "095cd7b1940988be334979af22008c635fdcaf58"
DNS_FIX = "d5efdf7782a7cd81f78bc282d684e884db001b6c"
DNS_FIX_TREE = "15d2d624429b914047a423c36c26b5744fcd5048"
CURRENT_MAIN = "f938c6d989f5fef052f08b9f1012116fb5cf2f69"
CURRENT_MAIN_TREE = "70b2339300278bc0c20e32ed091f74b442bea76d"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
INITIAL_PATCH_ID = "16e3886ad0985b4af853d34ede90e2b5e273af51"
CORRECTIVE_PATCH_ID = "18c4df4897f2562e5c797f7de4fb075b607de24b"
DNS_PATCH_ID = "c3902efb3266fa859487de20265f315c6f9401ed"

FINDING_ID = "MON-METRIC-REPLAY-DEDUPE-01"
CANDIDATE_FEATURE_ID = None
FEATURE_IDENTITY_STATUS = "UNASSIGNED_PENDING_FRESH_SEMANTIC_REVIEW"
FINDINGS = f"{PREFIX}/findings.json"

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
INITIAL_NAME_STATUS = [
    f"M\t{INITIAL_PATHS[0]}",
    f"A\t{INITIAL_PATHS[1]}",
    f"M\t{INITIAL_PATHS[2]}",
    f"M\t{INITIAL_PATHS[3]}",
    f"M\t{INITIAL_PATHS[4]}",
    f"M\t{INITIAL_PATHS[5]}",
    f"A\t{INITIAL_PATHS[6]}",
    f"M\t{INITIAL_PATHS[7]}",
    f"M\t{INITIAL_PATHS[8]}",
]
INITIAL_NUMSTAT = [
    f"15\t0\t{INITIAL_PATHS[0]}",
    f"41\t0\t{INITIAL_PATHS[1]}",
    f"44\t4\t{INITIAL_PATHS[2]}",
    f"109\t67\t{INITIAL_PATHS[3]}",
    f"6\t7\t{INITIAL_PATHS[4]}",
    f"132\t10\t{INITIAL_PATHS[5]}",
    f"493\t0\t{INITIAL_PATHS[6]}",
    f"776\t4\t{INITIAL_PATHS[7]}",
    f"119\t0\t{INITIAL_PATHS[8]}",
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
CORRECTIVE_NAME_STATUS = [
    f"M\t{CORRECTIVE_PATHS[0]}",
    f"M\t{CORRECTIVE_PATHS[1]}",
    f"M\t{CORRECTIVE_PATHS[2]}",
    f"M\t{CORRECTIVE_PATHS[3]}",
    f"M\t{CORRECTIVE_PATHS[4]}",
    f"A\t{CORRECTIVE_PATHS[5]}",
    f"M\t{CORRECTIVE_PATHS[6]}",
]
CORRECTIVE_NUMSTAT = [
    f"2\t0\t{CORRECTIVE_PATHS[0]}",
    f"2\t0\t{CORRECTIVE_PATHS[1]}",
    f"2\t0\t{CORRECTIVE_PATHS[2]}",
    f"24\t2\t{CORRECTIVE_PATHS[3]}",
    f"173\t18\t{CORRECTIVE_PATHS[4]}",
    f"134\t0\t{CORRECTIVE_PATHS[5]}",
    f"708\t45\t{CORRECTIVE_PATHS[6]}",
]

DNS_PATHS = [
    "app/Domain/Monitoring/Transports/NativeDnsTransport.php",
    "tests/Unit/Monitoring/NativeDnsTransportTest.php",
]
DNS_NAME_STATUS = [f"M\t{DNS_PATHS[0]}", f"A\t{DNS_PATHS[1]}"]
DNS_NUMSTAT = [f"278\t57\t{DNS_PATHS[0]}", f"430\t0\t{DNS_PATHS[1]}"]

FINAL_ISSUE_PATHS = list(dict.fromkeys(INITIAL_PATHS + CORRECTIVE_PATHS))
CORRECTIVE_SET = set(CORRECTIVE_PATHS)
INITIAL_ONLY_PATHS = [path for path in INITIAL_PATHS if path not in CORRECTIVE_SET]

COMPLETION_GATE_NAMES = [
    "routes_classified",
    "inertia_pages_classified",
    "features_in_canonical_register",
    "routes_and_pages_mapped_to_feature_id",
    "features_with_verified_benchmark_or_final_ncm",
    "human_features_with_task_script_and_ten_scores",
    "common_and_safety_journeys_cross_reviewed",
    "hero_banner_instances_classified",
    "overlay_implementations_and_triggers_classified",
    "safe_routes_observed_at_desktop",
    "selected_families_and_journeys_all_viewports",
    "required_visual_states_classified",
    "material_visual_finding_families_resampled",
    "models_classified",
    "policies_classified",
    "service_domain_entries_classified",
    "critical_async_owners_classified",
    "modules_with_all_eight_passes",
    "prompt_benchmark_projects_formally_triaged",
    "p0_p1_complete_finding_fields",
    "redesigns_neutral_native_no_copy",
    "ease_4_5_claims_independently_reviewed",
    "browser_claims_labeled",
    "visual_inconsistencies_complete_context",
    "official_source_inference_specialist_split",
    "all_agents_returned_reconciled_represented_none_live",
]

EXPECTED_DIRTY = {
    f"{PREFIX}/{SCRIPT_REL}",
    f"{PREFIX}/{OUTPUT_REL}",
}
ALLOWED_DIRTY = EXPECTED_DIRTY | {
    f"{PREFIX}/{REVIEW_SCRIPT_REL}",
    f"{PREFIX}/{REVIEW_OUTPUT_REL}",
}


def git(*args: str) -> str:
    result = subprocess.run(
        ["git", *args],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    return result.stdout.rstrip()


def git_bytes(revision: str, relative: str) -> bytes:
    return subprocess.run(
        ["git", "show", f"{revision}:{relative}"],
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout


def git_object_exists(specification: str) -> bool:
    return (
        subprocess.run(
            ["git", "cat-file", "-e", specification],
            cwd=ROOT,
            capture_output=True,
        ).returncode
        == 0
    )


def git_is_ancestor(ancestor: str, descendant: str) -> bool:
    return (
        subprocess.run(
            ["git", "merge-base", "--is-ancestor", ancestor, descendant],
            cwd=ROOT,
            capture_output=True,
        ).returncode
        == 0
    )


def stable_patch_id(before: str, after: str) -> str:
    diff = subprocess.run(
        ["git", "diff", before, after],
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout
    result = subprocess.run(
        ["git", "patch-id", "--stable"],
        cwd=ROOT,
        check=True,
        input=diff,
        capture_output=True,
    ).stdout.decode("ascii")
    return result.split()[0]


def sha256(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical_sha256(value: Any) -> str:
    return sha256(
        json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def strict_text(raw: bytes, label: str) -> None:
    assert not raw.startswith(b"\xef\xbb\xbf"), f"BOM not allowed: {label}"
    assert b"\r" not in raw, f"CR not allowed: {label}"
    assert raw.endswith(b"\n"), f"final LF required: {label}"
    for number, line in enumerate(raw.splitlines(), start=1):
        assert line == line.rstrip(b" \t"), f"trailing whitespace: {label}:{number}"


def strict_json(raw: bytes, label: str) -> dict[str, Any]:
    strict_text(raw, label)

    def no_duplicates(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key in {label}: {key}"
            result[key] = value
        return result

    value = json.loads(raw.decode("utf-8"), object_pairs_hook=no_duplicates)
    assert isinstance(value, dict)
    assert (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8") == raw
    return value


def file_record(relative: str, revision: str | None = None) -> dict[str, Any]:
    raw = git_bytes(revision, relative) if revision else (ROOT / relative).read_bytes()
    strict_text(raw, f"{revision or 'working'}:{relative}")
    return {
        "path": relative,
        "sha256": sha256(raw),
        "git_blob_id": (
            git("rev-parse", f"{revision}:{relative}")
            if revision
            else git("hash-object", "--", relative)
        ),
        "bytes": len(raw),
        "lines": raw.count(b"\n"),
    }


def revision_records(paths: list[str], revision: str) -> list[dict[str, Any]]:
    return [file_record(path, revision) for path in paths]


def validate_findings_snapshot() -> dict[str, Any]:
    raw = git_bytes(CURRENT_MAIN, FINDINGS)
    findings = strict_json(raw, f"{CURRENT_MAIN}:{FINDINGS}")
    records = findings["records"]
    record_ids = [record["id"] for record in records]
    statuses = Counter(record["record_status"] for record in records)
    counts = findings["counts"]
    reconciliation = findings["reconciliation"]

    assert len(records) == len(record_ids) == len(set(record_ids)) == 14
    assert FINDING_ID not in record_ids
    assert statuses == {
        "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING": 8,
        "HISTORICAL_SOURCE_ISSUE_ALREADY_FIXED_CURRENT_MAIN_NOT_FINAL_FINDING": 2,
        "HISTORICAL_SOURCE_ISSUE_REMEDIATED_CURRENT_MAIN_NOT_FINAL_FINDING": 4,
    }
    assert counts["retained_claim_records"] == 14
    assert counts["provisional_source_claims"] == 8
    assert counts["historical_already_fixed"] == 2
    assert counts["historical_remediated"] == 4
    assert counts["bounded_disposition_tests_passed"] == 99
    assert counts["bounded_disposition_assertions"] == 1931
    assert counts["static_source_feature_ownership_records"] == 666
    assert counts["static_source_feature_ownership_route_records"] == 309
    assert counts["static_source_feature_ownership_page_records"] == 357
    assert counts["static_controller_action_bridges"] == 97
    assert counts["direct_exact_queue_records"] == 507
    assert counts["direct_exact_queue_reviewed"] == 120
    assert counts["direct_exact_queue_pending_unreviewed"] == 387
    assert counts["direct_exact_queue_owned"] == 98
    assert counts["direct_exact_queue_without_ownership"] == 409
    assert counts["benchmark_mapped"] == 2
    assert counts["final_no_match"] == 0
    assert counts["benchmark_unresolved"] == 338
    assert reconciliation["retained_record_ids_unique"] is True
    assert reconciliation["current_provisional_ids_unique"] is True
    assert reconciliation["retained_record_count"] == 14
    assert reconciliation["current_provisional_count"] == 8
    assert reconciliation["historical_already_fixed_count"] == 2
    assert reconciliation["historical_remediated_count"] == 4
    assert reconciliation["final_ids_cross_file_reconciled"] is False

    return {
        "retained_record_count": 14,
        "current_provisional_count": 8,
        "historical_already_fixed_count": 2,
        "historical_remediated_count": 4,
        "monitoring_metric_replay_dedupe_record_present": False,
        "bounded_disposition_tests_passed": 99,
        "bounded_disposition_assertions": 1931,
        "static_owner_records": 666,
        "route_owners": 309,
        "page_owners": 357,
        "action_bridges": 97,
        "queue_total": 507,
        "queue_reviewed": 120,
        "queue_pending": 387,
        "queue_owned": 98,
        "queue_without_ownership": 409,
        "benchmark_mapped": 2,
        "benchmark_unresolved": 338,
        "final_no_match_or_ncm": 0,
    }


def validate_repository() -> dict[str, Any]:
    assert git("rev-parse", "HEAD") == CURRENT_MAIN
    assert git("rev-parse", "main") == CURRENT_MAIN
    assert git("rev-parse", "HEAD^{tree}") == CURRENT_MAIN_TREE
    assert git("rev-parse", f"{BASE}^{{tree}}") == BASE_TREE
    assert git("rev-parse", f"{INITIAL_FIX}^{{tree}}") == INITIAL_FIX_TREE
    assert git("rev-parse", f"{ADVANCED_MAIN}^{{tree}}") == ADVANCED_MAIN_TREE
    assert git("rev-parse", f"{INITIAL_MERGE}^{{tree}}") == INITIAL_MERGE_TREE
    assert git("rev-parse", f"{CORRECTIVE_FIX}^{{tree}}") == CORRECTIVE_FIX_TREE
    assert git("rev-parse", f"{CORRECTIVE_MERGE}^{{tree}}") == CORRECTIVE_MERGE_TREE
    assert git("rev-parse", f"{DNS_FIX}^{{tree}}") == DNS_FIX_TREE

    assert git("rev-parse", f"{INITIAL_FIX}^") == BASE
    assert git("show", "-s", "--format=%P", INITIAL_MERGE) == (
        f"{ADVANCED_MAIN} {INITIAL_FIX}"
    )
    assert git("rev-parse", f"{CORRECTIVE_FIX}^") == INITIAL_MERGE
    assert git("show", "-s", "--format=%P", CORRECTIVE_MERGE) == (
        f"{INITIAL_MERGE} {CORRECTIVE_FIX}"
    )
    assert git("rev-parse", f"{DNS_FIX}^") == INITIAL_MERGE
    assert git("show", "-s", "--format=%P", CURRENT_MAIN) == (
        f"{CORRECTIVE_MERGE} {DNS_FIX}"
    )
    assert git("show", "-s", "--format=%s", INITIAL_FIX) == (
        "fix(monitoring): deduplicate metric projection replays"
    )
    assert git("show", "-s", "--format=%s", ADVANCED_MAIN) == (
        "audit: seal RUN185 dashboard verification"
    )
    assert git("show", "-s", "--format=%s", INITIAL_MERGE) == (
        "Merge commit 'f521bc0b87222e56b4822e7cb9c935486e279e76'"
    )
    assert git("show", "-s", "--format=%s", CORRECTIVE_FIX) == (
        "fix(monitoring): harden metric replay cutover"
    )
    assert git("show", "-s", "--format=%s", CORRECTIVE_MERGE) == (
        "merge: harden metric replay cutover"
    )
    assert git("show", "-s", "--format=%s", DNS_FIX) == (
        "fix(monitoring): bind DNS responses to exact questions"
    )
    assert git("show", "-s", "--format=%s", CURRENT_MAIN) == (
        "merge: bind monitoring DNS responses"
    )

    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", "origin/main...main") == (
        "0\t38"
    )
    assert not git_is_ancestor(INITIAL_FIX, ORIGIN_MAIN)
    assert not git_is_ancestor(CORRECTIVE_FIX, ORIGIN_MAIN)
    assert not git_is_ancestor(CURRENT_MAIN, ORIGIN_MAIN)
    assert git_is_ancestor(BASE, CURRENT_MAIN)
    assert git_is_ancestor(INITIAL_FIX, CURRENT_MAIN)
    assert git_is_ancestor(CORRECTIVE_FIX, CURRENT_MAIN)
    assert git("diff", "--cached", "--name-only") == ""

    status_lines = [
        line
        for line in git("status", "--porcelain=v1", "--untracked-files=all").splitlines()
        if line
    ]
    assert all(line[:2] == "??" for line in status_lines), status_lines
    dirty = {line[3:] for line in status_lines}
    assert f"{PREFIX}/{SCRIPT_REL}" in dirty
    assert dirty.issubset(ALLOWED_DIRTY), sorted(dirty)
    assert git("diff", "--check") == ""

    assert git("diff", "--name-status", BASE, INITIAL_FIX).splitlines() == (
        INITIAL_NAME_STATUS
    )
    assert git("diff", "--numstat", BASE, INITIAL_FIX).splitlines() == INITIAL_NUMSTAT
    assert git("diff", "--name-status", ADVANCED_MAIN, INITIAL_MERGE).splitlines() == (
        INITIAL_NAME_STATUS
    )
    assert git("diff", "--numstat", ADVANCED_MAIN, INITIAL_MERGE).splitlines() == (
        INITIAL_NUMSTAT
    )
    assert git("diff", "--name-status", INITIAL_MERGE, CORRECTIVE_FIX).splitlines() == (
        CORRECTIVE_NAME_STATUS
    )
    assert git("diff", "--numstat", INITIAL_MERGE, CORRECTIVE_FIX).splitlines() == (
        CORRECTIVE_NUMSTAT
    )
    assert git("diff", "--name-status", INITIAL_MERGE, CORRECTIVE_MERGE).splitlines() == (
        CORRECTIVE_NAME_STATUS
    )
    assert git("diff", "--numstat", INITIAL_MERGE, CORRECTIVE_MERGE).splitlines() == (
        CORRECTIVE_NUMSTAT
    )
    assert git("diff", "--name-status", CORRECTIVE_MERGE, CURRENT_MAIN).splitlines() == (
        DNS_NAME_STATUS
    )
    assert git("diff", "--numstat", CORRECTIVE_MERGE, CURRENT_MAIN).splitlines() == (
        DNS_NUMSTAT
    )

    assert stable_patch_id(BASE, INITIAL_FIX) == INITIAL_PATCH_ID
    assert stable_patch_id(INITIAL_MERGE, CORRECTIVE_FIX) == CORRECTIVE_PATCH_ID
    assert stable_patch_id(INITIAL_MERGE, DNS_FIX) == DNS_PATCH_ID
    assert stable_patch_id(CORRECTIVE_MERGE, CURRENT_MAIN) == DNS_PATCH_ID

    assert set(INITIAL_PATHS).isdisjoint(DNS_PATHS)
    assert set(CORRECTIVE_PATHS).isdisjoint(DNS_PATHS)
    for path in INITIAL_PATHS:
        if git_object_exists(f"{BASE}:{path}"):
            assert git_bytes(BASE, path) == git_bytes(ADVANCED_MAIN, path)
        else:
            assert not git_object_exists(f"{ADVANCED_MAIN}:{path}")
        assert git_bytes(INITIAL_FIX, path) == git_bytes(INITIAL_MERGE, path)
    for path in CORRECTIVE_PATHS:
        assert git_bytes(CORRECTIVE_FIX, path) == git_bytes(CORRECTIVE_MERGE, path)
        assert git_bytes(CORRECTIVE_MERGE, path) == git_bytes(CURRENT_MAIN, path)
    for path in INITIAL_ONLY_PATHS:
        assert git_bytes(INITIAL_FIX, path) == git_bytes(CURRENT_MAIN, path)
    for path in DNS_PATHS:
        assert git_bytes(DNS_FIX, path) == git_bytes(CURRENT_MAIN, path)
    for path in FINAL_ISSUE_PATHS + DNS_PATHS:
        assert (ROOT / path).read_bytes() == git_bytes(CURRENT_MAIN, path)

    assert git_bytes(ADVANCED_MAIN, FINDINGS) == git_bytes(INITIAL_MERGE, FINDINGS)
    assert git_bytes(ADVANCED_MAIN, FINDINGS) == git_bytes(CORRECTIVE_MERGE, FINDINGS)
    assert git_bytes(ADVANCED_MAIN, FINDINGS) == git_bytes(CURRENT_MAIN, FINDINGS)
    assert (ROOT / FINDINGS).read_bytes() == git_bytes(CURRENT_MAIN, FINDINGS)

    current_service = git_bytes(CURRENT_MAIN, CORRECTIVE_PATHS[3]).decode("utf-8")
    current_receipt = git_bytes(CURRENT_MAIN, CORRECTIVE_PATHS[1]).decode("utf-8")
    current_summary = git_bytes(CURRENT_MAIN, CORRECTIVE_PATHS[0]).decode("utf-8")
    current_series = git_bytes(CURRENT_MAIN, CORRECTIVE_PATHS[2]).decode("utf-8")
    migration_100 = git_bytes(CURRENT_MAIN, CORRECTIVE_PATHS[4]).decode("utf-8")
    migration_110 = git_bytes(CURRENT_MAIN, CORRECTIVE_PATHS[5]).decode("utf-8")
    current_test = git_bytes(CURRENT_MAIN, CORRECTIVE_PATHS[6]).decode("utf-8")
    assert current_receipt.count("protected $dateFormat = 'Y-m-d H:i:s.u';") == 1
    assert current_summary.count("protected $dateFormat = 'Y-m-d H:i:s.u';") == 1
    assert current_series.count("protected $dateFormat = 'Y-m-d H:i:s.u';") == 1
    assert current_service.count("summaryForReceipt") >= 2
    assert current_service.count("Metric point receipt does not match its canonical") >= 1
    assert migration_100.count("monitoring_metric_current_summaries_bi_receipt") >= 2
    assert migration_100.count("monitoring_metric_current_summaries_bu_receipt") >= 2
    assert migration_110.count("monitoring_metric_current_summaries_bi_receipt") >= 1
    assert migration_110.count("monitoring_metric_current_summaries_bu_receipt") >= 1
    assert current_test.count("fails closed on a poisoned pre-cutover subsecond receipt") == 1
    assert current_test.count("captures canonical whole-second old-worker success") == 1

    worktrees = git("worktree", "list", "--porcelain").replace("\\", "/")
    assert "C:/w/monitoring-metric-replay-01" not in worktrees
    assert "C:/w/monitoring-metric-replay-corrective-01" not in worktrees
    assert git(
        "rev-parse",
        "refs/heads/codex/monitoring-metric-replay-dedupe-01-20260830",
    ) == INITIAL_FIX
    assert git(
        "rev-parse",
        "refs/heads/codex/monitoring-metric-replay-corrective-01-20260830",
    ) == CORRECTIVE_FIX

    initial_fix_records = revision_records(INITIAL_PATHS, INITIAL_FIX)
    initial_merge_records = revision_records(INITIAL_PATHS, INITIAL_MERGE)
    corrective_fix_records = revision_records(CORRECTIVE_PATHS, CORRECTIVE_FIX)
    corrective_merge_records = revision_records(CORRECTIVE_PATHS, CORRECTIVE_MERGE)
    current_issue_records = revision_records(FINAL_ISSUE_PATHS, CURRENT_MAIN)
    dns_records = revision_records(DNS_PATHS, CURRENT_MAIN)
    assert initial_fix_records == initial_merge_records
    assert corrective_fix_records == corrective_merge_records

    return {
        "initial_patch_id": INITIAL_PATCH_ID,
        "corrective_patch_id": CORRECTIVE_PATCH_ID,
        "dns_patch_id": DNS_PATCH_ID,
        "initial_fix_records": initial_fix_records,
        "initial_merge_records": initial_merge_records,
        "corrective_fix_records": corrective_fix_records,
        "corrective_merge_records": corrective_merge_records,
        "current_issue_records": current_issue_records,
        "current_dns_records": dns_records,
        "current_findings": file_record(FINDINGS, CURRENT_MAIN),
        "findings_snapshot": validate_findings_snapshot(),
    }


def build_receipt(repository: dict[str, Any]) -> dict[str, Any]:
    completion_gates = [
        {"gate": number, "name": name, "complete": False}
        for number, name in enumerate(COMPLETION_GATE_NAMES, start=1)
    ]
    completion_boundary = {name: False for name in COMPLETION_GATE_NAMES}
    noninheritance = {
        "initial_flawed_green_execution": False,
        "initial_post_merge_green_execution": False,
        "root_red_reproduction_passing_denominator": False,
        "first_corrective_full_green_execution": False,
        "stopped_option_a_target_execution": False,
        "final_isolated_replay_duplicated_in_denominator": False,
        "targeted_six_test_supporting_replay_duplicated_in_denominator": False,
        "dns_application_or_runtime": False,
        "facility_signal_application_or_runtime": False,
        "static_route_or_page_feature_ownership": False,
        "static_controller_action_bridge": False,
        "queue_matrix_or_feature_union_change": False,
        "canonical_feature_identity": False,
        "full_monitoring_suite_or_coverage": False,
        "application_browser_or_ease": False,
        "benchmark_mapping_or_final_no_match_ncm": False,
        "migration_deployment_or_release": False,
        "publication_final_finding_gate4_module_or_audit_completion": False,
    }
    credit = {
        "historical_condition_confirmed": True,
        "current_defect_reproduced": True,
        "application_remediation": True,
        "corrective_application_remediation_after_no_go": True,
        "bounded_runtime": True,
        "bounded_metric_projection_replay_correctness": True,
        "application_commit_integrated_local_main": True,
        "application_commit_published": False,
        "new_historical_remediated_record_reporting": False,
        "static_route_or_page_feature_ownership": False,
        "static_controller_action_bridge": False,
        "canonical_feature_identity": False,
        "framework_route_reachability_complete": False,
        "application_browser": False,
        "benchmark_mapping": False,
        "final_no_match_or_ncm": False,
        "ease": False,
        "full_feature_or_module": False,
        "migration_deployment": False,
        "release": False,
        "final_finding": False,
        "completion": False,
        "audit_complete": False,
    }
    receipt: dict[str, Any] = {
        "schema_version": (
            "run-186-monitoring-metric-replay-dedupe-remediation-wave-36-v1"
        ),
        "run_id": RUN_ID,
        "status": STATUS,
        "materialized_on": "2026-08-31",
        "architecture_boundary": (
            "One operating organisation across multiple Sites; canonical Site, "
            "Device and collector provenance, exact permissions, immutable evidence, "
            "and fail-closed privacy controls are the boundaries. Site is not a tenant."
        ),
        "prompt_lineage": {
            "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
            "continuation_attachment_sha256": CONTINUATION_PROMPT_SHA256,
            "continuation_attachment_governing": False,
        },
        "pins": {
            "application_baseline_commit": BASE,
            "application_baseline_tree": BASE_TREE,
            "initial_fix_commit": INITIAL_FIX,
            "initial_fix_tree": INITIAL_FIX_TREE,
            "initial_fix_parent": BASE,
            "initial_fix_subject": "fix(monitoring): deduplicate metric projection replays",
            "initial_stable_patch_id": repository["initial_patch_id"],
            "clean_advanced_audit_main_commit": ADVANCED_MAIN,
            "clean_advanced_audit_main_tree": ADVANCED_MAIN_TREE,
            "initial_merge_commit": INITIAL_MERGE,
            "initial_merge_tree": INITIAL_MERGE_TREE,
            "initial_merge_parents": [ADVANCED_MAIN, INITIAL_FIX],
            "initial_merge_subject": (
                "Merge commit 'f521bc0b87222e56b4822e7cb9c935486e279e76'"
            ),
            "corrective_fix_commit": CORRECTIVE_FIX,
            "corrective_fix_tree": CORRECTIVE_FIX_TREE,
            "corrective_fix_parent": INITIAL_MERGE,
            "corrective_fix_subject": "fix(monitoring): harden metric replay cutover",
            "corrective_stable_patch_id": repository["corrective_patch_id"],
            "corrective_merge_commit": CORRECTIVE_MERGE,
            "corrective_merge_tree": CORRECTIVE_MERGE_TREE,
            "corrective_merge_parents": [INITIAL_MERGE, CORRECTIVE_FIX],
            "corrective_merge_subject": "merge: harden metric replay cutover",
            "disjoint_dns_fix_commit": DNS_FIX,
            "disjoint_dns_fix_tree": DNS_FIX_TREE,
            "disjoint_dns_fix_parent": INITIAL_MERGE,
            "disjoint_dns_stable_patch_id": repository["dns_patch_id"],
            "current_local_main_commit": CURRENT_MAIN,
            "current_local_main_tree": CURRENT_MAIN_TREE,
            "current_local_main_parents": [CORRECTIVE_MERGE, DNS_FIX],
            "current_local_main_subject": "merge: bind monitoring DNS responses",
            "origin_main_observed": ORIGIN_MAIN,
            "local_main_ahead": 38,
            "local_main_behind": 0,
            "application_remote_publication_observed": False,
            "publication_authorized": False,
            "materializer": file_record(f"{PREFIX}/{SCRIPT_REL}"),
            "initial_fix_records": repository["initial_fix_records"],
            "initial_merge_records": repository["initial_merge_records"],
            "initial_name_status": INITIAL_NAME_STATUS,
            "initial_numstat": INITIAL_NUMSTAT,
            "corrective_fix_records": repository["corrective_fix_records"],
            "corrective_merge_records": repository["corrective_merge_records"],
            "corrective_name_status": CORRECTIVE_NAME_STATUS,
            "corrective_numstat": CORRECTIVE_NUMSTAT,
            "current_final_issue_records": repository["current_issue_records"],
            "current_disjoint_dns_records": repository["current_dns_records"],
            "disjoint_dns_name_status": DNS_NAME_STATUS,
            "disjoint_dns_numstat": DNS_NUMSTAT,
            "current_findings_before_run_186": repository["current_findings"],
        },
        "issue_first_disposition": {
            "finding_id": FINDING_ID,
            "record_status": RECORD_STATUS,
            "candidate_feature_id": CANDIDATE_FEATURE_ID,
            "feature_identity_status": FEATURE_IDENTITY_STATUS,
            "verdict": (
                "REPRODUCED_INITIAL_FIX_POST_MERGE_NO_GO_CORRECTED_AND_REMEDIATED_"
                "LOCAL_MAIN_NOT_PUBLISHED"
            ),
            "issue_application_baseline": BASE,
            "initial_exclusive_paths": INITIAL_PATHS,
            "corrective_exclusive_paths": CORRECTIVE_PATHS,
            "final_issue_path_union": FINAL_ISSUE_PATHS,
            "new_discovery_stopped_after_confirmation": True,
            "source_counterexample": (
                "A duplicate observation source key returned canonical persisted "
                "evidence, but metric projection could replay request payload, rewrite "
                "or recount history, rewind current state, and strand partial writes."
            ),
            "root_post_merge_red_reproduction": {
                "commit": INITIAL_MERGE,
                "temporary_change": (
                    "one existing MetricRetentionTest timestamp changed to .123456"
                ),
                "temporary_change_reverted": True,
                "tests": 1,
                "failed": 1,
                "passed": 0,
                "assertions_reported": 0,
                "duration_seconds": 145.27,
                "exit_code": 1,
                "exception": "LogicException",
                "locus": "MetricIngestService.php:292",
                "observed_contract_break": (
                    "receipt timestamp precision did not match the canonical point"
                ),
                "credit": "REPRODUCTION_ONLY_ZERO_PASSING_DENOMINATOR_CREDIT",
            },
        },
        "initial_remediation_and_no_go": {
            "initial_contract": [
                "project only canonical stored observation payload",
                "seal an observation only after every canonical metric point succeeds",
                "retain value-free durable per-point receipts",
                "resume partial projection from receipts without re-probing",
                "reject Site, Device, collector, series or observed-time mismatch",
                "write unique late points historically without regressing current state",
            ],
            "initial_changed_paths": 9,
            "initial_insertions": 1735,
            "initial_deletions": 92,
            "isolated_green_superseded": {
                "tests": 49,
                "assertions": 392,
                "duration_seconds": 153.35,
                "exit_code": 0,
                "denominator_credit": False,
            },
            "post_merge_green_superseded": {
                "tests": 49,
                "assertions": 392,
                "duration_seconds": 151.11,
                "exit_code": 0,
                "denominator_credit": False,
            },
            "post_merge_independent_disposition": "NO_GO",
            "no_go_reasons": [
                "Eloquent timestamp serialization truncated six-decimal receipt time",
                (
                    "mixed-version old-success then newer-success then old replay could "
                    "lose durable receipt evidence"
                ),
            ],
            "initial_green_contributes_current_reporting_denominator": False,
        },
        "corrective_remediation": {
            "summary": (
                "The corrective lineage preserves six-decimal time, updates the current "
                "evidence tuple only for a current point, installs value-free receipt "
                "bridges on fresh and already-applied migration paths, and fails closed "
                "on poisoned pre-cutover subsecond evidence."
            ),
            "changed_paths": 7,
            "insertions": 1045,
            "deletions": 65,
            "six_decimal_eloquent_models": [
                "MetricPointReceipt",
                "MetricCurrentSummary",
                "MetricSeries",
            ],
            "current_evidence_tuple_updates_atomically_only_when_point_is_current": True,
            "unique_late_points_do_not_regress_current_tuple_or_coverage": True,
            "no_op_metric_series_saves_avoided": True,
            "fresh_install_migration_amended": (
                "2026_08_30_000100_govern_monitoring_metric_projection_replays.php"
            ),
            "already_applied_upgrade_migration_added": (
                "2026_08_30_000110_govern_monitoring_metric_projection_cutover.php"
            ),
            "bridge_guards_and_triggers_installed_before_cutover": True,
            "whole_second_old_worker_success_receipted_before_displacement": True,
            "old_worker_failure_remains_canonically_retryable": True,
            "partial_success_receipted_per_completed_point": True,
            "poisoned_pre_f521_subsecond_bridge_fails_closed": True,
            "poisoned_bridge_never_rewrites_recounts_or_silently_completes": True,
            "observation_evidence_classification_and_provenance_immutable": True,
            "site_device_and_collector_mismatch_fails_closed": True,
            "direct_runner_retry_does_not_reprobe": True,
            "receipt_retains_raw_metric_values": False,
            "single_organisation_multi_site_boundary_preserved": True,
            "deployment_prerequisite": [
                "quiesce old monitoring workers",
                "reconcile pending or incoherent rows",
                "apply migration 000110",
                "start new workers only after cutover reconciliation",
            ],
            "deployment_prerequisite_verified_in_production": False,
            "production_migration_or_release_credit": False,
        },
        "delegated_runtime_execution": {
            "execution_owners": {
                "initial_isolated_and_post_merge": "separate ten-fix remediation task",
                "post_merge_red_reproduction": "root audit lane",
                "corrective_isolated_and_post_merge": "separate OSS audit fixes task",
            },
            "run_186_producer_executed_php_or_tests": False,
            "initial_green_runs": {
                "isolated": "49 tests / 392 assertions / 153.35s / exit 0",
                "post_merge": "49 tests / 392 assertions / 151.11s / exit 0",
                "later_no_go": True,
                "denominator_credit": False,
            },
            "root_red_reproduction": {
                "tests": 1,
                "failed": 1,
                "passed": 0,
                "assertions": 0,
                "duration_seconds": 145.27,
                "exit_code": 1,
                "denominator_credit": False,
            },
            "initial_corrective_subsets_later_no_go": [
                {
                    "scope": "literal .123456 receipt replay",
                    "tests": 1,
                    "assertions": 10,
                    "exit_code": 0,
                    "denominator_credit": False,
                },
                {
                    "scope": (
                        "same-second ordering, interior point, old success, old failure, "
                        "and raw bridge"
                    ),
                    "tests": 5,
                    "assertions": 58,
                    "exit_code": 0,
                    "denominator_credit": False,
                },
                {
                    "scope": (
                        "historical replay, unique late point, partial recovery, high-water "
                        "compatibility, time mismatch, and series mismatch"
                    ),
                    "tests": 6,
                    "assertions": 58,
                    "exit_code": 0,
                    "denominator_credit": False,
                },
            ],
            "first_corrective_full_green_later_no_go": {
                "tests": 54,
                "assertions": 455,
                "duration_seconds": 156.72,
                "exit_code": 0,
                "no_go_reasons": [
                    "already-applied 000100 did not receive bridge triggers",
                    "mixed-worker subsecond recovery remained unsafe",
                ],
                "denominator_credit": False,
            },
            "stopped_option_a_target": {
                "product_cases_passed": 5,
                "test_only_failures": 1,
                "failure": (
                    "composite unique index assertion expected one information_schema row"
                ),
                "correction": "require both composite index rows to be NON_UNIQUE=0",
                "denominator_credit": False,
            },
            "final_corrected_targeted_support": {
                "tests": 6,
                "assertions": 66,
                "exit_code": 0,
                "reported_separately": True,
                "denominator_credit": False,
            },
            "final_corrected_isolated_full_focused": {
                "scope": "MetricRetentionTest plus RunMonitorCheckTest",
                "tests": 56,
                "assertions": 472,
                "duration_seconds": 154.66,
                "exit_code": 0,
                "denominator_credit": False,
            },
            "final_corrected_post_merge_full_focused": {
                "scope": "MetricRetentionTest plus RunMonitorCheckTest",
                "tests": 56,
                "assertions": 472,
                "duration_seconds": 162.46,
                "exit_code": 0,
                "unique_bounded_disposition_denominator_credit": True,
            },
            "unique_bounded_accounting": {
                "prior": {"tests": 99, "assertions": 1931},
                "increment": {"tests": 56, "assertions": 472},
                "proposed_after_run_186r_go": {"tests": 155, "assertions": 2403},
            },
            "syntax": "PASS",
            "scoped_pint": "PASS",
            "git_diff_and_check": "PASS",
            "full_suite_or_coverage_credit": False,
        },
        "independent_review": {
            "initial_production_schema_and_test_reviews": "GO_THEN_SUPERSEDED",
            "post_merge_precision_and_mixed_version_review": "NO_GO",
            "corrective_first_review": "NO_GO",
            "corrective_first_review_blockers": [
                "already-migrated databases required forward migration 000110",
                "pre-f521 subsecond evidence could not be reconstructed safely",
            ],
            "accepted_option": "A_CANONICAL_WHOLE_SECOND_MIXED_WORKER_BRIDGE",
            "final_corrective_reviewers": 2,
            "final_corrective_review_verdict": "GO",
            "final_review_boundary": (
                "old workers quiesced, pending rows reconciled, 000110 applied, then "
                "new workers started; poisoned subsecond evidence requires operator "
                "reconciliation"
            ),
            "reviewers_executed_tests": False,
            "reviewers_wrote_application_files": False,
            "exact_run_186_receipt_review_completed": False,
            "new_record_reporting_authorized": False,
            "run_186r_still_required": True,
        },
        "cleanup_evidence": {
            "initial_isolated_worktree": "C:/w/monitoring-metric-replay-01",
            "initial_isolated_worktree_removed": True,
            "initial_recovery_branch": (
                "codex/monitoring-metric-replay-dedupe-01-20260830"
            ),
            "initial_recovery_branch_retained_at_fix": True,
            "corrective_isolated_worktree": (
                "C:/w/monitoring-metric-replay-corrective-01"
            ),
            "corrective_isolated_worktree_removed": True,
            "corrective_recovery_branch": (
                "codex/monitoring-metric-replay-corrective-01-20260830"
            ),
            "corrective_recovery_branch_retained_at_fix": True,
            "post_merge_global_php_or_pest_process_count": 0,
            "numeric_pid_test_schema_count": 0,
            "corrective_upgrade_schema_count": 0,
            "root_red_reproduction_cleanup": {
                "global_php_or_pest_process_count": 0,
                "numeric_pid_test_schema_count": 0,
            },
            "stopped_option_a_target_cleanup": {
                "global_php_or_pest_process_count": 0,
                "numeric_pid_test_schema_count": 0,
                "owned_upgrade_schema_count": 0,
            },
            "primary_main_clean_before_audit_writes": True,
            "exclusive_corrective_path_ownership_released": True,
            "serialized_runtime_lane_released": True,
        },
        "static_ownership_boundary": {
            "owner_records": 666,
            "route_owners": 309,
            "page_owners": 357,
            "action_bridges": 97,
            "queue_total": 507,
            "queue_reviewed": 120,
            "queue_pending": 387,
            "queue_owned": 98,
            "queue_without_ownership": 409,
            "next_zero_based_index": 85,
            "candidate_feature_id": CANDIDATE_FEATURE_ID,
            "feature_identity_status": FEATURE_IDENTITY_STATUS,
            "correctness_does_not_adjudicate_static_ownership": True,
            "route_or_page_owner_authorized": False,
            "controller_action_bridge_authorized": False,
            "queue_advance_authorized": False,
            "fresh_outcome_neutral_semantic_review_required_later": True,
        },
        "benchmark_boundary": {
            "mapped": 2,
            "total": 340,
            "final_no_match_or_ncm": 0,
            "unresolved": 338,
            "changed_by_run_186": False,
        },
        "current_main_disjoint_dns_noninheritance": {
            "finding_id": "MON-DNS-RESPONSE-BINDING-01",
            "path_count": 2,
            "paths": DNS_PATHS,
            "merge_commit": CURRENT_MAIN,
            "application_credit_in_run_186": False,
            "runtime_credit_in_run_186": False,
            "reporting_credit_in_run_186": False,
            "feature_or_static_ownership_credit_in_run_186": False,
        },
        "noninheritance_boundary": noninheritance,
        "reporting_boundary": {
            "current_findings_snapshot": repository["findings_snapshot"],
            "current_retained_identity_count": 14,
            "current_split": (
                "8 provisional + 2 historical already-fixed + 4 historical remediated"
            ),
            "pending_new_record_id": FINDING_ID,
            "pending_record_status": RECORD_STATUS,
            "pending_candidate_feature_id": CANDIDATE_FEATURE_ID,
            "pending_feature_identity_status": FEATURE_IDENTITY_STATUS,
            "pending_reporting_delta": {
                "retained_claim_records": 1,
                "current_provisional_source_claims": 0,
                "historical_already_fixed_records": 0,
                "historical_remediated_records": 1,
                "bounded_disposition_tests_passed": 56,
                "bounded_disposition_assertions": 472,
                "final_P0": 0,
                "final_P1": 0,
            },
            "proposed_after_independent_run_186r_go": {
                "retained_claim_records": 15,
                "current_provisional_source_claims": 8,
                "historical_already_fixed_records": 2,
                "historical_remediated_records": 5,
                "bounded_disposition_tests_passed": 155,
                "bounded_disposition_assertions": 2403,
                "final_P0": 0,
                "final_P1": 0,
            },
            "independent_review_authorized": False,
            "run_186_changes_live_reporting": False,
            "run_186r_required": True,
            "run_187_reporting_required_after_go": True,
            "run_188_fresh_dashboard_verification_required_after_reporting": True,
        },
        "credit_boundary": credit,
        "completion_gates": completion_gates,
        "completion_boundary": completion_boundary,
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            f"{PREFIX}/{SCRIPT_REL}",
            f"{PREFIX}/{OUTPUT_REL}",
        ],
    }
    assert len(receipt["completion_gates"]) == 26
    assert [row["gate"] for row in receipt["completion_gates"]] == list(range(1, 27))
    assert all(row["complete"] is False for row in receipt["completion_gates"])
    assert len(receipt["completion_boundary"]) == 26
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert all(value is False for value in noninheritance.values())
    assert [key for key, value in credit.items() if value] == [
        "historical_condition_confirmed",
        "current_defect_reproduced",
        "application_remediation",
        "corrective_application_remediation_after_no_go",
        "bounded_runtime",
        "bounded_metric_projection_replay_correctness",
        "application_commit_integrated_local_main",
    ]
    receipt["receipt_self_seal_sha256"] = canonical_sha256(receipt)
    return receipt


def validate_receipt(receipt: dict[str, Any]) -> None:
    copy = dict(receipt)
    seal = copy.pop("receipt_self_seal_sha256")
    assert seal == canonical_sha256(copy)
    assert receipt["run_id"] == RUN_ID
    assert receipt["status"] == STATUS
    assert receipt["materialized_on"] == "2026-08-31"
    assert receipt["prompt_lineage"] == {
        "governing_prompt_sha256": GOVERNING_PROMPT_SHA256,
        "continuation_attachment_sha256": CONTINUATION_PROMPT_SHA256,
        "continuation_attachment_governing": False,
    }
    disposition = receipt["issue_first_disposition"]
    assert disposition["finding_id"] == FINDING_ID
    assert disposition["record_status"] == RECORD_STATUS
    assert disposition["candidate_feature_id"] is None
    assert disposition["feature_identity_status"] == FEATURE_IDENTITY_STATUS
    assert disposition["initial_exclusive_paths"] == INITIAL_PATHS
    assert disposition["corrective_exclusive_paths"] == CORRECTIVE_PATHS
    assert disposition["final_issue_path_union"] == FINAL_ISSUE_PATHS
    assert disposition["root_post_merge_red_reproduction"]["exit_code"] == 1
    assert disposition["root_post_merge_red_reproduction"]["assertions_reported"] == 0

    runtime = receipt["delegated_runtime_execution"]
    assert runtime["run_186_producer_executed_php_or_tests"] is False
    assert runtime["initial_green_runs"]["denominator_credit"] is False
    assert runtime["first_corrective_full_green_later_no_go"]["denominator_credit"] is False
    assert runtime["stopped_option_a_target"]["denominator_credit"] is False
    assert runtime["final_corrected_targeted_support"]["denominator_credit"] is False
    assert runtime["final_corrected_isolated_full_focused"]["denominator_credit"] is False
    assert runtime["final_corrected_post_merge_full_focused"] == {
        "scope": "MetricRetentionTest plus RunMonitorCheckTest",
        "tests": 56,
        "assertions": 472,
        "duration_seconds": 162.46,
        "exit_code": 0,
        "unique_bounded_disposition_denominator_credit": True,
    }
    assert runtime["unique_bounded_accounting"] == {
        "prior": {"tests": 99, "assertions": 1931},
        "increment": {"tests": 56, "assertions": 472},
        "proposed_after_run_186r_go": {"tests": 155, "assertions": 2403},
    }

    assert receipt["pins"]["initial_fix_records"] == receipt["pins"][
        "initial_merge_records"
    ]
    assert receipt["pins"]["corrective_fix_records"] == receipt["pins"][
        "corrective_merge_records"
    ]
    assert receipt["corrective_remediation"]["deployment_prerequisite_verified_in_production"] is False
    assert receipt["independent_review"]["run_186r_still_required"] is True
    assert receipt["static_ownership_boundary"]["candidate_feature_id"] is None
    assert receipt["static_ownership_boundary"]["next_zero_based_index"] == 85
    assert receipt["current_main_disjoint_dns_noninheritance"]["runtime_credit_in_run_186"] is False
    assert receipt["reporting_boundary"]["current_retained_identity_count"] == 14
    assert receipt["reporting_boundary"]["proposed_after_independent_run_186r_go"] == {
        "retained_claim_records": 15,
        "current_provisional_source_claims": 8,
        "historical_already_fixed_records": 2,
        "historical_remediated_records": 5,
        "bounded_disposition_tests_passed": 155,
        "bounded_disposition_assertions": 2403,
        "final_P0": 0,
        "final_P1": 0,
    }
    assert receipt["reporting_boundary"]["independent_review_authorized"] is False
    assert receipt["credit_boundary"]["new_historical_remediated_record_reporting"] is False
    assert receipt["credit_boundary"]["application_commit_published"] is False
    assert receipt["credit_boundary"]["migration_deployment"] is False
    assert len(receipt["completion_gates"]) == 26
    assert all(row["complete"] is False for row in receipt["completion_gates"])
    assert all(value is False for value in receipt["completion_boundary"].values())
    assert receipt["artifact_completion_test_met"] is True
    assert receipt["audit_completion_test_met"] is False


def main() -> None:
    repository = validate_repository()
    receipt = build_receipt(repository)
    validate_receipt(receipt)
    encoded = (json.dumps(receipt, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(encoded)
    assert OUTPUT.read_bytes() == encoded
    reloaded = strict_json(OUTPUT.read_bytes(), OUTPUT_REL)
    assert reloaded == receipt
    validate_receipt(reloaded)
    print(
        json.dumps(
            {
                "run_id": RUN_ID,
                "status": STATUS,
                "materializer_sha256": file_record(f"{PREFIX}/{SCRIPT_REL}")["sha256"],
                "receipt_sha256": sha256(encoded),
                "receipt_self_seal_sha256": receipt["receipt_self_seal_sha256"],
                "historical_remediated_reporting_authorized": False,
                "proposed_result_after_run_186r_go": (
                    "15 = 8 provisional + 2 already-fixed + 5 remediated"
                ),
                "proposed_unique_bounded_total_after_run_186r_go": "155/2403",
                "static_ownership_adjudicated": False,
                "feature_identity_assigned": False,
                "application_published": False,
                "migration_deployed": False,
                "all_26_completion_gates_complete": False,
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
