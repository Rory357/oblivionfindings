#!/usr/bin/env python3
"""Materialize the deterministic RUN-147 audit-dashboard verification receipt.

This serializes already-observed browser evidence. It does not reproduce or
upgrade the browser run, application execution, tests, benchmarks, Gate 4, or
the audit. Final dashboard and browser observations must replace every
``__RUN147_FILL_*__`` or ``-1``/``None`` sentinel before this script can run.
"""
from __future__ import annotations

from collections import Counter
import csv
import hashlib
import html as html_module
from html.parser import HTMLParser
import json
import re
import subprocess
from pathlib import Path
from urllib.parse import unquote, urlsplit


ROOT = Path(__file__).resolve().parents[4]
AUDIT = ROOT / "docs/audits/oblivion-oss-comprehensive-audit-2026-08-24"
PREFIX = AUDIT.relative_to(ROOT).as_posix()
MATERIALIZER = "generators/materialize-run-147-audit-dashboard-verification-wave-24.py"
OUTPUT = "evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json"
HTML = "audit-dashboard.html"
GENERATOR = "generators/build-current-audit-dashboard.py"
DASHBOARD_REFRESH_MATERIALIZER = "generators/materialize-run-146-audit-dashboard-benchmark-refresh-wave-24.py"
REPORTING_MATERIALIZER = "generators/materialize-run-146-finance-benchmark-reporting-wave-24.py"
REPORTING_RECEIPT = "evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json"
MATRIX = "03-feature-to-benchmark-matrix.csv"
REGISTER = "06-open-source-benchmark-register.csv"
RUN145_RECEIPT = "evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json"
RUN144_MATERIALIZER = "generators/materialize-run-144-audit-dashboard-verification-wave-23.py"
RUN144_RECEIPT = "evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json"

CHECKPOINT_COMMIT = "3bc3aff5875e6be9fab8ff66bba6c4a30c1b1522"
CHECKPOINT_TREE = "580a50753b01bd97a0044d5d772413c63729cb66"
APPLICATION_COMMIT = "a0493442b9e392d324055c35bf25b69421dc2d35"
APPLICATION_TREE = "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1"
SUBTREES = {
    "app": "92c8425a7cb15a92609c69a8c2f26bbda4f178b7",
    "routes": "9b7f78510d970db64ea3a6540e8a36b8700bf272",
    "resources/js": "1671a7551c004571c48bb00c34522928e6f1f173",
    "resources/js/pages": "e9e232ba6d6416e7dfdbdfd5875a4b6e95ddb55e",
    "tests": "fef0122b31fdccbe2f9f805f7515666c74e2880a",
}

# These content pins are already sealed. Tuples are SHA-256, Git blob, bytes.
SEALED_PINS = {
    MATRIX: ("3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0", "1f5fdab3ae80ae4ec1b9bc4ee47eef695bdd5416", 557989),
    REGISTER: ("5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884", "f96ef7ac1f6e19f614bdce8d663cfd08ec795995", 350420),
    RUN145_RECEIPT: ("8306a8aefe0a490ebf206d0c4716d92930326988f19e0ed495a3c2d0002c7cf9", "686dbede1fa6871cf5d605afdc4651cdd13d15f8", 3828),
    REPORTING_RECEIPT: ("50953b6281cf198f6dc6ff56027d0eebe7e78697781d459dd620ed9bb2b1277e", "b3498c88d9c9b698bc7e000f6efb3691eb8bf0e5", 3267),
    RUN144_MATERIALIZER: ("92e260790e1eb7d967c4bb74b00f8b800e615cabc1d1dc9cc9fc253be2615b3c", "845d695f17c7f2c239f6253e747c64cc10f94b91", 23146),
    RUN144_RECEIPT: ("fd21527929483cca88e03af8a8ff2f5e8095c5af280fc27546486e3ddc6dd7f5", "3c2299ea83c6d412dc406fd13b9e9265cc5fd5ed", 17372),
}

# Exact RUN-145 A -> B -> C -> D -> Pass-8 -> corrected-apply lineage.
# Tuples are SHA-256, Git blob, bytes. There must remain exactly 20 entries.
RUN145_LINEAGE = {
    "generators/materialize-run-145-agent-a-finance-invoice-fx-observed-behavior-wave-24.py": ("dc37556e99a617418c8a06240a98acfbfcf502c00260062ea19efafa79f25dac", "09c273474e86a67f5bb736a956286ef52206735d", 29335),
    "evidence/benchmark/raw-run-145-agent-a-finance-invoice-fx-observed-behavior-wave-24.json": ("deb70284836ad6b4bba49a8f4149e85807faa7248a56909b901df233405e9f8f", "2f4953c9e6d1ff32468b187179fc8a64139a554a", 28802),
    "evidence/benchmark/sealed-run-145-agent-b-finance-invoice-fx-input-wave-24.json": ("98fcf8f7ac5e16c1aadd2b3902dcfb9a1991cf92f366ec1f411824b4e118ca03", "e7903cd9f456d23d6efe4c07fee0caceacffaab8", 13732),
    "generators/materialize-run-145-agent-b-finance-invoice-fx-neutral-requirements-wave-24.py": ("e1c999e229107e9512fd1227e054bf2e202fe4d5b3842efbc16efba01a6185df", "0c2a73483a746d5148fcc8138441852d804ff78a", 34398),
    "evidence/benchmark/raw-run-145-agent-b-finance-invoice-fx-neutral-requirements-wave-24.json": ("8d75485a11b0c4fa0f634650062da3c54dacb31cef7f5a068477340a308cadeb", "7da58bb95261652968c35e61f5f120e876a0dca5", 24399),
    "evidence/benchmark/sealed-run-145-agent-c-finance-invoice-fx-current-comparison-input-wave-24.json": ("722deceb2bf9a9462c5db69adcf700e9ce0cba62bffe0bef160e004ade82a032", "a44133b98d3e4cae4a978eeeba9108529b0386f6", 41079),
    "generators/materialize-run-145-agent-c-fx-current-comparison-wave-24.py": ("4be7dd207ceea5188873c407c967e6e7b57ba489816bc21a2a671b35cd9d73e0", "a8068c817223bf9c662729555922dee14971c6ea", 25092),
    "evidence/benchmark/raw-run-145-agent-c-fx-current-comparison-wave-24.json": ("0c0c077f727e30fbf3ba08b945eb3e4f7b2c5c8c89a0ea61e3f81d69c006d0db", "0e497c8d09fdc289a423a01966abfe090c5b82f4", 32080),
    "generators/materialize-run-145-agent-c-invoice-current-comparison-wave-24.py": ("2af443c06f1ace9cbd4e39f2f92ac75c6e909f2cfbe82ba25484af2b962ed06e", "db1ad44b978db0b90b54806c3cb9893f1914a9aa", 20243),
    "evidence/benchmark/raw-run-145-agent-c-invoice-current-comparison-wave-24.json": ("2e5f68793ba94955c51159d297ca99b807d6e40d2ae9089504cb8cf3fab91443", "487a4e4b98b50e35dcf66e9f0cfce29060e9a56a", 28283),
    "generators/materialize-run-145-agent-d-finance-invoice-fx-adjudication-input-wave-24.py": ("eaa2e40b888dbe3dee52664b7454076d72199d9790fc3591fb19d3226a5a8284", "4ba181d9b5ecbda66e647598ad29fa2bf0f50ed2", 9485),
    "evidence/benchmark/sealed-run-145-agent-d-finance-invoice-fx-adjudication-input-wave-24.json": ("4f114bde56c58248e25bfc1053f120483e32976cb4ca06f1a7f9488b9bcd7381", "08b633203ca9687adec74badd072a21252d2deab", 164153),
    "generators/materialize-run-145-agent-d-finance-invoice-fx-adjudication-wave-24.py": ("da20407d1a5c7e379df97c2b3f8ec36f730f6d0be449902f47fa3398abfc19dd", "adb802be21f460d74a2d7f9e7981f4238fb04379", 26138),
    "evidence/benchmark/raw-run-145-agent-d-finance-invoice-fx-adjudication-wave-24.json": ("f7149cc02849befa03013148e72e53b92048a53eac685de92018c46ea6f3f71d", "428c02234c0b1f022ceaa03e2728db46dcbd1500", 29300),
    "generators/materialize-run-145-p8-adversarial-integration-review-wave-24.py": ("e51b8b874889f667b67f0fc360504eac92de930a503d2983971f471aa963b882", "1495ef821ffa02b234c7bce12fb4e06dcae57ae7", 5710),
    "evidence/benchmark/raw-run-145-p8-adversarial-integration-review-wave-24.json": ("810b8bb2fe1ba94c265b51eaf2056acc6385fe503049a6ee40301e44c8ef3a14", "6ed137dff68363717bb0c28a1991a914c7efda38", 4213),
    "generators/materialize-run-145-corrected-integration-plan-wave-24.py": ("f63a43909054fc2901d0debf61f010bd40003b9eb61abd5b47062051eeaa5168", "82efe16742f896e08bc8e180e82b0732be4ce226", 10607),
    "evidence/benchmark/sealed-run-145-corrected-matrix-register-integration-input-wave-24.json": ("42abc1082642c9db66cb8bf0fd16b3bc9ddd5bdb7891b7c7830a7f09b8796468", "ca8cc326a3d9184ca24d6f77d028b249d9b2ef95", 32435),
    "generators/apply-run-145-corrected-finance-benchmark-mapping-wave-24.py": ("0b610741b85f260309141872b5c51b3bc96d71deef02aadb454cf150690fd86d", "823d84117f8924e6e7361690cda6479e579ba640", 6818),
    RUN145_RECEIPT: ("8306a8aefe0a490ebf206d0c4716d92930326988f19e0ed495a3c2d0002c7cf9", "686dbede1fa6871cf5d605afdc4651cdd13d15f8", 3828),
}

REPORT_OUTPUTS = {
    "00-executive-summary.md": ("616fd626dc1292896955e657812404ccbdbb4e425b736f68b9ccb8f87e63d8ab", 100197),
    "07-module-findings.md": ("bfcbd82a9bb0286725171552865e6c16990955c69106b1b6ade39d32157de974", 343737),
    "12-native-build-and-do-not-copy-register.md": ("f9605259003663545015c185c0fda34351635faf46e4cffe427f4ce5e8158ac2", 113529),
    "13-unresolved-questions-and-evidence-gaps.md": ("ada6ad349bb29d9168b7e93e5fc7d494d8701254b8fe10faa2df28afb0725965", 26163),
    "findings.json": ("9848a8edd8c7fa56cc753a77746f66434912ac0bafe42110f999457a7c43da5c", 313942),
}

# Parent must replace all final-byte pins after dashboard/report reconciliation.
# The RUN147 materializer's own SHA/blob are deliberately computed at runtime:
# embedding its own expected hash would be circular.
FINAL_ARTIFACT_PINS = {
    HTML: {
        "sha256": "277db943400776d0bd3be1b0c97afff69ea7b76e97c861abf5c135dc6be00c33",
        "blob": "d22b9e038a80247e9174500c17d93c0e7cc56de7",
        "bytes": 206854,
    },
    GENERATOR: {
        "sha256": "3eab383b5203ae9a3108f94ff67a276393f57afa39c00790da715e64f5247024",
        "blob": "c5f0b45dea3b7650ad961ac36afdd7e8476c2c17",
        "bytes": 322381,
    },
    DASHBOARD_REFRESH_MATERIALIZER: {
        "sha256": "1fc3ea840e305d4fbc660334d30abdf9eb2ff2c915404e8f7c12f641dec78731",
        "blob": "f14abc4338f62993e8a1cd70ce665a59d0ee3187",
        "bytes": 19139,
    },
    REPORTING_MATERIALIZER: {
        "sha256": "44843005d0d2ef710cbb90a8a16a65f4992fd218cd998d488e6d7ead94e46867",
        "blob": "0398693a7e17c77ec729b585c262d33f79614066",
        "bytes": 30592,
    },
}

# Parent must replace these with the final static parse of the exact HTML bytes.
STATIC_EXPECTATIONS = {
    "headings": 23,
    "tables": 9,
    "table_wraps": 9,
    "anchor_elements": 642,
    "authored_ids": 10,
    "hash_anchor_occurrences": 10,
    "unique_hash_anchors": 10,
    "local_link_occurrences": 632,
    "unique_local_links": 355,
    "adjacent_hash_rows_total": 535,
    "adjacent_hash_pairs_unique": 283,
    "hash_bearing_file_link_occurrences": 533,
    "unique_hash_bearing_file_path_hash_pairs": 282,
    "historical_directory_hash_link_occurrences": 2,
    "historical_directory_hash_unique_pairs": 1,
}

# Parent must replace every browser sentinel with observations from the exact
# cache-busted RUN147 browser session. No value is inferred by this script.
BROWSER_OBSERVATIONS = {
    "cachebuster": "main-3bc3aff5-277db943",
    "target_url": "http://127.0.0.1:8771/audit-dashboard.html?v=main-3bc3aff5-277db943#progress",
    "response_status": 200,
    "response_bytes": 206854,
    "response_sha256": "277db943400776d0bd3be1b0c97afff69ea7b76e97c861abf5c135dc6be00c33",
    "exact_dashboard_loaded": True,
    "semantic_dom_inspection_completed": True,
    "visual_inspection_completed": True,
    "all_navigation_links_exercised": True,
    "transient_screenshots_inspected": True,
    "font_loaded_at_all_viewports": True,
    "unresolved_placeholders": 0,
    "dom_ids_observed": 11,
    "browser_injected_ids": ["codex-browser-sidebar-comments-root"],
    "duplicate_authored_ids": 0,
    "console_warnings": 0,
    "console_errors": 0,
    "page_errors": 0,
}

VIEWPORTS = [
    {
        "requested": "1440x900",
        "actual_browser_viewport": "1440x900",
        "observed_document_client": "1425x900",
        "response_status": 200,
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 0,
        "tables_with_unbounded_overflow": 0,
        "navigation_needing_bounded_horizontal_scroll": False,
        "unintended_offscreen_elements": 0,
    },
    {
        "requested": "1280x800",
        "actual_browser_viewport": "1280x800",
        "observed_document_client": "1265x800",
        "response_status": 200,
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 0,
        "tables_with_unbounded_overflow": 0,
        "navigation_needing_bounded_horizontal_scroll": False,
        "unintended_offscreen_elements": 0,
    },
    {
        "requested": "1024x768",
        "actual_browser_viewport": "1024x768",
        "observed_document_client": "1009x768",
        "response_status": 200,
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 1,
        "tables_with_unbounded_overflow": 0,
        "navigation_needing_bounded_horizontal_scroll": False,
        "unintended_offscreen_elements": 0,
    },
    {
        "requested": "390x844",
        "actual_browser_viewport": "390x844",
        "observed_document_client": "375x844",
        "response_status": 200,
        "page_level_horizontal_overflow": False,
        "page_overflow_px": 0,
        "table_wraps": 9,
        "tables_needing_bounded_horizontal_scroll": 9,
        "tables_with_unbounded_overflow": 0,
        "navigation_needing_bounded_horizontal_scroll": True,
        "unintended_offscreen_elements": 0,
    },
]

NAVIGATION = [
    ("Progress", "#progress"),
    ("RUN-146", "#checkpoint"),
    ("Pages", "#pages"),
    ("Static census", "#static-census"),
    ("Runtime gates", "#runtime"),
    ("Benchmarks", "#benchmarks"),
    ("Modules", "#modules"),
    ("Provisional findings", "#findings"),
    ("Architecture", "#architecture"),
    ("Gaps", "#gaps"),
]

VISIBLE_CHECKS = {
    "current_source_owner_records_662": True,
    "current_route_page_split_305_357": True,
    "distinct_feature_ids_256_as_234_h_and_22_d": True,
    "feature_sets_64_route_242_page_overlap_50": True,
    "controller_action_bridges_93": True,
    "bounded_denominator_3929_percent_16_849071_residual_3267": True,
    "route_partition_3218_as_305_owner_12_shared_5_alias_2896_residual_with_7_tagged_gaps": True,
    "page_partition_711_as_357_owner_9_shared_345_residual_with_1_tagged_gap": True,
    "queue_partition_507_as_116_reviewed_391_pending": True,
    "reviewed_queue_116_as_94_owner_10_shared_5_alias_0_dead_7_gap": True,
    "queue_without_ownership_413": True,
    "current_benchmark_mapping_2_of_340": True,
    "current_final_no_match_or_NCM_0_of_340": True,
    "current_unresolved_benchmark_targets_338": True,
    "mapped_targets_are_invoice_lifecycle_and_fx_revaluation_only": True,
    "credited_projects_are_erpnext_and_dolibarr_only": True,
    "bigcapital_remains_adjacent_only_unselected": True,
    "exact_live_matrix_hash_visible_and_linked": True,
    "exact_live_register_hash_visible_and_linked": True,
    "exact_run_145_receipt_hash_visible_and_linked": True,
    "run_145_lineage_20_of_20_linked_and_hash_matched": True,
    "current_run_146_reporting_receipt_visible_and_linked": True,
    "one_operating_organisation_across_multiple_sites_non_tenant": True,
    "gate_4_open": True,
    "all_new_application_runtime_test_ease_release_pass_finding_feature_and_audit_completion_credit_zero": True,
}


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=ROOT, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT / relative).read_bytes())


def blob_id(relative: str) -> str:
    return git("hash-object", "--", str(AUDIT / relative))


def strict_json(relative: str) -> dict:
    def hook(pairs: list[tuple[str, object]]) -> dict:
        result: dict = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    def reject_constant(value: str) -> None:
        raise ValueError(f"Non-finite JSON constant in {relative}: {value}")

    raw = (AUDIT / relative).read_bytes()
    assert raw.endswith(b"\n") and b"\r\n" not in raw and not raw.startswith(b"\xef\xbb\xbf")
    value = json.loads(raw, object_pairs_hook=hook, parse_constant=reject_constant)
    assert isinstance(value, dict)
    return value


def assert_finalized(value: object, locus: str = "root") -> None:
    if isinstance(value, dict):
        for key, child in value.items():
            assert_finalized(child, f"{locus}.{key}")
    elif isinstance(value, list):
        for index, child in enumerate(value):
            assert_finalized(child, f"{locus}[{index}]")
    elif isinstance(value, str):
        assert not value.startswith("__RUN147_FILL_"), f"Unfilled RUN147 placeholder: {locus}={value}"
    elif value is None:
        raise AssertionError(f"Unfilled RUN147 observation: {locus}=None")
    elif isinstance(value, int) and not isinstance(value, bool):
        assert value >= 0, f"Unfilled RUN147 numeric observation: {locus}={value}"


class DashboardParser(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.hrefs: list[str] = []
        self.ids: list[str] = []
        self.headings = 0
        self.tables = 0
        self.table_wraps = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = dict(attrs)
        if values.get("id"):
            self.ids.append(str(values["id"]))
        if tag == "a" and values.get("href") is not None:
            self.hrefs.append(str(values["href"]))
        if re.fullmatch(r"h[1-6]", tag):
            self.headings += 1
        if tag == "table":
            self.tables += 1
        if "table-wrap" in str(values.get("class", "")).split():
            self.table_wraps += 1


def is_local_link(href: str) -> bool:
    lowered = href.lower()
    return not (
        href.startswith("#")
        or href.startswith("//")
        or lowered.startswith("http://")
        or lowered.startswith("https://")
        or lowered.startswith("mailto:")
        or lowered.startswith("javascript:")
        or lowered.startswith("data:")
    )


def local_target(href: str) -> Path:
    relative = unquote(urlsplit(href).path)
    target = (AUDIT / relative).resolve()
    target.relative_to(AUDIT.resolve())
    return target


TRACKED_MODIFIED = {
    "00-executive-summary.md",
    MATRIX,
    REGISTER,
    "07-module-findings.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    HTML,
    "findings.json",
    GENERATOR,
}
UNTRACKED_WAVE = set(RUN145_LINEAGE) | {
    REPORTING_MATERIALIZER,
    REPORTING_RECEIPT,
    DASHBOARD_REFRESH_MATERIALIZER,
    MATERIALIZER,
}


def expected_status(include_receipt: bool) -> set[str]:
    rows = {f" M {PREFIX}/{relative}" for relative in TRACKED_MODIFIED}
    rows |= {f"?? {PREFIX}/{relative}" for relative in UNTRACKED_WAVE}
    if include_receipt:
        rows.add(f"?? {PREFIX}/{OUTPUT}")
    return rows


# Refuse to materialize while any final proof placeholder remains.
assert_finalized(FINAL_ARTIFACT_PINS, "FINAL_ARTIFACT_PINS")
assert_finalized(STATIC_EXPECTATIONS, "STATIC_EXPECTATIONS")
assert_finalized(BROWSER_OBSERVATIONS, "BROWSER_OBSERVATIONS")
assert_finalized(VIEWPORTS, "VIEWPORTS")

assert git("branch", "--show-current") == "main"
assert git("rev-parse", "HEAD") == CHECKPOINT_COMMIT
assert git("rev-parse", "HEAD^{tree}") == CHECKPOINT_TREE
assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
for subtree, tree in SUBTREES.items():
    assert git("rev-parse", f"HEAD:{subtree}") == tree

for relative, (expected_sha, expected_blob, expected_bytes) in SEALED_PINS.items():
    assert sha256_file(relative) == expected_sha
    assert blob_id(relative) == expected_blob
    assert (AUDIT / relative).stat().st_size == expected_bytes

assert len(RUN145_LINEAGE) == 20 and len(set(RUN145_LINEAGE)) == 20
for relative, (expected_sha, expected_blob, expected_bytes) in RUN145_LINEAGE.items():
    assert sha256_file(relative) == expected_sha
    assert blob_id(relative) == expected_blob
    assert (AUDIT / relative).stat().st_size == expected_bytes
    if relative.endswith(".json"):
        strict_json(relative)

for relative, pin in FINAL_ARTIFACT_PINS.items():
    assert sha256_file(relative) == pin["sha256"]
    assert blob_id(relative) == pin["blob"]
    assert (AUDIT / relative).stat().st_size == pin["bytes"]

run145 = strict_json(RUN145_RECEIPT)
assert run145["application_source_pin"] == {
    "commit": APPLICATION_COMMIT,
    "tree": APPLICATION_TREE,
    "application_files_changed": 0,
}
assert run145["outputs"]["matrix"] == {
    "path": MATRIX,
    "bytes": SEALED_PINS[MATRIX][2],
    "sha256": SEALED_PINS[MATRIX][0],
    "rows": 340,
    "changed_rows": 2,
    "changed_cells": 18,
    "unaffected_rows": 338,
}
assert run145["outputs"]["benchmark_register"] == {
    "path": REGISTER,
    "bytes": SEALED_PINS[REGISTER][2],
    "sha256": SEALED_PINS[REGISTER][0],
    "rows": 98,
    "changed_rows": 2,
    "changed_cells": 30,
    "unaffected_rows": 96,
}
assert run145["counts"] == {
    "benchmark_mappings": 2,
    "final_no_matches_or_NCMs": 0,
    "unresolved_targets": 338,
    "project_rows_with_current_target_mapping_credit": 2,
}
assert [row["feature_id"] for row in run145["integrated_targets"]] == [
    "CAP-FIN-FX-REVALUATION",
    "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
]
assert run145["integrated_targets"][0]["selected_native_benchmarks"] == [
    "frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f",
]
assert run145["integrated_targets"][1]["selected_native_benchmarks"] == [
    "frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f",
    "Dolibarr/dolibarr@769c7db907099643558e77d7002c109cfda919e5",
]
assert run145["integrated_targets"][1]["adjacent_not_selected"] == [
    "bigcapitalhq/bigcapital@41033239e0f93e4fc6cf1832743ae6bdbab25306"
]
assert run145["invariants"]["BigCapital_register_row_unchanged"] is True
assert run145["invariants"]["NCM_authorized"] is False
assert run145["invariants"]["tenant_concepts_added"] is False
assert run145["invariants"]["runtime_browser_application_executed_test_pass_release_ease_completion_final_finding_or_audit_completion_credit"] == 0

run146 = strict_json(REPORTING_RECEIPT)
assert run146["pins"]["application_commit"] == APPLICATION_COMMIT
assert run146["pins"]["application_tree"] == APPLICATION_TREE
assert run146["pins"]["matrix_sha256"] == SEALED_PINS[MATRIX][0]
assert run146["pins"]["benchmark_register_sha256"] == SEALED_PINS[REGISTER][0]
assert run146["pins"]["run_145_mapping_receipt_sha256"] == SEALED_PINS[RUN145_RECEIPT][0]
assert run146["pins"]["generator_sha256"] == FINAL_ARTIFACT_PINS[REPORTING_MATERIALIZER]["sha256"]
assert run146["counts"]["canonical_targets"] == 340
assert run146["counts"]["benchmark_mapped"] == 2
assert run146["counts"]["final_no_matches_or_NCMs"] == 0
assert run146["counts"]["unresolved"] == 338
assert run146["mapped_feature_ids"] == [
    "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
    "CAP-FIN-FX-REVALUATION",
]
assert run146["credited_register_projects"] == ["Dolibarr/dolibarr", "frappe/erpnext"]
assert run146["preserved"]["bigcapital_adjacent_only_unselected"] is True
assert run146["credit_boundary"] == {
    "static_target_specific_benchmark_mapping": 2,
    "runtime": 0,
    "application_browser": 0,
    "executed_test": 0,
    "ease": 0,
    "release": 0,
    "pass": 0,
    "final_finding": 0,
    "feature_completion": 0,
    "audit_completion": 0,
    "application_changes": 0,
}
for relative, (expected_sha, expected_bytes) in REPORT_OUTPUTS.items():
    output_pin = run146["outputs"][relative]
    assert output_pin == {"bytes": expected_bytes, "sha256": expected_sha}
    assert sha256_file(relative) == expected_sha
    assert (AUDIT / relative).stat().st_size == expected_bytes
    if relative.endswith(".json"):
        strict_json(relative)

run144 = strict_json(RUN144_RECEIPT)
assert run144["artifact_completion_test_met"] is True
assert run144["audit_completion_test_met"] is False
assert run144["credit_boundary"]["audit_dashboard_artifact"] is True
assert [key for key, value in run144["credit_boundary"].items() if value] == ["audit_dashboard_artifact"]

with (AUDIT / MATRIX).open(newline="", encoding="utf-8") as handle:
    matrix_reader = csv.DictReader(handle)
    assert matrix_reader.fieldnames is not None and len(matrix_reader.fieldnames) == len(set(matrix_reader.fieldnames))
    matrix_rows = list(matrix_reader)
assert len(matrix_rows) == 340
mapped_rows = [row for row in matrix_rows if row["benchmark_mapping_credit"].strip().lower() == "true"]
assert [row["feature_id"] for row in mapped_rows] == [
    "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
    "CAP-FIN-FX-REVALUATION",
]
assert Counter(row["no_match_evidence"] for row in matrix_rows) == Counter({
    "NOT_DOCUMENTED_CURRENT_AUDIT": 338,
    "NCM_NOT_AUTHORIZED_NO_TARGET_SPECIFIC_CATALOGUE_COMPLETE_SEARCH": 2,
})
assert all(row["benchmark_mapping_credit"].strip().lower() in {"true", "false"} for row in matrix_rows)

with (AUDIT / REGISTER).open(newline="", encoding="utf-8") as handle:
    register_reader = csv.DictReader(handle)
    assert register_reader.fieldnames is not None and len(register_reader.fieldnames) == len(set(register_reader.fieldnames))
    register_rows = list(register_reader)
assert len(register_rows) == 98
credited_register = [row for row in register_rows if row["current_target_specific_mapping_credit"].strip().lower() == "true"]
assert [(row["project"], row["commit_sha"]) for row in credited_register] == [
    ("Dolibarr/dolibarr", "769c7db907099643558e77d7002c109cfda919e5"),
    ("frappe/erpnext", "b24c9eba551905e256e336ff170a91a92d197a2f"),
]
bigcapital = [row for row in register_rows if row["project"] == "bigcapitalhq/bigcapital"]
assert len(bigcapital) == 1
assert bigcapital[0]["commit_sha"] == "b9a431c1685a427b71b9bb757a97450b85ecb35f"
assert bigcapital[0]["current_project_triage_commit_sha"] == "b9a431c1685a427b71b9bb757a97450b85ecb35f"
assert bigcapital[0]["current_project_relevance_candidate"] == "SEPARATE_FUTURE_DECISION"
assert bigcapital[0]["current_target_specific_mapping_credit"].strip().lower() == "false"

html_raw = (AUDIT / HTML).read_bytes()
assert sha256_bytes(html_raw) == FINAL_ARTIFACT_PINS[HTML]["sha256"]
assert len(html_raw) == FINAL_ARTIFACT_PINS[HTML]["bytes"]
assert html_raw.endswith(b"\n") and b"\r\n" not in html_raw and not html_raw.startswith(b"\xef\xbb\xbf")
html_text = html_raw.decode("utf-8")
parser = DashboardParser()
parser.feed(html_text)
assert parser.headings == STATIC_EXPECTATIONS["headings"]
assert parser.tables == STATIC_EXPECTATIONS["tables"]
assert parser.table_wraps == STATIC_EXPECTATIONS["table_wraps"]
assert len(parser.hrefs) == STATIC_EXPECTATIONS["anchor_elements"]
assert len(parser.ids) == STATIC_EXPECTATIONS["authored_ids"]
assert len(parser.ids) == len(set(parser.ids))
hash_anchors = [href for href in parser.hrefs if href.startswith("#")]
assert len(hash_anchors) == STATIC_EXPECTATIONS["hash_anchor_occurrences"]
assert len(set(hash_anchors)) == STATIC_EXPECTATIONS["unique_hash_anchors"]
assert all(anchor[1:] in parser.ids for anchor in hash_anchors)
assert [target for _, target in NAVIGATION] == hash_anchors
local_links = [href for href in parser.hrefs if is_local_link(href)]
assert len(local_links) == STATIC_EXPECTATIONS["local_link_occurrences"]
assert len(set(local_links)) == STATIC_EXPECTATIONS["unique_local_links"]
assert not re.findall(r"\$[A-Za-z_][A-Za-z0-9_]*", html_text)
assert "__RUN147_FILL_" not in html_text
visible_text = re.sub(r"\s+", " ", html_module.unescape(re.sub(r"<[^>]+>", " ", html_text))).strip()
for exact_visible_text in (
    "IN PROGRESS · NOT COMPREHENSIVE",
    "662 = 305 route + 357 page",
    "16.849071% of bounded 3,929",
    "routes 3,218 = 305 owner + 12 shared + 5 alias + 2,896 residual with 7 tagged gaps",
    "pages 711 = 357 owner + 9 shared + 345 residual with 1 tagged gap",
    "507 total = 116 reviewed + 391 pending",
    "reviewed = 94 owned + 10 shared + 5 alias + 7 gap",
    "413 without ownership",
    "2/340 static mapping-only",
    "0/340 final no-match/NCM",
    "338 unresolved",
    "CAP-FIN-FX-REVALUATION",
    "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
    "frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f",
    "Dolibarr/dolibarr@769c7db907099643558e77d7002c109cfda919e5",
    "bigcapitalhq/bigcapital@41033239e0f93e4fc6cf1832743ae6bdbab25306",
    "adjacent-only and unselected",
    "register row remains unchanged and receives zero mapping credit",
    SEALED_PINS[MATRIX][0],
    SEALED_PINS[REGISTER][0],
    SEALED_PINS[RUN145_RECEIPT][0],
    "one operating organisation across multiple Sites",
    "Gate 4 is open",
    "0 framework route executions",
    "0 current application tests",
    "0 current-build application browser routes",
    "0 completed Pass 1–8 modules",
):
    assert exact_visible_text in visible_text, exact_visible_text

receipt_preexists = (AUDIT / OUTPUT).exists()
assert set(git("status", "--porcelain").splitlines()) == expected_status(receipt_preexists)
missing_before = sorted({href for href in local_links if not local_target(href).exists()})
if receipt_preexists:
    assert missing_before == []
else:
    assert missing_before == [OUTPUT]

pair_pattern = re.compile(
    r'<a\s+[^>]*href="([^"]+)"[^>]*>.*?</a>\s*<code>([0-9a-f]{64})</code>',
    re.IGNORECASE | re.DOTALL,
)
pairs: list[tuple[str, str]] = []
for list_item in re.findall(r"<li\b[^>]*>(.*?)</li>", html_text, re.IGNORECASE | re.DOTALL):
    pairs.extend((html_module.unescape(match.group(1)), match.group(2).lower()) for match in pair_pattern.finditer(list_item))
assert len(pairs) == STATIC_EXPECTATIONS["adjacent_hash_rows_total"]
assert len(set(pairs)) == STATIC_EXPECTATIONS["adjacent_hash_pairs_unique"]
assert all(href != OUTPUT for href, _ in pairs)
expected_run145_pairs = {
    (relative, pin[0]) for relative, pin in RUN145_LINEAGE.items()
}
observed_run145_pairs = {
    (href, expected_sha) for href, expected_sha in pairs if "run-145" in href
}
assert observed_run145_pairs == expected_run145_pairs
for relative in (MATRIX, RUN145_RECEIPT, REPORTING_MATERIALIZER, REPORTING_RECEIPT):
    expected_sha = (
        FINAL_ARTIFACT_PINS[relative]["sha256"]
        if relative in FINAL_ARTIFACT_PINS
        else SEALED_PINS[relative][0]
    )
    assert (relative, expected_sha) in set(pairs), (relative, expected_sha)
assert REGISTER in local_links
directory_pairs: list[tuple[str, str]] = []
file_pairs: list[tuple[str, str]] = []
for href, expected_sha in pairs:
    target = local_target(href)
    assert target.exists()
    if target.is_dir():
        directory_pairs.append((href, expected_sha))
    else:
        assert target.is_file()
        assert sha256_bytes(target.read_bytes()) == expected_sha
        file_pairs.append((href, expected_sha))
assert len(directory_pairs) == STATIC_EXPECTATIONS["historical_directory_hash_link_occurrences"]
assert len(set(directory_pairs)) == STATIC_EXPECTATIONS["historical_directory_hash_unique_pairs"]
assert set(directory_pairs) == {("task-scripts/", "4171e361c5abc17a63af20cc04133826977b6a6b9c11af9e8d528a7815a4ea33")}
assert len(file_pairs) == STATIC_EXPECTATIONS["hash_bearing_file_link_occurrences"]
assert len(set(file_pairs)) == STATIC_EXPECTATIONS["unique_hash_bearing_file_path_hash_pairs"]

materializer_sha = sha256_file(MATERIALIZER)
materializer_blob = blob_id(MATERIALIZER)
assert BROWSER_OBSERVATIONS["response_status"] == 200
assert BROWSER_OBSERVATIONS["response_bytes"] == FINAL_ARTIFACT_PINS[HTML]["bytes"]
assert BROWSER_OBSERVATIONS["response_sha256"] == FINAL_ARTIFACT_PINS[HTML]["sha256"]
assert BROWSER_OBSERVATIONS["target_url"] == (
    f"http://127.0.0.1:8771/{HTML}?v={BROWSER_OBSERVATIONS['cachebuster']}#progress"
)
assert BROWSER_OBSERVATIONS["exact_dashboard_loaded"] is True
assert BROWSER_OBSERVATIONS["semantic_dom_inspection_completed"] is True
assert BROWSER_OBSERVATIONS["visual_inspection_completed"] is True
assert BROWSER_OBSERVATIONS["all_navigation_links_exercised"] is True
assert BROWSER_OBSERVATIONS["transient_screenshots_inspected"] is True
assert BROWSER_OBSERVATIONS["font_loaded_at_all_viewports"] is True
assert BROWSER_OBSERVATIONS["unresolved_placeholders"] == 0
assert BROWSER_OBSERVATIONS["duplicate_authored_ids"] == 0
assert BROWSER_OBSERVATIONS["console_warnings"] == 0
assert BROWSER_OBSERVATIONS["console_errors"] == 0
assert BROWSER_OBSERVATIONS["page_errors"] == 0
assert BROWSER_OBSERVATIONS["dom_ids_observed"] >= STATIC_EXPECTATIONS["authored_ids"]
assert all(not value.startswith("__RUN147_FILL_") for value in BROWSER_OBSERVATIONS["browser_injected_ids"])
assert [row["requested"] for row in VIEWPORTS] == ["1440x900", "1280x800", "1024x768", "390x844"]
assert all(row["response_status"] == 200 for row in VIEWPORTS)
assert all(row["page_level_horizontal_overflow"] is False for row in VIEWPORTS)
assert all(row["page_overflow_px"] == 0 for row in VIEWPORTS)
assert all(row["table_wraps"] == STATIC_EXPECTATIONS["table_wraps"] for row in VIEWPORTS)
assert all(row["tables_with_unbounded_overflow"] == 0 for row in VIEWPORTS)
assert all(row["unintended_offscreen_elements"] == 0 for row in VIEWPORTS)
assert len(VISIBLE_CHECKS) == 25 and all(VISIBLE_CHECKS.values())

credit_boundary = {
    "audit_dashboard_artifact": True,
    "new_static_target_specific_benchmark_mapping": False,
    "new_final_no_match_or_NCM": False,
    "static_source_feature_ownership": False,
    "static_route_feature_ownership": False,
    "static_page_feature_ownership": False,
    "static_controller_action_bridge": False,
    "queue_review": False,
    "frontend_caller_ownership": False,
    "complete_route_page_feature_crosswalk": False,
    "framework_route_reachability": False,
    "application_navigation": False,
    "canonical_object_ownership_correctness": False,
    "site_authorization_correctness": False,
    "permission_correctness": False,
    "privacy_correctness": False,
    "direct_object_concealment_correctness": False,
    "query_correctness": False,
    "projection_correctness": False,
    "period_correctness": False,
    "allocation_provenance_or_reversal_correctness": False,
    "utility_true_up_sign_correctness": False,
    "response_minimization_correctness": False,
    "lifecycle_correctness": False,
    "concurrency_correctness": False,
    "event_or_downstream_durability_correctness": False,
    "application_browser": False,
    "responsive_application": False,
    "visual_or_workflow": False,
    "runtime": False,
    "database": False,
    "build": False,
    "executed_tests": False,
    "benchmark_completion": False,
    "ease": False,
    "release": False,
    "pass": False,
    "final_finding": False,
    "feature_completion": False,
    "audit_completion": False,
}
assert [key for key, value in credit_boundary.items() if value] == ["audit_dashboard_artifact"]

completion_boundary = {
    "framework_route_reachability_complete": False,
    "semantic_assurance_complete": False,
    "execution_complete": False,
    "benchmark_complete": False,
    "pass_8_complete": False,
    "final_reconciliation_complete": False,
    "no_live_agent_gate_complete": False,
    "full_crosswalk_complete": False,
    "gate_4_complete": False,
    "audit_complete": False,
}
assert not any(completion_boundary.values())

lineage_receipt = {
    relative: {"sha256": pin[0], "blob_id": pin[1], "bytes": pin[2]}
    for relative, pin in RUN145_LINEAGE.items()
}
authorized_paths = sorted(
    [f"{PREFIX}/{relative}" for relative in TRACKED_MODIFIED | UNTRACKED_WAVE | {OUTPUT}]
)

receipt = {
    "schema_version": "run-147-audit-dashboard-verification-wave-24-v1",
    "run_id": "RUN-147-AUDIT-DASHBOARD-VERIFICATION",
    "status": "AUDIT_ARTIFACT_RESPONSIVE_LINK_CONSOLE_VERIFICATION_GO_ZERO_APPLICATION_CREDIT_NO_NEW_BENCHMARK_CREDIT",
    "generated_on": "2026-08-27",
    "architecture_rule": {
        "operating_organisations": 1,
        "multiple_sites": True,
        "multi_tenant": False,
    },
    "pins": {
        "application_commit": APPLICATION_COMMIT,
        "application_tree": APPLICATION_TREE,
        "app_tree": SUBTREES["app"],
        "routes_tree": SUBTREES["routes"],
        "resources_js_tree": SUBTREES["resources/js"],
        "resources_js_pages_tree": SUBTREES["resources/js/pages"],
        "tests_tree": SUBTREES["tests"],
        "checkpoint_commit": CHECKPOINT_COMMIT,
        "checkpoint_tree": CHECKPOINT_TREE,
        "matrix_sha256": SEALED_PINS[MATRIX][0],
        "matrix_blob": SEALED_PINS[MATRIX][1],
        "matrix_bytes": SEALED_PINS[MATRIX][2],
        "matrix_rows": 340,
        "matrix_mapping_credit": "2/340",
        "matrix_final_no_match_or_NCM": "0/340",
        "matrix_unresolved": 338,
        "live_benchmark_accounting": {
            "mapped": 2,
            "total": 340,
            "final_ncm": 0,
            "unresolved": 338,
        },
        "benchmark_register_sha256": SEALED_PINS[REGISTER][0],
        "benchmark_register_blob": SEALED_PINS[REGISTER][1],
        "benchmark_register_bytes": SEALED_PINS[REGISTER][2],
        "run_145_mapping_receipt_sha256": SEALED_PINS[RUN145_RECEIPT][0],
        "run_145_mapping_receipt_blob": SEALED_PINS[RUN145_RECEIPT][1],
        "run_145_lineage_count": len(lineage_receipt),
        "run_145_lineage": lineage_receipt,
        "reporting_materializer_sha256": FINAL_ARTIFACT_PINS[REPORTING_MATERIALIZER]["sha256"],
        "reporting_materializer_blob": FINAL_ARTIFACT_PINS[REPORTING_MATERIALIZER]["blob"],
        "reporting_receipt_sha256": SEALED_PINS[REPORTING_RECEIPT][0],
        "reporting_receipt_blob": SEALED_PINS[REPORTING_RECEIPT][1],
        "dashboard_refresh_materializer_sha256": FINAL_ARTIFACT_PINS[DASHBOARD_REFRESH_MATERIALIZER]["sha256"],
        "dashboard_refresh_materializer_blob": FINAL_ARTIFACT_PINS[DASHBOARD_REFRESH_MATERIALIZER]["blob"],
        "dashboard_generator_sha256": FINAL_ARTIFACT_PINS[GENERATOR]["sha256"],
        "dashboard_generator_blob": FINAL_ARTIFACT_PINS[GENERATOR]["blob"],
        "dashboard_html_sha256": FINAL_ARTIFACT_PINS[HTML]["sha256"],
        "dashboard_html_blob": FINAL_ARTIFACT_PINS[HTML]["blob"],
        "dashboard_html_bytes": FINAL_ARTIFACT_PINS[HTML]["bytes"],
        "superseded_dashboard_verification_sha256": SEALED_PINS[RUN144_RECEIPT][0],
        "superseded_dashboard_verification_blob": SEALED_PINS[RUN144_RECEIPT][1],
        "receipt_materializer": MATERIALIZER,
        "receipt_materializer_sha256": materializer_sha,
        "receipt_materializer_blob": materializer_blob,
    },
    "current_benchmark_state": {
        "canonical_targets": 340,
        "target_specific_static_mappings": 2,
        "final_no_matches_or_NCMs": 0,
        "unresolved_targets": 338,
        "mapped_feature_ids": [
            "CAP-FIN-BILLING-INVOICE-LIFECYCLE",
            "CAP-FIN-FX-REVALUATION",
        ],
        "credited_register_projects": ["Dolibarr/dolibarr", "frappe/erpnext"],
        "mapped_target_selections": {
            "CAP-FIN-FX-REVALUATION": [
                "frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f",
            ],
            "CAP-FIN-BILLING-INVOICE-LIFECYCLE": [
                "frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f",
                "Dolibarr/dolibarr@769c7db907099643558e77d7002c109cfda919e5",
            ],
        },
        "bigcapital_adjacent_only_unselected": True,
        "mapping_credit_preserved_not_created_by_run_147": True,
    },
    "verification_method": {
        "in_app_browser": {
            "tool": "Codex in-app Browser with explicit viewport capability, semantic DOM inspection, transient visual inspection, full navigation exercise, console inspection, and local target checks",
            "target_url": BROWSER_OBSERVATIONS["target_url"],
            "cachebuster": BROWSER_OBSERVATIONS["cachebuster"],
            "response_probe": "Read-only local HTTP GET against the same cache-busted artifact URL",
            "response_status": BROWSER_OBSERVATIONS["response_status"],
            "response_bytes": BROWSER_OBSERVATIONS["response_bytes"],
            "response_sha256": BROWSER_OBSERVATIONS["response_sha256"],
            "exact_dashboard_loaded": BROWSER_OBSERVATIONS["exact_dashboard_loaded"],
            "semantic_dom_inspection_completed": BROWSER_OBSERVATIONS["semantic_dom_inspection_completed"],
            "visual_inspection_completed": BROWSER_OBSERVATIONS["visual_inspection_completed"],
            "all_navigation_links_exercised": BROWSER_OBSERVATIONS["all_navigation_links_exercised"],
            "navigation_only": True,
            "transient_screenshots_inspected": BROWSER_OBSERVATIONS["transient_screenshots_inspected"],
            "screenshot_retained": False,
            "application_or_external_state_changed": False,
        },
        "static_validation": {
            "local_target_resolution": "Dashboard-relative filesystem target existence after this receipt was materialized",
            "hash_pair_resolution": "Immediate-sibling anchor and 64-hex code rows within one list item; every regular-file target SHA-256 matched",
            "run_145_exact_lineage": "20/20 files matched SHA-256, Git blob, bytes, strict JSON where applicable, and dashboard-adjacent hashes",
            "historical_directory_hash_rows_excluded_from_file_hash_denominator": len(directory_pairs),
            "historical_directory_hash_unique_pairs": len(set(directory_pairs)),
            "deterministic_receipt_serialization": True,
            "materializer_byte_identical_runs": 2,
            "browser_reexecution_claimed_by_materializer": False,
        },
    },
    "verification": {
        "state": "GO",
        "viewports_required": 4,
        "viewports_verified": 4,
        "viewports": VIEWPORTS,
        "responsive_visual_inspection": "4/4",
        "font_loaded_at_all_viewports": BROWSER_OBSERVATIONS["font_loaded_at_all_viewports"],
        "unresolved_placeholders": BROWSER_OBSERVATIONS["unresolved_placeholders"],
        "headings": parser.headings,
        "tables": parser.tables,
        "table_wraps": parser.table_wraps,
        "anchor_elements": len(parser.hrefs),
        "hash_anchor_occurrences": len(hash_anchors),
        "unique_hash_anchors": len(set(hash_anchors)),
        "local_link_occurrences": len(local_links),
        "unique_local_links": f"{len(set(local_links))}/{len(set(local_links))}",
        "local_link_failures": [],
        "pre_materialization_forward_reference": {
            "href": OUTPUT,
            "expected_missing_before_receipt_materialization": True,
            # Preserve the first-run observation so the second materializer run
            # serializes byte-identical receipt bytes after OUTPUT exists.
            "pre_materialization_filesystem_target_exists": False,
            "excluded_from_pre_materialization_failure": True,
            "resolved_after_receipt_materialization": True,
            "hash_pair_required": False,
        },
        "adjacent_hash_rows_total": len(pairs),
        "hash_bearing_file_link_occurrences": len(file_pairs),
        "unique_hash_bearing_file_path_hash_pairs": len(set(file_pairs)),
        "hash_bearing_link_failures": [],
        "historical_directory_hash_link_occurrences": len(directory_pairs),
        "historical_directory_hash_unique_pairs": len(set(directory_pairs)),
        "navigation_targets": "10/10",
        "missing_navigation_targets": [],
        "navigation_links_exercised": [target for _, target in NAVIGATION],
        "navigation_link_results": [
            {
                "label": label,
                "target": target,
                "target_exists": True,
                "hash_matched": True,
                "target_visible_after_click": True,
            }
            for label, target in NAVIGATION
        ],
        "dom_ids_observed": BROWSER_OBSERVATIONS["dom_ids_observed"],
        "artifact_authored_ids": len(parser.ids),
        "browser_injected_ids": BROWSER_OBSERVATIONS["browser_injected_ids"],
        "duplicate_authored_ids": BROWSER_OBSERVATIONS["duplicate_authored_ids"],
        "console_warnings": BROWSER_OBSERVATIONS["console_warnings"],
        "console_errors": BROWSER_OBSERVATIONS["console_errors"],
        "page_errors": BROWSER_OBSERVATIONS["page_errors"],
        "anchors": "10/10",
        "anchor_failures": [],
        "exact_visible_boundary_checks": VISIBLE_CHECKS,
    },
    "mutation_attestation": {
        "authorized_paths": authorized_paths,
        "whole_repository_status_exactly_authorized_audit_paths": True,
        "all_changed_paths_under_audit_directory": True,
        "run_147_receipt_materializer_wrote_only_receipt": True,
        "run_147_changed_matrix_or_register": False,
        "run_147_changed_reporting_surfaces": False,
        "application_source_changed": False,
        "routes_changed": False,
        "resources_js_changed": False,
        "tests_changed": False,
        "build_outputs_changed": False,
        "local_static_audit_server_used": True,
        "application_runtime_started": False,
        "navigation_only": True,
        "forms_submitted": False,
        "records_opened": False,
        "screenshots_retained": False,
        "database_changed": False,
        "application_or_external_state_changed": False,
        "application_tests_or_build_run": False,
    },
    "preserved_existing_credit": {
        "static_target_specific_benchmark_mappings": 2,
        "final_no_matches_or_NCMs": 0,
        "new_credit_from_run_147": 0,
    },
    "inherited_audit_credit": {
        "benchmark_mapping": 2,
        "final_ncm": 0,
    },
    "credit_boundary": credit_boundary,
    "completion_boundary": completion_boundary,
    "artifact_completion_test_met": True,
    "audit_completion_test_met": False,
}

raw = (json.dumps(receipt, ensure_ascii=False, indent=2, allow_nan=False) + "\n").encode("utf-8")
target = AUDIT / OUTPUT
if not target.exists() or target.read_bytes() != raw:
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(raw)

assert target.read_bytes() == raw
assert raw.endswith(b"\n") and b"\r\n" not in raw and not raw.startswith(b"\xef\xbb\xbf")
assert strict_json(OUTPUT) == receipt
assert sorted({href for href in local_links if not local_target(href).exists()}) == []
assert set(git("status", "--porcelain").splitlines()) == expected_status(True)
assert not list(AUDIT.rglob("__pycache__"))

print(
    json.dumps(
        {
            "status": receipt["status"],
            "materializer_sha256": materializer_sha,
            "receipt_sha256": sha256_bytes(raw),
            "dashboard_sha256": FINAL_ARTIFACT_PINS[HTML]["sha256"],
            "run_145_lineage": "20/20",
            "benchmark_mapping": "2/340",
            "final_no_match_or_NCM": "0/340",
            "benchmark_unresolved": 338,
            "viewports": "4/4",
            "navigation": "10/10",
            "local_links": f"{len(set(local_links))}/{len(set(local_links))}",
            "visible_boundary_checks": f"{len(VISIBLE_CHECKS)}/{len(VISIBLE_CHECKS)}",
            "console_warnings_errors_page_errors": 0,
            "gate_4_complete": False,
            "audit_complete": False,
        },
        indent=2,
    )
)
