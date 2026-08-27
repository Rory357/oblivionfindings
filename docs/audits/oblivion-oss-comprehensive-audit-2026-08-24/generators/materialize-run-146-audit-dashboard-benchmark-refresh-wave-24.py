from __future__ import annotations

import runpy
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
BUILDER = AUDIT_DIR / "generators/build-current-audit-dashboard.py"


def replace_once(text: str, old: str, new: str, label: str) -> str:
    old_count = text.count(old)
    new_count = text.count(new)
    if new_count == 1:
        return text
    if old_count == 1 and new_count == 0:
        return text.replace(old, new, 1)
    raise AssertionError(f"{label}: expected one baseline or current fragment; old={old_count}, current={new_count}")


def main() -> None:
    raw = BUILDER.read_bytes()
    assert b"\r\n" not in raw
    text = raw.decode("utf-8")

    replacements = [
        (
            '.footer{color:var(--muted);font-size:.88rem;margin-top:24px}',
            '.footer{color:var(--muted);font-size:.88rem;margin-top:24px;overflow-wrap:anywhere}',
            "mobile footer overflow boundary",
        ),
        (
            '<a href="#checkpoint">RUN-143</a>',
            '<a href="#checkpoint">RUN-146</a>',
            "navigation checkpoint label",
        ),
        (
            'The live matrix is unchanged at <span class="mono">$route_page_matrix_short</span>, mapping remains 0/340, and current-source framework reachability, runtime, browser, build, rendered visual, executed-test, benchmark, ease, release, Pass, and audit-completion credit remain zero.',
            'RUN-145 changes exactly two benchmark rows / 18 cells after the historical RUN-080 linkage checkpoint: the live matrix is <span class="mono">$live_matrix_short</span>, target-specific mapping is $benchmark_mapped/340, final no-match/NCM is $final_no_matches/340, and $benchmark_unresolved targets remain unresolved. Current-source framework reachability, runtime, browser, build, rendered visual, executed-test, ease, release, Pass, final-finding, feature-completion, and audit-completion credit remain zero.',
            "primary notice current benchmark boundary",
        ),
        (
            '<strong>RUN-071–143 current reporting checkpoint:</strong>',
            '<strong>RUN-071–146 current reporting checkpoint:</strong>',
            "secondary notice run range",
        ),
        (
            'The matrix changes 0 rows / 0 cells and retains $route_gap_count route-path, $route_name_gap_count route-name, $page_gap_count page-file, $both_gap_count combined route/page, $backend_gap_count backend-anchor, and $test_gap_count test-anchor gaps.',
            'RUN-082 historically changed 0 rows / 0 cells at its candidate-only checkpoint; the current matrix retains $route_gap_count route-path, $route_name_gap_count route-name, $page_gap_count page-file, $both_gap_count combined route/page, $backend_gap_count backend-anchor, and $test_gap_count test-anchor gaps.',
            "secondary notice matrix chronology",
        ),
        (
            'RUN-143 reports only that bounded delta. The framework-expanded denominator, residual ownership, and full route/page/backend crosswalk remain open. Every execution, benchmark, Pass, finding, and completion credit remains zero.',
            'RUN-143 reports only that bounded ownership delta; RUN-144 verifies its exact audit dashboard; RUN-145 adds exactly two independently adjudicated static benchmark mappings; and RUN-146 materializes the current reporting. The framework-expanded denominator, residual ownership, full route/page/backend crosswalk, and 338 benchmark targets remain open. Runtime, application-browser, executed-test, ease, release, Pass, final-finding, feature-completion, and audit-completion credit remain zero.',
            "secondary notice current wave",
        ),
        (
            '<h2>RUN-071–143 completion-gate checkpoint</h2>',
            '<h2>RUN-071–146 completion-gate checkpoint</h2>',
            "checkpoint heading",
        ),
        (
            'and RUN-143 refreshes current reporting. Static relation, structural classification, registration, public/login, or audit-dashboard artifacts are not measured task, framework reachability, attributable application browser, runtime, mapping, Pass, final-finding, or completion evidence.',
            'RUN-143 refreshes that ownership reporting, RUN-144 verifies the exact dashboard artifact, RUN-145 completes the bounded two-target Finance benchmark chain, and RUN-146 refreshes current reporting. Static relation, structural classification, registration, public/login, or audit-dashboard artifacts are not measured task, framework reachability, attributable application browser, runtime, Pass, final-finding, feature-completion, or completion evidence; only the exact two RUN-145 static benchmark mappings receive mapping credit.',
            "checkpoint current wave paragraph",
        ),
        (
            '<tr><td>RUN-143 reporting refresh</td><td><strong>Site-portfolio API route/action overlay reported</strong></td><td class="partial">audit-only materialization · matrix byte-identical · fresh RUN-144 verification required</td></tr>',
            '<tr><td>RUN-143 reporting refresh</td><td><strong>Site-portfolio API route/action overlay reported</strong></td><td class="partial">audit-only historical checkpoint · matrix then byte-identical</td></tr><tr><td>RUN-144 audit-dashboard verification</td><td><strong>4/4 required viewports · 23/23 visible checks · 10/10 navigation</strong></td><td class="partial">exact superseded dashboard artifact only · zero application credit</td></tr><tr><td>RUN-145 Finance benchmark chain</td><td><strong>$benchmark_mapped/340 mapped · $final_no_matches/340 NCM · $benchmark_unresolved unresolved</strong></td><td class="partial">two exact static target mappings only · matrix $live_matrix_short · register $live_register_short</td></tr><tr><td>RUN-146 reporting/dashboard refresh</td><td><strong>current matrix, register, reports and evidence reconciled</strong></td><td class="partial">fresh RUN-147 audit-dashboard verification required · zero application credit</td></tr>',
            "checkpoint table current rows",
        ),
        (
            'RUN-001 through RUN-143 are represented by audit artifacts; none grants current-source application runtime, signed-in browser, executed-test, benchmark-mapping, or completion credit.',
            'RUN-001 through RUN-146 are represented by audit artifacts. RUN-145 grants exactly two target-specific static benchmark-mapping credits; no represented wave grants current-source application runtime, signed-in browser, executed-test, ease, release, Pass, final-finding, feature-completion, or audit-completion credit.',
            "evidence waves current range",
        ),
        (
            '<li>RUN-143: deterministic Site-portfolio API reporting refresh · matrix and every Site/permission/privacy/direct-object/query/projection/period/allocation/reversal/utility-sign/minimization/lifecycle/concurrency/event/durability/execution/benchmark/Pass/finding/completion boundary unchanged</li>',
            '<li>RUN-143: deterministic Site-portfolio API reporting refresh · historical matrix then byte-identical · every correctness/execution boundary unchanged</li><li>RUN-144: exact RUN-143 audit-dashboard artifact verified at 4/4 required viewports · 23/23 visible checks · 10/10 navigation · zero application credit</li><li>RUN-145: fresh Agent A → B → C → independent Agent D plus Pass-8 correction/review · exactly two Finance target mappings · 0 final no-match/NCM · 338 unresolved · BigCapital adjacent-only</li><li>RUN-146: deterministic current reporting and dashboard refresh · current matrix $live_matrix_short · register $live_register_short · receipt $run145_receipt_short · every non-mapping credit zero</li>',
            "evidence waves RUN-143 through RUN-146",
        ),
        (
            '<li><span class="zero">0</span> benchmark mappings promoted</li>',
            '<li><span class="partial">$benchmark_mapped</span> target-specific static benchmark mappings promoted</li>',
            "execution-credit benchmark count",
        ),
        (
            'and RUN-143 refreshes reporting. Rendered coverage, schema truth, runtime, benchmark, ease, release, and completion gates remain open.',
            'RUN-143 refreshes ownership reporting, RUN-144 verifies that exact dashboard, RUN-145 adds two static benchmark mappings, and RUN-146 refreshes current reporting. Rendered coverage, schema truth, runtime, the other $benchmark_unresolved benchmark targets, ease, release, and completion gates remain open.',
            "static census current range",
        ),
        (
            'zero correctness credit · Gate 4 incomplete · matrix unchanged',
            'zero correctness credit · Gate 4 incomplete · ownership/linkage fields unchanged by RUN-145 benchmark-only mapping',
            "ownership row matrix boundary",
        ),
        (
            'Formal project/facet-record acceptance is not project or facet selection, a target mapping, or an exhaustive final no-match. All 340 target mappings or final no-matches remain open.',
            'Formal project/facet-record acceptance is not by itself project or facet selection, a target mapping, or an exhaustive final no-match. RUN-145 separately maps exactly two Finance targets through a fresh clean-room chain; final no-match/NCM remains 0/340 and $benchmark_unresolved targets remain open.',
            "benchmark section current paragraph",
        ),
        (
            '<tr><td>Promoted feature mappings or final no-matches</td><td>$promoted_count</td><td class="zero">$facet_edges formal edges · $facet_final_no_matches final no-matches · 0 / 340 credited</td></tr>',
            '<tr><td>Current target-specific mappings / final no-matches</td><td>$benchmark_mapped / $final_no_matches</td><td class="partial">$benchmark_mapped / 340 static mapping-only · $final_no_matches / 340 final no-match/NCM · $benchmark_unresolved unresolved</td></tr>',
            "benchmark current credit row",
        ),
        (
            '<li>Full current project behaviour/licence/edition triage and one final mapping or documented no-match per frozen feature</li>',
            '<li>Complete exact clean-room target mapping or catalogue-complete final no-match/NCM adjudication for the remaining $benchmark_unresolved frozen targets</li>',
            "gaps benchmark work",
        ),
        (
            'no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to RUN-143.',
            'no prior viewport, overflow, navigation, table, link, anchor, or console proof transfers to the current RUN-146 dashboard.',
            "prior dashboard transfer boundary",
        ),
        (
            'and RUN-140 responsive verification are immutable history',
            'RUN-140, and RUN-144 responsive verification are immutable history',
            "prior dashboard list range",
        ),
        (
            '<li><a href="evidence/browser/current-audit-dashboard-verification-run-140-wave-22.json">Superseded RUN-140 verification GO</a></li>',
            '<li><a href="evidence/browser/current-audit-dashboard-verification-run-140-wave-22.json">Superseded RUN-140 verification GO</a></li><li><a href="evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json">Superseded RUN-144 verification GO</a></li>',
            "prior dashboard RUN-144 link",
        ),
        (
            '<section class="panel"><h2>Fresh RUN-144 audit-dashboard verification</h2><p>The exact regenerated RUN-143 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-144 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 662/305/357 ownership, one finance.api.sites.overview JSON route/action owner and one bridge, 64/242/50 route/page/overlap feature sets, 93 cumulative bridges, route 3,218=305+12+5+2,896 with seven tagged gaps, page 711=357+9+345 with one tagged gap, queue 507=116+391 with 116=94+10+5+0+7 and 413 without ownership, 3,267 residual records, 24 expansion files (six existing plus 18 new), one locus correction, 17 assurance mapping inputs, nine action plus three shared findings with zero final-finding credit, page owner PAGE-ROOT-FC2C5F5706FD9066 / RUN086-PAGE-MAP-0313, sibling RUN090-ROUTE-0041 / RUN077-ROUTE-0418, three page-path callers, zero exact selected-API frontend callers, excluded neighbor index 79, next pending index 80, one operating organisation across multiple Sites, Gate 4 open, mapping 0/340, and all zero-credit boundaries. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-144-wave-23.json">RUN-144 responsive audit-dashboard verification receipt</a></li></ul></section>',
            '<section class="panel"><h2>Fresh RUN-147 audit-dashboard verification required</h2><p>The exact regenerated RUN-146 dashboard must be checked at 1440×900, 1280×800, 1024×768, and 390×844. The linked RUN-147 receipt must record page overflow, bounded mobile table scrolling, navigation, local links, anchors, duplicate authored IDs, console output, visible 662/305/357 ownership, current $benchmark_mapped/340 benchmark mapping, $final_no_matches/340 final no-match/NCM, $benchmark_unresolved unresolved targets, exact RUN-145 matrix/register/receipt pins, one operating organisation across multiple Sites, Gate 4 open, and every non-mapping zero-credit boundary. It verifies the audit artifact only and grants no application-browser, responsive-application, visual, workflow, finding, runtime, test, release, Pass, feature-completion, or audit-complete credit.</p><ul class="list"><li><a href="evidence/browser/current-audit-dashboard-verification-run-147-wave-24.json">RUN-147 responsive audit-dashboard verification receipt</a> (forward reference until materialized)</li></ul></section>',
            "fresh dashboard verification section",
        ),
        (
            '<section class="panel"><h2>RUN-071–143 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–143 source/reporting artifact is linked with its exact SHA-256.',
            '<section class="panel"><h2>RUN-071–146 evidence lineage</h2><p>Every current raw, generated, reviewed, and integrated RUN-077–146 source/reporting/benchmark artifact is linked with its exact SHA-256.',
            "evidence lineage heading",
        ),
        (
            '<section class="panel"><h2>Formal upstream evidence</h2>',
            '<section class="panel"><h2>RUN-145 current benchmark mapping</h2><p>Exactly two Finance targets have independently adjudicated static mapping credit. Current state is $benchmark_mapped/340 static mapping-only, $final_no_matches/340 final no-match/NCM, and $benchmark_unresolved unresolved. The current matrix is <span class="mono">$live_matrix_sha256</span>, the current register is <span class="mono">$live_register_sha256</span>, and the mapping receipt is <span class="mono">$run145_receipt_sha256</span>.</p><ul class="list"><li><strong>Selected:</strong> <span class="mono">CAP-FIN-FX-REVALUATION</span> maps to <span class="mono">frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f</span>.</li><li><strong>Selected:</strong> <span class="mono">CAP-FIN-BILLING-INVOICE-LIFECYCLE</span> maps to <span class="mono">frappe/erpnext@b24c9eba551905e256e336ff170a91a92d197a2f</span> and <span class="mono">Dolibarr/dolibarr@769c7db907099643558e77d7002c109cfda919e5</span>.</li><li><strong>BigCapital boundary:</strong> <span class="mono">bigcapitalhq/bigcapital@41033239e0f93e4fc6cf1832743ae6bdbab25306</span> remains adjacent-only and unselected for <span class="mono">CAP-FIN-BILLING-INVOICE-LIFECYCLE</span>; its register row remains unchanged and receives zero mapping credit.</li><li><a href="evidence/benchmark/current-run-145-finance-invoice-fx-benchmark-mapping-wave-24.json">RUN-145 mapping receipt</a></li><li><a href="evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json">RUN-146 reporting receipt</a></li><li><a href="03-feature-to-benchmark-matrix.csv">Current 340-row matrix</a></li><li><a href="06-open-source-benchmark-register.csv">Current 98-row project register</a></li></ul></section>\n    <section class="panel"><h2>Formal upstream evidence</h2>',
            "RUN-145 current evidence section",
        ),
        (
            '<p class="footer">Generated deterministically from independently reviewed static evidence through RUN-142/R and reported in RUN-143. The matrix is unchanged; audit artifacts only and no application remediation is authorised.</p>',
            '<p class="footer">Generated deterministically from independently reviewed static evidence through RUN-145 and reported in RUN-146. Exactly two matrix rows have static benchmark-mapping credit; all other application/runtime/browser/test/Pass/finding/completion boundaries remain unchanged. Audit artifacts only; no application remediation is authorised.</p>',
            "dashboard footer",
        ),
        (
            'route_page_matrix_short=route_page_integration["matrix"]["updated_sha256"][:16],',
            'route_page_matrix_short=route_page_integration["matrix"]["updated_sha256"][:16],\n    live_matrix_short=CURRENT_RUN_145_MATRIX_SHA256[:16],\n    live_register_short=CURRENT_RUN_145_REGISTER_SHA256[:16],\n    run145_receipt_short=CURRENT_RUN_145_RECEIPT_SHA256[:16],\n    live_matrix_sha256=CURRENT_RUN_145_MATRIX_SHA256,\n    live_register_sha256=CURRENT_RUN_145_REGISTER_SHA256,\n    run145_receipt_sha256=CURRENT_RUN_145_RECEIPT_SHA256,\n    benchmark_mapped=len(live_mapping_rows),\n    benchmark_unresolved=len(live_unresolved_rows),\n    final_no_matches=0,',
            "current benchmark template substitutions",
        ),
        (
            'temporary_path = output_path.with_name(f".{output_path.name}.tmp-run143-dashboard")',
            'temporary_path = output_path.with_name(f".{output_path.name}.tmp-run146-dashboard")',
            "dashboard temporary path",
        ),
        (
            '("RUN-146 finance benchmark reporting receipt", "evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json"),',
            '("RUN-146 finance benchmark reporting receipt", "evidence/source/current-run-146-finance-benchmark-reporting-wave-24.json"),\n    ("RUN-146 audit-dashboard benchmark refresh materializer", "generators/materialize-run-146-audit-dashboard-benchmark-refresh-wave-24.py"),',
            "dashboard refresh evidence link",
        ),
    ]

    for old, new, label in replacements:
        text = replace_once(text, old, new, label)

    with BUILDER.open("w", encoding="utf-8", newline="\n") as handle:
        handle.write(text)
    runpy.run_path(str(BUILDER), run_name="__main__")


if __name__ == "__main__":
    main()
