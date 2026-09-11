<?php

namespace App\Domain\It\Services;

use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Metadata-only recovery using the existing connection permission and inbound ledger. */
final class ItInboundQuarantineReview
{
    private const RETRY_REASONS = ['sender_unknown', 'sender_inactive', 'sender_not_allowed', 'sender_site_unresolved', 'sender_unauthorized', 'sensitive_work'];

    public function listing(Request $request, string $provider, array $input): array
    {
        $this->authorize($request);

        return DB::transaction(function () use ($request, $provider, $input): array {
            $connection = $this->connection($request, $provider, $input);
            $records = ItInboundEmail::query()->where('mailbox_scope_hash', $connection->mailboxScopeHash())
                ->where(fn ($query) => $query->where('status', 'quarantined')->orWhere('quarantine_review_version', '>', 0))
                ->when($input['before_id'] ?? null, fn ($query, $id) => $query->where('id', '<', $id))
                ->orderByDesc('id')->limit(26)->get();
            $page = $records->take(25);
            $canPoll = (new ItMailboxConnectionPresenter)->present($provider, $connection)['can_poll'];

            return ['connection_id' => (int) $connection->id, 'connection_version' => (int) $connection->configuration_version,
                'records' => $page->map(fn ($receipt) => $this->present($connection, $receipt, $canPoll))->values()->all(),
                'next_before_id' => $records->count() > 25 ? (int) $page->last()->id : null];
        });
    }

    public function retry(Request $request, string $provider, int $id, array $input): array
    {
        $this->authorize($request);

        return DB::transaction(function () use ($request, $provider, $id, $input): array {
            // Same lock order as polling/configuration; a retry cannot modify a worker's receipt.
            $connection = $this->connection($request, $provider, $input);
            $receipt = ItInboundEmail::query()->where('mailbox_scope_hash', $connection->mailboxScopeHash())
                ->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($receipt->quarantine_review_version === $input['expected_review_version'], 409, 'The record changed. Reload quarantine review.');
            abort_if($this->retryBlock($connection, $receipt) !== null, 409, 'This record cannot be retried in its current state. Reload quarantine review.');
            AuditLogger::logOrFail('settings.it_mailbox.quarantine_retry_requested', $receipt, [
                'provider' => $provider, 'connection_id' => (int) $connection->id,
                'configuration_version' => (int) $connection->configuration_version,
                'review_version' => $receipt->quarantine_review_version + 1,
                'previous_reason' => $receipt->quarantine_reason,
                'previous_acknowledged_at' => $receipt->acknowledged_at?->toIso8601String(),
            ], $request);
            $receipt->forceFill([
                'status' => 'pending', 'quarantine_retry_requested_at' => now(),
                'quarantine_review_version' => $receipt->quarantine_review_version + 1,
                'acknowledged_at' => null, 'transport_retry_at' => null, 'transport_failure_code' => null,
            ])->save();

            return ['status' => 'requested', 'record' => $this->present($connection, $receipt)];
        });
    }

    private function authorize(Request $request): void
    {
        abort_unless($request->user()?->fresh()?->canDo('integrations.manage_secrets'), 403);
        abort_unless(Schema::hasColumn('it_inbound_emails', 'quarantine_review_version'), 503, 'Quarantine review requires the pending database upgrade.');
    }

    private function connection(Request $request, string $provider, array $input): ItMailboxConnection
    {
        $this->authorize($request);
        abort_unless(in_array($provider, ['microsoft', 'google'], true), 404);
        $connection = ItMailboxConnection::query()->where('provider', $provider)->lockForUpdate()->first();
        abort_unless($connection && (int) $connection->id === $input['connection_id']
            && $connection->configuration_version === $input['expected_version'], 409, 'The mailbox changed. Reload its saved state.');

        return $connection;
    }

    private function retryBlock(ItMailboxConnection $connection, ItInboundEmail $receipt, ?bool $canPoll = null): ?string
    {
        if ($receipt->status !== 'quarantined') {
            return $receipt->status === 'pending' ? 'Retry requested. The next mailbox poll will recheck the original message.' : 'Review the recorded outcome. This record does not need a quarantine retry.';
        }
        if (! in_array($receipt->quarantine_reason, self::RETRY_REASONS, true)) {
            return match ($receipt->quarantine_reason) {
                'automatic_message' => 'Automated mail is kept out of ticket conversations to prevent reply loops. This message cannot be retried as a ticket reply.',
                'delivery_report' => 'Delivery reports do not become ticket replies. Review the original email’s delivery record.',
                default => 'Automatic retry is unavailable for this security or message-format decision. Retain the evidence for the authorised support review.',
            };
        }
        if ($receipt->remote_message_id === null || $receipt->message_content_hash === null
            || $receipt->message_identity_hash === null || $receipt->identity_claim_key !== $receipt->message_identity_hash
            || $receipt->duplicate_of_id !== null || $receipt->it_ticket_id !== null) {
            return 'The original provider message and its identity cannot be proven. Retain this record for reconciliation.';
        }
        if ($receipt->attachments()->where(fn ($query) => $query->whereNull('inbound_storage_state')
            ->orWhere('inbound_storage_state', '!=', 'ready')->orWhereNull('malware_scan_status')
            ->orWhere('malware_scan_status', '!=', 'clean'))->exists()) {
            return 'Files are not ready for safe ingestion. Keep them quarantined for storage or scanner recovery.';
        }
        if ($receipt->acknowledged_at === null) {
            return 'The provider read flag is not confirmed. Complete mailbox recovery before requesting this retry.';
        }
        if (! ($canPoll ?? (new ItMailboxConnectionPresenter)->present($connection->provider, $connection)['can_poll'])) {
            return 'The mailbox is busy, waiting for its retry time, or needs connection recovery. Reload its current state first.';
        }

        return null;
    }

    private function present(ItMailboxConnection $connection, ItInboundEmail $receipt, ?bool $canPoll = null): array
    {
        $blocked = $this->retryBlock($connection, $receipt, $canPoll);
        $reason = match ($receipt->quarantine_reason) {
            'sender_unknown' => 'Sender is not registered', 'sender_inactive' => 'Sender is not active or approved',
            'sender_not_allowed' => 'Sender cannot create support requests', 'sender_site_unresolved' => 'Sender has no approved request site',
            'sender_unauthorized', 'sensitive_work' => 'Sender cannot access the referenced work',
            'retry_message_changed', 'retry_identity_changed' => 'Original message identity or content changed',
            'retry_message_invalid' => 'Original message no longer passes validation',
            'automatic_message' => 'Automated email excluded from ticketing',
            'delivery_report' => 'Delivery report excluded from ticket replies',
            default => 'Message requires authorised review',
        };

        return ['id' => (int) $receipt->id, 'version' => (int) $receipt->quarantine_review_version,
            'status' => $receipt->status, 'reason' => $receipt->status === 'quarantined' ? $reason : null,
            'received_at' => $receipt->received_at?->toIso8601String(),
            'retry_requested_at' => $receipt->quarantine_retry_requested_at?->toIso8601String(),
            'can_retry' => $blocked === null,
            'guidance' => $blocked ?? 'Correct the sender’s account, approved site or work access through the existing administration workflow, then request a recheck. Access is checked again during ingestion.'];
    }
}
