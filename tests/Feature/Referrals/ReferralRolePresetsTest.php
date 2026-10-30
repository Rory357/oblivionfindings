<?php

use App\Domain\Referrals\ReferralPermissions;
use App\Models\Role;
use Database\Seeders\RbacSeeder;

function referralRoleKeys(string $name): array
{
    return Role::query()->where('name', $name)->firstOrFail()->permissions()->where('key', 'like', 'referrals.%')
        ->pluck('key')->sort()->values()->all();
}

function assertReferralPresets(): void
{
    $sorted = fn (array $keys): array => collect($keys)->unique()->sort()->values()->all();
    foreach (['ceo', 'coo', 'provider_manager', 'compliance_lead', 'coordinator', 'finance', 'auditor'] as $role) {
        expect(referralRoleKeys($role))->toBe($sorted(ReferralPermissions::rolePresets()[$role]));
    }
    expect(referralRoleKeys('support_worker'))->toBe([]);
    $admin = referralRoleKeys('admin');
    // Admin holds settings governance (Stephan, 21 Oct 2026) but none of the other independent keys.
    foreach (array_diff(ReferralPermissions::INDEPENDENT, ['referrals.settings.governance']) as $key) {
        expect($admin)->not->toContain($key);
    }
    expect($admin)->toContain('referrals.settings.view', 'referrals.settings.manage', 'referrals.settings.publish', 'referrals.convert');
    expect(referralRoleKeys('provider_manager'))->toContain('referrals.commence', 'referrals.proposals.approve')
        ->not->toContain('referrals.funding.verify')->not->toContain('referrals.settings.manage');
    expect(referralRoleKeys('finance'))->toBe(['referrals.funding.verify', 'referrals.funding.view', 'referrals.proposals.approve', 'referrals.reports.view', 'referrals.viewAny']);
    expect(referralRoleKeys('coordinator'))->toContain('referrals.convert', 'referrals.funding.manage')
        ->not->toContain('referrals.commence')->not->toContain('referrals.funding.verify')->not->toContain('referrals.settings.view');
    // Governance is held only by the named roles, each able to view and publish settings to act on it.
    $holders = Role::all()->filter(fn (Role $role) => in_array('referrals.settings.governance', referralRoleKeys($role->name), true))->pluck('name')->sort()->values()->all();
    expect($holders)->toBe(['admin', 'ceo', 'compliance_lead', 'provider_manager']);
    foreach ($holders as $holder) {
        expect(referralRoleKeys($holder))->toContain('referrals.settings.view', 'referrals.settings.publish');
    }
}

it('grants the suggested referral presets on deploy, idempotently and without independent authority for admin', function () {
    foreach (['admin', 'ceo', 'coo', 'provider_manager', 'compliance_lead', 'coordinator', 'finance', 'auditor', 'support_worker'] as $name) {
        Role::query()->firstOrCreate(['name' => $name], ['label' => ucfirst($name)]);
    }
    $migration = require base_path('database/migrations/2026_10_20_090000_grant_referral_role_presets.php');
    $migration->up();
    $migration->up();
    assertReferralPresets();
});

it('seeds the same referral presets on a fresh RBAC seed', function () {
    (new RbacSeeder)->run();
    assertReferralPresets();
});
