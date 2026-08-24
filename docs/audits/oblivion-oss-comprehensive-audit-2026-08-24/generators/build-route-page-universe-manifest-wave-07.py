#!/usr/bin/env python3
"""Materialize the pinned RUN-077 static route/page-universe manifest.

This collector is audit-only. It reads committed source and existing audit
evidence, does not boot Laravel, and awards no runtime, browser, test,
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
OUTPUT_REL = "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json"
OUTPUT_PATH = AUDIT_DIR / OUTPUT_REL
GENERATED_ON = "2026-08-25T11:25:00+12:00"

CHECKPOINT_COMMIT = "a2e5392b2a97d6548a93fc0897f782d05e404a83"
CHECKPOINT_TREE = "3bf79f35a97f84f97067caaaf446b47b9de2b926"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
ROUTES_TREE = "9b7f78510d970db64ea3a6540e8a36b8700bf272"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
RESOURCES_JS_PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
INERTIA_RESOLVER_PATH = "resources/js/inertia-pages.ts"
INERTIA_RESOLVER_BLOB = "2fe0a1341c68d28cae26835f6c36df194ef7e8f9"
INERTIA_RESOLVER_SHA256 = "8b74c20ba277a684a584456a256fabead4f002c6e90d337e6f17fbfed9e5f562"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"
ALLOWED_PREDECESSOR_OUTPUT_SHA256S = {
    "e9edd5dc1147b6eb19a1be500026100846e756b791568fafbe2341513a5e8b2d",
    "71d5b8d4ac06d4b0bf85395a2b15d3a2a5eaf678df429b996f069b42bfe4a48b",
    "3dfaf7379b29028599e38de26c1ad8c7656649de4154a9922e68685c6056d58e",
    "3371edfe5222f9c8cc098dea8eeea48b6ba61bf055f3cf9e84ace960e5e55665",
}

EXPECTED_ROUTE_FILE_LIST_SHA256 = "35c01637962473e3c0f2615d867f4f55b572a062512e9f0eeb671fab32d367f2"
EXPECTED_ROUTE_BLOB_MANIFEST_SHA256 = "12ff59e01b85d20726dad356b484736e8aa5a61f5dd9a966f1b3376346356ece"
EXPECTED_ROUTE_LOCATOR_SHA256 = "141bb8297e140e090fa60a1a3815cef32b3015f7fabf90ad3a0c9a11103124e8"
EXPECTED_NAME_LOCATOR_SHA256 = "f2da00f68fbe9fe22a5d078675083ae14a8a2d974391a21e1595c015f3cd47c0"
EXPECTED_ROUTE_LIKE_SENTINEL_SOURCE_KEY_SHA256 = "31724f1feddd0b4428cc890b616bbf46c65d3ef2e9a1f2bc1f776dc8345413e9"
EXPECTED_RESOLVER_PATH_LIST_SHA256 = "1ba9fbc49d0fd8185392561afe2abefd1d66d7264c96a804da93768e45cc8f55"
EXPECTED_RESOLVER_BLOB_MANIFEST_SHA256 = "24ab0b74f31c2f47d2529a156c6578dfb00130bf101d81883de6aa5fc69b1dd7"
EXPECTED_PAGE_ROOT_PATH_LIST_SHA256 = "a847a52ea3342a3ed53ba4860fcb41bcb0d33f44b7fa1545c6d623ae41fb2702"
EXPECTED_PAGE_ROOT_BLOB_MANIFEST_SHA256 = "67805c341f6ef4ad9348633bcb5c32ec720b745ea91421efe4541bbda17ca365"
EXPECTED_PAGE_ROW_KEY_LIST_SHA256 = "f31a7bbf0d1b551a08b31cc0db65faf8b52caa8484bfea43f9211056f03c8342"

EXPECTED_ROUTE_PARTITION_SOURCE_KEY_SHA256 = {
    "A": "8b794238289ec253a05f9b956c5a70cc9f75698b2bf685e3527d362e35633867",
    "B": "275f853adf1410dfb9cccb7cee0bc7895c76cff5a166e2d22d7a859512d0b270",
    "C": "79d4a9b9c2a5db87e2ba55a1cd9aed7ac7e64761925e295390537a25c96c1eb0",
}
EXPECTED_NAME_PARTITION_SOURCE_KEY_SHA256 = {
    "A": "6e20073655389320488f763efae1b34bfa4f02d4de792dbccf980e5a98343615",
    "B": "7903679b809667e8aa6d39f7c4dc35920a51109e81a12f1fa6e7b411782c35e4",
    "C": "2745273167cebc0b6e9abd1c17dc3ef5f5d30e4fd837ca2df054f147130916f5",
}

EXPECTED_PAGE_PARTITIONS = {
    "A": {
        "row_key_sha256": "38ffa1a320c4ef4b2117ee1168a03971656b493a58801ae4e460e021e69748d6",
        "path_sha256": "e730aafb8bb9eba1f3a8c712d9b5a0d4dd298a4936a94f882400fab912759ba2",
        "first_render_name": "Governance/Actions/Index",
        "last_render_name": "fleet-assets/daily-check",
        "first_id": "PAGE-ROOT-3B51528F1FA22152",
        "last_id": "PAGE-ROOT-9E7079559DD90905",
    },
    "B": {
        "row_key_sha256": "43caecaf9ece9f16479a730e0f9c032b78e5c18dd265522e2fd6e930f1ed3c20",
        "path_sha256": "08439a72567935a15ec025770f937d2c32ab2438a1b75673bdfebf4d5d8f49e8",
        "first_render_name": "fleet-assets/dashboard",
        "last_render_name": "operations/family-portal/Edit",
        "first_id": "PAGE-ROOT-12420609C3B53733",
        "last_id": "PAGE-ROOT-A28E61CC3886B61E",
    },
    "C": {
        "row_key_sha256": "0646e04b884ff614849d75c9b03e795b7ddc753e941ebbb61cf23b8ecda044ee",
        "path_sha256": "c3058f3aeca9ee8ef04446f4b34d3f210c38ac65519ab018b95c45f6be819334",
        "first_render_name": "operations/family-portal/Index",
        "last_render_name": "timeline/index",
        "first_id": "PAGE-ROOT-BF619DE30DE5C5B5",
        "last_id": "PAGE-ROOT-D04EAEA81868B94B",
    },
}

PINNED_INPUTS = {
    "03-feature-to-benchmark-matrix.csv": "00085d407433307e7f6798c0e8e04629b1746d4bfb1e18024c51ead1dc4f7afd",
    "evidence/source/current-static-linkage-independent-review-wave-06.json": "6ee2c0beb90ce8e9fec75190c1a2d87e44b7f7d7b0b7776d25df4832b73d20a0",
    "evidence/source/current-static-linkage-integration-wave-06.json": "c0e6e78ede5a41e0214ce67eda69432ff5ca4034b53c731191cf2b57605f11bf",
    "evidence/source/current-static-linkage-reporting-materialization-wave-06.json": "04d5fd61048c2c877f6bdba3785fa46365ba85464649eb1b83779cc0daf39906",
    "evidence/source/current-canonical-feature-identity-wave-01.json": "f4feae2598622afe346b1163fed2bb842305a8d973a89ec890c02746d99b5999",
    "evidence/source/current-route-navigation-gap-wave-01.json": "de4e7cbb693c2d8550120277aed0a603ce08b4162c501e6a3eb657f43152d450",
    "evidence/source/current-static-semantic-census.json": "f19f6c48d0cd3d9203d8706e0893eae3157f0e65fdb2688faf4b27598cbf3e68",
    "evidence/source/current-page-adjudication-wave-01.json": "50c7cb41cff93dcc4aa57f90f43fec508a759be42b622211b06f851ba5fa405c",
    "evidence/source/current-page-agent-register.json": "b98682164fc6af58def00a931191a73ee54522cf5cc9fab01a4ad399fda115a3",
    "evidence/source/audit-run-manifest.json": "f3f70f7b68b38c27e7c0c37f204515df28322c53285e5a2c8dcb463250ca735b",
}

ROUTE_PARTITION_FILES = {
    "A": [
        "routes/hr.php",
        "routes/fleet-assets.php",
        "routes/control-room.php",
        "routes/portal.php",
        "routes/health-clinical.php",
        "routes/incidents.php",
        "routes/system.php",
        "routes/staff.php",
        "routes/reports.php",
        "routes/auth.php",
        "routes/compliance.php",
        "routes/console.php",
        "routes/channels.php",
    ],
    "B": [
        "routes/operations.php",
        "routes/governance.php",
        "routes/security-devices.php",
        "routes/emar.php",
        "routes/settings.php",
        "routes/privacy.php",
        "routes/api_medications.php",
        "routes/safeguarding.php",
        "routes/roadmap.php",
        "routes/shifts.php",
        "routes/tasks.php",
        "routes/monitoring-collector.php",
        "routes/integrations.php",
    ],
    "C": [
        "routes/finance.php",
        "routes/sites.php",
        "routes/health-safety.php",
        "routes/web.php",
        "routes/respite.php",
        "routes/clients.php",
        "routes/assets.php",
        "routes/training.php",
        "routes/catering.php",
        "routes/api-hr.php",
        "routes/fleet.php",
        "routes/medications.php",
    ],
}

EXPECTED_PARTITION_COUNTS = {
    "A": {"route_files": 13, "route_callsites": 1072, "fluent_name_callsites": 1105, "page_roots": 237},
    "B": {"route_files": 13, "route_callsites": 1073, "fluent_name_callsites": 1068, "page_roots": 237},
    "C": {"route_files": 12, "route_callsites": 1072, "fluent_name_callsites": 1072, "page_roots": 237},
}

ROUTE_METHODS = (
    "get",
    "post",
    "put",
    "patch",
    "delete",
    "match",
    "redirect",
    "permanentRedirect",
    "resource",
)
ROUTE_RE = re.compile(r"\bRoute\s*::\s*(" + "|".join(ROUTE_METHODS) + r")\s*\(")
NAME_RE = re.compile(r"->\s*name\s*\(")
RENDER_RE = re.compile(r"\b(Inertia::render|inertia)\s*\(\s*(['\"])([^'\"]+)\2")
ANCHOR_RE = re.compile(r"^(routes/[^:]+)(?::(\d+)(?:-(\d+))?)?$")
LINE_SUFFIX_RE = re.compile(r":\d+(?:-\d+)?$")
EXCLUDED_JS_RE = re.compile(
    r"(?:^|/)(?:__tests__|__snapshots__|tests?|stories)(?:/|$)|\.(?:test|spec|stories|story)\.(?:js|jsx|ts|tsx)$",
    re.IGNORECASE,
)

EXPECTED_METHOD_COUNTS = {
    "get": 1218,
    "post": 1364,
    "put": 295,
    "patch": 76,
    "delete": 213,
    "match": 4,
    "redirect": 41,
    "permanentRedirect": 5,
    "resource": 1,
}

EXPECTED_RESIDUAL_TARGETS = [
    "CAP-CLI-CLIENT-SUPPORT-PLAN",
    "CAP-FIN-FINANCIAL-STATEMENTS-EXPORT",
    "CAP-GOV-ACTION-ITEM-WORKFLOW",
    "CAP-GOV-AUDIT-EVIDENCE-PACK",
    "CAP-HR-SCHEDULED-REPORT-EXECUTION",
    "CAP-INT-INBOUND-PROVIDER-WEBHOOK",
    "CAP-MED-PHARMACY-ACTIONS",
    "CAP-REP-COMBINED-REPORTS",
    "CAP-RESP-BOOKING-REQUEST-REPORTING-EXPORT",
    "CAP-RESP-EVIDENCE-PACK-EXPORT",
    "CAP-SAFE-CONCERN-REPORTING-EXPORT",
    "CAP-SITE-RESOURCE-REGISTER",
]

CREDIT_BOUNDARY = {
    "route_or_page_presence_as_feature_mapping": False,
    "candidate_overlap_as_feature_mapping": False,
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
    "routes_classified": False,
    "pages_prompt_classified": False,
    "all_routes_expanded_and_mapped_to_feature_ids": False,
    "all_page_roots_mapped_to_feature_ids": False,
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

EXPECTED_TOP_LEVEL_KEYS = {
    "schema_version",
    "run_id",
    "status",
    "generated_on",
    "pins",
    "architecture_rule",
    "scope",
    "counts",
    "canonical_targets",
    "residual_scoped_gaps",
    "route_name_gaps",
    "route_universe",
    "page_universe",
    "partitions",
    "review_contract",
    "completion_boundary",
    "credit_boundary",
    "attestation",
}


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_list_sha256(values: list[str]) -> str:
    ordered = sorted(values, key=lambda value: value.encode("utf-8"))
    return sha256_bytes(("\n".join(ordered) + "\n").encode("utf-8"))


def canonical_json_sha256(value: object) -> str:
    return sha256_bytes(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8"))


def matrix_projection_sha256(rows: list[dict[str, str]], columns: list[str]) -> str:
    value = {
        "columns": columns,
        "rows": [[row[column] for column in columns] for row in rows],
    }
    return sha256_bytes(json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8"))


def git_text(*args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=REPO_DIR, text=True, encoding="utf-8").strip()


def git_lines(*args: str) -> list[str]:
    value = git_text(*args)
    return value.splitlines() if value else []


@lru_cache(maxsize=None)
def git_blob_map(commit: str) -> dict[str, str]:
    rows = git_lines("ls-tree", "-r", commit)
    result: dict[str, str] = {}
    for row in rows:
        metadata, path = row.split("\t", 1)
        _mode, object_type, object_id = metadata.split(" ")
        if object_type == "blob":
            result[path.replace("\\", "/")] = object_id
    return result


def git_blob_bytes(commit: str, path: str) -> bytes:
    normalized = path.replace("\\", "/")
    expected_object_id = git_blob_map(commit)[normalized]
    raw = (REPO_DIR / normalized).read_bytes()
    assert len(expected_object_id) == 40
    actual_object_id = hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\0" + raw).hexdigest()
    assert actual_object_id == expected_object_id, normalized
    return raw


def git_blob_id(commit: str, path: str) -> str:
    return git_blob_map(commit)[path.replace("\\", "/")]


def read_json(relative: str) -> dict:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def split_cell(value: str) -> list[str]:
    if not value or value.startswith("NOT_ESTABLISHED") or value == "NOT_APPLICABLE":
        return []
    return [part.strip() for part in value.split(";") if part.strip()]


def line_column(raw: str, offset: int) -> tuple[int, int]:
    line_start = raw.rfind("\n", 0, offset) + 1
    return raw.count("\n", 0, offset) + 1, offset - line_start + 1


def mask_match(match: re.Match[str]) -> str:
    return "".join(char if char in "\r\n" else " " for char in match.group(0))


def mask_php_comments(raw: str) -> str:
    masked = re.sub(r"/\*[\s\S]*?\*/", mask_match, raw)
    masked = re.sub(r"(^|[^:])//[^\n]*", mask_match, masked, flags=re.MULTILINE)
    return re.sub(r"#[^\n]*", mask_match, masked)


def find_matching_paren(text: str, open_index: int) -> int:
    depth = 0
    quote: str | None = None
    escaped = False
    for index in range(open_index, len(text)):
        char = text[index]
        if quote is not None:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            continue
        if char in "'\"":
            quote = char
        elif char == "(":
            depth += 1
        elif char == ")":
            depth -= 1
            if depth == 0:
                return index
    raise AssertionError(f"unclosed parenthesis at offset {open_index}")


def find_statement_end(text: str, start: int) -> int:
    stack: list[str] = []
    quote: str | None = None
    escaped = False
    pairs = {")": "(", "]": "[", "}": "{"}
    for index in range(start, len(text)):
        char = text[index]
        if quote is not None:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            continue
        if char in "'\"":
            quote = char
        elif char in "([{":
            stack.append(char)
        elif char in ")]}" and stack and stack[-1] == pairs[char]:
            stack.pop()
        elif char == ";" and not stack:
            return index
    return min(len(text) - 1, start + 2000)


def split_top_level_args(value: str) -> list[str]:
    args: list[str] = []
    start = 0
    stack: list[str] = []
    quote: str | None = None
    escaped = False
    pairs = {")": "(", "]": "[", "}": "{"}
    for index, char in enumerate(value):
        if quote is not None:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            continue
        if char in "'\"":
            quote = char
        elif char in "([{":
            stack.append(char)
        elif char in ")]}" and stack and stack[-1] == pairs[char]:
            stack.pop()
        elif char == "," and not stack:
            args.append(value[start:index].strip())
            start = index + 1
    args.append(value[start:].strip())
    return args


def literal_string(expression: str | None) -> str | None:
    if expression is None:
        return None
    match = re.fullmatch(r"(['\"])(.*)\1", expression.strip(), flags=re.DOTALL)
    return match.group(2) if match else None


def compact_excerpt(value: str, limit: int = 500) -> str:
    compact = re.sub(r"\s+", " ", value).strip()
    return compact if len(compact) <= limit else compact[: limit - 3] + "..."


def current_generator_sha256() -> str:
    return sha256_file(Path(__file__).resolve())


def validate_pins() -> None:
    assert git_text("branch", "--show-current") == "main"
    assert git_text("rev-parse", f"{CHECKPOINT_COMMIT}^{{tree}}") == CHECKPOINT_TREE
    checkpoint_ancestor = subprocess.run(
        ["git", "merge-base", "--is-ancestor", CHECKPOINT_COMMIT, "HEAD"],
        cwd=REPO_DIR,
        check=False,
    )
    assert checkpoint_ancestor.returncode == 0
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}:app") == APP_TREE
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}:routes") == ROUTES_TREE
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}:resources/js") == RESOURCES_JS_TREE
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}:resources/js/pages") == RESOURCES_JS_PAGES_TREE
    assert git_text("rev-parse", f"{APPLICATION_COMMIT}:{INERTIA_RESOLVER_PATH}") == INERTIA_RESOLVER_BLOB
    assert sha256_bytes(git_blob_bytes(APPLICATION_COMMIT, INERTIA_RESOLVER_PATH)) == INERTIA_RESOLVER_SHA256
    product_diff = subprocess.run(
        ["git", "diff", "--quiet", APPLICATION_COMMIT, "--", "app", "routes", "resources/js"],
        cwd=REPO_DIR,
        check=False,
    )
    assert product_diff.returncode == 0
    for relative, expected in PINNED_INPUTS.items():
        assert sha256_file(AUDIT_DIR / relative) == expected, relative


def load_matrix_contract() -> tuple[list[dict[str, str]], dict[str, list[str]], dict[str, list[tuple[str, int, int]]], dict[str, list[str]]]:
    with (AUDIT_DIR / "03-feature-to-benchmark-matrix.csv").open(encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    assert len(rows) == 340
    assert len({row["feature_id"] for row in rows}) == 340
    assert Counter(row["feature_class"] for row in rows) == Counter({"H": 300, "D": 40})
    assert {row["feature_identity_status"] for row in rows} == {"STATIC_CANONICAL_IDENTITY_FROZEN"}
    assert {row["completion_status"] for row in rows} == {"INCOMPLETE_CANONICAL_STATIC_IDENTITY_ONLY"}
    assert {row["benchmark_mapping_credit"] for row in rows} == {"false"}

    name_index: dict[str, list[str]] = defaultdict(list)
    anchor_index: dict[str, list[tuple[str, int, int]]] = defaultdict(list)
    page_index: dict[str, list[str]] = defaultdict(list)
    for row in rows:
        feature_id = row["feature_id"]
        for name in split_cell(row["route_names"]):
            name_index[name].append(feature_id)
        for anchor in split_cell(row["route_paths"]):
            match = ANCHOR_RE.fullmatch(anchor)
            assert match, (feature_id, anchor)
            start = int(match.group(2)) if match.group(2) else 1
            end = int(match.group(3) or start) if match.group(2) else 10**9
            anchor_index[match.group(1)].append((feature_id, start, end))
        for page_anchor in split_cell(row["page_files"]):
            page_path = LINE_SUFFIX_RE.sub("", page_anchor)
            page_index[page_path].append(feature_id)
    return rows, name_index, anchor_index, page_index


def route_partition_by_file() -> dict[str, str]:
    partition_by_file: dict[str, str] = {}
    for partition, paths in ROUTE_PARTITION_FILES.items():
        for path in paths:
            assert path not in partition_by_file
            partition_by_file[path] = partition
    return partition_by_file


def extract_route_universe(
    name_index: dict[str, list[str]], anchor_index: dict[str, list[tuple[str, int, int]]]
) -> tuple[list[dict], list[dict], list[dict], list[dict]]:
    partition_by_file = route_partition_by_file()
    route_paths = sorted(
        (
            path.replace("\\", "/")
            for path in git_lines("ls-tree", "-r", "--name-only", APPLICATION_COMMIT, "--", "routes")
            if path.lower().endswith(".php")
        ),
        key=lambda value: value.encode("utf-8"),
    )
    assert route_paths == sorted(partition_by_file)

    route_rows: list[dict] = []
    name_rows: list[dict] = []
    route_like_sentinels: list[dict] = []
    file_summaries: list[dict] = []
    global_route_ordinal = 0
    global_name_ordinal = 0

    for route_path in route_paths:
        raw_bytes = git_blob_bytes(APPLICATION_COMMIT, route_path)
        raw = raw_bytes.decode("utf-8")
        route_file_sha256 = sha256_bytes(raw_bytes)
        route_file_blob_id = git_blob_id(APPLICATION_COMMIT, route_path)
        masked = mask_php_comments(raw)
        route_matches = list(ROUTE_RE.finditer(masked))
        name_matches = list(NAME_RE.finditer(masked))
        intervals: list[tuple[int, int, str]] = []
        file_route_keys: list[str] = []
        file_name_keys: list[str] = []

        for file_ordinal, match in enumerate(route_matches, start=1):
            global_route_ordinal += 1
            method = match.group(1)
            line, column = line_column(raw, match.start())
            open_index = masked.find("(", match.start(), match.end() + 1)
            close_index = find_matching_paren(masked, open_index)
            args = split_top_level_args(raw[open_index + 1 : close_index])
            uri_arg_index = 1 if method == "match" else 0
            uri_expression = args[uri_arg_index] if len(args) > uri_arg_index else None
            statement_end = find_statement_end(masked, match.start())
            statement_raw = raw[match.start() : statement_end + 1]
            statement_masked = masked[match.start() : statement_end + 1]
            inline_names: list[str] = []
            for name_match in NAME_RE.finditer(statement_masked):
                name_open = statement_masked.find("(", name_match.start(), name_match.end() + 1)
                name_close = find_matching_paren(statement_masked, name_open)
                inline_names.append(literal_string(statement_raw[name_open + 1 : name_close]))
            inline_literal_names = sorted({name for name in inline_names if name is not None})
            anchor_candidates = sorted(
                {
                    feature_id
                    for feature_id, start, end in anchor_index.get(route_path, [])
                    if start <= line <= end
                }
            )
            name_candidates = sorted(
                {feature_id for name in inline_literal_names for feature_id in name_index.get(name, [])}
            )
            candidate_ids = sorted(set(anchor_candidates) | set(name_candidates))
            row_id = f"RUN077-ROUTE-{global_route_ordinal:04d}"
            source_key = f"{route_path}:{line}:{column}:{method}:{file_ordinal}"
            row = {
                "row_id": row_id,
                "route_record_id": row_id,
                "source_key": source_key,
                "route_ordinal": global_route_ordinal,
                "partition": partition_by_file[route_path],
                "route_file": route_path,
                "route_file_sha256": route_file_sha256,
                "route_file_blob_id": route_file_blob_id,
                "global_ordinal": global_route_ordinal,
                "file_ordinal": file_ordinal,
                "start_byte": len(raw[: match.start()].encode("utf-8")),
                "source_line": line,
                "source_column": column,
                "line": line,
                "column": column,
                "method": method,
                "source_locator": f"{route_path}:{line}:{column}:{method}",
                "source_anchor": f"{route_path}:{line}",
                "route_method": method,
                "uri_expression": uri_expression,
                "literal_uri": literal_string(uri_expression),
                "action_expression": args[uri_arg_index + 1] if len(args) > uri_arg_index + 1 else None,
                "inline_literal_route_names": inline_literal_names,
                "direct_name_callsite_id": None,
                "direct_name_literal": None,
                "statement_excerpt": compact_excerpt(statement_raw),
                "statement_sha256": sha256_bytes(statement_raw.encode("utf-8")),
                "candidate_feature_ids": candidate_ids,
                "candidate_bases": {
                    "matrix_route_anchor_overlap": anchor_candidates,
                    "matrix_route_name_exact": name_candidates,
                },
                "reviewed_feature_ids": [],
                "ownership_classification": "UNREVIEWED_STATIC_ROUTE_LOCATOR",
                "classification_status": "UNREVIEWED_STATIC_ROUTE_LOCATOR",
                "feature_mapping_status": "NOT_EXECUTED",
                "framework_reachability": "NOT_EXECUTED",
                "credit_awarded": False,
            }
            route_rows.append(row)
            intervals.append((match.start(), statement_end, row_id))
            file_route_keys.append(source_key)

        for file_ordinal, match in enumerate(name_matches, start=1):
            global_name_ordinal += 1
            line, column = line_column(raw, match.start())
            open_index = masked.find("(", match.start(), match.end() + 1)
            close_index = find_matching_paren(masked, open_index)
            expression = raw[open_index + 1 : close_index].strip()
            literal_name = literal_string(expression)
            parents = [item for item in intervals if item[0] <= match.start() <= item[1]]
            parent_id = min(parents, key=lambda item: item[1] - item[0])[2] if parents else None
            candidate_ids = sorted(name_index.get(literal_name, [])) if literal_name is not None else []
            source_key = f"{route_path}:{line}:{column}:name:{file_ordinal}"
            relationship = "DIRECT_COUNTED_ROUTE" if parent_id is not None else None
            group_prefix_kind = None
            route_like_sentinel_id = None
            if relationship is None and route_path == "routes/console.php" and line in {616, 644}:
                relationship = "NON_ROUTE_SCHEDULE"
            elif relationship is None and route_path == "routes/hr.php" and line == 835:
                relationship = "FLUENT_REGISTRAR_ROUTE_OUTSIDE_PRIMARY_DENOMINATOR"
                route_like_sentinel_id = "RUN077-ROUTE-SENTINEL-0001"
                source_lines = raw.splitlines()
                sentinel_statement = "\n".join(source_lines[832:835])
                route_like_sentinels.append(
                    {
                        "row_id": route_like_sentinel_id,
                        "route_record_id": route_like_sentinel_id,
                        "source_key": "routes/hr.php:833:fluent-registrar-get:1",
                        "partition": "A",
                        "route_file": route_path,
                        "route_file_sha256": route_file_sha256,
                        "route_file_blob_id": route_file_blob_id,
                        "source_line_start": 833,
                        "source_line_end": 835,
                        "source_anchor": "routes/hr.php:833-835",
                        "route_declaration_syntax": "Route::middleware(...)->get(...)->name(...) FLUENT_REGISTRAR_CHAIN",
                        "route_method": "get",
                        "literal_uri": "/job-postings",
                        "literal_route_name": "job-postings.index",
                        "statement_excerpt": compact_excerpt(sentinel_statement),
                        "statement_sha256": sha256_bytes(sentinel_statement.encode("utf-8")),
                        "candidate_feature_ids": candidate_ids,
                        "reviewed_feature_ids": [],
                        "ownership_classification": "UNREVIEWED_STATIC_ROUTE_LIKE_SENTINEL",
                        "classification_status": "UNREVIEWED_STATIC_ROUTE_LIKE_SENTINEL",
                        "feature_mapping_status": "NOT_EXECUTED",
                        "framework_reachability": "NOT_EXECUTED",
                        "credit_awarded": False,
                    }
                )
            elif relationship is None:
                context = raw[max(0, match.start() - 1500) : match.start()]
                middleware_offset = context.rfind("Route::middleware")
                prefix_offset = context.rfind("Route::prefix")
                assert max(middleware_offset, prefix_offset) >= 0, (route_path, line)
                relationship = "ROUTE_GROUP_PREFIX"
                group_prefix_kind = "MIDDLEWARE_ROOT" if middleware_offset > prefix_offset else "PREFIX_ROOT"
            name_rows.append(
                {
                    "row_id": f"RUN077-NAME-{global_name_ordinal:04d}",
                    "name_record_id": f"RUN077-NAME-{global_name_ordinal:04d}",
                    "source_key": source_key,
                    "partition": partition_by_file[route_path],
                    "route_file": route_path,
                    "route_file_sha256": route_file_sha256,
                    "route_file_blob_id": route_file_blob_id,
                    "global_ordinal": global_name_ordinal,
                    "file_ordinal": file_ordinal,
                    "start_byte": len(raw[: match.start()].encode("utf-8")),
                    "source_line": line,
                    "source_column": column,
                    "source_anchor": f"{route_path}:{line}",
                    "name_expression": expression,
                    "literal_route_name": literal_name,
                    "parent_route_callsite_id": parent_id,
                    "relationship_classification": relationship,
                    "group_prefix_kind": group_prefix_kind,
                    "route_like_sentinel_id": route_like_sentinel_id,
                    "candidate_feature_ids": candidate_ids,
                    "reviewed_feature_ids": [],
                    "classification": "UNREVIEWED_STATIC_NAME_LOCATOR",
                    "feature_mapping_status": "NOT_EXECUTED",
                    "credit_awarded": False,
                }
            )
            file_name_keys.append(source_key)

        file_summaries.append(
            {
                "route_file": route_path,
                "partition": partition_by_file[route_path],
                "sha256": route_file_sha256,
                "blob_id": route_file_blob_id,
                "route_callsites": len(route_matches),
                "fluent_name_callsites": len(name_matches),
                "route_source_keys_sha256": canonical_list_sha256(file_route_keys),
                "name_source_keys_sha256": canonical_list_sha256(file_name_keys),
            }
        )

    direct_names: dict[str, list[dict]] = defaultdict(list)
    for row in name_rows:
        if row["relationship_classification"] == "DIRECT_COUNTED_ROUTE":
            direct_names[row["parent_route_callsite_id"]].append(row)
    for row in route_rows:
        attached = direct_names.get(row["row_id"], [])
        assert len(attached) <= 1, row["row_id"]
        if attached:
            row["direct_name_callsite_id"] = attached[0]["row_id"]
            row["direct_name_literal"] = attached[0]["literal_route_name"]

    assert len(route_like_sentinels) == 1
    return route_rows, name_rows, file_summaries, route_like_sentinels


def extract_page_universe(page_index: dict[str, list[str]]) -> tuple[list[dict], dict]:
    tracked_paths = git_lines("ls-tree", "-r", "--name-only", APPLICATION_COMMIT, "--")
    php_paths = sorted(
        (path.replace("\\", "/") for path in tracked_paths if path.lower().endswith(".php")),
        key=lambda value: value.encode("utf-8"),
    )
    page_paths = sorted(
        (
            path.replace("\\", "/")
            for path in tracked_paths
            if path.startswith("resources/js/pages/")
            and path.lower().endswith(".tsx")
            and not EXCLUDED_JS_RE.search(path.replace("\\", "/"))
        ),
        key=lambda value: value.encode("utf-8"),
    )
    page_path_set = set(page_paths)
    assert len(page_paths) == 963
    resolver_blob_manifest = [f"{path}\t{git_blob_id(APPLICATION_COMMIT, path)}" for path in page_paths]
    assert canonical_list_sha256(page_paths) == EXPECTED_RESOLVER_PATH_LIST_SHA256
    assert canonical_list_sha256(resolver_blob_manifest) == EXPECTED_RESOLVER_BLOB_MANIFEST_SHA256

    render_calls: list[dict] = []
    php_manifest: list[str] = []
    for php_path in php_paths:
        raw_bytes = git_blob_bytes(APPLICATION_COMMIT, php_path)
        raw = raw_bytes.decode("utf-8")
        php_manifest.append(f"{php_path}\t{sha256_bytes(raw_bytes)}")
        masked = mask_php_comments(raw)
        for match in RENDER_RE.finditer(masked):
            line, column = line_column(raw, match.start())
            render_name = match.group(3)
            page_path = f"resources/js/pages/{render_name}.tsx"
            render_calls.append(
                {
                    "render_name": render_name,
                    "page_file": page_path if page_path in page_path_set else None,
                    "source_file": php_path,
                    "source_file_sha256": sha256_bytes(raw_bytes),
                    "source_file_blob_id": git_blob_id(APPLICATION_COMMIT, php_path),
                    "source_line": line,
                    "source_column": column,
                    "start_byte": len(raw[: match.start()].encode("utf-8")),
                    "source_anchor": f"{php_path}:{line}",
                    "call_kind": match.group(1),
                    "syntax": f"{match.group(1)}({match.group(2)}{render_name}{match.group(2)}, ...)",
                }
            )

    grouped: dict[str, list[dict]] = defaultdict(list)
    for call in render_calls:
        if call["page_file"] is not None:
            grouped[call["render_name"]].append(call)
    assert len(render_calls) == 745
    assert len({call["render_name"] for call in render_calls}) == 722
    assert sum(len(calls) for calls in grouped.values()) == 734
    assert len(grouped) == 711

    page_rows: list[dict] = []
    for ordinal, render_name in enumerate(sorted(grouped, key=lambda value: value.encode("utf-8")), start=1):
        calls = sorted(
            grouped[render_name],
            key=lambda call: (call["source_file"].encode("utf-8"), call["source_line"], call["source_column"]),
        )
        partition = "A" if ordinal <= 237 else "B" if ordinal <= 474 else "C"
        render_names = sorted({call["render_name"] for call in calls})
        assert len(render_names) == 1
        page_path = f"resources/js/pages/{render_name}.tsx"
        assert page_path in page_path_set
        candidate_ids = sorted(set(page_index.get(page_path, [])))
        assert len(candidate_ids) == len(set(candidate_ids))
        row_key = f"page-root|{render_name}"
        page_record_id = f"PAGE-ROOT-{sha256_bytes(row_key.encode('utf-8'))[:16].upper()}"
        page_bytes = git_blob_bytes(APPLICATION_COMMIT, page_path)
        page_rows.append(
            {
                "page_ordinal": ordinal,
                "page_record_id": page_record_id,
                "page_root_id": page_record_id,
                "row_key": row_key,
                "partition": partition,
                "page_root": page_path,
                "render_name": render_name,
                "render_names": render_names,
                "page_file": page_path,
                "page_file_sha256": sha256_bytes(page_bytes),
                "page_file_blob_id": git_blob_id(APPLICATION_COMMIT, page_path),
                "static_identity_status": "EXISTING_LITERAL_BACKEND_RENDER_ROOT",
                "resolver_membership": "RESOLVED_PINNED_RESOLVER_PAGE",
                "render_call_count": len(calls),
                "render_callsites": calls,
                "render_owner_locators": [call["source_anchor"] for call in calls],
                "candidate_feature_ids": candidate_ids,
                "candidate_basis": "EXACT_MATRIX_PAGE_FILE_PATH_OVERLAP" if candidate_ids else "NO_EXACT_MATRIX_PAGE_FILE_PATH_OVERLAP",
                "reviewed_feature_ids": [],
                "prompt_classification_status": "NOT_ADJUDICATED_CURRENT_AUDIT",
                "feature_mapping_status": "NOT_EXECUTED",
                "independent_review_status": "PENDING",
                "architecture_boundary": "ONE_OPERATING_ORGANISATION_MULTIPLE_SITES",
                "framework_reachability": "NOT_EXECUTED",
                "build_resolution": "NOT_EXECUTED",
                "browser_observation": "NOT_EXECUTED",
                "credit_awarded": False,
            }
        )

    missing_calls = [call for call in render_calls if call["page_file"] is None]
    page_root_paths = [row["page_file"] for row in page_rows]
    page_root_blob_manifest = [f"{row['page_file']}\t{row['page_file_blob_id']}" for row in page_rows]
    page_row_keys = [row["row_key"] for row in page_rows]
    assert canonical_list_sha256(page_root_paths) == EXPECTED_PAGE_ROOT_PATH_LIST_SHA256
    assert canonical_list_sha256(page_root_blob_manifest) == EXPECTED_PAGE_ROOT_BLOB_MANIFEST_SHA256
    assert canonical_list_sha256(page_row_keys) == EXPECTED_PAGE_ROW_KEY_LIST_SHA256
    assert len({row["page_record_id"] for row in page_rows}) == 711
    assert Counter(row["render_call_count"] for row in page_rows) == Counter({1: 689, 2: 21, 3: 1})

    for partition in ("A", "B", "C"):
        rows = [row for row in page_rows if row["partition"] == partition]
        expected = EXPECTED_PAGE_PARTITIONS[partition]
        assert len(rows) == 237
        assert rows[0]["render_name"] == expected["first_render_name"]
        assert rows[-1]["render_name"] == expected["last_render_name"]
        assert rows[0]["page_record_id"] == expected["first_id"]
        assert rows[-1]["page_record_id"] == expected["last_id"]
        assert canonical_list_sha256([row["row_key"] for row in rows]) == expected["row_key_sha256"]
        assert canonical_list_sha256([row["page_file"] for row in rows]) == expected["path_sha256"]

    page_scope = {
        "tracked_php_paths": len(php_paths),
        "tracked_php_path_list_sha256": canonical_list_sha256(php_paths),
        "tracked_php_blob_manifest_sha256": canonical_list_sha256(php_manifest),
        "resolver_non_test_tsx_paths": len(page_paths),
        "resolver_non_test_tsx_path_list_sha256": canonical_list_sha256(page_paths),
        "resolver_non_test_tsx_blob_manifest_sha256": canonical_list_sha256(resolver_blob_manifest),
        "literal_render_callsites": len(render_calls),
        "existing_render_callsites": sum(len(calls) for calls in grouped.values()),
        "unique_render_names": len({call["render_name"] for call in render_calls}),
        "existing_file_backed_page_roots": len(grouped),
        "page_root_path_list_sha256": canonical_list_sha256(page_root_paths),
        "page_root_blob_manifest_sha256": canonical_list_sha256(page_root_blob_manifest),
        "page_row_key_list_sha256": canonical_list_sha256(page_row_keys),
        "repeated_page_roots": sum(row["render_call_count"] > 1 for row in page_rows),
        "missing_render_targets": sorted({call["render_name"] for call in missing_calls}),
        "missing_render_callsites": missing_calls,
    }
    return page_rows, page_scope


def build_residual_contract(
    matrix_rows: list[dict[str, str]], integration: dict
) -> tuple[dict, dict]:
    gaps = integration["remaining_gaps"]
    matrix_by_id = {row["feature_id"]: row for row in matrix_rows}
    ordinal_by_id = {row["feature_id"]: ordinal for ordinal, row in enumerate(matrix_rows, start=1)}
    scoped_fields = ("route_paths", "page_files", "backend_anchors", "test_anchors")
    recomputed = {
        field: [row["feature_id"] for row in matrix_rows if row[field] == "NOT_ESTABLISHED_CURRENT_AUDIT"]
        for field in scoped_fields
    }
    for field in scoped_fields:
        assert recomputed[field] == gaps[field], field
    recomputed_any = [
        row["feature_id"]
        for row in matrix_rows
        if any(row[field] == "NOT_ESTABLISHED_CURRENT_AUDIT" for field in scoped_fields)
    ]
    assert recomputed_any == gaps["any_scoped_field"]
    recomputed_route_names = [
        row["feature_id"]
        for row in matrix_rows
        if row["route_names"] == "NOT_ESTABLISHED_CURRENT_AUDIT"
    ]
    assert recomputed_route_names == gaps["route_names"]
    assert gaps["any_scoped_field"] == EXPECTED_RESIDUAL_TARGETS
    assert len(gaps["route_paths"]) == 1
    assert len(gaps["page_files"]) == 6
    assert gaps["backend_anchors"] == []
    assert len(gaps["test_anchors"]) == 8
    assert len(gaps["route_names"]) == 244
    assert gaps["both_route_and_page"] == sorted(set(gaps["route_paths"]) & set(gaps["page_files"]))

    residual_records: list[dict] = []
    for feature_id in recomputed_any:
        row = matrix_by_id[feature_id]
        missing_fields = [field for field in scoped_fields if row[field] == "NOT_ESTABLISHED_CURRENT_AUDIT"]
        residual_records.append(
            {
                "matrix_ordinal": ordinal_by_id[feature_id],
                "feature_id": feature_id,
                "module": row["module"],
                "feature_class": row["feature_class"],
                "missing_fields": missing_fields,
                "original_values": {field: row[field] for field in missing_fields},
            }
        )
    assert sum(len(record["missing_fields"]) for record in residual_records) == 15

    route_name_records = [
        {
            "matrix_ordinal": ordinal_by_id[feature_id],
            "feature_id": feature_id,
            "module": matrix_by_id[feature_id]["module"],
            "feature_class": matrix_by_id[feature_id]["feature_class"],
            "original_value": matrix_by_id[feature_id]["route_names"],
        }
        for feature_id in recomputed_route_names
    ]
    assert Counter(record["feature_class"] for record in route_name_records) == Counter({"H": 220, "D": 24})
    assert len(set(recomputed_route_names) & set(recomputed_any)) == 7

    residual_contract = {
        "status": "OPEN_EXACT_SCOPED_STATIC_LINKAGE_SENTINELS",
        "scoped_fields": list(scoped_fields),
        "counts": {
            "targets": 12,
            "cells": 15,
            "route_paths": 1,
            "page_files": 6,
            "backend_anchors": 0,
            "test_anchors": 8,
            "both_route_and_page": 1,
        },
        "field_feature_ids": {field: gaps[field] for field in scoped_fields},
        "both_route_and_page_feature_ids": gaps["both_route_and_page"],
        "records": residual_records,
        "records_sha256": canonical_json_sha256(residual_records),
        "boundary": "Adjacent route, page, or test anchors must not be inherited. Confirmed headless, absent-UI, and no-direct-test sentinels remain open unless exact pinned source is established.",
    }
    route_name_contract = {
        "status": "OPEN_SEPARATE_ROUTE_NAME_SENTINEL_LANE",
        "count": 244,
        "H_targets": 220,
        "D_targets": 24,
        "overlap_with_residual_scoped_targets": 7,
        "records": route_name_records,
        "feature_id_list_sha256": canonical_list_sha256(recomputed_route_names),
        "records_sha256": canonical_json_sha256(route_name_records),
        "included_in_15_scoped_cell_denominator": False,
    }
    return residual_contract, route_name_contract


def main() -> None:
    validate_pins()
    matrix_rows, name_index, anchor_index, page_index = load_matrix_contract()
    integration = read_json("evidence/source/current-static-linkage-integration-wave-06.json")
    route_census = read_json("evidence/source/current-route-navigation-gap-wave-01.json")
    semantic_census = read_json("evidence/source/current-static-semantic-census.json")
    page_adjudication = read_json("evidence/source/current-page-adjudication-wave-01.json")

    route_rows, name_rows, route_files, route_like_sentinels = extract_route_universe(name_index, anchor_index)
    page_rows, page_scope = extract_page_universe(page_index)
    residual_scoped_gaps, route_name_gaps = build_residual_contract(matrix_rows, integration)

    canonical_targets = [
        {
            "matrix_ordinal": ordinal,
            "feature_id": row["feature_id"],
            "module": row["module"],
            "feature_class": row["feature_class"],
        }
        for ordinal, row in enumerate(matrix_rows, start=1)
    ]
    matrix_feature_ids = {row["feature_id"] for row in matrix_rows}
    assert len(canonical_targets) == len(matrix_feature_ids) == 340
    assert integration["matrix"]["updated_sha256"] == PINNED_INPUTS["03-feature-to-benchmark-matrix.csv"]
    immutable_columns = integration["matrix"]["immutable_columns"]
    benchmark_columns = integration["matrix"]["benchmark_and_credit_columns"]
    assert matrix_projection_sha256(matrix_rows, immutable_columns) == integration["matrix"]["updated_immutable_projection_sha256"]
    assert matrix_projection_sha256(matrix_rows, benchmark_columns) == integration["matrix"]["updated_benchmark_and_credit_projection_sha256"]
    assert integration["matrix"]["base_immutable_projection_sha256"] == integration["matrix"]["updated_immutable_projection_sha256"]
    assert integration["matrix"]["base_benchmark_and_credit_projection_sha256"] == integration["matrix"]["updated_benchmark_and_credit_projection_sha256"]

    assert len(route_rows) == route_census["route_denominator"]["static_route_declaration_callsites"] == 3217
    assert len(name_rows) == route_census["route_denominator"]["fluent_name_callsites"] == 3245
    assert len(route_like_sentinels) == 1
    assert Counter(row["route_method"] for row in route_rows) == Counter(EXPECTED_METHOD_COUNTS)
    relationship_counts = Counter(row["relationship_classification"] for row in name_rows)
    assert relationship_counts == Counter(
        {
            "DIRECT_COUNTED_ROUTE": 3189,
            "ROUTE_GROUP_PREFIX": 53,
            "FLUENT_REGISTRAR_ROUTE_OUTSIDE_PRIMARY_DENOMINATOR": 1,
            "NON_ROUTE_SCHEDULE": 2,
        }
    )
    assert Counter(
        row["group_prefix_kind"]
        for row in name_rows
        if row["relationship_classification"] == "ROUTE_GROUP_PREFIX"
    ) == Counter({"MIDDLEWARE_ROOT": 27, "PREFIX_ROOT": 26})
    assert sum(row["direct_name_callsite_id"] is not None for row in route_rows) == 3189
    assert sum(row["direct_name_callsite_id"] is None for row in route_rows) == 28
    assert route_like_sentinels[0]["source_anchor"] == "routes/hr.php:833-835"
    assert route_like_sentinels[0]["literal_route_name"] == "job-postings.index"
    assert canonical_list_sha256([row["source_key"] for row in route_like_sentinels]) == EXPECTED_ROUTE_LIKE_SENTINEL_SOURCE_KEY_SHA256

    route_census_by_file = {
        row["route_file"]: row for row in route_census["route_denominator"]["rows"]
    }
    assert set(route_census_by_file) == {row["route_file"] for row in route_files}
    for row in route_files:
        census_row = route_census_by_file[row["route_file"]]
        assert row["route_callsites"] == census_row["route_callsites"]
        assert row["fluent_name_callsites"] == census_row["name_callsites"]
        assert census_row["classification"] in {"R", "C", "A", "M/P"}
        row["prior_census_classification"] = census_row["classification"]
        row["prior_census_accounted_family"] = census_row["accounted_family"]

    route_path_list = [row["route_file"] for row in route_files]
    route_blob_manifest = [f"{row['route_file']}\t{row['blob_id']}" for row in route_files]
    route_locators = [
        f"{row['route_file']}:{row['source_line']}:{row['source_column']}:{row['route_method']}"
        for row in route_rows
    ]
    name_locators = [
        f"{row['route_file']}:{row['source_line']}:{row['source_column']}"
        for row in name_rows
    ]
    assert canonical_list_sha256(route_path_list) == EXPECTED_ROUTE_FILE_LIST_SHA256
    assert canonical_list_sha256(route_blob_manifest) == EXPECTED_ROUTE_BLOB_MANIFEST_SHA256
    assert canonical_list_sha256(route_locators) == EXPECTED_ROUTE_LOCATOR_SHA256
    assert canonical_list_sha256(name_locators) == EXPECTED_NAME_LOCATOR_SHA256

    assert len(page_rows) == semantic_census["inertia_pages"]["existing_render_roots"] == 711
    assert page_scope["literal_render_callsites"] == page_adjudication["reproduction"]["php_render_callsites"] == 745
    assert page_scope["existing_render_callsites"] == 734
    assert page_scope["unique_render_names"] == page_adjudication["reproduction"]["unique_backend_render_names"] == 722
    assert page_scope["missing_render_targets"] == semantic_census["inertia_pages"]["missing_render_targets"]

    excluded_non_roots = page_adjudication["candidate_adjudication"]
    missing_liabilities = page_adjudication["missing_render_target_adjudication"]
    assert len(excluded_non_roots) == 25
    assert len(missing_liabilities) == 11
    assert canonical_list_sha256([row["path"] for row in excluded_non_roots]) == "89db7b14d5e8db8acd9e85dd633cb5cdb86707315eb4c4fef5d9429b0c008361"
    assert canonical_json_sha256(excluded_non_roots) == "1a8c494b83a7e9351fbd5673421d34f5ee0a9d17b86863e5100984e8979ac2a0"
    assert Counter(row["prompt_classification"] for row in excluded_non_roots) == Counter(
        {"Redirect/legacy": 10, "Duplicate": 10, "Dead/unreachable": 3, "Out of product scope": 2}
    )
    assert canonical_list_sha256([row["target"] for row in missing_liabilities]) == "eacf10bbf65c6c3b9922fbdbec19d06bb8dd3e98bbe96c31d6e0e97954ee697c"
    assert canonical_json_sha256(missing_liabilities) == "993f28efd2a9d28887916326fb81beb3161e1501a4bf0a9f01b4d83b786a86a5"
    assert Counter(row["classification"] for row in missing_liabilities) == Counter(
        {"retired_unreachable_render_literal": 4, "unrouted_stub_render_literal": 7}
    )
    assert sorted(row["target"] for row in missing_liabilities) == page_scope["missing_render_targets"]
    assert {
        (row["target"], row["render_call"]) for row in missing_liabilities
    } == {
        (row["render_name"], row["source_anchor"])
        for row in page_scope["missing_render_callsites"]
    }
    assert not ({row["path"] for row in excluded_non_roots} & {row["page_file"] for row in page_rows})

    residual_ids_sorted = sorted(
        (record["feature_id"] for record in residual_scoped_gaps["records"]),
        key=lambda value: value.encode("utf-8"),
    )
    route_name_ids_sorted = sorted(
        (record["feature_id"] for record in route_name_gaps["records"]),
        key=lambda value: value.encode("utf-8"),
    )
    partition_records: list[dict] = []
    all_route_partition_ids: list[str] = []
    all_name_partition_ids: list[str] = []
    all_sentinel_partition_ids: list[str] = []
    all_page_partition_ids: list[str] = []
    all_residual_partition_ids: list[str] = []
    all_route_name_gap_partition_ids: list[str] = []
    for partition in ("A", "B", "C"):
        partition_route_rows = [row for row in route_rows if row["partition"] == partition]
        partition_name_rows = [row for row in name_rows if row["partition"] == partition]
        partition_sentinels = [row for row in route_like_sentinels if row["partition"] == partition]
        partition_page_rows = [row for row in page_rows if row["partition"] == partition]
        route_record_ids = [row["route_record_id"] for row in partition_route_rows]
        name_record_ids = [row["name_record_id"] for row in partition_name_rows]
        sentinel_record_ids = [row["route_record_id"] for row in partition_sentinels]
        page_record_ids = [row["page_record_id"] for row in partition_page_rows]
        residual_feature_ids = residual_ids_sorted[{"A": 0, "B": 4, "C": 8}[partition] : {"A": 4, "B": 8, "C": 12}[partition]]
        route_name_gap_feature_ids = route_name_ids_sorted[
            {"A": 0, "B": 82, "C": 163}[partition] : {"A": 82, "B": 163, "C": 244}[partition]
        ]
        partition_counts = {
            "route_files": sum(1 for row in route_files if row["partition"] == partition),
            "primary_route_facade_callsites": len(partition_route_rows),
            "route_like_sentinels_outside_primary_denominator": len(partition_sentinels),
            "static_route_like_review_rows": len(partition_route_rows) + len(partition_sentinels),
            "fluent_name_callsites": len(partition_name_rows),
            "page_roots": len(partition_page_rows),
            "residual_scoped_targets": len(residual_feature_ids),
            "separate_route_name_gap_targets": len(route_name_gap_feature_ids),
        }
        for key, expected in EXPECTED_PARTITION_COUNTS[partition].items():
            translated_key = "primary_route_facade_callsites" if key == "route_callsites" else key
            assert partition_counts[translated_key] == expected, (partition, key)
        assert partition_counts["static_route_like_review_rows"] == EXPECTED_PARTITION_COUNTS[partition]["route_callsites"] + (1 if partition == "A" else 0)
        assert canonical_list_sha256([row["source_key"] for row in partition_route_rows]) == EXPECTED_ROUTE_PARTITION_SOURCE_KEY_SHA256[partition]
        assert canonical_list_sha256([row["source_key"] for row in partition_name_rows]) == EXPECTED_NAME_PARTITION_SOURCE_KEY_SHA256[partition]
        partition_records.append(
            {
                "partition_id": partition,
                "counts": partition_counts,
                "route_record_ids": route_record_ids,
                "name_record_ids": name_record_ids,
                "route_like_sentinel_ids": sentinel_record_ids,
                "page_record_ids": page_record_ids,
                "residual_feature_ids": residual_feature_ids,
                "route_name_gap_feature_ids": route_name_gap_feature_ids,
                "identity_hashes": {
                    "route_record_id_list_sha256": canonical_list_sha256(route_record_ids),
                    "route_source_key_list_sha256": canonical_list_sha256([row["source_key"] for row in partition_route_rows]),
                    "name_record_id_list_sha256": canonical_list_sha256(name_record_ids),
                    "name_source_key_list_sha256": canonical_list_sha256([row["source_key"] for row in partition_name_rows]),
                    "route_like_sentinel_id_list_sha256": canonical_list_sha256(sentinel_record_ids) if sentinel_record_ids else None,
                    "route_like_sentinel_source_key_list_sha256": canonical_list_sha256([row["source_key"] for row in partition_sentinels]) if partition_sentinels else None,
                    "page_record_id_list_sha256": canonical_list_sha256(page_record_ids),
                    "page_row_key_list_sha256": canonical_list_sha256([row["row_key"] for row in partition_page_rows]),
                },
            }
        )
        all_route_partition_ids.extend(route_record_ids)
        all_name_partition_ids.extend(name_record_ids)
        all_sentinel_partition_ids.extend(sentinel_record_ids)
        all_page_partition_ids.extend(page_record_ids)
        all_residual_partition_ids.extend(residual_feature_ids)
        all_route_name_gap_partition_ids.extend(route_name_gap_feature_ids)

    route_source_keys = [row["source_key"] for row in route_rows]
    name_source_keys = [row["source_key"] for row in name_rows]
    page_row_keys = [row["row_key"] for row in page_rows]
    assert len(route_source_keys) == len(set(route_source_keys))
    assert len(name_source_keys) == len(set(name_source_keys))
    assert len(page_row_keys) == len(set(page_row_keys))
    assert len(all_route_partition_ids) == len(set(all_route_partition_ids)) == 3217
    assert set(all_route_partition_ids) == {row["route_record_id"] for row in route_rows}
    assert len(all_name_partition_ids) == len(set(all_name_partition_ids)) == 3245
    assert set(all_name_partition_ids) == {row["name_record_id"] for row in name_rows}
    assert all_sentinel_partition_ids == ["RUN077-ROUTE-SENTINEL-0001"]
    assert len(all_page_partition_ids) == len(set(all_page_partition_ids)) == 711
    assert set(all_page_partition_ids) == {row["page_record_id"] for row in page_rows}
    assert len(all_residual_partition_ids) == len(set(all_residual_partition_ids)) == 12
    assert set(all_residual_partition_ids) == set(residual_ids_sorted)
    assert len(all_route_name_gap_partition_ids) == len(set(all_route_name_gap_partition_ids)) == 244
    assert set(all_route_name_gap_partition_ids) == set(route_name_ids_sorted)
    assert all(
        set(row["candidate_feature_ids"]).issubset(matrix_feature_ids)
        and not row["reviewed_feature_ids"]
        and not row["credit_awarded"]
        for row in route_rows + name_rows + route_like_sentinels + page_rows
    )

    manifest = {
        "schema_version": 2,
        "run_id": "RUN-077-ROUTE-PAGE-UNIVERSE-MANIFEST",
        "status": "PRIMARY_ROUTE_METHOD_SCOPE_PLUS_ROUTE_LIKE_SENTINEL_AND_PAGE_UNIVERSE_PARTITIONED_UNREVIEWED_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": GENERATED_ON,
        "pins": {
            "checkpoint_commit": CHECKPOINT_COMMIT,
            "checkpoint_tree": CHECKPOINT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "routes_tree": ROUTES_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "resources_js_pages_tree": RESOURCES_JS_PAGES_TREE,
            "inertia_resolver_path": INERTIA_RESOLVER_PATH,
            "inertia_resolver_blob": INERTIA_RESOLVER_BLOB,
            "inertia_resolver_sha256": INERTIA_RESOLVER_SHA256,
            "prompt_sha256": PROMPT_SHA256,
            "generator": f"generators/{Path(__file__).name}",
            "generator_sha256": current_generator_sha256(),
            "inputs": PINNED_INPUTS,
            "matrix_base_immutable_projection_sha256": integration["matrix"]["base_immutable_projection_sha256"],
            "matrix_updated_immutable_projection_sha256": integration["matrix"]["updated_immutable_projection_sha256"],
            "matrix_base_benchmark_and_credit_projection_sha256": integration["matrix"]["base_benchmark_and_credit_projection_sha256"],
            "matrix_updated_benchmark_and_credit_projection_sha256": integration["matrix"]["updated_benchmark_and_credit_projection_sha256"],
        },
        "architecture_rule": "One operating organisation across multiple Sites; roles, permissions, approved Sites, canonical ownership, direct-object concealment, and privacy boundaries apply. This is not tenant-isolation evidence.",
        "scope": {
            "methods": [
                "Exact comment-masked raw Route::<method>(...) and fluent ->name(...) callsite scan over the 38 pinned route PHP files.",
                "Comment-masked literal Inertia::render(...) and inertia(...) scan over all 5,854 pinned tracked PHP files.",
                "Exact case-sensitive resources/js/pages/<render-name>.tsx resolution against the 963 pinned non-test resolver TSX paths.",
                "Matrix route-anchor, route-name, and page-file overlap is candidate context only and awards no mapping credit.",
            ],
            "primary_route_method_names": list(ROUTE_METHODS),
            "primary_route_method_scope": "Exactly the nine Route::<method>(...) source-call syntaxes listed here. This is not an exhaustive denominator for every Laravel route-declaration syntax.",
            "route_like_sentinel_scope": "One Route::middleware(...)->get(...)->name(...) fluent-registrar declaration at routes/hr.php:833-835 is retained outside the 3,217 primary denominator.",
            "group_prefix_boundary": "The 53 Route group-prefix name callsites are relationship-classified only; this manifest does not propagate or claim effective runtime route names.",
            "page_root_scope": "711 exact case-sensitive existing file-backed literal backend render roots; 25 prior non-roots and 11 missing render literals stay outside this denominator.",
        },
        "counts": {
            "canonical_targets": len(matrix_rows),
            "H_targets": sum(row["feature_class"] == "H" for row in matrix_rows),
            "D_targets": sum(row["feature_class"] == "D" for row in matrix_rows),
            "route_files": len(route_files),
            "primary_route_facade_callsites": len(route_rows),
            "route_like_sentinels_outside_primary_denominator": len(route_like_sentinels),
            "static_route_like_review_rows": len(route_rows) + len(route_like_sentinels),
            "fluent_name_callsites": len(name_rows),
            "literal_name_callsites": sum(row["literal_route_name"] is not None for row in name_rows),
            "name_callsites_linked_to_primary_route_facade_callsite": sum(row["parent_route_callsite_id"] is not None for row in name_rows),
            "route_group_prefix_name_callsites": relationship_counts["ROUTE_GROUP_PREFIX"],
            "non_route_schedule_name_callsites": relationship_counts["NON_ROUTE_SCHEDULE"],
            "primary_route_facade_callsites_with_matrix_candidates": sum(bool(row["candidate_feature_ids"]) for row in route_rows),
            "name_callsites_with_matrix_candidates": sum(bool(row["candidate_feature_ids"]) for row in name_rows),
            "page_roots": len(page_rows),
            "page_roots_with_matrix_candidates": sum(bool(row["candidate_feature_ids"]) for row in page_rows),
            "page_render_callsites": page_scope["literal_render_callsites"],
            "existing_page_render_callsites": page_scope["existing_render_callsites"],
            "remaining_scoped_targets": 12,
            "remaining_scoped_cells": 15,
            "separate_route_name_gap_targets": 244,
            "final_feature_mappings": 0,
            "framework_routes_executed": 0,
            "runtime_credit": 0,
            "application_browser_credit": 0,
            "executed_test_credit": 0,
            "benchmark_mapping_credit": 0,
            "pass_credit": 0,
            "completion_credit": 0,
        },
        "canonical_targets": canonical_targets,
        "residual_scoped_gaps": residual_scoped_gaps,
        "route_name_gaps": route_name_gaps,
        "route_universe": {
            "status": "STATIC_IDENTITY_MATERIALIZED_CLASSIFICATION_NOT_EXECUTED",
            "denominator_rule": "3,217 is the exact nine-method Route::<method>(...) scope; one additional route-like fluent-registrar declaration is an explicit out-of-denominator sentinel.",
            "source_identity": {
                "route_file_list_sha256": canonical_list_sha256(route_path_list),
                "route_blob_manifest_sha256": canonical_list_sha256(route_blob_manifest),
                "primary_route_locator_list_sha256": canonical_list_sha256(route_locators),
                "fluent_name_locator_list_sha256": canonical_list_sha256(name_locators),
                "primary_route_source_key_list_sha256": canonical_list_sha256(route_source_keys),
                "fluent_name_source_key_list_sha256": canonical_list_sha256(name_source_keys),
                "route_like_sentinel_source_key_list_sha256": canonical_list_sha256([row["source_key"] for row in route_like_sentinels]),
            },
            "method_counts": dict(sorted(Counter(row["route_method"] for row in route_rows).items())),
            "name_relationship_counts": dict(sorted(relationship_counts.items())),
            "group_prefix_kind_counts": {"MIDDLEWARE_ROOT": 27, "PREFIX_ROOT": 26},
            "supplemental_registration_counts": {
                "broadcast_channel_registrations": route_census["route_denominator"]["broadcast_channel_registrations"],
                "schedule_registrations": route_census["route_denominator"]["schedule_registrations"],
                "artisan_closure_registrations": route_census["route_denominator"]["artisan_closure_registrations"],
            },
            "route_files": route_files,
            "primary_route_facade_callsites": route_rows,
            "fluent_name_callsites": name_rows,
            "route_like_sentinels": route_like_sentinels,
        },
        "page_universe": {
            "status": "STATIC_FILE_BACKED_RENDER_IDENTITY_MATERIALIZED_PROMPT_CLASSIFICATION_NOT_EXECUTED",
            "source_identity": page_scope,
            "page_roots": page_rows,
            "excluded_prior_non_roots": {
                "count": 25,
                "path_list_sha256": canonical_list_sha256([row["path"] for row in excluded_non_roots]),
                "records_sha256": canonical_json_sha256(excluded_non_roots),
                "records": excluded_non_roots,
                "credit_awarded": False,
            },
            "missing_backend_render_liabilities": {
                "count": 11,
                "target_list_sha256": canonical_list_sha256([row["target"] for row in missing_liabilities]),
                "records_sha256": canonical_json_sha256(missing_liabilities),
                "records": missing_liabilities,
                "credit_awarded": False,
            },
        },
        "partitions": {
            "assignment_rules": {
                "routes_and_names": "Explicit balanced pinned route-file allocation A/B/C.",
                "route_like_sentinel": "The single hr.php fluent-registrar sentinel follows route partition A but remains outside the 3,217 denominator.",
                "pages": "Exact render-name UTF-8 byte ordering split into contiguous 237-row partitions.",
                "residual_scoped_targets": "Exact feature-ID UTF-8 byte ordering split 4/4/4.",
                "separate_route_name_gaps": "Exact feature-ID UTF-8 byte ordering split 82/81/81.",
            },
            "records": partition_records,
            "all_lanes_exhaustive_and_pairwise_disjoint_within_lane": True,
        },
        "review_contract": {
            "allowed_page_prompt_classifications": [
                "Reviewed",
                "Redirect/legacy",
                "Generated/vendor",
                "Duplicate",
                "Dead/unreachable",
                "Out of product scope",
                "Evidence gap",
            ],
            "producer_required_top_level_keys": [
                "schema_version",
                "run_id",
                "status",
                "generated_on",
                "pins",
                "partition_id",
                "route_decisions",
                "name_decisions",
                "page_decisions",
                "residual_scoped_decisions",
                "route_name_gap_decisions",
                "completion_test",
                "credit_boundary",
                "wrote_files",
                "attestation",
            ],
            "producer_required_pin_bindings": [
                "manifest_sha256",
                "checkpoint_commit",
                "checkpoint_tree",
                "application_commit",
                "application_tree",
                "partition_id",
            ],
            "producer_required_route_decision_keys": [
                "route_record_id",
                "classification",
                "reviewed_feature_ids",
                "source_anchors",
                "rationale",
            ],
            "producer_required_name_decision_keys": [
                "name_record_id",
                "relationship_classification_confirmed",
                "reviewed_feature_ids",
                "source_anchors",
                "rationale",
            ],
            "producer_required_page_decision_keys": [
                "page_record_id",
                "prompt_classification",
                "reviewed_feature_ids",
                "source_anchors",
                "rationale",
            ],
            "producer_required_residual_scoped_decision_keys": [
                "feature_id",
                "missing_field_decisions",
                "source_anchors",
                "rationale",
            ],
            "producer_required_route_name_gap_decision_keys": [
                "feature_id",
                "route_name_decision",
                "source_anchors",
                "rationale",
            ],
            "producer_count_parity": "Every assigned route, name, page, residual-scoped, and separate route-name-gap ID must have exactly one decision; no extra IDs are permitted.",
            "independent_reviewer_required_bindings": [
                "manifest_sha256",
                "checkpoint_commit",
                "application_commit",
                "partition_id",
                "every producer decision",
                "all-false credit boundary",
                "wrote_files=false",
                "attestation",
            ],
            "allowed_ownership_classifications": [
                "OWNER",
                "PROJECTION",
                "ALIAS_OR_REDIRECT",
                "HEADLESS_API_OR_BACKGROUND",
                "DEAD_OR_UNREACHABLE",
                "SHARED_RELATION",
                "EXPLICIT_UNMAPPED_SENTINEL",
            ],
            "required_for_each_row": [
                "exact reviewed FEATURE-ID list or explicit unmapped sentinel",
                "ownership classification",
                "exact pinned source anchor",
                "bounded rationale",
            ],
            "candidate_overlap_is_not_mapping": True,
            "adjacent_anchor_inheritance_prohibited": True,
            "cyclic_review": "A reviews B, B reviews C, C reviews A; root alone normalizes and integrates.",
        },
        "completion_boundary": COMPLETION_BOUNDARY,
        "credit_boundary": CREDIT_BOUNDARY,
        "attestation": "Audit-only deterministic static manifest creation. No Laravel boot, framework/provider route expansion, runtime, database, application browser, build, test execution, benchmark mapping, ease measurement, release, Pass, completion, or application-source change occurred.",
    }

    assert set(manifest) == EXPECTED_TOP_LEVEL_KEYS
    assert manifest["pins"]["matrix_base_immutable_projection_sha256"] == manifest["pins"]["matrix_updated_immutable_projection_sha256"]
    assert manifest["pins"]["matrix_base_benchmark_and_credit_projection_sha256"] == manifest["pins"]["matrix_updated_benchmark_and_credit_projection_sha256"]
    assert not any(CREDIT_BOUNDARY.values())
    assert not any(COMPLETION_BOUNDARY.values())

    encoded = (json.dumps(manifest, indent=2, ensure_ascii=False) + "\n").encode("utf-8")
    candidate = json.loads(encoded.decode("utf-8"))
    assert candidate == manifest
    assert set(candidate) == EXPECTED_TOP_LEVEL_KEYS
    candidate_sha256 = sha256_bytes(encoded)
    assert OUTPUT_PATH.parent.is_dir()
    temporary = OUTPUT_PATH.with_name(OUTPUT_PATH.name + ".tmp-run077")
    assert not temporary.exists(), temporary
    if OUTPUT_PATH.exists():
        existing_sha256 = sha256_file(OUTPUT_PATH)
        assert existing_sha256 in ALLOWED_PREDECESSOR_OUTPUT_SHA256S | {candidate_sha256}, existing_sha256
    try:
        temporary.write_bytes(encoded)
        assert json.loads(temporary.read_text(encoding="utf-8")) == manifest
        assert sha256_file(temporary) == candidate_sha256
        os.replace(temporary, OUTPUT_PATH)
    finally:
        if temporary.exists():
            temporary.unlink()
    assert sha256_file(OUTPUT_PATH) == candidate_sha256
    assert json.loads(OUTPUT_PATH.read_text(encoding="utf-8")) == manifest
    print(
        json.dumps(
            {
                "status": manifest["status"],
                "output": OUTPUT_REL,
                "sha256": candidate_sha256,
                "primary_route_facade_callsites": len(route_rows),
                "route_like_sentinels": len(route_like_sentinels),
                "fluent_name_callsites": len(name_rows),
                "page_roots": len(page_rows),
                "partitions": {row["partition_id"]: row["counts"] for row in partition_records},
                "downstream_credit": 0,
            },
            separators=(",", ":"),
        )
    )


if __name__ == "__main__":
    main()
