<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // RBAC + full demo dataset for realistic end-to-end testing.
        // Run with: php artisan migrate:fresh --seed
        $this->call(RbacSeeder::class);
        $this->call(ItServiceCatalogSeeder::class);
        $this->call(SeedHrPermissionsSeeder::class);
        $this->call(OperationsPermissionsSeeder::class);
        $this->call(FinancePermissionsSeeder::class);
        $this->call(SeedCalendarPermissionsSeeder::class);
        $this->call(SystemCatalogSeeder::class);
        $this->call(SystemUsersSeeder::class);
        $this->call(SystemClientsSeeder::class);
        $this->call(NextOfKinSeeder::class);
        $this->call(OperationsDemoSeeder::class);
        $this->call(SystemAssetsSeeder::class);
        $this->call(SystemShiftsSeeder::class);
        $this->call(FrontlineLifecycleDemoSeeder::class);
        $this->call(SystemMedicationsSeeder::class);
        $this->call(MedicationEnterpriseSeeder::class);
        $this->call(SystemIncidentsSeeder::class);
        $this->call(SystemDocumentsAndNotesSeeder::class);
        $this->call(SafeguardingSeeder::class);
        $this->call(SitesModuleSeeder::class);
        $this->call(SiteEmergencyPlanSeeder::class);
        $this->call(MedicationWorkflowDemoSeeder::class);
        $this->call(MedicationDashboardDemoSeeder::class);
        $this->call(MedicationRoundsDemoSeeder::class);

        // Compliance module seeders
        $this->call(ConsentTypesSeeder::class);
        $this->call(StandardConsentTypesSeeder::class);
        $this->call(TrainingCoursesSeeder::class);
        $this->call(CompetencyFrameworksSeeder::class);
        $this->call(NzComplianceObligationsSeeder::class);
        $this->call(RespiteRetentionPolicySeeder::class);
        $this->call(RespiteDemoSeeder::class);
        $this->call(AssetCategoriesSeeder::class);
        $this->call(AssetProcedureTemplatesSeeder::class);
        $this->call(FleetDemoSeeder::class);
        $this->call(FleetManagementSeeder::class);
        $this->call(ControlRoomSeeder::class);
        $this->call(GovernancePermissionsSeeder::class);
        $this->call(SecurityDevicesPermissionsSeeder::class);
        $this->call(QueclinkPresetSeeder::class);
        $this->call(RoadmapPermissionsSeeder::class);
        $this->call(RoadmapSeeder::class);
        $this->call(BoardMemberSeeder::class);
        $this->call(GovernanceSeeder::class);
        $this->call(HrSeeder::class);
        $this->call(HrPublicHolidaysSeeder::class);
        $this->call(HrPayEquityBandsSeeder::class);
        $this->call(RoleCatalogSeeder::class);
        $this->call(CateringPermissionsSeeder::class);
        $this->call(CateringSeeder::class);
        $this->call(CateringDemoSeeder::class);

        // Additional demo/debug seeders kept in main seeding flow for full dataset coverage.
        $this->call(DemoSeeder::class);
        $this->call(HrDemoSeeder::class);
        $this->call(FinanceDemoSeeder::class);
        $this->call(DebugMedicalData::class);
        $this->call(FamilyPortalDemoSeeder::class);
        $this->call(RosteringProductionDemoSeeder::class);

        // Health & Safety demo dataset + right-sized worked-hours basis for the LTIFR/TRIFR
        // denominator (both idempotent). Run late so clients/users/sites/events all exist.
        $this->call(HealthSafetyDemoSeeder::class);
        $this->call(HealthSafetyBillingDemoSeeder::class);

        $this->call(SeedAllPermissionsToAdminSeeder::class);
    }
}
