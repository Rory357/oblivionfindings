# Fleet release follow-up — 30 September 2026

The user authorised deployment, hosted verification and assessment of the failing CI checks. Live Google configuration remains excluded. After the HTTPS diagnosis below, the user deferred test-server networking until their static public IP is restored. Hosted visual acceptance and actual 125% browser zoom remain deferred, not passed.

## Deployment established

The user identified the Antigravity chat **Troubleshooting Local Server Deployment** as the existing deployment workflow. Its existing connection was reused without putting credentials in this repository.

The application is served from `/var/www/oblivionfindings`. The existing deployment poller follows `origin/main` through `/usr/local/bin/deploy-oblivionfindings-main`. Do not start a competing release while the poller holds the deployment lock.

The release log confirms successful deployment of `ddb8d3af4eaae9eb482f6490d43671cf63203c3f` at **2026-09-29 19:35:36 UTC** and `9b006825d14eb3ab2dd10392871076d3c2be522f` at **19:49:48 UTC**. These contain Fleet follow-up `75d5f46b8597b2173d02385c98bab1a0adbc7794`. Source, compiled assets and server runtime were checked rather than inferring deployment from a Git push.

Observed release checks included Composer, the client/SSR build, migration and lifecycle validation (16 expected and 16 observed triggers), worker/listener supervision, Nginx configuration validation and leaving maintenance mode. At the second checkpoint the server checkout and `origin/main` matched, tracked files were clean, maintenance mode was off and the compiled vehicle and asset profile entries existed.

Those checkpoints precede this commit's additional Device handoff correction. Its publication and subsequent deployment are checked separately in the completing chat; they are not covered by the earlier runtime timestamps.

## Device handoff correction

The CI case `device-profile-acceptance.spec.ts` stopped at the retired **Vehicle technology** tab assertion. Investigation beyond that first failure found two real integration gaps:

- The vehicle telemetry endpoint already returned the permitted canonical Device projection, but the new telemetry panel did not render its links. The panel now exposes those exact source links, including installed devices with no telemetry samples. It does not construct links from a telemetry ID or bypass device/site permissions.
- Asset links using `?tab=technology` fell back to Overview. They now open the existing Location section and its Device provenance links. An explicit modern hash view keeps precedence. Each source link has an accessible name identifying its device.

The end-to-end test now targets **Vehicle telemetry** and **Location & observations**, using their `aria-selected` contract. Both directions of the canonical Device round trip and the exact target IDs remain asserted. The browser test was not rerun in this session and is not recorded as passing.

Focused component verification: **22 tests passed in four files**, including five new telemetry handoff cases covering permitted tracker/camera links before a first sample, omitted links, HTTP 403/404, and lack of vehicle technology permission. TypeScript, scoped ESLint and the production Vite build passed. The build completed in 8m 18s with the existing large-chunk advisory. No backend permission rule or provider setting changed.

## CI disposition

The assessment compares completed runs at baseline `3ffc4c1c4623f6219a3a1994b458a03c891637be` with `88b7d3a3a95495a96ffac06b5e18e46c9225d623`. Newer main runs were still running during the assessment, so these are not described as checks of every later main commit.

- **Lint:** both [baseline](https://github.com/Rory357/oblivionfindings/actions/runs/36501810803) and [comparison](https://github.com/Rory357/oblivionfindings/actions/runs/36527467770) report **3,340 findings: 2,634 errors and 706 warnings**. The repository-wide check is not green. No bulk autofix, disabled rule or global formatting change was applied.
- **Visual:** [baseline](https://github.com/Rory357/oblivionfindings/actions/runs/36501810821) and [comparison](https://github.com/Rory357/oblivionfindings/actions/runs/36527467826) contain the same 88 failed project/case entries: 46 desktop workflows, 12 app-shell snapshots and 15 cases at each IT/Security desktop width. This includes the Fleet-to-Device case corrected above. The earlier record's statement that captured visual failures were all non-Fleet is superseded by this full-log assessment. Matching historical failures do not establish that their behaviour is acceptable.
- **Backend:** [baseline](https://github.com/Rory357/oblivionfindings/actions/runs/36501810780) contains 34 failing tests in the first failing batches; [comparison](https://github.com/Rory357/oblivionfindings/actions/runs/36527467730) contains 37. The crash-safe workflow stops each shard at its first failing batch, so these are not complete application test totals. Failures span Authentication, Attendance, Client, Catering, Assurance/Compliance, Control Room and Security Devices.
- The newer failure set includes `DeviceAssignmentServiceTest` and `MigrateDevicesCommandTest` around assignment-linked location consent, plus `ControlRoomJourneyAuthorizationTest` expecting two accessible journeys but receiving none. The consent failures need fixture-versus-authority investigation; they are not a reason to weaken consent validation. The Control Room result is not proof of an access leak. Neither area was silently rewritten as part of this Fleet UI correction.
- **Database bootstrap:** passed at `88b7d3a`; this is separate from the failing application checks.

CI assessment is complete for these captured runs; **application-wide green remains false**. Existing broader Fleet test results in the 29 September audit retain their original scope and are not a substitute for the failing full-repository checks.

## Hosted acceptance limits and deferral

The served Let's Encrypt certificate expired at **2026-09-29 03:29:03 UTC**. Certificate validation failed independently from this computer and from the server. Verification was not bypassed.

Certbot's timer was active, but its recent renewal attempts failed because the public HTTP challenge timed out. External DNS and the server's outbound public address agreed on `150.107.173.50`; the local network resolved the site to `172.16.0.232`. Nginx listened on ports 80 and 443, UFW allowed Nginx Full, and local HTTP returned the expected redirect. The public-address port-80 probe timed out. This points to public routing, forwarding or an upstream firewall; it does not establish which router rule or ISP condition is responsible.

The user then stated they would restore their static IP and asked not to worry about the test server for now. No router rule, DNS record, certificate-renewal configuration or Google setting was changed. After the network is ready, verify public DNS and HTTP challenge reachability, renew using the existing Certbot configuration, confirm certificate-valid HTTPS, and then resume authenticated hosted checks.

Windows Computer Use also stopped because it could not confidently determine the current browser URL for its safety checks. Browser automation was not retried through another backend. Actual browser-menu 125% zoom, layout/scrolling/dialog acceptance and the corrected end-to-end round trip remain unverified. Viewport emulation and component tests are not zoom evidence.

Rory's `DESIGN.md` and `design_styles/*` were read as references and were not edited by this follow-up. Concurrent unrelated main commits and local untracked audit/mockup material were preserved. Authorization remains single-organisation, with approved sites, roles, canonical ownership and privacy rules.
