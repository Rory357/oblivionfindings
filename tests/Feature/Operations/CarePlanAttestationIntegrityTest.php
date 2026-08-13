<?php

use App\Models\CarePlan;
use App\Models\CarePlanSignOff;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Timeline\TimelineEmitter;
use Illuminate\Database\QueryException;

function grantCarePlanAttestationPermissions(
    User $user,
    array $keys = ['care_plans.update'],
    bool $applicationWide = true,
): void {
    if ($applicationWide) {
        $keys = array_values(array_unique([...$keys, 'clients.viewAny']));
    }

    $role = Role::query()->firstOrCreate(
        ['name' => 'care_plan_attestation_'.$user->id],
        ['label' => 'Care Plan Attestation', 'level' => 50, 'type' => 'custom'],
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

function assignCarePlanAttestationPortalRole(User $user, string $roleName): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'label' => $roleName === 'client' ? 'Client Portal' : 'Next of Kin / Guardian (Portal)',
            'level' => 15,
            'type' => 'system',
        ],
    );

    $user->forceFill(['role' => $roleName, 'approved_at' => now()])->save();
    $user->roles()->sync([$role->id]);
}

/** @return array{active: CarePlan, review: CarePlan} */
function makeCarePlanAttestationVersions(User $creator, Client $client, string $suffix = ''): array
{
    $content = [
        'domains' => [[
            'key' => 'daily_living',
            'label' => 'Daily living'.$suffix,
            'status' => 'active',
            'strategies' => [['text' => 'Follow the current agreed support approach.', 'owner' => 'Care team']],
        ]],
    ];

    $active = CarePlan::query()->create([
        'client_id' => $client->id,
        'title' => 'Published support plan'.$suffix,
        'status' => 'active',
        'plan_type' => 'support_plan',
        'version' => 1,
        'created_by' => $creator->id,
        'content' => $content,
    ]);
    $review = CarePlan::query()->create([
        'client_id' => $client->id,
        'title' => 'Reviewed support plan'.$suffix,
        'status' => 'review',
        'plan_type' => 'support_plan',
        'version' => 2,
        'parent_id' => $active->id,
        'created_by' => $creator->id,
        'content' => [
            ...$content,
            'review_context' => [
                'source_plan_id' => $active->id,
                'source_version' => 1,
            ],
        ],
    ]);

    return compact('active', 'review');
}

function witnessedCarePlanAttestationPayload(Client $client, string $reference = 'witness-record-001'): array
{
    return [
        'attestation_state' => CarePlanSignOff::STATE_WITNESSED,
        'party_role' => 'client',
        'party_name' => 'Ignored staff-entered label',
        'signer_client_id' => $client->id,
        'agreed_on' => today()->toDateString(),
        'method' => 'in_person',
        'witness_declaration' => '1',
        'evidence_type' => 'witness_statement',
        'evidence_reference' => $reference,
        'acknowledgement' => 'The identified client communicated their response in the witnessed meeting.',
    ];
}

/** @return array{authority: NextOfKin, capacity: ClientConsent} */
function makeCarePlanRepresentativeEvidence(
    Client $client,
    User $representative,
    User $verifier,
): array {
    $authority = NextOfKin::query()->create([
        'user_id' => $representative->id,
        'client_id' => $client->id,
        'relationship' => 'Welfare guardian',
        'legal_authority_type' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        'legal_authority_verified_at' => now()->subDay(),
        'legal_authority_verified_by_user_id' => $verifier->id,
        'legal_authority_expires_at' => now()->addYear(),
    ]);
    $consentType = ConsentType::query()->create([
        'name' => 'Care-plan capacity evidence '.$client->id,
        'category' => 'essential',
        'description' => 'Capacity evidence fixture.',
        'purpose' => 'Test care-plan attestation authority binding.',
        'legal_basis' => 'Recorded evidence for governance review.',
        'requires_capacity_assessment' => true,
        'active' => true,
    ]);
    $capacity = ClientConsent::query()->create([
        'client_id' => $client->id,
        'consent_type_id' => $consentType->id,
        'status' => 'given',
        'given_at' => now()->subDay(),
        'given_by_user_id' => $representative->id,
        'given_by_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        'given_method' => 'portal',
        'capacity_assessed' => true,
        'capacity_outcome' => 'lacks_capacity',
        'capacity_assessor_id' => $verifier->id,
        'capacity_assessed_at' => now()->subDay(),
        'capacity_notes' => 'Assessment evidence is retained in the consent record.',
        'evidence_type' => 'portal_signature',
        'created_by' => $verifier->id,
        'updated_by' => $verifier->id,
    ]);

    return compact('authority', 'capacity');
}

it('records a staff-entered label only as non-gating proxy evidence', function () {
    $manager = User::factory()->create();
    grantCarePlanAttestationPermissions($manager);
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    ['active' => $active, 'review' => $review] = makeCarePlanAttestationVersions($manager, $client);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/sign-offs", [
            'party_role' => 'client',
            'party_name' => 'Staff typed resident name',
            'agreed_on' => today()->toDateString(),
            'method' => 'verbal',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $evidence = CarePlanSignOff::query()->sole();
    expect($evidence->attestation_state)->toBe(CarePlanSignOff::STATE_RECORDED_PROXY)
        ->and($evidence->signer_type)->toBe('named_proxy')
        ->and($evidence->signer_user_id)->toBeNull()
        ->and($evidence->recorded_by)->toBe($manager->id)
        ->and($evidence->gate_satisfying)->toBeFalse()
        ->and($evidence->plan_version_digest)->toHaveLength(64);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/sign-offs", [
            'attestation_state' => CarePlanSignOff::STATE_DIRECT_AUTHENTICATED,
            'party_role' => 'key_worker',
            'signer_user_id' => $manager->id,
            'agreed_on' => today()->toDateString(),
            'method' => 'in_person',
        ])
        ->assertSessionHasErrors('attestation');

    expect(CarePlanSignOff::query()->count())->toBe(1);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/complete-review")
        ->assertSessionHasErrors('sign_offs');

    expect($active->fresh()->status)->toBe('active')
        ->and($review->fresh()->status)->toBe('review');
});

it('accepts an authenticated current clinician once and completes the exact review version idempotently', function () {
    $clinician = User::factory()->create();
    grantCarePlanAttestationPermissions($clinician);
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    ['active' => $active, 'review' => $review] = makeCarePlanAttestationVersions($clinician, $client);
    $credential = StaffCredential::query()->create([
        'user_id' => $clinician->id,
        'type' => 'registered_nurse',
        'issuer' => 'Recorded professional register',
        'issued_at' => today()->subYear(),
        'expires_at' => today()->addYear(),
        'reference' => 'CLINICAL-TEST-01',
    ]);
    $payload = [
        'attestation_state' => CarePlanSignOff::STATE_DIRECT_AUTHENTICATED,
        'party_role' => 'clinician',
        'signer_user_id' => $clinician->id,
        'clinical_credential_id' => $credential->id,
        'agreed_on' => today()->toDateString(),
        'method' => 'in_person',
        'acknowledgement' => 'I attest to this exact recorded care-plan version.',
    ];

    $this->actingAs($clinician)->post("/operations/care-plans/{$review->id}/sign-offs", $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->actingAs($clinician)->post("/operations/care-plans/{$review->id}/sign-offs", $payload)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $signOff = CarePlanSignOff::query()->sole();
    expect($signOff->signer_user_id)->toBe($clinician->id)
        ->and($signOff->recorded_by)->toBe($clinician->id)
        ->and($signOff->clinical_credential_id)->toBe($credential->id)
        ->and($signOff->gate_satisfying)->toBeTrue()
        ->and(CarePlanSignOff::query()->count())->toBe(1);

    $duplicate = $signOff->replicate(['id', 'created_at', 'updated_at']);
    expect(fn () => $duplicate->save())->toThrow(QueryException::class);

    $this->actingAs($clinician)
        ->post("/operations/care-plans/{$review->id}/complete-review", ['review_notes' => 'Reviewed with current evidence.'])
        ->assertRedirect("/operations/clients/{$client->id}?tab=care_plans")
        ->assertSessionHasNoErrors();
    $this->actingAs($clinician)
        ->post("/operations/care-plans/{$review->id}/complete-review", ['review_notes' => 'Replayed completion.'])
        ->assertRedirect("/operations/clients/{$client->id}?tab=care_plans")
        ->assertSessionHasNoErrors();

    expect($active->fresh()->status)->toBe('archived')
        ->and($review->fresh()->status)->toBe('active')
        ->and(TimelineEvent::query()->where('type', 'care_plan_review_completed')->count())->toBe(1);
});

it('supersedes a digest-bound attestation after a substantive plan edit and denies stale completion', function () {
    $manager = User::factory()->create();
    grantCarePlanAttestationPermissions($manager);
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    ['active' => $active, 'review' => $review] = makeCarePlanAttestationVersions($manager, $client);

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/sign-offs", witnessedCarePlanAttestationPayload($client))
        ->assertSessionHasNoErrors();
    $oldDigest = CarePlanSignOff::query()->sole()->plan_version_digest;

    $this->actingAs($manager)
        ->put("/operations/care-plans/{$review->id}", ['title' => 'Substantively changed review plan'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $stale = CarePlanSignOff::query()->sole();
    expect($stale->superseded_at)->not->toBeNull()
        ->and($stale->active_identity_key)->toBeNull();

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/complete-review")
        ->assertSessionHasErrors('sign_offs');

    $this->actingAs($manager)
        ->post(
            "/operations/care-plans/{$review->id}/sign-offs",
            witnessedCarePlanAttestationPayload($client, 'witness-record-after-edit'),
        )
        ->assertSessionHasNoErrors();

    $current = CarePlanSignOff::query()->whereNull('superseded_at')->sole();
    expect($current->plan_version_digest)->not->toBe($oldDigest)
        ->and($current->gate_satisfying)->toBeTrue()
        ->and($active->fresh()->status)->toBe('active');
});

it('fails closed on representative authority or capacity gaps and accepts the fully bound portal representative', function () {
    $manager = User::factory()->create();
    grantCarePlanAttestationPermissions($manager);
    $representative = User::factory()->create();
    assignCarePlanAttestationPortalRole($representative, 'next_of_kin');
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    $client->portalUsers()->attach($representative->id, ['relation' => 'next_of_kin']);
    ['active' => $active, 'review' => $review] = makeCarePlanAttestationVersions($manager, $client);
    ['authority' => $authority, 'capacity' => $capacity] = makeCarePlanRepresentativeEvidence(
        $client,
        $representative,
        $manager,
    );
    $payload = [
        'attestation_state' => CarePlanSignOff::STATE_AUTHORISED_REPRESENTATIVE,
        'party_role' => 'eor_guardian',
        'signer_user_id' => $representative->id,
        'authority_next_of_kin_id' => $authority->id,
        'authority_basis' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        'agreed_on' => today()->toDateString(),
        'method' => 'portal',
    ];

    $this->actingAs($representative)
        ->post("/portal/clients/{$client->id}/care-plans/{$review->id}/attestations", $payload)
        ->assertSessionHasErrors('attestation');
    expect(CarePlanSignOff::query()->exists())->toBeFalse();

    $this->actingAs($representative)
        ->post("/portal/clients/{$client->id}/care-plans/{$review->id}/attestations", [
            ...$payload,
            'capacity_evidence_consent_id' => $capacity->id,
            'acknowledgement' => 'I attest under the recorded authority and capacity evidence shown for governance review.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $signOff = CarePlanSignOff::query()->sole();
    expect($signOff->attestation_state)->toBe(CarePlanSignOff::STATE_AUTHORISED_REPRESENTATIVE)
        ->and($signOff->signer_user_id)->toBe($representative->id)
        ->and($signOff->recorded_by)->toBe($representative->id)
        ->and($signOff->authority_next_of_kin_id)->toBe($authority->id)
        ->and($signOff->capacity_evidence_consent_id)->toBe($capacity->id)
        ->and($signOff->gate_satisfying)->toBeTrue();

    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/complete-review")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($active->fresh()->status)->toBe('archived')
        ->and($review->fresh()->status)->toBe('active');
});

it('distinguishes declined unavailable witnessed and explicitly not-required states', function () {
    $manager = User::factory()->create();
    grantCarePlanAttestationPermissions($manager);
    $site = Site::factory()->create();

    $decliningClient = Client::factory()->create(['site_id' => $site->id]);
    $clientUser = User::factory()->create();
    assignCarePlanAttestationPortalRole($clientUser, 'client');
    $decliningClient->portalUsers()->attach($clientUser->id, ['relation' => 'client']);
    ['review' => $declinedPlan] = makeCarePlanAttestationVersions($manager, $decliningClient, ' declined');
    $this->actingAs($clientUser)
        ->post("/portal/clients/{$decliningClient->id}/care-plans/{$declinedPlan->id}/attestations", [
            'attestation_state' => CarePlanSignOff::STATE_DECLINED,
            'party_role' => 'client',
            'agreed_on' => today()->toDateString(),
            'method' => 'portal',
            'outcome_reason' => 'I do not agree to this version.',
        ])
        ->assertSessionHasNoErrors();

    $unavailableClient = Client::factory()->create(['site_id' => $site->id]);
    ['review' => $unavailablePlan] = makeCarePlanAttestationVersions($manager, $unavailableClient, ' unavailable');
    $this->actingAs($manager)
        ->post("/operations/care-plans/{$unavailablePlan->id}/sign-offs", [
            ...witnessedCarePlanAttestationPayload($unavailableClient, 'contact-attempt-log-77'),
            'attestation_state' => CarePlanSignOff::STATE_UNAVAILABLE,
            'outcome_reason' => 'Three recorded contact attempts were unsuccessful.',
        ])
        ->assertSessionHasNoErrors();

    $witnessedClient = Client::factory()->create(['site_id' => $site->id]);
    ['review' => $witnessedPlan] = makeCarePlanAttestationVersions($manager, $witnessedClient, ' witnessed');
    $this->actingAs($manager)
        ->post(
            "/operations/care-plans/{$witnessedPlan->id}/sign-offs",
            witnessedCarePlanAttestationPayload($witnessedClient, 'signed-plan-copy-88'),
        )
        ->assertSessionHasNoErrors();

    $notRequiredClient = Client::factory()->create(['site_id' => $site->id]);
    ['review' => $notRequiredPlan] = makeCarePlanAttestationVersions($manager, $notRequiredClient, ' policy');
    $notRequiredPlan->update([
        'attestation_policy' => [
            'version' => 1,
            'requirement' => 'not_required',
            'satisfying_states' => [CarePlanSignOff::STATE_NOT_REQUIRED],
            'governance_review_required' => true,
        ],
    ]);
    $this->actingAs($manager)
        ->post("/operations/care-plans/{$notRequiredPlan->id}/sign-offs", [
            'attestation_state' => CarePlanSignOff::STATE_NOT_REQUIRED,
            'party_role' => 'other',
            'agreed_on' => today()->toDateString(),
            'evidence_type' => 'governance_record',
            'evidence_reference' => 'clinical-governance-record-2026-08-14',
            'outcome_reason' => 'The explicit plan policy records that individual attestation is not required.',
        ])
        ->assertSessionHasNoErrors();
    $this->actingAs($manager)
        ->post("/operations/care-plans/{$notRequiredPlan->id}/complete-review")
        ->assertSessionHasNoErrors();

    $states = CarePlanSignOff::query()->pluck('gate_satisfying', 'attestation_state');
    expect((bool) $states[CarePlanSignOff::STATE_DECLINED])->toBeFalse()
        ->and((bool) $states[CarePlanSignOff::STATE_UNAVAILABLE])->toBeFalse()
        ->and((bool) $states[CarePlanSignOff::STATE_WITNESSED])->toBeTrue()
        ->and((bool) $states[CarePlanSignOff::STATE_NOT_REQUIRED])->toBeTrue()
        ->and($notRequiredPlan->fresh()->status)->toBe('active');
});

it('denies wrong-site plan ids cross-plan revocation and cross-client evidence without disclosure or side effects', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $scopedManager = User::factory()->create();
    grantCarePlanAttestationPermissions($scopedManager, ['care_plans.update'], false);
    ensureCanonicalHrStaffProfile($scopedManager, $site);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    ['review' => $hiddenPlan] = makeCarePlanAttestationVersions($scopedManager, $otherClient, ' hidden');

    $this->actingAs($scopedManager)
        ->post(
            "/operations/care-plans/{$hiddenPlan->id}/sign-offs",
            witnessedCarePlanAttestationPayload($otherClient),
        )
        ->assertForbidden();
    expect(CarePlanSignOff::query()->exists())->toBeFalse();

    $manager = User::factory()->create();
    grantCarePlanAttestationPermissions($manager);
    $clientA = Client::factory()->create(['site_id' => $site->id]);
    $clientB = Client::factory()->create(['site_id' => $site->id]);
    ['review' => $planA] = makeCarePlanAttestationVersions($manager, $clientA, ' A');
    ['review' => $planB] = makeCarePlanAttestationVersions($manager, $clientB, ' B');
    $this->actingAs($manager)
        ->post("/operations/care-plans/{$planA->id}/sign-offs", witnessedCarePlanAttestationPayload($clientA))
        ->assertSessionHasNoErrors();
    $signOff = CarePlanSignOff::query()->sole();

    $this->actingAs($manager)
        ->delete("/operations/care-plans/{$planB->id}/sign-offs/{$signOff->id}")
        ->assertNotFound();
    expect($signOff->fresh()->revoked_at)->toBeNull();

    $representative = User::factory()->create();
    assignCarePlanAttestationPortalRole($representative, 'next_of_kin');
    $clientA->portalUsers()->attach($representative->id, ['relation' => 'next_of_kin']);
    ['authority' => $authority] = makeCarePlanRepresentativeEvidence($clientA, $representative, $manager);
    $otherRepresentative = User::factory()->create();
    assignCarePlanAttestationPortalRole($otherRepresentative, 'next_of_kin');
    ['capacity' => $wrongCapacity] = makeCarePlanRepresentativeEvidence($clientB, $otherRepresentative, $manager);

    $this->actingAs($representative)
        ->post("/portal/clients/{$clientA->id}/care-plans/{$planA->id}/attestations", [
            'attestation_state' => CarePlanSignOff::STATE_AUTHORISED_REPRESENTATIVE,
            'party_role' => 'eor_guardian',
            'signer_user_id' => $representative->id,
            'authority_next_of_kin_id' => $authority->id,
            'authority_basis' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
            'capacity_evidence_consent_id' => $wrongCapacity->id,
            'agreed_on' => today()->toDateString(),
            'method' => 'portal',
        ])
        ->assertSessionHasErrors('attestation');

    expect(CarePlanSignOff::query()->count())->toBe(1);
});

it('keeps replay protection and correction history immutable through revocation', function () {
    $manager = User::factory()->create();
    grantCarePlanAttestationPermissions($manager);
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    ['review' => $review] = makeCarePlanAttestationVersions($manager, $client);
    $payload = witnessedCarePlanAttestationPayload($client, 'original-witness-evidence');

    $this->actingAs($manager)->post("/operations/care-plans/{$review->id}/sign-offs", $payload)
        ->assertSessionHasNoErrors();
    $original = CarePlanSignOff::query()->sole();

    $this->actingAs($manager)->post("/operations/care-plans/{$review->id}/sign-offs", [
        ...$payload,
        'acknowledgement' => 'Changed replay payload.',
    ])->assertSessionHasErrors('attestation');
    expect(CarePlanSignOff::query()->count())->toBe(1);

    expect(fn () => $original->update(['party_name' => 'Rewritten signer']))
        ->toThrow(LogicException::class);

    $this->actingAs($manager)
        ->delete("/operations/care-plans/{$review->id}/sign-offs/{$original->id}", [
            'reason' => 'Evidence reference needed correction.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->actingAs($manager)
        ->post(
            "/operations/care-plans/{$review->id}/sign-offs",
            witnessedCarePlanAttestationPayload($client, 'corrected-witness-evidence'),
        )
        ->assertSessionHasNoErrors();

    expect(CarePlanSignOff::query()->count())->toBe(2)
        ->and($original->fresh()->revoked_at)->not->toBeNull()
        ->and($original->fresh()->party_name)->toBe($client->full_name)
        ->and(TimelineEvent::query()
            ->where('source_type', CarePlanSignOff::class)
            ->where('source_id', $original->id)
            ->where('type', 'care_plan_signed_off')
            ->exists())->toBeTrue()
        ->and(TimelineEvent::query()
            ->where('source_type', CarePlanSignOff::class)
            ->where('source_id', $original->id)
            ->where('type', 'care_plan_attestation_revoked')
            ->exists())->toBeTrue();
});

it('rolls back the complete-review transition when immutable timeline emission fails', function () {
    $manager = User::factory()->create();
    grantCarePlanAttestationPermissions($manager);
    $client = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
    ['active' => $active, 'review' => $review] = makeCarePlanAttestationVersions($manager, $client);
    $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/sign-offs", witnessedCarePlanAttestationPayload($client))
        ->assertSessionHasNoErrors();

    $emitter = Mockery::mock(TimelineEmitter::class);
    $emitter->shouldReceive('record')
        ->once()
        ->andThrow(new RuntimeException('Timeline unavailable during completion'));
    $this->app->instance(TimelineEmitter::class, $emitter);
    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($manager)
        ->post("/operations/care-plans/{$review->id}/complete-review"))
        ->toThrow(RuntimeException::class, 'Timeline unavailable during completion');

    expect($active->fresh()->status)->toBe('active')
        ->and($review->fresh()->status)->toBe('review')
        ->and($review->fresh()->reviewed_at)->toBeNull()
        ->and(TimelineEvent::query()->where('type', 'care_plan_review_completed')->exists())->toBeFalse();
});
