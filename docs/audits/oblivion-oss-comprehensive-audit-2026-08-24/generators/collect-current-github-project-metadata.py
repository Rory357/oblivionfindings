#!/usr/bin/env python3
"""Collect a read-only official GitHub metadata snapshot for prompt repositories."""

from __future__ import annotations

import collections
import datetime as dt
import hashlib
import json
import re
import subprocess
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
ORDERED_URL_OCCURRENCE_SHA256 = "2dfd95c017a64f57b312e5869ae486b65eb50a6751276b67b3835ff2055cbb73"
ORDERED_REPOSITORY_OCCURRENCE_SHA256 = "f975b21784e8ac28f8541a17d1f3676623755a8023236599f1bdfd32188d2142"
SORTED_LOWER_OCCURRENCE_SHA256 = "a11def3fd47294297fb8aac9b327287059e063aeb58ccd2045d8afb9347f49f5"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
BATCH_SIZE = 24


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def utc_now() -> str:
    return dt.datetime.now(dt.timezone.utc).isoformat().replace("+00:00", "Z")


prompt_bytes = PROMPT_PATH.read_bytes()
assert sha256_bytes(prompt_bytes) == PROMPT_SHA256
prompt_lines = prompt_bytes.decode("utf-8").splitlines()
prompt_slice = "\n".join(prompt_lines[495:515])
urls = re.findall(r"https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", prompt_slice)
assert len(urls) == 98
assert sha256_bytes(("\n".join(urls) + "\n").encode("utf-8")) == ORDERED_URL_OCCURRENCE_SHA256
assert sha256_bytes(("\n".join(sorted(url.rstrip("/").lower() for url in urls)) + "\n").encode("utf-8")) == SORTED_LOWER_OCCURRENCE_SHA256

occurrence_repositories = [url.removeprefix("https://github.com/").rstrip("/") for url in urls]
assert sha256_bytes(("\n".join(occurrence_repositories) + "\n").encode("utf-8")) == ORDERED_REPOSITORY_OCCURRENCE_SHA256
occurrences: dict[str, list[int]] = collections.defaultdict(list)
canonical_requested_case: dict[str, str] = {}
ordered_unique_keys: list[str] = []
for index, repository in enumerate(occurrence_repositories, start=1):
    key = repository.lower()
    if key not in occurrences:
        ordered_unique_keys.append(key)
        canonical_requested_case[key] = repository
    occurrences[key].append(index)

assert len(ordered_unique_keys) == 95
assert {key: len(indices) for key, indices in occurrences.items() if len(indices) > 1} == {
    "glpi-project/glpi": 2,
    "netbox-community/netbox": 2,
    "opf/openproject": 2,
}


def graphql_batch(batch_keys: list[str], batch_number: int) -> tuple[list[dict], dict]:
    selections = []
    aliases: dict[str, str] = {}
    for offset, key in enumerate(batch_keys, start=1):
        alias = f"r{batch_number:02d}_{offset:02d}"
        aliases[alias] = key
        owner, name = canonical_requested_case[key].split("/", 1)
        selections.append(
            f'''{alias}: repository(owner: {json.dumps(owner)}, name: {json.dumps(name)}) {{
              nameWithOwner url isArchived isDisabled visibility pushedAt updatedAt
              defaultBranchRef {{ name target {{ ... on Commit {{ oid committedDate }} }} }}
              licenseInfo {{ spdxId name }}
              latestRelease {{ tagName publishedAt url }}
            }}'''
        )
    query = "query {\n" + "\n".join(selections) + "\n}"
    command = ["gh", "api", "graphql", "--include", "-f", f"query={query}"]
    started = utc_now()
    result = subprocess.run(command, cwd=AUDIT_DIR, capture_output=True, text=True, encoding="utf-8", errors="replace")
    ended = utc_now()
    if result.returncode != 0:
        raise RuntimeError(f"GitHub GraphQL batch {batch_number} failed with exit {result.returncode}: {result.stderr.strip()}")
    parts = re.split(r"\r?\n\r?\n", result.stdout, maxsplit=1)
    if len(parts) != 2:
        raise RuntimeError(f"GitHub GraphQL batch {batch_number} returned no parseable headers/body boundary")
    headers, body = parts
    payload = json.loads(body)
    errors = payload.get("errors") or []
    if errors:
        raise RuntimeError(f"GitHub GraphQL batch {batch_number} returned errors: {json.dumps(errors)}")
    request_match = re.search(r"(?im)^x-github-request-id:\s*(\S+)", headers)
    status_match = re.search(r"(?im)^HTTP/\S+\s+(\d+)", headers)
    records = []
    for alias, key in aliases.items():
        repository = payload.get("data", {}).get(alias)
        if repository is None:
            raise RuntimeError(f"GitHub GraphQL batch {batch_number} returned null for {canonical_requested_case[key]}")
        branch = repository.get("defaultBranchRef") or {}
        target = branch.get("target") or {}
        licence = repository.get("licenseInfo") or {}
        release = repository.get("latestRelease") or {}
        canonical = repository["nameWithOwner"]
        records.append(
            {
                "ordinal": ordered_unique_keys.index(key) + 1,
                "requested_repository": canonical_requested_case[key],
                "canonical_repository": canonical,
                "canonical_url": repository["url"],
                "occurrence_indices": occurrences[key],
                "occurrence_weight": len(occurrences[key]),
                "identity_equal_case_sensitive": canonical == canonical_requested_case[key],
                "archived": repository["isArchived"],
                "disabled": repository["isDisabled"],
                "visibility": repository["visibility"],
                "default_branch": branch.get("name"),
                "default_branch_head_sha": target.get("oid"),
                "default_branch_head_committed_at": target.get("committedDate"),
                "pushed_at": repository.get("pushedAt"),
                "updated_at": repository.get("updatedAt"),
                "github_license_info": {"spdx_id": licence.get("spdxId"), "name": licence.get("name")},
                "github_latest_release": {"tag": release.get("tagName"), "published_at": release.get("publishedAt"), "url": release.get("url")},
                "metadata_status": "OFFICIAL_GITHUB_METADATA_SNAPSHOT_SUCCESS",
            }
        )
    receipt = {
        "batch": batch_number,
        "row_start": min(row["ordinal"] for row in records),
        "row_end": max(row["ordinal"] for row in records),
        "rows": len(records),
        "http_status": int(status_match.group(1)) if status_match else None,
        "cli_exit_code": result.returncode,
        "graphql_error_count": len(errors),
        "github_request_id": request_match.group(1) if request_match else None,
        "started_at": started,
        "ended_at": ended,
    }
    return records, receipt


snapshot_started = utc_now()
records: list[dict] = []
receipts: list[dict] = []
for start in range(0, len(ordered_unique_keys), BATCH_SIZE):
    batch_records, receipt = graphql_batch(ordered_unique_keys[start : start + BATCH_SIZE], len(receipts) + 1)
    records.extend(batch_records)
    receipts.append(receipt)
snapshot_ended = utc_now()

records.sort(key=lambda row: row["ordinal"])
assert len(records) == 95
assert len({row["canonical_repository"].lower() for row in records}) == 95
assert sum(row["occurrence_weight"] for row in records) == 98
assert all(row["metadata_status"] == "OFFICIAL_GITHUB_METADATA_SNAPSHOT_SUCCESS" for row in records)
assert all(row["visibility"] == "PUBLIC" for row in records)

payload = {
    "schema_version": 1,
    "status": "CURRENT_OFFICIAL_GITHUB_METADATA_SNAPSHOT_COMPLETE_NO_TRIAGE_CREDIT",
    "snapshot_started_at": snapshot_started,
    "snapshot_ended_at": snapshot_ended,
    "pins": {
        "governing_prompt_path": str(PROMPT_PATH),
        "governing_prompt_sha256": PROMPT_SHA256,
        "prompt_line_range": "496-515",
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
    },
    "method": {
        "source": "Authenticated read-only api.github.com GraphQL repository queries via gh CLI",
        "prompt_url_pattern": "literal https://github.com/<owner>/<repo>",
        "ordered_occurrence_normalization": "Original prompt order, exact URL case, LF separators, final LF",
        "ordered_url_occurrence_list_sha256": ORDERED_URL_OCCURRENCE_SHA256,
        "ordered_repository_occurrence_list_sha256": ORDERED_REPOSITORY_OCCURRENCE_SHA256,
        "sorted_lower_occurrence_multiset_sha256": SORTED_LOWER_OCCURRENCE_SHA256,
        "batch_size": BATCH_SIZE,
        "clone_or_checkout_performed": False,
        "archive_download_performed": False,
        "non_official_mirror_used": False,
    },
    "denominator": {
        "prompt_url_occurrences": 98,
        "unique_prompt_repositories": 95,
        "occurrence_weight_sum": 98,
        "repeated_repositories": {"glpi-project/glpi": 2, "netbox-community/netbox": 2, "opf/openproject": 2},
    },
    "result_counts": {
        "successful_metadata_records": len(records),
        "failed_records": 0,
        "redirected_or_case_changed_identities": sum(not row["identity_equal_case_sensitive"] for row in records),
        "public": sum(row["visibility"] == "PUBLIC" for row in records),
        "archived": sum(row["archived"] for row in records),
        "disabled": sum(row["disabled"] for row in records),
        "github_license_info_null_or_noassertion": sum((row["github_license_info"]["spdx_id"] in (None, "NOASSERTION")) for row in records),
        "latest_release_null": sum(row["github_latest_release"]["tag"] is None for row in records),
    },
    "api_receipts": receipts,
    "records": records,
    "evidence_count": 197,
    "evidence_count_basis": "98 literal prompt occurrences plus 95 repository metadata records plus four official API batch receipts; pins and derived hashes are not double-counted.",
    "credit_boundary": {
        "metadata_prerequisite_coverage": "95/95 unique repositories and 98/98 occurrence-weighted prompt entries",
        "upstream_full_triage_credit": 0,
        "exact_behaviour_credit": 0,
        "edition_boundary_credit": 0,
        "root_licence_confirmation_credit": 0,
        "maintenance_quality_credit": 0,
        "selection_or_outcome_credit": 0,
        "feature_mapping_credit": 0,
        "benchmark_completion_credit": 0,
    },
    "limits": [
        "GitHub licenseInfo is metadata only and does not establish the repository root licence or edition boundary.",
        "A null latestRelease field does not prove that no tags, prereleases, or other release artifacts exist.",
        "Archived, pushed, updated, commit, and release timestamps are provenance signals, not a maintenance-quality decision.",
        "No exact feature behaviour, screen, workflow, selection outcome, neutral requirement, or Oblivion mapping is established by this snapshot.",
    ],
}

target = AUDIT_DIR / "evidence/benchmark/current-github-project-metadata-snapshot.json"
target.parent.mkdir(parents=True, exist_ok=True)
target.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
print(json.dumps({"target": str(target), "records": len(records), "occurrence_weight": sum(row["occurrence_weight"] for row in records), "snapshot_started_at": snapshot_started, "snapshot_ended_at": snapshot_ended}))
