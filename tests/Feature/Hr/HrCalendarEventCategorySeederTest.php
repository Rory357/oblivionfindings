<?php

use Database\Seeders\HrCalendarEventCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('restores the canonical system calendar categories idempotently', function () {
    DB::table('hr_calendar_event_categories')->delete();

    $this->seed(HrCalendarEventCategorySeeder::class);
    $this->seed(HrCalendarEventCategorySeeder::class);

    $categories = DB::table('hr_calendar_event_categories')
        ->whereNull('tenant_id')
        ->orderBy('sort')
        ->get(['key', 'label', 'icon', 'color_token', 'is_system', 'sort'])
        ->map(fn (object $category): array => (array) $category)
        ->all();

    expect($categories)->toBe([
        ['key' => 'company', 'label' => 'Company', 'icon' => 'Building2', 'color_token' => 'category-hr', 'is_system' => 1, 'sort' => 1],
        ['key' => 'team', 'label' => 'Team', 'icon' => 'Users', 'color_token' => 'category-ops', 'is_system' => 1, 'sort' => 2],
        ['key' => 'training', 'label' => 'Training', 'icon' => 'GraduationCap', 'color_token' => 'status-info', 'is_system' => 1, 'sort' => 3],
        ['key' => 'social', 'label' => 'Social', 'icon' => 'PartyPopper', 'color_token' => 'category-finance', 'is_system' => 1, 'sort' => 4],
        ['key' => 'holiday', 'label' => 'Holiday / closure', 'icon' => 'CalendarRange', 'color_token' => 'status-warning', 'is_system' => 1, 'sort' => 5],
    ]);
});
