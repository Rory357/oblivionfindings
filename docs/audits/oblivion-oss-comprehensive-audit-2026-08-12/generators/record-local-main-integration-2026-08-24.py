#!/usr/bin/env python3
"""Record the reviewed local-main integration without changing audit credit."""

from __future__ import annotations

import json
import subprocess
from datetime import datetime
from pathlib import Path


AUDIT_ROOT = Path(__file__).resolve().parents[1]
REPO_ROOT = Path(__file__).resolve().parents[4]
DASHBOARD = AUDIT_ROOT / "audit-dashboard.html"
SUMMARY = AUDIT_ROOT / "00-executive-summary.md"
GAPS = AUDIT_ROOT / "13-unresolved-questions-and-evidence-gaps.md"
EVIDENCE = AUDIT_ROOT / "evidence" / "source" / "local-main-integration-snapshot-2026-08-24.json"

APPLICATION_HEAD = "a0493442b9e392d324055c35bf25b69421dc2d35"
PUBLISHED_ORIGIN_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
AUDITED_SOURCE = "081ef198f9f992f224e8c0c9fba33df33dde40be"


def git(*arguments: str) -> str:
    return subprocess.check_output(
        ["git", *arguments], cwd=REPO_ROOT, text=True, encoding="utf-8"
    ).strip()


def exact_replace(text: str, old: str, new: str) -> str:
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f"Expected one dashboard occurrence, found {count}: {old[:80]!r}")
    return text.replace(old, new, 1)


def insert_marked_section(path: Path, anchor: str, marker: str, body: str) -> None:
    text = path.read_text(encoding="utf-8")
    start = f"<!-- {marker}_START -->"
    end = f"<!-- {marker}_END -->"
    rendered = f"{start}\n{body.strip()}\n{end}"
    if start in text:
        before, rest = text.split(start, 1)
        _, after = rest.split(end, 1)
        text = before + rendered + after
    else:
        text = exact_replace(text, anchor, anchor + "\n\n" + rendered)
    path.write_text(text, encoding="utf-8", newline="")


def build_snapshot() -> dict[str, object]:
    head = git("rev-parse", "HEAD")
    origin = git("rev-parse", "origin/main")
    if head != APPLICATION_HEAD or origin != PUBLISHED_ORIGIN_MAIN:
        raise RuntimeError(
            "This one-shot integration record must run at the reviewed application base "
            f"{APPLICATION_HEAD} with origin/main {PUBLISHED_ORIGIN_MAIN}; got {head} / {origin}."
        )

    tracked_status = git("status", "--porcelain", "--untracked-files=no")
    commits = []
    for line in git(
        "log", "--reverse", "--format=%H%x09%s", f"{PUBLISHED_ORIGIN_MAIN}..{APPLICATION_HEAD}"
    ).splitlines():
        commit, subject = line.split("\t", 1)
        commits.append({"commit": commit, "subject": subject})

    changed_paths = git(
        "diff", "--name-only", f"{PUBLISHED_ORIGIN_MAIN}..{APPLICATION_HEAD}"
    ).splitlines()

    return {
        "schema_version": "1.0",
        "generated_at": datetime.now().astimezone().isoformat(timespec="seconds"),
        "scope": "Git integration and audit-artifact publication evidence only.",
        "audited_source": AUDITED_SOURCE,
        "published_origin_main": PUBLISHED_ORIGIN_MAIN,
        "reviewed_local_application_main": APPLICATION_HEAD,
        "local_application_commits_ahead": len(commits),
        "local_application_changed_paths": len(changed_paths),
        "local_application_tracked_clean_before_audit_commit": tracked_status == "",
        "application_commits": commits,
        "branch_reconciliation": {
            "local_branches": 295,
            "registered_worktrees": 128,
            "tips_ancestor_of_local_main": 194,
            "tips_not_ancestor_of_local_main": 101,
            "non_ancestor_patch_equivalent": 53,
            "non_ancestor_patch_positive": 48,
            "completed_mappings_already_resolved_into_main": 4,
            "red_source_only_superseded_or_unproved": 44,
            "potentially_completed_and_still_missing": 0,
            "dirty_worktrees": 27,
            "dirty_product_candidate_worktrees": 23,
            "artifact_or_tool_only_dirty_worktrees": 4,
            "staged_entries_across_dirty_worktrees": 0,
        },
        "completed_mappings_already_on_main": [
            {
                "branch": "codex/hs-assurance-01-b11a",
                "candidate": "d1585344665443ab7177e04d2742fc63e7b79b2c",
                "main_commit": "8ba099fc12719d18b0b8f10eea2f0656e823195c",
                "resolution": "same-subject same-16-path integration",
            },
            {
                "branch": "codex/hr-compliance-renewals-disclosure-01-current-main",
                "candidate": "8f31cc95c84541fc5f140bff13f52a30d1bea494",
                "main_commit": "442416a4bfaf18ec2a18e48f56faa74126dfe957",
                "resolution": "empty after conflict resolution; patch already represented",
            },
            {
                "branch": "codex/wf-attendance-forced-end-site-current-main",
                "candidate": "71c75ce62e45e382e0c2877650a000b448cf9057",
                "main_commit": "9a14d542ee3775f9833bf0db3652bfdd4ebb7951",
                "resolution": "empty after conflict resolution; patch already represented",
            },
            {
                "branch": "codex/wf-email-identity-convergence-current-main",
                "candidate": "f58b898476ea2874cfe4a5740b9a3e974e59bb52",
                "main_commit": "61b6723c5f49e949c0d699e4ebb9a4d347a50492",
                "resolution": "empty after conflict resolution; patch already represented",
            },
        ],
        "authoritative_exclusion_examples": [
            {"category": "source_or_static_only", "refs": [
                "codex/gov-spend-authority-01-current-main@6c56fd85",
                "codex/fin-gl-recurring-01-current-main@4a8e7bf1",
                "codex/fin-settlement-01-current-main@aeb2b6db",
                "codex/fund-bind-01-current-main@53149104",
                "codex/fin-fixed-asset-disposal-01-current-main@90765ff7",
            ]},
            {"category": "runtime_red_or_residual", "refs": [
                "codex/priv-dsr-01-current-main@d531d9a0",
                "codex/fin-payment-match-01-f869@c3f4339e",
                "codex/med-error-lifecycle-terminal-bypass-01-current-main@4fa42822",
                "codex/med-rbac-01-current-main-reconstruction@efdf9139",
                "codex/nzs-assurance-01-current-main@6ec2691b",
            ]},
            {"category": "no_retained_completion_evidence", "refs": [
                "codex/vis-overlay-focus-01-current-main@2a4337a5",
                "codex/hr-profile-site-privacy-candidate-20260821@d89886bc",
            ]},
        ],
        "publication": {
            "application_commit_created_by_this_step": False,
            "audit_bundle_commit_pending_when_generated": True,
            "pushed": False,
            "combined_mysql_gate_claimed": False,
            "deployed_browser_release_claimed": False,
        },
        "claim_limit": (
            "The reviewed local application base is distinct from published origin/main and from the "
            "immutable audited source. Branch presence, source review, merge, runtime verification, "
            "deployment, and audit-wide completion remain separate claims."
        ),
    }


def update_dashboard(snapshot: dict[str, object]) -> None:
    text = DASHBOARD.read_text(encoding="utf-8")
    text = exact_replace(
        text,
        ".decision-grid {\n      display: grid;\n      grid-template-columns: repeat(3, minmax(0, 1fr));",
        ".decision-grid {\n      display: grid;\n      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));",
    )
    text = exact_replace(
        text,
        "      overflow-x: auto;\n    }\n\n    .quick-nav a {",
        "      overflow-x: auto;\n      scrollbar-width: none;\n      -ms-overflow-style: none;\n    }\n\n    .quick-nav .shell::-webkit-scrollbar { display: none; }\n\n    .quick-nav a {",
    )
    text = exact_replace(
        text,
        "      scroll-margin-top: 20px;",
        "      scroll-margin-top: 72px;",
    )
    text = exact_replace(
        text,
        "Remediation mode: <strong>23 Aug 2026 · no further audit waves; work restricted to the canonical 100 findings</strong>",
        "Remediation mode: <strong>24 Aug 2026 · no further audit waves; work restricted to the canonical 100 findings</strong>",
    )
    text = exact_replace(
        text,
        "Current checkpoint: <strong>23 Aug 2026 · live 904 audit and remediation state</strong>",
        "Current checkpoint: <strong>24 Aug 2026 · frozen 904 audit plus reviewed local-main integration evidence</strong>",
    )
    text = exact_replace(
        text,
        '<span id="orchestrationSummary"><strong>0 background tasks active:</strong> audit expansion stopped · remediation workspace snapshot complete · sole heavy/frontend slot free</span>',
        '<span id="orchestrationSummary"><strong>0 background tasks active:</strong> audit expansion stopped · branch reconciliation closed · unfinished completion gates remain blocked</span>',
    )
    text = exact_replace(
        text,
        '<span id="currentMainRelease">Latest reviewed release on main: <code>20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4</code></span>',
        '<span id="currentMainRelease">Published origin/main: <code>20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4</code> · reviewed local application main: <code>a0493442b9e392d324055c35bf25b69421dc2d35</code> (57 commits ahead; not pushed)</span>',
    )
    text = exact_replace(
        text,
        '<span class="decision-label">Local remediation workspace · 23 Aug</span>\n          <h3 id="workspaceFindingHeading">55 findings have current workspaces</h3>\n          <ul>\n            <li id="workspaceCandidateSummary">51 findings have a local dirty or ahead candidate.</li>\n            <li id="workspaceCleanSummary">4 have a clean current-main reconciliation with no duplicate local patch.</li>',
        '<span class="decision-label">Historical local remediation workspace · 23 Aug</span>\n          <h3 id="workspaceFindingHeading">55 findings had recorded workspaces</h3>\n          <ul>\n            <li id="workspaceCandidateSummary">51 findings had a local dirty or ahead candidate.</li>\n            <li id="workspaceCleanSummary">4 had a clean current-main reconciliation with no duplicate local patch.</li>',
    )
    text = exact_replace(
        text,
        '<span id="deliveryCardLabel" class="decision-label">Latest remediation delivery</span>\n          <h3 id="reviewedWaveHeading">1 runtime-verified · 2 pending runtime</h3>\n          <ul>\n            <li id="reviewedWavePublication">3 of 3 are source-ready or better; 0 published and 0 merged.</li>\n            <li id="reviewedWaveRuntime">AUTH gate: 28 tests and 121 assertions passed.</li>\n            <li><a href="evidence/source/remediation-delivery-snapshot-2026-08-23.json">Open the exact three-lane delivery snapshot</a>.</li>\n          </ul>',
        '<span id="deliveryCardLabel" class="decision-label">Historical delivery snapshot · 23 Aug</span>\n          <h3 id="reviewedWaveHeading">Three-lane snapshot retained as historical evidence</h3>\n          <ul>\n            <li id="reviewedWavePublication">Its branch heads are not used as current merge evidence.</li>\n            <li id="reviewedWaveRuntime">Canonical finding statuses and audit-wide gates are unchanged.</li>\n            <li><a href="evidence/source/remediation-delivery-snapshot-2026-08-23.json">Open the historical three-lane snapshot</a>.</li>\n          </ul>',
    )
    insertion = '''        <article class="decision-card good" id="local-main-integration-card">
          <span class="decision-label">Reviewed local main · 24 Aug</span>
          <h3>57 application commits consolidated</h3>
          <ul>
            <li>490 application paths differ from published <code>origin/main</code>.</li>
            <li>Zero completed branch patches remain missing; 44 real-delta tips stay excluded as red, source-only, superseded or unproved.</li>
            <li>No push, combined MySQL gate or deployed-release claim is made.</li>
            <li><a href="evidence/source/local-main-integration-snapshot-2026-08-24.json">Open the exact local-main integration snapshot</a>.</li>
          </ul>
        </article>
'''
    text = exact_replace(text, "      </div>\n    </section>\n\n    <section aria-labelledby=\"decision-heading\">", insertion + "      </div>\n    </section>\n\n    <section aria-labelledby=\"decision-heading\">")
    text = exact_replace(
        text,
        "<div class=\"update-stamp\"><strong>Checkpoint 904 · live evidence and remediation state</strong><br>Last dashboard update: 23 Aug 2026</div>",
        "<div class=\"update-stamp\"><strong>Checkpoint 904 · blocked audit plus reviewed local-main integration</strong><br>Last dashboard update: 24 Aug 2026</div>",
    )

    prefix = '<script id="dashboardData" type="application/json">'
    start = text.index(prefix) + len(prefix)
    end = text.index("</script>", start)
    data = json.loads(text[start:end])
    data["localMainIntegration"] = snapshot
    rendered = json.dumps(data, separators=(",", ":"), ensure_ascii=False)
    text = text[:start] + rendered + text[end:]
    DASHBOARD.write_text(text, encoding="utf-8", newline="")


def update_markdown(snapshot: dict[str, object]) -> None:
    summary_anchor = (
        "Architecture: one tenant, multiple sites. Site, role, ownership, direct-object and privacy "
        "boundaries—not tenant isolation."
    )
    summary_body = f"""
## Current local-main integration snapshot — 24 Aug 2026

Published `origin/main` remains `{snapshot['published_origin_main']}`. The reviewed local application base is `{snapshot['reviewed_local_application_main']}`, **57 commits and 490 application paths ahead**. A full 295-branch/128-worktree reconciliation found 101 non-ancestor tips: 53 are patch-equivalent and 48 carry real deltas. Four completed mappings are already represented on local `main`; the other 44 real-delta tips are red, source/static-only, superseded or lack authoritative completion evidence. **Potential completed work still missing from local `main`: 0.** The exact evidence is in [`evidence/source/local-main-integration-snapshot-2026-08-24.json`](evidence/source/local-main-integration-snapshot-2026-08-24.json).

This dated audit bundle is an audit-only follow-on to that application base. It has not been pushed, does not change any canonical finding status, and does not claim a combined MySQL gate, deployed-browser release proof or audit-wide completion.
"""
    insert_marked_section(
        SUMMARY,
        summary_anchor,
        "LOCAL_MAIN_INTEGRATION_2026_08_24",
        summary_body,
    )

    gaps_anchor = (
        "The audit is **blocked—not comprehensive or complete**. The corrected active denominator is "
        "**904 = 790 human + 111 download/API + 3 machine-ingress capabilities**. Static source provenance "
        "is reconciled for 3,024/3,024 routes and 727/727 true Inertia pages, but accepted FEATURE-ID ownership "
        "is only 2,994/3,024 routes and 714/727 pages. Completion remains blocked because 404 benchmark targets, "
        "585/8,753 visual rows and 364/4,312 material rows remain unproved or unresolved, and runtime, state, "
        "ease-score and audit-wide test proof is absent."
    )
    gaps_body = """
## Current Git integration boundary — 24 Aug 2026

The reviewed local application base is `a0493442b9e392d324055c35bf25b69421dc2d35`, 57 commits/490 paths ahead of published `origin/main` at `20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4`. Branch reconciliation found no completed patch still missing from local `main`. Forty-four non-equivalent tips remain deliberately excluded because their evidence is red, source/static-only, superseded or incomplete. This resolves the Git-consolidation question only; it does not close any audit completion gate or authorise merging those excluded candidates.
"""
    insert_marked_section(
        GAPS,
        gaps_anchor,
        "LOCAL_MAIN_INTEGRATION_2026_08_24",
        gaps_body,
    )


def main() -> None:
    snapshot = build_snapshot()
    EVIDENCE.write_text(json.dumps(snapshot, indent=2) + "\n", encoding="utf-8")
    update_dashboard(snapshot)
    update_markdown(snapshot)
    print(json.dumps({
        "reviewed_local_application_main": snapshot["reviewed_local_application_main"],
        "published_origin_main": snapshot["published_origin_main"],
        "commits_ahead": snapshot["local_application_commits_ahead"],
        "changed_paths": snapshot["local_application_changed_paths"],
        "potentially_completed_and_still_missing": snapshot["branch_reconciliation"]["potentially_completed_and_still_missing"],
    }, indent=2))


if __name__ == "__main__":
    main()
