<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentAuthorityScope;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\Operations\ConsentRequestReminderNotification;
use App\Notifications\Operations\ConsentRequestRespondedNotification;
use App\Services\ConsentRequestService;
use App\Services\Consents\ConsentAuthorityScopeService;
use App\Services\ConsentValidationService;
use App\Services\Portal\PortalClientSectionAccess;
use Carbon\Carbon;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\AuthoritativeConsentFixture;

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

    $consentType = ConsentType::factory()->create([
        'active' => true,
        'purpose' => 'Authorise the documented support intervention.',
    ]);
    $consentTypeVersion = ConsentTypeVersion::query()->create([
        'consent_type_id' => $consentType->id,
        'version' => $consentType->version,
        'description' => $consentType->description,
        'purpose' => $consentType->purpose,
        'legal_basis' => $consentType->legal_basis,
        'effective_from' => now()->subDay(),
        'created_by' => $staff->id,
    ]);

    return [
        'staff' => $staff,
        'recipient' => $recipient,
        'client' => $client,
        'site' => $site,
        'consentType' => $consentType,
        'consentTypeVersion' => $consentTypeVersion,
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
        'site_id' => $context['site']->id,
        'consent_type_id' => $context['consentType']->id,
        'consent_type_version_id' => $context['consentTypeVersion']->id,
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

function makeConsentIntegritySelfContext(): array
{
    $context = makeConsentIntegrityContext();
    assignConsentIntegrityPortalRole($context['recipient'], 'client');
    $context['client']->update(['user_id' => $context['recipient']->id]);
    $context['client']->portalUsers()->detach($context['recipient']->id);
    $context['client']->portalUsers()->attach($context['recipient']->id, ['relation' => 'self']);

    return $context;
}

/** @param array{staff: User, recipient: User, client: Client, site: Site, consentType: ConsentType} $context */
function makeVerifiedConsentAuthority(
    array $context,
    string $authorityType = ConsentRequest::RELATION_WELFARE_GUARDIAN,
    ?Carbon $expiresAt = null,
): NextOfKin {
    $authority = NextOfKin::query()->create([
        'user_id' => $context['recipient']->id,
        'client_id' => $context['client']->id,
        'relationship' => 'guardian',
        'legal_authority_type' => $authorityType,
        'legal_authority_verified_at' => now()->subDay(),
        'legal_authority_verified_by_user_id' => $context['staff']->id,
        'legal_authority_expires_at' => $expiresAt ?? now()->addYear(),
    ]);

    $capacity = ClientConsent::query()->create([
        'client_id' => $context['client']->id,
        'site_id' => $context['site']->id,
        'consent_type_id' => $context['consentType']->id,
        'consent_type_version_id' => $context['consentTypeVersion']->id,
        'status' => 'given',
        'given_at' => now()->subDay(),
        'given_by_user_id' => $context['staff']->id,
        'given_method' => 'written',
        'capacity_assessed' => true,
        'capacity_outcome' => 'lacks_capacity',
        'capacity_assessor_id' => $context['staff']->id,
        'capacity_assessed_at' => now()->subDay(),
        'capacity_notes' => 'Capacity evidence retained for the scoped decision.',
        'expires_at' => $expiresAt ?? now()->addYear(),
        'created_by' => $context['staff']->id,
    ]);
    ConsentAuthorityScope::query()->create([
        'next_of_kin_id' => $authority->id,
        'client_id' => $context['client']->id,
        'site_id' => $context['site']->id,
        'representative_user_id' => $context['recipient']->id,
        'consent_type_id' => $context['consentType']->id,
        'authority_type' => $authorityType,
        'purpose' => consentIntegrityPayload($context)['purpose'],
        'version' => 1,
        'valid_from' => now()->subDay(),
        'expires_at' => $expiresAt ?? now()->addYear(),
        'verified_at' => now()->subDay(),
        'verified_by_user_id' => $context['staff']->id,
        'capacity_evidence_consent_id' => $capacity->id,
        'evidence_reference' => 'governance-test-record',
        'evidence_snapshot' => [
            'authority' => [
                'next_of_kin_id' => $authority->id,
                'legal_authority_type' => $authority->legal_authority_type,
                'verified_at' => $authority->legal_authority_verified_at?->toISOString(),
                'verified_by_user_id' => $authority->legal_authority_verified_by_user_id,
                'expires_at' => $authority->legal_authority_expires_at?->toISOString(),
            ],
            'capacity' => [
                'client_consent_id' => $capacity->id,
                'outcome' => $capacity->capacity_outcome,
                'assessor_user_id' => $capacity->capacity_assessor_id,
                'assessed_at' => $capacity->capacity_assessed_at?->toISOString(),
            ],
            'governance_decision' => 'explicit_test_fixture_only',
            'legal_or_clinical_determination' => 'not_made_by_consent_workflow',
        ],
    ]);

    return $authority;
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
            ]),
        )
        ->assertSessionHasErrors('recipient_relationship');

    expect(ConsentRequest::query()->exists())->toBeFalse();
});

it('binds verified substitute authority and materialises capacity fields from that record', function () {
    $context = makeConsentIntegrityContext();
    $authority = makeVerifiedConsentAuthority($context);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
            ]),
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $consentRequest = ConsentRequest::query()->sole();
    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            [
                'response_notes' => 'Approved under the verified welfare authority.',
                'acknowledge_authority' => '1',
            ],
        )
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $consentRequest->refresh();
    $consent = ClientConsent::query()->findOrFail($consentRequest->resulting_consent_id);

    expect($consentRequest->authority_next_of_kin_id)->toBe($authority->id)
        ->and($consentRequest->authorityToConsent())->toBe('substitute')
        ->and($consentRequest->authority_scope_id)->not->toBeNull()
        ->and($consentRequest->capacity_evidence_consent_id)->not->toBeNull()
        ->and($consent->capacity_assessed)->toBeTrue()
        ->and($consent->capacity_outcome)->toBe('lacks_capacity')
        ->and($consent->best_interests_decision)->toBeFalse()
        ->and($consent->best_interests_decision_maker_id)->toBeNull()
        ->and($consent->conditions['authority_next_of_kin_id'] ?? null)->toBe($authority->id)
        ->and($consent->decision_state)->toBe(ClientConsent::DECISION_AUTHORITATIVE)
        ->and($consent->gate_satisfying)->toBeTrue();
});

it('rejects verified authority when its explicit type does not match the request', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context, ConsentRequest::RELATION_COURT_APPOINTED);

    $this->actingAs($context['staff'])
        ->post(
            "/operations/clients/{$context['client']->id}/consent-requests",
            consentIntegrityPayload($context, [
                'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
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
            ]),
        )
        ->assertRedirect();
    $consentRequest = ConsentRequest::query()->sole();
    $authority->update(['legal_authority_expires_at' => now()->subMinute()]);

    $this->actingAs($context['recipient'])
        ->post(
            "/portal/clients/{$context['client']->id}/consent-requests/{$consentRequest->id}/approve",
            [
                'acknowledge_authority' => '1',
            ],
        )
        ->assertStatus(409);

    expect($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->whereNotNull('source_consent_request_id')->exists())->toBeFalse();
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

    expect($consentRequest->fresh()->authorityToConsent())->toBe('informational_only')
        ->and($consentRequest->fresh()->decision_kind)->toBe(ConsentRequest::DECISION_INFORMATIONAL)
        ->and($consentRequest->fresh()->resulting_consent_id)->toBeNull()
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('keeps canonical next-of-kin provisioning separate from portal membership and decision authority', function () {
    $context = makeConsentIntegrityContext();
    $portalRole = Role::query()->firstOrCreate(
        ['name' => 'next_of_kin'],
        ['label' => 'Next of Kin / Guardian (Portal)', 'level' => 15, 'type' => 'system'],
    );
    $provisionedRecipient = User::factory()->create([
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    $provisionedRecipient->roles()->sync([$portalRole->id]);
    $relationship = NextOfKin::query()->create([
        'user_id' => $provisionedRecipient->id,
        'client_id' => $context['client']->id,
        'relationship' => 'guardian',
    ]);
    $payload = consentIntegrityPayload($context, [
        'recipient_user_id' => $provisionedRecipient->id,
    ]);
    unset($payload['expires_in_days']);
    $payload['client_id'] = $context['client']->id;
    $service = app(ConsentRequestService::class);

    $rejectedWithoutMembership = false;
    try {
        $service->create($payload, $context['staff'], '14');
    } catch (ValidationException) {
        $rejectedWithoutMembership = true;
    }

    expect($rejectedWithoutMembership)->toBeTrue()
        ->and(ConsentRequest::query()->exists())->toBeFalse();

    $context['client']->portalUsers()->attach($provisionedRecipient->id, [
        'relation' => ConsentRequest::RELATION_NEXT_OF_KIN,
    ]);
    $clientRole = Role::query()->firstOrCreate(
        ['name' => 'client'],
        ['label' => 'Client Portal', 'level' => 10, 'type' => 'system'],
    );
    $provisionedRecipient->roles()->sync([$clientRole->id]);
    $rejectedWithWrongRole = false;
    try {
        $service->create($payload, $context['staff'], '14');
    } catch (ValidationException) {
        $rejectedWithWrongRole = true;
    }

    expect($rejectedWithWrongRole)->toBeTrue()
        ->and(ConsentRequest::query()->exists())->toBeFalse();

    $provisionedRecipient->roles()->sync([$portalRole->id]);
    $consentRequest = $service->create($payload, $context['staff'], '14');
    $provisionedRecipient->roles()->sync([$clientRole->id]);
    $rejectedAfterRoleDrift = false;
    try {
        $service->approve(
            $consentRequest,
            $provisionedRecipient,
            consentIntegrityHttpRequest($provisionedRecipient),
        );
    } catch (ConflictHttpException) {
        $rejectedAfterRoleDrift = true;
    }

    expect($rejectedAfterRoleDrift)->toBeTrue()
        ->and($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->exists())->toBeFalse();

    $provisionedRecipient->roles()->sync([$portalRole->id]);
    $result = $service->approve(
        $consentRequest,
        $provisionedRecipient,
        consentIntegrityHttpRequest($provisionedRecipient),
    );
    $consentRequest->refresh();

    expect($result)->toBeNull()
        ->and($consentRequest->decision_kind)->toBe(ConsentRequest::DECISION_INFORMATIONAL)
        ->and($consentRequest->authority_next_of_kin_id)->toBeNull()
        ->and($consentRequest->authority_scope_id)->toBeNull()
        ->and($relationship->legal_authority_type)->toBeNull()
        ->and(ClientConsent::query()->exists())->toBeFalse();
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
        ->and($consent->best_interests_decision)->toBeFalse()
        ->and(ConsentValidationService::isConsumable(
            $consent,
            $context['client'],
            $context['consentType']->id,
            $context['consentTypeVersion']->purpose,
        ))->toBeTrue();
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
    $context = makeConsentIntegritySelfContext();
    $consentRequest = makeConsentIntegrityRequest($context, [
        'recipient_relationship' => ConsentRequest::RELATION_SELF,
    ]);
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
    $context = makeConsentIntegritySelfContext();
    $consentRequest = makeConsentIntegrityRequest($context, [
        'recipient_relationship' => ConsentRequest::RELATION_SELF,
    ]);
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
    $consent = AuthoritativeConsentFixture::manualSelf(
        $context['client'],
        $context['consentType'],
        $context['staff'],
        [
            'given_at' => now(),
        ],
    );
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

it('rejects a Site-scoped requester on inaccessible direct create and cancel paths', function () {
    $context = makeConsentIntegrityContext();
    $scopedStaff = User::factory()->create(['role' => 'manager']);
    grantConsentIntegrityPermissions($scopedStaff, [
        'clients.viewAssigned',
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

/** @return array{context: array, request: ConsentRequest, consent: ClientConsent, scope: ConsentAuthorityScope} */
function makeConsumableSubstituteDecision(): array
{
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);
    $payload = consentIntegrityPayload($context, [
        'client_id' => $context['client']->id,
        'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
    ]);
    unset($payload['expires_in_days']);

    $request = app(ConsentRequestService::class)->create($payload, $context['staff'], '14');
    $consent = app(ConsentRequestService::class)->approve(
        $request,
        $context['recipient'],
        consentIntegrityHttpRequest($context['recipient']),
        'Approved under the scoped governance evidence.',
    );

    expect($consent)->toBeInstanceOf(ClientConsent::class);

    return [
        'context' => $context,
        'request' => $request->fresh(),
        'consent' => $consent->fresh(),
        'scope' => ConsentAuthorityScope::query()->findOrFail($request->authority_scope_id),
    ];
}

it('consumes one fully bound substitute decision through the canonical authority contract', function () {
    ['context' => $context, 'consent' => $consent] = makeConsumableSubstituteDecision();

    expect(ConsentValidationService::consumabilityDecision(
        $consent,
        $context['client'],
        $context['consentType']->id,
        $context['consentTypeVersion']->purpose,
    )->allowed)->toBeTrue();
});

it('fails the authority type and downstream matrix closed', function (string $case) {
    ['context' => $context, 'consent' => $consent, 'scope' => $scope] = makeConsumableSubstituteDecision();

    match ($case) {
        'expired authority' => $scope->update(['expires_at' => now()->subMinute()]),
        'revoked authority' => $scope->update([
            'revoked_at' => now(),
            'revoked_by_user_id' => $context['staff']->id,
            'revocation_reason' => 'Governance evidence withdrawn.',
        ]),
        'wrong purpose' => $scope->update(['purpose' => 'A different scoped purpose.']),
        'wrong type' => $consent->update([
            'consent_type_id' => ConsentType::factory()->create()->id,
        ]),
        'wrong person' => $consent->update([
            'decision_actor_user_id' => User::factory()->create()->id,
        ]),
        'wrong site' => $consent->update([
            'site_id' => Site::factory()->create()->id,
        ]),
        'stale authority version' => $scope->update(['version' => 2]),
        'authority record reverified' => $scope->nextOfKin()->update([
            'legal_authority_verified_at' => now()->subHour(),
        ]),
        'expired capacity evidence' => $scope->capacityEvidenceConsent()->update([
            'expires_at' => now()->subMinute(),
        ]),
        'informational capacity evidence' => $scope->capacityEvidenceConsent()->update([
            'decision_state' => ClientConsent::DECISION_INFORMATIONAL,
        ]),
    };

    $decision = ConsentValidationService::consumabilityDecision(
        $consent->fresh(),
        $context['client']->fresh(),
        $context['consentType']->id,
        $context['consentTypeVersion']->purpose,
    );

    expect($decision->allowed)->toBeFalse();
})->with([
    'expired authority',
    'revoked authority',
    'wrong purpose',
    'wrong type',
    'wrong person',
    'wrong site',
    'stale authority version',
    'authority record reverified',
    'expired capacity evidence',
    'informational capacity evidence',
]);

it('revokes scoped authority once and disables every derived authoritative decision', function () {
    ['context' => $context, 'consent' => $consent, 'scope' => $scope] = makeConsumableSubstituteDecision();
    $service = app(ConsentAuthorityScopeService::class);
    $reason = 'Governance evidence was withdrawn.';

    $service->revoke($scope, $context['staff'], $reason);
    $firstUpdatedAt = $scope->fresh()->updated_at->copy();
    $service->revoke($scope, $context['staff'], $reason);

    expect($scope->fresh()->updated_at->equalTo($firstUpdatedAt))->toBeTrue()
        ->and($scope->fresh()->revoked_by_user_id)->toBe($context['staff']->id)
        ->and($scope->fresh()->revocation_reason)->toBe($reason)
        ->and($consent->fresh()->status)->toBe('revoked')
        ->and($consent->fresh()->gate_satisfying)->toBeFalse()
        ->and(ConsentValidationService::isConsumable($consent->fresh(), $context['client']))->toBeFalse();
});

it('rolls approval back when scoped capacity evidence becomes stale under the lock', function () {
    $context = makeConsentIntegrityContext();
    makeVerifiedConsentAuthority($context);
    $payload = consentIntegrityPayload($context, [
        'client_id' => $context['client']->id,
        'recipient_relationship' => ConsentRequest::RELATION_WELFARE_GUARDIAN,
    ]);
    unset($payload['expires_in_days']);
    $request = app(ConsentRequestService::class)->create($payload, $context['staff'], '14');
    $request->capacityEvidenceConsent()->update(['expires_at' => now()->subMinute()]);

    expect(fn () => app(ConsentRequestService::class)->approve(
        $request,
        $context['recipient'],
        consentIntegrityHttpRequest($context['recipient']),
    ))->toThrow(ConflictHttpException::class);

    expect($request->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->where('source_consent_request_id', $request->id)->exists())->toBeFalse();
});

it('denies an informational consent-shaped legacy row to portal disclosure', function () {
    $context = makeConsentIntegrityContext();
    $familyType = ConsentType::factory()->create([
        'name' => 'Information Sharing with Whānau / Family',
        'category' => 'communication',
        'purpose' => 'Share agreed care information with family.',
        'requires_capacity_assessment' => false,
    ]);
    $version = ConsentTypeVersion::query()->create([
        'consent_type_id' => $familyType->id,
        'version' => $familyType->version,
        'description' => $familyType->description,
        'purpose' => $familyType->purpose,
        'legal_basis' => $familyType->legal_basis,
        'effective_from' => now()->subDay(),
    ]);
    ClientConsent::query()->create([
        'client_id' => $context['client']->id,
        'site_id' => $context['site']->id,
        'consent_type_id' => $familyType->id,
        'consent_type_version_id' => $version->id,
        'decision_state' => ClientConsent::DECISION_INFORMATIONAL,
        'decision_basis' => 'informational_only',
        'decision_client_id' => $context['client']->id,
        'decision_actor_user_id' => $context['recipient']->id,
        'decision_purpose' => $version->purpose,
        'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
        'gate_satisfying' => false,
        'status' => 'given',
        'given_at' => now(),
        'given_by_user_id' => $context['recipient']->id,
        'given_method' => 'electronic',
    ]);

    expect(app(PortalClientSectionAccess::class)
        ->hasActiveFamilyInformationConsent($context['client']))->toBeFalse();
});

it('routes every authoritative consent consumer through the canonical decision contract', function () {
    $directConsumers = [
        'app/Models/ClientConsent.php',
        'app/Services/Portal/PortalClientSectionAccess.php',
        'app/Domain/SecurityDevices/Models/DeviceAssignment.php',
        'app/Domain/SecurityDevices/Services/DeviceAssignmentService.php',
        'app/Domain/SecurityDevices/Services/PersonalTrackingPrivacyService.php',
        'app/Domain/SecurityDevices/Http/Controllers/DeviceAssignmentController.php',
        'app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php',
        'app/Http/Controllers/FleetAssets/DeviceController.php',
        'app/Http/Controllers/FleetAssets/ResidentTrackingController.php',
        'app/Services/Fleet/FleetDeviceRuntimeService.php',
        'app/Services/Clients/ClientPersonalAssetTrackerService.php',
        'app/Http/Controllers/ClientPersonalAssetController.php',
    ];
    $delegatingConsumers = [
        'app/Domain/SecurityDevices/Presenters/TrackingWorkspacePresenter.php',
        'app/Services/ControlRoom/ControlRoomDeviceVisibilityService.php',
    ];

    foreach ($directConsumers as $path) {
        expect(file_get_contents(base_path($path)))
            ->toContain('ConsentValidationService');
    }
    foreach ($delegatingConsumers as $path) {
        expect(file_get_contents(base_path($path)))
            ->toContain('PersonalTrackingPrivacyService');
    }

    expect(file_get_contents(base_path('app/Http/Controllers/FleetAssets/DeviceController.php')))
        ->not->toContain('ClientConsent::create');
});
