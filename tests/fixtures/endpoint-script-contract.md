# endpoint-script-contract.json

Synthetic signed script-contract corpus written by the PHP side
(`EndpointScriptContract` plus `EndpointCommandSigner`) during the Codex
script-contract package on 20 Oct 2026. It is a byte-for-byte copy of that
package's final producer export (`php-producer-i.json`).

It contains a synthetic Ed25519 public key, a synthetic agent UUID, signed
command cases, receipt cases and runtime-manifest cases. There are no private
keys, real people, real computers or real scripts.

The Go tests `TestScriptPHPContractCorpus`, `TestScriptPHPRuntimePaths` and
`TestScriptPHPClaimCancellationAndResultCorpus` read it by default. Set
`OBLI_SCRIPT_EXPORT` to test a freshly produced export instead.
