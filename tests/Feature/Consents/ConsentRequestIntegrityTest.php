<?php

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Operations\ConsentRequestReminderNotification;
use App\Notifications\Operations\ConsentRequestRespondedNotification;
use App\Services\ConsentRequestService;
use Carbon\Carbon;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

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

/**
 * @return array{staff: User, recipient: User, client: Client, consentType: ConsentType}
 */
function makeConsentIntegrityContext(): array
{
    $staff = User::factory()->create([
        'organization_id' => 1,
        'role' => 'manager',
    ]);
    grantConsentIntegrityPermissions($staff, [
        'clients.viewAny',
        'consents.request',
        'consents.withdraw',
    ]);
    $recipient = User::factory()->create(['organization_id' => 1]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $client->portalUsers()->attach($recipient->id, ['relation' => 'next_of_kin']);

    return [
        'staff' => $staff,
        'recipient' => $recipient,
        'client' => $client,
        'consentType' => ConsentType::factory()->create(['active' => true]),
    ];
}

/** @param array{staff: User, recipient: User, client: Client, consentType: ConsentType} $context */
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

/** @param array{staff: User, recipient: User, client: Client, consentType: ConsentType} $context */
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

/** @param array{staff: User, recipient: User, client: Client, consentType: ConsentType} $context */
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
        ->and($consent->capacity_assessed)->toBeTrue()
        ->and($consent->capacity_outcome)->toBe('lacks_capacity')
        ->and($consent->best_interests_decision)->toBeTrue()
        ->and($consent->best_interests_decision_maker_id)->toBe($context['recipient']->id)
        ->and($consent->conditions['authority_next_of_kin_id'] ?? null)->toBe($authority->id);
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
        ->and(ClientConsent::query()->exists())->toBeFalse();
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

it('rejects a cross-organisation recipient on the direct approval path', function () {
    $context = makeConsentIntegrityContext();
    $staleRecipient = User::query()->findOrFail($context['recipient']->id);
    $context['recipient']->update(['organization_id' => 2]);
    $consentRequest = makeConsentIntegrityRequest($context);

    $conflicted = false;
    try {
        app(ConsentRequestService::class)->approve(
            $consentRequest,
            $staleRecipient,
            consentIntegrityHttpRequest($staleRecipient),
        );
    } catch (ConflictHttpException) {
        $conflicted = true;
    }

    expect($conflicted)->toBeTrue()
        ->and($consentRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING)
        ->and(ClientConsent::query()->exists())->toBeFalse();
});

it('rejects a cross-organisation requester on the direct create and cancel paths', function () {
    $context = makeConsentIntegrityContext();
    $foreignClient = Client::factory()->create(['organization_id' => 2]);
    $foreignRecipient = User::factory()->create(['organization_id' => 2]);
    $foreignClient->portalUsers()->attach($foreignRecipient->id, ['relation' => 'next_of_kin']);
    $service = app(ConsentRequestService::class);
    $payload = consentIntegrityPayload($context, [
        'client_id' => $foreignClient->id,
        'recipient_user_id' => $foreignRecipient->id,
    ]);
    unset($payload['expires_in_days']);

    $createRejected = false;
    try {
        $service->create($payload, $context['staff']);
    } catch (ValidationException) {
        $createRejected = true;
    }

    $foreignRequest = ConsentRequest::factory()->create([
        'client_id' => $foreignClient->id,
        'consent_type_id' => $context['consentType']->id,
        'requested_by_user_id' => $context['staff']->id,
        'recipient_user_id' => $foreignRecipient->id,
        'recipient_relationship' => ConsentRequest::RELATION_NEXT_OF_KIN,
    ]);
    $cancelRejected = false;
    try {
        $service->cancel($foreignRequest, $context['staff'], 'Cross-organisation attempt.');
    } catch (ConflictHttpException) {
        $cancelRejected = true;
    }

    expect($createRejected)->toBeTrue()
        ->and($cancelRejected)->toBeTrue()
        ->and($foreignRequest->fresh()->status)->toBe(ConsentRequest::STATUS_PENDING);
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
