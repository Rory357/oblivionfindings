<?php

namespace Database\Seeders;

use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
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
        $policies = [
            [RespiteReferral::class, 'Respite referrals and declined intake records', 10, ['health_record' => true]],
            [RespiteBookingRequest::class, 'Respite booking requests', 10, ['health_record' => true]],
            [RespiteBooking::class, 'Respite bookings and funding records', 10, ['health_record' => true]],
            [RespiteStay::class, 'Respite stay records', 10, ['health_record' => true]],
        ];

        foreach ($policies as [$model, $name, $years, $conditions]) {
            DB::table('data_retention_policies')->updateOrInsert(
                ['model_type' => $model, 'policy_name' => $name],
                [
                    'description' => 'NZ respite health record retention policy seeded from the respite implementation plan.',
                    'retention_period_years' => $years,
                    'retention_conditions' => json_encode($conditions),
                    'active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }
}
