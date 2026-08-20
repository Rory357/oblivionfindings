<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\NextOfKin;
use App\Models\User;
use App\Notifications\Operations\ConsentRequestCreatedNotification;
use App\Notifications\Operations\ConsentRequestReminderNotification;
use App\Notifications\Operations\ConsentRequestRespondedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * State-machine for ConsentRequest. All transitions go through here so the
 * audit trail, notifications, and ClientConsent side-effects stay in lockstep.
 */
class ConsentRequestService
{
    public function __construct(
        private readonly ConsentDecisionEvidenceService $decisionEvidence,
    ) {}

    /**
     * Staff creates a new consent request.
     *
     * @param  array<string, mixed>  $data  Keys: client_id, consent_type_id,
     *                                      recipient_user_id, recipient_relationship, purpose, and the
     *                                      optional Right-7 disclosure fields.
     */
    public function create(array $data, User $requester, ?string $expiresInDays = null): ConsentRequest
    {
        $expiresAt = now()->addDays((int) ($expiresInDays ?? 14));

        return DB::transaction(function () use ($data, $requester, $expiresAt) {
            $data = $this->validatedCreationData($data, $requester);

            $request = ConsentRequest::create(array_merge($data, [
                'requested_by_user_id' => $requester->id,
                'status' => ConsentRequest::STATUS_PENDING,
                'sent_at' => now(),
                'expires_at' => $expiresAt,
                'audit_trail' => [[
                    'event' => 'created',
                    'actor_id' => $requester->id,
                    'at' => now()->toIso8601String(),
                ]],
            ]));

            $request->recipient?->notify(new ConsentRequestCreatedNotification($request));

            return $request->fresh();
        });
    }

    /**
     * Recipient opens the detail page — record viewed_at on first view.
     */
    public function markViewed(ConsentRequest $request): void
    {
        DB::transaction(function () use ($request): void {
            $lockedRequest = $this->lockedRequest($request);

            if ($lockedRequest->viewed_at !== null) {
                return;
            }

            $lockedRequest->update([
                'viewed_at' => now(),
                'audit_trail' => $this->appendAudit(
                    $lockedRequest,
                    'viewed',
                    $lockedRequest->recipient_user_id,
                ),
            ]);
        });
    }

    /**
     * Recipient approves. Writes a ClientConsent row, links it back, notifies
     * the requester.
     *
     * @return ClientConsent The consent row that now authorises whatever the
     *                       request was asking about.
     */
    public function approve(
        ConsentRequest $request,
        User $recipient,
        Request $httpRequest,
        ?string $responseNotes = null,
    ): ClientConsent {
        return DB::transaction(function () use ($request, $recipient, $httpRequest, $responseNotes) {
            $lockedRequest = $this->lockedRequest($request);
            $lockedClient = $this->lockedClient($lockedRequest);
            $lockedRequest->setRelation('client', $lockedClient);
            $this->assertRecipientContext($lockedRequest, $recipient);

            if ($lockedRequest->status === ConsentRequest::STATUS_APPROVED) {
                if (
                    $lockedRequest->response_notes === $responseNotes
                    && $lockedRequest->resulting_consent_id !== null
                ) {
                    return ClientConsent::query()->findOrFail($lockedRequest->resulting_consent_id);
                }

                throw new ConflictHttpException('This consent request has already been approved with a different response.');
            }

            $this->assertActionableForDecision($lockedRequest);
            $authority = $this->assertBoundAuthorityStillValid($lockedRequest);
            $acceptedAt = CarbonImmutable::now();
            $lockedRequester = null;

            if ($authority !== null || $lockedRequest->triggering_subject_id !== null) {
                $lockedRequester = User::query()
                    ->lockForUpdate()
                    ->find($lockedRequest->requested_by_user_id);
                if (! $lockedRequester) {
                    throw new ConflictHttpException('Decision-specific capacity evidence is no longer current.');
                }
            }

            if ($lockedRequest->triggering_subject_id !== null) {
                $this->assertTriggeringSubjectCurrent($lockedRequest, $lockedRequester, $lockedClient);
            }

            if ($authority !== null) {
                $reason = trim((string) $responseNotes);
                if (mb_strlen($reason) < 10) {
                    throw ValidationException::withMessages([
                        'response_notes' => 'Record the substitute decision reason for this specific consent.',
                    ]);
                }

                $this->decisionEvidence->assertCurrent(
                    $lockedRequest,
                    $lockedRequester,
                    $recipient,
                    $lockedClient,
                    $authority,
                );
            }

            $consent = $this->materialiseClientConsent(
                $lockedRequest,
                $recipient,
                $responseNotes,
                $acceptedAt,
            );

            $lockedRequest->update([
                'status' => ConsentRequest::STATUS_APPROVED,
                'responded_at' => now(),
                'response_notes' => $responseNotes,
                'response_ip_address' => $httpRequest->ip(),
                'response_user_agent' => substr((string) $httpRequest->userAgent(), 0, 500),
                'resulting_consent_id' => $consent->id,
                'decision_evidence_accepted_by_user_id' => $authority ? $recipient->id : null,
                'decision_evidence_accepted_at' => $authority ? $acceptedAt : null,
                'audit_trail' => $this->appendAudit($lockedRequest, 'approved', $recipient->id, [
                    'resulting_consent_id' => $consent->id,
                ]),
            ]);

            $lockedRequest->requestedBy?->notify(new ConsentRequestRespondedNotification($lockedRequest->fresh(), 'approved'));

            return $consent;
        });
    }

    public function decline(
        ConsentRequest $request,
        User $recipient,
        Request $httpRequest,
        ?string $responseNotes = null,
    ): void {
        DB::transaction(function () use ($request, $recipient, $httpRequest, $responseNotes) {
            $lockedRequest = $this->lockedRequest($request);
            $lockedRequest->setRelation('client', $this->lockedClient($lockedRequest));
            $this->assertRecipientContext($lockedRequest, $recipient);

            if ($lockedRequest->status === ConsentRequest::STATUS_DECLINED) {
                if ($lockedRequest->response_notes === $responseNotes) {
                    return;
                }

                throw new ConflictHttpException('This consent request has already been declined with a different response.');
            }

            $this->assertActionableForDecision($lockedRequest);

            $lockedRequest->update([
                'status' => ConsentRequest::STATUS_DECLINED,
                'responded_at' => now(),
                'response_notes' => $responseNotes,
                'response_ip_address' => $httpRequest->ip(),
                'response_user_agent' => substr((string) $httpRequest->userAgent(), 0, 500),
                ...$this->revokedEvidence(
                    $lockedRequest,
                    $recipient->id,
                    'Substitute decision declined: '.trim((string) $responseNotes),
                ),
                'audit_trail' => $this->appendAudit($lockedRequest, 'declined', $recipient->id),
            ]);

            $lockedRequest->requestedBy?->notify(new ConsentRequestRespondedNotification($lockedRequest->fresh(), 'declined'));
        });
    }

    /**
     * Staff cancels a still-pending request (e.g. it was sent to the wrong
     * relative, or the clinical situation changed).
     */
    public function cancel(ConsentRequest $request, User $staff, string $reason): void
    {
        DB::transaction(function () use ($request, $staff, $reason) {
            $lockedRequest = $this->lockedRequest($request);
            $lockedRequest->setRelation('client', $this->lockedClient($lockedRequest));
            $this->assertStaffContext($lockedRequest, $staff);

            if ($lockedRequest->status === ConsentRequest::STATUS_CANCELLED) {
                if (
                    $lockedRequest->cancelled_by_user_id === $staff->id
                    && $lockedRequest->cancellation_reason === $reason
                ) {
                    return;
                }

                throw new ConflictHttpException('This consent request has already been cancelled with different details.');
            }

            if (! $lockedRequest->isPending()) {
                throw new ConflictHttpException('Only pending consent requests can be cancelled.');
            }

            $lockedRequest->update([
                'status' => ConsentRequest::STATUS_CANCELLED,
                'cancelled_by_user_id' => $staff->id,
                'cancellation_reason' => $reason,
                ...$this->revokedEvidence($lockedRequest, $staff->id, $reason),
                'audit_trail' => $this->appendAudit($lockedRequest, 'cancelled', $staff->id, [
                    'reason' => $reason,
                ]),
            ]);
        });
    }

    /**
     * Bulk-expire pending requests past their expires_at. Intended for a
     * scheduled console command. Returns the number expired.
     */
    public function expireStale(): int
    {
        $expired = 0;

        ConsentRequest::query()
            ->overdueForExpiry()
            ->lazy()
            ->each(function (ConsentRequest $request) use (&$expired) {
                $didExpire = DB::transaction(function () use ($request): bool {
                    $lockedRequest = ConsentRequest::query()
                        ->lockForUpdate()
                        ->find($request->getKey());

                    if (
                        ! $lockedRequest
                        || ! $lockedRequest->isPending()
                        || $lockedRequest->expires_at?->isFuture()
                    ) {
                        return false;
                    }

                    $lockedRequest->update([
                        'status' => ConsentRequest::STATUS_EXPIRED,
                        ...$this->revokedEvidence(
                            $lockedRequest,
                            null,
                            'Consent request expired before the decision was accepted.',
                        ),
                        'audit_trail' => $this->appendAudit($lockedRequest, 'expired', null),
                    ]);

                    return true;
                });

                if ($didExpire) {
                    $expired++;
                }
            });

        return $expired;
    }

    /**
     * Send a reminder to the recipient of a pending consent request and
     * append a `reminder_sent` event to the audit trail. Intended to be
     * called from the scheduled reminder command — idempotency (one
     * reminder per request) is enforced by the caller.
     */
    public function sendReminder(ConsentRequest $request): bool
    {
        return DB::transaction(function () use ($request): bool {
            $lockedRequest = ConsentRequest::query()
                ->with(['client', 'consentType', 'recipient'])
                ->lockForUpdate()
                ->find($request->getKey());

            if (! $lockedRequest || ! $lockedRequest->isActionable()) {
                return false;
            }

            $alreadySent = collect($lockedRequest->audit_trail ?? [])
                ->contains(fn (array $entry): bool => ($entry['event'] ?? null) === 'reminder_sent');

            if ($alreadySent) {
                return false;
            }

            $lockedRequest->recipient?->notify(new ConsentRequestReminderNotification($lockedRequest));

            $lockedRequest->update([
                'audit_trail' => $this->appendAudit($lockedRequest, 'reminder_sent', null),
            ]);

            return true;
        });
    }

    // ── internals ─────────────────────────────────────────────────

    private function materialiseClientConsent(
        ConsentRequest $request,
        User $recipient,
        ?string $responseNotes,
        CarbonImmutable $acceptedAt,
    ): ClientConsent {
        $consentType = $request->consentType;
        $expiresAt = $consentType?->validity_period_days
            ? now()->addDays($consentType->validity_period_days)
            : null;

        $isSubstituted = $request->authorityToConsent() === 'substitute';

        return ClientConsent::create([
            'client_id' => $request->client_id,
            'consent_type_id' => $request->consent_type_id,
            'consent_type_version_id' => $consentType?->currentVersion()->first()?->id,
            'consent_request_id' => $request->id,
            'decision_evidence_digest' => $isSubstituted ? $request->decision_scope_digest : null,
            'status' => 'given',
            'given_at' => $acceptedAt,
            'given_by_user_id' => $recipient->id,
            'given_by_relationship' => $request->recipient_relationship,
            'given_method' => 'electronic',
            'given_notes' => $responseNotes,
            'capacity_assessed' => $isSubstituted,
            'capacity_outcome' => $isSubstituted ? $request->capacity_outcome : null,
            'capacity_assessor_id' => $isSubstituted ? $request->capacity_assessor_user_id : null,
            'capacity_assessed_at' => $isSubstituted ? $request->capacity_assessed_at : null,
            'capacity_notes' => $isSubstituted ? $request->capacity_assessment_reason : null,
            'best_interests_decision' => $isSubstituted,
            'best_interests_decision_maker_id' => $isSubstituted ? $recipient->id : null,
            'best_interests_decision_at' => $isSubstituted ? $acceptedAt : null,
            'best_interests_rationale' => $isSubstituted ? sprintf(
                "%s\n\nRepresentative decision reason: %s",
                $request->best_interests_process_reason,
                trim((string) $responseNotes),
            ) : null,
            'best_interests_consultees' => $isSubstituted ? $request->best_interests_consultees : null,
            'evidence_type' => 'portal_signature',
            'conditions' => [
                'source' => 'family_portal',
                'consent_request_id' => $request->id,
                'authority_next_of_kin_id' => $isSubstituted ? $request->authority_next_of_kin_id : null,
                'data_scope' => $request->data_scope,
                'retention_period_days' => $request->retention_period_days,
                'purpose' => $request->purpose,
                'decision_evidence' => $isSubstituted
                    ? $this->decisionEvidence->provenance(
                        $request,
                        $recipient,
                        trim((string) $responseNotes),
                        $acceptedAt,
                    )
                    : null,
            ],
            'expires_at' => $expiresAt,
            'created_by' => $request->requested_by_user_id,
            'updated_by' => $recipient->id,
        ]);
    }

    private function assertActionableForDecision(ConsentRequest $request): void
    {
        if (! $request->isActionable()) {
            throw new ConflictHttpException(sprintf(
                'Consent request #%d is not actionable (status=%s).',
                $request->id,
                $request->status,
            ));
        }
    }

    private function assertRecipientContext(ConsentRequest $request, User $user): void
    {
        if ($request->recipient_user_id !== $user->id) {
            throw new ConflictHttpException('Only the designated recipient may respond to this request.');
        }

        $lockedUser = User::query()
            ->lockForUpdate()
            ->find($user->id);
        $client = $request->client;
        if (
            ! $lockedUser
            || ! $client
            || ! $lockedUser->canAccessClientPortal($client)
            || ! $client->portalUsers()
                ->whereKey($lockedUser->id)
                ->lockForUpdate()
                ->first()
        ) {
            throw new ConflictHttpException('The designated recipient is no longer linked to this client.');
        }
    }

    private function assertStaffContext(ConsentRequest $request, User $staff): void
    {
        $client = $request->client;
        if (! $client || ! $staff->can('view', $client)) {
            throw new ConflictHttpException('This consent request is not available to this staff member.');
        }
    }

    private function assertBoundAuthorityStillValid(ConsentRequest $request): ?NextOfKin
    {
        if (! in_array($request->recipient_relationship, ConsentRequest::AUTHORISED_SUBSTITUTE_RELATIONS, true)) {
            return null;
        }

        if ($request->authority_next_of_kin_id === null) {
            throw new ConflictHttpException('Verified substitute decision-making authority is no longer available.');
        }

        $authority = NextOfKin::query()
            ->lockForUpdate()
            ->find($request->authority_next_of_kin_id);

        if (
            ! $authority
            || $authority->client_id !== $request->client_id
            || $authority->user_id !== $request->recipient_user_id
            || ! $authority->hasVerifiedLegalAuthority($request->recipient_relationship)
        ) {
            throw new ConflictHttpException('Verified substitute decision-making authority is no longer valid.');
        }

        return $authority;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatedCreationData(array $data, User $requester): array
    {
        $client = Client::query()->lockForUpdate()->find($data['client_id'] ?? null);
        $recipient = User::query()->lockForUpdate()->find($data['recipient_user_id'] ?? null);

        if (! $client || ! $requester->can('view', $client)) {
            throw ValidationException::withMessages([
                'client_id' => 'The Client must be available to you.',
            ]);
        }

        $portalRecipient = $recipient && $recipient->canAccessClientPortal($client)
            ? $client->portalUsers()
                ->whereKey($recipient->id)
                ->lockForUpdate()
                ->first()
            : null;

        if (! $recipient || ! $portalRecipient) {
            throw ValidationException::withMessages([
                'recipient_user_id' => 'The recipient must be a family-portal user linked to this client.',
            ]);
        }

        $relationship = $data['recipient_relationship'] ?? null;
        $supportedRelationships = [
            ConsentRequest::RELATION_SELF,
            ConsentRequest::RELATION_NEXT_OF_KIN,
            ...ConsentRequest::AUTHORISED_SUBSTITUTE_RELATIONS,
        ];

        if (! in_array($relationship, $supportedRelationships, true)) {
            throw ValidationException::withMessages([
                'recipient_relationship' => 'Select a supported relationship for this consent request.',
            ]);
        }

        if (
            $relationship === ConsentRequest::RELATION_SELF
            && ! in_array(
                $portalRecipient->pivot?->relation,
                [ConsentRequest::RELATION_SELF, 'self'],
                true,
            )
        ) {
            throw ValidationException::withMessages([
                'recipient_relationship' => 'Self-consent requests must be sent to a portal account linked as the client.',
            ]);
        }

        if (
            $relationship === ConsentRequest::RELATION_NEXT_OF_KIN
            && $portalRecipient->pivot?->relation !== ConsentRequest::RELATION_NEXT_OF_KIN
        ) {
            throw ValidationException::withMessages([
                'recipient_relationship' => 'The relationship must match the recipient\'s current Client link.',
            ]);
        }

        $data['authority_next_of_kin_id'] = null;
        $evidence = [];
        $this->bindTriggeringSubject($data, $requester, $client);

        if (in_array($relationship, ConsentRequest::AUTHORISED_SUBSTITUTE_RELATIONS, true)) {
            $authority = NextOfKin::query()
                ->where('client_id', $client->id)
                ->where('user_id', $recipient->id)
                ->where('legal_authority_type', $relationship)
                ->lockForUpdate()
                ->get()
                ->first(fn (NextOfKin $nextOfKin) => $nextOfKin->hasVerifiedLegalAuthority($relationship));

            if (! $authority) {
                throw ValidationException::withMessages([
                    'recipient_relationship' => 'Verified, current legal authority is required for substituted consent.',
                ]);
            }

            $data['recipient_relationship'] = $authority->legal_authority_type;
            $data['authority_next_of_kin_id'] = $authority->id;
            $evidence = $this->decisionEvidence->capture(
                $data,
                $requester,
                $client,
                $recipient,
                $authority,
            );

            if (ConsentRequest::query()->where('decision_scope_digest', $evidence['decision_scope_digest'])->exists()) {
                throw ValidationException::withMessages([
                    'capacity_assessment' => 'This decision-specific evidence is already bound to a consent request.',
                ]);
            }
        } elseif ($this->containsDecisionEvidence($data)) {
            throw ValidationException::withMessages([
                'capacity_assessment' => 'Capacity and best-interests evidence applies only to a verified substituted-consent request.',
            ]);
        } else {
            $data['recipient_relationship'] = $relationship === ConsentRequest::RELATION_SELF
                ? ConsentRequest::RELATION_SELF
                : ConsentRequest::RELATION_NEXT_OF_KIN;
        }

        return [
            ...Arr::only($data, [
                'client_id',
                'consent_type_id',
                'recipient_user_id',
                'recipient_relationship',
                'authority_next_of_kin_id',
                'triggering_subject_type',
                'triggering_subject_id',
                'purpose',
                'least_restrictive_justification',
                'data_scope',
                'retention_period_days',
                'withdrawal_method_text',
                'staff_notes',
            ]),
            ...$evidence,
        ];
    }

    private function lockedRequest(ConsentRequest $request): ConsentRequest
    {
        return ConsentRequest::query()
            ->with(['client', 'consentType', 'requestedBy'])
            ->lockForUpdate()
            ->findOrFail($request->getKey());
    }

    private function lockedClient(ConsentRequest $request): Client
    {
        $client = Client::query()->lockForUpdate()->find($request->client_id);
        if (! $client) {
            throw new ConflictHttpException('This consent request is no longer available.');
        }

        return $client;
    }

    /** @param array<string, mixed> $data */
    private function bindTriggeringSubject(array &$data, User $requester, Client $client): void
    {
        $type = $data['triggering_subject_type'] ?? null;
        $id = $data['triggering_subject_id'] ?? null;

        if (! filled($type) && ! filled($id)) {
            $data['triggering_subject_type'] = null;
            $data['triggering_subject_id'] = null;

            return;
        }

        if (! is_string($type) || ! filled($id)) {
            $this->invalidTriggeringSubject();
        }

        $modelClass = $this->triggeringSubjectModelClass($type);
        if ($modelClass === null) {
            $this->invalidTriggeringSubject();
        }

        /** @var Model|null $subject */
        $subject = $modelClass::query()->lockForUpdate()->find($id);
        $this->assertTriggeringSubjectProvenance($subject, $requester, $client);

        $data['triggering_subject_type'] = $subject->getMorphClass();
        $data['triggering_subject_id'] = (int) $subject->getKey();
    }

    private function assertTriggeringSubjectCurrent(
        ConsentRequest $request,
        User $requester,
        Client $client,
    ): void {
        $modelClass = is_string($request->triggering_subject_type)
            ? $this->triggeringSubjectModelClass($request->triggering_subject_type)
            : null;
        if ($modelClass === null) {
            throw new ConflictHttpException('The consent source record is no longer available.');
        }

        /** @var Model|null $subject */
        $subject = $modelClass::query()
            ->lockForUpdate()
            ->find($request->triggering_subject_id);

        try {
            $this->assertTriggeringSubjectProvenance($subject, $requester, $client);
        } catch (ValidationException) {
            throw new ConflictHttpException('The consent source record is no longer available.');
        }
    }

    private function assertTriggeringSubjectProvenance(
        ?Model $subject,
        User $requester,
        Client $client,
    ): void {
        $subjectClientId = $subject instanceof Client
            ? $subject->getKey()
            : $subject?->getAttribute('client_id');
        $subjectSiteId = $subject instanceof Client
            ? $subject->site_id
            : $subject?->getAttribute('site_id');

        if (
            ! $subject
            || ! is_numeric($subjectClientId)
            || (int) $subjectClientId !== (int) $client->id
            || ! is_numeric($client->site_id)
            || (is_numeric($subjectSiteId) && (int) $subjectSiteId !== (int) $client->site_id)
            || ! $requester->can('view', $subject)
        ) {
            $this->invalidTriggeringSubject();
        }
    }

    private function invalidTriggeringSubject(): never
    {
        throw ValidationException::withMessages([
            'triggering_subject_id' => 'The consent source record is not available for this Client.',
        ]);
    }

    /** @return class-string<Model>|null */
    private function triggeringSubjectModelClass(string $type): ?string
    {
        $modelClass = Relation::getMorphedModel($type) ?? $type;

        return is_subclass_of($modelClass, Model::class) ? $modelClass : null;
    }

    /** @param array<string, mixed> $data */
    private function containsDecisionEvidence(array $data): bool
    {
        foreach ([
            'capacity_outcome',
            'capacity_assessed_at',
            'capacity_assessment_expires_at',
            'capacity_assessment_reason',
            'capacity_evidence_type',
            'capacity_evidence_reference',
            'best_interests_process_reason',
            'best_interests_evidence_type',
            'best_interests_evidence_reference',
            'best_interests_consultees',
        ] as $key) {
            if (filled($data[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function revokedEvidence(ConsentRequest $request, ?int $actorId, string $reason): array
    {
        if ($request->decision_scope_digest === null || $request->decision_evidence_revoked_at !== null) {
            return [];
        }

        return [
            'decision_evidence_revoked_by_user_id' => $actorId,
            'decision_evidence_revoked_at' => now(),
            'decision_evidence_revocation_reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<int, array<string, mixed>>
     */
    private function appendAudit(ConsentRequest $request, string $event, ?int $actorId, array $meta = []): array
    {
        $trail = is_array($request->audit_trail) ? $request->audit_trail : [];

        $trail[] = array_filter([
            'event' => $event,
            'actor_id' => $actorId,
            'at' => now()->toIso8601String(),
            'meta' => $meta ?: null,
        ], fn ($v) => $v !== null);

        return $trail;
    }
}
