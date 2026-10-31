# Original native heartbeat fixture

`endpoint-script-readiness-go.json` is an unchanged byte-for-byte copy of
`.endpoint-tools/script-readiness-20261021/go-heartbeat-a.json`, produced by
`TestScriptReadinessHeartbeatWire` in the native Go agent.

The two original JSON request strings contain synthetic computer inventory and
a fixed clock. The first ready report uses sequence 1; the omitted-capability
report uses sequence 2. No host probe, credentials, script execution or real
installed readiness was involved. Production code does not set this capability.

The server lifecycle tests submit these original bytes through the authenticated
HTTP endpoint. They distinguish a recorded observation from policy/approval or
execution authority. Separate native tests reject reuse of a pending heartbeat
that contains any script-readiness claim in this unfinished executor build.
