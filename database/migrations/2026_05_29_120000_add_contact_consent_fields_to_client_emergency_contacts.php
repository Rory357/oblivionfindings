<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Add Client wizard captures a fuller emergency-contact record than the
 * original table held — an alternate phone, a postal address, an explicit
 * "primary" flag, and the granular information-sharing consents that were
 * previously only modelled on next_of_kins. We store the whole contact as a
 * single client_emergency_contacts row (no portal User required) so it shows
 * on the client's profile immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_emergency_contacts')) {
            return;
        }

        Schema::table('client_emergency_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('client_emergency_contacts', 'alternate_phone')) {
                $table->string('alternate_phone')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('client_emergency_contacts', 'address')) {
                $table->string('address')->nullable()->after('email');
            }
            if (! Schema::hasColumn('client_emergency_contacts', 'is_primary_contact')) {
                $table->boolean('is_primary_contact')->default(false)->after('contact_order');
            }
            if (! Schema::hasColumn('client_emergency_contacts', 'can_view_medical')) {
                $table->boolean('can_view_medical')->default(false)->after('authorised_health_info');
            }
            if (! Schema::hasColumn('client_emergency_contacts', 'can_view_medications')) {
                $table->boolean('can_view_medications')->default(false)->after('can_view_medical');
            }
            if (! Schema::hasColumn('client_emergency_contacts', 'can_view_incidents')) {
                $table->boolean('can_view_incidents')->default(false)->after('can_view_medications');
            }
            if (! Schema::hasColumn('client_emergency_contacts', 'can_receive_updates')) {
                $table->boolean('can_receive_updates')->default(true)->after('can_view_incidents');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_emergency_contacts')) {
            return;
        }

        Schema::table('client_emergency_contacts', function (Blueprint $table) {
            $cols = [
                'alternate_phone',
                'address',
                'is_primary_contact',
                'can_view_medical',
                'can_view_medications',
                'can_view_incidents',
                'can_receive_updates',
            ];
            $drop = array_filter($cols, fn ($c) => Schema::hasColumn('client_emergency_contacts', $c));
            if ($drop) {
                $table->dropColumn($drop);
            }
        });
    }
};
