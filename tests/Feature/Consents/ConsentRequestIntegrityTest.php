<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ClientIncident;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\Operations\ConsentRequestReminderNotification;
use App\Notifications\Operations\ConsentRequestRespondedNotification;
use App\Services\ConsentRequestService;
use Carbon\Carbon;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\Process;

function grantConsentIntegrityPermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'consent_integrity_'.$user->id],
        ['label' => 'Consent Integrity', 'level' => 50, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function assignConsentIntegrityPortalRole(User $user, string $roleName): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName],
        [
            'label' => $roleName === 'client' ? 'Client Portal' : 'Next of Kin / Guardian (Portal)',
            'level' => 15,
            'type' => 'system',
        ],
    );

    $user->update(['role' => $roleName]);
    $user->roles()->sync([$role->id]);
}

/**
 * @return array{staff: User, recipient: User, client: Client, site: Site, consentType: ConsentType}
 */
function makeConsentIntegrityContext(): array
{
    $site = Site::factory()->create();
    $staff = User::factory()->create([
        'role' => 'manager',
    ]);
    grantConsentIntegrityPermissions($staff, [
        'clients.viewAny',
        'consents.manage',
        'consents.record',
        'consents.request',
        'consents.withdraw',
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $staff->id,
        'updated_by' => $staff->id,
    ]);

    $recipient = User::factory()->create();
    assignConsentIntegrityPortalRole($recipient, 'next_of_kin');
    $client = Client::factory()->create(['site_id' => $site->id]);
    $client->portalUsers()->attach($recipient->id, ['relation' => 'next_of_kin']);

    return [
        'staff' => $staff,
        'recipient' => $recipient,
        'client' => $client,
        'site' => $site,
        'consentType' => ConsentType::factory()->create(['active' => true]),
    ];
}

/** @param array{staff: User, recipient: User, client: Client, site: Site, consentType: ConsentType} $context */
function consentIntegrityPayload(array $context, array $overrides = []): array
{
    return [
        'consent_type_id' => $context['consentType']->id,
        'recipient_user_id' => $context['recipient']->id,
        'recipient_relationship' => ConsentRequest::RELATION_NEXT_OF_KIN,
        'purpose' => 'Confirm informed consent for the documented support intervention.',
        'least_restrictive_justification' => 'Less restrictive alternatives were reviewed with the care team.',
        'data_scope' => 'Immediate care team only.',
        'retention_period_days' => 180,
        'withdrawal_method_text' => 'Contact the key worker to withdraw consent.',
        'expires_in_days' => 14,
        ...$overrides,
    ];
}

/** @param array{staff: User, recipient: User, client: Client, site: Site, consentType: ConsentType} $context */
function makeConsentIntegrityRequest(array $context, array $overrides = []): ConsentRequest
{
    return ConsentRequest::factory()->create([
        'client_id' => $context['client']->id,
        'consent_type_id' => $context['consentType']->id,
        'requested_by_user_id' => $context['staff']->id,
        'recipient_user_id' => $context['recipient']->id,
        'recipient_relationship' => ConsentRequest::RELATION_NEXT_OF_KIN,
        'status' => ConsentRequest::STATUS_PENDING,
        'expires_at' => now()->addDays(14),
        ...$overrides,
    ]);
}

function consentIntegrityHttpRequest(User $user): HttpRequest
{
    $request = HttpRequest::create('/portal/consent-request', 'POST', server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'ConsentIntegrityPest',
    ]);
    $request->setUserResolver(fn () => $user);

    return $request;
}

/** @param array{staff: User, recipient: User, client: Client, site: Site, consentType: ConsentType} $context */
function makeVerifiedConsentAuthority(
    array $context,
    string $authorityType = ConsentRequest::RELATION_WELFARE_GUARDIAN,
    ?Carbon $expiresAt = null,
): NextOfKin {
    return NextOfKin::query()->create([
        'user_id' => $context['recipient']->id,
        'client_id' => $context['client']->id,
        'relationship' => 'guardian',
        'legal_authority_type' => $authorityType,
        'legal_authority_verified_at' => now()->subDay(),
        'legal_authority_verified_by_user_id' => $context['staff']->id,
        'legal_authority_expires_at' => $expiresAt ?? now()->addYear(),
    ]);
}

/** @return array<string, mixed> */
function consentIntegrityDecisionEvidence(array $overrides = []): array
{
    return [
        'capacity_outcome' => 'lacks_capacity',
        'capacity_assessed_at' => now()->subDay()->toIso8601String(),
        'capacity_assessment_expires_at' => now()->addMonth()->toIso8601String(),
        'capacity_assessment_reason' => 'The client could not understand, retain, or weigh the information for this specific decision.',
        'capacity_evidence_type' => 'documented_assessment',
        'capacity_evidence_reference' => 'capacity-assessment-CA-2026-0042',
        'best_interests_process_reason' => 'The team reviewed known wishes, foreseeable effects, and less restrictive alternatives for this decision.',
        'best_interests_evidence_type' => 'multidisciplinary_review',
        'best_interests_evidence_reference' => 'best-interests-review-BI-2026-0042',
        'best_interests_consultees' => ['Key worker', 'Clinical lead', 'Welfare guardian'],
        ...$overrides,
    ];
}

beforeEach(function () {
    Notification::fake();
});

it('rejects a substitute relationship label without verified legal authority', function () {
    $context = makeConsentIntegrityContext();
    NextOfKin::query()->create([
        'user_id' => $context['recipient']->id,
        'client_id' => $context['client']->id,
        'relationship' => 'guardian',
    ]);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertSessionHasErrors('recipient_relationship');

    expect(ConsentRequest::query()->exists())->toBeFalse();
});

it('does not let verified relationship or authority imply capacity and best interests', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
            ]),
        )
        ->assertSessionHasErrors('capacity_outcome');

    expect(ConsentRequest::query()->exists())->toBeFalse()
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('rejects every partial substituted-consent evidence bundle atomically', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);
    $requiredEvidence = array_keys(consentIntegrityDecisionEvidence());

    foreach ($requiredEvidence as $missing) {
        $evidence = consentIntegrityDecisionEvidence();
        unset($evidence[$missing]);

        $this->actingAs($context['staff'])
            ->post(
                "/operations/clients/{$context['client']->id}/consent-requests",
                consentIntegrityPayload($context, [
                    'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                    ...$evidence,
                ]),
            )
            ->assertSessionHasErrors($missing);
    }

    expect(ConsentRequest::query()->exists())->toBeFalse()
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('rejects forged capacity and best-interests assertions on direct consent recording', function () {
    $context = makeConsentIntegrityContext();

    $this->actingAs($context['staff'])
        ->post("/operations/clients/{$context['client']->id}/consents", [
            'consent_type_id' => $context['consentType']->id,
            'status' => 'given',
            'given_method' => 'written',
            'given_at' => now()->toDateString(),
            'given_by_relationship' => 'staff_recorded',
            'capacity_assessed' => true,
            'capacity_outcome' => 'lacks_capacity',
            'capacity_notes' => 'A relationship label is not capacity evidence.',
            'best_interests_decision' => true,
            'best_interests_rationale' => 'A client-supplied assertion is not a process.',
        ])
        ->assertSessionHasErrors('capacity_assessed');

    expect(ClientConsent::query()->exists())->toBeFalse();
});

it('rejects a substitute relationship on direct consent recording without canonical authority evidence', function () {
    $context = makeConsentIntegrityContext();

    $this->actingAs($context['staff'])
        ->post("/operations/clients/{$context['client']->id}/consents", [
            'consent_type_id' => $context['consentType']->id,
            'status' => 'given',
            'given_method' => 'written',
            'given_at' => now()->toDateString(),
            'given_by_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        ])
        ->assertSessionHasErrors('given_by_relationship');

    expect(ClientConsent::query()->exists())->toBeFalse();
});

it('rejects forged authority and decision provenance on direct consent recording', function () {
    $context = makeConsentIntegrityContext();

    $this->actingAs($context['staff'])
        ->post("/operations/clients/{$context['client']->id}/consents", [
            'consent_type_id' => $context['consentType']->id,
            'status' => 'given',
            'given_method' => 'written',
            'given_at' => now()->toDateString(),
            'conditions' => [
                'authority_next_of_kin_id' => 999999,
                'decision_evidence' => ['scope_digest' => str_repeat('a', 64)],
            ],
        ])
        ->assertSessionHasErrors('conditions');

    expect(ClientConsent::query()->exists())->toBeFalse();
});

it('separates verified authority capacity assessment and accepted best-interests evidence', function () {
    $context = makeConsentIntegrityContext();
    $authority = makeVerifiedConsentAuthority($context);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $consentRequest = ConsentRequest::query()->sole();
    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            [
                'response_notes' => 'This decision reflects the client\'s known wishes and is the least restrictive available option.',
                'acknowledge_authority' => '1',
            ],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $consentRequest->refresh();
    $consent = ClientConsent::query()->findOrFail($consentRequest->resulting_consent_id);

    expect($consentRequest->authority_next_of_kin_id)->toBe($authority->id)
        ->and($consentRequest->authorityToConsent())->toBe('substitute')
        ->and($consent->capacity_assessed)->toBeTrue()
        ->and($consent->capacity_outcome)->toBe('lacks_capacity')
        ->and($consent->best_interests_decision)->toBeTrue()
        ->and($consent->best_interests_decision_maker_id)->toBe($context['recipient']->id)
        ->and($consent->conditions['authority_next_of_kin_id'] ?? null)->toBe($authority->id)
        ->and($consentRequest->decision_evidence_recorded_by_user_id)->toBe($context['staff']->id)
        ->and($consentRequest->capacity_assessor_user_id)->toBe($context['staff']->id)
        ->and($consentRequest->decision_evidence_accepted_by_user_id)->toBe($context['recipient']->id)
        ->and($consentRequest->decision_scope_digest)->toHaveLength(64)
        ->and($consent->decision_evidence_digest)->toBe($consentRequest->decision_scope_digest)
        ->and(data_get($consent->conditions, 'decision_evidence.best_interests_process.evidence_reference'))
        ->toBe('best-interests-review-BI-2026-0042');
});

it('derives the authority binding server-side and ignores a forged foreign authority id', function () {
    $context = makeConsentIntegrityContext();
    $canonicalAuthority = makeVerifiedConsentAuthority($context);
    $foreign = makeConsentIntegrityContext();
    $foreignAuthority = makeVerifiedConsentAuthority($foreign);
    $payload = consentIntegrityPayload($context, [
        'client_id' => $context['client']->id,
        'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        'authority_next_of_kin_id' => $foreignAuthority->id,
        ...consentIntegrityDecisionEvidence(),
    ]);
    unset($payload['expires_in_days']);

    $request = app(ConsentRequestService::class)->create($payload, $context['staff']);

    expect($request->authority_next_of_kin_id)->toBe($canonicalAuthority->id)
        ->and($request->authority_next_of_kin_id)->not->toBe($foreignAuthority->id);
});

it('binds only an authorised canonical Client source and conceals foreign source ids', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);
    $foreignClient = Client::factory()->create(['site_id' => $context['site']->id]);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                'triggering_subject_type' => 'client',
                'triggering_subject_id' => $context['client']->id,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $boundRequest = ConsentRequest::query()->sole();
    expect($boundRequest->triggering_subject_type)->toBe('client')
        ->and($boundRequest->triggering_subject_id)->toBe($context['client']->id)
        ->and($boundRequest->decision_scope_digest)->toHaveLength(64);

    $privateIncident = ClientIncident::factory()->create([
        'client_id' => $context['client']->id,
        'site_id' => $context['site']->id,
    ]);
    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                'triggering_subject_type' => ClientIncident::class,
                'triggering_subject_id' => $privateIncident->id,
                ...consentIntegrityDecisionEvidence([
                    'capacity_evidence_reference' => 'capacity-assessment-private-source',
                ]),
            ]),
        )
        ->assertSessionHasErrors('triggering_subject_id');

    grantConsentIntegrityPermissions($context['staff'], [
        'clients.viewAny',
        'consents.manage',
        'consents.record',
        'consents.request',
        'consents.withdraw',
        'incidents.viewAny',
    ]);
    $wrongSiteIncident = ClientIncident::factory()->create([
        'client_id' => $context['client']->id,
        'site_id' => Site::factory()->create()->id,
    ]);
    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                'triggering_subject_type' => ClientIncident::class,
                'triggering_subject_id' => $wrongSiteIncident->id,
                ...consentIntegrityDecisionEvidence([
                    'capacity_evidence_reference' => 'capacity-assessment-wrong-site-source',
                ]),
            ]),
        )
        ->assertSessionHasErrors('triggering_subject_id');

    foreach ([$foreignClient->id, 999999999] as $foreignId) {
        $this->actingAs($context['staff'])
            ->post(
                "/operations/clients/{$context['client']->id}/consent-requests",
                consentIntegrityPayload($context, [
                    'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                    'triggering_subject_type' => 'client',
                    'triggering_subject_id' => $foreignId,
                    ...consentIntegrityDecisionEvidence([
                        'capacity_evidence_reference' => "capacity-assessment-source-{$foreignId}",
                    ]),
                ]),
            )
            ->assertSessionHasErrors('triggering_subject_id');
    }

    expect(ConsentRequest::query()->count())->toBe(1);
});

it('revalidates the locked canonical source Site and privacy authority before acceptance', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);
    grantConsentIntegrityPermissions($context['staff'], [
        'clients.viewAny',
        'consents.manage',
        'consents.record',
        'consents.request',
        'consents.withdraw',
        'incidents.viewAny',
    ]);
    $incident = ClientIncident::factory()->create([
        'client_id' => $context['client']->id,
        'site_id' => $context['site']->id,
    ]);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                'triggering_subject_type' => ClientIncident::class,
                'triggering_subject_id' => $incident->id,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertRedirect();
    $consentRequest = ConsentRequest::query()->sole();
    $incident->update(['site_id' => Site::factory()->create()->id]);

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            [
                'response_notes' => 'The source changed after this evidence was recorded.',
                'acknowledge_authority' => '1',
            ],
        )
        ->assertStatus(409);

    expect($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and($consentRequest->fresh()->decision_evidence_accepted_at)->toBeNull()
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('will not bind the same decision evidence to a second request', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);
    $payload = consentIntegrityPayload($context, [
        'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        ...consentIntegrityDecisionEvidence(),
    ]);

    $this->actingAs($context['staff'])
        ->post("/operations/clients/{$context['client']->id}/consent-requests", $payload)
        ->assertRedirect();
    $this->actingAs($context['staff'])
        ->post("/operations/clients/{$context['client']->id}/consent-requests", $payload)
        ->assertSessionHasErrors('capacity_assessment');

    expect(ConsentRequest::query()->count())->toBe(1);
});

it('requires segregation between the capacity assessor and substitute decision maker', function () {
    $context = makeConsentIntegrityContext();
    $combinedActor = User::factory()->create();
    assignConsentIntegrityPortalRole($combinedActor, 'next_of_kin');
    grantConsentIntegrityPermissions($combinedActor, [
        'clients.viewAny',
        'consents.manage',
        'consents.request',
    ]);
    $context['client']->portalUsers()->detach($context['recipient']->id);
    $context['client']->portalUsers()->attach($combinedActor->id, [
        'relation' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
    ]);
    $context['recipient'] = $combinedActor;
    makeVerifiedConsentAuthority($context);

    $this->actingAs($combinedActor)
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertSessionHasErrors('capacity_assessment');

    expect(ConsentRequest::query()->exists())->toBeFalse();
});

it('permits an explicit application-wide Site role as a separate positive path', function () {
    $context = makeConsentIntegrityContext();
    $globalRecorder = User::factory()->create(['role' => 'manager']);
    grantConsentIntegrityPermissions($globalRecorder, [
        'clients.viewAny',
        'sites.viewAll',
        'consents.manage',
        'consents.request',
    ]);
    $context['staff'] = $globalRecorder;
    makeVerifiedConsentAuthority($context);

    $this->actingAs($globalRecorder)
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(ConsentRequest::query()->sole()->decision_evidence_recorded_by_user_id)
        ->toBe($globalRecorder->id);
});

it('rejects verified authority when its explicit type does not match the request', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context, ConsentRequest::RELATION_COURT_APPOINTED);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertSessionHasErrors('recipient_relationship');

    expect(ConsentRequest::query()->exists())->toBeFalse();
});

it('revalidates substitute authority expiry inside the locked approval decision', function () {
    $context = makeConsentIntegrityContext();
    $authority = makeVerifiedConsentAuthority($context);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertRedirect();
    $consentRequest = ConsentRequest::query()->sole();
    $authority->update(['legal_authority_expires_at' => now()->subMinute()]);

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            [
                'response_notes' => 'The current evidence supports this decision.',
                'acknowledge_authority' => '1',
            ],
        )
        ->assertStatus(409);

    expect($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('rejects a changed authority snapshot even when the revised authority remains current', function () {
    $context = makeConsentIntegrityContext();
    $authority = makeVerifiedConsentAuthority($context);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertRedirect();
    $consentRequest = ConsentRequest::query()->sole();
    $authority->update(['legal_authority_expires_at' => now()->addMonths(6)]);

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            [
                'response_notes' => 'The revised authority should require a newly bound decision request.',
                'acknowledge_authority' => '1',
            ],
        )
        ->assertStatus(409);

    expect($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('rejects stale decision scope after the canonical Client Site changes', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertRedirect();
    $consentRequest = ConsentRequest::query()->sole();
    $context['client']->update(['site_id' => Site::factory()->create()->id]);

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            [
                'response_notes' => 'The decision is recorded for the changed scope.',
                'acknowledge_authority' => '1',
            ],
        )
        ->assertStatus(409);

    expect($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and($consentRequest->fresh()->decision_evidence_accepted_at)->toBeNull()
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('requires a decision-specific representative reason before accepting evidence', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertRedirect();
    $consentRequest = ConsentRequest::query()->sole();

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            ['acknowledge_authority' => '1'],
        )
        ->assertSessionHasErrors('response_notes');

    expect($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('keeps revocation provenance and will not accept cancelled decision evidence', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
                ...consentIntegrityDecisionEvidence(),
            ]),
        )
        ->assertRedirect();
    $consentRequest = ConsentRequest::query()->sole();

    app(ConsentRequestService::class)->cancel(
        $consentRequest,
        $context['staff'],
        'Capacity evidence changed and must be reassessed.',
    );

    $conflicted = false;
    try {
        app(ConsentRequestService::class)->approve(
            $consentRequest,
            $context['recipient'],
            consentIntegrityHttpRequest($context['recipient']),
            'Attempted replay after the evidence was revoked.',
        );
    } catch (ConflictHttpException) {
        $conflicted = true;
    }

    $consentRequest->refresh();
    expect($conflicted)->toBeTrue()
        ->and($consentRequest->status)->toBe(ConsentRequest::STATUS_CANCELLED)
        ->and($consentRequest->decision_evidence_revoked_by_user_id)->toBe($context['staff']->id)
        ->and($consentRequest->decision_evidence_revocation_reason)->toContain('Capacity evidence changed')
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('keeps recorded capacity and best-interests evidence immutable through change and revocation', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);
    $payload = consentIntegrityPayload($context, [
        'client_id' => $context['client']->id,
        'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        ...consentIntegrityDecisionEvidence(),
    ]);
    unset($payload['expires_in_days']);
    $consentRequest = app(ConsentRequestService::class)->create($payload, $context['staff']);
    $originalReason = $consentRequest->capacity_assessment_reason;

    expect(fn () => $consentRequest->update([
        'capacity_assessment_reason' => 'Silently replaced capacity evidence.',
    ]))->toThrow(LogicException::class, 'immutable');

    app(ConsentRequestService::class)->cancel(
        $consentRequest->fresh(),
        $context['staff'],
        'New evidence requires a new decision request.',
    );

    expect($consentRequest->fresh()->capacity_assessment_reason)->toBe($originalReason)
        ->and($consentRequest->fresh()->decision_evidence_revoked_at)->not->toBeNull();
});

it('rolls back consent evidence acceptance when materialisation fails', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);
    $payload = consentIntegrityPayload($context, [
        'client_id' => $context['client']->id,
        'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        ...consentIntegrityDecisionEvidence(),
    ]);
    unset($payload['expires_in_days']);
    $consentRequest = app(ConsentRequestService::class)->create($payload, $context['staff']);
    $auditCountBefore = DB::table('audit_logs')->count();

    ClientConsent::created(function (): void {
        throw new RuntimeException('Injected failure after consent insert.');
    });

    expect(fn () => app(ConsentRequestService::class)->approve(
        $consentRequest,
        $context['recipient'],
        consentIntegrityHttpRequest($context['recipient']),
        'This evidence should roll back with the injected failure.',
    ))->toThrow(RuntimeException::class, 'Injected failure');

    $consentRequest->refresh();
    expect($consentRequest->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and($consentRequest->resulting_consent_id)->toBeNull()
        ->and($consentRequest->decision_evidence_accepted_at)->toBeNull()
        ->and(ClientConsent::query()->exists())->toBeFalse()
        ->and(DB::table('audit_logs')->count())->toBe($auditCountBefore);
});

it('preserves ordinary next-of-kin approval without fabricating incapacity', function () {
    $context = makeConsentIntegrityContext();

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context),
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $consentRequest = ConsentRequest::query()->sole();

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            [
                'acknowledge_authority' => '1',
            ],
        )
        ->assertRedirect();

    $consent = ClientConsent::query()->sole();
    expect($consentRequest->fresh()->authorityToConsent())->toBe('informational_only')
        ->and($consent->capacity_assessed)->toBeFalse()
        ->and($consent->capacity_outcome)->toBeNull()
        ->and($consent->best_interests_decision)->toBeFalse()
        ->and($consent->best_interests_decision_maker_id)->toBeNull();
});

it('preserves client self-consent without substitute-capacity fields', function () {
    $context = makeConsentIntegrityContext();
    assignConsentIntegrityPortalRole($context['recipient'], 'client');
    $context['client']->update(['user_id' => $context['recipient']->id]);
    $context['client']->portalUsers()->detach($context['recipient']->id);
    $context['client']->portalUsers()->attach($context['recipient']->id, ['relation' => 'self']);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_SELF,
            ]),
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $consentRequest = ConsentRequest::query()->sole();

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            [
                'acknowledge_authority' => '1',
            ],
        )
        ->assertRedirect();

    $consent = ClientConsent::query()->sole();
    expect($consentRequest->fresh()->authorityToConsent())->toBe('self')
        ->and($consent->capacity_assessed)->toBeFalse()
        ->and($consent->best_interests_decision)->toBeFalse();
});

it('rejects a self label when the recipient is only linked as next of kin', function () {
    $context = makeConsentIntegrityContext();
    $context['client']->update(['user_id' => $context['recipient']->id]);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_SELF,
            ]),
        )
        ->assertSessionHasErrors('recipient_relationship');

    expect(ConsentRequest::query()->exists())->toBeFalse();
});

it('makes two stale identical approvals one committed decision and one consent', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context);
    $firstCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $staleCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $service = app(ConsentRequestService::class);
    $httpRequest = consentIntegrityHttpRequest($context['recipient']);

    $firstConsent = $service->approve(
        $firstCopy,
        $context['recipient'],
        $httpRequest,
        'Same decision.',
    );
    $secondConsent = $service->approve(
        $staleCopy,
        $context['recipient'],
        $httpRequest,
        'Same decision.',
    );

    $approvedAuditEvents = collect($consentRequest->fresh()->audit_trail)
        ->where('event', 'approved')
        ->count();

    expect($secondConsent->id)->toBe($firstConsent->id)
        ->and(ClientConsent::query()->count())->toBe(1)
        ->and($approvedAuditEvents)->toBe(1)
        ->and(Notification::sent(
            $context['staff'],
            ConsentRequestRespondedNotification::class,
        )->count())->toBe(1);
});

it('serializes concurrent substituted approvals to one accepted evidence transition', function () {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);
    $payload = consentIntegrityPayload($context, [
        'client_id' => $context['client']->id,
        'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        ...consentIntegrityDecisionEvidence(),
    ]);
    unset($payload['expires_in_days']);
    $consentRequest = app(ConsentRequestService::class)->create($payload, $context['staff']);
    $database = $connection->getDatabaseName();
    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."consent-approval-release-{$token}";
    $readyPaths = [
        sys_get_temp_dir().DIRECTORY_SEPARATOR."consent-approval-ready-a-{$token}",
        sys_get_temp_dir().DIRECTORY_SEPARATOR."consent-approval-ready-b-{$token}",
    ];
    $attemptPaths = [
        sys_get_temp_dir().DIRECTORY_SEPARATOR."consent-approval-attempt-a-{$token}",
        sys_get_temp_dir().DIRECTORY_SEPARATOR."consent-approval-attempt-b-{$token}",
    ];
    $processes = [];
    $consentIds = [];

    $connection->commit();

    try {
        $connection->beginTransaction();
        ConsentRequest::query()->whereKey($consentRequest->id)->lockForUpdate()->firstOrFail();

        foreach ([0, 1] as $index) {
            $processes[] = consentIntegrityStartApprovalWorker(
                $database,
                $consentRequest->id,
                $context['recipient']->id,
                $readyPaths[$index],
                $attemptPaths[$index],
                $releasePath,
            );
        }

        consentIntegrityWaitForFiles($readyPaths, 'Both consent workers did not become ready.');
        touch($releasePath);
        consentIntegrityWaitForFiles($attemptPaths, 'Both consent workers did not attempt approval.');
        usleep(250_000);
        foreach ($processes as $process) {
            expect($process->isRunning())->toBeTrue();
        }

        $connection->commit();

        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue(
                trim($process->getErrorOutput()) ?: 'A consent approval worker failed.',
            );
            $consentIds[] = json_decode(
                trim($process->getOutput()),
                true,
                flags: JSON_THROW_ON_ERROR,
            )['consent_id'];
        }

        expect(array_unique($consentIds))->toHaveCount(1)
            ->and(ClientConsent::query()->where('consent_request_id', $consentRequest->id)->count())->toBe(1)
            ->and(collect($consentRequest->fresh()->audit_trail)->where('event', 'approved')->count())->toBe(1)
            ->and($consentRequest->fresh()->decision_evidence_accepted_by_user_id)->toBe($context['recipient']->id);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $materialisedIds = DB::table('client_consents')
            ->where('consent_request_id', $consentRequest->id)
            ->pluck('id');
        DB::table('notifications')
            ->where('notifiable_id', $context['staff']->id)
            ->where('data', 'like', '%"consent_request_id":'.$consentRequest->id.'%')
            ->delete();
        DB::table('audit_logs')
            ->where('auditable_type', ClientConsent::class)
            ->whereIn('auditable_id', $materialisedIds)
            ->delete();
        DB::table('consent_requests')->where('id', $consentRequest->id)->update(['resulting_consent_id' => null]);
        DB::table('client_consents')->whereIn('id', $materialisedIds)->delete();
        DB::table('consent_requests')->where('id', $consentRequest->id)->delete();
        DB::table('next_of_kins')->where('client_id', $context['client']->id)->delete();
        DB::table('client_portal_users')->where('client_id', $context['client']->id)->delete();
        DB::table('hr_employee_profiles')->where('user_id', $context['staff']->id)->delete();
        $createdUserIds = [$context['staff']->id, $context['recipient']->id];
        $createdRoleIds = DB::table('role_user')->whereIn('user_id', $createdUserIds)->pluck('role_id');
        DB::table('role_user')->whereIn('user_id', $createdUserIds)->delete();
        DB::table('role_permission')->whereIn('role_id', $createdRoleIds)->delete();
        DB::table('roles')->whereIn('id', $createdRoleIds)->delete();
        DB::table('permissions')->where('module', 'Test')->delete();
        DB::table('consent_types')->where('id', $context['consentType']->id)->delete();
        DB::table('clients')->where('id', $context['client']->id)->delete();
        DB::table('users')->whereIn('id', $createdUserIds)->delete();
        DB::table('sites')->where('id', $context['site']->id)->delete();

        $connection->beginTransaction();
    }
});

it('preserves a committed decision audit when a stale view finishes later', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context);
    $staleView = ConsentRequest::query()->findOrFail($consentRequest->id);
    $service = app(ConsentRequestService::class);

    $service->approve(
        $consentRequest,
        $context['recipient'],
        consentIntegrityHttpRequest($context['recipient']),
        'Approved before the delayed view completed.',
    );
    $service->markViewed($staleView);

    $consentRequest->refresh();
    $events = collect($consentRequest->audit_trail)->pluck('event');

    expect($consentRequest->status)->toBe(ConsentRequest::STATUS_APPROVED)
        ->and($consentRequest->viewed_at)->not->toBeNull()
        ->and($events->filter(fn ($event) => $event === 'approved')->count())->toBe(1)
        ->and($events->filter(fn ($event) => $event === 'viewed')->count())->toBe(1);
});

it('does not send a reminder or overwrite audit after a terminal decision', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context, [
        'expires_at' => now()->addDays(2),
    ]);
    $staleReminder = ConsentRequest::query()->findOrFail($consentRequest->id);
    $service = app(ConsentRequestService::class);

    $service->approve(
        $consentRequest,
        $context['recipient'],
        consentIntegrityHttpRequest($context['recipient']),
        'Approved before the delayed reminder completed.',
    );
    $sent = $service->sendReminder($staleReminder);

    $events = collect($consentRequest->fresh()->audit_trail)->pluck('event');

    expect($sent)->toBeFalse()
        ->and($events->filter(fn ($event) => $event === 'approved')->count())->toBe(1)
        ->and($events->contains('reminder_sent'))->toBeFalse();
    Notification::assertNotSentTo(
        $context['recipient'],
        ConsentRequestReminderNotification::class,
    );
});

it('makes two stale reminder attempts one notification and one audit event', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context, [
        'expires_at' => now()->addDays(2),
    ]);
    $firstCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $staleCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $service = app(ConsentRequestService::class);

    $firstSent = $service->sendReminder($firstCopy);
    $secondSent = $service->sendReminder($staleCopy);

    expect($firstSent)->toBeTrue()
        ->and($secondSent)->toBeFalse()
        ->and(collect($consentRequest->fresh()->audit_trail)
            ->where('event', 'reminder_sent')
            ->count())->toBe(1);
    Notification::assertSentToTimes(
        $context['recipient'],
        ConsentRequestReminderNotification::class,
        1,
    );
});

it('does not let stale expiry overwrite a cancellation committed after selection', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context, [
        'expires_at' => now()->subMinute(),
    ]);
    $service = app(ConsentRequestService::class);
    $intercepted = false;

    ConsentRequest::retrieved(function (ConsentRequest $selected) use (
        &$intercepted,
        $consentRequest,
        $context,
        $service,
    ): void {
        if ($intercepted || $selected->id !== $consentRequest->id) {
            return;
        }

        $intercepted = true;
        $service->cancel(
            ConsentRequest::query()->findOrFail($selected->id),
            $context['staff'],
            'Cancelled while the expiry batch held a stale selection.',
        );
    });

    $expired = $service->expireStale();
    $consentRequest->refresh();
    $events = collect($consentRequest->audit_trail)->pluck('event');

    expect($intercepted)->toBeTrue()
        ->and($expired)->toBe(0)
        ->and($consentRequest->status)->toBe(ConsentRequest::STATUS_CANCELLED)
        ->and($events->filter(fn ($event) => $event === 'cancelled')->count())->toBe(1)
        ->and($events->contains('expired'))->toBeFalse();
});

it('prevents a stale decline from overriding a committed approval', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context);
    $approveCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $declineCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $service = app(ConsentRequestService::class);
    $httpRequest = consentIntegrityHttpRequest($context['recipient']);

    $service->approve($approveCopy, $context['recipient'], $httpRequest, 'Approved.');

    $conflicted = false;
    try {
        $service->decline($declineCopy, $context['recipient'], $httpRequest, 'Declined.');
    } catch (ConflictHttpException) {
        $conflicted = true;
    }

    expect($conflicted)->toBeTrue()
        ->and($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_APPROVED)
        ->and(ClientConsent::query()->count())->toBe(1);
});

it('prevents a stale approval from overriding a committed decline', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context);
    $declineCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $approveCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $service = app(ConsentRequestService::class);
    $httpRequest = consentIntegrityHttpRequest($context['recipient']);

    $service->decline($declineCopy, $context['recipient'], $httpRequest, 'Declined.');

    $conflicted = false;
    try {
        $service->approve($approveCopy, $context['recipient'], $httpRequest, 'Approved.');
    } catch (ConflictHttpException) {
        $conflicted = true;
    }

    expect($conflicted)->toBeTrue()
        ->and($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_DECLINED)
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('makes two stale identical declines one committed decision', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context);
    $firstCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $staleCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $service = app(ConsentRequestService::class);
    $httpRequest = consentIntegrityHttpRequest($context['recipient']);

    $this->travelTo(Carbon::parse('2026-07-10 09:00:00'));
    $service->decline($firstCopy, $context['recipient'], $httpRequest, 'Same decline.');
    $firstUpdatedAt = $consentRequest->fresh()->updated_at->copy();

    $this->travelTo(Carbon::parse('2026-07-10 09:05:00'));
    $service->decline($staleCopy, $context['recipient'], $httpRequest, 'Same decline.');
    $consentRequest->refresh();
    $this->travelBack();

    expect($consentRequest->updated_at->equalTo($firstUpdatedAt))->toBeTrue()
        ->and(collect($consentRequest->audit_trail)->where('event', 'declined')->count())->toBe(1)
        ->and(Notification::sent(
            $context['staff'],
            ConsentRequestRespondedNotification::class,
        )->count())->toBe(1);
});

it('makes a stale repeated identical cancellation a no-op', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context);
    $firstCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $staleCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $service = app(ConsentRequestService::class);

    $this->travelTo(Carbon::parse('2026-07-10 10:00:00'));
    $service->cancel($firstCopy, $context['staff'], 'Request sent in error.');
    $firstUpdatedAt = $consentRequest->fresh()->updated_at->copy();

    $this->travelTo(Carbon::parse('2026-07-10 10:05:00'));
    $service->cancel($staleCopy, $context['staff'], 'Request sent in error.');
    $consentRequest->refresh();
    $this->travelBack();

    expect($consentRequest->updated_at->equalTo($firstUpdatedAt))->toBeTrue()
        ->and(collect($consentRequest->audit_trail)->where('event', 'cancelled')->count())->toBe(1);
});

it('rejects a conflicting cancellation after another cancellation committed', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context);
    $firstCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $staleCopy = ConsentRequest::query()->findOrFail($consentRequest->id);
    $service = app(ConsentRequestService::class);

    $service->cancel($firstCopy, $context['staff'], 'Request sent in error.');

    $conflicted = false;
    try {
        $service->cancel($staleCopy, $context['staff'], 'Different cancellation reason.');
    } catch (ConflictHttpException) {
        $conflicted = true;
    }

    expect($conflicted)->toBeTrue()
        ->and($consentRequest->fresh()->cancellation_reason)->toBe('Request sent in error.');
});

it('makes repeated identical withdrawal idempotent without a second write', function () {
    $context = makeConsentIntegrityContext();
    $consent = ClientConsent::query()->create([
        'client_id' => $context['client']->id,
        'consent_type_id' => $context['consentType']->id,
        'status' => 'given',
        'given_at' => now(),
        'given_method' => 'written',
        'given_by_user_id' => $context['recipient']->id,
        'created_by' => $context['staff']->id,
    ]);
    $reason = 'Client withdrew after reviewing the implications.';

    $this->travelTo(Carbon::parse('2026-07-10 11:00:00'));
    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consents/{$consent->id}/withdraw",
            ['withdrawal_reason' => $reason],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $firstUpdatedAt = $consent->fresh()->updated_at->copy();

    $this->travelTo(Carbon::parse('2026-07-10 11:05:00'));
    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consents/{$consent->id}/withdraw",
            ['withdrawal_reason' => $reason],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $consent->refresh();
    $this->travelBack();

    expect($consent->updated_at->equalTo($firstUpdatedAt))->toBeTrue()
        ->and($consent->withdrawal_reason)->toBe($reason);
});

it('revalidates that the recipient remains linked to the request client', function () {
    $context = makeConsentIntegrityContext();
    $consentRequest = makeConsentIntegrityRequest($context);
    $context['client']->portalUsers()->detach($context['recipient']->id);

    $conflicted = false;
    try {
        app(ConsentRequestService::class)->approve(
            $consentRequest,
            $context['recipient'],
            consentIntegrityHttpRequest($context['recipient']),
        );
    } catch (ConflictHttpException) {
        $conflicted = true;
    }

    expect($conflicted)->toBeTrue()
        ->and($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('locks the portal authorization link while creating and responding', function () {
    $context = makeConsentIntegrityContext();
    $service = app(ConsentRequestService::class);
    $queries = [];
    $captureQueries = true;
    DB::listen(function ($query) use (&$captureQueries, &$queries): void {
        if (! $captureQueries) {
            return;
        }

        $queries[] = [
            'sql' => strtolower($query->sql),
            'transaction_level' => DB::transactionLevel(),
        ];
    });
    $payload = consentIntegrityPayload($context);
    unset($payload['expires_in_days']);
    $payload['client_id'] = $context['client']->id;

    $consentRequest = $service->create($payload, $context['staff'], '14');
    $creationQueries = $queries;
    $queries = [];

    $service->approve(
        $consentRequest,
        $context['recipient'],
        consentIntegrityHttpRequest($context['recipient']),
    );
    $responseQueries = $queries;
    $captureQueries = false;

    $hasLockedPortalRead = fn (array $captured): bool => collect($captured)->contains(
        fn (array $query): bool => str_contains($query['sql'], 'client_portal_users')
            && str_contains($query['sql'], 'for update')
            && $query['transaction_level'] > 0,
    );

    expect($hasLockedPortalRead($creationQueries))->toBeTrue()
        ->and($hasLockedPortalRead($responseQueries))->toBeTrue();
});

it('rejects a recipient linked only to another Client at a different Site on the direct approval path', function () {
    $context = makeConsentIntegrityContext();
    $recipient = User::query()->findOrFail($context['recipient']->id);
    $otherSite = Site::factory()->create();
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $context['client']->portalUsers()->detach($recipient->id);
    $otherClient->portalUsers()->attach($recipient->id, ['relation' => 'next_of_kin']);
    $consentRequest = makeConsentIntegrityRequest($context);

    $conflicted = false;
    try {
        app(ConsentRequestService::class)->approve(
            $consentRequest,
            $recipient,
            consentIntegrityHttpRequest($recipient),
        );
    } catch (ConflictHttpException) {
        $conflicted = true;
    }

    expect($conflicted)->toBeTrue()
        ->and($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('conceals foreign consent request and parent Client ids from portal recipients', function () {
    $context = makeConsentIntegrityContext();
    $foreign = makeConsentIntegrityContext();
    $ownRequest = makeConsentIntegrityRequest($context);
    $foreignRequest = makeConsentIntegrityRequest($foreign);

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$foreign['client']->id}/consent-requests/{$foreignRequest->id}/approve",
            ['acknowledge_authority' => '1'],
        )
        ->assertNotFound();

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$foreign['client']->id}/consent-requests/{$ownRequest->id}/approve",
            ['acknowledge_authority' => '1'],
        )
        ->assertNotFound();

    expect($ownRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and($foreignRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('rejects a Site-scoped requester on inaccessible direct create and cancel paths', function () {
    $context = makeConsentIntegrityContext();
    $scopedStaff = User::factory()->create(['role' => 'manager']);
    grantConsentIntegrityPermissions($scopedStaff, [
        'clients.viewAny',
        'consents.request',
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $scopedStaff->id,
        'primary_site_id' => $context['site']->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $scopedStaff->id,
        'updated_by' => $scopedStaff->id,
    ]);

    $otherSite = Site::factory()->create();
    $inaccessibleClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $portalRecipient = User::factory()->create();
    assignConsentIntegrityPortalRole($portalRecipient, 'next_of_kin');
    $inaccessibleClient->portalUsers()->attach($portalRecipient->id, ['relation' => 'next_of_kin']);
    $service = app(ConsentRequestService::class);
    $payload = consentIntegrityPayload($context, [
        'client_id' => $inaccessibleClient->id,
        'recipient_user_id' => $portalRecipient->id,
    ]);
    unset($payload['expires_in_days']);

    $createRejected = false;
    try {
        $service->create($payload, $scopedStaff);
    } catch (ValidationException) {
        $createRejected = true;
    }

    $inaccessibleRequest = ConsentRequest::factory()->create([
        'client_id' => $inaccessibleClient->id,
        'consent_type_id' => $context['consentType']->id,
        'requested_by_user_id' => $scopedStaff->id,
        'recipient_user_id' => $portalRecipient->id,
        'recipient_relationship' => ConsentRequest::RELATION_NEXT_OF_KIN,
    ]);
    $cancelRejected = false;
    try {
        $service->cancel($inaccessibleRequest, $scopedStaff, 'Cross-Site direct-object attempt.');
    } catch (ConflictHttpException) {
        $cancelRejected = true;
    }

    expect($createRejected)->toBeTrue()
        ->and($cancelRejected)->toBeTrue()
        ->and($inaccessibleRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING);
});

it('refuses to roll back verified authority columns while authority data is populated', function () {
    $context = makeConsentIntegrityContext();
    $authority = makeVerifiedConsentAuthority($context);
    makeConsentIntegrityRequest($context, [
        'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
        'authority_next_of_kin_id' => $authority->id,
    ]);
    $migration = require database_path(
        'migrations/2026_07_10_000003_add_verified_legal_authority_to_consent_workflow.php',
    );

    $blocked = false;
    $message = null;
    try {
        $migration->down();
    } catch (RuntimeException $exception) {
        $blocked = true;
        $message = $exception->getMessage();
    }

    if (! Schema::hasColumn('consent_requests', 'authority_next_of_kin_id')) {
        $migration->up();
    }

    expect($blocked)->toBeTrue()
        ->and($message)->toContain('verified legal authority')
        ->and(Schema::hasColumn('consent_requests', 'authority_next_of_kin_id'))->toBeTrue()
        ->and(Schema::hasColumn('next_of_kins', 'legal_authority_type'))->toBeTrue();
});

it('round trips verified authority columns when no authority data is populated', function () {
    $migration = require database_path(
        'migrations/2026_07_10_000003_add_verified_legal_authority_to_consent_workflow.php',
    );

    $migration->down();

    expect(Schema::hasColumn('consent_requests', 'authority_next_of_kin_id'))->toBeFalse()
        ->and(Schema::hasColumn('next_of_kins', 'legal_authority_type'))->toBeFalse()
        ->and(Schema::hasColumn('next_of_kins', 'legal_authority_verified_at'))->toBeFalse()
        ->and(Schema::hasColumn('next_of_kins', 'legal_authority_verified_by_user_id'))->toBeFalse()
        ->and(Schema::hasColumn('next_of_kins', 'legal_authority_expires_at'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('consent_requests', 'authority_next_of_kin_id'))->toBeTrue()
        ->and(Schema::hasColumn('next_of_kins', 'legal_authority_type'))->toBeTrue()
        ->and(Schema::hasColumn('next_of_kins', 'legal_authority_verified_at'))->toBeTrue()
        ->and(Schema::hasColumn('next_of_kins', 'legal_authority_verified_by_user_id'))->toBeTrue()
        ->and(Schema::hasColumn('next_of_kins', 'legal_authority_expires_at'))->toBeTrue();
});

function consentIntegrityStartApprovalWorker(
    string $database,
    int $consentRequestId,
    int $recipientId,
    string $readyPath,
    string $attemptPath,
    string $releasePath,
): Process {
    $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$recipient = App\Models\User::query()->findOrFail((int) $argv[3]);
$request = Illuminate\Http\Request::create('/portal/consent-request', 'POST', server: [
    'REMOTE_ADDR' => '127.0.0.1',
    'HTTP_USER_AGENT' => 'ConsentConcurrencyWorker',
]);
$request->setUserResolver(fn () => $recipient);
file_put_contents($argv[4], (string) Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id);
$deadline = microtime(true) + 15;
while (! is_file($argv[6])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the consent approval release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[5], 'attempting');
$consent = $app->make(App\Services\ConsentRequestService::class)->approve(
    App\Models\ConsentRequest::query()->findOrFail((int) $argv[2]),
    $recipient,
    $request,
    'The same decision-specific evidence supports this least restrictive option.',
);
echo json_encode(['consent_id' => $consent->id], JSON_THROW_ON_ERROR);
PHP;

    $process = new Process([
        PHP_BINARY,
        '-r',
        $worker,
        base_path(),
        (string) $consentRequestId,
        (string) $recipientId,
        $readyPath,
        $attemptPath,
        $releasePath,
    ], base_path(), [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => $database,
    ]);
    $process->setTimeout(30);
    $process->start();

    return $process;
}

/** @param array<int, string> $paths */
function consentIntegrityWaitForFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 15;
    while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(10_000);
    }
}
