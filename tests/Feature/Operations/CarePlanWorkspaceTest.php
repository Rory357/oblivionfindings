<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\CarePlanSignOff;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Timeline\TimelineEmitter;
use Barryvdh\DomPDF\Facade\Pdf;

function grantCpPerms(User $user, array $keys, bool $applicationWide = true): void
{
    if ($applicationWide) {
        $keys = array_values(array_unique([...$keys, 'clients.viewAny']));
    }

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

function makeCpClient(?User $_user = null): Client
{
    return Client::factory()->create([
        'site_id' => Site::factory()->create()->id,
    ]);
}

function makeCpPlan(User $user, Client $client, array $attrs = []): CarePlan
{
    return CarePlan::query()->create(array_merge([
        'client_id' => $client->id,
        'title' => 'Active plan',
        'status' => 'active',
        'plan_type' => 'support_plan',
        'version' => 1,
        'created_by' => $user->id,
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

it('blocks creating an active plan with no goals or structured domains', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.create']);
    $client = makeCpClient($user);

    $this->actingAs($user)
        ->post('/operations/care-plans', [
            'client_id' => $client->id,
            'title' => 'Structurally empty active plan',
            'plan_type' => 'support_plan',
            'status' => 'active',
            'content' => ['domains' => []],
        ])
        ->assertSessionHasErrors('goals');

    expect(CarePlan::query()
        ->where('client_id', $client->id)
        ->where('title', 'Structurally empty active plan')
        ->exists())->toBeFalse();
});

it('does not reparent a care plan or its goals and notes to another client', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.update']);
    $originalClient = makeCpClient($user);
    $otherClient = makeCpClient($user);
    $plan = makeCpPlan($user, $originalClient, ['status' => 'draft']);
    $goal = CarePlanGoal::query()->create([
        'care_plan_id' => $plan->id,
        'client_id' => $originalClient->id,
        'title' => 'Keep this goal with the original client',
        'category' => 'daily_living',
        'priority' => 'medium',
        'created_by' => $user->id,
    ]);
    $note = ClientNote::query()->create([
        'client_id' => $originalClient->id,
        'care_plan_goal_id' => $goal->id,
        'user_id' => $user->id,
        'type' => 'progress_note',
        'body' => 'Keep this note with the original client',
        'visibility' => 'internal',
    ]);

    $this->actingAs($user)
        ->put("/operations/care-plans/{$plan->id}", [
            'client_id' => $otherClient->id,
        ])
        ->assertSessionHasErrors('client_id');

    expect($plan->fresh()->client_id)->toBe($originalClient->id)
        ->and($goal->fresh()->client_id)->toBe($originalClient->id)
        ->and($note->fresh()->client_id)->toBe($originalClient->id)
        ->and($note->fresh()->care_plan_goal_id)->toBe($goal->id);
});

it('binds care plan funding agreements to the plan client', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.create', 'care_plans.update']);
    $client = makeCpClient($user);
    $otherClient = makeCpClient($user);
    $ownAgreement = ServiceAgreement::factory()->create([
        'client_id' => $client->id,
        'title' => 'Own service agreement',
    ]);
    $otherAgreement = ServiceAgreement::factory()->create([
        'client_id' => $otherClient->id,
        'title' => 'Other client service agreement',
    ]);

    $this->actingAs($user)
        ->post('/operations/care-plans', [
            'client_id' => $client->id,
            'title' => 'Invalid agreement plan',
            'plan_type' => 'support_plan',
            'status' => 'draft',
            'content' => ['funding' => ['service_agreement_id' => $otherAgreement->id]],
        ])
        ->assertSessionHasErrors('content.funding.service_agreement_id');

    $this->actingAs($user)
        ->post('/operations/care-plans', [
            'client_id' => $client->id,
            'title' => 'Valid agreement plan',
            'plan_type' => 'support_plan',
            'status' => 'draft',
            'content' => ['funding' => ['service_agreement_id' => $ownAgreement->id]],
        ])
        ->assertRedirect("/operations/clients/{$client->id}?tab=care_plans");

    $plan = CarePlan::query()->where('title', 'Valid agreement plan')->sole();
    $this->actingAs($user)
        ->put("/operations/care-plans/{$plan->id}", [
            'content' => ['funding' => ['service_agreement_id' => $otherAgreement->id]],
        ])
        ->assertSessionHasErrors('content.funding.service_agreement_id');

    expect((int) data_get($plan->fresh()->content, 'funding.service_agreement_id'))
        ->toBe($ownAgreement->id);
});

it('records proxy information and preserves it when revoked', function () {
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
        ->and($signOff->recorded_by)->toBe($user->id)
        ->and($signOff->attestation_state)->toBe('recorded_proxy')
        ->and($signOff->gate_satisfying)->toBeFalse();

    $this->actingAs($user)
        ->delete("/operations/care-plans/{$plan->id}/sign-offs/{$signOff->id}")
        ->assertRedirect();
    expect(CarePlanSignOff::query()->find($signOff->id)?->revoked_at)->not->toBeNull();
});

it('records repeated care plan sign-offs with each canonical sign-off as the timeline source', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.update']);
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client);

    foreach ([
        ['party_role' => 'client', 'party_name' => 'Tane Client'],
        ['party_role' => 'whanau', 'party_name' => 'Hana Whanau'],
    ] as $signatory) {
        $this->actingAs($user)
            ->post("/operations/care-plans/{$plan->id}/sign-offs", [
                ...$signatory,
                'agreed_on' => '2026-07-10',
                'method' => 'in_person',
            ])
            ->assertRedirect();
    }

    $signOffs = CarePlanSignOff::query()
        ->where('care_plan_id', $plan->id)
        ->orderBy('id')
        ->get();
    $events = TimelineEvent::query()
        ->where('type', 'care_plan_attestation_evidence_recorded')
        ->where('source_type', CarePlanSignOff::class)
        ->orderBy('source_id')
        ->get();

    expect($signOffs)->toHaveCount(2)
        ->and($events)->toHaveCount(2)
        ->and($events->pluck('source_id')->all())->toBe($signOffs->pluck('id')->all())
        ->and($events->pluck('meta.care_plan_id')->unique()->all())->toBe([$plan->id]);
});

it('rolls back a care plan sign-off when timeline emission fails', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.update']);
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client);
    $emitter = Mockery::mock(TimelineEmitter::class);
    $emitter->shouldReceive('record')
        ->once()
        ->andThrow(new RuntimeException('Timeline unavailable'));
    $this->app->instance(TimelineEmitter::class, $emitter);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($user)
        ->post("/operations/care-plans/{$plan->id}/sign-offs", [
            'party_role' => 'client',
            'party_name' => 'Rollback signatory',
            'agreed_on' => '2026-07-10',
        ]))->toThrow(RuntimeException::class, 'Timeline unavailable');

    expect(CarePlanSignOff::query()->where('care_plan_id', $plan->id)->count())->toBe(0);
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
        'party_role' => 'client',
        'party_name' => 'Tane',
        'agreed_on' => '2026-06-01',
        'recorded_by' => $user->id,
    ]);

    $resp = $this->actingAs($user)->get("/operations/care-plans/{$plan->id}/pdf");
    $resp->assertOk();
    expect($resp->headers->get('content-type'))->toContain('application/pdf');
});

it('does not bind another clients service agreement into a legacy care plan PDF', function () {
    $user = User::factory()->create();
    grantCpPerms($user, ['care_plans.viewAny']);
    $client = makeCpClient($user);
    $otherClient = makeCpClient($user);
    $otherAgreement = ServiceAgreement::factory()->create([
        'client_id' => $otherClient->id,
        'title' => 'Private agreement for another client',
    ]);
    $plan = makeCpPlan($user, $client, [
        'content' => [
            'funding' => ['service_agreement_id' => $otherAgreement->id],
        ],
    ]);

    Pdf::shouldReceive('loadView')
        ->once()
        ->withArgs(fn (string $view, array $data) => $view === 'pdf.care-plan'
            && $data['plan']->is($plan)
            && $data['agreement'] === null)
        ->andReturnSelf();
    Pdf::shouldReceive('setPaper')->once()->with('A4')->andReturnSelf();
    Pdf::shouldReceive('download')->once()->andReturn(response('PDF'));

    $this->actingAs($user)
        ->get("/operations/care-plans/{$plan->id}/pdf")
        ->assertOk();
});

it('forbids PDF export without view permission', function () {
    $user = User::factory()->create();
    $client = makeCpClient($user);
    $plan = makeCpPlan($user, $client);

    $this->actingAs($user)->get("/operations/care-plans/{$plan->id}/pdf")->assertForbidden();
});

it('enforces canonical Client Site access and fails closed without a valid Site', function () {
    $visibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $viewer = User::factory()->create(['approved_at' => now()]);
    grantCpPerms($viewer, ['care_plans.viewAny'], applicationWide: false);
    HrEmployeeProfile::factory()->create([
        'user_id' => $viewer->id,
        'primary_site_id' => $visibleSite->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $visibleClient = Client::factory()->create(['site_id' => $visibleSite->id]);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);
    $clientWithoutSite = Client::factory()->create(['site_id' => null]);
    $visiblePlan = makeCpPlan($viewer, $visibleClient);
    $outsidePlan = makeCpPlan($viewer, $outsideClient);
    $planWithoutSite = makeCpPlan($viewer, $clientWithoutSite);

    $this->actingAs($viewer)
        ->get("/operations/care-plans/{$visiblePlan->id}")
        ->assertOk();

    $this->actingAs($viewer)
        ->get("/operations/care-plans/{$outsidePlan->id}")
        ->assertForbidden();

    $this->actingAs($viewer)
        ->get("/operations/care-plans/{$planWithoutSite->id}")
        ->assertForbidden();
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
