<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->boolean('is_organisation_wide')
                ->default(false)
                ->after('site_id');
            $table->index(
                ['is_organisation_wide', 'status'],
                'it_tickets_organisation_wide_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('it_tickets', function (Blueprint $table) {
            $table->dropIndex('it_tickets_organisation_wide_status_idx');
            $table->dropColumn('is_organisation_wide');
        });
    }
};
