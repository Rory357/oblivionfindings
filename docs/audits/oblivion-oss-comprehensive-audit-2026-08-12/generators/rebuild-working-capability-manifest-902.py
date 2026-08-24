#!/usr/bin/env python3
"""Add the source-proved machine signal-to-alert target to the 901 manifest."""

from __future__ import annotations

import hashlib
import json
from collections import Counter, defaultdict
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
BASE = SOURCE / "working-capability-manifest-901.json"
DECISION = SOURCE / "capability-denominator-902-adjudication.json"
OUTPUT = SOURCE / "working-capability-manifest-902.json"
SUMMARY = SOURCE / "working-capability-manifest-902-generation-summary.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write(path: Path, value: dict) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(values: list[str]) -> str:
    return hashlib.sha256("\n".join(sorted(values)).encode("utf-8")).hexdigest()


base = load(BASE)
decision = load(DECISION)
assert base["audited_commit"] == decision["audited_commit"] == COMMIT
assert len(base["targets"]) == 901

targets = [dict(row) for row in base["targets"]]
addition = dict(decision["accepted_addition"])
assert addition["working_key"] not in {row["working_key"] for row in targets}
for path in addition["backend_anchors"]:
    assert (SOURCE.parents[4] / path).is_file(), path
targets.append(addition)
targets.sort(key=lambda row: row["working_key"])

assert len(targets) == 902
assert len({row["working_key"] for row in targets}) == 902
assert all(row["working_key"] == row["id"] for row in targets)

class_counts = Counter(row["class"] for row in targets)
assert class_counts == Counter({"H": 788, "D": 111, "M": 3})
status_counts = Counter(row["id_status"] for row in targets)
assert status_counts == Counter({
    "exact": 881,
    "source_stable_existing_feature_id": 4,
    "source_stable_reclassified": 1,
    "audit_assigned_stable_name": 16,
})

module_counts: dict[str, dict[str, int]] = defaultdict(lambda: {"total": 0, "H": 0, "D": 0, "M": 0})
for row in targets:
    module = row["canonical_module"]
    module_counts[module]["total"] += 1
    module_counts[module][row["class"]] += 1
module_counts = dict(sorted(module_counts.items()))
assert module_counts["CONTROL_ROOM"] == {"total": 38, "H": 34, "D": 3, "M": 1}
assert sum(row["total"] for row in module_counts.values()) == 902

counts = dict(base["counts"])
counts.update({
    "total": 902,
    "H": 788,
    "D": 111,
    "M": 3,
    "audit_assigned_stable_names": 16,
    "targets_with_backend_anchors": 729,
    "backend_relations": 828,
    "unique_backend_anchors": 469,
    "source_family_relations": 993,
})

canonical_lines = [f"{row['working_key']}|{row['class']}|{row['canonical_module']}|{row['id_status']}" for row in targets]
stable_ids = [row["working_key"] for row in targets]
source_stable_ids = [row["working_key"] for row in targets if row["id_status"].startswith("source_stable")]
audit_ids = [row["working_key"] for row in targets if row["id_status"] == "audit_assigned_stable_name"]

manifest = dict(base)
manifest.update({
    "schema_version": "1.3",
    "artifact": "working-capability-manifest-902",
    "status": "working_static_manifest_902_identity_reconciled_not_completion_claim",
    "generated_at": decision["generated_at"],
    "counts": counts,
    "module_counts": module_counts,
    "checksums": {
        "working_targets_sha256": sha_lines(canonical_lines),
        "canonical_stable_target_ids_sha256": sha_lines(stable_ids),
        "source_stable_ids_sha256": sha_lines(source_stable_ids),
        "audit_assigned_stable_names_sha256": sha_lines(audit_ids),
        "method": "lexicographic sort; LF join without terminal LF; UTF-8 SHA-256",
    },
    "targets": targets,
    "supersedes": list(base.get("supersedes", [])) + [{"file": BASE.name, "sha256": sha_file(BASE)}],
    "denominator_adjudication": {
        "file": DECISION.name,
        "sha256": sha_file(DECISION),
        "accepted_delta": decision["accepted_counts"]["delta"],
        "runtime_claim": False,
    },
})
manifest["transformations"] = list(base.get("transformations", [])) + [{
    "stage": "final_signal_pipeline_machine_identity_adjudication",
    "accepted_additions": 1,
    "result": 902,
    "count_fit_used": False,
    "runtime_claim": False,
}]
manifest["adjudication_inputs"] = sorted(set(base.get("adjudication_inputs", [])) | {DECISION.name})
write(OUTPUT, manifest)

summary = {
    "schema_version": "1.0",
    "artifact": "working-capability-manifest-902-generation-summary",
    "status": "generated_and_validated_static_manifest_not_completion_claim",
    "generated_at": decision["generated_at"],
    "audited_commit": COMMIT,
    "inputs": [
        {"file": BASE.name, "sha256": sha_file(BASE)},
        {"file": DECISION.name, "sha256": sha_file(DECISION)},
    ],
    "output": {"file": OUTPUT.name, "sha256": sha_file(OUTPUT)},
    "counts": counts,
    "module_counts": module_counts,
    "id_status_counts": dict(sorted(status_counts.items())),
    "checksums": manifest["checksums"],
    "validation": {
        "unique_working_keys": True,
        "working_key_equals_stable_id": True,
        "class_sum_matches_902": sum(class_counts.values()) == 902,
        "module_sum_matches_902": sum(row["total"] for row in module_counts.values()) == 902,
        "new_machine_target_has_no_route_or_page": not addition["route_ids"] and not addition["page_ids"],
        "new_machine_target_retains_cr_alert_discovery_lineage": addition["source_family_ids"] == ["CR-ALERT"],
        "all_backend_anchors_exist": True,
    },
    "completion_gate": {
        "complete": False,
        "reason": "The missing machine identity is reconciled; benchmark, browser, task, visual, test and Pass-8 completion gates remain blocked.",
    },
}
write(SUMMARY, summary)
print(json.dumps({"output": OUTPUT.name, "sha256": sha_file(OUTPUT), "counts": counts, "module_counts": module_counts}, indent=2))
