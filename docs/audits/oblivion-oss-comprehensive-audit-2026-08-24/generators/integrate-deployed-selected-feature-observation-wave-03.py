#!/usr/bin/env python3
"""Normalize the sealed unknown-build selected-feature browser observation."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
RAW_RELATIVE = "evidence/browser/deployed-selected-feature-observation-wave-03.json"
REVIEW_RELATIVE = "evidence/browser/raw-run-059b-independent-unknown-build-browser-review-wave-03.json"
OUTPUT_RELATIVE = "evidence/browser/current-deployed-selected-feature-observation-wave-03.json"
RAW_SHA256 = "b559b2662f6148f31871f57a0aa15e26ac05a7abb235b781f5ac2fd5e99ef290"
REVIEW_SHA256 = "b2eae3ba63ef9f8a39ab9a0a96fd7f6c265bde859a6c0ec4d162f7f9b752f1e0"
GENERATOR_RELATIVE = "generators/integrate-deployed-selected-feature-observation-wave-03.py"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


raw_path = AUDIT_DIR / RAW_RELATIVE
review_path = AUDIT_DIR / REVIEW_RELATIVE
output_path = AUDIT_DIR / OUTPUT_RELATIVE
assert sha256(raw_path) == RAW_SHA256
assert sha256(review_path) == REVIEW_SHA256
raw = json.loads(raw_path.read_text(encoding="utf-8"))
review = json.loads(review_path.read_text(encoding="utf-8"))

assert raw["run_id"] == "RUN-058-BROWSER"
assert raw["status"] == "SIGNED_IN_DEPLOYED_SELECTED_FEATURES_OBSERVED_UNKNOWN_BUILD_NO_CURRENT_SOURCE_BROWSER_CREDIT"
assert raw["source_pin"] == {
    "application_commit": APPLICATION_COMMIT,
    "application_tree": APPLICATION_TREE,
}
assert raw["environment_boundary"]["authoritative_deployed_commit_or_tree_found"] is False
assert raw["environment_boundary"]["build_or_release_meta_found"] is False
assert raw["environment_boundary"]["meaningful_mutations"] == 0
assert raw["environment_boundary"]["forms_submitted"] == 0
assert raw["environment_boundary"]["records_changed"] == 0
assert raw["environment_boundary"]["screenshots_retained"] == 0
assert review["schema_version"] == "RUN-059B-1"
assert review["verdict"] == "ACCEPT_UNKNOWN_BUILD_OBSERVATION_ONLY_INTEGRATION_UPDATE_REQUIRED"
assert review["sealed_artifact"]["sha256"] == RAW_SHA256

features = raw["selected_features"]
feature_by_id = {row["feature_id"]: row for row in features}
assert len(features) == len(feature_by_id) == 6
assert len({row["route"] for row in features}) == 6

viewport_rows = raw["viewport_coverage"]["viewports"]
dimensions = [(row["width"], row["height"]) for row in viewport_rows]
assert dimensions == [(1440, 900), (1280, 800), (1024, 768), (390, 844)]
assert all(row["routes_checked"] == 6 for row in viewport_rows)
assert all(row["routes_with_document_horizontal_overflow"] == 0 for row in viewport_rows)
assert raw["viewport_coverage"]["route_viewport_cells_checked"] == 24

route_viewport_observations = []
for viewport in viewport_rows:
    inner_scroll_routes = set(viewport["intentional_inner_horizontal_scroll"])
    assert inner_scroll_routes <= {row["route"] for row in features}
    for feature in features:
        route_viewport_observations.append(
            {
                "cell_id": "|".join(
                    [
                        feature["feature_id"],
                        feature["route"],
                        str(viewport["width"]),
                        str(viewport["height"]),
                    ]
                ),
                "feature_id": feature["feature_id"],
                "route": feature["route"],
                "width": viewport["width"],
                "height": viewport["height"],
                "document_horizontal_overflow_observed": False,
                "intentional_inner_horizontal_scroll_observed": feature["route"] in inner_scroll_routes,
                "attribution": "DEPLOYED_UNKNOWN_BUILD_ONLY",
                "current_source_credit": False,
            }
        )

assert len(route_viewport_observations) == 24
assert len({row["cell_id"] for row in route_viewport_observations}) == 24

normalized_overlays = []
for index, overlay in enumerate(raw["overlay_observations"], start=1):
    feature_id = overlay["feature_id"]
    assert feature_id in feature_by_id
    if "cancel_returned_to_detail" in overlay:
        close_mechanism = "cancel_to_detail"
        focus_restored = None
    elif overlay.get("escape_closed_without_edits"):
        close_mechanism = "escape"
        focus_restored = overlay["focus_restored_to_trigger_after_close"]
    else:
        assert overlay.get("close_button_closed") is True
        close_mechanism = "close_button"
        focus_restored = overlay["focus_restored_to_trigger_after_close"]
    normalized_overlays.append(
        {
            "overlay_id": f"UNKNOWN-BUILD-OVERLAY-{index:02d}",
            "feature_id": feature_id,
            "route": feature_by_id[feature_id]["route"],
            "trigger": overlay["trigger"],
            "primary_action": overlay["primary_action"],
            "close_mechanism_observed": close_mechanism,
            "focus_before_close_role_or_locator": None,
            "focus_after_close_role_or_locator": None,
            "focus_restored_to_trigger_observed": focus_restored,
            "submission_performed": overlay["submission_performed"],
            "screenshot_count": 0,
            "screenshot_credit": False,
            "resampling_required": True,
            "attribution": "DEPLOYED_UNKNOWN_BUILD_ONLY",
            "current_source_credit": False,
        }
    )

assert len(normalized_overlays) == 5
assert all(row["submission_performed"] is False for row in normalized_overlays)
candidate_ids = [row["candidate_id"] for row in raw["observed_unknown_build_candidates"]]
assert candidate_ids == [
    "VIS-UNKNOWN-BUILD-FOCUS-RESTORE-01",
    "VIS-UNKNOWN-BUILD-HR-ESCAPE-01",
]

credit_boundary = {
    "observation_record_accepted": True,
    "current_build_application_browser_credit": False,
    "current_source_route_credit": False,
    "current_source_responsive_credit": False,
    "current_source_visual_credit": False,
    "current_source_overlay_credit": False,
    "current_source_workflow_credit": False,
    "current_source_journey_credit": False,
    "screenshot_credit": False,
    "accessibility_credit": False,
    "finding_credit": False,
    "ease_credit": False,
    "runtime_credit": False,
    "test_execution_credit": False,
    "release_credit": False,
    "pass_credit": False,
    "completion_credit": False,
    "audit_complete": False,
}

output = {
    "schema_version": "1.0",
    "run_id": "RUN-060",
    "status": "UNKNOWN_BUILD_SELECTED_FEATURE_OBSERVATION_NORMALIZED_ZERO_CURRENT_SOURCE_CREDIT",
    "generated_at": raw["observed_at"],
    "input": {"path": RAW_RELATIVE, "sha256": RAW_SHA256, "run_id": raw["run_id"]},
    "independent_review": {
        "path": REVIEW_RELATIVE,
        "sha256": REVIEW_SHA256,
        "verdict": review["verdict"],
    },
    "generator": {
        "path": GENERATOR_RELATIVE,
        "sha256": sha256(Path(__file__)),
    },
    "audit_application_pin": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
    },
    "deployed_identity": {
        "deployed_application_commit": None,
        "deployed_application_tree": None,
        "reproducible_build_marker": None,
        "attribution_status": "UNPROVEN",
    },
    "actor_and_fixture_boundary": {
        "actor_role": "UNKNOWN",
        "approved_site_context": "UNKNOWN",
        "fixture_safety_context": "UNKNOWN",
        "environment_classification": "UNKNOWN",
        "signed_in_session_observed": True,
    },
    "counts": {
        "selected_feature_ids": 6,
        "selected_routes": 6,
        "prompt_dimensions_sampled_on_unknown_build": 4,
        "unknown_build_route_viewport_cells": 24,
        "unknown_build_overlay_families": 5,
        "unknown_build_provisional_candidates": 2,
        "current_build_required_viewports_credited": 0,
        "current_source_routes": 0,
        "current_source_route_viewport_cells": 0,
        "current_source_overlay_families": 0,
        "meaningful_mutations": 0,
        "forms_submitted": 0,
        "records_changed": 0,
        "screenshots_retained": 0,
        "console_warnings_or_errors": 0,
    },
    "selected_features": features,
    "route_viewport_observations": route_viewport_observations,
    "overlay_observations": normalized_overlays,
    "provisional_unknown_build_candidates": raw["observed_unknown_build_candidates"],
    "credit_boundary": credit_boundary,
    "attestation": {
        "normalization_only": True,
        "sealed_input_mutated": False,
        "meaningful_application_mutation": False,
        "credential_or_demo_record_value_persisted": False,
        "application_source_inspected_by_generator": False,
        "runtime_build_test_or_database_used_by_generator": False,
        "unknown_build_observation_kept_separate_from_current_source_credit": True,
    },
}

output_path.write_text(
    json.dumps(output, indent=2, ensure_ascii=False) + "\n",
    encoding="utf-8",
    newline="\n",
)
