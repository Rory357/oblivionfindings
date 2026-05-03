<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_events')) {
            return;
        }

        if (Schema::hasIndex('integration_events', 'integration_events_provider_source_event_id_unique')) {
            Schema::table('integration_events', function (Blueprint $table): void {
                $table->dropUnique('integration_events_provider_source_event_id_unique');
            });
        }

        if (! Schema::hasIndex('integration_events', 'integration_events_tenant_provider_source_event_unique')) {
            Schema::table('integration_events', function (Blueprint $table): void {
                $table->unique(
                    ['tenant_id', 'provider', 'source_event_id'],
                    'integration_events_tenant_provider_source_event_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('integration_events')) {
            return;
        }

        if (Schema::hasIndex('integration_events', 'integration_events_tenant_provider_source_event_unique')) {
            Schema::table('integration_events', function (Blueprint $table): void {
                $table->dropUnique('integration_events_tenant_provider_source_event_unique');
            });
        }

        if (! Schema::hasIndex('integration_events', 'integration_events_provider_source_event_id_unique')) {
            Schema::table('integration_events', function (Blueprint $table): void {
                $table->unique(['provider', 'source_event_id']);
            });
        }
    }
};
