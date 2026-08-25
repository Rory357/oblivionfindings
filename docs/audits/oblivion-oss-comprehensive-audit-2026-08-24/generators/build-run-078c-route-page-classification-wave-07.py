#!/usr/bin/env python3
"""Build the exhaustive RUN-078C static route/page classification evidence."""

from __future__ import annotations

import copy
import csv
import hashlib
import json
import re
import subprocess
from collections import Counter, defaultdict
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]

PARTITION_ID = "C"
CHECKPOINT_COMMIT = "87826adc6fb8c9f0b1ca5ea99dcdc06e32bbd6d0"
CHECKPOINT_TREE = "d1eb36fabc0f5150c81f2140e834347dca87dd25"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"

MANIFEST_REL = (
    "evidence/source/"
    "root-run-077-route-page-universe-manifest-wave-07.json"
)
MANIFEST_SHA256 = (
    "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be"
)
PRIOR_REVIEW_REL = (
    "evidence/source/current-static-linkage-independent-review-wave-06.json"
)
PRIOR_REVIEW_SHA256 = (
    "6ee2c0beb90ce8e9fec75190c1a2d87e44b7f7d7b0b7776d25df4832b73d20a0"
)
MATRIX_REL = "03-feature-to-benchmark-matrix.csv"
GENERATOR_REL = "generators/build-run-078c-route-page-classification-wave-07.py"
OUTPUT_REL = (
    "evidence/source/raw-run-078c-route-page-classification-wave-07.json"
)
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL
GENERATED_ON = "2026-08-25T12:00:00+12:00"

EXPECTED_COUNTS = {
    "route_decisions": 1072,
    "name_decisions": 1072,
    "page_decisions": 237,
    "residual_scoped_decisions": 4,
    "residual_scoped_cells": 4,
    "route_name_gap_decisions": 81,
}

LINE_ANCHOR_RE = re.compile(r"^(routes/[^:]+):(\d+)(?:-(\d+))?$")


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def sha256_string_list(values: list[str]) -> str:
    return sha256_bytes(("\n".join(values) + ("\n" if values else "")).encode("utf-8"))


def git_text(*args: str) -> str:
    return subprocess.check_output(
        ["git", *args], cwd=REPO_DIR, text=True, encoding="utf-8"
    ).strip()


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def read_matrix() -> dict[str, dict[str, str]]:
    with (AUDIT_DIR / MATRIX_REL).open(
        encoding="utf-8-sig", newline=""
    ) as handle:
        rows = list(csv.DictReader(handle))
    by_id = {row["feature_id"]: row for row in rows}
    assert len(rows) == len(by_id) == 340
    return by_id


def split_cell(value: str) -> list[str]:
    if not value or value.startswith("NOT_ESTABLISHED") or value == "NOT_APPLICABLE":
        return []
    return [part.strip() for part in value.split(";") if part.strip()]


def explicit_route_intervals(matrix_row: dict[str, str]) -> dict[str, list[tuple[int, int]]]:
    result: dict[str, list[tuple[int, int]]] = defaultdict(list)
    for anchor in split_cell(matrix_row["route_paths"]):
        match = LINE_ANCHOR_RE.fullmatch(anchor)
        if match:
            start = int(match.group(2))
            result[match.group(1)].append((start, int(match.group(3) or start)))
    return dict(result)


def line_is_explicitly_anchored(
    matrix_row: dict[str, str], route_file: str, source_line: int
) -> bool:
    return any(
        start <= source_line <= end
        for start, end in explicit_route_intervals(matrix_row).get(route_file, [])
    )


def ordered_unique(values: list[str]) -> list[str]:
    return list(dict.fromkeys(values))


def current_generator_sha256() -> str:
    return sha256_bytes(Path(__file__).resolve().read_bytes())


def exact_key_set(row: dict, required: list[str], label: str) -> None:
    assert set(row) == set(required), (label, sorted(set(required) - set(row)), sorted(set(row) - set(required)))


def require_unique_ids(rows: list[dict], key: str, expected: list[str]) -> None:
    actual = [row[key] for row in rows]
    assert actual == expected, (key, actual[:3], expected[:3], len(actual), len(expected))
    assert len(actual) == len(set(actual)), key


def validate_source_state(manifest: dict) -> None:
    assert git_text("branch", "--show-current") == "main"
    assert git_text("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git_text("rev-parse", f"{CHECKPOINT_COMMIT}^{{tree}}") == CHECKPOINT_TREE
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert sha256_file(MANIFEST_REL) == MANIFEST_SHA256
    assert sha256_file(PRIOR_REVIEW_REL) == PRIOR_REVIEW_SHA256
    assert manifest["pins"]["checkpoint_commit"] == "a2e5392b2a97d6548a93fc0897f782d05e404a83"
    assert manifest["pins"]["checkpoint_tree"] == "3bf79f35a97f84f97067caaaf446b47b9de2b926"
    assert manifest["pins"]["application_commit"] == APPLICATION_COMMIT
    assert manifest["pins"]["application_tree"] == APPLICATION_TREE
    assert manifest["pins"]["inputs"][PRIOR_REVIEW_REL] == PRIOR_REVIEW_SHA256
    assert sha256_file(MATRIX_REL) == manifest["pins"]["inputs"][MATRIX_REL]

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


def route_decision(row: dict) -> dict:
    exact_name_ids = ordered_unique(
        list(row["candidate_bases"]["matrix_route_name_exact"])
    )
    candidate_ids = list(row["candidate_feature_ids"])
    assert set(exact_name_ids).issubset(candidate_ids)

    if not exact_name_ids:
        classification = "EXPLICIT_UNMAPPED_SENTINEL"
        reviewed_ids: list[str] = []
        if candidate_ids:
            rationale = (
                "The pinned source callsite is classified explicitly unmapped. "
                "Its manifest candidates arise only from route-anchor overlap; the "
                "RUN-077 contract prohibits treating candidate overlap as mapping, "
                "and no literal matrix route-name equality establishes a FEATURE-ID."
            )
        else:
            rationale = (
                "The pinned source callsite has no exact literal matrix route-name "
                "match, so no FEATURE-ID is established and the row remains an "
                "explicit unmapped sentinel."
            )
    else:
        reviewed_ids = exact_name_ids
        method = row["method"].lower()
        if method in {"redirect", "permanentredirect"}:
            classification = "ALIAS_OR_REDIRECT"
        elif row["route_file"].startswith("routes/api-"):
            classification = "HEADLESS_API_OR_BACKGROUND"
        elif len(reviewed_ids) > 1:
            classification = "SHARED_RELATION"
        else:
            classification = "OWNER"
        rationale = (
            "The direct literal route name at this pinned callsite equals the "
            "canonical matrix route-name evidence for exactly the reviewed "
            "FEATURE-ID subset. Classification is source-static only."
        )

    return {
        "route_record_id": row["route_record_id"],
        "classification": classification,
        "reviewed_feature_ids": reviewed_ids,
        "source_anchors": [row["source_anchor"]],
        "rationale": rationale,
    }


def name_decision(
    row: dict, route_rows: dict[str, dict], route_decisions: dict[str, dict]
) -> dict:
    relationship = row["relationship_classification"]
    reviewed_ids: list[str] = []
    anchors = [row["source_anchor"]]

    if relationship == "ROUTE_GROUP_PREFIX":
        assert row["parent_route_callsite_id"] is None
        rationale = (
            "The pinned fluent name is confirmed as a route-group prefix. It is "
            "not propagated into an effective runtime route name and establishes "
            "no FEATURE-ID mapping."
        )
    else:
        assert relationship == "DIRECT_COUNTED_ROUTE"
        parent_id = row["parent_route_callsite_id"]
        assert parent_id in route_rows
        parent_row = route_rows[parent_id]
        parent_decision = route_decisions[parent_id]
        assert row["literal_route_name"] == parent_row["direct_name_literal"]
        assert set(parent_decision["reviewed_feature_ids"]).issubset(
            row["candidate_feature_ids"]
        )
        reviewed_ids = list(parent_decision["reviewed_feature_ids"])
        anchors = ordered_unique(anchors + [parent_row["source_anchor"]])
        if reviewed_ids:
            rationale = (
                "The direct counted fluent name relationship is confirmed. The "
                "reviewed FEATURE-ID subset is limited to the parent callsite's "
                "literal matrix route-name equality; no group prefix is inherited."
            )
        else:
            rationale = (
                "The direct counted fluent name relationship is confirmed, but "
                "the parent callsite has no literal matrix route-name equality; "
                "no FEATURE-ID mapping or group-prefix propagation is made."
            )

    return {
        "name_record_id": row["name_record_id"],
        "relationship_classification_confirmed": relationship,
        "reviewed_feature_ids": reviewed_ids,
        "source_anchors": anchors,
        "rationale": rationale,
    }


def page_decision(row: dict) -> dict:
    assert row["static_identity_status"] == "EXISTING_LITERAL_BACKEND_RENDER_ROOT"
    assert row["resolver_membership"] == "RESOLVED_PINNED_RESOLVER_PAGE"
    candidates = ordered_unique(list(row["candidate_feature_ids"]))
    if candidates:
        assert row["candidate_basis"] == "EXACT_MATRIX_PAGE_FILE_PATH_OVERLAP"
    anchors = ordered_unique(
        [row["page_file"], *list(row["render_owner_locators"])]
    )
    if len(candidates) == 1:
        prompt_classification = "Reviewed"
        reviewed_ids = candidates
        rationale = (
            "Reviewed as an existing literal backend-render root. The FEATURE-ID "
            "is established by unique exact canonical matrix page-file path equality; "
            "framework reachability, build resolution, runtime, and browser state "
            "remain unexecuted and uncredited."
        )
    elif candidates:
        prompt_classification = "Evidence gap"
        reviewed_ids = []
        rationale = (
            f"The pinned literal backend-render root has {len(candidates)} exact "
            "canonical page-file candidates. Shared ownership is not adjudicated by "
            "path overlap alone, so no FEATURE-ID is promoted; framework reachability, "
            "build, runtime, and browser state remain unexecuted and uncredited."
        )
    else:
        prompt_classification = "Evidence gap"
        reviewed_ids = []
        rationale = (
            "The pinned literal backend-render root has no exact canonical matrix "
            "page-file path equality, so it remains an explicit mapping evidence gap. "
            "Framework reachability, build, runtime, and browser state remain "
            "unexecuted and uncredited."
        )
    return {
        "page_record_id": row["page_record_id"],
        "prompt_classification": prompt_classification,
        "reviewed_feature_ids": reviewed_ids,
        "source_anchors": anchors,
        "rationale": rationale,
    }


def residual_decision(manifest_row: dict, prior_row: dict) -> dict:
    assert prior_row["feature_id"] == manifest_row["feature_id"]
    field_decisions: dict[str, dict] = {}
    top_anchors: list[str] = []
    for field in manifest_row["missing_fields"]:
        final = copy.deepcopy(prior_row["field_reviews"][field]["final_decision"])
        assert final["status"] in {"ESTABLISHED", "RETAIN_NOT_ESTABLISHED"}
        anchors = ordered_unique(list(final.pop("anchors")))
        final["source_anchors"] = anchors
        final["prior_independent_review"] = {
            "run_id": prior_row["review_id"],
            "review_disposition": prior_row["field_reviews"][field][
                "review_disposition"
            ],
            "review_rationale": prior_row["field_reviews"][field][
                "review_rationale"
            ],
        }
        if final["status"] == "RETAIN_NOT_ESTABLISHED":
            assert final["value"] == "NOT_ESTABLISHED_CURRENT_AUDIT"
            assert anchors == []
            assert final["bounded_search"]
        top_anchors.extend(anchors)
        field_decisions[field] = final

    return {
        "feature_id": manifest_row["feature_id"],
        "missing_field_decisions": field_decisions,
        "source_anchors": ordered_unique(top_anchors),
        "rationale": (
            "Reproduces the exact pinned independent-review final decision for "
            "each still-open RUN-077 scoped field. Adjacent anchors are not inherited."
        ),
    }


def route_name_gap_decision(
    manifest_row: dict,
    matrix_row: dict[str, str],
    all_route_rows: list[dict],
    all_name_rows: list[dict],
    name_by_id: dict[str, dict],
    group_prefix_ids: list[str],
) -> dict:
    feature_id = manifest_row["feature_id"]
    assert matrix_row["feature_id"] == feature_id
    assert manifest_row["original_value"] == "NOT_ESTABLISHED_CURRENT_AUDIT"
    candidate_routes = [
        row for row in all_route_rows if feature_id in row["candidate_feature_ids"]
    ]
    exact_name_routes = [
        row
        for row in candidate_routes
        if feature_id in row["candidate_bases"]["matrix_route_name_exact"]
    ]
    candidate_names = [
        row for row in all_name_rows if feature_id in row["candidate_feature_ids"]
    ]
    assert exact_name_routes == []
    assert candidate_names == []

    exact_explicit_rows = [
        row
        for row in all_route_rows
        if row.get("direct_name_literal")
        and row["candidate_bases"]["matrix_route_anchor_overlap"] == [feature_id]
        and line_is_explicitly_anchored(
            matrix_row, row["route_file"], row["source_line"]
        )
    ]
    literal_names = sorted(
        {row["direct_name_literal"] for row in exact_explicit_rows},
        key=lambda value: value.encode("utf-8"),
    )
    exact_name_anchors = ordered_unique(
        [
            name_by_id[row["direct_name_callsite_id"]]["source_anchor"]
            for row in exact_explicit_rows
        ]
    )
    all_explicit_rows = [
        row
        for row in all_route_rows
        if row.get("direct_name_literal")
        and feature_id in row["candidate_bases"]["matrix_route_anchor_overlap"]
        and line_is_explicitly_anchored(
            matrix_row, row["route_file"], row["source_line"]
        )
    ]
    all_explicit_anchors = ordered_unique(
        [
            name_by_id[row["direct_name_callsite_id"]]["source_anchor"]
            for row in all_explicit_rows
        ]
    )

    route_record_ids = [row["route_record_id"] for row in candidate_routes]
    route_anchors = ordered_unique([row["source_anchor"] for row in candidate_routes])
    direct_literals = ordered_unique(
        [
            row["direct_name_literal"]
            for row in candidate_routes
            if row["direct_name_literal"] is not None
        ]
    )
    common_search = {
        "scope": (
            "Entire pinned RUN-077 primary route and fluent-name universes plus "
            "the exact canonical matrix route line/ranges for this FEATURE-ID."
        ),
        "explicit_matrix_route_anchors": split_cell(matrix_row["route_paths"]),
        "candidate_route_record_ids": route_record_ids,
        "candidate_route_record_id_list_sha256": sha256_string_list(route_record_ids),
        "candidate_route_source_anchors": route_anchors,
        "candidate_direct_literal_route_names": direct_literals,
        "explicit_line_direct_name_source_anchors": all_explicit_anchors,
        "exact_matrix_route_name_match_record_ids": [],
        "candidate_fluent_name_record_ids": [],
        "group_prefix_rows_excluded_from_runtime_name_propagation": {
            "count": len(group_prefix_ids),
            "record_id_list_sha256": sha256_string_list(group_prefix_ids),
        },
    }

    if literal_names:
        route_name_decision = {
            "status": "ESTABLISHED",
            "value": "; ".join(literal_names),
            "source_anchors": exact_name_anchors,
            "bounded_search": {
                **common_search,
                "result": (
                    f"{len(literal_names)} direct literal name value(s) are uniquely "
                    "bound by sole-candidate explicit matrix line/ranges."
                ),
            },
            "rationale": (
                "Exact direct ->name(...) literals are established only where each "
                "pinned route row is uniquely assigned to this FEATURE-ID by an "
                "explicit canonical matrix line/range. Group prefixes are not "
                "propagated and effective runtime names are not claimed."
            ),
        }
        outer_anchors = exact_name_anchors
        outer_rationale = (
            "The separate route-name sentinel is resolved to exact pinned direct "
            "literals under the sole-candidate explicit-line boundary."
        )
    else:
        route_name_decision = {
            "status": "RETAIN_NOT_ESTABLISHED",
            "value": "NOT_ESTABLISHED_CURRENT_AUDIT",
            "source_anchors": [],
            "bounded_search": {
                **common_search,
                "result": (
                    f"{len(all_explicit_anchors)} explicitly anchored direct-name "
                    "callsite(s) were ambiguous or non-unique; zero exact names were "
                    "promoted."
                ),
            },
            "rationale": (
                "No direct literal name callsite is uniquely bound by a sole-candidate "
                "explicit canonical matrix line/range. Ambiguous overlap, whole-file "
                "anchors, and group-prefix expansion are not inherited."
            ),
        }
        outer_anchors = []
        outer_rationale = (
            "The separate route-name sentinel is retained after the complete pinned "
            "route/name universe failed the sole-candidate explicit-line test."
        )

    return {
        "feature_id": feature_id,
        "route_name_decision": route_name_decision,
        "source_anchors": outer_anchors,
        "rationale": outer_rationale,
    }


def main() -> None:
    manifest = read_json(MANIFEST_REL)
    prior_review = read_json(PRIOR_REVIEW_REL)
    matrix_by_id = read_matrix()
    validate_source_state(manifest)

    review_contract = manifest["review_contract"]
    allowed_route = set(review_contract["allowed_ownership_classifications"])
    allowed_page = set(review_contract["allowed_page_prompt_classifications"])
    canonical_feature_ids = {row["feature_id"] for row in manifest["canonical_targets"]}
    assert canonical_feature_ids == set(matrix_by_id)
    assert len(canonical_feature_ids) == 340

    partition = next(
        row
        for row in manifest["partitions"]["records"]
        if row["partition_id"] == PARTITION_ID
    )
    assert partition["counts"] == {
        "route_files": 12,
        "primary_route_facade_callsites": 1072,
        "route_like_sentinels_outside_primary_denominator": 0,
        "static_route_like_review_rows": 1072,
        "fluent_name_callsites": 1072,
        "page_roots": 237,
        "residual_scoped_targets": 4,
        "separate_route_name_gap_targets": 81,
    }
    assert partition["route_like_sentinel_ids"] == []

    all_route_rows = manifest["route_universe"]["primary_route_facade_callsites"]
    all_name_rows = manifest["route_universe"]["fluent_name_callsites"]
    all_page_rows = manifest["page_universe"]["page_roots"]
    route_by_id = {row["route_record_id"]: row for row in all_route_rows}
    name_by_id = {row["name_record_id"]: row for row in all_name_rows}
    page_by_id = {row["page_record_id"]: row for row in all_page_rows}
    residual_by_id = {
        row["feature_id"]: row
        for row in manifest["residual_scoped_gaps"]["records"]
    }
    gap_by_id = {
        row["feature_id"]: row for row in manifest["route_name_gaps"]["records"]
    }
    prior_by_id = {row["feature_id"]: row for row in prior_review["records"]}

    route_rows = [route_by_id[row_id] for row_id in partition["route_record_ids"]]
    name_rows = [name_by_id[row_id] for row_id in partition["name_record_ids"]]
    page_rows = [page_by_id[row_id] for row_id in partition["page_record_ids"]]

    route_decisions = [route_decision(row) for row in route_rows]
    route_decision_by_id = {
        row["route_record_id"]: row for row in route_decisions
    }
    name_decisions = [
        name_decision(row, route_by_id, route_decision_by_id) for row in name_rows
    ]
    page_decisions = [page_decision(row) for row in page_rows]
    residual_decisions = [
        residual_decision(residual_by_id[feature_id], prior_by_id[feature_id])
        for feature_id in partition["residual_feature_ids"]
    ]
    group_prefix_ids = [
        row["name_record_id"]
        for row in all_name_rows
        if row["relationship_classification"] == "ROUTE_GROUP_PREFIX"
    ]
    route_name_gap_decisions = [
        route_name_gap_decision(
            gap_by_id[feature_id],
            matrix_by_id[feature_id],
            all_route_rows,
            all_name_rows,
            name_by_id,
            group_prefix_ids,
        )
        for feature_id in partition["route_name_gap_feature_ids"]
    ]

    require_unique_ids(
        route_decisions, "route_record_id", partition["route_record_ids"]
    )
    require_unique_ids(name_decisions, "name_record_id", partition["name_record_ids"])
    require_unique_ids(page_decisions, "page_record_id", partition["page_record_ids"])
    require_unique_ids(
        residual_decisions, "feature_id", partition["residual_feature_ids"]
    )
    require_unique_ids(
        route_name_gap_decisions,
        "feature_id",
        partition["route_name_gap_feature_ids"],
    )

    for row in route_decisions:
        exact_key_set(
            row,
            review_contract["producer_required_route_decision_keys"],
            row["route_record_id"],
        )
        assert row["classification"] in allowed_route
        assert set(row["reviewed_feature_ids"]).issubset(canonical_feature_ids)
        if row["classification"] == "EXPLICIT_UNMAPPED_SENTINEL":
            assert row["reviewed_feature_ids"] == []
    for row in name_decisions:
        exact_key_set(
            row,
            review_contract["producer_required_name_decision_keys"],
            row["name_record_id"],
        )
        assert set(row["reviewed_feature_ids"]).issubset(canonical_feature_ids)
        source_row = name_by_id[row["name_record_id"]]
        assert (
            row["relationship_classification_confirmed"]
            == source_row["relationship_classification"]
        )
        if source_row["relationship_classification"] == "ROUTE_GROUP_PREFIX":
            assert row["reviewed_feature_ids"] == []
    for row in page_decisions:
        exact_key_set(
            row,
            review_contract["producer_required_page_decision_keys"],
            row["page_record_id"],
        )
        assert row["prompt_classification"] in allowed_page
        assert set(row["reviewed_feature_ids"]).issubset(canonical_feature_ids)
    for row in residual_decisions:
        exact_key_set(
            row,
            review_contract["producer_required_residual_scoped_decision_keys"],
            row["feature_id"],
        )
    for row in route_name_gap_decisions:
        exact_key_set(
            row,
            review_contract["producer_required_route_name_gap_decision_keys"],
            row["feature_id"],
        )

    actual_counts = {
        "route_decisions": len(route_decisions),
        "name_decisions": len(name_decisions),
        "page_decisions": len(page_decisions),
        "residual_scoped_decisions": len(residual_decisions),
        "residual_scoped_cells": sum(
            len(row["missing_field_decisions"]) for row in residual_decisions
        ),
        "route_name_gap_decisions": len(route_name_gap_decisions),
    }
    assert actual_counts == EXPECTED_COUNTS
    page_classification_counts = Counter(
        row["prompt_classification"] for row in page_decisions
    )
    assert page_classification_counts == Counter(
        {"Evidence gap": 156, "Reviewed": 81}
    )
    route_name_gap_status_counts = Counter(
        row["route_name_decision"]["status"]
        for row in route_name_gap_decisions
    )
    assert route_name_gap_status_counts == Counter(
        {"RETAIN_NOT_ESTABLISHED": 52, "ESTABLISHED": 29}
    )

    payload = {
        "schema_version": 1,
        "run_id": "RUN-078C-ROUTE-PAGE-CLASSIFICATION",
        "status": "PARTITION_C_SOURCE_STATIC_CLASSIFICATION_COMPLETE_PENDING_INDEPENDENT_REVIEW_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": GENERATED_ON,
        "pins": {
            "manifest_path": MANIFEST_REL,
            "manifest_sha256": MANIFEST_SHA256,
            "manifest_checkpoint_commit": manifest["pins"]["checkpoint_commit"],
            "manifest_checkpoint_tree": manifest["pins"]["checkpoint_tree"],
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "partition_id": PARTITION_ID,
            "prior_independent_review_path": PRIOR_REVIEW_REL,
            "prior_independent_review_sha256": PRIOR_REVIEW_SHA256,
            "matrix_path": MATRIX_REL,
            "matrix_sha256": manifest["pins"]["inputs"][MATRIX_REL],
            "generator": GENERATOR_REL,
            "generator_sha256": current_generator_sha256(),
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
            "all_assigned_ids_decided_exactly_once": True,
            "assigned_order_preserved": True,
            "no_extra_ids": True,
            "decision_key_sets_exact": True,
            "classification_enums_valid": True,
            "literal_route_name_equality_only": True,
            "literal_page_file_equality_only": True,
            "route_name_gap_discovery_uses_sole_candidate_explicit_lines": True,
            "group_prefix_runtime_names_propagated": False,
            "candidate_overlap_inherited_as_mapping": False,
            "residual_sentinel_decisions_reproduced_from_pinned_review": True,
            "runtime_build_browser_or_test_execution_performed": False,
            "met": True,
            "classification_counts": dict(
                sorted(Counter(row["classification"] for row in route_decisions).items())
            ),
            "page_prompt_classification_counts": dict(
                sorted(page_classification_counts.items())
            ),
            "route_name_gap_status_counts": dict(
                sorted(route_name_gap_status_counts.items())
            ),
        },
        "credit_boundary": copy.deepcopy(manifest["credit_boundary"]),
        "wrote_files": True,
        "write_scope": [GENERATOR_REL, OUTPUT_REL],
        "outside_scope_files_written": [],
        "attestation": (
            "Exhaustive partition C producer evidence over the exact RUN-077 assigned "
            "IDs. Decisions are pinned source-static classifications only. Candidate "
            "overlap and adjacent anchors were not inherited; group prefixes were not "
            "propagated; no framework reachability, runtime, build, browser, executed "
            "test, benchmark mapping, ease, release, Pass, completion, or audit-complete "
            "credit is awarded."
        ),
    }

    required_top = set(review_contract["producer_required_top_level_keys"])
    required_top.update({"write_scope", "outside_scope_files_written"})
    assert set(payload) == required_top
    assert set(review_contract["producer_required_pin_bindings"]).issubset(
        payload["pins"]
    )
    assert payload["credit_boundary"] == manifest["credit_boundary"]
    assert not any(payload["credit_boundary"].values())

    encoded = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode(
        "utf-8"
    )
    assert json.loads(encoded.decode("utf-8")) == payload
    candidate_sha256 = sha256_bytes(encoded)
    OUTPUT_PATH.write_bytes(encoded)
    assert sha256_bytes(OUTPUT_PATH.read_bytes()) == candidate_sha256
    assert json.loads(OUTPUT_PATH.read_text(encoding="utf-8")) == payload

    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_REL,
                "sha256": candidate_sha256,
                "generator_sha256": current_generator_sha256(),
                "counts": actual_counts,
                "route_classifications": payload["completion_test"][
                    "classification_counts"
                ],
                "page_prompt_classifications": payload["completion_test"][
                    "page_prompt_classification_counts"
                ],
                "route_name_gap_statuses": payload["completion_test"][
                    "route_name_gap_status_counts"
                ],
            },
            separators=(",", ":"),
        )
    )


if __name__ == "__main__":
    main()
