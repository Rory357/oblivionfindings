# W14 Monitoring dashboard correlation

Status: implemented; focused backend verification passed, desktop verification starting. W14/F06/B04/E13 and the full release gate remain open.

The prior dashboard lookup calculated availability-only correlation keys and required an alert before finding IT work. The new native projection ranks source failures by occurrence within each monitor, validates the exact issue and immutable observation provenance, and checks current canonical Site ownership. It finds direct work through the existing sealed source evidence and urgent/manual work through the canonical alert link. Current IT and source permissions remain required. A later undelivered issue suppresses an older ticket; recovery retains the existing verification-required work. Historical pre-episode correlation remains separate from the native projection.

Changed application source: app/Domain/SecurityDevices/Presenters/MonitoringOperationsPresenter.php. Added actual ingest/delivery/HTTP regression cases to tests/Feature/Monitoring/MonitoringAvailabilityEpisodeTest.php. The fingerprinted browser fixture now creates dedicated monitoring and source-only test actors, leaving the original technician's permissions unchanged. No working accounts, provider configurations or design files changed.

Initial isolated run29310/token it_1b8f2dd543254493 terminal1:58 passed/4 failed/62 finished/553 assertions; all14 postflight checks passed and schema absent. All four new direct/urgent mapping assertions passed, but HTTP assertions at line595 failed because the test account omitted the existing securityDevices.viewAny entry grant. That fixture is corrected without changing middleware. The native override is now limited to IT-infrastructure; distinct monitors on one device, changed Site with a viewer permitted at both Sites, and preserved non-IT operational correlation have dedicated new cases. Expanded PHP26434/token it_09d6655aa04740e2 is running the same two suites. Wait for terminal result and all14 cleanup checks; do not edit loaded PHP. Review follow-up before final browser acceptance: match native signal external_ref/type/version and seal embedded record IDs against the existing canonical delivery contract; add a bounded negative integrity regression.

No frontend source changed: the existing Monitoring card already independently renders an IT link without a Control Room link. Assets remain app-CUgUJHwk.js from build98832. Browser verification is pending against a fresh fingerprinted runtime after the final backend checks; never resize or replace the user's tab.

Main715bae1f3 holds the preceding fully checked technical-check adapter/report/history slice. Eight local commits now await the previously requested public-destination push approval. Preserve independent Governance work. No owned browser server, row lock or build is live; only PHP26434 is running.

## Expanded run and integrity review

PHP26434/token it_09d6655aa04740e2 completed64 passed/1 failed/65 finished/601 assertions, terminal1, all14 postflight checks and schema absence. Corrected HTTP entry-grant cases pass. The sole failure at test line653 expected a moved device to remain visible after changing only assignable_id; custody_site_id still pointed to the old Site, so the existing custody-integrity boundary correctly hid it. The test now releases the old assignment and creates a current new assignment, preserving custody provenance.

The final projection additionally requires the canonical signal external_ref/type and episode version and checks the sealed JSON ticket/device/Site/source IDs against its row bindings. Ranked queries materialize source IDs rather than historical diagnostic payloads. Four negative cases cover a changed external reference, signal type, episode version and snapshot ticket foreign key. These are local integrity checks, not new operational policies.

Live integrity run13739/token it_78eef0a4db0047db selects both monitoring/operations suites. Wait for terminal result and all14 cleanup checks; no other database import, build or browser runtime is active. Do not edit loaded application PHP. After pass, fingerprint the three changed sources and existing current manifest, create one isolated Monitoring browser runtime, verify direct/urgent/recovered links, Site restriction and source-only account concealment, then clean it. No additional frontend build is required unless frontend source changes: the existing card already accepts independent IT/Control Room links.

## Final focused result

Run13739/token it_78eef0a4db0047db completed with terminal0:69 passed/69 finished/611 assertions, no failed or errored tests. Counts are from the preserved diagnostic event stream; no standard Pest summary was emitted. All14 postflight checks passed, including isolated schema absence. The corrected canonical Site-move fixture and all four source-integrity regressions pass.

Owned browser bootstrap70194/token d716ac905348a2f9 is starting from read-only preview fingerprint511157badfc1a14fea63663543468e62da94ba43ddc953dcd75322895d82182c. No parallel test/import/build/row lock. Verify the actual Monitoring frontend with the dedicated full/source-only synthetic actors, then close only the owned tab and clean the exact runtime. Backend success is not browser acceptance.
