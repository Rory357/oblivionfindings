<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_medical_profiles')) {
            Schema::table('client_medical_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('client_medical_profiles', 'gp_name')) {
                    $table->string('gp_name', 255)->nullable()->after('notes');
                }
                if (! Schema::hasColumn('client_medical_profiles', 'gp_practice')) {
                    $table->string('gp_practice', 255)->nullable()->after('gp_name');
                }
                if (! Schema::hasColumn('client_medical_profiles', 'gp_phone')) {
                    $table->string('gp_phone', 50)->nullable()->after('gp_practice');
                }
                if (! Schema::hasColumn('client_medical_profiles', 'hospital_preference')) {
                    $table->string('hospital_preference', 255)->nullable()->after('gp_phone');
                }
                if (! Schema::hasColumn('client_medical_profiles', 'blood_type')) {
                    $table->string('blood_type', 10)->nullable()->after('hospital_preference');
                }
                if (! Schema::hasColumn('client_medical_profiles', 'organ_donor')) {
                    $table->boolean('organ_donor')->default(false)->after('blood_type');
                }
                if (! Schema::hasColumn('client_medical_profiles', 'immunisation_notes')) {
                    $table->text('immunisation_notes')->nullable()->after('organ_donor');
                }
                if (! Schema::hasColumn('client_medical_profiles', 'mental_health_history')) {
                    $table->text('mental_health_history')->nullable()->after('immunisation_notes');
                }
                if (! Schema::hasColumn('client_medical_profiles', 'surgical_history')) {
                    $table->text('surgical_history')->nullable()->after('mental_health_history');
                }
            });
        }

        if (Schema::hasTable('client_medications')) {
            Schema::table('client_medications', function (Blueprint $table) {
                if (! Schema::hasColumn('client_medications', 'indication')) {
                    $table->text('indication')->nullable()->after('prescriber');
                }
                if (! Schema::hasColumn('client_medications', 'review_date')) {
                    $table->date('review_date')->nullable()->after('end_date');
                }
            });
        }

        if (Schema::hasTable('client_emergency_contacts')) {
            Schema::table('client_emergency_contacts', function (Blueprint $table) {
                if (! Schema::hasColumn('client_emergency_contacts', 'contact_order')) {
                    $table->tinyInteger('contact_order')->unsigned()->default(1)->after('notes');
                }
                if (! Schema::hasColumn('client_emergency_contacts', 'preferred_method')) {
                    $table->string('preferred_method', 50)->nullable()->after('contact_order');
                }
                if (! Schema::hasColumn('client_emergency_contacts', 'availability')) {
                    $table->string('availability', 255)->nullable()->after('preferred_method');
                }
                if (! Schema::hasColumn('client_emergency_contacts', 'authorised_health_info')) {
                    $table->boolean('authorised_health_info')->default(false)->after('availability');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_medical_profiles')) {
            Schema::table('client_medical_profiles', function (Blueprint $table) {
                $cols = [
                    'gp_name', 'gp_practice', 'gp_phone', 'hospital_preference',
                    'blood_type', 'organ_donor', 'immunisation_notes',
                    'mental_health_history', 'surgical_history',
                ];
                $drop = array_filter($cols, fn ($c) => Schema::hasColumn('client_medical_profiles', $c));
                if ($drop) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (Schema::hasTable('client_medications')) {
            Schema::table('client_medications', function (Blueprint $table) {
                $cols = ['indication', 'review_date'];
                $drop = array_filter($cols, fn ($c) => Schema::hasColumn('client_medications', $c));
                if ($drop) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (Schema::hasTable('client_emergency_contacts')) {
            Schema::table('client_emergency_contacts', function (Blueprint $table) {
                $cols = ['contact_order', 'preferred_method', 'availability', 'authorised_health_info'];
                $drop = array_filter($cols, fn ($c) => Schema::hasColumn('client_emergency_contacts', $c));
                if ($drop) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
