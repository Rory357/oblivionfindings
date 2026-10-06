<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'medications.pharmacy.connect.manage' => 'Manage approved pharmacy connections',
            'medications.pharmacy.send' => 'Send pharmacy supply orders',
            'medications.external.manage' => 'Manage named external clinician access',
            'medications.transfers.manage' => 'Manage provider medication handovers',
            'medications.catalogue.manage' => 'Manage licensed medicine image sources',
            'medications.backups.manage' => 'Manage protected medication backups',
        ] as $key => $description) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'description' => $description, 'group' => 'medications', 'module' => 'Clinical', 'created_at' => now(), 'updated_at' => now()]);
        }
        // Deliberately no role grants: each connection capability is assigned explicitly.
    }

    public function down(): void
    {
        // Preserve permission identities and existing grants during rollback.
    }
};
