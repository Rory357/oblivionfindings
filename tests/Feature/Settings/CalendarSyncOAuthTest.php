<?php

use App\Models\CalendarSyncConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function calSyncOauthAdmin(): User
{
    $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'admin')->first()->id]);

    return $user;
}

function fakeCalendarSocialite(string $provider, array $attributes): void
{
    $oauthUser = (new SocialiteUser())->map($attributes);
    $oauthUser->setRaw($attributes);
    $oauthUser->setToken($provider.'-access');
    $oauthUser->setRefreshToken($provider.'-refresh');
    $oauthUser->setExpiresIn(3600);

    $driver = Mockery::mock();
    $driver->shouldReceive('redirectUrl')->andReturnSelf();
    $driver->shouldReceive('user')->andReturn($oauthUser);

    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

test('the admin OAuth callback stores a connected connection with encrypted tokens', function () {
    fakeCalendarSocialite('google', [
        'id' => 'g-1',
        'name' => 'Calendar Admin',
        'email' => 'admin@org.test',
    ]);

    $this->actingAs(calSyncOauthAdmin())
        ->get(route('settings.calendar-sync.callback', ['provider' => 'google']))
        ->assertRedirect(route('settings.calendar-sync'))
        ->assertSessionHas('success');

    $conn = CalendarSyncConnection::query()->where('provider', 'google')->firstOrFail();
    expect($conn->status)->toBe(CalendarSyncConnection::STATUS_CONNECTED)
        ->and($conn->account_email)->toBe('admin@org.test')
        ->and($conn->getAccessToken())->toBe('google-access')
        ->and($conn->getRefreshToken())->toBe('google-refresh');

    // Tokens are encrypted at rest (the raw column is not the plaintext).
    $raw = \Illuminate\Support\Facades\DB::table('calendar_sync_connections')->value('access_token');
    expect($raw)->not->toBe('google-access');
});

test('the OAuth callback is gated by the manage-integrations permission', function () {
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->first()->id]);

    $this->actingAs($worker)
        ->get(route('settings.calendar-sync.callback', ['provider' => 'google']))
        ->assertForbidden();

    expect(CalendarSyncConnection::count())->toBe(0);
});

test('disconnect removes the connection', function () {
    CalendarSyncConnection::create([
        'tenant_id' => 0,
        'provider' => 'microsoft',
        'status' => CalendarSyncConnection::STATUS_CONNECTED,
        'access_token' => 'tok',
        'token_expires_at' => now()->addHour(),
    ]);

    $this->actingAs(calSyncOauthAdmin())
        ->delete(route('settings.calendar-sync.disconnect', ['provider' => 'microsoft']))
        ->assertRedirect(route('settings.calendar-sync'));

    expect(CalendarSyncConnection::where('provider', 'microsoft')->count())->toBe(0);
});

test('connecting an unconfigured provider redirects back with an error', function () {
    config()->set('services.google.client_id', null);
    config()->set('services.google.client_secret', null);

    $this->actingAs(calSyncOauthAdmin())
        ->get(route('settings.calendar-sync.connect', ['provider' => 'google']))
        ->assertRedirect(route('settings.calendar-sync'))
        ->assertSessionHasErrors('google');
});
