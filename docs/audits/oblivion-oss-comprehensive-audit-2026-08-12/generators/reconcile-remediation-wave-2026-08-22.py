#!/usr/bin/env python3
"""Deterministically record the fresh exactly-ten-session remediation wave.

The coordinator owns this artifact. Source, publication, main ancestry, and
runtime are independent states; this generator never edits findings or awards
completion credit.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
ROOT = AUDIT.parent.parent.parent
SOURCE = AUDIT / "evidence" / "source"
OUTPUT = SOURCE / "orchestration-status-2026-08-14.json"
COMPLETION = SOURCE / "completion-gate-report.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
AUDITED = "081ef198f9f992f224e8c0c9fba33df33dde40be"
WAVE11 = SOURCE / "final-904-visual-wave11-overlay10-generation-summary.json"
WAVE11_GENERATOR_SHA256 = "1c39d265e7001153963632b065f2653b5477f0bd8ecf7a892588a3f7242a49cf"


def require(ok: bool, message: str) -> None:
    if not ok:
        raise RuntimeError(message)


def git_ref(name: str) -> str:
    return subprocess.run(
        ["git", "rev-parse", name], cwd=ROOT, check=True,
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    ).stdout.strip()


def record(path: Path) -> dict[str, object]:
    return {
        "path": path.relative_to(AUDIT).as_posix(),
        "sha256": hashlib.sha256(path.read_bytes()).hexdigest(),
        "bytes": path.stat().st_size,
    }


def row(
    session: int,
    thread: str,
    finding: str,
    worktree: str,
    branch: str | None,
    head: str,
    tree: str,
    paths: int,
    source_ready: bool,
    verdict: str,
    next_gate: str,
) -> dict[str, object]:
    return {
        "session": session,
        "thread_id": thread,
        "finding_id": finding,
        "worktree": worktree,
        "branch": branch,
        "candidate_head": head,
        "candidate_tree": tree,
        "current_main_base": MAIN,
        "current_main_is_ancestor": True,
        "candidate_is_ancestor_of_current_main": False,
        "tracked_worktree_clean": True,
        "cumulative_path_count": paths,
        "source_ready": source_ready,
        "source_verdict": verdict,
        "branch_published": False,
        "merged_to_main": False,
        "runtime_verified": False,
        "blocker_or_next_gate": next_gate,
        "publication_boundary": "local-only; no push, merge, or canonical finding credit",
        "dashboard_edit_allowed": False,
    }


require(git_ref("HEAD") == AUDITED, "Audited checkout drift")
require(git_ref("refs/heads/main") == MAIN, "Local main drift")
require(git_ref("refs/remotes/origin/main") == MAIN, "Cached origin/main drift")
require(WAVE11.exists(), "Accepted Visual Wave11/Overlay10 summary missing")
require(
    hashlib.sha256((GENERATOR.parent / "apply-visual-wave904-11-overlay10.py").read_bytes()).hexdigest()
    == WAVE11_GENERATOR_SHA256,
    "Accepted Visual Wave11/Overlay10 generator drift",
)

tasks = [
    row(1, "01a022b4-7cec-7561-804c-757806f0d6d1", "ARCH-P0-B-ALERTS-EVENTS", "<local-user>/.codex/worktrees/7359/oblivionfindings", "codex/arch-p0-b-alerts-events-current-main", "b06f80a7af57aaa5407b4539973468c736e08323", "4a331f687fd41945ccd6974473dc5219fdd65062", 3, True, "SOURCE_GO", "Written exclusive PHP/MySQL grant, then scoped syntax/Pint/Pest and exact cleanup."),
    row(2, "01a022b4-8a17-7880-a604-d0e3958ebf69", "FLEET-BOOKING-SITE-PRIVACY-01", "<local-user>/.codex/worktrees/2645/oblivionfindings", "codex/fleet-booking-site-privacy-01-current-main", "7c934f9678e65200797d42f455cb2c3cdc08630f", "01e139f80e889efe27fec1990d0c97ca4976a546", 3, True, "SOURCE_GO", "Written exclusive PHP/MySQL grant, then bounded authorization/concurrency gate and exact cleanup."),
    row(3, "01a022b4-abcb-7270-a285-9438725e2bcf", "OPS-DASHBOARD-ACTIVITY-SITE-PRIVACY-01", "<local-user>/.codex/worktrees/621d/oblivionfindings", "codex/ops-dashboard-activity-site-privacy-01-current-main", "3ebe62df774ad4f98d9be7d4ddf002e2085281c3", "ef80d1fd4cd84a6b5bd3e2f8292c47f41a0d6287", 5, True, "SOURCE_GO", "Written exclusive PHP/MySQL grant, then bounded dashboard privacy gate and exact cleanup."),
    row(4, "01a022b4-839f-7022-bc0a-93e52a1a4994", "MED-READER-SITE-CONCEALMENT-01", "<local-user>/.codex/worktrees/98e1/oblivionfindings", "codex/med-rbac-01-current-main-reconstruction", "efdf913968500876c55aec255b0807f3458e5ce5", "7efd09fe6cef1e7a65eca7c68df84fd0f04faa21", 67, False, "NO_CREDIT_VERDICT_UNAVAILABLE", "Independent 67-path review completed twice without a readable verdict payload; obtain a fresh readable verdict before runtime."),
    row(5, "01a022b4-8f78-72b0-977f-bf14ca152b5b", "GOV-SPEND-AUTHORITY-01", "<local-user>/.codex/worktrees/f5ab/oblivionfindings", "codex/gov-spend-authority-01", "dcde2163e5a1811677d8bc2e90e4c10bdf0e7d05", "a9f32f2c97c9fcd8db2e4490c86f193cf1f942ac", 24, True, "SOURCE_GO", "Written exclusive PHP/MySQL grant for the corrected isolated real-process test; no runtime credit yet."),
    row(6, "01a022b4-5b24-7c01-92e8-2d89dd6e13a0", "RESP-STATE-01", "<local-user>/.codex/worktrees/23d0/oblivionfindings", None, "8d6eae0058a5069903dd8bc02aef38648f3e9d73", "ca19f5e19d41bd14dcf0d5f8ea1a741d25ee7faf", 34, True, "SOURCE_GO_RUNTIME_NO_GO", "Repair cross-worktree Windows vendor/dependency topology under a fresh grant; prior Pest discovered zero tests."),
    row(7, "01a022b4-95ea-7d43-9071-ac6e41dccad2", "HS-PROCEDURE-APPROVAL-ASSURANCE-01", "<local-user>/.codex/worktrees/c7e5/oblivionfindings", "codex/hs-procedure-approval-assurance-01-wave", "cdd77dcaf408d85eef342a6bbf01dbf13f410e7c", "6cf38dae445e2fb540e0ec29f2029c0854daae5f", 13, True, "SOURCE_GO", "Written exclusive PHP/MySQL grant after mutation-authority, restrict-delete, and migration-isolation corrections."),
    row(8, "01a022b4-6895-70a2-b8e9-aa44bdc685bb", "GOV-RESOLUTION-QUORUM-01", "<local-user>/.codex/worktrees/f481/oblivionfindings", "codex/gov-resolution-quorum-01-current-main", "c8e6ae37e21184a03dd2891bf1fe74d413b96f42", "d01737bda2bae0a611cce8157c09e67bb863ce8c", 26, True, "SOURCE_GO", "Written exclusive serialized PHP/MySQL/frontend grant; source evidence alone is not runtime credit."),
    row(9, "01a022b4-7cec-7561-804c-7550f8144618", "MED-ERROR-LIFECYCLE-TERMINAL-BYPASS-01", "<local-user>/.codex/worktrees/d238/oblivionfindings", "codex/med-error-lifecycle-terminal-bypass-01-current-main", "4fa42822f60040e6fffad4f293536a931b771c5c", "f4a8c65da1d446ebbc76d1ee11b9241824453e75", 4, False, "SOURCE_NO_GO", "Remove clients.update as a record/correct action substitute and add clients.update-only negatives; correction follow-up hit the external usage limit."),
    row(10, "01a022b4-5aa3-7380-8774-9f17c4152b9c", "FIN-PAYMENT-MATCH-01", "<local-user>/.codex/worktrees/f869/oblivionfindings", "codex/fin-payment-match-01-f869", "c3f4339e004e59ae903a801a30dc7d8f41556bfb", "27c41f277f486e880bff3b20cc0854ea25e7b43a", 8, True, "SOURCE_GO_RUNTIME_INCOMPLETE", "Written exclusive PHP/MySQL grant for a fresh complete stop-first-failure gate; prior partial Pest is not verification."),
]

document = {
    "schema_version": "3.0",
    "generated_at": "2026-08-22T21:00:57+12:00",
    "purpose": "Fresh evidence-led exactly-ten-session remediation-wave reconciliation.",
    "coordinator_thread": "01a02451-bfd1-7d91-aae2-43503510915b",
    "summary": {
        "audit_research_tasks_active": 0,
        "remediation_tasks_active": 0,
        "total_background_tasks_active": 0,
        "allocated_sessions": 10,
        "returned_or_externally_blocked_sessions": 10,
        "source_ready_count": sum(bool(item["source_ready"]) for item in tasks),
        "branch_published_count": sum(bool(item["branch_published"]) for item in tasks),
        "merged_to_main_count": sum(bool(item["merged_to_main"]) for item in tasks),
        "runtime_verified_count": sum(bool(item["runtime_verified"]) for item in tasks),
        "heavy_php_slots_total": 1,
        "heavy_php_slots_in_use": 0,
        "slot_holder": "none",
        "current_main_sha": MAIN,
        "next_in_php_queue": "none-without-written-task-specific-exclusive-grant",
        "php_queue": [],
        "last_slot_release": "No heavy runtime was granted or run by this wave.",
        "last_coordination_event": "Exactly ten isolated sessions were reconciled: eight source-ready, zero published, zero merged to main, and zero runtime-verified. MED-READER lacks readable independent verdict evidence; MED-ERROR is source NO-GO and its correction follow-up hit the external usage limit.",
    },
    "safety_boundary": {
        "research_tasks_read_only": True,
        "remediation_tasks_use_isolated_worktrees": True,
        "remediation_tasks_preserve_existing_ui_ux": True,
        "remediation_tasks_do_not_write_audit_dashboard": True,
        "coordinator_owns_canonical_artifacts": True,
        "single_heavy_php_test_process": True,
        "merge_requires_explicit_user_authority": True,
    },
    "audit_research_tasks": [],
    "remediation_tasks": tasks,
    "visual_wave_11_overlay_10": {
        "verdict": "APPLIED_AFTER_TWO_INDEPENDENT_SOURCE_REVIEWS",
        "generator_sha256": WAVE11_GENERATOR_SHA256,
        "immediate_second_run": "already_applied",
        "runtime_credit_delta": 0,
    },
    "completion_status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE",
    "claim_limit": "Source-ready, branch-published, merged-to-main, and runtime-verified are independent. A pushed branch is not merged; source or Git evidence is not runtime/browser/product/task completion credit; this wave does not mutate canonical finding statuses.",
}

OUTPUT.write_text(json.dumps(document, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")

completion = json.loads(COMPLETION.read_text(encoding="utf-8"))
gate_name = "agent_assignments_reconciled_and_none_running"
require(gate_name in completion["completion_blockers"], "Historical assignment blocker drift")
gate = completion["gates"][gate_name]
require(gate["completed"] == 105 and gate["denominator"] == 111, "Historical assignment count drift")
gate["status"] = "blocked-historical-reconciliation-incomplete"
gate["detail"] = (
    "Historical reconciliation snapshot only: 105/111 assignment records were reconciled with explicit role/ID, "
    "scope, pass, returned-evidence count and unresolved gaps. Live orchestration records 0 active tasks after all "
    "exactly-ten remediation sessions returned or reached an external blocker; the historical 105/111 ratio remains "
    "incomplete and is not a live completion denominator."
)
COMPLETION.write_text(json.dumps(completion, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")

pointer = json.loads(POINTER.read_text(encoding="utf-8"))
require("completion_report" in pointer["artifacts"], "Completion pointer record missing")
pointer["artifacts"]["completion_report"] = record(COMPLETION)
POINTER.write_text(json.dumps(pointer, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
