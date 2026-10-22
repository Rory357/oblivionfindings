# Original prepared-script HTTP transport fixture

`endpoint-script-http.json` is an unchanged copy of `.endpoint-tools/script-transport-20261021/original-http-c.json`.

Producer: the `serves exact private prepared script bytes and a nonrenewable original signed HTTP claim` case in `EndpointScriptCatalogueTest.php`, accepted native C (83 tests/796 assertions). It exercises the real Laravel HTTP routes and isolated MySQL state. The request is independently approved, but the test injects the release predicate and seeds the internal dispatch state because ordinary script queue/dispatch remains closed.

The fixture preserves original signed payload/signature fields from poll and claim, the original private artifact bytes as base64, the synthetic claim request, public verification key and recorded verification time. It contains no credential or signing secret. The user, computer, script and runtime manifest are synthetic; the fixture never authorizes a real operation.

SHA-256: `73b2c14c0ffb54534258d98c6975b22a5beaf4365b4f5212ec9a2cf0f9442a85` (4159 bytes).

`TestScriptOriginalHTTPTransport` reads this shape using `OBLI_SCRIPT_TRANSPORT_EXPORT`. The accepted Go A run consumed the original producer and checked dispatch/artifact/claim identity, unchanged lease on retry, and denial for expiry or changed boot/desktop/nonce. The tracked fixture is byte-for-byte identical.

This is attended application-HTTP/native-reader interoperability, not installed execution, live networking/certificates, actual desktop consent, unattended HTTP acceptance or hardware/customer-release evidence. Native C and Go A source pins, separate earlier failures and exact cleanup are recorded in `.endpoint-tools/script-transport-20261021/final-check.json`.
