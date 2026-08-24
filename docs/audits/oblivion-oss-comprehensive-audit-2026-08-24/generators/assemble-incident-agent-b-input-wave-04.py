from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT_DIR = Path(__file__).resolve().parents[1]
SOURCE = AUDIT_DIR / "evidence/benchmark/raw-run-072-agent-a-incident-observed-behavior-wave-04.json"
OUTPUT = AUDIT_DIR / "evidence/benchmark/sealed-run-072-agent-b-input-wave-04.json"
EXPECTED_SOURCE_SHA256 = "c8b513225613053253207d457a0556e9888510950ec53534d8d23c85ec51e8b1"
EXPECTED_PAYLOADS = {
    "OBP-72-A-4F2D9C": (3658, "718bc43127d6cf94b415c9e34d3da57c0b9620648b4d4b8a2bf0f66f1aefcf0c"),
    "OBP-72-B-91E7A4": (5224, "550776f8f45fddb5b9875c3a660bef95169434660699194c9f30a6956ffa6dd9"),
}


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def compact_json(value: object) -> bytes:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


source_bytes = SOURCE.read_bytes()
assert sha256(source_bytes) == EXPECTED_SOURCE_SHA256
source = json.loads(source_bytes)
assert source["run_id"] == "RUN-072-A"
assert len(source["packets"]) == 2

sealed_payloads = []
for packet in source["packets"]:
    payload = packet["payload"]
    packet_id = payload["packet_id"]
    assert packet_id in EXPECTED_PAYLOADS
    payload_bytes = compact_json(payload)
    expected_bytes, expected_sha256 = EXPECTED_PAYLOADS[packet_id]
    assert (len(payload_bytes), sha256(payload_bytes)) == (expected_bytes, expected_sha256)
    source_seal = packet["reattachment_appendix"]["payload_seal"]
    assert source_seal["bytes"] == expected_bytes
    assert source_seal["sha256"] == expected_sha256
    assert payload["counts"]["identity_keys"] == 0
    assert payload["counts"]["implementation_details"] == 0
    assert payload["counts"]["current_product_comparisons"] == 0
    assert payload["counts"]["mapping_or_credit_claims"] == 0
    assert payload["attestation"]["identity_stripped"] is True
    sealed_payloads.append(
        {
            "payload_seal": {
                "algorithm": "SHA-256",
                "canonical_bytes": "UTF-8 compact JSON; no BOM; no terminal newline",
                "bytes": expected_bytes,
                "sha256": expected_sha256,
            },
            "payload": payload,
        }
    )

sealed_payloads.sort(key=lambda item: item["payload"]["packet_id"])
assert {item["payload"]["packet_id"] for item in sealed_payloads} == set(EXPECTED_PAYLOADS)

output = {
    "schema_version": "sealed_run_072_agent_b_input_wave_04_v1",
    "run_id": "RUN-072-B-INPUT",
    "status": "IDENTITY_STRIPPED_PAYLOADS_ONLY",
    "allowed_operation": "Derive neutral requirements while preserving unknowns and limitations.",
    "prohibited_context": [
        "reattachment appendices",
        "repository or project identity",
        "canonical target identity",
        "current-product source or behavior",
        "old comparisons or adjudications",
        "selection, mapping, NCM, benchmark, pass, or completion credit",
    ],
    "input_payloads": sealed_payloads,
    "counts": {
        "payloads": 2,
        "observations": sum(item["payload"]["counts"]["observations"] for item in sealed_payloads),
        "identity_keys": 0,
        "implementation_details": 0,
        "current_product_comparisons": 0,
        "mapping_or_credit_claims": 0,
    },
    "attestation": {
        "identity_stripped": True,
        "reattachment_appendices_excluded": True,
        "current_product_context_excluded": True,
        "zero_credit": True,
    },
}
output_bytes = (json.dumps(output, ensure_ascii=False, indent=2) + "\n").encode("utf-8")

if OUTPUT.exists():
    assert OUTPUT.read_bytes() == output_bytes, f"Refusing to overwrite different bytes: {OUTPUT}"
else:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_bytes(output_bytes)

assert json.loads(OUTPUT.read_bytes()) == output
print(f"{OUTPUT.relative_to(AUDIT_DIR)}\t{len(output_bytes)}\t{sha256(output_bytes)}")
