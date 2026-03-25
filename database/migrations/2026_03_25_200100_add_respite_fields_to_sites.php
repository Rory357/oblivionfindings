<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (!Schema::hasColumn('sites', 'offers_respite')) {
                $table->boolean('offers_respite')->default(false)->after('onboarding_progress');
            }
            if (!Schema::hasColumn('sites', 'respite_capacity')) {
                $table->integer('respite_capacity')->nullable()->after('offers_respite');
            }
            if (!Schema::hasColumn('sites', 'respite_description')) {
                $table->text('respite_description')->nullable()->after('respite_capacity');
            }
            if (!Schema::hasColumn('sites', 'respite_contact_name')) {
                $table->string('respite_contact_name', 255)->nullable()->after('respite_description');
            }
            if (!Schema::hasColumn('sites', 'respite_contact_phone')) {
                $table->string('respite_contact_phone', 50)->nullable()->after('respite_contact_name');
            }
            if (!Schema::hasColumn('sites', 'respite_contact_email')) {
                $table->string('respite_contact_email', 255)->nullable()->after('respite_contact_phone');
            }
            if (!Schema::hasColumn('sites', 'respite_funding_types')) {
                $table->json('respite_funding_types')->nullable()->after('respite_contact_email');
            }
            if (!Schema::hasColumn('sites', 'respite_min_stay_days')) {
                $table->integer('respite_min_stay_days')->nullable()->after('respite_funding_types');
            }
            if (!Schema::hasColumn('sites', 'respite_max_stay_days')) {
                $table->integer('respite_max_stay_days')->nullable()->after('respite_min_stay_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $columns = [
                'offers_respite',
                'respite_capacity',
                'respite_description',
                'respite_contact_name',
                'respite_contact_phone',
                'respite_contact_email',
                'respite_funding_types',
                'respite_min_stay_days',
                'respite_max_stay_days',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
