<?php

// Fictional browser fixtures only; refuses a persistent or non-preview schema.
declare(strict_types=1);

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConfiguration;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Models\ReferralWorkItem;
use App\Domain\Referrals\ReferralPermissions;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

$database = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !preg_match('/\Aoblivion_referrals_preview_[1-9][0-9]*\z/', $database)) throw new RuntimeException('An explicitly named disposable preview is required.');
$root = dirname(__DIR__, 3);
chdir($root);
require $root.'/vendor/autoload.php';
foreach (simplexml_load_file($root.'/phpunit.xml')->php->env as $entry) {
    $key = (string) $entry['name']; $value = (string) $entry['value'];
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
foreach (['APP_ENV' => 'local', 'DB_DATABASE' => $database, 'APP_CONFIG_CACHE' => __DIR__.'/unused-config.php', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'database', 'BROADCAST_CONNECTION' => 'null'] as $key => $value) {
    putenv($key.'='.$value); $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->useStoragePath(__DIR__.'/runtime');
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== $database) throw new RuntimeException('Preview connection mismatch.');
$version = ReferralSettingsVersion::where('state', 'published')->orderByDesc('version')->firstOrFail();
if (!str_contains($version->configuration['public']['notice'], 'Demonstration only.')) throw new RuntimeException('The isolated fictional provider fixture is required.');
Mail::fake(); Notification::fake();
DB::transaction(function () use ($version): void {
    ReferralConfiguration::firstOrCreate(['id' => 1], ['published_version_id' => $version->id, 'lock_version' => 1]);
    $site = Site::firstOrCreate(['name' => 'Referrals browser preview'], ['type' => 'residential', 'is_active' => true, 'archived' => false]);
    foreach (['author', 'reviewer'] as $name) {
        $user = User::where('email', $name.'@referrals-preview.test')->first();
        if (!$user) $user = User::factory()->withoutTwoFactor()->create(['name' => 'Preview '.ucfirst($name), 'email' => $name.'@referrals-preview.test', 'password' => 'Referrals-preview-only-2026!', 'role' => 'support_worker', 'approved_at' => now()]);
        if (!HrEmployeeProfile::where('user_id', $user->id)->exists()) HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'employee_number' => 'REF-PREVIEW-'.$user->id, 'work_email' => $user->email, 'position_role' => 'support_worker', 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true, 'created_by' => $user->id, 'updated_by' => $user->id]);
        foreach (array_keys(ReferralPermissions::DEFINITIONS) as $suffix) $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'referrals.'.$suffix)->sole()->id => ['allowed' => true]]);
    }
    $owner = User::where('email', 'author@referrals-preview.test')->sole();
    $referral = CareReferral::where('team', 'Browser verification fixture')->first();
    if (!$referral) {
        $referral = CareReferral::create(['settings_version_id' => $version->id, 'site_id' => $site->id, 'owner_id' => $owner->id, 'team' => 'Browser verification fixture', 'source' => 'phone', 'entry_kind' => 'self', 'identity' => ['first_name' => 'Fictional', 'last_name' => 'Calendar'], 'intake' => ['submitted_contact' => ['name' => 'Fictional Calendar', 'phone' => '0200000000', 'channel' => 'phone', 'leave_message' => false]], 'authority' => ['contact_permission' => true], 'assessment' => [], 'next_action' => 'Confirm the preferred contact time', 'next_due_at' => now()->addDay(), 'stage_changed_at' => now()]);
        foreach (['intake', 'assessment', 'funding'] as $index => $audience) ReferralWorkItem::create(['referral_id' => $referral->id, 'owner_id' => $owner->id, 'kind' => 'internal_review', 'audience' => $audience, 'status' => 'open', 'details' => ['title' => 'Preview '.$audience.' follow-up', 'description' => 'Fictional browser verification only'], 'due_at' => now()->addDays($index + 1)->setTime(10, 0), 'ends_at' => $audience === 'assessment' ? now()->addDays($index + 1)->setTime(11, 0) : null]);
    }
    echo json_encode(['staff' => ['author@referrals-preview.test', 'reviewer@referrals-preview.test'], 'referral' => $referral->reference]).PHP_EOL;
});
