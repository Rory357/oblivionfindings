<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G3 / Q11 (Stephan, 31 Oct; boards approved 1 Nov 2026): when a check says an action didn't work, its safety review
 * can't close until a revised action is added, done and checked. The revised action records which failed action it
 * replaces, so readiness can follow the chain. Nullable: ordinary actions replace nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('hs_corrective_actions', 'replaces_action_id')) {
            return;
        }

        Schema::table('hs_corrective_actions', function (Blueprint $table): void {
            $table->foreignId('replaces_action_id')
                ->nullable()
                ->after('hs_investigation_id')
                ->constrained('hs_corrective_actions', indexName: 'hs_ca_replaces_action_fk')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('hs_corrective_actions', 'replaces_action_id')) {
            return;
        }

        Schema::table('hs_corrective_actions', function (Blueprint $table): void {
            $table->dropForeign('hs_ca_replaces_action_fk');
            $table->dropColumn('replaces_action_id');
        });
    }
};
