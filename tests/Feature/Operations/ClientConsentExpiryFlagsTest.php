<?php

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthoritativeConsentFixture;

/*
 * S14 (design refresh, 20 Oct 2026): the consents page filters its Expired and
 * Expiring soon tabs, and hides Withdraw, on is_expired / is_expiring_soon,
 * but the controller never sent them, so an expired consent showed as Given.
 */

function makeConsentExpiryViewer(Site $site): User
{
    $user = User::factory()->create(['role' => 'coordinator', 'approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'consent_expiry_'.$user->id,
        'label' => 'Consent expiry test',
        'level' => 50,
        'type' => 'custom',
    ]);
    foreach (['clients.viewAny', 'consents.viewAny'] as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }
    $role->permissions()->sync(
        Permission::query()->whereIn('key', ['clients.viewAny', 'consents.viewAny'])->pluck('id')->all(),
    );
    $user->roles()->sync([$role->id]);
    ensureCanonicalHrStaffProfile($user, $site);

    return $user;
}

it('sends expiry flags on the consents page so expired and expiring consents are told apart', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $viewer = makeConsentExpiryViewer($site);
    $client = Client::factory()->create(['site_id' => $site->id]);

    $expired = AuthoritativeConsentFixture::manualSelf($client, ConsentType::factory()->create(['active' => true]), $viewer, [
        'given_at' => now()->subYear(),
        'expires_at' => now()->subDay(),
    ]);
    $expiring = AuthoritativeConsentFixture::manualSelf($client, ConsentType::factory()->create(['active' => true]), $viewer, [
        'given_at' => now()->subMonth(),
        'expires_at' => now()->addDays(10),
    ]);
    $current = AuthoritativeConsentFixture::manualSelf($client, ConsentType::factory()->create(['active' => true]), $viewer, [
        'given_at' => now()->subMinute(),
        'expires_at' => now()->addMonths(6),
    ]);

    $this->actingAs($viewer)
        ->get("/operations/clients/{$client->id}/consents")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('operations/clients/consents/Index')
            ->has('consents', 3)
            ->where('consents', function (Collection $consents) use ($expired, $expiring, $current): bool {
                $byId = $consents->keyBy('id');

                expect($byId[$expired->id])
                    ->toHaveKey('is_expired', true)
                    ->toHaveKey('is_expiring_soon', false)
                    ->toHaveKey('is_consumable', false)
                    ->and($byId[$expiring->id])
                    ->toHaveKey('is_expired', false)
                    ->toHaveKey('is_expiring_soon', true)
                    ->toHaveKey('is_consumable', true)
                    ->and($byId[$current->id])
                    ->toHaveKey('is_expired', false)
                    ->toHaveKey('is_expiring_soon', false)
                    ->toHaveKey('is_consumable', true);

                return true;
            })
            ->where('stats.expired', 1)
            ->where('stats.expiring_soon', 1)
            ->where('stats.active', 2));
});

it('only counts a consent as expiring soon inside the window', function () {
    $consent = new ClientConsent(['status' => 'given']);

    $consent->expires_at = now()->addDays(10);
    expect($consent->isExpiringSoon())->toBeTrue();

    // Carbon 3's signed diffInDays() made any future expiry count as soon.
    $consent->expires_at = now()->addMonths(6);
    expect($consent->isExpiringSoon())->toBeFalse()
        ->and($consent->isExpiringSoon(365))->toBeTrue();

    $consent->expires_at = now()->subDay();
    expect($consent->isExpiringSoon())->toBeFalse()
        ->and($consent->isExpired())->toBeTrue();

    $consent->status = 'withdrawn';
    $consent->expires_at = now()->addDays(10);
    expect($consent->isExpiringSoon())->toBeFalse();
});
