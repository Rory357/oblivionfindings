<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\RespiteReferral;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->attach(Role::where('name', 'admin')->first());
});

test('intake creates a lightweight client and a referral from new_client fields', function () {
    $this->actingAs($this->admin)
        ->post('/respite/referrals', [
            'new_client' => [
                'first_name' => 'Aroha',
                'last_name' => 'Ngata',
                'nhi_number' => 'ABC1234',
            ],
            'referrer_name' => 'Te Whatu Ora — Waitematā',
            'referrer_type' => 'Te Whatu Ora',
            'referral_reason' => 'Carer hospitalised — emergency cover needed within 24h',
            'urgency' => 'crisis',
            'risk_level' => 'high',
            'funding_source' => 'whaikaha',
            'funding_reference' => 'WK-44213',
        ])
        ->assertRedirect();

    $client = Client::where('first_name', 'Aroha')->where('last_name', 'Ngata')->first();
    expect($client)->not->toBeNull();
    expect($client->funding_type)->toBe('Whaikaha'); // label derived from the typed source
    expect($client->status)->toBe('active');

    $referral = RespiteReferral::where('client_id', $client->id)->first();
    expect($referral)->not->toBeNull();
    expect($referral->status)->toBe('received');
    expect($referral->urgency)->toBe('crisis');
    expect($referral->risk_level)->toBe('high');
    expect($referral->funding_source)->toBe('whaikaha');
    expect($referral->funding_reference)->toBe('WK-44213');
});

test('intake applies the default service context to a lightweight client', function () {
    $context = ServiceContext::factory()->create(['is_active' => true]);
    AppSetting::create([
        'key' => 'service_context.default_id',
        'value' => $context->id,
    ]);

    $this->actingAs($this->admin)
        ->post('/respite/referrals', [
            'new_client' => [
                'first_name' => 'Hemi',
                'last_name' => 'Wiremu',
            ],
            'referrer_name' => 'NASC Coordinator',
            'referral_reason' => 'Scheduled respite block',
            'urgency' => 'planned',
        ])
        ->assertRedirect();

    $client = Client::where('first_name', 'Hemi')->firstOrFail();

    expect($client->service_context_id)->toBe($context->id);
});

test('intake rejects a funding source outside the NZ list', function () {
    $this->actingAs($this->admin)
        ->post('/respite/referrals', [
            'new_client' => ['first_name' => 'Test'],
            'referrer_name' => 'GP',
            'referral_reason' => 'Respite',
            'urgency' => 'planned',
            'funding_source' => 'legacy_foreign_funder',
        ])
        ->assertSessionHasErrors('funding_source');
});

test('intake links an existing client without creating a duplicate', function () {
    $site = Site::factory()->create();
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->admin->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth(),
        'end_date' => null,
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $before = Client::count();

    $this->actingAs($this->admin)
        ->post('/respite/referrals', [
            'client_id' => $client->id,
            'referrer_name' => 'GP — Dr Patel, Sunnynook',
            'referral_reason' => 'Quarterly respite block, mobility support',
            'urgency' => 'planned',
        ])
        ->assertRedirect();

    expect(Client::count())->toBe($before);
    expect(RespiteReferral::where('client_id', $client->id)->exists())->toBeTrue();
});

test('intake requires a client identity', function () {
    $this->actingAs($this->admin)
        ->post('/respite/referrals', [
            'referrer_name' => 'NASC Coordinator',
            'referral_reason' => 'Needs scheduled respite',
            'urgency' => 'planned',
        ])
        ->assertSessionHasErrors('new_client.first_name');

    expect(RespiteReferral::count())->toBe(0);
});
