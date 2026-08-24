#!/usr/bin/env python3
"""Apply independently reviewed custom-overlay trigger pairings, Wave 2."""

from __future__ import annotations

import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
ROOT = AUDIT.parent.parent.parent
SOURCE = AUDIT / "evidence" / "source"
GENERATED_AT = "2026-08-21T19:50:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
ARTIFACT = SOURCE / "overlay-trigger-adjudication-904-wave2.json"
POINTER = SOURCE / "canonical-audit-inputs.json"

INPUT_CLASSIFIER_SHA = "5db2c2a5a98949f0aa186bbf31e11627d41de5802d3e3831eaf5fe3126fc47a1"
ORDERED_SELECTION_SHA = "7e75dd94685373201a02684adf8bb2f0774434f75000f61a746e8f74f843a105"
INDEPENDENT_PROOF_SHA = "f9304db6c989498ecd3820e4b7b7494802c3805f27b9d04d3a4ece8a2404152e"
INDEPENDENT_SOURCE_MAP_SHA = "9c7c85e2ae7cc7d984d764b5bcdf502d1c62b109978e000dfa453dc64ff8109a"

ROWS = [
    {
        "file": "resources/js/pages/attendance/index.tsx", "line": 1496, "symbol": "HandoverWizard",
        "source_sha256": "a571a5439fc53e021a67f94e72a4d844cb551fb828138496051c6855d244bd60",
        "state": "handoverOpen", "setter": "setHandoverOpen", "state_line": 718,
        "handler": "openHandoverWizard", "handler_line": 888, "setter_line": 893,
        "trigger_line": 1294, "open_line": 1497,
    },
    {
        "file": "resources/js/pages/control-room/sla/index.tsx", "line": 1127, "symbol": "SlaFormDialog",
        "source_sha256": "b8e99ae7b411a893712c6d401724a7dbc7a7b0b6a76cb10ed2b7e956f54c4535",
        "state": "dialogOpen", "setter": "setDialogOpen", "state_line": 895,
        "handler": "handleCreate", "handler_line": 902, "setter_line": 905,
        "trigger_line": 1032, "open_line": 1128,
    },
    {
        "file": "resources/js/pages/emar/AuditLog.tsx", "line": 439, "symbol": "MedicationEventDrawer",
        "source_sha256": "f67990e546c7dd35e19e035a3ebeac8e8f0bd3665761fa1f05f62955c288ec52",
        "state": "selected", "setter": "setSelected", "state_line": 124,
        "handler": "openEvent", "handler_line": 129, "setter_line": 129,
        "trigger_line": 429, "open_line": 438,
    },
    {
        "file": "resources/js/pages/emar/MarCharts.tsx", "line": 446, "symbol": "RecordDoseWizard",
        "source_sha256": "257261fb6b00cf753afbcd22f092200e37b6fa232d6df09962596b7d06c846f3",
        "state": "recordTarget", "setter": "setRecordTarget", "state_line": 123,
        "handler": "onRecord", "handler_line": 219, "setter_line": 219,
        "trigger_line": 405, "open_line": 445,
        "child": {"file": "resources/js/components/emar/mar/mar-grid.tsx", "line": 158,
                  "token": "onRecord", "sha256": "a8913ac0e94df1de3a82197871e1f3bbf5acf7ef6a9794e91c53755fd08192d2"},
    },
    {
        "file": "resources/js/pages/emar/MarCharts.tsx", "line": 479, "symbol": "PrnWizard",
        "source_sha256": "257261fb6b00cf753afbcd22f092200e37b6fa232d6df09962596b7d06c846f3",
        "state": "prnMedId", "setter": "setPrnMedId", "state_line": 125,
        "handler": "onGivePrn", "handler_line": 220, "setter_line": 220,
        "trigger_line": 399, "open_line": 478,
        "child": {"file": "resources/js/components/emar/mar/prn-card.tsx", "line": 71,
                  "token": "onGive", "sha256": "66e31724729c22417416b49053ba0b8442b1f368a0e73602043be9b0d7ef7606"},
    },
    {
        "file": "resources/js/pages/fleet-assets/maintenance/work-orders/show.tsx", "line": 315, "symbol": "ConfirmDialog",
        "source_sha256": "15ffabd73537736da9273649135f095d0edf0f10a4ff6b16b28c0d525d37e8c9",
        "state": "showCancelConfirm", "setter": "setShowCancelConfirm", "state_line": 85,
        "handler": "handleUpdate", "handler_line": 87, "setter_line": 90,
        "trigger_line": 270, "open_line": 316,
    },
    {
        "file": "resources/js/pages/health-safety/drills/show.tsx", "line": 31, "symbol": "DrillCompleteDialog",
        "source_sha256": "5a4fe1d4385ac0d3f4b33dd6c5abff806e4f7ca864fb7bcb35fe99b8ef994198",
        "state": "completeOpen", "setter": "setCompleteOpen", "state_line": 11,
        "handler": "onLaunchComplete", "handler_line": 27, "setter_line": 27,
        "trigger_line": 27, "open_line": 30,
        "child": {"file": "resources/js/components/health-safety/drill-detail-dialog.tsx", "line": 188,
                  "token": "onLaunchComplete", "sha256": "5329735124d25192928402f22ce027d4836afdb71ad2e6c91445e7ee6157860a"},
    },
    {
        "file": "resources/js/pages/hr/feed/index.tsx", "line": 346, "symbol": "RecognitionWizard",
        "source_sha256": "1e894f612da854bee3ad4ccf24cd7fc093265b282e6362191575563207c297ad",
        "state": "recogOpen", "setter": "setRecogOpen", "state_line": 93,
        "handler": "openRecognition", "handler_line": 188, "setter_line": 190,
        "trigger_line": 241, "open_line": 347,
    },
    {
        "file": "resources/js/pages/hr/leave/balances.tsx", "line": 383, "symbol": "LeaveAdjustDialog",
        "source_sha256": "e8558cb2fe7f29f6c27d5004ae64ae6ddd1bf15be993f7f3d54e47a270db53d5",
        "state": "adjustOpen", "setter": "setAdjustOpen", "state_line": 139,
        "handler": "openAdjust", "handler_line": 144, "setter_line": 146,
        "trigger_line": 279, "open_line": 384,
    },
    {
        "file": "resources/js/pages/hr/onboarding/index.tsx", "line": 357, "symbol": "OnboardingWizardDialog",
        "source_sha256": "f4254d1d7bb446d83fb7de2b71220b726ccc9a7d97006cad18c631102f97c2e1",
        "state": "wizardOpen", "setter": "setWizardOpen", "state_line": 140,
        "handler": "openWizard", "handler_line": 260, "setter_line": 262,
        "trigger_line": 275, "open_line": 358,
        "child": {"file": "resources/js/components/hr/onboarding/onboarding-hero.tsx", "line": 210,
                  "token": "onStart", "sha256": "d71213020411ddd50800cc4834c6c09da3e269d920074070550de5f76b6e95ce"},
    },
]


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(lines).encode("utf-8")).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def record(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


selection = [f"{row['file']}:{row['line']}:{row['symbol']}" for row in ROWS]
require(sha_lines(selection) == ORDERED_SELECTION_SHA, "Selection digest drift")

if ARTIFACT.exists():
    classifier = load(CLASSIFIER)
    require(classifier["custom_usage_layer"]["exact_trigger_resolved"] == 173, "Post classifier count drift")
    pointer = load(POINTER)
    require(pointer["artifacts"]["overlay_trigger_wave2"] == record(ARTIFACT), "Pointer artifact drift")
    require(pointer["artifacts"]["overlay_trigger_classification"] == record(CLASSIFIER), "Pointer classifier drift")
    print(json.dumps({"status": "already_applied", "classifier": record(CLASSIFIER), "artifact": record(ARTIFACT)}, indent=2))
    raise SystemExit(0)

require(sha_file(CLASSIFIER) == INPUT_CLASSIFIER_SHA, "Overlay classifier input SHA drift")
classifier = load(CLASSIFIER)
require(classifier["audited_commit"] == AUDITED_COMMIT, "Audited commit drift")
require(len(classifier["custom_rows"]) == 659, "Custom-overlay denominator drift")

by_key: dict[tuple[str, int, str], dict[str, Any]] = {}
for row in classifier["custom_rows"]:
    key = (str(row["file"]), int(row["line"]), str(row["symbol"]))
    require(key not in by_key, f"Duplicate custom row: {key}")
    by_key[key] = row

adjudications: list[dict[str, Any]] = []
for proof in ROWS:
    key = (proof["file"], proof["line"], proof["symbol"])
    require(key in by_key, f"Missing classifier row: {key}")
    row = by_key[key]
    require(row["trigger_resolution"] in {"source_inferred_not_exactly_paired", "unresolved"}, f"Resolution drift: {key}")
    require(row["trigger_classification"] in {"internal_state_trigger_candidate", "controlled_without_resolved_activator"}, f"Class drift: {key}")
    require(row.get("state_identifier") == proof["state"], f"State drift: {key}")
    require(row.get("setter_identifier") == proof["setter"], f"Setter drift: {key}")

    path = ROOT / proof["file"]
    require(sha_file(path) == proof["source_sha256"], f"Source SHA drift: {proof['file']}")
    lines = path.read_text(encoding="utf-8").splitlines()
    anchors = [proof["state_line"], proof["handler_line"], proof["setter_line"], proof["trigger_line"], proof["line"], proof["open_line"]]
    require(all(1 <= number <= len(lines) for number in anchors), f"Anchor range drift: {key}")
    require(proof["state"] in lines[proof["state_line"] - 1], f"State anchor drift: {key}")
    require(proof["setter"] in lines[proof["state_line"] - 1], f"Setter declaration drift: {key}")
    require(proof["handler"] in lines[proof["handler_line"] - 1], f"Handler anchor drift: {key}")
    require(proof["setter"] in lines[proof["setter_line"] - 1], f"Setter call drift: {key}")
    require(proof["symbol"] in lines[proof["line"] - 1], f"Overlay anchor drift: {key}")

    child_evidence = None
    if proof.get("child"):
        child = proof["child"]
        child_path = ROOT / child["file"]
        require(sha_file(child_path) == child["sha256"], f"Child source SHA drift: {child['file']}")
        child_lines = child_path.read_text(encoding="utf-8").splitlines()
        require(child["token"] in child_lines[child["line"] - 1], f"Child trigger drift: {child['file']}")
        child_evidence = child

    evidence = {
        "kind": "named_state_open_handler",
        "file": proof["file"], "source_sha256": proof["source_sha256"],
        "state_identifier": proof["state"], "state_line": proof["state_line"],
        "setter": proof["setter"], "setter_line": proof["setter_line"],
        "handler": proof["handler"], "handler_line": proof["handler_line"],
        "trigger_line": proof["trigger_line"], "open_line": proof["open_line"],
        "child_trigger": child_evidence,
    }
    row["trigger_classification"] = "same_scope_state_handler"
    row["trigger_resolution"] = "resolved"
    row["trigger_evidence"] = [evidence]
    adjudications.append({"file": proof["file"], "line": proof["line"], "symbol": proof["symbol"],
                          "verdict": "GO_exact_static_trigger_pairing", "evidence": evidence})

class_counts = Counter(str(row["trigger_classification"]) for row in classifier["custom_rows"])
resolution_counts = Counter(str(row["trigger_resolution"]) for row in classifier["custom_rows"])
require(class_counts["same_scope_state_handler"] == 154, "Same-scope count drift")
require(class_counts["internal_state_trigger_candidate"] == 213, "Candidate count drift")
require(class_counts["controlled_without_resolved_activator"] == 256, "Controlled count drift")
require(resolution_counts["resolved"] == 173, "Resolved count drift")
require(resolution_counts["source_inferred_not_exactly_paired"] == 213, "Source-inferred count drift")
require(resolution_counts["unresolved"] == 271, "Unresolved count drift")

classifier["custom_usage_layer"].update({
    "exact_trigger_resolved": 173,
    "source_inferred_not_exactly_paired": 213,
    "unresolved_or_blocked": 273,
    "classification_counts": dict(sorted(class_counts.items())),
    "resolution_counts": dict(sorted(resolution_counts.items())),
})
write_json(CLASSIFIER, classifier)

artifact = {
    "schema_version": "1.0.0",
    "artifact": "overlay-trigger-adjudication-904-wave2",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_exact_static_pairings_runtime_unchanged",
    "audit_boundary": "Static source trigger pairing only. Runtime, focus, keyboard, viewport, visibility, permission, usability and completion credit remain zero.",
    "inputs": {"classifier_before": {"sha256": INPUT_CLASSIFIER_SHA, "rows": 659}},
    "method": {"selection_sha256": ORDERED_SELECTION_SHA, "independent_proof_tuple_sha256": INDEPENDENT_PROOF_SHA,
               "independent_source_map_sha256": INDEPENDENT_SOURCE_MAP_SHA,
               "verdict": "GO_10_exact_named_state_open_handler_chains"},
    "adjudications": adjudications,
    "count_delta": {"exact_trigger_resolved": 10, "source_inferred_not_exactly_paired": -5,
                    "same_scope_state_handler": 10, "internal_state_trigger_candidate": -5,
                    "controlled_without_resolved_activator": -5, "generic_unresolved": -5,
                    "unresolved_or_blocked": -5, "runtime_credit": 0},
    "post_counts": {"custom_denominator": 659, "exact_trigger_resolved": 173,
                    "source_inferred_not_exactly_paired": 213, "unresolved_or_blocked": 273,
                    "same_scope_state_handler": 154, "internal_state_trigger_candidate": 213,
                    "controlled_without_resolved_activator": 256, "generic_unresolved": 271,
                    "primitive_denominator": 477, "primitive_exact_trigger_resolved": 208,
                    "primitive_unresolved": 269},
}
write_json(ARTIFACT, artifact)

pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"]["overlay_trigger_classification"] = record(CLASSIFIER)
pointer["artifacts"]["overlay_trigger_wave2"] = record(ARTIFACT)
write_json(POINTER, pointer)

print(json.dumps({"status": "applied", "classifier": record(CLASSIFIER), "artifact": record(ARTIFACT), "pointer": record(POINTER)}, indent=2))
