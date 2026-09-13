<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItInboundAttachmentUnavailable;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\User;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/** Explicit authorized settings projection. Never serialize credentials or legacy provider errors. */
final class ItMailboxConnectionPresenter
{
    private const FAILURE_CODES = [
        'configuration', 'authentication', 'permission', 'unavailable', 'invalid_response', 'rejected',
        'rate_limited', 'processing_failed', 'scanner_unavailable', 'scanner_failed', 'scanner_timeout',
        'file_unavailable', 'attachment_storage_unavailable', 'attachment_storage_changed',
        'attachment_cleanup_pending', 'attachment_not_ready', 'attachment_reservation_incomplete',
    ];

    /** Settings authority is required even for mailbox health; omit account and message details. */
    public function operations(User $viewer): array
    {
        $allowed = $viewer->fresh()?->canDo('integrations.manage_secrets') ?? false;
        $available = $allowed && Schema::hasColumns('it_mailbox_connections', [
            'last_poll_attempt_at', 'last_poll_failure_code', 'consecutive_poll_failures',
            'next_poll_at', 'poll_claim_token', 'poll_claim_expires_at', 'inbox_scan_before',
        ]) && Schema::hasColumns('it_inbound_emails', [
            'mailbox_scope_hash', 'acknowledged_at', 'processing_attempts', 'acknowledgement_attempts',
        ]);
        $connections = $available ? ItMailboxConnection::query()
            ->whereIn('provider', [ItMailboxConnection::PROVIDER_MICROSOFT, ItMailboxConnection::PROVIDER_GOOGLE])
            ->orderBy('provider')->get() : collect();

        return [
            'viewer_user_id' => (int) $viewer->id,
            'can_view' => $allowed,
            'available' => $available,
            'checked_at' => now()->toIso8601String(),
            'settings_url' => $allowed ? route('settings.it-mailbox', absolute: false) : null,
            'connections' => $connections->map(function (ItMailboxConnection $connection): array {
                $pending = ItInboundEmail::query()->where('mailbox_scope_hash', $connection->mailboxScopeHash())
                    ->whereNull('acknowledged_at');
                $quarantine = ItInboundEmail::query()->where('mailbox_scope_hash', $connection->mailboxScopeHash())
                    ->where('status', 'quarantined');
                $oldest = (clone $pending)->min('received_at');
                $quarantinedAt = (clone $quarantine)->min('received_at');

                return [
                    'provider' => $connection->provider,
                    'status' => in_array($connection->status, ['connected', 'disconnected', 'error'], true)
                        ? $connection->status : 'unknown',
                    // Only a completed discovery/processing/acknowledgement cycle writes last_polled_at.
                    'last_completed_poll_at' => $connection->last_polled_at?->toIso8601String(),
                    'last_attempt_at' => $connection->last_poll_attempt_at?->toIso8601String(),
                    'failure_category' => $this->failureCategory($connection),
                    'consecutive_failed_polls' => (int) $connection->consecutive_poll_failures,
                    'next_poll_at' => $connection->next_poll_at?->toIso8601String(),
                    'operation_active' => $connection->poll_claim_token !== null && ($connection->poll_claim_expires_at?->isFuture() ?? false),
                    'scan_pending' => $connection->inbox_scan_before !== null,
                    'pending' => [
                        'processing' => (clone $pending)->where('status', 'pending')->count(),
                        'acknowledgement' => (clone $pending)->where('status', '!=', 'pending')->count(),
                        'oldest_received_at' => $oldest ? CarbonImmutable::parse($oldest)->toIso8601String() : null,
                        'processing_attempts' => (int) (clone $pending)->sum('processing_attempts'),
                        'acknowledgement_attempts' => (int) (clone $pending)->sum('acknowledgement_attempts'),
                    ],
                    'quarantine' => [
                        'count' => $quarantine->count(),
                        'oldest_received_at' => $quarantinedAt ? CarbonImmutable::parse($quarantinedAt)->toIso8601String() : null,
                    ],
                ];
            })->values()->all(),
        ];
    }

    private function failureCategory(ItMailboxConnection $connection): ?string
    {
        $code = $connection->last_poll_failure_code;

        return in_array($code, self::FAILURE_CODES, true) ? $code
            : ($code !== null || $connection->status === 'error' ? 'unknown' : null);
    }

    public function present(string $provider, ?ItMailboxConnection $connection): array
    {
        $code = $connection?->last_poll_failure_code;
        $failure = match ($code) {
            'configuration', 'authentication', 'permission', 'unavailable', 'invalid_response', 'rejected' => (new MailboxProviderFailure($code))->getMessage(),
            'rate_limited' => 'The provider requested a retry delay. Wait until the recorded retry time.',
            'processing_failed' => 'Some mailbox work could not finish. Review pending work and retry after the recorded delay.',
            'scanner_unavailable', 'scanner_failed', 'scanner_timeout', 'file_unavailable',
            'attachment_storage_unavailable', 'attachment_storage_changed', 'attachment_cleanup_pending',
            'attachment_not_ready', 'attachment_reservation_incomplete' => (new ItInboundAttachmentUnavailable($code))->getMessage(),
            default => $connection?->status === 'error' ? 'A previous mailbox operation failed. Reload the current state and review recovery options.' : null,
        };
        $counts = ['awaiting_processing' => 0, 'awaiting_acknowledgement' => 0, 'quarantined' => 0];
        if ($connection) {
            $scope = $connection->mailboxScopeHash();
            $counts['awaiting_processing'] = ItInboundEmail::query()->where('mailbox_scope_hash', $scope)->where('status', 'pending')->count();
            $counts['awaiting_acknowledgement'] = ItInboundEmail::query()->where('mailbox_scope_hash', $scope)->where('status', '!=', 'pending')->whereNull('acknowledged_at')->count();
            $counts['quarantined'] = ItInboundEmail::query()->where('mailbox_scope_hash', $scope)->where('status', 'quarantined')->count();
        }
        $active = $connection?->poll_claim_token !== null && $connection?->poll_claim_expires_at?->isFuture();
        $waiting = $connection?->next_poll_at?->isFuture() ?? false;
        $canPoll = $connection && in_array($connection->status, ['connected', 'error'], true)
            && $connection->access_token !== null && $code !== 'authentication' && ! $active && ! $waiting;

        return [
            'id' => $connection?->id, 'version' => $connection?->configuration_version,
            'configured' => ! empty(config("services.{$provider}.client_id")) && ! empty(config("services.{$provider}.client_secret")),
            'status' => $connection?->status,
            'account_email' => $connection?->account_email, 'account_name' => $connection?->account_name,
            'mailbox_email' => $connection?->mailbox_email, 'effective_mailbox' => $connection?->mailboxEmail(),
            'last_polled_at' => $connection?->last_polled_at?->toIso8601String(),
            'last_poll_attempt_at' => $connection?->last_poll_attempt_at?->toIso8601String(),
            'next_poll_at' => $connection?->next_poll_at?->toIso8601String(),
            'operation_active' => (bool) $active, 'can_poll' => (bool) $canPoll,
            'authorization_required' => $code === 'authentication', 'last_error' => $failure,
            'scan_pending' => $connection?->inbox_scan_before !== null,
            'counts' => $counts,
        ];
    }
}
