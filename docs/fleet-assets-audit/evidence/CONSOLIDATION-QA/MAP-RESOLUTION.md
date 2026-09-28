# MAIN-CONSOL-01 resolution

Main explicitly authorised the compatibility-only legacy map correction after reviewing the original synthetic 200 responses for both asset-only permissions. The original finding and failing evidence are retained unchanged.

LiveMapController now requires fleet.viewAny or assets.geofences.manage and returns only the redirect to the canonical Maps & boundaries workspace. The named /fleet-assets/map route has its own matching middleware so a geofence-only reader can reach that workspace without broadening the shared Overview/compliance/daily-check group. The obsolete direct coordinate, boundary and alert-count projection is removed.

Permanent regressions cover assets.viewAny and assets.viewAssigned denial with consent-blocked and personal-trip snapshots present, plus Fleet and geofence-only reader redirects. Updated canonical-map tests retain permitted/unassigned Site distinctions, enforce alert-view authority and location privacy, and demonstrate explicit secondary-Site access. fleet.manage does not grant all Sites in that workspace.

This is the only application behavior change after combined source f3fd2f77657c7fe09022ff69562e8c3487c9668b: app/Http/Controllers/FleetAssets/LiveMapController.php and routes/fleet-assets.php. Other subsequent edits are bounded test corrections and new regression evidence. Main can reuse its independent Reports/People review at f3fd2f776 for unchanged sources and review this explicit two-file correction separately.

The architecture fixture failure was resolved by using IntegrationEvent's normal creating hook; the single-tenant architecture guard was not weakened. The separate stale Fleet Overview architecture case now follows DashboardController -> FleetOverviewService -> VehicleLocationService while retaining role, approved-Site, no-partition and no-raw-payload checks.
