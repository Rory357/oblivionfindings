# I1 — observed vehicle evidence and current use decisions

22 September 2026. Root reviewed the backend proposal and approves this bounded implementation contract. This is an internal implementation increment within Main's released full v13 scope, not a new product approval. Single organisation; canonical roles, approved sites, object ownership and privacy apply.

## Custody

Setup baseline commit `0744e5ffd` follows integration baseline `5307692ec59be84f3503c06354419b7da95be805`. Root remains Astra/xhigh. Sole worker `/root/vehicle_backend` is independently verified Sol/high (proof in BUILD-CHECKPOINT). Once explicitly granted, worker is the only application writer until its scoped commit and handoff. Root may read source and write implementation evidence, but not application files during that custody. No React/CSS/design/preview edits, new worker, Main messaging or operational storage. No blanket staging.

## Canonical data and additive migration

One timestamped PKG-02B readiness migration adds:

- `fleet_vehicle_compliance_records`: canonical Asset ID, requirement kind (`registration`, `wof`, `cof`, `ruc`), current version pointer, timestamps; unique asset/kind. Kinds identify assessment domains, never automatic legal applicability.
- `fleet_vehicle_compliance_versions`: immutable record/version/superseded ID; applicability (`unknown`, `applicable`, `not_applicable`) and basis; source type/ID/reference; outcome (`needs_assessment`, `recorded`, `passed`, `failed`); optional evidence reference and same-Asset document ID; date-only effective/expiry; RUC start/end km; observed and created timestamps, actor, reason, request key/fingerprint/content hash. Unique record/version and record/request key. Asset then record lock protects the current pointer. Validate that linked document actually exists and belongs to the authorised Asset; an ID alone must not assert verified file evidence.
- `fleet_vehicle_odometer_observations`: immutable Asset, value, observed timestamp, source kind (`dashboard_manual`, `booking_checkout`, `booking_return`, `inspection`, `legacy_unverified`), source reference/type/ID, actor, optional corrected observation ID, correction reason, request key/fingerprint and creation timestamp. A correction creates a new row and retains the original. Effective selection excludes corrected originals and orders by observed time and ID. Validate correction belongs to the same Asset, is current within its lineage, and is not already corrected by another request.
- FleetVehicleBooking compatible approval columns: approval route defaults required; optional no-approval reason/evidence and actor/time, approved_at. Existing `approved` status means confirmed. No-approval confirmation has authority provenance and does not impersonate an independent approver.

Add three corresponding Eloquent models and Asset relationships; extend FleetVehicleBooking fields/casts. Existing date/odometer columns remain compatibility projections, never an alternate readiness authority.

## Commands and assessment

`VehicleComplianceService::record` resolves current fleet.manage permission and canonical scoped Asset, rechecks under lock, validates explicit assessment, retains immutable version and actor/source, and returns retry-identical output only for an identical fingerprint. The user may save unknown/needs-assessment evidence without inventing a reference, expiry or range. An applicable assessed/passed state requires its real recorded evidence and appropriate date/range. Not applicable requires a non-empty recorded basis; v13 does not require an extra reference or invented date. A valid current expiry may update the old Asset projection; it never makes a missing assessment acceptable.

`VehicleOdometerService::record/currentObserved/latestTrackerEstimate` owns observation recording and corrections. User-entered observed values remain separate from device estimates. Booking checkout/return use the same canonical recorder. Corrections preserve reason and original; idempotent retries cannot create extra readings. Readiness requires an observed value for applicable RUC, rather than imposing an unsupported odometer requirement on sourced Not applicable RUC. Checkout independently requires its current observation under the approved workflow.

`VehicleReadinessService::assess` and a typed context/assessment/reason DTO provide one source for profile, calendar, booking request/confirmation/approval/checkout and Maintenance release evidence gates. DTO includes status/can_proceed, typed reason code/scope/kind/source/version IDs, assessed_at, version IDs, observed odometer ID/value, separately permissioned tracker estimate, restriction/check IDs and input fingerprint. Projection is vehicle readiness, never a future driver/booking guarantee. Decisions use current evidence under the Asset serialization lock and persist source IDs/fingerprint in existing audit/action records.

Evidence blockers: inactive Asset; unassessed/missing/unknown domain; applicable needs-assessment/failed outcome; missing assessed evidence/date; expiry before the current Pacific/Auckland local date; invalid applicable RUC range; missing accepted observed odometer for RUC; observed/current checkout value strictly greater than RUC end. Equality preserves v13 behavior without claiming a legal threshold. No default fuel/type applicability, invented due-soon threshold, or tracker substitution.

Use decisions also preserve canonical active restriction and unresolved check rules, current staff/HR driver eligibility and licence through relevant use dates, overlaps/custody and source coverage. Pending booking requests can be retained with blocking reasons; confirmation, explicit approval and checkout must fail closed. No-approval is permissioned (`fleet.bookings.approve` or `fleet.manage`), requires reason or evidence, retains actor/time and passes the same safety assessment. Required-approval retains no self-approval.

## Release semantics — preserve PKG-01

MaintenanceTransitionService already independently proves reviewer grant, current repair/retest/release rules, attestation/evidence, a later passing retest of the exact active restriction set, no newer unresolved check, and required custody. Keep those contracts and Asset→work→sources lock order.

Apply current compliance/RUC evidence gates before releasing that work's sources and retain assessment fingerprint/version IDs in the release payload. Precisely covered original failed checks must not remain an impossible blocker after their validated passing retest. Other work's holds remain active and keep vehicle readiness false. **Do not add a new prohibition on releasing one work merely because a different work remains restricted**: the existing implementation explicitly supports per-work release; changing it would create a two-hold deadlock. The shared typed context distinguishes source release from global availability. Never accept client-provided ignore IDs or a broad bypass flag.

## Access, ordering and compatibility

- Reuse SecurityDevicesAccessService/VehicleBookingAccessService and MaintenanceRestrictionService blocker semantics. Canonical missing/foreign direct-ID responses remain indistinguishable. Revalidate current role/site/driver authority inside mutation boundaries, not only preflight.
- Booking lock order remains ordered Sites → optional Client → Asset → Booking/overlapping bookings. Asset serializes even empty booking ranges and current compliance/odometer writes. Maintenance remains Asset → Work → Actions/restrictions/checks; no reverse lock acquisition added.
- Add backend-only scoped POST record endpoints under vehicle profile for compliance versions and odometer observations. Return persisted IDs/versions/assessment for subsequent UI wiring. Existing index/profile/calendar status DTOs use the same evidence assessment.
- Correct unscoped Vehicle/Compliance controller projections in touched paths. Keep the separately permissioned vehicle technology projection. Do not expose source documents/telemetry payloads or hidden driver details simply because readiness was computed internally.
- Generic Asset/Vehicle update paths must not silently change authoritative compliance or observed mileage through old scalar fields. Reject unsupported direct fields with an actionable error or route to the canonical command with explicit original provenance. Do not silently accept-and-ignore input.

## Backfill and rollback

Backfill each existing non-null registration/WoF/CoF Asset date as an immutable legacy source with `unknown` applicability and `needs_assessment`; it stays readable but does not become evidence of a pass. No invented RUC row, licence range or not-applicable decision. Existing odometer becomes `legacy_unverified`, visibly retained but not a verified RUC observation. New Assets with no sources remain unassessed. No forced operating policy.

Old columns stay. Additive migration down is guarded: refuse rollback if non-legacy compliance/odometer evidence or new booking approval provenance exists. Only untouched legacy backfill may be dropped in isolated rollback verification. No operational database migrations, broad cleanup or automatic history deletion.

## Scoped verification and handoff

Before Laravel bootstrap run `implementation/verify-isolation.php`, then only `phpunit.pkg02b.xml`; inherited process tokens/URLs/config caches must be absent. Worker owns its test run while it owns application custody. Existing page/booking baseline passed 12 tests, 179 assertions.

Cover permission/site/direct-ID/privacy, immutable versions/correction lineage, duplicate request fingerprints/conflicting retries, same-Asset evidence, current authority withdrawal, date/local-day handling, B01 across all use/release routes, B02 actual observed and checkout candidates/equality/Not applicable controls, unknown/failed/expired/missing evidence, tracker separation, assessment projection/decision parity, driver/time/conflict/custody checks and serialization. Preserve PKG-01 independent and per-work release semantics, including multiple simultaneous holds, rather than weakening older tests to conceal new behavior. Update fixtures with explicit valid evidence where a newly required prerequisite applies.

Run relevant existing VehiclePageContract, VehicleBookingSitePrivacy and targeted PKG-01/Fleet maintenance suites; classify unmodified baseline failures with evidence. Commit only I1 application/test/migration changes and its evidence. Return exact commit, files/DTO/routes, tests, residual gaps and custody. Root reviews before dependent frontend writes. At most two worker correction passes per underlying issue; maintain identity/count.
