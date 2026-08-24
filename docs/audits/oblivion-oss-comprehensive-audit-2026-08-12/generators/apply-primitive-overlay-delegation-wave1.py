#!/usr/bin/env python3
"""Promote only primitive roots delegated to reachable, statically resolved custom usages."""

from __future__ import annotations

import hashlib
import json
import re
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-21T23:50:00+12:00"

CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "primitive-overlay-delegation-adjudication-904-wave1.json"
SUMMARY = SOURCE / "primitive-overlay-delegation-wave1-generation-summary.json"

EXPECTED_CLASSIFIER = "42266d989a55ff3c47f9dba6bea9a696d6f81096ffaadd26dfb2e3864bb665cf"
EXPECTED_POINTER = "d60a17b4c7ef34cf18a1cb67a233dc2ef5f48b950442688d1e5f7efbdf8f88bd"
STRUCTURAL_35_ID_SHA = "a4bb9895d7daa5530eb5a97ac9b4aae1e8995c40123daa92d691bf40a7bc39ec"
STRUCTURAL_35_PROOF_SHA = "fea0a327bb6b7da4190b21e194485e66adf5a5efa8a15e48a6138e167e7b5f02"
REACHABLE_34_ID_SHA = "2e7814baa5494acd5e0cee6328850d481c181bf500e6cd1586cbdd23085d6ca9"
REACHABLE_34_PROOF_SHA = "cb07fe044f4833a7f2d8e8264bf18a480a3b3ab9dd569e5a0b9cb6d4eeb80858"
EXCLUDED = "resources/js/components/handover-write-sheet.tsx|63|Sheet"


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def record(path: Path) -> dict[str, Any]:
    return {"path": path.relative_to(AUDIT).as_posix(), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def identity(row: dict[str, Any]) -> str:
    return f"{row['file']}|{row['line']}|{row['symbol']}"


def anchor_file(anchor: str) -> str:
    match = re.fullmatch(r"(.+):(\d+)", anchor)
    return str(match.group(1)) if match is not None else anchor


def candidate_rows(classifier: dict[str, Any], *, reachable: bool) -> list[tuple[dict[str, Any], list[dict[str, Any]], str]]:
    unresolved_by_file: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for row in classifier["primitive_rows"]:
        if row.get("trigger_resolution") == "unresolved":
            unresolved_by_file[str(row["file"])].append(row)

    usages_by_file: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for usage in classifier["custom_rows"]:
        anchor = usage.get("implementation_anchor")
        if anchor:
            usages_by_file[anchor_file(str(anchor))].append(usage)

    selected: list[tuple[dict[str, Any], list[dict[str, Any]], str]] = []
    for file, roots in unresolved_by_file.items():
        if len(roots) != 1:
            continue
        usages = usages_by_file.get(file, [])
        if not usages or any(usage.get("trigger_resolution") != "resolved" for usage in usages):
            continue
        anchors = {str(usage.get("implementation_anchor")) for usage in usages}
        require(len(anchors) == 1, f"Multiple implementation anchors for {file}")
        if reachable and any(usage.get("reachable") is not True for usage in usages):
            continue
        selected.append((roots[0], usages, next(iter(anchors))))
    return sorted(selected, key=lambda item: identity(item[0]))


def proof_lines(rows: list[tuple[dict[str, Any], list[dict[str, Any]], str]]) -> list[str]:
    output: list[str] = []
    for root, usages, anchor in rows:
        usage_rows = sorted(
            f"{usage['file']}|{usage['line']}|{usage['symbol']}|{usage.get('state_identifier')}|{usage.get('setter_identifier')}"
            for usage in usages
        )
        output.append(identity(root) + "\x1f" + anchor + "\x1f" + "\x1e".join(usage_rows))
    return output


if any(path.exists() for path in (ARTIFACT, SUMMARY)):
    require(all(path.exists() for path in (ARTIFACT, SUMMARY)), "Partial primitive-delegation output set")
    classifier = load(CLASSIFIER)
    require(classifier["primitive_root_layer"]["exact_trigger_resolved"] == 242, "Existing primitive count drift")
    pointer = load(POINTER)
    for key, path in (("primitive_overlay_delegation_wave1", ARTIFACT),
                      ("primitive_overlay_delegation_wave1_generation_summary", SUMMARY),
                      ("overlay_trigger_classification", CLASSIFIER)):
        require(pointer["artifacts"][key] == record(path), f"Existing pointer drift: {key}")
    print(json.dumps({"status": "already_applied", "artifact": record(ARTIFACT),
                      "classifier": record(CLASSIFIER), "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

require(sha_file(CLASSIFIER) == EXPECTED_CLASSIFIER, "Classifier input drift")
require(sha_file(POINTER) == EXPECTED_POINTER, "Pointer input drift")
classifier = load(CLASSIFIER)
structural = candidate_rows(classifier, reachable=False)
selected = candidate_rows(classifier, reachable=True)

structural_ids = [identity(row) for row, _, _ in structural]
selected_ids = [identity(row) for row, _, _ in selected]
require(len(structural) == 35 and sha_text("\n".join(structural_ids)) == STRUCTURAL_35_ID_SHA, "Structural candidate identity drift")
require(sha_text("\n".join(proof_lines(structural))) == STRUCTURAL_35_PROOF_SHA, "Structural proof drift")
require(len(selected) == 34 and sha_text("\n".join(selected_ids)) == REACHABLE_34_ID_SHA, "Reachable candidate identity drift")
require(sha_text("\n".join(proof_lines(selected))) == REACHABLE_34_PROOF_SHA, "Reachable proof drift")
require(set(structural_ids) - set(selected_ids) == {EXCLUDED}, "Unexpected reachability exclusion")

adjudications: list[dict[str, Any]] = []
for root, usages, anchor in selected:
    require(root["trigger_classification"] == "controlled_without_resolved_activator" and root["trigger_resolution"] == "unresolved", f"Primitive preimage drift: {identity(root)}")
    evidence = {
        "kind": "delegated_to_all_statically_reachable_resolved_usage_activators",
        "implementation_anchor": anchor,
        "usage_identities": [identity(usage) for usage in sorted(usages, key=identity)],
        "usage_count": len(usages),
        "all_usages_reachable": True,
        "all_usages_trigger_resolved": True,
        "credit_boundary": "audited-baseline static delegation only",
    }
    root["trigger_classification"] = "delegated_to_all_statically_reachable_resolved_usage_activators"
    root["trigger_resolution"] = "resolved"
    root["trigger_evidence"] = [evidence]
    adjudications.append({"identity": identity(root), "verdict": "GO_STATIC_DELEGATION", "evidence": evidence})

class_counts = Counter(str(row["trigger_classification"]) for row in classifier["primitive_rows"])
resolution_counts = Counter(str(row["trigger_resolution"]) for row in classifier["primitive_rows"])
require(class_counts == Counter({
    "nested_explicit_primitive_trigger": 145,
    "same_scope_state_handler": 63,
    "controlled_without_resolved_activator": 235,
    "delegated_to_all_statically_reachable_resolved_usage_activators": 34,
}), "Primitive class-count drift")
require(resolution_counts == Counter({"resolved": 242, "unresolved": 235}), "Primitive resolution-count drift")
classifier["primitive_root_layer"].update({
    "exact_trigger_resolved": 242,
    "unresolved": 235,
    "classification_counts": dict(sorted(class_counts.items())),
    "resolution_counts": dict(sorted(resolution_counts.items())),
})

artifact = {
    "schema_version": "1.0.0",
    "artifact": "primitive-overlay-delegation-adjudication-904-wave1",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_34_static_delegations_runtime_unchanged",
    "audit_boundary": "Primitive-to-custom-usage static source linkage only. Runtime, browser, focus, keyboard, visibility, permission, usability, representative-task and completion credit remain zero.",
    "input": record(CLASSIFIER),
    "selection": {
        "predicate": "Exactly one unresolved primitive root per definition file; at least one custom usage; one exact full implementation anchor; every matching custom usage is reachable and trigger-resolved.",
        "structural_candidate_count": 35,
        "structural_identity_sha256": STRUCTURAL_35_ID_SHA,
        "structural_proof_sha256": STRUCTURAL_35_PROOF_SHA,
        "accepted_count": 34,
        "accepted_identities": selected_ids,
        "accepted_identity_sha256": REACHABLE_34_ID_SHA,
        "accepted_proof_sha256": REACHABLE_34_PROOF_SHA,
        "excluded_unreachable_identity": EXCLUDED,
        "serialization": "Ordinal-sorted file|line|symbol identities; proof uses identity + US + exact full implementation_anchor + US + ordinal-sorted custom usage tuples joined by RS; LF join, no final LF, UTF-8/no BOM.",
    },
    "adjudications": adjudications,
    "count_delta": {"primitive_exact_trigger_resolved": 34, "primitive_unresolved": -34, "runtime_credit": 0},
    "post_counts": {"primitive_denominator": 477, "primitive_exact_trigger_resolved": 242, "primitive_unresolved": 235,
                    "custom_denominator": 659, "custom_exact_trigger_resolved": 223, "custom_unresolved_or_blocked": 270},
}

write_json(ARTIFACT, artifact)
write_json(CLASSIFIER, classifier)
summary = {
    "schema_version": "1.0.0",
    "artifact": "primitive-overlay-delegation-wave1-generation-summary",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "outputs": {"adjudication": record(ARTIFACT), "overlay_classifier": record(CLASSIFIER)},
    "counts": {"primitive_denominator": 477, "primitive_exact_trigger_resolved": 242, "primitive_unresolved": 235,
               "custom_denominator": 659, "custom_exact_trigger_resolved": 223, "custom_unresolved_or_blocked": 270},
    "credit_boundary": {"static_classifier_delta": 34, "runtime_credit_delta": 0, "browser_credit_delta": 0, "completion_credit_delta": 0},
}
write_json(SUMMARY, summary)
pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({
    "primitive_overlay_delegation_wave1": record(ARTIFACT),
    "primitive_overlay_delegation_wave1_generation_summary": record(SUMMARY),
    "overlay_trigger_classification": record(CLASSIFIER),
})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)
print(json.dumps({"status": "applied", "artifact": record(ARTIFACT),
                  "classifier": record(CLASSIFIER), "summary": record(SUMMARY),
                  "pointer": record(POINTER)}, indent=2))
