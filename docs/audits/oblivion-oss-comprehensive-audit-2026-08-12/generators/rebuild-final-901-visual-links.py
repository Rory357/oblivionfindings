#!/usr/bin/env python3
"""Reconcile retained visual observations to the corrected 901-target register.

This preserves every observation and classification. It assigns a final feature
only when exact identity, one-to-one source lineage, or exact target route/page
evidence proves a unique target. Shared envelopes remain unresolved.
"""

from __future__ import annotations

import csv
import hashlib
import json
import re
from collections import Counter, defaultdict
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
REPO = AUDIT.parents[2]
MATRIX = AUDIT / "05-browser-visual-coverage-matrix.csv"
MANIFEST = SOURCE / "working-capability-manifest-901.json"
INVENTORY = AUDIT / "inventory.json"
SUMMARY = SOURCE / "final-901-visual-link-generation-summary.json"

EXPECTED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
EXPECTED_MATRIX_SHA = "92d12eedaa2e7593cc1ad0bc829f22d57aed36ded55ccfca8d919865f407e1a6"
EXPECTED_PRE_NORMALIZATION_MATRIX_SHA = "ea5df8257d5c6e3fa0c816aecc7e4642ed0ee6f13c4a3f39dfa0f8c8a7b3596b"
EXPECTED_PRE_ENRICHMENT_RECONCILED_MATRIX_SHA = "9eefb83821f12baee89d5721ea7c3318c03f35b6ffc53d0b597b1c466f4c4f0c"
EXPECTED_RECONCILED_MATRIX_SHA = "bda2192ff8a9d9aa1ea07acf83230efa4a4cd9edc3f08591dbc5f4c3fd739896"
EXPECTED_PRE_GLOBAL_EXACT_MATRIX_SHA = "d8aaf715968a3d974fd90cb8fac3365f0a18f3e575c2787d5098dc9771de4dcf"
EXPECTED_PRE_SPLIT_GLOBAL_MATRIX_SHA = "807ec6a27494b0e911e02e3989a2e26777eba6fd02fc88c6efa158511293bcd7"
EXPECTED_FINAL_901_MATRIX_SHA = "6e3dd441d97c77dad44adc6f20d370f2d7e849c65deb746f31bd309b4302de60"
EXPECTED_MATERIAL_ROUTE_WAVE_MATRIX_SHA = "66f4e8e4258e22f839575b086808b439db0ce526feefebae29444edb884eb2a2"
EXPECTED_MANIFEST_SHA = "5b477cc3fa5e5343b223b7ba559919f708f945426f193dbb0510245771148900"
EXPECTED_INVENTORY_SHA = "17a48ffe2f8d487d9100c88f5882d4b4f35fa30c90ff5b5b995fbeed7193b4c5"
LOCATION_RE = re.compile(r"^(.+\.(?:tsx|jsx|ts|js|vue)):(\d+)(?::\d+|-\d+)?$")


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def load(path: Path) -> dict:
    with path.open("r", encoding="utf-8-sig") as handle:
        return json.load(handle)


matrix_input_sha = sha(MATRIX)
require(
    matrix_input_sha
    in {
        EXPECTED_MATRIX_SHA,
        EXPECTED_PRE_NORMALIZATION_MATRIX_SHA,
        EXPECTED_PRE_ENRICHMENT_RECONCILED_MATRIX_SHA,
        EXPECTED_RECONCILED_MATRIX_SHA,
        EXPECTED_PRE_GLOBAL_EXACT_MATRIX_SHA,
        EXPECTED_PRE_SPLIT_GLOBAL_MATRIX_SHA,
        EXPECTED_FINAL_901_MATRIX_SHA,
        EXPECTED_MATERIAL_ROUTE_WAVE_MATRIX_SHA,
    },
    "Visual matrix input SHA drift",
)
require(sha(MANIFEST) == EXPECTED_MANIFEST_SHA, "Manifest input SHA drift")
require(sha(INVENTORY) == EXPECTED_INVENTORY_SHA, "Inventory input SHA drift")

manifest = load(MANIFEST)
inventory = load(INVENTORY)
require(manifest.get("audited_commit") == EXPECTED_COMMIT, "Manifest commit mismatch")
require(inventory.get("commit") == EXPECTED_COMMIT, "Inventory commit mismatch")

with MATRIX.open("r", encoding="utf-8-sig", newline="") as handle:
    reader = csv.DictReader(handle)
    input_fields = list(reader.fieldnames or [])
    input_rows = list(reader)

if "legacy_feature_id" in input_fields:
    generated_fields = {"feature_id", "working_feature_ids", "feature_link_status", "feature_link_evidence"}
    original_fields = []
    for field in input_fields:
        if field == "legacy_feature_id":
            original_fields.append("feature_id")
        elif field not in generated_fields:
            original_fields.append(field)
    original_rows = []
    for row in input_rows:
        original_rows.append({field: (row["legacy_feature_id"] if field == "feature_id" else row[field]) for field in original_fields})
else:
    original_fields = input_fields
    original_rows = input_rows

require(len(original_rows) == 8753, f"Expected 8753 visual rows, found {len(original_rows)}")
require(len({row["visual_id"] for row in original_rows}) == 8753, "Visual IDs are not unique")
require("feature_id" in original_fields, "Missing legacy feature_id column")

targets = manifest["targets"]
target_by_key = {row["working_key"]: row for row in targets}
require(len(target_by_key) == 901, "Manifest target key count mismatch")
family_to_targets: dict[str, set[str]] = defaultdict(set)
for target in targets:
    for family in target.get("source_family_ids", []):
        family_to_targets[str(family)].add(target["working_key"])

routes = inventory["routes"]
pages = inventory["pages"]
routes_by_pair: dict[tuple[str, str], list[dict]] = defaultdict(list)
routes_by_name: dict[str, list[dict]] = defaultdict(list)
routes_by_path: dict[str, list[dict]] = defaultdict(list)
for route in routes:
    routes_by_pair[(str(route.get("name", "")), str(route.get("uri", "")))].append(route)
    routes_by_name[str(route.get("name", ""))].append(route)
    routes_by_path[str(route.get("uri", ""))].append(route)

pages_by_key: dict[str, list[dict]] = defaultdict(list)
pages_by_file: dict[str, list[dict]] = defaultdict(list)
for page in pages:
    pages_by_key[str(page.get("page_key", ""))].append(page)
    pages_by_file[str(page.get("file", ""))].append(page)


def resolve_routes(row: dict[str, str]) -> list[dict]:
    name = row.get("route_name", "")
    path = row.get("route_path", "")
    if name and path:
        return routes_by_pair.get((name, path), [])
    if name:
        return routes_by_name.get(name, [])
    if path:
        return routes_by_path.get(path, [])
    return []


def resolve_pages(row: dict[str, str]) -> list[dict]:
    anchor = row.get("component_anchor", "")
    matches: dict[str, dict] = {}
    for page in pages_by_key.get(anchor, []):
        matches[page["page_id"]] = page
    for page in pages_by_file.get(anchor, []):
        matches[page["page_id"]] = page
    location = LOCATION_RE.match(anchor)
    if location:
        for page in pages_by_file.get(location.group(1), []):
            matches[page["page_id"]] = page
    return [matches[key] for key in sorted(matches)]


def target_candidates_for_routes(lineage: set[str], route_ids: set[str]) -> set[str]:
    return {
        key for key in lineage
        if set(map(str, target_by_key[key].get("route_ids", []))) & route_ids
    }


def target_candidates_for_pages(lineage: set[str], page_ids: set[str]) -> set[str]:
    return {
        key for key in lineage
        if set(map(str, target_by_key[key].get("page_ids", []))) & page_ids
    }


new_fields: list[str] = []
for field in original_fields:
    if field == "feature_id":
        new_fields.extend([
            "legacy_feature_id",
            "feature_id",
            "working_feature_ids",
            "feature_link_status",
            "feature_link_evidence",
        ])
    else:
        new_fields.append(field)

result_rows: list[dict[str, str]] = []
status_counts: Counter[str] = Counter()

for original in original_rows:
    legacy = original["feature_id"]
    final_id = ""
    candidate_ids: list[str] = []
    status = ""
    evidence = ""

    if legacy in target_by_key:
        final_id = legacy
        candidate_ids = [legacy]
        status = "exact_working_key"
        evidence = f"legacy feature_id exactly equals final working key {legacy}"
    else:
        lineage = set(family_to_targets.get(legacy, set()))
        if len(lineage) == 1:
            final_id = next(iter(lineage))
            candidate_ids = [final_id]
            status = "source_family_one_to_one"
            evidence = f"source family {legacy} has exactly one final target"
        elif len(lineage) > 1:
            resolved_routes = resolve_routes(original)
            resolved_pages = resolve_pages(original)
            route_ids = {str(row["route_id"]) for row in resolved_routes}
            page_ids = {str(row["page_id"]) for row in resolved_pages}
            route_targets = target_candidates_for_routes(lineage, route_ids)
            page_targets = target_candidates_for_pages(lineage, page_ids)

            if route_targets and page_targets:
                intersection = route_targets & page_targets
                if len(intersection) == 1:
                    final_id = next(iter(intersection))
                    candidate_ids = [final_id]
                    status = "split_family_exact_route_page"
                elif not intersection:
                    candidate_ids = sorted(lineage)
                    status = "unresolved_split_family_route_page_conflict"
                else:
                    candidate_ids = sorted(lineage)
                    status = "unresolved_split_family_page_ambiguous"
            elif route_targets:
                if len(route_targets) == 1:
                    final_id = next(iter(route_targets))
                    candidate_ids = [final_id]
                    status = "split_family_exact_route"
                else:
                    candidate_ids = sorted(lineage)
                    status = "unresolved_split_source_family"
            elif page_targets:
                if len(page_targets) == 1:
                    final_id = next(iter(page_targets))
                    candidate_ids = [final_id]
                    status = "split_family_exact_page"
                else:
                    candidate_ids = sorted(lineage)
                    status = "unresolved_split_family_page_ambiguous"
            else:
                candidate_ids = sorted(lineage)
                status = "unresolved_split_source_family"

            evidence = (
                f"split source family {legacy}; inventory route_ids={'|'.join(sorted(route_ids)) or 'none'}; "
                f"route targets={'|'.join(sorted(route_targets)) or 'none'}; "
                f"inventory page_ids={'|'.join(sorted(page_ids)) or 'none'}; "
                f"page targets={'|'.join(sorted(page_targets)) or 'none'}; shared family envelope not promoted"
            )

            # Some retained family labels are stale even though the row's exact
            # inventory route/page relation points uniquely to a current target
            # outside that family envelope. Permit only the unique global exact
            # relation; shared relations and conflicts remain unresolved.
            if status.startswith("unresolved_"):
                all_targets = set(target_by_key)
                global_route_targets = target_candidates_for_routes(all_targets, route_ids)
                global_page_targets = target_candidates_for_pages(all_targets, page_ids)
                if (
                    global_route_targets
                    and global_page_targets
                    and len(global_route_targets & global_page_targets) == 1
                ):
                    final_id = next(iter(global_route_targets & global_page_targets))
                    status = "split_family_global_exact_route_page"
                elif global_route_targets and not global_page_targets and len(global_route_targets) == 1:
                    final_id = next(iter(global_route_targets))
                    status = "split_family_global_exact_route"
                elif global_page_targets and not global_route_targets and len(global_page_targets) == 1:
                    final_id = next(iter(global_page_targets))
                    status = "split_family_global_exact_page"

                if final_id:
                    candidate_ids = [final_id]
                    evidence += (
                        f"; global route targets={'|'.join(sorted(global_route_targets)) or 'none'}; "
                        f"global page targets={'|'.join(sorted(global_page_targets)) or 'none'}; "
                        "unique exact current target relation promoted outside the stale family envelope"
                    )
        else:
            # A legacy label may be absent from the retained source-family
            # register even when this exact visual row names an inventory route
            # and/or resolver page that has a unique current target relation.
            # Promote only that unique exact relation. A shared relation or a
            # route/page conflict remains unresolved; no family inference is
            # introduced here.
            resolved_routes = resolve_routes(original)
            resolved_pages = resolve_pages(original)
            route_ids = {str(row["route_id"]) for row in resolved_routes}
            page_ids = {str(row["page_id"]) for row in resolved_pages}
            all_targets = set(target_by_key)
            route_targets = target_candidates_for_routes(all_targets, route_ids)
            page_targets = target_candidates_for_pages(all_targets, page_ids)

            if route_targets and page_targets and len(route_targets & page_targets) == 1:
                final_id = next(iter(route_targets & page_targets))
                status = "global_exact_route_page"
            elif route_targets and not page_targets and len(route_targets) == 1:
                final_id = next(iter(route_targets))
                status = "global_exact_route"
            elif page_targets and not route_targets and len(page_targets) == 1:
                final_id = next(iter(page_targets))
                status = "global_exact_page"
            else:
                status = "unresolved_no_manifest_lineage"

            if final_id:
                candidate_ids = [final_id]
                evidence = (
                    f"legacy feature_id {legacy} has no exact final key or retained source-family lineage; "
                    f"global exact inventory route_ids={'|'.join(sorted(route_ids)) or 'none'}; "
                    f"route targets={'|'.join(sorted(route_targets)) or 'none'}; "
                    f"inventory page_ids={'|'.join(sorted(page_ids)) or 'none'}; "
                    f"page targets={'|'.join(sorted(page_targets)) or 'none'}; "
                    "unique exact target relation promoted without family inheritance"
                )
            else:
                evidence = f"legacy feature_id {legacy} has no exact final key or retained source-family lineage"

    # A material-state row is route/action-specific, not a shared page envelope.
    # Promote only when the exact current route pair, inventory projection,
    # manifest route relation, frontend source and backend method all agree on
    # one target. This does not change its Not safely reproducible classification.
    if (
        status.startswith("unresolved_")
        and original.get("pattern_type") == "material-state-applicability"
        and original.get("implementation") == "exact source-applicability map"
        and original.get("route_name")
        and original.get("route_path")
    ):
        exact_routes = routes_by_pair.get(
            (original["route_name"], original["route_path"]), []
        )
        if len(exact_routes) == 1:
            exact_route = exact_routes[0]
            route_id = str(exact_route["route_id"])
            inventory_targets = {
                str(x) for x in exact_route.get("working_canonical_feature_ids", [])
            }
            manifest_targets = {
                key for key, target in target_by_key.items()
                if route_id in set(map(str, target.get("route_ids", [])))
            }
            action = str(exact_route.get("action", ""))
            controller_method_proved = False
            if "@" in action:
                controller_class, controller_method = action.split("@", 1)
                if controller_class.startswith("App\\") and controller_method:
                    controller_rel = (
                        "app/" + controller_class[4:].replace("\\", "/") + ".php"
                    )
                    controller_path = REPO / controller_rel
                    if controller_path.is_file():
                        controller_source = controller_path.read_text(
                            encoding="utf-8", errors="replace"
                        )
                        controller_method_proved = bool(re.search(
                            rf"\bfunction\s+{re.escape(controller_method)}\s*\(",
                            controller_source,
                        ))
            component_source_proved = (REPO / original["component_anchor"]).is_file()
            if (
                len(inventory_targets) == 1
                and inventory_targets == manifest_targets
                and controller_method_proved
                and component_source_proved
            ):
                final_id = next(iter(inventory_targets))
                candidate_ids = [final_id]
                status = "material_state_exact_route_action"
                evidence = (
                    f"material-state applicability row; exact current inventory route pair "
                    f"{original['route_name']} {original['route_path']} resolves only {route_id}; "
                    f"inventory working target and manifest route relation both uniquely equal {final_id}; "
                    f"backend action {action} has a current controller method declaration; "
                    f"component source {original['component_anchor']} exists; route-specific state assigned "
                    "without source-family or shared-page inheritance"
                )

    require(bool(final_id) == (not status.startswith("unresolved_")), f"Resolved/status mismatch for {original['visual_id']}")
    if final_id:
        require(final_id in target_by_key and candidate_ids == [final_id], f"Invalid final assignment for {original['visual_id']}")
    else:
        require(not final_id, f"Unresolved row has final ID: {original['visual_id']}")
    require(candidate_ids == sorted(set(candidate_ids)), f"Candidate IDs not sorted/unique: {original['visual_id']}")
    require(set(candidate_ids) <= set(target_by_key), f"Unknown candidate: {original['visual_id']}")

    row: dict[str, str] = {}
    for field in original_fields:
        if field == "feature_id":
            row["legacy_feature_id"] = legacy
            row["feature_id"] = final_id
            row["working_feature_ids"] = "|".join(candidate_ids)
            row["feature_link_status"] = status
            row["feature_link_evidence"] = evidence
        else:
            # The governing prompt permits only Observed, Source-inferred,
            # Not safely reproducible, or Blocked for browser claims. Preserve
            # the underlying notes while normalizing two historical labels:
            # four Observed-light rows were browser-measured, while five
            # Dead/unreachable rows are static source conclusions rather than
            # browser observations.
            row[field] = (
                {"Observed-light": "Observed", "Dead/unreachable": "Source-inferred"}.get(
                    original[field], original[field]
                )
                if field == "classification"
                else original[field]
            )
    result_rows.append(row)
    status_counts[status] += 1

expected_status = Counter({
    "exact_working_key": 3497,
    "source_family_one_to_one": 1565,
    "split_family_exact_route": 61,
    "split_family_exact_page": 758,
    "split_family_exact_route_page": 1081,
    "global_exact_route_page": 353,
    "global_exact_route": 109,
    "global_exact_page": 224,
    "split_family_global_exact_route_page": 6,
    "split_family_global_exact_route": 1,
    "split_family_global_exact_page": 5,
    "material_state_exact_route_action": 125,
    "unresolved_split_family_page_ambiguous": 342,
    "unresolved_split_source_family": 113,
    "unresolved_no_manifest_lineage": 513,
})
require(status_counts == expected_status, f"Visual reconciliation status drift: {status_counts}")
require(len(result_rows) == 8753, "Result row count changed")
require([row["visual_id"] for row in result_rows] == [row["visual_id"] for row in original_rows], "Visual row order changed")

for original, result in zip(original_rows, result_rows):
    require(result["legacy_feature_id"] == original["feature_id"], "Legacy feature ID not preserved")
    for field in original_fields:
        if field != "feature_id":
            expected = (
                {"Observed-light": "Observed", "Dead/unreachable": "Source-inferred"}.get(
                    original[field], original[field]
                )
                if field == "classification"
                else original[field]
            )
            require(result[field] == expected, f"Observation field changed: {result['visual_id']} {field}")

allowed_classifications = {"Observed", "Source-inferred", "Not safely reproducible", "Blocked"}
require(
    {row["classification"] for row in result_rows} <= allowed_classifications,
    "Visual matrix contains a browser-claim classification outside the permitted vocabulary",
)

temp = MATRIX.with_suffix(".csv.tmp")
with temp.open("w", encoding="utf-8", newline="") as handle:
    writer = csv.DictWriter(handle, fieldnames=new_fields, lineterminator="\n")
    writer.writeheader()
    writer.writerows(result_rows)

with temp.open("r", encoding="utf-8-sig", newline="") as handle:
    reparsed = list(csv.DictReader(handle))
require(len(reparsed) == 8753 and len({row["visual_id"] for row in reparsed}) == 8753, "Reparsed visual matrix failed")
temp.replace(MATRIX)

tuple_lines = [
    "\x1f".join((row["visual_id"], row["legacy_feature_id"], row["feature_id"], row["working_feature_ids"], row["feature_link_status"], row["feature_link_evidence"]))
    for row in result_rows
]
tuple_sha = hashlib.sha256("\n".join(tuple_lines).encode("utf-8")).hexdigest()
require(tuple_sha == "1d2286e748de22f9a4eefd636b70eaafa8c10ea5016b7e7153886fdc70fb6de4", "Semantic tuple SHA drift")

material_route_patch_lines: list[str] = []
for original, result in zip(original_rows, result_rows):
    if result["feature_link_status"] != "material_state_exact_route_action":
        continue
    exact_routes = routes_by_pair[(original["route_name"], original["route_path"])]
    require(len(exact_routes) == 1, f"Material route patch lost exact route: {original['visual_id']}")
    exact_route = exact_routes[0]
    material_route_patch_lines.append("\x1f".join((
        original["visual_id"],
        result["feature_id"],
        str(exact_route["route_id"]),
        original["route_name"],
        original["route_path"],
        str(exact_route.get("action", "")),
        original["component_anchor"],
        original.get("state", ""),
    )))
material_route_patch_lines.sort()
material_route_patch_sha = hashlib.sha256(
    "\n".join(material_route_patch_lines).encode("utf-8")
).hexdigest()
require(len(material_route_patch_lines) == 125, "Material route patch count drift")
require(
    material_route_patch_sha == "baae6c620b6cf33cd70a69047ca1a3c912d86bc694966094d1c13147d36321ae",
    "Material route patch SHA drift",
)

unresolved_identity_lines = sorted(
    "\x1f".join((
        row["visual_id"],
        row["feature_link_status"],
        row["legacy_feature_id"],
        row["working_feature_ids"],
        row.get("route_name", ""),
        row.get("route_path", ""),
        row.get("component_anchor", ""),
        row.get("pattern_type", ""),
    ))
    for row in result_rows
    if not row["feature_id"]
)
unresolved_identity_sha = hashlib.sha256(
    "\n".join(unresolved_identity_lines).encode("utf-8")
).hexdigest()
require(len(unresolved_identity_lines) == 968, "Unresolved identity count drift")
require(
    unresolved_identity_sha == "d7b67c2751774a20a7a8e5fed5c5f6f8b829d5a779161c780e08a19304bccf9c",
    "Unresolved identity SHA drift",
)

assigned = sum(bool(row["feature_id"]) for row in result_rows)
unresolved = len(result_rows) - assigned
represented_manifest_ids = {candidate for row in result_rows for candidate in row["working_feature_ids"].split("|") if candidate}
summary = {
    "schema_version": "1.0",
    "artifact": "final-901-visual-link-generation-summary",
    "audited_commit": EXPECTED_COMMIT,
    "status": "partial_final_id_linkage_observations_preserved_runtime_coverage_unchanged",
    "audit_boundary": "Audit artifacts only; no application code, configuration, data, tests, browser state, deployment or Git history changed.",
    "inputs": {
        "visual_matrix_input_sha256": matrix_input_sha,
        "visual_matrix_original_sha256": EXPECTED_MATRIX_SHA,
        "manifest_sha256": EXPECTED_MANIFEST_SHA,
        "inventory_sha256": EXPECTED_INVENTORY_SHA,
    },
    "counts": {
        "rows": 8753,
        "unique_visual_ids": 8753,
        "assigned_final_feature_id": assigned,
        "unresolved_final_feature_id": unresolved,
        "unique_assigned_final_feature_ids": len({row["feature_id"] for row in result_rows if row["feature_id"]}),
        "manifest_ids_with_any_visual_lineage": len(represented_manifest_ids),
        "manifest_ids_without_visual_lineage": 901 - len(represented_manifest_ids),
        "unresolved_with_manifest_candidates": sum(not row["feature_id"] and bool(row["working_feature_ids"]) for row in result_rows),
        "unresolved_without_manifest_candidates": sum(not row["working_feature_ids"] for row in result_rows),
        "status_counts": dict(sorted(status_counts.items())),
        "classification_counts": dict(sorted(Counter(row["classification"] for row in result_rows).items())),
        "pattern_type_counts": dict(sorted(Counter(row["pattern_type"] for row in result_rows).items())),
    },
    "outputs": {
        "matrix": "../../05-browser-visual-coverage-matrix.csv",
        "matrix_sha256": sha(MATRIX),
        "semantic_tuple_sha256": tuple_sha,
        "semantic_tuple_algorithm": "Current row order; UTF-8; LF/no trailing LF; visual_id US legacy_feature_id US feature_id US working_feature_ids US feature_link_status US feature_link_evidence",
    },
    "proof_boundary": "Every original observation is preserved. Four browser-measured Observed-light labels are normalized to Observed; five static Dead/unreachable source conclusions are normalized to Source-inferred; their evidence notes remain unchanged. Blank final feature IDs are explicit unresolved links. Source-family envelopes are never promoted to split-target proof; a missing or stale family label may be bypassed only when the row's exact inventory route/page relation identifies one unique current target. Browser/runtime coverage is unchanged.",
    "material_state_exact_route_action_wave": {
        "count": 125,
        "classification": "Not safely reproducible",
        "selection_rule": "Material-state applicability + exact source-applicability map + exact current route name/path + one inventory target equal to one manifest route target + existing frontend source + existing backend controller method.",
        "patch_map_sha256": material_route_patch_sha,
        "patch_map_algorithm": "Lexicographic line sort; UTF-8; LF/no trailing LF; visual_id US target US route_id US route_name US route_path US action US component_anchor US state",
        "unresolved_remainder_sha256": unresolved_identity_sha,
        "unresolved_remainder_algorithm": "Lexicographic line sort; UTF-8; LF/no trailing LF; visual_id US current_status US legacy_feature_id US working_feature_ids US route_name US route_path US component_anchor US pattern_type",
        "claim_limit": "This proves exact target lineage for route-specific material-state rows; it does not convert any row into runtime/browser completion."
    },
    "validation": {
        "row_count_preserved": True,
        "visual_id_order_preserved": True,
        "legacy_feature_id_preserved": True,
        "original_observation_fields_preserved_except_permitted_classification_normalization": True,
        "browser_claim_classification_vocabulary_conforming": True,
        "assigned_ids_exist_in_manifest": True,
        "unresolved_rows_have_blank_final_id": True,
    },
    "completion_gate": {"complete": False, "reason": f"{unresolved} rows remain without a uniquely proved final working ID"},
}
SUMMARY.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

print(json.dumps({
    "rows": 8753,
    "assigned": assigned,
    "unresolved": unresolved,
    "unique_assigned_feature_ids": summary["counts"]["unique_assigned_final_feature_ids"],
    "matrix_sha256": sha(MATRIX),
    "semantic_tuple_sha256": tuple_sha,
    "summary_sha256": sha(SUMMARY),
}, indent=2))
