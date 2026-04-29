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
        $this->call(OperationsPermissionsSeeder::class);
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
        $this->call(MedicationWorkflowDemoSeeder::class);
        $this->call(MedicationDashboardDemoSeeder::class);

        // Compliance module seeders
        $this->call(ConsentTypesSeeder::class);
        $this->call(StandardConsentTypesSeeder::class);
        $this->call(TrainingCoursesSeeder::class);
        $this->call(CompetencyFrameworksSeeder::class);
        $this->call(NzComplianceObligationsSeeder::class);
        $this->call(AssetCategoriesSeeder::class);
        $this->call(AssetProcedureTemplatesSeeder::class);
        $this->call(FleetDemoSeeder::class);
        $this->call(FleetManagementSeeder::class);
        $this->call(ControlRoomSeeder::class);
        $this->call(GovernancePermissionsSeeder::class);
        $this->call(SecurityDevicesPermissionsSeeder::class);
        $this->call(RoadmapPermissionsSeeder::class);
        $this->call(RoadmapSeeder::class);
        $this->call(BoardMemberSeeder::class);
        $this->call(GovernanceSeeder::class);
        $this->call(HrSeeder::class);
        $this->call(RoleCatalogSeeder::class);

        // Additional demo/debug seeders kept in main seeding flow for full dataset coverage.
        $this->call(DemoSeeder::class);
        $this->call(DebugMedicalData::class);
        $this->call(FamilyPortalDemoSeeder::class);
        $this->call(RosteringProductionDemoSeeder::class);
    }
}
