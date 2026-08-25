#!/usr/bin/env python3
"""Build the deterministic RUN-078B static classification producer packet.

The producer reviews partition B of the pinned RUN-077 manifest. It is a
source-only audit collector: candidate overlap is not promoted unless an exact
literal name/page binding satisfies the conservative rules below, and no
runtime, browser, test-execution, benchmark, Pass, or completion credit is
awarded.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
import subprocess
from collections import Counter
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
PARTITION_ID = "B"

CHECKPOINT_COMMIT = "87826adc6fb8c9f0b1ca5ea99dcdc06e32bbd6d0"
CHECKPOINT_TREE = "d1eb36fabc0f5150c81f2140e834347dca87dd25"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"

MANIFEST_REL = "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json"
MANIFEST_SHA256 = "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be"
MATRIX_REL = "03-feature-to-benchmark-matrix.csv"
GENERATOR_REL = "generators/build-run-078b-route-page-classification-wave-07.py"
OUTPUT_REL = "evidence/source/raw-run-078b-route-page-classification-wave-07.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL
GENERATED_ON = "2026-08-25T12:15:00+12:00"

EXPECTED_COUNTS = {
    "route_decisions": 1073,
    "name_decisions": 1068,
    "page_decisions": 237,
    "residual_scoped_decisions": 4,
    "route_name_gap_decisions": 81,
}

EXPLICIT_ROUTE_ANCHOR_RE = re.compile(
    r"^(routes/[^:]+):(\d+)(?:-(\d+))?$"
)


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def utf8_key(value: str) -> bytes:
    return value.encode("utf-8")


def unique_sorted(values: list[str]) -> list[str]:
    result = sorted(set(values), key=utf8_key)
    assert all(result)
    return result


def git_text(*args: str) -> str:
    return subprocess.check_output(
        ["git", *args], cwd=REPO_DIR, text=True, encoding="utf-8"
    ).strip()


def current_generator_sha256() -> str:
    return sha256_file(Path(__file__).resolve())


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def read_matrix() -> tuple[list[dict[str, str]], dict[str, dict[str, str]]]:
    with (AUDIT_DIR / MATRIX_REL).open(newline="", encoding="utf-8-sig") as handle:
        rows = list(csv.DictReader(handle))
    assert len(rows) == 340
    by_id = {row["feature_id"]: row for row in rows}
    assert len(by_id) == 340
    return rows, by_id


def require_segment(
    relative: str, start: int, end: int, required_terms: list[str]
) -> str:
    path = REPO_DIR / relative
    lines = path.read_text(encoding="utf-8").splitlines()
    assert 1 <= start <= end <= len(lines), (relative, start, end, len(lines))
    segment = "\n".join(lines[start - 1 : end])
    for term in required_terms:
        assert term in segment, (relative, start, end, term)
    return segment


def bounded_test_hits(terms: list[str]) -> list[str]:
    hits: list[str] = []
    for path in sorted((REPO_DIR / "tests").rglob("*.php"), key=lambda p: utf8_key(p.as_posix())):
        relative = path.relative_to(REPO_DIR).as_posix()
        for line_number, line in enumerate(
            path.read_text(encoding="utf-8").splitlines(), start=1
        ):
            for term in terms:
                if term in line:
                    hits.append(f"{relative}:{line_number}:{term}")
    return hits


def parse_route_path_anchors(value: str) -> tuple[list[dict], list[str]]:
    explicit: list[dict] = []
    rejected: list[str] = []
    for raw_part in value.split(";"):
        part = raw_part.strip()
        if not part or part.startswith("NOT_ESTABLISHED"):
            continue
        match = EXPLICIT_ROUTE_ANCHOR_RE.fullmatch(part)
        if not match:
            rejected.append(part)
            continue
        start = int(match.group(2))
        end = int(match.group(3) or match.group(2))
        explicit.append(
            {
                "anchor": part,
                "route_file": match.group(1),
                "start_line": start,
                "end_line": end,
            }
        )
    return explicit, rejected


def anchor_contains_row(anchor: dict, route_row: dict) -> bool:
    return (
        anchor["route_file"] == route_row["route_file"]
        and anchor["start_line"]
        <= route_row["source_line"]
        <= anchor["end_line"]
    )


def validate_environment(manifest: dict) -> None:
    assert git_text("branch", "--show-current") == "main"
    assert git_text("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git_text("rev-parse", f"{CHECKPOINT_COMMIT}^{{tree}}") == CHECKPOINT_TREE
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE

    product_diff = subprocess.run(
        [
            "git",
            "diff",
            "--quiet",
            CHECKPOINT_COMMIT,
            "--",
            "app",
            "routes",
            "resources/js",
            "tests",
        ],
        cwd=REPO_DIR,
        check=False,
    )
    assert product_diff.returncode == 0
    assert git_text(
        "status", "--porcelain=v1", "--", "app", "routes", "resources/js", "tests"
    ) == ""
    assert sha256_file(AUDIT_DIR / MANIFEST_REL) == MANIFEST_SHA256
    assert manifest["pins"]["application_commit"] == APPLICATION_COMMIT
    assert manifest["pins"]["application_tree"] == APPLICATION_TREE
    assert sha256_file(AUDIT_DIR / MATRIX_REL) == manifest["pins"]["inputs"][MATRIX_REL]
    assert Path(__file__).resolve() == (AUDIT_DIR / GENERATOR_REL).resolve()


def build_route_decisions(route_rows: list[dict], allowed: set[str]) -> list[dict]:
    decisions: list[dict] = []
    for row in route_rows:
        exact_name_ids = unique_sorted(
            list(row["candidate_bases"]["matrix_route_name_exact"])
        )
        assert set(exact_name_ids).issubset(row["candidate_feature_ids"])
        if len(exact_name_ids) == 1:
            assert row["direct_name_literal"]
            classification = "OWNER"
            reviewed = exact_name_ids
            rationale = (
                f"Pinned {row['route_method']} declaration exposes exact literal route name "
                f"{row['direct_name_literal']!r}; canonical matrix route_names equality "
                f"establishes only {exact_name_ids[0]}. Static ownership only; framework "
                "reachability, runtime, browser, benchmark, Pass, and completion remain uncredited."
            )
        elif len(exact_name_ids) > 1:
            assert row["direct_name_literal"]
            classification = "SHARED_RELATION"
            reviewed = exact_name_ids
            rationale = (
                f"Pinned literal route name {row['direct_name_literal']!r} exactly equals the "
                f"canonical route_names evidence for {', '.join(exact_name_ids)}; the declaration "
                "is retained as a shared static relation without runtime or completion credit."
            )
        else:
            classification = "EXPLICIT_UNMAPPED_SENTINEL"
            reviewed = []
            rationale = (
                "No exact literal canonical route-name equality exists for this callsite. "
                "Route-anchor and adjacent/file-wide overlaps remain candidates only, so this "
                "row is explicitly unmapped with zero downstream credit."
            )
        assert classification in allowed
        decisions.append(
            {
                "route_record_id": row["route_record_id"],
                "classification": classification,
                "reviewed_feature_ids": reviewed,
                "source_anchors": [row["source_locator"]],
                "rationale": rationale,
            }
        )

    counts = Counter(row["classification"] for row in decisions)
    assert counts == Counter({"EXPLICIT_UNMAPPED_SENTINEL": 1027, "OWNER": 46})
    return decisions


def build_name_decisions(name_rows: list[dict]) -> list[dict]:
    decisions: list[dict] = []
    for row in name_rows:
        reviewed = unique_sorted(list(row["candidate_feature_ids"]))
        relationship = row["relationship_classification"]
        if relationship == "ROUTE_GROUP_PREFIX":
            assert not reviewed
            rationale = (
                f"Confirmed {row['group_prefix_kind']} group-prefix literal "
                f"{row['literal_route_name']!r}. No child-name propagation or effective runtime "
                "route-name claim is made, and no feature mapping or credit is awarded."
            )
        elif reviewed:
            assert relationship == "DIRECT_COUNTED_ROUTE"
            assert row["literal_route_name"]
            rationale = (
                f"Confirmed direct name relationship for exact literal {row['literal_route_name']!r}; "
                f"canonical matrix route_names equality establishes only {', '.join(reviewed)}. "
                "This is static source identity, not runtime reachability or completion evidence."
            )
        else:
            assert relationship == "DIRECT_COUNTED_ROUTE"
            rationale = (
                "Confirmed the direct relationship to its counted parent declaration. No exact "
                "canonical literal-name equality exists, so reviewed FEATURE-IDs remain empty; "
                "group-prefix inheritance and downstream credit are prohibited."
            )
        decisions.append(
            {
                "name_record_id": row["name_record_id"],
                "relationship_classification_confirmed": relationship,
                "reviewed_feature_ids": reviewed,
                "source_anchors": [row["source_key"]],
                "rationale": rationale,
            }
        )

    assert Counter(
        row["relationship_classification_confirmed"] for row in decisions
    ) == Counter({"DIRECT_COUNTED_ROUTE": 1065, "ROUTE_GROUP_PREFIX": 3})
    assert sum(bool(row["reviewed_feature_ids"]) for row in decisions) == 46
    return decisions


def build_page_decisions(page_rows: list[dict], allowed: set[str]) -> list[dict]:
    decisions: list[dict] = []
    for row in page_rows:
        candidates = unique_sorted(list(row["candidate_feature_ids"]))
        source_anchors = unique_sorted(
            [row["page_file"], *list(row["render_owner_locators"])]
        )
        if len(candidates) == 1:
            prompt_classification = "Reviewed"
            reviewed = candidates
            rationale = (
                f"Exact pinned page-file equality and literal backend render ownership establish "
                f"the static page relation to {candidates[0]}. Build resolution, framework "
                "reachability, browser observation, benchmark mapping, and completion were not executed."
            )
        elif len(candidates) > 1:
            prompt_classification = "Evidence gap"
            reviewed = []
            rationale = (
                f"The pinned page path overlaps {len(candidates)} canonical candidates, but this "
                "source row does not distinguish a sole or shared feature owner. No candidate is "
                "accepted and all downstream credit remains false."
            )
        else:
            prompt_classification = "Evidence gap"
            reviewed = []
            rationale = (
                "The exact file-backed render root exists, but no canonical matrix page-file equality "
                "exists for this row. It remains an explicit evidence gap with no mapping, runtime, "
                "browser, benchmark, Pass, or completion credit."
            )
        assert prompt_classification in allowed
        decisions.append(
            {
                "page_record_id": row["page_record_id"],
                "prompt_classification": prompt_classification,
                "reviewed_feature_ids": reviewed,
                "source_anchors": source_anchors,
                "rationale": rationale,
            }
        )

    assert Counter(row["prompt_classification"] for row in decisions) == Counter(
        {"Evidence gap": 156, "Reviewed": 81}
    )
    return decisions


def build_residual_decisions(
    residual_rows: list[dict], matrix_by_id: dict[str, dict[str, str]]
) -> list[dict]:
    by_id = {row["feature_id"]: row for row in residual_rows}
    assert set(by_id) == {
        "CAP-HR-SCHEDULED-REPORT-EXECUTION",
        "CAP-INT-INBOUND-PROVIDER-WEBHOOK",
        "CAP-MED-PHARMACY-ACTIONS",
        "CAP-REP-COMBINED-REPORTS",
    }

    require_segment(
        "app/Http/Controllers/Hr/HrReportController.php",
        30,
        84,
        ["HrReportSubscription::query()", "Inertia::render('hr/reports/index'", "'subscriptions'"],
    )
    require_segment(
        "resources/js/pages/hr/reports/index.tsx",
        44,
        123,
        ["subscriptions: ReportSubscription[]", "/hr/reports/subscriptions/"],
    )
    require_segment(
        "resources/js/pages/hr/reports/index.tsx",
        417,
        567,
        ["Scheduled Reports", "subscriptions.map", "Schedule report"],
    )

    webhook_route = require_segment(
        "routes/integrations.php",
        26,
        30,
        ["Webhook receiver", "Route::post('/webhooks/{provider}'", "webhooks.receive"],
    )
    webhook_controller = require_segment(
        "app/Http/Controllers/Api/WebhookReceiverController.php",
        35,
        222,
        ["Receive a webhook from an external provider", "response()->json"],
    )
    assert "Inertia::render" not in webhook_route + webhook_controller
    assert "inertia(" not in webhook_route + webhook_controller

    pharmacy_terms = [
        "/emar/stock/pharmacy-orders",
        "storePharmacyOrder",
        "updatePharmacyOrder",
        "advancePharmacyOrder",
    ]
    pharmacy_test_hits = bounded_test_hits(pharmacy_terms)
    assert pharmacy_test_hits == []
    require_segment(
        "routes/emar.php",
        220,
        223,
        ["Pharmacy Orders + Stock", "storePharmacyOrder", "advancePharmacyOrder"],
    )
    require_segment(
        "tests/Feature/Emar/StockManagementTest.php",
        45,
        133,
        ["pharmacyOrders"],
    )
    require_segment(
        "tests/Feature/Emar/AuditTrailTest.php",
        224,
        239,
        ["test_pharmacy_delivery_appears_as_stock_received", "MedicationPharmacyOrder::query()->create"],
    )

    combined_terms = [
        "/reports/combined/",
        "CombinedReportController",
        "reports.combined.show",
        "reports.combined.export",
    ]
    combined_test_hits = bounded_test_hits(combined_terms)
    assert combined_test_hits == []
    require_segment(
        "routes/reports.php",
        32,
        37,
        ["CombinedReportController::class, 'show'", "CombinedReportController::class, 'export'"],
    )
    require_segment(
        "app/Http/Controllers/CombinedReportController.php",
        19,
        49,
        ["class CombinedReportController", "inertia('reports/combined'"],
    )

    specs = {
        "CAP-HR-SCHEDULED-REPORT-EXECUTION": {
            "field": "page_files",
            "status": "ESTABLISHED",
            "value": "resources/js/pages/hr/reports/index.tsx",
            "source_anchors": [
                "03-feature-to-benchmark-matrix.csv:155",
                "app/Http/Controllers/Hr/HrReportController.php:30-84",
                "resources/js/pages/hr/reports/index.tsx:44-123",
                "resources/js/pages/hr/reports/index.tsx:417-567",
            ],
            "rationale": (
                "The pinned controller loads report subscriptions and renders hr/reports/index; the "
                "exact page consumes, schedules, edits, pauses, and resumes those subscriptions. "
                "This establishes the static page surface only, not scheduled-job execution credit."
            ),
            "bounded_search": {
                "scope": [
                    "app/Http/Controllers/Hr/HrReportController.php:30-84",
                    "resources/js/pages/hr/reports/index.tsx:44-123,417-567",
                ],
                "terms": ["HrReportSubscription", "hr/reports/index", "Scheduled Reports"],
                "evidence": [
                    "app/Http/Controllers/Hr/HrReportController.php:76-84",
                    "resources/js/pages/hr/reports/index.tsx:417-567",
                ],
                "result": "One exact management page is source-established for scheduled report subscriptions.",
            },
        },
        "CAP-INT-INBOUND-PROVIDER-WEBHOOK": {
            "field": "page_files",
            "status": "ESTABLISHED",
            "value": "NOT_APPLICABLE",
            "source_anchors": [
                "03-feature-to-benchmark-matrix.csv:188",
                "routes/integrations.php:26-30",
                "app/Http/Controllers/Api/WebhookReceiverController.php:35-222",
            ],
            "rationale": (
                "The exact POST webhook declaration and receiver are JSON-only and contain no "
                "Inertia render path. Page files are therefore source-established as not applicable "
                "for this headless inbound endpoint; no runtime or reachability credit is implied."
            ),
            "bounded_search": {
                "scope": [
                    "routes/integrations.php:26-30",
                    "app/Http/Controllers/Api/WebhookReceiverController.php:35-222",
                ],
                "terms": ["Route::post('/webhooks/{provider}'", "response()->json", "Inertia::render", "inertia("],
                "evidence": [
                    "routes/integrations.php:27-30",
                    "app/Http/Controllers/Api/WebhookReceiverController.php:35-222",
                ],
                "result": "The bounded owner path is headless; no page render exists in the receiver path.",
            },
        },
        "CAP-MED-PHARMACY-ACTIONS": {
            "field": "test_anchors",
            "status": "RETAIN_NOT_ESTABLISHED",
            "value": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "source_anchors": [],
            "rationale": (
                "The bounded test scan found page-prop and direct-model pharmacy coverage, but no "
                "test request or method reference for create, update, or advance pharmacy-order "
                "actions. Exact test anchors remain not established."
            ),
            "bounded_search": {
                "scope": ["tests/**/*.php", "routes/emar.php:220-223"],
                "terms": pharmacy_terms,
                "evidence": [
                    "tests/Feature/Emar/StockManagementTest.php:45-133",
                    "tests/Feature/Emar/AuditTrailTest.php:224-239",
                    "routes/emar.php:220-223",
                ],
                "result": "Zero exact endpoint/method test hits; adjacent projection tests were not inherited.",
            },
        },
        "CAP-REP-COMBINED-REPORTS": {
            "field": "test_anchors",
            "status": "RETAIN_NOT_ESTABLISHED",
            "value": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "source_anchors": [],
            "rationale": (
                "The bounded PHP test scan found no combined-report URI, controller, or literal route-name "
                "reference. Source routes/controllers exist, but adjacent reporting tests are not inherited."
            ),
            "bounded_search": {
                "scope": ["tests/**/*.php", "routes/reports.php:32-37"],
                "terms": combined_terms,
                "evidence": [
                    "routes/reports.php:32-37",
                    "app/Http/Controllers/CombinedReportController.php:19-49",
                ],
                "result": "Zero exact test hits; test_anchors remains explicitly not established.",
            },
        },
    }

    decisions: list[dict] = []
    for row in residual_rows:
        feature_id = row["feature_id"]
        spec = specs[feature_id]
        field = spec["field"]
        assert row["missing_fields"] == [field]
        assert row["original_values"][field] == matrix_by_id[feature_id][field]
        assert matrix_by_id[feature_id][field] == "NOT_ESTABLISHED_CURRENT_AUDIT"
        field_decision = {
            "status": spec["status"],
            "value": spec["value"],
            "source_anchors": spec["source_anchors"],
            "rationale": spec["rationale"],
            "bounded_search": spec["bounded_search"],
        }
        top_anchors = unique_sorted(
            [
                f"03-feature-to-benchmark-matrix.csv:{row['matrix_ordinal'] + 1}",
                *spec["bounded_search"]["evidence"],
            ]
        )
        decisions.append(
            {
                "feature_id": feature_id,
                "missing_field_decisions": {field: field_decision},
                "source_anchors": top_anchors,
                "rationale": spec["rationale"],
            }
        )

    statuses = Counter(
        next(iter(row["missing_field_decisions"].values()))["status"]
        for row in decisions
    )
    assert statuses == Counter({"ESTABLISHED": 2, "RETAIN_NOT_ESTABLISHED": 2})
    return decisions


def build_route_name_gap_decisions(
    gap_rows: list[dict],
    matrix_by_id: dict[str, dict[str, str]],
    all_route_rows: list[dict],
    all_name_rows: list[dict],
) -> list[dict]:
    name_by_id = {row["name_record_id"]: row for row in all_name_rows}
    assert len(name_by_id) == len(all_name_rows)
    decisions: list[dict] = []

    for gap in gap_rows:
        feature_id = gap["feature_id"]
        matrix_row = matrix_by_id[feature_id]
        assert matrix_row["route_names"] == gap["original_value"]
        assert gap["original_value"] == "NOT_ESTABLISHED_CURRENT_AUDIT"
        explicit_anchors, rejected_anchors = parse_route_path_anchors(
            matrix_row["route_paths"]
        )

        exact_range_candidates: list[dict] = []
        accepted_rows: list[dict] = []
        for route_row in all_route_rows:
            anchor_candidates = list(
                route_row["candidate_bases"]["matrix_route_anchor_overlap"]
            )
            if feature_id not in anchor_candidates:
                continue
            if not any(
                anchor_contains_row(anchor, route_row) for anchor in explicit_anchors
            ):
                continue
            exact_range_candidates.append(route_row)
            if (
                anchor_candidates == [feature_id]
                and route_row["direct_name_literal"]
                and route_row["direct_name_callsite_id"]
            ):
                name_row = name_by_id[route_row["direct_name_callsite_id"]]
                assert name_row["relationship_classification"] == "DIRECT_COUNTED_ROUTE"
                assert name_row["literal_route_name"] == route_row["direct_name_literal"]
                accepted_rows.append(route_row)

        matrix_anchor = (
            f"03-feature-to-benchmark-matrix.csv:{gap['matrix_ordinal'] + 1}"
        )
        accepted_names = unique_sorted(
            [row["direct_name_literal"] for row in accepted_rows]
        )
        accepted_source_anchors = unique_sorted(
            [
                anchor
                for row in accepted_rows
                for anchor in (
                    row["source_locator"],
                    name_by_id[row["direct_name_callsite_id"]]["source_key"],
                )
            ]
        )
        evidence = {
            "explicit_matrix_route_path_anchors": [
                anchor["anchor"] for anchor in explicit_anchors
            ],
            "rejected_non_explicit_route_path_tokens": rejected_anchors,
            "exact_range_candidate_route_record_ids": [
                row["route_record_id"] for row in exact_range_candidates
            ],
            "accepted_route_record_ids": [
                row["route_record_id"] for row in accepted_rows
            ],
            "rule": (
                "Accept only a direct literal ->name on a primary route row where this FEATURE-ID "
                "is the sole matrix_route_anchor_overlap candidate and the matrix route_paths cell "
                "contains an explicit file:line or file:start-end anchor covering that row."
            ),
            "name_semantics": "DECLARED_LITERAL_ONLY_NO_GROUP_PREFIX_PROPAGATION_OR_EFFECTIVE_RUNTIME_NAME_CLAIM",
        }

        if accepted_names:
            route_name_decision = {
                "status": "ESTABLISHED",
                "value": "; ".join(accepted_names),
                "source_anchors": accepted_source_anchors,
                "rationale": (
                    "Exact direct literal name declarations were established under the sole-candidate "
                    "and explicit-line rule. Values are declared literals only; no group prefix is "
                    "propagated and no effective runtime route name or downstream credit is claimed."
                ),
                "bounded_search": {
                    "scope": [
                        "all 3,217 pinned primary route rows",
                        matrix_anchor,
                    ],
                    "terms": [feature_id, matrix_row["route_paths"]],
                    "evidence": evidence,
                    "result": f"Established {len(accepted_names)} exact declared literal name(s).",
                },
            }
            rationale = route_name_decision["rationale"]
            top_anchors = unique_sorted([matrix_anchor, *accepted_source_anchors])
        else:
            route_name_decision = {
                "status": "RETAIN_NOT_ESTABLISHED",
                "value": "NOT_ESTABLISHED_CURRENT_AUDIT",
                "source_anchors": [],
                "rationale": (
                    "No primary route row satisfied both the sole-candidate and explicit-line literal-name "
                    "rule. Whole-file, adjacent, ambiguous, and group-prefix inference was rejected, so "
                    "route_names remains explicitly not established with zero downstream credit."
                ),
                "bounded_search": {
                    "scope": [
                        "all 3,217 pinned primary route rows",
                        matrix_anchor,
                    ],
                    "terms": [feature_id, matrix_row["route_paths"]],
                    "evidence": evidence,
                    "result": "No exact accepted literal; retained NOT_ESTABLISHED_CURRENT_AUDIT.",
                },
            }
            rationale = route_name_decision["rationale"]
            top_anchors = unique_sorted(
                [matrix_anchor, *[anchor["anchor"] for anchor in explicit_anchors]]
            )

        decisions.append(
            {
                "feature_id": feature_id,
                "route_name_decision": route_name_decision,
                "source_anchors": top_anchors,
                "rationale": rationale,
            }
        )

    statuses = Counter(
        row["route_name_decision"]["status"] for row in decisions
    )
    assert statuses == Counter({"ESTABLISHED": 19, "RETAIN_NOT_ESTABLISHED": 62})
    established_literals = sum(
        len(row["route_name_decision"]["value"].split("; "))
        for row in decisions
        if row["route_name_decision"]["status"] == "ESTABLISHED"
    )
    assert established_literals == 126
    return decisions


def assert_exact_lane(
    decisions: list[dict], id_key: str, assigned_ids: list[str]
) -> None:
    ids = [row[id_key] for row in decisions]
    assert len(ids) == len(set(ids)) == len(assigned_ids)
    assert ids == assigned_ids


def main() -> None:
    manifest = read_json(MANIFEST_REL)
    validate_environment(manifest)
    matrix_rows, matrix_by_id = read_matrix()
    assert len(matrix_rows) == 340

    review_contract = manifest["review_contract"]
    allowed_route = set(review_contract["allowed_ownership_classifications"])
    allowed_page = set(review_contract["allowed_page_prompt_classifications"])
    canonical_feature_ids = {row["feature_id"] for row in manifest["canonical_targets"]}
    assert canonical_feature_ids == set(matrix_by_id)

    partition = next(
        row
        for row in manifest["partitions"]["records"]
        if row["partition_id"] == PARTITION_ID
    )
    assert partition["counts"] == {
        "route_files": 13,
        "primary_route_facade_callsites": 1073,
        "route_like_sentinels_outside_primary_denominator": 0,
        "static_route_like_review_rows": 1073,
        "fluent_name_callsites": 1068,
        "page_roots": 237,
        "residual_scoped_targets": 4,
        "separate_route_name_gap_targets": 81,
    }

    route_by_id = {
        row["route_record_id"]: row
        for row in manifest["route_universe"]["primary_route_facade_callsites"]
    }
    route_by_id.update(
        {
            row["route_record_id"]: row
            for row in manifest["route_universe"]["route_like_sentinels"]
        }
    )
    name_by_id = {
        row["name_record_id"]: row
        for row in manifest["route_universe"]["fluent_name_callsites"]
    }
    page_by_id = {
        row["page_record_id"]: row
        for row in manifest["page_universe"]["page_roots"]
    }
    residual_by_id = {
        row["feature_id"]: row
        for row in manifest["residual_scoped_gaps"]["records"]
    }
    gap_by_id = {
        row["feature_id"]: row
        for row in manifest["route_name_gaps"]["records"]
    }

    assigned_route_ids = list(partition["route_record_ids"]) + list(
        partition["route_like_sentinel_ids"]
    )
    assigned_name_ids = list(partition["name_record_ids"])
    assigned_page_ids = list(partition["page_record_ids"])
    assigned_residual_ids = list(partition["residual_feature_ids"])
    assigned_gap_ids = list(partition["route_name_gap_feature_ids"])

    route_rows = [route_by_id[row_id] for row_id in assigned_route_ids]
    name_rows = [name_by_id[row_id] for row_id in assigned_name_ids]
    page_rows = [page_by_id[row_id] for row_id in assigned_page_ids]
    residual_rows = [residual_by_id[row_id] for row_id in assigned_residual_ids]
    gap_rows = [gap_by_id[row_id] for row_id in assigned_gap_ids]

    route_decisions = build_route_decisions(route_rows, allowed_route)
    name_decisions = build_name_decisions(name_rows)
    page_decisions = build_page_decisions(page_rows, allowed_page)
    residual_decisions = build_residual_decisions(residual_rows, matrix_by_id)
    route_name_gap_decisions = build_route_name_gap_decisions(
        gap_rows,
        matrix_by_id,
        manifest["route_universe"]["primary_route_facade_callsites"],
        manifest["route_universe"]["fluent_name_callsites"],
    )

    assert_exact_lane(route_decisions, "route_record_id", assigned_route_ids)
    assert_exact_lane(name_decisions, "name_record_id", assigned_name_ids)
    assert_exact_lane(page_decisions, "page_record_id", assigned_page_ids)
    assert_exact_lane(residual_decisions, "feature_id", assigned_residual_ids)
    assert_exact_lane(route_name_gap_decisions, "feature_id", assigned_gap_ids)

    for rows, required_keys in (
        (route_decisions, review_contract["producer_required_route_decision_keys"]),
        (name_decisions, review_contract["producer_required_name_decision_keys"]),
        (page_decisions, review_contract["producer_required_page_decision_keys"]),
        (
            residual_decisions,
            review_contract["producer_required_residual_scoped_decision_keys"],
        ),
        (
            route_name_gap_decisions,
            review_contract["producer_required_route_name_gap_decision_keys"],
        ),
    ):
        assert all(set(row) == set(required_keys) for row in rows)

    reviewed_feature_lists = [
        row["reviewed_feature_ids"]
        for row in route_decisions + name_decisions + page_decisions
    ]
    assert all(set(values).issubset(canonical_feature_ids) for values in reviewed_feature_lists)
    assert all(
        not row["reviewed_feature_ids"]
        for row in route_decisions
        if row["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
    )

    actual_counts = {
        "route_decisions": len(route_decisions),
        "name_decisions": len(name_decisions),
        "page_decisions": len(page_decisions),
        "residual_scoped_decisions": len(residual_decisions),
        "route_name_gap_decisions": len(route_name_gap_decisions),
    }
    assert actual_counts == EXPECTED_COUNTS
    assert not any(manifest["credit_boundary"].values())

    payload = {
        "schema_version": 1,
        "run_id": "RUN-078B-ROUTE-PAGE-CLASSIFICATION-WAVE-07",
        "status": "PARTITION_B_STATIC_PRODUCER_DECISIONS_COMPLETE_PENDING_INDEPENDENT_REVIEW_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": GENERATED_ON,
        "pins": {
            "manifest_path": MANIFEST_REL,
            "manifest_sha256": MANIFEST_SHA256,
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "partition_id": PARTITION_ID,
            "generator_path": GENERATOR_REL,
            "generator_sha256": current_generator_sha256(),
            "matrix_path": MATRIX_REL,
            "matrix_sha256": sha256_file(AUDIT_DIR / MATRIX_REL),
            "partition_identity_hashes": partition["identity_hashes"],
        },
        "partition_id": PARTITION_ID,
        "route_decisions": route_decisions,
        "name_decisions": name_decisions,
        "page_decisions": page_decisions,
        "residual_scoped_decisions": residual_decisions,
        "route_name_gap_decisions": route_name_gap_decisions,
        "completion_test": {
            "expected_counts": EXPECTED_COUNTS,
            "actual_counts": actual_counts,
            "route_ids_exactly_once_no_extras": True,
            "name_ids_exactly_once_no_extras": True,
            "page_ids_exactly_once_no_extras": True,
            "residual_feature_ids_exactly_once_no_extras": True,
            "route_name_gap_feature_ids_exactly_once_no_extras": True,
            "all_assigned_decisions_complete": True,
            "producer_packet_complete": True,
            "independent_review_complete": False,
            "audit_completion_awarded": False,
            "downstream_credit_awarded": False,
            "route_classifications": dict(
                sorted(Counter(row["classification"] for row in route_decisions).items())
            ),
            "page_prompt_classifications": dict(
                sorted(
                    Counter(
                        row["prompt_classification"] for row in page_decisions
                    ).items()
                )
            ),
            "residual_field_statuses": dict(
                sorted(
                    Counter(
                        next(iter(row["missing_field_decisions"].values()))[
                            "status"
                        ]
                        for row in residual_decisions
                    ).items()
                )
            ),
            "route_name_gap_statuses": dict(
                sorted(
                    Counter(
                        row["route_name_decision"]["status"]
                        for row in route_name_gap_decisions
                    ).items()
                )
            ),
        },
        "credit_boundary": manifest["credit_boundary"],
        "wrote_files": True,
        "write_scope": [GENERATOR_REL, OUTPUT_REL],
        "outside_scope_files_written": [],
        "attestation": (
            "RUN-078B producer-only deterministic static source review. Only the named generator "
            "and raw producer artifact were written. Candidate overlap was not treated as mapping; "
            "group prefixes were not propagated; no application/matrix/report/dashboard change, "
            "framework route execution, runtime, database, build, browser, executed test, benchmark "
            "mapping, ease, release, Pass, or audit completion credit occurred."
        ),
    }

    required_top = set(review_contract["producer_required_top_level_keys"])
    assert required_top.issubset(payload)
    assert set(review_contract["producer_required_pin_bindings"]).issubset(
        payload["pins"]
    )
    assert payload["wrote_files"] is True
    assert payload["write_scope"] == [GENERATOR_REL, OUTPUT_REL]
    assert payload["outside_scope_files_written"] == []
    assert not any(payload["credit_boundary"].values())

    encoded = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode(
        "utf-8"
    )
    assert json.loads(encoded.decode("utf-8")) == payload
    if OUTPUT_PATH.exists():
        existing = json.loads(OUTPUT_PATH.read_text(encoding="utf-8"))
        assert existing["run_id"] == payload["run_id"]
        assert existing["partition_id"] == PARTITION_ID
        assert existing["pins"]["checkpoint_commit"] == CHECKPOINT_COMMIT
    OUTPUT_PATH.write_bytes(encoded)
    assert OUTPUT_PATH.read_bytes() == encoded

    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_REL,
                "sha256": sha256_bytes(encoded),
                "generator_sha256": payload["pins"]["generator_sha256"],
                "counts": actual_counts,
                "route_classifications": payload["completion_test"][
                    "route_classifications"
                ],
                "page_prompt_classifications": payload["completion_test"][
                    "page_prompt_classifications"
                ],
                "residual_field_statuses": payload["completion_test"][
                    "residual_field_statuses"
                ],
                "route_name_gap_statuses": payload["completion_test"][
                    "route_name_gap_statuses"
                ],
                "downstream_credit": 0,
            },
            separators=(",", ":"),
        )
    )


if __name__ == "__main__":
    main()
