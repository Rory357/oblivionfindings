<?php

use App\Models\CarePlan;
use App\Models\CarePlanSignOff;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

function grantCpPerms(User $user, array $keys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'cp_test_'.$user->id],
        ['label' => 'CP Test', 'level' => 50, 'type' => 'custom'],
    );
    foreach ($keys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }
    $role->permissions()->sync(
        Permission::query()->whereIn('key', $keys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function makeCpClient(User $user): Client
{
    return Client::factory()->create(['organization_id' => $user->organization_id]);
}

function makeCpPlan(User $user, Client $client, array $attrs = []): CarePlan
{
    return CarePlan::query()->create(array_merge([
        'organization_id' => $user->organization_id,
        'client_id' => $client->id,
        'title' => 'Active plan',
        'status' => 'active',
        'plan_type' => 'support_plan',
        'version' => 1,
    ], $attrs));
}

it('persists structured content and lands back on the profile tab', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.create']);
    $client = makeCpClient($user);

    $this->actingAs($user)->post('/operations/care-plans', [
        'client_id' => $client->id,
        'title' => 'Tane Support Plan',
        'plan_type' => 'support_plan',
        'status' => 'draft',
        'content' => [
            'about_me' => ['dreams' => 'Live independently'],
            'support_needs' => ['daily_living' => true],
            'egl' => ['vision' => 'A good life', 'principles' => ['Self-determination']],
            'funding' => ['nasc_organisation' => 'NASC Wellington', 'allocated_hours' => 20],
            'review_schedule' => ['frequency_months' => 3],
            'domains' => [[
                'key' => 'daily',
                'label' => 'Daily living',
                'status' => 'active',
                'strategies' => [['text' => 'Prompt meds after breakfast', 'owner' => 'Key worker']],
            ]],
        ],
    ])->assertRedirect("/operations/clients/{$client->id}?tab=care_plans");

    $plan = CarePlan::query()->where('client_id', $client->id)->firstOrFail();
    expect(data_get($plan->content, 'about_me.dreams'))->toBe('Live independently')
        ->and(data_get($plan->content, 'egl.vision'))->toBe('A good life')
        ->and(data_get($plan->content, 'egl.principles.0'))->toBe('Self-determination')
        ->and(data_get($plan->content, 'funding.nasc_organisation'))->toBe('NASC Wellington')
        ->and((int) data_get($plan->content, 'review_schedule.frequency_months'))->toBe(3)
        ->and(data_get($plan->content, 'domains.0.label'))->toBe('Daily living');
});

it('overwrites content on update and stays on the profile tab', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.update']);
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client, ['content' => ['about_me' => ['dreams' => 'old']]]);

    $this->actingAs($user)->put("/operations/care-plans/{$plan->id}", [
        'title' => 'Updated plan',
        'plan_type' => 'support_plan',
        'content' => ['about_me' => ['dreams' => 'new'], 'egl' => ['vision' => 'V']],
    ])->assertRedirect("/operations/clients/{$client->id}?tab=care_plans");

    $plan->refresh();
    expect(data_get($plan->content, 'about_me.dreams'))->toBe('new')
        ->and(data_get($plan->content, 'egl.vision'))->toBe('V')
        ->and($plan->title)->toBe('Updated plan');
});

it('blocks activating a plan with no goals or domains', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.update']);
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client, ['status' => 'draft']);

    $this->actingAs($user)->put("/operations/care-plans/{$plan->id}", [
        'title' => 'x',
        'plan_type' => 'support_plan',
        'status' => 'active',
        'content' => ['domains' => []],
    ])->assertSessionHasErrors('goals');

    expect($plan->fresh()->status)->toBe('draft');
});

it('records and removes a sign-off', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.update']);
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client);

    $this->actingAs($user)->post("/operations/care-plans/{$plan->id}/sign-offs", [
        'party_role' => 'whanau',
        'party_name' => 'Hana Wineera',
        'relationship' => 'Sister',
        'agreed_on' => '2026-06-12',
        'method' => 'hui',
    ])->assertRedirect();

    $signOff = CarePlanSignOff::query()->where('care_plan_id', $plan->id)->firstOrFail();
    expect($signOff->party_role)->toBe('whanau')
        ->and($signOff->party_name)->toBe('Hana Wineera')
        ->and($signOff->recorded_by)->toBe($user->id);

    $this->actingAs($user)
        ->delete("/operations/care-plans/{$plan->id}/sign-offs/{$signOff->id}")
        ->assertRedirect();
    expect(CarePlanSignOff::query()->find($signOff->id))->toBeNull();
});

it('rejects an invalid sign-off role', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.update']);
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client);

    $this->actingAs($user)->post("/operations/care-plans/{$plan->id}/sign-offs", [
        'party_role' => 'banana',
        'party_name' => 'X',
        'agreed_on' => '2026-06-12',
    ])->assertSessionHasErrors('party_role');
});

it('forbids sign-off without the update permission', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.viewAny']);
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client);

    $this->actingAs($user)->post("/operations/care-plans/{$plan->id}/sign-offs", [
        'party_role' => 'client',
        'party_name' => 'X',
        'agreed_on' => '2026-06-12',
    ])->assertForbidden();
});

it('exports a plan as a PDF', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.viewAny']);
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client, [
        'content' => [
            'about_me' => ['dreams' => 'Travel'],
            'egl' => ['vision' => 'A good life', 'principles' => ['Mana-enhancing']],
            'funding' => ['nasc_organisation' => 'NASC'],
            'domains' => [['label' => 'Health', 'status' => 'active', 'strategies' => [['text' => 'GP review', 'owner' => 'GP']]]],
        ],
    ]);
    $plan->signOffs()->create([
        'organization_id' => $user->organization_id,
        'party_role' => 'client',
        'party_name' => 'Tane',
        'agreed_on' => '2026-06-01',
        'recorded_by' => $user->id,
    ]);

    $resp = $this->actingAs($user)->get("/operations/care-plans/{$plan->id}/pdf");
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('application/pdf');
});

it('forbids PDF export without view permission', function () {
    $user = User::factory()->create();
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client);

    $this->actingAs($user)->get("/operations/care-plans/{$plan->id}/pdf")->assertForbidden();
});

it('starts a review and stays on the profile tab', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.update']);
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client);

    $this->actingAs($user)
        ->post("/operations/care-plans/{$plan->id}/start-review")
        ->assertRedirect("/operations/clients/{$client->id}?tab=care_plans");

    expect(CarePlan::query()->where('client_id', $client->id)->where('status', 'review')->exists())->toBeTrue();
});
