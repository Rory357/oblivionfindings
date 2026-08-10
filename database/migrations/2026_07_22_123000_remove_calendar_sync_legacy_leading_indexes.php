<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, string> */
    private const INDEXES = [
        'calendar_sync_mappings' => 'calendar_sync_mappings_tenant_id_index',
        'calendar_sync_event_links' => 'calendar_sync_event_links_tenant_id_index',
        'calendar_sync_busy_blocks' => 'calendar_sync_busy_blocks_tenant_id_index',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $tableName => $indexName) {
            if (! Schema::hasIndex($tableName, $indexName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->dropIndex($indexName);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $tableName => $indexName) {
            if (Schema::hasIndex($tableName, $indexName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
                $table->index('tenant_id', $indexName);
            });
        }
    }
};
