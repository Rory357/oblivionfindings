<?php

namespace App\Domain\Referrals\Services;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralConversion;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralOutbox;
use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Durable claim before transport; ambiguous sends never automatically repeat. */
final class ReferralDelivery
{
    public function __construct(private readonly ReferralAcknowledgementTransport $transport, private readonly ReferralAccess $access, private readonly ReferralMessaging $messaging) {}

    public function run(int $limit = 25): int
    {
        $ids = ReferralOutbox::query()->where(function ($query): void {
            $query->where(fn ($q) => $q->where('state', 'pending')->where('available_at', '<=', now()))
                ->orWhere(fn ($q) => $q->where('state', 'sending')->where('claimed_at', '<=', now()->subMinutes(10)));
        })->orderBy('id')->limit(max(1, min(100, $limit)))->pluck('id');
        foreach ($ids as $id) {
            $this->deliver((int) $id);
        }

        return $ids->count();
    }

    public function deliver(int $id): void
    {
        $referralId = ReferralOutbox::query()->whereKey($id)->value('referral_id');
        if (! $referralId) {
            return;
        }
        // Resolve intake recipients before taking any lock; each is re-checked under the lock.
        $candidates = $this->intakeCandidates($id);
        $claim = DB::transaction(function () use ($id, $referralId, $candidates): ?array {
            $referral = CareReferral::query()->lockForUpdate()->findOrFail($referralId);
            $row = ReferralOutbox::query()->where('referral_id', $referralId)->lockForUpdate()->findOrFail($id);
            if ($row->state === 'sending' && $row->claimed_at?->lte(now()->subMinutes(10))) {
                $this->finish($row, 'delivery_unknown', 'worker_interrupted');

                return null;
            }
            if ($row->state !== 'pending' || $row->available_at->isFuture()) {
                return null;
            }
            $row->attempts++;
            if ($row->kind === 'intake_received') {
                $this->notifyIntake($row, $referral, $candidates);

                return null;
            }
            if ($row->kind === 'handover_committed') {
                $this->notifyHandover($row, $referral);

                return null;
            }
            if ($row->kind === 'staff_message') {
                // Contact preferences, the sender's access and any secure link are re-checked under the lock.
                $prepared = ($reason = $this->messaging->deliveryBlocker($row, $referral)) ? null : $this->messaging->prepare($row, $referral);
                if ($prepared === null) {
                    $this->finish($row, 'failed', $reason ?? 'document_request_unavailable');

                    return null;
                }
                $token = (string) Str::uuid();
                $row->fill(['state' => 'sending', 'claim_token' => $token, 'claimed_at' => now(), 'last_error_code' => null])->save();
                $this->event($row, 'claimed');

                return ['token' => $token, 'staff_message' => $prepared];
            }
            if ($row->kind !== 'public_acknowledgement') {
                $this->finish($row, 'failed', 'unsupported_message');

                return null;
            }
            // Checked under the referral lock immediately before the claim, so a contact
            // correction or authority withdrawal committed earlier always wins.
            if ($reason = $this->blockedReason($row, $referral)) {
                $this->finish($row, 'failed', $reason);

                return null;
            }
            $token = (string) Str::uuid();
            $row->fill(['state' => 'sending', 'claim_token' => $token, 'claimed_at' => now(), 'last_error_code' => null])->save();
            $this->event($row, 'claimed');

            return ['token' => $token, 'recipient' => $row->message['recipient'], 'reference' => $referral->reference];
        }, 3);
        if ($claim === null) {
            return;
        }

        // The claim is committed, so no referral or outbox lock is held during the external call.
        // A database retry can never resend; an interrupted worker leaves the row for the
        // stale-claim path above, which records delivery_unknown rather than sending again.
        try {
            // A staff message's failure code is null when the mail or text service accepted it.
            $failure = isset($claim['staff_message'])
                ? $this->messaging->send($claim['staff_message'])
                : ($this->transport->send($claim['recipient'], $claim['reference']) ? null : 'transport_suppressed');
        } catch (Throwable) {
            // Never retain a mail or text exception: provider diagnostics can include addresses or message content.
            $this->settle($id, $referralId, $claim['token'], fn (ReferralOutbox $row) => $this->finish($row, 'delivery_unknown', 'transport_result_unknown'));

            return;
        }
        $staffMessage = isset($claim['staff_message']);
        $this->settle($id, $referralId, $claim['token'], function (ReferralOutbox $row) use ($failure, $staffMessage, $referralId): void {
            if ($failure === null) {
                $sentAt = now()->toImmutable();
                $row->sent_at = $sentAt;
                if ($staffMessage) {
                    // A sent staff message is a contact attempt, never a conversation.
                    ReferralMessaging::recordAttempt($referralId, $sentAt);
                }
            }
            $this->finish($row, $failure === null ? 'sent' : 'failed', $failure);
        });
    }

    private function settle(int $id, int $referralId, string $token, callable $outcome): void
    {
        DB::transaction(function () use ($id, $referralId, $token, $outcome): void {
            CareReferral::query()->whereKey($referralId)->lockForUpdate()->firstOrFail();
            $row = ReferralOutbox::query()->where('referral_id', $referralId)->lockForUpdate()->findOrFail($id);
            if ($row->state === 'sending' && hash_equals($row->claim_token ?? '', $token)) {
                $outcome($row);
            }
        }, 3);
    }

    /**
     * Users who currently hold a referral read permission (role or explicit allow) and can see
     * this referral, resolved without locks. Delivery re-checks each one under the referral lock.
     *
     * @return list<int>
     */
    private function intakeCandidates(int $id): array
    {
        $row = ReferralOutbox::query()->find($id);
        $referral = $row?->kind === 'intake_received' ? CareReferral::query()->find($row->referral_id) : null;
        if (! $referral || $referral->stage === 'closed') {
            return [];
        }
        $keys = ['referrals.viewAny', 'referrals.viewAssigned'];
        $candidates = [];
        User::query()->whereNotNull('approved_at')->when($referral->owner_id, fn ($q) => $q->whereKey($referral->owner_id))
            ->where(fn ($q) => $q->whereHas('roles.permissions', fn ($p) => $p->whereIn('permissions.key', $keys))
                ->orWhereHas('permissionOverrides', fn ($p) => $p->whereIn('permissions.key', $keys)->where('permission_user.allowed', true)))
            ->chunkById(100, function ($users) use (&$candidates, $referral): void {
                foreach ($users as $user) {
                    if ($this->eligibleForIntake($user, $referral)) {
                        $candidates[] = (int) $user->id;
                    }
                }
            });

        return $candidates;
    }

    private function eligibleForIntake(User $user, CareReferral $referral): bool
    {
        return $this->access->query($user)->whereKey($referral->id)->exists()
            && ($referral->owner_id || $this->access->can($user, 'assign'));
    }
    public function blockedReason(ReferralOutbox $row, CareReferral $referral): ?string
    {
        if ($row->kind === 'staff_message') {
            return $this->messaging->deliveryBlocker($row, $referral);
        }
        if ($referral->stage === 'closed' || ! ReferralAssessment::scope($referral, 'contact')) {
            return 'contact_not_permitted';
        }
        $contact = $referral->intake['submitted_contact'] ?? [];
        if (($contact['channel'] ?? '') !== 'email' || ! filter_var($row->message['recipient'] ?? '', FILTER_VALIDATE_EMAIL)
            || ($contact['email'] ?? '') !== ($row->message['recipient'] ?? '')) {
            return 'safe_contact_changed';
        }
        if (filled($contact['safe_time'] ?? null)) {
            return 'safe_time_needs_review';
        }
        $effective = app(ReferralSettings::class)->effective();
        if (! ($effective?->configuration['public']['acknowledgement_enabled'] ?? false)) {
            return 'acknowledgements_disabled';
        }
        if (! $this->transport->configured()) {
            return 'transport_not_configured';
        }

        return null;
    }

    private function notifyIntake(ReferralOutbox $row, CareReferral $referral, array $candidates): void
    {
        if ($referral->stage === 'closed') {
            $this->finish($row, 'cancelled', 'referral_closed');

            return;
        }
        $recipients = 0;
        // Revoked access applies at delivery time: re-check the pre-resolved candidates only.
        User::query()->whereNotNull('approved_at')->whereIn('id', $candidates)->when($referral->owner_id, fn ($q) => $q->whereKey($referral->owner_id))->chunkById(100, function ($users) use (&$recipients, $referral): void {
            foreach ($users as $user) {
                if (! $this->eligibleForIntake($user, $referral)) {
                    continue;
                }
                DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => AppEventNotification::class,
                    'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id,
                    // Generic navigation only: later access withdrawal cannot expose a person or referral.
                    'data' => json_encode(['kind' => 'referral_intake', 'title' => 'Review referral intake', 'body' => 'New work is available in your permitted Referrals queue.', 'url' => '/operations/referrals']),
                    'created_at' => now(), 'updated_at' => now()]);
                $recipients++;
            }
        });
        if ($recipients === 0) {
            $row->available_at = now()->addMinutes(15);
            $this->finish($row, $row->attempts < 5 ? 'pending' : 'failed', 'no_eligible_recipient');
        } else {
            $row->delivered_at = now();
            $this->finish($row, 'delivered', null);
        }
    }

    private function notifyHandover(ReferralOutbox $row, CareReferral $referral): void
    {
        $conversion = ReferralConversion::query()->where('referral_id', $referral->id)->find($row->message['conversion_id'] ?? 0);
        $person = $conversion ? Client::query()->lockForUpdate()->find($conversion->client_id) : null;
        $workflow = $person ? ClientOnboardingWorkflow::query()->where('client_id', $person->id)->lockForUpdate()->find($conversion->workflow_id) : null;
        if ($referral->stage !== 'converted' || ! $workflow || $workflow->status !== 'in_progress') {
            $this->finish($row, 'cancelled', 'handover_no_longer_open');

            return;
        }
        // Assignment may have changed since conversion. Never send to the saved former owner.
        $owner = $workflow->assigned_to ? User::query()->find($workflow->assigned_to) : null;
        if (! $owner || ! app(ReferralOnboardingOwners::class)->eligible($owner, $workflow->site_id, $referral->restricted, $person)) {
            $row->available_at = now()->addMinutes(15);
            $this->finish($row, $row->attempts < 5 ? 'pending' : 'failed', 'no_eligible_handover_owner');

            return;
        }
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => AppEventNotification::class,
            'notifiable_type' => $owner->getMorphClass(), 'notifiable_id' => $owner->id,
            'data' => json_encode(['kind' => 'referral_handover', 'title' => 'Review onboarding handover', 'body' => 'New work is available in your permitted onboarding queue.', 'url' => '/operations/onboarding']),
            'created_at' => now(), 'updated_at' => now()]);
        $row->delivered_at = now();
        $this->finish($row, 'delivered', null);
    }

    private function finish(ReferralOutbox $row, string $state, ?string $code): void
    {
        $row->fill(['state' => $state, 'last_error_code' => $code, 'claim_token' => null])->save();
        $this->event($row, $state);
    }

    private function event(ReferralOutbox $row, string $state): void
    {
        ReferralEvent::create(['referral_id' => $row->referral_id, 'kind' => 'communication.'.$state, 'audience' => 'intake',
            'subject_type' => 'outbox', 'subject_id' => $row->id, 'payload' => ['outbox_id' => $row->id, 'kind' => $row->kind, 'state' => $row->state, 'attempt' => $row->attempts, 'reason' => $row->last_error_code], 'occurred_at' => now()]);
    }
}
