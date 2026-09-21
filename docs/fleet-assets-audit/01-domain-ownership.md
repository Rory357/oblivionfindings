# Observed domain ownership

Owner: MAIN ASTRA. Revision: 2. Updated: 2026-09-19.
Status: Full audit source map; proposed contracts unapproved. Source: Revision 10 + A1/A2; local code baseline `19354ecbc70046d12dfdf9c86f888e65fa1879d1`.

The following relationships are present at the recorded local baseline. “Present” does not mean the complete workflow passed. See the [full audit](10-full-audit.md) and [integration matrix](13-integration-matrix.md) for findings, evidence and target gaps.

- **Assets / vehicles:** [Asset](../../app/Models/Asset.php) owns the shared identity; vehicle routes bind `{asset}`. Fleet bookings, trips, work orders and schedules reference it. The master's shared-identity target already has a foundation.
- **Bookings:** [FleetVehicleBooking](../../app/Models/FleetVehicleBooking.php), [VehicleBookingController](../../app/Http/Controllers/FleetAssets/VehicleBookingController.php) and [VehicleBookingAccessService](../../app/Services/Fleet/VehicleBookingAccessService.php) own reservation records and scoped access. Complete shared downtime/readiness calculation is not established.
- **Sites:** [Site](../../app/Models/Site.php) retains head_office, house and facility types. Existing SiteHouseRoom, SiteRoom and facility-zone relationships must be reconciled as existing structures, not replaced by another hierarchy.
- **Boundaries:** [AssetGeofence](../../app/Models/AssetGeofence.php) is runtime geometry shared with Sites. [GeofenceZone](../../app/Models/GeofenceZone.php) is explicitly retired/read-only migration access.
- **Devices:** [canonical Device](../../app/Domain/SecurityDevices/Models/Device.php), DeviceAssetLink and DeviceAssignment own identity/linkage. [AssetTracker](../../app/Models/AssetTracker.php) is a deliberate history/consent compatibility bridge, not a new-write identity.
- **Clients / authorisation:** ClientConsent and [PersonalTrackingPrivacyService](../../app/Domain/SecurityDevices/Services/PersonalTrackingPrivacyService.php) participate in assignment-bound tracking consent. Client request records and actual Fleet journeys are distinct.
- **HR:** [HrAsset](../../app/Domain/Hr/Models/HrAsset.php) links through fleet_asset_id. HrDriverEligibility is consumed by Fleet. Employment and eligibility remain HR records.
- **Finance:** [FinFixedAsset](../../app/Domain/Finance/Models/FinFixedAsset.php) links via linked_asset_id. [projection presenter](../../app/Domain/Finance/Presenters/AssetFinanceTechnologyProjectionPresenter.php) explicitly separates operational, financial and device owners.
- **Control Room / IT:** [DispatchFleetSignalOutbox](../../app/Jobs/DispatchFleetSignalOutbox.php) routes Fleet signals into Control Room and includes an IT-delivery integration. Preserve the established signal/alert pathway.
- **Shared tasks/calendar:** [TaskAggregator](../../app/Services/Tasks/TaskAggregator.php) includes FleetMaintenanceProvider; [SiteCalendarAggregator](../../app/Services/Sites/Calendar/SiteCalendarAggregator.php) includes FleetServiceScheduleObligationProvider.

The full audit traced these foundations and identified incomplete allocation/readiness/custody, independent portal sharing and Finance approval/recovery contracts. The [integration matrix](13-integration-matrix.md) separates current owners from proposed contracts and unexecuted runtime checks. No ownership change or migration is approved. Exact new fields/services and room compatibility remain implementation-design decisions behind the applicable approval gates.
