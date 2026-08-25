#!/usr/bin/env python3
"""Build the pinned RUN-084 full Inertia page-tree and import-graph census.

This generator is intentionally source-only.  It enumerates every tracked file
under resources/js/pages at the pinned application commit, preserves the prior
reviewed classifications, and exposes previously hidden imported support paths.
It never promotes a support/import relation into FEATURE-ID, runtime, browser,
test, Pass, or completion credit.
"""

from __future__ import annotations

import hashlib
import json
import os
import posixpath
import re
import subprocess
from collections import Counter, defaultdict, deque
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-084-full-inertia-page-graph-wave-09.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

AUDIT_HEAD = "cdf8721d339f770c176ebee7688868547885500a"
AUDIT_TREE = "b787f46e02a08a1d538ce7741af39d96763143a3"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
RESOURCES_JS_TREE = "1671a7551c004571c48bb00c34522928e6f1f173"
PAGES_TREE = "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e"
INERTIA_RESOLVER_PATH = "resources/js/inertia-pages.ts"
INERTIA_RESOLVER_BLOB = "2fe0a1341c68d28cae26835f6c36df194ef7e8f9"
INERTIA_RESOLVER_SHA256 = "8b74c20ba277a684a584456a256fabead4f002c6e90d337e6f17fbfed9e5f562"

PINNED_INPUTS = {
    "AGENTS.md": "2972a5612c834f4745010658aeaa2cd4640d4d7a29d932e9952e406c790718ed",
    "03-feature-to-benchmark-matrix.csv": "dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390",
    "evidence/source/current-page-adjudication-wave-01.json": "50c7cb41cff93dcc4aa57f90f43fec508a759be42b622211b06f851ba5fa405c",
    "evidence/source/root-run-077-route-page-universe-manifest-wave-07.json": "150fcff9b100ad85a7a2e998ed69c8dafdc2d0098e8ec2b4dbac7d3b404061be",
    "evidence/source/current-route-page-classification-wave-07.json": "7b534fea15e6c4ec98fbb2cb0c761e26116b2abee500c39e599b0e779729df97",
    "evidence/source/current-route-page-independent-review-wave-07.json": "3910255856a757e612b6f6d75522fe394ac19e4e011836c1aedbcfd29eb344be",
    "evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json": "3c6ad4df13a09ae8ff8f19aee09a1907f9754a1877806659676c6c2898652f85",
    "evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json": "a6a4f886ca209bc41ffa86afec37f6bddaf062ac80a6b375391adeea20e1c396",
}
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

EXPECTED_COUNTS = {
    "page_tree_files": 1058,
    "tsx_files": 1007,
    "production_non_test_tsx": 963,
    "excluded_test_spec_story_tsx": 44,
    "ts_support_or_test_files": 51,
    "production_ts_helpers": 32,
    "test_spec_ts_files": 19,
    "literal_rendered_page_roots": 711,
    "imported_support_components": 227,
    "adjudicated_unrendered_unimported_non_roots": 25,
}
EXPECTED_PRODUCTION_PATH_SHA256 = "1ba9fbc49d0fd8185392561afe2abefd1d66d7264c96a804da93768e45cc8f55"
EXPECTED_ROOT_PATH_SHA256 = "a847a52ea3342a3ed53ba4860fcb41bcb0d33f44b7fa1545c6d623ae41fb2702"
EXPECTED_SUPPORT_PATH_SHA256 = "c9f165c3896e189ed38ffa2b69ed632cefb03abf56f927707133c58ac6c937ff"
EXPECTED_NON_ROOT_PATH_SHA256 = "89db7b14d5e8db8acd9e85dd633cb5cdb86707315eb4c4fef5d9429b0c008361"

EXCLUDED_TSX_RE = re.compile(
    r"(?:^|/)(?:__tests__|__snapshots__|tests?|stories)(?:/|$)|"
    r"\.(?:test|spec|stories|story)\.(?:js|jsx|ts|tsx)$",
    re.IGNORECASE,
)
CODE_EXTENSIONS = (".ts", ".tsx", ".js", ".jsx", ".mjs", ".cjs")
RESOLUTION_EXTENSIONS = (".ts", ".tsx", ".d.ts", ".js", ".jsx", ".mjs", ".cjs")
APPLICATION_DIFF_PATHS = (
    "app",
    "bootstrap",
    "config",
    "routes",
    "resources/js",
    "composer.json",
    "composer.lock",
    "package.json",
    "package-lock.json",
    "vite.config.ts",
)


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_list_sha256(values: list[str] | set[str]) -> str:
    ordered = sorted(values)
    return sha256_bytes(("\n".join(ordered) + "\n").encode("utf-8"))


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def git(*args: str, text: bool = True) -> str | bytes:
    completed = subprocess.run(
        ["git", *args],
        cwd=REPO,
        check=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=text,
    )
    return completed.stdout


def git_blob(commit: str, path: str) -> bytes:
    return git("show", f"{commit}:{path}", text=False)  # type: ignore[return-value]


def git_tree_entries(commit: str, prefix: str) -> dict[str, str]:
    raw = git("ls-tree", "-r", "-z", "--full-tree", commit, "--", prefix, text=False)
    result: dict[str, str] = {}
    for record in raw.split(b"\0"):  # type: ignore[union-attr]
        if not record:
            continue
        metadata, raw_path = record.split(b"\t", 1)
        object_id = metadata.split()[2].decode("ascii")
        result[raw_path.decode("utf-8").replace("\\", "/")] = object_id
    return result


def git_blobs_by_id(object_ids: set[str]) -> dict[str, bytes]:
    ordered = sorted(object_ids)
    completed = subprocess.run(
        ["git", "cat-file", "--batch"],
        cwd=REPO,
        input=("\n".join(ordered) + "\n").encode("ascii"),
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=True,
    )
    raw_output = completed.stdout
    offset = 0
    result: dict[str, bytes] = {}
    for requested in ordered:
        header_end = raw_output.index(b"\n", offset)
        header = raw_output[offset:header_end].decode("ascii").split()
        assert len(header) == 3 and header[0] == requested and header[1] == "blob", (requested, header)
        size = int(header[2])
        offset = header_end + 1
        raw = raw_output[offset : offset + size]
        assert len(raw) == size
        offset += size
        assert raw_output[offset : offset + 1] == b"\n"
        offset += 1
        result[requested] = raw
    assert offset == len(raw_output)
    return result


def load_json(relative: str) -> dict[str, Any]:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def assert_pins() -> None:
    assert git("rev-parse", "HEAD").strip() == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}").strip() == AUDIT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}").strip() == APPLICATION_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}:resources/js").strip() == RESOURCES_JS_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}:resources/js/pages").strip() == PAGES_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}:{INERTIA_RESOLVER_PATH}").strip() == INERTIA_RESOLVER_BLOB
    assert sha256_bytes(git_blob(APPLICATION_COMMIT, INERTIA_RESOLVER_PATH)) == INERTIA_RESOLVER_SHA256
    assert sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for relative, expected in PINNED_INPUTS.items():
        path = REPO / relative if relative == "AGENTS.md" else AUDIT_DIR / relative
        assert sha256_file(path) == expected, relative
    diff = git("diff", "--name-only", APPLICATION_COMMIT, AUDIT_HEAD, "--", *APPLICATION_DIFF_PATHS)
    assert not diff.strip(), diff


def decode_js_string(raw: str) -> str:
    # Module paths in this tree are ordinary slash-delimited literals.  Decode
    # only the escapes that can occur in such paths; leave unusual escapes as a
    # recorded unresolved literal rather than inventing a target.
    return raw.replace("\\/", "/").replace("\\\\", "\\").replace("\\'", "'").replace('\\"', '"')


def lexical_tokens(source: str) -> list[dict[str, Any]]:
    """Return identifiers, strings and punctuation while excluding comments/templates."""
    tokens: list[dict[str, Any]] = []
    length = len(source)
    i = 0
    line = 1
    while i < length:
        char = source[i]
        if char in " \t\r":
            i += 1
            continue
        if char == "\n":
            line += 1
            i += 1
            continue
        if source.startswith("//", i):
            end = source.find("\n", i + 2)
            i = length if end < 0 else end
            continue
        if source.startswith("/*", i):
            end = source.find("*/", i + 2)
            if end < 0:
                end = length - 2
            line += source.count("\n", i, end + 2)
            i = end + 2
            continue
        if char == "/":
            previous = tokens[-1] if tokens else None
            previous_value = previous["value"] if previous else None
            next_char = source[i + 1] if i + 1 < length else ""
            regex_context = previous is None or previous_value in {
                "(", "[", "{", "=", ":", ",", ";", "!", "?", "&", "|", "+", "-", "*", "%", "^", "~", ">"
            } or (previous and previous["kind"] == "identifier" and previous_value in {"return", "throw", "case", "delete", "void", "typeof", "yield"})
            if regex_context and not (previous_value == "<" and (next_char.isalpha() or next_char in "_")):
                start = i
                start_line = line
                i += 1
                in_character_class = False
                while i < length:
                    if source[i] == "\\":
                        i += 2
                        continue
                    if source[i] == "\n":
                        line += 1
                        break
                    if source[i] == "[":
                        in_character_class = True
                    elif source[i] == "]":
                        in_character_class = False
                    elif source[i] == "/" and not in_character_class:
                        i += 1
                        while i < length and source[i].isalpha():
                            i += 1
                        break
                    i += 1
                tokens.append({"kind": "regex", "value": "", "line": start_line, "start": start})
                continue
        if char == "`":
            start = i
            start_line = line
            i += 1
            while i < length:
                if source[i] == "\\":
                    if i + 1 < length and source[i + 1] == "\n":
                        line += 1
                    i += 2
                    continue
                if source[i] == "\n":
                    line += 1
                if source[i] == "`":
                    i += 1
                    break
                i += 1
            tokens.append({"kind": "template", "value": "", "line": start_line, "start": start})
            continue
        if char in "'\"":
            quote = char
            start = i
            start_line = line
            i += 1
            value_start = i
            pieces: list[str] = []
            piece_start = i
            while i < length:
                if source[i] == "\\":
                    pieces.append(source[piece_start:i])
                    if i + 1 < length:
                        pieces.append(source[i : i + 2])
                        if source[i + 1] == "\n":
                            line += 1
                    i += 2
                    piece_start = i
                    continue
                if source[i] == "\n":
                    line += 1
                if source[i] == quote:
                    pieces.append(source[piece_start:i])
                    i += 1
                    break
                i += 1
            raw_value = "".join(pieces) if pieces else source[value_start : max(value_start, i - 1)]
            tokens.append(
                {"kind": "string", "value": decode_js_string(raw_value), "line": start_line, "start": start}
            )
            continue
        if char.isalpha() or char in "_$":
            start = i
            i += 1
            while i < length and (source[i].isalnum() or source[i] in "_$"):
                i += 1
            tokens.append({"kind": "identifier", "value": source[start:i], "line": line, "start": start})
            continue
        tokens.append({"kind": "punctuation", "value": char, "line": line, "start": i})
        i += 1
    return tokens


def line_leading(source: str, start: int) -> bool:
    beginning = source.rfind("\n", 0, start) + 1
    return not source[beginning:start].strip()


def extract_module_references(source: str, importer: str) -> list[dict[str, Any]]:
    tokens = lexical_tokens(source)
    references: list[dict[str, Any]] = []

    def append(token: dict[str, Any], spec_token: dict[str, Any], kind: str, type_only: bool) -> None:
        references.append(
            {
                "importer": importer,
                "source_anchor": f"{importer}:{token['line']}",
                "source_line": token["line"],
                "module_specifier": spec_token["value"],
                "reference_kind": kind,
                "type_only": type_only,
            }
        )

    def clause_is_type_only(start: int, end: int) -> bool:
        clause = tokens[start:end]
        if clause and clause[0]["value"] == "type":
            return True
        if not clause or clause[0]["value"] != "{" or clause[-1]["value"] != "}":
            return False
        segments: list[list[dict[str, Any]]] = []
        segment: list[dict[str, Any]] = []
        depth = 0
        for item in clause[1:-1]:
            if item["value"] in {"{", "(", "["}:
                depth += 1
            elif item["value"] in {"}", ")", "]"}:
                depth -= 1
            if item["value"] == "," and depth == 0:
                if segment:
                    segments.append(segment)
                segment = []
            else:
                segment.append(item)
        if segment:
            segments.append(segment)
        return bool(segments) and all(
            next((item["value"] for item in part if item["kind"] == "identifier"), None) == "type"
            for part in segments
        )

    for index, token in enumerate(tokens):
        if token["kind"] != "identifier":
            continue
        value = token["value"]
        if value == "import":
            if index + 2 < len(tokens) and tokens[index + 1]["value"] == "(" and tokens[index + 2]["kind"] == "string":
                close_index = index + 3
                while close_index < len(tokens) and tokens[close_index]["value"] != ")" and close_index < index + 8:
                    close_index += 1
                type_query = (
                    close_index + 2 < len(tokens)
                    and tokens[close_index]["value"] == ")"
                    and tokens[close_index + 1]["value"] == "."
                    and tokens[close_index + 2]["kind"] == "identifier"
                    and tokens[close_index + 2]["value"] not in {"then", "catch", "finally"}
                )
                append(token, tokens[index + 2], "TYPE_QUERY_IMPORT" if type_query else "DYNAMIC_IMPORT", type_query)
                continue
            if not line_leading(source, token["start"]):
                continue
            if index + 1 < len(tokens) and tokens[index + 1]["kind"] == "string":
                append(token, tokens[index + 1], "STATIC_SIDE_EFFECT_IMPORT", False)
                continue
            limit = min(len(tokens), index + 220)
            for cursor in range(index + 1, limit):
                candidate = tokens[cursor]
                if candidate["value"] == ";":
                    break
                if candidate["line"] > token["line"] + 60:
                    break
                if (
                    cursor > index + 1
                    and candidate["kind"] == "identifier"
                    and candidate["value"] in {"import", "export"}
                    and line_leading(source, candidate["start"])
                ):
                    break
                if candidate["kind"] == "identifier" and candidate["value"] == "from":
                    if cursor + 1 < len(tokens) and tokens[cursor + 1]["kind"] == "string":
                        append(token, tokens[cursor + 1], "STATIC_IMPORT", clause_is_type_only(index + 1, cursor))
                    break
        elif value == "export" and line_leading(source, token["start"]):
            limit = min(len(tokens), index + 220)
            for cursor in range(index + 1, limit):
                candidate = tokens[cursor]
                if candidate["value"] == ";":
                    break
                if candidate["line"] > token["line"] + 60:
                    break
                if candidate["kind"] == "identifier" and candidate["value"] == "from":
                    if cursor + 1 < len(tokens) and tokens[cursor + 1]["kind"] == "string":
                        append(token, tokens[cursor + 1], "EXPORT_FROM", clause_is_type_only(index + 1, cursor))
                    break
        elif value == "require":
            if index + 2 < len(tokens) and tokens[index + 1]["value"] == "(" and tokens[index + 2]["kind"] == "string":
                append(token, tokens[index + 2], "REQUIRE", False)

    unique: dict[tuple[Any, ...], dict[str, Any]] = {}
    for reference in references:
        key = (
            reference["source_line"],
            reference["module_specifier"],
            reference["reference_kind"],
            reference["type_only"],
        )
        unique[key] = reference
    return [unique[key] for key in sorted(unique)]


def internal_base(importer: str, specifier: str) -> str | None:
    if specifier.startswith("@/"):
        return posixpath.normpath("resources/js/" + specifier[2:])
    if specifier.startswith("./") or specifier.startswith("../"):
        return posixpath.normpath(posixpath.join(posixpath.dirname(importer), specifier))
    return None


def resolve_reference(
    importer: str,
    specifier: str,
    tracked_paths: set[str],
    code_paths: set[str],
) -> dict[str, Any]:
    base = internal_base(importer, specifier)
    if base is None:
        return {"status": "EXTERNAL_PACKAGE_OR_ALIAS", "target": None, "candidates": []}
    if not base.startswith("resources/js/"):
        return {"status": "OUTSIDE_RESOURCES_JS_SCOPE", "target": base, "candidates": [base]}
    if base in tracked_paths and base not in code_paths:
        return {"status": "RESOLVED_NON_CODE_ASSET", "target": base, "candidates": [base]}

    candidates: list[str] = []
    if base in code_paths:
        candidates.append(base)
    suffix = posixpath.splitext(base)[1].lower()
    if not suffix:
        candidates.extend(candidate for extension in RESOLUTION_EXTENSIONS if (candidate := base + extension) in code_paths)
        candidates.extend(
            candidate
            for extension in RESOLUTION_EXTENSIONS
            if (candidate := posixpath.join(base, "index" + extension)) in code_paths
        )
    elif suffix in {".js", ".jsx"} and base not in code_paths:
        stem = base[: -len(suffix)]
        candidates.extend(candidate for extension in (".ts", ".tsx") if (candidate := stem + extension) in code_paths)

    ordered = list(dict.fromkeys(candidates))
    if not ordered:
        if specifier == "@/routes" or specifier.startswith("@/routes/") or specifier == "@/actions" or specifier.startswith("@/actions/") or specifier == "@/wayfinder" or specifier.startswith("@/wayfinder/"):
            return {"status": "GENERATED_MODULE_ABSENT_AT_APPLICATION_PIN", "target": None, "candidates": []}
        return {"status": "UNRESOLVED_INTERNAL_LITERAL", "target": None, "candidates": []}
    status = "RESOLVED_EXACT" if len(ordered) == 1 else "RESOLVED_BY_BUNDLER_PRECEDENCE_WITH_SHADOWS"
    return {"status": status, "target": ordered[0], "candidates": ordered}


def strongly_connected_components(nodes: list[str], outgoing: dict[str, set[str]]) -> list[list[str]]:
    index = 0
    indices: dict[str, int] = {}
    lowlinks: dict[str, int] = {}
    stack: list[str] = []
    on_stack: set[str] = set()
    components: list[list[str]] = []

    def visit(node: str) -> None:
        nonlocal index
        indices[node] = index
        lowlinks[node] = index
        index += 1
        stack.append(node)
        on_stack.add(node)
        for target in sorted(outgoing.get(node, set())):
            if target not in indices:
                visit(target)
                lowlinks[node] = min(lowlinks[node], lowlinks[target])
            elif target in on_stack:
                lowlinks[node] = min(lowlinks[node], indices[target])
        if lowlinks[node] == indices[node]:
            component: list[str] = []
            while True:
                member = stack.pop()
                on_stack.remove(member)
                component.append(member)
                if member == node:
                    break
            components.append(sorted(component))

    for node in nodes:
        if node not in indices:
            visit(node)
    return sorted(components, key=lambda component: component[0])


def ancestor_roots(path: str, incoming: dict[str, set[str]], roots: set[str]) -> tuple[list[str], list[str]]:
    direct = sorted(incoming.get(path, set()) & roots)
    reached: set[str] = set()
    visited: set[str] = {path}
    queue: deque[str] = deque(sorted(incoming.get(path, set())))
    while queue:
        importer = queue.popleft()
        if importer in visited:
            continue
        visited.add(importer)
        if importer in roots:
            reached.add(importer)
        for parent in sorted(incoming.get(importer, set())):
            if parent not in visited:
                queue.append(parent)
    return direct, sorted(reached)


def build() -> dict[str, Any]:
    assert_pins()

    all_js_entries = git_tree_entries(APPLICATION_COMMIT, "resources/js")
    blob_bytes_by_id = git_blobs_by_id(set(all_js_entries.values()))
    tracked_paths = set(all_js_entries)
    code_paths = {path for path in tracked_paths if path.lower().endswith(CODE_EXTENSIONS)}
    page_entries = {path: blob for path, blob in all_js_entries.items() if path.startswith("resources/js/pages/")}
    page_paths = sorted(page_entries)
    tsx_paths = {path for path in page_paths if path.lower().endswith(".tsx")}
    excluded_tsx = {path for path in tsx_paths if EXCLUDED_TSX_RE.search(path)}
    production_tsx = tsx_paths - excluded_tsx
    ts_paths = set(page_paths) - tsx_paths
    test_spec_ts = {path for path in ts_paths if EXCLUDED_TSX_RE.search(path)}
    production_ts_helpers = ts_paths - test_spec_ts

    manifest = load_json("evidence/source/root-run-077-route-page-universe-manifest-wave-07.json")
    page_adjudication = load_json("evidence/source/current-page-adjudication-wave-01.json")
    classification = load_json("evidence/source/current-route-page-classification-wave-07.json")
    independent_review = load_json("evidence/source/current-route-page-independent-review-wave-07.json")
    run_082 = load_json("evidence/source/root-run-082-exact-owner-containment-candidate-manifest-wave-08.json")
    run_082r = load_json("evidence/source/raw-run-082r-independent-exact-owner-containment-review-wave-08.json")

    root_rows = manifest["page_universe"]["page_roots"]
    roots_by_path = {row["page_file"]: row for row in root_rows}
    roots = set(roots_by_path)
    prior_non_root_rows = page_adjudication["candidate_adjudication"]
    prior_non_roots_by_path = {row["path"]: row for row in prior_non_root_rows}
    prior_non_roots = set(prior_non_roots_by_path)
    support_paths = production_tsx - roots - prior_non_roots

    assert len(page_paths) == EXPECTED_COUNTS["page_tree_files"]
    assert len(tsx_paths) == EXPECTED_COUNTS["tsx_files"]
    assert len(production_tsx) == EXPECTED_COUNTS["production_non_test_tsx"]
    assert len(excluded_tsx) == EXPECTED_COUNTS["excluded_test_spec_story_tsx"]
    assert len(ts_paths) == EXPECTED_COUNTS["ts_support_or_test_files"]
    assert len(production_ts_helpers) == EXPECTED_COUNTS["production_ts_helpers"]
    assert len(test_spec_ts) == EXPECTED_COUNTS["test_spec_ts_files"]
    assert len(roots) == EXPECTED_COUNTS["literal_rendered_page_roots"]
    assert len(support_paths) == EXPECTED_COUNTS["imported_support_components"]
    assert len(prior_non_roots) == EXPECTED_COUNTS["adjudicated_unrendered_unimported_non_roots"]
    assert roots | support_paths | prior_non_roots == production_tsx
    assert not (roots & support_paths or roots & prior_non_roots or support_paths & prior_non_roots)
    assert canonical_list_sha256(production_tsx) == EXPECTED_PRODUCTION_PATH_SHA256
    assert canonical_list_sha256(roots) == EXPECTED_ROOT_PATH_SHA256
    assert canonical_list_sha256(support_paths) == EXPECTED_SUPPORT_PATH_SHA256
    assert canonical_list_sha256(prior_non_roots) == EXPECTED_NON_ROOT_PATH_SHA256

    decisions_by_id = {row["page_record_id"]: row for row in classification["page_decisions"]}
    assert set(decisions_by_id) == {row["page_root_id"] for row in root_rows}
    decisions = {
        path: decisions_by_id[root_row["page_root_id"]]
        for path, root_row in roots_by_path.items()
    }
    assert set(decisions) == roots
    assert independent_review["status"] == "THREE_PART_CYCLIC_INDEPENDENT_REVIEW_GO_ZERO_DOWNSTREAM_CREDIT"
    assert run_082["credit_boundary"]["static_candidate_relation_as_feature_mapping"] is False
    assert run_082r["status"] == "GO_STATIC_CANDIDATE_CENSUS_REVIEWED_ZERO_DOWNSTREAM_CREDIT"
    assert run_082r["verdict"]["decision"] == "GO"
    assert run_082r["verdict"]["feature_mapping_authorized"] is False

    outgoing: dict[str, set[str]] = defaultdict(set)
    incoming: dict[str, set[str]] = defaultdict(set)
    production_outgoing: dict[str, set[str]] = defaultdict(set)
    production_incoming: dict[str, set[str]] = defaultdict(set)
    incoming_edges: dict[str, list[dict[str, Any]]] = defaultdict(list)
    production_incoming_edges: dict[str, list[dict[str, Any]]] = defaultdict(list)
    type_incoming_edges: dict[str, list[dict[str, Any]]] = defaultdict(list)
    all_references: list[dict[str, Any]] = []
    unresolved: list[dict[str, Any]] = []
    generated_absent: list[dict[str, Any]] = []
    outside_scope: list[dict[str, Any]] = []
    shadowed: list[dict[str, Any]] = []

    for importer in sorted(code_paths):
        raw = blob_bytes_by_id[all_js_entries[importer]]
        source = raw.decode("utf-8", errors="strict")
        for reference in extract_module_references(source, importer):
            resolution = resolve_reference(importer, reference["module_specifier"], tracked_paths, code_paths)
            record = {**reference, **resolution}
            all_references.append(record)
            if resolution["status"] == "UNRESOLVED_INTERNAL_LITERAL":
                unresolved.append(record)
                continue
            if resolution["status"] == "GENERATED_MODULE_ABSENT_AT_APPLICATION_PIN":
                generated_absent.append(record)
                continue
            if resolution["status"] == "OUTSIDE_RESOURCES_JS_SCOPE":
                outside_scope.append(record)
                continue
            if resolution["status"] == "RESOLVED_BY_BUNDLER_PRECEDENCE_WITH_SHADOWS":
                shadowed.append(record)
            target = resolution["target"]
            if not target or target not in code_paths:
                continue
            edge = {
                "importer": importer,
                "target": target,
                "source_anchor": reference["source_anchor"],
                "source_line": reference["source_line"],
                "module_specifier": reference["module_specifier"],
                "reference_kind": reference["reference_kind"],
                "type_only": reference["type_only"],
                "resolution_status": resolution["status"],
            }
            if reference["type_only"]:
                type_incoming_edges[target].append(edge)
            else:
                outgoing[importer].add(target)
                incoming[target].add(importer)
                incoming_edges[target].append(edge)
                if not EXCLUDED_TSX_RE.search(importer):
                    production_outgoing[importer].add(target)
                    production_incoming[target].add(importer)
                    production_incoming_edges[target].append(edge)

    components = strongly_connected_components(sorted(code_paths), outgoing)
    cyclic_components = [
        component
        for component in components
        if len(component) > 1 or (len(component) == 1 and component[0] in outgoing.get(component[0], set()))
    ]
    page_cycle_components = [component for component in cyclic_components if any(path in page_entries for path in component)]
    cycle_by_path: dict[str, str] = {}
    cycle_records: list[dict[str, Any]] = []
    for component in page_cycle_components:
        component_id = "IMPORT-CYCLE-" + sha256_bytes(("\n".join(component) + "\n").encode("utf-8"))[:16].upper()
        for path in component:
            cycle_by_path[path] = component_id
        cycle_records.append(
            {
                "cycle_component_id": component_id,
                "member_count": len(component),
                "page_tree_member_count": sum(path in page_entries for path in component),
                "members": component,
                "credit_awarded": False,
            }
        )

    records: list[dict[str, Any]] = []
    for ordinal, path in enumerate(page_paths, start=1):
        raw = blob_bytes_by_id[page_entries[path]]
        row_key = f"page-file|{path}"
        row_id = "PAGE-FILE-" + sha256_bytes(row_key.encode("utf-8"))[:16].upper()
        direct_roots, transitive_roots = ancestor_roots(path, production_incoming, roots)
        direct_edges = sorted(
            incoming_edges.get(path, []),
            key=lambda edge: (edge["importer"], edge["source_line"], edge["module_specifier"], edge["reference_kind"]),
        )
        production_direct_edges = sorted(
            production_incoming_edges.get(path, []),
            key=lambda edge: (edge["importer"], edge["source_line"], edge["module_specifier"], edge["reference_kind"]),
        )
        type_edges = sorted(
            type_incoming_edges.get(path, []),
            key=lambda edge: (edge["importer"], edge["source_line"], edge["module_specifier"], edge["reference_kind"]),
        )

        if path in roots:
            partition = "LITERAL_RENDERED_PAGE_ROOT"
            decision = decisions[path]
            prompt_classification = decision["prompt_classification"]
            classification_basis = decision.get(
                "decision_basis",
                f"RUN_078_PARTITION_{decision['partition_id']}_STATIC_PAGE_CLASSIFICATION",
            )
            page_root_id = roots_by_path[path]["page_root_id"]
            root_source_anchors = decision["source_anchors"]
            candidate_feature_ids = decision["reviewed_feature_ids"]
            owner_status = "SELF_RENDERED_ROOT"
        elif path in support_paths:
            partition = "IMPORTED_SUPPORT_COMPONENT"
            prompt_classification = "Evidence gap"
            classification_basis = "PINNED_963_PARTITION_SUPPORT_PATH_EXPOSED_BY_RUN_084"
            page_root_id = None
            root_source_anchors = []
            candidate_feature_ids = sorted(
                {
                    feature_id
                    for root in transitive_roots
                    for feature_id in decisions[root]["reviewed_feature_ids"]
                }
            )
            if len(transitive_roots) == 0:
                owner_status = "ORPHAN_SUPPORT_CANDIDATE"
            elif len(transitive_roots) == 1:
                owner_status = "SINGLE_RENDERED_ROOT_OWNER_CANDIDATE"
            else:
                owner_status = "SHARED_SUPPORT_CANDIDATE"
        elif path in prior_non_roots:
            partition = "ADJUDICATED_UNRENDERED_UNIMPORTED_NON_ROOT"
            prior = prior_non_roots_by_path[path]
            prompt_classification = prior["prompt_classification"]
            classification_basis = "PINNED_INDEPENDENT_PAGE_ADJUDICATION_WAVE_01"
            page_root_id = None
            root_source_anchors = prior["closest_ownership_anchors"]
            candidate_feature_ids = []
            owner_status = "ADJUDICATED_NON_ROOT"
        elif path in excluded_tsx:
            partition = "EXCLUDED_TEST_SPEC_STORY_TSX"
            prompt_classification = "Out of product scope"
            classification_basis = "PATH_CONVENTION_EXCLUDED_FROM_PRODUCTION_NON_TEST_TSX_DENOMINATOR"
            page_root_id = None
            root_source_anchors = []
            candidate_feature_ids = []
            owner_status = "TEST_SPEC_STORY_NOT_PRODUCTION_PAGE"
        elif path in test_spec_ts:
            partition = "PAGE_TREE_TS_TEST_SPEC"
            prompt_classification = "Out of product scope"
            classification_basis = "NON_TSX_TEST_SPEC_PATH_DOES_NOT_MATCH_PINNED_INERTIA_GLOB"
            page_root_id = None
            root_source_anchors = []
            candidate_feature_ids = []
            owner_status = "NON_TSX_TEST_SPEC"
        else:
            partition = "PAGE_TREE_TS_PRODUCTION_HELPER"
            prompt_classification = "Evidence gap"
            classification_basis = "NON_TSX_PRODUCTION_HELPER_OUTSIDE_PINNED_INERTIA_GLOB"
            page_root_id = None
            root_source_anchors = []
            candidate_feature_ids = []
            owner_status = "NON_TSX_PRODUCTION_HELPER"

        records.append(
            {
                "page_file_ordinal": ordinal,
                "page_file_id": row_id,
                "row_key": row_key,
                "path": path,
                "extension": Path(path).suffix.lower(),
                "blob_id": page_entries[path],
                "sha256": sha256_bytes(raw),
                "partition": partition,
                "inertia_glob_pattern_match": path.lower().endswith(".tsx"),
                "effective_production_resolver_denominator_member": path in production_tsx,
                "production_non_test_tsx_denominator_member": path in production_tsx,
                "literal_backend_render_root": path in roots,
                "page_root_id": page_root_id,
                "prompt_classification": prompt_classification,
                "prompt_classification_basis": classification_basis,
                "root_source_anchors": root_source_anchors,
                "all_source_direct_value_import_count": len(direct_edges),
                "all_source_direct_value_imports": direct_edges,
                "production_direct_value_import_count": len(production_direct_edges),
                "production_direct_value_imports": production_direct_edges,
                "direct_type_only_import_count": len(type_edges),
                "direct_type_only_imports": type_edges,
                "direct_rendered_root_count": len(direct_roots),
                "direct_rendered_root_paths": direct_roots,
                "transitive_rendered_root_count": len(transitive_roots),
                "transitive_rendered_root_paths": transitive_roots,
                "transitive_rendered_root_ids": [roots_by_path[root]["page_root_id"] for root in transitive_roots],
                "root_owner_candidate_status": owner_status,
                "candidate_feature_ids_from_reviewed_root_rows": candidate_feature_ids,
                "cycle_component_id": cycle_by_path.get(path),
                "feature_mapping_status": "NOT_FINAL_NO_IMPORT_INHERITANCE",
                "feature_mapping_credit": False,
                "framework_reachability": "NOT_EXECUTED",
                "build_resolution": "NOT_EXECUTED",
                "browser_observation": "NOT_EXECUTED",
                "runtime_credit": False,
                "executed_test_credit": False,
                "pass_credit": False,
                "completion_credit": False,
            }
        )

    partition_counts = Counter(record["partition"] for record in records)
    prompt_counts = Counter(
        record["prompt_classification"]
        for record in records
        if record["production_non_test_tsx_denominator_member"]
    )
    support_owner_counts = Counter(
        record["root_owner_candidate_status"]
        for record in records
        if record["partition"] == "IMPORTED_SUPPORT_COMPONENT"
    )
    assert partition_counts == Counter(
        {
            "LITERAL_RENDERED_PAGE_ROOT": 711,
            "IMPORTED_SUPPORT_COMPONENT": 227,
            "ADJUDICATED_UNRENDERED_UNIMPORTED_NON_ROOT": 25,
            "EXCLUDED_TEST_SPEC_STORY_TSX": 44,
            "PAGE_TREE_TS_PRODUCTION_HELPER": 32,
            "PAGE_TREE_TS_TEST_SPEC": 19,
        }
    )
    assert prompt_counts == Counter(
        {
            "Reviewed": 318,
            "Evidence gap": 620,
            "Redirect/legacy": 10,
            "Duplicate": 10,
            "Dead/unreachable": 3,
            "Out of product scope": 2,
        }
    )

    page_incoming_edge_records = sorted(
        [edge for target, edges in incoming_edges.items() if target in page_entries for edge in edges],
        key=lambda edge: (edge["target"], edge["importer"], edge["source_line"], edge["module_specifier"]),
    )
    production_page_incoming_edge_records = sorted(
        [edge for target, edges in production_incoming_edges.items() if target in page_entries for edge in edges],
        key=lambda edge: (edge["target"], edge["importer"], edge["source_line"], edge["module_specifier"]),
    )
    type_page_incoming_edge_records = sorted(
        [edge for target, edges in type_incoming_edges.items() if target in page_entries for edge in edges],
        key=lambda edge: (edge["target"], edge["importer"], edge["source_line"], edge["module_specifier"]),
    )
    unresolved = sorted(
        unresolved,
        key=lambda row: (row["importer"], row["source_line"], row["module_specifier"], row["reference_kind"]),
    )
    generated_absent = sorted(
        generated_absent,
        key=lambda row: (row["importer"], row["source_line"], row["module_specifier"], row["reference_kind"]),
    )
    outside_scope = sorted(
        outside_scope,
        key=lambda row: (row["importer"], row["source_line"], row["module_specifier"], row["reference_kind"]),
    )
    shadowed = sorted(
        shadowed,
        key=lambda row: (row["importer"], row["source_line"], row["module_specifier"], row["reference_kind"]),
    )

    return {
        "schema_version": "run-084-full-inertia-page-graph-wave-09-v1",
        "run_id": "RUN-084-PAGE-GRAPH",
        "status": "STATIC_FULL_PAGE_TREE_CANDIDATE_CENSUS_PENDING_INDEPENDENT_REVIEW_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": "2026-08-25",
        "pins": {
            "audit_head": AUDIT_HEAD,
            "audit_tree": AUDIT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "resources_js_tree": RESOURCES_JS_TREE,
            "pages_tree": PAGES_TREE,
            "inertia_resolver_path": INERTIA_RESOLVER_PATH,
            "inertia_resolver_blob": INERTIA_RESOLVER_BLOB,
            "inertia_resolver_sha256": INERTIA_RESOLVER_SHA256,
            "prompt_sha256": PROMPT_SHA256,
            "generator_sha256": sha256_file(Path(__file__).resolve()),
            "inputs": PINNED_INPUTS,
            "non_audit_application_paths_changed_from_application_pin": 0,
        },
        "methods": [
            "Enumerated the committed resources/js/pages tree and blob identities from the pinned application commit, never from mutable filesystem contents.",
            "Applied the prior exclusion convention only to the production non-test TSX denominator; all excluded TSX and all .ts paths remain explicit rows.",
            "Preserved the independently reviewed RUN-078/079 root classifications and the RUN-010/011 adjudication of the 25 unrendered/unimported non-roots.",
            "Tokenized committed JS/TS source to exclude comments and template bodies, then resolved literal static imports, export-from declarations, dynamic imports, and require calls through exact relative or @/ paths.",
            "Separated type-only relations and all-source test/spec edges from production value-import reachability, then computed reverse direct/transitive reachability to the 711 literal rendered roots using production importers only.",
            "Classified support-owner relations as candidates only; import presence, singleton reachability, and root FEATURE-ID candidates never propagate mapping credit.",
        ],
        "denominators": {
            **EXPECTED_COUNTS,
            "production_partition_sum": len(roots) + len(support_paths) + len(prior_non_roots),
            "page_tree_path_list_sha256": canonical_list_sha256(page_paths),
            "tsx_path_list_sha256": canonical_list_sha256(tsx_paths),
            "production_non_test_tsx_path_list_sha256": canonical_list_sha256(production_tsx),
            "excluded_test_spec_story_tsx_path_list_sha256": canonical_list_sha256(excluded_tsx),
            "ts_support_or_test_path_list_sha256": canonical_list_sha256(ts_paths),
            "production_ts_helper_path_list_sha256": canonical_list_sha256(production_ts_helpers),
            "test_spec_ts_path_list_sha256": canonical_list_sha256(test_spec_ts),
            "literal_rendered_root_path_list_sha256": canonical_list_sha256(roots),
            "imported_support_path_list_sha256": canonical_list_sha256(support_paths),
            "adjudicated_non_root_path_list_sha256": canonical_list_sha256(prior_non_roots),
        },
        "classification": {
            "page_tree_partition_counts": dict(sorted(partition_counts.items())),
            "production_non_test_tsx_prompt_classification_counts": dict(sorted(prompt_counts.items())),
            "production_non_test_tsx_classified": len(production_tsx),
            "production_non_test_tsx_total": len(production_tsx),
            "static_structural_classification_percentage": "100.00%",
            "imported_support_root_owner_candidate_counts": dict(sorted(support_owner_counts.items())),
            "scope_boundary": "Static page-tree and import-graph classification only; framework route reachability, build resolution, browser observation, task execution, usability, FEATURE-ID mapping, and overall completion remain unproved.",
        },
        "import_graph": {
            "tracked_resources_js_paths": len(tracked_paths),
            "tracked_resources_js_code_paths": len(code_paths),
            "literal_module_reference_count": len(all_references),
            "value_import_edge_count": sum(len(targets) for targets in outgoing.values()),
            "production_value_import_edge_count": sum(len(targets) for targets in production_outgoing.values()),
            "all_source_page_tree_value_import_edge_count": len(page_incoming_edge_records),
            "all_source_page_tree_value_import_edge_records_sha256": canonical_json_sha256(page_incoming_edge_records),
            "production_page_tree_value_import_edge_count": len(production_page_incoming_edge_records),
            "production_page_tree_value_import_edge_records_sha256": canonical_json_sha256(production_page_incoming_edge_records),
            "page_tree_type_only_import_edge_count": len(type_page_incoming_edge_records),
            "page_tree_type_only_import_edge_records_sha256": canonical_json_sha256(type_page_incoming_edge_records),
            "unresolved_internal_literal_count": len(unresolved),
            "unresolved_internal_literals_sha256": canonical_json_sha256(unresolved),
            "unresolved_internal_literals": unresolved,
            "generated_module_absent_at_application_pin_count": len(generated_absent),
            "generated_module_absent_at_application_pin_sha256": canonical_json_sha256(generated_absent),
            "generated_module_absent_at_application_pin": generated_absent,
            "outside_resources_js_scope_count": len(outside_scope),
            "outside_resources_js_scope_sha256": canonical_json_sha256(outside_scope),
            "outside_resources_js_scope": outside_scope,
            "bundler_precedence_shadow_count": len(shadowed),
            "bundler_precedence_shadows_sha256": canonical_json_sha256(shadowed),
            "bundler_precedence_shadows": shadowed,
            "page_tree_cycle_component_count": len(cycle_records),
            "page_tree_cycle_components_sha256": canonical_json_sha256(cycle_records),
            "page_tree_cycle_components": cycle_records,
            "limits": "The graph proves literal committed-source relations only. Dynamic computed specifiers, runtime conditionals, package/provider resolution, framework reachability, and production bundle inclusion were not executed.",
        },
        "records": records,
        "record_set": {
            "count": len(records),
            "row_key_list_sha256": canonical_list_sha256([record["row_key"] for record in records]),
            "records_sha256": canonical_json_sha256(records),
        },
        "independent_review": {
            "status": "PENDING_FRESH_RUN_084_REVIEW",
            "required_checks": [
                "Recompute all five disjoint path partitions from the pinned Git tree.",
                "Recompute import edges and direct/transitive rendered-root reachability independently.",
                "Red-team type-only, comment/template, unresolved, case-sensitive, cycle, and path-precedence handling.",
                "Confirm that no support or root relation changes the canonical matrix or awards any downstream credit.",
            ],
        },
        "credit_boundary": {
            "page_tree_enumeration_credit": True,
            "static_structural_page_classification_candidate": True,
            "framework_route_denominator_credit": False,
            "feature_mapping_credit": False,
            "benchmark_mapping_credit": False,
            "build_credit": False,
            "application_browser_credit": False,
            "runtime_credit": False,
            "executed_test_credit": False,
            "usability_credit": False,
            "pass_credit": False,
            "completion_credit": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-full-inertia-page-graph-wave-09.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-084-full-inertia-page-graph-wave-09.json",
        ],
    }


def main() -> None:
    payload = build()
    encoded = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    candidate_sha256 = sha256_bytes(encoded)
    OUTPUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    temporary = OUTPUT_PATH.with_suffix(OUTPUT_PATH.suffix + ".tmp")
    temporary.write_bytes(encoded)
    assert sha256_file(temporary) == candidate_sha256
    os.replace(temporary, OUTPUT_PATH)
    assert sha256_file(OUTPUT_PATH) == candidate_sha256
    print(
        json.dumps(
            {
                "status": payload["status"],
                "output": OUTPUT_PATH.relative_to(REPO).as_posix(),
                "sha256": candidate_sha256,
                "records": payload["record_set"]["count"],
                "denominators": payload["denominators"],
                "support_owner_candidates": payload["classification"]["imported_support_root_owner_candidate_counts"],
                "unresolved_internal_literals": payload["import_graph"]["unresolved_internal_literal_count"],
                "generated_module_absent_at_application_pin": payload["import_graph"]["generated_module_absent_at_application_pin_count"],
                "page_tree_cycle_components": payload["import_graph"]["page_tree_cycle_component_count"],
                "all_downstream_credit": 0,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
