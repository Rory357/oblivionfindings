<?php

namespace App\Domain\It\Services;

use App\Domain\It\Exceptions\ItMailboxPollSuperseded;
use App\Models\ItMailboxConnection;
use App\Services\AuditLogger;
use App\Services\Integration\Exceptions\MailboxProviderFailure;
use App\Services\Integration\Exceptions\ProviderRateLimited;
use App\Services\MicrosoftGraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Versioned configuration against the same credentials and lease used by mailbox polling. */
final class ItMailboxConfigurationService
{
    public function __construct(private readonly ItMailboxPollState $state) {}

    public function changeMailbox(Request $request, string $provider, array $input): ItMailboxConnection
    {
        $this->authorize($request);
        abort_unless(in_array($provider, [ItMailboxConnection::PROVIDER_MICROSOFT, ItMailboxConnection::PROVIDER_GOOGLE], true), 404);
        abort_unless($provider === ItMailboxConnection::PROVIDER_MICROSOFT, 422, 'Gmail reads the connected account. Connect the required account instead.');
        $claim = DB::transaction(function () use ($request, $provider, $input): ItMailboxConnection {
            $current = $this->current($request, $provider, $input);
            abort_if($current->poll_claim_token !== null && $current->poll_claim_expires_at?->isFuture(), 409, 'Another mailbox operation is running. Reload the current state before retrying.');
            abort_if($current->next_poll_at?->isFuture(), 409, 'The provider retry time has not arrived. Reload the current state before retrying.');
            // Reserve the existing exclusive credential lease without stamping a poll attempt.
            $current->forceFill([
                'poll_claim_token' => (string) Str::uuid(), 'poll_claim_expires_at' => now()->addMinutes(2),
            ])->save();

            return $current;
        });
        $address = isset($input['mailbox_email']) && trim($input['mailbox_email']) !== '' ? trim($input['mailbox_email']) : null;
        try {
            $effective = $address ?? $claim->account_email;
            if (! is_string($effective) || ! filter_var($effective, FILTER_VALIDATE_EMAIL)) {
                throw new MailboxProviderFailure('configuration');
            }
            // Read-only provider check outside the DB transaction. No message body or read flag changes.
            (new MicrosoftGraphService(new ItMailboxPollingToken($claim, $this->state)))
                ->discoverUnread($effective, now()->utc(), limit: 1);

            return $this->state->withinClaim($claim, function (ItMailboxConnection $current) use ($request, $address): ItMailboxConnection {
                $this->authorize($request);
                $current->forceFill([
                    'mailbox_email' => $address, 'configuration_version' => $current->configuration_version + 1,
                    'last_polled_at' => null, 'last_poll_attempt_at' => null,
                    'last_poll_failure_code' => null, 'last_error' => null, 'consecutive_poll_failures' => 0,
                    'next_poll_at' => null, 'poll_claim_token' => null, 'poll_claim_expires_at' => null,
                    'inbox_scan_before' => null, 'inbox_scan_cursor' => null,
                    'inbox_scan_cursor_hashes' => null, 'inbox_scan_complete' => false,
                ])->save();
                AuditLogger::logOrFail('settings.it_mailbox.address_changed', $current, [
                    'provider' => $current->provider, 'configuration_version' => $current->configuration_version,
                    'provider_read_access_checked' => true, 'delegated_mailbox' => $address !== null,
                ], $request);

                return $current;
            });
        } catch (MailboxProviderFailure $failure) {
            $this->authorize($request);
            throw ValidationException::withMessages(['mailbox_email' => $failure->getMessage().' The saved mailbox has not changed.']);
        } catch (ProviderRateLimited $failure) {
            $this->authorize($request);
            try {
                $this->state->withinClaim($claim, fn (ItMailboxConnection $current) => $current->forceFill([
                    'next_poll_at' => now()->addSeconds($failure->retryAfterSeconds),
                ])->save());
            } catch (ItMailboxPollSuperseded) {
                abort(409, 'The connection changed during verification. Reload the current state before retrying.');
            }
            throw ValidationException::withMessages(['mailbox_email' => 'The provider requested a retry delay. The saved mailbox has not changed. Reload its current retry time.']);
        } catch (ItMailboxPollSuperseded) {
            abort(409, 'The connection changed during verification. Reload the current state before retrying.');
        } finally {
            // Conditional release cannot clear a replacement/reconnected worker's lease.
            ItMailboxConnection::query()->whereKey($claim->id)
                ->where('configuration_version', $claim->configuration_version)
                ->where('poll_claim_token', $claim->poll_claim_token)
                ->update(['poll_claim_token' => null, 'poll_claim_expires_at' => null]);
        }
    }

    public function disconnect(Request $request, string $provider, array $input): void
    {
        DB::transaction(function () use ($request, $provider, $input): void {
            $current = $this->current($request, $provider, $input);
            AuditLogger::logOrFail('settings.it_mailbox.disconnected', $current, [
                'provider' => $provider, 'configuration_version' => $current->configuration_version,
            ], $request);
            $current->delete();
        });
    }

    private function current(Request $request, string $provider, array $input): ItMailboxConnection
    {
        $this->authorize($request);
        abort_unless(in_array($provider, [ItMailboxConnection::PROVIDER_MICROSOFT, ItMailboxConnection::PROVIDER_GOOGLE], true), 404);
        $current = ItMailboxConnection::query()->where('provider', $provider)->lockForUpdate()->first();
        abort_unless($current && (int) $current->id === (int) $input['connection_id']
            && $current->configuration_version === (int) $input['expected_version'], 409,
            'The mailbox connection changed. Reload its current state before retrying.');

        return $current;
    }

    private function authorize(Request $request): void
    {
        abort_unless($request->user()?->fresh()?->canDo('integrations.manage_secrets'), 403);
    }
}
