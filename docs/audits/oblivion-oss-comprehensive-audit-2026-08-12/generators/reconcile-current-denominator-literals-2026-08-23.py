#!/usr/bin/env python3
"""Reconcile retired 902/788/50 denominators in current audit artifacts.

This transform updates current canonical wording only. Historical evidence and
superseded generators remain byte-preserved, and no benchmark, browser,
runtime, usability, finding, remediation, or release credit is awarded.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-23T16:18:00+12:00"

NATIVE_REGISTER = AUDIT / "12-native-build-and-do-not-copy-register.md"
FINDINGS = AUDIT / "findings.json"
INVENTORIES = (AUDIT / "inventory-904.json", AUDIT / "inventory.json")
OUTPUT = SOURCE / "current-denominator-literal-reconciliation-2026-08-23.json"


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def record(path: Path) -> dict[str, Any]:
    return {
        "path": path.relative_to(AUDIT).as_posix(),
        "sha256": sha256(path),
        "bytes": path.stat().st_size,
    }


def replace_exact(path: Path, old: str, new: str, expected: int) -> int:
    text = path.read_text(encoding="utf-8")
    old_count = text.count(old)
    new_count = text.count(new)
    if old_count == 0:
        require(new_count == expected, f"Unexpected idempotent count in {path.name}: {new_count}")
        return 0
    require(old_count == expected, f"Unexpected source count in {path.name}: {old_count}")
    path.write_text(text.replace(old, new), encoding="utf-8", newline="\n")
    require(path.read_text(encoding="utf-8").count(new) == expected, f"Replacement failed in {path.name}")
    return old_count


head = subprocess.run(
    ["git", "rev-parse", "HEAD"],
    cwd=AUDIT,
    check=True,
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    text=True,
).stdout.strip()
require(head == AUDITED_COMMIT, f"Audited checkout drift: {head}")

native_old = (
    "Against the canonical 902-target register, **362 targets have a verified benchmark mapping, "
    "89 have a target-specific documented No Credible Match decision, and 451 remain completion-unproved**: "
    "451/902 (50.00%) currently have evidence-preserving dispositions."
)
native_new = (
    "Against the canonical 904-target register, **411 targets have a verified benchmark mapping, "
    "89 have a target-specific documented No Credible Match decision, and 404 remain completion-unproved**: "
    "500/904 (55.31%) currently have evidence-preserving dispositions."
)
native_footer_old = (
    "The current completion gate is 451/902 (50.00%) mapped or documented No Credible Match "
    "and 451/902 unproved."
)
native_footer_new = (
    "The current completion gate is 500/904 (55.31%) mapped or documented No Credible Match "
    "and 404/904 unproved."
)

changes = {
    "native_register_summary": replace_exact(NATIVE_REGISTER, native_old, native_new, 1),
    "native_register_footer": replace_exact(NATIVE_REGISTER, native_footer_old, native_footer_new, 1),
    "finding_task_denominators": replace_exact(
        FINDINGS,
        "0/788",
        "0/790",
        72,
    ),
    "inventory_p6_denominators": 0,
}

for inventory in INVENTORIES:
    changes["inventory_p6_denominators"] += replace_exact(
        inventory,
        "source-reviewed 50/50",
        "source-reviewed 58/58",
        741,
    )

require(INVENTORIES[0].read_bytes() == INVENTORIES[1].read_bytes(), "Canonical inventory mirrors differ")
json.loads(FINDINGS.read_text(encoding="utf-8"))
for inventory in INVENTORIES:
    json.loads(inventory.read_text(encoding="utf-8"))

result = {
    "schema_version": "1.0",
    "artifact": "current-denominator-literal-reconciliation-2026-08-23",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "status": "current_denominator_wording_reconciled_completion_unchanged",
    "changes": changes,
    "current_denominators": {
        "capabilities": 904,
        "human_tasks": 790,
        "benchmark_verified": 411,
        "documented_no_credible_match": 89,
        "benchmark_or_ncm": 500,
        "benchmark_unproved": 404,
        "official_p6_finding_propositions_reviewed": 58,
        "official_p6_finding_propositions_denominator": 58,
    },
    "outputs": [record(NATIVE_REGISTER), record(FINDINGS), *(record(path) for path in INVENTORIES)],
    "credit_boundary": {
        "benchmark_credit_delta": 0,
        "finding_credit_delta": 0,
        "browser_credit_delta": 0,
        "runtime_credit_delta": 0,
        "usability_credit_delta": 0,
        "remediation_credit_delta": 0,
    },
}
OUTPUT.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")

print(json.dumps({"output": record(OUTPUT), **result}, ensure_ascii=False, indent=2))
