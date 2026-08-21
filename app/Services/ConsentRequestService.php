<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentAuthorityScope;
use App\Models\ConsentRequest;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
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
use Illuminate\Support\Str;
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
                    'prior_status' => null,
                    'source' => 'operations',
                    'authority_basis' => $this->authorityBasisFromData($data),
                    'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
                    'meta' => [
                        'client_id' => $data['client_id'],
                        'site_id' => $data['site_id'],
                        'consent_type_id' => $data['consent_type_id'],
                        'consent_type_version_id' => $data['consent_type_version_id'],
                        'recipient_user_id' => $data['recipient_user_id'],
                        'authority_next_of_kin_id' => $data['authority_next_of_kin_id'] ?? null,
                        'authority_scope_id' => $data['authority_scope_id'] ?? null,
                        'capacity_evidence_consent_id' => $data['capacity_evidence_consent_id'] ?? null,
                    ],
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
     * Informational acknowledgements intentionally return null and never
     * materialise a ClientConsent row.
     */
    public function approve(
        ConsentRequest $request,
        User $recipient,
        Request $httpRequest,
        ?string $responseNotes = null,
    ): ?ClientConsent {
        return DB::transaction(function () use ($request, $recipient, $httpRequest, $responseNotes) {
            $lockedRequest = $this->lockedRequest($request);
            $lockedClient = $this->lockedClient($lockedRequest);
            $lockedRequest->setRelation('client', $lockedClient);
            $this->assertRecipientContext($lockedRequest, $recipient);

            if ($lockedRequest->status === ConsentRequest::STATUS_APPROVED) {
                if ($lockedRequest->response_notes === $responseNotes) {
                    if ($lockedRequest->decision_kind === ConsentRequest::DECISION_INFORMATIONAL
                        && $lockedRequest->resulting_consent_id === null) {
                        return null;
                    }

                    if ($lockedRequest->decision_kind === ConsentRequest::DECISION_AUTHORITATIVE
                        && $lockedRequest->resulting_consent_id !== null) {
                        return ClientConsent::query()->findOrFail($lockedRequest->resulting_consent_id);
                    }
                }

                throw new ConflictHttpException('This consent request has already been approved with a different response.');
            }

            $this->assertActionableForDecision($lockedRequest);
            $this->assertRequestBindingStillValid($lockedRequest);

            $authorityBasis = $lockedRequest->authorityToConsent();
            if ($authorityBasis === 'informational_only') {
                $evidence = $this->decisionEvidence($lockedRequest, $recipient, $authorityBasis);
                $lockedRequest->update([
                    'status' => ConsentRequest::STATUS_APPROVED,
                    'responded_at' => now(),
                    'response_notes' => $responseNotes,
                    'response_ip_address' => $httpRequest->ip(),
                    'response_user_agent' => substr((string) $httpRequest->userAgent(), 0, 500),
                    'decision_kind' => ConsentRequest::DECISION_INFORMATIONAL,
                    'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
                    'decision_evidence' => $evidence,
                    'resulting_consent_id' => null,
                    'audit_trail' => $this->appendAudit(
                        $lockedRequest,
                        'approved',
                        $recipient->id,
                        [
                            'decision_kind' => ConsentRequest::DECISION_INFORMATIONAL,
                            'decision_evidence' => $evidence,
                        ],
                    ),
                ]);

                $lockedRequest->requestedBy?->notify(new ConsentRequestRespondedNotification(
                    $lockedRequest->fresh(),
                    'approved',
                ));

                return null;
            }

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
            $evidence = $consent->decision_evidence;

            $lockedRequest->update([
                'status' => ConsentRequest::STATUS_APPROVED,
                'responded_at' => now(),
                'response_notes' => $responseNotes,
                'response_ip_address' => $httpRequest->ip(),
                'response_user_agent' => substr((string) $httpRequest->userAgent(), 0, 500),
                'decision_kind' => ConsentRequest::DECISION_AUTHORITATIVE,
                'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
                'decision_evidence' => $evidence,
                'resulting_consent_id' => $consent->id,
                'decision_evidence_accepted_by_user_id' => $authority ? $recipient->id : null,
                'decision_evidence_accepted_at' => $authority ? $acceptedAt : null,
                'audit_trail' => $this->appendAudit($lockedRequest, 'approved', $recipient->id, [
                    'resulting_consent_id' => $consent->id,
                    'decision_evidence' => $evidence,
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
                'decision_kind' => ConsentRequest::DECISION_DECLINED,
                'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
                'decision_evidence' => $this->decisionEvidence(
                    $lockedRequest,
                    $recipient,
                    $lockedRequest->authorityToConsent(),
                ),
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
                'decision_kind' => 'cancelled',
                'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
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
                        'decision_kind' => 'expired',
                        'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
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

            try {
                if (! $lockedRequest->recipient) {
                    return false;
                }
                $this->assertRecipientContext($lockedRequest, $lockedRequest->recipient);
                $this->assertRequestBindingStillValid($lockedRequest);
            } catch (ConflictHttpException) {
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
        $consentTypeVersion = $request->consentTypeVersion;
        $authorityBasis = $request->authorityToConsent();
        $isSubstituted = $authorityBasis === 'substitute';
        $capacityEvidence = $isSubstituted ? $request->capacityEvidenceConsent : null;
        $decisionAt = $acceptedAt->startOfSecond();
        $expiryCandidates = collect([
            $consentType?->validity_period_days
                ? $decisionAt->copy()->addDays($consentType->validity_period_days)
                : null,
            $isSubstituted ? $request->authorityScope?->expires_at : null,
            $isSubstituted ? $capacityEvidence?->expires_at : null,
        ])->filter()->sortBy(fn ($date) => $date->getTimestamp());
        $expiresAt = $expiryCandidates->first()?->copy()->startOfSecond();
        $evidence = [
            ...$this->decisionEvidence($request, $recipient, $authorityBasis),
            'decision_specific_evidence' => $isSubstituted
                ? $this->decisionEvidence->provenance(
                    $request,
                    $recipient,
                    trim((string) $responseNotes),
                    $acceptedAt,
                )
                : null,
            'decision_at' => $decisionAt->toISOString(),
            'decision_expires_at' => $expiresAt?->toISOString(),
        ];

        return ClientConsent::create([
            'client_id' => $request->client_id,
            'site_id' => $request->site_id,
            'consent_type_id' => $request->consent_type_id,
            'consent_type_version_id' => $consentTypeVersion?->id,
            'consent_request_id' => $request->id,
            'decision_evidence_digest' => $isSubstituted ? $request->decision_scope_digest : null,
            'source_consent_request_id' => $request->id,
            'decision_state' => ClientConsent::DECISION_AUTHORITATIVE,
            'decision_basis' => $isSubstituted
                ? ClientConsent::BASIS_SUBSTITUTE
                : ClientConsent::BASIS_SELF,
            'decision_client_id' => $request->client_id,
            'decision_actor_user_id' => $recipient->id,
            'authority_scope_id' => $isSubstituted ? $request->authority_scope_id : null,
            'capacity_evidence_consent_id' => $isSubstituted
                ? $request->capacity_evidence_consent_id
                : null,
            'decision_purpose' => $consentTypeVersion?->purpose,
            'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
            'decision_evidence' => $evidence,
            'gate_satisfying' => true,
            'governance_review_reason' => null,
            'status' => 'given',
            'given_at' => $decisionAt,
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
                'authority_scope_id' => $isSubstituted ? $request->authority_scope_id : null,
                'capacity_evidence_consent_id' => $isSubstituted
                    ? $request->capacity_evidence_consent_id
                    : null,
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
                'request_purpose' => $request->purpose,
                'consent_type_purpose' => $consentTypeVersion?->purpose,
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
        $portalLink = $lockedUser && $client
            ? $client->portalUsers()
                ->whereKey($lockedUser->id)
                ->lockForUpdate()
                ->first()
            : null;
        if (
            ! $lockedUser
            || ! $client
            || ! is_numeric($client->site_id)
            || (int) $request->site_id !== (int) $client->site_id
            || ! ConsentRequest::recipientRoleMatchesRelationship($lockedUser, $request->recipient_relationship)
            || ! $lockedUser->canAccessClientPortal($client)
            || ! $portalLink
        ) {
            throw new ConflictHttpException('The designated recipient is no longer linked to this client.');
        }

        if ($request->recipient_relationship === ConsentRequest::RELATION_SELF
            && (! in_array($portalLink->pivot?->relation, ['self', 'client'], true)
                || (int) $client->user_id !== (int) $lockedUser->id)) {
            throw new ConflictHttpException('The designated recipient is no longer linked as the Client.');
        }
    }

    private function assertStaffContext(ConsentRequest $request, User $staff): void
    {
        $client = $request->client;
        if (! $client
            || ! is_numeric($client->site_id)
            || (int) $request->site_id !== (int) $client->site_id
            || ! $staff->can('view', $client)) {
            throw new ConflictHttpException('This consent request is not available to this staff member.');
        }
    }

    private function assertBoundAuthorityStillValid(ConsentRequest $request): ?NextOfKin
    {
        if ($request->authorityToConsent() !== 'substitute') {
            return null;
        }

        if ($request->authority_next_of_kin_id === null || $request->authority_scope_id === null) {
            throw new ConflictHttpException('Verified substitute decision-making authority is no longer available.');
        }

        $authority = NextOfKin::query()
            ->lockForUpdate()
            ->find($request->authority_next_of_kin_id);
        $scope = ConsentAuthorityScope::query()
            ->with('capacityEvidenceConsent')
            ->lockForUpdate()
            ->find($request->authority_scope_id);

        if (
            ! $authority
            || ! $scope
            || ! $this->scopeIsValidForRequest($scope, $authority, $request)
        ) {
            throw new ConflictHttpException('Verified substitute decision-making authority is no longer valid.');
        }

        return $authority;
    }

    private function assertRequestBindingStillValid(ConsentRequest $request): void
    {
        $client = $request->client;
        $type = $request->consentType;
        $version = $request->consentTypeVersion;

        if (! $client
            || ! is_numeric($client->site_id)
            || (int) $request->site_id !== (int) $client->site_id
            || ! $type
            || ! $type->active
            || ! $version
            || (int) $version->consent_type_id !== (int) $type->id
            || (int) $request->consent_type_version_id !== (int) $version->id) {
            throw new ConflictHttpException('This consent request is no longer bound to a current Client, Site, type and version.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatedCreationData(array $data, User $requester): array
    {
        $client = Client::query()->lockForUpdate()->find($data['client_id'] ?? null);
        $recipient = User::query()->lockForUpdate()->find($data['recipient_user_id'] ?? null);
        $consentType = ConsentType::query()->lockForUpdate()->find($data['consent_type_id'] ?? null);

        if (! $client
            || ! is_numeric($client->site_id)
            || ! $requester->can('view', $client)) {
            throw ValidationException::withMessages([
                'client_id' => 'The Client must be available to you.',
            ]);
        }

        if (! $consentType || ! $consentType->active) {
            throw ValidationException::withMessages([
                'consent_type_id' => 'Select a current consent type.',
            ]);
        }

        $consentTypeVersion = $this->boundConsentTypeVersion($consentType);
        $data['site_id'] = (int) $client->site_id;
        $data['consent_type_version_id'] = $consentTypeVersion->id;

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

        if (! ConsentRequest::recipientRoleMatchesRelationship($recipient, $relationship)) {
            throw ValidationException::withMessages([
                'recipient_relationship' => 'The recipient portal role does not match the selected relationship.',
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

        if ($relationship === ConsentRequest::RELATION_SELF
            && (int) $client->user_id !== (int) $recipient->id) {
            throw ValidationException::withMessages([
                'recipient_relationship' => 'Self-consent requests must be sent to the Client’s own linked portal account.',
            ]);
        }

        $data['authority_next_of_kin_id'] = null;
        $evidence = [];
        $this->bindTriggeringSubject($data, $requester, $client);
        $data['authority_scope_id'] = null;
        $data['capacity_evidence_consent_id'] = null;

        if (in_array($relationship, ConsentRequest::AUTHORISED_SUBSTITUTE_RELATIONS, true)) {
            $authorities = NextOfKin::query()
                ->where('client_id', $client->id)
                ->where('user_id', $recipient->id)
                ->where('legal_authority_type', $relationship)
                ->lockForUpdate()
                ->get();
            $authorityIds = $authorities->pluck('id');
            $scope = ConsentAuthorityScope::query()
                ->with(['nextOfKin', 'capacityEvidenceConsent'])
                ->whereIn('next_of_kin_id', $authorityIds)
                ->where('client_id', $client->id)
                ->where('site_id', $client->site_id)
                ->where('representative_user_id', $recipient->id)
                ->where('consent_type_id', $consentType->id)
                ->where('authority_type', $relationship)
                ->where('purpose', $data['purpose'])
                ->whereNull('revoked_at')
                ->where('valid_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest('verified_at')
                ->lockForUpdate()
                ->get()
                ->first(function (ConsentAuthorityScope $candidate) use (
                    $authorities,
                    $client,
                    $consentType,
                    $data,
                    $recipient,
                ): bool {
                    $authority = $authorities->firstWhere('id', $candidate->next_of_kin_id);

                    return $authority instanceof NextOfKin
                        && $this->scopeIsValid(
                            $candidate,
                            $authority,
                            $client,
                            $consentType,
                            $data['purpose'],
                            $recipient,
                        );
                });

            if (! $scope) {
                throw ValidationException::withMessages([
                    'recipient_relationship' => 'Current, verified authority scoped to this person, Site, consent type, purpose and period is required for substituted consent.',
                ]);
            }

            $authority = $authorities->firstWhere('id', $scope->next_of_kin_id);
            if (! $authority instanceof NextOfKin) {
                throw ValidationException::withMessages([
                    'recipient_relationship' => 'Current verified authority is no longer available for this Client.',
                ]);
            }

            $data['recipient_relationship'] = $authority->legal_authority_type;
            $data['authority_next_of_kin_id'] = $authority->id;
            $data['authority_scope_id'] = $scope->id;
            $data['capacity_evidence_consent_id'] = $scope->capacity_evidence_consent_id;
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
                'site_id',
                'consent_type_id',
                'consent_type_version_id',
                'recipient_user_id',
                'recipient_relationship',
                'authority_next_of_kin_id',
                'authority_scope_id',
                'capacity_evidence_consent_id',
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

    private function boundConsentTypeVersion(ConsentType $type): ConsentTypeVersion
    {
        $version = ConsentTypeVersion::query()
            ->where('consent_type_id', $type->id)
            ->where('version', $type->version)
            ->lockForUpdate()
            ->first();

        if (! $version) {
            $version = ConsentTypeVersion::query()->create([
                'consent_type_id' => $type->id,
                'version' => $type->version,
                'description' => $type->description,
                'purpose' => $type->purpose,
                'legal_basis' => $type->legal_basis,
                'changes_summary' => ['source' => 'canonical_consent_type_version'],
                'effective_from' => $type->created_at ?? now(),
                'created_by' => $type->getAttribute('created_by'),
            ]);
        }

        if (! hash_equals($this->normalisePurpose($type->purpose), $this->normalisePurpose($version->purpose))) {
            throw ValidationException::withMessages([
                'consent_type_id' => 'The selected consent type requires a current governance-approved version before it can be used.',
            ]);
        }

        return $version;
    }

    private function scopeIsValidForRequest(
        ConsentAuthorityScope $scope,
        NextOfKin $authority,
        ConsentRequest $request,
    ): bool {
        $client = $request->client;
        $type = $request->consentType;

        return $client instanceof Client
            && $type instanceof ConsentType
            && $this->scopeIsValid(
                $scope,
                $authority,
                $client,
                $type,
                $request->purpose,
                $request->recipient,
            )
            && (int) $scope->id === (int) $request->authority_scope_id
            && (int) $scope->next_of_kin_id === (int) $request->authority_next_of_kin_id
            && (int) ($scope->capacity_evidence_consent_id ?? 0)
                === (int) ($request->capacity_evidence_consent_id ?? 0);
    }

    private function scopeIsValid(
        ConsentAuthorityScope $scope,
        NextOfKin $authority,
        Client $client,
        ConsentType $type,
        string $requestPurpose,
        ?User $recipient,
    ): bool {
        if (! $recipient
            || ! $scope->isCurrent()
            || ! $scope->authorityEvidenceIsCurrent()
            || ! $authority->hasVerifiedLegalAuthority($scope->authority_type)
            || ! in_array($scope->authority_type, ConsentRequest::AUTHORISED_SUBSTITUTE_RELATIONS, true)
            || (int) $scope->next_of_kin_id !== (int) $authority->id
            || (int) $scope->client_id !== (int) $client->id
            || (int) $authority->client_id !== (int) $client->id
            || ! is_numeric($client->site_id)
            || (int) $scope->site_id !== (int) $client->site_id
            || (int) $scope->representative_user_id !== (int) $recipient->id
            || (int) $authority->user_id !== (int) $recipient->id
            || (int) $scope->consent_type_id !== (int) $type->id
            || $scope->authority_type !== $authority->legal_authority_type
            || ! hash_equals($this->normalisePurpose($scope->purpose), $this->normalisePurpose($requestPurpose))
            || $scope->verified_by_user_id === null
            || $scope->verified_at === null
            || $scope->verified_at->isFuture()
            || $authority->legal_authority_verified_at === null
            || $scope->verified_at->lessThan($authority->legal_authority_verified_at)) {
            return false;
        }

        if (! $type->requiresCapacityAssessment()) {
            return true;
        }

        $capacity = $scope->capacityEvidenceConsent;

        return $capacity instanceof ClientConsent
            && $scope->capacityEvidenceIsCurrent()
            && (int) $capacity->client_id === (int) $client->id
            && $capacity->decision_state !== ClientConsent::DECISION_INFORMATIONAL
            && (int) $capacity->site_id === (int) $client->site_id
            && (int) $capacity->consent_type_id === (int) $type->id
            && $capacity->status === 'given'
            && $capacity->capacity_assessed
            && $capacity->capacity_outcome === 'lacks_capacity'
            && $capacity->capacity_assessor_id !== null
            && $capacity->capacity_assessed_at !== null
            && ! $capacity->capacity_assessed_at->isFuture()
            && $capacity->withdrawn_at === null
            && ($capacity->expires_at === null || $capacity->expires_at->isFuture());
    }

    /** @return array<string, mixed> */
    private function decisionEvidence(
        ConsentRequest $request,
        User $recipient,
        string $authorityBasis,
    ): array {
        $scope = $authorityBasis === 'substitute' ? $request->authorityScope : null;
        $authority = $authorityBasis === 'substitute' ? $request->authorityNextOfKin : null;
        $capacity = $authorityBasis === 'substitute' ? $request->capacityEvidenceConsent : null;

        return [
            'source' => 'family_portal',
            'consent_request_id' => $request->id,
            'client_id' => $request->client_id,
            'decision_client_id' => $request->client_id,
            'site_id' => $request->site_id,
            'consent_type_id' => $request->consent_type_id,
            'consent_type_version_id' => $request->consent_type_version_id,
            'consent_type_purpose' => $request->consentTypeVersion?->purpose,
            'request_purpose' => $request->purpose,
            'decision_actor_user_id' => $recipient->id,
            'authority_basis' => $authorityBasis,
            'authority_next_of_kin_id' => $authority?->id,
            'authority_scope_id' => $scope?->id,
            'authority_scope_version' => $scope?->version,
            'authority_type' => $scope?->authority_type,
            'authority_verified_at' => $scope?->verified_at?->toISOString(),
            'authority_verified_by_user_id' => $scope?->verified_by_user_id,
            'authority_valid_from' => $scope?->valid_from?->toISOString(),
            'authority_expires_at' => $scope?->expires_at?->toISOString(),
            'capacity_evidence_consent_id' => $capacity?->id,
            'capacity_outcome' => $capacity?->capacity_outcome,
            'capacity_assessor_user_id' => $capacity?->capacity_assessor_id,
            'capacity_assessed_at' => $capacity?->capacity_assessed_at?->toISOString(),
            'recorded_at' => now()->toISOString(),
            'legal_or_clinical_determination' => 'not_made_by_consent_workflow',
        ];
    }

    /** @param array<string, mixed> $data */
    private function authorityBasisFromData(array $data): string
    {
        if (($data['recipient_relationship'] ?? null) === ConsentRequest::RELATION_SELF) {
            return 'self';
        }

        return isset($data['authority_scope_id']) && $data['authority_scope_id'] !== null
            ? 'substitute'
            : 'informational_only';
    }

    private function normalisePurpose(?string $purpose): string
    {
        return Str::of((string) $purpose)->squish()->lower()->toString();
    }

    private function lockedRequest(ConsentRequest $request): ConsentRequest
    {
        return ConsentRequest::query()
            ->with([
                'client',
                'consentType',
                'consentTypeVersion',
                'requestedBy',
                'recipient',
                'authorityNextOfKin',
                'authorityScope.capacityEvidenceConsent',
                'capacityEvidenceConsent',
            ])
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
            'prior_status' => $request->status,
            'source' => request()?->routeIs('portal.*') ? 'family_portal' : 'operations',
            'authority_basis' => $request->authorityToConsent(),
            'decision_contract_version' => ConsentRequest::DECISION_CONTRACT_VERSION,
            'meta' => $meta ?: null,
        ], fn ($v) => $v !== null);

        return $trail;
    }
}
