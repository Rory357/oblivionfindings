<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array<string, array<int, string>>> */
    private array $indexes = [
        'site_hazards' => [
            'site_hazards_site_status_due_idx' => ['site_id', 'status', 'due_date'],
            'site_hazards_site_status_review_idx' => ['site_id', 'status', 'review_date'],
        ],
        'emergency_drills' => [
            'emergency_drills_site_status_scheduled_idx' => ['site_id', 'status', 'scheduled_at'],
        ],
        'site_documents' => [
            'site_documents_site_expiry_idx' => ['site_id', 'expiry_date'],
        ],
        'assets' => [
            'assets_site_inspection_due_idx' => ['site_id', 'requires_inspection', 'inspection_due_at'],
            'assets_site_maintenance_due_idx' => ['site_id', 'requires_maintenance', 'maintenance_due_at'],
        ],
        'ppe_inventory' => [
            'ppe_site_status_inspection_due_idx' => ['site_id', 'status', 'next_inspection_due'],
            'ppe_site_status_expiry_idx' => ['site_id', 'status', 'expiry_date'],
        ],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if (Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                    $blueprint->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $name) {
                if (! Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }
};
