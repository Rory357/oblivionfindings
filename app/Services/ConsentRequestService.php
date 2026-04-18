<?php

namespace App\Services;

use App\Models\ClientConsent;
use App\Models\ConsentRequest;
use App\Models\User;
use App\Notifications\Operations\ConsentRequestCreatedNotification;
use App\Notifications\Operations\ConsentRequestRespondedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * State-machine for ConsentRequest. All transitions go through here so the
 * audit trail, notifications, and ClientConsent side-effects stay in lockstep.
 */
class ConsentRequestService
{

    /**
     * Staff creates a new consent request.
     *
     * @param array<string, mixed> $data Keys: client_id, consent_type_id,
     *        recipient_user_id, recipient_relationship, purpose, and the
     *        optional Right-7 disclosure fields.
     */
    public function create(array $data, User $requester, ?string $expiresInDays = null): ConsentRequest
    {
        $expiresAt = now()->addDays((int) ($expiresInDays ?? 14));

        return DB::transaction(function () use ($data, $requester, $expiresAt) {
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
        if ($request->viewed_at !== null) {
            return;
        }

        $request->update([
            'viewed_at' => now(),
            'audit_trail' => $this->appendAudit($request, 'viewed', $request->recipient_user_id),
        ]);
    }

    /**
     * Recipient approves. Writes a ClientConsent row, links it back, notifies
     * the requester.
     *
     * @return ClientConsent The consent row that now authorises whatever the
     *   request was asking about.
     */
    public function approve(
        ConsentRequest $request,
        User $recipient,
        Request $httpRequest,
        ?string $responseNotes = null,
    ): ClientConsent {
        $this->assertActionable($request);
        $this->assertRecipient($request, $recipient);

        return DB::transaction(function () use ($request, $recipient, $httpRequest, $responseNotes) {
            $consent = $this->materialiseClientConsent($request, $recipient, $responseNotes);

            $request->update([
                'status' => ConsentRequest::STATUS_APPROVED,
                'responded_at' => now(),
                'response_notes' => $responseNotes,
                'response_ip_address' => $httpRequest->ip(),
                'response_user_agent' => substr((string) $httpRequest->userAgent(), 0, 500),
                'resulting_consent_id' => $consent->id,
                'audit_trail' => $this->appendAudit($request, 'approved', $recipient->id, [
                    'resulting_consent_id' => $consent->id,
                ]),
            ]);

            $request->requestedBy?->notify(new ConsentRequestRespondedNotification($request->fresh(), 'approved'));

            return $consent;
        });
    }

    public function decline(
        ConsentRequest $request,
        User $recipient,
        Request $httpRequest,
        ?string $responseNotes = null,
    ): void {
        $this->assertActionable($request);
        $this->assertRecipient($request, $recipient);

        DB::transaction(function () use ($request, $recipient, $httpRequest, $responseNotes) {
            $request->update([
                'status' => ConsentRequest::STATUS_DECLINED,
                'responded_at' => now(),
                'response_notes' => $responseNotes,
                'response_ip_address' => $httpRequest->ip(),
                'response_user_agent' => substr((string) $httpRequest->userAgent(), 0, 500),
                'audit_trail' => $this->appendAudit($request, 'declined', $recipient->id),
            ]);

            $request->requestedBy?->notify(new ConsentRequestRespondedNotification($request->fresh(), 'declined'));
        });
    }

    /**
     * Staff cancels a still-pending request (e.g. it was sent to the wrong
     * relative, or the clinical situation changed).
     */
    public function cancel(ConsentRequest $request, User $staff, string $reason): void
    {
        if (!$request->isPending()) {
            throw new RuntimeException('Only pending requests can be cancelled.');
        }

        $request->update([
            'status' => ConsentRequest::STATUS_CANCELLED,
            'cancelled_by_user_id' => $staff->id,
            'cancellation_reason' => $reason,
            'audit_trail' => $this->appendAudit($request, 'cancelled', $staff->id, [
                'reason' => $reason,
            ]),
        ]);
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
                $request->update([
                    'status' => ConsentRequest::STATUS_EXPIRED,
                    'audit_trail' => $this->appendAudit($request, 'expired', null),
                ]);
                $expired++;
            });

        return $expired;
    }

    // ── internals ─────────────────────────────────────────────────

    private function materialiseClientConsent(
        ConsentRequest $request,
        User $recipient,
        ?string $responseNotes,
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
            'status' => 'given',
            'given_at' => now(),
            'given_by_user_id' => $recipient->id,
            'given_by_relationship' => $request->recipient_relationship,
            'given_method' => 'electronic',
            'given_notes' => $responseNotes,
            'capacity_assessed' => $isSubstituted,
            'capacity_outcome' => $isSubstituted ? 'lacks_capacity' : null,
            'capacity_assessor_id' => $isSubstituted ? $request->requested_by_user_id : null,
            'capacity_assessed_at' => $isSubstituted ? now() : null,
            'capacity_notes' => $isSubstituted
                ? sprintf(
                    'Consent obtained via family portal under %s authority (PPPR Act 1988 / substituted decision).',
                    str_replace('_', ' ', $request->recipient_relationship),
                )
                : null,
            'best_interests_decision' => $isSubstituted,
            'best_interests_decision_maker_id' => $isSubstituted ? $recipient->id : null,
            'best_interests_decision_at' => $isSubstituted ? now() : null,
            'best_interests_rationale' => $isSubstituted
                ? $request->least_restrictive_justification
                : null,
            'evidence_type' => 'portal_signature',
            'conditions' => [
                'source' => 'family_portal',
                'consent_request_id' => $request->id,
                'data_scope' => $request->data_scope,
                'retention_period_days' => $request->retention_period_days,
                'purpose' => $request->purpose,
            ],
            'expires_at' => $expiresAt,
            'created_by' => $request->requested_by_user_id,
            'updated_by' => $recipient->id,
        ]);
    }

    private function assertActionable(ConsentRequest $request): void
    {
        if (!$request->isActionable()) {
            throw new RuntimeException(sprintf(
                'Consent request #%d is not actionable (status=%s).',
                $request->id,
                $request->status,
            ));
        }
    }

    private function assertRecipient(ConsentRequest $request, User $user): void
    {
        if ($request->recipient_user_id !== $user->id) {
            throw new RuntimeException('Only the designated recipient may respond to this request.');
        }
    }

    /**
     * @param array<string, mixed> $meta
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
