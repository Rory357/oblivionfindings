#!/usr/bin/env python3
"""Rebuild the final 902-target audit ledgers without changing application files.

The corrected working manifest is the sole target denominator. Target route/page
columns are populated only from target-supported IDs in that manifest. Legacy
inventory rows are used only to describe a clearly labelled discovery envelope;
they never grant exclusive route, page, benchmark, finding, or pass ownership.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Iterable


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT_DIR = GENERATOR_DIR.parent
SOURCE_DIR = AUDIT_DIR / "evidence" / "source"
GENERATOR_PATH = Path(__file__).resolve()

MANIFEST_PATH = SOURCE_DIR / "working-capability-manifest-902.json"
BENCHMARK_PATH = SOURCE_DIR / "benchmark-final-902-mapping.json"
FRESH_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-901.json"
WAVE2_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-901-wave2.json"
WAVE3_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-901-wave3.json"
WAVE4_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave4.json"
WAVE5_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave5.json"
WAVE6_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave6.json"
WAVE7_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave7.json"
WAVE8_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave8.json"
WAVE9_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave9.json"
WAVE10_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave10.json"
WAVE11_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave11.json"
WAVE12_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave12.json"
WAVE13_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave13.json"
WAVE14_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave14.json"
WAVE15_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave15.json"
WAVE16_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave16.json"
WAVE17_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave17.json"
WAVE18_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave18.json"
WAVE19_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave19.json"
WAVE20_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave20.json"
WAVE21_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave21.json"
WAVE22_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave22.json"
WAVE23_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave23.json"
WAVE24_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave24.json"
WAVE27_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave27.json"
WAVE28_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave28.json"
WAVE30_PATH = SOURCE_DIR / "benchmark-target-specific-adjudication-902-wave30.json"
WAVE8_WITHDRAWAL_PATH = SOURCE_DIR / "benchmark-wave8-ncm-withdrawal-adjudication.json"
SELECTED_PATH = SOURCE_DIR / "selected-benchmark-adjudication.json"
NCM_PATH = SOURCE_DIR / "no-credible-match-adjudication.json"
INVENTORY_PATH = AUDIT_DIR / "inventory.json"
FINDINGS_PATH = AUDIT_DIR / "findings.json"
AGENT_REGISTER_PATH = SOURCE_DIR / "agent-reconciliation-register.json"

LEDGER_PATH = AUDIT_DIR / "02-eight-pass-coverage-ledger.csv"
MATRIX_PATH = AUDIT_DIR / "03-feature-to-benchmark-matrix.csv"
SUMMARY_PATH = SOURCE_DIR / "final-902-ledger-generation-summary.json"

LEDGER_HEADERS = [
    "feature_id",
    "working_key",
    "class",
    "module",
    "submodule",
    "source_family_inventory_envelope",
    "P1_status",
    "P2_status",
    "P3_status",
    "P4_status",
    "P5_status",
    "P6_status",
    "P7_status",
    "P8_status",
    "agent_assignments",
    "evidence_count",
    "gaps",
    "reconciliation_status",
]

MATRIX_HEADERS = [
    "feature_id",
    "module",
    "submodule",
    "owning_actor",
    "secondary_actors",
    "user_job",
    "criticality",
    "navigation_entry",
    "route_names",
    "route_paths",
    "page_files",
    "backend_anchors",
    "current_states",
    "current_workflow_summary",
    "benchmark_candidates",
    "selected_open_source_benchmark",
    "benchmark_url_and_sha",
    "verified_behaviour",
    "neutral_requirements_extracted",
    "no_match_evidence",
    "current_ease_score",
    "target_ease_score",
    "P1",
    "P2",
    "P3",
    "P4",
    "P5",
    "P6",
    "P7",
    "P8",
    "finding_ids",
    "confidence",
]


def load_json(path: Path, *, required: bool = True) -> dict[str, Any] | None:
    if not path.exists():
        if required:
            raise FileNotFoundError(path)
        return None
    with path.open("r", encoding="utf-8-sig") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise ValueError(f"Expected object in {path}")
    return value


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def file_fingerprint(path: Path) -> dict[str, Any]:
    data = path.read_bytes()
    return {
        "path": path.relative_to(AUDIT_DIR).as_posix(),
        "sha256": sha256_bytes(data),
        "bytes": len(data),
    }


def ordered_text_sha(values: Iterable[str]) -> str:
    return sha256_bytes(("\n".join(values) + "\n").encode("utf-8"))


def canonical_row_sha(rows: list[dict[str, Any]], headers: list[str]) -> str:
    lines = [
        json.dumps([str(row.get(header, "")) for header in headers], ensure_ascii=False, separators=(",", ":"))
        for row in rows
    ]
    return ordered_text_sha(lines)


def unique_strings(values: Iterable[Any]) -> list[str]:
    return sorted({str(value).strip() for value in values if str(value).strip()})


def semicolon(values: Iterable[Any]) -> str:
    return "; ".join(unique_strings(values))


def as_list(value: Any) -> list[Any]:
    if value is None:
        return []
    return value if isinstance(value, list) else [value]


def write_csv(path: Path, headers: list[str], rows: list[dict[str, Any]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="raise", lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def github_commit_url(repo_at_sha: str) -> str:
    match = re.fullmatch(r"([^@\s]+)@([0-9a-fA-F]{7,40})", repo_at_sha.strip())
    if not match:
        return repo_at_sha
    repo, sha = match.groups()
    return f"https://github.com/{repo}/commit/{sha}"


def benchmark_detail(
    mapping: dict[str, Any] | None,
    selected_by_id: dict[str, dict[str, Any]],
    selected_supported_by_id: dict[str, list[dict[str, Any]]],
    ncm_by_id: dict[str, dict[str, Any]],
    ncm_repos: dict[str, dict[str, Any]],
    ncm_loci: dict[str, dict[str, Any]],
    fresh_by_key: dict[str, dict[str, Any]],
    fresh_repos: dict[str, dict[str, Any]],
) -> dict[str, Any]:
    if mapping is None:
        return {
            "completion_credit": False,
            "resolution": "mapping_artifact_absent",
            "P3": "Blocked—final 902-target benchmark mapping artifact unavailable; rerun after it is present.",
            "candidates": "Not target-adjudicated",
            "selected": "",
            "url_sha": "",
            "verified_behaviour": "",
            "neutral_requirement": "",
            "no_match": "",
            "confidence": "Static identity only; benchmark and runtime unverified",
        }

    working_key = str(mapping.get("working_key", ""))
    status = str(mapping.get("status", "unproved"))
    source_units = [str(value) for value in as_list(mapping.get("source_units"))]
    evidence_loci = [str(value) for value in as_list(mapping.get("evidence_loci"))]
    credit = bool(mapping.get("completion_credit"))

    if credit and status.startswith("verified_benchmark_"):
        if len(source_units) != 1:
            raise ValueError(
                f"Verified target {working_key} must have exactly one final source unit; found {source_units}"
            )
        raw_unit = source_units[0]
        prefix, separator, source_id = raw_unit.partition(":")
        if not separator or not source_id:
            raise ValueError(f"Verified target {working_key} has malformed source unit {raw_unit}")

        if prefix == "selected":
            direct_record = selected_by_id.get(source_id)
            projected_records = selected_supported_by_id.get(source_id, [])
            if direct_record is not None:
                if any(record is not direct_record for record in projected_records):
                    raise ValueError(
                        f"Selected target {working_key} has conflicting direct/projected records"
                    )
                if direct_record.get("verdict") != "verified":
                    raise ValueError(
                        f"Selected direct target {working_key} is not backed by a verified record"
                    )
                record = direct_record
                resolution = "selected_direct"
            else:
                if len(projected_records) != 1:
                    raise ValueError(
                        f"Selected projected target {working_key} must resolve exactly once; "
                        f"found {len(projected_records)}"
                    )
                record = projected_records[0]
                if (
                    source_id
                    not in unique_strings(
                        as_list(
                            (record.get("projected_capability_adjudication") or {}).get(
                                "benchmark_supported_capability_ids"
                            )
                        )
                    )
                    or record.get("verdict") not in {"verified", "overextended"}
                ):
                    raise ValueError(
                        f"Selected projected target {working_key} lacks one supported capability decision"
                    )
                resolution = "selected_projected_supported"

            expected_loci = unique_strings(as_list(record.get("cited_project_sha_path_lines")))
            if expected_loci != unique_strings(evidence_loci):
                raise ValueError(
                    f"Selected target {working_key} final loci differ from its adjudication record"
                )
            selected_name = str(record.get("selected_benchmark", "")).strip()
            commit = str(record.get("benchmark_catalogue_commit", "")).strip()
            url_sha = github_commit_url(commit)
            verified_behaviour = str(record.get("exact_cited_behavior", "")).strip()
            neutral_requirement = str(record.get("neutral_requirement", "")).strip()

        elif prefix == "ncm":
            record = ncm_by_id.get(source_id)
            if record is None or record.get("verdict") != "candidate-found":
                raise ValueError(
                    f"Verified target {working_key} ncm source is not a candidate-found record"
                )
            repo_id = str(record.get("matched_repo_id", ""))
            locus_id = str(record.get("matched_evidence_locus_id", ""))
            repo = ncm_repos.get(repo_id)
            locus = ncm_loci.get(locus_id)
            if repo is None or locus is None:
                raise ValueError(
                    f"Verified candidate target {working_key} has unresolved repo/locus evidence"
                )
            sha = str(repo.get("commit_sha", "")).strip()
            expected_locus = (
                f"{repo_id}@{sha}::{locus.get('path')}::"
                f"L{locus.get('line_start')}-L{locus.get('line_end')}"
            )
            if unique_strings(evidence_loci) != [expected_locus]:
                raise ValueError(
                    f"Verified candidate target {working_key} final locus differs from candidate evidence"
                )
            selected_name = str(repo.get("project", "")).strip()
            repository_url = str(repo.get("official_repository_url", "")).rstrip("/")
            url_sha = f"{repository_url}/commit/{sha}" if repository_url and sha else ""
            verified_behaviour = str(locus.get("finding", "")).strip()
            neutral_requirement = str(record.get("neutral_requirement", "")).strip()
            resolution = "ncm_candidate_found"

        elif prefix in {"current-901", "fresh-902-wave4", "fresh-902-wave5", "fresh-902-wave6", "fresh-902-wave7", "fresh-902-wave8", "fresh-902-wave9", "fresh-902-wave10", "fresh-902-wave11", "fresh-902-wave12", "fresh-902-wave13", "fresh-902-wave14", "fresh-902-wave16", "fresh-902-wave17", "fresh-902-wave18", "fresh-902-wave19", "fresh-902-wave20", "fresh-902-wave21", "fresh-902-wave22", "fresh-902-wave23", "fresh-902-wave24", "fresh-902-wave27", "fresh-902-wave28", "fresh-902-wave30"}:
            record = fresh_by_key.get(working_key)
            if (
                record is None
                or str(record.get("source_unit")) != raw_unit
                or str(record.get("mapping_status")) != "verified_benchmark_direct"
            ):
                raise ValueError(f"Fresh verified target {working_key} does not resolve exactly")
            benchmark = record.get("benchmark") or {}
            expected_loci = unique_strings(record.get("evidence_loci", []))
            if expected_loci != unique_strings(evidence_loci):
                raise ValueError(f"Fresh verified target {working_key} loci differ from final mapping")
            repository_url = str(benchmark.get("official_repository_url", "")).rstrip("/")
            sha = str(benchmark.get("commit_sha", "")).strip()
            selected_name = repository_url.rsplit("/", 1)[-1] if repository_url else ""
            url_sha = f"{repository_url}/commit/{sha}" if repository_url and sha else ""
            verified_behaviour = str(benchmark.get("proven_slice", "")).strip()
            neutral_requirement = str(record.get("neutral_requirement", "")).strip()
            resolution = "fresh_target_specific_verified"

        else:
            raise ValueError(
                f"Verified target {working_key} has unsupported source prefix {prefix}"
            )

        if any(
            not value
            for value in (selected_name, url_sha, verified_behaviour, neutral_requirement)
        ):
            raise ValueError(
                f"Verified target {working_key} resolved but required matrix evidence is blank"
            )

        return {
            "completion_credit": True,
            "resolution": resolution,
            "P3": (
                "Mapped—verified benchmark with final-target completion credit; "
                f"inheritance={mapping.get('inheritance_method', 'recorded')}; full feature parity is not claimed."
            ),
            "candidates": selected_name,
            "selected": selected_name,
            "url_sha": url_sha,
            "verified_behaviour": verified_behaviour,
            "neutral_requirement": neutral_requirement,
            "no_match": "",
            "confidence": "High for the cited benchmark slice; Oblivion runtime and full parity unverified",
        }

    if credit and status.startswith("documented_ncm_"):
        if len(source_units) != 1:
            raise ValueError(
                f"NCM target {working_key} must have exactly one final source unit; found {source_units}"
            )
        raw_unit = source_units[0]
        prefix, separator, source_id = raw_unit.partition(":")
        if not separator or not source_id:
            raise ValueError(f"NCM target {working_key} has malformed source unit {raw_unit}")
        if prefix in {"current-901", "fresh-902-wave8", "fresh-902-wave12", "fresh-902-wave13"}:
            record = fresh_by_key.get(working_key)
            if (
                record is None
                or str(record.get("source_unit")) != raw_unit
                or str(record.get("mapping_status")) != "documented_ncm_direct"
            ):
                raise ValueError(f"Fresh NCM target {working_key} does not resolve exactly")
            if unique_strings(record.get("evidence_loci", [])) != unique_strings(evidence_loci):
                raise ValueError(f"Fresh NCM target {working_key} loci differ from final mapping")
            rejections = list(record.get("rejected_repositories", []))
            repo_urls = []
            rejection_text = []
            repo_ids = []
            for item in rejections:
                repo_id = str(item.get("repository_id", "")).strip()
                if repo_id:
                    repo = fresh_repos.get(repo_id, {})
                    url = str(repo.get("url", "")).rstrip("/")
                    sha = str(repo.get("commit_sha", "")).strip()
                else:
                    url = str(item.get("official_repository_url", "")).rstrip("/")
                    sha = str(item.get("commit_sha", "")).strip()
                    repo_id = url.rsplit("/", 2)[-2] + "/" + url.rsplit("/", 1)[-1] if url else ""
                if not repo_id or not url or not sha:
                    raise ValueError(f"Fresh NCM target {working_key} has an unresolved repository record")
                repo_ids.append(repo_id)
                if url and sha:
                    repo_urls.append(f"{url}/commit/{sha}")
                rejection_text.append(
                    f"{repo_id}: query={item.get('query', '')}; rejection={item.get('rejection', item.get('reason', ''))}"
                )
            repo_ids = unique_strings(repo_ids)
            search_terms = unique_strings(record.get("search_terms", []))
            neutral_requirement = str(record.get("neutral_requirement", "")).strip()
            bounded_ncm_reason = str(record.get("bounded_ncm_reason", "")).strip()
            if not repo_ids or not repo_urls or not neutral_requirement or not rejection_text:
                raise ValueError(f"Fresh NCM target {working_key} has incomplete evidence")
            search_basis = (
                f"Queries: {semicolon(search_terms)}."
                if search_terms
                else (
                    f"Neutral requirement: {neutral_requirement}. "
                    f"Bounded conclusion: {bounded_ncm_reason}."
                )
            )
            no_match = (
                f"Final target {working_key}; source adjudication unit {source_id}; final mapping status={status}; "
                f"inheritance={mapping.get('inheritance_method', 'recorded')}. "
                f"{search_basis} Bounded target-specific evaluation rejected "
                f"{len(repo_ids)} repositories. Exact evaluations: {' | '.join(rejection_text)}. "
                "This does not assert global non-existence."
            )
            return {
                "completion_credit": True,
                "resolution": "fresh_target_specific_documented_ncm",
                "P3": (
                    "Mapped—fresh target-specific documented No Credible Match with completion credit; "
                    "the result is bounded, not global."
                ),
                "candidates": semicolon(repo_ids),
                "selected": "No credible match (bounded official-repository evaluation)",
                "url_sha": semicolon(repo_urls),
                "verified_behaviour": "",
                "neutral_requirement": neutral_requirement,
                "no_match": no_match,
                "confidence": "Recorded target-specific bounded no-match evidence; runtime unverified",
            }
        if prefix != "ncm":
            raise ValueError(f"NCM target {working_key} has unsupported source prefix {prefix}")
        record = ncm_by_id.get(source_id)
        if record is None or record.get("verdict") != "documented-no-credible-match":
            raise ValueError(
                f"NCM target {working_key} does not resolve to documented-no-credible-match"
            )
        ncm_records = [record]
        repo_ids = unique_strings(
            repo_id
            for record in ncm_records
            for repo_id in as_list(record.get("curated_official_repo_ids"))
            + as_list(record.get("beyond_catalogue_official_repo_ids"))
        )
        missing_repo_ids = [repo_id for repo_id in repo_ids if repo_id not in ncm_repos]
        if missing_repo_ids:
            raise ValueError(
                f"NCM target {working_key} has unresolved repository snapshots: {missing_repo_ids}"
            )
        repo_urls = []
        for repo_id in repo_ids:
            repo = ncm_repos.get(repo_id, {})
            url = str(repo.get("official_repository_url", "")).rstrip("/")
            sha = str(repo.get("commit_sha", ""))
            if url and sha:
                repo_urls.append(f"{url}/commit/{sha}")
        requirements = unique_strings(record.get("neutral_requirement", "") for record in ncm_records)
        confidence_levels = unique_strings(
            (record.get("confidence") or {}).get("level", "") for record in ncm_records
        )
        unit_ids = unique_strings(record.get("adjudication_unit_id", "") for record in ncm_records)
        if not repo_ids or not repo_urls or not requirements or not unit_ids:
            raise ValueError(f"NCM target {working_key} has incomplete target-specific evidence")
        no_match = (
            f"Final target {working_key}; source adjudication unit {source_id}; "
            f"final mapping status={status}; inheritance={mapping.get('inheritance_method', 'recorded')}. "
            f"Bounded target-specific official-repository evaluation rejected {len(repo_ids)} candidates: "
            f"{semicolon(repo_ids)}. Exact reasons: no-credible-match-adjudication.json units {semicolon(unit_ids)}. "
            "This does not assert global non-existence."
        )
        return {
            "completion_credit": True,
            "resolution": "documented_ncm",
            "P3": (
                "Mapped—documented No Credible Match with final-target completion credit; "
                f"inheritance={mapping.get('inheritance_method', 'recorded')}; result is bounded, not global."
            ),
            "candidates": semicolon(repo_ids),
            "selected": "No credible match (bounded official-repository evaluation)",
            "url_sha": semicolon(repo_urls),
            "verified_behaviour": "",
            "neutral_requirement": semicolon(requirements),
            "no_match": no_match,
            "confidence": (
                f"{semicolon(confidence_levels) or 'Recorded'} for bounded no-match evidence; runtime unverified"
            ),
        }

    historical = semicolon(source_units)
    locus_note = f" Evidence loci retained as context: {len(evidence_loci)}." if evidence_loci else ""
    return {
        "completion_credit": False,
        "resolution": "unproved",
        "P3": (
            f"Blocked—final mapping status={status}; no completion credit. "
            "Pending, rejected, split, regrouped, merged, or unproved evidence is not inherited."
        ),
        "candidates": (
            f"Historical context only—no completion credit: {historical}.{locus_note}" if historical else "Not target-adjudicated"
        ),
        "selected": "",
        "url_sha": "",
        "verified_behaviour": "",
        "neutral_requirement": "",
        "no_match": "",
        "confidence": "High for stable target identity; benchmark completion and runtime unverified",
    }


def main() -> None:
    manifest = load_json(MANIFEST_PATH)
    inventory = load_json(INVENTORY_PATH)
    findings = load_json(FINDINGS_PATH)
    agent_register = load_json(AGENT_REGISTER_PATH)
    benchmark = load_json(BENCHMARK_PATH, required=False)
    fresh = load_json(FRESH_PATH, required=False) or {}
    wave2 = load_json(WAVE2_PATH, required=False) or {}
    wave3 = load_json(WAVE3_PATH, required=False) or {}
    wave4 = load_json(WAVE4_PATH, required=False) or {}
    wave5 = load_json(WAVE5_PATH, required=False) or {}
    wave6 = load_json(WAVE6_PATH, required=False) or {}
    wave7 = load_json(WAVE7_PATH, required=False) or {}
    wave8 = load_json(WAVE8_PATH, required=False) or {}
    wave9 = load_json(WAVE9_PATH, required=False) or {}
    wave10 = load_json(WAVE10_PATH, required=False) or {}
    wave11 = load_json(WAVE11_PATH, required=False) or {}
    wave12 = load_json(WAVE12_PATH, required=False) or {}
    wave13 = load_json(WAVE13_PATH, required=False) or {}
    wave14 = load_json(WAVE14_PATH, required=False) or {}
    wave15 = load_json(WAVE15_PATH, required=False) or {}
    wave16 = load_json(WAVE16_PATH, required=False) or {}
    wave17 = load_json(WAVE17_PATH, required=False) or {}
    wave18 = load_json(WAVE18_PATH, required=False) or {}
    wave19 = load_json(WAVE19_PATH, required=False) or {}
    wave20 = load_json(WAVE20_PATH, required=False) or {}
    wave21 = load_json(WAVE21_PATH, required=False) or {}
    wave22 = load_json(WAVE22_PATH, required=False) or {}
    wave23 = load_json(WAVE23_PATH, required=False) or {}
    wave24 = load_json(WAVE24_PATH, required=False) or {}
    wave27 = load_json(WAVE27_PATH, required=False) or {}
    wave28 = load_json(WAVE28_PATH, required=False) or {}
    wave30 = load_json(WAVE30_PATH, required=False) or {}
    wave8_withdrawal = load_json(WAVE8_WITHDRAWAL_PATH, required=False) or {}
    selected = load_json(SELECTED_PATH, required=False) or {}
    ncm = load_json(NCM_PATH, required=False) or {}

    assert manifest is not None and inventory is not None and findings is not None and agent_register is not None
    withdrawn_wave8_keys = {
        str(item.get("working_key"))
        for item in wave8_withdrawal.get("withdrawals", [])
        if str(item.get("working_key", "")).strip()
    }
    if withdrawn_wave8_keys != {"CAP-CLIN-BEHAVIOUR-REGISTER", "CAP-CR-EVIDENCE-PACK-ASSEMBLY"}:
        raise ValueError("Wave-8 NCM withdrawal key set changed")
    agent_assignments = list(agent_register.get("assignments", []))
    required_agent_fields = {
        "agent_id", "role", "scope", "pass", "evidence_count", "unresolved_gaps"
    }
    if int(agent_register.get("assignment_count", -1)) != len(agent_assignments):
        raise ValueError("Agent register assignment_count does not match assignments")
    if any(required_agent_fields - set(row) for row in agent_assignments):
        raise ValueError("Agent register lacks the prompt-required per-assignment fields")
    agent_reconciliation = (
        "P1-P8 specialist evidence register: evidence/source/agent-reconciliation-register.json; "
        f"{len(agent_assignments)} assignments explicitly record role/ID, scope, pass, "
        "returned-evidence count and unresolved gaps; target-row lenses: "
        "P1/P5/P7/P8 final_matrix_rebuild, P3 benchmark_working_map, P2/P4/P6 blocked"
    )
    targets = list(manifest.get("targets", []))
    if len(targets) != 902:
        raise ValueError(f"Corrected manifest must contain 902 targets, found {len(targets)}")
    targets.sort(key=lambda target: str(target["working_key"]))

    working_keys = [str(target["working_key"]) for target in targets]
    feature_ids = [str(target["id"]) for target in targets]
    if len(set(working_keys)) != 902 or len(set(feature_ids)) != 902:
        raise ValueError("Manifest working keys and feature IDs must each be unique")
    if working_keys != feature_ids:
        raise ValueError("This corrected manifest requires working_key == id for every target")

    expected_counts = manifest.get("counts", {})
    class_counts = Counter(str(target["class"]) for target in targets)
    if {key: class_counts.get(key, 0) for key in ("H", "D", "M")} != {
        key: int(expected_counts[key]) for key in ("H", "D", "M")
    }:
        raise ValueError("Manifest class totals do not match its declared counts")

    routes_by_id = {str(route["route_id"]): route for route in inventory.get("routes", [])}
    pages_by_id = {str(page["page_id"]): page for page in inventory.get("pages", [])}
    module_names = {
        str(module["module_key"]): str(module["module"]) for module in inventory.get("modules", [])
    }

    superseded_projection = inventory.get("superseded_feature_projection")
    if (
        isinstance(superseded_projection, dict)
        and isinstance(superseded_projection.get("features"), list)
    ):
        family_inventory_features = superseded_projection["features"]
        family_inventory_source = "superseded_feature_projection.features"
    else:
        family_inventory_features = inventory.get("features", [])
        family_inventory_source = "features"

    family_index: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for feature in family_inventory_features:
        family_keys = unique_strings(
            [
                feature.get("feature_id", ""),
                feature.get("legacy_family_id", ""),
                feature.get("canonical_capability_id", ""),
            ]
        )
        for family_key in family_keys:
            family_index[family_key].append(feature)

    benchmark_by_key: dict[str, dict[str, Any]] = {}
    if benchmark is not None:
        benchmark_targets = list(benchmark.get("targets", []))
        benchmark_by_key = {str(target["working_key"]): target for target in benchmark_targets}
        if len(benchmark_targets) != 902 or set(benchmark_by_key) != set(working_keys):
            raise ValueError("Final benchmark mapping must exactly cover the corrected 902 working keys")

    fresh_records = list(fresh.get("targets", []))
    wave2_evaluations = list(wave2.get("evaluations", []))
    wave2_selected_records = []
    for evaluation in wave2_evaluations:
        if evaluation.get("completion_credit_recommended") is not True:
            continue
        normalized = dict(evaluation)
        normalized["mapping_status"] = "verified_benchmark_direct"
        normalized["source_unit"] = f"current-901:{evaluation['working_key']}"
        wave2_selected_records.append(normalized)
    fresh_records.extend(wave2_selected_records)
    wave3_evaluations = list(wave3.get("evaluations", []))
    wave3_selected_records = []
    for evaluation in wave3_evaluations:
        if evaluation.get("completion_credit_recommended") is not True:
            continue
        normalized = dict(evaluation)
        normalized["mapping_status"] = (
            "verified_benchmark_direct"
            if evaluation.get("candidate_status") == "candidate_found_direct"
            else "documented_ncm_direct"
        )
        normalized["source_unit"] = f"current-901:{evaluation['working_key']}"
        wave3_selected_records.append(normalized)
    fresh_records.extend(wave3_selected_records)
    wave4_evaluations = list(wave4.get("evaluations", []))
    wave4_selected_records = []
    wave4_pending_ncm_records = []
    for evaluation in wave4_evaluations:
        if (
            evaluation.get("candidate_status") == "candidate_found_direct"
            and evaluation.get("completion_credit_recommended") is True
        ):
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave4:{evaluation['working_key']}"
            wave4_selected_records.append(normalized)
        elif (
            evaluation.get("candidate_status") == "ncm_research_pending"
            and evaluation.get("completion_credit_recommended") is False
        ):
            wave4_pending_ncm_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-4 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave4_selected_records)
    wave5_evaluations = list(wave5.get("evaluations", []))
    wave5_selected_records = []
    for evaluation in wave5_evaluations:
        if (
            evaluation.get("candidate_status") == "candidate_found_direct"
            and evaluation.get("completion_credit_recommended") is True
        ):
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave5:{evaluation['working_key']}"
            wave5_selected_records.append(normalized)
        else:
            raise ValueError(f"Unexpected wave-5 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave5_selected_records)
    wave6_evaluations = list(wave6.get("evaluations", []))
    wave6_selected_records = []
    for evaluation in wave6_evaluations:
        if (
            evaluation.get("candidate_status") == "candidate_found_direct"
            and evaluation.get("completion_credit_recommended") is True
        ):
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave6:{evaluation['working_key']}"
            wave6_selected_records.append(normalized)
        else:
            raise ValueError(f"Unexpected wave-6 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave6_selected_records)
    wave7_evaluations = list(wave7.get("evaluations", []))
    wave7_selected_records = []
    for evaluation in wave7_evaluations:
        if (
            evaluation.get("candidate_status") == "candidate_found_direct"
            and evaluation.get("completion_credit_recommended") is True
        ):
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave7:{evaluation['working_key']}"
            wave7_selected_records.append(normalized)
        else:
            raise ValueError(f"Unexpected wave-7 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave7_selected_records)
    wave8_evaluations = list(wave8.get("evaluations", []))
    wave8_selected_records = []
    for evaluation in wave8_evaluations:
        if evaluation.get("completion_credit_recommended") is not True:
            raise ValueError(f"Unexpected wave-8 adjudication state for {evaluation.get('working_key')}")
        normalized = dict(evaluation)
        normalized["mapping_status"] = (
            "verified_benchmark_direct"
            if evaluation.get("candidate_status") == "candidate_found_direct"
            else "documented_ncm_direct"
        )
        normalized["source_unit"] = f"fresh-902-wave8:{evaluation['working_key']}"
        wave8_selected_records.append(normalized)
    fresh_records.extend(wave8_selected_records)
    wave9_evaluations = list(wave9.get("evaluations", []))
    wave9_selected_records = []
    for evaluation in wave9_evaluations:
        if (
            evaluation.get("candidate_status") == "candidate_found_direct"
            and evaluation.get("completion_credit_recommended") is True
        ):
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave9:{evaluation['working_key']}"
            wave9_selected_records.append(normalized)
        else:
            raise ValueError(f"Unexpected wave-9 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave9_selected_records)
    wave10_evaluations = list(wave10.get("evaluations", []))
    wave10_selected_records = []
    for evaluation in wave10_evaluations:
        if (
            evaluation.get("candidate_status") == "candidate_found_direct"
            and evaluation.get("completion_credit_recommended") is True
        ):
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave10:{evaluation['working_key']}"
            wave10_selected_records.append(normalized)
        else:
            raise ValueError(f"Unexpected wave-10 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave10_selected_records)
    wave11_evaluations = list(wave11.get("evaluations", []))
    wave11_selected_records = []
    for evaluation in wave11_evaluations:
        if (
            evaluation.get("candidate_status") == "candidate_found_direct"
            and evaluation.get("completion_credit_recommended") is True
        ):
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave11:{evaluation['working_key']}"
            wave11_selected_records.append(normalized)
        else:
            raise ValueError(f"Unexpected wave-11 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave11_selected_records)
    wave12_evaluations = list(wave12.get("evaluations", []))
    wave12_selected_records = []
    for evaluation in wave12_evaluations:
        if evaluation.get("completion_credit_recommended") is not True:
            raise ValueError(f"Unexpected wave-12 adjudication state for {evaluation.get('working_key')}")
        normalized = dict(evaluation)
        normalized["mapping_status"] = (
            "verified_benchmark_direct"
            if evaluation.get("candidate_status") == "candidate_found_direct"
            else "documented_ncm_direct"
        )
        normalized["source_unit"] = f"fresh-902-wave12:{evaluation['working_key']}"
        wave12_selected_records.append(normalized)
    fresh_records.extend(wave12_selected_records)
    wave13_evaluations = list(wave13.get("evaluations", []))
    wave13_selected_records = []
    for evaluation in wave13_evaluations:
        if evaluation.get("completion_credit_recommended") is not True:
            raise ValueError(f"Unexpected wave-13 adjudication state for {evaluation.get('working_key')}")
        normalized = dict(evaluation)
        normalized["mapping_status"] = (
            "verified_benchmark_direct"
            if evaluation.get("candidate_status") == "candidate_found_direct"
            else "documented_ncm_direct"
        )
        normalized["source_unit"] = f"fresh-902-wave13:{evaluation['working_key']}"
        wave13_selected_records.append(normalized)
    fresh_records.extend(wave13_selected_records)
    wave14_evaluations = list(wave14.get("evaluations", []))
    wave14_selected_records = []
    wave14_retained_unproved_records = []
    for evaluation in wave14_evaluations:
        if (
            evaluation.get("candidate_status") == "candidate_found_direct"
            and evaluation.get("completion_credit_recommended") is True
        ):
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave14:{evaluation['working_key']}"
            wave14_selected_records.append(normalized)
        elif (
            evaluation.get("candidate_status") == "retained_unproved_after_target_specific_review"
            and evaluation.get("completion_credit_recommended") is False
        ):
            wave14_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-14 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave14_selected_records)
    wave16_evaluations = list(wave16.get("evaluations", []))
    wave16_selected_records = []
    wave16_retained_unproved_records = []
    wave16_repositories = {
        str(record.get("repo")): record
        for record in (wave16.get("repository_snapshots") or {}).values()
        if str(record.get("repo", "")).strip()
    }
    for evaluation in wave16_evaluations:
        if (
            evaluation.get("candidate_status") == "verified_benchmark_direct_recommended"
            and evaluation.get("completion_credit_recommended") is True
        ):
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [
                record for repo, record in wave16_repositories.items() if repo in candidate
            ]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-16 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave16:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {
                "official_repository_url": repository.get("official_repository_url"),
                "commit_sha": repository.get("commit_sha"),
                "proven_slice": evaluation.get("reason"),
            }
            wave16_selected_records.append(normalized)
        elif (
            evaluation.get("candidate_status") == "retained_unproved"
            and evaluation.get("completion_credit_recommended") is False
        ):
            wave16_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-16 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave16_selected_records)
    wave17_evaluations = list(wave17.get("evaluations", []))
    wave17_selected_records = []
    wave17_retained_unproved_records = []
    wave17_repositories = {
        str(record.get("repo")): record
        for record in (wave17.get("repository_snapshots") or {}).values()
        if str(record.get("repo", "")).strip()
    }
    for evaluation in wave17_evaluations:
        if (
            evaluation.get("candidate_status") == "verified_benchmark_direct_recommended"
            and evaluation.get("completion_credit_recommended") is True
        ):
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [
                record for repo, record in wave17_repositories.items() if repo in candidate
            ]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-17 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave17:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {
                "official_repository_url": repository.get("official_repository_url"),
                "commit_sha": repository.get("commit_sha"),
                "proven_slice": evaluation.get("reason"),
            }
            wave17_selected_records.append(normalized)
        elif (
            evaluation.get("candidate_status") == "retained_unproved"
            and evaluation.get("completion_credit_recommended") is False
        ):
            wave17_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-17 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave17_selected_records)
    wave18_evaluations = list(wave18.get("evaluations", []))
    wave18_selected_records = []
    wave18_retained_unproved_records = []
    wave18_repositories = {
        str(record.get("repo")): record
        for record in (wave18.get("repository_snapshots") or {}).values()
        if str(record.get("repo", "")).strip()
    }
    for evaluation in wave18_evaluations:
        if (
            evaluation.get("candidate_status") == "verified_benchmark_direct_recommended"
            and evaluation.get("completion_credit_recommended") is True
        ):
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [
                record for repo, record in wave18_repositories.items() if repo in candidate
            ]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-18 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave18:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {
                "official_repository_url": repository.get("official_repository_url"),
                "commit_sha": repository.get("commit_sha"),
                "proven_slice": evaluation.get("reason"),
            }
            wave18_selected_records.append(normalized)
        elif (
            evaluation.get("candidate_status") == "retained_unproved"
            and evaluation.get("completion_credit_recommended") is False
        ):
            wave18_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-18 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave18_selected_records)
    wave19_evaluations = list(wave19.get("evaluations", []))
    wave19_selected_records = []
    wave19_retained_unproved_records = []
    wave19_repositories = {str(record.get("repo")): record for record in (wave19.get("repository_snapshots") or {}).values() if str(record.get("repo", "")).strip()}
    for evaluation in wave19_evaluations:
        if evaluation.get("candidate_status") == "verified_benchmark_direct_recommended" and evaluation.get("completion_credit_recommended") is True:
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [record for repo, record in wave19_repositories.items() if repo in candidate]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-19 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave19:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {"official_repository_url": repository.get("official_repository_url"), "commit_sha": repository.get("commit_sha"), "proven_slice": evaluation.get("reason")}
            wave19_selected_records.append(normalized)
        elif evaluation.get("candidate_status") == "retained_unproved" and evaluation.get("completion_credit_recommended") is False:
            wave19_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-19 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave19_selected_records)
    wave20_evaluations = list(wave20.get("evaluations", []))
    wave20_selected_records = []
    wave20_retained_unproved_records = []
    wave20_repositories = {str(record.get("repo")): record for record in (wave20.get("repository_snapshots") or {}).values() if str(record.get("repo", "")).strip()}
    for evaluation in wave20_evaluations:
        if evaluation.get("candidate_status") == "verified_benchmark_direct_recommended" and evaluation.get("completion_credit_recommended") is True:
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [record for repo, record in wave20_repositories.items() if repo in candidate]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-20 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave20:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {"official_repository_url": repository.get("official_repository_url"), "commit_sha": repository.get("commit_sha"), "proven_slice": evaluation.get("reason")}
            wave20_selected_records.append(normalized)
        elif evaluation.get("candidate_status") == "retained_unproved" and evaluation.get("completion_credit_recommended") is False:
            wave20_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-20 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave20_selected_records)
    wave21_evaluations = list(wave21.get("evaluations", []))
    wave21_selected_records = []
    wave21_retained_unproved_records = []
    wave21_repositories = {str(record.get("repo")): record for record in (wave21.get("repository_snapshots") or {}).values() if str(record.get("repo", "")).strip()}
    for evaluation in wave21_evaluations:
        if evaluation.get("candidate_status") == "verified_benchmark_direct_recommended" and evaluation.get("completion_credit_recommended") is True:
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [record for repo, record in wave21_repositories.items() if repo in candidate]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-21 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave21:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {"official_repository_url": repository.get("official_repository_url"), "commit_sha": repository.get("commit_sha"), "proven_slice": evaluation.get("reason")}
            wave21_selected_records.append(normalized)
        elif evaluation.get("candidate_status") == "retained_unproved" and evaluation.get("completion_credit_recommended") is False:
            wave21_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-21 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave21_selected_records)
    wave22_evaluations = list(wave22.get("evaluations", []))
    wave22_selected_records = []
    wave22_retained_unproved_records = []
    wave22_repositories = {str(record.get("repo")): record for record in (wave22.get("repository_snapshots") or {}).values() if str(record.get("repo", "")).strip()}
    for evaluation in wave22_evaluations:
        if evaluation.get("candidate_status") == "verified_benchmark_direct_recommended" and evaluation.get("completion_credit_recommended") is True:
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [record for repo, record in wave22_repositories.items() if repo in candidate]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-22 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave22:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {"official_repository_url": repository.get("official_repository_url"), "commit_sha": repository.get("commit_sha"), "proven_slice": evaluation.get("reason")}
            wave22_selected_records.append(normalized)
        elif evaluation.get("candidate_status") == "retained_unproved" and evaluation.get("completion_credit_recommended") is False:
            wave22_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-22 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave22_selected_records)
    wave23_evaluations = list(wave23.get("evaluations", []))
    wave23_selected_records = []
    wave23_retained_unproved_records = []
    wave23_repositories = {str(record.get("repo")): record for record in (wave23.get("repository_snapshots") or {}).values() if str(record.get("repo", "")).strip()}
    for evaluation in wave23_evaluations:
        if evaluation.get("candidate_status") == "verified_benchmark_direct_recommended" and evaluation.get("completion_credit_recommended") is True:
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [record for repo, record in wave23_repositories.items() if repo in candidate]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-23 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave23:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {"official_repository_url": repository.get("official_repository_url"), "commit_sha": repository.get("commit_sha"), "proven_slice": evaluation.get("reason")}
            wave23_selected_records.append(normalized)
        elif evaluation.get("candidate_status") == "retained_unproved" and evaluation.get("completion_credit_recommended") is False:
            wave23_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-23 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave23_selected_records)
    wave24_evaluations = list(wave24.get("evaluations", []))
    wave24_selected_records = []
    wave24_retained_unproved_records = []
    wave24_repositories = {str(record.get("repo")): record for record in (wave24.get("repository_snapshots") or {}).values() if str(record.get("repo", "")).strip()}
    for evaluation in wave24_evaluations:
        if evaluation.get("candidate_status") == "verified_benchmark_direct_recommended" and evaluation.get("completion_credit_recommended") is True:
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [record for repo, record in wave24_repositories.items() if repo in candidate]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-24 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave24:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {"official_repository_url": repository.get("official_repository_url"), "commit_sha": repository.get("commit_sha"), "proven_slice": evaluation.get("reason")}
            wave24_selected_records.append(normalized)
        elif evaluation.get("candidate_status") == "retained_unproved" and evaluation.get("completion_credit_recommended") is False:
            wave24_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-24 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave24_selected_records)
    wave27_evaluations = list(wave27.get("evaluations", []))
    wave27_selected_records = []
    wave27_retained_unproved_records = []
    wave27_repositories = {str(record.get("repo")): record for record in (wave27.get("repository_snapshots") or {}).values() if str(record.get("repo", "")).strip()}
    for evaluation in wave27_evaluations:
        if evaluation.get("candidate_status") == "verified_benchmark_direct_recommended" and evaluation.get("completion_credit_recommended") is True:
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [record for repo, record in wave27_repositories.items() if repo in candidate]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-27 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave27:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {"official_repository_url": repository.get("official_repository_url"), "commit_sha": repository.get("commit_sha"), "proven_slice": evaluation.get("reason")}
            wave27_selected_records.append(normalized)
        elif evaluation.get("candidate_status") == "retained_unproved" and evaluation.get("completion_credit_recommended") is False:
            wave27_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-27 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave27_selected_records)
    wave28_evaluations = list(wave28.get("evaluations", []))
    wave28_selected_records = []
    wave28_retained_unproved_records = []
    wave28_repositories = {str(record.get("repo")): record for record in (wave28.get("repository_snapshots") or {}).values() if str(record.get("repo", "")).strip()}
    for evaluation in wave28_evaluations:
        if evaluation.get("candidate_status") == "verified_benchmark_direct_recommended" and evaluation.get("completion_credit_recommended") is True:
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [record for repo, record in wave28_repositories.items() if repo in candidate]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-28 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave28:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {"official_repository_url": repository.get("official_repository_url"), "commit_sha": repository.get("commit_sha"), "proven_slice": evaluation.get("reason")}
            wave28_selected_records.append(normalized)
        elif evaluation.get("candidate_status") == "retained_unproved" and evaluation.get("completion_credit_recommended") is False:
            wave28_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-28 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave28_selected_records)
    wave30_evaluations = list(wave30.get("evaluations", []))
    wave30_selected_records = []
    wave30_retained_unproved_records = []
    wave30_repositories = {str(record.get("repo")): record for record in (wave30.get("repository_snapshots") or {}).values() if str(record.get("repo", "")).strip()}
    for evaluation in wave30_evaluations:
        if evaluation.get("candidate_status") == "verified_benchmark_direct_recommended" and evaluation.get("completion_credit_recommended") is True:
            candidate = str(evaluation.get("research_candidate", ""))
            matching_repositories = [record for repo, record in wave30_repositories.items() if repo in candidate]
            if len(matching_repositories) != 1:
                raise ValueError(f"Wave-30 repository resolution is not unique for {evaluation.get('working_key')}")
            repository = matching_repositories[0]
            locus = evaluation.get("evidence_loci")
            normalized = dict(evaluation)
            normalized["mapping_status"] = "verified_benchmark_direct"
            normalized["source_unit"] = f"fresh-902-wave30:{evaluation['working_key']}"
            normalized["evidence_loci"] = [locus.strip()] if isinstance(locus, str) and locus.strip() else unique_strings(as_list(locus))
            normalized["benchmark"] = {"official_repository_url": repository.get("official_repository_url"), "commit_sha": repository.get("commit_sha"), "proven_slice": evaluation.get("reason")}
            wave30_selected_records.append(normalized)
        elif evaluation.get("candidate_status") == "retained_unproved" and evaluation.get("completion_credit_recommended") is False:
            wave30_retained_unproved_records.append(dict(evaluation))
        else:
            raise ValueError(f"Unexpected wave-30 adjudication state for {evaluation.get('working_key')}")
    fresh_records.extend(wave30_selected_records)
    fresh_by_key = {str(record["working_key"]): record for record in fresh_records}
    if len(wave2_evaluations) != 15 or len(wave2_selected_records) != 5:
        raise ValueError("Wave-2 adjudication must contain 15 evaluations and five selected rows")
    if len(wave3_evaluations) != 12 or len(wave3_selected_records) != 12:
        raise ValueError("Wave-3 adjudication must contain 12 evaluated and selected rows")
    if any(record.get("mapping_status") != "verified_benchmark_direct" for record in wave3_selected_records):
        raise ValueError("All independently reviewed wave-3 rows must be verified-benchmark direct")
    if len(wave4_evaluations) != 24 or len(wave4_selected_records) != 12 or len(wave4_pending_ncm_records) != 12:
        raise ValueError("Wave-4 must contain 12 selected direct rows and 12 uncredited pending NCM rows")
    if any(record.get("mapping_status") != "verified_benchmark_direct" for record in wave4_selected_records):
        raise ValueError("All accepted wave-4 rows must be verified-benchmark direct")
    if len(wave5_evaluations) != 12 or len(wave5_selected_records) != 12:
        raise ValueError("Wave-5 must contain 12 selected direct rows")
    if any(record.get("mapping_status") != "verified_benchmark_direct" for record in wave5_selected_records):
        raise ValueError("All accepted wave-5 rows must be verified-benchmark direct")
    if len(wave6_evaluations) != 12 or len(wave6_selected_records) != 12:
        raise ValueError("Wave-6 must contain 12 selected direct rows")
    if len(wave7_evaluations) != 12 or len(wave7_selected_records) != 12:
        raise ValueError("Wave-7 must contain 12 selected direct rows")
    if len(wave8_evaluations) != 12 or len(wave8_selected_records) != 12:
        raise ValueError("Wave-8 must contain 12 selected rows")
    if Counter(record.get("mapping_status") for record in wave8_selected_records) != Counter({
        "verified_benchmark_direct": 10,
        "documented_ncm_direct": 2,
    }):
        raise ValueError("Wave-8 must contain ten direct benchmark rows and two documented NCM rows")
    if len(wave9_evaluations) != 12 or len(wave9_selected_records) != 12:
        raise ValueError("Wave-9 must contain twelve selected direct rows")
    if any(record.get("mapping_status") != "verified_benchmark_direct" for record in wave9_selected_records):
        raise ValueError("All accepted wave-9 rows must be verified-benchmark direct")
    if len(wave10_evaluations) != 12 or len(wave10_selected_records) != 12:
        raise ValueError("Wave-10 must contain twelve selected direct rows")
    if any(record.get("mapping_status") != "verified_benchmark_direct" for record in wave10_selected_records):
        raise ValueError("All accepted wave-10 rows must be verified-benchmark direct")
    if len(wave11_evaluations) != 12 or len(wave11_selected_records) != 12:
        raise ValueError("Wave-11 must contain twelve selected direct rows")
    if any(record.get("mapping_status") != "verified_benchmark_direct" for record in wave11_selected_records):
        raise ValueError("All accepted wave-11 rows must be verified-benchmark direct")
    if len(wave12_evaluations) != 12 or len(wave12_selected_records) != 12:
        raise ValueError("Wave-12 must contain twelve selected rows")
    if Counter(record.get("mapping_status") for record in wave12_selected_records) != Counter({
        "verified_benchmark_direct": 8,
        "documented_ncm_direct": 4,
    }):
        raise ValueError("Wave-12 must contain eight direct benchmark rows and four documented NCM rows")
    if len(wave13_evaluations) != 12 or len(wave13_selected_records) != 12:
        raise ValueError("Wave-13 must contain twelve selected rows")
    if Counter(record.get("mapping_status") for record in wave13_selected_records) != Counter({
        "verified_benchmark_direct": 11,
        "documented_ncm_direct": 1,
    }):
        raise ValueError("Wave-13 must contain eleven direct benchmark rows and one documented NCM row")
    if (
        len(wave14_evaluations) != 12
        or len(wave14_selected_records) != 4
        or len(wave14_retained_unproved_records) != 8
    ):
        raise ValueError("Wave-14 must contain four selected direct rows and eight retained-unproved rows")
    if any(record.get("mapping_status") != "verified_benchmark_direct" for record in wave14_selected_records):
        raise ValueError("All accepted wave-14 rows must be verified-benchmark direct")
    wave15_evaluations = list(wave15.get("evaluations", []))
    if len(wave15_evaluations) != 12 or any(
        record.get("candidate_status") != "retained_unproved"
        or record.get("completion_credit_recommended") is not False
        or benchmark_by_key.get(str(record.get("working_key")), {}).get("completion_credit") is not False
        for record in wave15_evaluations
    ):
        raise ValueError("Wave-15 must contain exactly twelve zero-credit retained-unproved rows")
    if len(wave16_evaluations) != 7 or len(wave16_selected_records) != 4 or len(wave16_retained_unproved_records) != 3:
        raise ValueError("Wave-16 must contain four direct and three retained-unproved rows")
    if len(wave17_evaluations) != 8 or len(wave17_selected_records) != 2 or len(wave17_retained_unproved_records) != 6:
        raise ValueError("Wave-17 must contain two direct and six retained-unproved rows")
    if len(wave18_evaluations) != 8 or len(wave18_selected_records) != 1 or len(wave18_retained_unproved_records) != 7:
        raise ValueError("Wave-18 must contain one direct and seven retained-unproved rows")
    if len(wave19_evaluations) != 8 or len(wave19_selected_records) != 2 or len(wave19_retained_unproved_records) != 6:
        raise ValueError("Wave-19 must contain two direct and six retained-unproved rows")
    if len(wave20_evaluations) != 8 or len(wave20_selected_records) != 1 or len(wave20_retained_unproved_records) != 7:
        raise ValueError("Wave-20 must contain one direct and seven retained-unproved rows")
    if len(wave21_evaluations) != 8 or len(wave21_selected_records) != 3 or len(wave21_retained_unproved_records) != 5:
        raise ValueError("Wave-21 must contain three direct and five retained-unproved rows")
    if len(wave22_evaluations) != 8 or len(wave22_selected_records) != 4 or len(wave22_retained_unproved_records) != 4:
        raise ValueError("Wave-22 must contain four direct and four retained-unproved rows")
    if len(wave23_evaluations) != 8 or len(wave23_selected_records) != 3 or len(wave23_retained_unproved_records) != 5:
        raise ValueError("Wave-23 must contain three direct and five retained-unproved rows")
    if len(wave24_evaluations) != 8 or len(wave24_selected_records) != 1 or len(wave24_retained_unproved_records) != 7:
        raise ValueError("Wave-24 must contain one direct and seven retained-unproved rows")
    if len(wave27_evaluations) != 8 or len(wave27_selected_records) != 2 or len(wave27_retained_unproved_records) != 6:
        raise ValueError("Wave-27 must contain two direct and six retained-unproved rows")
    if len(wave28_evaluations) != 8 or len(wave28_selected_records) != 3 or len(wave28_retained_unproved_records) != 5:
        raise ValueError("Wave-28 must contain three direct and five retained-unproved rows")
    if len(wave30_evaluations) != 8 or len(wave30_selected_records) != 1 or len(wave30_retained_unproved_records) != 7:
        raise ValueError("Wave-30 must contain one direct and seven retained-unproved rows")
    if any(record.get("mapping_status") != "verified_benchmark_direct" for record in wave6_selected_records):
        raise ValueError("All accepted wave-6 rows must be verified-benchmark direct")
    if len(fresh_records) != len(fresh_by_key) or len(fresh_records) != 188:
        raise ValueError("Combined fresh target-specific adjudication must contain 188 unique selected rows")
    fresh_repos = {
        str(repo_id): record for repo_id, record in (fresh.get("repository_snapshots") or {}).items()
    }

    selected_records = list(selected.get("adjudications", []))
    selected_by_id: dict[str, dict[str, Any]] = {}
    selected_supported_by_id: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for record in selected_records:
        selected_id = str(record["feature_id"])
        if selected_id in selected_by_id:
            raise ValueError(f"Duplicate selected adjudication ID {selected_id}")
        selected_by_id[selected_id] = record
        for projected_id in unique_strings(
            as_list(
                (record.get("projected_capability_adjudication") or {}).get(
                    "benchmark_supported_capability_ids"
                )
            )
        ):
            selected_supported_by_id[projected_id].append(record)
    ambiguous_selected_targets = sorted(
        target_id
        for target_id, records in selected_supported_by_id.items()
        if len(records) != 1
    )
    if ambiguous_selected_targets:
        raise ValueError(
            f"Ambiguous selected projected-target records: {ambiguous_selected_targets}"
        )

    ncm_records = list(ncm.get("projected_unit_adjudications", []))
    ncm_by_id = {
        str(record["adjudication_unit_id"]): record for record in ncm_records
    }
    if len(ncm_by_id) != len(ncm_records):
        raise ValueError("Duplicate NCM adjudication unit IDs")
    ncm_repo_records = list(ncm.get("repository_snapshots", []))
    ncm_repos = {str(record["id"]): record for record in ncm_repo_records}
    if len(ncm_repos) != len(ncm_repo_records):
        raise ValueError("Duplicate NCM repository snapshot IDs")
    ncm_locus_records = list(ncm.get("exact_source_loci", []))
    ncm_loci = {str(record["id"]): record for record in ncm_locus_records}
    if len(ncm_loci) != len(ncm_locus_records):
        raise ValueError("Duplicate NCM exact source locus IDs")

    findings_by_feature: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for finding in findings.get("findings", []):
        for feature_id in unique_strings(as_list(finding.get("feature_ids"))):
            findings_by_feature[feature_id].append(finding)

    ledger_rows: list[dict[str, Any]] = []
    matrix_rows: list[dict[str, Any]] = []
    missing_route_ids: set[str] = set()
    missing_page_ids: set[str] = set()
    source_envelope_targets = 0
    exact_finding_links = 0
    exact_finding_targets = 0
    official_source_finding_targets = 0
    p5_reviewed = 0
    p7_reviewed = 0
    benchmark_resolution_counts: Counter[str] = Counter()

    for target in targets:
        working_key = str(target["working_key"])
        feature_id = str(target["id"])
        module_key = str(target["canonical_module"])
        module = module_names.get(module_key, module_key)
        target_class = str(target["class"])
        source_families = unique_strings(as_list(target.get("source_family_ids")))
        route_ids = unique_strings(as_list(target.get("route_ids")))
        page_ids = unique_strings(as_list(target.get("page_ids")))
        manifest_backend = unique_strings(as_list(target.get("backend_anchors")))

        route_records = []
        for route_id in route_ids:
            route = routes_by_id.get(route_id)
            if route is None:
                missing_route_ids.add(route_id)
            else:
                route_records.append(route)
        page_records = []
        for page_id in page_ids:
            page = pages_by_id.get(page_id)
            if page is None:
                missing_page_ids.add(page_id)
            else:
                page_records.append(page)

        route_names = semicolon(
            str(route.get("name") or f"[unnamed {route['route_id']}]") for route in route_records
        )
        route_paths = semicolon(
            f"{route.get('method', '')} {route.get('uri', '')} [{route['route_id']}]" for route in route_records
        )
        page_files = semicolon(
            f"{page.get('file', '')} [{page['page_id']}]" for page in page_records
        )
        exact_backend = unique_strings(
            manifest_backend
            + [
                f"{route.get('action')} [via exact target route {route['route_id']}]"
                for route in route_records
                if route.get("action")
            ]
        )

        envelope_rows: list[dict[str, Any]] = []
        seen_envelope_objects: set[int] = set()
        for family_id in source_families:
            for feature in family_index.get(family_id, []):
                marker = id(feature)
                if marker not in seen_envelope_objects:
                    seen_envelope_objects.add(marker)
                    envelope_rows.append(feature)
        envelope_routes = unique_strings(
            route_id for feature in envelope_rows for route_id in as_list(feature.get("route_ids"))
        )
        envelope_pages = unique_strings(
            page_id for feature in envelope_rows for page_id in as_list(feature.get("page_ids"))
        )
        envelope_anchors = unique_strings(
            feature.get("backend_anchor", "") for feature in envelope_rows
        )
        if envelope_rows:
            source_envelope_targets += 1
        envelope_summary = (
            "Source-family inventory envelope (discovery context only; never exclusive target ownership): "
            f"families={semicolon(source_families) or 'none'}; legacy_rows={len(envelope_rows)}; "
            f"routes={len(envelope_routes)}; pages={len(envelope_pages)}; backend_anchors={len(envelope_anchors)}."
        )

        exact_findings = sorted(findings_by_feature.get(feature_id, []), key=lambda value: str(value.get("id", "")))
        finding_ids = unique_strings(finding.get("id", "") for finding in exact_findings)
        if finding_ids:
            exact_finding_targets += 1
            exact_finding_links += len(finding_ids)
        has_official_source_finding = any(
            as_list(finding.get("official_sources")) or as_list(finding.get("official_source_proposition_keys"))
            for finding in exact_findings
        )
        if has_official_source_finding:
            official_source_finding_targets += 1

        benchmark_row = benchmark_by_key.get(working_key) if benchmark_by_key else None
        bench = benchmark_detail(
            benchmark_row,
            selected_by_id,
            selected_supported_by_id,
            ncm_by_id,
            ncm_repos,
            ncm_loci,
            fresh_by_key,
            fresh_repos,
        )
        benchmark_resolution_counts[str(bench["resolution"])] += 1

        p1 = (
            "Reviewed—stable corrected working target identity and discovery envelope reconciled; "
            f"target-supported exact enrichment routes={len(route_ids)}, pages={len(page_ids)}, "
            f"backend_anchors={len(manifest_backend)}. Empty fields mean unenriched, never no route/page."
        )
        p2 = (
            "Blocked—target-specific representative-role task and persisted outcome were not executed as part of the audit; "
            "later focused remediation tests do not count as canonical task execution."
        )
        p3 = str(bench["P3"])
        p4 = (
            "Blocked—representative-role happy, error, recovery, handoff, responsive and accessibility states "
            "were not safely executed for this target."
        )
        if route_ids or page_ids or manifest_backend:
            p5 = (
                "Reviewed—static target-supported route/page/backend anchors recorded; architecture, data, "
                "integration effects and runtime completion remain unverified."
            )
            p5_reviewed += 1
        else:
            p5 = (
                "Blocked—only stable identity/source-family discovery context is integrated; target-specific "
                "architecture, data and integration anchors remain unenriched."
            )
        if has_official_source_finding:
            p6 = (
                "Reviewed (source only)—an exact target-linked finding carries official proposition evidence; "
                "legal/clinical applicability and representative role/site/direct-object runtime remain blocked."
            )
        else:
            p6 = (
                "Blocked—target-specific official-source applicability and representative role/site/direct-object "
                "runtime evidence are not linked."
            )
        if finding_ids:
            p7 = (
                "Reviewed (source only)—exact target-linked finding evidence records constraints/failure modes; "
                "tests were not executed and target-wide test sufficiency is unproved."
            )
            p7_reviewed += 1
        else:
            p7 = (
                "Blocked—target-specific tests, constraints and failure-mode evidence are not linked; the complete "
                "audit-wide test denominator was not executed as one controlled gate."
            )
        benchmark_status = str(benchmark_row.get("status")) if benchmark_row else "mapping_artifact_absent"
        p8 = (
            "Reviewed (static challenge)—target survived the corrected 902 identity/count reconciliation; "
            f"id_status={target.get('id_status')}, benchmark_status={benchmark_status}. "
            "No runtime or audit-completion claim follows."
        )

        exact_evidence_count = (
            1
            + len(route_ids)
            + len(page_ids)
            + len(manifest_backend)
            + len(finding_ids)
            + (1 if bench["completion_credit"] else 0)
        )
        gaps = []
        if not route_ids:
            gaps.append("target route enrichment unavailable")
        if not page_ids:
            gaps.append("target page enrichment unavailable")
        if not manifest_backend:
            gaps.append("target backend-anchor enrichment unavailable")
        gaps.append("representative-role runtime task/state evidence blocked")
        if not bench["completion_credit"]:
            gaps.append("P3 benchmark/no-match completion unproved")
        if not finding_ids:
            gaps.append("no exact target-linked finding")

        ledger_rows.append(
            {
                "feature_id": feature_id,
                "working_key": working_key,
                "class": target_class,
                "module": module,
                "submodule": f"{working_key} — stable working target",
                "source_family_inventory_envelope": envelope_summary,
                "P1_status": p1,
                "P2_status": p2,
                "P3_status": p3,
                "P4_status": p4,
                "P5_status": p5,
                "P6_status": p6,
                "P7_status": p7,
                "P8_status": p8,
                "agent_assignments": agent_reconciliation,
                "evidence_count": str(exact_evidence_count),
                "gaps": semicolon(gaps),
                "reconciliation_status": (
                    "Corrected 902-target ledger row generated; static evidence only; audit completion not claimed"
                ),
            }
        )

        exact_summary = (
            f"Target-supported exact enrichment: routes={len(route_ids)}, pages={len(page_ids)}, "
            f"manifest_backend_anchors={len(manifest_backend)}. Empty exact fields mean unenriched, not none. "
            f"{envelope_summary} Representative-role runtime completion was not executed."
        )
        matrix_rows.append(
            {
                "feature_id": feature_id,
                "module": module,
                "submodule": f"{working_key} — stable working target ({target_class})",
                "owning_actor": "Unresolved—target-specific actor not established by the corrected static manifest",
                "secondary_actors": "Unresolved—representative role/site variants unavailable",
                "user_job": f"Static capability target {working_key}; representative-role job completion not executed",
                "criticality": "Unresolved—product/domain owner decision required",
                "navigation_entry": "Not established—exact route enrichment is not navigation/menu proof",
                "route_names": route_names,
                "route_paths": route_paths,
                "page_files": page_files,
                "backend_anchors": semicolon(exact_backend),
                "current_states": "Not established—route verbs are not workflow-state proof",
                "current_workflow_summary": exact_summary,
                "benchmark_candidates": str(bench["candidates"]),
                "selected_open_source_benchmark": str(bench["selected"]),
                "benchmark_url_and_sha": str(bench["url_sha"]),
                "verified_behaviour": str(bench["verified_behaviour"]),
                "neutral_requirements_extracted": str(bench["neutral_requirement"]),
                "no_match_evidence": str(bench["no_match"]),
                "current_ease_score": "Not measured—representative-role runtime blocked",
                "target_ease_score": "Not scored—owner/user validation required",
                "P1": p1,
                "P2": p2,
                "P3": p3,
                "P4": p4,
                "P5": p5,
                "P6": p6,
                "P7": p7,
                "P8": p8,
                "finding_ids": semicolon(finding_ids),
                "confidence": str(bench["confidence"]),
            }
        )

    if missing_route_ids or missing_page_ids:
        raise ValueError(
            f"Manifest enrichment failed inventory lookup: routes={sorted(missing_route_ids)}, "
            f"pages={sorted(missing_page_ids)}"
        )

    mapped_p3 = sum(
        1 for target in targets if benchmark_by_key.get(str(target["working_key"]), {}).get("completion_credit")
    )
    if benchmark is not None and mapped_p3 != 451:
        raise ValueError(f"Expected 451 final P3 completion-credit rows, found {mapped_p3}")

    write_csv(LEDGER_PATH, LEDGER_HEADERS, ledger_rows)
    write_csv(MATRIX_PATH, MATRIX_HEADERS, matrix_rows)

    # Re-parse outputs before reporting success.
    with LEDGER_PATH.open("r", encoding="utf-8", newline="") as handle:
        parsed_ledger = list(csv.DictReader(handle))
        parsed_ledger_headers = list(parsed_ledger[0].keys()) if parsed_ledger else []
    with MATRIX_PATH.open("r", encoding="utf-8", newline="") as handle:
        parsed_matrix = list(csv.DictReader(handle))
        parsed_matrix_headers = list(parsed_matrix[0].keys()) if parsed_matrix else []

    if len(parsed_ledger) != 902 or len(parsed_matrix) != 902:
        raise ValueError("Generated CSVs must each contain exactly 902 data rows")
    if parsed_ledger_headers != LEDGER_HEADERS or parsed_matrix_headers != MATRIX_HEADERS:
        raise ValueError("Generated CSV headers differ from the declared schemas")
    if len({row["feature_id"] for row in parsed_ledger}) != 902:
        raise ValueError("Ledger feature IDs are not unique")
    if len({row["feature_id"] for row in parsed_matrix}) != 902:
        raise ValueError("Matrix feature IDs are not unique")
    if [row["feature_id"] for row in parsed_ledger] != feature_ids:
        raise ValueError("Ledger target order does not equal deterministic manifest key order")
    if [row["feature_id"] for row in parsed_matrix] != feature_ids:
        raise ValueError("Matrix target order does not equal deterministic manifest key order")

    benchmark_field_validation = {
        "verified_rows": 0,
        "verified_rows_with_complete_required_fields": 0,
        "documented_ncm_rows": 0,
        "documented_ncm_rows_with_target_explicit_evidence": 0,
        "completion_unproved_rows_with_no_accidental_completion_fields": 0,
    }
    if benchmark is not None:
        expected_resolution_counts = Counter(
            {
                "selected_direct": 39,
                "selected_projected_supported": 20,
                "ncm_candidate_found": 131,
                "fresh_target_specific_verified": 172,
                "documented_ncm": 75,
                "fresh_target_specific_documented_ncm": 14,
                "unproved": 451,
            }
        )
        if benchmark_resolution_counts != expected_resolution_counts:
            raise ValueError(
                "Final benchmark join resolution counts differ from the validated "
                f"39/20/131/172/75/14/451 partition: {dict(benchmark_resolution_counts)}"
            )

        parsed_matrix_by_id = {row["feature_id"]: row for row in parsed_matrix}
        verified_targets = [
            target
            for target in benchmark.get("targets", [])
            if target.get("completion_credit")
            and str(target.get("status", "")).startswith("verified_benchmark_")
        ]
        documented_ncm_targets = [
            target
            for target in benchmark.get("targets", [])
            if target.get("completion_credit")
            and str(target.get("status", "")).startswith("documented_ncm_")
        ]
        unproved_targets = [
            target for target in benchmark.get("targets", []) if not target.get("completion_credit")
        ]
        if (
            len(verified_targets) != 362
            or len(documented_ncm_targets) != 89
            or len(unproved_targets) != 451
        ):
            raise ValueError("Final benchmark 362/89/451 partition changed")

        verified_required_fields = (
            "selected_open_source_benchmark",
            "benchmark_url_and_sha",
            "verified_behaviour",
            "neutral_requirements_extracted",
        )
        incomplete_verified = {
            field: [
                str(target["working_key"])
                for target in verified_targets
                if not parsed_matrix_by_id[str(target["working_key"])][field].strip()
            ]
            for field in verified_required_fields
        }
        incomplete_verified = {
            field: ids for field, ids in incomplete_verified.items() if ids
        }
        if incomplete_verified:
            raise ValueError(
                f"Verified benchmark matrix fields remain blank: {incomplete_verified}"
            )

        ncm_evidence_failures = []
        for target in documented_ncm_targets:
            working_key = str(target["working_key"])
            source_units = [str(value) for value in as_list(target.get("source_units"))]
            source_id = source_units[0].partition(":")[2] if source_units else ""
            row = parsed_matrix_by_id[working_key]
            no_match = row["no_match_evidence"]
            if (
                not no_match.strip()
                or working_key not in no_match
                or not source_id
                or source_id not in no_match
                or not row["benchmark_candidates"].strip()
                or not row["benchmark_url_and_sha"].strip()
                or not row["neutral_requirements_extracted"].strip()
            ):
                ncm_evidence_failures.append(working_key)
        if ncm_evidence_failures:
            raise ValueError(
                f"NCM rows lack final-target/source-unit evidence: {ncm_evidence_failures}"
            )

        accidental_unproved_fields = []
        completion_only_fields = (
            "selected_open_source_benchmark",
            "verified_behaviour",
            "neutral_requirements_extracted",
            "no_match_evidence",
        )
        for target in unproved_targets:
            working_key = str(target["working_key"])
            row = parsed_matrix_by_id[working_key]
            if row["P3"].startswith("Mapped") or any(
                row[field].strip() for field in completion_only_fields
            ):
                accidental_unproved_fields.append(working_key)
        if accidental_unproved_fields:
            raise ValueError(
                "Completion-unproved rows received benchmark completion fields: "
                f"{accidental_unproved_fields}"
            )

        benchmark_field_validation = {
            "verified_rows": len(verified_targets),
            "verified_rows_with_complete_required_fields": len(verified_targets),
            "documented_ncm_rows": len(documented_ncm_targets),
            "documented_ncm_rows_with_target_explicit_evidence": len(documented_ncm_targets),
            "completion_unproved_rows_with_no_accidental_completion_fields": len(unproved_targets),
        }

    p3_status_counts = Counter(
        str(benchmark_by_key.get(key, {}).get("status", "mapping_artifact_absent")) for key in working_keys
    )
    module_counts: dict[str, dict[str, int]] = {}
    for module_key in sorted({str(target["canonical_module"]) for target in targets}):
        module_targets = [target for target in targets if str(target["canonical_module"]) == module_key]
        counts = Counter(str(target["class"]) for target in module_targets)
        module_counts[module_key] = {
            "total": len(module_targets),
            "H": counts.get("H", 0),
            "D": counts.get("D", 0),
            "M": counts.get("M", 0),
        }

    input_paths = [GENERATOR_PATH, MANIFEST_PATH, INVENTORY_PATH, FINDINGS_PATH, AGENT_REGISTER_PATH]
    for optional_path in (BENCHMARK_PATH, FRESH_PATH, WAVE2_PATH, WAVE3_PATH, WAVE4_PATH, WAVE5_PATH, WAVE6_PATH, WAVE7_PATH, WAVE8_PATH, WAVE8_WITHDRAWAL_PATH, WAVE9_PATH, WAVE10_PATH, WAVE11_PATH, WAVE12_PATH, WAVE13_PATH, WAVE14_PATH, WAVE15_PATH, WAVE16_PATH, WAVE17_PATH, WAVE18_PATH, WAVE19_PATH, WAVE20_PATH, WAVE21_PATH, WAVE22_PATH, WAVE23_PATH, WAVE24_PATH, WAVE27_PATH, WAVE28_PATH, WAVE30_PATH, SELECTED_PATH, NCM_PATH):
        if optional_path.exists():
            input_paths.append(optional_path)

    summary = {
        "schema_version": "1.0",
        "artifact": "final-902-ledger-generation-summary",
        "generator": GENERATOR_PATH.relative_to(AUDIT_DIR).as_posix(),
        "generator_run_at": datetime.now(timezone.utc).isoformat(),
        "audited_commit": manifest.get("audited_commit"),
        "status": "generated_902_rows_validation_passed_audit_completion_not_claimed",
        "audit_boundary": (
            "Audit-artifact-only deterministic CSV generation. No application source, routes, configuration, "
            "tests, data, deployment, Git history, or external system was changed."
        ),
        "denominator": {
            "total": 902,
            "class_counts": {key: class_counts.get(key, 0) for key in ("H", "D", "M")},
            "module_counts": module_counts,
            "working_key_equals_feature_id": True,
        },
        "inputs": [file_fingerprint(path) for path in input_paths],
        "outputs": {
            "ledger": {
                **file_fingerprint(LEDGER_PATH),
                "data_rows": len(parsed_ledger),
                "headers": LEDGER_HEADERS,
                "canonical_row_sha256": canonical_row_sha(parsed_ledger, LEDGER_HEADERS),
            },
            "matrix": {
                **file_fingerprint(MATRIX_PATH),
                "data_rows": len(parsed_matrix),
                "headers": MATRIX_HEADERS,
                "canonical_row_sha256": canonical_row_sha(parsed_matrix, MATRIX_HEADERS),
            },
        },
        "deterministic_key_checksums": {
            "ordering": "ascending ordinal working_key; working_key == feature_id",
            "working_key_lf_sha256": ordered_text_sha(working_keys),
            "feature_id_lf_sha256": ordered_text_sha(feature_ids),
            "working_key_unique_count": len(set(working_keys)),
            "feature_id_unique_count": len(set(feature_ids)),
        },
        "exact_target_enrichment": {
            "targets_with_route_ids": sum(1 for target in targets if as_list(target.get("route_ids"))),
            "unique_route_ids": len(
                {route_id for target in targets for route_id in as_list(target.get("route_ids"))}
            ),
            "targets_with_page_ids": sum(1 for target in targets if as_list(target.get("page_ids"))),
            "unique_page_ids": len(
                {page_id for target in targets for page_id in as_list(target.get("page_ids"))}
            ),
            "targets_with_manifest_backend_anchors": sum(
                1 for target in targets if as_list(target.get("backend_anchors"))
            ),
            "missing_route_inventory_lookups": sorted(missing_route_ids),
            "missing_page_inventory_lookups": sorted(missing_page_ids),
            "source_family_envelope_route_or_page_ids_used_as_exact": 0,
        },
        "source_family_inventory_envelope": {
            "purpose": "Discovery context only; never exclusive target route/page/backend ownership.",
            "inventory_feature_source": family_inventory_source,
            "inventory_feature_rows": len(family_inventory_features),
            "targets_with_at_least_one_matching_legacy_inventory_row": source_envelope_targets,
            "targets_without_matching_legacy_inventory_row": 902 - source_envelope_targets,
        },
        "benchmark_integration": {
            "artifact_present": benchmark is not None,
            "mapped_completion_credit": mapped_p3,
            "completion_unproved": 902 - mapped_p3,
            "status_counts": dict(sorted(p3_status_counts.items())),
            "resolution_counts": dict(sorted(benchmark_resolution_counts.items())),
            "field_validation": benchmark_field_validation,
            "proof_rule": (
                "Only completion_credit=true final-target rows are marked P3 Mapped; prior family, pending, "
                "reject, split, regroup, and merge evidence grants no mechanical credit."
            ),
        },
        "exact_finding_integration": {
            "targets_with_exact_feature_id_links": exact_finding_targets,
            "exact_finding_links": exact_finding_links,
            "official_source_finding_targets": official_source_finding_targets,
            "inheritance_rule": "Only finding.feature_ids exact equality with final feature_id; no family inheritance.",
        },
        "pass_status_summary": {
            "P1_static_identity_envelope_reviewed": 902,
            "P2_runtime_task_blocked": 902,
            "P3_mapped": mapped_p3,
            "P3_blocked": 902 - mapped_p3,
            "P4_runtime_visual_task_blocked": 902,
            "P5_static_target_anchors_reviewed": p5_reviewed,
            "P5_target_trace_blocked": 902 - p5_reviewed,
            "P6_official_source_link_reviewed_runtime_blocked": official_source_finding_targets,
            "P6_target_specific_source_and_runtime_blocked": 902 - official_source_finding_targets,
            "P7_exact_finding_source_reviewed_tests_not_run": p7_reviewed,
            "P7_target_specific_test_failure_trace_blocked": 902 - p7_reviewed,
            "P8_corrected_static_identity_challenge_reviewed": 902,
        },
        "validation": {
            "status": "passed",
            "assertions": [
                "Corrected manifest contains exactly 902 unique working keys and feature IDs.",
                "working_key equals feature_id for all generated rows.",
                "Manifest class totals are 788 H, 111 D, and 3 M.",
                "Both CSVs re-parse with exactly 902 data rows and declared headers.",
                "Both CSVs have 902 unique feature IDs in deterministic key order.",
                "Every manifest route/page ID used in exact fields resolves in inventory.json.",
                "No source-family inventory-envelope route/page ID is promoted into an exact target field.",
                (
                    "Source-family discovery envelopes use superseded_feature_projection.features when present; "
                    "otherwise they fall back to inventory.features."
                ),
                "Only exact final feature-ID finding links are included.",
                (
                    "All 362 verified-benchmark rows resolve through 39 selected direct, 20 selected projected-"
                    "supported, 131 NCM candidate-found and 172 fresh target-specific records, with nonblank benchmark, URL/SHA, behaviour "
                    "and neutral-requirement fields."
                    if benchmark is not None
                    else "Verified benchmark detail validation is deferred because the final mapping is absent."
                ),
                (
                    "All 89 documented-NCM rows identify both the final target and source adjudication unit and "
                    "retain nonblank candidate, URL/SHA, neutral-requirement and no-match evidence."
                    if benchmark is not None
                    else "NCM target-specific validation is deferred because the final mapping is absent."
                ),
                (
                    "All 451 completion-unproved rows remain P3 Blocked and receive no selected benchmark, "
                    "verified behaviour, neutral requirement or no-match completion field."
                    if benchmark is not None
                    else "All 902 rows remain P3 Blocked because the final mapping is absent."
                ),
                (
                    "Final benchmark mapping target set exactly equals the 902-target manifest and exactly 451 "
                    "rows receive P3 completion credit."
                    if benchmark is not None
                    else "Benchmark mapping was absent; all 902 P3 rows remain blocked and generator supports rerun."
                ),
            ],
        },
        "limitations": [
            "This generation does not establish 902 representative user jobs, actors, navigation entries, workflow states, or ease scores.",
            "Empty exact route/page/backend cells mean target enrichment is unavailable; they never prove absence.",
            "Source-family inventory envelopes are discovery context only and do not prove exclusive ownership.",
            "Only 451 final targets have P3 completion credit (362 verified benchmark, 89 bounded documented no-match); 451 remain unproved.",
            "P2/P4 runtime task and visual proof remain blocked; P6 role/site/direct-object runtime remains blocked.",
            "The complete audit-wide test denominator was not executed as one controlled gate; later focused remediation suites are tracked separately.",
            "No audit-wide completion claim follows from row generation or structural validation.",
        ],
    }
    SUMMARY_PATH.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    # Final summary self-parse; its own hash is intentionally not embedded recursively.
    load_json(SUMMARY_PATH)
    print(
        json.dumps(
            {
                "status": summary["status"],
                "ledger_rows": len(parsed_ledger),
                "matrix_rows": len(parsed_matrix),
                "class_counts": summary["denominator"]["class_counts"],
                "P3_mapped": mapped_p3,
                "P3_blocked": 902 - mapped_p3,
                "ledger_sha256": summary["outputs"]["ledger"]["sha256"],
                "matrix_sha256": summary["outputs"]["matrix"]["sha256"],
                "summary_path": SUMMARY_PATH.relative_to(AUDIT_DIR).as_posix(),
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
