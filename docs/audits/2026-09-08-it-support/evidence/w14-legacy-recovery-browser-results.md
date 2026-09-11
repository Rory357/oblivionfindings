# W14 legacy recovery desktop browser verification — 12 September 2026

Mappings: W14 / F06 / B04 / E13. This verifies the legacy recovery/source-attribution slice, not all W14 acceptance.

## Environment and source identity

- Owned run217874f910344249, bootstrap95457 terminal0. Original fingerprint483b5955f03c29304015836ea083ff18669cf130014b6e5e76ce71eb88264174. Exact checkout C:/Users/steph/Herd/oblivionfindings; disposable database oblivion_it_draft_browser_217874f910344249; normal local CSRF, array mail, sync queue, provider configuration cleared and SSR disabled. Read-only HTTP identity is saved in w14-legacy-recovery-browser-identity.json.
- Current existing assets: app-FyuqyHfZ.js, manifest eaef8b8c79fd868f184d6c80485686f0d5669d5ce74188697b4f96da8a34c67b. This continuation changes backend projection/source processing; no JS rebuild required. Exact eight-file bundle: w14-legacy-recovery-final-source-hashes.json.
- Normal browser sign-in for synthetic technician w06-tech@demo.test and restricted source role w06-audit@demo.test. Only owned in-app tab5 was used and closed. No browser resizing or mobile testing; unowned tab2 untouched.

## Passed journeys

- Technician opens /control-room/alerts/3 (CR-2026-0003). High/Open, operational response active. Overview displays Monitoring recovery recorded with the source occurrence time and an explicit instruction to review before resolving; neither alert nor ticket is closed automatically.
- From initial Overview focus, three Tab presses and Return open Evidence. The same notice appears above the original sealed snapshot. Integrity verified; original offline observation retained. Screenshot visually inspected the existing approved workspace, visible keyboard focus and readable notice; screenshot is conversation evidence, not a saved PNG.
- Linked records opens the real IT-000013 link at /it/tickets/13. The summary states Open, assigned technician and monitoring recovered with technician closure still required. Ticket header says Raised automatically; original report says SYSTEM — ORIGINAL REPORT. No fake human requester was introduced.
- After normal sign-out/sign-in, the role with Control Room access but no Device source permission can open the alert. Overview and Evidence omit the recovery notice and sealed snapshot; Evidence navigation says no packs. Direct /security-devices/devices/6 returns403. Browser Back restores the usable permitted alert.
- /it/tickets/1 retains the actual synthetic requester in both Raised by and ORIGINAL REPORT. The System fallback does not replace real human attribution.

No real communication, provider, deployment or working-database mutation. Browser inspection proves rendered access behavior; hidden response-payload privacy is covered by existing backend context tests, not claimed as browser network inspection. The earlier failed bootstrap, timestamp root cause, diagnostic rollback and independent cleanup are retained separately in w14-legacy-recovery-results.md.

## Cleanup

Owned tab5 closed. Exact fingerprinted cleanup65975 completed terminal0. Independent w14-legacy-recovery-browser-postflight.json confirms schema_absent=true and owned_directory_absent=true. All eight current source hashes still match. No owned runtime/schema remains.
