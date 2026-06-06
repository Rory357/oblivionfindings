<?php

namespace Database\Seeders;

use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\RespiteDailyNote;
use App\Models\RespiteEvidencePack;
use App\Models\RespiteMedicationReconciliation;
use App\Models\RespiteReferral;
use App\Models\RespiteStay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RespiteRetentionPolicySeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('data_retention_policies')) {
            return;
        }

        $now = now();
        $healthRecordDescription = 'NZ respite health record retention policy seeded from the respite implementation plan.';

        DB::table('data_retention_policies')
            ->whereIn('policy_name', [
                'Respite referrals and declined intake records',
                'Respite booking requests',
                'Respite bookings and funding records',
                'Respite stay records',
            ])
            ->delete();

        $policies = [
            [
                'model_type' => RespiteReferral::class,
                'policy_name' => 'Declined respite referral disposal',
                'description' => 'Shorter NZ HIPC Rule 9 disposal window for declined respite referrals that never converted to a booking request.',
                'retention_period_years' => 2,
                'hard_delete_after_years' => 3,
                'retention_conditions' => ['status' => 'declined', 'linked_booking_request_id' => null],
            ],
            [
                'model_type' => RespiteReferral::class,
                'policy_name' => 'Respite referral health records',
                'description' => $healthRecordDescription,
                'retention_period_years' => 10,
                'hard_delete_after_years' => 12,
                'retention_conditions' => null,
            ],
            [
                'model_type' => RespiteBookingRequest::class,
                'policy_name' => 'Respite booking request health records',
                'description' => $healthRecordDescription,
                'retention_period_years' => 10,
                'hard_delete_after_years' => 12,
                'retention_conditions' => null,
            ],
            [
                'model_type' => RespiteBooking::class,
                'policy_name' => 'Respite booking and funding records',
                'description' => $healthRecordDescription,
                'retention_period_years' => 10,
                'hard_delete_after_years' => 12,
                'retention_conditions' => null,
            ],
            [
                'model_type' => RespiteStay::class,
                'policy_name' => 'Respite stay health records',
                'description' => $healthRecordDescription,
                'retention_period_years' => 10,
                'hard_delete_after_years' => 12,
                'retention_conditions' => null,
            ],
            [
                'model_type' => RespiteDailyNote::class,
                'policy_name' => 'Respite daily note health records',
                'description' => $healthRecordDescription,
                'retention_period_years' => 10,
                'hard_delete_after_years' => 12,
                'retention_conditions' => null,
            ],
            [
                'model_type' => RespiteEvidencePack::class,
                'policy_name' => 'Respite evidence pack health records',
                'description' => $healthRecordDescription,
                'retention_period_years' => 10,
                'hard_delete_after_years' => 12,
                'retention_conditions' => null,
            ],
            [
                'model_type' => RespiteMedicationReconciliation::class,
                'policy_name' => 'Respite medication reconciliation health records',
                'description' => $healthRecordDescription,
                'retention_period_years' => 10,
                'hard_delete_after_years' => 12,
                'retention_conditions' => null,
            ],
        ];

        foreach ($policies as $policy) {
            DB::table('data_retention_policies')->updateOrInsert(
                ['model_type' => $policy['model_type'], 'policy_name' => $policy['policy_name']],
                [
                    'description' => $policy['description'],
                    'retention_period_years' => $policy['retention_period_years'],
                    'archive_after_years' => null,
                    'hard_delete_after_years' => $policy['hard_delete_after_years'],
                    'retention_conditions' => $policy['retention_conditions'] === null
                        ? null
                        : json_encode($policy['retention_conditions']),
                    'legal_basis' => 'Health (Retention of Health Information) Regulations 1996; HIPC 2020 Rule 9.',
                    'business_justification' => 'Retain respite health records for audit and continuity of care while disposing of declined never-converted referrals sooner.',
                    'applies_to_soft_deleted' => true,
                    'legal_hold_exemption' => true,
                    'active_case_exemption' => false,
                    'active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
