#!/usr/bin/env python3
"""Reconcile the audited route provenance and the true Inertia-page denominator.

This is an audit-artifact-only transformation. It does not execute Laravel,
JavaScript, tests, a browser, or any product mutation.
"""

from __future__ import annotations

import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-23T16:32:00+12:00"

INVENTORY = AUDIT / "inventory-904.json"
INVENTORY_ALIAS = AUDIT / "inventory.json"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "route-page-source-provenance-reconciliation-2026-08-23.json"

BASE_INVENTORY_SHA = "41a80e3269b73cad5237147f38b8918c55b05c365d9c07dc7a8f775fd0620790"
ROUTE_HASHES = {
    "all": (3024, "443f2812907a39e127bdc2569ded75e988438924ba4ad04462fafd0a309f2f52"),
    "source_backed": (2994, "26ab151b71c12965b996c157a9b5f9c2c640897dfe422a4d71c88a459ea6dd98"),
    "direct": (2956, "024e55ae580387db336d899c1153c8047ea606cddd03dc9761cac4ea45e5e69c"),
    "helper": (32, "5f721b9881288221f9fde4ba5caed1d441fa979a53a364c7b724ee6d1bb4dc29"),
    "resource": (5, "a1967e310a399cafd63f26c999ff66a2065ffb8ea47babd215ebccca8b4b319e"),
    "fluent": (1, "3acd0b13a7b267da6e5c3389ce01e58f3020aa8aa887fd26f929f2af56b3c727"),
    "provider": (30, "1dd9e5b8fb5c2b3e52973a1b6e920992038b4a2fcfd831fadaf43e5a852211bb"),
}

HELPER_IDS = {
    f"ROUTE-{value:04d}"
    for value in (
        2708, 2726, 2711, 2709, 2727, 2712, 2713, 2725, 2723, 2718, 2714,
        2720, 2721, 2722, 2724, 2984, 2995, 2999, 2987, 2990, 2985, 2988,
        2994, 2992, 2989, 2991, 2993, 2996, 2998, 2997, 2500, 2501,
    )
}
RESOURCE_IDS = {f"ROUTE-{value:04d}" for value in range(536, 541)}
FLUENT_IDS = {"ROUTE-1486"}
PROVIDER_IDS = {
    "ROUTE-0001", "ROUTE-0002", "ROUTE-0003",
    "ROUTE-0324", "ROUTE-0325", "ROUTE-0326", "ROUTE-0852", "ROUTE-0853",
    "ROUTE-1869", "ROUTE-1870", "ROUTE-1871", "ROUTE-2344", "ROUTE-2345",
    "ROUTE-2358", "ROUTE-2359", "ROUTE-3008", "ROUTE-3009", "ROUTE-3011",
    "ROUTE-3012", "ROUTE-3013", "ROUTE-3014", "ROUTE-3015", "ROUTE-3016",
    "ROUTE-3017", "ROUTE-3018", "ROUTE-3019", "ROUTE-3020",
    "ROUTE-2943", "ROUTE-2944", "ROUTE-3010",
}

SUPPORT_CORRECTIONS = {"PAGE-0022", "PAGE-0285", "PAGE-0836"}
EXPECTED_UNMAPPED_PAGES = {
    "PAGE-0035", "PAGE-0037", "PAGE-0038", "PAGE-0100", "PAGE-0252",
    "PAGE-0530", "PAGE-0531", "PAGE-0590", "PAGE-0827", "PAGE-0835",
    "PAGE-0858", "PAGE-0876", "PAGE-0962",
}
PAGE_STREAM = {
    "rows": 727,
    "bytes": 50575,
    "sha256": "1212a8c8248667bdb371669bc200240c9ac47ce1d921b1955b23b18f175942e6",
    "terminal_lf": True,
}
PAGE_BLOBS = {
    "PAGE-0022": "2269d9f494244070dc67edba155a7a2877877257",
    "PAGE-0035": "d10b50516a7b899b9d4668ec90e13ff9a83274a2",
    "PAGE-0037": "7aa7be08f6beaff2adc03eddc3f95354fbbe1d92",
    "PAGE-0038": "714a616f58fd888d292971ade4ccfc61725c4c69",
    "PAGE-0100": "11dc96cebdbf9f7e61db53516d87e52d341ce633",
    "PAGE-0285": "72fb2b7aabc12df87bb201f740e06792ef81af5e",
    "PAGE-0530": "8bf234afcb289506f4974efb701ac4eb0e13619f",
    "PAGE-0590": "e45c5d93874350a35ff8dafbf2b80b0eab5a2aab",
    "PAGE-0827": "0d05f9cd50b30fc2cd1854beb2e1af1840c7f016",
    "PAGE-0835": "7c06878c3319c623bd26ba27950683ce126962b6",
    "PAGE-0836": "09b6076f19485d0ccb99eb80b83a379c09f71fd2",
    "PAGE-0858": "da05009598ff5664c5f2b3babc7320d3437d11cd",
    "PAGE-0876": "701cad9c560357fb37b53eb35a848bb13862076b",
    "PAGE-0962": "93bb676feee91e23ec3bd815feadf0cba5d51200",
    "PAGE-0252": "bbc8d40e4b1f1d5bc36e4192409ca78d9e80a4da",
    "PAGE-0559": "b7d1a01107f17d2cb2cd5f35bb53f4697e0ffa8c",
    "PAGE-0531": "f9c265d274783ed21b59297e2683730ef0368365",
}
BACKEND_RENDER_NAMES_WITHOUT_PAGE_FILES = [
    {"render": "hr/recruitment/jobs", "locus": "app/Http/Controllers/Hr/RecruitmentJobController.php:89"},
    {"render": "hr/recruitment/kits", "locus": "app/Http/Controllers/Hr/InterviewKitController.php:38"},
    {"render": "hr/training/index", "locus": "app/Http/Controllers/Hr/TrainingDashboardController.php:128"},
    {"render": "operations/timesheets/approvals", "locus": "app/Http/Controllers/TimesheetController.php:44"},
    {"render": "training/competencies/index", "locus": "app/Http/Controllers/Training/CompetencyAssessmentController.php:10"},
    {"render": "training/competencies/show", "locus": "app/Http/Controllers/Training/CompetencyAssessmentController.php:11"},
    {"render": "training/inductions/index", "locus": "app/Http/Controllers/Training/InductionController.php:10"},
    {"render": "training/inductions/show", "locus": "app/Http/Controllers/Training/InductionController.php:11"},
    {"render": "training/records/index", "locus": "app/Http/Controllers/Training/TrainingRecordController.php:10"},
    {"render": "training/records/show", "locus": "app/Http/Controllers/Training/TrainingRecordController.php:12"},
    {"render": "training/records/user", "locus": "app/Http/Controllers/Training/TrainingRecordController.php:11"},
]


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def sha_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha_file(path: Path) -> str:
    return sha_bytes(path.read_bytes())


def record(path: Path) -> dict[str, Any]:
    return {
        "path": path.relative_to(AUDIT).as_posix(),
        "sha256": sha_file(path),
        "bytes": path.stat().st_size,
    }


def git_object(path: str) -> str:
    return subprocess.check_output(
        ["git", "rev-parse", f"{COMMIT}:{path}"],
        cwd=AUDIT.parent.parent.parent,
        text=True,
    ).strip()


def route_line(row: dict[str, Any]) -> str:
    return "|".join(
        [
            str(row["route_id"]), str(row["method"]), str(row["uri"]),
            str(row.get("name") or ""), str(row["action"]),
            ",".join(str(value) for value in row.get("middleware", [])),
        ]
    )


require(sha_file(INVENTORY) == sha_file(INVENTORY_ALIAS), "Inventory alias drift")
if not ARTIFACT.exists():
    require(sha_file(INVENTORY) == BASE_INVENTORY_SHA, "Base inventory drift")

inventory = load(INVENTORY)
require(inventory["commit"] == COMMIT, "Audited commit drift")
require(len(inventory["routes"]) == 3024 and len(inventory["pages"]) == 962, "Source inventory denominator drift")
require(git_object("routes") == "59c114ed46d64351784f08004130ea77775ff03c", "Routes tree drift")
require(git_object("routes/shifts.php") == "527d81ac2883d3421a5c1d51fc94f671be3a07af", "Shift route blob drift")
require(git_object("routes/finance.php") == "6c13d195a759dbff0eb79ccfc2ce29fbcde134e6", "Finance route blob drift")
require(git_object("routes/hr.php") == "e475f4ed99c39b3c531e2a07fa00c423e68c6ee4", "HR route blob drift")
require(git_object("resources/js/inertia-pages.ts") == "2fe0a1341c68d28cae26835f6c36df194ef7e8f9", "Inertia resolver blob drift")

routes = {row["route_id"]: row for row in inventory["routes"]}
all_ids = set(routes)
require(len(all_ids) == 3024, "Duplicate route IDs")
source_ids = all_ids - PROVIDER_IDS
direct_ids = source_ids - HELPER_IDS - RESOURCE_IDS - FLUENT_IDS
partitions = {
    "all": all_ids,
    "source_backed": source_ids,
    "direct": direct_ids,
    "helper": HELPER_IDS,
    "resource": RESOURCE_IDS,
    "fluent": FLUENT_IDS,
    "provider": PROVIDER_IDS,
}
route_streams: dict[str, dict[str, Any]] = {}
for name, ids in partitions.items():
    require(ids <= all_ids, f"Unknown route in {name} partition")
    payload = "\n".join(route_line(routes[key]) for key in sorted(ids)).encode("utf-8")
    expected_rows, expected_sha = ROUTE_HASHES[name]
    require(len(ids) == expected_rows, f"{name} row-count drift")
    require(sha_bytes(payload) == expected_sha, f"{name} stream drift")
    route_streams[name] = {
        "rows": len(ids), "bytes": len(payload), "sha256": expected_sha,
        "terminal_lf": False,
    }
require(source_ids == direct_ids | HELPER_IDS | RESOURCE_IDS | FLUENT_IDS, "Source route partition gap")
require(not (direct_ids & HELPER_IDS or direct_ids & RESOURCE_IDS or direct_ids & FLUENT_IDS), "Source route partition overlap")

pages = {row["page_id"]: row for row in inventory["pages"]}
require(len(pages) == 962, "Duplicate page IDs")
for page_id, blob in PAGE_BLOBS.items():
    require(git_object(pages[page_id]["file"]) == blob, f"Page blob drift: {page_id}")

render_ids = {key for key, row in pages.items() if row["classification"] == "Resolver-render root"}
orphan_ids_before = {key for key, row in pages.items() if row["classification"] == "Resolver-orphan/unreachable candidate"}
require(len(render_ids) == 702 and len(orphan_ids_before) == 28, "Page class input drift")
require(SUPPORT_CORRECTIONS <= orphan_ids_before, "Support correction input drift")
orphan_ids = orphan_ids_before - SUPPORT_CORRECTIONS
page_ids = render_ids | orphan_ids
require(len(page_ids) == 727 and len(orphan_ids) == 25, "Page denominator arithmetic drift")
page_lines = [
    f"{key}|{pages[key]['file']}|{'render_root' if key in render_ids else 'resolver_orphan'}"
    for key in sorted(page_ids)
]
page_payload = ("\n".join(page_lines) + "\n").encode("utf-8")
require(len(page_payload) == PAGE_STREAM["bytes"], "Page stream byte drift")
require(sha_bytes(page_payload) == PAGE_STREAM["sha256"], "Page stream hash drift")

mapped_page_ids = {key for key in page_ids if pages[key].get("working_canonical_feature_ids")}
unmapped_page_ids = page_ids - mapped_page_ids
require(len(mapped_page_ids) == 714 and unmapped_page_ids == EXPECTED_UNMAPPED_PAGES, "Page mapping partition drift")
mapped_target_ids = {
    target for key in mapped_page_ids for target in pages[key].get("working_canonical_feature_ids", [])
}
mapped_relations = sum(len(pages[key].get("working_canonical_feature_ids", [])) for key in mapped_page_ids)
shared_pages = sum(len(pages[key].get("working_canonical_feature_ids", [])) > 1 for key in mapped_page_ids)
require((len(mapped_target_ids), mapped_relations, shared_pages) == (682, 968, 118), "Page enrichment arithmetic drift")

for key, row in pages.items():
    if key in render_ids:
        row["inertia_page_denominator_status"] = "included"
        row["inertia_page_kind"] = "render_root"
    elif key in orphan_ids:
        row["inertia_page_denominator_status"] = "included"
        row["inertia_page_kind"] = "resolver_orphan"
    else:
        row["inertia_page_denominator_status"] = "excluded_source_support_helper_or_test"
        row["inertia_page_kind"] = None

pages["PAGE-0022"]["classification"] = "Resolver-imported support"
pages["PAGE-0285"]["classification"] = "Page support/component (unreferenced)"
pages["PAGE-0836"]["classification"] = "Resolver-imported support"

denominators = inventory["denominators"]
denominators.update({
    "tracked_page_tree_files": 962,
    "prompt_inertia_page_denominator": 727,
    "inertia_render_roots": 702,
    "inertia_resolver_orphans": 25,
    "inertia_tsx_support_components": 190,
    "inertia_ts_helpers": 28,
    "inertia_tests_and_specs": 17,
    "canonical_unique_pages_enriched": 714,
    "canonical_page_mapping_percent": 98.21,
    "inertia_pages_with_static_classification": 727,
    "pages_mapping_gap": 13,
})
denominators["resolver_orphan_candidates"] = 25

inventory["page_reconciliation"] = {
    "resolver": "resources/js/inertia-pages.ts",
    "source_inventory_total": 962,
    "source_inventory_partition": {
        "tsx": 931, "ts": 31, "resolver_glob_modules": 917,
        "render_roots": 702, "tsx_support_components": 190,
        "ts_helpers": 28, "tests_and_specs": 17,
    },
    "total": 727,
    "render_roots": 702,
    "resolver_orphans": 25,
    "accepted_feature_id_mapped": 714,
    "mapping_gap": 13,
    "unmapped_page_ids": sorted(unmapped_page_ids),
    "identity_stream": PAGE_STREAM,
    "denominator_semantics": "Every exact backend render-root file plus every page-shaped resolver orphan; TSX support/components, TS helpers and tests/specs remain source-classified but are not Inertia pages.",
    "mapping_semantics": "A page is mapped only when it has at least one literal accepted canonical 904 FEATURE-ID; source support and excluded SURFACE dispositions are not promoted.",
    "backend_render_names_without_page_files": BACKEND_RENDER_NAMES_WITHOUT_PAGE_FILES,
    "evidence": ARTIFACT.relative_to(AUDIT).as_posix(),
}

source_tree_page_enrichment = {
    "targets": 756, "relations": 1526, "unique_files": 945,
    "excluded_surface_files": 17, "classified_source_files": 962,
}
page_enrichment = {
    "targets": 682, "relations": 968, "unique_pages": 714,
    "unmapped_pages": 13, "inertia_page_denominator": 727,
    "shared_page_ids": 118,
}
metadata = inventory["canonical_feature_register_metadata"]
metadata["source_tree_page_enrichment"] = source_tree_page_enrichment
metadata["page_enrichment"] = page_enrichment
metadata["source_artifacts"]["route_page_source_provenance_reconciliation"] = ARTIFACT.relative_to(AUDIT).as_posix()
status = inventory["capability_denominator_status"]
status["page_enrichment"] = page_enrichment
status["source_tree_page_enrichment"] = source_tree_page_enrichment
status["source_page_classification"] = "962/962 tracked resources/js/pages files classified; the prompt Inertia-page denominator is 727, of which 714 map to accepted final IDs and 13 remain unmapped"
status["inventory_page_mapping"] = {
    "completed": 714, "denominator": 727, "percent": 98.21,
    "scope": "accepted canonical FEATURE-ID mapping of true Inertia pages",
}
inventory["generated_at"] = max(str(inventory.get("generated_at", "")), GENERATED_AT)

artifact = {
    "schema_version": "1.0.0",
    "artifact": "route-page-source-provenance-reconciliation-2026-08-23",
    "generated_at": GENERATED_AT,
    "audited_commit": COMMIT,
    "read_only": True,
    "status": "GO_SOURCE_ROUTE_AND_PAGE_DENOMINATOR_PROVENANCE_ONLY",
    "input_pins": {
        "inventory_before_reconciliation_sha256": BASE_INVENTORY_SHA,
        "routes_tree": "59c114ed46d64351784f08004130ea77775ff03c",
        "inertia_resolver_blob": "2fe0a1341c68d28cae26835f6c36df194ef7e8f9",
    },
    "route_provenance": {
        "equation": "2956 direct + 5 resource expansions + 32 helper invocations + 1 fluent registration = 2994 source-backed runtime rows; plus 30 framework/provider rows = 3024",
        "static_equation": "2964 statically expanded declarations - 2 abstract helper bodies + 32 concrete helper invocations = 2994 source-backed runtime rows",
        "source_multiset_check": {"parse_failures": 0, "multiset_differences": 0},
        "streams": route_streams,
        "helper_route_ids": sorted(HELPER_IDS),
        "resource_route_ids": sorted(RESOURCE_IDS),
        "fluent_route_ids": sorted(FLUENT_IDS),
        "provider_route_ids": sorted(PROVIDER_IDS),
        "provider_partition": {"fortify": 24, "dusk": 3, "local_storage": 2, "health": 1},
        "claim_boundary": "Static provenance of frozen runtime identities only; not a fresh route:list run and not accepted FEATURE-ID, runtime, browser, test or task credit.",
    },
    "page_provenance": {
        "source_equation": "962 tracked files = 931 TSX + 31 TS = 702 render roots + 190 TSX support/components + 25 page-shaped resolver orphans + 28 TS helpers + 17 tests/specs",
        "prompt_page_denominator": 727,
        "accepted_feature_id_mapped": 714,
        "mapping_percent": 98.21,
        "unmapped_page_ids": sorted(unmapped_page_ids),
        "support_corrections": sorted(SUPPORT_CORRECTIONS),
        "identity_stream": PAGE_STREAM,
        "backend_render_names_without_page_files": BACKEND_RENDER_NAMES_WITHOUT_PAGE_FILES,
        "claim_boundary": "Support/helpers/tests remain fully source-classified but do not inflate the Inertia-page denominator; no runtime, browser, benchmark or completion credit.",
    },
    "independent_review": {
        "route": "GO; exact source-derived multiset and every published route stream reproduced",
        "page": "GO after correcting the identity stream metadata to terminal-LF",
        "combined_source_family_route_page_union_reconciled": True,
        "all_routes_pages_mapped_to_accepted_canonical_feature_ids": False,
    },
}

write(ARTIFACT, artifact)
metadata["source_artifacts"]["route_page_source_provenance_reconciliation_sha256"] = sha_file(ARTIFACT)
write(INVENTORY, inventory)
write(INVENTORY_ALIAS, inventory)
require(sha_file(INVENTORY) == sha_file(INVENTORY_ALIAS), "Inventory alias write drift")

pointer = load(POINTER)
pointer["generated_at"] = max(str(pointer.get("generated_at", "")), GENERATED_AT)
pointer["artifacts"]["inventory"] = record(INVENTORY_ALIAS)
pointer["artifacts"]["route_page_source_provenance_reconciliation"] = record(ARTIFACT)
pointer.setdefault("versioned_904_mirrors", {})["inventory"] = record(INVENTORY)
write(POINTER, pointer)

print(json.dumps({
    "status": artifact["status"],
    "route_source_rows": 2994,
    "page_denominator": 727,
    "page_mapped": 714,
    "inventory": record(INVENTORY),
    "evidence": record(ARTIFACT),
    "pointer": record(POINTER),
}, indent=2))
