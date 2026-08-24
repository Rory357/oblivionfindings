#!/usr/bin/env python3
"""Add the accepted direct-only wave-4 evidence to benchmark register 06.

The prompt-listed denominator remains 97 projects. Frappe core is retained as
one separately labelled supplemental upstream, producing 98 unique rows.
"""

from __future__ import annotations

import csv
import hashlib
import json
from collections import defaultdict
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
REGISTER = AUDIT / "06-open-source-benchmark-register.csv"
WAVE = SOURCE / "benchmark-target-specific-adjudication-902-wave4.json"
EXPECTED_WAVE_SHA = "6eba8e290637fcdb5045ad80cb5ac0579b3843a1bfe348a4ff2728d62fefbe04"
FRAPPE_URL = "https://github.com/frappe/frappe"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def append_once(value: str, addition: str) -> str:
    value = value.strip()
    addition = addition.strip()
    if not addition or addition in value:
        return value
    return f"{value} {addition}".strip()


def union_pipe(value: str, additions: list[str]) -> str:
    existing = {item.strip() for item in value.split("|") if item.strip()}
    existing.update(additions)
    return " | ".join(sorted(existing))


def source_link(repo_url: str, commit: str, locus: str) -> str:
    path, line_range = locus.rsplit(":", 1)
    return f"{repo_url}/blob/{commit}/{path}#{line_range}"


assert sha(WAVE) == EXPECTED_WAVE_SHA
wave = json.loads(WAVE.read_text(encoding="utf-8-sig"))
direct = [
    item for item in wave["evaluations"]
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
]
assert len(direct) == 12
by_repo: dict[str, list[dict]] = defaultdict(list)
for item in direct:
    by_repo[item["benchmark"]["official_repository_url"]].append(item)
assert set(by_repo) == {
    "https://github.com/keycloak/keycloak",
    "https://github.com/openemr/openemr",
    "https://github.com/frappe/erpnext",
    "https://github.com/opf/openproject",
    "https://github.com/frappe/hrms",
    FRAPPE_URL,
    "https://github.com/grocy/grocy",
}

with REGISTER.open("r", encoding="utf-8-sig", newline="") as handle:
    reader = csv.DictReader(handle)
    fieldnames = list(reader.fieldnames or [])
    rows = list(reader)

assert len(rows) in {97, 98}
assert len({row["canonical_url"] for row in rows}) == len(rows)
assert sum(row["canonical_url"] == FRAPPE_URL for row in rows) <= 1


def apply_evidence(row: dict[str, str], evaluations: list[dict]) -> None:
    commits = {item["benchmark"]["commit_sha"] for item in evaluations}
    assert len(commits) == 1
    commit = next(iter(commits))
    row["commit_sha"] = commit
    row["inspected_ref"] = "commit-pinned wave-4"
    row["inspected_date"] = "2026-08-13"
    row["release_activity_signal"] = append_once(
        row["release_activity_signal"],
        f"Prior catalogue activity evidence retained; supplemental target-specific snapshot inspected at {commit}.",
    )
    edition_notes = sorted({item["benchmark"]["licence"]["edition_boundary"] for item in evaluations})
    row["edition_boundary"] = append_once(
        row["edition_boundary"],
        " Wave-4 boundary: " + " ".join(edition_notes),
    )
    behavior = []
    limitations = []
    requirements = []
    targets = []
    for item in sorted(evaluations, key=lambda entry: entry["working_key"]):
        target = item["working_key"]
        bench = item["benchmark"]
        links = "; ".join(source_link(bench["official_repository_url"], bench["commit_sha"], locus) for locus in bench["source_loci"])
        behavior.append(f"[{target}] {bench['proven_slice']} Primary sources: {links}.")
        limitations.append(f"[{target}] {bench['parity_limits']}")
        requirements.append(f"[{target}] {item['neutral_requirement']}")
        targets.append(target)
    row["exact_behaviour_screen_workflow_inspected"] = append_once(
        row["exact_behaviour_screen_workflow_inspected"], " Wave-4: " + " ".join(behavior)
    )
    row["related_feature_ids"] = union_pipe(row["related_feature_ids"], targets)
    row["limitations"] = append_once(row["limitations"], " Wave-4: " + " ".join(limitations))
    row["neutral_requirements_extracted"] = append_once(
        row["neutral_requirements_extracted"], " Wave-4: " + " ".join(requirements)
    )
    row["security_or_operational_caveat"] = append_once(
        row["security_or_operational_caveat"],
        " Wave-4 target limits: " + " ".join(limitations) + " Benchmark behavior only; no implementation inheritance or copying.",
    )
    row["reason_selected_or_excluded"] = append_once(
        row["reason_selected_or_excluded"],
        " Selected for fresh direct target-specific wave-4 evidence; no sibling or module-wide inheritance.",
    )
    row["observer_agent"] = union_pipe(row["observer_agent"], ["benchmark_wave4_ncm"])
    row["neutral_writer_agent"] = union_pipe(row["neutral_writer_agent"], ["root"])
    row["native_comparator_agent"] = union_pipe(row["native_comparator_agent"], ["wave4_integrity_review"])


for row in rows:
    evaluations = by_repo.get(row["canonical_url"])
    if evaluations:
        apply_evidence(row, evaluations)

frappe_evaluations = by_repo[FRAPPE_URL]
frappe_row = next((row for row in rows if row["canonical_url"] == FRAPPE_URL), None)
if frappe_row is None:
    frappe_row = {name: "" for name in fieldnames}
    rows.append(frappe_row)
frappe_row.update({
    "category": "Cross-cutting features previously under-represented; Settings, access and integrations",
    "project": "frappe/frappe",
    "canonical_url": FRAPPE_URL,
    "inspected_ref": "commit-pinned wave-4",
    "commit_sha": frappe_evaluations[0]["benchmark"]["commit_sha"],
    "inspected_date": "2026-08-13",
    "release_activity_signal": "Supplemental target-specific immutable-source inspection; not part of the 97-project prompt denominator; activity/maturity not independently refreshed in this row.",
    "root_licence": "MIT",
    "edition_boundary": "Only the MIT framework user-role and webhook sources are credited; Frappe Cloud and application-specific/private extensions are excluded.",
    "strengths": "Permission-gated role editing; configurable signed delivery with explicit delivery/retry evidence.",
    "benchmark_outcome": "Native benchmark",
    "reason_selected_or_excluded": "Closest verified framework-native behavior for these two exact settings targets; no other Frappe behavior inherits credit.",
    "observer_agent": "benchmark_wave4_ncm",
    "neutral_writer_agent": "root",
    "native_comparator_agent": "wave4_integrity_review",
})
apply_evidence(frappe_row, frappe_evaluations)
rows.sort(key=lambda row: (row["project"].casefold(), row["canonical_url"]))

assert len(rows) == 98
assert len({row["canonical_url"] for row in rows}) == 98
for repo_url, evaluations in by_repo.items():
    row = next(item for item in rows if item["canonical_url"] == repo_url)
    assert all(evaluation["working_key"] in row["related_feature_ids"] for evaluation in evaluations)
    assert all(evaluation["benchmark"]["commit_sha"] == row["commit_sha"] for evaluation in evaluations)

with REGISTER.open("w", encoding="utf-8", newline="") as handle:
    writer = csv.DictWriter(handle, fieldnames=fieldnames, quoting=csv.QUOTE_ALL, lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)

print(json.dumps({"path": str(REGISTER), "sha256": sha(REGISTER), "rows": len(rows), "unique_urls": len({row['canonical_url'] for row in rows})}, indent=2))
