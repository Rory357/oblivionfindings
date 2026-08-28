#!/usr/bin/env python3
"""Materialize the bounded RUN156 medication source-integration receipt.

This receipt distinguishes the immutable cd5 merge payload from the effective
application bytes at c5.  It uses Git object evidence only, writes no
application source, and awards no remediation or downstream audit credit.
"""
from __future__ import annotations

from collections import Counter
import hashlib
import json
from pathlib import Path
import subprocess
from typing import Any


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()
AUDIT_PREFIX = f"{PREFIX}/"

RUN_ID = "RUN-156-MEDICATION-GOVERNANCE-SOURCE-MAIN-RECEIPT-WAVE-27"
MATERIALIZER = "generators/materialize-run-156-medication-governance-source-main-receipt-wave-27.py"
OUTPUT = "evidence/source/current-run-156-medication-governance-source-main-receipt-wave-27.json"

CHECKPOINT_COMMIT = "86b232cb14967c63ff345ac5208ec6d4c379f24f"
CHECKPOINT_TREE = "5444cf4131451642b0d2d144f28f5c04dffa7445"
OBSERVED_LOCAL_ORIGIN_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
HISTORICAL_MERGE = "cd5d34e6b8aa7e494808745041ec1dfa187dc101"
HISTORICAL_MERGE_TREE = "6ec8b3f01618c806e2466b18ef21d54c81d4534b"
MERGE_PARENT_1 = "64d2a0814d571f583a1dda0dcf53554a8992d4b5"
MERGE_PARENT_1_TREE = "163b880fb750506bbbba60befb602c6fca0c6252"
MERGE_PARENT_2 = "389b4fadbbd6c99ec7eec4cdd1a5a47d940da1ee"
MERGE_PARENT_2_TREE = "963ac503e4ce0a5e7652c26d3bec9c3ebf06595e"
COMMON_BASE = "8c179fe14840ff1093c455bfb943f3c9ba60e59b"
COMMON_BASE_TREE = "2ad599448435f2eacdabc0cc0ee2b95d98dfe817"
EFFECTIVE_APPLICATION_COMMIT = "c5c0ad0903d2e2e2229d5d0090fc0a69a2206f0f"
EFFECTIVE_APPLICATION_TREE = "4d5bb5f8106e49568fd7a9d2a067f46505c29ea5"
AUDITED_FINDINGS_APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"

MERGE_NAME_STATUS_SHA256 = "3e322e6d04c24aa789237625ec92ca69b180c12e5bda545b525bc74214b7fb5a"
MERGE_PATH_LIST_SHA256 = "c1355ec1533b99236297d427c6395ebae38656f8b2318b9dba5db2cb1dccb5be"
MERGE_PATH_BLOB_SHA256 = "aed709ff56b37fecb1be9fb9f9fa1cca3f4922d0e22c3abaf3ceafee5b694a3f"
COMMON_BASE_NAME_STATUS_SHA256 = "4b0b4a03087f83128c9373b78268d63ac653b20dae9f8830f30c1ca445a12032"
COMMON_BASE_PATH_LIST_SHA256 = "cdd376e77a73ee3c44ac6a6a681d1e8ac70ed93ad681940c091a84f7e2b765ac"
TOPOLOGY_COMMIT_LIST_SHA256 = "15cacf7011ea39862d67a03d325ae9d76ef98a50b137dc9f97d542c7227c671a"
C5_NAME_STATUS_SHA256 = "028a6ac7f0e67cb799870b59090cd5261a6d865d6c58642d0f09ac3e1d89e8f3"
C5_PATH_LIST_SHA256 = "80c4e5354819f0258e8510ca4790dbc86180a8a946d17c3aeca91d4271fb3aa0"
C5_TRANSITION_MANIFEST_SHA256 = "2577f6f8dec59baa120230aa4a8d5884e0cd01f752b744e54c360118ddbda2cc"
UNCHANGED_358_PATH_LIST_SHA256 = "4f2b3b5d47be4c475b3ddc0b1791880d617806958a1a2f80f975eb4323b5f76c"
EFFECTIVE_PAYLOAD_STATE_SHA256 = "7341ad07d6a31c378464907b336603eab1b27c147c0aab6c6430fa387a5586a6"
EFFECTIVE_PAYLOAD_PATH_BLOB_SHA256 = "fb0dfc61a391d93887a880426a26cf02f5cc8617396077870ec1456fe6216234"
NON_AUDIT_MANIFEST_SHA256 = "016f4f12e8482ec11fcfdcaaec793417df35463deb90ee49d0c806e7ca7a0ea2"
POST_C5_NAME_STATUS_SHA256 = "6b47f14508a8e648baa6158bf45f6327362870c74d5cd8b978214cd8ab0139a5"
POST_C5_PATH_LIST_SHA256 = "bb753bca3d4c88193f5dcef7aeac5900aae2be8069816f3768e21605970fa84a"
POST_C5_COMMIT_LIST_SHA256 = "59c84bb0277edc3f91d12b073eb12b8cd1925c2fbd8a4ff48acf1e7b4907c00b"

TOPOLOGY_COMMITS = (
    "9c55c589d21108b5d04e32622037a7d9709e3901",
    "64d2a0814d571f583a1dda0dcf53554a8992d4b5",
    "71d6313a0c39c7d7da4c1b3d45534ea45c16909d",
    "e6e0d4a7ad7e7c485aa9633fb9bada23d623e142",
    "dc6303585df1f90d9027ca4d03699dbb5c3a4b45",
    "389b4fadbbd6c99ec7eec4cdd1a5a47d940da1ee",
    HISTORICAL_MERGE,
)

POST_C5_COMMITS = (
    "3e9407f9fac197d3ed075782187c35ee11db4d2e",
    "df0f3758131433b91da7c6d3cfb485c3d917d7ef",
    CHECKPOINT_COMMIT,
)

EXPECTED_POST_C5_STATUS = (
    f"M\t{PREFIX}/00-executive-summary.md",
    f"M\t{PREFIX}/01-repository-module-map.md",
    f"M\t{PREFIX}/13-unresolved-questions-and-evidence-gaps.md",
    f"M\t{PREFIX}/audit-dashboard.html",
    f"A\t{PREFIX}/evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json",
    f"A\t{PREFIX}/evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json",
    f"A\t{PREFIX}/evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json",
    f"M\t{PREFIX}/findings.json",
    f"M\t{PREFIX}/generators/build-current-audit-dashboard.py",
    f"A\t{PREFIX}/generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.py",
    f"A\t{PREFIX}/generators/materialize-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.py",
    f"A\t{PREFIX}/generators/materialize-run-155-audit-dashboard-verification-wave-26.py",
)

EXPECTED_COMMIT_DELTAS = {
    POST_C5_COMMITS[0]: (
        f"A\t{PREFIX}/evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json",
        f"A\t{PREFIX}/generators/materialize-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.py",
    ),
    POST_C5_COMMITS[1]: (
        f"M\t{PREFIX}/00-executive-summary.md",
        f"M\t{PREFIX}/01-repository-module-map.md",
        f"M\t{PREFIX}/13-unresolved-questions-and-evidence-gaps.md",
        f"A\t{PREFIX}/evidence/source/current-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.json",
        f"M\t{PREFIX}/findings.json",
        f"M\t{PREFIX}/generators/build-current-audit-dashboard.py",
        f"A\t{PREFIX}/generators/materialize-run-154-reviewed-fleet-vehicle-register-index-route-action-reporting-wave-26.py",
    ),
    POST_C5_COMMITS[2]: (
        f"M\t{PREFIX}/audit-dashboard.html",
        f"A\t{PREFIX}/evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json",
        f"A\t{PREFIX}/generators/materialize-run-155-audit-dashboard-verification-wave-26.py",
    ),
}

EFFECTIVE_SUBTREES = {
    "app": "af164e03ecf3d9d439535be614984f3494688569",
    "bootstrap": "df6189abe5ab5343d88674c199c4ce46e6152a57",
    "database": "341446159b5d8f6e303db9e9cddabfd446b0e034",
    "resources": "f8d9a36a11f3579716d090642ccd2dddf09a4fa4",
    "resources/js": "776359c5b8b06a55fcf5fe4464bc3e00d01248e5",
    "routes": "9392e22e4c472610da98977bec4e112092d223b9",
    "tests": "d05c50ac79105f337a055a82b3e78056de8b62ed",
}

C5_TRANSITIONS = (
    {
        "path": "resources/js/pages/my-day/components/whats-next-rail.tsx",
        "historical_merge_blob": "98071a97cb19f45426ecdc31beb1d7b91b4949f1",
        "effective_blob": "af084e912beab5be7cd95b866d545306af024647",
        "historical_merge_bytes": 7464,
        "effective_bytes": 7514,
        "historical_merge_sha256": "12d6cdaec189fb5c3b085af31e53a843f9235d97168ad756596f85daf670a6dd",
        "effective_sha256": "2691cdd0d1d29b00525fe067fc3cec6b554479f48ebbde60f79e6d25a1ace55d",
        "classification": "POST_MERGE_ADJACENT_PATH",
    },
    {
        "path": "resources/js/pages/my-day/index-audit-fixes.test.tsx",
        "historical_merge_blob": "90323a2a7885d4e21f4768c12756d87482514cb5",
        "effective_blob": "89df3b6cd8137710cfa0cb6d891295be67557de1",
        "historical_merge_bytes": 8680,
        "effective_bytes": 9025,
        "historical_merge_sha256": "e3a9b805ff86142342db93a53963ca91514864ed314f4d07abd2eae3ccb8fe43",
        "effective_sha256": "c94352936b81cf06567755f72285e22c5beff6c13b21c0df04e4fbb9a221a193",
        "classification": "POST_MERGE_ADJACENT_PATH",
    },
    {
        "path": "resources/js/pages/my-day/index.tsx",
        "historical_merge_blob": "b454ee22f9f18db6d53eec39ac7e92158b345916",
        "effective_blob": "85ab102d1c6cd2bc96d322dc4aefe7b5254e2e21",
        "historical_merge_bytes": 64800,
        "effective_bytes": 65002,
        "historical_merge_sha256": "7df2b1b25cb3b3c57eeba7e8b4fc66f4c78f4268317d42d7e42dcd376607ce4b",
        "effective_sha256": "d6d4fb72aa328be312c0ef402603c77fcc6d915b66c0637b7d563d2a27e38ec4",
        "classification": "MERGE_PAYLOAD_SUPERSEDED_AT_EFFECTIVE_CHECKPOINT",
    },
)

PROVISIONAL_FINDING_HASHES = {
    "MED-RBAC-01": "aa35c543ac25d15d074b344abd6ce8750975717f6c6e229d36986256c5a301ea",
    "MED-CD-SCOPE-01": "dd86bf94f3b4d894e95c56c95a9409ce803b8d82d108cdd3c42f3343e348cd21",
    "MED-CD-ATOMICITY-01": "9ba4f430ee59efea414b42a8633c1c969a2fd4428fbf3fef173fb5548cc8e7f1",
}


def run_git(*args: str, check: bool = True) -> subprocess.CompletedProcess[bytes]:
    return subprocess.run(
        ["git", *args], cwd=ROOT, check=check, capture_output=True
    )


def git(*args: str) -> str:
    return run_git(*args).stdout.decode("utf-8").rstrip("\r\n")


def git_lines(*args: str) -> list[str]:
    value = git(*args)
    return [] if not value else value.splitlines()


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sequence_sha256(lines: list[str] | tuple[str, ...]) -> str:
    return sha256_bytes(("\n".join(lines) + "\n").encode("utf-8"))


def canonical_sha256(value: Any) -> str:
    payload = json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")
    return sha256_bytes(payload)


def file_sha256(relative: str) -> str:
    return sha256_bytes((AUDIT / relative).read_bytes())


def working_blob(relative: str) -> str:
    return git("hash-object", "--", str(AUDIT / relative))


def commit_blob(commit: str, path: str) -> str:
    return git("rev-parse", f"{commit}:{path}")


def blob_bytes(commit: str, path: str) -> bytes:
    return run_git("cat-file", "blob", f"{commit}:{path}").stdout


def strict_json(relative: str) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, f"duplicate JSON key {key!r} in {relative}"
            result[key] = value
        return result

    value = json.loads((AUDIT / relative).read_bytes(), object_pairs_hook=hook)
    assert isinstance(value, dict)
    return value


def diff_stats(older: str, newer: str) -> tuple[int, int, int]:
    rows = git_lines("diff", "--numstat", older, newer)
    added = 0
    deleted = 0
    for row in rows:
        parts = row.split("\t")
        assert len(parts) == 3 and parts[0].isdigit() and parts[1].isdigit()
        added += int(parts[0])
        deleted += int(parts[1])
    return len(rows), added, deleted


def object_map(commit: str) -> dict[str, tuple[str, str]]:
    result: dict[str, tuple[str, str]] = {}
    for row in git_lines("ls-tree", "-r", commit):
        metadata, path = row.split("\t", 1)
        _, kind, object_id = metadata.split(" ", 2)
        assert path not in result
        result[path] = (kind, object_id)
    return result


def non_audit_manifest(commit: str) -> list[str]:
    rows: list[str] = []
    for row in git_lines("ls-tree", "-r", commit):
        _, path = row.split("\t", 1)
        if not path.startswith(AUDIT_PREFIX):
            rows.append(row)
    return rows


def status_lines() -> list[str]:
    return git_lines("status", "--porcelain", "--untracked-files=all")


def validate_clean_scope_before_write() -> None:
    allowed = {
        f"?? {PREFIX}/{MATERIALIZER}",
        f"?? {PREFIX}/{OUTPUT}",
    }
    current = set(status_lines())
    assert current <= allowed, sorted(current - allowed)
    assert f"?? {PREFIX}/{MATERIALIZER}" in current
    assert not list(AUDIT.rglob("__pycache__"))


def validate_git_topology() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
    assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
    assert git("rev-parse", f"{HISTORICAL_MERGE}^{{tree}}") == HISTORICAL_MERGE_TREE
    assert git("rev-parse", f"{MERGE_PARENT_1}^{{tree}}") == MERGE_PARENT_1_TREE
    assert git("rev-parse", f"{MERGE_PARENT_2}^{{tree}}") == MERGE_PARENT_2_TREE
    assert git("rev-parse", f"{COMMON_BASE}^{{tree}}") == COMMON_BASE_TREE
    assert git("rev-parse", f"{EFFECTIVE_APPLICATION_COMMIT}^{{tree}}") == EFFECTIVE_APPLICATION_TREE
    assert git("show", "-s", "--format=%P", HISTORICAL_MERGE).split() == [
        MERGE_PARENT_1,
        MERGE_PARENT_2,
    ]
    assert git("merge-base", MERGE_PARENT_1, MERGE_PARENT_2) == COMMON_BASE
    assert git("show", "-s", "--format=%P", EFFECTIVE_APPLICATION_COMMIT) == HISTORICAL_MERGE
    assert run_git(
        "merge-base", "--is-ancestor", EFFECTIVE_APPLICATION_COMMIT, CHECKPOINT_COMMIT,
        check=False,
    ).returncode == 0

    topology_commits = git_lines(
        "rev-list", "--reverse", "--topo-order", f"{COMMON_BASE}..{HISTORICAL_MERGE}"
    )
    assert tuple(topology_commits) == TOPOLOGY_COMMITS
    assert sequence_sha256(topology_commits) == TOPOLOGY_COMMIT_LIST_SHA256
    return {
        "merge_commit": HISTORICAL_MERGE,
        "merge_tree": HISTORICAL_MERGE_TREE,
        "parent_1": MERGE_PARENT_1,
        "parent_1_tree": MERGE_PARENT_1_TREE,
        "parent_2": MERGE_PARENT_2,
        "parent_2_tree": MERGE_PARENT_2_TREE,
        "common_base": COMMON_BASE,
        "common_base_tree": COMMON_BASE_TREE,
        "commits_since_common_base": len(topology_commits),
        "ordered_commits": topology_commits,
        "ordered_commit_list_sha256": sequence_sha256(topology_commits),
    }


def validate_historical_merge_payload() -> tuple[dict[str, Any], list[str]]:
    name_status = git_lines("diff", "--name-status", MERGE_PARENT_1, HISTORICAL_MERGE)
    paths = git_lines("diff", "--name-only", MERGE_PARENT_1, HISTORICAL_MERGE)
    status_counts = Counter(row.split("\t", 1)[0] for row in name_status)
    assert len(name_status) == len(paths) == 359
    assert status_counts == {"A": 87, "M": 272}
    assert sequence_sha256(name_status) == MERGE_NAME_STATUS_SHA256
    assert sequence_sha256(paths) == MERGE_PATH_LIST_SHA256
    assert diff_stats(MERGE_PARENT_1, HISTORICAL_MERGE) == (359, 76238, 9031)

    merge_objects = object_map(HISTORICAL_MERGE)
    path_blob_lines: list[str] = []
    for path in paths:
        kind, object_id = merge_objects[path]
        assert kind == "blob"
        path_blob_lines.append(f"{path}\t{object_id}")
    assert sequence_sha256(path_blob_lines) == MERGE_PATH_BLOB_SHA256

    common_status = git_lines("diff", "--name-status", COMMON_BASE, HISTORICAL_MERGE)
    common_paths = git_lines("diff", "--name-only", COMMON_BASE, HISTORICAL_MERGE)
    common_counts = Counter(row.split("\t", 1)[0] for row in common_status)
    assert len(common_status) == len(common_paths) == 394
    assert common_counts == {"A": 90, "M": 304}
    assert sequence_sha256(common_status) == COMMON_BASE_NAME_STATUS_SHA256
    assert sequence_sha256(common_paths) == COMMON_BASE_PATH_LIST_SHA256
    assert diff_stats(COMMON_BASE, HISTORICAL_MERGE) == (394, 81375, 10154)

    return {
        "first_parent_payload": {
            "paths": 359,
            "added_paths": 87,
            "modified_paths": 272,
            "deleted_or_renamed_paths": 0,
            "lines_added": 76238,
            "lines_deleted": 9031,
            "name_status_sha256": sequence_sha256(name_status),
            "path_list_sha256": sequence_sha256(paths),
            "path_blob_manifest_sha256": sequence_sha256(path_blob_lines),
        },
        "common_base_cumulative": {
            "paths": 394,
            "added_paths": 90,
            "modified_paths": 304,
            "deleted_or_renamed_paths": 0,
            "lines_added": 81375,
            "lines_deleted": 10154,
            "name_status_sha256": sequence_sha256(common_status),
            "path_list_sha256": sequence_sha256(common_paths),
        },
    }, paths


def validate_effective_application(payload_paths: list[str]) -> tuple[dict[str, Any], dict[str, Any]]:
    name_status = git_lines(
        "diff", "--name-status", HISTORICAL_MERGE, EFFECTIVE_APPLICATION_COMMIT
    )
    paths = git_lines("diff", "--name-only", HISTORICAL_MERGE, EFFECTIVE_APPLICATION_COMMIT)
    assert tuple(name_status) == tuple(f"M\t{row['path']}" for row in C5_TRANSITIONS)
    assert paths == [row["path"] for row in C5_TRANSITIONS]
    assert sequence_sha256(name_status) == C5_NAME_STATUS_SHA256
    assert sequence_sha256(paths) == C5_PATH_LIST_SHA256
    assert diff_stats(HISTORICAL_MERGE, EFFECTIVE_APPLICATION_COMMIT) == (3, 38, 23)

    transition_lines: list[str] = []
    for expected in C5_TRANSITIONS:
        path = expected["path"]
        historical_raw = blob_bytes(HISTORICAL_MERGE, path)
        effective_raw = blob_bytes(EFFECTIVE_APPLICATION_COMMIT, path)
        assert commit_blob(HISTORICAL_MERGE, path) == expected["historical_merge_blob"]
        assert commit_blob(EFFECTIVE_APPLICATION_COMMIT, path) == expected["effective_blob"]
        assert len(historical_raw) == expected["historical_merge_bytes"]
        assert len(effective_raw) == expected["effective_bytes"]
        assert sha256_bytes(historical_raw) == expected["historical_merge_sha256"]
        assert sha256_bytes(effective_raw) == expected["effective_sha256"]
        short_class = (
            "MERGE_PAYLOAD_SUPERSEDED"
            if path in payload_paths
            else "POST_MERGE_ADJACENT_PATH"
        )
        assert (
            expected["classification"] == "MERGE_PAYLOAD_SUPERSEDED_AT_EFFECTIVE_CHECKPOINT"
        ) == (short_class == "MERGE_PAYLOAD_SUPERSEDED")
        transition_lines.append(
            f"{path}\t{expected['historical_merge_blob']}\t{expected['effective_blob']}\t{short_class}"
        )
    assert sequence_sha256(transition_lines) == C5_TRANSITION_MANIFEST_SHA256

    historical_objects = object_map(HISTORICAL_MERGE)
    effective_objects = object_map(EFFECTIVE_APPLICATION_COMMIT)
    state_lines: list[str] = []
    effective_path_blob_lines: list[str] = []
    unchanged_paths: list[str] = []
    superseded_paths: list[str] = []
    for path in payload_paths:
        historical_kind, historical_blob = historical_objects[path]
        effective_kind, effective_blob = effective_objects[path]
        assert historical_kind == effective_kind == "blob"
        if historical_blob == effective_blob:
            state = "UNCHANGED"
            unchanged_paths.append(path)
        else:
            state = "SUPERSEDED_AT_EFFECTIVE_CHECKPOINT"
            superseded_paths.append(path)
        state_lines.append(f"{path}\t{historical_blob}\t{effective_blob}\t{state}")
        effective_path_blob_lines.append(f"{path}\t{effective_blob}")

    assert len(unchanged_paths) == 358
    assert superseded_paths == ["resources/js/pages/my-day/index.tsx"]
    assert sequence_sha256(unchanged_paths) == UNCHANGED_358_PATH_LIST_SHA256
    assert sequence_sha256(state_lines) == EFFECTIVE_PAYLOAD_STATE_SHA256
    assert sequence_sha256(effective_path_blob_lines) == EFFECTIVE_PAYLOAD_PATH_BLOB_SHA256

    for path, expected_tree in EFFECTIVE_SUBTREES.items():
        assert git("rev-parse", f"{EFFECTIVE_APPLICATION_COMMIT}:{path}") == expected_tree
        assert git("rev-parse", f"{CHECKPOINT_COMMIT}:{path}") == expected_tree

    return {
        "commit": EFFECTIVE_APPLICATION_COMMIT,
        "tree": EFFECTIVE_APPLICATION_TREE,
        "parent": HISTORICAL_MERGE,
        "effective_subtrees": EFFECTIVE_SUBTREES,
        "historical_merge_payload_paths": 359,
        "historical_merge_payload_blobs_unchanged": 358,
        "historical_merge_payload_blobs_superseded": 1,
        "superseded_merge_payload_paths": superseded_paths,
        "unchanged_path_list_sha256": sequence_sha256(unchanged_paths),
        "effective_payload_state_manifest_sha256": sequence_sha256(state_lines),
        "effective_payload_path_blob_manifest_sha256": sequence_sha256(
            effective_path_blob_lines
        ),
    }, {
        "paths": list(C5_TRANSITIONS),
        "path_count": 3,
        "modified_paths": 3,
        "lines_added": 38,
        "lines_deleted": 23,
        "name_status_sha256": sequence_sha256(name_status),
        "path_list_sha256": sequence_sha256(paths),
        "transition_manifest_sha256": sequence_sha256(transition_lines),
        "merge_payload_overlap_paths": ["resources/js/pages/my-day/index.tsx"],
        "post_merge_adjacent_paths": [
            row["path"] for row in C5_TRANSITIONS if row["path"] not in payload_paths
        ],
    }


def validate_later_audit_only_lineage() -> dict[str, Any]:
    later_commits = git_lines(
        "rev-list", "--reverse", f"{EFFECTIVE_APPLICATION_COMMIT}..{CHECKPOINT_COMMIT}"
    )
    assert tuple(later_commits) == POST_C5_COMMITS
    assert sequence_sha256(later_commits) == POST_C5_COMMIT_LIST_SHA256
    assert git_lines(
        "rev-list", "--merges", f"{EFFECTIVE_APPLICATION_COMMIT}..{CHECKPOINT_COMMIT}"
    ) == []

    commit_records: list[dict[str, Any]] = []
    for commit in later_commits:
        delta = tuple(git_lines("diff-tree", "--no-commit-id", "--name-status", "-r", commit))
        assert delta == EXPECTED_COMMIT_DELTAS[commit]
        assert all(row.split("\t", 1)[1].startswith(AUDIT_PREFIX) for row in delta)
        commit_records.append(
            {
                "commit": commit,
                "tree": git("rev-parse", f"{commit}^{{tree}}"),
                "parent": git("show", "-s", "--format=%P", commit),
                "changed_paths": len(delta),
                "name_status": list(delta),
                "audit_root_only": True,
            }
        )

    cumulative_status = git_lines(
        "diff", "--name-status", EFFECTIVE_APPLICATION_COMMIT, CHECKPOINT_COMMIT
    )
    cumulative_paths = git_lines(
        "diff", "--name-only", EFFECTIVE_APPLICATION_COMMIT, CHECKPOINT_COMMIT
    )
    assert tuple(cumulative_status) == EXPECTED_POST_C5_STATUS
    assert len(cumulative_paths) == 12
    assert Counter(row.split("\t", 1)[0] for row in cumulative_status) == {
        "A": 6,
        "M": 6,
    }
    assert sequence_sha256(cumulative_status) == POST_C5_NAME_STATUS_SHA256
    assert sequence_sha256(cumulative_paths) == POST_C5_PATH_LIST_SHA256
    assert diff_stats(EFFECTIVE_APPLICATION_COMMIT, CHECKPOINT_COMMIT) == (12, 6010, 1217)
    assert all(path.startswith(AUDIT_PREFIX) for path in cumulative_paths)

    effective_non_audit = non_audit_manifest(EFFECTIVE_APPLICATION_COMMIT)
    checkpoint_non_audit = non_audit_manifest(CHECKPOINT_COMMIT)
    assert effective_non_audit == checkpoint_non_audit
    assert len(effective_non_audit) == 12784
    assert sequence_sha256(effective_non_audit) == NON_AUDIT_MANIFEST_SHA256

    return {
        "effective_application_is_ancestor_of_receipt_checkpoint": True,
        "commits_after_effective_application_checkpoint": len(later_commits),
        "ordered_commits": commit_records,
        "ordered_commit_list_sha256": sequence_sha256(later_commits),
        "merge_commits_after_effective_application_checkpoint": 0,
        "cumulative_changed_paths": 12,
        "cumulative_added_paths": 6,
        "cumulative_modified_paths": 6,
        "cumulative_lines_added": 6010,
        "cumulative_lines_deleted": 1217,
        "cumulative_name_status_sha256": sequence_sha256(cumulative_status),
        "cumulative_path_list_sha256": sequence_sha256(cumulative_paths),
        "all_later_paths_inside_exact_audit_root": True,
        "non_audit_tracked_entries": len(effective_non_audit),
        "non_audit_tree_manifest_sha256": sequence_sha256(effective_non_audit),
        "effective_and_receipt_checkpoint_non_audit_manifests_equal": True,
    }


def provisional_finding_references() -> dict[str, Any]:
    findings_relative = "findings.json"
    findings = strict_json(findings_relative)
    records = {row["id"]: row for row in findings["records"]}
    references: list[dict[str, Any]] = []
    for finding_id, expected_sha in PROVISIONAL_FINDING_HASHES.items():
        record = records[finding_id]
        assert canonical_sha256(record) == expected_sha
        assert record["record_status"] == "PROVISIONAL_SOURCE_CLAIM_NOT_FINAL_FINDING"
        assert record["priority_status"] == "PROVISIONAL_NOT_FINAL_PRIORITY_COUNT"
        assert record["completion_credit"] is False
        assert all(value is False for value in record["credit"].values())
        assert record["frontend_anchor"]["application_commit"] == AUDITED_FINDINGS_APPLICATION_COMMIT
        assert record["backend_anchor"]["application_commit"] == AUDITED_FINDINGS_APPLICATION_COMMIT
        references.append(
            {
                "id": finding_id,
                "canonical_record_sha256": expected_sha,
                "record_status": record["record_status"],
                "audited_application_commit": AUDITED_FINDINGS_APPLICATION_COMMIT,
                "reference_only": True,
                "promoted_or_rebased_by_run_156": False,
                "final_finding_credit": False,
                "completion_credit": False,
            }
        )
    return {
        "findings_path": findings_relative,
        "findings_sha256": file_sha256(findings_relative),
        "findings_blob_id": working_blob(findings_relative),
        "reference_count": len(references),
        "records": references,
        "historical_audited_application_pin": AUDITED_FINDINGS_APPLICATION_COMMIT,
        "historical_audited_application_pin_preserved": True,
        "finding_register_mutated_by_run_156": False,
        "finding_or_priority_promotion_authorized": False,
    }


def write_receipt() -> None:
    topology = validate_git_topology()
    historical_payload, payload_paths = validate_historical_merge_payload()
    effective_checkpoint, my_day_delta = validate_effective_application(payload_paths)
    later_lineage = validate_later_audit_only_lineage()
    finding_references = provisional_finding_references()

    observed_local_origin_main = git("rev-parse", "refs/remotes/origin/main")
    assert observed_local_origin_main == OBSERVED_LOCAL_ORIGIN_MAIN
    local_behind, local_ahead = (
        int(value)
        for value in git(
            "rev-list",
            "--left-right",
            "--count",
            f"refs/remotes/origin/main...{CHECKPOINT_COMMIT}",
        ).split()
    )
    assert (local_ahead, local_behind) == (179, 0)
    local_only_origin_attestation = {
        "boundary": "LOCAL_REMOTE_TRACKING_OBSERVATION_ONLY",
        "scope_wording": "unfetched local remote-tracking observation only; no current remote state, publication, or push is verified",
        "observed_local_remote_tracking_ref": "origin/main",
        "observed_local_remote_tracking_ref_sha": observed_local_origin_main,
        "observed_at_local_checkpoint_commit": CHECKPOINT_COMMIT,
        "local_ahead": local_ahead,
        "local_behind": local_behind,
        "fetch_performed": False,
        "remote_currency_verified": False,
        "publication_or_push_verified": False,
    }

    credit_false = (
        "source_merge_or_change_recredit",
        "application_source_mutation",
        "remediation_or_defect_closure",
        "finding",
        "final_finding",
        "priority_promotion",
        "runtime",
        "database",
        "build",
        "application_browser",
        "responsive_application",
        "visual_or_workflow",
        "executed_tests",
        "test_coverage",
        "coverage_completion",
        "benchmark_mapping",
        "final_no_match_or_NCM",
        "origin_currency_correctness",
        "origin_currency_coverage",
        "remote_currency",
        "publication_or_push",
        "ease",
        "release",
        "pass",
        "feature_completion",
        "completion",
        "audit_complete",
    )
    receipt = {
        "schema_version": "run-156-medication-governance-source-main-receipt-wave-27-v1",
        "run_id": RUN_ID,
        "generated_on": "2026-08-29",
        "status": "TWO_CHECKPOINT_MEDICATION_SOURCE_INTEGRATION_RECEIPT_358_OF_359_MERGE_PATHS_EFFECTIVE_ONE_SUPERSEDED_ZERO_OUTCOME_CREDIT",
        "architecture_rule": {
            "operating_organisations": 1,
            "multiple_sites": True,
            "multi_tenant": False,
            "authorization_boundary": "APPROVED_SITES_ROLES_PERMISSIONS_CANONICAL_OWNERSHIP_DIRECT_OBJECT_CONCEALMENT_PRIVACY",
        },
        "scope": "Git-object receipt for the historical medication-governance merge payload and the later effective application-source checkpoint only.",
        "pins": {
            "receipt_checkpoint_commit": CHECKPOINT_COMMIT,
            "receipt_checkpoint_tree": CHECKPOINT_TREE,
            "historical_merge_commit": HISTORICAL_MERGE,
            "historical_merge_tree": HISTORICAL_MERGE_TREE,
            "effective_application_commit": EFFECTIVE_APPLICATION_COMMIT,
            "effective_application_tree": EFFECTIVE_APPLICATION_TREE,
            "run_155_dashboard_materializer": {
                "path": "generators/materialize-run-155-audit-dashboard-verification-wave-26.py",
                "sha256": file_sha256("generators/materialize-run-155-audit-dashboard-verification-wave-26.py"),
                "blob_id": working_blob("generators/materialize-run-155-audit-dashboard-verification-wave-26.py"),
            },
            "run_155_dashboard_receipt": {
                "path": "evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json",
                "sha256": file_sha256("evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json"),
                "blob_id": working_blob("evidence/browser/current-audit-dashboard-verification-run-155-wave-26.json"),
            },
            "materializer": MATERIALIZER,
            "materializer_sha256": file_sha256(MATERIALIZER),
            "materializer_blob_id": working_blob(MATERIALIZER),
        },
        "two_checkpoint_rule": {
            "historical_merge_payload_checkpoint": HISTORICAL_MERGE,
            "effective_application_source_checkpoint": EFFECTIVE_APPLICATION_COMMIT,
            "historical_merge_tree_is_not_presented_as_the_effective_application_tree": True,
            "all_359_historical_merge_payload_blobs_claimed_current": False,
            "effective_source_uses_358_unchanged_merge_payload_blobs_and_one_superseding_blob": True,
        },
        "historical_merge_checkpoint": topology,
        "historical_merge_payload": historical_payload,
        "effective_application_checkpoint": effective_checkpoint,
        "post_merge_my_day_delta": my_day_delta,
        "later_audit_only_lineage": later_lineage,
        "provisional_finding_reference_boundary": finding_references,
        "local_only_origin_attestation": local_only_origin_attestation,
        "mutation_attestation": {
            "run_156_writes_only_generator_and_receipt": True,
            "application_source_changed": False,
            "test_files_changed": False,
            "findings_changed": False,
            "reports_changed": False,
            "dashboard_generator_changed": False,
            "dashboard_html_changed": False,
            "matrix_or_benchmark_register_changed": False,
            "runtime_or_external_system_changed": False,
            "database_changed": False,
            "browser_started": False,
            "tests_or_coverage_executed": False,
            "audit_artifacts_only": True,
        },
        "credit_boundary": {
            "GIT_SOURCE_INTEGRATION_RECEIPT": True,
            **{key: False for key in credit_false},
        },
        "completion_boundary": {
            key: False
            for key in (
                "semantic_assurance_complete",
                "execution_complete",
                "coverage_complete",
                "benchmark_complete",
                "pass_8_complete",
                "final_reconciliation_complete",
                "no_live_agent_gate_complete",
                "gate_4_complete",
                "audit_complete",
            )
        },
        "artifact_completion_test_met": True,
        "audit_completion_test_met": False,
        "wrote_files": [
            f"{PREFIX}/{MATERIALIZER}",
            f"{PREFIX}/{OUTPUT}",
        ],
    }
    (AUDIT / OUTPUT).parent.mkdir(parents=True, exist_ok=True)
    (AUDIT / OUTPUT).write_text(
        json.dumps(receipt, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def validate_output() -> dict[str, Any]:
    receipt = strict_json(OUTPUT)
    assert receipt["run_id"] == RUN_ID
    assert receipt["pins"]["materializer_sha256"] == file_sha256(MATERIALIZER)
    assert receipt["pins"]["materializer_blob_id"] == working_blob(MATERIALIZER)
    assert receipt["two_checkpoint_rule"]["all_359_historical_merge_payload_blobs_claimed_current"] is False
    assert receipt["effective_application_checkpoint"]["historical_merge_payload_blobs_unchanged"] == 358
    assert receipt["effective_application_checkpoint"]["superseded_merge_payload_paths"] == [
        "resources/js/pages/my-day/index.tsx"
    ]
    origin_attestation = receipt["local_only_origin_attestation"]
    assert origin_attestation["boundary"] == "LOCAL_REMOTE_TRACKING_OBSERVATION_ONLY"
    assert "unfetched local remote-tracking observation only" in origin_attestation[
        "scope_wording"
    ]
    assert origin_attestation["observed_local_remote_tracking_ref"] == "origin/main"
    assert origin_attestation["observed_local_remote_tracking_ref_sha"] == OBSERVED_LOCAL_ORIGIN_MAIN
    assert origin_attestation["observed_at_local_checkpoint_commit"] == CHECKPOINT_COMMIT
    assert (origin_attestation["local_ahead"], origin_attestation["local_behind"]) == (179, 0)
    assert origin_attestation["fetch_performed"] is False
    assert origin_attestation["remote_currency_verified"] is False
    assert origin_attestation["publication_or_push_verified"] is False
    assert [key for key, value in receipt["credit_boundary"].items() if value] == [
        "GIT_SOURCE_INTEGRATION_RECEIPT"
    ]
    assert all(value is False for value in receipt["completion_boundary"].values())
    for relative in (MATERIALIZER, OUTPUT):
        payload = (AUDIT / relative).read_bytes()
        assert payload.endswith(b"\n") and b"\r\n" not in payload
        assert not payload.startswith(b"\xef\xbb\xbf")
    expected_status = {
        f"?? {PREFIX}/{MATERIALIZER}",
        f"?? {PREFIX}/{OUTPUT}",
    }
    assert set(status_lines()) == expected_status
    assert not list(AUDIT.rglob("__pycache__"))
    return receipt


def main() -> None:
    validate_clean_scope_before_write()
    write_receipt()
    receipt = validate_output()
    print(
        json.dumps(
            {
                "status": receipt["status"],
                "materializer_sha256": file_sha256(MATERIALIZER),
                "receipt_sha256": file_sha256(OUTPUT),
                "historical_merge_payload_paths": 359,
                "effective_unchanged_merge_payload_blobs": 358,
                "effective_superseded_merge_payload_blobs": 1,
                "later_audit_only_commits": 3,
                "non_audit_tree_manifest_sha256": NON_AUDIT_MANIFEST_SHA256,
                "positive_credit_keys": ["GIT_SOURCE_INTEGRATION_RECEIPT"],
                "audit_complete": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
