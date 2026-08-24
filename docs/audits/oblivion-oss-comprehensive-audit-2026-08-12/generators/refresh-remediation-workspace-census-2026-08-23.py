from __future__ import annotations

import json
import re
import subprocess
from collections import Counter
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
FINDINGS = AUDIT / "findings.json"
OUTPUT = (
    AUDIT
    / "evidence"
    / "source"
    / "remediation-workspace-census-2026-08-23.json"
)
REPO = AUDIT.parents[2]
GENERATED_AT = "2026-08-23T16:55:20+12:00"

SPECIAL_BRANCHES = {
    "codex/arch-p0-b-alerts-events-current-main",
    "codex/fin-payment-match-01-f869",
    "codex/fleet-booking-site-privacy-01-current-main",
    "codex/gov-resolution-quorum-01-current-main",
    "codex/hs-procedure-approval-assurance-01-current-main",
    "codex/med-rbac-01-current-main-reconstruction",
    "codex/ops-dashboard-activity-site-privacy-01-current-main",
}

FINDING_OVERRIDES = {
    "codex/arch-p0-b-alerts-events-current-main": "ARCH-P0-B",
    "codex/med-rbac-01-current-main-reconstruction": "MED-READER-SITE-CONCEALMENT-01",
}


def run(*args: str, cwd: Path = REPO, check: bool = True) -> str:
    completed = subprocess.run(
        ["git", *args],
        cwd=cwd,
        check=check,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    return completed.stdout.strip()


def slug(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "-", value.lower()).strip("-")


def parse_worktrees() -> list[dict[str, str]]:
    records: list[dict[str, str]] = []
    record: dict[str, str] = {}
    for line in run("worktree", "list", "--porcelain").splitlines() + [""]:
        if not line:
            if record.get("worktree"):
                records.append(record)
            record = {}
            continue
        key, _, value = line.partition(" ")
        if key in {"worktree", "HEAD", "branch"}:
            record[key.lower()] = value
    return records


def finding_for_branch(branch: str, finding_ids: list[str]) -> str | None:
    if branch in FINDING_OVERRIDES:
        return FINDING_OVERRIDES[branch]
    branch_slug = branch.removeprefix("codex/")
    matches = [
        finding_id
        for finding_id in finding_ids
        if branch_slug == slug(finding_id)
        or branch_slug.startswith(f"{slug(finding_id)}-")
    ]
    if not matches:
        return None
    return max(matches, key=lambda finding_id: len(slug(finding_id)))


finding_document = json.loads(FINDINGS.read_text(encoding="utf-8"))
findings = finding_document.get("findings", finding_document)
finding_ids = [str(row["id"]) for row in findings]
finding_priority = {str(row["id"]): str(row["priority"]) for row in findings}
canonical_status_counts = Counter(
    str(row.get("remediation", {}).get("status", "open")) for row in findings
)

current_main = run("rev-parse", "origin/main")
rows: list[dict[str, object]] = []
for worktree in parse_worktrees():
    branch_ref = worktree.get("branch", "")
    if not branch_ref.startswith("refs/heads/codex/"):
        continue
    branch = branch_ref.removeprefix("refs/heads/")
    if "current-main" not in branch and branch not in SPECIAL_BRANCHES:
        continue
    finding_id = finding_for_branch(branch, finding_ids)
    if finding_id is None:
        continue

    path = Path(worktree["worktree"])
    head = worktree["head"]
    status_lines = run("status", "--porcelain=v1", cwd=path).splitlines()
    ahead = int(run("rev-list", "--count", f"{current_main}..{head}"))
    behind = int(run("rev-list", "--count", f"{head}..{current_main}"))
    head_in_main = (
        subprocess.run(
            ["git", "merge-base", "--is-ancestor", head, current_main],
            cwd=REPO,
            capture_output=True,
        ).returncode
        == 0
    )
    if head_in_main and not status_lines:
        state = "clean_current_main_reconciliation"
    elif ahead > 0 or status_lines:
        state = "local_candidate_not_merged"
    else:
        state = "workspace_present_no_local_delta"

    rows.append(
        {
            "finding_id": finding_id,
            "priority": finding_priority[finding_id],
            "branch": branch,
            "worktree": path.as_posix(),
            "head": head,
            "current_main": current_main,
            "ahead_of_current_main": ahead,
            "behind_current_main": behind,
            "dirty_path_count": len(status_lines),
            "state": state,
        }
    )

rows.sort(key=lambda row: (str(row["finding_id"]), str(row["branch"])))
finding_states: dict[str, str] = {}
for row in rows:
    current = finding_states.get(str(row["finding_id"]))
    if row["state"] == "local_candidate_not_merged" or current is None:
        finding_states[str(row["finding_id"])] = str(row["state"])

state_counts = Counter(finding_states.values())
document = {
    "schema_version": "1.0",
    "generated_at": GENERATED_AT,
    "scope": "Local current-main remediation worktrees for the canonical 100 findings; filesystem and Git state only.",
    "current_main": current_main,
    "summary": {
        "canonical_findings": len(findings),
        "findings_with_current_workspace": len(finding_states),
        "findings_with_local_candidate_delta": state_counts[
            "local_candidate_not_merged"
        ],
        "clean_current_main_reconciliations": state_counts[
            "clean_current_main_reconciliation"
        ],
        "workspace_present_no_local_delta": state_counts[
            "workspace_present_no_local_delta"
        ],
        "matched_worktrees": len(rows),
        "dirty_or_ahead_worktrees": sum(
            row["state"] == "local_candidate_not_merged" for row in rows
        ),
        "canonical_open": canonical_status_counts["open"],
        "canonical_in_progress": canonical_status_counts["in_progress"],
        "canonical_fixed_pending_verification": canonical_status_counts[
            "fixed_pending_verification"
        ],
        "canonical_verified": canonical_status_counts["verified"],
        "newly_merged_by_this_workspace_census": 0,
        "newly_runtime_verified_by_this_workspace_census": 0,
        "background_audit_tasks_active": 0,
    },
    "claim_boundary": (
        "A local dirty or ahead worktree proves candidate activity only. It does not prove "
        "source readiness, test success, publication, merge, deployment, or runtime verification. "
        "Canonical finding statuses remain governed by findings.json."
    ),
    "worktrees": rows,
}

OUTPUT.write_text(
    json.dumps(document, ensure_ascii=False, indent=2) + "\n",
    encoding="utf-8",
    newline="\n",
)
print(json.dumps(document["summary"], separators=(",", ":")))
