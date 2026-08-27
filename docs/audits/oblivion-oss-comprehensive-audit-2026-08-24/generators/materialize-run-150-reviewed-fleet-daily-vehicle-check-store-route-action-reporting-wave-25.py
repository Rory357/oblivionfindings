from __future__ import annotations

import ast
import csv
import hashlib
import json
import subprocess
from copy import deepcopy
from pathlib import Path
from typing import Any


AUDIT = Path(__file__).resolve().parents[1]
ROOT = AUDIT.parents[2]
PREFIX = AUDIT.relative_to(ROOT).as_posix()
RUN_ID = "RUN-150-REVIEWED-FLEET-DAILY-VEHICLE-CHECK-STORE-ROUTE-ACTION-REPORTING-WAVE-25"
MATERIALIZER = "generators/materialize-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.py"
OUTPUT = "evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json"
HEAD = "198ac398589891c6c58aa334c1b0a11f11277de3"
TREE = "2deb006a1a4be647e4ea7d1a29b459b6f45bae2c"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MATRIX_SHA256 = "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0"
REGISTER_SHA256 = "5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884"
RUN145_SHA256 = "8306a8aefe0a490ebf206d0c4716d92930326988f19e0ed495a3c2d0002c7cf9"
RUN147_SHA256 = "36e0595b3e90f439770c9e8aadbb01555591c79e38ffac54d3cfd6dc3b892cc0"
OVERLAY = "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"
OVERLAY_REVIEW = "evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json"
COHORT = "evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json"
CANDIDATE_REVIEW = "evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json"

SURFACES = (
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
    "generators/build-current-audit-dashboard.py",
)

BASELINE_SHA256 = {
    "00-executive-summary.md": "616fd626dc1292896955e657812404ccbdbb4e425b736f68b9ccb8f87e63d8ab",
    "01-repository-module-map.md": "242b03dfa2f1c09eb7cb2860bf02047d9cc9886f655d060a0dac0843881dc763",
    "13-unresolved-questions-and-evidence-gaps.md": "ada6ad349bb29d9168b7e93e5fc7d494d8701254b8fe10faa2df28afb0725965",
    "findings.json": "9848a8edd8c7fa56cc753a77746f66434912ac0bafe42110f999457a7c43da5c",
    "generators/build-current-audit-dashboard.py": "3eab383b5203ae9a3108f94ff67a276393f57afa39c00790da715e64f5247024",
}

PRESERVED = {
    "audit-dashboard.html": "277db943400776d0bd3be1b0c97afff69ea7b76e97c861abf5c135dc6be00c33",
    "03-feature-to-benchmark-matrix.csv": MATRIX_SHA256,
    "06-open-source-benchmark-register.csv": REGISTER_SHA256,
    "evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json": RUN145_SHA256,
    "evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json": RUN147_SHA256,
}

LINEAGE = {
    "generators/build-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.py": "c8c6a9f1500fe088f6c61c3edff5351095518d14661a77af86b327a9ee253f65",
    COHORT: "621c1794a73e232b6fc9ff8d2b81ac9ae31ea2ccfe9f038ae77afe332b3ab28d",
    "generators/materialize-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.py": "c41b37679763c0ea0eb4a08fc14368692c5b4cc0176167c4369b637c6f68f4b3",
    CANDIDATE_REVIEW: "6720a7570f7f0547fca222758c0632cb7514d953a20605e7c00d6ce88efc18b2",
    "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py": "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e",
    OVERLAY: "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55",
    "generators/materialize-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.py": "bd09980ac26a7e9d026eda518f1964f8a2a87ea75fecf271981e4017e8dcd57c",
    OVERLAY_REVIEW: "545694fc1b7bd5f4af244617fb421ece1265fe6e6f2cad2ca834115e7a9e75a2",
}


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT / relative).read_bytes())


def git(*args: str) -> str:
    return subprocess.run(["git", *args], cwd=ROOT, check=True, capture_output=True, text=True).stdout.strip()


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key {key!r} in {relative}"
            result[key] = value
        return result

    value = json.loads((AUDIT / relative).read_bytes(), object_pairs_hook=hook)
    assert isinstance(value, dict)
    return value


def read_lf(relative: str) -> str:
    payload = (AUDIT / relative).read_bytes()
    assert b"\r\n" not in payload and not payload.startswith(b"\xef\xbb\xbf")
    return payload.decode("utf-8")


def write_lf(relative: str, text: str) -> None:
    assert "\r" not in text
    with (AUDIT / relative).open("w", encoding="utf-8", newline="\n") as handle:
        handle.write(text)


def replace_exact(text: str, old: str, new: str, label: str) -> str:
    if text.count(new) == 1:
        return text
    assert text.count(old) == 1, f"{label}: expected one baseline value, got {text.count(old)}"
    assert new not in text, f"{label}: current value unexpectedly duplicated"
    return text.replace(old, new, 1)


def replace_line(text: str, prefix: str, new_line: str, label: str) -> str:
    lines = text.splitlines()
    if lines.count(new_line) == 1 and not any(line.startswith(prefix) and line != new_line for line in lines):
        return text
    matches = [index for index, line in enumerate(lines) if line.startswith(prefix)]
    assert len(matches) == 1, f"{label}: expected one line beginning {prefix!r}, got {len(matches)}"
    lines[matches[0]] = new_line
    return "\n".join(lines) + ("\n" if text.endswith("\n") else "")


def validate_inputs() -> tuple[dict[str, Any], dict[str, Any], dict[str, Any], dict[str, Any]]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert not git("status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database")
    for relative, expected in BASELINE_SHA256.items():
        if sha256_file(relative) != expected:
            # Idempotent reruns may see the already-materialized output.
            assert relative in SURFACES
    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected
    for relative, expected in LINEAGE.items():
        assert sha256_file(relative) == expected
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}")

    with (AUDIT / "03-feature-to-benchmark-matrix.csv").open(encoding="utf-8", newline="") as handle:
        rows = list(csv.DictReader(handle))
    assert len(rows) == 340
    assert sorted(row["feature_id"] for row in rows if row["benchmark_mapping_credit"] == "true") == [
        "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
        "CAP-FIN-FX-REVALUATION",
    ]

    cohort = strict_json(COHORT)
    candidate_review = strict_json(CANDIDATE_REVIEW)
    overlay = strict_json(OVERLAY)
    review = strict_json(OVERLAY_REVIEW)
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    assert (counts["source_owner_records"], counts["route_owner_records"], counts["page_owner_records"], counts["static_controller_action_bridges"]) == (663, 306, 357, 94)
    assert (counts["distinct_feature_ids"], counts["distinct_H_feature_ids"], counts["distinct_D_feature_ids"]) == (256, 234, 22)
    assert (counts["route_distinct_feature_ids"], counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"]) == (64, 242, 50)
    assert counts["bounded_static_source_ownership_percent"] == "16.874523"
    assert counts["bounded_static_source_residual_records"] == 3266
    assert counts["residual_explicit_unmapped_routes"] == 2895
    assert (queue["reviewed_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"], queue["owner_queue_surface_rows"], queue["queue_surfaces_without_ownership"]) == (117, 390, 95, 412)
    assert 3929 == 663 + 3266 and 663 == 306 + 357 and 256 == 64 + 242 - 50
    assert 3218 == 306 + 12 + 5 + 0 + 2895 and 711 == 357 + 9 + 0 + 0 + 345
    assert 507 == 117 + 390 and 117 == 95 + 10 + 5 + 0 + 7 and 412 == 390 + 10 + 5 + 0 + 7
    observations = overlay["provisional_assurance_observation_preservation"]
    assert observations["observation_count"] == 4
    assert [row["observation_id"] for row in observations["observations"]] == [
        "RUN148R-ASSURANCE-MUTATION-CAPABILITY",
        "RUN148R-ASSURANCE-SITE-DIRECT-OBJECT",
        "RUN148R-ASSURANCE-TEMPLATE-DAY-CONCURRENCY",
        "RUN148R-ASSURANCE-MUTATION-TEST-COVERAGE",
    ]
    assert all(row["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING" for row in observations["observations"])
    assert all(not row["correctness_credit_authorized"] and not row["final_finding_credit_authorized"] for row in observations["observations"])
    disclosure = overlay["reviewer_lineage"]["nonblinding_disclosure_preserved"]
    assert disclosure == {
        "review_a_blinded": False,
        "review_a_prior_outcome_visible_in_team_status": False,
        "review_b_blinded": False,
        "review_b_prior_outcome_visible_in_team_status": True,
        "reviewers_consulted_each_other": False,
        "both_completed_independent_evidence_traces": True,
    }
    assert overlay["noninheritance_boundary"] == {
        "preceding_index_79_owner_not_inherited_or_recredited": True,
        "page_owner_not_inherited_or_recredited": True,
        "frontend_caller_not_inherited_or_recredited": True,
        "next_index_81_not_selected_or_credited": True,
        "current_overlay_correctness_and_downstream_credit": False,
    }
    assert len(overlay["source_packet_expansion_preservation"]["ownership_material_expansion"]) == 0
    assert len(overlay["source_packet_expansion_preservation"]["correctness_only_expanded_files"]) == 4
    assert review["decision"]["verdict"] == "GO" and review["decision"]["reporting_materialization_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert {key for key, value in review["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
    return cohort, candidate_review, overlay, review


def update_executive(overlay: dict[str, Any]) -> None:
    relative = "00-executive-summary.md"
    text = read_lf(relative)
    old = """## RUN-113–143 reviewed route/action and page-ownership lineage

RUN-113/R–140 remain historical reviewed route/action, page-owner, reporting, and exact audit-dashboard checkpoints. RUN-137/R–140 preserve the one Finance invoice-index route/action owner, one bridge, two existing page-owner contexts without recredit, and the exact superseded RUN-139 dashboard receipt.

RUN-141 freezes exactly one still-pending Site-portfolio API action without pre-awarding ownership: finance.api.sites.overview, RUN090-ROUTE-0079 / RUN077-ROUTE-0669 for CAP-FIN-SITE-PORTFOLIO-OVERVIEW. Two blinded candidate reviewers and a distinct synthesis reviewer trace the exact JSON route/action, one existing page owner PAGE-ROOT-FC2C5F5706FD9066 / RUN086-PAGE-MAP-0313, the separate finance.sites.overview sibling RUN090-ROUTE-0041 / RUN077-ROUTE-0418, three page-path callers, zero exact frontend callers of the selected API, the already-reviewed neighbor at index 79, the next pending row at index 80, source-packet expansions, and assurance risks. RUN-141R classifies only the selected API action as one explicit OWNER_ROUTE_ACTION; page, sibling, caller, neighbor, and next-row context confer no inherited, reassigned, or repeated credit.

RUN-142 integrates exactly that one route owner and one controller-action bridge. RUN-142R independently verifies the corrected committed bytes, all 91 identities, 35 count pointers, 24 source-packet expansion files (six existing plus 18 new) with one transparent locus correction, all 17 assurance-reconciliation inputs, nine action findings plus three shared findings, accounting, denominators, reviewer lineage, and zero-credit boundaries. This static ownership does not establish approved-Site access, exact permissions, privacy, direct-object concealment, query, projection, period, allocation provenance or reversal, utility true-up sign, response minimization, lifecycle, concurrency, event or downstream durability, runtime, or release correctness.

The current bounded checkpoint is **662 records = 305 routes + 357 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 93 controller-action bridges. Route and page owners span 64 and 242 FEATURE-IDs with 50 in their overlap. This is 16.849071% of the bounded 3,929-record source universe; 3,267 records remain. The route universe is **3,218 = 305 owners + 12 shared + 5 aliases + 2,896 residual**, with seven evidence gaps tagged inside that residual. The page universe remains **711 = 357 owners + 9 shared + 345 residual**, with one earlier evidence gap tagged inside its residual. Queue accounting is **507 = 116 reviewed + 391 pending**; reviewed rows are 94 owned, 10 shared, 5 aliases, 0 dead, and 7 evidence gaps, while 413 remain without ownership.

RUN-143 reports only that bounded one-action delta. The exact regenerated dashboard requires a fresh RUN-144 audit-artifact receipt. Oblivion Findings remains one operating organisation across multiple Sites. Framework reachability, navigation, approved-Site access, roles/permissions, canonical object ownership, direct-object concealment, privacy, query/projection/period/allocation/reversal/utility-sign/minimization/lifecycle/concurrency/event/durability correctness, runtime, database, build, application browser, tests, benchmarks, ease, Passes, findings, completion, and Gate 4 remain separate open or zero-credit gates. The 340-row matrix remains byte-identical and mapping remains 0/340.
"""
    new = """## RUN-113–150 reviewed route/action and page-ownership lineage

RUN-113/R–147 remain historical reviewed ownership, reporting, benchmark-mapping, and exact audit-dashboard checkpoints. RUN-141/R–144 preserve the Site-portfolio API owner and dashboard receipt; RUN-145–147 preserve exactly two static Finance benchmark mappings and the exact now-superseded RUN-146 dashboard receipt.

RUN-148 freezes exactly one still-pending Fleet daily-check POST action without pre-awarding ownership: `fleet-assets.daily-check.store`, RUN090-ROUTE-0081 / RUN077-ROUTE-0689 for `CAP-FLEET-DAILY-VEHICLE-CHECK`. Two independent candidate reviews and a distinct synthesis agree on `DailyCheckController::store`. Neither review is represented as blinded: reviewer B had the prior outcome visible in team status, did not consult reviewer A, and completed an independent evidence trace. The preceding reviewed owner at queue index 79 is not recredited; page and frontend submitter context confer no page ownership; queue index 81 / RUN090-ROUTE-0082 remains pending.

RUN-149 integrates exactly one route owner and one controller-action bridge, with zero page-owner, correctness, runtime, browser, test, finding, or completion credit. RUN-149R independently verifies the committed overlay and authorizes reporting only. Four preserved source observations remain `PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING`: the read-labelled OR permission group does not establish an exact mutation capability; raw `assets.id` existence does not establish canonical approved-Site vehicle resolution or concealment; template/day update-or-create is not shown under exact authority, transaction, lock, or uniqueness; and no exact selected-POST mutation test was found or executed. The four correctness-only expansion files authorize no correctness or final-finding credit and do not alter the separate 12 provisional finding records.

The current bounded checkpoint is **663 records = 306 routes + 357 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 94 controller-action bridges. Route and page owners span 64 and 242 FEATURE-IDs with 50 in their overlap. This is 16.874523% of the bounded 3,929-record source universe; 3,266 records remain. The route universe is **3,218 = 306 owners + 12 shared + 5 aliases + 2,895 residual**, with seven evidence gaps tagged inside that residual. The page universe remains **711 = 357 owners + 9 shared + 345 residual**, with one earlier evidence gap tagged inside its residual. Queue accounting is **507 = 117 reviewed + 390 pending**; reviewed rows are 95 owned, 10 shared, 5 aliases, 0 dead, and 7 evidence gaps, while 412 remain without ownership.

RUN-150 reports only that bounded one-action delta. The exact regenerated dashboard requires a fresh RUN-151 audit-artifact receipt. Oblivion Findings remains one operating organisation across multiple Sites. Framework reachability, navigation, approved-Site access, roles/permissions, canonical object ownership, direct-object concealment, privacy, exact mutation authority, template authority, lifecycle, concurrency, audit/event durability, runtime, database, build, application browser, tests, ease, release, Passes, final findings, completion, and Gate 4 remain separate open or zero-credit gates. RUN-145 benchmark state remains exactly 2/340 mapped, 0/340 final no-match/NCM, and 338 unresolved.
"""
    text = replace_exact(text, old, new, "executive current ownership lineage")
    evidence_marker = "- `evidence/source/raw-run-073a-required-artifact-contract-wave-05.json`:"
    evidence = """- `generators/materialize-run-147-audit-dashboard-verification-wave-24.py` and `evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json`: exact now-superseded RUN-146 dashboard verification at four viewports with 25/25 visible boundaries, 10/10 navigation, 355/355 unique local links, and zero application credit.
- `generators/build-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.py` and `evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json`: exact zero-credit one-action Fleet daily-check POST review cohort.
- `generators/materialize-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.py` and `evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json`: two independent owner reviews plus synthesis, exact nonblinding disclosure, four provisional-not-final source observations, and zero current-overlay or correctness credit.
- `generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py` and `evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json`: exact one-route and one-bridge Fleet daily-check static-only overlay with preceding/page/frontend/next-row noninheritance.
- `generators/materialize-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.py` and `evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json`: three-part exact-byte, identity, accounting, provenance, observation, and boundary GO receipt.
- `generators/materialize-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.py` and `evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json`: deterministic RUN-150 reporting refresh preserving benchmark 2/340, the 12 provisional findings, four separate provisional source observations, and every downstream zero-credit boundary.
"""
    if evidence.splitlines()[0] not in text:
        assert text.count(evidence_marker) == 1
        text = text.replace(evidence_marker, evidence + evidence_marker, 1)
    text = replace_line(
        text,
        "- `audit-dashboard.html`:",
        "- `audit-dashboard.html`: progress dashboard generated from current structured evidence through RUN-150 only after the updated builder is executed. The currently committed RUN-146 HTML remains byte-identical during RUN-150; the regenerated artifact requires a separate fresh RUN-151 viewport/link/anchor/console receipt and cannot award application-browser or downstream credit.",
        "executive dashboard catalogue",
    )
    write_lf(relative, text)


def update_module_map() -> None:
    relative = "01-repository-module-map.md"
    text = read_lf(relative)
    old = """## RUN-113–143 reviewed route/action and page-ownership lineage

RUN-113/R–140 remain historical reviewed route/action, page-owner, reporting, and exact-dashboard checkpoints. RUN-137/R–140 preserve the invoice-index route owner, bridge, existing page contexts, and exact superseded dashboard receipt.

RUN-141/R separately review finance.api.sites.overview as one explicit route/action owner for CAP-FIN-SITE-PORTFOLIO-OVERVIEW. RUN-142/R integrate and independently verify exactly one route record and one controller-action bridge with zero page, caller, sibling, neighbor, next-row, feature-union, or matrix credit. The selected API action returns JSON and has zero exact frontend callers; PAGE-ROOT-FC2C5F5706FD9066 / RUN086-PAGE-MAP-0313, the separate finance.sites.overview route RUN090-ROUTE-0041 / RUN077-ROUTE-0418, three page-path callers, neighbor index 79, and pending index 80 remain context only.

The cumulative bounded ledger is 662 source owners (305 route + 357 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets are 64/242 with overlap 50, and the action-bridge count is 93. Route accounting is 3,218 = 305 owners + 12 shared + 5 aliases + 2,896 residual, with seven evidence gaps tagged within residual. Page accounting remains 711 = 357 owners + 9 shared + 345 residual, with one earlier tagged evidence gap. RUN-090 queue accounting is 507 total, 116 reviewed, 94 owned, 10 shared, 5 aliases, 0 dead, 7 evidence gaps, 391 pending, and 413 without ownership.

These relations establish bounded static ownership only. The 24 expansion files (six existing plus 18 new), one locus correction, 17 assurance-mapping inputs, nine action findings, and three shared findings leave unproved approved-Site, permission, privacy, direct-object, query, projection, period, allocation provenance or reversal, utility true-up sign, response minimization, lifecycle, concurrency, event and downstream durability correctness, framework reachability, runtime, build, browser, tests, benchmarks, findings, Passes, and completion. The existing page owner, separate sibling route, callers, reviewed neighbor, and next pending row are not inherited or recredited.
"""
    new = """## RUN-113–150 reviewed route/action and page-ownership lineage

RUN-113/R–147 remain historical reviewed ownership, reporting, benchmark-mapping, and exact-dashboard checkpoints. RUN-141/R–144 preserve the Site-portfolio API owner; RUN-145–147 preserve the two current static Finance benchmark mappings and exact superseded dashboard receipt.

RUN-148/R separately review `fleet-assets.daily-check.store` / RUN077-ROUTE-0689 / `DailyCheckController::store` as one explicit route/action owner for `CAP-FLEET-DAILY-VEHICLE-CHECK`. Both candidate reviewers completed independent traces and neither is described as blinded; reviewer B had the prior outcome visible in team status and did not consult the other reviewer. RUN-149/R integrate and independently verify exactly one route record and one controller-action bridge with zero page, frontend-caller, preceding-neighbor, next-row, feature-union, matrix, correctness, or downstream credit. The preceding owner at queue index 79 is not recredited and queue index 81 remains pending.

The cumulative bounded ledger is 663 source owners (306 route + 357 page) across 256 FEATURE-IDs (234 H + 22 D). Route/page feature sets remain 64/242 with overlap 50, and the action-bridge count is 94. Route accounting is 3,218 = 306 owners + 12 shared + 5 aliases + 2,895 residual, with seven evidence gaps tagged within residual. Page accounting remains 711 = 357 owners + 9 shared + 345 residual, with one earlier tagged evidence gap. RUN-090 queue accounting is 507 total, 117 reviewed, 95 owned, 10 shared, 5 aliases, 0 dead, 7 evidence gaps, 390 pending, and 412 without ownership.

Four separate RUN-148R observations remain provisional source observations, not final findings: exact daily-check mutation capability is unproved; canonical approved-Site vehicle/direct-object concealment is unproved; template authority and same-day concurrency/uniqueness are unproved; and no exact selected-POST mutation test was found or executed. Four correctness-only expansion files and four requested-but-not-fully-inspected areas authorize no correctness or final-finding credit. These observations do not alter the separate 12 provisional finding records. Framework reachability, Site/permission/privacy/direct-object/template/concurrency/audit correctness, runtime, build, application browser, executed tests, benchmark-final, Pass, final-finding, feature-completion, and audit-completion gates remain open or zero-credit.
"""
    text = replace_exact(text, old, new, "module-map current ownership lineage")
    write_lf(relative, text)


def update_gaps() -> None:
    relative = "13-unresolved-questions-and-evidence-gaps.md"
    text = read_lf(relative)
    text = replace_line(text, "| Required reporting paths |", "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-147 independently verified the exact now-superseded RUN-146 dashboard at four viewports; the regenerated RUN-150 dashboard requires a separate fresh RUN-151 audit-artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |", "required reporting row")
    text = replace_line(text, "| Runtime routes |", "| Runtime routes | RUN-149/R preserve 306 bounded route-owner records and 94 static controller-action bridges; 2,895 residual route rows, 12 semantic-shared route rows, and 5 reviewed aliases remain distinguished within the bounded 3,218-row static route-like universe, with 7 evidence gaps tagged inside residual. | Wave 25 adds exactly one reviewed `fleet-assets.daily-check.store` POST owner and one bridge. Static owner/action linkage is not a framework-expanded route table, reachability proof, exact mutation-capability proof, approved-Site/canonical-vehicle/direct-object proof, template-authority or same-day concurrency proof, audit/event durability proof, or authorization proof. | Under a fresh bounded runtime grant, use an exact disposable database, execute a separately pinned daily-check mutation lane with representative role/Site/direct-ID/concurrency negatives, and reconcile it to all 38 source route files and 3,218 route-like rows. |", "runtime route row")
    text = replace_line(text, "| Inertia pages |", "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-149/R preserve 357 bounded page owners, nine semantic-shared roots, and 345 residual roots including one earlier tagged evidence gap. | Wave 25 adds zero page owner. The direct frontend submitter, preceding owner at queue index 79, and next pending queue index 81 are explicit noninheritance context. Full-tree structural GO and bounded ownership are not a complete crosswalk, runtime reachability, build resolution, rendered browser behaviour, or final feature mapping. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |", "inertia page row")
    text = replace_line(text, "| Canonical features |", "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-149/R establish 663 bounded source-owner records (306 routes + 357 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 94 controller-action bridges while the matrix remains at `3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0`. | This is 16.874523% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,266 non-owner records, the framework-expanded denominator, shared, alias, and gap relations, reachability, and the full crosswalk remain open. Four RUN-148R observations remain provisional-not-final with zero correctness/final-finding credit; the separate provisional finding register remains 12. Exactly 2/340 targets retain static benchmark-mapping credit; 338 remain unresolved and 0/340 have final no-match/NCM credit. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, siblings, callers, or presence, and without awarding runtime, browser, test, benchmark-final, ease, release, or completion credit. |", "canonical feature row")
    text = replace_line(text, "| Agent universe and writer rule |", "| Agent universe and writer rule | RUN-001 through RUN-150 represented at the current reporting checkpoint; finalization gate false. | RUN-141/R–147 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints. RUN-148/R review one Fleet daily-check POST action; RUN-149/R independently integrate and verify one route owner and one bridge while preserving four provisional-not-final observations, exact nonblinding disclosure, preceding/page/frontend/next noninheritance, and every correctness boundary; RUN-150 reports only that bounded class. Runtime, browser, tests, 338 benchmark targets, Pass 8 finalization, and completion remain open. | Complete residual ownership and every semantic/execution/benchmark gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |", "agent universe row")
    text = replace_exact(text, "## RUN-077–143 route/page, page-tree, backend, ownership and reporting lineage", "## RUN-077–150 route/page, page-tree, backend, ownership and reporting lineage", "lineage heading")
    old_paragraph = "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–140 successively review, integrate, report, and verify bounded route/action and page ownership, reaching 661 owners while preserving explicit shared, alias, and gap outcomes. RUN-141/R review finance.api.sites.overview as one explicit route/action owner. RUN-142/R integrate and independently verify exactly one route owner and one controller-action bridge, preserve 24 expansion files, one correction, 17 assurance mappings, nine action and three shared findings without correctness credit, and reach 662 owners; RUN-143 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, approved-Site/permission/privacy/direct-object/query/projection/period/allocation/reversal/utility-sign/minimization/lifecycle/concurrency/event/durability correctness, runtime, build, signed-in application browser, executed tests, benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    new_paragraph = "RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–147 successively review, integrate, report, benchmark-map, and verify bounded route/action and page ownership, reaching 662 owners while preserving explicit shared, alias, gap, and benchmark boundaries. RUN-148/R review `fleet-assets.daily-check.store` as one explicit route/action owner. RUN-149/R integrate and independently verify exactly one route owner and one controller-action bridge, preserve four provisional-not-final source observations and four correctness-only expansion files without correctness credit, and reach 663 owners; RUN-150 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete; Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, approved-Site/permission/privacy/direct-object/template/concurrency/audit correctness, runtime, build, signed-in application browser, executed tests, remaining benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit."
    text = replace_exact(text, old_paragraph, new_paragraph, "gaps lineage paragraph")
    write_lf(relative, text)


def update_findings(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    relative = "findings.json"
    data = strict_json(relative)
    data["generated_on"] = "2026-08-27"
    pins = data["pins"]
    pins.update({
        "run_147_dashboard_verification_sha256": RUN147_SHA256,
        "run_148_fleet_daily_check_cohort_generator_sha256": LINEAGE["generators/build-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.py"],
        "run_148_fleet_daily_check_cohort_sha256": LINEAGE[COHORT],
        "run_148r_fleet_daily_check_review_materializer_sha256": LINEAGE["generators/materialize-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.py"],
        "run_148r_fleet_daily_check_review_sha256": LINEAGE[CANDIDATE_REVIEW],
        "run_149_fleet_daily_check_overlay_generator_sha256": LINEAGE["generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"],
        "run_149_fleet_daily_check_overlay_sha256": LINEAGE[OVERLAY],
        "run_149r_fleet_daily_check_overlay_review_materializer_sha256": LINEAGE["generators/materialize-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.py"],
        "run_149r_fleet_daily_check_overlay_review_sha256": LINEAGE[OVERLAY_REVIEW],
        "run_150_reporting_materializer_sha256": sha256_file(MATERIALIZER),
    })
    counts = data["counts"]
    counts.update({
        "static_source_feature_ownership_records": 663,
        "static_source_feature_ownership_route_records": 306,
        "static_source_feature_ownership_page_records": 357,
        "static_source_feature_ownership_distinct_feature_ids": 256,
        "static_controller_action_bridges": 94,
        "bounded_static_source_ownership_percent": "16.874523",
        "bounded_static_source_residual_records": 3266,
        "direct_exact_queue_pending_unreviewed": 390,
        "static_source_feature_ownership_distinct_H_feature_ids": 234,
        "static_source_feature_ownership_distinct_D_feature_ids": 22,
        "static_source_feature_ownership_route_distinct_feature_ids": 64,
        "static_source_feature_ownership_page_distinct_feature_ids": 242,
        "static_source_feature_ownership_route_page_feature_overlap": 50,
        "direct_exact_queue_reviewed": 117,
        "direct_exact_queue_owned": 95,
        "direct_exact_queue_shared": 10,
        "direct_exact_queue_without_ownership": 412,
        "direct_exact_queue_alias": 5,
        "direct_exact_queue_dead_or_noncanonical": 0,
        "direct_exact_queue_evidence_gap": 7,
        "benchmark_mapped": 2,
        "final_no_match": 0,
        "benchmark_unresolved": 338,
    })
    assert counts["provisional_source_claims"] == counts["provisional_P1"] == 12
    assert counts["final_P0"] == counts["final_P1"] == 0 and len(data["records"]) == 12
    queue = data["current_direct_exact_route_page_review_queue"]
    queue.update({
        "reviewed_queue_surfaces": 117,
        "owned_queue_surfaces": 95,
        "shared_queue_surfaces": 10,
        "alias_queue_surfaces": 5,
        "dead_or_noncanonical_queue_surfaces": 0,
        "evidence_gap_queue_surfaces": 7,
        "pending_unreviewed": 390,
        "without_ownership": 412,
        "reconciled_through_overlay_run_id": overlay["run_id"],
        "reconciled_through_review_run_id": review["run_id"],
    })
    history = data["current_audit_artifact_verification_history"]
    history["run_147"] = {
        "run_id": "RUN-147-AUDIT-DASHBOARD-VERIFICATION-WAVE-24",
        "receipt_sha256": RUN147_SHA256,
        "status": "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT",
        "viewports": "4/4",
        "navigation": "10/10",
        "unique_local_links": "355/355",
        "visible_boundary_checks": "25/25",
        "application_browser_credit": False,
        "audit_complete": False,
        "superseded_by_run_150_dashboard": True,
    }
    prior_current = data.get("current_static_source_feature_ownership")
    if isinstance(prior_current, dict) and "FINANCE-SITE-PORTFOLIO" in str(prior_current.get("run_id", "")):
        data.setdefault("historical_run_142_outcome_neutral_finance_site_portfolio_overview_route_action_ownership", prior_current)
    prior_review = data.pop("current_outcome_neutral_finance_site_portfolio_overview_route_action_ownership_review", None)
    if prior_review is not None:
        data.setdefault("historical_run_142_outcome_neutral_finance_site_portfolio_overview_route_action_ownership_review", prior_review)
    current_overlay = deepcopy(overlay)
    current_overlay["review_run_id"] = review["run_id"]
    current_overlay["independent_overlay_review_summary"] = {
        "decision": review["decision"],
        "review_records": review["review_records"],
        "synthesis_review": review["synthesis_review"],
        "verified_reviewer_lineage": review["verified_reviewer_lineage"],
        "credit_boundary": review["credit_boundary"],
    }
    data["current_static_source_feature_ownership"] = current_overlay
    data["current_outcome_neutral_fleet_daily_vehicle_check_store_route_action_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "decision": review["decision"],
        "review_records": review["review_records"],
        "synthesis_review": review["synthesis_review"],
        "verified_counts": review["verified_counts"],
        "verified_queue_accounting": review["verified_queue_accounting"],
        "verified_reviewer_lineage": review["verified_reviewer_lineage"],
        "verified_provisional_assurance_observations": review["verified_provisional_assurance_observations"],
        "verified_noninheritance": review["verified_noninheritance"],
        "credit_boundary": review["credit_boundary"],
    }
    data["current_provisional_source_observations"] = {
        "run_id": overlay["run_id"],
        **deepcopy(overlay["provisional_assurance_observation_preservation"]),
        "separate_from_provisional_finding_records": True,
        "provisional_finding_record_count_unchanged": 12,
    }
    write_lf(relative, json.dumps(data, ensure_ascii=False, indent=2) + "\n")


def update_dashboard_builder() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = read_lf(relative)
    load_old = 'run_146_reporting = read_json("evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json")'
    load_new = load_old + '''\ndashboard_run_147 = read_json("evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json")
fleet_daily_check_cohort = read_json("evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json")
fleet_daily_check_review = read_json("evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json")
reviewed_fleet_daily_check_overlay = read_json("evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json")
reviewed_fleet_daily_check_overlay_review = read_json("evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json")'''
    text = replace_exact(text, load_old, load_new, "dashboard lineage loads")
    hash_old = 'assert sha256_file("evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json") == "50953b6281cf198f6dc6ff56027d0eebe7e78697781d459dd620ed9bb2b1277e"'
    hash_new = hash_old + '''\nassert sha256_file("evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json") == "36e0595b3e90f439770c9e8aadbb01555591c79e38ffac54d3cfd6dc3b892cc0"
assert sha256_file("evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json") == "621c1794a73e232b6fc9ff8d2b81ac9ae31ea2ccfe9f038ae77afe332b3ab28d"
assert sha256_file("evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json") == "6720a7570f7f0547fca222758c0632cb7514d953a20605e7c00d6ce88efc18b2"
assert sha256_file("evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json") == "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55"
assert sha256_file("evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json") == "545694fc1b7bd5f4af244617fb421ece1265fe6e6f2cad2ca834115e7a9e75a2"'''
    text = replace_exact(text, hash_old, hash_new, "dashboard lineage hashes")
    validation_anchor = "assert 413 == 391 + 10 + 5 + 0 + 7"
    validation_block = validation_anchor + '''\nfleet_counts = reviewed_fleet_daily_check_overlay["combined_counts"]
fleet_queue = reviewed_fleet_daily_check_overlay["queue_accounting"]
assert (fleet_counts["source_owner_records"], fleet_counts["route_owner_records"], fleet_counts["page_owner_records"], fleet_counts["static_controller_action_bridges"]) == (663, 306, 357, 94)
assert (fleet_counts["distinct_feature_ids"], fleet_counts["distinct_H_feature_ids"], fleet_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (fleet_counts["route_distinct_feature_ids"], fleet_counts["page_distinct_feature_ids"], fleet_counts["route_page_feature_overlap"]) == (64, 242, 50)
assert fleet_counts["bounded_static_source_ownership_percent"] == "16.874523"
assert (fleet_counts["bounded_static_source_residual_records"], fleet_counts["residual_explicit_unmapped_routes"]) == (3266, 2895)
assert (fleet_queue["reviewed_queue_surface_rows"], fleet_queue["pending_unreviewed_queue_surface_rows"], fleet_queue["owner_queue_surface_rows"], fleet_queue["queue_surfaces_without_ownership"]) == (117, 390, 95, 412)
assert 3929 == 663 + 3266 and 663 == 306 + 357 and 256 == 64 + 242 - 50
assert 3218 == 306 + 12 + 5 + 2895 and 711 == 357 + 9 + 345
assert 507 == 117 + 390 and 117 == 95 + 10 + 5 + 0 + 7 and 412 == 390 + 10 + 5 + 0 + 7
assert reviewed_fleet_daily_check_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_fleet_daily_check_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_fleet_daily_check_overlay_review["decision"]["gate_4_complete"] is False
assert reviewed_fleet_daily_check_overlay["noninheritance_boundary"] == {"preceding_index_79_owner_not_inherited_or_recredited": True, "page_owner_not_inherited_or_recredited": True, "frontend_caller_not_inherited_or_recredited": True, "next_index_81_not_selected_or_credited": True, "current_overlay_correctness_and_downstream_credit": False}
fleet_observations = reviewed_fleet_daily_check_overlay["provisional_assurance_observation_preservation"]
assert fleet_observations["observation_count"] == 4
assert all(row["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING" for row in fleet_observations["observations"])
assert all(not row["correctness_credit_authorized"] and not row["final_finding_credit_authorized"] for row in fleet_observations["observations"])
assert len(reviewed_fleet_daily_check_overlay["source_packet_expansion_preservation"]["ownership_material_expansion"]) == 0
assert len(reviewed_fleet_daily_check_overlay["source_packet_expansion_preservation"]["correctness_only_expanded_files"]) == 4
fleet_disclosure = reviewed_fleet_daily_check_overlay["reviewer_lineage"]["nonblinding_disclosure_preserved"]
assert fleet_disclosure == {"review_a_blinded": False, "review_a_prior_outcome_visible_in_team_status": False, "review_b_blinded": False, "review_b_prior_outcome_visible_in_team_status": True, "reviewers_consulted_each_other": False, "both_completed_independent_evidence_traces": True}'''
    text = replace_exact(text, validation_anchor, validation_block, "dashboard RUN149 validation")
    checkpoint_old = '    ("RUN-146 audit-dashboard benchmark refresh materializer", "generators/materialize-run-146-audit-dashboard-benchmark-refresh-wave-24.py"),'
    checkpoint_new = checkpoint_old + '''\n    ("RUN-147 verified superseded RUN-146 dashboard receipt materializer", "generators/materialize-run-147-audit-dashboard-verification-wave-24.py"),
    ("RUN-147 verified superseded RUN-146 dashboard receipt", "evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json"),
    ("RUN-148 Fleet daily-check cohort generator", "generators/build-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.py"),
    ("RUN-148 Fleet daily-check cohort", "evidence/source/root-run-148-outcome-neutral-fleet-daily-vehicle-check-store-route-action-cohort-wave-25.json"),
    ("RUN-148R Fleet daily-check candidate-review materializer", "generators/materialize-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.py"),
    ("RUN-148R Fleet daily-check candidate review", "evidence/source/raw-run-148r-independent-outcome-neutral-fleet-daily-vehicle-check-store-route-action-review-wave-25.json"),
    ("RUN-149 Fleet daily-check overlay generator", "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"),
    ("RUN-149 Fleet daily-check one-route one-bridge overlay", "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"),
    ("RUN-149R Fleet daily-check overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.py"),
    ("RUN-149R Fleet daily-check overlay review", "evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json"),
    ("RUN-150 Fleet daily-check reporting materializer", "generators/materialize-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.py"),
    ("RUN-150 Fleet daily-check reporting receipt", "evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json"),'''
    text = replace_exact(text, checkpoint_old, checkpoint_new, "dashboard checkpoint lineage")

    # Current aggregate substitutions must now come from RUN149, while the
    # Site-portfolio-specific historical context remains pinned to RUN142.
    for field in (
        "source_owner_records", "route_owner_records", "page_owner_records", "distinct_feature_ids",
        "distinct_H_feature_ids", "distinct_D_feature_ids", "route_distinct_feature_ids",
        "page_distinct_feature_ids", "route_page_feature_overlap", "static_controller_action_bridges",
        "bounded_static_source_residual_records", "bounded_static_source_ownership_percent",
        "residual_explicit_unmapped_routes", "semantic_shared_routes", "reviewed_alias_routes",
        "semantic_shared_page_roots", "residual_unadjudicated_page_roots",
        "evidence_gap_page_roots_tagged_within_residual", "evidence_gap_routes_tagged_within_residual",
    ):
        text = text.replace(f'reviewed_finance_site_portfolio_overview_overlay["combined_counts"]["{field}"]', f'reviewed_fleet_daily_check_overlay["combined_counts"]["{field}"]')
        text = text.replace(f"reviewed_finance_site_portfolio_overview_overlay['combined_counts']['{field}']", f"reviewed_fleet_daily_check_overlay['combined_counts']['{field}']")
    for field in (
        "evidence_gap_queue_surface_rows", "direct_exact_queue_records", "reviewed_queue_surface_rows",
        "pending_unreviewed_queue_surface_rows", "queue_surfaces_without_ownership", "owner_queue_surface_rows",
        "shared_queue_surface_rows", "alias_queue_surface_rows",
    ):
        text = text.replace(f'reviewed_finance_site_portfolio_overview_overlay["queue_accounting"]["{field}"]', f'reviewed_fleet_daily_check_overlay["queue_accounting"]["{field}"]')

    text = text.replace('<a href="#checkpoint">RUN-146</a>', '<a href="#checkpoint">RUN-150</a>')
    text = text.replace("RUN-071–146 current reporting checkpoint:", "RUN-071–150 current reporting checkpoint:")
    text = text.replace("RUN-071–146 evidence lineage", "RUN-071–150 evidence lineage")
    text = text.replace("RUN-071–146 completion-gate checkpoint", "RUN-071–150 completion-gate checkpoint")
    text = text.replace("RUN-001 through RUN-146 are represented by audit artifacts.", "RUN-001 through RUN-150 are represented by audit artifacts.")
    text = text.replace("Every current raw, generated, reviewed, and integrated RUN-077–146 source/reporting/benchmark artifact", "Every current raw, generated, reviewed, and integrated RUN-077–150 source/reporting/benchmark artifact")
    text = text.replace("RUN-143 reports only that bounded ownership delta; RUN-144 verifies its exact audit dashboard; RUN-145 adds exactly two independently adjudicated static benchmark mappings; and RUN-146 materializes the current reporting.", "RUN-143/R–147 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints. RUN-148/R review one Fleet daily-check POST owner; RUN-149/R add exactly one route owner and one bridge with four provisional-not-final observations and every correctness boundary false; RUN-150 materializes the current reporting.")
    text = text.replace("RUN-142/R establish $static_owner_records bounded source-owner records", "RUN-149/R establish $static_owner_records bounded source-owner records")
    text = text.replace("RUN-142/R current Finance Site-portfolio API route/action ownership", "RUN-149/R current Fleet daily-check POST route/action ownership")
    text = text.replace("RUN-142/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one finance.api.sites.overview JSON route owner and one bridge, preserving 24 expansion files, 17 assurance mappings, nine action and three shared findings, inheriting or recrediting no page/sibling/caller/neighbor/next-row ownership, and adding zero feature-union or matrix credit", "RUN-149/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one fleet-assets.daily-check.store POST owner and one bridge, preserving four provisional-not-final observations, inheriting or recrediting no preceding/page/frontend/next-row ownership, and adding zero feature-union, matrix, correctness, or final-finding credit")
    text = text.replace("RUN-149/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one finance.api.sites.overview JSON route owner and one bridge, preserving 24 expansion files, 17 assurance mappings, nine action and three shared findings, inheriting or recrediting no page/sibling/caller/neighbor/next-row ownership, and adding zero feature-union or matrix credit", "RUN-149/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one fleet-assets.daily-check.store POST owner and one bridge, preserving four provisional-not-final observations separately from the 12 provisional findings, inheriting or recrediting no preceding-index-79/page/frontend/next-index-81 ownership, and adding zero feature-union, matrix, correctness, or final-finding credit")
    text = text.replace("Exactly one route owner and one bridge are added; the existing page owner, separate sibling route, three page-path callers, zero exact API frontend callers, reviewed neighbor 79, and pending row 80 are context only, route/page/overlap sets are $route_feature_ids/$page_feature_ids/$route_page_overlap, and 24 expansion files, 17 assurance inputs, nine action findings, and three shared findings retain zero correctness credit.", "Exactly one fleet-assets.daily-check.store POST route owner and one bridge are added; the existing page owner and frontend callers are not inherited, preceding index 79 is not recredited, and next index 81 remains pending. Route/page/overlap sets are $route_feature_ids/$page_feature_ids/$route_page_overlap; four provisional source observations remain separate from the 12 provisional findings and retain zero correctness or final-finding credit.")
    text = text.replace("RUN-071–146 current reporting checkpoint:", "RUN-071–150 current reporting checkpoint:")
    text = text.replace("RUN-141/R–142/R independently review, integrate, and verify one finance.api.sites.overview JSON route owner plus one bridge while preserving 24 expansion files, 17 assurance mappings, nine action plus three shared findings, exact page/sibling/caller/neighbor/next noninheritance, and every correctness boundary, RUN-143 refreshes that ownership reporting, RUN-144 verifies the exact dashboard artifact, RUN-145 completes the bounded two-target Finance benchmark chain, and RUN-146 refreshes current reporting.", "RUN-141/R–147 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints. RUN-148/R independently review one fleet-assets.daily-check.store POST route/action candidate; RUN-149/R add one route owner and one bridge, preserve four provisional-not-final observations separately from 12 provisional findings, disclose reviewer A not blinded with no prior outcome visible and reviewer B not blinded with the prior outcome visible, confirm neither reviewer consulted the other and both completed independent evidence traces, preserve preceding index 79 without recredit and next index 81 pending, and keep every correctness boundary and Gate 4 false. RUN-150 refreshes current reporting.")
    text = text.replace("RUN-141/R–142/R add one independently reviewed finance.api.sites.overview JSON route owner and one bridge while preserving 24 expansion files, 17 assurance mappings, nine action plus three shared findings, exact page/sibling/caller/neighbor/next noninheritance, and every correctness boundary, RUN-143 refreshes ownership reporting, RUN-144 verifies that exact dashboard, RUN-145 adds two static benchmark mappings, and RUN-146 refreshes current reporting.", "RUN-141/R–147 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints; RUN-148/R–149/R add one independently reviewed fleet-assets.daily-check.store POST route owner and one bridge, preserve four provisional-not-final observations separately from 12 provisional findings, preserve preceding index 79 without recredit and next index 81 pending, and keep all correctness boundaries and Gate 4 false; RUN-150 refreshes current reporting.")
    text = text.replace(" · Site-portfolio API wave $finance_site_wave_reviewed = $finance_site_review_owner owner · 1 route row + 1 bridge · 0 page rows · selected JSON action has $finance_site_literal_page_calls literal Inertia callsites and $finance_site_exact_frontend_callers exact frontend callers · $finance_site_existing_page_owners existing page-owner context / $finance_site_sibling_routes separate sibling route / $finance_site_page_callers page-path callers / $finance_site_page_owners_added new owners · excluded neighbor index $finance_site_excluded_neighbor / next pending index $finance_site_next_pending · 24 expansion files (6 existing + 18 new) · 17 assurance mapping inputs · 9 action + 3 shared findings · zero correctness credit", " · Fleet daily-check wave 1 reviewed = 1 owner · 1 route row + 1 bridge · 0 page rows · preceding index 79 not recredited · next index 81 pending · 4 provisional source observations separate from 12 provisional findings · reviewer A not blinded/prior outcome not visible · reviewer B not blinded/prior outcome visible · neither consulted the other · both completed independent evidence traces · zero correctness and final-finding credit")
    evidence_tail = '<li>RUN-147: exact RUN-146 audit-dashboard artifact verification · zero application credit</li><li>RUN-148/R: one fleet-assets.daily-check.store POST candidate independently reviewed · four provisional source observations only</li><li>RUN-149/R: one route owner + one bridge · 663/306/357/94 · 117 reviewed / 390 pending · preceding index 79 not recredited · next index 81 pending · all correctness and Gate 4 credit false</li><li>RUN-150: deterministic current reporting · benchmark 2/340 · 338 unresolved · fresh RUN-151 dashboard verification required</li>'
    while evidence_tail + evidence_tail in text:
        text = text.replace(evidence_tail + evidence_tail, evidence_tail)
    if evidence_tail not in text:
        text = text.replace("<li>RUN-146: deterministic current reporting and dashboard refresh · current matrix $live_matrix_short · register $live_register_short · receipt $run145_receipt_short · every non-mapping credit zero</li>", "<li>RUN-146: deterministic current reporting and dashboard refresh · current matrix $live_matrix_short · register $live_register_short · receipt $run145_receipt_short · every non-mapping credit zero</li>" + evidence_tail)
    old_progress_tail = '<tr><td>RUN-141/R → 142/R current Site-portfolio API route/action overlay</td><td><strong>$finance_site_wave_reviewed reviewed = $finance_site_review_owner owner action · 1 route row · 1 bridge · 0 page rows</strong></td><td class="partial">$static_owner_records cumulative owners · $static_owner_routes routes + $static_owner_pages pages · Gate 4 incomplete</td></tr><tr><td>RUN-143 reporting refresh</td><td><strong>Site-portfolio API route/action overlay reported</strong></td><td class="partial">audit-only historical checkpoint · matrix then byte-identical</td></tr><tr><td>RUN-144 audit-dashboard verification</td><td><strong>4/4 required viewports · 23/23 visible checks · 10/10 navigation</strong></td><td class="partial">exact superseded dashboard artifact only · zero application credit</td></tr><tr><td>RUN-145 Finance benchmark chain</td><td><strong>$benchmark_mapped/340 mapped · $final_no_matches/340 NCM · $benchmark_unresolved unresolved</strong></td><td class="partial">two exact static target mappings only · matrix $live_matrix_short · register $live_register_short</td></tr><tr><td>RUN-146 reporting/dashboard refresh</td><td><strong>current matrix, register, reports and evidence reconciled</strong></td><td class="partial">fresh RUN-147 audit-dashboard verification required · zero application credit</td></tr>'
    new_progress_tail = '<tr><td>RUN-141/R → 142/R historical Site-portfolio API route/action overlay</td><td><strong>$finance_site_wave_reviewed reviewed = $finance_site_review_owner owner action · 1 route row · 1 bridge · 0 page rows</strong></td><td class="partial">662 historical cumulative owners · exact bounded checkpoint</td></tr><tr><td>RUN-143 reporting refresh</td><td><strong>Site-portfolio API route/action overlay reported</strong></td><td class="partial">audit-only historical checkpoint · matrix then byte-identical</td></tr><tr><td>RUN-144 audit-dashboard verification</td><td><strong>4/4 required viewports · 23/23 visible checks · 10/10 navigation</strong></td><td class="partial">exact superseded dashboard artifact only · zero application credit</td></tr><tr><td>RUN-145 Finance benchmark chain</td><td><strong>$benchmark_mapped/340 mapped · $final_no_matches/340 NCM · $benchmark_unresolved unresolved</strong></td><td class="partial">two exact static target mappings only · matrix $live_matrix_short · register $live_register_short</td></tr><tr><td>RUN-146 reporting/dashboard refresh</td><td><strong>matrix, register, reports and evidence reconciled</strong></td><td class="partial">historical reporting checkpoint · zero application credit</td></tr><tr><td>RUN-147 dashboard verification</td><td><strong>exact RUN-146 dashboard verified</strong></td><td class="partial">superseded audit artifact only · zero application credit</td></tr><tr><td>RUN-148/R candidate review</td><td><strong>1 Fleet daily-check POST candidate · 4 provisional observations</strong></td><td class="partial">provisional-not-final · no ownership or correctness credit</td></tr><tr><td>RUN-149/R current Fleet daily-check overlay</td><td><strong>663 owners · 306 routes + 357 pages · 94 bridges</strong></td><td class="partial">117 reviewed / 390 pending · 95 owners / 412 without ownership · Gate 4 false</td></tr><tr><td>RUN-150 current reporting refresh</td><td><strong>16.874523% bounded ownership · 2/340 mapped · 338 unresolved</strong></td><td class="partial">fresh RUN-151 dashboard verification required · every correctness/application/completion credit false</td></tr>'
    text = replace_exact(text, old_progress_tail, new_progress_tail, "dashboard progress tail")
    text = text.replace("no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-146 dashboard.", "no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-150 dashboard.")
    run144_link = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json">Superseded RUN-144 verification GO</a></li>'
    run147_link = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json">Superseded RUN-147 verification GO</a></li>'
    if run147_link not in text:
        text = text.replace(run144_link, run144_link + run147_link)
    text = text.replace("Prior audit-dashboard verification</h2><p>RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, RUN-116, RUN-120, RUN-124, RUN-128, RUN-132, RUN-136, RUN-140, and RUN-144 responsive verification", "Prior audit-dashboard verification</h2><p>RUN-070, RUN-072, RUN-073, RUN-076, RUN-081, RUN-083, RUN-085, RUN-088, RUN-094, RUN-100, RUN-104, RUN-108, RUN-112, RUN-116, RUN-120, RUN-124, RUN-128, RUN-132, RUN-136, RUN-140, RUN-144, and RUN-147 responsive verification")
    old_fresh = '<section class="panel"><h2>Fresh RUN-147 audit-dashboard verification required</h2><p>The exact regenerated RUN-146 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-147 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 662/305/357 ownership, current $benchmark_mapped/340 benchmark mapping, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, exact RUN-145 matrix/register/receipt pins, one operating organisation across multiple Sites, Gate 4 open, and every non-mapping zero-credit boundary. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, feature-completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json">RUN-147 responsive audit-dashboard verification receipt</a> (forward reference until materialized)</li></ul></section>'
    new_fresh = '<section class="panel"><h2>Fresh RUN-151 audit-dashboard verification required</h2><p>The exact regenerated RUN-150 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-151 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 663/306/357 ownership, 94 bridges, 117/390 queue accounting, all four provisional-not-final source observations, exact nonblinding disclosure, current $benchmark_mapped/340 benchmark mapping, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, exact RUN-145 matrix/register/receipt pins, one operating organisation across multiple Sites, Gate 4 open, and every non-mapping zero-credit boundary. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, feature-completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json">RUN-151 responsive audit-dashboard verification receipt</a> (forward reference until materialized)</li></ul></section>'
    text = replace_exact(text, old_fresh, new_fresh, "dashboard fresh verification panel")
    obsolete_observation_panel = '''<section class="panel"><h2>RUN-148R–150 Fleet daily-check ownership and provisional source observations</h2><p><span class="mono">fleet-assets.daily-check.store</span> / <span class="mono">RUN077-ROUTE-0689</span> / <span class="mono">DailyCheckController::store</span> is one bounded static route/action owner for <span class="mono">CAP-FLEET-DAILY-VEHICLE-CHECK</span>. RUN-149 adds one route row and one bridge, zero page rows, and no new FEATURE-ID. Queue index 79 is not recredited; index 81 remains pending.</p><p>Both candidate reviewers completed independent source traces and neither is represented as blinded. Reviewer B had the prior outcome visible in team status, did not consult the other reviewer, and independently traced the evidence. Four correctness-only expansion files and the observations below authorize no correctness or final-finding credit and remain separate from the 12 provisional finding records.</p><ul class="list">$fleet_observation_items</ul></section>
    '''
    text = text.replace(obsolete_observation_panel, "")
    benchmark_panel = '<section class="panel"><h2>RUN-145 current benchmark mapping</h2>'
    observation_panel = '''<section class="panel"><h2>RUN-148R–150 Fleet daily-check ownership and provisional source observations</h2><p><span class="mono">fleet-assets.daily-check.store</span> / <span class="mono">RUN077-ROUTE-0689</span> / <span class="mono">DailyCheckController::store</span> is one bounded static route/action owner for <span class="mono">CAP-FLEET-DAILY-VEHICLE-CHECK</span>. RUN-149 adds one route row and one bridge, zero page rows, and no new FEATURE-ID. Queue index 79 is not recredited; index 81 remains pending.</p><p>Reviewer A was not blinded and did not have the prior outcome visible in team status. Reviewer B was not blinded and did have the prior outcome visible in team status. Neither reviewer consulted the other and both completed independent evidence traces. Four correctness-only expansion files and the observations below authorize no correctness or final-finding credit and remain separate from the 12 provisional finding records.</p><ul class="list">$fleet_observation_items</ul></section>\n    ''' + benchmark_panel
    text = replace_exact(text, benchmark_panel, observation_panel, "dashboard observation panel")
    text = text.replace("Generated deterministically from independently reviewed static evidence through RUN-145 and reported in RUN-146.", "Generated deterministically from independently reviewed static evidence through RUN-149 and reported in RUN-150.")
    substitution_anchor = "dashboard = TEMPLATE.substitute("
    prep = '''fleet_observation_items = "".join(
    f'<li><strong>{html.escape(row["observation_id"])}</strong>: {html.escape(row["observation"])} <span class="mono">PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING</span></li>'
    for row in fleet_observations["observations"]
)


''' + substitution_anchor
    text = replace_exact(text, substitution_anchor, prep, "dashboard observation rendering")
    text = replace_exact(text, "    application_short=canonical[\"source_pin\"][\"application_commit\"][:12],", "    fleet_observation_items=fleet_observation_items,\n    application_short=canonical[\"source_pin\"][\"application_commit\"][:12],", "dashboard observation substitution")
    ast.parse(text)
    write_lf(relative, text)


def write_receipt(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    counts = overlay["combined_counts"] | overlay["queue_accounting"]
    credit_false = [
        "direct_exact_queue_review", "new_queue_review", "new_source_ownership", "new_route_ownership",
        "new_page_ownership", "new_controller_action_bridge", "current_overlay_ownership_credit",
        "complete_route_page_feature_crosswalk", "framework_route_reachability", "matrix_mutation",
        "canonical_object_ownership_correctness", "site_authorization_correctness", "permission_correctness",
        "privacy_correctness", "direct_object_correctness", "template_authority_correctness",
        "concurrency_or_idempotency_correctness", "audit_or_event_durability_correctness", "runtime",
        "database", "build", "application_browser", "responsive_application", "visual_application_workflow",
        "executed_tests", "application_source_mutation", "new_benchmark_mapping", "final_no_match_or_NCM",
        "ease", "release", "pass", "final_finding", "feature_completion", "completion", "audit_complete",
    ]
    credit = {"REPORTING_REFRESH_FOR_REVIEWED_OVERLAY": True, **{key: False for key in credit_false}}
    receipt = {
        "schema_version": "run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25-v1",
        "run_id": RUN_ID,
        "status": "REPORTING_MATERIALIZED_663_OWNERS_4_PROVISIONAL_SOURCE_OBSERVATIONS_ZERO_CORRECTNESS_OR_FINAL_CREDIT",
        "generated_on": "2026-08-27",
        "architecture_rule": {"operating_organisations": 1, "multiple_sites": True, "multi_tenant": False},
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "benchmark_register_sha256": REGISTER_SHA256,
            "run_145_mapping_receipt_sha256": RUN145_SHA256,
            "run_147_dashboard_verification_sha256": RUN147_SHA256,
            "materializer": MATERIALIZER,
            "materializer_sha256": sha256_file(MATERIALIZER),
            "lineage": {relative: {"sha256": value, "blob_id": git("rev-parse", f"HEAD:{PREFIX}/{relative}")} for relative, value in LINEAGE.items()},
        },
        "baseline_outputs": BASELINE_SHA256,
        "outputs": {relative: {"bytes": (AUDIT / relative).stat().st_size, "sha256": sha256_file(relative)} for relative in SURFACES},
        "counts": counts,
        "benchmark_state": {"mapped": 2, "final_no_match_or_NCM": 0, "unresolved": 338},
        "review_preservation": {
            "overlay_run_id": overlay["run_id"],
            "overlay_review_run_id": review["run_id"],
            "independent_overlay_reviews": review["decision"]["independent_reviews"],
            "discrepancies": review["decision"]["discrepancies"],
            "provisional_source_observations": 4,
            "provisional_finding_records_unchanged": 12,
            "final_findings": 0,
            "ownership_material_expansion_files": 0,
            "correctness_only_expansion_files": 4,
            "nonblinding_disclosure": overlay["reviewer_lineage"]["nonblinding_disclosure_preserved"],
        },
        "noninheritance": overlay["noninheritance_boundary"],
        "provisional_source_observations": overlay["provisional_assurance_observation_preservation"],
        "reporting_boundary": {
            "matrix_rows_changed": 0,
            "matrix_cells_changed": 0,
            "benchmark_register_rows_changed": 0,
            "benchmark_register_cells_changed": 0,
            "matrix_mapping_credit": "2/340",
            "final_no_match_or_NCM_credit": "0/340",
            "dashboard_html_changed": False,
            "dashboard_html_sha256_preserved": PRESERVED["audit-dashboard.html"],
            "dashboard_requires_fresh_run_151_artifact_verification": True,
            "gate_4_complete": False,
            "audit_complete": False,
        },
        "verified_overlay_credit_boundary": overlay["credit_boundary"],
        "verified_overlay_review_credit_boundary": review["credit_boundary"],
        "credit_boundary": credit,
        "completion_boundary": {key: False for key in (
            "framework_route_reachability_complete", "semantic_assurance_complete", "execution_complete",
            "benchmark_complete", "pass_8_complete", "final_reconciliation_complete",
            "no_live_agent_gate_complete", "full_crosswalk_complete", "gate_4_complete", "audit_complete",
        )},
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{relative}" for relative in (*SURFACES, MATERIALIZER, OUTPUT)],
    }
    write_lf(OUTPUT, json.dumps(receipt, ensure_ascii=False, indent=2) + "\n")


def validate_outputs() -> None:
    ast.parse(read_lf(MATERIALIZER))
    ast.parse(read_lf("generators/build-current-audit-dashboard.py"))
    findings = strict_json("findings.json")
    receipt = strict_json(OUTPUT)
    assert len(findings["records"]) == 12
    assert findings["counts"]["provisional_P1"] == 12
    assert findings["counts"]["final_P0"] == findings["counts"]["final_P1"] == 0
    assert findings["counts"]["benchmark_mapped"] == 2 and findings["counts"]["benchmark_unresolved"] == 338
    assert receipt["reporting_boundary"]["dashboard_html_changed"] is False
    assert sha256_file("audit-dashboard.html") == PRESERVED["audit-dashboard.html"]
    assert [key for key, value in receipt["credit_boundary"].items() if value] == ["REPORTING_REFRESH_FOR_REVIEWED_OVERLAY"]
    for relative in (*SURFACES, MATERIALIZER, OUTPUT):
        payload = (AUDIT / relative).read_bytes()
        assert payload.endswith(b"\n") and b"\r\n" not in payload and not payload.startswith(b"\xef\xbb\xbf")
    expected = {f"M {PREFIX}/{relative}" for relative in SURFACES}
    expected |= {f"?? {PREFIX}/{MATERIALIZER}", f"?? {PREFIX}/{OUTPUT}"}
    assert {line.lstrip() for line in git("status", "--porcelain").splitlines()} == expected
    assert not list(AUDIT.rglob("__pycache__"))


def main() -> None:
    _, _, overlay, review = validate_inputs()
    update_executive(overlay)
    update_module_map()
    update_gaps()
    update_findings(overlay, review)
    update_dashboard_builder()
    write_receipt(overlay, review)
    validate_outputs()
    print(json.dumps({
        "status": "RUN150_REPORTING_MATERIALIZED",
        "output_sha256": sha256_file(OUTPUT),
        "materializer_sha256": sha256_file(MATERIALIZER),
        "owners": 663,
        "routes": 306,
        "pages": 357,
        "bridges": 94,
        "reviewed_queue": 117,
        "pending_queue": 390,
        "benchmark_mapping": "2/340",
        "dashboard_html_sha256_unchanged": sha256_file("audit-dashboard.html"),
        "fresh_dashboard_verification": "RUN-151",
        "gate_4_complete": False,
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
