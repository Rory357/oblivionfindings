<?php

namespace Database\Seeders;

use App\Models\Site;
use App\Models\SiteEmergencyPlan;
use Illuminate\Database\Seeder;

/**
 * Demo emergency / evacuation plans so the calendar's `emergency` obligation
 * source has something to surface (one plan per house/facility, with review
 * dates spread around "now" — including one overdue).
 *
 * Standalone post-deploy run:
 *   php artisan db:seed --class=SiteEmergencyPlanSeeder --force
 */
class SiteEmergencyPlanSeeder extends Seeder
{
    public function run(): void
    {
        $sites = Site::query()
            ->whereIn('type', ['house', 'facility'])
            ->get(['id', 'tenant_id', 'name']);

        $templates = [
            ['plan_type' => 'evacuation', 'title' => 'Evacuation & assembly plan', 'months' => 12, 'offset' => 21],
            ['plan_type' => 'fire', 'title' => 'Fire safety plan', 'months' => 6, 'offset' => 9],
            ['plan_type' => 'civil_defence', 'title' => 'Civil defence / earthquake plan', 'months' => 12, 'offset' => -6],
        ];

        foreach ($sites as $i => $site) {
            $t = $templates[$i % count($templates)];

            SiteEmergencyPlan::query()->updateOrCreate(
                ['site_id' => $site->id, 'plan_type' => $t['plan_type']],
                [
                    'tenant_id' => $site->tenant_id,
                    'title' => $t['title'],
                    'last_reviewed_at' => now()->subMonths($t['months'])->addDays($t['offset']),
                    'review_interval_months' => $t['months'],
                    'next_review_at' => now()->addDays($t['offset']),
                    'status' => 'active',
                ]
            );
        }
    }
}
