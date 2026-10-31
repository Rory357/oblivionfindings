<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Models\ReferralOutbox;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralAcknowledgementTransport;
use App\Domain\Referrals\Services\ReferralCommand;
use App\Domain\Referrals\Services\ReferralDelivery;
use App\Domain\Referrals\Services\ReferralMessaging;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ReferralCommunicationController extends Controller
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralCommand $commands, private readonly ReferralDelivery $delivery, private readonly ReferralMessaging $messaging) {}

    public function index(Request $request, string $reference)
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        $input = $request->validate(['page' => 'nullable|integer|min:1']);
        $rows = ReferralOutbox::query()->where('referral_id', $referral->id)->latest('id')->paginate(20, ['*'], 'page', $input['page'] ?? 1)->withQueryString();
        $senders = User::query()->whereIn('id', collect($rows->items())->where('kind', 'staff_message')->map(fn ($row) => $row->message['sender_id'] ?? null)->filter()->unique())->pluck('name', 'id');
        // Messages to People involved are assessment information: without that access only the role shows.
        $assessment = $this->access->audience($request->user(), 'assessment');
        $rows->through(function (ReferralOutbox $row) use ($referral, $senders, $assessment): array {
            $staff = $row->kind === 'staff_message';
            $message = $staff ? $row->message : [];
            $hidden = $staff && ($message['recipient_kind'] ?? 'enquiry') === 'contact' && ! $assessment;
            $role = $staff ? (($message['recipient_kind'] ?? 'enquiry') === 'contact'
                ? (ReferralMessaging::ROLES[$message['recipient_role'] ?? ''] ?? 'Other contact')
                : (($message['recipient'] ?? '') === 'person' ? 'The person' : 'Referrer')) : null;

            return ['id' => $row->id, 'kind' => $row->kind, 'state' => $row->state, 'attempts' => $row->attempts,
                'recipient' => match (true) {
                    $row->kind === 'public_acknowledgement' => $row->message['recipient'] ?? null,
                    $hidden => 'Someone in People involved',
                    $staff => implode(' · ', array_filter([$message['recipient_name'] ?? null, $message['address'] ?? null])),
                    default => null,
                },
                'recipient_role' => $role,
                'channel' => $staff ? $message['channel'] : ($row->kind === 'public_acknowledgement' ? 'email' : 'in_app'),
                'template' => $message['template_label'] ?? null,
                'subject' => $hidden ? null : ($message['subject'] ?? null),
                'body' => $staff && ! $hidden ? ReferralMessaging::display($message['body']) : null,
                'restricted' => $hidden,
                'edited' => (bool) ($message['edited'] ?? false),
                // Held for the recipient's safe contact time; delivery never sends before it.
                'scheduled_for' => $staff && $row->state === 'pending' && $row->available_at->isFuture() ? $row->available_at->toISOString() : null,
                'sender_name' => $staff ? ($senders[$message['sender_id'] ?? 0] ?? null) : null,
                'created_at' => $row->created_at->toISOString(), 'available_at' => $row->available_at->toISOString(),
                'sent_at' => $row->sent_at?->toISOString(), 'delivered_at' => $row->delivered_at?->toISOString(),
                'reason' => $row->last_error_code,
                'retry_blocker' => in_array($row->kind, ['public_acknowledgement', 'staff_message'], true) ? $this->delivery->blockedReason($row, $referral) : null];
        });

        return response()->json(['communications' => $rows, 'version' => $referral->lock_version,
            'can_manage' => $this->access->can($request->user(), 'communications.manage'),
            'can_send' => $this->access->can($request->user(), 'communications.send'),
            'acknowledgement' => ['subject' => ReferralAcknowledgementTransport::SUBJECT, 'body' => ReferralAcknowledgementTransport::body($referral->reference),
                'sender' => config('referral-delivery.from_address') ?: 'Sender not configured']])->header('Cache-Control', 'no-store, private');
    }

    /** What the Send a message dialog may offer: recipients, safe channels, templates and their blockers. */
    public function compose(Request $request, string $reference)
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        $this->access->authorize($request->user(), $referral, 'communications.send');

        return response()->json($this->messaging->compose($request->user(), $referral))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $reference)
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        $receipt = $this->messaging->queue($request->user(), $referral, $request->only(['version', 'request_key', 'recipient', 'channel', 'template_id', 'body', 'document_request_id', 'send_at']));

        return response()->json($receipt, 201)->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $reference, int $outbox)
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        $data = $request->validate(['version' => 'required|integer|min:1', 'request_key' => 'required|uuid',
            'operation' => ['required', Rule::in(['retry', 'cancel'])], 'reason' => 'required|string|min:10|max:1000', 'acknowledge_duplicate_risk' => 'required|boolean']);
        $hash = hash('sha256', json_encode([$data['operation'], $data['reason'], $data['acknowledge_duplicate_risk']], JSON_THROW_ON_ERROR));
        // Staff who can send messages may also retry or cancel a staff message; everything else needs communications.manage.
        $staffMessage = ReferralOutbox::query()->where('referral_id', $referral->id)->whereKey($outbox)->value('kind') === 'staff_message';
        $action = $staffMessage && ! $this->access->can($request->user(), 'communications.manage') ? 'communications.send' : 'communications.manage';
        $receipt = $this->commands->run($request->user(), $referral->id, $data['version'], $action, function ($current, $actor) use ($outbox, $data, $hash, $action): array {
            $row = ReferralOutbox::query()->where('referral_id', $current->id)->lockForUpdate()->findOrFail($outbox);
            abort_unless($action === 'communications.manage' || $row->kind === 'staff_message', 403);
            abort_unless(in_array($row->state, $data['operation'] === 'cancel' ? ['pending', 'failed', 'delivery_unknown'] : ['failed', 'delivery_unknown'], true), 409, 'This communication has changed. Refresh its current status before deciding.');
            if ($data['operation'] === 'retry') {
                abort_if($row->state === 'delivery_unknown' && ! $data['acknowledge_duplicate_risk'], 422, 'Review the mail service result first. Retrying an uncertain send may send another copy.');
                if (in_array($row->kind, ['public_acknowledgement', 'staff_message'], true)) {
                    abort_if($this->delivery->blockedReason($row, $current) !== null, 422, 'Resolve the current delivery blocker before retrying. A changed recipient must not receive the old queued message.');
                }
            }
            $previous = $row->state;
            $row->fill(['state' => $data['operation'] === 'retry' ? 'pending' : 'cancelled', 'available_at' => now(), 'claim_token' => null,
                'last_error_code' => null, 'message' => [...$row->message, 'last_recovery' => ['request_key' => $data['request_key'], 'hash' => $hash, 'actor_id' => $actor->id]]])->save();
            $this->commands->record($current, $actor, 'communication.'.$data['operation'], 'intake', ['outbox_id' => $row->id, 'previous' => $previous, 'state' => $row->state, 'reason' => $data['reason'], 'duplicate_risk_acknowledged' => $data['acknowledge_duplicate_risk']]);

            return ['saved' => true];
        }, [], function ($current, $actor) use ($outbox, $data, $hash): ?array {
            $row = ReferralOutbox::query()->where('referral_id', $current->id)->lockForUpdate()->findOrFail($outbox);
            $last = $row->message['last_recovery'] ?? [];
            if (($last['request_key'] ?? '') !== $data['request_key']) {
                return null;
            }
            abort_unless(($last['actor_id'] ?? null) === $actor->id && hash_equals($last['hash'] ?? '', $hash), 409, 'This recovery request was used for a different decision.');

            return ['saved' => true];
        });

        return response()->json($receipt)->header('Cache-Control', 'no-store, private');
    }
}
