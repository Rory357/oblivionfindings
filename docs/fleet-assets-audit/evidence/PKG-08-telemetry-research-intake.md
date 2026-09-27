# PKG-08 — telemetry research and existing accuracy finding

Owner: MAIN ASTRA. Revision: 1. Updated: 2026-09-27. Research/review only.

Authority is the actual Settings user message `01a0e21a-f960-7632-8484-c28f7d1f468e`, independently verified in [Maps intake](PKG-08-maps-direction-intake.md): research missing Settings features and distinguish telemetry policy from unique tracker configuration. The same Designer returned a bounded read-only investigation on published926b4981. Main verified the key source references and independently reproduced the accuracy-normalisation issue below. This does not authorise implementation, operating-device commands, new tracker support, retention changes or replacing existing module owners.

## Recommended ownership for the next design

Fleet Settings should offer a compact Tracking & data overview: effective policies, distinct provider/contact/position health, provenance, impact/history and permissioned links. Reuse Security & Devices for compatible configuration profiles and individual tracker setup; Fleet retains vehicle-telemetry interpretation and operational policies. Client/People Location retain source/assignment/consent/privacy authority; Control Room retains response and escalation ownership. Do not add a universal tracker editor or one retention/freshness slider.

Main verified immutable version protection in `DeviceConfigurationProfile.php`, provider/domain/category filtering in `QueclinkConfigurationProfileService::compatibleProfiles()`, and the stronger exact GL30-family guard in `buildGovernedSequence()`. `ClientTrackerModeProfiles.php:22` additionally restricts client modes to specified GL30M-family models. These are existing foundations, not evidence that every tracker/model/firmware accepts every payload. Preserve provider-managed versus operator-controlled fields through `DeviceFieldOwnershipService`. Compatibility and verified device application must be proven for the actual supported target before any future configuration command.

Proposed design additions remain proposals until an exact subsequent mockup is approved: separate last contact and last usable fix with delayed/no-fix/withheld/unsupported states; effective inherited/override policy and profile provenance; source-owned retention/access summaries; intended versus confirmed device configuration with pending/failed/unknown/applied states; source-aware alert/suppression context linking to Control Room; and impact review with device-level outcomes for default/profile changes. Reuse existing notification/setup/history controls rather than duplicating them. No new thresholds, model payloads, retention periods or response suppression are established here.

## MAIN-TELEM-01 — P2: HDOP is assigned to a metre-accuracy field

**Status: source and pure normaliser reproduction confirmed; end-to-end ingestion/visible-device impact not yet exercised.** This is an existing issue in published926b4981, not introduced by the Settings mockup or recent Transport correction.

`app/Services/Fleet/Telemetry/QueclinkAdapter.php:42` assigns `accuracy_m` from `accuracy`, otherwise directly from `hdop`. Main independently ran the real adapter through Composer autoload only, without Laravel bootstrap, database, network or device access. Results:

- Synthetic `{hdop:1.8}` produces `accuracy_m:1.8`.
- Synthetic `{accuracy:7,hdop:1.8}` produces `accuracy_m:7`.
- With neither field present, the output is `accuracy_m:null`.

HDOP is a dimensionless precision factor, not a distance in metres; deriving a distance requires a supported error model and source semantics. [GPSD's SKY field definition](https://gpsd.io/gpsd_json.html#_sky), independently checked2026-09-27. No arbitrary conversion factor is justified by this source.

Main traced normalisation at `FleetTelemetryIngestService.php:134`–`:135`, persistence of non-withheld `accuracy_m` at312/337, and location metadata assignment at425. `VehicleLocationService.php:329` projects the event's `accuracy_m`. Thus an HDOP-only payload can carry a dimensionless quantity under a metre-labelled contract. This trace and the in-memory probe do not claim that a real tracker payload, active endpoint, map accuracy circle or database record was reproduced or repaired.

Required later correction/acceptance: preserve HDOP as its own quality value; expose metre accuracy only when supported by documented provider/model data or an explicit validated conversion. Preserve unknown values and privacy withholding. Test HDOP-only, true metre-accuracy, missing/invalid and privacy-withheld inputs through the actual routed ingestion and authorised projections. Assess any persisted values before proposing a separately governed repair; do not rewrite historical data by assumption. This finding must be resolved or explicitly dispositioned before a cross-location telemetry accuracy sign-off. No code or data fix has been released by this research intake.

## Separate policy concepts to retain

Main verified `VehicleTelemetryPresenter.php:28` uses15-minute sample freshness based on `occurred_at`, while `DetectFleetOfflineDevices.php:28` uses configurable offline contact timing and `last_seen_at`. Trip coverage and mileage freshness are separate concepts. Different thresholds are not automatically a defect; the interface and effective policy must explain contact health, usable position age, history coverage and mileage eligibility, including approved sleep/reporting modes. UI refresh frequency is not device acquisition or transmission frequency. No threshold has been changed or consolidated.

Main verified Fleet pruning uses `fleet.retention.telemetry_days`, personal pruning uses assignment `retention_days`, and raw-frame pruning has its own settings and hold checks in `PrunePersonalTrackingTelemetry`. An overview should project these source-owned rules rather than replace them. No existing duration, hold or privacy policy is altered.

The Designer also cited vendor device manuals and desired/reported-configuration documentation as research support. Those broader vendor details remain owner research, not separately verified Main compatibility claims. The concrete Main unit finding and cited source above are independently checked. The same Designer continues design research/refinement; no new session or implementation worker is required.
