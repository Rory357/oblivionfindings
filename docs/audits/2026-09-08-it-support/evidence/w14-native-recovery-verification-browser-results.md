# Native operational recovery browser results — 12 September 2026

W14 / F06 / B04 / E13, native exact-episode recovery slice only. Full W14/E13 remain incomplete.

## Environment

Owned run `a4f703b18c9e652d`, fingerprint `c60eaa3eeab2e988946e78aaf4b48c9bcfd5aa81ea97d0ac04fdfa61221dc4d5`, bootstrap72814 terminal0. HTTP identity confirms checkout C:/Users/steph/Herd/oblivionfindings, schema oblivion_it_draft_browser_a4f703b18c9e652d, normal CSRF, array mail/sync queue and manifest eaef8b8c79fd868f184d6c80485686f0d5669d5ce74188697b4f96da8a34c67b. Build app-FyuqyHfZ.js; public/hot absent. See browser-identity.json and browser-preview.json with the w14-native-recovery-verification prefix.

Real Codex in-app browser, owned tab4, normal sign-in/out. Existing desktop dimensions unchanged. No external tabs or real user permissions changed. Screenshot of the actual recovered urgent alert overview inspected in the conversation; this is conversation image evidence, not a saved PNG. The notice fits the existing workspace panel, below lifecycle status and above the linked journey, using existing status and text tokens.

## Actual journeys

- Synthetic technician has Control Room alert and Device access. `/control-room/alerts/2` opens CR-2026-0002 as High/Open with Operational response active. Overview displays Monitoring recovery recorded, the real observation time, and Review the recovery before resolving this alert. It explicitly states that monitoring recovery does not resolve the alert or complete IT work.
- From initial Overview focus, Tab three times and Return opens Evidence. The same recovery notice is present, and original offline evidence remains integrity verified, with its captured timestamp/checksum and original alert reference. No automatic resolution action runs.
- Linked records opens the existing IT-000012 link. Its summary says Open and monitoring recovered; technician closure still required. Following the link loads `/it/tickets/12` with Open, Raised automatically and the assigned technician. Both operational and technical work remain open.
- Synthetic audit actor has Control Room alert access but no Device access. The same alert opens normally, but Overview and Evidence show no recovery notice or sealed monitoring snapshot; navigation says Evidence/no packs. Direct access to `/security-devices/devices/5` returns403. Returning to the permitted alert restores its normal open workspace.
- Full response privacy is independently proved by the PHP workspace regression: raw monitoring_recoveries references are absent for both roles; only the source-authorised viewer receives the bounded recovery field. Browser evidence proves the user-facing rendering, not hidden network payload inspection.

## Remaining observations and next step

- Confirmed small copy gap: the IT ticket header correctly says Raised automatically, but its original-report author still says UNKNOWN. Correct that shared requester presentation in a subsequent focused change; this result does not claim complete ticket-copy conformance.
- Existing non-episode legacy recovery still auto-resolves operational alerts. Its compatibility tests pass, but that is not proof of the plan's final recovery acceptance. Reconcile legacy source proof and verification semantics before closing W14. Fleet intake, operator create/link-existing handoff and delivery-failure/retry UI also remain open.
- Owned tab4 closed. Exact cleanup10321 completed terminal0 with the original fingerprint. Independent postflight confirms schema and owned directory absent (w14-native-recovery-verification-browser-postflight.json). No owned runtime remains. Working database migrations17–27 remain unapplied; no deployment or live communications.
