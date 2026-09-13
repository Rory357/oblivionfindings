<?php

// Read-only operational ownership inventory. No credentials or leave details.
require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItQueue;
use App\Models\ItTeam;
use Illuminate\Contracts\Console\Kernel;

$connection = config('database.connections.mysql');
if (! app()->environment('local')
    || strcasecmp(str_replace('\\', '/', realpath(base_path())), 'C:/Users/steph/Herd/oblivionfindings') !== 0
    || config('database.default') !== 'mysql'
    || $connection['host'] !== '127.0.0.1'
    || $connection['database'] !== 'oblivion_findings_codex_test'
    || ! empty($connection['url'])) {
    throw new RuntimeException('Local read-only inventory guard failed.');
}

echo json_encode([
    'read_only' => true,
    'agents' => ItStaffDirectory::agents()->reject(fn ($user) => str_starts_with($user->name, 'IT Support W01'))
        ->map(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'roles' => $user->roles()->pluck('name')->all(),
            'approved_sites' => app(ItWorkAccessService::class)->approvedSiteIds($user),
            'organisation_wide' => $user->canDo('it.organisationWide'),
            'current_profile' => HrEmployeeProfile::query()->where('user_id', $user->id)->first(['is_active', 'start_date', 'end_date']),
        ])->values(),
    'teams' => ItTeam::query()->get(['id', 'name', 'manager_user_id', 'is_active']),
    'queues' => ItQueue::query()->get(['id', 'name', 'team_id', 'filter_rules', 'is_active']),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
