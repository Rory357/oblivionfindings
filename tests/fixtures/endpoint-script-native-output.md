# endpoint-script-native-output.json

Ten synthetic script-output cases produced by the Windows agent's output
collector (Go test `TestScriptOutputNormalizationAndBudget`, run with
`OBLI_SCRIPT_OUTPUT_EXPORT` pointing at this file). They cover the empty and
zero-budget cases, truncation, scalar boundaries, Unicode, invalid and
incomplete UTF-8 replacement, control characters and NUL.

Regenerated on 22 Oct 2026; it is byte-identical to the Codex export
`native-output-a.json` from the 21 Oct script-output package. The PHP test
`EndpointScriptCatalogueTest` ("stores original native output privately…")
reads it by default; set `OBLI_SCRIPT_NATIVE_OUTPUT` to use a fresh export.
