#!/usr/bin/env python3
"""Materialize the strict-current RUN179R Fleet trip-index ownership review."""
from __future__ import annotations

import csv
import hashlib
import json
from pathlib import Path
import re
import subprocess
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/materialize-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.py"
OUTPUT = "evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json"
COHORT_GENERATOR = "generators/build-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.py"
COHORT = "evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json"

HEAD = "b263db6e2c883cae8370cc3529eac490d121f2db"
TREE = "21ba7de95cd1d9dcd98c84b1ef5ed2e2efe75014"
PARENT = "f40e3d63ea99d774265ff9f2eefef8176ab0cbc7"
APPLICATION_COMMIT = PARENT
APPLICATION_TREE = "880721d56b7d379abf9628abb22a5a9b9445194b"
CHECKPOINT_AUDIT_SUBTREE = "0711a7e30e90d1aeb7e6df73283cf62ae11bcd93"

COHORT_GENERATOR_SHA = "61c895a305f743f102765c9f86d38843c3ce61bcc1a8684a672aa2d7cd6ee157"
COHORT_GENERATOR_BLOB = "506a7007c8d7b8e719b1bfa904a880a2885fe8c1"
COHORT_SHA = "5505cf17bb68d3e534116ea9d33e501e0222714b6e3779d0ec6b70f819cc3b0a"
COHORT_BLOB = "ea3a958c125038a95c8d98370328a263d2a2c151"
COHORT_SELF_SEAL = "2fb26afd47c818fe5654fdc685af9a87e40624ad44e205914cca85298593bfc2"
SOURCE_PACKET_SHA = "7709e6aa7dd434d6be780f17f943ffbeb453486bd3e3865a664875e083fbc77d"
CANDIDATE_HASH = "b09ac81def93dcb4800f4a1ac340c698ff73f538ae3bcca792b01a53d7c2b650"
QUEUE_RECORD_SEAL = "928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011"
TRIPS_METHOD_SLICE_SHA = "8a6e9d9c6fa42bc03a226c9a856efde55f6924778d7eefbd16ea7ff926af2d1c"
GOVERNING_PROMPT_SHA = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
CONTINUATION_REQUEST_SHA = "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d"
RUN170 = "evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json"
RUN170_SHA = "c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d"
EXCLUDED_OLD_BUNDLE = "docs/audits/oblivion-oss-comprehensive-audit-2026-08-12"

TIEBREAK_A = """OWNER_ROUTE_ACTION

Decisive current-only anchors:
- Matrix row 108 explicitly freezes `fleet-assets.trips.index` under `CAP-FLEET-VEHICLE-REGISTER` and “Maintain vehicles and vehicle-specific state.”
- RUN090 index 84 has exact, unique literal-route identity. `NAME_ONLY` results from the frozen backend range ending at line 550, not contradictory ownership evidence.
- Current route `routes/fleet-assets.php:54` uniquely invokes `VehicleController::trips`.
- Current action `app/Http/Controllers/FleetAssets/VehicleController.php:566` queries Site-visible vehicles and their trips, filters vehicle/status/date/search, exports CSV, computes vehicle-trip summaries, and renders the dedicated trip-history page.
- The page `resources/js/pages/fleet-assets/trips/index.tsx:308` expressly supports viewing and analysing fleet vehicle trips.
- RUN170’s accepted precedent classified an analogous out-of-range `VehicleController` action with a dedicated non-matrix page as `OWNER_ROUTE_ACTION`.
Treat the frozen coarse user job as the canonical denominator grouping: this action owns a material route/action slice; it need not perform the entire broad job. Do not split, rewrite, inherit, or recredit the denominator. `FleetTrip`, helpers, and the page are dependencies/consumer context, not co-owners.
No page, correctness, Site/privacy, runtime, browser, test, benchmark, finding, completion, or downstream integration credit is granted. No tests or edits were performed."""

TIEBREAK_B = """OWNER_ROUTE_ACTION

Exact canonical inclusion is unique: matrix row 108 names `fleet-assets.trips.index`, `VehicleController` is the source owner, and no other current capability claims trips. Current source routes that GET uniquely to `VehicleController::trips`, which queries vehicle-bound `FleetTrip` state, filters/aggregates/exports it, and renders `fleet-assets/trips/index`. Current-bundle `NAME_ONLY` precedent makes missing backend/page containment non-automatic; the substantive action trace closes narrow static ownership.
Limit: route owner plus controller-action bridge only. No page ownership, adjacent trip-route inheritance, correctness, Site/privacy/direct-object, runtime, browser, test, benchmark, finding, or completion credit."""

ARTIFACT_REVIEW_A = """GO on the stabilized hardened bytes.
- Generator SHA: `61c895a305f743f102765c9f86d38843c3ce61bcc1a8684a672aa2d7cd6ee157`
- Receipt SHA: `5505cf17bb68d3e534116ea9d33e501e0222714b6e3779d0ec6b70f819cc3b0a`
- Self-seal independently recalculated: `2fb26afd47c818fe5654fdc685af9a87e40624ad44e205914cca85298593bfc2`
- Baseline remains `f40e3d63…`, tree `880721d5…`.
- Prompt roles/hashes, index-84 selection, candidate identity, queue boundary, two-review contract, multi-Site architecture, and zero-credit neutrality are unchanged.
- Range enforcement now fails closed on an empty/non-list set, non-string entries, malformed syntax, zero-based values, reversed ranges, and out-of-bounds endpoints.
- All nine source records emit `review_loci_validated=true`.
- Exact correction: there are 13 declared ranges across the nine files; all 13 independently validate and all source SHA/blob pins match.
- Receipt source-packet hash independently recalculates to `7709e6aa7dd434d6be780f17f943ffbeb453486bd3e3865a664875e083fbc77d`.
- Dirty state is exactly the two intended RUN179 files. No cache or temporary file exists.
No remaining discrepancy found."""

ARTIFACT_REVIEW_B = """GO.

- Exact state: `main` at `f40e3d63ea99d774265ff9f2eefef8176ab0cbc7`, tree `880721d56b7d379abf9628abb22a5a9b9445194b`; dirt is exactly the two RUN179 files. No repo cache or `.tmp` remains.
- Strict JSON/no duplicate keys passes. Receipt SHA-256 is `5505cf17…cc3b0a`; canonical self-seal independently recomputes to `2fb26afd…93bfc2`.
- Generator AST parses. Two `-B` executions in an external clean clone were byte-identical to each other and the submitted 54,801-byte receipt. Both emitted generator `61c895a3…ee157`, receipt `5505cf17…cc3b0a`, and self-seal `2fb26afd…93bfc2`.
- Hardened range integrity passes: all 9 source records emit `review_loci_validated=true`; 13 ranges are mechanically parsed and in bounds. Independently verified all 9 file SHA/blob/byte/line pins, 23 exact-line anchors, 7 method slices, 11 tracked-input SHA/blob pairs, and 9 subtree pins.
- Governing prompt `4a022841…18484f` and continuation-only request `1fe63113…bd32d` independently hash and retain their distinct roles.
- Selection is exactly queue index 84 only: `RUN090-ROUTE-0085` / `RUN077-ROUTE-0693`; the embedded record equals queue row 84.
- Ownership decisions: `0`; correctness decisions: `0`; exactly two distinct independent reviewer roles required. The only true credit is `outcome_neutral_source_packet`; all ownership, correctness, runtime, finding, completion, Gate 4, and audit-complete credits remain false.
- No workspace file was edited and no commit was made."""


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def sha(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def audit_sha(relative: str) -> str:
    return sha((AUDIT / relative).read_bytes())


def repo_sha(relative: str) -> str:
    return sha((REPO / relative).read_bytes())


def canonical(value: Any) -> str:
    return sha(
        json.dumps(
            value,
            ensure_ascii=False,
            sort_keys=True,
            separators=(",", ":"),
        ).encode("utf-8")
    )


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    raw = (AUDIT / relative).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf")
    assert b"\r\n" not in raw and raw.endswith(b"\n")
    value = json.loads(raw, object_pairs_hook=hook)
    assert isinstance(value, dict)
    assert (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8") == raw
    return value


def sealed(record: dict[str, Any], field: str) -> dict[str, Any]:
    record[field] = canonical(record)
    return record


def verify_record_seal(record: dict[str, Any], field: str) -> None:
    expected = record[field]
    without_seal = {key: value for key, value in record.items() if key != field}
    assert expected == canonical(without_seal)


def file_record(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    return {
        "path": f"{PREFIX}/{relative}",
        "sha256": sha(raw),
        "blob_id": git("hash-object", "--", str(AUDIT / relative)),
        "bytes": len(raw),
        "lines": len(raw.splitlines()),
    }


def raw_payload(value: str) -> dict[str, Any]:
    raw = value.encode("utf-8")
    return {
        "text": value,
        "sha256": sha(raw),
        "bytes": len(raw),
        "lines": len(value.splitlines()),
        "verbatim_payload_retained": True,
    }


def assert_exact_dirty_set() -> None:
    generator_status = f"?? {PREFIX}/{GENERATOR}"
    output_status = f"?? {PREFIX}/{OUTPUT}"
    actual = {line for line in git("status", "--porcelain").splitlines() if line}
    expected = (
        {generator_status, output_status}
        if (AUDIT / OUTPUT).exists()
        else {generator_status}
    )
    assert actual == expected, (actual, expected)


def validate_source_record(source: dict[str, Any]) -> int:
    relative = source["path"]
    raw = (REPO / relative).read_bytes()
    lines = raw.splitlines()
    assert source["sha256"] == sha(raw)
    assert source["blob_id"] == git("rev-parse", f"{APPLICATION_COMMIT}:{relative}")
    assert source["blob_id"] == git("rev-parse", f"HEAD:{relative}")
    assert source["bytes"] == len(raw) and source["lines"] == len(lines)
    assert source["review_loci_validated"] is True
    loci = source["review_loci"]
    assert isinstance(loci, list) and loci
    for locus in loci:
        assert isinstance(locus, str)
        match = re.fullmatch(r"([1-9]\d*)-([1-9]\d*)", locus)
        assert match is not None, (relative, locus)
        start, end = (int(value) for value in match.groups())
        assert 1 <= start <= end <= len(lines), (relative, locus, len(lines))
    return len(loci)


def validate_exact_locus(locus: dict[str, Any]) -> None:
    relative, number_text = locus["source_anchor"].rsplit(":", 1)
    number = int(number_text)
    lines = (REPO / relative).read_text(encoding="utf-8-sig").splitlines()
    assert 1 <= number <= len(lines)
    selected = lines[number - 1].strip()
    assert locus["source_line"] == selected
    assert locus["source_line_sha256"] == sha((selected + "\n").encode("utf-8"))


def validate_review_locus(locus: str) -> None:
    assert isinstance(locus, str)
    match = re.fullmatch(r"(.+):([1-9]\d*)(?:-([1-9]\d*))?", locus)
    assert match is not None, locus
    relative, start_text, end_text = match.groups()
    assert EXCLUDED_OLD_BUNDLE not in relative
    path = REPO / relative
    assert path.is_file(), path
    line_count = len(path.read_bytes().splitlines())
    start = int(start_text)
    end = int(end_text or start_text)
    assert 1 <= start <= end <= line_count, (locus, line_count)


def validate_method_slice(slice_record: dict[str, Any]) -> None:
    relative = slice_record["source_file"]
    lines = (REPO / relative).read_text(encoding="utf-8-sig").splitlines()
    start = slice_record["start_line"]
    end = slice_record["end_line"]
    assert isinstance(start, int) and isinstance(end, int)
    assert 1 <= start <= end <= len(lines)
    text = "\n".join(lines[start - 1 : end]) + "\n"
    assert slice_record["text"] == text
    assert slice_record["text_sha256"] == sha(text.encode("utf-8"))
    assert slice_record["source_file_sha256"] == repo_sha(relative)
    assert slice_record["source_file_blob_id"] == git("rev-parse", f"HEAD:{relative}")
    assert slice_record["source_file_blob_id"] == git("rev-parse", f"{APPLICATION_COMMIT}:{relative}")
    assert slice_record["definition_anchor"] == f"{relative}:{start}"
    assert f"function {slice_record['method']}(" in lines[start - 1]


def validate_cohort() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD
    assert git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", "HEAD^") == PARENT
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", f"HEAD:{PREFIX}") == CHECKPOINT_AUDIT_SUBTREE
    assert_exact_dirty_set()

    assert audit_sha(COHORT_GENERATOR) == COHORT_GENERATOR_SHA
    assert audit_sha(COHORT) == COHORT_SHA
    assert git("rev-parse", f"HEAD:{PREFIX}/{COHORT_GENERATOR}") == COHORT_GENERATOR_BLOB
    assert git("rev-parse", f"HEAD:{PREFIX}/{COHORT}") == COHORT_BLOB

    cohort = strict_json(COHORT)
    without_seal = {key: value for key, value in cohort.items() if key != "self_seal"}
    assert cohort["self_seal"] == {
        "algorithm": "sha256-canonical-json-with-self-seal-omitted",
        "sha256": COHORT_SELF_SEAL,
    }
    assert canonical(without_seal) == COHORT_SELF_SEAL
    assert cohort["pins"]["checkpoint_commit"] == APPLICATION_COMMIT
    assert cohort["pins"]["checkpoint_tree"] == APPLICATION_TREE
    assert cohort["pins"]["application_commit"] == APPLICATION_COMMIT
    assert cohort["pins"]["application_tree"] == APPLICATION_TREE
    assert cohort["pins"]["generator_sha256"] == COHORT_GENERATOR_SHA
    assert cohort["pins"]["generator_blob_id"] == COHORT_GENERATOR_BLOB
    assert cohort["pins"]["governing_prompt"]["sha256"] == GOVERNING_PROMPT_SHA
    assert cohort["pins"]["governing_prompt"]["role"] == "GOVERNING_AUDIT_PROMPT"
    assert cohort["pins"]["continuation_request"]["sha256"] == CONTINUATION_REQUEST_SHA
    assert cohort["pins"]["continuation_request"]["role"] == "CONTINUATION_REQUEST_ONLY"
    assert cohort["pins"]["continuation_request"]["is_governing_prompt"] is False
    assert GOVERNING_PROMPT_SHA != CONTINUATION_REQUEST_SHA
    governing = Path(cohort["pins"]["governing_prompt"]["path"])
    continuation = Path(cohort["pins"]["continuation_request"]["path"])
    assert governing.is_file() and sha(governing.read_bytes()) == GOVERNING_PROMPT_SHA
    assert continuation.is_file() and sha(continuation.read_bytes()) == CONTINUATION_REQUEST_SHA

    subtrees = cohort["pins"]["subtrees"]
    assert len(subtrees) == 9
    for relative, expected in subtrees.items():
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{relative}") == expected
        if relative != PREFIX:
            assert git("rev-parse", f"HEAD:{relative}") == expected

    inputs = cohort["pins"]["inputs"]
    assert len(inputs) == 11
    for relative, expected in inputs.items():
        assert audit_sha(relative) == expected, relative
        assert git("rev-parse", f"HEAD:{PREFIX}/{relative}") == git(
            "hash-object", "--", str(AUDIT / relative)
        ), relative
    assert inputs[RUN170] == RUN170_SHA

    candidate = cohort["records"][0]
    assert canonical(candidate) == CANDIDATE_HASH
    assert canonical({key: value for key, value in candidate.items() if key != "queue_record_sha256"}) == QUEUE_RECORD_SEAL
    assert candidate["queue_record_sha256"] == QUEUE_RECORD_SEAL
    assert (
        candidate["queue_id"],
        candidate["canonical_key"],
        candidate["source_record_id"],
        candidate["candidate_feature_id"],
        candidate["source"]["literal_route_name"],
        candidate["secondary_lane"]["backend_method_relation"]["resolution"]["method"],
    ) == (
        "RUN090-ROUTE-0085",
        "route|RUN077-ROUTE-0693",
        "RUN077-ROUTE-0693",
        "CAP-FLEET-VEHICLE-REGISTER",
        "fleet-assets.trips.index",
        "trips",
    )
    assert candidate["secondary_lane"]["relation_comparison"] == "NAME_ONLY"
    assert candidate["direct_identity"]["case_sensitive_exact_equality"] is True
    assert candidate["direct_identity"]["candidate_cardinality"] == 1
    assert candidate["review_state"] == {
        "status": "PENDING_FRESH_SEMANTIC_REVIEW",
        "allowed_outcomes": ["OWNER", "SHARED_RELATION", "EVIDENCE_GAP"],
        "ownership_credit": False,
    }
    assert cohort["selection_contract"]["selected_queue_indices_zero_based"] == [84]
    assert cohort["selection_contract"]["ownership_decisions_authored"] == 0
    assert cohort["selection_contract"]["correctness_decisions_authored"] == 0
    assert cohort["fresh_review_contract"]["required_independent_reviews"] == 2
    assert cohort["fresh_review_contract"]["ownership_integration_authorized"] is False
    assert cohort["queue_boundary"]["current_next_unresolved_index"] == 84
    assert cohort["queue_boundary"]["current_next_unresolved_queue_id"] == "RUN090-ROUTE-0085"
    assert cohort["queue_boundary"]["post_selection_next_index_if_owner"] == 85
    assert cohort["queue_boundary"]["post_selection_next_queue_id_if_owner"] == "RUN090-ROUTE-0086"
    assert cohort["queue_boundary"]["post_selection_next_route_record_id_if_owner"] == "RUN077-ROUTE-0694"
    assert cohort["queue_boundary"]["queue_advance_authorized"] is False

    matrix_path = AUDIT / "03-feature-to-benchmark-matrix.csv"
    matrix_lines = matrix_path.read_text(encoding="utf-8-sig").splitlines()
    assert matrix_lines[107].startswith("CAP-FLEET-VEHICLE-REGISTER,Fleet & Assets,")
    assert "fleet-assets.trips.index" in matrix_lines[107]
    with matrix_path.open(encoding="utf-8-sig", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    trip_matches = [
        row
        for row in matrix_rows
        if "fleet-assets.trips.index" in row["route_names"].split("; ")
    ]
    assert len(trip_matches) == 1
    feature = trip_matches[0]
    assert feature["feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert feature["user_job"] == "Maintain vehicles and vehicle-specific state"
    assert feature["page_files"] == "resources/js/pages/fleet-assets/vehicles/index.tsx"
    assert "app/Http/Controllers/FleetAssets/VehicleController.php:475-550" in feature["backend_anchors"]

    assert cohort["source_review_packet"]["packet_sha256"] == SOURCE_PACKET_SHA
    assert canonical(cohort["source_review_packet"]["required_source_files"]) == SOURCE_PACKET_SHA

    range_count = sum(
        validate_source_record(source)
        for source in cohort["source_review_packet"]["required_source_files"]
    )
    assert len(cohort["source_review_packet"]["required_source_files"]) == 9
    assert range_count == 13
    exact_loci = cohort["source_review_packet"]["exact_current_loci"]
    assert len(exact_loci) == 23
    for locus in exact_loci.values():
        validate_exact_locus(locus)
    page_lines = (REPO / "resources/js/pages/fleet-assets/trips/index.tsx").read_text(
        encoding="utf-8-sig"
    ).splitlines()
    assert page_lines[307].strip() == '<Head title="Trip History" />'
    slices = cohort["source_review_packet"]["selected_controller_action_and_helper_slices"]
    assert len(slices) == 6
    for slice_record in slices.values():
        validate_method_slice(slice_record)
    producer = cohort["source_review_packet"]["canonical_trip_producer_slice"]
    validate_method_slice(producer)
    assert slices["trips"]["text_sha256"] == TRIPS_METHOD_SLICE_SHA
    assert cohort["source_review_packet"]["current_trip_page_is_context_only"] is True
    assert cohort["source_review_packet"]["caller_files_are_context_only"] is True
    assert cohort["source_review_packet"]["focused_test_file_is_source_context_only"] is True
    assert cohort["source_review_packet"]["test_execution_inherited"] is False
    assert cohort["source_review_packet"]["page_or_caller_ownership_inherited"] is False
    assert all(value is False for key, value in cohort["credit_boundary"].items() if key != "outcome_neutral_source_packet")
    assert cohort["credit_boundary"]["outcome_neutral_source_packet"] is True
    assert cohort["audit_completion_test_met"] is False

    run170 = strict_json(RUN170)
    assert run170["run_id"] == "RUN-170-REVIEWED-OUTCOME-NEUTRAL-FLEET-VEHICLE-ALERTS-CONFIG-ROUTE-ACTION-OWNERSHIP-OVERLAY-WAVE-31"
    assert run170["reviewed_overlay"]["owner_route_actions"] == 1
    assert run170["reviewed_overlay"]["accepted_page_owner_records"] == 0
    assert run170["reviewed_overlay"]["accepted_controller_action_bridges"] == 1
    assert run170["overlay_source_records"][0]["review_outcome"] == "OWNER_ROUTE_ACTION"
    assert run170["overlay_source_records"][0]["feature_id"] == "CAP-FLEET-VEHICLE-REGISTER"
    assert run170["new_static_controller_action_bridges"][0]["method"] == "alertsConfig"
    assert run170["queue_boundary"]["next_unresolved_index"] == 84
    assert EXCLUDED_OLD_BUNDLE not in json.dumps(cohort, ensure_ascii=False)
    return cohort


def semantic_review(
    identifier: str,
    reviewer_task_path: str,
    payload: str,
    decisive_loci: list[str],
) -> dict[str, Any]:
    assert decisive_loci
    for locus in decisive_loci:
        validate_review_locus(locus)
    return sealed(
        {
            "review_id": identifier,
            "reviewer_role": "fresh independent strict-current semantic tiebreak reviewer",
            "reviewer_task_path": reviewer_task_path,
            "independent_from_neutral_packet_producer": True,
            "independent_from_other_tiebreak_reviewer": True,
            "other_tiebreak_reviewer_consulted": False,
            "performed_after_strict_current_split_stop": True,
            "review_method": "CURRENT_2026_08_24_DENOMINATOR_AND_PINNED_SOURCE_ONLY_NO_EXECUTION",
            "raw_payload": raw_payload(payload),
            "outcome": "OWNER_ROUTE_ACTION",
            "confidence": "HIGH_STATIC_IDENTITY_ONLY",
            "decisive_current_loci": decisive_loci,
            "decisive_current_loci_validated": True,
            "older_2026_08_12_bundle_consulted": False,
            "older_bundle_feature_identity_or_credit_imported": False,
            "current_run170_precedent_consulted": True,
            "route_ownership_authorized_for_later_overlay": True,
            "controller_action_bridge_authorized_for_later_overlay": True,
            "page_ownership_authorized": False,
            "adjacent_route_ownership_authorized": False,
            "correctness_or_downstream_credit_authorized": False,
            "reviewer_wrote_files": False,
            "reviewer_executed_application_or_tests": False,
        },
        "review_record_sha256",
    )


def artifact_review(
    identifier: str,
    reviewer_task_path: str,
    role: str,
    payload: str,
) -> dict[str, Any]:
    return sealed(
        {
            "review_id": identifier,
            "reviewer_role": role,
            "reviewer_task_path": reviewer_task_path,
            "independent_from_neutral_packet_producer": True,
            "independent_from_other_artifact_reviewer": True,
            "review_method": "FINAL_STABILIZED_HARDENED_NEUTRAL_BYTES_ONLY",
            "verdict": "GO_ZERO_REMAINING_ARTIFACT_DISCREPANCIES",
            "raw_payload": raw_payload(payload),
            "reviewed_checkpoint_commit": APPLICATION_COMMIT,
            "reviewed_checkpoint_tree": APPLICATION_TREE,
            "generator_sha256": COHORT_GENERATOR_SHA,
            "receipt_sha256": COHORT_SHA,
            "receipt_self_seal_sha256": COHORT_SELF_SEAL,
            "source_packet_sha256": SOURCE_PACKET_SHA,
            "pre_hardening_stop_or_no_go_counted_as_acceptance": False,
            "semantic_reviewer_role": False,
            "semantic_outcome_authored_or_authorized": False,
            "ownership_credit_authorized": False,
            "correctness_or_downstream_credit_authorized": False,
            "reviewer_wrote_workspace_files": False,
        },
        "artifact_review_record_sha256",
    )


def build() -> dict[str, Any]:
    cohort = validate_cohort()
    candidate = cohort["records"][0]

    chronology = [
        sealed(
            {
                "sequence": 1,
                "reviewer_task_path": "/root/run179_candidate_a",
                "stage": "PRELIMINARY",
                "reported_outcome": "UNRESOLVED_EVIDENCE_GAP_LIKE_CONSERVATISM",
                "accepted_for_final_synthesis": False,
                "reason_not_accepted": "Preliminary discovery input only; it did not resolve the frozen current capability identity.",
            },
            "chronology_record_sha256",
        ),
        sealed(
            {
                "sequence": 2,
                "reviewer_task_path": "/root/run179_candidate_b",
                "stage": "PRELIMINARY",
                "reported_outcome": "SHARED_RELATION",
                "accepted_for_final_synthesis": False,
                "reason_not_accepted": "The reasoning relied on excluded non-governing 2026-08-12 audit material; it was invalidated and rerun current-only.",
                "excluded_material": EXCLUDED_OLD_BUNDLE,
            },
            "chronology_record_sha256",
        ),
        sealed(
            {
                "sequence": 3,
                "reviewer_task_path": "/root/run179_feature_judge",
                "stage": "PRELIMINARY",
                "reported_outcome": "SHARED_RELATION",
                "accepted_for_final_synthesis": False,
                "reason_not_accepted": "The reasoning relied on excluded non-governing 2026-08-12 audit material; it was invalidated and rerun current-only.",
                "excluded_material": EXCLUDED_OLD_BUNDLE,
            },
            "chronology_record_sha256",
        ),
        sealed(
            {
                "sequence": 4,
                "reviewer_task_path": "/root/run179_candidate_b",
                "stage": "STRICT_CURRENT_RERUN",
                "reported_outcome": "OWNER_ROUTE_ACTION",
                "accepted_for_final_synthesis": False,
                "reason_not_accepted": "It conflicted with the separate strict-current EVIDENCE_GAP result, so integration stopped under the conflict rule pending bounded expansion and fresh tiebreaks.",
                "older_bundle_used": False,
            },
            "chronology_record_sha256",
        ),
        sealed(
            {
                "sequence": 5,
                "reviewer_task_path": "/root/run179_feature_judge",
                "stage": "STRICT_CURRENT_RERUN",
                "reported_outcome": "EVIDENCE_GAP",
                "accepted_for_final_synthesis": False,
                "reason_not_accepted": "It conflicted with the separate strict-current OWNER_ROUTE_ACTION result, so integration stopped under the conflict rule pending bounded expansion and fresh tiebreaks.",
                "older_bundle_used": False,
            },
            "chronology_record_sha256",
        ),
    ]

    reviews = [
        semantic_review(
            "RUN179R-STRICT-CURRENT-TIEBREAK-A",
            "/root/run179_tiebreak_a",
            TIEBREAK_A,
            [
                f"{PREFIX}/03-feature-to-benchmark-matrix.csv:108",
                "routes/fleet-assets.php:54",
                "app/Http/Controllers/FleetAssets/VehicleController.php:566-822",
                "resources/js/pages/fleet-assets/trips/index.tsx:308",
                f"{PREFIX}/{RUN170}:1",
            ],
        ),
        semantic_review(
            "RUN179R-STRICT-CURRENT-TIEBREAK-B",
            "/root/run179_tiebreak_b",
            TIEBREAK_B,
            [
                f"{PREFIX}/03-feature-to-benchmark-matrix.csv:108",
                "routes/fleet-assets.php:54",
                "app/Http/Controllers/FleetAssets/VehicleController.php:566-822",
                "resources/js/pages/fleet-assets/trips/index.tsx:151-745",
                f"{PREFIX}/{RUN170}:1",
            ],
        ),
    ]
    assert len(reviews) == 2
    assert len({review["reviewer_task_path"] for review in reviews}) == 2
    assert all(review["outcome"] == "OWNER_ROUTE_ACTION" for review in reviews)
    assert all(review["other_tiebreak_reviewer_consulted"] is False for review in reviews)

    artifact_reviews = [
        artifact_review(
            "RUN179R-NEUTRAL-ARTIFACT-GO-A",
            "/root/run179_packet_review_a",
            "independent exact-artifact/hash/provenance reviewer after fail-closed range hardening",
            ARTIFACT_REVIEW_A,
        ),
        artifact_review(
            "RUN179R-NEUTRAL-ARTIFACT-GO-B",
            "/root/run179_packet_review_b",
            "separate clean-clone deterministic/strict-JSON/self-seal/pin reviewer",
            ARTIFACT_REVIEW_B,
        ),
    ]
    assert len(artifact_reviews) == 2
    assert len({review["reviewer_task_path"] for review in artifact_reviews}) == 2
    assert not ({review["reviewer_task_path"] for review in reviews} & {review["reviewer_task_path"] for review in artifact_reviews})

    projection = {
        "status": "PROJECTED_ONLY_IF_LATER_REVIEWED_OVERLAY_INTEGRATES_THIS_DECISION",
        "currently_applied": False,
        "source_owners": {"before": 665, "delta": 1, "after": 666},
        "route_owners": {"before": 308, "delta": 1, "after": 309},
        "page_owners": {"before": 357, "delta": 0, "after": 357},
        "controller_action_bridges": {"before": 96, "delta": 1, "after": 97},
        "residual_source_records": {"before": 3264, "delta": -1, "after": 3263},
        "residual_route_records": {"before": 2893, "delta": -1, "after": 2892},
        "route_shared_relations": {"before": 12, "delta": 0, "after": 12},
        "route_alias_relations": {"before": 5, "delta": 0, "after": 5},
        "reviewed_queue_rows": {"before": 119, "delta": 1, "after": 120},
        "owner_queue_rows": {"before": 97, "delta": 1, "after": 98},
        "shared_queue_rows": {"before": 10, "delta": 0, "after": 10},
        "alias_queue_rows": {"before": 5, "delta": 0, "after": 5},
        "dead_queue_rows": {"before": 0, "delta": 0, "after": 0},
        "evidence_gap_queue_rows": {"before": 7, "delta": 0, "after": 7},
        "pending_queue_rows": {"before": 388, "delta": -1, "after": 387},
        "queue_rows_without_ownership": {"before": 410, "delta": -1, "after": 409},
        "next_unresolved_index": 85,
        "next_unresolved_queue_id": "RUN090-ROUTE-0086",
        "next_unresolved_route_record_id": "RUN077-ROUTE-0694",
        "feature_union": {
            "route_features": 64,
            "page_features": 242,
            "route_page_overlap": 50,
            "before": 256,
            "delta": 0,
            "after": 256,
        },
        "source_owner_coverage_percent_after": 16.950878,
        "conservation_equations": {
            "source_before": "665 + 3264 = 3929",
            "source_after": "666 + 3263 = 3929",
            "route_before": "308 + 12 shared + 5 alias + 2893 residual = 3218",
            "route_after": "309 + 12 shared + 5 alias + 2892 residual = 3218",
            "queue_before": "97 owner + 10 shared + 5 alias + 0 dead + 7 evidence-gap + 388 pending = 507",
            "queue_after": "98 owner + 10 shared + 5 alias + 0 dead + 7 evidence-gap + 387 pending = 507",
            "reviewed_before": "97 + 10 + 5 + 0 + 7 = 119",
            "reviewed_after": "98 + 10 + 5 + 0 + 7 = 120",
            "feature_union": "64 route + 242 page - 50 overlap = 256",
        },
    }
    assert projection["source_owners"]["before"] + projection["residual_source_records"]["before"] == 3929
    assert projection["source_owners"]["after"] + projection["residual_source_records"]["after"] == 3929
    assert (
        projection["route_owners"]["before"]
        + projection["route_shared_relations"]["before"]
        + projection["route_alias_relations"]["before"]
        + projection["residual_route_records"]["before"]
        == 3218
    )
    assert (
        projection["route_owners"]["after"]
        + projection["route_shared_relations"]["after"]
        + projection["route_alias_relations"]["after"]
        + projection["residual_route_records"]["after"]
        == 3218
    )
    assert (
        projection["owner_queue_rows"]["before"]
        + projection["shared_queue_rows"]["before"]
        + projection["alias_queue_rows"]["before"]
        + projection["dead_queue_rows"]["before"]
        + projection["evidence_gap_queue_rows"]["before"]
        + projection["pending_queue_rows"]["before"]
        == 507
    )
    assert (
        projection["owner_queue_rows"]["after"]
        + projection["shared_queue_rows"]["after"]
        + projection["alias_queue_rows"]["after"]
        + projection["dead_queue_rows"]["after"]
        + projection["evidence_gap_queue_rows"]["after"]
        + projection["pending_queue_rows"]["after"]
        == 507
    )
    assert (
        projection["owner_queue_rows"]["before"]
        + projection["shared_queue_rows"]["before"]
        + projection["alias_queue_rows"]["before"]
        + projection["dead_queue_rows"]["before"]
        + projection["evidence_gap_queue_rows"]["before"]
        == projection["reviewed_queue_rows"]["before"]
    )
    assert (
        projection["owner_queue_rows"]["after"]
        + projection["shared_queue_rows"]["after"]
        + projection["alias_queue_rows"]["after"]
        + projection["dead_queue_rows"]["after"]
        + projection["evidence_gap_queue_rows"]["after"]
        == projection["reviewed_queue_rows"]["after"]
    )
    assert projection["queue_rows_without_ownership"]["before"] == 507 - projection["owner_queue_rows"]["before"]
    assert projection["queue_rows_without_ownership"]["after"] == 507 - projection["owner_queue_rows"]["after"]
    assert (
        projection["feature_union"]["route_features"]
        + projection["feature_union"]["page_features"]
        - projection["feature_union"]["route_page_overlap"]
        == projection["feature_union"]["before"]
        == projection["feature_union"]["after"]
        == 256
    )
    assert (
        round(100 * projection["source_owners"]["after"] / 3929, 6)
        == projection["source_owner_coverage_percent_after"]
    )

    synthesis = sealed(
        {
            "synthesis_id": "RUN179R-INDEPENDENT-REVIEW-SYNTHESIS",
            "verdict": "GO_ACCEPT_1_OWNER_ROUTE_ACTION_FOR_BOUNDED_LATER_INTEGRATION",
            "accepted_independent_semantic_review_ids": [review["review_id"] for review in reviews],
            "accepted_independent_semantic_review_record_sha256s": [review["review_record_sha256"] for review in reviews],
            "accepted_artifact_review_ids": [review["review_id"] for review in artifact_reviews],
            "accepted_artifact_review_record_sha256s": [review["artifact_review_record_sha256"] for review in artifact_reviews],
            "semantic_and_artifact_roles_distinct": True,
            "chronology_record_sha256s": [record["chronology_record_sha256"] for record in chronology],
            "preliminary_old_bundle_contaminated_shared_judgments_invalidated": 2,
            "strict_current_conflict_stop_observed": True,
            "strict_current_split": {"OWNER_ROUTE_ACTION": 1, "EVIDENCE_GAP": 1},
            "original_strict_current_dissent_preserved": True,
            "dissenting_strict_current_outcome": "EVIDENCE_GAP",
            "bounded_expansion_sources": [
                "current 2026-08-24 340-capability denominator",
                "RUN179 pinned current-source packet",
                "RUN170 current-bundle route-action ownership precedent",
            ],
            "excluded_2026_08_12_identity_or_credit_imported": False,
            "fresh_tiebreak_votes": {"OWNER_ROUTE_ACTION": 2, "SHARED_RELATION": 0, "EVIDENCE_GAP": 0},
            "conflict_reconciliation": "The original EVIDENCE_GAP dissent remains recorded; two later independent current-only tiebreaks examined the named bounded evidence, did not consult one another, and both resolved the single candidate as OWNER_ROUTE_ACTION.",
            "candidate_outcome": {"OWNER_ROUTE_ACTION": 1, "SHARED_RELATION": 0, "EVIDENCE_GAP": 0},
            "canonical_candidate_record_sha256": CANDIDATE_HASH,
            "queue_record_self_seal_sha256": QUEUE_RECORD_SEAL,
            "route_ownership_authorized_for_later_overlay": True,
            "controller_action_bridge_authorized_for_later_overlay": True,
            "page_ownership_authorized": False,
            "bounded_overlay_integration_authorized_later_only": True,
            "current_overlay_credit_awarded": False,
            "correctness_or_downstream_credit_authorized": False,
            "conditional_projection": projection,
            "synthesizer_wrote_files": False,
        },
        "synthesis_record_sha256",
    )

    decision = sealed(
        {
            "decision_id": "RUN179R-FLEET-TRIP-INDEX-OWNER-ROUTE-ACTION",
            "candidate_record_sha256": CANDIDATE_HASH,
            "queue_record_self_seal_sha256": QUEUE_RECORD_SEAL,
            "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
            "queue_index_zero_based": 84,
            "queue_id": "RUN090-ROUTE-0085",
            "route_record_id": "RUN077-ROUTE-0693",
            "literal_route_name": "fleet-assets.trips.index",
            "controller_fqcn": "App\\Http\\Controllers\\FleetAssets\\VehicleController",
            "controller_file": "app/Http/Controllers/FleetAssets/VehicleController.php",
            "controller_method": "trips",
            "candidate_feature_id": "CAP-FLEET-VEHICLE-REGISTER",
            "outcome": "OWNER_ROUTE_ACTION",
            "confidence": "HIGH_STATIC_IDENTITY_ONLY_TWO_OF_TWO_FRESH_TIEBREAKS",
            "owner_source_record_key": "route|RUN077-ROUTE-0693|CAP-FLEET-VEHICLE-REGISTER",
            "bridge_key": [
                "app/Http/Controllers/FleetAssets/VehicleController.php",
                "trips",
                "CAP-FLEET-VEHICLE-REGISTER",
            ],
            "route_ownership_authorized_for_later_overlay": True,
            "controller_action_bridge_authorized_for_later_overlay": True,
            "page_ownership_authorized": False,
            "adjacent_route_ownership_authorized": False,
            "model_service_helper_caller_or_test_ownership_authorized": False,
            "current_overlay_credit_awarded": False,
            "correctness_or_downstream_credit_authorized": False,
            "conditional_projection": projection,
        },
        "decision_record_sha256",
    )

    false_credit = {
        key: False
        for key in (
            "current_overlay_ownership",
            "static_page_feature_ownership",
            "adjacent_route_ownership",
            "model_service_helper_caller_or_test_ownership",
            "canonical_object_ownership_correctness",
            "approved_site_scope_correctness",
            "permission_correctness",
            "privacy_correctness",
            "direct_object_concealment_correctness",
            "query_projection_correctness",
            "framework_route_reachability",
            "runtime",
            "database",
            "build",
            "application_browser",
            "responsive_application",
            "executed_tests",
            "remediation",
            "benchmark",
            "final_no_match_or_NCM",
            "ease",
            "release",
            "publication",
            "pass",
            "final_finding",
            "feature_completion",
            "completion",
            "gate_4",
            "audit_complete",
        )
    }

    payload: dict[str, Any] = {
        "schema_version": "run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34-v1",
        "run_id": "RUN-179R-INDEPENDENT-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-REVIEW-WAVE-34",
        "status": "GO_TWO_OF_TWO_FRESH_STRICT_CURRENT_TIEBREAKS_ACCEPT_ONE_STATIC_ROUTE_OWNER_AND_BRIDGE_FOR_LATER_INTEGRATION_ZERO_CURRENT_OR_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-30",
        "decision": "GO",
        "architecture_rule": "One operating organisation across multiple Sites. Exact permissions, approved Sites, canonical record ownership, direct-object concealment and privacy are the boundaries; no tenant design or tenant-isolation credit.",
        "pins": {
            "checkpoint_commit": HEAD,
            "checkpoint_tree": TREE,
            "checkpoint_parent": PARENT,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "checkpoint_audit_subtree": CHECKPOINT_AUDIT_SUBTREE,
            "application_subtrees": cohort["pins"]["subtrees"],
            "governing_prompt": cohort["pins"]["governing_prompt"],
            "continuation_request": cohort["pins"]["continuation_request"],
            "cohort_generator": file_record(COHORT_GENERATOR),
            "cohort": file_record(COHORT),
            "cohort_self_seal_sha256": COHORT_SELF_SEAL,
            "source_packet_sha256": SOURCE_PACKET_SHA,
            "candidate_record_sha256": CANDIDATE_HASH,
            "queue_record_self_seal_sha256": QUEUE_RECORD_SEAL,
            "selected_controller_method_slice_sha256": TRIPS_METHOD_SLICE_SHA,
            "cohort_inputs": cohort["pins"]["inputs"],
            "generator": file_record(GENERATOR),
        },
        "methods": {
            "accepted_independent_semantic_reviewers": 2,
            "independent_artifact_reviewers": 2,
            "semantic_and_artifact_reviewer_roles_distinct": True,
            "strict_current_source_only": True,
            "current_2026_08_24_denominator_only": True,
            "application_executed": False,
            "framework_routes_executed": False,
            "database_used": False,
            "build_used": False,
            "browser_used": False,
            "tests_executed": False,
        },
        "review_chronology": chronology,
        "excluded_material_boundary": {
            "path": EXCLUDED_OLD_BUNDLE,
            "role": "NON_GOVERNING_EXCLUDED_FROM_FINAL_IDENTITY_AND_CREDIT",
            "preliminary_shared_judgments_invalidated": 2,
            "feature_identity_imported": False,
            "benchmark_or_mapping_credit_imported": False,
            "semantic_vote_imported": False,
        },
        "bounded_expansion": {
            "trigger": "ONE_OWNER_ROUTE_ACTION_VERSUS_ONE_EVIDENCE_GAP_STRICT_CURRENT_SPLIT",
            "integration_stopped_before_expansion": True,
            "examined_only": [
                "current 2026-08-24 denominator row 108",
                "RUN179 current source packet",
                "RUN170 current-bundle accepted precedent",
            ],
            "examined_older_2026_08_12_bundle": False,
            "rewrote_or_split_frozen_denominator": False,
            "inherited_or_recredited_neighbor_outcomes": False,
        },
        "independent_semantic_tiebreak_reviews": reviews,
        "independent_neutral_artifact_reviews": artifact_reviews,
        "synthesis_review": synthesis,
        "action_decision": decision,
        "verified_counts": {
            "cohort_records": 1,
            "accepted_independent_semantic_reviews": 2,
            "independent_artifact_go_reviews": 2,
            "owner_route_actions": 1,
            "shared_relations": 0,
            "evidence_gaps": 0,
            "route_owners_authorized_for_later_overlay": 1,
            "controller_action_bridges_authorized_for_later_overlay": 1,
            "page_owners_authorized": 0,
            "current_overlay_rows_written": 0,
            "source_files_revalidated": 9,
            "source_ranges_revalidated": 13,
            "exact_line_anchors_revalidated": 23,
            "method_slices_revalidated": 7,
            "tracked_input_sha_blob_pairs_revalidated": 11,
            "application_subtree_pins_revalidated": 9,
            "final_findings": 0,
        },
        "queue_boundary_reconciliation": {
            "reviewed_queue_rows_before_overlay": 119,
            "owner_queue_rows_before_overlay": 97,
            "pending_queue_rows_before_overlay": 388,
            "queue_rows_without_ownership_before_overlay": 410,
            "selected_index_84_still_unintegrated": True,
            "true_next_unresolved_index_before_overlay": 84,
            "true_next_unresolved_queue_id_before_overlay": "RUN090-ROUTE-0085",
            "conditional_projection_only": projection,
        },
        "source_packet_boundary": {
            "final_hardened_artifact_review_complete": True,
            "selected_source_semantic_review_complete": True,
            "source_packet_completeness_beyond_selected_action_claimed": False,
            "ownership_material_expansion_required_beyond_bounded_current_inputs": False,
            "correctness_observations_or_execution_authorize_no_credit": True,
        },
        "remediation_and_history_noninheritance": cohort["remediation_and_history_noninheritance"],
        "credit_boundary": {
            "reviewed_static_route_feature_ownership_for_1_record": True,
            "reviewed_static_controller_action_bridge_for_1_action": True,
            "bounded_overlay_integration_authorized_later_only": True,
            **false_credit,
        },
        "completion_boundary": cohort["completion_boundary"],
        "source_review_complete": True,
        "artifact_completion_test_met": False,
        "audit_completion_test_met": False,
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    payload["self_seal"] = {
        "algorithm": "sha256-canonical-json-with-self-seal-omitted",
        "sha256": canonical(payload),
    }
    return payload


def main() -> None:
    payload = build()
    output_path = AUDIT / OUTPUT
    temp_path = output_path.with_name(output_path.name + ".tmp")
    assert not temp_path.exists(), temp_path
    try:
        temp_path.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
            newline="\n",
        )
        temp_path.replace(output_path)
    finally:
        if temp_path.exists():
            temp_path.unlink()

    assert_exact_dirty_set()
    parsed = strict_json(OUTPUT)
    seal = parsed.pop("self_seal")
    assert seal["sha256"] == canonical(parsed)
    assert parsed["pins"]["generator"] == file_record(GENERATOR)
    assert len(parsed["review_chronology"]) == 5
    for record in parsed["review_chronology"]:
        verify_record_seal(record, "chronology_record_sha256")
    assert len(parsed["independent_semantic_tiebreak_reviews"]) == 2
    for record in parsed["independent_semantic_tiebreak_reviews"]:
        verify_record_seal(record, "review_record_sha256")
        raw = record["raw_payload"]["text"].encode("utf-8")
        assert record["raw_payload"]["sha256"] == sha(raw)
        assert record["raw_payload"]["bytes"] == len(raw)
        assert record["raw_payload"]["lines"] == len(record["raw_payload"]["text"].splitlines())
        assert record["decisive_current_loci_validated"] is True
        for locus in record["decisive_current_loci"]:
            validate_review_locus(locus)
    assert [
        record["raw_payload"]["text"]
        for record in parsed["independent_semantic_tiebreak_reviews"]
    ] == [TIEBREAK_A, TIEBREAK_B]
    assert len(parsed["independent_neutral_artifact_reviews"]) == 2
    for record in parsed["independent_neutral_artifact_reviews"]:
        verify_record_seal(record, "artifact_review_record_sha256")
        raw = record["raw_payload"]["text"].encode("utf-8")
        assert record["raw_payload"]["sha256"] == sha(raw)
        assert record["raw_payload"]["bytes"] == len(raw)
        assert record["raw_payload"]["lines"] == len(record["raw_payload"]["text"].splitlines())
        assert record["semantic_reviewer_role"] is False
        assert record["semantic_outcome_authored_or_authorized"] is False
    assert [
        record["raw_payload"]["text"]
        for record in parsed["independent_neutral_artifact_reviews"]
    ] == [ARTIFACT_REVIEW_A, ARTIFACT_REVIEW_B]
    verify_record_seal(parsed["synthesis_review"], "synthesis_record_sha256")
    verify_record_seal(parsed["action_decision"], "decision_record_sha256")
    assert not list(AUDIT.rglob("__pycache__"))
    print(
        json.dumps(
            {
                "status": payload["status"],
                "generator_sha256": audit_sha(GENERATOR),
                "receipt_sha256": audit_sha(OUTPUT),
                "semantic_review_seals": [
                    review["review_record_sha256"]
                    for review in payload["independent_semantic_tiebreak_reviews"]
                ],
                "artifact_review_seals": [
                    review["artifact_review_record_sha256"]
                    for review in payload["independent_neutral_artifact_reviews"]
                ],
                "synthesis_seal": payload["synthesis_review"]["synthesis_record_sha256"],
                "decision_seal": payload["action_decision"]["decision_record_sha256"],
                "self_seal": seal["sha256"],
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
