#!/usr/bin/env python3
"""Report the independently reviewed RUN153 Fleet vehicle-index overlay."""
from __future__ import annotations

import ast
import csv
from copy import deepcopy
import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


AUDIT = Path(__file__).resolve().parents[1]
ROOT = AUDIT.parents[2]
PREFIX = AUDIT.relative_to(ROOT).as_posix()
RUN_ID = "RUN-154-REVIEWED-FLEET-VEHICLE-REGISTER-INDEX-ROUTE-ACTION-REPORTING-WAVE-26"
MATERIALIZER = "generators/materialize-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.py"
OUTPUT = "evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json"
HEAD = "3e9407f9fac197d3ed075782187c35ee11db4d2e"
TREE = "698d8c810dd00f8cc81d8e90d687bbc31c014ab7"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
MATRIX_SHA256 = "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0"
REGISTER_SHA256 = "5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884"
RUN145_SHA256 = "8306a8aefe0a490ebf206d0c4716d92930326988f19e0ed495a3c2d0002c7cf9"
RUN150 = "evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json"
RUN151 = "evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json"
OVERLAY = "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json"
OVERLAY_REVIEW = "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json"

SURFACES = (
    "00-executive-summary.md",
    "01-repository-module-map.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
    "generators/build-current-audit-dashboard.py",
)

BASELINE_SHA256 = {
    "00-executive-summary.md": "c8b1885704825e69112af6f7830d01be2c34b9e9d97754e0c0986112d0488eb2",
    "01-repository-module-map.md": "3512e7dde9f08ce6cbc59a10c3e5c97c30c053704f87ebe4c3fb10cab7ea4c3a",
    "13-unresolved-questions-and-evidence-gaps.md": "753210c92a2438975b9aadff3d3ae72ea336a8e870b3faa7f204cd9bddcb7efa",
    "findings.json": "43b499788ed5a185a3466198bd707ec11526c0009c91513159c0c18f441dd2f3",
    "generators/build-current-audit-dashboard.py": "a2ae48cbb59ff5c16c56f805095dbda4829575d0f1e7063c76b098b9753fe284",
}

PRESERVED = {
    "audit-dashboard.html": "7d5556d9e94d9f7c480cbad8b5f4fd5a69990080ff4515364d0821e05ab8f56d",
    "03-feature-to-benchmark-matrix.csv": MATRIX_SHA256,
    "06-open-source-benchmark-register.csv": REGISTER_SHA256,
    "evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json": RUN145_SHA256,
}

LINEAGE = {
    "generators/materialize-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.py": "8927a0e203203a739a8c8bb3d0e04dda5f1ebe55a9915fc9fcab6a9c4a73bcc4",
    RUN150: "f5fd2fd59e8cdf26e30343774c7e76ede235a64cc1f6bb447b9867df2c5f30b2",
    "generators/materialize-run-151-audit-dashboard-verification-wave-25.py": "e3f939f00bdf68cc47543e4e75658cbe5c0f7ad096583068ab4950a491cc1fe8",
    RUN151: "15b4ef5de5fc9029af9ff74dcb02dd1e52177695fd367ea9347c3a8b3c9f20c0",
    "generators/build-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.py": "7b3e6501d3fe806e7bb27be8d20236467496e20e101d42a9efc0741e67f0e336",
    "evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json": "5e987d8727896183aadf30b9000ed56b318e2f4c8935b6d77e3600999105eac4",
    "generators/materialize-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.py": "ecf6c7aa7c68d1b7936086316927057726797f7fa61d3b76af0c7435844f4597",
    "evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json": "43697db4e3a5743d6dc9b47a3e80c6ec5c528dba17c2e99a4a13f95933c899d8",
    "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py": "00b90c5932614eaf67cbca29c860924fad67190605bbf476fdc285174831ea83",
    OVERLAY: "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815",
    "generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.py": "6fb94e5382120e4d74b1a4b28fbdc75141e248f4585e850825e6f302d3d741ef",
    OVERLAY_REVIEW: "7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461",
}


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT / relative).read_bytes())


def git(*args: str) -> str:
    return subprocess.run(
        ["git", *args], cwd=ROOT, check=True, capture_output=True, text=True
    ).stdout.strip()


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
    if lines.count(new_line) == 1 and not any(
        line.startswith(prefix) and line != new_line for line in lines
    ):
        return text
    matches = [index for index, line in enumerate(lines) if line.startswith(prefix)]
    assert len(matches) == 1, f"{label}: expected one line beginning {prefix!r}"
    lines[matches[0]] = new_line
    return "\n".join(lines) + ("\n" if text.endswith("\n") else "")


def replace_section(
    text: str,
    old_start: str,
    new_start: str,
    end_marker: str,
    new_section: str,
    label: str,
) -> str:
    if old_start not in text:
        assert text.count(new_start) == 1, f"{label}: neither old nor current section found"
        return text
    assert text.count(old_start) == 1 and text.count(end_marker) == 1, label
    start = text.index(old_start)
    end = text.index(end_marker, start)
    assert start < end
    return text[:start] + new_section.rstrip() + "\n\n" + text[end:]


def validate_inputs() -> tuple[dict[str, Any], dict[str, Any], dict[str, Any]]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert not git(
        "status", "--porcelain", "--", "app", "routes", "resources/js", "tests", "database"
    )

    prior_receipt = strict_json(OUTPUT) if (AUDIT / OUTPUT).exists() else None
    for relative, expected in BASELINE_SHA256.items():
        actual = sha256_file(relative)
        if actual != expected:
            if prior_receipt is None:
                assert relative in {
                    "00-executive-summary.md",
                    "01-repository-module-map.md",
                    "13-unresolved-questions-and-evidence-gaps.md",
                }
                assert "RUN-154" in read_lf(relative)
            else:
                assert actual == prior_receipt["outputs"][relative]["sha256"]
    for relative, expected in PRESERVED.items():
        assert sha256_file(relative) == expected, relative
    for relative, expected in LINEAGE.items():
        assert sha256_file(relative) == expected, relative
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}")

    historical = strict_json(RUN150)
    assert historical["status"] == "REPORTING_MATERIALIZED_663_OWNERS_4_PROVISIONAL_SOURCE_OBSERVATIONS_ZERO_CORRECTNESS_OR_FINAL_CREDIT"
    assert {
        relative: record["sha256"] for relative, record in historical["outputs"].items()
    } == BASELINE_SHA256
    dashboard_review = strict_json(RUN151)
    assert dashboard_review["status"] == "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT"
    assert dashboard_review["pins"]["dashboard_html"]["sha256"] == PRESERVED["audit-dashboard.html"]
    assert dashboard_review["verification"]["viewports_verified"] == 4
    assert dashboard_review["verification"]["navigation_targets"] == "10/10"
    assert dashboard_review["verification"]["unique_local_links"] == 367
    assert len(dashboard_review["verification"]["exact_visible_static_boundary_checks"]) == 42
    assert all(dashboard_review["verification"]["exact_visible_static_boundary_checks"].values())

    overlay = strict_json(OVERLAY)
    review = strict_json(OVERLAY_REVIEW)
    counts = overlay["combined_counts"]
    queue = overlay["queue_accounting"]
    assert (
        counts["source_owner_records"], counts["route_owner_records"],
        counts["page_owner_records"], counts["static_controller_action_bridges"],
    ) == (664, 307, 357, 95)
    assert (
        counts["distinct_feature_ids"], counts["distinct_H_feature_ids"],
        counts["distinct_D_feature_ids"], counts["route_distinct_feature_ids"],
        counts["page_distinct_feature_ids"], counts["route_page_feature_overlap"],
    ) == (256, 234, 22, 64, 242, 50)
    assert counts["bounded_static_source_ownership_percent"] == "16.899975"
    assert (counts["bounded_static_source_residual_records"], counts["residual_explicit_unmapped_routes"]) == (3265, 2894)
    assert (
        queue["reviewed_queue_surface_rows"], queue["pending_unreviewed_queue_surface_rows"],
        queue["owner_queue_surface_rows"], queue["queue_surfaces_without_ownership"],
    ) == (118, 389, 96, 411)
    assert 3929 == 664 + 3265 and 664 == 307 + 357 and 256 == 64 + 242 - 50
    assert 3218 == 307 + 12 + 5 + 0 + 2894 and 711 == 357 + 9 + 0 + 0 + 345
    assert 507 == 118 + 389 and 118 == 96 + 10 + 5 + 0 + 7
    assert 411 == 389 + 10 + 5 + 0 + 7
    observations = overlay["provisional_assurance_observation_preservation"]
    assert observations["observation_count"] == len(observations["observations"]) == 6
    assert all(
        row["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING"
        and not row["correctness_credit_authorized"]
        and not row["final_finding_credit_authorized"]
        for row in observations["observations"]
    )
    boundary = overlay["queue_boundary"]
    assert boundary["preceding_index_80_not_recredited"] is True
    assert boundary["selected_index_81_integrated"] is True
    assert boundary["index_82_reviewed_context_not_recredited"] is True
    assert (boundary["next_unresolved_index"], boundary["next_unresolved_queue_id"]) == (83, "RUN090-ROUTE-0084")
    noninheritance = overlay["noninheritance_boundary"]
    assert noninheritance["page_owner_not_inherited_or_recredited"] is True
    assert noninheritance["historical_sentinel_preserved_not_rewritten_or_credited"] is True
    assert noninheritance["neighbor_identity_or_outcome_not_inherited"] is True
    assert review["decision"]["verdict"] == "GO"
    assert review["decision"]["independent_reviews"] == 3
    assert review["decision"]["discrepancies"] == 0
    assert review["decision"]["reporting_materialization_authorized"] is True
    assert review["decision"]["gate_4_complete"] is False
    assert {key for key, value in review["credit_boundary"].items() if value} == {
        "INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"
    }
    return overlay, review, dashboard_review


def update_executive() -> None:
    relative = "00-executive-summary.md"
    text = read_lf(relative)
    section = """## RUN-113–154 reviewed route/action and page-ownership lineage

RUN-113/R–151 remain historical reviewed ownership, reporting, benchmark-mapping, and exact audit-dashboard checkpoints. RUN-145 preserves exactly two static Finance benchmark mappings; RUN-150 reports the daily-check checkpoint, and RUN-151 verifies only that now-superseded audit dashboard.

RUN-152/R separately review queue index 81: `fleet-assets.vehicles.index`, RUN090-ROUTE-0082 / RUN077-ROUTE-0690, `VehicleController::index`, for `CAP-FLEET-VEHICLE-REGISTER`. Both candidate reviewers completed independent source traces and neither is represented as blinded: reviewer A had prior team-status visibility, reviewer B had prior self-assessment visibility, and neither consulted the other. Existing page-owner context, the historical sentinel, adjacent queue context, and later rows confer no inherited outcome.

RUN-153 integrates exactly one route owner and one controller-action bridge, with zero page-owner, correctness, runtime, browser, test, finding, NCM, or completion credit. RUN-153R independently verifies the immutable producer bytes, lineage, self-seals, accounting, Site/noninheritance boundaries, and authorizes reporting only. Six observations remain `PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING`: ordinary-viewer list/export/filter Site scope, aggregate scope, live telemetry privacy, authority, adjacent-show concealment nontransfer, and negative-path execution remain unproved. They do not alter the separate 12 provisional finding records.

The current bounded checkpoint is **664 records = 307 routes + 357 pages across 256 canonical FEATURE-IDs (234 H + 22 D)**, with 95 controller-action bridges. Route and page owners span 64 and 242 FEATURE-IDs with 50 in their overlap. This is 16.899975% of the bounded 3,929-record source universe; 3,265 records remain. The route universe is **3,218 = 307 owners + 12 shared + 5 aliases + 2,894 residual**, with seven evidence gaps tagged inside that residual. The page universe remains **711 = 357 owners + 9 shared + 345 residual**, with one earlier evidence gap tagged inside its residual. Queue accounting is **507 = 118 reviewed + 389 pending**; reviewed rows are 96 owned, 10 shared, 5 aliases, 0 dead, and 7 evidence gaps, while 411 remain without ownership.

RUN-154 reports only that bounded one-action delta. The exact regenerated dashboard requires a fresh RUN-155 audit-artifact receipt. Oblivion Findings remains one operating organisation across multiple Sites. Framework reachability, navigation, approved-Site access, roles/permissions, canonical ownership, direct-object concealment, privacy, query/projection/aggregate/telemetry correctness, runtime, database, build, application browser, tests, ease, release, Passes, final findings, completion, and Gate 4 remain separate open or zero-credit gates. RUN-145 benchmark state remains exactly 2/340 mapped, 0/340 final no-match/NCM, and 338 unresolved.
"""
    text = replace_section(
        text,
        "## RUN-113–150 reviewed route/action and page-ownership lineage",
        "## RUN-113–154 reviewed route/action and page-ownership lineage",
        "## RUN-144–145 dashboard and benchmark-mapping checkpoint",
        section,
        "executive current ownership lineage",
    )
    evidence = """- `generators/materialize-run-151-audit-dashboard-verification-wave-25.py` and `evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json`: exact now-superseded RUN-150 dashboard verification at four viewports with 42/42 visible boundaries, 10/10 navigation, 367/367 unique local links, and zero application credit.
- `generators/build-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.py` and `evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json`: exact zero-credit Fleet vehicle-register index route/action review cohort.
- `generators/materialize-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.py` and `evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json`: two independent owner reviews plus synthesis, exact nonblinding disclosure, six provisional-not-final source observations, and zero current-overlay or correctness credit.
- `generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py` and `evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json`: exact one-route and one-bridge Fleet vehicle-register index static-only overlay with page/sentinel/neighbor noninheritance.
- `generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.py` and `evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json`: three-part exact-byte, identity, accounting, provenance, Site-boundary, observation, and noninheritance GO receipt.
- `generators/materialize-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.py` and `evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json`: deterministic RUN-154 reporting refresh preserving benchmark 2/340, the 12 provisional findings, six separate provisional source observations, and every downstream zero-credit boundary.
"""
    marker = "- `evidence/source/raw-run-073a-required-artifact-contract-wave-05.json`:"
    if evidence.strip() not in text:
        assert text.count(marker) == 1
        text = text.replace(marker, evidence + marker, 1)
    old_dashboard = "- `audit-dashboard.html`: progress dashboard generated from current structured evidence through RUN-150 only after the updated builder is executed. The currently committed RUN-146 HTML remains byte-identical during RUN-150; the regenerated artifact requires a separate fresh RUN-151 viewport/link/anchor/console receipt and cannot award application-browser or downstream credit."
    new_dashboard = "- `audit-dashboard.html`: the RUN-150 dashboard was verified by RUN-151 and remains byte-identical during RUN-154. The regenerated RUN-154 artifact requires a separate fresh RUN-155 viewport/link/anchor/console receipt and cannot award application-browser or downstream credit."
    text = replace_exact(text, old_dashboard, new_dashboard, "executive dashboard catalogue")
    write_lf(relative, text)


def update_module_map() -> None:
    relative = "01-repository-module-map.md"
    text = read_lf(relative)
    section = """## RUN-113–154 reviewed route/action and page-ownership lineage

RUN-113/R–151 remain historical reviewed ownership, reporting, benchmark-mapping, and exact-dashboard checkpoints. RUN-152/R independently review `fleet-assets.vehicles.index` / RUN077-ROUTE-0690 / `VehicleController::index` for `CAP-FLEET-VEHICLE-REGISTER`. Neither candidate reviewer is described as blinded: reviewer A had prior team-status visibility, reviewer B had prior self-assessment visibility, and neither consulted the other.

RUN-153/R integrate and independently verify exactly one route record and one controller-action bridge with zero page, sentinel, neighbor, feature-union, matrix, correctness, or downstream credit. Queue index 81 is selected, index 82 is context only, and the next unresolved row remains index 83. Existing page-owner and historical sentinel records are preserved without recredit or rewrite.

The cumulative bounded ledger is 664 source owners (307 route + 357 page) across 256 FEATURE-IDs (234 H + 22 D), with 95 action bridges. Route accounting is 3,218 = 307 owners + 12 shared + 5 aliases + 2,894 residual; page accounting is 711 = 357 owners + 9 shared + 345 residual. Queue accounting is 507 total, 118 reviewed, 96 owned, 10 shared, 5 aliases, 0 dead, 7 evidence gaps, 389 pending, and 411 without ownership.

Six RUN-152R observations remain provisional source observations, not final findings: list/export/filter Site scope, aggregate scope, live telemetry privacy, authority, show-concealment nontransfer, and ordinary-viewer negative-path execution remain unproved. They do not alter the separate 12 provisional finding records. RUN-154 reports the static overlay only; framework reachability, approved-Site/permission/privacy/direct-object/query/telemetry correctness, runtime, build, application browser, executed tests, benchmark-final, Pass, final-finding, feature-completion, and audit-completion gates remain open or zero-credit.
"""
    text = replace_section(
        text,
        "## RUN-113–150 reviewed route/action and page-ownership lineage",
        "## RUN-113–154 reviewed route/action and page-ownership lineage",
        "## Candidate register",
        section,
        "module-map current ownership lineage",
    )
    write_lf(relative, text)


def update_gaps() -> None:
    relative = "13-unresolved-questions-and-evidence-gaps.md"
    text = read_lf(relative)
    text = replace_line(
        text,
        "| Required reporting paths |",
        "| Required reporting paths | 18 / 18 prompt-required files or directories present. RUN-151 independently verified the exact now-superseded RUN-150 dashboard at four viewports; the regenerated RUN-154 dashboard requires a separate fresh RUN-155 audit-artifact receipt. | Presence and prior audit-artifact verification make the reporting contract inspectable but grant no application execution, final-finding, Pass, or completion credit. Gate 26 remains open. | Complete the semantic, execution, benchmark, Pass 8, final reconciliation, and no-live-agent gates; repeat audit-dashboard verification after every later HTML change. |",
        "gaps reporting path",
    )
    text = replace_line(
        text,
        "| Runtime routes |",
        "| Runtime routes | RUN-153/R preserve 307 bounded route-owner records and 95 static controller-action bridges; 2,894 residual route rows, 12 semantic-shared route rows, and 5 reviewed aliases remain distinguished within the bounded 3,218-row static route-like universe, with 7 evidence gaps tagged inside residual. | Wave 26 adds exactly one reviewed `fleet-assets.vehicles.index` owner and one bridge. Static owner/action linkage is not framework reachability, approved-Site list/export/filter scope, exact permissions, canonical ownership, direct-object concealment, aggregate/telemetry privacy correctness, or execution proof. | Under a fresh bounded runtime grant, use an exact disposable database and representative role/Site fixtures to execute list/export/filter/aggregate/telemetry negatives, then reconcile the results to all 38 route files and 3,218 route-like rows. |",
        "gaps runtime routes",
    )
    text = replace_line(
        text,
        "| Inertia pages |",
        "| Inertia pages | RUN-084/R enumerate 1,058 physical page-tree files. RUN-153/R preserve 357 bounded page owners, nine semantic-shared roots, and 345 residual roots including one earlier tagged evidence gap. | Wave 26 adds zero page owner. Existing page-owner context, the historical route sentinel, and neighboring queue rows are explicit noninheritance context. Full-tree structural GO and bounded ownership are not a complete crosswalk, runtime reachability, build resolution, or rendered browser behaviour. | Reconcile all 711 literal roots and support relations to safely expanded framework routes and frozen FEATURE-IDs, prove build resolution, and observe every safely reachable current-build page under approved roles/Sites. |",
        "gaps pages",
    )
    text = replace_line(
        text,
        "| Canonical features |",
        "| Canonical features | Static canonical identity remains 340 targets: 300 H, 40 D, zero M; RUN-153/R establish 664 bounded source-owner records (307 routes + 357 pages) across 256 FEATURE-IDs (234 H + 22 D) plus 95 controller-action bridges while the matrix remains at `3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0`. | This is 16.899975% of the bounded 3,929-record source universe. Gate 4 remains incomplete: 3,265 non-owner records, framework expansion, shared/alias/gap relations, reachability, and the full crosswalk remain open. Six RUN-152R observations remain provisional-not-final with zero correctness/final-finding credit; the separate provisional finding register remains 12. Exactly 2/340 targets retain static benchmark-mapping credit; 338 remain unresolved and 0/340 have final no-match/NCM credit. | Finish canonical denominator and residual ownership adjudication without inheriting FEATURE-IDs from prefixes, imports, containment, names, siblings, callers, or presence, and without awarding runtime, browser, test, benchmark-final, ease, release, or completion credit. |",
        "gaps canonical features",
    )
    text = replace_line(
        text,
        "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-154 represented at the current reporting checkpoint; finalization gate false. | RUN-151 preserves the exact superseded RUN-150 dashboard receipt. RUN-152/R review one Fleet vehicle-register index action; RUN-153/R independently integrate and verify one route owner and one bridge while preserving six provisional-not-final observations, exact nonblinding disclosure, page/sentinel/neighbor noninheritance, and every correctness boundary; RUN-154 reports only that bounded class. Runtime, browser, tests, 338 benchmark targets, Pass 8 finalization, and completion remain open. | Complete residual ownership and every semantic/execution/benchmark gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
        "gaps agent universe",
    )
    section = """## RUN-077–154 route/page, page-tree, backend, ownership and reporting lineage

RUN-077 freezes the exhaustive committed-source route/name/page universe through RUN-084B's page-tree and backend structural ledgers. RUN-086/R establish the initial 530 bounded source owners; RUN-090 freezes the zero-credit direct-exact queue; RUN-091/R–151 successively review, integrate, report, benchmark-map, and verify bounded route/action and page ownership, reaching 663 owners while preserving explicit shared, alias, gap, benchmark, and exact-dashboard boundaries. RUN-152/R review `fleet-assets.vehicles.index` as one explicit route/action owner. RUN-153/R integrate and independently verify exactly one route owner and one controller-action bridge, preserve six provisional-not-final source observations without correctness credit, and reach 664 owners; RUN-154 reports only that bounded delta. Gate 4 and the full route/page/backend crosswalk remain incomplete. Oblivion Findings remains one operating organisation across multiple Sites, and framework reachability, approved-Site/permission/privacy/direct-object/query/aggregate/telemetry correctness, runtime, build, signed-in application browser, executed tests, remaining benchmark mapping, ease, release, Pass, final-finding, and completion remain zero-credit.
"""
    text = replace_section(
        text,
        "## RUN-077–150 route/page, page-tree, backend, ownership and reporting lineage",
        "## RUN-077–154 route/page, page-tree, backend, ownership and reporting lineage",
        "## Current provisional source findings",
        section,
        "gaps current ownership lineage",
    )
    write_lf(relative, text)


def update_findings(
    overlay: dict[str, Any], review: dict[str, Any], dashboard_review: dict[str, Any]
) -> None:
    relative = "findings.json"
    data = strict_json(relative)
    records_before = deepcopy(data["records"])
    data["generated_on"] = "2026-08-29"
    pins = data["pins"]
    pins.update({
        "run_151_dashboard_verification_sha256": LINEAGE[RUN151],
        "run_152_fleet_vehicle_register_index_cohort_generator_sha256": LINEAGE["generators/build-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.py"],
        "run_152_fleet_vehicle_register_index_cohort_sha256": LINEAGE["evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json"],
        "run_152r_fleet_vehicle_register_index_review_materializer_sha256": LINEAGE["generators/materialize-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.py"],
        "run_152r_fleet_vehicle_register_index_review_sha256": LINEAGE["evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json"],
        "run_153_fleet_vehicle_register_index_overlay_generator_sha256": LINEAGE["generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py"],
        "run_153_fleet_vehicle_register_index_overlay_sha256": LINEAGE[OVERLAY],
        "run_153r_fleet_vehicle_register_index_overlay_review_materializer_sha256": LINEAGE["generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.py"],
        "run_153r_fleet_vehicle_register_index_overlay_review_sha256": LINEAGE[OVERLAY_REVIEW],
        "run_154_reporting_materializer_sha256": sha256_file(MATERIALIZER),
    })
    counts = data["counts"]
    counts.update({
        "static_source_feature_ownership_records": 664,
        "static_source_feature_ownership_route_records": 307,
        "static_source_feature_ownership_page_records": 357,
        "static_source_feature_ownership_distinct_feature_ids": 256,
        "static_controller_action_bridges": 95,
        "bounded_static_source_ownership_percent": "16.899975",
        "bounded_static_source_residual_records": 3265,
        "bounded_static_source_residual_route_records": 2894,
        "direct_exact_queue_pending_unreviewed": 389,
        "static_source_feature_ownership_distinct_H_feature_ids": 234,
        "static_source_feature_ownership_distinct_D_feature_ids": 22,
        "static_source_feature_ownership_route_distinct_feature_ids": 64,
        "static_source_feature_ownership_page_distinct_feature_ids": 242,
        "static_source_feature_ownership_route_page_feature_overlap": 50,
        "direct_exact_queue_reviewed": 118,
        "direct_exact_queue_owned": 96,
        "direct_exact_queue_shared": 10,
        "direct_exact_queue_without_ownership": 411,
        "direct_exact_queue_alias": 5,
        "direct_exact_queue_dead_or_noncanonical": 0,
        "direct_exact_queue_evidence_gap": 7,
        "benchmark_mapped": 2,
        "final_no_match": 0,
        "benchmark_unresolved": 338,
    })
    assert counts["provisional_source_claims"] == counts["provisional_P1"] == 12
    assert counts["final_P0"] == counts["final_P1"] == 0
    queue = data["current_direct_exact_route_page_review_queue"]
    queue.update({
        "reviewed_queue_surfaces": 118,
        "owned_queue_surfaces": 96,
        "shared_queue_surfaces": 10,
        "alias_queue_surfaces": 5,
        "dead_or_noncanonical_queue_surfaces": 0,
        "evidence_gap_queue_surfaces": 7,
        "pending_unreviewed": 389,
        "without_ownership": 411,
        "reconciled_through_overlay_run_id": overlay["run_id"],
        "reconciled_through_review_run_id": review["run_id"],
    })
    history = data["current_audit_artifact_verification_history"]
    history["run_151"] = {
        "run_id": dashboard_review["run_id"],
        "receipt_sha256": LINEAGE[RUN151],
        "status": dashboard_review["status"],
        "viewports": "4/4",
        "navigation": "10/10",
        "unique_local_links": "367/367",
        "visible_boundary_checks": "42/42",
        "application_browser_credit": False,
        "audit_complete": False,
        "superseded_by_run_154_dashboard": True,
    }
    prior_current = data.get("current_static_source_feature_ownership")
    if isinstance(prior_current, dict) and "RUN-149-" in str(prior_current.get("run_id", "")):
        data.setdefault(
            "historical_run_149_outcome_neutral_fleet_daily_vehicle_check_store_route_action_ownership",
            prior_current,
        )
    prior_review = data.pop(
        "current_outcome_neutral_fleet_daily_vehicle_check_store_route_action_ownership_review",
        None,
    )
    if prior_review is not None:
        data.setdefault(
            "historical_run_149_outcome_neutral_fleet_daily_vehicle_check_store_route_action_ownership_review",
            prior_review,
        )
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
    data["current_outcome_neutral_fleet_vehicle_register_index_route_action_ownership_review"] = {
        "run_id": review["run_id"],
        "status": review["status"],
        "decision": review["decision"],
        "review_records": review["review_records"],
        "synthesis_review": review["synthesis_review"],
        "verified_counts": review["verified_counts"],
        "verified_queue_accounting": review["verified_queue_accounting"],
        "verified_reviewer_lineage": review["verified_reviewer_lineage"],
        "verified_provisional_assurance_observations": review["verified_provisional_assurance_observations"],
        "verified_queue_boundary": review["verified_queue_boundary"],
        "verified_noninheritance": review["verified_noninheritance"],
        "credit_boundary": review["credit_boundary"],
    }
    data["current_provisional_source_observations"] = {
        "run_id": overlay["run_id"],
        **deepcopy(overlay["provisional_assurance_observation_preservation"]),
        "separate_from_provisional_finding_records": True,
        "provisional_finding_record_count_unchanged": 12,
    }
    assert data["records"] == records_before and len(data["records"]) == 12
    write_lf(relative, json.dumps(data, ensure_ascii=False, indent=2) + "\n")


def update_dashboard_builder() -> None:
    relative = "generators/build-current-audit-dashboard.py"
    text = read_lf(relative)
    loader_anchor = 'reviewed_fleet_daily_check_overlay_review = read_json("evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json")'
    loader_block = loader_anchor + '''
run_150_reporting = read_json("evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json")
dashboard_run_151 = read_json("evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json")
fleet_vehicle_register_cohort = read_json("evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json")
fleet_vehicle_register_review = read_json("evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json")
reviewed_fleet_vehicle_register_overlay = read_json("evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json")
reviewed_fleet_vehicle_register_overlay_review = read_json("evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json")
run_154_reporting = read_json("evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json")'''
    text = replace_exact(text, loader_anchor, loader_block, "dashboard RUN150-154 loaders")

    hash_anchor = 'assert sha256_file("evidence/source/current-run-149r-independent-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-review-wave-25.json") == "545694fc1b7bd5f4af244617fb421ece1265fe6e6f2cad2ca834115e7a9e75a2"'
    hash_block = hash_anchor + '''
assert sha256_file("evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json") == "f5fd2fd59e8cdf26e30343774c7e76ede235a64cc1f6bb447b9867df2c5f30b2"
assert sha256_file("evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json") == "15b4ef5de5fc9029af9ff74dcb02dd1e52177695fd367ea9347c3a8b3c9f20c0"
assert sha256_file("evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json") == "5e987d8727896183aadf30b9000ed56b318e2f4c8935b6d77e3600999105eac4"
assert sha256_file("evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json") == "43697db4e3a5743d6dc9b47a3e80c6ec5c528dba17c2e99a4a13f95933c899d8"
assert sha256_file("evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json") == "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815"
assert sha256_file("evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json") == "7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461"'''
    text = replace_exact(text, hash_anchor, hash_block, "dashboard RUN150-153R hashes")

    validation_anchor = 'assert fleet_disclosure == {"review_a_blinded": False, "review_a_prior_outcome_visible_in_team_status": False, "review_b_blinded": False, "review_b_prior_outcome_visible_in_team_status": True, "reviewers_consulted_each_other": False, "both_completed_independent_evidence_traces": True}'
    validation_block = validation_anchor + '''
assert run_150_reporting["outputs"] == {
    "00-executive-summary.md": {"bytes": 102408, "sha256": "c8b1885704825e69112af6f7830d01be2c34b9e9d97754e0c0986112d0488eb2"},
    "01-repository-module-map.md": {"bytes": 29677, "sha256": "3512e7dde9f08ce6cbc59a10c3e5c97c30c053704f87ebe4c3fb10cab7ea4c3a"},
    "13-unresolved-questions-and-evidence-gaps.md": {"bytes": 26253, "sha256": "753210c92a2438975b9aadff3d3ae72ea336a8e870b3faa7f204cd9bddcb7efa"},
    "findings.json": {"bytes": 426548, "sha256": "43b499788ed5a185a3466198bd707ec11526c0009c91513159c0c18f441dd2f3"},
    "generators/build-current-audit-dashboard.py": {"bytes": 332610, "sha256": "a2ae48cbb59ff5c16c56f805095dbda4829575d0f1e7063c76b098b9753fe284"},
}
# RUN-150 output hashes close that immutable historical receipt; RUN-154
# intentionally advances the live reporting surfaces and never compares them.
assert dashboard_run_151["verification"]["viewports_verified"] == 4
assert dashboard_run_151["verification"]["navigation_targets"] == "10/10"
assert dashboard_run_151["verification"]["unique_local_links"] == 367
assert len(dashboard_run_151["verification"]["exact_visible_static_boundary_checks"]) == 42
vehicle_counts = reviewed_fleet_vehicle_register_overlay["combined_counts"]
vehicle_queue = reviewed_fleet_vehicle_register_overlay["queue_accounting"]
assert (vehicle_counts["source_owner_records"], vehicle_counts["route_owner_records"], vehicle_counts["page_owner_records"], vehicle_counts["static_controller_action_bridges"]) == (664, 307, 357, 95)
assert (vehicle_counts["distinct_feature_ids"], vehicle_counts["distinct_H_feature_ids"], vehicle_counts["distinct_D_feature_ids"]) == (256, 234, 22)
assert (vehicle_counts["route_distinct_feature_ids"], vehicle_counts["page_distinct_feature_ids"], vehicle_counts["route_page_feature_overlap"]) == (64, 242, 50)
assert (vehicle_counts["bounded_static_source_residual_records"], vehicle_counts["residual_explicit_unmapped_routes"], vehicle_counts["bounded_static_source_ownership_percent"]) == (3265, 2894, "16.899975")
assert (vehicle_queue["reviewed_queue_surface_rows"], vehicle_queue["pending_unreviewed_queue_surface_rows"], vehicle_queue["owner_queue_surface_rows"], vehicle_queue["queue_surfaces_without_ownership"]) == (118, 389, 96, 411)
assert 3929 == 664 + 3265 and 664 == 307 + 357 and 256 == 64 + 242 - 50
assert 3218 == 307 + 12 + 5 + 0 + 2894 and 711 == 357 + 9 + 0 + 0 + 345
assert 507 == 118 + 389 and 118 == 96 + 10 + 5 + 0 + 7 and 411 == 389 + 10 + 5 + 0 + 7
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["verdict"] == "GO"
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["independent_reviews"] == 3
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["discrepancies"] == 0
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["reporting_materialization_authorized"] is True
assert reviewed_fleet_vehicle_register_overlay_review["decision"]["gate_4_complete"] is False
vehicle_observations = reviewed_fleet_vehicle_register_overlay["provisional_assurance_observation_preservation"]
assert vehicle_observations["observation_count"] == 6
assert all(row["status"] == "PROVISIONAL_SOURCE_OBSERVATION_NOT_FINAL_FINDING" and not row["correctness_credit_authorized"] and not row["final_finding_credit_authorized"] for row in vehicle_observations["observations"])
assert reviewed_fleet_vehicle_register_overlay["noninheritance_boundary"]["page_owner_not_inherited_or_recredited"] is True
assert reviewed_fleet_vehicle_register_overlay["noninheritance_boundary"]["historical_sentinel_preserved_not_rewritten_or_credited"] is True
assert reviewed_fleet_vehicle_register_overlay["noninheritance_boundary"]["neighbor_identity_or_outcome_not_inherited"] is True
assert reviewed_fleet_vehicle_register_overlay["queue_boundary"]["selected_index_81_integrated"] is True
assert reviewed_fleet_vehicle_register_overlay["queue_boundary"]["index_82_reviewed_context_not_recredited"] is True
assert reviewed_fleet_vehicle_register_overlay["queue_boundary"]["next_unresolved_index"] == 83
reviewed_fleet_daily_check_overlay = reviewed_fleet_vehicle_register_overlay
fleet_observations = vehicle_observations'''
    text = replace_exact(text, validation_anchor, validation_block, "dashboard RUN153 validations")

    tuple_anchor = '    ("RUN-150 Fleet daily-check reporting receipt", "evidence/source/current-run-150-reviewed-fleet-daily-vehicle-check-store-route-action-reporting-wave-25.json"),'
    tuple_block = tuple_anchor + '''
    ("RUN-151 superseded RUN-150 dashboard verification materializer", "generators/materialize-run-151-audit-dashboard-verification-wave-25.py"),
    ("RUN-151 superseded RUN-150 dashboard verification", "evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json"),
    ("RUN-152 Fleet vehicle-register cohort generator", "generators/build-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.py"),
    ("RUN-152 Fleet vehicle-register cohort", "evidence/source/root-run-152-outcome-neutral-fleet-vehicle-register-index-route-action-cohort-wave-26.json"),
    ("RUN-152R Fleet vehicle-register review materializer", "generators/materialize-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.py"),
    ("RUN-152R Fleet vehicle-register review", "evidence/source/raw-run-152r-independent-outcome-neutral-fleet-vehicle-register-index-route-action-review-wave-26.json"),
    ("RUN-153 Fleet vehicle-register overlay generator", "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.py"),
    ("RUN-153 Fleet vehicle-register one-route one-bridge overlay", "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json"),
    ("RUN-153R Fleet vehicle-register overlay-review materializer", "generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.py"),
    ("RUN-153R Fleet vehicle-register overlay review", "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json"),
    ("RUN-154 Fleet vehicle-register reporting materializer", "generators/materialize-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.py"),
    ("RUN-154 Fleet vehicle-register reporting receipt", "evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json"),'''
    text = replace_exact(text, tuple_anchor, tuple_block, "dashboard RUN151-154 evidence tuples")

    text = replace_exact(text, '<a href="#checkpoint">RUN-150</a>', '<a href="#checkpoint">RUN-154</a>', "dashboard navigation")
    text = replace_exact(text, "RUN-149/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly one fleet-assets.daily-check.store POST route owner and one bridge are added; the existing page owner and frontend callers are not inherited, preceding index 79 is not recredited, and next index 81 remains pending. Route/page/overlap sets are $route_feature_ids/$page_feature_ids/$route_page_overlap; four provisional source observations remain separate from the 12 provisional findings and retain zero correctness or final-finding credit.", "RUN-153/R establish $static_owner_records bounded source-owner records ($static_owner_routes routes + $static_owner_pages pages) across $static_owner_features FEATURE-IDs plus $static_action_bridges action bridges. Exactly one fleet-assets.vehicles.index route owner and one bridge are added; existing page-owner and sentinel context are not inherited or recredited, index 82 is context only, and index 83 remains unresolved. Route/page/overlap sets are $route_feature_ids/$page_feature_ids/$route_page_overlap; six provisional source observations remain separate from the 12 provisional findings and retain zero correctness or final-finding credit.", "dashboard hero current overlay")
    text = replace_exact(text, '<strong>RUN-071–150 current reporting checkpoint:</strong>', '<strong>RUN-071–154 current reporting checkpoint:</strong>', "dashboard reporting notice")
    text = replace_exact(text, "RUN-148/R review one Fleet daily-check POST owner; RUN-149/R add exactly one route owner and one bridge with four provisional-not-final observations and every correctness boundary false; RUN-150 materializes the current reporting.", "RUN-151 preserves the exact superseded dashboard receipt; RUN-152/R review one Fleet vehicle-register index owner; RUN-153/R add exactly one route owner and one bridge with six provisional-not-final observations and every correctness boundary false; RUN-154 materializes the current reporting.", "dashboard notice tail")
    text = replace_exact(text, "<h2>RUN-071–150 completion-gate checkpoint</h2>", "<h2>RUN-071–154 completion-gate checkpoint</h2>", "dashboard checkpoint heading")
    text = replace_exact(text, "RUN-141/R–147 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints. RUN-148/R independently review one fleet-assets.daily-check.store POST route/action candidate; RUN-149/R add one route owner and one bridge, preserve four provisional-not-final observations separately from 12 provisional findings, disclose reviewer A not blinded with no prior outcome visible and reviewer B not blinded with the prior outcome visible, confirm neither reviewer consulted the other and both completed independent evidence traces, preserve preceding index 79 without recredit and next index 81 pending, and keep every correctness boundary and Gate 4 false. RUN-150 refreshes current reporting.", "RUN-141/R–151 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints. RUN-152/R independently review one fleet-assets.vehicles.index route/action candidate; both reviewers are non-blinded, reviewer A had prior team-status visibility, reviewer B had prior self-assessment visibility, neither consulted the other, and both completed independent evidence traces. RUN-153/R add one route owner and one bridge, preserve six provisional-not-final observations separately from 12 provisional findings, preserve page/sentinel/neighbor noninheritance, and keep every correctness boundary and Gate 4 false. RUN-154 refreshes current reporting.", "dashboard overview lineage")

    progress_old = '<tr><td>RUN-148/R candidate review</td><td><strong>1 Fleet daily-check POST candidate · 4 provisional observations</strong></td><td class="partial">provisional-not-final · no ownership or correctness credit</td></tr><tr><td>RUN-149/R current Fleet daily-check overlay</td><td><strong>663 owners · 306 routes + 357 pages · 94 bridges</strong></td><td class="partial">117 reviewed / 390 pending · 95 owners / 412 without ownership · Gate 4 false</td></tr><tr><td>RUN-150 current reporting refresh</td><td><strong>16.874523% bounded ownership · 2/340 mapped · 338 unresolved</strong></td><td class="partial">fresh RUN-151 dashboard verification required · every correctness/application/completion credit false</td></tr>'
    progress_new = '<tr><td>RUN-151 dashboard verification</td><td><strong>exact RUN-150 dashboard verified at 4/4 viewports</strong></td><td class="partial">superseded audit artifact only · zero application credit</td></tr><tr><td>RUN-152/R candidate review</td><td><strong>1 Fleet vehicle-register index candidate · 6 provisional observations</strong></td><td class="partial">provisional-not-final · no ownership or correctness credit</td></tr><tr><td>RUN-153/R current Fleet vehicle-register overlay</td><td><strong>664 owners · 307 routes + 357 pages · 95 bridges</strong></td><td class="partial">118 reviewed / 389 pending · 96 owners / 411 without ownership · Gate 4 false</td></tr><tr><td>RUN-154 current reporting refresh</td><td><strong>16.899975% bounded ownership · 2/340 mapped · 338 unresolved</strong></td><td class="partial">fresh RUN-155 dashboard verification required · every correctness/application/completion credit false</td></tr>'
    text = replace_exact(text, progress_old, progress_new, "dashboard progress tail")

    evidence_old = '<li>RUN-147: exact RUN-146 audit-dashboard artifact verification · zero application credit</li><li>RUN-148/R: one fleet-assets.daily-check.store POST candidate independently reviewed · four provisional source observations only</li><li>RUN-149/R: one route owner + one bridge · 663/306/357/94 · 117 reviewed / 390 pending · preceding index 79 not recredited · next index 81 pending · all correctness and Gate 4 credit false</li><li>RUN-150: deterministic current reporting · benchmark 2/340 · 338 unresolved · fresh RUN-151 dashboard verification required</li>'
    evidence_new = '<li>RUN-147: exact RUN-146 audit-dashboard artifact verification · zero application credit</li><li>RUN-148/R–150: historical Fleet daily-check ownership and reporting checkpoint</li><li>RUN-151: exact RUN-150 dashboard verified at 4/4 viewports · zero application credit</li><li>RUN-152/R: one fleet-assets.vehicles.index candidate independently reviewed · six provisional source observations only</li><li>RUN-153/R: one route owner + one bridge · 664/307/357/95 · 118 reviewed / 389 pending · page/sentinel/neighbor noninheritance · all correctness and Gate 4 credit false</li><li>RUN-154: deterministic current reporting · benchmark 2/340 · 338 unresolved · fresh RUN-155 dashboard verification required</li>'
    text = replace_exact(text, evidence_old, evidence_new, "dashboard evidence-wave tail")

    census_old = "RUN-141/R–147 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints; RUN-148/R–149/R add one independently reviewed fleet-assets.daily-check.store POST route owner and one bridge, preserve four provisional-not-final observations separately from 12 provisional findings, preserve preceding index 79 without recredit and next index 81 pending, and keep all correctness boundaries and Gate 4 false; RUN-150 refreshes current reporting."
    census_new = "RUN-141/R–151 preserve the Site-portfolio, benchmark, reporting, and exact-dashboard checkpoints; RUN-152/R–153/R add one independently reviewed fleet-assets.vehicles.index route owner and one bridge, preserve six provisional-not-final observations separately from 12 provisional findings, preserve page/sentinel/neighbor noninheritance, and keep all correctness boundaries and Gate 4 false; RUN-154 refreshes current reporting."
    text = replace_exact(text, census_old, census_new, "dashboard static-census intro")
    row_start = '<tr><td>RUN-149/R current Fleet daily-check POST route/action ownership'
    row_end = '<tr><td>RUN-090 direct-exact queue'
    if row_start in text:
        start = text.index(row_start)
        end = text.index(row_end, start)
        new_row = '<tr><td>RUN-153/R current Fleet vehicle-register index route/action ownership</td><td>$static_owner_records = $static_owner_routes route + $static_owner_pages page · $static_owner_features FEATURE-IDs = $static_owner_h_features H + $static_owner_d_features D · $static_action_bridges action bridges</td><td class="partial">$ownership_percent% of bounded 3,929 · $static_residual residual · features $route_feature_ids route / $page_feature_ids page / $route_page_overlap overlap · routes 3,218 = $static_owner_routes owner + $route_shared_current shared + $route_alias_current alias + $route_residual residual with $finance_site_route_gap tagged gaps · pages 711 = $static_owner_pages owner + $page_shared shared + $page_residual residual with $page_gap tagged gap · Fleet vehicle-register wave 1 reviewed = 1 owner · 1 route row + 1 bridge · 0 page rows · page owner and historical sentinel not recredited · index 82 context only · index 83 unresolved · 6 provisional source observations separate from 12 provisional findings · both reviewers non-blinded with disclosed prior visibility · neither consulted the other · zero correctness and final-finding credit · Gate 4 incomplete · ownership/linkage fields unchanged by RUN-145 benchmark-only mapping</td></tr>'
        text = text[:start] + new_row + text[end:]
    else:
        assert text.count('<tr><td>RUN-153/R current Fleet vehicle-register index route/action ownership') == 1

    old_panel_start = '<section class="panel"><h2>RUN-148R–150 Fleet daily-check ownership and provisional source observations</h2>'
    new_panel_start = '<section class="panel"><h2>RUN-152R–154 Fleet vehicle-register ownership and provisional source observations</h2>'
    if old_panel_start in text:
        start = text.index(old_panel_start)
        end = text.index('<section class="panel"><h2>RUN-145 current benchmark mapping</h2>', start)
        panel = '<section class="panel"><h2>RUN-152R–154 Fleet vehicle-register ownership and provisional source observations</h2><p><span class="mono">fleet-assets.vehicles.index</span> / <span class="mono">RUN077-ROUTE-0690</span> / <span class="mono">VehicleController::index</span> is one bounded static route/action owner for <span class="mono">CAP-FLEET-VEHICLE-REGISTER</span>. RUN-153 adds one route row and one bridge, zero page rows, and no new FEATURE-ID. Existing page-owner and historical-sentinel context are not recredited; index 82 is context only and index 83 remains unresolved.</p><p>Both reviewers completed independent evidence traces and neither is represented as blinded. Reviewer A had prior team-status visibility; reviewer B had prior self-assessment visibility; neither consulted the other. The six observations below authorize no correctness or final-finding credit and remain separate from the 12 provisional finding records.</p><ul class="list">$fleet_observation_items</ul></section>\n    '
        text = text[:start] + panel + text[end:]
    else:
        assert text.count(new_panel_start) == 1

    text = replace_exact(text, "RUN-140, RUN-144, and RUN-147 responsive verification", "RUN-140, RUN-144, RUN-147, and RUN-151 responsive verification", "dashboard prior verification list")
    text = replace_exact(text, "current RUN-150 dashboard", "current RUN-154 dashboard", "dashboard prior verification transfer")
    text = replace_exact(text, "RUN-001 through RUN-150 are represented by audit artifacts.", "RUN-001 through RUN-154 are represented by audit artifacts.", "dashboard represented-wave range")
    run147_link = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json">Superseded RUN-147 verification GO</a></li>'
    run151_link = '<li><a href="evidence/browser/current-audit-dashboard-verification-run-151-wave-25.json">Superseded RUN-151 verification GO</a></li>'
    if run151_link not in text:
        text = replace_exact(text, run147_link, run147_link + run151_link, "dashboard prior RUN151 link")

    fresh_start = '<section class="panel"><h2>Fresh RUN-151 audit-dashboard verification required</h2>'
    fresh_new_start = '<section class="panel"><h2>Fresh RUN-155 audit-dashboard verification required</h2>'
    lineage_start = '<section class="panel"><h2>RUN-071–150 evidence lineage</h2>'
    if fresh_start in text:
        start = text.index(fresh_start)
        end = text.index(lineage_start, start)
        fresh = '<section class="panel"><h2>Fresh RUN-155 audit-dashboard verification required</h2><p>The exact regenerated RUN-154 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-155 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 664/307/357 ownership, 95 bridges, 118/389 queue accounting, all six provisional-not-final source observations, exact nonblinding disclosure, current $benchmark_mapped/340 benchmark mapping, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, exact RUN-145 matrix/register/receipt pins, one operating organisation across multiple Sites, Gate 4 open, and every non-mapping zero-credit boundary. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, feature-completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json">RUN-155 responsive audit-dashboard verification receipt</a> (forward reference until materialized)</li></ul></section>\n    '
        text = text[:start] + fresh + text[end:]
    else:
        assert text.count(fresh_new_start) == 1
    text = replace_exact(text, '<section class="panel"><h2>RUN-071–150 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–150 source/reporting/benchmark artifact', '<section class="panel"><h2>RUN-071–154 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–154 source/reporting/benchmark artifact', "dashboard evidence lineage heading")
    text = replace_exact(text, "RUN-149/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one fleet-assets.daily-check.store POST owner and one bridge, preserving four provisional-not-final observations separately from the 12 provisional findings, inheriting or recrediting no preceding-index-79/page/frontend/next-index-81 ownership, and adding zero feature-union, matrix, correctness, or final-finding credit; complete the framework-expanded canonical route/page denominator", "RUN-153/R establish $static_owner_records bounded source-owner records and $static_action_bridges action bridges while adding one fleet-assets.vehicles.index owner and one bridge, preserving six provisional-not-final observations separately from the 12 provisional findings, inheriting or recrediting no page-owner, historical-sentinel, neighbor, or index-82 context and leaving index 83 unresolved, and adding zero feature-union, matrix, correctness, or final-finding credit; complete the framework-expanded canonical route/page denominator", "dashboard open-gate current overlay")
    text = replace_exact(text, "Generated deterministically from independently reviewed static evidence through RUN-149 and reported in RUN-150.", "Generated deterministically from independently reviewed static evidence through RUN-153 and reported in RUN-154.", "dashboard footer")
    text = replace_exact(text, 'f".{output_path.name}.tmp-run146-dashboard"', 'f".{output_path.name}.tmp-run154-dashboard"', "dashboard temporary suffix")
    ast.parse(text)
    write_lf(relative, text)


def write_receipt(overlay: dict[str, Any], review: dict[str, Any]) -> None:
    counts = overlay["combined_counts"] | overlay["queue_accounting"]
    false_keys = (
        "direct_exact_queue_review", "new_queue_review", "new_source_ownership",
        "new_route_ownership", "new_page_ownership", "new_controller_action_bridge",
        "current_overlay_ownership_credit", "complete_route_page_feature_crosswalk",
        "framework_route_reachability", "matrix_mutation",
        "canonical_object_ownership_correctness", "site_authorization_correctness",
        "permission_correctness", "privacy_correctness", "direct_object_correctness",
        "query_projection_aggregate_or_telemetry_correctness", "runtime", "database",
        "build", "application_browser", "responsive_application",
        "visual_application_workflow", "executed_tests", "application_source_mutation",
        "new_benchmark_mapping", "final_no_match_or_NCM", "ease", "release", "pass",
        "final_finding", "feature_completion", "completion", "audit_complete",
    )
    credit = {"REPORTING_REFRESH_FOR_REVIEWED_OVERLAY": True, **{key: False for key in false_keys}}
    receipt = {
        "schema_version": "run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26-v1",
        "run_id": RUN_ID,
        "status": "REPORTING_MATERIALIZED_664_OWNERS_6_PROVISIONAL_SOURCE_OBSERVATIONS_ZERO_CORRECTNESS_OR_FINAL_CREDIT",
        "generated_on": "2026-08-29",
        "architecture_rule": {"operating_organisations": 1, "multiple_sites": True},
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "matrix_sha256": MATRIX_SHA256,
            "benchmark_register_sha256": REGISTER_SHA256,
            "run_145_mapping_receipt_sha256": RUN145_SHA256,
            "run_151_dashboard_verification_sha256": LINEAGE[RUN151],
            "materializer": MATERIALIZER,
            "materializer_sha256": sha256_file(MATERIALIZER),
            "lineage": {
                relative: {
                    "sha256": value,
                    "blob_id": git("rev-parse", f"HEAD:{PREFIX}/{relative}"),
                }
                for relative, value in LINEAGE.items()
            },
        },
        "baseline_outputs": BASELINE_SHA256,
        "outputs": {
            relative: {"bytes": (AUDIT / relative).stat().st_size, "sha256": sha256_file(relative)}
            for relative in SURFACES
        },
        "counts": counts,
        "benchmark_state": {"mapped": 2, "final_no_match_or_NCM": 0, "unresolved": 338},
        "review_preservation": {
            "overlay_run_id": overlay["run_id"],
            "overlay_review_run_id": review["run_id"],
            "independent_overlay_reviews": review["decision"]["independent_reviews"],
            "discrepancies": review["decision"]["discrepancies"],
            "provisional_source_observations": 6,
            "provisional_finding_records_unchanged": 12,
            "final_findings": 0,
            "nonblinding_disclosure_preserved_from_exact_reviewer_records": True,
        },
        "queue_boundary": overlay["queue_boundary"],
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
            "dashboard_requires_fresh_run_155_artifact_verification": True,
            "gate_4_complete": False,
            "audit_complete": False,
        },
        "verified_overlay_credit_boundary": overlay["credit_boundary"],
        "verified_overlay_review_credit_boundary": review["credit_boundary"],
        "credit_boundary": credit,
        "completion_boundary": {
            key: False for key in (
                "framework_route_reachability_complete", "semantic_assurance_complete",
                "execution_complete", "benchmark_complete", "pass_8_complete",
                "final_reconciliation_complete", "no_live_agent_gate_complete",
                "full_crosswalk_complete", "gate_4_complete", "audit_complete",
            )
        },
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
    assert findings["counts"]["benchmark_mapped"] == 2
    assert findings["counts"]["benchmark_unresolved"] == 338
    assert findings["counts"]["static_source_feature_ownership_records"] == 664
    assert findings["counts"]["static_controller_action_bridges"] == 95
    assert receipt["reporting_boundary"]["dashboard_html_changed"] is False
    assert sha256_file("audit-dashboard.html") == PRESERVED["audit-dashboard.html"]
    assert [key for key, value in receipt["credit_boundary"].items() if value] == [
        "REPORTING_REFRESH_FOR_REVIEWED_OVERLAY"
    ]
    for relative in (*SURFACES, MATERIALIZER, OUTPUT):
        payload = (AUDIT / relative).read_bytes()
        assert payload.endswith(b"\n") and b"\r\n" not in payload
        assert not payload.startswith(b"\xef\xbb\xbf")
    expected = {
        f"M {PREFIX}/{relative}" for relative in SURFACES
    } | {f"?? {PREFIX}/{MATERIALIZER}", f"?? {PREFIX}/{OUTPUT}"}
    assert {line.lstrip() for line in git("status", "--porcelain").splitlines()} == expected
    assert not list(AUDIT.rglob("__pycache__"))


def main() -> None:
    overlay, review, dashboard_review = validate_inputs()
    update_executive()
    update_module_map()
    update_gaps()
    update_findings(overlay, review, dashboard_review)
    update_dashboard_builder()
    write_receipt(overlay, review)
    validate_outputs()
    print(json.dumps({
        "status": "RUN154_REPORTING_MATERIALIZED",
        "output_sha256": sha256_file(OUTPUT),
        "materializer_sha256": sha256_file(MATERIALIZER),
        "owners": 664,
        "routes": 307,
        "pages": 357,
        "bridges": 95,
        "reviewed_queue": 118,
        "pending_queue": 389,
        "benchmark_mapping": "2/340",
        "dashboard_html_sha256_unchanged": sha256_file("audit-dashboard.html"),
        "fresh_dashboard_verification": "RUN-155",
        "gate_4_complete": False,
        "audit_complete": False,
    }, indent=2))


if __name__ == "__main__":
    main()
