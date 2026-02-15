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
        $this->call(SystemCatalogSeeder::class);
        $this->call(SystemUsersSeeder::class);
        $this->call(SystemClientsSeeder::class);
        $this->call(NextOfKinSeeder::class);
        $this->call(SystemAssetsSeeder::class);
        $this->call(SystemShiftsSeeder::class);
        $this->call(SystemMedicationsSeeder::class);
        $this->call(SystemIncidentsSeeder::class);
        $this->call(SystemDocumentsAndNotesSeeder::class);
        $this->call(SafeguardingSeeder::class);

        // Compliance module seeders
        $this->call(ConsentTypesSeeder::class);
        $this->call(TrainingCoursesSeeder::class);
        $this->call(CompetencyFrameworksSeeder::class);
        $this->call(AssetCategoriesSeeder::class);
        $this->call(AssetProcedureTemplatesSeeder::class);
        $this->call(AssetAlertPoliciesSeeder::class);
        $this->call(FleetDemoSeeder::class);
        $this->call(FleetManagementSeeder::class);
        $this->call(ControlRoomSeeder::class);
        $this->call(GovernancePermissionsSeeder::class);
        $this->call(RoadmapPermissionsSeeder::class);
        $this->call(RoadmapSeeder::class);
        $this->call(BoardMemberSeeder::class);
        $this->call(GovernanceSeeder::class);

        // NOTE: RoleCatalogSeeder created a large catalogue of job-title roles.
        // We are not using those roles in the system right now, so we no longer seed them.
        // (Keeps Access Control role list clean and prevents accidental assignment.)
        // $this->call(RoleCatalogSeeder::class);
    }
}
