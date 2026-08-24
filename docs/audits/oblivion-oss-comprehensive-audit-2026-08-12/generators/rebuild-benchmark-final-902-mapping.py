#!/usr/bin/env python3
"""Deterministically lift and extend the benchmark/NCM map to 902 targets.

This audit-only transform preserves every prior 901 adjudication verbatim and
adds CAP-CR-SIGNAL-TO-ALERT-PIPELINE without inherited completion credit, then
    applies the independently accepted target-specific outcomes through wave 30,
then applies any orchestrator-authored credit withdrawals.
It does not execute application code, browsers, databases, or external systems.
"""

from __future__ import annotations

import copy
import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
PRIOR_MAPPING_PATH = SOURCE / "benchmark-final-901-mapping.json"
WAVE4_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave4.json"
WAVE5_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave5.json"
WAVE6_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave6.json"
WAVE7_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave7.json"
WAVE8_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave8.json"
WAVE9_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave9.json"
WAVE10_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave10.json"
WAVE11_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave11.json"
WAVE12_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave12.json"
WAVE13_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave13.json"
WAVE14_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave14.json"
WAVE15_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave15.json"
WAVE16_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave16.json"
WAVE17_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave17.json"
WAVE18_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave18.json"
WAVE19_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave19.json"
WAVE20_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave20.json"
WAVE21_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave21.json"
WAVE22_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave22.json"
WAVE23_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave23.json"
WAVE24_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave24.json"
WAVE27_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave27.json"
WAVE28_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave28.json"
WAVE30_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave30.json"
WAVE8_WITHDRAWAL_PATH = SOURCE / "benchmark-wave8-ncm-withdrawal-adjudication.json"
OUTPUT_PATH = SOURCE / "benchmark-final-902-mapping.json"
SUMMARY_PATH = SOURCE / "benchmark-final-902-generation-summary.json"
EXPECTED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
EXPECTED_MANIFEST_SHA256 = "ded38bc3672bf51cb48a02a576cc36ca83d01af6a982dbd19c118ff50edf59b9"
EXPECTED_WAVE4_SHA256 = "6eba8e290637fcdb5045ad80cb5ac0579b3843a1bfe348a4ff2728d62fefbe04"
EXPECTED_WAVE5_SHA256 = "3ba4abf748ce30f8ac84746e817ecc839e9698ac951613c720677f561de799d3"
EXPECTED_WAVE6_SHA256 = "7fe743d46ec1351b9655da564baf7ebbf506af9e5e125ee28541b9c913e34ebb"
EXPECTED_WAVE7_SHA256 = "5f3674bf651cfa63ff74d4862e3b2b49d03c659985addb788c26281c42fd0dcb"
EXPECTED_WAVE8_SHA256 = "ba191214a4f2e7d453aa3867d267e8f1cd72eb6354d0e1fea459f69f3b185b8c"
EXPECTED_WAVE9_SHA256 = "e8a5550011168155c7fcfc7f84e8a3e541a4725c8fbbeded9516a77a6caf8a08"
EXPECTED_WAVE10_SHA256 = "5dfa91dc364b1546cdbbb49df371a24406205110974606a38696838ff7380db2"
EXPECTED_WAVE11_SHA256 = "2a9a605130be69fb350b9bec7954dcc670050c006ca655e68edd5befd84f1832"
EXPECTED_WAVE12_SHA256 = "7796987c90ee4ac0ca1406d48c0a840f75ab7d358158a822ea4e4e2cee5f7416"
EXPECTED_WAVE13_SHA256 = "63a3768ef3aaeada1c75bf2b10b79f0c5cd53605de1a88ec8e00f2dd3294a3c3"
EXPECTED_WAVE14_SHA256 = "cd1501484a8b7b53f1628a0df1daeb96ad45ef80e590a5544459d2d31bba183b"
EXPECTED_WAVE15_SHA256 = "c0a57c0ee8e248d6ddded383e09c378ed43fd38430c5b21218f6ea6398ad551b"
EXPECTED_WAVE16_SHA256 = "0fd33aca3396c54f142900484b06a73f8645884598eab19aff033742cb81e49c"
EXPECTED_WAVE17_SHA256 = "07860807a51ce1e52c59c3dc520671a89672c0bdca2b95948a6fc13f8fdf5c7a"
EXPECTED_WAVE18_SHA256 = "9e8f63fab776c065fe026f74832182dff71f381b5ef239b74ce888c66c41b693"
EXPECTED_WAVE19_SHA256 = "e1ba0aa31a964e4baa2b1a6b1b8d24e879eced71d11b3a525f87c5273e7939ba"
EXPECTED_WAVE20_SHA256 = "e084378b467aca63b473e4717b2dd0c8604acdc76d53f0e61c233a021394cd9f"
EXPECTED_WAVE21_SHA256 = "9474a59f10a3aad16ec1dfaeb7e976b9e5f7386a5655c564834e197e092cd2fb"
EXPECTED_WAVE22_SHA256 = "e9b28de8e44d46cab9e824e0d9ab362300b53714ab6abb34ce3bafe395c66b98"
EXPECTED_WAVE23_SHA256 = "16b0c90fa8d2cca7b6c9e64670953b47612d0f0cfaac08eb6ccbe28e3a8cfd3e"
EXPECTED_WAVE24_SHA256 = "c96e62aae6964ee6f1fe8633b6ec07c553dccd42cf1ed352544ca7b234f47c38"
EXPECTED_WAVE27_SHA256 = "405e89ae05e02dbebce8cf1cd484010a603714baff1cf6dab0b0879214e2226e"
EXPECTED_WAVE28_SHA256 = "d68aa992bef7c76b2f91e04a284c3f55174fae618c27785a482429f747a17084"
EXPECTED_WAVE30_SHA256 = "5fd6e15f7796915c1d4ca2b97cecdc77d0732d030ea4b3c0fc4b1fd78cbc23a7"
EXPECTED_WAVE8_WITHDRAWAL_SHA256 = "17ba2e52588e06a2329b3ae849b815e9906506f91b13685fdaba4b3df8d40c64"
EXPECTED_PRE_WAVE_MAPPING_SHA256 = "583975fe82f7960375ae5b3bf4f442278d3a3f505734ae9077203e9ac4f66972"
EXPECTED_PRE_WAVE5_MAPPING_SHA256 = "6362545bf14cfeccf6b2d0f351d063317410fa2777476375795a087322e809d0"
EXPECTED_POST_WAVE5_MAPPING_SHA256 = "d4c73bc3be5a491cf67161f7bf1dd7f88037ab41c2b4dc1f62d7bf8b8f4f00c2"
EXPECTED_POST_WAVE6_MAPPING_SHA256 = "2dfe20676b9b019bac44141520c55abb6380804ae43b0102c1cdef121f5bf836"
EXPECTED_POST_WAVE7_MAPPING_SHA256 = "571e6a78bf34d5542168c32ab015f35e802933f4ac4cdb3188f047e93ca23511"
EXPECTED_POST_WAVE8_MAPPING_SHA256 = "347e8cbf78249db0d0f6ee648c992b6a639e2bbe15547e218436219852de9171"
EXPECTED_POST_WAVE9_MAPPING_SHA256 = "cfed6aeea4dcac5a132ca6d1b066652f0d6345d541d33dbe8908a0252150a7c2"
EXPECTED_POST_WAVE10_MAPPING_SHA256 = "3161a576cacec7ff4bd6a7202f9f1ad028d46b4446bd71287ac5dd05d213ae84"
EXPECTED_POST_WAVE11_MAPPING_SHA256 = "788f0f78cb8a9fb31257c6fffed60b468c687bcaf8757acc2e8494d653ab5d9d"
EXPECTED_POST_WAVE12_MAPPING_SHA256 = "f0f18e8c0ce902b4a9ea625aa911ce3dcdb50c1bca6a75391a04926cb34b67bf"
EXPECTED_PRE_PAGE_REFRESH_MAPPING_SHA256 = "499eec23858868f14f327f725fb5759d33cb6578fee02e0537b2f88f1b6a0a66"
EXPECTED_POST_WAVE13_MAPPING_SHA256 = "a2011f7228169fe4cfcfc01e172b9083eb239b110253cd45779cec66a7788bf1"
EXPECTED_POST_WITHDRAWAL_MAPPING_SHA256 = "6594c1f6ea03959faa6e07d54ce7fe292452a57c2e114e3465b40e8d5deb3e34"
EXPECTED_POST_WAVE14_MAPPING_SHA256 = "2c4c70238c7f7dfb1861808237be8e9b44deb44858c14c82ce9c461e5b7dbf5d"
EXPECTED_POST_WAVE16_MAPPING_SHA256 = "04c451ec5abc64504d3e4a4051ea65bbbe8c73ab7360363f00e053fe4bb3e407"
EXPECTED_POST_WAVE17_MAPPING_SHA256 = "2cfbddf865d01cc9d42baa5b9091ac7e3a67e51a06d749fcbdcce40128dae305"
EXPECTED_POST_WAVE18_MAPPING_SHA256 = "6565e56fa55437dc7b2e19f8a8a63c310b169dcc81eb967b732f1ea614de7160"
EXPECTED_POST_WAVE19_MAPPING_SHA256 = "0061f40580cc763297768ae53dcdf3ffb9d76d38bff2172015c4b9fe5d6fbd98"
EXPECTED_POST_WAVE20_MAPPING_SHA256 = "92c721f8f5d1ee0636a28c6b0eb406dc80863906792630338a5e866ab88134d8"
EXPECTED_POST_WAVE21_MAPPING_SHA256 = "25625c6b5524bdaed1b4660bc7f5ff991a997e47978037af5d1c449c9ae4afaa"
EXPECTED_POST_WAVE22_MAPPING_SHA256 = "5170cb9f26fa6bfd28b1ddaf01b439df8a8cc6939f25fa011251ac8dd9a6ae6c"
EXPECTED_POST_WAVE23_MAPPING_SHA256 = "7abb8fcebead1a083dd0089c27773b88abe20063ef1cf120358acefa073605d9"
EXPECTED_POST_WAVE24_MAPPING_SHA256 = "400514562630101d5b6f20761129bb30c7b0fb2ee4b76b65544e9a268a08a948"
EXPECTED_POST_WAVE27_MAPPING_SHA256 = "698548b476cec3bd0f1d6651f4918ddc3243f3aca4c00142f290d6d6efe58916"
EXPECTED_POST_WAVE28_MAPPING_SHA256 = "0ec2c6c24659a9f2ce60268c0b916bf916c475d8731bb4e61e5325c927a354fa"
EXPECTED_POST_WAVE30_MAPPING_SHA256 = "c2cb6ea0f584b8eef7c6e74cf6aca3cf580139fabdb66198ace43e02fddabe3c"
NEW_KEY = "CAP-CR-SIGNAL-TO-ALERT-PIPELINE"


def load(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8-sig") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise RuntimeError(f"Expected object: {path}")
    return value


def write(path: Path, value: dict[str, Any]) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def strings(value: Any) -> list[str]:
    if value is None:
        return []
    if not isinstance(value, list):
        raise RuntimeError(f"Expected array, got {type(value).__name__}")
    return sorted({str(item).strip() for item in value if str(item).strip()})


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def tuple_line(row: dict[str, Any]) -> str:
    return "|".join([
        str(row["working_key"]),
        str(row["status"]),
        ";".join(strings(row.get("source_units", []))),
        ";".join(strings(row.get("evidence_loci", []))),
    ])


def sha_lines(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(sorted(lines)).encode("utf-8")).hexdigest()


manifest = load(MANIFEST_PATH)
prior = load(PRIOR_MAPPING_PATH)
wave4 = load(WAVE4_PATH)
wave5 = load(WAVE5_PATH)
wave6 = load(WAVE6_PATH)
wave7 = load(WAVE7_PATH)
wave8 = load(WAVE8_PATH)
wave9 = load(WAVE9_PATH)
wave10 = load(WAVE10_PATH)
wave11 = load(WAVE11_PATH)
wave12 = load(WAVE12_PATH)
wave13 = load(WAVE13_PATH)
wave14 = load(WAVE14_PATH)
wave15 = load(WAVE15_PATH)
wave16 = load(WAVE16_PATH)
wave17 = load(WAVE17_PATH)
wave18 = load(WAVE18_PATH)
wave19 = load(WAVE19_PATH)
wave20 = load(WAVE20_PATH)
wave21 = load(WAVE21_PATH)
wave22 = load(WAVE22_PATH)
wave23 = load(WAVE23_PATH)
wave24 = load(WAVE24_PATH)
wave27 = load(WAVE27_PATH)
wave28 = load(WAVE28_PATH)
wave30 = load(WAVE30_PATH)
wave8_withdrawal = load(WAVE8_WITHDRAWAL_PATH)

require(sha_file(MANIFEST_PATH) == EXPECTED_MANIFEST_SHA256, "902 manifest SHA-256 mismatch")
require(sha_file(WAVE4_PATH) == EXPECTED_WAVE4_SHA256, "Wave-4 adjudication SHA-256 mismatch")
require(sha_file(WAVE5_PATH) == EXPECTED_WAVE5_SHA256, "Wave-5 adjudication SHA-256 mismatch")
require(sha_file(WAVE6_PATH) == EXPECTED_WAVE6_SHA256, "Wave-6 adjudication SHA-256 mismatch")
require(sha_file(WAVE7_PATH) == EXPECTED_WAVE7_SHA256, "Wave-7 adjudication SHA-256 mismatch")
require(sha_file(WAVE8_PATH) == EXPECTED_WAVE8_SHA256, "Wave-8 adjudication SHA-256 mismatch")
require(sha_file(WAVE9_PATH) == EXPECTED_WAVE9_SHA256, "Wave-9 adjudication SHA-256 mismatch")
require(sha_file(WAVE10_PATH) == EXPECTED_WAVE10_SHA256, "Wave-10 adjudication SHA-256 mismatch")
require(sha_file(WAVE11_PATH) == EXPECTED_WAVE11_SHA256, "Wave-11 adjudication SHA-256 mismatch")
require(sha_file(WAVE12_PATH) == EXPECTED_WAVE12_SHA256, "Wave-12 adjudication SHA-256 mismatch")
require(sha_file(WAVE13_PATH) == EXPECTED_WAVE13_SHA256, "Wave-13 adjudication SHA-256 mismatch")
require(sha_file(WAVE14_PATH) == EXPECTED_WAVE14_SHA256, "Wave-14 adjudication SHA-256 mismatch")
require(sha_file(WAVE15_PATH) == EXPECTED_WAVE15_SHA256, "Wave-15 adjudication SHA-256 mismatch")
require(sha_file(WAVE16_PATH) == EXPECTED_WAVE16_SHA256, "Wave-16 adjudication SHA-256 mismatch")
require(sha_file(WAVE17_PATH) == EXPECTED_WAVE17_SHA256, "Wave-17 adjudication SHA-256 mismatch")
require(sha_file(WAVE18_PATH) == EXPECTED_WAVE18_SHA256, "Wave-18 adjudication SHA-256 mismatch")
require(sha_file(WAVE19_PATH) == EXPECTED_WAVE19_SHA256, "Wave-19 adjudication SHA-256 mismatch")
require(sha_file(WAVE20_PATH) == EXPECTED_WAVE20_SHA256, "Wave-20 adjudication SHA-256 mismatch")
require(sha_file(WAVE21_PATH) == EXPECTED_WAVE21_SHA256, "Wave-21 adjudication SHA-256 mismatch")
require(sha_file(WAVE22_PATH) == EXPECTED_WAVE22_SHA256, "Wave-22 adjudication SHA-256 mismatch")
require(sha_file(WAVE23_PATH) == EXPECTED_WAVE23_SHA256, "Wave-23 adjudication SHA-256 mismatch")
require(sha_file(WAVE24_PATH) == EXPECTED_WAVE24_SHA256, "Wave-24 adjudication SHA-256 mismatch")
require(sha_file(WAVE27_PATH) == EXPECTED_WAVE27_SHA256, "Wave-27 adjudication SHA-256 mismatch")
require(sha_file(WAVE28_PATH) == EXPECTED_WAVE28_SHA256, "Wave-28 adjudication SHA-256 mismatch")
require(sha_file(WAVE30_PATH) == EXPECTED_WAVE30_SHA256, "Wave-30 adjudication SHA-256 mismatch")
require(
    sha_file(WAVE8_WITHDRAWAL_PATH) == EXPECTED_WAVE8_WITHDRAWAL_SHA256,
    "Wave-8 NCM withdrawal adjudication SHA-256 mismatch",
)
require(
    sha_file(OUTPUT_PATH) in {
        EXPECTED_POST_WAVE5_MAPPING_SHA256,
        EXPECTED_POST_WAVE6_MAPPING_SHA256,
        EXPECTED_POST_WAVE7_MAPPING_SHA256,
        EXPECTED_POST_WAVE8_MAPPING_SHA256,
        EXPECTED_POST_WAVE9_MAPPING_SHA256,
        EXPECTED_POST_WAVE10_MAPPING_SHA256,
        EXPECTED_POST_WAVE11_MAPPING_SHA256,
        EXPECTED_POST_WAVE12_MAPPING_SHA256,
        EXPECTED_PRE_PAGE_REFRESH_MAPPING_SHA256,
        EXPECTED_POST_WAVE13_MAPPING_SHA256,
        EXPECTED_POST_WITHDRAWAL_MAPPING_SHA256,
        EXPECTED_POST_WAVE14_MAPPING_SHA256,
        EXPECTED_POST_WAVE16_MAPPING_SHA256,
        EXPECTED_POST_WAVE17_MAPPING_SHA256,
        EXPECTED_POST_WAVE18_MAPPING_SHA256,
        EXPECTED_POST_WAVE19_MAPPING_SHA256,
        EXPECTED_POST_WAVE20_MAPPING_SHA256,
        EXPECTED_POST_WAVE21_MAPPING_SHA256,
        EXPECTED_POST_WAVE22_MAPPING_SHA256,
        EXPECTED_POST_WAVE23_MAPPING_SHA256,
        EXPECTED_POST_WAVE24_MAPPING_SHA256,
        EXPECTED_POST_WAVE27_MAPPING_SHA256,
        EXPECTED_POST_WAVE28_MAPPING_SHA256,
        EXPECTED_POST_WAVE30_MAPPING_SHA256,
    },
    "Mapping file is not an accepted pre-wave or deterministic post-wave output",
)
require(manifest.get("audited_commit") == EXPECTED_COMMIT, "Manifest commit mismatch")
require(prior.get("audited_commit") == EXPECTED_COMMIT, "Prior mapping commit mismatch")
require(prior.get("artifact") == "benchmark-final-901-mapping", "Unexpected prior mapping artifact")
require(wave4.get("audited_commit") == EXPECTED_COMMIT, "Wave-4 audited commit mismatch")
require(wave4.get("artifact") == "benchmark-target-specific-adjudication-902-wave4", "Unexpected wave-4 artifact")
require(wave5.get("audited_commit") == EXPECTED_COMMIT, "Wave-5 audited commit mismatch")
require(wave5.get("artifact") == "benchmark-target-specific-adjudication-902-wave5", "Unexpected wave-5 artifact")
require(wave6.get("audited_commit") == EXPECTED_COMMIT, "Wave-6 audited commit mismatch")
require(wave6.get("artifact") == "benchmark-target-specific-adjudication-902-wave6", "Unexpected wave-6 artifact")
require(wave7.get("audited_commit") == EXPECTED_COMMIT, "Wave-7 audited commit mismatch")
require(wave7.get("artifact") == "benchmark-target-specific-adjudication-902-wave7", "Unexpected wave-7 artifact")
require(wave8.get("audited_commit") == EXPECTED_COMMIT, "Wave-8 audited commit mismatch")
require(wave8.get("artifact") == "benchmark-target-specific-adjudication-902-wave8", "Unexpected wave-8 artifact")
require(wave9.get("audited_commit") == EXPECTED_COMMIT, "Wave-9 audited commit mismatch")
require(wave9.get("artifact") == "benchmark-target-specific-adjudication-902-wave9", "Unexpected wave-9 artifact")
require(wave10.get("audited_commit") == EXPECTED_COMMIT, "Wave-10 audited commit mismatch")
require(wave10.get("artifact") == "benchmark-target-specific-adjudication-902-wave10", "Unexpected wave-10 artifact")
require(wave11.get("audited_commit") == EXPECTED_COMMIT, "Wave-11 audited commit mismatch")
require(wave11.get("artifact") == "benchmark-target-specific-adjudication-902-wave11", "Unexpected wave-11 artifact")
require(wave12.get("audited_commit") == EXPECTED_COMMIT, "Wave-12 audited commit mismatch")
require(wave12.get("artifact") == "benchmark-target-specific-adjudication-902-wave12", "Unexpected wave-12 artifact")
require(wave13.get("audited_commit") == EXPECTED_COMMIT, "Wave-13 audited commit mismatch")
require(wave13.get("artifact") == "benchmark-target-specific-adjudication-902-wave13", "Unexpected wave-13 artifact")
require(wave14.get("audited_commit") == EXPECTED_COMMIT, "Wave-14 audited commit mismatch")
require(wave14.get("artifact") == "benchmark-target-specific-adjudication-902-wave14", "Unexpected wave-14 artifact")
require(wave15.get("audited_commit") == EXPECTED_COMMIT, "Wave-15 audited commit mismatch")
require(wave15.get("artifact") == "benchmark-target-specific-adjudication-902-wave15", "Unexpected wave-15 artifact")
require(wave16.get("audited_commit") == EXPECTED_COMMIT, "Wave-16 audited commit mismatch")
require(wave16.get("artifact") == "benchmark-target-specific-adjudication-902-wave16", "Unexpected wave-16 artifact")
require(wave17.get("audited_commit") == EXPECTED_COMMIT, "Wave-17 audited commit mismatch")
require(wave17.get("artifact") == "benchmark-target-specific-adjudication-902-wave17", "Unexpected wave-17 artifact")
require(wave18.get("audited_commit") == EXPECTED_COMMIT, "Wave-18 audited commit mismatch")
require(wave18.get("artifact") == "benchmark-target-specific-adjudication-902-wave18", "Unexpected wave-18 artifact")
require(wave19.get("audited_commit") == EXPECTED_COMMIT, "Wave-19 audited commit mismatch")
require(wave19.get("artifact") == "benchmark-target-specific-adjudication-902-wave19", "Unexpected wave-19 artifact")
require(wave20.get("audited_commit") == EXPECTED_COMMIT, "Wave-20 audited commit mismatch")
require(wave20.get("artifact") == "benchmark-target-specific-adjudication-902-wave20", "Unexpected wave-20 artifact")
require(wave21.get("audited_commit") == EXPECTED_COMMIT, "Wave-21 audited commit mismatch")
require(wave21.get("artifact") == "benchmark-target-specific-adjudication-902-wave21", "Unexpected wave-21 artifact")
require(wave22.get("audited_commit") == EXPECTED_COMMIT, "Wave-22 audited commit mismatch")
require(wave22.get("artifact") == "benchmark-target-specific-adjudication-902-wave22", "Unexpected wave-22 artifact")
require(wave23.get("audited_commit") == EXPECTED_COMMIT, "Wave-23 audited commit mismatch")
require(wave23.get("artifact") == "benchmark-target-specific-adjudication-902-wave23", "Unexpected wave-23 artifact")
require(wave24.get("audited_commit") == EXPECTED_COMMIT, "Wave-24 audited commit mismatch")
require(wave24.get("artifact") == "benchmark-target-specific-adjudication-902-wave24", "Unexpected wave-24 artifact")
require(wave27.get("audited_commit") == EXPECTED_COMMIT, "Wave-27 audited commit mismatch")
require(wave27.get("artifact") == "benchmark-target-specific-adjudication-902-wave27", "Unexpected wave-27 artifact")
require(wave28.get("audited_commit") == EXPECTED_COMMIT, "Wave-28 audited commit mismatch")
require(wave28.get("artifact") == "benchmark-target-specific-adjudication-902-wave28", "Unexpected wave-28 artifact")
require(wave30.get("audited_commit") == EXPECTED_COMMIT, "Wave-30 audited commit mismatch")
require(wave30.get("artifact") == "benchmark-target-specific-adjudication-902-wave30", "Unexpected wave-30 artifact")
require(wave8_withdrawal.get("audited_commit") == EXPECTED_COMMIT, "Wave-8 withdrawal audited commit mismatch")
require(
    wave8_withdrawal.get("artifact") == "benchmark-wave8-ncm-withdrawal-adjudication",
    "Unexpected Wave-8 withdrawal artifact",
)
require(
    wave13.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE12_MAPPING_SHA256,
    "Wave-13 base mapping pin mismatch",
)
require(
    wave14.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WITHDRAWAL_MAPPING_SHA256,
    "Wave-14 base mapping pin mismatch",
)
require(
    wave15.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE14_MAPPING_SHA256,
    "Wave-15 base mapping pin mismatch",
)
require(
    wave16.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE14_MAPPING_SHA256,
    "Wave-16 base mapping pin mismatch",
)
require(
    wave17.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE16_MAPPING_SHA256,
    "Wave-17 base mapping pin mismatch",
)
require(
    wave18.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE17_MAPPING_SHA256,
    "Wave-18 base mapping pin mismatch",
)
require(
    wave19.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE18_MAPPING_SHA256,
    "Wave-19 base mapping pin mismatch",
)
require(
    wave20.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE19_MAPPING_SHA256,
    "Wave-20 base mapping pin mismatch",
)
require(
    wave21.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE20_MAPPING_SHA256,
    "Wave-21 base mapping pin mismatch",
)
require(
    wave22.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE21_MAPPING_SHA256,
    "Wave-22 base mapping pin mismatch",
)
require(
    wave23.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE22_MAPPING_SHA256,
    "Wave-23 base mapping pin mismatch",
)
require(
    wave24.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE23_MAPPING_SHA256,
    "Wave-24 base mapping pin mismatch",
)
require(
    wave27.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE24_MAPPING_SHA256,
    "Wave-27 base mapping pin mismatch",
)
require(
    wave28.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE27_MAPPING_SHA256,
    "Wave-28 base mapping pin mismatch",
)
require(
    wave30.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE28_MAPPING_SHA256,
    "Wave-30 base mapping pin mismatch",
)
require(
    wave12.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE11_MAPPING_SHA256,
    "Wave-12 base mapping pin mismatch",
)
require(
    wave11.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE10_MAPPING_SHA256,
    "Wave-11 base mapping pin mismatch",
)
require(
    wave10.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE9_MAPPING_SHA256,
    "Wave-10 base mapping pin mismatch",
)
require(
    wave9.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE8_MAPPING_SHA256,
    "Wave-9 base mapping pin mismatch",
)
require(
    wave8.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE7_MAPPING_SHA256,
    "Wave-8 base mapping pin mismatch",
)
require(
    wave4.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_PRE_WAVE_MAPPING_SHA256,
    "Wave-4 pre-wave mapping pin mismatch",
)
require(
    wave5.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_PRE_WAVE5_MAPPING_SHA256,
    "Wave-5 pre-wave mapping pin mismatch",
)
require(
    wave6.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE5_MAPPING_SHA256,
    "Wave-6 pre-wave mapping pin mismatch",
)
require(
    wave7.get("input_pins", {}).get("benchmark_final_902_before_wave", {}).get("file_sha256")
    == EXPECTED_POST_WAVE6_MAPPING_SHA256,
    "Wave-7 pre-wave mapping pin mismatch",
)

manifest_rows = list(manifest.get("targets", []))
manifest_by_key = {str(row["working_key"]): row for row in manifest_rows}
require(len(manifest_rows) == len(manifest_by_key) == 902, "Manifest is not exactly 902 unique targets")
require(manifest.get("counts", {}).get("total") == 902, "Manifest total count mismatch")
require(
    {name: manifest.get("counts", {}).get(name) for name in ("H", "D", "M")}
    == {"H": 788, "D": 111, "M": 3},
    "Manifest class partition mismatch",
)

prior_rows = list(prior.get("targets", []))
prior_by_key = {str(row["working_key"]): row for row in prior_rows}
require(len(prior_rows) == len(prior_by_key) == 901, "Prior mapping is not exactly 901 unique targets")
require(set(manifest_by_key) - set(prior_by_key) == {NEW_KEY}, "902 manifest does not add exactly the expected target")
require(not (set(prior_by_key) - set(manifest_by_key)), "Prior mapping has targets absent from the 902 manifest")

# Existing adjudications and their identity snapshots must remain valid and are
# intentionally copied without changing source units, evidence, or credit.
for key, row in prior_by_key.items():
    identity = manifest_by_key[key]
    require(row.get("id_status") == identity.get("id_status"), f"ID status drift: {key}")
    require(row.get("class") == identity.get("class"), f"Class drift: {key}")
    require(row.get("canonical_module") == identity.get("canonical_module"), f"Module drift: {key}")
    require(
        strings(row.get("source_family_ids", [])) == strings(identity.get("source_family_ids", [])),
        f"Source-family lineage drift: {key}",
    )

new_identity = manifest_by_key[NEW_KEY]
require(new_identity.get("id_status") == "audit_assigned_stable_name", "New target ID status mismatch")
require(new_identity.get("class") == "M", "New target must be class M")
require(new_identity.get("canonical_module") == "CONTROL_ROOM", "New target module mismatch")
require(strings(new_identity.get("source_family_ids", [])) == ["CR-ALERT"], "New target lineage mismatch")

rows = copy.deepcopy(prior_rows)
rows.append({
    "working_key": NEW_KEY,
    "id_status": new_identity.get("id_status"),
    "class": new_identity.get("class"),
    "canonical_module": new_identity.get("canonical_module"),
    "source_family_ids": strings(new_identity.get("source_family_ids", [])),
    "status": "unproved_audit_assigned_id",
    "inheritance_method": "none_new_902_target_without_completed_target_specific_adjudication",
    "prior_outcome": None,
    "source_units": [],
    "evidence_loci": [],
    "completion_credit": False,
})
rows.sort(key=lambda row: str(row["working_key"]))
row_by_key = {str(row["working_key"]): row for row in rows}

require(len(rows) == len(row_by_key) == 902, "Output mapping is not exactly 902 unique targets")
require(set(row_by_key) == set(manifest_by_key), "Output target set differs from the manifest")
require(row_by_key[NEW_KEY]["completion_credit"] is False, "New target must not receive completion credit")
require(not row_by_key[NEW_KEY]["source_units"], "New target must not inherit a source unit")
require(not row_by_key[NEW_KEY]["evidence_loci"], "New target must not inherit evidence loci")
require(
    {str(row["working_key"]): row for row in rows if row["working_key"] != NEW_KEY} == prior_by_key,
    "A prior 901 adjudication changed during the 902 lift",
)

# Independent review accepted only the twelve direct material matches. The
# twelve NCM research candidates remain explicitly uncredited until their
# broader-search evidence is complete.
wave4_evaluations = list(wave4.get("evaluations", []))
require(len(wave4_evaluations) == 24, "Wave-4 must contain exactly 24 evaluations")
wave4_direct = {
    str(item["working_key"]): item
    for item in wave4_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
wave4_pending_ncm = {
    str(item["working_key"]): item
    for item in wave4_evaluations
    if item.get("candidate_status") == "ncm_research_pending"
    and item.get("completion_credit_recommended") is False
}
require(len(wave4_direct) == 12, "Wave-4 direct-only accepted set must contain 12 targets")
require(len(wave4_pending_ncm) == 12, "Wave-4 pending NCM set must contain 12 targets")
require(not (set(wave4_direct) & set(wave4_pending_ncm)), "Wave-4 direct and pending-NCM sets overlap")

for key, evaluation in wave4_direct.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-4 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"),
        "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-4 manifest lineage mismatch: {key}")
    require(current.get("completion_credit") is False, f"Wave-4 target already has completion credit: {key}")
    require(str(current.get("status")) == "unproved", f"Wave-4 target is not ordinary unproved: {key}")
    benchmark = evaluation.get("benchmark", {})
    require(benchmark.get("official_repository_url"), f"Wave-4 repository URL missing: {key}")
    require(len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-4 commit SHA missing: {key}")
    require(strings(benchmark.get("source_loci", [])), f"Wave-4 source loci missing: {key}")
    require(benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-4 slice/limits missing: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-4 evidence loci missing: {key}")
    current["status"] = "verified_benchmark_direct"
    current["inheritance_method"] = "fresh_target_specific_wave4_direct"
    current["prior_outcome"] = "unproved"
    current["source_units"] = [f"fresh-902-wave4:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True

# The pending NCM rows must stay byte-for-byte equivalent to their pre-wave
# mapping values and must not receive source units or evidence in the final map.
for key in wave4_pending_ncm:
    require(row_by_key[key] == prior_by_key[key], f"Pending wave-4 NCM target changed: {key}")

# Wave 5 contains twelve fresh direct material slices. Apply them only after
# the complete wave-4 reconstruction, and require exact current manifest
# lineage for every accepted target.
wave5_evaluations = list(wave5.get("evaluations", []))
require(len(wave5_evaluations) == 12, "Wave-5 must contain exactly 12 evaluations")
wave5_direct = {
    str(item["working_key"]): item
    for item in wave5_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
require(len(wave5_direct) == 12, "Wave-5 accepted direct set must contain 12 targets")
require(not (set(wave5_direct) & set(wave4_direct)), "Wave-4 and wave-5 direct sets overlap")

for key, evaluation in wave5_direct.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-5 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"),
        "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-5 manifest lineage mismatch: {key}")
    require(current.get("completion_credit") is False, f"Wave-5 target already has completion credit: {key}")
    require(str(current.get("status")) == "unproved", f"Wave-5 target is not ordinary unproved: {key}")
    benchmark = evaluation.get("benchmark", {})
    require(benchmark.get("official_repository_url"), f"Wave-5 repository URL missing: {key}")
    require(len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-5 commit SHA missing: {key}")
    require(strings(benchmark.get("source_loci", [])), f"Wave-5 source loci missing: {key}")
    require(benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-5 slice/limits missing: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-5 evidence loci missing: {key}")
    current["status"] = "verified_benchmark_direct"
    current["inheritance_method"] = "fresh_target_specific_wave5_direct"
    current["prior_outcome"] = "unproved"
    current["source_units"] = [f"fresh-902-wave5:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True

# Wave 6 contains twelve additional exact-target direct material slices.
wave6_evaluations = list(wave6.get("evaluations", []))
require(len(wave6_evaluations) == 12, "Wave-6 must contain exactly 12 evaluations")
wave6_direct = {
    str(item["working_key"]): item
    for item in wave6_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
require(len(wave6_direct) == 12, "Wave-6 accepted direct set must contain 12 targets")
require(not (set(wave6_direct) & (set(wave4_direct) | set(wave5_direct))), "Wave-6 target overlap")

for key, evaluation in wave6_direct.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-6 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"),
        "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-6 manifest lineage mismatch: {key}")
    require(current.get("completion_credit") is False, f"Wave-6 target already has completion credit: {key}")
    expected_prior_status = "unproved_pending" if key == "FIN-BANK-ACCOUNT" else "unproved"
    require(str(current.get("status")) == expected_prior_status, f"Wave-6 target has unexpected prior status: {key}")
    benchmark = evaluation.get("benchmark", {})
    require(benchmark.get("official_repository_url"), f"Wave-6 repository URL missing: {key}")
    require(len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-6 commit SHA missing: {key}")
    require(strings(benchmark.get("source_loci", [])), f"Wave-6 source loci missing: {key}")
    require(benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-6 slice/limits missing: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-6 evidence loci missing: {key}")
    current["status"] = "verified_benchmark_direct"
    current["inheritance_method"] = "fresh_target_specific_wave6_direct"
    current["prior_outcome"] = expected_prior_status
    current["source_units"] = [f"fresh-902-wave6:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True

# Wave 7 contains twelve additional exact-target Frappe HR material slices.
wave7_evaluations = list(wave7.get("evaluations", []))
require(len(wave7_evaluations) == 12, "Wave-7 must contain exactly 12 evaluations")
wave7_direct = {
    str(item["working_key"]): item
    for item in wave7_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
require(len(wave7_direct) == 12, "Wave-7 accepted direct set must contain 12 targets")
require(
    not (set(wave7_direct) & (set(wave4_direct) | set(wave5_direct) | set(wave6_direct))),
    "Wave-7 target overlap",
)

for key, evaluation in wave7_direct.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-7 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"),
        "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-7 manifest lineage mismatch: {key}")
    require(current.get("completion_credit") is False, f"Wave-7 target already has completion credit: {key}")
    expected_prior_status = str(evaluation.get("prior_status"))
    require(expected_prior_status in {"unproved", "unproved_pending"}, f"Wave-7 bad prior status: {key}")
    require(str(current.get("status")) == expected_prior_status, f"Wave-7 target has unexpected prior status: {key}")
    benchmark = evaluation.get("benchmark", {})
    require(benchmark.get("official_repository_url"), f"Wave-7 repository URL missing: {key}")
    require(len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-7 commit SHA missing: {key}")
    require(strings(benchmark.get("source_loci", [])), f"Wave-7 source loci missing: {key}")
    require(benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-7 slice/limits missing: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-7 evidence loci missing: {key}")
    current["status"] = "verified_benchmark_direct"
    current["inheritance_method"] = "fresh_target_specific_wave7_direct"
    current["prior_outcome"] = expected_prior_status
    current["source_units"] = [f"fresh-902-wave7:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True

wave8_evaluations = list(wave8.get("evaluations", []))
require(len(wave8_evaluations) == 12, "Wave-8 must contain exactly 12 evaluations")
wave8_direct = {
    str(item["working_key"]): item for item in wave8_evaluations
    if item.get("candidate_status") == "candidate_found_direct" and item.get("completion_credit_recommended") is True
}
wave8_ncm = {
    str(item["working_key"]): item for item in wave8_evaluations
    if item.get("candidate_status") == "documented_ncm_direct" and item.get("completion_credit_recommended") is True
}
require(len(wave8_direct) == 10 and len(wave8_ncm) == 2, "Wave-8 must contain 10 direct and 2 NCM outcomes")
prior_wave_keys = set(wave4_direct) | set(wave5_direct) | set(wave6_direct) | set(wave7_direct)
require(not ((set(wave8_direct) | set(wave8_ncm)) & prior_wave_keys), "Wave-8 target overlap")

for key, evaluation in {**wave8_direct, **wave8_ncm}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-8 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"), "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-8 manifest lineage mismatch: {key}")
    require(current.get("completion_credit") is False and current.get("status") == "unproved", f"Wave-8 target is not ordinary unproved: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-8 evidence loci missing: {key}")
    current["status"] = "verified_benchmark_direct" if key in wave8_direct else "documented_ncm_direct"
    current["inheritance_method"] = "fresh_target_specific_wave8_direct" if key in wave8_direct else "fresh_target_specific_wave8_documented_ncm"
    current["prior_outcome"] = "unproved"
    current["source_units"] = [f"fresh-902-wave8:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True
    if key in wave8_direct:
        benchmark = evaluation.get("benchmark", {})
        require(benchmark.get("official_repository_url") and len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-8 direct repository pin missing: {key}")
        require(strings(benchmark.get("source_loci", [])) and benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-8 direct evidence fields missing: {key}")
    else:
        require(len(evaluation.get("rejected_repositories", [])) >= 2 and evaluation.get("bounded_ncm_reason"), f"Wave-8 NCM corpus/reason missing: {key}")

wave9_evaluations = list(wave9.get("evaluations", []))
require(len(wave9_evaluations) == 12, "Wave-9 must contain exactly 12 evaluations")
wave9_direct = {
    str(item["working_key"]): item for item in wave9_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
require(len(wave9_direct) == 12, "Wave-9 must contain 12 direct outcomes")
prior_wave_keys |= set(wave8_direct) | set(wave8_ncm)
require(not (set(wave9_direct) & prior_wave_keys), "Wave-9 target overlap")

for key, evaluation in wave9_direct.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-9 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"), "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-9 manifest lineage mismatch: {key}")
    prior_status = str(evaluation.get("prior_status"))
    require(prior_status in {"unproved", "unproved_pending"}, f"Wave-9 prior status invalid: {key}")
    require(current.get("completion_credit") is False and current.get("status") == prior_status, f"Wave-9 target prior state drift: {key}")
    benchmark = evaluation.get("benchmark", {})
    require(benchmark.get("official_repository_url") and len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-9 repository pin missing: {key}")
    require(benchmark.get("spdx") and benchmark.get("edition_boundary"), f"Wave-9 licence/edition boundary missing: {key}")
    require(benchmark.get("exact_loci") and benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-9 material evidence missing: {key}")
    require(all(item.get("path") and item.get("lines") and len(str(item.get("sha256", ""))) == 64 for item in benchmark["exact_loci"]), f"Wave-9 exact locus pin missing: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-9 evidence loci missing: {key}")
    current["status"] = "verified_benchmark_direct"
    current["inheritance_method"] = "fresh_target_specific_wave9_direct"
    current["prior_outcome"] = prior_status
    current["source_units"] = [f"fresh-902-wave9:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True

wave10_evaluations = list(wave10.get("evaluations", []))
require(len(wave10_evaluations) == 12, "Wave-10 must contain exactly 12 evaluations")
wave10_direct = {
    str(item["working_key"]): item for item in wave10_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
require(len(wave10_direct) == 12, "Wave-10 must contain 12 direct outcomes")
prior_wave_keys |= set(wave9_direct)
require(not (set(wave10_direct) & prior_wave_keys), "Wave-10 target overlap")

for key, evaluation in wave10_direct.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-10 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"), "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-10 manifest lineage mismatch: {key}")
    require(current.get("completion_credit") is False and current.get("status") == "unproved", f"Wave-10 target prior state drift: {key}")
    benchmark = evaluation.get("benchmark", {})
    require(benchmark.get("official_repository_url") and len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-10 repository pin missing: {key}")
    require(benchmark.get("spdx") and benchmark.get("edition_boundary"), f"Wave-10 licence/edition boundary missing: {key}")
    require(benchmark.get("exact_loci") and benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-10 material evidence missing: {key}")
    require(all(item.get("path") and item.get("lines") and len(str(item.get("sha256", ""))) == 64 for item in benchmark["exact_loci"]), f"Wave-10 exact locus pin missing: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-10 evidence loci missing: {key}")
    current["status"] = "verified_benchmark_direct"
    current["inheritance_method"] = "fresh_target_specific_wave10_direct"
    current["prior_outcome"] = "unproved"
    current["source_units"] = [f"fresh-902-wave10:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True

wave11_evaluations = list(wave11.get("evaluations", []))
require(len(wave11_evaluations) == 12, "Wave-11 must contain exactly 12 evaluations")
wave11_direct = {
    str(item["working_key"]): item for item in wave11_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
require(len(wave11_direct) == 12, "Wave-11 must contain 12 direct outcomes")
prior_wave_keys |= set(wave10_direct)
require(not (set(wave11_direct) & prior_wave_keys), "Wave-11 target-key overlap")
require(
    len(wave11.get("source_slice_reuse_disclosure", [])) == 8,
    "Wave-11 source-slice reuse disclosure drift",
)

for key, evaluation in wave11_direct.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-11 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"), "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-11 manifest lineage mismatch: {key}")
    prior_status = str(evaluation.get("prior_status"))
    require(
        prior_status in {"unproved", "unproved_audit_assigned_id"},
        f"Wave-11 prior status invalid: {key}",
    )
    require(
        current.get("completion_credit") is False and current.get("status") == prior_status,
        f"Wave-11 target prior state drift: {key}",
    )
    benchmark = evaluation.get("benchmark", {})
    require(benchmark.get("official_repository_url") and len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-11 repository pin missing: {key}")
    require(benchmark.get("spdx") and benchmark.get("edition_boundary"), f"Wave-11 licence/edition boundary missing: {key}")
    require(benchmark.get("exact_loci") and benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-11 material evidence missing: {key}")
    require(all(item.get("path") and item.get("lines") and len(str(item.get("sha256", ""))) == 64 for item in benchmark["exact_loci"]), f"Wave-11 exact locus pin missing: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-11 evidence loci missing: {key}")
    current["status"] = "verified_benchmark_direct"
    current["inheritance_method"] = "fresh_target_specific_wave11_direct"
    current["prior_outcome"] = prior_status
    current["source_units"] = [f"fresh-902-wave11:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True

wave12_evaluations = list(wave12.get("evaluations", []))
require(len(wave12_evaluations) == 12, "Wave-12 must contain exactly 12 evaluations")
wave12_direct = {
    str(item["working_key"]): item for item in wave12_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
wave12_ncm = {
    str(item["working_key"]): item for item in wave12_evaluations
    if item.get("candidate_status") == "documented_ncm_direct"
    and item.get("completion_credit_recommended") is True
}
require((len(wave12_direct), len(wave12_ncm)) == (8, 4), "Wave-12 must contain 8 direct and 4 NCM outcomes")
prior_wave_keys |= set(wave11_direct)
require(not ((set(wave12_direct) | set(wave12_ncm)) & prior_wave_keys), "Wave-12 target-key overlap")

for key, evaluation in {**wave12_direct, **wave12_ncm}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-12 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"), "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-12 manifest lineage mismatch: {key}")
    require(evaluation.get("prior_status") == "unproved", f"Wave-12 prior status invalid: {key}")
    require(current.get("completion_credit") is False and current.get("status") == "unproved", f"Wave-12 target prior state drift: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-12 evidence loci missing: {key}")
    if key in wave12_direct:
        benchmark = evaluation.get("benchmark", {})
        require(benchmark.get("official_repository_url") and len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-12 repository pin missing: {key}")
        require(benchmark.get("spdx") and benchmark.get("edition_boundary"), f"Wave-12 licence/edition boundary missing: {key}")
        require(benchmark.get("exact_loci") and benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-12 material evidence missing: {key}")
        require(all(item.get("path") and item.get("lines") and len(str(item.get("sha256", ""))) == 64 for item in benchmark["exact_loci"]), f"Wave-12 exact locus pin missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave12_direct"
    else:
        rejected = list(evaluation.get("rejected_repositories", []))
        require(len({row.get("official_repository_url") for row in rejected}) >= 2, f"Wave-12 NCM corpus too narrow: {key}")
        require(evaluation.get("bounded_ncm_reason"), f"Wave-12 NCM reason missing: {key}")
        require(all(row.get("reason") and row.get("source_loci") and row.get("spdx") and row.get("edition_boundary") for row in rejected), f"Wave-12 NCM evidence incomplete: {key}")
        current["status"] = "documented_ncm_direct"
        current["inheritance_method"] = "fresh_target_specific_wave12_documented_ncm"
    current["prior_outcome"] = "unproved"
    current["source_units"] = [f"fresh-902-wave12:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True

wave13_evaluations = list(wave13.get("evaluations", []))
require(len(wave13_evaluations) == 12, "Wave-13 must contain exactly 12 evaluations")
wave13_direct = {
    str(item["working_key"]): item for item in wave13_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
wave13_ncm = {
    str(item["working_key"]): item for item in wave13_evaluations
    if item.get("candidate_status") == "documented_ncm_direct"
    and item.get("completion_credit_recommended") is True
}
require((len(wave13_direct), len(wave13_ncm)) == (11, 1), "Wave-13 must contain 11 direct and 1 NCM outcomes")
prior_wave_keys |= set(wave12_direct) | set(wave12_ncm)
require(not ((set(wave13_direct) | set(wave13_ncm)) & prior_wave_keys), "Wave-13 target-key overlap")

for key, evaluation in {**wave13_direct, **wave13_ncm}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-13 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"), "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-13 manifest lineage mismatch: {key}")
    require(evaluation.get("prior_status") == "unproved", f"Wave-13 prior status invalid: {key}")
    require(current.get("completion_credit") is False and current.get("status") == "unproved", f"Wave-13 target prior state drift: {key}")
    require(strings(evaluation.get("evidence_loci", [])), f"Wave-13 evidence loci missing: {key}")
    if key in wave13_direct:
        benchmark = evaluation.get("benchmark", {})
        require(benchmark.get("official_repository_url") and len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-13 repository pin missing: {key}")
        require(benchmark.get("spdx") and benchmark.get("edition_boundary"), f"Wave-13 licence/edition boundary missing: {key}")
        require(benchmark.get("exact_loci") and benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-13 material evidence missing: {key}")
        require(all(item.get("path") and item.get("lines") and len(str(item.get("sha256", ""))) == 64 for item in benchmark["exact_loci"]), f"Wave-13 exact locus pin missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave13_direct"
    else:
        rejected = list(evaluation.get("rejected_repositories", []))
        require(len({row.get("official_repository_url") for row in rejected}) >= 2, f"Wave-13 NCM corpus too narrow: {key}")
        require(evaluation.get("bounded_ncm_reason"), f"Wave-13 NCM reason missing: {key}")
        require(all(row.get("reason") and row.get("source_loci") and row.get("spdx") and row.get("edition_boundary") for row in rejected), f"Wave-13 NCM evidence incomplete: {key}")
        current["status"] = "documented_ncm_direct"
        current["inheritance_method"] = "fresh_target_specific_wave13_documented_ncm"
    current["prior_outcome"] = "unproved"
    current["source_units"] = [f"fresh-902-wave13:{key}"]
    current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
    current["completion_credit"] = True

# A later independent catalogue-completeness review found that both Wave-8 NCM
# rows omitted relevant catalogue candidates. Preserve the historical Wave-8
# evidence, but restore these targets to their exact pre-wave unproved rows.
withdrawals = list(wave8_withdrawal.get("withdrawals", []))
withdrawal_by_key = {str(item["working_key"]): item for item in withdrawals}
withdrawn_keys = set(withdrawal_by_key)
require(
    withdrawn_keys == {"CAP-CLIN-BEHAVIOUR-REGISTER", "CAP-CR-EVIDENCE-PACK-ASSEMBLY"},
    "Unexpected Wave-8 NCM withdrawal key set",
)
require(withdrawn_keys == set(wave8_ncm), "Withdrawal set must equal the two historical Wave-8 NCM keys")
require(
    sha_lines([str(item.get("replacement_tuple", "")) for item in withdrawals])
    == "e2a8bd5b704427318ab8dcd8f1fd23ab9dec7049cab2b452058682dd3cf2bf60",
    "Wave-8 withdrawal replacement tuple hash mismatch",
)
for key, item in withdrawal_by_key.items():
    require(row_by_key[key].get("status") == "documented_ncm_direct", f"Wave-8 NCM status drift: {key}")
    require(row_by_key[key].get("completion_credit") is True, f"Wave-8 NCM credit drift: {key}")
    replacement = item.get("replacement", {})
    require(
        replacement == {
            "status": "unproved",
            "completion_credit": False,
            "inheritance_method": "none_no_strict_inheritance_basis",
            "prior_outcome": None,
            "source_units": [],
            "evidence_loci": [],
        },
        f"Unexpected withdrawal replacement payload: {key}",
    )
    require(prior_by_key[key].get("status") == "unproved", f"Withdrawn key was not originally unproved: {key}")
    require(prior_by_key[key].get("completion_credit") is False, f"Withdrawn key had prior completion credit: {key}")
    row_by_key[key] = copy.deepcopy(prior_by_key[key])

wave14_evaluations = list(wave14.get("evaluations", []))
require(len(wave14_evaluations) == 12, "Wave-14 must contain exactly 12 evaluations")
wave14_direct = {
    str(item["working_key"]): item for item in wave14_evaluations
    if item.get("candidate_status") == "candidate_found_direct"
    and item.get("completion_credit_recommended") is True
}
wave14_retained = {
    str(item["working_key"]): item for item in wave14_evaluations
    if item.get("candidate_status") == "retained_unproved_after_target_specific_review"
    and item.get("completion_credit_recommended") is False
}
require((len(wave14_direct), len(wave14_retained)) == (4, 8), "Wave-14 must contain 4 direct and 8 retained-unproved outcomes")
prior_wave_keys |= set(wave13_direct) | set(wave13_ncm)
require(not ((set(wave14_direct) | set(wave14_retained)) & prior_wave_keys), "Wave-14 target-key overlap")

for key, evaluation in {**wave14_direct, **wave14_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-14 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    identity = manifest_by_key[key]
    expected_lineage = {
        "id_status": identity.get("id_status"), "class": identity.get("class"),
        "canonical_module": identity.get("canonical_module"),
        "source_family_ids": strings(identity.get("source_family_ids", [])),
        "route_ids": strings(identity.get("route_ids", [])),
        "page_ids": strings(identity.get("page_ids", [])),
        "backend_anchors": strings(identity.get("backend_anchors", [])),
    }
    actual_lineage = copy.deepcopy(evaluation.get("current_source_lineage", {}))
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors"):
        actual_lineage[field] = strings(actual_lineage.get(field, []))
    require(actual_lineage == expected_lineage, f"Wave-14 manifest lineage mismatch: {key}")
    prior_status = str(evaluation.get("prior_status"))
    require(current.get("completion_credit") is False and current.get("status") == prior_status, f"Wave-14 target prior state drift: {key}")
    if key in wave14_direct:
        require(strings(evaluation.get("evidence_loci", [])), f"Wave-14 evidence loci missing: {key}")
        benchmark = evaluation.get("benchmark", {})
        require(benchmark.get("official_repository_url") and len(str(benchmark.get("commit_sha", ""))) == 40, f"Wave-14 repository pin missing: {key}")
        require(benchmark.get("spdx") and benchmark.get("edition_boundary"), f"Wave-14 licence/edition boundary missing: {key}")
        require(benchmark.get("exact_loci") and benchmark.get("proven_slice") and benchmark.get("parity_limits"), f"Wave-14 material evidence missing: {key}")
        require(all(item.get("path") and item.get("lines") and len(str(item.get("sha256", ""))) == 64 for item in benchmark["exact_loci"]), f"Wave-14 exact locus pin missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave14_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave14:{key}"]
        current["evidence_loci"] = strings(evaluation.get("evidence_loci", []))
        current["completion_credit"] = True
    else:
        require(evaluation.get("bounded_reason"), f"Wave-14 retained-unproved reason missing: {key}")

wave15_evaluations = list(wave15.get("evaluations", []))
require(len(wave15_evaluations) == 12, "Wave-15 must contain exactly 12 evaluations")
require(
    all(
        item.get("candidate_status") == "retained_unproved"
        and item.get("completion_credit_recommended") is False
        and row_by_key[str(item["working_key"])].get("completion_credit") is False
        for item in wave15_evaluations
    ),
    "Wave-15 must remain a zero-credit adjudication",
)

wave16_evaluations = list(wave16.get("evaluations", []))
require(len(wave16_evaluations) == 7, "Wave-16 must contain exactly 7 evaluations")
wave16_direct = {
    str(item["working_key"]): item for item in wave16_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave16_retained = {
    str(item["working_key"]): item for item in wave16_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave16_direct), len(wave16_retained)) == (4, 3), "Wave-16 must contain 4 direct and 3 retained-unproved outcomes")
require(
    not ((set(wave16_direct) | set(wave16_retained)) & (prior_wave_keys - withdrawn_keys)),
    "Wave-16 target-key overlap outside the explicitly withdrawn Wave-8 keys",
)

for key, evaluation in {**wave16_direct, **wave16_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-16 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    require(current.get("completion_credit") is False and current.get("status") == "unproved", f"Wave-16 target prior state drift: {key}")
    if key in wave16_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-16 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-16 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave16_direct"
        current["prior_outcome"] = "unproved"
        current["source_units"] = [f"fresh-902-wave16:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-16 retained-unproved reason missing: {key}")

wave17_evaluations = list(wave17.get("evaluations", []))
require(len(wave17_evaluations) == 8, "Wave-17 must contain exactly 8 evaluations")
wave17_direct = {
    str(item["working_key"]): item for item in wave17_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave17_retained = {
    str(item["working_key"]): item for item in wave17_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave17_direct), len(wave17_retained)) == (2, 6), "Wave-17 must contain 2 direct and 6 retained-unproved outcomes")
require(
    not ((set(wave17_direct) | set(wave17_retained)) & set(wave16_direct)),
    "Wave-17 overlaps a Wave-16 credited target",
)

for key, evaluation in {**wave17_direct, **wave17_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-17 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id", "unproved_pending"},
        f"Wave-17 target prior state drift: {key}",
    )
    if key in wave17_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-17 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-17 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave17_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave17:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-17 retained-unproved reason missing: {key}")

wave18_evaluations = list(wave18.get("evaluations", []))
require(len(wave18_evaluations) == 8, "Wave-18 must contain exactly 8 evaluations")
wave18_direct = {
    str(item["working_key"]): item for item in wave18_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave18_retained = {
    str(item["working_key"]): item for item in wave18_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave18_direct), len(wave18_retained)) == (1, 7), "Wave-18 must contain 1 direct and 7 retained-unproved outcomes")
require(
    not ((set(wave18_direct) | set(wave18_retained)) & (set(wave17_direct) | set(wave17_retained))),
    "Wave-18 overlaps a Wave-17 target",
)

for key, evaluation in {**wave18_direct, **wave18_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-18 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id"},
        f"Wave-18 target prior state drift: {key}",
    )
    if key in wave18_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-18 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-18 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave18_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave18:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-18 retained-unproved reason missing: {key}")

wave19_evaluations = list(wave19.get("evaluations", []))
require(len(wave19_evaluations) == 8, "Wave-19 must contain exactly 8 evaluations")
wave19_direct = {
    str(item["working_key"]): item for item in wave19_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave19_retained = {
    str(item["working_key"]): item for item in wave19_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave19_direct), len(wave19_retained)) == (2, 6), "Wave-19 must contain 2 direct and 6 retained-unproved outcomes")
require(
    not ((set(wave19_direct) | set(wave19_retained)) & (set(wave18_direct) | set(wave18_retained))),
    "Wave-19 overlaps a Wave-18 target",
)

for key, evaluation in {**wave19_direct, **wave19_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-19 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id"},
        f"Wave-19 target prior state drift: {key}",
    )
    if key in wave19_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-19 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-19 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave19_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave19:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-19 retained-unproved reason missing: {key}")

wave20_evaluations = list(wave20.get("evaluations", []))
require(len(wave20_evaluations) == 8, "Wave-20 must contain exactly 8 evaluations")
wave20_direct = {
    str(item["working_key"]): item for item in wave20_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave20_retained = {
    str(item["working_key"]): item for item in wave20_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave20_direct), len(wave20_retained)) == (1, 7), "Wave-20 must contain 1 direct and 7 retained-unproved outcomes")
require(
    not ((set(wave20_direct) | set(wave20_retained)) & (set(wave19_direct) | set(wave19_retained))),
    "Wave-20 overlaps a Wave-19 target",
)

for key, evaluation in {**wave20_direct, **wave20_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-20 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id"},
        f"Wave-20 target prior state drift: {key}",
    )
    if key in wave20_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-20 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-20 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave20_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave20:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-20 retained-unproved reason missing: {key}")

wave21_evaluations = list(wave21.get("evaluations", []))
require(len(wave21_evaluations) == 8, "Wave-21 must contain exactly 8 evaluations")
wave21_direct = {
    str(item["working_key"]): item for item in wave21_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave21_retained = {
    str(item["working_key"]): item for item in wave21_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave21_direct), len(wave21_retained)) == (3, 5), "Wave-21 must contain 3 direct and 5 retained-unproved outcomes")
require(
    not ((set(wave21_direct) | set(wave21_retained)) & (set(wave20_direct) | set(wave20_retained))),
    "Wave-21 overlaps a Wave-20 target",
)

for key, evaluation in {**wave21_direct, **wave21_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-21 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id"},
        f"Wave-21 target prior state drift: {key}",
    )
    if key in wave21_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-21 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-21 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave21_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave21:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-21 retained-unproved reason missing: {key}")

wave22_evaluations = list(wave22.get("evaluations", []))
require(len(wave22_evaluations) == 8, "Wave-22 must contain exactly 8 evaluations")
wave22_direct = {
    str(item["working_key"]): item for item in wave22_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave22_retained = {
    str(item["working_key"]): item for item in wave22_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave22_direct), len(wave22_retained)) == (4, 4), "Wave-22 must contain 4 direct and 4 retained-unproved outcomes")
require(
    not ((set(wave22_direct) | set(wave22_retained)) & (set(wave21_direct) | set(wave21_retained))),
    "Wave-22 overlaps a Wave-21 target",
)

for key, evaluation in {**wave22_direct, **wave22_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-22 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id"},
        f"Wave-22 target prior state drift: {key}",
    )
    if key in wave22_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-22 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-22 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave22_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave22:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-22 retained-unproved reason missing: {key}")

wave23_evaluations = list(wave23.get("evaluations", []))
require(len(wave23_evaluations) == 8, "Wave-23 must contain exactly 8 evaluations")
wave23_direct = {
    str(item["working_key"]): item for item in wave23_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave23_retained = {
    str(item["working_key"]): item for item in wave23_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave23_direct), len(wave23_retained)) == (3, 5), "Wave-23 must contain 3 direct and 5 retained-unproved outcomes")
require(
    not ((set(wave23_direct) | set(wave23_retained)) & (set(wave22_direct) | set(wave22_retained))),
    "Wave-23 overlaps a Wave-22 target",
)

for key, evaluation in {**wave23_direct, **wave23_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-23 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id", "unproved_pending"},
        f"Wave-23 target prior state drift: {key}",
    )
    if key in wave23_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-23 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-23 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave23_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave23:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-23 retained-unproved reason missing: {key}")

wave24_evaluations = list(wave24.get("evaluations", []))
require(len(wave24_evaluations) == 8, "Wave-24 must contain exactly 8 evaluations")
wave24_direct = {
    str(item["working_key"]): item for item in wave24_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave24_retained = {
    str(item["working_key"]): item for item in wave24_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave24_direct), len(wave24_retained)) == (1, 7), "Wave-24 must contain 1 direct and 7 retained-unproved outcomes")
require(
    not ((set(wave24_direct) | set(wave24_retained)) & (set(wave23_direct) | set(wave23_retained))),
    "Wave-24 overlaps a Wave-23 target",
)

for key, evaluation in {**wave24_direct, **wave24_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-24 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id", "unproved_pending"},
        f"Wave-24 target prior state drift: {key}",
    )
    if key in wave24_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-24 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-24 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave24_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave24:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-24 retained-unproved reason missing: {key}")

wave27_evaluations = list(wave27.get("evaluations", []))
require(len(wave27_evaluations) == 8, "Wave-27 must contain exactly 8 evaluations")
wave27_direct = {
    str(item["working_key"]): item for item in wave27_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave27_retained = {
    str(item["working_key"]): item for item in wave27_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave27_direct), len(wave27_retained)) == (2, 6), "Wave-27 must contain 2 direct and 6 retained-unproved outcomes")
require(
    not ((set(wave27_direct) | set(wave27_retained)) & (set(wave24_direct) | set(wave24_retained))),
    "Wave-27 overlaps a Wave-24 target",
)

for key, evaluation in {**wave27_direct, **wave27_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-27 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id", "unproved_pending"},
        f"Wave-27 target prior state drift: {key}",
    )
    if key in wave27_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-27 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-27 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave27_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave27:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-27 retained-unproved reason missing: {key}")

wave28_evaluations = list(wave28.get("evaluations", []))
require(len(wave28_evaluations) == 8, "Wave-28 must contain exactly 8 evaluations")
wave28_direct = {
    str(item["working_key"]): item for item in wave28_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave28_retained = {
    str(item["working_key"]): item for item in wave28_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave28_direct), len(wave28_retained)) == (3, 5), "Wave-28 must contain 3 direct and 5 retained-unproved outcomes")
require(
    not ((set(wave28_direct) | set(wave28_retained)) & (set(wave27_direct) | set(wave27_retained))),
    "Wave-28 overlaps a Wave-27 target",
)

for key, evaluation in {**wave28_direct, **wave28_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-28 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id", "unproved_pending"},
        f"Wave-28 target prior state drift: {key}",
    )
    if key in wave28_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-28 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-28 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave28_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave28:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-28 retained-unproved reason missing: {key}")

wave30_evaluations = list(wave30.get("evaluations", []))
require(len(wave30_evaluations) == 8, "Wave-30 must contain exactly 8 evaluations")
wave30_direct = {
    str(item["working_key"]): item for item in wave30_evaluations
    if item.get("candidate_status") == "verified_benchmark_direct_recommended"
    and item.get("completion_credit_recommended") is True
}
wave30_retained = {
    str(item["working_key"]): item for item in wave30_evaluations
    if item.get("candidate_status") == "retained_unproved"
    and item.get("completion_credit_recommended") is False
}
require((len(wave30_direct), len(wave30_retained)) == (1, 7), "Wave-30 must contain 1 direct and 7 retained-unproved outcomes")
require(
    not ((set(wave30_direct) | set(wave30_retained)) & (set(wave28_direct) | set(wave28_retained))),
    "Wave-30 overlaps a Wave-28 target",
)

for key, evaluation in {**wave30_direct, **wave30_retained}.items():
    require(key in row_by_key and key in manifest_by_key, f"Wave-30 target absent from 902 mapping: {key}")
    current = row_by_key[key]
    prior_status = current.get("status")
    require(
        current.get("completion_credit") is False
        and prior_status in {"unproved", "unproved_audit_assigned_id", "unproved_pending"},
        f"Wave-30 target prior state drift: {key}",
    )
    if key in wave30_direct:
        locus = evaluation.get("evidence_loci")
        evidence_loci = [locus.strip()] if isinstance(locus, str) and locus.strip() else strings(locus)
        require(evidence_loci, f"Wave-30 evidence loci missing: {key}")
        require(evaluation.get("research_candidate") and evaluation.get("reason"), f"Wave-30 direct rationale missing: {key}")
        current["status"] = "verified_benchmark_direct"
        current["inheritance_method"] = "fresh_target_specific_wave30_direct"
        current["prior_outcome"] = prior_status
        current["source_units"] = [f"fresh-902-wave30:{key}"]
        current["evidence_loci"] = evidence_loci
        current["completion_credit"] = True
    else:
        require(evaluation.get("reason"), f"Wave-30 retained-unproved reason missing: {key}")

rows = [row_by_key[key] for key in sorted(row_by_key)]

status_counts = Counter(str(row["status"]) for row in rows)
verified_direct = status_counts["verified_benchmark_direct"]
verified_rename = status_counts["verified_benchmark_rename"]
ncm_direct = status_counts["documented_ncm_direct"]
ncm_rename = status_counts["documented_ncm_rename"]
eligible = sum(bool(row.get("completion_credit")) for row in rows)
unproved = len(rows) - eligible

require((verified_direct, verified_rename) == (340, 22), "Verified benchmark partition changed")
require((ncm_direct, ncm_rename) == (82, 7), "Documented NCM partition changed")
require((eligible, unproved) == (451, 451), "Expected 451 eligible and 451 completion-unproved rows")

unproved_statuses = {
    "ordinary": "unproved",
    "audit_assigned_stable_name": "unproved_audit_assigned_id",
    "prior_pending": "unproved_pending",
    "prior_reject": "unproved_reject",
    "source_stable_semantic_merge": "unproved_source_stable",
}
completion_unproved = {name: status_counts[status] for name, status in unproved_statuses.items()}
completion_unproved["total"] = sum(completion_unproved.values())
expected_unproved = {
    "ordinary": 413,
    "audit_assigned_stable_name": 10,
    "prior_pending": 24,
    "prior_reject": 3,
    "source_stable_semantic_merge": 1,
    "total": 451,
}
require(completion_unproved == expected_unproved, f"Unexpected completion-unproved partition: {completion_unproved}")

all_lines = [tuple_line(row) for row in rows]
eligible_lines = [tuple_line(row) for row in rows if row.get("completion_credit")]
prior_eligible_keys = {
    str(row["working_key"]) for row in prior_rows if row.get("completion_credit")
}
eligible_keys = {str(row["working_key"]) for row in rows if row.get("completion_credit")}
require(
    eligible_keys == (
        prior_eligible_keys | set(wave4_direct) | set(wave5_direct) | set(wave6_direct)
        | set(wave7_direct) | set(wave8_direct) | set(wave9_direct) | set(wave10_direct)
        | set(wave11_direct) | set(wave12_direct) | set(wave12_ncm)
        | set(wave13_direct) | set(wave13_ncm) | set(wave14_direct) | set(wave16_direct)
        | set(wave17_direct)
        | set(wave18_direct)
        | set(wave19_direct)
        | set(wave20_direct)
        | set(wave21_direct)
        | set(wave22_direct)
        | set(wave23_direct)
        | set(wave24_direct)
        | set(wave27_direct)
        | set(wave28_direct)
        | set(wave30_direct)
    ),
    "Eligible target set differs from prior credit plus wave-4 through wave-30 outcomes",
)

full_tuple_sha = sha_lines(all_lines)
eligible_tuple_sha = sha_lines(eligible_lines)
require(
    full_tuple_sha == "ba3d7b6705667c5b7d1c16baefab6c4ed0f8533830a7aae3ccf262e345899d7d",
        f"Post-wave30 full tuple SHA drift: {full_tuple_sha}",
)
require(
    eligible_tuple_sha == "8b29940543fe57a24544b362681939209a305d973c5bbac466820f785adc1394",
        f"Post-wave30 eligible tuple SHA drift: {eligible_tuple_sha}",
)

output = {
    "schema_version": "1.1",
    "artifact": "benchmark-final-902-mapping",
    "generated_at": wave30.get("generated_at"),
    "audited_commit": EXPECTED_COMMIT,
    "status": "target_specific_451_of_902_complete_not_overall_audit_completion",
    "audit_boundary": prior.get("audit_boundary"),
    "denominator": {"total": 902, "H": 788, "D": 111, "M": 3},
    "summary": {
        "verified_benchmark": {
            "direct": verified_direct,
            "strict_one_to_one_rename": verified_rename,
            "total": verified_direct + verified_rename,
        },
        "documented_no_credible_match": {
            "direct": ncm_direct,
            "strict_one_to_one_rename": ncm_rename,
            "total": ncm_direct + ncm_rename,
        },
        "eligible_total": eligible,
        "completion_unproved": completion_unproved,
        "status_counts": dict(sorted(status_counts.items())),
    },
    "completion_boundary": {
        "eligible_rows": eligible,
        "completion_unproved_rows": unproved,
        "statement": "Only independently completed target-specific direct matches or bounded documented No Credible Match outcomes receive completion credit; all other targets remain blocked.",
        "formal_audit_gate": "blocked_451_of_902_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome",
    },
    "rules": copy.deepcopy(prior.get("rules", {})),
    "checksum_algorithm": {
        "tuple_schema": "working_key|status|source_units|evidence_loci",
        "array_encoding": "Ordinal-sort and deduplicate source_units and evidence_loci independently; join each array with semicolon.",
        "record_encoding": "Ordinal-sort complete tuple lines; join with LF and no terminal LF; UTF-8 without BOM; SHA-256 lowercase hexadecimal.",
        "eligible_subset": "Rows where completion_credit is true.",
        "full_mapping_sha256": sha_lines(all_lines),
        "eligible_subset_sha256": sha_lines(eligible_lines),
    },
    "inputs": {
        "working_manifest": {
            "path": f"evidence/source/{MANIFEST_PATH.name}",
            "file_sha256": sha_file(MANIFEST_PATH),
            "canonical_stable_target_ids_sha256": manifest.get("checksums", {}).get("canonical_stable_target_ids_sha256"),
        },
        "prior_901_mapping": {
            "path": f"evidence/source/{PRIOR_MAPPING_PATH.name}",
            "file_sha256": sha_file(PRIOR_MAPPING_PATH),
            "full_mapping_sha256": prior.get("checksum_algorithm", {}).get("full_mapping_sha256"),
            "eligible_subset_sha256": prior.get("checksum_algorithm", {}).get("eligible_subset_sha256"),
        },
        "target_specific_wave4": {
            "path": f"evidence/source/{WAVE4_PATH.name}",
            "file_sha256": sha_file(WAVE4_PATH),
            "accepted_direct_keys_sha256": wave4.get("integrity", {}).get("verified_key_sha256"),
            "pending_ncm_keys_sha256": wave4.get("integrity", {}).get("ncm_key_sha256"),
        },
        "target_specific_wave5": {
            "path": f"evidence/source/{WAVE5_PATH.name}",
            "file_sha256": sha_file(WAVE5_PATH),
            "accepted_direct_count": len(wave5_direct),
        },
        "target_specific_wave6": {
            "path": f"evidence/source/{WAVE6_PATH.name}",
            "file_sha256": sha_file(WAVE6_PATH),
            "accepted_direct_count": len(wave6_direct),
        },
        "target_specific_wave7": {
            "path": f"evidence/source/{WAVE7_PATH.name}",
            "file_sha256": sha_file(WAVE7_PATH),
            "accepted_direct_count": len(wave7_direct),
        },
        "target_specific_wave8": {
            "path": f"evidence/source/{WAVE8_PATH.name}",
            "file_sha256": sha_file(WAVE8_PATH),
            "accepted_direct_count": len(wave8_direct),
            "historical_documented_ncm_count": len(wave8_ncm),
            "accepted_documented_ncm_count_after_withdrawal": len(set(wave8_ncm) - withdrawn_keys),
        },
        "wave8_ncm_withdrawal": {
            "path": f"evidence/source/{WAVE8_WITHDRAWAL_PATH.name}",
            "file_sha256": sha_file(WAVE8_WITHDRAWAL_PATH),
            "withdrawn_key_count": len(withdrawn_keys),
            "withdrawn_keys_sha256": sha_lines(list(withdrawn_keys)),
            "replacement_tuple_sha256": wave8_withdrawal.get("review_source", {}).get("normalized_replacement_tuple_sha256"),
        },
        "target_specific_wave9": {
            "path": f"evidence/source/{WAVE9_PATH.name}",
            "file_sha256": sha_file(WAVE9_PATH),
            "accepted_direct_count": len(wave9_direct),
            "selected_keys_sha256": wave9.get("selected_keys_sha256"),
            "selected_lineage_tuple_sha256": wave9.get("selected_lineage_tuple_sha256"),
        },
        "target_specific_wave10": {
            "path": f"evidence/source/{WAVE10_PATH.name}",
            "file_sha256": sha_file(WAVE10_PATH),
            "accepted_direct_count": len(wave10_direct),
            "selected_keys_sha256": wave10.get("selected_keys_sha256"),
            "selected_lineage_tuple_sha256": wave10.get("selected_lineage_tuple_sha256"),
        },
        "target_specific_wave11": {
            "path": f"evidence/source/{WAVE11_PATH.name}",
            "file_sha256": sha_file(WAVE11_PATH),
            "accepted_direct_count": len(wave11_direct),
            "selected_keys_sha256": wave11.get("selected_keys_sha256"),
            "selected_lineage_tuple_sha256": wave11.get("selected_lineage_tuple_sha256"),
            "source_slice_reuse_disclosure": copy.deepcopy(wave11.get("source_slice_reuse_disclosure", [])),
        },
        "target_specific_wave12": {
            "path": f"evidence/source/{WAVE12_PATH.name}",
            "file_sha256": sha_file(WAVE12_PATH),
            "accepted_direct_count": len(wave12_direct),
            "accepted_documented_ncm_count": len(wave12_ncm),
            "selected_keys_sha256": wave12.get("selected_keys_sha256"),
            "selected_lineage_tuple_sha256": wave12.get("selected_lineage_tuple_sha256"),
            "source_slice_reuse_disclosure": copy.deepcopy(wave12.get("source_slice_reuse_disclosure", [])),
        },
        "target_specific_wave13": {
            "path": f"evidence/source/{WAVE13_PATH.name}",
            "file_sha256": sha_file(WAVE13_PATH),
            "accepted_direct_count": len(wave13_direct),
            "accepted_documented_ncm_count": len(wave13_ncm),
            "selected_keys_sha256": wave13.get("selected_keys_sha256"),
            "selected_lineage_tuple_sha256": wave13.get("selected_lineage_tuple_sha256"),
            "review_decision_reference_sha256": wave13.get("review_decision_reference_sha256"),
            "source_slice_reuse_disclosure": copy.deepcopy(wave13.get("source_slice_reuse_disclosure", [])),
        },
        "target_specific_wave14": {
            "path": f"evidence/source/{WAVE14_PATH.name}",
            "file_sha256": sha_file(WAVE14_PATH),
            "accepted_direct_count": len(wave14_direct),
            "retained_unproved_count": len(wave14_retained),
            "source_slice_reuse_disclosure": copy.deepcopy(wave14.get("source_slice_reuse_disclosure", [])),
        },
        "target_specific_wave16": {
            "path": f"evidence/source/{WAVE16_PATH.name}",
            "file_sha256": sha_file(WAVE16_PATH),
            "accepted_direct_count": len(wave16_direct),
            "retained_unproved_count": len(wave16_retained),
            "selection_sha256": wave16.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave17": {
            "path": f"evidence/source/{WAVE17_PATH.name}",
            "file_sha256": sha_file(WAVE17_PATH),
            "accepted_direct_count": len(wave17_direct),
            "retained_unproved_count": len(wave17_retained),
            "selection_sha256": wave17.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave18": {
            "path": f"evidence/source/{WAVE18_PATH.name}",
            "file_sha256": sha_file(WAVE18_PATH),
            "accepted_direct_count": len(wave18_direct),
            "retained_unproved_count": len(wave18_retained),
            "selection_sha256": wave18.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave19": {
            "path": f"evidence/source/{WAVE19_PATH.name}",
            "file_sha256": sha_file(WAVE19_PATH),
            "accepted_direct_count": len(wave19_direct),
            "retained_unproved_count": len(wave19_retained),
            "selection_sha256": wave19.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave20": {
            "path": f"evidence/source/{WAVE20_PATH.name}",
            "file_sha256": sha_file(WAVE20_PATH),
            "accepted_direct_count": len(wave20_direct),
            "retained_unproved_count": len(wave20_retained),
            "selection_sha256": wave20.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave21": {
            "path": f"evidence/source/{WAVE21_PATH.name}",
            "file_sha256": sha_file(WAVE21_PATH),
            "accepted_direct_count": len(wave21_direct),
            "retained_unproved_count": len(wave21_retained),
            "selection_sha256": wave21.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave22": {
            "path": f"evidence/source/{WAVE22_PATH.name}",
            "file_sha256": sha_file(WAVE22_PATH),
            "accepted_direct_count": len(wave22_direct),
            "retained_unproved_count": len(wave22_retained),
            "selection_sha256": wave22.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave23": {
            "path": f"evidence/source/{WAVE23_PATH.name}",
            "file_sha256": sha_file(WAVE23_PATH),
            "accepted_direct_count": len(wave23_direct),
            "retained_unproved_count": len(wave23_retained),
            "selection_sha256": wave23.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave24": {
            "path": f"evidence/source/{WAVE24_PATH.name}",
            "file_sha256": sha_file(WAVE24_PATH),
            "accepted_direct_count": len(wave24_direct),
            "retained_unproved_count": len(wave24_retained),
            "selection_sha256": wave24.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave27": {
            "path": f"evidence/source/{WAVE27_PATH.name}",
            "file_sha256": sha_file(WAVE27_PATH),
            "accepted_direct_count": len(wave27_direct),
            "retained_unproved_count": len(wave27_retained),
            "selection_sha256": wave27.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave28": {
            "path": f"evidence/source/{WAVE28_PATH.name}",
            "file_sha256": sha_file(WAVE28_PATH),
            "accepted_direct_count": len(wave28_direct),
            "retained_unproved_count": len(wave28_retained),
            "selection_sha256": wave28.get("methodology", {}).get("selection_sha256"),
        },
        "target_specific_wave30": {
            "path": f"evidence/source/{WAVE30_PATH.name}",
            "file_sha256": sha_file(WAVE30_PATH),
            "accepted_direct_count": len(wave30_direct),
            "retained_unproved_count": len(wave30_retained),
            "selection_sha256": wave30.get("methodology", {}).get("selection_sha256"),
        },
        "inherited_adjudication_provenance": copy.deepcopy(prior.get("inputs", {})),
    },
    "explicit_exclusions": copy.deepcopy(prior.get("explicit_exclusions", {})),
    "targets": rows,
}

write(OUTPUT_PATH, output)

summary = {
    "schema_version": "1.0",
    "artifact": "benchmark-final-902-generation-summary",
    "generated_at": wave30.get("generated_at"),
    "audited_commit": EXPECTED_COMMIT,
    "inputs": {
        **output["inputs"],
        "target_specific_wave15_zero_delta": {
            "path": f"evidence/source/{WAVE15_PATH.name}",
            "file_sha256": sha_file(WAVE15_PATH),
            "retained_unproved_count": len(wave15_evaluations),
            "completion_credit_delta": 0,
        },
    },
    "output": {"file": OUTPUT_PATH.name, "sha256": sha_file(OUTPUT_PATH)},
    "counts": output["summary"],
    "checksums": output["checksum_algorithm"],
    "validation": {
        "manifest_sha256_matches_pin": sha_file(MANIFEST_PATH) == EXPECTED_MANIFEST_SHA256,
        "exact_manifest_key_set": set(row_by_key) == set(manifest_by_key),
        "unique_target_keys": len(row_by_key) == 902,
        "manifest_class_partition": {name: manifest.get("counts", {}).get(name) for name in ("H", "D", "M")},
        "only_expected_target_added": set(manifest_by_key) - set(prior_by_key) == {NEW_KEY},
        "prior_901_non_wave_rows_preserved_exactly": all(
            row_by_key[key] == prior_by_key[key]
            for key in prior_by_key if key not in wave4_direct and key not in wave5_direct and key not in wave6_direct and key not in wave7_direct and key not in wave8_direct and key not in wave8_ncm and key not in wave9_direct and key not in wave10_direct and key not in wave11_direct and key not in wave12_direct and key not in wave12_ncm and key not in wave13_direct and key not in wave13_ncm and key not in wave14_direct and key not in wave16_direct and key not in wave17_direct and key not in wave18_direct and key not in wave19_direct and key not in wave20_direct and key not in wave21_direct and key not in wave22_direct and key not in wave23_direct and key not in wave24_direct and key not in wave27_direct and key not in wave28_direct and key not in wave30_direct
        ),
        "wave4_direct_rows_verified": len(wave4_direct) == 12 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave4:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave4_direct
        ),
        "wave4_pending_ncm_rows_uncredited": len(wave4_pending_ncm) == 12 and all(
            row_by_key[key] == prior_by_key[key]
            and row_by_key[key].get("completion_credit") is False
            for key in wave4_pending_ncm
        ),
        "wave5_direct_rows_verified": len(wave5_direct) == 12 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave5:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave5_direct
        ),
        "wave6_direct_rows_verified": len(wave6_direct) == 12 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave6:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave6_direct
        ),
        "wave7_direct_rows_verified": len(wave7_direct) == 12 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave7:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave7_direct
        ),
        "wave8_direct_rows_verified": len(wave8_direct) == 10 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave8:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave8_direct
        ),
        "wave8_historical_documented_ncm_rows_withdrawn": len(withdrawn_keys) == 2 and all(
            row_by_key[key] == prior_by_key[key]
            and row_by_key[key].get("status") == "unproved"
            and row_by_key[key].get("completion_credit") is False
            and not row_by_key[key].get("source_units")
            and not row_by_key[key].get("evidence_loci")
            for key in withdrawn_keys
        ),
        "wave9_direct_rows_verified": len(wave9_direct) == 12 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave9:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave9_direct
        ),
        "wave10_direct_rows_verified": len(wave10_direct) == 12 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave10:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave10_direct
        ),
        "wave11_direct_rows_verified": len(wave11_direct) == 12 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave11:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave11_direct
        ),
        "wave12_direct_rows_verified": len(wave12_direct) == 8 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave12:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave12_direct
        ),
        "wave12_documented_ncm_rows_verified": len(wave12_ncm) == 4 and all(
            row_by_key[key].get("status") == "documented_ncm_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave12:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave12_ncm
        ),
        "wave13_direct_rows_verified": len(wave13_direct) == 11 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave13:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave13_direct
        ),
        "wave13_documented_ncm_rows_verified": len(wave13_ncm) == 1 and all(
            row_by_key[key].get("status") == "documented_ncm_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave13:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave13_ncm
        ),
        "wave14_direct_rows_verified": len(wave14_direct) == 4 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave14:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave14_direct
        ),
        "wave14_retained_unproved_rows_unchanged": len(wave14_retained) == 8 and all(
            row_by_key[key].get("status") == wave14_retained[key].get("prior_status")
            and row_by_key[key].get("completion_credit") is False
            for key in wave14_retained
        ),
        "wave15_zero_delta_rows_remain_unproved": all(
            row_by_key[str(item["working_key"])].get("completion_credit") is False
            for item in wave15_evaluations
        ),
        "wave16_direct_rows_verified": len(wave16_direct) == 4 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave16:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave16_direct
        ),
        "wave16_retained_unproved_rows_unchanged": len(wave16_retained) == 3 and all(
            row_by_key[key].get("status") == "unproved"
            and row_by_key[key].get("completion_credit") is False
            for key in wave16_retained
        ),
        "wave17_direct_rows_verified": len(wave17_direct) == 2 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave17:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave17_direct
        ),
        "wave17_retained_unproved_rows_unchanged": len(wave17_retained) == 6 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id", "unproved_pending"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave17_retained
        ),
        "wave18_direct_rows_verified": len(wave18_direct) == 1 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave18:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave18_direct
        ),
        "wave18_retained_unproved_rows_unchanged": len(wave18_retained) == 7 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave18_retained
        ),
        "wave19_direct_rows_verified": len(wave19_direct) == 2 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave19:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave19_direct
        ),
        "wave19_retained_unproved_rows_unchanged": len(wave19_retained) == 6 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave19_retained
        ),
        "wave20_direct_rows_verified": len(wave20_direct) == 1 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave20:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave20_direct
        ),
        "wave20_retained_unproved_rows_unchanged": len(wave20_retained) == 7 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave20_retained
        ),
        "wave21_direct_rows_verified": len(wave21_direct) == 3 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave21:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave21_direct
        ),
        "wave21_retained_unproved_rows_unchanged": len(wave21_retained) == 5 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave21_retained
        ),
        "wave22_direct_rows_verified": len(wave22_direct) == 4 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave22:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave22_direct
        ),
        "wave22_retained_unproved_rows_unchanged": len(wave22_retained) == 4 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave22_retained
        ),
        "wave23_direct_rows_verified": len(wave23_direct) == 3 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave23:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave23_direct
        ),
        "wave23_retained_unproved_rows_unchanged": len(wave23_retained) == 5 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id", "unproved_pending"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave23_retained
        ),
        "wave24_direct_rows_verified": len(wave24_direct) == 1 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave24:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave24_direct
        ),
        "wave24_retained_unproved_rows_unchanged": len(wave24_retained) == 7 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id", "unproved_pending"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave24_retained
        ),
        "wave27_direct_rows_verified": len(wave27_direct) == 2 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave27:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave27_direct
        ),
        "wave27_retained_unproved_rows_unchanged": len(wave27_retained) == 6 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id", "unproved_pending"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave27_retained
        ),
        "wave28_direct_rows_verified": len(wave28_direct) == 3 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave28:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave28_direct
        ),
        "wave28_retained_unproved_rows_unchanged": len(wave28_retained) == 5 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id", "unproved_pending"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave28_retained
        ),
        "wave30_direct_rows_verified": len(wave30_direct) == 1 and all(
            row_by_key[key].get("status") == "verified_benchmark_direct"
            and row_by_key[key].get("source_units") == [f"fresh-902-wave30:{key}"]
            and row_by_key[key].get("completion_credit") is True
            for key in wave30_direct
        ),
        "wave30_retained_unproved_rows_unchanged": len(wave30_retained) == 7 and all(
            row_by_key[key].get("status") in {"unproved", "unproved_audit_assigned_id", "unproved_pending"}
            and row_by_key[key].get("completion_credit") is False
            for key in wave30_retained
        ),
        "new_target_status": row_by_key[NEW_KEY]["status"],
        "new_target_has_no_inherited_credit": (
            row_by_key[NEW_KEY]["completion_credit"] is False
            and not row_by_key[NEW_KEY]["source_units"]
            and not row_by_key[NEW_KEY]["evidence_loci"]
        ),
        "eligible_target_set_is_prior_plus_wave4_through_wave30_outcomes": (
            eligible_keys == (
                prior_eligible_keys | set(wave4_direct) | set(wave5_direct) | set(wave6_direct)
                | set(wave7_direct) | set(wave8_direct) | set(wave9_direct) | set(wave10_direct)
                | set(wave11_direct) | set(wave12_direct) | set(wave12_ncm)
                | set(wave13_direct) | set(wave13_ncm) | set(wave14_direct) | set(wave16_direct)
                | set(wave17_direct)
                | set(wave18_direct)
                | set(wave19_direct)
                | set(wave20_direct)
                | set(wave21_direct)
                | set(wave22_direct)
                | set(wave23_direct)
                | set(wave24_direct)
                | set(wave27_direct)
                | set(wave28_direct)
                | set(wave30_direct)
            )
        ),
        "eligible_rows_have_one_source_unit": all(
            len(strings(row.get("source_units", []))) == 1 for row in rows if row.get("completion_credit")
        ),
        "eligible_rows_have_evidence_loci": all(
            bool(strings(row.get("evidence_loci", []))) for row in rows if row.get("completion_credit")
        ),
        "lineage_snapshots_match_manifest": all(
            strings(row.get("source_family_ids", []))
            == strings(manifest_by_key[str(row["working_key"])].get("source_family_ids", []))
            for row in rows
        ),
    },
    "completion_gate": {
        "complete": False,
        "reason": "451/902 targets still require completed target-specific benchmark or bounded No Credible Match adjudication.",
    },
}
write(SUMMARY_PATH, summary)

print(json.dumps({
    "output": str(OUTPUT_PATH),
    "sha256": sha_file(OUTPUT_PATH),
    "summary_sha256": sha_file(SUMMARY_PATH),
    "eligible": eligible,
    "unproved": unproved,
    "verified": verified_direct + verified_rename,
    "ncm": ncm_direct + ncm_rename,
    "full_tuple_sha256": output["checksum_algorithm"]["full_mapping_sha256"],
    "eligible_tuple_sha256": output["checksum_algorithm"]["eligible_subset_sha256"],
}, indent=2))
