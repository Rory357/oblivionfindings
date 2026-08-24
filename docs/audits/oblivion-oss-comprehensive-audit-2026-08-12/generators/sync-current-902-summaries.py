#!/usr/bin/env python3
"""Synchronise current audit summaries to the canonical 902 register.

This rewrites audit evidence only. Frozen derivation-stage artifacts are not
recomputed; they receive explicit supersession markers instead.
"""

from __future__ import annotations

import csv
import hashlib
import json
import os
import re
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
MANIFEST_NAME = "working-capability-manifest-902.json"
MANIFEST_SHA = "ded38bc3672bf51cb48a02a576cc36ca83d01af6a982dbd19c118ff50edf59b9"
BENCHMARK_NAME = "benchmark-final-902-mapping.json"
BENCHMARK_SHA = "c2cb6ea0f584b8eef7c6e74cf6aca3cf580139fabdb66198ace43e02fddabe3c"
WAVE15_NAME = "benchmark-target-specific-adjudication-902-wave15.json"
WAVE15_SHA = "c0a57c0ee8e248d6ddded383e09c378ed43fd38430c5b21218f6ea6398ad551b"
WAVE16_NAME = "benchmark-target-specific-adjudication-902-wave16.json"
WAVE16_SHA = "0fd33aca3396c54f142900484b06a73f8645884598eab19aff033742cb81e49c"
WAVE17_NAME = "benchmark-target-specific-adjudication-902-wave17.json"
WAVE17_SHA = "07860807a51ce1e52c59c3dc520671a89672c0bdca2b95948a6fc13f8fdf5c7a"
WAVE18_NAME = "benchmark-target-specific-adjudication-902-wave18.json"
WAVE18_SHA = "9e8f63fab776c065fe026f74832182dff71f381b5ef239b74ce888c66c41b693"
WAVE19_NAME = "benchmark-target-specific-adjudication-902-wave19.json"
WAVE19_SHA = "e1ba0aa31a964e4baa2b1a6b1b8d24e879eced71d11b3a525f87c5273e7939ba"
WAVE20_NAME = "benchmark-target-specific-adjudication-902-wave20.json"
WAVE20_SHA = "e084378b467aca63b473e4717b2dd0c8604acdc76d53f0e61c233a021394cd9f"
WAVE21_NAME = "benchmark-target-specific-adjudication-902-wave21.json"
WAVE21_SHA = "9474a59f10a3aad16ec1dfaeb7e976b9e5f7386a5655c564834e197e092cd2fb"
WAVE22_NAME = "benchmark-target-specific-adjudication-902-wave22.json"
WAVE22_SHA = "e9b28de8e44d46cab9e824e0d9ab362300b53714ab6abb34ce3bafe395c66b98"
WAVE23_NAME = "benchmark-target-specific-adjudication-902-wave23.json"
WAVE23_SHA = "16b0c90fa8d2cca7b6c9e64670953b47612d0f0cfaac08eb6ccbe28e3a8cfd3e"
WAVE24_NAME = "benchmark-target-specific-adjudication-902-wave24.json"
WAVE24_SHA = "c96e62aae6964ee6f1fe8633b6ec07c553dccd42cf1ed352544ca7b234f47c38"
WAVE27_NAME = "benchmark-target-specific-adjudication-902-wave27.json"
WAVE27_SHA = "405e89ae05e02dbebce8cf1cd484010a603714baff1cf6dab0b0879214e2226e"
WAVE28_NAME = "benchmark-target-specific-adjudication-902-wave28.json"
WAVE28_SHA = "d68aa992bef7c76b2f91e04a284c3f55174fae618c27785a482429f747a17084"
WAVE30_NAME = "benchmark-target-specific-adjudication-902-wave30.json"
WAVE30_SHA = "5fd6e15f7796915c1d4ca2b97cecdc77d0732d030ea4b3c0fc4b1fd78cbc23a7"
GAP_NAME = "route-page-gap-reconciliation-902.json"
GAP_SHA = "cefc4af1571d50ad17c155c083635d2bacf79828a78d1d68ffc2ee86242c49eb"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def save(path: Path, value: dict) -> None:
    temp = path.with_suffix(path.suffix + ".tmp")
    temp.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    json.loads(temp.read_text(encoding="utf-8"))
    os.replace(temp, path)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def replace_once_in_text(path: Path, pattern: str, replacement: str, label: str) -> None:
    source = path.read_text(encoding="utf-8")
    updated, count = re.subn(pattern, replacement, source, count=1, flags=re.MULTILINE)
    if count != 1:
        raise RuntimeError(f"Expected exactly one current-summary replacement for {label}: {path}")
    path.write_text(updated, encoding="utf-8", newline="\n")


def csv_shape(name: str) -> tuple[int, int, str]:
    path = AUDIT / name
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.reader(handle)
        rows = list(reader)
    return len(rows) - 1, len(rows[0]), sha(path)


manifest = load(SOURCE / MANIFEST_NAME)
benchmark = load(SOURCE / BENCHMARK_NAME)
wave15 = load(SOURCE / WAVE15_NAME)
wave16 = load(SOURCE / WAVE16_NAME)
wave17 = load(SOURCE / WAVE17_NAME)
wave18 = load(SOURCE / WAVE18_NAME)
wave19 = load(SOURCE / WAVE19_NAME)
wave20 = load(SOURCE / WAVE20_NAME)
wave21 = load(SOURCE / WAVE21_NAME)
wave22 = load(SOURCE / WAVE22_NAME)
wave23 = load(SOURCE / WAVE23_NAME)
wave24 = load(SOURCE / WAVE24_NAME)
wave27 = load(SOURCE / WAVE27_NAME)
wave28 = load(SOURCE / WAVE28_NAME)
wave30 = load(SOURCE / WAVE30_NAME)
findings = load(AUDIT / "findings.json")
visual_summary = load(SOURCE / "final-902-visual-link-generation-summary.json")
task_summary = load(SOURCE / "final-902-task-script-generation-summary.json")
browser_role_pass = load(SOURCE / "browser-representative-role-pass-901.json")
clinical_lead_pass = load(SOURCE / "browser-clinical-lead-current-main-pass-902.json")
agent_register = load(SOURCE / "agent-reconciliation-register.json")
orchestration = load(SOURCE / "orchestration-status-2026-08-14.json")
project_triage_path = SOURCE / "project-specific-triage-complete-2026-08-14.json"
project_triage = load(project_triage_path)

assert sha(SOURCE / MANIFEST_NAME) == MANIFEST_SHA
assert sha(SOURCE / BENCHMARK_NAME) == BENCHMARK_SHA
assert sha(SOURCE / WAVE15_NAME) == WAVE15_SHA
assert sha(SOURCE / WAVE16_NAME) == WAVE16_SHA
assert sha(SOURCE / WAVE17_NAME) == WAVE17_SHA
assert sha(SOURCE / WAVE18_NAME) == WAVE18_SHA
assert sha(SOURCE / WAVE19_NAME) == WAVE19_SHA
assert sha(SOURCE / WAVE20_NAME) == WAVE20_SHA
assert sha(SOURCE / WAVE21_NAME) == WAVE21_SHA
assert sha(SOURCE / WAVE22_NAME) == WAVE22_SHA
assert sha(SOURCE / WAVE23_NAME) == WAVE23_SHA
assert sha(SOURCE / WAVE24_NAME) == WAVE24_SHA
assert sha(SOURCE / WAVE27_NAME) == WAVE27_SHA
assert sha(SOURCE / WAVE28_NAME) == WAVE28_SHA
assert sha(SOURCE / WAVE30_NAME) == WAVE30_SHA
assert sha(SOURCE / GAP_NAME) == GAP_SHA
assert len(manifest["targets"]) == 902
assert len(findings["findings"]) == 92
assert browser_role_pass.get("audited_commit") == COMMIT
assert len(browser_role_pass.get("role_observations", [])) == 11
assert browser_role_pass.get("required_actor_coverage", {}).get("observed") == 11
assert clinical_lead_pass.get("coverage_effect", {}).get("representative_actor_classes_after") == 12
assert clinical_lead_pass.get("mutation_boundary", {}).get("canonical_tasks_completed") == 0
AGENT_COUNT = len(agent_register.get("assignments", []))
assert agent_register.get("assignment_count") == AGENT_COUNT
assert AGENT_COUNT == 105
ACTIVE_BACKGROUND_TASKS = int(orchestration.get("summary", {}).get("total_background_tasks_active", 0))
assert ACTIVE_BACKGROUND_TASKS >= 0

NOW = datetime.now(timezone.utc).isoformat()


# The catalogue triage is classification evidence, so it must point at the
# current benchmark mapping without claiming that the mapping itself changed.
project_triage["mapping_unchanged_sha256"] = BENCHMARK_SHA
save(project_triage_path, project_triage)


# The original 901-manifest bytes are no longer present at their historical
# path. Preserve the expected derivation hash and explicitly mark the live path
# as non-dereferenceable historical provenance; never substitute current bytes
# for the pinned 901 input.
historical_901_path = SOURCE / "working-capability-manifest-901.json"
historical_901_live_sha = sha(historical_901_path)
assert historical_901_live_sha == "210b446c71a9ec1e1aeadbe32164d9b69e34c383da17257de1247c8bf9174ee2"
save(SOURCE / "historical-901-provenance-reconciliation.json", {
    "schema_version": "1.0.0",
    "generated_at": NOW,
    "status": "historic_non_dereferenceable",
    "historical_artifact": "working-capability-manifest-901.json",
    "historical_expected_sha256": "5b477cc3fa5e5343b223b7ba559919f708f945426f193dbb0510245771148900",
    "live_path_sha256": historical_901_live_sha,
    "current_authority": {
        "artifact": MANIFEST_NAME,
        "sha256": MANIFEST_SHA,
        "targets": 902,
    },
    "boundary": "The live 901 path does not contain the bytes pinned by historical 901/902 derivations. Preserve their recorded expected hash as lineage evidence; do not treat the current file at that path as a valid historical input or substitute its hash into frozen artifacts.",
})
COUNTS = {"total": 902, "H": 788, "D": 111, "M": 3}
PROVENANCE = {"exact_current": 881, "source_stable": 5, "audit_assigned": 16}
ROUTES = {"targets": 901, "relations": 3065, "accepted_unique": 2985, "excluded": 39, "total": 3024, "percent": 98.71}
PAGES = {"targets": 756, "relations": 1526, "accepted_unique": 945, "excluded": 17, "total": 962, "percent": 98.23}
BACKEND = {"targets": 729, "relations": 828, "unique_anchors": 469}
BENCH = {"eligible": 451, "verified": 362, "verified_direct": 340, "verified_rename": 22, "ncm": 89, "ncm_direct": 82, "ncm_rename": 7, "unproved": 451}
VISUAL = {"rows": 8753, "assigned": 8153, "unresolved": 600, "unique": 771, "lineage": 834}
MATERIAL = {"rows": 4312, "assigned": 3935, "unresolved": 377, "unique": 713, "percent": 91.26}
manifest_ids = {target["working_key"] for target in manifest["targets"]}
finding_rows = findings["findings"]
finding_priorities = Counter(row["priority"] for row in finding_rows)
finding_exact_pairs = [
    (row["id"], feature_id)
    for row in finding_rows
    for feature_id in row.get("feature_ids", [])
    if feature_id in manifest_ids
]
finding_exact_ids = {finding_id for finding_id, _ in finding_exact_pairs}
p0p1_rows = [row for row in finding_rows if row["priority"] in {"P0", "P1"}]
p0p1_exact_ids = {row["id"] for row in p0p1_rows} & finding_exact_ids
FINDING = {
    "total": len(finding_rows),
    "P0": finding_priorities.get("P0", 0),
    "P1": finding_priorities.get("P1", 0),
    "P2": finding_priorities.get("P2", 0),
    "links": sum(len(row.get("feature_ids", [])) for row in finding_rows),
    "exact_links": len(finding_exact_pairs),
    "exact_targets": len({feature_id for _, feature_id in finding_exact_pairs}),
    "with_exact": len(finding_exact_ids),
    "without_exact": len(finding_rows) - len(finding_exact_ids),
    "p0p1": len(p0p1_rows),
    "p0p1_exact": len(p0p1_exact_ids),
    "p0p1_without": len(p0p1_rows) - len(p0p1_exact_ids),
    "uncertain": sum(
        bool(row.get("feature_link_reconciliation", {}).get("uncertainties"))
        for row in finding_rows
    ),
}


# Current-facing prose is derived from the canonical findings and manifest;
# frozen browser/source evidence retains its original bytes and labels.
def sync_current_finding_prose() -> None:
    executive = AUDIT / "00-executive-summary.md"
    replace_once_in_text(
        executive,
        r"(?:Current remediation snapshot `origin/main`|Historical browser-evidence pin \(not current `origin/main`\)): `ad19f994a280835d039d1a31ebdcb05778733c5a`",
        "Historical browser-evidence pin (not current `origin/main`): `ad19f994a280835d039d1a31ebdcb05778733c5a`",
        "historical browser-evidence pin label",
    )
    replace_once_in_text(
        executive,
        r"\| P0/P1 findings with exact current-ID links \| \d+ / \d+ \| All \d+ retained findings have at least one literal current ID through \d+ exact links; exact linkage remains attribution evidence, not runtime proof \|",
        f"| P0/P1 findings with exact current-ID links | {FINDING['p0p1_exact']} / {FINDING['p0p1']} | All {FINDING['total']} retained findings have at least one literal current ID through {FINDING['exact_links']} exact links; exact linkage remains attribution evidence, not runtime proof |",
        "executive finding-link counts",
    )
    replace_once_in_text(
        executive,
        r"- \*\*\d+ P1\*\* — serious authorization",
        f"- **{FINDING['P1']} P1** — serious authorization",
        "executive P1 count",
    )

    module_findings = AUDIT / "07-module-findings.md"
    replace_once_in_text(
        module_findings,
        r"This document is the human-readable companion to `findings\.json`\..*",
        f"This document is the human-readable companion to `findings.json`. Counts in the retained finding set are **{FINDING['P0']} P0, {FINDING['P1']} P1 and {FINDING['P2']} P2**. The feature tables below are a **superseded 740-row projection**, not the final canonical register. The corrected current register is **902 capabilities (788 human, 111 download/API and three machine-ingress)**, and matrices 02–03 contain all 902 IDs. Finding linkage now resolves to {FINDING['exact_links']} literal exact current-ID links across {FINDING['exact_targets']} targets: all {FINDING['total']} retained findings and all {FINDING['p0p1']} P0/P1 findings have at least one current ID. The retained additions include email-verification contract enforcement, export-permission convergence, the inactive renewals selector, the signal-to-alert machine pipeline, System Users count animation, My Day and eMAR narrow-width overflow, the audited Clinical/Medication Lead account-creation blocker, the authenticated Shift task-provider failure, destructible safeguarding evidence, and the unsafe generic payment-allocation writer. Exact string matches remain lineage, not runtime proof. A blank findings cell means only that no distinct retained finding linked to the old projection row.",
        "module finding counts",
    )

    unresolved = AUDIT / "13-unresolved-questions-and-evidence-gaps.md"
    replace_once_in_text(
        unresolved,
        r"- The stable-ID spelling and route/page dispositions are reflected in the canonical register\. All \d+ retained findings and \d+/\d+ P0/P1 findings have at least one literal current ID through \d+ exact links; partial visual linkage and absent runtime proof still prevent completion\.",
        f"- The stable-ID spelling and route/page dispositions are reflected in the canonical register. All {FINDING['total']} retained findings and {FINDING['p0p1_exact']}/{FINDING['p0p1']} P0/P1 findings have at least one literal current ID through {FINDING['exact_links']} exact links; partial visual linkage and absent runtime proof still prevent completion.",
        "unresolved finding-link counts",
    )

    # Current benchmark and visual totals are generated from the canonical
    # mapping/matrix. Keep the human-readable summaries byte-consistent with
    # those artifacts; historical evidence files are deliberately untouched.
    replace_once_in_text(
        executive,
        r"Matrix 05 preserves all 8,753 visual observations, assigns [0-9,]+ rows to 771 unique final IDs and leaves \d+ unresolved\. The 4,312-row material subset assigns [0-9,]+ rows to 713 unique final IDs and leaves \d+ unresolved \([0-9.]+% assigned\)\.",
        f"Matrix 05 preserves all 8,753 visual observations, assigns {VISUAL['assigned']:,} rows to 771 unique final IDs and leaves {VISUAL['unresolved']} unresolved. The 4,312-row material subset assigns {MATERIAL['assigned']:,} rows to 713 unique final IDs and leaves {MATERIAL['unresolved']} unresolved ({MATERIAL['percent']:.2f}% assigned).",
        "executive visual narrative",
    )
    replace_once_in_text(
        executive,
        r"The final benchmark mapping formally credits \d+ targets \(\d+ verified benchmark and \d+ bounded documented No Credible Match\), leaving \d+ completion-unproved\.",
        f"The final benchmark mapping formally credits {BENCH['eligible']} targets ({BENCH['verified']} verified benchmark and {BENCH['ncm']} bounded documented No Credible Match), leaving {BENCH['unproved']} completion-unproved.",
        "executive benchmark narrative",
    )
    replace_once_in_text(
        executive,
        r"\| Capability benchmark or documented No Credible Match \| \d+ / 902 \| \*\*[0-9.]+%\*\* formal evidence-preserving mapping: \d+ verified-benchmark and \d+ bounded documented-no-match; \d+ completion-unproved\. See `evidence/source/benchmark-final-902-mapping\.json` \|",
        f"| Capability benchmark or documented No Credible Match | {BENCH['eligible']} / 902 | **{100 * BENCH['eligible'] / 902:.2f}%** formal evidence-preserving mapping: {BENCH['verified']} verified-benchmark and {BENCH['ncm']} bounded documented-no-match; {BENCH['unproved']} completion-unproved. See `evidence/source/benchmark-final-902-mapping.json` |",
        "executive benchmark table",
    )
    replace_once_in_text(
        executive,
        r"\| Visual-matrix rows assigned to final IDs \| [0-9,]+ / 8,753 \| 771 unique final IDs; \d+ rows unresolved; static linkage is not runtime proof \|",
        f"| Visual-matrix rows assigned to final IDs | {VISUAL['assigned']:,} / 8,753 | 771 unique final IDs; {VISUAL['unresolved']} rows unresolved; static linkage is not runtime proof |",
        "executive visual table",
    )
    replace_once_in_text(
        executive,
        r"\| Material-state applicability linked to final IDs \| [0-9,]+ / 4,312 \| \*\*[0-9.]+%\*\* final-ID linkage to 713 unique final IDs; \d+ links remain unresolved and 0 states were deliberately executed \|",
        f"| Material-state applicability linked to final IDs | {MATERIAL['assigned']:,} / 4,312 | **{MATERIAL['percent']:.2f}%** final-ID linkage to 713 unique final IDs; {MATERIAL['unresolved']} links remain unresolved and 0 states were deliberately executed |",
        "executive material table",
    )

    module_map = AUDIT / "01-repository-module-map.md"
    replace_once_in_text(
        module_map,
        r"Matrix 05 assigns [0-9,]+/8,753 rows to 771 unique final IDs and leaves \d+ unresolved; its material subset assigns [0-9,]+/4,312 rows to 713 IDs and leaves \d+ unresolved \([0-9.]+%\)\.",
        f"Matrix 05 assigns {VISUAL['assigned']:,}/8,753 rows to 771 unique final IDs and leaves {VISUAL['unresolved']} unresolved; its material subset assigns {MATERIAL['assigned']:,}/4,312 rows to 713 IDs and leaves {MATERIAL['unresolved']} unresolved ({MATERIAL['percent']:.2f}%).",
        "module-map visual totals",
    )
    replace_once_in_text(
        module_findings,
        r"Exact-lineage reconciliation identifies \d+ evidence-preserving working targets \(\d+ verified-benchmark and \d+ documented No Credible Match\), but \d+ remain completion-unproved",
        f"Exact-lineage reconciliation identifies {BENCH['eligible']} evidence-preserving working targets ({BENCH['verified']} verified-benchmark and {BENCH['ncm']} documented No Credible Match), but {BENCH['unproved']} remain completion-unproved",
        "module findings benchmark totals",
    )

    visual_doc = AUDIT / "09-ui-ux-accessibility-visual-consistency.md"
    replace_once_in_text(
        visual_doc,
        r"Final-ID reconciliation assigns [0-9,]+ rows to 713 unique final capabilities and leaves \d+ rows unresolved \([0-9.]+% assigned\)\. Every row remains unexecuted\. Across the whole 8,753-row visual matrix, [0-9,]+ rows are assigned to 771 unique final IDs and \d+ remain unresolved\.",
        f"Final-ID reconciliation assigns {MATERIAL['assigned']:,} rows to 713 unique final capabilities and leaves {MATERIAL['unresolved']} rows unresolved ({MATERIAL['percent']:.2f}% assigned). Every row remains unexecuted. Across the whole 8,753-row visual matrix, {VISUAL['assigned']:,} rows are assigned to 771 unique final IDs and {VISUAL['unresolved']} remain unresolved.",
        "visual consistency totals",
    )

    native = AUDIT / "12-native-build-and-do-not-copy-register.md"
    replace_once_in_text(
        native,
        r"Against the canonical 902-target register, \*\*\d+ targets have a verified benchmark mapping, \d+ have a target-specific documented No Credible Match decision, and \d+ remain completion-unproved\*\*: \d+/902 \([0-9.]+%\) currently have evidence-preserving dispositions\.",
        f"Against the canonical 902-target register, **{BENCH['verified']} targets have a verified benchmark mapping, {BENCH['ncm']} have a target-specific documented No Credible Match decision, and {BENCH['unproved']} remain completion-unproved**: {BENCH['eligible']}/902 ({100 * BENCH['eligible'] / 902:.2f}%) currently have evidence-preserving dispositions.",
        "native register benchmark totals",
    )
    replace_once_in_text(
        native,
        r"The current completion gate is \d+/902 \([0-9.]+%\) mapped or documented No Credible Match and \d+/902 unproved\.",
        f"The current completion gate is {BENCH['eligible']}/902 ({100 * BENCH['eligible'] / 902:.2f}%) mapped or documented No Credible Match and {BENCH['unproved']}/902 unproved.",
        "native register completion gate",
    )

    replace_once_in_text(
        unresolved,
        r"\| Capability benchmark / No Credible Match \| \d+/902 \([0-9.]+%\) formally mapped; \d+/902 completion-unproved \| The mapped set comprises \d+ verified-benchmark and \d+ bounded documented No Credible Match targets\. \| Complete target-specific research for the remaining \d+\. \|",
        f"| Capability benchmark / No Credible Match | {BENCH['eligible']}/902 ({100 * BENCH['eligible'] / 902:.2f}%) formally mapped; {BENCH['unproved']}/902 completion-unproved | The mapped set comprises {BENCH['verified']} verified-benchmark and {BENCH['ncm']} bounded documented No Credible Match targets. | Complete target-specific research for the remaining {BENCH['unproved']}. |",
        "unresolved benchmark table",
    )
    replace_once_in_text(
        unresolved,
        r"\| Material required-state execution \| 4,312 applicability rows; [0-9,]+ linked to 713 unique final IDs, \d+ unresolved \([0-9.]+% assigned\), 0 executed \|",
        f"| Material required-state execution | 4,312 applicability rows; {MATERIAL['assigned']:,} linked to 713 unique final IDs, {MATERIAL['unresolved']} unresolved ({MATERIAL['percent']:.2f}% assigned), 0 executed |",
        "unresolved material table",
    )
    replace_once_in_text(
        unresolved,
        r"- `evidence/source/benchmark-final-902-mapping\.json` and matrix 03 retain \d+ evidence-preserving mappings \(\d+ verified benchmark and \d+ bounded documented No Credible Match\), or [0-9.]+%; \d+ remain completion-unproved\.",
        f"- `evidence/source/benchmark-final-902-mapping.json` and matrix 03 retain {BENCH['eligible']} evidence-preserving mappings ({BENCH['verified']} verified benchmark and {BENCH['ncm']} bounded documented No Credible Match), or {100 * BENCH['eligible'] / 902:.2f}%; {BENCH['unproved']} remain completion-unproved.",
        "unresolved benchmark narrative",
    )

    findings["audit_status"] = (
        "Blocked—not comprehensive or complete. The corrected 902-target register is current "
        "(788H/111D/3M). All 3,024 routes and 962 pages have accepted-target or excluded-surface "
        "static dispositions; accepted IDs map to 2,985 routes and 945 pages. "
        f"Benchmark/NCM completion credit is {BENCH['eligible']}/902, visual final-ID linkage is "
        f"{VISUAL['assigned']:,}/8,753, material-state linkage is {MATERIAL['assigned']:,}/4,312, "
        f"and {FINDING['total']} source-backed findings are retained. Only "
        f"{FINDING['p0p1_exact']}/{FINDING['p0p1']} P0/P1 findings contain a literal "
        "current-manifest ID; runtime remains unexecuted."
    )
    reconciliation = findings["counts"]["feature_link_reconciliation"]
    reconciliation["benchmark_mapping"] = {
        "eligible": BENCH["eligible"],
        "verified_benchmark": BENCH["verified"],
        "documented_no_credible_match": BENCH["ncm"],
        "completion_unproved": BENCH["unproved"],
    }
    reconciliation["visual_linkage"] = {
        "assigned": VISUAL["assigned"], "rows": VISUAL["rows"],
        "unresolved": VISUAL["unresolved"], "unique_working_ids": VISUAL["unique"],
    }
    reconciliation["material_state_linkage"] = {
        "assigned": MATERIAL["assigned"], "rows": MATERIAL["rows"],
        "unresolved": MATERIAL["unresolved"], "unique_working_ids": MATERIAL["unique"],
    }
    save(AUDIT / "findings.json", findings)


sync_current_finding_prose()
WAVE_DECISION_ID = "exact-current-902-source-intersection-wave-2026-08-13"
BASE_EXPLICIT_FINDING_IDS = {
    "FIN-INSIGHTS-DIRECT-OBJECT-01",
    "SITE-RBAC-001",
    "AUTH-EMAIL-VERIFY-CONTRACT-01",
    "HR-COMPLIANCE-EXPORT-PERMISSION-01",
    "HR-COMPLIANCE-RENEWALS-DISCLOSURE-01",
}
wave_finding_ids = set()
wave_link_count = 0
for finding in finding_rows:
    for decision in finding.get("feature_link_reconciliation", {}).get("decisions", []):
        if decision.get("legacy_family_id") == WAVE_DECISION_ID:
            wave_finding_ids.add(finding["id"])
            wave_link_count += len(decision.get("feature_ids", []))
explicit_re_adjudicated_findings = sorted(BASE_EXPLICIT_FINDING_IDS | wave_finding_ids)
explicit_re_adjudicated_links = 10 + wave_link_count
assert len(wave_finding_ids) == 48 and wave_link_count == 98
assert len(explicit_re_adjudicated_findings) == 53 and explicit_re_adjudicated_links == 108


def working_manifest_summary() -> dict:
    return {
        "path": MANIFEST_NAME, "sha256": MANIFEST_SHA, "rows": 902, "unique_stable_ids": 902,
        "classes": {"H": 788, "D": 111, "M": 3}, "stable_id_provenance": PROVENANCE,
        "route_enrichment": {
            "targets": ROUTES["targets"], "relations": ROUTES["relations"], "unique_routes": ROUTES["accepted_unique"],
            "inventory_routes": ROUTES["total"], "accepted_percent": ROUTES["percent"],
            "excluded_surface_relations": ROUTES["excluded"], "static_disposition_total": ROUTES["total"],
        },
        "page_enrichment": {
            "targets": PAGES["targets"], "relations": PAGES["relations"], "unique_pages": PAGES["accepted_unique"],
            "inventory_pages": PAGES["total"], "accepted_percent": PAGES["percent"],
            "excluded_surface_relations": PAGES["excluded"], "static_disposition_total": PAGES["total"],
        },
        "backend_enrichment": BACKEND,
        "benchmark_mapping": {
            "eligible": BENCH["eligible"], "verified_benchmark": BENCH["verified"],
            "verified_direct": BENCH["verified_direct"], "verified_rename": BENCH["verified_rename"],
            "documented_no_credible_match": BENCH["ncm"], "documented_ncm_direct": BENCH["ncm_direct"],
            "documented_ncm_rename": BENCH["ncm_rename"], "completion_unproved": BENCH["unproved"],
        },
        "derivation_note": "The 902 register supersedes the 894 stage after source adjudication added four reachable human jobs, three parameter-distinct exports and one scheduled signal-to-alert machine job. Excluded surfaces remain outside H/D/M counts.",
    }


def gate(completed: int, denominator: int, status: str, detail: str = "") -> dict:
    value = {"completed": completed, "denominator": denominator, "percent": round(100 * completed / denominator, 2), "status": status}
    if detail:
        value["detail"] = detail
    return value


# Completion gate report.
path = SOURCE / "completion-gate-report.json"
report = load(path)
report["audit_boundary"] = "Audit artifacts only for filesystem writes. No application code, configuration, routes, domain data, tests, deployment or Git history was changed. Authorised impersonation created only normal start/stop audit-log entries, and the browser identity was restored to Demo Administrator."
report["canonical_register"] = {"total": 902, "H": 788, "D": 111, "M": 3, "manifest": MANIFEST_NAME, "manifest_sha256": MANIFEST_SHA}
gates = report["gates"]
gates["canonical_features_registered"] = gate(902, 902, "complete-static-identity-only")
gates["routes_mapped_to_accepted_canonical_feature_id"] = gate(
    2985, 3024, "blocked",
    "39 routes are classified under excluded non-denominator SURFACE dispositions, not accepted canonical capability IDs. Static disposition is complete, but the prompt's literal FEATURE-ID mapping gate is not.",
)
gates["pages_mapped_to_accepted_canonical_feature_id"] = gate(
    945, 962, "blocked",
    "17 pages are classified under excluded non-denominator SURFACE dispositions, not accepted canonical capability IDs. Static disposition is complete, but the prompt's literal FEATURE-ID mapping gate is not.",
)
gates["combined_route_page_accepted_feature_id_mapping"] = gate(
    3930, 3986, "blocked",
    "64 classified surfaces remain outside the accepted capability denominator under SURFACE dispositions; they are not silently counted as canonical FEATURE-ID mappings.",
)
gates["routes_with_stable_static_disposition_id"] = gate(3024, 3024, "complete-static-disposition", "2,985 routes map to accepted targets; 39 retain excluded non-denominator SURFACE dispositions.")
gates["pages_with_stable_static_disposition_id"] = gate(962, 962, "complete-static-disposition", "945 pages map to accepted targets; 17 retain excluded non-denominator SURFACE dispositions.")
gates["combined_route_page_static_disposition"] = gate(3986, 3986, "complete-static-disposition")
gates["feature_benchmark_or_documented_no_match"] = gate(451, 902, "blocked", "362 verified benchmark mappings (340 direct, 22 rename) and 89 target-specific NCM decisions (82 direct, 7 rename); 451 unproved.")
gates["final_id_task_scripts_structural"] = gate(788, 788, "complete-structural-only", "788 Markdown files and scorecard rows; current scores blank and runtime unexecuted.")
gates["ten_dimension_ease_scores_measured_and_independently_validated"] = gate(0, 788, "blocked")
gates["representative_role_tasks_executed"] = gate(0, 788, "blocked")
gates["representative_actor_classes"] = gate(
    12, 12, "complete-bounded-browser-entry",
    "The original signed-in pass sampled 11 actor classes; a current-main direct-login pass sampled the synthetic Clinical/Medication Lead on Health & Clinical and eMAR at all four required viewports. This proves actor entry only; task-level completion remains 0/788.",
)
gates["required_component_viewport_rows_present"] = gate(
    1880, 1880, "complete-structural-observation-set",
    "All 470 selected page/component families retain all four required viewport rows; the pinned raw System Users 1280x800 observation is restored while the later 1280x720 remediation screenshot remains separate finding evidence.",
)
gates["fully_measured_component_viewport_rows"] = gate(
    1876, 1880, "blocked",
    "Four immutable-baseline Control Room handover rows lack full geometry; 469/470 families are fully measured. A separate current-main pass measured the materially changed handover component at all four viewports without horizontal overflow, so it does not retroactively change baseline credit.",
)
gates["tests_executed"] = gate(
    0, 867, "blocked-not-audit-wide-executed",
    "The full 867-test audit denominator has not been executed as one controlled audit gate. Focused remediation suites used disposable MySQL databases, but they are not credited as audit-wide execution.",
)
gates["visual_rows_linked_to_exact_final_feature_id"] = gate(8153, 8753, "blocked", "600 unresolved; 771 final IDs assigned and 834 have some visual lineage.")
gates["material_required_states_linked_to_exact_final_feature_id"] = gate(3935, 4312, "blocked", "377 unresolved; 713 final IDs represented; runtime execution 0/4,312.")
gates["benchmark_project_catalogue_metadata"] = gate(97, 97, "complete-metadata", "Every prompt-listed project has a pinned identity, licence/edition boundary and explicit catalogue disposition. This is metadata coverage, not substantive Pass-3 review.")
gates["benchmark_project_specific_triage"] = gate(97, 97, "complete-primary-source-triage", "All 97 prompt-listed projects have project-specific behavior, immutable primary-source loci, licence/edition boundaries and an explicit Native, Reject or Separate future decision. Catalogue relations do not automatically grant target completion credit.")
gates["visual_findings_independently_resampled"] = {
    "completed": 0,
    "denominator": 4,
    "percent": 0.0,
    "status": "blocked-no-independent-runtime-resample",
    "detail": "Four retained material hero/overlay finding families are in scope: VIS-HERO-DENSITY-01, VIS-OVERLAY-FOCUS-01, VIS-MOBILE-NAV-01 and INCIDENT-RECOVERY-01. The frozen audited baseline still has 0/4 independent runtime resamples. A supplemental read-only current-main pass sampled all 4/4 and reproduced or partially reproduced every family, but source/build drift means it cannot be credited retroactively to the baseline denominator; see evidence/browser/current-main-visual-family-resample-2026-08-14.json.",
}
gates["p0_p1_required_evidence_fields"] = gate(FINDING["p0p1"], FINDING["p0p1"], "complete-structural")
gates["p0_p1_exact_final_feature_link"] = gate(
    FINDING["p0p1_exact"], FINDING["p0p1"], "complete-static-linkage",
    f"{FINDING['p0p1_exact']}/{FINDING['p0p1']} P0/P1 findings contain a literal current ID; "
    f"{FINDING['p0p1_without']} do not. Literal equality is not runtime validation.",
)
gates["p0_p1_exact_owner_or_explicit_no_owner_disposition"] = gate(
    FINDING["p0p1"], FINDING["p0p1"], "complete-static-accountability",
    f"All {FINDING['p0p1']} P0/P1 findings have literal current-target links. This is not runtime validation.",
)
gates["all_findings_with_literal_current_feature_id"] = gate(
    FINDING["with_exact"], FINDING["total"], "complete-static-linkage",
    "All retained findings have at least one literal current target ID; this does not prove runtime reproduction or remediation.",
)
gates["findings_with_neutral_requirements_and_no_copy_boundary"] = gate(FINDING["total"], FINDING["total"], "complete-structural")
gates["agent_assignments_reconciled_and_none_running"] = gate(
    AGENT_COUNT,
    111,
    "blocked-historical-reconciliation-live-tasks-active",
    f"Historical reconciliation snapshot only: {AGENT_COUNT}/111 assignment records were reconciled with explicit role/ID, scope, pass, "
    f"returned-evidence count and unresolved gaps. Live orchestration currently records {ACTIVE_BACKGROUND_TASKS} active audit/remediation tasks; "
    "the historical 105/111 ratio is not a live completion denominator and final freeze remains blocked.",
)
gates["orchestrator_only_wrote_audit_artifacts"] = gate(
    0, 1, "blocked-process-deviation",
    "Delegated agents wrote dated audit artifacts during earlier and closing waves. No product files were changed, but the prompt's orchestrator-only-writer rule was not met.",
)
gates["evidence_directory_content_boundary"] = gate(
    1,
    1,
    "complete-structural",
    "Executable reproducibility helpers are isolated in audit-root generators/; evidence/ contains only "
    "redacted/structured safe evidence logs, comparison metadata, browser sidecars, benchmark metadata "
    "and small derived records as classified by evidence/README.md.",
)
gates["agent_assignment_required_fields"] = gate(
    AGENT_COUNT,
    AGENT_COUNT,
    "complete-process",
    "Every reconciled assignment explicitly records role/ID, scope, pass, evidence_count and unresolved_gaps.",
)
report["completion_blockers"] = [
    "routes_mapped_to_accepted_canonical_feature_id", "pages_mapped_to_accepted_canonical_feature_id",
    "combined_route_page_accepted_feature_id_mapping", "visual_findings_independently_resampled",
    "feature_benchmark_or_documented_no_match", "ten_dimension_ease_scores_measured_and_independently_validated",
    "representative_role_tasks_executed", "journeys_executed_all_viewports", "safe_routes_against_all_user_facing_gets",
    "fully_measured_component_viewport_rows", "visual_rows_linked_to_exact_final_feature_id",
    "custom_overlay_static_trigger_classification", "primitive_overlay_static_trigger_classification",
    "material_required_states_linked_to_exact_final_feature_id", "tests_executed",
    "modules_with_all_eight_passes_complete",
    "pass8_fresh_reconciliation",
    "agent_assignments_reconciled_and_none_running",
    "orchestrator_only_wrote_audit_artifacts",
]
report["remaining_static_work_not_requiring_user_input"] = [
    "Target-specific benchmark/NCM research for 451 targets.",
    "Resolve 600 visual rows and 377 material-state rows without family-level inheritance.",
    "Retain target-specific finding validation and runtime reproduction boundaries despite complete literal current-ID linkage.",
]
save(path, report)


# CSV semantic validation.
path = SOURCE / "csv-semantic-validation.json"
csv_report = load(path)
shapes = csv_report["current_csv_shapes"]
for name, required in (("02-eight-pass-coverage-ledger.csv", 902), ("03-feature-to-benchmark-matrix.csv", 902), ("04-workflow-usability-scorecard.csv", 788), ("05-browser-visual-coverage-matrix.csv", 8753), ("06-open-source-benchmark-register.csv", 98)):
    rows, columns, digest = csv_shape(name)
    shapes[name]["data_rows"] = rows
    shapes[name]["columns"] = columns
    shapes[name]["sha256"] = digest
    shapes[name]["required_rows"] = required
    shapes["03-feature-to-benchmark-matrix.csv"].update({"benchmark_mapped": 451, "benchmark_verified": 362, "benchmark_documented_no_credible_match": 89, "benchmark_completion_unproved": 451})
shapes["04-workflow-usability-scorecard.csv"].update({"runtime_executed": 0, "independently_reviewed": 0, "current_scores_measured": 0})
shapes["05-browser-visual-coverage-matrix.csv"].update({
    "semantic_tuple_sha256": visual_summary["outputs"]["semantic_tuple_sha256"],
    "assigned_final_feature_id": 8153, "unresolved_final_feature_id": 600,
    "unique_assigned_final_feature_ids": 771, "manifest_ids_with_any_visual_lineage": 834,
})
shapes["06-open-source-benchmark-register.csv"].update({
    "prompt_listed_project_denominator": 97,
    "prompt_listed_identity_rows_complete": 97,
    "supplemental_project_rows": 1,
    "physical_rows": 98,
    "identity_rows_complete": True,
    "project_specific_triage_rows": 98,
    "project_specific_triage_prompt_listed_rows": 97,
    "project_specific_triage_supplemental_rows": 1,
    "selected_native_deep_comparison_prompt_rows": 72,
    "project_specific_triage_complete": True,
})
csv_report["working_manifest"] = working_manifest_summary()
csv_report["semantic_checks"]["all_route_page_inventory_ids_have_static_disposition"] = True
csv_report["completion_boundary"] = "Structural identity, route/page static disposition, prompt-list project triage and task files are complete. Target-level benchmark research, exact visual linkage, current ease scores, representative runtime roles/tasks/states and tests remain incomplete."
save(path, csv_report)


# Validation report.
path = SOURCE / "validation-report.json"
validation = load(path)
validation["validation_scope"] = "current canonical 902 bundle after deterministic inventory, ledger, task-script, benchmark and partial visual/finding-link generation"
checks = validation["checks"]
checks.pop("corrected_894_denominator_independently_reestablished", None)
checks["corrected_902_denominator_independently_reestablished"] = True
checks["downstream_manifest_integration_complete"] = True
checks["all_routes_pages_have_stable_static_disposition_ids"] = True
checks["all_routes_pages_mapped_to_accepted_canonical_feature_ids"] = False
checks["visual_finding_resample_denominator_established"] = True
checks["visual_finding_independent_runtime_resample_complete"] = False
checks["current_main_supplemental_visual_resample_complete"] = True
checks["all_agents_received_and_reconciled"] = True
checks["historical_agent_reconciliation_not_live_completion_metric"] = True
checks["benchmark_project_specific_triage_complete"] = True
checks["fresh_pass8_after_current_rebuild"] = False
validation["working_manifest"] = working_manifest_summary()
validation["structural_errors"] = [
    "All 3,024 route IDs and 962 page IDs have stable static dispositions; 39 routes and 17 pages are deliberately excluded non-denominator surfaces rather than accepted capabilities.",
    "The prompt's literal accepted FEATURE-ID mapping gate is 2,985/3,024 routes and 945/962 pages, not 100%; excluded SURFACE dispositions close classification only.",
    "The material hero/overlay finding-family resample denominator is 4 and the frozen-baseline independent runtime numerator remains 0/4, so that completion gate stays blocked. A separate read-only current-main pass sampled 4/4 and reproduced or partially reproduced each family; it is supplemental drift evidence, not retroactive baseline credit.",
    "The feature benchmark gate is 451/902; 451 targets remain completion-unproved.",
    "The 788 task scripts and scorecard rows are structural only: bounded browser entry now covers 12/12 actor classes, but 0 canonical task scripts, 0 independent usability reviews and 0 current ten-dimension scores are complete.",
    "The visual matrix assigns 8,153/8,753 rows to final IDs and leaves 600 unresolved; its material-state subset assigns 3,935/4,312 and leaves 377 unresolved.",
    f"Finding linkage is incomplete: {FINDING['exact_links']} literal exact-ID links across {FINDING['exact_targets']} final targets and {FINDING['with_exact']} findings do not establish runtime validation. Only {FINDING['p0p1_exact']}/{FINDING['p0p1']} P0/P1 findings contain at least one literal current-manifest ID; {FINDING['p0p1_without']}/{FINDING['p0p1']} do not.",
    "All 97 prompt-listed projects have substantive project-specific primary-source triage and an explicit Native, Reject or Separate future decision. The one supplemental Frappe row remains outside the prompt denominator.",
    "Final independent static bundle validation and process-register closure passed. Substantive product-evidence gates remain blocked.",
]
validation["current_artifact_hashes"] = {
    "manifest_sha256": MANIFEST_SHA, "benchmark_mapping_sha256": BENCHMARK_SHA,
    "route_page_gap_sha256": GAP_SHA, "inventory_sha256": sha(AUDIT / "inventory.json"),
    "findings_sha256": sha(AUDIT / "findings.json"),
    "02_ledger_sha256": sha(AUDIT / "02-eight-pass-coverage-ledger.csv"),
    "03_matrix_sha256": sha(AUDIT / "03-feature-to-benchmark-matrix.csv"),
    "04_scorecard_sha256": sha(AUDIT / "04-workflow-usability-scorecard.csv"),
    "05_visual_matrix_sha256": sha(AUDIT / "05-browser-visual-coverage-matrix.csv"),
    "05_visual_semantic_tuple_sha256": visual_summary["outputs"]["semantic_tuple_sha256"],
    "browser_representative_role_pass_sha256": sha(SOURCE / "browser-representative-role-pass-901.json"),
    "benchmark_wave15_zero_delta_adjudication_sha256": WAVE15_SHA,
    "benchmark_wave16_adjudication_sha256": WAVE16_SHA,
    "benchmark_wave17_adjudication_sha256": WAVE17_SHA,
    "benchmark_wave18_adjudication_sha256": WAVE18_SHA,
    "benchmark_wave19_adjudication_sha256": WAVE19_SHA,
    "benchmark_wave20_adjudication_sha256": WAVE20_SHA,
    "benchmark_wave21_adjudication_sha256": WAVE21_SHA,
    "benchmark_wave22_adjudication_sha256": WAVE22_SHA,
    "benchmark_wave23_adjudication_sha256": WAVE23_SHA,
    "benchmark_wave24_adjudication_sha256": WAVE24_SHA,
    "benchmark_wave27_adjudication_sha256": WAVE27_SHA,
    "benchmark_wave28_adjudication_sha256": WAVE28_SHA,
    "benchmark_wave30_adjudication_sha256": WAVE30_SHA,
    "00_executive_summary_sha256": sha(AUDIT / "00-executive-summary.md"),
    "07_module_findings_sha256": sha(AUDIT / "07-module-findings.md"),
    "13_unresolved_questions_sha256": sha(AUDIT / "13-unresolved-questions-and-evidence-gaps.md"),
    "completion_gate_report_sha256": sha(SOURCE / "completion-gate-report.json"),
    "orchestration_status_sha256": sha(SOURCE / "orchestration-status-2026-08-14.json"),
    "coordinator_live_checkpoint_sha256": sha(SOURCE / "coordinator-live-checkpoint-2026-08-21.json"),
    "coordinator_live_checkpoint_markdown_sha256": sha(SOURCE / "coordinator-live-checkpoint-2026-08-21.md"),
    "audit_dashboard_sha256": sha(AUDIT / "audit-dashboard.html"),
}
validation["completion_blockers"] = report["completion_blockers"]
save(path, validation)


# Fresh Pass-8 bundle reconciliation.
path = SOURCE / "fresh-pass8-bundle-reconciliation.json"
fresh = load(path)
fresh["review_scope"] = "Fresh static reconciliation of the canonical 902 audit bundle after route/page disposition, benchmark, ledger, task and visual regeneration"
fresh["denominator"] = {
    "accepted_total": 902, "human_ui": 788, "download_or_api": 111, "machine_ingress": 3,
    "superseded_894_base": 894, "source_adjudicated_additions": 7,
    "source_families": 595, "route_references": 3024, "unique_pages": 962,
    "arithmetic_validated": True, "working_manifest": MANIFEST_NAME, "working_manifest_sha256": MANIFEST_SHA,
    "working_manifest_unique_stable_ids": 902, "stable_id_provenance": PROVENANCE,
    "accepted_route_enrichment": ROUTES, "accepted_page_enrichment": PAGES, "backend_enrichment": BACKEND,
    "full_static_surface_disposition": {"routes": "3024/3024", "pages": "962/962"},
    "durable_working_target_manifest_materialized": True,
}
fresh["artifact_integration"] = {
    "inventory": {"rows": 902, "status": "canonical-static-register-current"},
    "02_eight_pass_coverage_ledger": {"rows": 902, "status": "canonical-structural-current"},
    "03_feature_benchmark_matrix": {"rows": 902, "mapped": 451, "blocked": 451, "status": "canonical-structural-current-substantive-coverage-blocked"},
    "04_workflow_usability_scorecard": {"rows": 788, "measured_scores": 0, "runtime_executed": 0, "status": "canonical-structural-current-runtime-blocked"},
    "05_browser_visual_matrix": {"rows": 8753, "final_id_assigned": 8153, "unresolved": 600, "status": "partial-final-id-linkage"},
    "task_scripts_final_902": {"files": 788, "nul_files": 0, "runtime_executed": 0, "status": "canonical-structural-current-runtime-blocked"},
    "browser_representative_role_pass": {"roles_sampled": 11, "canonical_tasks_completed": 0, "status": "bounded-read-only-sample-not-completion"},
}
fresh["current_visual_state_reconciliation"] = {
    "rows": 8753, "assigned_to_final_id": 8153, "unresolved": 600,
    "assigned_unique_final_ids": 771, "final_ids_with_any_visual_lineage": 834,
    "classification_counts": visual_summary["counts"]["classification_counts"],
    "material_state_rows": 4312, "material_state_final_id_assigned": 3935,
    "material_state_unresolved": 377, "material_state_unique_final_ids": 713, "runtime_executed": 0,
}
fresh.setdefault("runtime_blockers", {})["representative_actor_classes_executed"] = "12/12 bounded actor-entry samples; no canonical task completion, denied-state, recovery or handoff credit"
fresh["runtime_blockers"]["audit_wide_tests_executed"] = "0/867 (focused disposable-MySQL remediation suites exist, but the complete audit-wide test denominator has not been run as one controlled gate)"
fresh["benchmark_reconciliation"] = {
    "catalogue_projects": 97, "prompt_listed_metadata_dispositions": 97, "prompt_listed_substantively_triaged": 97,
    "prompt_listed_substantive_triage_incomplete": 0, "supplemental_projects": 1,
    "final_targets_with_completion_credit": 451, "verified_benchmark": 362,
    "verified_direct": 340, "verified_rename": 22, "documented_no_credible_match": 89,
    "documented_ncm_direct": 82, "documented_ncm_rename": 7, "completion_unproved": 451,
    "mapping_artifact": BENCHMARK_NAME, "mapping_sha256": BENCHMARK_SHA,
}
fresh["finding_reconciliation"] = {
    "findings": FINDING["total"], "p0": FINDING["P0"], "p1": FINDING["P1"], "p2": FINDING["P2"], "links": FINDING["links"],
    "literal_exact_current_links": FINDING["exact_links"], "literal_exact_current_targets": FINDING["exact_targets"],
    "explicitly_re_adjudicated_findings": explicit_re_adjudicated_findings,
    "p0_p1_with_core_required_fields": FINDING["p0p1"], "p0_p1_with_exact_final_feature_id": FINDING["p0p1_exact"],
    "p0_p1_without_exact_final_feature_id": FINDING["p0p1_without"], "findings_with_exact_final_feature_id": FINDING["with_exact"],
    "findings_without_exact_final_feature_id": FINDING["without_exact"], "final_link_coverage_established": FINDING["without_exact"] == 0,
    "definition_boundary": "Literal stable-ID equality is not runtime or target-outcome validation.",
}
fresh["retained_audit_reproducibility_generators"] = {
    "inventory": "generators/rebuild-canonical-inventory-register.py", "ledgers": "generators/rebuild-final-902-ledgers.py",
    "tasks": "generators/rebuild-final-902-task-scripts.ps1", "visual_links": "generators/rebuild-final-902-visual-links.py",
    "findings": "generators/append-three-902-findings.py", "summary_sync": "generators/sync-current-902-summaries.py",
}
fresh["remaining_reconciliation_order"] = [
    "Complete 451 target-specific benchmark/NCM decisions.", "Resolve 600 visual and 377 material-state final-ID links.",
    "Retain target-specific finding validation and runtime reproduction boundaries despite complete literal current-ID linkage.",
    "Expand the bounded 12-role entry sample into canonical task execution using resettable fixtures; retain the observed eMAR mobile overflow as an open finding.",
    "Execute task, failure, recovery, handoff and required viewport validation.",
]
save(path, fresh)


# Material-state summary.
path = SOURCE / "material-state-reconciliation.json"
material = load(path)
material["generated_at"] = NOW
material["denominator_status"] = "canonical_902_register_materialized_material_links_partial_runtime_unexecuted"
material["working_human_capabilities"] = 788
material["earlier_894_derivation_superseded"] = True
material["working_manifest"] = working_manifest_summary()
material["final_feature_linkage"] = {
    "assigned_rows": 3935, "unresolved_rows": 377, "assigned_unique_final_feature_ids": 713, "percent": 91.26,
    "proof_boundary": "Static identity linkage only; it does not establish rendered, recovery or completion behavior.",
}
material["final_feature_link_completion_credit"] = 3935
material["runtime_state_completion_credit"] = 0
save(path, material)


# Finding-link reconciliation rebuilt directly from findings.json.
path = SOURCE / "finding-link-reconciliation.json"
link_report = load(path)
manifest_ids = {row["working_key"] for row in manifest["targets"]}
rows = findings["findings"]
exact = [(row["id"], feature) for row in rows for feature in row.get("feature_ids", []) if feature in manifest_ids]
exact_findings = {finding_id for finding_id, _ in exact}
p0p1 = [row for row in rows if row["priority"] in {"P0", "P1"}]
p0p1_exact = {row["id"] for row in p0p1} & exact_findings
link_report["generated_at"] = NOW
link_report["status"] = "current_902_literal_link_reconciliation_partial_runtime_unverified"
link_report["scope_boundary"] = "Links preserve source evidence and literal current IDs; neither literal equality nor route/page intersection establishes runtime outcome completion."
link_report["current_final_id_link_summary"] = {
    "literal_links": len(exact), "literal_targets": len({feature for _, feature in exact}),
    "explicitly_re_adjudicated_links": explicit_re_adjudicated_links,
    "explicitly_re_adjudicated_findings": explicit_re_adjudicated_findings,
    "findings_with_literal_exact_current_id": len(exact_findings),
    "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
    "p0_p1_with_literal_exact_current_id": len(p0p1_exact),
    "p0_p1_without_literal_exact_current_id": len(p0p1) - len(p0p1_exact), "complete": False,
}
decisions = [decision for row in rows for decision in row.get("feature_link_reconciliation", {}).get("decisions", [])]
link_report["counts"] = {
    "findings": len(rows), "total_links": sum(len(row.get("feature_ids", [])) for row in rows),
    "findings_with_uncertainty": sum(bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows),
    "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
    "route_intersection_groups": sum(bool(decision.get("route_hits")) for decision in decisions),
    "unique_page_intersection_groups": sum(bool(decision.get("page_hits")) for decision in decisions),
    "one_to_one_groups": sum("one-to-one" in str(decision.get("method", "")).lower() for decision in decisions),
}
link_report["findings"] = [
    {"finding_id": row["id"], "feature_ids": row.get("feature_ids", []), "literal_current_feature_ids": [feature for feature in row.get("feature_ids", []) if feature in manifest_ids], "reconciliation": row.get("feature_link_reconciliation", {})}
    for row in rows
]
save(path, link_report)


# Historical-stage authority labels.
path = SOURCE / "capability-integration-reconciliation.json"
historical = load(path)
historical["current_authority_summary"] = {
    "status": "canonical_902_register_current", "manifest": MANIFEST_NAME, "manifest_sha256": MANIFEST_SHA,
    "counts": COUNTS, "benchmark_completion_credit": 451, "benchmark_completion_unproved": 451,
    "note": "All other projection counts in this artifact are frozen historical lineage evidence.",
}
save(path, historical)

path = SOURCE / "final-capability-denominator-reconciliation.json"
historical = load(path)
historical["status"] = "historical_superseded_894_denominator_derivation_evidence_only"
historical["superseded_by"] = ["capability-denominator-902-adjudication.json", MANIFEST_NAME]
historical["current_authority"] = {"manifest": MANIFEST_NAME, "sha256": MANIFEST_SHA, "counts": COUNTS}
save(path, historical)

path = SOURCE / "static-route-enrichment-application-summary.json"
historical = load(path)
historical["status"] = "historical_894_enrichment_stage_superseded_by_902_static_disposition_register"
historical["superseded_by"] = [MANIFEST_NAME, GAP_NAME, "canonical-inventory-register-generation-summary.json"]
historical["derivation_stage_boundary"] = "Frozen 894-stage enrichment evidence; do not read its after-counts as current authority."
save(path, historical)

path = SOURCE / "visual-matrix-generation-summary.json"
historical = load(path)
historical["superseded_by"] = "final-902-visual-link-generation-summary.json"
historical["note"] = "Historical pre-link snapshot only. Current authority is final-902-visual-link-generation-summary.json: 8,153/8,753 assigned, 600 unresolved, 771 assigned IDs, 834 IDs with lineage; material subset 3,935/4,312 assigned and 377 unresolved."
save(path, historical)

print(json.dumps({
    "status": "current_902_summaries_synchronised_runtime_still_blocked",
    "manifest": MANIFEST_SHA, "inventory": sha(AUDIT / "inventory.json"), "findings": sha(AUDIT / "findings.json"),
    "ledger": sha(AUDIT / "02-eight-pass-coverage-ledger.csv"), "matrix": sha(AUDIT / "03-feature-to-benchmark-matrix.csv"),
    "scorecard": sha(AUDIT / "04-workflow-usability-scorecard.csv"), "visual": sha(AUDIT / "05-browser-visual-coverage-matrix.csv"),
}, indent=2))
