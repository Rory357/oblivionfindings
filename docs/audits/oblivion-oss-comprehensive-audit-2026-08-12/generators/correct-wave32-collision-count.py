#!/usr/bin/env python3
"""Correct Wave32 collision-corpus metadata without changing its adjudication."""

import hashlib
import json
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
SOURCE = AUDIT / "evidence" / "source"
ARTIFACT = SOURCE / "benchmark-target-specific-adjudication-904-wave32.json"
BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
INVENTORY = AUDIT / "inventory-904.json"
SUMMARY = SOURCE / "final-904-benchmark-wave32-generation-summary.json"
POINTER = SOURCE / "canonical-audit-inputs.json"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def load(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def write(path: Path, value) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def record(path: Path):
    return {"path": path.relative_to(AUDIT).as_posix(), "sha256": sha(path), "bytes": path.stat().st_size}


artifact = load(ARTIFACT)
collision = artifact["collision_disclosure"]
collision.pop("materialized_wave_artifacts_replayed", None)
collision["active_902_wave_artifacts_replayed"] = 24
collision["superseded_901_wave_artifacts_replayed"] = 2
collision["total_wave_artifacts_replayed"] = 26
write(ARTIFACT, artifact)

benchmark = load(BENCHMARK)
benchmark["inputs"]["target_specific_wave32"].update(record(ARTIFACT))
write(BENCHMARK, benchmark)

inventory = load(INVENTORY)
inventory["canonical_feature_register_metadata"]["source_artifacts"]["benchmark_mapping_sha256"] = sha(BENCHMARK)
write(INVENTORY, inventory)

summary = load(SUMMARY)
summary["inputs"]["wave32"] = record(ARTIFACT)
summary["outputs"]["benchmark"] = record(BENCHMARK)
summary["outputs"]["inventory"] = record(INVENTORY)
write(SUMMARY, summary)

pointer = load(POINTER)
pointer["artifacts"]["benchmark"] = record(BENCHMARK)
pointer["artifacts"]["inventory"] = record(INVENTORY)
pointer["artifacts"]["benchmark_wave32"] = record(ARTIFACT)
pointer["artifacts"]["benchmark_wave32_generation_summary"] = record(SUMMARY)
write(POINTER, pointer)

print(json.dumps({"wave32": record(ARTIFACT), "benchmark": record(BENCHMARK), "inventory": record(INVENTORY), "summary": record(SUMMARY), "active_inputs": record(POINTER)}, indent=2))
