# Permission Seeder Consolidation Plan

## Current State
The following seeders create overlapping permissions and roles:
- `RbacSeeder.php` - Core roles + permissions (primary source)
- `FinancePermissionsSeeder.php` - Finance module permissions
- `GovernancePermissionsSeeder.php` - Governance permissions + board role assignments
- `OperationsPermissionsSeeder.php` - Operations permissions
- `RoadmapPermissionsSeeder.php` - Roadmap permissions + role creation
- `SeedHrPermissionsSeeder.php` - HR permissions (overlaps with RbacSeeder HR section)
- `RoleCatalogSeeder.php` - Display-name roles (legacy, uses different naming convention)
- `SeedAllPermissionsToAdminSeeder.php` - Grants all to admin

## Issues
1. Roles created in multiple seeders (e.g., board_chair in both RbacSeeder and GovernancePermissionsSeeder)
2. HR permissions duplicated between RbacSeeder and SeedHrPermissionsSeeder
3. RoleCatalogSeeder uses display names while RbacSeeder uses snake_case
4. Run order matters - if seeders run in wrong order, permissions may be missing

## Recommended Consolidation
1. Merge ALL permission definitions into RbacSeeder
2. Remove duplicate role creation from GovernancePermissionsSeeder, RoadmapPermissionsSeeder
3. Deprecate RoleCatalogSeeder (migrate display-name roles to snake_case)
4. Keep FinancePermissionsSeeder as standalone (new module, clean separation)
5. Run `SeedAllPermissionsToAdminSeeder` last to catch any new permissions
