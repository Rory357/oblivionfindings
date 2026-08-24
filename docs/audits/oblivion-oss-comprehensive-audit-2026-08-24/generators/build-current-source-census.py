#!/usr/bin/env python3
"""Build the reproducible current-source census for the 2026-08-24 audit.

This generator is deliberately static. It reads committed Git objects at the
application source pin and writes only inside this audit directory. It does not
boot Laravel, execute tests, access a database, or award runtime/browser credit.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any, Iterable


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
REPO = AUDIT.parents[2]

AUDIT_DATE = "2026-08-24"
GENERATED_AT = "2026-08-24T15:40:41+12:00"
STALE_AUDIT_BASE = "081ef198f9f992f224e8c0c9fba33df33dde40be"
APPLICATION_BASE = "a0493442b9e392d324055c35bf25b69421dc2d35"
AUDIT_OUTPUT_PARENT = "cf578b357e3662e1f6902478a5623f1f54414fb2"
PROMPT_FILENAME = "oblivion-open-source-benchmark-and-8-pass-audit-prompt.md"
PROMPT = Path.home() / "Downloads" / PROMPT_FILENAME


def git(*args: str, text: bool = True) -> str | bytes:
    return subprocess.check_output(
        ["git", *args], cwd=REPO, text=text, stderr=subprocess.STDOUT
    )


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def git_tree_rows(commit: str) -> list[dict[str, Any]]:
    raw = git("ls-tree", "-r", "-z", "--long", commit, text=False)
    assert isinstance(raw, bytes)
    rows: list[dict[str, Any]] = []
    for item in raw.split(b"\0"):
        if not item:
            continue
        header, raw_path = item.split(b"\t", 1)
        mode, object_type, object_id, raw_size = header.decode("ascii").split(" ", 3)
        path = raw_path.decode("utf-8")
        rows.append(
            {
                "path": path,
                "mode": mode,
                "object_type": object_type,
                "object_id": object_id,
                "bytes": None if raw_size == "-" else int(raw_size),
                "categories": categories(path),
            }
        )
    return sorted(rows, key=lambda row: row["path"])


def categories(path: str) -> list[str]:
    lower = path.lower()
    name = lower.rsplit("/", 1)[-1]
    result: list[str] = []

    def add(label: str, condition: bool) -> None:
        if condition:
            result.append(label)

    add("route_file", lower.startswith("routes/") and lower.endswith(".php"))
    add("inertia_page_tree_file", lower.startswith("resources/js/pages/"))
    add("inertia_page_tree_ts_tsx", lower.startswith("resources/js/pages/") and lower.endswith((".ts", ".tsx")))
    add("tsx_page_tree", lower.startswith("resources/js/pages/") and lower.endswith(".tsx"))
    add("component_tree_file", lower.startswith("resources/js/components/"))
    add("component_tree_ts_tsx", lower.startswith("resources/js/components/") and lower.endswith((".ts", ".tsx")))
    add("controller_path_php", lower.startswith("app/") and "/controllers/" in lower and lower.endswith(".php"))
    add("controller_suffix_php", lower.startswith("app/") and name.endswith("controller.php"))
    add("model_path_php", lower.startswith("app/") and "/models/" in lower and lower.endswith(".php"))
    add("policy_path_php", lower.startswith("app/") and "/policies/" in lower and lower.endswith(".php"))
    add("service_path_php", lower.startswith("app/") and "/services/" in lower and lower.endswith(".php"))
    add("job_path_php", lower.startswith("app/") and "/jobs/" in lower and lower.endswith(".php"))
    add("event_path_php", lower.startswith("app/") and "/events/" in lower and lower.endswith(".php"))
    add("listener_path_php", lower.startswith("app/") and "/listeners/" in lower and lower.endswith(".php"))
    add("migration", lower.startswith("database/migrations/") and lower.endswith(".php"))
    add("seeder", lower.startswith("database/seeders/") and lower.endswith(".php"))
    add("php", lower.endswith(".php"))
    add("tsx", lower.endswith(".tsx"))
    add("test_tree", lower.startswith("tests/"))
    add(
        "resource_test_spec",
        lower.startswith("resources/js/")
        and (
            "/__tests__/" in lower
            or name.endswith((".test.ts", ".test.tsx", ".spec.ts", ".spec.tsx"))
        ),
    )
    add(
        "production_tsx",
        lower.endswith(".tsx")
        and not lower.startswith("tests/")
        and "/__tests__/" not in lower
        and not name.endswith((".test.tsx", ".spec.tsx")),
    )
    add("audit_output", lower.startswith("docs/audits/"))
    add("documentation", lower.startswith("docs/") or name.endswith(".md"))
    add(
        "config_or_manifest",
        lower.startswith("config/")
        or name
        in {
            "composer.json",
            "composer.lock",
            "package.json",
            "package-lock.json",
            "vite.config.ts",
            "phpunit.xml",
        },
    )
    return result


def count_categories(rows: Iterable[dict[str, Any]]) -> dict[str, int]:
    counter: Counter[str] = Counter()
    for row in rows:
        counter.update(row["categories"])
    return dict(sorted(counter.items()))


def diff_rows(old: str, new: str) -> list[dict[str, Any]]:
    raw = git("diff", "--name-status", "--no-renames", "-z", f"{old}..{new}", text=False)
    assert isinstance(raw, bytes)
    fields = [field.decode("utf-8") for field in raw.split(b"\0") if field]
    require(len(fields) % 2 == 0, "Unexpected Git name-status stream")
    rows: list[dict[str, Any]] = []
    for offset in range(0, len(fields), 2):
        status, path = fields[offset : offset + 2]
        rows.append(
            {
                "status": status,
                "path": path,
                "categories": categories(path),
                "audit_output_path": path.lower().startswith("docs/audits/"),
            }
        )
    return sorted(rows, key=lambda row: (row["path"], row["status"]))


def git_status_paths() -> list[dict[str, str]]:
    raw = git("status", "--porcelain=v1", "-z", text=False)
    assert isinstance(raw, bytes)
    rows = []
    for item in raw.split(b"\0"):
        if not item:
            continue
        decoded = item.decode("utf-8")
        rows.append({"status": decoded[:2], "path": decoded[3:]})
    return rows


def pinned_blob_record(path: str) -> dict[str, Any]:
    payload = git("show", f"{APPLICATION_BASE}:{path}", text=False)
    assert isinstance(payload, bytes)
    return {
        "path": path,
        "git_object_id": str(git("rev-parse", f"{APPLICATION_BASE}:{path}")).strip(),
        "bytes": len(payload),
        "sha256": sha256_bytes(payload),
    }


require(AUDIT.name == f"oblivion-oss-comprehensive-audit-{AUDIT_DATE}", "Audit directory/date mismatch")
require(PROMPT.is_file(), f"Prompt not found: {PROMPT}")
require(str(git("rev-parse", APPLICATION_BASE)).strip() == APPLICATION_BASE, "Application base is missing")
require(str(git("rev-parse", AUDIT_OUTPUT_PARENT)).strip() == AUDIT_OUTPUT_PARENT, "Audit output parent is missing")
require(
    subprocess.run(
        ["git", "merge-base", "--is-ancestor", AUDIT_OUTPUT_PARENT, "HEAD"],
        cwd=REPO,
        check=False,
    ).returncode
    == 0,
    "HEAD no longer descends from the audited output parent",
)

product_drift_after_base = str(
    git(
        "diff",
        "--name-only",
        f"{APPLICATION_BASE}..HEAD",
        "--",
        ".",
        ":(exclude)docs/audits/**",
    )
).splitlines()
require(not product_drift_after_base, "Product paths changed after the application source pin")

status_rows = git_status_paths()
outside_output_dirt = [
    row for row in status_rows if not row["path"].replace("\\", "/").startswith(AUDIT.relative_to(REPO).as_posix())
]
require(not outside_output_dirt, f"Unexpected worktree dirt outside current audit output: {outside_output_dirt}")

tree_rows = git_tree_rows(APPLICATION_BASE)
drift_rows = diff_rows(STALE_AUDIT_BASE, APPLICATION_BASE)
non_audit_drift = [row for row in drift_rows if not row["audit_output_path"]]
change_kinds = Counter(row["status"][0] for row in drift_rows)
change_kind_names = {"A": "added", "M": "modified", "D": "deleted", "T": "type_changed", "U": "unmerged"}
generated_at = GENERATED_AT
head_at_generation = str(git("rev-parse", "HEAD")).strip()
origin_main = str(git("rev-parse", "origin/main")).strip()
stale_current_merge_base = str(git("merge-base", STALE_AUDIT_BASE, APPLICATION_BASE)).strip()
stale_only, current_only = [
    int(value)
    for value in str(git("rev-list", "--left-right", "--count", f"{STALE_AUDIT_BASE}...{APPLICATION_BASE}")).split()
]
stale_is_ancestor = (
    subprocess.run(
        ["git", "merge-base", "--is-ancestor", STALE_AUDIT_BASE, APPLICATION_BASE],
        cwd=REPO,
        check=False,
    ).returncode
    == 0
)

denominators = {
    "tracked_files": len(tree_rows),
    **count_categories(tree_rows),
    "changes_since_stale_audit_base": len(drift_rows),
    "non_audit_changes_since_stale_audit_base": len(non_audit_drift),
    "audit_output_path_changes_since_stale_audit_base": len(drift_rows) - len(non_audit_drift),
}

inventory = {
    "schema_version": "0.1-current-source-census",
    "audit_status": "IN_PROGRESS_NOT_COMPREHENSIVE_OR_COMPLETE",
    "generated_at": generated_at,
    "audited_repository": str(git("remote", "get-url", "origin")).strip(),
    "branch": str(git("branch", "--show-current")).strip(),
    "application_source_commit": APPLICATION_BASE,
    "application_source_tree": str(git("rev-parse", f"{APPLICATION_BASE}^{{tree}}" )).strip(),
    "audit_output_parent_commit": AUDIT_OUTPUT_PARENT,
    "head_at_generation": head_at_generation,
    "origin_main_observed": origin_main,
    "superseded_audit_source_commit": STALE_AUDIT_BASE,
    "superseded_to_current_source_relation": {
        "merge_base": stale_current_merge_base,
        "superseded_commit_is_ancestor": stale_is_ancestor,
        "superseded_side_commits": stale_only,
        "current_side_commits": current_only,
        "comparison_kind": "direct committed-tree diff; no ancestry assumed",
    },
    "architecture_rule": "Single tenant, multiple Sites; assess Site, role, ownership, direct-object concealment, and privacy boundaries, not tenant isolation.",
    "credit_boundary": {
        "static_source_census": True,
        "semantic_feature_classification_complete": False,
        "runtime_routes_executed": False,
        "tests_executed": False,
        "browser_credit_from_this_generator": False,
        "benchmark_credit_from_this_generator": False,
    },
    "denominators": denominators,
    "classification_note": "Categories are deterministic path classifications, not capability, reachability, security, usability, runtime, or benchmark proof.",
    "files": tree_rows,
}

census = {
    "schema_version": "1.0",
    "audit_status": inventory["audit_status"],
    "generated_at": generated_at,
    "application_source_commit": APPLICATION_BASE,
    "superseded_audit_source_commit": STALE_AUDIT_BASE,
    "source_relation": inventory["superseded_to_current_source_relation"],
    "counts": {
        **denominators,
        "change_kinds": {
            change_kind_names.get(kind, kind): count for kind, count in sorted(change_kinds.items())
        },
        "changed_category_memberships": count_categories(drift_rows),
    },
    "changed_paths": drift_rows,
    "conclusion": "The 2026-08-12 bundle cannot establish current-main completion because its non-ancestor source tree differs by this direct changed-path set. Fresh semantic, benchmark, workflow, visual, test, and Pass-8 evidence is required.",
}

manifest = {
    "schema_version": "1.0",
    "audit_date": AUDIT_DATE,
    "status": "IN_PROGRESS_NOT_COMPREHENSIVE_OR_COMPLETE",
    "generated_at": generated_at,
    "governing_prompt": {
        "provided_filename": PROMPT_FILENAME,
        "bytes": PROMPT.stat().st_size,
        "sha256": sha256_bytes(PROMPT.read_bytes()),
    },
    "source_pins": {
        "application_source_commit": APPLICATION_BASE,
        "application_source_tree": inventory["application_source_tree"],
        "audit_output_parent_commit": AUDIT_OUTPUT_PARENT,
        "head_at_generation": head_at_generation,
        "origin_main_observed": origin_main,
        "superseded_audit_source_commit": STALE_AUDIT_BASE,
        "superseded_to_current_source_relation": inventory["superseded_to_current_source_relation"],
    },
    "source_governance_inputs": [
        pinned_blob_record("AGENTS.md"),
        pinned_blob_record("docs/architecture/single-tenant-application.md"),
    ],
    "writer_boundary": {
        "orchestrator_only_writes": True,
        "generator": GENERATOR.relative_to(AUDIT).as_posix(),
        "permitted_output_root": AUDIT.relative_to(REPO).as_posix(),
        "unexpected_dirty_paths_outside_output": outside_output_dirt,
    },
    "execution_boundaries": {
        "laravel_booted": False,
        "database_accessed": False,
        "tests_executed": False,
        "build_executed": False,
        "browser_executed_by_generator": False,
        "external_system_mutation": False,
    },
    "current_stage": "current-source denominator and stale-source reconciliation",
    "completion_claim": False,
}

write_json(AUDIT / "inventory.json", inventory)
write_json(SOURCE / "current-source-census.json", census)
write_json(SOURCE / "audit-run-manifest.json", manifest)

print(
    json.dumps(
        {
            "status": manifest["status"],
            "application_source_commit": APPLICATION_BASE,
            "tracked_files": denominators["tracked_files"],
            "changes_since_stale_audit_base": len(drift_rows),
            "non_audit_changes_since_stale_audit_base": len(non_audit_drift),
            "outputs": [
                "inventory.json",
                "evidence/source/current-source-census.json",
                "evidence/source/audit-run-manifest.json",
            ],
        },
        indent=2,
    )
)
