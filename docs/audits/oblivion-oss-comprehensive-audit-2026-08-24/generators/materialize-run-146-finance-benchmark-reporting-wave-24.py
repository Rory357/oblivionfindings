from __future__ import annotations

import csv
import hashlib
import io
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
RUN_ID = "RUN-146-FINANCE-BENCHMARK-REPORTING-WAVE-24"
MATRIX_SHA256 = "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0"
REGISTER_SHA256 = "5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884"
RUN_145_RECEIPT_SHA256 = "8306a8aefe0a490ebf206d0c4716d92930326988f19e0ed495a3c2d0002c7cf9"
REPORT_PATHS = (
    "00-executive-summary.md",
    "07-module-findings.md",
    "12-native-build-and-do-not-copy-register.md",
    "13-unresolved-questions-and-evidence-gaps.md",
    "findings.json",
)
BASELINE_REPORT_SHA256 = {
    "00-executive-summary.md": "45f92926a50814f57f64fba1d72ff87ebe700df3ddfdab41192f9c5997f66468",
    "07-module-findings.md": "5a8de7d5c9e181d8da0425e7f040e8744dd85cbfda16573ef824ce3219f85712",
    "12-native-build-and-do-not-copy-register.md": "44ae85422a6863d4804fec7d495107b9bdc937257f023767fb306ccd755e137a",
    "13-unresolved-questions-and-evidence-gaps.md": "61888895bce1860f0c26b3ca0bae789180c770c1348a76b5ba83a0ed0187e6d0",
    "findings.json": "e66620d6bf0ecdafedcd265c82b26fab77ff59363807fbd93cb2bb3d68e3b441",
}


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(relative: str) -> str:
    return sha256_bytes((AUDIT_DIR / relative).read_bytes())


def read_lf(relative: str) -> str:
    payload = (AUDIT_DIR / relative).read_bytes()
    assert b"\r\n" not in payload, f"{relative} must remain LF-only"
    return payload.decode("utf-8")


def write_lf(relative: str, text: str) -> None:
    assert "\r" not in text
    with (AUDIT_DIR / relative).open("w", encoding="utf-8", newline="\n") as handle:
        handle.write(text)


def replace_exact(text: str, old: str, new: str, label: str) -> str:
    old_count = text.count(old)
    new_count = text.count(new)
    if old_count == 1 and new_count == 0:
        return text.replace(old, new, 1)
    if old_count == 0 and new_count == 1:
        return text
    raise AssertionError(f"{label}: expected exactly one old or one current value; old={old_count}, current={new_count}")


def replace_line(text: str, prefix: str, new_line: str, label: str) -> str:
    lines = text.splitlines()
    matches = [index for index, line in enumerate(lines) if line.startswith(prefix)]
    if not matches and lines.count(new_line) == 1:
        return text
    assert len(matches) == 1, f"{label}: expected one line beginning {prefix!r}, got {len(matches)}"
    lines[matches[0]] = new_line
    return "\n".join(lines) + ("\n" if text.endswith("\n") else "")


def replace_cell_on_line(text: str, prefix: str, old: str, new: str, label: str) -> str:
    lines = text.splitlines()
    matches = [index for index, line in enumerate(lines) if line.startswith(prefix)]
    assert len(matches) == 1, f"{label}: expected one target row, got {len(matches)}"
    line = lines[matches[0]]
    if line.count(new) == 1:
        return text
    if old in line:
        assert line.count(old) == 1
        lines[matches[0]] = line.replace(old, new, 1)
    else:
        raise AssertionError(f"{label}: target cell is neither baseline nor current")
    return "\n".join(lines) + ("\n" if text.endswith("\n") else "")


def assert_live_benchmark_state() -> None:
    assert sha256_file("03-feature-to-benchmark-matrix.csv") == MATRIX_SHA256
    assert sha256_file("06-open-source-benchmark-register.csv") == REGISTER_SHA256
    assert sha256_file("evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json") == RUN_145_RECEIPT_SHA256

    with (AUDIT_DIR / "03-feature-to-benchmark-matrix.csv").open("r", encoding="utf-8", newline="") as handle:
        matrix_rows = list(csv.DictReader(handle))
    assert len(matrix_rows) == 340
    credited_targets = sorted(row["feature_id"] for row in matrix_rows if row["benchmark_mapping_credit"] == "true")
    assert credited_targets == ["CAP-FIN-BILLING-INVOICE-LIFECYCLE", "CAP-FIN-FX-REVALUATION"]
    no_match_states = {row["no_match_evidence"] for row in matrix_rows}
    assert no_match_states == {
        "NOT_DOCUMENTED_CURRENT_AUDIT",
        "NCM_NOT_AUTHORIZED_NO_TARGET_SPECIFIC_CATALOGUE_COMPLETE_SEARCH",
    }
    assert sum(row["no_match_evidence"] == "NOT_DOCUMENTED_CURRENT_AUDIT" for row in matrix_rows) == 338
    assert sum(row["no_match_evidence"] == "NCM_NOT_AUTHORIZED_NO_TARGET_SPECIFIC_CATALOGUE_COMPLETE_SEARCH" for row in matrix_rows) == 2

    with (AUDIT_DIR / "06-open-source-benchmark-register.csv").open("r", encoding="utf-8", newline="") as handle:
        register_rows = list(csv.DictReader(handle))
    assert len(register_rows) == 98
    credited_projects = sorted(row["project"] for row in register_rows if row["current_target_specific_mapping_credit"] == "true")
    assert credited_projects == ["Dolibarr/dolibarr", "frappe/erpnext"]
    bigcapital = next(row for row in register_rows if row["project"] == "bigcapitalhq/bigcapital")
    assert bigcapital["current_target_specific_mapping_credit"] == "false"


def update_executive_summary() -> None:
    relative = "00-executive-summary.md"
    text = read_lf(relative)
    heading = "## RUN-144–145 dashboard and benchmark-mapping checkpoint"
    if heading not in text:
        assert sha256_file(relative) == BASELINE_REPORT_SHA256[relative]
        marker = "## Current raw source census"
        assert text.count(marker) == 1
        block = """## RUN-144–145 dashboard and benchmark-mapping checkpoint

RUN-144 independently verifies the exact RUN-143 audit dashboard at all four required viewports with 23/23 visible checks, 10/10 navigation targets, zero console warnings/errors, exact static census, and a three-file audit-artifact-only scope. This is dashboard verification only and grants no application-browser, runtime, test, benchmark, Pass, finding, feature-completion, or audit-completion credit.

RUN-145 completes a fresh Agent A → B → C → independent Agent D clean-room static comparison and a separate fresh Pass-8 adversarial correction/review for exactly two Finance targets. `CAP-FIN-FX-REVALUATION` maps narrowly to `frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f`; `CAP-FIN-BILLING-INVOICE-LIFECYCLE` maps narrowly to the complementary pair `frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f` and `Dolibarr/dolibarr@769c7db907099643558e77d7002c109cfda919e5`. `bigcapitalhq/bigcapital` remains adjacent-only and unselected. The matrix changes exactly 2 rows / 18 cells from `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390` to `3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0`; the benchmark register changes exactly 2 rows / 30 cells from `cc493cd1807e62a9ffa27192c658400e697391b7a0baa3f0014628145c6b7b91` to `5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884`. The RUN-145 integration receipt is SHA-256 `8306a8aefe0a490ebf206d0c4716d92930326988f19e0ed495a3c2d0002c7cf9`. Current target-specific benchmark coverage is **2 / 340**, final no-match/NCM coverage is **0 / 340**, and **338 targets remain unresolved**. This awards static target-specific mapping credit only: runtime, browser, executed-test, ease, release, Pass, final-finding, feature-completion, and audit-completion credit remain zero.

"""
        text = text.replace(marker, block + marker, 1)

    evidence = "- `evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json`: deterministic RUN-145 receipt for exactly two independently adjudicated static target mappings, 0 final no-matches/NCMs, 338 unresolved targets, exact 2-row matrix and 2-row register mutations, BigCapital adjacent-only preservation, and every non-mapping credit remaining zero."
    if evidence not in text:
        marker = "- `generators/integrate-formal-upstream-triage-wave-03.py`:"
        assert text.count(marker) == 1
        index = text.index(marker)
        text = text[:index] + evidence + "\n" + text[index:]

    reporting_evidence = "- `evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json`: deterministic RUN-146 reconciliation of the five current reporting surfaces to 2/340 target-specific static mappings, 0/340 final no-matches/NCMs, 338 unresolved targets, exact current matrix/register pins, and every non-mapping credit remaining zero."
    if reporting_evidence not in text:
        marker = "- `generators/integrate-formal-upstream-triage-wave-03.py`:"
        assert text.count(marker) == 1
        index = text.index(marker)
        text = text[:index] + reporting_evidence + "\n" + text[index:]

    text = replace_line(
        text,
        "- `03-feature-to-benchmark-matrix.csv`:",
        "- `03-feature-to-benchmark-matrix.csv`: current 340-row canonical static identity matrix at SHA-256 `3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0`; retained linkage gaps are 1 route path, 166 route names, 4 page files, 0 backend anchors, and 8 static test anchors. Exactly 2 rows have static target-specific benchmark-mapping credit, 0 have final no-match/NCM credit, and 338 remain unresolved. Ease, executed-test, runtime, browser, release, P2–P8, final-finding, feature-completion, and audit-completion credit remain zero.",
        "executive current matrix catalogue",
    )
    text = replace_line(
        text,
        "- `06-open-source-benchmark-register.csv`:",
        "- `06-open-source-benchmark-register.csv`: current 98-row register at SHA-256 `5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884`, preserving 95 exact prompt repositories plus three historical extras and prior observer/formal-triage boundaries. RUN-145 changes only the ERPNext and Dolibarr rows; those two rows carry current target-specific mapping credit. BigCapital remains unchanged, adjacent-only, and uncredited; no other project or target inherits mapping or completion credit.",
        "executive current register catalogue",
    )
    text = replace_line(
        text,
        "- `audit-dashboard.html`:",
        "- `audit-dashboard.html`: progress dashboard generated only from current structured evidence through RUN-146. Prior receipts are immutable history for their exact superseded HTML; the regenerated artifact requires a separate fresh RUN-147 viewport/link/anchor/console receipt and cannot award application-browser or downstream credit.",
        "executive current dashboard catalogue",
    )

    text = replace_line(
        text,
        "4. Build on the completed 16/16 blocker review",
        "4. Preserve the seven project-level blockers, D's bounded historical `AO-A53-024-01` correction, and RUN-145's two exact target-specific mappings. Continue the complete Agent A → B → C → independent Agent D sequence or a target-specific catalogue-complete final-no-match/NCM adjudication across the remaining 338 canonical targets. Promote only an independently approved exact target mapping or documented exhaustive final no-match; source observation, packet materialization, lineage validation, mechanical comparison, source-anchor validation, and adjacency are not mapping or completion credit.",
        "executive immediate work order",
    )
    write_lf(relative, text)


def update_module_findings() -> None:
    relative = "07-module-findings.md"
    text = read_lf(relative)
    if "awards exactly **2 target-specific static benchmark-mapping credits**" not in text:
        assert sha256_file(relative) == BASELINE_REPORT_SHA256[relative]
    text = replace_line(
        text,
        "> Status:",
        "> Status: in progress; source-bound reporting only. This file materializes all 340 frozen canonical static feature identities and 12 provisional source claims. It contains **0 final P0/P1 findings** and awards exactly **2 target-specific static benchmark-mapping credits**; it awards no Pass, runtime, browser, ease, final-no-match/NCM, finding, feature-completion, or audit-completion credit.",
        "module-findings status",
    )
    text = replace_line(
        text,
        "| Verified benchmark or final no-match |",
        "| Verified target-specific benchmark mappings / final no-matches or NCMs | 2 / 340 mappings · 0 / 340 final no-matches/NCMs · 338 unresolved | 2 mapping-only; 0 other credit |",
        "module-findings benchmark accounting",
    )
    text = replace_cell_on_line(
        text,
        "| `CAP-FIN-BILLING-INVOICE-LIFECYCLE` |",
        "0 credit; NOT_SELECTED_CURRENT_AUDIT",
        "mapping credit; frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f + Dolibarr/dolibarr@769c7db907099643558e77d7002c109cfda919e5; BigCapital adjacent-only; static comparison only",
        "invoice benchmark cell",
    )
    text = replace_cell_on_line(
        text,
        "| `CAP-FIN-FX-REVALUATION` |",
        "0 credit; NOT_SELECTED_CURRENT_AUDIT",
        "mapping credit; frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f; static comparison only",
        "FX benchmark cell",
    )
    write_lf(relative, text)


def update_native_register() -> None:
    relative = "12-native-build-and-do-not-copy-register.md"
    text = read_lf(relative)
    if "Target-specific feature mappings: **2 / 340**" not in text:
        assert sha256_file(relative) == BASELINE_REPORT_SHA256[relative]
    text = replace_line(text, "- Target-specific feature mappings:", "- Target-specific feature mappings: **2 / 340**. Final no-matches/NCMs: **0 / 340**. Unresolved targets: **338 / 340**.", "native-register totals")
    text = replace_line(text, "Historical labels such as", "Historical labels such as `Native benchmark`, `Reject` or `Separate future decision` in the physical register remain provenance fields and do not by themselves establish a current target edge. The current 2/340 mapping count comes only from RUN-145's exact independently adjudicated target-specific chain.", "native-register provenance boundary")
    text = replace_line(
        text,
        "| `CAP-FIN-BILLING-INVOICE-LIFECYCLE` |",
        "| `CAP-FIN-BILLING-INVOICE-LIFECYCLE` | Finance / H | frappe/erpnext; Dolibarr/dolibarr; bigcapitalhq/bigcapital [ADJACENT_ONLY_NOT_SELECTION_ELIGIBLE] | frappe/erpnext; Dolibarr/dolibarr | Provide an explicit invoice lifecycle from Draft through issue or validation, outstanding and partial or full settlement, overdue handling, cancellation, and governed correction; apply approval and accounting controls; distinguish payment capture from payment request; commit delivery intent before duplicate-safe handoff; audit successful delivery; reverse applicable effects on cancellation; keep paid correction separate; and support auditable payment allocation and finalisation. | NCM_NOT_AUTHORIZED_NO_TARGET_SPECIFIC_CATALOGUE_COMPLETE_SEARCH | 1 | Static canonical identity plus two pinned direct static-source benchmark mappings only. BigCapital remains adjacent. No runtime, browser, executed-test, pass, release, ease, audit-completion, or feature-completion credit. |",
        "native-register invoice row",
    )
    text = replace_line(
        text,
        "| `CAP-FIN-FX-REVALUATION` |",
        "| `CAP-FIN-FX-REVALUATION` | Finance / H | frappe/erpnext | frappe/erpnext | Require canonical ledger context and date before retrieval; distinct journal-write authority; eligible foreign-currency balance-sheet exposure selection; complete rate and gain-or-loss values; residual clearing; bounded rounding allowance; removal and rejection of zero-gain work; no-work feedback; configured gain-or-loss account; attributable balanced linked journals; navigable results; independently reported agreement and reversal state; and duplicate-safe draft reversal handling. | NCM_NOT_AUTHORIZED_NO_TARGET_SPECIFIC_CATALOGUE_COMPLETE_SEARCH | 1 | Static canonical identity plus pinned static-source benchmark mapping only; no runtime, browser, executed-test, pass, release, ease, audit-completion, or feature-completion credit. |",
        "native-register FX row",
    )
    text = replace_line(text, "All 340 rows have", "Exactly 2 / 340 rows have `benchmark_mapping_credit=true`; the other 338 remain false. Final no-match/NCM credit remains 0 / 340. A non-empty candidate or sentinel field is not by itself an approved edge, neutral specification, final no-match, or NCM.", "native-register row credit accounting")
    text = replace_cell_on_line(text, "| `Dolibarr/dolibarr` |", "Strong quote conversion comparison.", "Strong quote conversion comparison. RUN-145: selected only as one of two complementary direct Native benchmarks for CAP-FIN-BILLING-INVOICE-LIFECYCLE at 24.0.0#769c7db907099643558e77d7002c109cfda919e5; no quote, sibling or module-wide inheritance.", "Dolibarr selection note")
    text = replace_cell_on_line(text, "| `frappe/erpnext` |", "Strong GL/AP/tax/asset control comparison. Selected for fresh direct target-specific wave-4 evidence; no sibling or module-wide inheritance.", "Strong GL/AP/tax/asset control comparison. RUN-145: selected directly only for CAP-FIN-FX-REVALUATION and as one of two complementary direct benchmarks for CAP-FIN-BILLING-INVOICE-LIFECYCLE at v16.33.0#b24c9eba551905e256e336ff170a91a92d197a2f; no sibling or module-wide inheritance.", "ERPNext selection note")
    write_lf(relative, text)


def update_unresolved_gaps() -> None:
    relative = "13-unresolved-questions-and-evidence-gaps.md"
    text = read_lf(relative)
    if "2 / 340 target-specific benchmark mappings credited" not in text:
        assert sha256_file(relative) == BASELINE_REPORT_SHA256[relative]

    lines = text.splitlines()
    target_index = next(index for index, line in enumerate(lines) if line.startswith("| Canonical features |"))
    line = lines[target_index]
    line = line.replace("dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390", MATRIX_SHA256)
    line = line.replace("matrix target mapping stays 0/340", "exactly 2/340 targets now have static benchmark-mapping credit; 338 remain unresolved and 0/340 have final no-match/NCM credit")
    lines[target_index] = line
    text = "\n".join(lines) + ("\n" if text.endswith("\n") else "")

    text = replace_line(
        text,
        "| Feature benchmarks |",
        "| Feature benchmarks | 2 / 340 target-specific benchmark mappings credited; 0 / 340 final no-matches/NCMs; 338 unresolved | RUN-145 completes an exact Agent A → B → C → independent Agent D chain plus fresh Pass-8 correction/review. `CAP-FIN-FX-REVALUATION` maps narrowly to pinned ERPNext; `CAP-FIN-BILLING-INVOICE-LIFECYCLE` maps narrowly to pinned ERPNext plus Dolibarr. BigCapital remains adjacent-only and unselected. Matrix SHA-256 is `3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0`; register SHA-256 is `5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884`. No NCM is authorised and runtime, browser, executed-test, ease, release, Pass, finding, feature-completion, and audit-completion credit remain zero. | Preserve the two exact mappings and continue exact-edge or target-specific catalogue-complete final-no-match adjudication across the remaining 338 targets without inheriting credit to siblings, modules, adjacent projects, or uncited editions. |",
        "unresolved feature benchmark row",
    )
    text = replace_line(
        text,
        "| Benchmark project register |",
        "| Benchmark project register | Literal prompt = 98 URL occurrences / 95 unique repositories; physical carry-forward register = those 95 plus three historical extras; observer evidence = 95/95 unique and 98/98 occurrence-weighted; effective observer status = 88 complete observer-only / seven partial blocked; RUN-058A–070 initial formal-attempt subset = 18 unique records / 17 prompt repositories / occurrence weight 18; accepted wave subset = 9/17 eligible prompt records and weight 10/18; accepted global overlay = 9/95 and 10/98; RUN-145 current mapping rows = 2/98 | RUN-031–038 establish observer-only evidence. RUN-066 accepts one direct incident locator and two adjacent, non-promotable classification records; RUN-068 accepts six HR/finance project records—three selected-classification and three exclusion-classification records—plus twelve bounded upstream facet records. Every accepted record passes all 24 controls under a different exact-hash reviewer. RUN-069 retains medication/clinical at zero formal acceptance. At RUN-070 the CSV was byte-identical and no target mapping existed; that remains historical. RUN-145 later changes exactly the ERPNext and Dolibarr register rows, yielding two project rows with current target-specific mapping credit at register SHA-256 `5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884`. BigCapital remains unchanged and uncredited. | Preserve the seven residual observer blockers and the medication/clinical NO-GO packet. Continue neutral comparison and target-specific selection/exclusion or catalogue-complete no-match adjudication across the remaining 338 targets. |",
        "unresolved benchmark register row",
    )
    text = replace_line(
        text,
        "| Agent universe and writer rule |",
        "| Agent universe and writer rule | RUN-001 through RUN-145 represented at the current reporting checkpoint; finalization gate false. | RUN-141/R–143 preserve and report the bounded Site-portfolio ownership checkpoint; RUN-144 verifies its exact dashboard; RUN-145 adds exactly two mapping-only Finance targets through a fresh independent clean-room and adversarial chain. The remaining 338 benchmark targets plus runtime, browser, tests, Pass 8 finalization, and completion remain open. | Complete residual ownership and every semantic/execution/benchmark gate, then dispatch fresh Pass 8/final cross-reviewers, verify the final dashboard, and prove no agent remains live at finalization. |",
        "unresolved agent universe row",
    )
    text = replace_line(
        text,
        "The current `03-feature-to-benchmark-matrix.csv` has",
        "The current `03-feature-to-benchmark-matrix.csv` has 340 canonical static target rows: 300 H and 40 D. RUN-080 historically changed only independently reviewed route-name/page-file fields from `00085d407433307e7f6798c0e8e04629b1746d4bfb1e18024c51ead1dc4f7afd` to `dadc888b5069faf61cc0710418cd875ccbb868d9bfccbe05e55a637d0b64e390`; RUN-082 historically changed 0 rows / 0 cells. RUN-145 later changes exactly 2 rows / 18 benchmark cells to current SHA-256 `3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0`. Current benchmark mapping is 2/340, final no-match/NCM is 0/340, and 338 targets remain unresolved. Retained linkage gaps remain 1 route path, 166 route names, 4 page files, 1 combined, 0 backend anchors, and 8 static test anchors. Runtime, browser, executed-test, ease, release, P2–P8, Pass, finding, feature-completion, and audit-completion credit remain zero.",
        "unresolved current matrix boundary",
    )
    text = replace_line(
        text,
        "The current `06-open-source-benchmark-register.csv` preserves",
        "The current `06-open-source-benchmark-register.csv` preserves all unrelated historical cells and the prior project-triage/blocker-resolution boundaries. RUN-145 changes exactly the ERPNext and Dolibarr rows / 30 cells to current SHA-256 `5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884`; two project rows now carry current target-specific mapping credit. BigCapital remains unchanged, adjacent-only, and uncredited. Earlier observer-only coverage remains 95/95 unique and 98/98 occurrence-weighted, with 88 complete observer-only and seven partial records; it grants no additional target mapping.",
        "unresolved current register boundary",
    )
    text = replace_line(
        text,
        "Bounded formal upstream project-record coverage is now",
        "At the RUN-070 checkpoint, bounded formal upstream project-record coverage was 9/95 prompt repositories and 10/98 occurrence-weighted entries; within the wave's eligible subset it was 9/17 and 10/18. Twelve HR/finance facet records were accepted only at the bounded upstream record layer. Canonical-target benchmark mapping or final documented no-match was 0/340 at that checkpoint, and project/facet selection, neutral requirements, current-product comparison, runtime, browser, executed-test, ease, release, Pass 8, and completion credits were zero. Observer strength, formal project/facet-record acceptance, metadata presence, clean-chain lineage PASS, a comparison rating, or a historical `Native benchmark` outcome must not be read as a promoted current mapping. The then-immediate benchmark gate was to close or explicitly retain the medication/clinical identity/rubric blocker, then complete neutral comparison and exact mapping/exhaustive-no-match adjudication across the then-other 334 frozen targets.",
        "unresolved RUN-070 chronology",
    )

    run_145_paragraph = "RUN-145 then completes a fresh exact static clean-room mapping chain for two Finance targets. The current receipt `evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json` (SHA-256 `8306a8aefe0a490ebf206d0c4716d92930326988f19e0ed495a3c2d0002c7cf9`) records 2/340 mapped targets, 0/340 final no-matches/NCMs, 338 unresolved targets, a matrix SHA-256 of `3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0`, and a benchmark-register SHA-256 of `5a3898855f1ffffb1a493496a57f6532bdc464552e4577c9e7c97f83c9793884`. It grants no runtime, browser, executed-test, ease, release, Pass, final-finding, feature-completion, or audit-completion credit."
    if run_145_paragraph not in text:
        marker = "RUN-072 then executes a fresh, deliberately narrow incident candidate-edge chain"
        lines = text.splitlines()
        matches = [index for index, line in enumerate(lines) if line.startswith(marker)]
        assert len(matches) == 1
        lines.insert(matches[0] + 1, "")
        lines.insert(matches[0] + 2, run_145_paragraph)
        text = "\n".join(lines) + "\n"
    write_lf(relative, text)


def update_findings_json() -> None:
    relative = "findings.json"
    data = json.loads(read_lf(relative))
    if data["counts"]["benchmark_mapped"] != 2:
        assert sha256_file(relative) == BASELINE_REPORT_SHA256[relative]
    data["pins"]["current_matrix_sha256"] = MATRIX_SHA256
    data["pins"]["current_benchmark_register_sha256"] = REGISTER_SHA256
    data["pins"]["run_145_benchmark_mapping_receipt_sha256"] = RUN_145_RECEIPT_SHA256
    data["counts"]["benchmark_mapped"] = 2
    data["counts"]["final_no_match"] = 0
    data["counts"]["benchmark_unresolved"] = 338
    mapping = {
        "run_id": "RUN-145-FINANCE-INVOICE-FX-BENCHMARK-MAPPING-WAVE-24",
        "receipt": "evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json",
        "receipt_sha256": RUN_145_RECEIPT_SHA256,
        "matrix_sha256": MATRIX_SHA256,
        "benchmark_register_sha256": REGISTER_SHA256,
        "mapped_targets": 2,
        "mapped_feature_ids": ["CAP-FIN-BILLING-INVOICE-LIFECYCLE", "CAP-FIN-FX-REVALUATION"],
        "final_no_matches_or_NCMs": 0,
        "unresolved_targets": 338,
        "project_rows_with_current_target_mapping_credit": 2,
        "bigcapital_adjacent_only_unselected": True,
        "non_mapping_credit": {
            "runtime": 0,
            "browser": 0,
            "executed_test": 0,
            "ease": 0,
            "release": 0,
            "pass": 0,
            "final_finding": 0,
            "feature_completion": 0,
            "audit_completion": 0,
        },
    }
    rebuilt: dict[str, object] = {}
    for key, value in data.items():
        rebuilt[key] = value
        if key == "counts":
            rebuilt["current_benchmark_mapping"] = mapping
    write_lf(relative, json.dumps(rebuilt, indent=2, ensure_ascii=False) + "\n")


def write_receipt() -> None:
    receipt = {
        "schema_version": "run_146_finance_benchmark_reporting_wave_24_v1",
        "run_id": RUN_ID,
        "generated_on": "2026-08-27",
        "status": "REPORTING_MATERIALIZED_EXACT_TWO_STATIC_BENCHMARK_MAPPINGS_ZERO_OTHER_CREDIT",
        "architecture_rule": "One operating organisation across multiple Sites; benchmark comparison does not establish Site access, exact permissions, ownership, direct-object concealment, consent, or privacy.",
        "pins": {
            "application_commit": "a0493442b9e392d324055c35bf25b69421dc2d35",
            "application_tree": "f8cdaf81d83c71e4f5d064fdf88872b908ffaaa1",
            "matrix_sha256": MATRIX_SHA256,
            "benchmark_register_sha256": REGISTER_SHA256,
            "run_145_mapping_receipt_sha256": RUN_145_RECEIPT_SHA256,
            "generator_sha256": sha256_file("generators/materialize-run-146-finance-benchmark-reporting-wave-24.py"),
        },
        "baseline_report_sha256": BASELINE_REPORT_SHA256,
        "outputs": {path: {"bytes": (AUDIT_DIR / path).stat().st_size, "sha256": sha256_file(path)} for path in REPORT_PATHS},
        "counts": {
            "canonical_targets": 340,
            "benchmark_mapped": 2,
            "final_no_matches_or_NCMs": 0,
            "unresolved": 338,
            "matrix_rows_changed_by_run_145": 2,
            "matrix_cells_changed_by_run_145": 18,
            "register_rows_changed_by_run_145": 2,
            "register_cells_changed_by_run_145": 30,
        },
        "mapped_feature_ids": ["CAP-FIN-BILLING-INVOICE-LIFECYCLE", "CAP-FIN-FX-REVALUATION"],
        "credited_register_projects": ["Dolibarr/dolibarr", "frappe/erpnext"],
        "preserved": {"bigcapital_adjacent_only_unselected": True, "historical_checkpoint_zero_credit_statements": True},
        "credit_boundary": {
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
        },
    }
    write_lf("evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json", json.dumps(receipt, indent=2, ensure_ascii=False) + "\n")


def main() -> None:
    assert_live_benchmark_state()
    update_executive_summary()
    update_module_findings()
    update_native_register()
    update_unresolved_gaps()
    update_findings_json()
    write_receipt()


if __name__ == "__main__":
    main()
