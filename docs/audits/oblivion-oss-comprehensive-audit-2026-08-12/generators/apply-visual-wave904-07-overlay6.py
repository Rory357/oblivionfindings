#!/usr/bin/env python3
"""Record zero-promotion visual Wave 07 and exact-static overlay Wave 6."""

from __future__ import annotations

import csv
import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
ROOT = AUDIT.parent.parent.parent
SOURCE = AUDIT / "evidence" / "source"
GENERATED_AT = "2026-08-21T23:10:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

MANIFEST = SOURCE / "working-capability-manifest-904.json"
INVENTORY = AUDIT / "inventory-904.json"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
VISUAL07 = SOURCE / "visual-final-id-adjudication-904-wave07.json"
OVERLAY6 = SOURCE / "overlay-trigger-adjudication-904-wave6.json"
SUMMARY = SOURCE / "final-904-visual-wave07-overlay6-generation-summary.json"

PINS = {
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "inventory": "bca2b2549bef0e737df5bde5e2db6a158dd53f978aa6b84800e3a7f561ed8ec2",
    "visual": "707885a83264c8e2ab3f92898578a2b20ba3e8a2ccdb3ece17156d8c0774c293",
    "classifier": "268c964f0fbe616073610bec942321b3e8ad7c001d6d98d28b502f5fdc1db7e7",
    "research_pointer": "c7647482e5a9e20bdf229b3db116495b35be7f2e6b08306335c32dbf394517ca",
    "materialization_pointer": "74e064a636271fa31dff6faaf8534e885273d4dcd421ae492e587458fc72a9c6",
}

VISUAL_IDS = [
    "VIS-021084", "VIS-021091", "VIS-016570", "VIS-018672", "VIS-018673",
    "VIS-018674", "VIS-018693", "VIS-018694", "VIS-018697", "VIS-018959",
    "VIS-019381", "VIS-020237", "VIS-020240", "VIS-020241", "VIS-020505",
    "VIS-018968", "VIS-020305", "VIS-020306", "VIS-018967", "VIS-015116",
    "VIS-019227", "VIS-016555", "VIS-018490", "VIS-018635", "VIS-019172",
]

OVERLAY_ROWS = [
    ("resources/js/components/rostering/availability-pane.tsx", 222, "EditAvailabilityDialog", "7fb2a3e840d1080304646c02beeaeeb3c23fc066aac689bd836805014d8bbdf6", "editing", "setEditing", "state 87; callback/setter 216; Button trigger 441; open 223"),
    ("resources/js/components/rostering/calendar-pane.tsx", 1656, "DayDetailDialog", "db91992debab73eeb37e6e2d59eda7f0fd61bcff11a487ca1ea77e5585e17dcf", "openDayKey", "setOpenDayKey", "state 974; setter 1314-1318; day-cell triggers 415/487; callback binding 1620; open 1657"),
    ("resources/js/components/timesheets/view-timesheet-dialog.tsx", 543, "ReasonDialog", "c4080c145fe094f71d9c220831d67af22e6c87a3533fda1d8b9d23cf1986cd20", "reasonAction", "setReasonAction", "state 162; intrinsic trigger/setters 501/510; open 544"),
    ("resources/js/pages/attendance/index.tsx", 1467, "FixClockOutWizard", "a571a5439fc53e021a67f94e72a4d844cb551fb828138496051c6855d244bd60", "fixSessions", "setFixSessions", "state 717; hero trigger/setter 1325-1327; open 1468"),
    ("resources/js/pages/attendance/index.tsx", 1474, "HandoverDetailDialog", "a571a5439fc53e021a67f94e72a4d844cb551fb828138496051c6855d244bd60", "detailId", "setDetailId", "state 722; intrinsic row trigger 578; callback/setter 1448; open 1476"),
    ("resources/js/pages/attendance/index.tsx", 1544, "ReasonDialog", "a571a5439fc53e021a67f94e72a4d844cb551fb828138496051c6855d244bd60", "endTarget", "setEndTarget", "state 725; context action/setter 1018; open 1545"),
    ("resources/js/pages/compliance/index.tsx", 816, "LogObligationDialog", "1fd05a173190bcb239c2a5efcbbff5b331b2c1505d8aa9c73bdab68872eae5ed", "wizard", "setWizard", "state 348; setter 352-354; hero trigger 484; open 817"),
    ("resources/js/pages/compliance/index.tsx", 822, "RecordEvidenceDialog", "1fd05a173190bcb239c2a5efcbbff5b331b2c1505d8aa9c73bdab68872eae5ed", "wizard", "setWizard", "state 348; setter 352-354; hero/context trigger 485/438; open 823"),
    ("resources/js/pages/compliance/index.tsx", 828, "CompleteObligationDialog", "1fd05a173190bcb239c2a5efcbbff5b331b2c1505d8aa9c73bdab68872eae5ed", "wizard", "setWizard", "state 348; setter 352-354; context trigger 437; open 829"),
    ("resources/js/pages/compliance/index.tsx", 835, "LogNotifiableDialog", "1fd05a173190bcb239c2a5efcbbff5b331b2c1505d8aa9c73bdab68872eae5ed", "wizard", "setWizard", "state 348; setter 352-354; hero trigger 486; open 836"),
]


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(lines).encode("utf-8")).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def record(path: Path) -> dict[str, Any]:
    return {"path": path.relative_to(AUDIT).as_posix(), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


if any(path.exists() for path in (VISUAL07, OVERLAY6, SUMMARY)):
    require(all(path.exists() for path in (VISUAL07, OVERLAY6, SUMMARY)), "Partial Wave07 output set")
    classifier = load(CLASSIFIER)
    require(classifier["custom_usage_layer"]["exact_trigger_resolved"] == 213, "Existing overlay count drift")
    pointer = load(POINTER)
    for key, path in (("visual_wave904_07", VISUAL07), ("overlay_trigger_wave6", OVERLAY6),
                      ("visual_wave07_overlay6_generation_summary", SUMMARY),
                      ("overlay_trigger_classification", CLASSIFIER)):
        require(pointer["artifacts"][key] == record(path), f"Existing pointer drift: {key}")
    print(json.dumps({"status": "already_applied", "visual07": record(VISUAL07), "overlay6": record(OVERLAY6),
                      "classifier": record(CLASSIFIER), "summary": record(SUMMARY)}, indent=2))
    raise SystemExit(0)

for path, expected, label in ((MANIFEST, PINS["manifest"], "manifest"), (INVENTORY, PINS["inventory"], "inventory"),
                              (VISUAL, PINS["visual"], "visual matrix"), (CLASSIFIER, PINS["classifier"], "classifier"),
                              (POINTER, PINS["materialization_pointer"], "materialization pointer")):
    require(sha_file(path) == expected, f"{label} input drift")
require(sha_lines(VISUAL_IDS) == "ff533e4b1ecc10b3046dd4d4b317eaf629f5054ba56158dccf983ed814fd0e69", "Visual selection drift")
require(sha_lines(sorted(VISUAL_IDS)) == "bf63274bfa428ec9f0cb6638094bd865fa517c575818463ed8fb5d71df2d23a4", "Visual set drift")

with VISUAL.open("r", encoding="utf-8-sig", newline="") as handle:
    visual_rows = list(csv.DictReader(handle))
by_visual = {row["visual_id"]: row for row in visual_rows}
require(len(by_visual) == len(visual_rows), "Visual ID uniqueness drift")
for visual_id in VISUAL_IDS:
    row = by_visual[visual_id]
    require(not row["feature_id"] and row["feature_link_status"].startswith("unresolved"), f"Visual state drift: {visual_id}")
    require(row["pattern_type"] == "material-state-applicability", f"Visual material-state drift: {visual_id}")

visual07 = {
    "schema_version": "1.0.0", "artifact": "visual-final-id-adjudication-904-wave07",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_zero_promotion",
    "audit_boundary": "Audited-baseline static final-ID ownership adjudication only. No current-main, browser, runtime, screenshot, usability, finding, benchmark or completion credit is inferred.",
    "inputs": {"manifest": record(MANIFEST), "inventory": record(INVENTORY), "visual_matrix": record(VISUAL),
               "research_pointer_sha256": PINS["research_pointer"], "materialization_pointer_preimage": record(POINTER)},
    "selection": {"rule": "Exclude 157 previously reviewed IDs; unresolved material rows with exact singleton route owners, one shared controller, present methods, empty owner intersection and no singleton page fallback; rank exact-controller, route count, visual_id; take 25.",
                  "exclusion_count": 157, "exclusion_sha256": "4bf3a9bfa5641d3e5ea398ec7bdf3ae2fdd654c749e2b2de1910fa24c0888aa7",
                  "ordered_ids": VISUAL_IDS, "ordered_sha256": "ff533e4b1ecc10b3046dd4d4b317eaf629f5054ba56158dccf983ed814fd0e69",
                  "sorted_sha256": "bf63274bfa428ec9f0cb6638094bd865fa517c575818463ed8fb5d71df2d23a4",
                  "upstream_declared_proof_tuple_sha256": "ef98d1a55539c9236941156e40c485948042a831aa760c22286f4adbb421c58d",
                  "upstream_declared_source_map_sha256": "cfc7386b218d7f30faee00331c50f76d4c47f0ed054ca7a2f816451c346a2d7a",
                  "independent_review": "Selection, route-owner intersections, page collisions, source methods and counts replayed; upstream proof/source-map digests retained as identity pins because no unambiguous delimiter preimage was supplied."},
    "adjudications": [{"visual_id": visual_id, "verdict": "RETAIN_UNRESOLVED",
                       "reason": "Exact route owners have an empty final-ID intersection and no singleton exact page fallback.",
                       "runtime_credit": 0} for visual_id in VISUAL_IDS],
    "count_delta": {"visual_assigned": 0, "visual_unresolved": 0, "material_assigned": 0, "material_unresolved": 0, "runtime_credit": 0},
    "post_counts": {"visual_assigned": 8168, "visual_rows": 8753, "visual_unresolved": 585,
                    "material_assigned": 3948, "material_rows": 4312, "material_unresolved": 364,
                    "unique_assigned_targets": 774},
}

classifier = load(CLASSIFIER)
by_key = {(str(row["file"]), int(row["line"]), str(row["symbol"])): row for row in classifier["custom_rows"]}
adjudications = []
for file, line, symbol, source_sha, state, setter, chain in OVERLAY_ROWS:
    key = (file, line, symbol)
    require(key in by_key, f"Missing overlay row: {key}")
    row = by_key[key]
    require(row["trigger_classification"] == "internal_state_trigger_candidate" and row["trigger_resolution"] == "source_inferred_not_exactly_paired", f"Overlay state drift: {key}")
    require(row.get("state_identifier") is None and row.get("setter_identifier") is None, f"Overlay null preimage drift: {key}")
    require(sha_file(ROOT / file) == source_sha, f"Overlay source drift: {file}")
    evidence = {"kind": "named_state_open_handler", "file": file, "source_sha256": source_sha,
                "state_identifier": state, "setter": setter, "static_chain": chain,
                "credit_boundary": "audited-baseline static pairing only"}
    row["state_identifier"] = state
    row["setter_identifier"] = setter
    row["trigger_classification"] = "same_scope_state_handler"
    row["trigger_resolution"] = "resolved"
    row["trigger_evidence"] = [evidence]
    adjudications.append({"file": file, "line": line, "symbol": symbol,
                          "verdict": "GO_exact_static_trigger_pairing", "evidence": evidence})

selection = [f"{file}:{line}:{symbol}" for file, line, symbol, *_ in OVERLAY_ROWS]
require(sha_lines(selection) == "d92fff5af8a8fb2fe9af6286c5f4438fa04c4087594c22a348f9751a534adb07", "Overlay selection drift")
class_counts = Counter(str(row["trigger_classification"]) for row in classifier["custom_rows"])
resolution_counts = Counter(str(row["trigger_resolution"]) for row in classifier["custom_rows"])
require(class_counts["same_scope_state_handler"] == 194 and class_counts["internal_state_trigger_candidate"] == 173, "Overlay class-count drift")
require(resolution_counts["resolved"] == 213 and resolution_counts["source_inferred_not_exactly_paired"] == 173, "Overlay resolution-count drift")
require(class_counts["controlled_without_resolved_activator"] == 256 and resolution_counts["unresolved"] == 271, "Unrelated overlay-count drift")
classifier["custom_usage_layer"].update({"exact_trigger_resolved": 213, "source_inferred_not_exactly_paired": 173,
    "unresolved_or_blocked": 273, "classification_counts": dict(sorted(class_counts.items())),
    "resolution_counts": dict(sorted(resolution_counts.items()))})

overlay6 = {
    "schema_version": "1.0.0", "artifact": "overlay-trigger-adjudication-904-wave6",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_exact_static_pairings_runtime_unchanged",
    "audit_boundary": "Audited-baseline static source trigger pairing only. Current-main, runtime, focus, keyboard, viewport, visibility, permission, usability and completion credit remain zero.",
    "method": {"selection_sha256": "d92fff5af8a8fb2fe9af6286c5f4438fa04c4087594c22a348f9751a534adb07",
               "exclusion_count": 50, "exclusion_sha256": "bad1e132b3ce3605fb67a7095c69c66b75ab6f63146d924544436cb18a823f7e",
               "upstream_declared_proof_tuple_sha256": "9284200d9bf48e6f8f34c5be02c63c617daf1f576346d464d6c7213b01992f1c",
               "upstream_declared_source_map_sha256": "3da9d2fd9b0c3b73f1673ad53d48230713ef7d1a9fcc7b754481f734d8ee902b",
               "verdict": "GO_10_exact_static_trigger_pairings"},
    "adjudications": adjudications,
    "count_delta": {"exact_trigger_resolved": 10, "source_inferred_not_exactly_paired": -10,
                    "same_scope_state_handler": 10, "internal_state_trigger_candidate": -10, "runtime_credit": 0},
    "post_counts": {"custom_denominator": 659, "exact_trigger_resolved": 213, "source_inferred_not_exactly_paired": 173,
                    "unresolved_or_blocked": 273, "same_scope_state_handler": 194, "internal_state_trigger_candidate": 173,
                    "controlled_without_resolved_activator": 256, "generic_unresolved": 271,
                    "primitive_denominator": 477, "primitive_exact_trigger_resolved": 208, "primitive_unresolved": 269},
}

write_json(VISUAL07, visual07); write_json(OVERLAY6, overlay6); write_json(CLASSIFIER, classifier)
summary = {"schema_version": "1.0.0", "artifact": "final-904-visual-wave07-overlay6-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT,
    "outputs": {"visual_wave07": record(VISUAL07), "overlay_wave6": record(OVERLAY6), "overlay_classifier": record(CLASSIFIER)},
    "counts": {"visual_assigned": 8168, "visual_unresolved": 585, "material_assigned": 3948, "material_unresolved": 364,
               "overlay_exact_resolved": 213, "overlay_unresolved_or_blocked": 273,
               "primitive_exact_resolved": 208, "primitive_unresolved": 269},
    "credit_boundary": {"visual_final_id_promotions": 0, "overlay_static_pairings": 10,
                        "runtime_credit_delta": 0, "browser_credit_delta": 0, "completion_credit_delta": 0}}
write_json(SUMMARY, summary)
pointer = load(POINTER); pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"visual_wave904_07": record(VISUAL07), "overlay_trigger_wave6": record(OVERLAY6),
    "overlay_trigger_classification": record(CLASSIFIER), "visual_wave07_overlay6_generation_summary": record(SUMMARY)})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"; pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)
print(json.dumps({"status": "applied", "visual07": record(VISUAL07), "overlay6": record(OVERLAY6),
                  "classifier": record(CLASSIFIER), "summary": record(SUMMARY), "pointer": record(POINTER)}, indent=2))
