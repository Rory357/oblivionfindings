#!/usr/bin/env python3
"""Build the pinned RUN-082 exact static-candidate relation census.

This generator is deliberately audit-only. It reads the pinned Git application
snapshot plus committed audit evidence and emits candidate relations. Exact
route names, controller method containment, and render-owner containment are
not feature mappings and award no framework, runtime, build, browser, test,
benchmark, Pass, or completion credit.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
import re
import subprocess
from collections import Counter, defaultdict
from functools import lru_cache
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
REPO_DIR = AUDIT_DIR.parents[2]
OUTPUT_REL = "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL
GENERATED_ON = "2026-08-25T15:20:00+12:00"

CHECKPOINT_COMMIT = "35a5228b26c54684718495c33281b24c0992de02"
CHECKPOINT_TREE = "8ba4e28575cdb53682824a9ae604c718646d8a18"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"

MANIFEST_REL = "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json"
MANIFEST_SHA256 = "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be"
CLASSIFICATION_REL = "evidence/source/current-route-page-classification-wave-07.json"
CLASSIFICATION_SHA256 = "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97"
MATRIX_REL = "03-feature-to-benchmark-matrix.csv"
MATRIX_SHA256 = "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390"
ALLOWED_PREDECESSOR_OUTPUT_SHA256S = {
    "1b84d2b8a59ee7ffee89ef1e8f88f5fe04db3206a25ba95d6dc64d678c89b8bb",
}

EXPECTED_ROUTE_DENOMINATOR = 3003
EXPECTED_ROUTE_ID_SHA256 = "b22bb80cbcf457574e4125e6415d54fbdaa902ad05feb102c24a098f69ffe1cc"
EXPECTED_PAGE_DENOMINATOR = 393
EXPECTED_PAGE_ID_SHA256 = "4c079190abb6715e8b965a1c26b84fd455c685bf9f8d0591aac0df75e75d5477"
EXPECTED_ROUTE_PARTITIONS = {"A": 1015, "B": 1027, "C": 961}
EXPECTED_PAGE_PARTITIONS = {"A": 81, "B": 156, "C": 156}
EXPECTED_ROUTE_METHODS = {
    "delete": 193,
    "get": 1156,
    "match": 4,
    "patch": 66,
    "permanentRedirect": 5,
    "post": 1263,
    "put": 275,
    "redirect": 40,
    "resource": 1,
}
EXPECTED_NAME_CARDINALITIES = {"many": 46, "one": 527, "zero": 2430}
EXPECTED_BACKEND_CARDINALITIES = {"many": 55, "one": 610, "zero": 2338}
EXPECTED_BACKEND_RESOLVED_CARDINALITIES = {"many": 55, "one": 610, "zero": 2214}
EXPECTED_BACKEND_RESOLVED_ID_SHA256S = {
    "many": "d3220f633d378a52b119231dce29ca38dd9fa8d3665daf97d9cb9bde3bc5235a",
    "one": "127090e3e925f545a66c0c322a3ea689d59bb48409683f54faf4fd1315eecdf6",
    "zero": "e369fd12975d40b5d15f08368f4b66c3446e4f825ed8e5712662ff225255024c",
}
EXPECTED_PAGE_RENDER_CARDINALITIES = {"many": 2, "one": 43, "zero": 348}

ACTION_ARRAY_RE = re.compile(
    r"^\s*\[\s*(?P<class>\\?[A-Za-z_][A-Za-z0-9_\\]*)::class\s*,\s*"
    r"(?P<quote>['\"])(?P<method>[A-Za-z_][A-Za-z0-9_]*)"
    r"(?P=quote)\s*\]\s*$"
)
USE_RE = re.compile(
    r"^\s*use\s+(?P<fqcn>[A-Za-z_\\][A-Za-z0-9_\\]*)"
    r"(?:\s+as\s+(?P<alias>[A-Za-z_][A-Za-z0-9_]*))?\s*;",
    re.MULTILINE,
)
EXPLICIT_ANCHOR_RE = re.compile(
    r"^(?P<path>[^:]+):(?P<start>[1-9][0-9]*)(?:-(?P<end>[1-9][0-9]*))?$"
)
WEB_REQUIRE_RE = re.compile(r"require\s+__DIR__\s*\.\s*['\"]/([^'\"]+)['\"]\s*;")

CREDIT_BOUNDARY = {
    "static_candidate_relation_as_feature_mapping": False,
    "exact_route_name_as_feature_mapping": False,
    "controller_method_containment_as_feature_mapping": False,
    "render_owner_containment_as_feature_mapping": False,
    "framework_route_reachability": False,
    "runtime": False,
    "build": False,
    "application_browser": False,
    "executed_tests": False,
    "benchmark_mapping": False,
    "ease": False,
    "release": False,
    "pass": False,
    "completion": False,
    "audit_complete": False,
}

COMPLETION_BOUNDARY = {
    "all_routes_mapped_to_feature_ids": False,
    "all_page_roots_mapped_to_feature_ids": False,
    "candidate_relations_independently_adjudicated": False,
    "framework_route_reachability": False,
    "runtime": False,
    "build": False,
    "application_browser": False,
    "executed_tests": False,
    "benchmark_mapping": False,
    "final_no_match": False,
    "ease": False,
    "pass_1_to_8": False,
    "completion": False,
    "audit_complete": False,
}


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_json_bytes(value: object) -> bytes:
    return json.dumps(
        value,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")


def canonical_json_sha256(value: object) -> str:
    return sha256_bytes(canonical_json_bytes(value))


def canonical_id_sha256(values: list[str]) -> str:
    return canonical_json_sha256(sorted(values))


def git_bytes(*args: str) -> bytes:
    return subprocess.run(
        ["git", *args],
        cwd=REPO_DIR,
        check=True,
        stdout=subprocess.PIPE,
    ).stdout


def git_text(*args: str) -> str:
    return git_bytes(*args).decode("utf-8").strip()


@lru_cache(maxsize=None)
def git_blob_bytes(commit: str, path: str) -> bytes:
    return git_bytes("show", f"{commit}:{path}")


@lru_cache(maxsize=None)
def git_blob_text(commit: str, path: str) -> str:
    return git_blob_bytes(commit, path).decode("utf-8-sig")


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def split_matrix_values(raw: str) -> list[str]:
    return [
        value.strip()
        for value in raw.split(";")
        if value.strip() and not value.strip().startswith("NOT_")
    ]


def mask_php_comments(source: str) -> str:
    """Mask PHP comments while preserving strings, newlines, and offsets."""

    chars = list(source)
    i = 0
    state = "code"
    quote = ""
    while i < len(chars):
        char = chars[i]
        nxt = chars[i + 1] if i + 1 < len(chars) else ""
        if state == "code":
            if char in {"'", '"'}:
                state = "string"
                quote = char
                i += 1
                continue
            if char == "/" and nxt == "/":
                chars[i] = chars[i + 1] = " "
                state = "line_comment"
                i += 2
                continue
            if char == "#":
                chars[i] = " "
                state = "line_comment"
                i += 1
                continue
            if char == "/" and nxt == "*":
                chars[i] = chars[i + 1] = " "
                state = "block_comment"
                i += 2
                continue
        elif state == "string":
            if char == "\\":
                i += 2
                continue
            if char == quote:
                state = "code"
                quote = ""
                i += 1
                continue
        elif state == "line_comment":
            if char in {"\r", "\n"}:
                state = "code"
            else:
                chars[i] = " "
        elif state == "block_comment":
            if char == "*" and nxt == "/":
                chars[i] = chars[i + 1] = " "
                state = "code"
                i += 2
                continue
            if char not in {"\r", "\n"}:
                chars[i] = " "
        i += 1
    assert state != "block_comment"
    return "".join(chars)


@lru_cache(maxsize=None)
def route_imports(route_file: str) -> dict[str, str]:
    masked = mask_php_comments(git_blob_text(APPLICATION_COMMIT, route_file))
    imports: dict[str, str] = {}
    for match in USE_RE.finditer(masked):
        fqcn = match.group("fqcn").lstrip("\\")
        alias = match.group("alias") or fqcn.rsplit("\\", 1)[-1]
        if alias in imports:
            assert imports[alias] == fqcn, (route_file, alias)
        imports[alias] = fqcn
    return imports


def resolve_action(route: dict) -> dict:
    expression = route.get("action_expression") or ""
    match = ACTION_ARRAY_RE.fullmatch(expression)
    if not match:
        return {
            "status": "NON_EXACT_CLASS_METHOD_ARRAY",
            "action_expression": expression,
            "class_token": None,
            "resolved_fqcn": None,
            "controller_file": None,
            "method": None,
            "definition_line": None,
            "definition_anchor": None,
        }

    class_token = match.group("class").lstrip("\\")
    method = match.group("method")
    if class_token.startswith("App\\"):
        fqcn = class_token
        resolution_basis = "DIRECT_APP_FQCN"
    else:
        fqcn = route_imports(route["route_file"]).get(class_token)
        resolution_basis = "EXACT_ROUTE_FILE_USE_IMPORT"
    assert fqcn and fqcn.startswith("App\\"), (route["route_record_id"], class_token, fqcn)
    controller_file = "app/" + fqcn[len("App\\") :].replace("\\", "/") + ".php"
    source = git_blob_text(APPLICATION_COMMIT, controller_file)
    masked = mask_php_comments(source)
    method_re = re.compile(r"\bfunction\s+&?\s*" + re.escape(method) + r"\s*\(")
    definitions = list(method_re.finditer(masked))
    assert len(definitions) == 1, (
        route["route_record_id"],
        controller_file,
        method,
        len(definitions),
    )
    definition_line = masked.count("\n", 0, definitions[0].start()) + 1
    return {
        "status": "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION",
        "action_expression": expression,
        "class_token": class_token,
        "resolved_fqcn": fqcn,
        "resolution_basis": resolution_basis,
        "controller_file": controller_file,
        "controller_file_sha256": sha256_bytes(git_blob_bytes(APPLICATION_COMMIT, controller_file)),
        "method": method,
        "definition_line": definition_line,
        "definition_anchor": f"{controller_file}:{definition_line}",
    }


def matrix_indexes() -> dict:
    with (AUDIT_DIR / MATRIX_REL).open(encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    assert len(rows) == 340
    assert len({row["feature_id"] for row in rows}) == 340

    route_names: dict[str, set[str]] = defaultdict(set)
    page_files: dict[str, set[str]] = defaultdict(set)
    explicit_anchors: dict[str, list[dict]] = defaultdict(list)
    non_explicit_anchors: list[dict] = []
    for row in rows:
        feature_id = row["feature_id"].strip()
        for name in split_matrix_values(row["route_names"]):
            route_names[name].add(feature_id)
        for page_file in split_matrix_values(row["page_files"]):
            page_files[page_file].add(feature_id)
        for raw_anchor in split_matrix_values(row["backend_anchors"]):
            match = EXPLICIT_ANCHOR_RE.fullmatch(raw_anchor)
            if not match:
                non_explicit_anchors.append(
                    {"feature_id": feature_id, "anchor": raw_anchor}
                )
                continue
            start = int(match.group("start"))
            end = int(match.group("end") or start)
            assert start <= end
            explicit_anchors[match.group("path")].append(
                {
                    "feature_id": feature_id,
                    "anchor": raw_anchor,
                    "start_line": start,
                    "end_line": end,
                    "containment_basis": "EXPLICIT_LINE_OR_LINE_RANGE_BACKEND_ANCHOR",
                }
            )
    for path in explicit_anchors:
        explicit_anchors[path].sort(
            key=lambda row: (
                row["feature_id"],
                row["start_line"],
                row["end_line"],
                row["anchor"],
            )
        )
    return {
        "rows": rows,
        "route_names": route_names,
        "page_files": page_files,
        "explicit_anchors": explicit_anchors,
        "non_explicit_anchors": sorted(
            non_explicit_anchors,
            key=lambda row: (row["feature_id"], row["anchor"]),
        ),
    }


def containment_matches(path: str | None, line: int | None, indexes: dict) -> list[dict]:
    if not path or not line:
        return []
    return [
        dict(anchor)
        for anchor in indexes["explicit_anchors"].get(path, [])
        if anchor["start_line"] <= line <= anchor["end_line"]
    ]


def cardinality(candidate_count: int) -> str:
    if candidate_count == 0:
        return "zero"
    if candidate_count == 1:
        return "one"
    return "many"


def compare_candidate_sets(left: set[str], right: set[str], left_name: str, right_name: str) -> str:
    if not left and not right:
        return "NO_CANDIDATE_EITHER_LANE"
    if left and not right:
        return f"{left_name}_ONLY"
    if right and not left:
        return f"{right_name}_ONLY"
    if left == right:
        return "BOTH_LANES_IDENTICAL"
    if left & right:
        return "BOTH_LANES_PARTIAL_OVERLAP"
    return "BOTH_LANES_DISJOINT"


def category_summary(records: list[dict], category_key: str, id_key: str) -> dict:
    grouped: dict[str, list[str]] = defaultdict(list)
    for row in records:
        grouped[row[category_key]].append(row[id_key])
    return {
        category: {
            "count": len(ids),
            "record_ids_sha256": canonical_id_sha256(ids),
        }
        for category, ids in sorted(grouped.items())
    }


def candidate_cardinality_summary(records: list[dict], relation_key: str, id_key: str) -> dict:
    grouped: dict[str, list[str]] = {"zero": [], "one": [], "many": []}
    for row in records:
        relation = row[relation_key]
        grouped[cardinality(relation["candidate_count"])].append(row[id_key])
    return {
        category: {
            "count": len(ids),
            "record_ids_sha256": canonical_id_sha256(ids),
        }
        for category, ids in grouped.items()
    }


def line_of_unique(source: str, needle: str) -> int:
    positions = [index for index in range(len(source)) if source.startswith(needle, index)]
    assert len(positions) == 1, (needle, len(positions))
    return source.count("\n", 0, positions[0]) + 1


def build_static_registration_closure(manifest: dict) -> dict:
    route_rows = manifest["route_universe"]["route_files"]
    route_paths = sorted(row["route_file"] for row in route_rows)
    assert len(route_paths) == 38 and len(set(route_paths)) == 38

    bootstrap = git_blob_text(APPLICATION_COMMIT, "bootstrap/app.php")
    direct_needles = {
        "routes/web.php": "web: __DIR__.'/../routes/web.php'",
        "routes/api-hr.php": "api: __DIR__.'/../routes/api-hr.php'",
        "routes/console.php": "commands: __DIR__.'/../routes/console.php'",
        "routes/monitoring-collector.php": "->group(base_path('routes/monitoring-collector.php'))",
        "routes/channels.php": "__DIR__.'/../routes/channels.php'",
    }
    direct = [
        {
            "route_file": route_file,
            "registration_kind": "DIRECT_BOOTSTRAP_SURFACE",
            "source_anchor": f"bootstrap/app.php:{line_of_unique(bootstrap, needle)}",
            "source_expression": needle,
        }
        for route_file, needle in sorted(direct_needles.items())
    ]

    web = git_blob_text(APPLICATION_COMMIT, "routes/web.php")
    web_requires: list[dict] = []
    for match in WEB_REQUIRE_RE.finditer(mask_php_comments(web)):
        route_file = "routes/" + match.group(1)
        line = web.count("\n", 0, match.start()) + 1
        web_requires.append(
            {
                "route_file": route_file,
                "registration_kind": "STATIC_WEB_REQUIRE",
                "source_anchor": f"routes/web.php:{line}",
            }
        )
    web_requires.sort(key=lambda row: row["route_file"])
    assert len(web_requires) == 33
    assert len({row["route_file"] for row in web_requires}) == 33

    represented = sorted(
        {row["route_file"] for row in direct}
        | {row["route_file"] for row in web_requires}
    )
    missing = sorted(set(route_paths) - set(represented))
    extra = sorted(set(represented) - set(route_paths))
    assert represented == route_paths
    assert not missing and not extra
    return {
        "status": "STATIC_SOURCE_REGISTRATION_CLOSURE_ESTABLISHED_RUNTIME_REACHABILITY_NOT_EXECUTED",
        "relation_class": "STATIC_SOURCE_REGISTRATION_RELATION",
        "contract": "Literal bootstrap registrations plus literal uncommented routes/web.php require statements at the pinned application commit; this is source closure, not an executed Laravel route table.",
        "counts": {
            "route_files_in_manifest": len(route_paths),
            "direct_bootstrap_surfaces": len(direct),
            "web_required_surfaces": len(web_requires),
            "represented_route_files": len(represented),
            "missing_route_files": len(missing),
            "extra_route_files": len(extra),
            "framework_route_tables_executed": 0,
        },
        "route_file_paths_sha256": canonical_id_sha256(route_paths),
        "represented_route_file_paths_sha256": canonical_id_sha256(represented),
        "direct_registrations": direct,
        "web_requires": web_requires,
        "missing_route_files": missing,
        "extra_route_files": extra,
        "framework_route_reachability": "NOT_EXECUTED",
        "credit_awarded": False,
    }


def ignored_by_git(relative: str) -> bool:
    result = subprocess.run(
        ["git", "check-ignore", "-q", relative],
        cwd=REPO_DIR,
        check=False,
    )
    assert result.returncode in {0, 1}
    return result.returncode == 0


def env_database_identifier(path: Path) -> str | None:
    if not path.is_file():
        return None
    for line in path.read_text(encoding="utf-8-sig", errors="replace").splitlines():
        if line.startswith("DB_DATABASE="):
            return line.partition("=")[2].strip()
    return None


def build_execution_gates() -> dict:
    node_modules = REPO_DIR / "node_modules"
    node_entries = sorted(path.name for path in node_modules.iterdir()) if node_modules.is_dir() else []
    env_db = env_database_identifier(REPO_DIR / ".env")
    test_db = env_database_identifier(REPO_DIR / ".env.testing")
    observations = {
        "vendor_autoload_present": (REPO_DIR / "vendor/autoload.php").is_file(),
        "node_modules_present": node_modules.is_dir(),
        "node_modules_top_level_entry_count": len(node_entries),
        "wayfinder_node_plugin_present": (REPO_DIR / "node_modules/@laravel/vite-plugin-wayfinder").is_dir(),
        "ignored_wayfinder_routes_present": (REPO_DIR / "resources/js/routes").is_dir(),
        "ignored_wayfinder_actions_present": (REPO_DIR / "resources/js/actions").is_dir(),
        "public_build_present": (REPO_DIR / "public/build").is_dir(),
        "public_build_git_ignored": ignored_by_git("public/build"),
        "route_cache_file_count": len(list((REPO_DIR / "bootstrap/cache").glob("routes*.php"))),
        "env_database_identifiers_present": env_db is not None and test_db is not None,
        "env_and_test_database_identifiers_equal": env_db is not None and env_db == test_db,
    }
    assert observations == {
        "vendor_autoload_present": False,
        "node_modules_present": True,
        "node_modules_top_level_entry_count": 0,
        "wayfinder_node_plugin_present": False,
        "ignored_wayfinder_routes_present": True,
        "ignored_wayfinder_actions_present": True,
        "public_build_present": True,
        "public_build_git_ignored": True,
        "route_cache_file_count": 0,
        "env_database_identifiers_present": True,
        "env_and_test_database_identifiers_equal": True,
    }
    return {
        "workspace_preflight_observations": observations,
        "framework_runtime": {
            "status": "NO_GO_NOT_EXECUTED_MISSING_VENDOR_AUTOLOAD_AND_ROUTE_CACHE",
            "reason": "The pinned source closure was inspected only. vendor/autoload.php and an attributable route cache are absent, so no Laravel route table was executed.",
            "executed": False,
            "credit_awarded": False,
        },
        "build": {
            "status": "NO_GO_NOT_EXECUTED_MISSING_PINNED_NODE_DEPENDENCIES_AND_BUILD_PROVENANCE",
            "reason": "node_modules is empty and the Wayfinder Node plugin is absent. Ignored generated route/action outputs and public/build are not accepted as current attributable build evidence.",
            "executed": False,
            "credit_awarded": False,
        },
        "tests": {
            "status": "NO_GO_NOT_EXECUTED_NO_DISPOSABLE_DATABASE_ISOLATION",
            "reason": ".env and .env.testing identify the same database; RUN-082 has no explicit disposable database grant and executed no tests.",
            "executed": False,
            "credit_awarded": False,
        },
        "application_browser": {
            "status": "NO_GO_NOT_EXECUTED_NO_AUTHORITATIVE_DEPLOYED_BUILD_MARKER",
            "reason": "A logged-in browser session alone does not bind the deployed application to the pinned commit, tree, or build. RUN-082 performed no application browser verification.",
            "executed": False,
            "credit_awarded": False,
        },
    }


def build_route_records(manifest: dict, classification: dict, indexes: dict) -> list[dict]:
    unresolved_ids = sorted(
        row["route_record_id"]
        for row in classification["route_decisions"]
        if row["classification"] == "EXPLICIT_UNMAPPED_SENTINEL"
    )
    assert len(unresolved_ids) == EXPECTED_ROUTE_DENOMINATOR
    assert canonical_id_sha256(unresolved_ids) == EXPECTED_ROUTE_ID_SHA256

    primary = manifest["route_universe"]["primary_route_facade_callsites"]
    sentinels = manifest["route_universe"]["route_like_sentinels"]
    all_rows = {row["route_record_id"]: row for row in primary + sentinels}
    assert len(all_rows) == 3218
    assert set(unresolved_ids).issubset(all_rows)
    primary_ids = {row["route_record_id"] for row in primary}

    records: list[dict] = []
    for route_id in unresolved_ids:
        source = all_rows[route_id]
        literal_name = (
            source.get("direct_name_literal")
            if route_id in primary_ids
            else source.get("literal_route_name")
        )
        name_ids = sorted(indexes["route_names"].get(literal_name, set())) if literal_name else []
        resolved = resolve_action(source)
        matches = containment_matches(
            resolved.get("controller_file"),
            resolved.get("definition_line"),
            indexes,
        )
        backend_ids = sorted({row["feature_id"] for row in matches})
        name_set = set(name_ids)
        backend_set = set(backend_ids)
        relation_comparison = compare_candidate_sets(
            name_set, backend_set, "NAME", "BACKEND"
        )
        records.append(
            {
                "route_record_id": route_id,
                "partition_id": source["partition"],
                "manifest_record_kind": (
                    "PRIMARY_ROUTE_FACADE_CALLSITE"
                    if route_id in primary_ids
                    else "ROUTE_LIKE_SENTINEL"
                ),
                "source_key": source["source_key"],
                "source_anchor": source["source_anchor"],
                "route_file": source["route_file"],
                "route_method": source["route_method"],
                "literal_uri": source.get("literal_uri"),
                "literal_route_name": literal_name,
                "action_expression": source.get("action_expression") or "",
                "name_relation": {
                    "relation_class": "STATIC_CANDIDATE_RELATION",
                    "rule": "Exact case-sensitive equality between the directly declared literal route name and a current matrix route_names token; no group-prefix propagation or adjacency inheritance.",
                    "candidate_count": len(name_ids),
                    "candidate_feature_ids": name_ids,
                    "matched_literal_route_name": literal_name if name_ids else None,
                    "credit_awarded": False,
                },
                "backend_method_relation": {
                    "relation_class": "STATIC_CANDIDATE_RELATION",
                    "rule": "Import-aware exact [Controller::class, 'method'] resolution, exactly one uncommented method definition, and exact controller-file plus definition-line containment within an explicit matrix backend_anchors line or line range; whole-file anchors are excluded from this exact-line lane.",
                    "resolution": resolved,
                    "candidate_count": len(backend_ids),
                    "candidate_feature_ids": backend_ids,
                    "matching_matrix_anchors": matches,
                    "credit_awarded": False,
                },
                "relation_comparison": relation_comparison,
                "candidate_union_feature_ids": sorted(name_set | backend_set),
                "feature_mapping_status": "NOT_EXECUTED_STATIC_CANDIDATES_ONLY",
                "framework_reachability": "NOT_EXECUTED",
                "independent_review_status": "PENDING",
                "credit_awarded": False,
            }
        )

    assert Counter(row["partition_id"] for row in records) == Counter(EXPECTED_ROUTE_PARTITIONS)
    assert Counter(row["route_method"] for row in records) == Counter(EXPECTED_ROUTE_METHODS)
    assert Counter(row["manifest_record_kind"] for row in records) == Counter(
        {"PRIMARY_ROUTE_FACADE_CALLSITE": 3002, "ROUTE_LIKE_SENTINEL": 1}
    )
    assert sum(row["literal_uri"] is not None for row in records) == 3001
    assert sum(row["literal_uri"] is None for row in records) == 2
    assert sum(row["literal_route_name"] is not None for row in records) == 2975
    assert sum(row["literal_route_name"] is None for row in records) == 28
    assert Counter(
        cardinality(row["name_relation"]["candidate_count"]) for row in records
    ) == Counter(EXPECTED_NAME_CARDINALITIES)
    backend_cardinalities = Counter(
        cardinality(row["backend_method_relation"]["candidate_count"])
        for row in records
    )
    assert backend_cardinalities == Counter(EXPECTED_BACKEND_CARDINALITIES), backend_cardinalities
    assert Counter(
        row["backend_method_relation"]["resolution"]["status"] for row in records
    ) == Counter(
        {
            "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION": 2879,
            "NON_EXACT_CLASS_METHOD_ARRAY": 124,
        }
    )
    return records


def build_page_records(manifest: dict, classification: dict, indexes: dict) -> list[dict]:
    gap_ids = sorted(
        row["page_record_id"]
        for row in classification["page_decisions"]
        if row["prompt_classification"] == "Evidence gap"
    )
    assert len(gap_ids) == EXPECTED_PAGE_DENOMINATOR
    assert canonical_id_sha256(gap_ids) == EXPECTED_PAGE_ID_SHA256
    page_by_id = {
        row["page_record_id"]: row for row in manifest["page_universe"]["page_roots"]
    }
    assert len(page_by_id) == 711
    assert set(gap_ids).issubset(page_by_id)

    records: list[dict] = []
    for page_id in gap_ids:
        source = page_by_id[page_id]
        render_matches: list[dict] = []
        callsites: list[dict] = []
        for callsite in source["render_callsites"]:
            callsite_copy = {
                "render_name": callsite["render_name"],
                "source_file": callsite["source_file"],
                "source_line": callsite["source_line"],
                "source_anchor": callsite["source_anchor"],
                "call_kind": callsite["call_kind"],
            }
            callsites.append(callsite_copy)
            for match in containment_matches(
                callsite["source_file"], callsite["source_line"], indexes
            ):
                render_matches.append(
                    {
                        "render_source_anchor": callsite["source_anchor"],
                        **match,
                    }
                )
        render_matches.sort(
            key=lambda row: (
                row["feature_id"],
                row["render_source_anchor"],
                row["anchor"],
            )
        )
        render_ids = sorted({row["feature_id"] for row in render_matches})
        page_file_ids = sorted(indexes["page_files"].get(source["page_file"], set()))
        render_set = set(render_ids)
        page_file_set = set(page_file_ids)
        records.append(
            {
                "page_record_id": page_id,
                "partition_id": source["partition"],
                "page_file": source["page_file"],
                "page_file_sha256": source["page_file_sha256"],
                "render_names": source["render_names"],
                "render_callsites": callsites,
                "render_owner_relation": {
                    "relation_class": "STATIC_CANDIDATE_RELATION",
                    "rule": "Exact render source file and render call line containment within an explicit current matrix backend_anchors line or line range.",
                    "candidate_count": len(render_ids),
                    "candidate_feature_ids": render_ids,
                    "matching_matrix_anchors": render_matches,
                    "credit_awarded": False,
                },
                "current_matrix_page_file_relation": {
                    "relation_class": "STATIC_CANDIDATE_RELATION",
                    "rule": "Exact case-sensitive page-file equality against the current post-RUN-080 matrix, retained only to expose lane agreement or disagreement for this historically classified Evidence gap denominator.",
                    "candidate_count": len(page_file_ids),
                    "candidate_feature_ids": page_file_ids,
                    "credit_awarded": False,
                },
                "relation_comparison": compare_candidate_sets(
                    page_file_set, render_set, "PAGE_FILE", "RENDER_OWNER"
                ),
                "candidate_union_feature_ids": sorted(page_file_set | render_set),
                "feature_mapping_status": "NOT_EXECUTED_STATIC_CANDIDATES_ONLY",
                "framework_reachability": "NOT_EXECUTED",
                "build_resolution": "NOT_EXECUTED",
                "application_browser_observation": "NOT_EXECUTED",
                "independent_review_status": "PENDING",
                "credit_awarded": False,
            }
        )

    assert Counter(row["partition_id"] for row in records) == Counter(EXPECTED_PAGE_PARTITIONS)
    assert Counter(
        cardinality(row["render_owner_relation"]["candidate_count"]) for row in records
    ) == Counter(EXPECTED_PAGE_RENDER_CARDINALITIES)
    return records


def current_generator_sha256() -> str:
    return sha256_file(Path(__file__))


def validate_pins() -> None:
    assert git_text("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git_text("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert sha256_file(AUDIT_DIR / MANIFEST_REL) == MANIFEST_SHA256
    assert sha256_file(AUDIT_DIR / CLASSIFICATION_REL) == CLASSIFICATION_SHA256
    assert sha256_file(AUDIT_DIR / MATRIX_REL) == MATRIX_SHA256


def main() -> None:
    validate_pins()
    manifest = read_json(MANIFEST_REL)
    classification = read_json(CLASSIFICATION_REL)
    indexes = matrix_indexes()
    route_records = build_route_records(manifest, classification, indexes)
    page_records = build_page_records(manifest, classification, indexes)
    closure = build_static_registration_closure(manifest)
    execution_gates = build_execution_gates()

    route_name_cardinalities = candidate_cardinality_summary(
        route_records, "name_relation", "route_record_id"
    )
    route_backend_cardinalities = candidate_cardinality_summary(
        route_records, "backend_method_relation", "route_record_id"
    )
    resolved_route_records = [
        row
        for row in route_records
        if row["backend_method_relation"]["resolution"]["status"]
        == "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION"
    ]
    route_backend_resolved_cardinalities = candidate_cardinality_summary(
        resolved_route_records, "backend_method_relation", "route_record_id"
    )
    page_render_cardinalities = candidate_cardinality_summary(
        page_records, "render_owner_relation", "page_record_id"
    )
    page_file_cardinalities = candidate_cardinality_summary(
        page_records, "current_matrix_page_file_relation", "page_record_id"
    )
    route_comparisons = category_summary(
        route_records, "relation_comparison", "route_record_id"
    )
    page_comparisons = category_summary(
        page_records, "relation_comparison", "page_record_id"
    )

    assert {key: value["count"] for key, value in route_name_cardinalities.items()} == EXPECTED_NAME_CARDINALITIES
    assert {key: value["count"] for key, value in route_backend_cardinalities.items()} == EXPECTED_BACKEND_CARDINALITIES
    assert {key: value["count"] for key, value in route_backend_resolved_cardinalities.items()} == EXPECTED_BACKEND_RESOLVED_CARDINALITIES
    assert {
        key: value["record_ids_sha256"]
        for key, value in route_backend_resolved_cardinalities.items()
    } == EXPECTED_BACKEND_RESOLVED_ID_SHA256S
    assert {key: value["count"] for key, value in page_render_cardinalities.items()} == EXPECTED_PAGE_RENDER_CARDINALITIES
    assert sum(row["count"] for row in route_comparisons.values()) == EXPECTED_ROUTE_DENOMINATOR
    assert sum(row["count"] for row in page_comparisons.values()) == EXPECTED_PAGE_DENOMINATOR

    route_ids = [row["route_record_id"] for row in route_records]
    page_ids = [row["page_record_id"] for row in page_records]
    payload = {
        "schema_version": 1,
        "run_id": "RUN-082-EXACT-OWNER-CONTAINMENT-CANDIDATE-CENSUS",
        "status": "STATIC_CANDIDATE_RELATIONS_MATERIALIZED_PENDING_INDEPENDENT_REVIEW_ZERO_CREDIT",
        "generated_on": GENERATED_ON,
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "manifest_path": MANIFEST_REL,
            "manifest_sha256": MANIFEST_SHA256,
            "classification_path": CLASSIFICATION_REL,
            "classification_sha256": CLASSIFICATION_SHA256,
            "matrix_path": MATRIX_REL,
            "matrix_sha256": MATRIX_SHA256,
            "generator": f"generators/{Path(__file__).name}",
            "generator_sha256": current_generator_sha256(),
        },
        "architecture_rule": {
            "system_model": "ONE_OPERATING_ORGANISATION_ACROSS_MULTIPLE_SITES",
            "authorization_boundary": "Roles, permissions, approved Sites, canonical record ownership, direct-object denial, and privacy rules; never tenant isolation.",
        },
        "scope": {
            "route_denominator": "The exact 3,003 RUN-078 EXPLICIT_UNMAPPED_SENTINEL route-like decisions, rehydrated from the authoritative RUN-077 manifest.",
            "page_denominator": "The exact 393 RUN-078 page decisions classified Evidence gap, rehydrated from the authoritative RUN-077 manifest.",
            "relation_status": "STATIC_CANDIDATE_RELATION_ONLY",
            "source_reads": "Pinned Git blobs and committed audit evidence only; Laravel, build tooling, application browser surfaces, and tests were not executed.",
        },
        "counts": {
            "canonical_matrix_features": 340,
            "unresolved_route_like_records": len(route_records),
            "unresolved_route_like_record_ids_sha256": canonical_id_sha256(route_ids),
            "route_exact_class_method_arrays_resolved": sum(
                row["backend_method_relation"]["resolution"]["status"]
                == "EXACT_CLASS_METHOD_ARRAY_RESOLVED_UNIQUE_DEFINITION"
                for row in route_records
            ),
            "route_non_exact_class_method_array_records": sum(
                row["backend_method_relation"]["resolution"]["status"]
                == "NON_EXACT_CLASS_METHOD_ARRAY"
                for row in route_records
            ),
            "page_evidence_gap_records": len(page_records),
            "page_evidence_gap_record_ids_sha256": canonical_id_sha256(page_ids),
            "static_route_files_represented": closure["counts"]["represented_route_files"],
            "final_feature_mappings": 0,
            "framework_routes_executed": 0,
            "runtime_credit": 0,
            "build_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "benchmark_mapping_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
        },
        "route_static_candidate_census": {
            "status": "STATIC_CANDIDATE_RELATIONS_PENDING_INDEPENDENT_REVIEW",
            "denominator_count": len(route_records),
            "denominator_record_ids_sha256": canonical_id_sha256(route_ids),
            "exact_route_name_cardinalities": route_name_cardinalities,
            "controller_method_containment_cardinalities_all_3003": route_backend_cardinalities,
            "controller_method_containment_cardinalities_resolved_2879": route_backend_resolved_cardinalities,
            "non_exact_class_method_array_count": len(route_records) - len(resolved_route_records),
            "lane_comparisons": route_comparisons,
            "records_sha256": canonical_json_sha256(route_records),
            "records": route_records,
        },
        "page_static_candidate_census": {
            "status": "STATIC_CANDIDATE_RELATIONS_PENDING_INDEPENDENT_REVIEW",
            "denominator_count": len(page_records),
            "denominator_record_ids_sha256": canonical_id_sha256(page_ids),
            "render_owner_containment_cardinalities": page_render_cardinalities,
            "current_matrix_page_file_cardinalities": page_file_cardinalities,
            "lane_comparisons": page_comparisons,
            "records_sha256": canonical_json_sha256(page_records),
            "records": page_records,
        },
        "static_route_registration_closure": closure,
        "execution_gates": execution_gates,
        "review_contract": {
            "independent_review_required": True,
            "review_status": "PENDING",
            "required_checks": [
                "Recompute both exact denominators and their canonical ID hashes.",
                "Recompute import-aware controller resolution and exact method-definition containment.",
                "Recompute exact route-name and render-owner relations against the pinned current matrix.",
                "Adjudicate every multi-candidate relation and every disjoint or partial-overlap lane comparison.",
                "Retain zero mapping and downstream credit unless separately authorized evidence closes the relevant gate.",
            ],
        },
        "completion_boundary": COMPLETION_BOUNDARY,
        "credit_boundary": CREDIT_BOUNDARY,
        "attestation": "RUN-082 is a deterministic static candidate census only. Candidate relations and static route registration closure are not canonical feature ownership, framework route reachability, runtime, build, application browser, executed-test, benchmark, ease, Pass, release, or completion evidence.",
    }
    assert not any(payload["completion_boundary"].values())
    assert not any(payload["credit_boundary"].values())
    assert payload["counts"]["final_feature_mappings"] == 0

    encoded = (json.dumps(payload, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    assert json.loads(encoded.decode("utf-8")) == payload
    candidate_sha256 = sha256_bytes(encoded)
    if OUTPUT_PATH.exists():
        assert sha256_file(OUTPUT_PATH) in ALLOWED_PREDECESSOR_OUTPUT_SHA256S | {candidate_sha256}

    temporary = OUTPUT_PATH.with_name(OUTPUT_PATH.name + ".tmp-run082")
    assert OUTPUT_PATH.parent.is_dir()
    assert not temporary.exists()
    try:
        with temporary.open("xb") as handle:
            written = handle.write(encoded)
            assert written == len(encoded)
            handle.flush()
            os.fsync(handle.fileno())
        assert temporary.read_bytes() == encoded
        os.replace(temporary, OUTPUT_PATH)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert OUTPUT_PATH.read_bytes() == encoded
    assert sha256_file(OUTPUT_PATH) == candidate_sha256
    assert json.loads(OUTPUT_PATH.read_text(encoding="utf-8")) == payload
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_REL,
                "sha256": candidate_sha256,
                "counts": payload["counts"],
                "route_name_cardinalities": {
                    key: value["count"] for key, value in route_name_cardinalities.items()
                },
                "route_backend_cardinalities": {
                    key: value["count"] for key, value in route_backend_cardinalities.items()
                },
                "route_backend_resolved_cardinalities": {
                    key: value["count"]
                    for key, value in route_backend_resolved_cardinalities.items()
                },
                "page_render_cardinalities": {
                    key: value["count"] for key, value in page_render_cardinalities.items()
                },
            },
            separators=(",", ":"),
        )
    )


if __name__ == "__main__":
    main()
