<?php

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralSettingsVersion;
use App\Domain\Referrals\Services\ReferralActivationGuard;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;

function referralTestUser(Site $site, array $actions): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker']);
    ensureCanonicalHrStaffProfile($user, $site);
    foreach ($actions as $action) {
        $permission = Permission::query()->where('key', 'referrals.'.$action)->firstOrFail();
        $user->permissionOverrides()->attach($permission, ['allowed' => true]);
    }

    return $user;
}

function referralTestRecord(?Site $site, array $overrides = []): CareReferral
{
    $version = ReferralSettingsVersion::query()->firstOrCreate(['version' => 1], [
        'state' => 'published', 'configuration' => ReferralSettings::defaults(), 'published_at' => now(), 'effective_at' => now(),
    ]);

    return CareReferral::query()->create([
        'settings_version_id' => $version->id, 'site_id' => $site?->id,
        'source' => 'website', 'entry_kind' => 'self',
        'identity' => ['first_name' => 'Private identity'],
        'intake' => ['reason' => 'Private reason'],
        'authority' => ['contact_permission' => true],
        'assessment' => ['notes' => 'Restricted assessment'],
        'next_action' => 'Confirm safe contact', 'next_due_at' => now()->addDay(),
        'stage_changed_at' => now(), ...$overrides,
    ])->fresh();
}

/** The organisation-wide switch: every person needs a recorded commencement before becoming active. */
function requireCommencementReviewForEveryone(bool $required = true): void
{
    AppSetting::query()->updateOrCreate(['key' => ReferralActivationGuard::REQUIRE_FOR_EVERYONE_KEY], ['value' => $required]);
}
