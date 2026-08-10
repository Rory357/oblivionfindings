<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HrCalendarEventCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['key' => 'company', 'label' => 'Company', 'icon' => 'Building2', 'color_token' => 'category-hr', 'sort' => 1],
            ['key' => 'team', 'label' => 'Team', 'icon' => 'Users', 'color_token' => 'category-ops', 'sort' => 2],
            ['key' => 'training', 'label' => 'Training', 'icon' => 'GraduationCap', 'color_token' => 'status-info', 'sort' => 3],
            ['key' => 'social', 'label' => 'Social', 'icon' => 'PartyPopper', 'color_token' => 'category-finance', 'sort' => 4],
            ['key' => 'holiday', 'label' => 'Holiday / closure', 'icon' => 'CalendarRange', 'color_token' => 'status-warning', 'sort' => 5],
        ];

        foreach ($categories as $category) {
            DB::table('hr_calendar_event_categories')->updateOrInsert(
                ['tenant_id' => null, 'key' => $category['key']],
                [
                    ...$category,
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
