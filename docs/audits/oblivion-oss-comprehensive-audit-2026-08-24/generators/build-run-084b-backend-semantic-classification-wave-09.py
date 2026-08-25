#!/usr/bin/env python3
"""Build the RUN-084B bounded backend semantic-classification ledger.

Every mechanically enumerated row receives the prompt-permitted classification
``Evidence gap`` and an explicit next-review contract.  Static tokens are only
locators: they never prove authorization, Site/privacy scope, lifecycle safety,
reachability, runtime behavior, tests, FEATURE-ID ownership, or completion.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


REPO = Path(__file__).resolve().parents[4]
AUDIT_DIR = Path(__file__).resolve().parents[1]
OUTPUT_PATH = AUDIT_DIR / "evidence/source/root-run-084b-backend-semantic-classification-wave-09.json"
PROMPT_PATH = Path(r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md")

AUDIT_HEAD = "cdf8721d339f770c176ebee7688868547885500a"
AUDIT_TREE = "b787f46e02a08a1d538ce7741af39d96763143a3"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
APP_TREE = "92c8425a7cb15a92609c69a8c2f26bbda4f178b7"
PROMPT_SHA256 = "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f"

PINNED_INPUTS = {
    "AGENTS.md": "2972a5612c834f4745010658aeaa2cd4640d4d7a29d932e9952e406c790718ed",
    "docs/architecture/single-tenant-application.md": "3dea6218db87ce22bed3cab6b9c500d1a850445d04e9325cb16c23a604979b3c",
    "evidence/source/current-backend-data-test-census-wave-01.json": "e663ff2e362c667034125af1c974ac2d7eef9563458fbbbb8c0662f3d6ec2583",
    "evidence/source/root-run-073c-architecture-data-integration-security-wave-05.json": "e406bdee1412c4ba49ec18afbd80849e7c6bc30e2098229f80f63888bde39272",
    "evidence/source/raw-run-073e-independent-architecture-review-wave-05.json": "6d8331e12cd43af8ffe47e496273f0ee7ca5cf7075e3b2a7c9cad0367eba906b",
}

EXPECTED = {
    "models": 782,
    "model_classes": 778,
    "model_traits": 4,
    "models_extending_model": 777,
    "models_extending_authenticatable": 1,
    "policies": 75,
    "explicit_policy_map_entries": 65,
    "policies_outside_explicit_map": 10,
    "services": 735,
    "app_services": 364,
    "domain_services_directory": 361,
    "domain_service_suffix_outside_directory": 10,
    "service_classes": 707,
    "service_interfaces": 26,
    "service_enums": 2,
    "jobs": 126,
    "events": 14,
    "listeners": 12,
    "outbox_related": 45,
    "named_outbox_owners": 12,
    "async_role_rows": 197,
    "async_unique_paths": 189,
    "total_role_rows": 1789,
    "unique_source_paths": 1755,
    "independently_range_reviewed_repository_php_paths": 47,
}

EXPECTED_HASHES = {
    "models_path": "a80eee2e2a037325b66c2ae4cc85875c1a0067996acbb74157abaae74b1fd8ea",
    "models_blob": "ea8cc6dd7c454e88b9a88b25d8d80b83c8c31b9f4bff8983043991059a20b887",
    "policies_path": "5d54347fb0f68c16a3a0a6151cd4e108c7995f122e6c6aad9f95dd7c35616a8a",
    "policies_blob": "ce5a70a62e268a2170c23fc8d81de25242a4127f3f80d36204fbb0624930c10e",
    "services_path": "aa12b6ceeb5c0e4edcff3aadf751aec6f7f9bda736296f3e7acad2d6716bde88",
    "services_blob": "8c00dc2bb841387e23b9f102d41c2cd30b1afed8cc421df2655a97576d5a2f63",
    "jobs_path": "0a9097f0aa69ffe446a449fcf92733905d01720893fe8ca5d80aca573a780741",
    "jobs_blob": "44dc15d5ff79d61609059b7e854e4c94c2712dbb54dc796747b5785467c37c6b",
    "events_path": "af8bac96fb3fe68e45552972280709021843e8bc4671f7366f1987444d97a70a",
    "events_blob": "2497fa4abf756b8347d5da4a69d7227efaf54da163b8dcebad7926f1569d1c8e",
    "listeners_path": "949b9d1095f90081655fde745b6a3769fda2dfddc746eb68f87e78100592ace0",
    "listeners_blob": "790cf9d2877a8a7e84ec8feff7730e7d3c76446242181737fa82e88a3d2c84e4",
    "outbox_path": "aeff04bd2ab884c7fea1001516b79f8bdfbc20008f2f4b802b0abc24a69b141e",
    "outbox_blob": "e4e108490dd4b2899dbf661744c0837ce95d9a93bf140b75f4b386d0941365d5",
    "async_role_path": "513d958ddcb32867f29cdf5cf9f3ad12a6360472a99445108f4b08bab5c413d1",
    "async_role_path_blob": "bc17528c6263d5fb4b6389573437fe83159a756d5182ce6ff4686ac49e9499a8",
    "named_outbox_path": "6dc3f9fa536eb2abdd55d654810b4f27b04285e21460dd654ae71d8428f9e511",
    "reviewed_models_path": "e5ce25fc5a444dcaa9aff0ae36cae474424fb84c63cf3770b543fdc23c76e26b",
    "reviewed_policies_path": "51115e48cfe061f25c50a625647979d63ddb836dff08f7cbc167b81d29b55c7d",
    "reviewed_services_path": "bb3ff106e1790de9f32c0591ea9ac1eaeeb18a3ff1f766efcec6df3db11e1ff1",
    "reviewed_repository_php_path": "f4922b29c2489c08faa90a7398112f0b52015d1a61c513b6326db793fb2bc8b7",
    "reviewed_async_role_path": "adb8778211d0cf6da3d33d706172bea2a10d0bb6ce8346963f31f24d531623bf",
}

DECLARATION_RE = re.compile(
    r"(?m)^[ \t]*(?:(?:final|abstract|readonly)[ \t]+)*(class|interface|trait|enum)[ \t]+([A-Za-z_][A-Za-z0-9_]*)"
)
PUBLIC_METHOD_RE = re.compile(r"(?m)^[ \t]*public[ \t]+(?:static[ \t]+)?function[ \t]+([A-Za-z_][A-Za-z0-9_]*)[ \t]*\(")
ANCHOR_RE = re.compile(
    r"(?P<path>(?:app|bootstrap|config|database|routes|tests)/[A-Za-z0-9_./-]+\.php):\d+(?:-\d+)?"
)
POLICY_MAP_RE = re.compile(r"(?m)^\s*([A-Za-z_][A-Za-z0-9_]*)::class\s*=>\s*([A-Za-z_][A-Za-z0-9_]*Policy)::class,")
USE_IMPORT_RE = re.compile(
    r"(?m)^use\s+([A-Za-z_][A-Za-z0-9_\\]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?;"
)

COMMON_LENSES = {
    "site_scope": re.compile(
        r"\bsites?\b|\bsite(?:_id|Ids|Id|_ids|Scope|Access|able)\b|approved.?site|"
        r"\b(?:accessible|visible|approved)Sites\b|\bcan(?:Access|ViewAll)Sites?\b",
        re.I,
    ),
    "exact_action_or_permission": re.compile(
        r"authorize|Gate::|->can\s*\(|->canDo\s*\(|->hasRole\s*\(|->hasAnyRole\s*\(|permission|hasPermission",
        re.I,
    ),
    "direct_object_lookup_or_concealment": re.compile(r"findOrFail|firstOrFail|resolveRouteBinding|whereKey|ModelNotFound", re.I),
    "privacy_or_sensitive_data": re.compile(
        r"privacy|sensitive|confidential|redact|mask|internal.?note|attachment|export|"
        r"\b(?:client|resident|health|clinical|medication|emar|consent|safeguard|hr|payroll|finance|camera|location|security)\b",
        re.I,
    ),
    "lifecycle_or_state": re.compile(r"\bstatus\b|\bstate\b|transition|approve|complete|cancel|archive", re.I),
    "audit_or_history": re.compile(r"audit|history|activity|event", re.I),
}
ROLE_LENSES = {
    "MODEL": {
        "table_mapping": re.compile(r"\$table\b"),
        "mass_assignment": re.compile(r"\$(?:fillable|guarded)\b"),
        "casts": re.compile(r"\$casts\b|function\s+casts\s*\("),
        "relations": re.compile(r"\b(?:belongsTo|hasOne|hasMany|belongsToMany|morphOne|morphMany|morphTo)\s*\("),
        "local_or_global_scopes": re.compile(r"function\s+scope[A-Z]|addGlobalScope|ScopedBy"),
        "soft_delete": re.compile(r"SoftDeletes"),
        "temporal_fields": re.compile(r"created_at|updated_at|deleted_at|effective_at|occurred_at|starts_at|ends_at"),
        "observer_or_hook": re.compile(r"observe\s*\(|booted\s*\(|creating\s*\(|updating\s*\(|deleting\s*\("),
    },
    "POLICY": {
        "delegated_access_service": re.compile(r"AccessService|ScopeService|Authori[sz]ationService"),
        "object_parameter": re.compile(r"function\s+\w+\s*\([^)]*\$\w+", re.S),
        "export_attachment_note_controls": re.compile(r"export|attachment|internal.?note|download", re.I),
        "lifecycle_guard": re.compile(r"status|state|approve|complete|cancel|archive", re.I),
    },
    "SERVICE_ENTRY": {
        "query_roots": re.compile(r"::query\s*\(|newQuery\s*\(|DB::table\s*\("),
        "transactions": re.compile(r"DB::transaction|beginTransaction|commit\s*\(|rollBack\s*\("),
        "locking": re.compile(r"lockForUpdate|sharedLock|WithoutOverlapping|Cache::lock"),
        "idempotency_or_deduplication": re.compile(r"idempoten|dedup|unique|replay", re.I),
        "events_or_outbox": re.compile(r"event\s*\(|dispatch\s*\(|outbox", re.I),
        "external_effect": re.compile(r"Http::|Mail::|Notification::|Storage::|curl|webhook", re.I),
    },
    "ASYNC": {
        "registration_schedule_dispatch": re.compile(r"schedule|dispatch|listen|subscribe|ShouldQueue", re.I),
        "payload_identifiers": re.compile(r"_id\b|Id\b|Uuid|Ulid"),
        "retry_timeout_failure": re.compile(r"\$tries|backoff|timeout|function\s+failed|retryUntil", re.I),
        "uniqueness_overlap_lock": re.compile(r"ShouldBeUnique|WithoutOverlapping|lock|unique", re.I),
        "transaction_boundary": re.compile(r"DB::transaction|afterCommit|beforeCommit", re.I),
        "ordering_replay_dead_letter": re.compile(r"order|replay|dead.?letter|dedup|idempoten", re.I),
        "suppression_observability": re.compile(r"suppress|log|metric|trace|report\s*\(", re.I),
    },
}
LEGACY_ORG_CONTEXT_RE = re.compile(r"tenant_id|tenantId|organization_id|organisation_id", re.I)


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def canonical_list_sha256(values: set[str] | list[str]) -> str:
    ordered = sorted(values)
    return sha256_bytes("\n".join(ordered).encode("utf-8"))


def canonical_json_sha256(value: Any) -> str:
    raw = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return sha256_bytes(raw.encode("utf-8"))


def git(*args: str, text: bool = True) -> str | bytes:
    completed = subprocess.run(
        ["git", *args], cwd=REPO, check=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=text
    )
    return completed.stdout


def git_tree_entries(commit: str, prefix: str) -> dict[str, str]:
    raw = git("ls-tree", "-r", "-z", "--full-tree", commit, "--", prefix, text=False)
    result: dict[str, str] = {}
    for record in raw.split(b"\0"):  # type: ignore[union-attr]
        if not record:
            continue
        metadata, raw_path = record.split(b"\t", 1)
        result[raw_path.decode("utf-8")] = metadata.split()[2].decode("ascii")
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
        assert header[:2] == [requested, "blob"] and len(header) == 3, (requested, header)
        size = int(header[2])
        offset = header_end + 1
        result[requested] = raw_output[offset : offset + size]
        assert len(result[requested]) == size
        offset += size
        assert raw_output[offset : offset + 1] == b"\n"
        offset += 1
    assert offset == len(raw_output)
    return result


def load_json(relative: str) -> dict[str, Any]:
    return json.loads((AUDIT_DIR / relative).read_text(encoding="utf-8"))


def assert_pins() -> None:
    assert git("rev-parse", "HEAD").strip() == AUDIT_HEAD
    assert git("rev-parse", "HEAD^{tree}").strip() == AUDIT_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}").strip() == APPLICATION_TREE
    assert git("rev-parse", f"{APPLICATION_COMMIT}:app").strip() == APP_TREE
    assert sha256_file(PROMPT_PATH) == PROMPT_SHA256
    for relative, expected in PINNED_INPUTS.items():
        path = REPO / relative if relative.startswith(("AGENTS.md", "docs/architecture/")) else AUDIT_DIR / relative
        assert sha256_file(path) == expected, relative
    diff = git(
        "diff",
        "--name-only",
        APPLICATION_COMMIT,
        AUDIT_HEAD,
        "--",
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
    assert not diff.strip(), diff


def module_lane(path: str) -> str:
    parts = path.split("/")
    if len(parts) > 2 and parts[1] == "Domain":
        return f"DOMAIN:{parts[2]}"
    return f"APP:{parts[1] if len(parts) > 1 else 'ROOT'}"


def declaration(raw_text: str, path: str) -> tuple[str, str]:
    matches = DECLARATION_RE.findall(raw_text)
    assert len(matches) == 1, (path, matches)
    kind, symbol = matches[0]
    return kind.upper(), symbol


def line_anchors(path: str, text: str, pattern: re.Pattern[str]) -> list[str]:
    return [f"{path}:{line_number}" for line_number, line in enumerate(text.splitlines(), start=1) if pattern.search(line)]


def semantic_lenses(path: str, text: str, role: str) -> dict[str, Any]:
    patterns = {**COMMON_LENSES, **ROLE_LENSES["ASYNC" if role in {"JOB", "EVENT", "LISTENER", "OUTBOX_RELATED"} else role]}
    result: dict[str, Any] = {}
    for name, pattern in patterns.items():
        anchors = line_anchors(path, text, pattern)
        result[name] = {
            "status": "PRESENT_WITH_STATIC_TOKEN_LOCATORS_NOT_SEMANTICALLY_REVIEWED" if anchors else "ABSENT_STATIC_LOCATOR_NOT_A_DEFECT",
            "anchors": anchors,
            "semantic_review_status": "UNRESOLVED_WHOLE_DECLARATION_REVIEW_REQUIRED",
        }
    legacy_anchors = line_anchors(path, text, LEGACY_ORG_CONTEXT_RE)
    result["legacy_organisation_context"] = {
        "status": "LEGACY_CONTEXT_TOKEN_ONLY_NOT_AUTHORIZATION_BOUNDARY" if legacy_anchors else "NO_STATIC_LEGACY_CONTEXT_TOKEN",
        "anchors": legacy_anchors,
        "architecture_rule": "ONE_OPERATING_ORGANISATION_MULTIPLE_SITES",
    }
    return result


def collect_reviewed_paths(value: Any) -> set[str]:
    paths: set[str] = set()
    if isinstance(value, dict):
        for nested in value.values():
            paths.update(collect_reviewed_paths(nested))
    elif isinstance(value, list):
        for nested in value:
            paths.update(collect_reviewed_paths(nested))
    elif isinstance(value, str):
        paths.update(match.group("path") for match in ANCHOR_RE.finditer(value))
    return paths


def build() -> dict[str, Any]:
    assert_pins()
    app_entries = git_tree_entries(APPLICATION_COMMIT, "app")
    php_entries = {path: blob for path, blob in app_entries.items() if path.endswith(".php")}
    blobs = git_blobs_by_id(set(php_entries.values()))
    text_by_path = {path: blobs[blob].decode("utf-8") for path, blob in php_entries.items()}

    models = {
        path for path in php_entries if "/models/" in path.lower()
    }
    policies = {
        path for path in php_entries if "/policies/" in path.lower()
    }
    app_services = {path for path in php_entries if path.startswith("app/Services/")}
    domain_services = {
        path for path in php_entries if path.startswith("app/Domain/") and "/Services/" in path
    }
    domain_service_suffix = {
        path
        for path in php_entries
        if path.startswith("app/Domain/")
        and "/Services/" not in path
        and Path(path).name.endswith("Service.php")
    }
    services = app_services | domain_services | domain_service_suffix
    jobs = {path for path in php_entries if "/jobs/" in path.lower()}
    events = {path for path in php_entries if "/events/" in path.lower()}
    listeners = {path for path in php_entries if "/listeners/" in path.lower()}
    outbox_related = {path for path, text in text_by_path.items() if "outbox" in text.lower()}
    named_outbox_owners = {path for path in outbox_related if "outbox" in Path(path).stem.lower()}

    assert len(models) == EXPECTED["models"]
    assert len(policies) == EXPECTED["policies"]
    assert (len(app_services), len(domain_services), len(domain_service_suffix), len(services)) == (
        EXPECTED["app_services"],
        EXPECTED["domain_services_directory"],
        EXPECTED["domain_service_suffix_outside_directory"],
        EXPECTED["services"],
    )
    assert (len(jobs), len(events), len(listeners), len(outbox_related), len(named_outbox_owners)) == (
        EXPECTED["jobs"], EXPECTED["events"], EXPECTED["listeners"], EXPECTED["outbox_related"], EXPECTED["named_outbox_owners"]
    )

    path_sets = {
        "models": models,
        "policies": policies,
        "services": services,
        "jobs": jobs,
        "events": events,
        "listeners": listeners,
        "outbox": outbox_related,
    }
    for name, paths in path_sets.items():
        expected_path = EXPECTED_HASHES[f"{name}_path"]
        expected_blob = EXPECTED_HASHES[f"{name}_blob"]
        assert canonical_list_sha256(paths) == expected_path, name
        assert canonical_list_sha256([f"{path}\t{php_entries[path]}" for path in paths]) == expected_blob, name
    assert canonical_list_sha256(named_outbox_owners) == EXPECTED_HASHES["named_outbox_path"]

    provider_text = text_by_path["app/Providers/AuthServiceProvider.php"]
    policy_pairs = POLICY_MAP_RE.findall(provider_text)
    assert len(policy_pairs) == EXPECTED["explicit_policy_map_entries"]
    provider_imports: dict[str, str] = {}
    for fqcn, explicit_alias in USE_IMPORT_RE.findall(provider_text):
        alias = explicit_alias or fqcn.rsplit("\\", 1)[-1]
        provider_imports[alias] = fqcn
    policy_map_by_declared_symbol: dict[str, dict[str, str]] = {}
    for model_alias, policy_alias in policy_pairs:
        assert model_alias in provider_imports and policy_alias in provider_imports
        model_fqcn = provider_imports[model_alias]
        policy_fqcn = provider_imports[policy_alias]
        policy_symbol = policy_fqcn.rsplit("\\", 1)[-1]
        assert policy_symbol not in policy_map_by_declared_symbol
        policy_map_by_declared_symbol[policy_symbol] = {
            "model_alias": model_alias,
            "model_fqcn": model_fqcn,
            "model_symbol": model_fqcn.rsplit("\\", 1)[-1],
            "policy_alias": policy_alias,
            "policy_fqcn": policy_fqcn,
            "policy_symbol": policy_symbol,
        }
    assert len(policy_map_by_declared_symbol) == EXPECTED["explicit_policy_map_entries"]

    declarations: dict[str, tuple[str, str]] = {
        path: declaration(text_by_path[path], path)
        for path in models | policies | services | jobs | events | listeners | outbox_related
    }
    model_kinds = Counter(declarations[path][0] for path in models)
    service_kinds = Counter(declarations[path][0] for path in services)
    assert model_kinds == Counter({"CLASS": EXPECTED["model_classes"], "TRAIT": EXPECTED["model_traits"]})
    assert service_kinds == Counter(
        {"CLASS": EXPECTED["service_classes"], "INTERFACE": EXPECTED["service_interfaces"], "ENUM": EXPECTED["service_enums"]}
    )
    assert all(declarations[path][0] == "CLASS" for path in policies | jobs | events | listeners)
    assert sum(bool(re.search(r"\bextends\s+Model\b", text_by_path[path])) for path in models) == EXPECTED["models_extending_model"]
    assert sum(bool(re.search(r"\bextends\s+Authenticatable\b", text_by_path[path])) for path in models) == EXPECTED["models_extending_authenticatable"]

    run_073c = load_json("evidence/source/root-run-073c-architecture-data-integration-security-wave-05.json")
    run_073e = load_json("evidence/source/raw-run-073e-independent-architecture-review-wave-05.json")
    assert run_073e["status"] == "INDEPENDENT_ARCHITECTURE_SOURCE_AND_REPORT_REVIEW_GO"
    reviewed_paths = collect_reviewed_paths(run_073c)
    assert len(reviewed_paths) == EXPECTED["independently_range_reviewed_repository_php_paths"]
    reviewed_models = reviewed_paths & models
    reviewed_policies = reviewed_paths & policies
    reviewed_services = reviewed_paths & services
    assert (len(reviewed_models), len(reviewed_policies), len(reviewed_services)) == (18, 1, 6)
    assert canonical_list_sha256(reviewed_models) == EXPECTED_HASHES["reviewed_models_path"]
    assert canonical_list_sha256(reviewed_policies) == EXPECTED_HASHES["reviewed_policies_path"]
    assert canonical_list_sha256(reviewed_services) == EXPECTED_HASHES["reviewed_services_path"]
    assert canonical_list_sha256(reviewed_paths) == EXPECTED_HASHES["reviewed_repository_php_path"]

    symbol_paths: dict[str, set[str]] = defaultdict(set)
    for path, text in text_by_path.items():
        for symbol in set(re.findall(r"\b[A-Z][A-Za-z0-9_]*\b", text)):
            symbol_paths[symbol].add(path)

    async_roles: list[tuple[str, str]] = []
    async_roles.extend(("JOB", path) for path in jobs)
    async_roles.extend(("EVENT", path) for path in events)
    async_roles.extend(("LISTENER", path) for path in listeners)
    async_roles.extend(("OUTBOX_RELATED", path) for path in outbox_related)
    async_roles.sort(key=lambda item: (item[0], item[1]))
    assert len(async_roles) == EXPECTED["async_role_rows"]
    assert len({path for _, path in async_roles}) == EXPECTED["async_unique_paths"]
    serialized_async_roles = [
        (("outbox" if role == "OUTBOX_RELATED" else role.lower()), path)
        for role, path in async_roles
    ]
    assert canonical_list_sha256([f"{role}\t{path}" for role, path in serialized_async_roles]) == EXPECTED_HASHES["async_role_path"]
    assert canonical_list_sha256([f"{role}\t{path}\t{php_entries[path]}" for role, path in serialized_async_roles]) == EXPECTED_HASHES["async_role_path_blob"]

    role_sets_by_path: dict[str, set[str]] = defaultdict(set)
    for role, path in async_roles:
        role_sets_by_path[path].add(role)
    overlap_counts = Counter("+".join(sorted(roles)) for roles in role_sets_by_path.values())
    assert overlap_counts == Counter(
        {
            "EVENT": 13,
            "EVENT+OUTBOX_RELATED": 1,
            "JOB": 119,
            "JOB+OUTBOX_RELATED": 7,
            "LISTENER": 12,
            "OUTBOX_RELATED": 37,
        }
    )
    outbox_lane_counts = Counter()
    for path in outbox_related:
        lower = path.lower()
        if "/commands/" in lower:
            outbox_lane_counts["COMMAND"] += 1
        elif "/controllers/" in lower:
            outbox_lane_counts["CONTROLLER"] += 1
        elif "/events/" in lower:
            outbox_lane_counts["EVENT"] += 1
        elif "/jobs/" in lower:
            outbox_lane_counts["JOB"] += 1
        elif "/models/" in lower:
            outbox_lane_counts["MODEL"] += 1
        elif "/observers/" in lower:
            outbox_lane_counts["OBSERVER"] += 1
        elif "/services/" in lower or Path(path).name.endswith("Service.php"):
            outbox_lane_counts["SERVICE"] += 1
        else:
            outbox_lane_counts["OTHER_SUPPORT_OR_BUILDER"] += 1
    assert outbox_lane_counts == Counter(
        {"COMMAND": 4, "CONTROLLER": 3, "EVENT": 1, "JOB": 7, "MODEL": 10, "OBSERVER": 2, "SERVICE": 16, "OTHER_SUPPORT_OR_BUILDER": 2}
    )

    role_units: list[tuple[str, str, str]] = []
    role_units.extend(("MODELS", "MODEL", path) for path in models)
    role_units.extend(("POLICIES", "POLICY", path) for path in policies)
    role_units.extend(("SERVICES", "SERVICE_ENTRY", path) for path in services)
    role_units.extend(("ASYNC", role, path) for role, path in async_roles)
    role_units.sort(key=lambda item: (item[0], item[1], item[2]))
    assert len(role_units) == EXPECTED["total_role_rows"]
    assert len({path for _, _, path in role_units}) == EXPECTED["unique_source_paths"]

    records: list[dict[str, Any]] = []
    for ordinal, (universe, role, path) in enumerate(role_units, start=1):
        raw = blobs[php_entries[path]]
        text = text_by_path[path]
        declaration_kind, symbol = declarations[path]
        public_methods = [
            {"method": match.group(1), "anchor": f"{path}:{text.count(chr(10), 0, match.start()) + 1}"}
            for match in PUBLIC_METHOD_RE.finditer(text)
        ]
        public_method_source_lines = text.splitlines()
        assert all(
            PUBLIC_METHOD_RE.match(public_method_source_lines[int(locator["anchor"].rsplit(":", 1)[1]) - 1])
            for locator in public_methods
        ), path
        external_paths = symbol_paths.get(symbol, set()) - {path}
        policy_mapping = None
        if role == "POLICY":
            mapping = policy_map_by_declared_symbol.get(symbol)
            policy_mapping = {
                "status": "EXPLICIT_POLICY_MAP_ENTRY" if mapping else "OUTSIDE_EXPLICIT_MAP_DISCOVERY_NOT_EXECUTED",
                "mapped_model_symbol": mapping["model_symbol"] if mapping else None,
                "mapped_model_fqcn": mapping["model_fqcn"] if mapping else None,
                "provider_model_alias": mapping["model_alias"] if mapping else None,
                "policy_fqcn": mapping["policy_fqcn"] if mapping else None,
                "provider_policy_alias": mapping["policy_alias"] if mapping else None,
            }
        row_key = f"backend-role|{universe}|{role}|{path}"
        records.append(
            {
                "ordinal": ordinal,
                "row_id": "BACKEND-ROLE-" + sha256_bytes(row_key.encode("utf-8"))[:16].upper(),
                "row_key": row_key,
                "universe": universe,
                "role": role,
                "path": path,
                "git_blob": php_entries[path],
                "sha256": sha256_bytes(raw),
                "declaration_kind": declaration_kind,
                "declared_symbol": symbol,
                "module_lane": module_lane(path),
                "public_method_locators": public_methods,
                "policy_mapping": policy_mapping,
                "semantic_lenses": semantic_lenses(path, text, role),
                "independent_range_review": {
                    "status": "BOUNDED_RANGES_REVIEWED_NOT_WHOLE_FILE_SEMANTICS" if path in reviewed_paths else "NO_INDEPENDENT_RANGE_REVIEW_IN_RUN_073C_E",
                    "source": "RUN-073C/RUN-073E" if path in reviewed_paths else None,
                },
                "external_app_reference_candidates": {
                    "path_count": len(external_paths),
                    "path_list_sha256": canonical_list_sha256(external_paths),
                    "scope": "Static identifier-token candidates in pinned app PHP only; not reachability or usage proof.",
                },
                "prompt_classification": "Evidence gap",
                "classification_proof": [path, f"git-blob:{php_entries[path]}"],
                "next_review_requirement": "Independently review the complete declaration and every required semantic lens; preserve Site/action/ownership/direct-object/privacy boundaries and treat lexical absence as neither proof nor defect.",
                "whole_file_semantic_review_status": "NOT_EXECUTED",
                "feature_mapping_status": "NOT_EXECUTED_NO_PATH_OR_SYMBOL_INHERITANCE",
                "source_classification_candidate": True,
                "runtime_credit": False,
                "executed_test_credit": False,
                "browser_credit": False,
                "benchmark_credit": False,
                "pass_credit": False,
                "finding_credit": False,
                "completion_credit": False,
            }
        )

    row_counts = Counter(record["role"] for record in records)
    assert row_counts == Counter(
        {"MODEL": 782, "POLICY": 75, "SERVICE_ENTRY": 735, "JOB": 126, "EVENT": 14, "LISTENER": 12, "OUTBOX_RELATED": 45}
    )
    policy_mapping_counts = Counter(
        record["policy_mapping"]["status"] for record in records if record["role"] == "POLICY"
    )
    assert policy_mapping_counts == Counter(
        {"EXPLICIT_POLICY_MAP_ENTRY": 65, "OUTSIDE_EXPLICIT_MAP_DISCOVERY_NOT_EXECUTED": 10}
    )
    assert len({record["row_id"] for record in records}) == len(records)
    assert all(record["prompt_classification"] == "Evidence gap" for record in records)

    reviewed_async_rows = [f"{role}\t{path}" for role, path in async_roles if path in reviewed_paths]
    assert len(reviewed_async_rows) == 11
    assert canonical_list_sha256(reviewed_async_rows) == EXPECTED_HASHES["reviewed_async_role_path"]

    return {
        "schema_version": "run-084b-backend-semantic-classification-wave-09-v1",
        "run_id": "RUN-084B",
        "status": "STATIC_BACKEND_CLASSIFICATION_CANDIDATE_PENDING_INDEPENDENT_REVIEW_ZERO_DOWNSTREAM_CREDIT",
        "generated_on": "2026-08-25",
        "pins": {
            "audit_head": AUDIT_HEAD,
            "audit_tree": AUDIT_TREE,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "app_tree": APP_TREE,
            "prompt_sha256": PROMPT_SHA256,
            "generator_sha256": sha256_file(Path(__file__).resolve()),
            "inputs": PINNED_INPUTS,
            "non_audit_product_diff": 0,
        },
        "architecture_rule": {
            "product": "ONE_OPERATING_ORGANISATION_MULTIPLE_SITES",
            "authorization_boundaries": ["roles_and_exact_capabilities", "approved_Sites", "canonical_ownership", "direct_object_concealment", "privacy"],
            "legacy_tenant_or_organisation_columns": "COMPATIBILITY_CONTEXT_ONLY_NEVER_AUTHORIZATION_BOUNDARY",
        },
        "methods": [
            "Enumerated exact path/blob universes from the pinned application commit using ordinal case-sensitive UTF-8 path ordering.",
            "Preserved role overlap: a job or event containing an outbox token has one row for each role.",
            "Assigned every row the prompt-permitted Evidence gap classification and a whole-declaration next-review requirement.",
            "Recorded lexical tokens only as locators; presence is not semantic proof and absence is not a defect.",
            "Kept RUN-073C/E selected range review separate from whole-file classification.",
        ],
        "denominators": {
            **EXPECTED,
            "model_path_list_sha256": canonical_list_sha256(models),
            "model_path_blob_list_sha256": canonical_list_sha256([f"{path}\t{php_entries[path]}" for path in models]),
            "policy_path_list_sha256": canonical_list_sha256(policies),
            "policy_path_blob_list_sha256": canonical_list_sha256([f"{path}\t{php_entries[path]}" for path in policies]),
            "service_path_list_sha256": canonical_list_sha256(services),
            "service_path_blob_list_sha256": canonical_list_sha256([f"{path}\t{php_entries[path]}" for path in services]),
            "job_path_list_sha256": canonical_list_sha256(jobs),
            "job_path_blob_list_sha256": canonical_list_sha256([f"{path}\t{php_entries[path]}" for path in jobs]),
            "event_path_list_sha256": canonical_list_sha256(events),
            "event_path_blob_list_sha256": canonical_list_sha256([f"{path}\t{php_entries[path]}" for path in events]),
            "listener_path_list_sha256": canonical_list_sha256(listeners),
            "listener_path_blob_list_sha256": canonical_list_sha256([f"{path}\t{php_entries[path]}" for path in listeners]),
            "outbox_path_list_sha256": canonical_list_sha256(outbox_related),
            "outbox_path_blob_list_sha256": canonical_list_sha256([f"{path}\t{php_entries[path]}" for path in outbox_related]),
            "async_role_path_list_sha256": canonical_list_sha256([f"{role}\t{path}" for role, path in serialized_async_roles]),
            "async_role_path_blob_list_sha256": canonical_list_sha256(
                [f"{role}\t{path}\t{php_entries[path]}" for role, path in serialized_async_roles]
            ),
            "async_overlap_partition": dict(sorted(overlap_counts.items())),
            "outbox_path_lane_partition": dict(sorted(outbox_lane_counts.items())),
        },
        "bounded_prior_range_review": {
            "repository_php_paths": len(reviewed_paths),
            "models": len(reviewed_models),
            "policies": len(reviewed_policies),
            "services": len(reviewed_services),
            "async_role_rows": len(reviewed_async_rows),
            "repository_php_path_list_sha256": canonical_list_sha256(reviewed_paths),
            "model_path_list_sha256": canonical_list_sha256(reviewed_models),
            "policy_path_list_sha256": canonical_list_sha256(reviewed_policies),
            "service_path_list_sha256": canonical_list_sha256(reviewed_services),
            "async_role_path_list_sha256": canonical_list_sha256(reviewed_async_rows),
            "limits": "Validated selected ranges only; no whole-file semantic classification or downstream credit is inherited.",
        },
        "classification": {
            "allowed_prompt_classification": "Evidence gap",
            "classified_role_rows": len(records),
            "total_role_rows": len(records),
            "static_structural_classification_percentage": "100.00%",
            "role_counts": dict(sorted(row_counts.items())),
            "policy_mapping_counts": dict(sorted(policy_mapping_counts.items())),
            "whole_file_semantically_reviewed": 0,
        },
        "records": records,
        "record_set": {
            "count": len(records),
            "row_key_list_sha256": canonical_list_sha256([record["row_key"] for record in records]),
            "records_sha256": canonical_json_sha256(records),
        },
        "independent_review": {
            "status": "PENDING_FRESH_RUN_084BR",
            "required_checks": [
                "Independently recompute every universe, role overlap, declaration partition, path hash, and blob hash.",
                "Confirm every row has one allowed classification, evidence identity, semantic lenses, and concrete next-review requirement.",
                "Red-team outbox lexical scope, explicit policy-map scope, and selected-range versus whole-file separation.",
                "Confirm lexical presence and absence never become security, lifecycle, reachability, or defect claims.",
            ],
        },
        "credit_boundary": {
            "static_structural_classification_candidate": True,
            "whole_file_semantic_review_credit": False,
            "feature_mapping_credit": False,
            "framework_reachability_credit": False,
            "runtime_credit": False,
            "database_credit": False,
            "executed_test_credit": False,
            "application_browser_credit": False,
            "benchmark_credit": False,
            "ease_credit": False,
            "pass_credit": False,
            "final_finding_credit": False,
            "completion_credit": False,
            "audit_complete": False,
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/build-run-084b-backend-semantic-classification-wave-09.py",
            "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/root-run-084b-backend-semantic-classification-wave-09.json",
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
                "record_rows": payload["record_set"]["count"],
                "unique_paths": payload["denominators"]["unique_source_paths"],
                "role_counts": payload["classification"]["role_counts"],
                "prior_range_review": payload["bounded_prior_range_review"],
                "whole_file_semantically_reviewed": 0,
                "all_downstream_credit": 0,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
