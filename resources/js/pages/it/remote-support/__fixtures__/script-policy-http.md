# Original script policy HTTP fixture

`script-policy-http.json` is a byte-for-byte copy of the native A producer at
`.endpoint-tools/script-policy-recovery-20261021/php-policy-a.json`.
The producer is `EndpointScriptPolicyTest`'s **exports original policy HTTP
receipts and fences delayed revisions after lost replies** case, run against
the isolated real application/MySQL schema on 21 October 2026 NZ.

The envelope contains twelve original request/response records. Request and
response JSON strings preserve their original bytes, including the padded
Unicode reason. All users, computers and policy requests are synthetic test
records. No script was staged or executed.

This proves a bounded native HTTP-to-TypeScript policy reader/recovery contract.
The delayed-write order cases are serial order witnesses, not a simultaneous
network race or installed/browser/device acceptance. Original runner output,
source pins and exact schema cleanup remain in the producer evidence directory.
