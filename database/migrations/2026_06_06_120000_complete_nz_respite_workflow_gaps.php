<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'nhi_hash')) {
                $table->string('nhi_hash', 64)->nullable()->after('nhi_number')->index();
            }
            if (! Schema::hasColumn('clients', 'iwi')) {
                $table->string('iwi')->nullable()->after('ethnicity');
            }
            if (! Schema::hasColumn('clients', 'hapu')) {
                $table->string('hapu')->nullable()->after('iwi');
            }
            if (! Schema::hasColumn('clients', 'marae')) {
                $table->string('marae')->nullable()->after('hapu');
            }
            if (! Schema::hasColumn('clients', 'cultural_dietary_needs')) {
                $table->text('cultural_dietary_needs')->nullable()->after('dietary_requirements');
            }
        });

        Schema::table('respite_referrals', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_referrals', 'nhi_number')) {
                $table->text('nhi_number')->nullable()->after('client_id');
            }
            if (! Schema::hasColumn('respite_referrals', 'nhi_hash')) {
                $table->string('nhi_hash', 64)->nullable()->after('nhi_number')->index();
            }
            if (! Schema::hasColumn('respite_referrals', 'third_party_source_type')) {
                $table->string('third_party_source_type')->nullable()->after('referrer_contact');
            }
            if (! Schema::hasColumn('respite_referrals', 'third_party_source_name')) {
                $table->string('third_party_source_name')->nullable()->after('third_party_source_type');
            }
            if (! Schema::hasColumn('respite_referrals', 'third_party_collection_consent')) {
                $table->boolean('third_party_collection_consent')->default(false)->after('third_party_source_name');
            }
            if (! Schema::hasColumn('respite_referrals', 'is_maori')) {
                $table->boolean('is_maori')->default(false)->after('risk_level');
            }
            if (! Schema::hasColumn('respite_referrals', 'ethnicity')) {
                $table->string('ethnicity')->nullable()->after('is_maori');
            }
            if (! Schema::hasColumn('respite_referrals', 'iwi')) {
                $table->string('iwi')->nullable()->after('ethnicity');
            }
            if (! Schema::hasColumn('respite_referrals', 'hapu')) {
                $table->string('hapu')->nullable()->after('iwi');
            }
            if (! Schema::hasColumn('respite_referrals', 'marae')) {
                $table->string('marae')->nullable()->after('hapu');
            }
            if (! Schema::hasColumn('respite_referrals', 'interpreter_required')) {
                $table->boolean('interpreter_required')->default(false)->after('marae');
            }
            if (! Schema::hasColumn('respite_referrals', 'interpreter_language')) {
                $table->string('interpreter_language')->nullable()->after('interpreter_required');
            }
            if (! Schema::hasColumn('respite_referrals', 'interpreter_arranged')) {
                $table->boolean('interpreter_arranged')->default(false)->after('interpreter_language');
            }
            if (! Schema::hasColumn('respite_referrals', 'cultural_considerations')) {
                $table->text('cultural_considerations')->nullable()->after('interpreter_arranged');
            }
            if (! Schema::hasColumn('respite_referrals', 'cultural_dietary_needs')) {
                $table->text('cultural_dietary_needs')->nullable()->after('cultural_considerations');
            }
            if (! Schema::hasColumn('respite_referrals', 'primary_carer_name')) {
                $table->string('primary_carer_name')->nullable()->after('cultural_dietary_needs');
            }
            if (! Schema::hasColumn('respite_referrals', 'primary_carer_relationship')) {
                $table->string('primary_carer_relationship')->nullable()->after('primary_carer_name');
            }
            if (! Schema::hasColumn('respite_referrals', 'primary_carer_contact')) {
                $table->string('primary_carer_contact')->nullable()->after('primary_carer_relationship');
            }
            if (! Schema::hasColumn('respite_referrals', 'carer_strain_level')) {
                $table->string('carer_strain_level')->nullable()->after('primary_carer_contact');
            }
            if (! Schema::hasColumn('respite_referrals', 'carer_breakdown_flag')) {
                $table->boolean('carer_breakdown_flag')->default(false)->after('carer_strain_level');
            }
            if (! Schema::hasColumn('respite_referrals', 'booker_type')) {
                $table->string('booker_type')->nullable()->after('carer_breakdown_flag');
            }
        });

        Schema::table('respite_booking_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_booking_requests', 'waitlist_position')) {
                $table->unsignedInteger('waitlist_position')->nullable()->after('status');
            }
            if (! Schema::hasColumn('respite_booking_requests', 'priority')) {
                $table->string('priority')->default('routine')->after('waitlist_position');
            }
            if (! Schema::hasColumn('respite_booking_requests', 'expected_availability_date')) {
                $table->date('expected_availability_date')->nullable()->after('priority');
            }
            if (! Schema::hasColumn('respite_booking_requests', 'is_emergency')) {
                $table->boolean('is_emergency')->default(false)->after('expected_availability_date');
            }
            if (! Schema::hasColumn('respite_booking_requests', 'fast_tracked')) {
                $table->boolean('fast_tracked')->default(false)->after('is_emergency');
            }
            if (! Schema::hasColumn('respite_booking_requests', 'intake_snapshot')) {
                $table->json('intake_snapshot')->nullable()->after('requirements');
            }
        });

        Schema::table('respite_bookings', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_bookings', 'agreement_status')) {
                $table->string('agreement_status')->default('not_sent')->after('funding_status');
            }
            if (! Schema::hasColumn('respite_bookings', 'consent_authority')) {
                $table->string('consent_authority')->nullable()->after('agreement_status');
            }
            if (! Schema::hasColumn('respite_bookings', 'consent_authority_name')) {
                $table->string('consent_authority_name')->nullable()->after('consent_authority');
            }
            if (! Schema::hasColumn('respite_bookings', 'consent_authority_contact')) {
                $table->string('consent_authority_contact')->nullable()->after('consent_authority_name');
            }
            if (! Schema::hasColumn('respite_bookings', 'consent_authority_evidence')) {
                $table->json('consent_authority_evidence')->nullable()->after('consent_authority_contact');
            }
            if (! Schema::hasColumn('respite_bookings', 'family_portal_consent_bound_at')) {
                $table->timestamp('family_portal_consent_bound_at')->nullable()->after('consent_authority_evidence');
            }
            if (! Schema::hasColumn('respite_bookings', 'family_portal_consent_bound_by')) {
                $table->foreignId('family_portal_consent_bound_by')->nullable()->after('family_portal_consent_bound_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('respite_bookings', 'cancellation_source')) {
                $table->string('cancellation_source')->nullable()->after('cancellation_reason');
            }
            if (! Schema::hasColumn('respite_bookings', 'cancellation_notice_hours')) {
                $table->unsignedInteger('cancellation_notice_hours')->nullable()->after('cancellation_source');
            }
            if (! Schema::hasColumn('respite_bookings', 'readiness_override_reason')) {
                $table->text('readiness_override_reason')->nullable()->after('approvals');
            }
            if (! Schema::hasColumn('respite_bookings', 'capacity_override_reason')) {
                $table->text('capacity_override_reason')->nullable()->after('readiness_override_reason');
            }
            if (! Schema::hasColumn('respite_bookings', 'cultural_snapshot')) {
                $table->json('cultural_snapshot')->nullable()->after('capacity_override_reason');
            }
            if (! Schema::hasColumn('respite_bookings', 'interpreter_arranged')) {
                $table->boolean('interpreter_arranged')->default(false)->after('cultural_snapshot');
            }
            if (! Schema::hasColumn('respite_bookings', 'copayment_amount')) {
                $table->decimal('copayment_amount', 10, 2)->nullable()->after('interpreter_arranged');
            }
            if (! Schema::hasColumn('respite_bookings', 'copayment_status')) {
                $table->string('copayment_status')->nullable()->after('copayment_amount');
            }
            if (! Schema::hasColumn('respite_bookings', 'recurrence_rule')) {
                $table->string('recurrence_rule')->nullable()->after('copayment_status');
            }
            if (! Schema::hasColumn('respite_bookings', 'funding_expiry_acknowledged_at')) {
                $table->timestamp('funding_expiry_acknowledged_at')->nullable()->after('recurrence_rule');
            }
        });

        Schema::table('respite_stays', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_stays', 'discharge_reason')) {
                $table->string('discharge_reason')->nullable()->after('discharge_summary');
            }
            if (! Schema::hasColumn('respite_stays', 'discharge_medication_reconciliation')) {
                $table->json('discharge_medication_reconciliation')->nullable()->after('discharge_reason');
            }
            if (! Schema::hasColumn('respite_stays', 'admission_risk_screen')) {
                $table->json('admission_risk_screen')->nullable()->after('arrival_checklist_complete');
            }
            if (! Schema::hasColumn('respite_stays', 'absence_records')) {
                $table->json('absence_records')->nullable()->after('transport_arrangements');
            }
            if (! Schema::hasColumn('respite_stays', 'cultural_support_notes')) {
                $table->text('cultural_support_notes')->nullable()->after('absence_records');
            }
        });

        Schema::table('respite_daily_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('respite_daily_notes', 'taha_wairua')) {
                $table->string('taha_wairua')->nullable()->after('engagement');
            }
            if (! Schema::hasColumn('respite_daily_notes', 'taha_whanau')) {
                $table->string('taha_whanau')->nullable()->after('taha_wairua');
            }
            if (! Schema::hasColumn('respite_daily_notes', 'whanau_contact')) {
                $table->text('whanau_contact')->nullable()->after('taha_whanau');
            }
            if (! Schema::hasColumn('respite_daily_notes', 'cultural_support_provided')) {
                $table->text('cultural_support_provided')->nullable()->after('whanau_contact');
            }
        });

        Schema::table('restraint_events', function (Blueprint $table) {
            if (! Schema::hasColumn('restraint_events', 'stay_id')) {
                $table->foreignId('stay_id')->nullable()->after('id')->constrained('respite_stays')->nullOnDelete();
            }
        });

        Schema::table('client_incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('client_incidents', 'respite_stay_id')) {
                $table->foreignId('respite_stay_id')->nullable()->after('shift_id')->constrained('respite_stays')->nullOnDelete();
            }
        });

        $this->expandEnums();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('respite_bookings')->whereIn('status', ['no_show', 'on_hold_pending_funding'])->update(['status' => 'cancelled']);
            DB::statement("ALTER TABLE respite_bookings MODIFY status ENUM('pending','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending'");
            DB::table('respite_stays')->where('status', 'on_leave')->update(['status' => 'active']);
            DB::statement("ALTER TABLE respite_stays MODIFY status ENUM('admitted','active','extended','discharged') NOT NULL DEFAULT 'admitted'");
            DB::table('respite_evidence_packs')->whereIn('status', ['draft', 'pending_review', 'complete'])->update(['status' => 'drafting']);
            DB::statement("ALTER TABLE respite_evidence_packs MODIFY status ENUM('drafting','sealed') NOT NULL DEFAULT 'drafting'");
        }

        if (Schema::hasColumn('client_incidents', 'respite_stay_id')) {
            Schema::table('client_incidents', function (Blueprint $table) {
                $table->dropForeign(['respite_stay_id']);
                $table->dropColumn('respite_stay_id');
            });
        }

        if (Schema::hasColumn('restraint_events', 'stay_id')) {
            Schema::table('restraint_events', function (Blueprint $table) {
                $table->dropForeign(['stay_id']);
                $table->dropColumn('stay_id');
            });
        }

        $this->dropColumns('respite_daily_notes', ['taha_wairua', 'taha_whanau', 'whanau_contact', 'cultural_support_provided']);
        $this->dropColumns('respite_stays', ['discharge_reason', 'discharge_medication_reconciliation', 'admission_risk_screen', 'absence_records', 'cultural_support_notes']);

        if (Schema::hasColumn('respite_bookings', 'family_portal_consent_bound_by')) {
            Schema::table('respite_bookings', function (Blueprint $table) {
                $table->dropForeign(['family_portal_consent_bound_by']);
            });
        }
        $this->dropColumns('respite_bookings', [
            'agreement_status',
            'consent_authority',
            'consent_authority_name',
            'consent_authority_contact',
            'consent_authority_evidence',
            'family_portal_consent_bound_at',
            'family_portal_consent_bound_by',
            'cancellation_source',
            'cancellation_notice_hours',
            'readiness_override_reason',
            'capacity_override_reason',
            'cultural_snapshot',
            'interpreter_arranged',
            'copayment_amount',
            'copayment_status',
            'recurrence_rule',
            'funding_expiry_acknowledged_at',
        ]);
        $this->dropColumns('respite_booking_requests', ['waitlist_position', 'priority', 'expected_availability_date', 'is_emergency', 'fast_tracked', 'intake_snapshot']);
        $this->dropColumns('respite_referrals', [
            'nhi_number',
            'nhi_hash',
            'third_party_source_type',
            'third_party_source_name',
            'third_party_collection_consent',
            'is_maori',
            'ethnicity',
            'iwi',
            'hapu',
            'marae',
            'interpreter_required',
            'interpreter_language',
            'interpreter_arranged',
            'cultural_considerations',
            'cultural_dietary_needs',
            'primary_carer_name',
            'primary_carer_relationship',
            'primary_carer_contact',
            'carer_strain_level',
            'carer_breakdown_flag',
            'booker_type',
        ]);
        $this->dropColumns('clients', ['nhi_hash', 'iwi', 'hapu', 'marae', 'cultural_dietary_needs']);

    }

    private function expandEnums(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE respite_bookings MODIFY status ENUM('pending','confirmed','in_progress','completed','cancelled','no_show','on_hold_pending_funding') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE respite_stays MODIFY status ENUM('admitted','active','extended','on_leave','discharged') NOT NULL DEFAULT 'admitted'");
        DB::statement("ALTER TABLE respite_evidence_packs MODIFY status ENUM('drafting','draft','pending_review','complete','sealed') NOT NULL DEFAULT 'drafting'");
    }

    /**
     * @param  array<int,string>  $columns
     */
    private function dropColumns(string $table, array $columns): void
    {
        $existing = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));

        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }
};
