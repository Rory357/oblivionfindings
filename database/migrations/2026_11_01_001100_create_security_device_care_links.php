<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S&D Phase F (links): a sensor or tracker linked to a person's care (pop-up 65), only with their
 * active consent to monitoring; and the link from a scald-risk alert to the H&S hazard a person
 * raised from it. Deploys skip seeders, so the consent type is added here too.
 */
return new class extends Migration
{
    public const string CONSENT_TYPE = 'Monitoring Sensors (Bed, Door or Room)';

    public function up(): void
    {
        if (Schema::hasTable('consent_types') && ! DB::table('consent_types')->where('name', self::CONSENT_TYPE)->exists()) {
            DB::table('consent_types')->insert([
                'name' => self::CONSENT_TYPE,
                'category' => 'safety',
                'description' => 'Consent to link a sensor in the client’s room or at their door (for example a bed sensor or a door contact) to their care, so staff on shift are told when their care plan says so.',
                'purpose' => 'To support the client’s safety plan (for example a falls or wandering plan) by telling staff on shift when the plan’s rule is met, without cameras or recordings.',
                'legal_basis' => 'Informed consent under HDC Code of Rights Right 7, balanced against the duty of care owed by the provider. Sensor data is handled under the NZ Privacy Act 2020 and the Health Information Privacy Code 2020. Where capacity is in doubt, a PPPR Act 1988 capacity assessment is undertaken and consent is given by an appointed welfare guardian or activated enduring power of attorney (personal care and welfare).',
                'is_mandatory' => false,
                'requires_capacity_assessment' => true,
                'allows_withdrawal' => true,
                'withdrawal_notice_days' => 0,
                'validity_period_days' => 365,
                'renewal_required' => true,
                'renewal_reminder_days' => 30,
                'withdrawal_implications' => 'The sensor is unlinked from the client’s care straight away and staff are no longer told by it. Their care plan is reviewed for other ways to keep them safe.',
                'version' => 1,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('security_device_person_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->string('purpose', 24); // falls | wandering | room | other
            $table->foreignId('care_plan_id')->nullable()->constrained('care_plans')->nullOnDelete();
            $table->json('rule')->nullable(); // {"minutes": 10, "from": "22:00", "to": "06:00"}
            $table->json('state')->nullable(); // {"out_since": "...", "alerted_for": "..."}
            $table->string('note', 500)->nullable();
            $table->foreignId('consent_id')->nullable()->constrained('client_consents')->nullOnDelete();
            $table->foreignId('linked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at');
            $table->timestamp('unlinked_at')->nullable();
            $table->foreignId('unlinked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unlink_reason', 32)->nullable(); // by_hand | consent_ended
            $table->timestamps();

            $table->index(['device_id', 'unlinked_at'], 'sd_person_links_device_idx');
            $table->index(['client_id', 'unlinked_at'], 'sd_person_links_client_idx');
        });

        Schema::table('security_device_alerts', function (Blueprint $table): void {
            $table->foreignId('hazard_id')->nullable()->after('close_reason')->constrained('site_hazards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('security_device_alerts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hazard_id');
        });
        Schema::dropIfExists('security_device_person_links');
        DB::table('consent_types')->where('name', self::CONSENT_TYPE)->delete();
    }
};
