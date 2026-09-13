<?php

require __DIR__.'/../../../../vendor/autoload.php';
$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

if (! app()->environment('local')
    || strcasecmp(str_replace('\\', '/', realpath(base_path())), 'C:/Users/steph/Herd/oblivionfindings') !== 0
    || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'oblivion_findings_codex_test'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('mail.default') !== 'array' || config('queue.default') !== 'sync'
    || app()->configurationIsCached()) {
    throw new RuntimeException('Expected isolated local browser runtime is not active.');
}

$role = Role::query()->where('name', 'it-support-w01-20260909-tech')->firstOrFail();
$owner = User::query()->whereKey(230)->where('email', 'it-support-w01-20260909-tech@demo.test')->firstOrFail();
$sites = Site::query()->whereIn('id', [9403, 9404])->whereIn('name', ['IT Support W01 20260909 Site A', 'IT Support W01 20260909 Site B'])->get();
if ($sites->count() !== 2) {
    throw new RuntimeException('Expected synthetic sites are missing.');
}
$email = 'it-support-w03-20260909-cover@demo.test';
$name = 'IT Support W03 20260909 Synthetic absence cover';
$existing = User::query()->where('email', $email)->first();
if (($argv[1] ?? null) !== '--create-fixture') {
    echo json_encode(['preflight' => true, 'database_mutations_performed' => false, 'proposed_new_users' => $existing ? 0 : 1, 'existing_role_id' => $role->id, 'approved_synthetic_site_ids' => [9403, 9404], 'existing_synthetic_owner_id' => $owner->id], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

Notification::fake();
Mail::fake();
Bus::fake();
Http::preventStrayRequests();
$lock = 'it-support-w03-cover-fixture';
if ((int) DB::scalar('SELECT GET_LOCK(?, 0)', [$lock]) !== 1) {
    throw new RuntimeException('Fixture operation already running.');
}
try {
    $result = DB::transaction(function () use ($email, $name, $role, $owner): array {
        $user = User::query()->where('email', $email)->first();
        $created = false;
        if (! $user) {
            $source = file_get_contents(base_path('tests/e2e/helpers.ts'));
            if (preg_match("/password = '([^']+)'/", $source, $matches) !== 1) {
                throw new RuntimeException('Existing demo credential convention is unavailable.');
            }
            $user = User::factory()->withoutTwoFactor()->create([
                'name' => $name, 'email' => $email, 'password' => $matches[1],
                'role' => 'support_worker', 'approved_at' => now(), 'email_verified_at' => now(),
                'remember_token' => null, 'landing_route_preference' => '/it',
            ]);
            unset($source, $matches);
            $user->roles()->attach($role->id);
            HrEmployeeProfile::query()->create([
                'user_id' => $user->id, 'employee_number' => 'it-support-w03-20260909-cover',
                'work_email' => $email, 'position_title' => 'Synthetic IT absence cover fixture',
                'position_role' => 'support_worker', 'employment_type' => 'casual',
                'start_date' => today()->subDay()->toDateString(), 'end_date' => null, 'is_active' => true,
                'primary_site_id' => 9403, 'secondary_site_ids' => [9404],
                'created_by' => $owner->id, 'updated_by' => $owner->id,
                'notes' => 'Disposable local browser fixture. No real employment, availability or responsibility is represented.',
            ]);
            $created = true;
        }
        $profile = HrEmployeeProfile::query()->where('user_id', $user->id)->first();
        if ($user->name !== $name || ! $user->roles()->whereKey($role->id)->exists()
            || ! $profile || (int) $profile->primary_site_id !== 9403
            || $profile->secondary_site_ids !== [9404]) {
            throw new RuntimeException('Existing fixture does not match; no repair or overwrite attempted.');
        }

        return ['created' => $created, 'user_id' => $user->id, 'name' => $user->name, 'role_id' => $role->id, 'site_ids' => [9403, 9404], 'existing_users_changed' => false, 'teams_or_queues_created' => false, 'real_communications' => false];
    });
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
}
