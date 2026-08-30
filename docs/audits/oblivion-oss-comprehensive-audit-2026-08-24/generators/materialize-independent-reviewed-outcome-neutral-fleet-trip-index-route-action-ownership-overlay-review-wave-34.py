#!/usr/bin/env python3
"""Materialize the three-part post-commit review of the RUN180 overlay."""
from __future__ import annotations

from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
import ast
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
from typing import Any


sys.dont_write_bytecode = True
REPO = Path(__file__).resolve().parents[4]
AUDIT = Path(__file__).resolve().parents[1]
PREFIX = AUDIT.relative_to(REPO).as_posix()
GENERATOR = "generators/materialize-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.py"
OUTPUT = "evidence/source/current-run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34.json"
PRODUCER_GENERATOR = "generators/integrate-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.py"
PRODUCER = "evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json"

HEAD = "e6dd903e2374ebccbd34adf1c2c483905643ae36"
TREE = "5ce1769287874ab69f3bdb5159dcd59b51bf2f30"
PARENT = "db4aa8c943c63f43892cfd2dd9d7495be60796b4"
PARENT_TREE = "d38e62180949fa5a1a6c09d906821d69ede5f95d"
SUBJECT = "audit: integrate RUN180 trip index owner"
APPLICATION_COMMIT = "f40e3d63ea99d774265ff9f2eefef8176ab0cbc7"
APPLICATION_TREE = "880721d56b7d379abf9628abb22a5a9b9445194b"
ORIGIN_MAIN = "c39b076547056b1e158c604957a04bd8b75b0f29"
SUBTREES = {
    "app": "3a83cf8acdd88071870634501ab7eacf2d76e62a",
    "routes": "b62a85f59ba5f45a54fd666b3199a65453034272",
    "resources/js": "8a851516cdb76ded362fb5912e3e930e45c8df86",
    "resources/js/pages": "8ad1ecc5817310f2f45c64733ca72d771c798a2f",
    "tests": "332a54fe95c85c1c1ea9477a1ea115bce9f7b4ac",
    "database": "341446159b5d8f6e303db9e9cddabfd446b0e034",
    "bootstrap": "df6189abe5ab5343d88674c199c4ce46e6152a57",
    "docs/architecture": "3444047114f5f446954b032dedc4e0c7892180bd",
}

GOVERNING_PROMPT = {
    "path": r"C:\Users\steph\Downloads\oblivion-open-source-benchmark-and-8-pass-audit-prompt.md",
    "sha256": "4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f",
    "role": "GOVERNING_AUDIT_PROMPT",
}
CONTINUATION_REQUEST = {
    "path": r"C:\Users\steph\.codex\attachments\8b35b9fe-b295-4a84-bdf9-a8afb05b2daa\pasted-text-1.txt",
    "sha256": "1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d",
    "role": "CONTINUATION_REQUEST_ONLY",
    "is_governing_prompt": False,
}

PRODUCER_GENERATOR_SHA = "cdbeeae65d0d5d928d6356de7c2433d437b6f2bae9fd80bb7a942b97d41f6594"
PRODUCER_GENERATOR_BLOB = "f3bd1cae87ff0b9f74bd1be8d5e963db91cd0813"
PRODUCER_SHA = "49b0bd12abbd4dd2b9ce0dbe9b6fd60ab79eea92861f6339407fbd05f0b7c925"
PRODUCER_BLOB = "b9d3d623d22e7ee8cad21fca62d703cd5881b0a9"
PRODUCER_SELF_SEAL = "181a94c9b53b7f78e3d29f5833b42bbe0e87fcfb899c2af0b465aec9a09339cf"
INPUT_MAP_SEAL = "006289f2ca75c99676443f24f5aff1450a03f2f71c5d16798466fb133c8c09fd"
COHORT_SELF_SEAL = "2fb26afd47c818fe5654fdc685af9a87e40624ad44e205914cca85298593bfc2"
RUN179R_SELF_SEAL = "75589c560904f51656af7038037e988ae169b181ddc480b95d5fca35cdbec14b"
RUN179R_SYNTHESIS_SEAL = "2142515ab596130890f398a3cb06f7818c1c98264b48598e7ccc991ea6d1df2d"
RUN179R_DECISION_SEAL = "e3530def5fb093b5b2169659d32b3251a6726d493257602c8138d3a38bc050d3"
CANDIDATE_SEAL = "b09ac81def93dcb4800f4a1ac340c698ff73f538ae3bcca792b01a53d7c2b650"
QUEUE_RECORD_SEAL = "928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011"
ROW_SEAL = "5e502f8732212c48edbf5e83ddb114410a06ac6d986a8a0f71f7554ad4ce2f50"
BRIDGE_SEAL = "eff07ebed3567fd6e9c6aebd7980333dd8ee34a201a0ac2c4e47b93b26c2e5de"
NEW_ROWS_SEAL = "7ecd0bd4b5d7ad8b1ae0d5b154254dbdc85741f5e95cb241d2d4947de58edcac"
NEW_BRIDGES_SEAL = "0f48e9484084b65d1173c0925069bc400aefc266f3b52dfda64ba7b5653bf3d7"
PRIOR_SOURCE_IDENTITY = "d691bbfc9eabfa3f34f0df294c24c6890d3082b2149ed8b553cc88747e3143e5"
COMBINED_SOURCE_IDENTITY = "1648a470ca0293c4c065b30925b8eda5a9f78d35fa64935e644a3354e17cdbba"
PRIOR_BRIDGE_IDENTITY = "19ed2b2cabf56de20dc2ae10b70877536140dc76285c5c64462d71535b302498"
COMBINED_BRIDGE_IDENTITY = "6ab1b8c1045ac6c159ba4aa5856ac58e648263a530f4f7c3031e4eed5d84fa32"
PRIOR_REVIEWED_IDENTITY = "acfca5e54d64c54334dbd94b30104244b3d2d6722a5426439aec7a8aa62d3ab5"
COMBINED_REVIEWED_IDENTITY = "5dbcecd3986300fe255fdb75efe6013c07f3adc4071745ebebf0c4a525ee99c9"
PRIOR_REVIEWED_CANONICAL = "e85b37e5410c1cc861f9116061e88fb82fdb854e5dc94e56eefe1947b3a7b510"
COMBINED_REVIEWED_CANONICAL = "738c7836dd770e12d67de62d4f28441825814d619bb641e070e25468786fb75e"

BASE_COLLECTOR = "generators/integrate-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.py"
RUN149 = "evidence/source/current-run-149-reviewed-outcome-neutral-fleet-daily-vehicle-check-store-route-action-ownership-overlay-wave-25.json"
RUN153 = "evidence/source/current-run-153-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-wave-26.json"
RUN170 = "evidence/source/current-run-170-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.json"
QUEUE = "evidence/source/root-run-090-direct-exact-route-page-review-queue-wave-11.json"
COHORT = "evidence/source/root-run-179-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.json"
RUN179R = "evidence/source/raw-run-179r-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.json"
MANIFEST = "evidence/source/audit-run-manifest.json"

EXPECTED_INPUTS = {
    BASE_COLLECTOR: "b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e",
    RUN149: "12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55",
    RUN153: "9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815",
    "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json": "7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461",
    "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py": "c732926f3112c987fbaaf3f398bc18b3d25027c7f1495c38016237a5cb6f28a3",
    RUN170: "c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d",
    "evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json": "62474100b0c2f027fa0c15f2bb841f08ad3de058da67725a931fcafec17dd139",
    QUEUE: "5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5",
    "03-feature-to-benchmark-matrix.csv": "3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0",
    "generators/build-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.py": "61c895a305f743f102765c9f86d38843c3ce61bcc1a8684a672aa2d7cd6ee157",
    COHORT: "5505cf17bb68d3e534116ea9d33e501e0222714b6e3779d0ec6b70f819cc3b0a",
    "generators/materialize-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.py": "80cf0e6febabee80b1fa99f3f296cabade8959bd5a4fcd72983af19d335332cd",
    RUN179R: "67c5b09cbb26c95042bd7ba487c2a2c92a75d14363952ca35e9b72ee55e36d62",
}
EXPECTED_INPUT_BLOBS = {
    BASE_COLLECTOR: "e8d9d1c9889be589a22db6dfea53d3122adce247",
    RUN149: "c5f0a3bda99167f66650d63bfdb35e18d8ed93b5",
    RUN153: "818b891cff9965193c60d83d0580c21a48d1a682",
    "evidence/source/current-run-153r-independent-reviewed-outcome-neutral-fleet-vehicle-register-index-route-action-ownership-overlay-review-wave-26.json": "20bb3580ba2cb60205694d52aa72e16cd2f2a423",
    "generators/integrate-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-wave-31.py": "2603b130a0a674e6803413583c95b51bc3f83545",
    RUN170: "8cff90e1e86e5752cbfc3e59d03ccc5423e23ed6",
    "evidence/source/current-run-170r-independent-reviewed-outcome-neutral-fleet-vehicle-alerts-config-route-action-ownership-overlay-review-wave-31.json": "fbcccd7e19ea57db52a1d6ca462aa107107159d1",
    QUEUE: "66809274d25916f4e0d2426419bfde6e371ba1f1",
    "03-feature-to-benchmark-matrix.csv": "1f5fdab3ae80ae4ec1b9bc4ee47eef695bdd5416",
    "generators/build-outcome-neutral-fleet-trip-index-route-action-cohort-wave-34.py": "506a7007c8d7b8e719b1bfa904a880a2885fe8c1",
    COHORT: "ea3a958c125038a95c8d98370328a263d2a2c151",
    "generators/materialize-independent-outcome-neutral-fleet-trip-index-route-action-review-wave-34.py": "3004a455a14736f2641e7f71c506181a0b02d967",
    RUN179R: "7a1d16ff8ee0f0fe78aeac742322bee0c8c6e8ec",
}

LANE_A = """RUN180R_LANE_A_POST_COMMIT_MECHANICS_REVIEW

DECISION: GO
DISCREPANCIES: 0
REPORTING_AUTHORIZATION: NOT GRANTED BY THIS LANE

- Commit pin verified:
  - Branch: `main`
  - HEAD: `e6dd903e2374ebccbd34adf1c2c483905643ae36`
  - Tree: `5ce1769287874ab69f3bdb5159dcd59b51bf2f30`
  - Sole parent: `db4aa8c943c63f43892cfd2dd9d7495be60796b4`
  - Subject: `audit: integrate RUN180 trip index owner`

- Exact commit diff verified: two added `100644` files and no other paths:
  - Generator blob `f3bd1cae87ff0b9f74bd1be8d5e963db91cd0813`
  - Receipt blob `b9d3d623d22e7ee8cad21fca62d703cd5881b0a9`

- Generator integrity:
  - SHA-256: `cdbeeae65d0d5d928d6356de7c2433d437b6f2bae9fd80bb7a942b97d41f6594`
  - `36,675` bytes / `650` lines
  - Strict UTF-8, no BOM, LF final newline
  - Python AST parse and in-memory compile passed
  - Working copy is byte-identical to the committed blob

- Receipt integrity:
  - SHA-256: `49b0bd12abbd4dd2b9ce0dbe9b6fd60ab79eea92861f6339407fbd05f0b7c925`
  - `46,534` bytes / `883` lines
  - Strict duplicate-free, finite JSON
  - Exact `ensure_ascii=False`, two-space-indent format with LF final newline; no BOM
  - Working copy is byte-identical to the committed blob
  - Reported and independently recomputed canonical self-seal match:
    `181a94c9b53b7f78e3d29f5833b42bbe0e87fcfb899c2af0b465aec9a09339cf`

- Application-subtree object IDs are unchanged across application pin `f40e3d63…`, parent, and current commit for `app`, `routes`, `resources/js`, `resources/js/pages`, `tests`, `database`, `bootstrap`, and `docs/architecture`.

- Final cleanliness:
  - No staged, unstaged, or untracked files
  - No `__pycache__`, `.pytest_cache`, `.mypy_cache`, `.ruff_cache`, or `*.tmp` in the audit subtree
  - No edits, generator execution, application/test/browser execution, commit, or cache creation performed

This GO is limited to RUN180R lane-A post-commit mechanics and artifact integrity; it does not authorize reporting, publication, audit completion, or substantive credit."""

LANE_B = """RUN180R LANE B — **GO**, lineage/accounting integrity only.

**Discrepancies: 0.** This lane does not authorize reporting, publication, completion, or downstream credit.

### Post-commit pin

- HEAD: `e6dd903e2374ebccbd34adf1c2c483905643ae36`
- Tree: `5ce1769287874ab69f3bdb5159dcd59b51bf2f30`
- Parent/RUN180 checkpoint: `db4aa8c943c63f43892cfd2dd9d7495be60796b4`
- Clean `main`; commit adds only the [RUN180 receipt](C:/Users/steph/Herd/oblivionfindings/docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/evidence/source/current-run-180-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.json) and [generator](C:/Users/steph/Herd/oblivionfindings/docs/audits/oblivion-oss-comprehensive-audit-2026-08-24/generators/integrate-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-wave-34.py).
- Generator: SHA-256 `cdbeeae65d0d5d928d6356de7c2433d437b6f2bae9fd80bb7a942b97d41f6594`; blob `f3bd1cae87ff0b9f74bd1be8d5e963db91cd0813`.
- Receipt: SHA-256 `49b0bd12abbd4dd2b9ce0dbe9b6fd60ab79eea92861f6339407fbd05f0b7c925`; blob `b9d3d623d22e7ee8cad21fca62d703cd5881b0a9`; self-seal `181a94c9b53b7f78e3d29f5833b42bbe0e87fcfb899c2af0b465aec9a09339cf`.

Checkpoint `db4aa8c…` resolves to tree `d38e62180949fa5a1a6c09d906821d69ede5f95d`, parent `b263db6e2c883cae8370cc3529eac490d121f2db`. Application pin `f40e3d63ea99d774265ff9f2eefef8176ab0cbc7` resolves to tree `880721d56b7d379abf9628abb22a5a9b9445194b`; all eight declared application subtrees match both pins.

### All governing input SHA/blob pairs

```text
base RUN149 generator
b5c7f04cd44ecd73dda9c7fe4a9e2e8616c68674cdc52d393ec696b06ad2327e / e8d9d1c9889be589a22db6dfea53d3122adce247

RUN149 receipt
12a52c434ecd18a5c6a644378070aa5ab046f5e7080726b983ded8d9c7377a55 / c5f0a3bda99167f66650d63bfdb35e18d8ed93b5

RUN153 receipt
9b7e382f83787d807de8d752ecb3e6524280c707899aba78d47082765272e815 / 818b891cff9965193c60d83d0580c21a48d1a682

RUN153R review
7f1da8394a8054f01f34fb943a3fba6601bf70ea06d69cf97033f2208edf4461 / 20bb3580ba2cb60205694d52aa72e16cd2f2a423

RUN170 generator
c732926f3112c987fbaaf3f398bc18b3d25027c7f1495c38016237a5cb6f28a3 / 2603b130a0a674e6803413583c95b51bc3f83545

RUN170 receipt
c739a36e1975b60d42988be3de36b9fe1ea88cf942752c90112f40ebaa04cd8d / 8cff90e1e86e5752cbfc3e59d03ccc5423e23ed6

RUN170R review
62474100b0c2f027fa0c15f2bb841f08ad3de058da67725a931fcafec17dd139 / fbcccd7e19ea57db52a1d6ca462aa107107159d1

RUN090 queue
5d38c3507eef04aa4bad3c713fbd3817d4cbb2879d0713476a8d4717f715e4a5 / 66809274d25916f4e0d2426419bfde6e371ba1f1

03 feature matrix
3f3b7bffdfa9464a111d1d65028d2660dd30e4541e429f6920987f7cae1448a0 / 1f5fdab3ae80ae4ec1b9bc4ee47eef695bdd5416

RUN179 cohort generator
61c895a305f743f102765c9f86d38843c3ce61bcc1a8684a672aa2d7cd6ee157 / 506a7007c8d7b8e719b1bfa904a880a2885fe8c1

RUN179 cohort
5505cf17bb68d3e534116ea9d33e501e0222714b6e3779d0ec6b70f819cc3b0a / ea3a958c125038a95c8d98370328a263d2a2c151

RUN179R review generator
80cf0e6febabee80b1fa99f3f296cabade8959bd5a4fcd72983af19d335332cd / 3004a455a14736f2641e7f71c506181a0b02d967

RUN179R review
67c5b09cbb26c95042bd7ba487c2a2c92a75d14363952ca35e9b72ee55e36d62 / 7a1d16ff8ee0f0fe78aeac742322bee0c8c6e8ec
```

All 26 comparisons match. Canonical input-map hash also recomputes to `006289f2ca75c99676443f24f5aff1450a03f2f71c5d16798466fb133c8c09fd`.

External governing prompt SHA matches `4a02284113c58f24bd4f695b672d39ff1912dc4b9126fc84fa9139072d18484f`; the distinct, non-governing continuation request matches `1fe63113afd626f671e3af401e66106b24efb91727a8bfc0573673060f8bd32d`.

### Lineage and seals

- Candidate: `b09ac81def93dcb4800f4a1ac340c698ff73f538ae3bcca792b01a53d7c2b650`.
- Selected queue-row seal: `928eeec741742f8329dd7e191a71f2d5249775b6de64e6a698a72836345ca011`.
- Cohort self-seal: `2fb26afd47c818fe5654fdc685af9a87e40624ad44e205914cca85298593bfc2`.
- Review self-seal: `75589c560904f51656af7038037e988ae169b181ddc480b95d5fca35cdbec14b`.
- Synthesis: `2142515ab596130890f398a3cb06f7818c1c98264b48598e7ccc991ea6d1df2d`.
- Decision: `e3530def5fb093b5b2169659d32b3251a6726d493257602c8138d3a38bc050d3`.

All five chronology seals, both semantic-tiebreak seals (`46a76d37…`, `bc872842…`), and both neutral-artifact seals (`2eba8723…`, `85ce2f4a…`) independently recompute. The strict-current split remains `1 OWNER_ROUTE_ACTION / 1 EVIDENCE_GAP`; both later independent tiebreaks resolve `OWNER_ROUTE_ACTION`, while the original dissent remains preserved.

### Cumulative identities and uniqueness

Historical base through RUN142 reconstructs to `662` owners, `93` bridges, `116` reviewed keys. Adding RUN149/RUN153/RUN170 reconstructs the prior RUN180 state:

- `665/665` unique source keys and IDs; hash `d691bbfc9eabfa3f34f0df294c24c6890d3082b2149ed8b553cc88747e3143e5`.
- `96/96` unique bridge keys; hash `19ed2b2cabf56de20dc2ae10b70877536140dc76285c5c64462d71535b302498`.
- `119` unique reviewed keys; all within the `507/507` unique frozen queue keys.

RUN180 adds exactly these previously absent identities:

```text
route|RUN077-ROUTE-0693|CAP-FLEET-VEHICLE-REGISTER
app/Http/Controllers/FleetAssets/VehicleController.php|trips|CAP-FLEET-VEHICLE-REGISTER
route|RUN077-ROUTE-0693
```

Combined state is `666/666` unique source keys/IDs, `97/97` unique bridges, and `120` reviewed keys, with zero duplicates.

- Combined source identity: `1648a470ca0293c4c065b30925b8eda5a9f78d35fa64935e644a3354e17cdbba`.
- Combined bridge identity: `6ab1b8c1045ac6c159ba4aa5856ac58e648263a530f4f7c3031e4eed5d84fa32`.
- New row seal: `5e502f8732212c48edbf5e83ddb114410a06ac6d986a8a0f71f7554ad4ce2f50`.
- New bridge seal: `eff07ebed3567fd6e9c6aebd7980333dd8ee34a201a0ac2c4e47b93b26c2e5de`.
- New-row aggregate: `7ecd0bd4b5d7ad8b1ae0d5b154254dbdc85741f5e95cb241d2d4947de58edcac`.
- New-bridge aggregate: `0f48e9484084b65d1173c0925069bc400aefc266f3b52dfda64ba7b5653bf3d7`.

Both reviewed-key algorithms match:

```text
LF-joined sorted unique UTF-8:
prior    acfca5e54d64c54334dbd94b30104244b3d2d6722a5426439aec7a8aa62d3ab5
combined 5dbcecd3986300fe255fdb75efe6013c07f3adc4071745ebebf0c4a525ee99c9

Canonical JSON sorted array:
prior    e85b37e5410c1cc861f9116061e88fb82fdb854e5dc94e56eefe1947b3a7b510
combined 738c7836dd770e12d67de62d4f28441825814d619bb641e070e25468786fb75e
```

Index 83 was already reviewed and is not recredited. Index 84 was absent before RUN180 and is present exactly once afterward. Index 85 remains absent and is the next unresolved row:

```text
RUN090-ROUTE-0086
RUN077-ROUTE-0694
fleet-assets.trips.playback
[FleetTripController::class, 'show']
f9df043e4557240020de213961c847fb56b8cd0e2d9b9144ec0b7a877ff84943
```

### Exact accounting

- Owners: `666 = 309 route + 357 page`.
- Features: `256 = 64 route + 242 page - 50 overlap`; `234 H + 22 D`.
- Bridges: `97`.
- Coverage: `round-half-up(666 × 100 ÷ 3929, 6) = 16.950878%`.
- Source: `3929 = 666 owner + 3263 residual`.
- Routes: `3218 = 309 owner + 12 shared + 5 alias + 0 dead + 2892 residual`.
- Pages: `711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual`.
- Queue: `507 = 120 reviewed + 387 pending`.
- Reviewed queue: `120 = 98 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap`.
- Without ownership: `409 = 387 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap`.
- Reviewed outcome: `1 = 1 owner + 0 shared + 0 evidence gap`.

The seven route and one page evidence-gap values are tags within the residual source populations, not additional source-universe terms.

### Credit boundary

Exactly two top-level `credit_boundary` flags are true:

```text
STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD
STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION
```

The other 31 credit flags are false, including page/adjacent ownership, correctness, Site/permission/privacy/direct-object assurance, runtime, browser, tests, benchmark, finding, publication, completion, Gate 4, and audit completion. `artifact_completion_test_met=true` is an artifact-state attestation, not an extra credit category; `audit_completion_test_met=false`.

No files were changed, no application/tests/browser were executed, and no cache or temporary artifacts were created."""

LANE_C = """GO — RUN180R lane C semantics/noninheritance review.

Discrepancies: 0.

At clean local `main` `e6dd903e2374ebccbd34adf1c2c483905643ae36` / tree `5ce1769287874ab69f3bdb5159dcd59b51bf2f30`, committed RUN180 faithfully integrates only queue index 84 (`RUN090-ROUTE-0085`, `RUN077-ROUTE-0693`, `fleet-assets.trips.index`) and its `VehicleController::trips` bridge.

Both distinct fresh strict-current tiebreaks and the original 1 OWNER_ROUTE_ACTION / 1 EVIDENCE_GAP dissent are preserved exactly. The contaminated preliminary 2026-08-12 judgments remain invalidated; no old-bundle identity, mapping, benchmark, or credit is imported. Immutable RUN179 route/controller provenance remains sealed by the candidate record, while RUN180 identifies the current route and controller hashes/anchors separately and presents no historical hash as current.

Index 83 is not recredited; index 85 `fleet-assets.trips.playback` remains next unresolved. No playback, page, caller, service, model, helper, test, or adjacent-route outcome is inherited. Fleet Site-privacy remediation, runtime, regressions, and historical reporting are not recredited. The one-organisation, multi-Site authorization boundary is correct.

The only current positive credits are one static route-feature owner and one static controller-action bridge. Correctness, Site/privacy, runtime, browser, executed-test, benchmark, NCM, finding, completion, Gate 4, release, publication, and audit-complete credit remain false. `artifact_completion_test_met=true` is bounded to the sealed two-file RUN180 artifact; `audit_completion_test_met=false`.

RUN179, RUN179R, RUN180, chronology, tiebreak, synthesis, decision, candidate, overlay-row, and bridge-row seals recompute correctly. Reporting remains gated pending the complete post-commit review synthesis; lane C alone does not authorize reporting.

Review was read-only: no files edited, no application/tests/browser/runtime executed, no cache or commit created.

"""


def run(*args: str) -> bytes:
    return subprocess.run(args, cwd=REPO, check=True, capture_output=True).stdout


def git(*args: str) -> str:
    return run("git", *args).decode("utf-8").rstrip("\r\n")


def digest(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def canonical(value: Any) -> str:
    return digest(json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode())


def hlist(values: list[str] | set[str]) -> str:
    return digest("\n".join(sorted(set(values))).encode())


def strict_json(relative: str, pretty: bool = True) -> dict[str, Any]:
    def hook(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        result: dict[str, Any] = {}
        for key, value in pairs:
            assert key not in result, (relative, key)
            result[key] = value
        return result

    def reject_constant(value: str) -> None:
        raise ValueError(f"non-finite JSON constant in {relative}: {value}")

    raw = (AUDIT / relative).read_bytes()
    assert not raw.startswith(b"\xef\xbb\xbf") and b"\r\n" not in raw and raw.endswith(b"\n")
    value = json.loads(raw, object_pairs_hook=hook, parse_constant=reject_constant)
    assert isinstance(value, dict)
    if pretty:
        assert (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode() == raw
    return value


def verify_seal(record: dict[str, Any], field: str, expected: str) -> None:
    raw = record[field]
    actual = raw["sha256"] if isinstance(raw, dict) else raw
    assert actual == expected
    assert actual == canonical({key: value for key, value in record.items() if key != field})


def sealed(record: dict[str, Any], field: str) -> dict[str, Any]:
    record[field] = canonical(record)
    return record


def artifact(relative: str) -> dict[str, Any]:
    raw = (AUDIT / relative).read_bytes()
    committed = run("git", "show", f"{HEAD}:{PREFIX}/{relative}")
    assert raw == committed
    return {
        "path": f"{PREFIX}/{relative}",
        "sha256": digest(raw),
        "blob_id": git("rev-parse", f"{HEAD}:{PREFIX}/{relative}"),
        "bytes": len(raw),
        "lines": len(raw.splitlines()),
    }


def review_record(identifier: str, role: str, task_path: str, payload: str, dimensions: list[str]) -> dict[str, Any]:
    raw = payload.encode("utf-8")
    return sealed({
        "review_id": identifier,
        "reviewer_role": role,
        "reviewer_task_path": task_path,
        "independent_from_producer": True,
        "independent_from_other_review_lanes": True,
        "blinded_review": False,
        "nonblinding_reason": "The committed RUN180 producer artifact and bounded audit context were visible; no blindness is claimed.",
        "delivery_channel": "collaboration_status_or_message",
        "raw_payload": payload,
        "raw_payload_sha256": digest(raw),
        "raw_payload_bytes": len(raw),
        "raw_payload_lines": len(payload.splitlines()),
        "verbatim_payload_retained": True,
        "review_method": "READ_ONLY_INDEPENDENT_COMMITTED_ARTIFACT_REVIEW_NO_APPLICATION_EXECUTION",
        "verified_dimensions": dimensions,
        "verdict": "GO",
        "discrepancies": 0,
        "reviewer_wrote_files": False,
        "reviewer_executed_generator_tests_application_runtime_or_browser": False,
        "reporting_authorization_individually_granted": False,
    }, "review_record_sha256")


def validate_run179_lineage(producer: dict[str, Any]) -> tuple[dict[str, Any], dict[str, Any]]:
    cohort = strict_json(COHORT)
    review = strict_json(RUN179R)
    verify_seal(cohort, "self_seal", COHORT_SELF_SEAL)
    verify_seal(review, "self_seal", RUN179R_SELF_SEAL)
    assert len(cohort["records"]) == 1 and canonical(cohort["records"][0]) == CANDIDATE_SEAL
    assert cohort["records"][0]["queue_record_sha256"] == QUEUE_RECORD_SEAL

    synthesis = review["synthesis_review"]
    decision = review["action_decision"]
    assert synthesis["synthesis_record_sha256"] == RUN179R_SYNTHESIS_SEAL
    assert synthesis["synthesis_record_sha256"] == canonical({key: value for key, value in synthesis.items() if key != "synthesis_record_sha256"})
    assert decision["decision_record_sha256"] == RUN179R_DECISION_SEAL
    assert decision["decision_record_sha256"] == canonical({key: value for key, value in decision.items() if key != "decision_record_sha256"})
    assert decision["candidate_record_sha256"] == CANDIDATE_SEAL
    assert decision["queue_record_self_seal_sha256"] == QUEUE_RECORD_SEAL
    assert decision["outcome"] == "OWNER_ROUTE_ACTION"

    chronology = review["review_chronology"]
    assert len(chronology) == 5
    for item in chronology:
        assert item["chronology_record_sha256"] == canonical({key: value for key, value in item.items() if key != "chronology_record_sha256"})
    strict = [item["reported_outcome"] for item in chronology if item["stage"] == "STRICT_CURRENT_RERUN"]
    assert strict == ["OWNER_ROUTE_ACTION", "EVIDENCE_GAP"]
    assert synthesis["strict_current_split"] == {"OWNER_ROUTE_ACTION": 1, "EVIDENCE_GAP": 1}
    assert synthesis["original_strict_current_dissent_preserved"] is True
    assert synthesis["dissenting_strict_current_outcome"] == "EVIDENCE_GAP"
    assert synthesis["fresh_tiebreak_votes"] == {"OWNER_ROUTE_ACTION": 2, "SHARED_RELATION": 0, "EVIDENCE_GAP": 0}

    tiebreaks = review["independent_semantic_tiebreak_reviews"]
    artifact_reviews = review["independent_neutral_artifact_reviews"]
    assert len(tiebreaks) == len(artifact_reviews) == 2
    for item in tiebreaks:
        assert item["review_record_sha256"] == canonical({key: value for key, value in item.items() if key != "review_record_sha256"})
    for item in artifact_reviews:
        assert item["artifact_review_record_sha256"] == canonical({key: value for key, value in item.items() if key != "artifact_review_record_sha256"})
    assert {item["outcome"] for item in tiebreaks} == {"OWNER_ROUTE_ACTION"}
    assert all(item["older_2026_08_12_bundle_consulted"] is False for item in tiebreaks)
    assert producer["reviewer_lineage_and_dissent_preservation"]["review_chronology"] == chronology
    assert producer["reviewer_lineage_and_dissent_preservation"]["accepted_independent_semantic_tiebreak_reviews"] == tiebreaks
    assert producer["reviewer_lineage_and_dissent_preservation"]["synthesis_review"] == synthesis
    assert producer["reviewer_lineage_and_dissent_preservation"]["action_decision"] == decision
    assert producer["reviewer_lineage_and_dissent_preservation"]["original_strict_current_split_preserved"] is True
    assert producer["reviewer_lineage_and_dissent_preservation"]["original_dissenting_outcome"] == "EVIDENCE_GAP"
    assert producer["reviewer_lineage_and_dissent_preservation"]["preliminary_shared_judgments_recredited"] is False
    assert producer["reviewer_lineage_and_dissent_preservation"]["excluded_older_bundle_identity_or_credit_imported"] is False
    return cohort, review


def validate_accounting(producer: dict[str, Any]) -> None:
    run149, run153, run170 = strict_json(RUN149), strict_json(RUN153), strict_json(RUN170)
    spec = importlib.util.spec_from_file_location("run149_base_for_run180r", AUDIT / BASE_COLLECTOR)
    assert spec and spec.loader
    base = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(base)
    prior_records, prior_bridges = base.collect_prior_state()
    prior_records += run149["overlay_source_records"] + run153["overlay_source_records"] + run170["overlay_source_records"]
    prior_bridges += run149["new_static_controller_action_bridges"] + run153["new_static_controller_action_bridges"] + run170["new_static_controller_action_bridges"]
    assert (len(prior_records), len(prior_bridges)) == (665, 96)

    row = producer["overlay_source_records"][0]
    bridge = producer["new_static_controller_action_bridges"][0]
    verify_seal(row, "overlay_row_sha256", ROW_SEAL)
    verify_seal(bridge, "bridge_row_sha256", BRIDGE_SEAL)
    assert canonical([row]) == NEW_ROWS_SEAL
    assert canonical([bridge]) == NEW_BRIDGES_SEAL
    assert row["source_record_key"] == "route|RUN077-ROUTE-0693|CAP-FLEET-VEHICLE-REGISTER"
    assert (bridge["controller_file"], bridge["method"], bridge["feature_id"]) == (
        "app/Http/Controllers/FleetAssets/VehicleController.php", "trips", "CAP-FLEET-VEHICLE-REGISTER",
    )
    assert row["original_strict_current_dissent_preserved"] is True
    assert row["original_strict_current_split"] == {"OWNER_ROUTE_ACTION": 1, "EVIDENCE_GAP": 1}

    prior_source_keys = [item["source_record_key"] for item in prior_records]
    prior_bridge_keys = ["|".join((item["controller_file"], item["method"], item["feature_id"])) for item in prior_bridges]
    assert len(prior_source_keys) == len(set(prior_source_keys)) == 665
    assert len(prior_bridge_keys) == len(set(prior_bridge_keys)) == 96
    assert hlist(prior_source_keys) == PRIOR_SOURCE_IDENTITY
    assert hlist(prior_bridge_keys) == PRIOR_BRIDGE_IDENTITY

    records = prior_records + [row]
    bridges = prior_bridges + [bridge]
    source_keys = [item["source_record_key"] for item in records]
    source_ids = [item["source_record_id"] for item in records]
    bridge_keys = ["|".join((item["controller_file"], item["method"], item["feature_id"])) for item in bridges]
    assert len(source_keys) == len(set(source_keys)) == len(source_ids) == len(set(source_ids)) == 666
    assert len(bridge_keys) == len(set(bridge_keys)) == 97
    assert hlist(source_keys) == COMBINED_SOURCE_IDENTITY
    assert hlist(bridge_keys) == COMBINED_BRIDGE_IDENTITY

    routes = [item for item in records if item["surface"] == "ROUTE_SOURCE_RECORD"]
    pages = [item for item in records if item["surface"] == "PAGE_ROOT_SOURCE_RECORD"]
    features = {item["feature_id"] for item in records}
    route_features = {item["feature_id"] for item in routes}
    page_features = {item["feature_id"] for item in pages}
    assert (len(routes), len(pages), len(features), len(route_features), len(page_features), len(route_features & page_features)) == (309, 357, 256, 64, 242, 50)
    assert Counter({item["feature_id"]: item["feature_class"] for item in records}.values()) == {"H": 234, "D": 22}
    percent = (Decimal(666) * 100 / Decimal(3929)).quantize(Decimal("0.000001"), rounding=ROUND_HALF_UP)
    assert format(percent, "f") == "16.950878"

    prior_reviewed = base.collect_prior_reviewed_queue_keys() | {
        "route|RUN077-ROUTE-0689", "route|RUN077-ROUTE-0690", "route|RUN077-ROUTE-0692",
    }
    reviewed = prior_reviewed | {"route|RUN077-ROUTE-0693"}
    assert len(prior_reviewed) == 119 and len(reviewed) == 120
    assert hlist(prior_reviewed) == PRIOR_REVIEWED_IDENTITY and canonical(sorted(prior_reviewed)) == PRIOR_REVIEWED_CANONICAL
    assert hlist(reviewed) == COMBINED_REVIEWED_IDENTITY and canonical(sorted(reviewed)) == COMBINED_REVIEWED_CANONICAL
    queue = strict_json(QUEUE)["records"]
    assert len(queue) == len({item["canonical_key"] for item in queue}) == 507
    assert queue[83]["canonical_key"] in prior_reviewed
    assert queue[84]["canonical_key"] not in prior_reviewed and queue[84]["canonical_key"] in reviewed
    assert queue[85]["canonical_key"] not in reviewed
    assert (
        queue[85]["queue_id"], queue[85]["source_record_id"], queue[85]["source"]["literal_route_name"],
        queue[85]["source"]["action_expression"], queue[85]["queue_record_sha256"],
    ) == (
        "RUN090-ROUTE-0086", "RUN077-ROUTE-0694", "fleet-assets.trips.playback",
        "[FleetTripController::class, 'show']", "f9df043e4557240020de213961c847fb56b8cd0e2d9b9144ec0b7a877ff84943",
    )

    expected_counts = {
        "source_owner_records": 666, "route_owner_records": 309, "page_owner_records": 357,
        "distinct_feature_ids": 256, "distinct_H_feature_ids": 234, "distinct_D_feature_ids": 22,
        "route_distinct_feature_ids": 64, "page_distinct_feature_ids": 242, "route_page_feature_overlap": 50,
        "static_controller_action_bridges": 97, "bounded_static_source_denominator": 3929,
        "bounded_static_source_ownership_percent": "16.950878", "bounded_static_source_residual_records": 3263,
        "residual_explicit_unmapped_routes": 2892, "semantic_shared_routes": 12, "reviewed_alias_routes": 5,
        "reviewed_dead_routes": 0, "evidence_gap_routes_tagged_within_residual": 7,
        "residual_unadjudicated_page_roots": 345, "semantic_shared_page_roots": 9,
        "reviewed_alias_page_roots": 0, "reviewed_dead_page_roots": 0,
        "evidence_gap_page_roots_tagged_within_residual": 1,
    }
    expected_queue = {
        "direct_exact_queue_records": 507, "reviewed_queue_surface_rows": 120,
        "owner_queue_surface_rows": 98, "shared_queue_surface_rows": 10,
        "alias_queue_surface_rows": 5, "dead_queue_surface_rows": 0,
        "evidence_gap_queue_surface_rows": 7, "pending_unreviewed_queue_surface_rows": 387,
        "queue_surfaces_without_ownership": 409, "new_reviewed_route_surface_rows": 1,
        "new_owner_route_surface_rows": 1,
    }
    assert producer["combined_counts"] == expected_counts
    assert producer["queue_accounting"] == expected_queue
    identity = producer["identity"]
    assert identity["prior_source_record_key_list_sha256"] == PRIOR_SOURCE_IDENTITY
    assert identity["combined_source_record_key_list_sha256"] == COMBINED_SOURCE_IDENTITY
    assert identity["prior_bridge_key_list_sha256"] == PRIOR_BRIDGE_IDENTITY
    assert identity["combined_bridge_key_list_sha256"] == COMBINED_BRIDGE_IDENTITY
    assert identity["prior_reviewed_queue_key_list_sha256"] == PRIOR_REVIEWED_IDENTITY
    assert identity["combined_reviewed_queue_key_list_sha256"] == COMBINED_REVIEWED_IDENTITY
    assert identity["canonical_json_reviewed_key_hashes"] == {"prior": PRIOR_REVIEWED_CANONICAL, "combined": COMBINED_REVIEWED_CANONICAL}
    assert identity["new_overlay_source_records_sha256"] == NEW_ROWS_SEAL
    assert identity["new_action_bridges_sha256"] == NEW_BRIDGES_SEAL
    assert producer["queue_boundary"]["next_unresolved_index"] == 85
    assert producer["queue_boundary"]["next_unresolved_queue_record_sha256"] == queue[85]["queue_record_sha256"]

    assert 3929 == 666 + 3263 and 666 == 309 + 357
    assert 3218 == 309 + 12 + 5 + 0 + 2892
    assert 711 == 357 + 9 + 0 + 0 + 345
    assert 507 == 120 + 387 and 120 == 98 + 10 + 5 + 0 + 7
    assert 409 == 387 + 10 + 5 + 0 + 7
    assert 256 == 64 + 242 - 50
    assert producer["outcome_conservation"] == {
        "reviewed_outcomes_equation": "1 = 1 owner + 0 shared + 0 evidence gap",
        "bounded_source_equation": "3929 = 666 owner + 3263 non-owner residual",
        "owner_surface_equation": "666 = 309 route + 357 page",
        "feature_union_equation": "256 = 64 route + 242 page - 50 overlap",
        "route_universe_equation": "3218 = 309 owner + 12 shared + 5 alias + 0 dead + 2892 residual",
        "page_universe_equation": "711 = 357 owner + 9 shared + 0 alias + 0 dead + 345 residual",
        "queue_equation": "507 = 120 reviewed + 387 pending",
        "reviewed_queue_equation": "120 = 98 owner + 10 shared + 5 alias + 0 dead + 7 evidence gap",
        "queue_without_ownership_equation": "409 = 387 pending + 10 shared + 5 alias + 0 dead + 7 evidence gap",
    }


def validate_producer() -> dict[str, Any]:
    assert git("branch", "--show-current") == "main"
    assert git("rev-parse", "HEAD") == HEAD and git("rev-parse", "HEAD^{tree}") == TREE
    assert git("rev-parse", "HEAD^") == PARENT and git("rev-parse", f"{PARENT}^{{tree}}") == PARENT_TREE
    assert git("show", "-s", "--format=%s", HEAD) == SUBJECT
    assert git("rev-parse", f"{APPLICATION_COMMIT}^{{tree}}") == APPLICATION_TREE
    assert git("rev-parse", "origin/main") == ORIGIN_MAIN
    assert git("rev-list", "--left-right", "--count", f"origin/main...{HEAD}").split() == ["0", "15"]
    for path, expected in SUBTREES.items():
        assert git("rev-parse", f"{APPLICATION_COMMIT}:{path}") == expected
        assert git("rev-parse", f"{PARENT}:{path}") == expected
        assert git("rev-parse", f"{HEAD}:{path}") == expected

    expected_paths = {
        f"{PREFIX}/{PRODUCER_GENERATOR}": ("A", "650", "0"),
        f"{PREFIX}/{PRODUCER}": ("A", "883", "0"),
    }
    names = [line.split("\t") for line in git("diff-tree", "--no-commit-id", "--name-status", "-r", HEAD).splitlines()]
    assert {parts[1]: parts[0] for parts in names} == {path: values[0] for path, values in expected_paths.items()}
    numstat = [line.split("\t") for line in git("diff-tree", "--no-commit-id", "--numstat", "-r", HEAD).splitlines()]
    assert {parts[2]: (parts[0], parts[1]) for parts in numstat} == {path: values[1:] for path, values in expected_paths.items()}

    generator = artifact(PRODUCER_GENERATOR)
    receipt_artifact = artifact(PRODUCER)
    assert generator == {"path": f"{PREFIX}/{PRODUCER_GENERATOR}", "sha256": PRODUCER_GENERATOR_SHA, "blob_id": PRODUCER_GENERATOR_BLOB, "bytes": 36675, "lines": 650}
    assert receipt_artifact == {"path": f"{PREFIX}/{PRODUCER}", "sha256": PRODUCER_SHA, "blob_id": PRODUCER_BLOB, "bytes": 46534, "lines": 883}
    generator_source = (AUDIT / PRODUCER_GENERATOR).read_text(encoding="utf-8")
    tree = ast.parse(generator_source)
    compile(tree, PRODUCER_GENERATOR, "exec")

    producer = strict_json(PRODUCER)
    verify_seal(producer, "self_seal", PRODUCER_SELF_SEAL)
    assert producer["pins"]["generator"] == generator
    assert producer["pins"]["inputs"] == EXPECTED_INPUTS
    assert producer["pins"]["input_blobs"] == EXPECTED_INPUT_BLOBS
    assert producer["pins"]["input_map_sha256"] == canonical(EXPECTED_INPUTS) == INPUT_MAP_SEAL
    for relative, expected in EXPECTED_INPUTS.items():
        raw = (AUDIT / relative).read_bytes()
        assert digest(raw) == expected
        assert git("rev-parse", f"{HEAD}:{PREFIX}/{relative}") == EXPECTED_INPUT_BLOBS[relative]

    assert producer["pins"]["governing_prompt"] == GOVERNING_PROMPT
    assert producer["pins"]["continuation_request"] == CONTINUATION_REQUEST
    assert GOVERNING_PROMPT["sha256"] != CONTINUATION_REQUEST["sha256"]
    assert digest(Path(GOVERNING_PROMPT["path"]).read_bytes()) == GOVERNING_PROMPT["sha256"]
    assert digest(Path(CONTINUATION_REQUEST["path"]).read_bytes()) == CONTINUATION_REQUEST["sha256"]
    assert strict_json(MANIFEST)["governing_prompt"]["sha256"] == GOVERNING_PROMPT["sha256"]
    assert producer["pins"]["cohort_self_seal_sha256"] == COHORT_SELF_SEAL
    assert producer["pins"]["review_self_seal_sha256"] == RUN179R_SELF_SEAL
    assert producer["pins"]["synthesis_record_sha256"] == RUN179R_SYNTHESIS_SEAL
    assert producer["pins"]["decision_record_sha256"] == RUN179R_DECISION_SEAL

    validate_run179_lineage(producer)
    validate_accounting(producer)
    assert {key for key, value in producer["credit_boundary"].items() if value} == {
        "STATIC_ROUTE_FEATURE_OWNERSHIP_FOR_1_RECORD", "STATIC_CONTROLLER_ACTION_BRIDGE_FOR_1_ACTION",
    }
    assert producer["noninheritance_boundary"]["page_caller_service_model_helper_or_test_not_inherited_or_recredited"] is True
    assert producer["noninheritance_boundary"]["adjacent_route_identity_or_outcome_not_inherited"] is True
    assert producer["noninheritance_boundary"]["older_2026_08_12_feature_identity_imported"] is False
    assert producer["noninheritance_boundary"]["older_2026_08_12_mapping_or_benchmark_credit_imported"] is False
    assert producer["remediation_and_history_noninheritance"]["static_route_ownership_inherited_from_remediation"] is False
    assert producer["remediation_and_history_noninheritance"]["controller_action_bridge_inherited_from_remediation"] is False
    assert producer["remediation_and_history_noninheritance"]["correctness_inherited_from_static_identity"] is False
    assert producer["mutation_attestation"]["run180_producer_scope_contains_only_generator_and_receipt"] is True
    assert producer["artifact_completion_test_met"] is True
    assert producer["audit_completion_test_met"] is False
    assert producer["completion_boundary"]["gate_4_complete"] is False
    assert producer["completion_boundary"]["audit_complete"] is False
    return producer


def main() -> None:
    producer = validate_producer()
    reviews = [
        review_record(
            "RUN180R-INDEPENDENT-REVIEW-A", "exact commit mechanics and artifact integrity reviewer",
            "/root/run180r_lane_a", LANE_A,
            ["commit_tree_parent_subject", "exact_two_path_diff_and_numstat", "producer_bytes_format_ast_compile", "strict_finite_json_and_self_seal", "application_subtrees", "cleanliness_and_execution_boundary"],
        ),
        review_record(
            "RUN180R-INDEPENDENT-REVIEW-B", "lineage accounting identity and cursor reviewer",
            "/root/run180r_lane_b", LANE_B,
            ["all_input_sha_blob_pairs", "prompt_provenance_distinction", "run179_and_run179r_seals", "chronology_and_tiebreak_seals", "cumulative_identity_and_uniqueness", "accounting_equations", "cursor_and_credit_conservation"],
        ),
        review_record(
            "RUN180R-INDEPENDENT-REVIEW-C", "semantic credit dissent and noninheritance reviewer",
            "/root/run180r_lane_c", LANE_C,
            ["selected_route_action_only", "strict_current_dissent_preservation", "excluded_older_bundle_boundary", "immutable_and_current_provenance", "cursor_noninheritance", "historical_remediation_noninheritance", "single_organisation_multi_site_boundary", "reporting_gate"],
        ),
    ]
    assert len({item["reviewer_task_path"] for item in reviews}) == 3
    assert all(item["verdict"] == "GO" and item["discrepancies"] == 0 for item in reviews)
    assert all(item["reporting_authorization_individually_granted"] is False for item in reviews)
    assert "REPORTING_AUTHORIZATION: NOT GRANTED BY THIS LANE" in LANE_A
    assert "does not authorize reporting" in LANE_B
    assert "lane C alone does not authorize reporting" in LANE_C

    synthesis = sealed({
        "synthesis_id": "RUN180R-THREE-PART-POST-COMMIT-SYNTHESIS",
        "accepted_review_ids": [item["review_id"] for item in reviews],
        "accepted_review_record_sha256s": [item["review_record_sha256"] for item in reviews],
        "independent_reviews": 3,
        "all_three_exact_lane_payloads_sealed_before_synthesis": True,
        "discrepancies": 0,
        "mechanics_go": True,
        "lineage_accounting_identity_and_cursor_go": True,
        "semantic_dissent_and_noninheritance_go": True,
        "producer_commit_exact_two_path_scope": True,
        "reporting_materialization_authorized": True,
        "new_current_or_downstream_credit_authorized": False,
        "correctness_runtime_benchmark_finding_or_completion_credit_authorized": False,
        "release_or_publication_authorized": False,
        "gate_4_complete": False,
        "audit_complete": False,
    }, "synthesis_record_sha256")
    decision = sealed({
        "decision_id": "RUN180R-POST-COMMIT-REVIEW-DECISION",
        "verdict": "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT",
        "accepted_review_record_sha256s": synthesis["accepted_review_record_sha256s"],
        "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "independent_reviews": 3,
        "independently_sealed_review_records": True,
        "discrepancies": 0,
        "reporting_materialization_authorized": True,
        "new_source_ownership_credit": False,
        "new_route_ownership_credit": False,
        "new_page_ownership_credit": False,
        "new_controller_action_bridge_credit": False,
        "current_overlay_ownership_credit": False,
        "correctness_or_downstream_credit": False,
        "release_authorized": False,
        "publication_authorized": False,
        "gate_4_complete": False,
        "audit_complete": False,
    }, "decision_record_sha256")

    false_credit = {key: False for key in (
        "new_source_ownership", "new_route_ownership", "new_page_ownership", "new_controller_action_bridge",
        "current_overlay_ownership_credit", "downstream_ownership_credit", "adjacent_route_ownership",
        "frontend_caller_ownership", "service_model_helper_caller_or_test_ownership",
        "complete_route_page_feature_crosswalk", "framework_route_reachability",
        "canonical_object_ownership_correctness", "approved_site_scope_correctness",
        "permission_correctness", "privacy_correctness", "direct_object_concealment_correctness",
        "query_projection_correctness", "runtime", "database", "build", "application_browser",
        "responsive_application", "executed_tests", "benchmark", "final_no_match_or_NCM", "ease",
        "pass", "final_finding", "feature_completion", "completion", "gate_4", "release",
        "publication", "audit_complete",
    )}
    payload: dict[str, Any] = {
        "schema_version": "run-180r-independent-reviewed-outcome-neutral-fleet-trip-index-route-action-ownership-overlay-review-wave-34-v1",
        "run_id": "RUN-180R-INDEPENDENT-REVIEWED-OUTCOME-NEUTRAL-FLEET-TRIP-INDEX-ROUTE-ACTION-OWNERSHIP-OVERLAY-REVIEW-WAVE-34",
        "status": "GO_THREE_PART_POST_COMMIT_REVIEW_COMPLETE_REPORTING_ONLY_ZERO_NEW_OR_DOWNSTREAM_CREDIT",
        "reviewed_on": "2026-08-30",
        "pins": {
            "producer_commit": HEAD,
            "producer_tree": TREE,
            "producer_parent": PARENT,
            "producer_parent_tree": PARENT_TREE,
            "producer_subject": SUBJECT,
            "application_commit": APPLICATION_COMMIT,
            "application_tree": APPLICATION_TREE,
            "application_subtrees": SUBTREES,
            "origin_main_observed_without_refetch": ORIGIN_MAIN,
            "governing_prompt": GOVERNING_PROMPT,
            "continuation_request": CONTINUATION_REQUEST,
            "producer_generator": artifact(PRODUCER_GENERATOR),
            "producer": artifact(PRODUCER),
            "producer_self_seal_sha256": PRODUCER_SELF_SEAL,
            "producer_input_map_sha256": INPUT_MAP_SEAL,
            "cohort_self_seal_sha256": COHORT_SELF_SEAL,
            "run179r_self_seal_sha256": RUN179R_SELF_SEAL,
            "run179r_synthesis_record_sha256": RUN179R_SYNTHESIS_SEAL,
            "run179r_decision_record_sha256": RUN179R_DECISION_SEAL,
            "candidate_record_sha256": CANDIDATE_SEAL,
            "selected_queue_record_sha256": QUEUE_RECORD_SEAL,
            "overlay_row_sha256": ROW_SEAL,
            "bridge_row_sha256": BRIDGE_SEAL,
            "materializer": {
                "path": f"{PREFIX}/{GENERATOR}",
                "sha256": digest((AUDIT / GENERATOR).read_bytes()),
                "blob_id": git("hash-object", "--", str(AUDIT / GENERATOR)),
                "bytes": (AUDIT / GENERATOR).stat().st_size,
                "lines": len((AUDIT / GENERATOR).read_bytes().splitlines()),
            },
        },
        "architecture_rule": producer["architecture_rule"],
        "methods": {
            "independent_reviews": 3,
            "synthesizers": 1,
            "committed_artifact_only": True,
            "producer_generator_executed_by_reviewers": False,
            "application_executed": False,
            "tests_executed": False,
            "database_used": False,
            "build_used": False,
            "browser_used": False,
            "external_system_used": False,
        },
        "producer_scope": {
            "changed_paths": [f"{PREFIX}/{PRODUCER_GENERATOR}", f"{PREFIX}/{PRODUCER}"],
            "changed_path_count": 2,
            "added_lines": 1533,
            "deleted_lines": 0,
            "generator_numstat": "650/0",
            "receipt_numstat": "883/0",
            "working_copies_match_committed_blobs": True,
            "application_subtrees_unchanged": True,
        },
        "independent_review_records": reviews,
        "synthesis_review": synthesis,
        "decision": decision,
        "producer_snapshot": {
            "run_id": producer["run_id"],
            "status": producer["status"],
            "combined_counts": producer["combined_counts"],
            "queue_accounting": producer["queue_accounting"],
            "queue_boundary": producer["queue_boundary"],
            "identity": producer["identity"],
            "outcome_conservation": producer["outcome_conservation"],
            "original_strict_current_split_preserved": producer["reviewer_lineage_and_dissent_preservation"]["original_strict_current_split_preserved"],
            "original_dissenting_outcome": producer["reviewer_lineage_and_dissent_preservation"]["original_dissenting_outcome"],
            "excluded_older_bundle_identity_or_credit_imported": producer["reviewer_lineage_and_dissent_preservation"]["excluded_older_bundle_identity_or_credit_imported"],
            "overlay_row_sha256": ROW_SEAL,
            "bridge_row_sha256": BRIDGE_SEAL,
        },
        "publication_boundary": {
            "local_main_equals_producer_commit": True,
            "origin_main_equals_producer_commit": False,
            "origin_main_observed_without_refetch": ORIGIN_MAIN,
            "local_main_ahead_of_origin_main_by_commits": 15,
            "local_main_behind_origin_main_by_commits": 0,
            "remote_refetch_for_run180r_performed": False,
            "push_performed": False,
            "release_authorized": False,
            "publication_authorized_or_performed": False,
            "local_remote_tracking_alignment_claimed": False,
        },
        "credit_boundary": {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING": True, **false_credit},
        "completion_boundary": producer["completion_boundary"],
        "artifact_completion_test_met": True,
        "artifact_completion_scope": "THIS_EXACT_TWO_FILE_RUN180R_REVIEW_ARTIFACT_ONLY",
        "reporting_materialization_authorized": True,
        "audit_completion_test_met": False,
        "mutation_attestation": {
            "application_source_changed": False,
            "test_files_changed": False,
            "matrix_or_reporting_changed": False,
            "runtime_browser_or_external_system_changed": False,
            "audit_artifacts_only": True,
            "run180r_scope_contains_only_materializer_and_receipt": True,
        },
        "wrote_files": [f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"],
    }
    assert {key for key, value in payload["credit_boundary"].items() if value} == {"INDEPENDENT_OVERLAY_REVIEW_FOR_REPORTING"}
    payload["self_seal"] = {"algorithm": "sha256-canonical-json-with-self-seal-omitted", "sha256": canonical(payload)}

    target = AUDIT / OUTPUT
    temporary = target.with_name(target.name + ".tmp")
    assert not temporary.exists()
    raw = (json.dumps(payload, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
    temporary.write_bytes(raw)
    os.replace(temporary, target)
    parsed = strict_json(OUTPUT)
    seal = parsed.pop("self_seal")
    assert seal["sha256"] == canonical(parsed)
    assert target.read_bytes() == raw and not temporary.exists()
    assert not git("status", "--porcelain", "--untracked-files=no")
    expected_untracked = {f"{PREFIX}/{GENERATOR}", f"{PREFIX}/{OUTPUT}"}
    actual_untracked = {line[3:] for line in git("status", "--porcelain").splitlines() if line.startswith("?? ")}
    assert actual_untracked == expected_untracked, (actual_untracked, expected_untracked)
    assert not list(AUDIT.rglob("__pycache__"))
    assert not list(AUDIT.rglob(".pytest_cache"))
    assert not list(AUDIT.rglob(".mypy_cache"))
    assert not list(AUDIT.rglob(".ruff_cache"))
    assert not list(AUDIT.rglob("*.tmp"))
    print(json.dumps({
        "status": payload["status"],
        "materializer_sha256": payload["pins"]["materializer"]["sha256"],
        "receipt_sha256": digest(target.read_bytes()),
        "lane_payloads": [{
            "review_id": item["review_id"],
            "raw_payload_sha256": item["raw_payload_sha256"],
            "raw_payload_bytes": item["raw_payload_bytes"],
            "raw_payload_lines": item["raw_payload_lines"],
            "review_record_sha256": item["review_record_sha256"],
        } for item in reviews],
        "synthesis_record_sha256": synthesis["synthesis_record_sha256"],
        "decision_record_sha256": decision["decision_record_sha256"],
        "self_seal": payload["self_seal"]["sha256"],
        "receipt_bytes": target.stat().st_size,
        "receipt_lines": len(target.read_bytes().splitlines()),
        "reporting_materialization_authorized": True,
    }, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
