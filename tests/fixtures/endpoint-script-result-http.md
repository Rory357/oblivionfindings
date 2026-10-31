# Original script result HTTP witnesses

These are unchanged copies of the final native D producer responses from `.endpoint-tools/script-results-20261021`. The synthetic fixtures inject the release predicate and an independently approved internal dispatch state. No script, interpreter, endpoint installer, actual consent UI or private output upload runs.

- `endpoint-script-result-http-attended.json`: source `original-result-d.json`, 4140 bytes, SHA-256 `ef9dafcf3ecbcee196353b67a51cc0c08c586d33877f28ade5a98b34f8cef47f`.
- `endpoint-script-result-http-unattended.json`: source `original-unattended-d.json`, 3961 bytes, SHA-256 `2ab63a5075d470bd7247b3a6206b20d3b30d959a67f04a3f563e56e6d7b8a021`.

Each includes original signed dispatch and claim envelopes, the submitted result document, and the actual HTTP acknowledgement. The attended case records a delayed result after withdrawal, lease expiry and a later boot; the unattended case withdraws its separate execution permission before delayed result recovery. Accepted metadata does not prove output storage, process-tree quiescence, rollback or installed execution.

Full catalogue B passed38 tests/412 assertions before the observed-start timestamp correction. Final focused D passed16/150 with88 current source/fixture pins unchanged, including distinct observed start times retained through uncertain and successful results. Go `TestScriptOriginalHTTPResult` consumes the original D producer bytes for both modes, validates the historical signed context and result binding, rejects using the historical claim as a fresh execution grant, and rejects false cancellation after entry. Final Go B passed15 top-level tests/130 subtests with no skips,12 source pins and four producer inputs unchanged. Earlier B producer bytes and `final-check-b.json` remain preserved in the evidence package. See its latest `final-check.json` for separate broad A, catalogue B, interrupted C, final D, unit, Go, source-overlay and cleanup evidence.
