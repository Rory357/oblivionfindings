<?php

namespace App\Services;

use App\Mail\SupportMailboxSender;
use App\Models\AppSetting;
use App\Models\ItMailboxConnection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The existing outgoing email settings and secret, with a versioned IT delivery contract. */
final class EmailConfiguration
{
    public const KEY = 'settings.email.configuration';

    public const PASSWORD_KEY = 'settings.email.smtp_password';

    public function canManage(?User $user): bool
    {
        return $user !== null && $user->approved_at !== null
            && $user->canDo('settings.access.manage') && $user->canDo('integrations.manage_secrets');
    }

    public function current(): array
    {
        return $this->values(AppSetting::query()->useWritePdo()->where('key', self::KEY)->value('value'));
    }

    public function passwordSaved(): bool
    {
        return AppSetting::query()->where('key', self::PASSWORD_KEY)->exists();
    }

    public function smtpPassword(int $configurationVersion): ?string
    {
        return DB::transaction(function () use ($configurationVersion): ?string {
            $record = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->first();
            if ($this->values($record?->value)['configuration_version'] !== $configurationVersion) {
                throw new \DomainException('Email settings changed before credentials were loaded.');
            }
            $stored = AppSetting::query()->where('key', self::PASSWORD_KEY)->value('value');

            return $stored === null ? config('mail.mailers.smtp.password') : Crypt::decryptString((string) $stored);
        });
    }

    public function connections(): array
    {
        return ItMailboxConnection::query()->orderBy('provider')->get()->map(fn (ItMailboxConnection $connection): array => [
            'id' => (int) $connection->id,
            'provider' => $connection->provider,
            'configuration_version' => $connection->configuration_version,
            'account_email' => $connection->account_email,
            'mailbox_email' => $connection->mailboxEmail(),
            'connected' => $connection->isConnected(),
            'sending_issue' => SupportMailboxSender::configurationIssue($connection, $connection->provider),
        ])->all();
    }

    public function save(Request $request): array
    {
        abort_unless($this->canManage($request->user()?->fresh()), 403);
        $input = $request->validate([
            'expected_actor_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:0'],
            'provider' => ['required', 'in:smtp,microsoft,google'],
            'smtp_host' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x20\x7f]/'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', 'in:tls,ssl,none'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_password' => ['nullable', 'string', 'max:1000'],
            'clear_smtp_password' => ['required', 'boolean'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255', 'not_regex:/[\r\n]/'],
            'support_enabled' => ['required', 'boolean'],
            'support_connection_id' => ['nullable', 'integer', 'min:1'],
            'support_connection_version' => ['nullable', 'integer', 'min:1'],
            'public_reply_mode' => ['required', 'in:link_only,full_reply'],
        ]);
        abort_unless((int) $input['expected_actor_id'] === (int) $request->user()->id, 403);
        if ($input['clear_smtp_password'] && ($input['smtp_password'] ?? '') !== '') {
            throw ValidationException::withMessages(['smtp_password' => 'Choose either a replacement password or removal of the saved password.']);
        }

        return DB::transaction(function () use ($request, $input): array {
            abort_unless($this->canManage($request->user()?->fresh()), 403);
            // The unique existing key serializes first-time and subsequent editors.
            AppSetting::query()->insertOrIgnore(['key' => self::KEY, 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            $record = AppSetting::query()->where('key', self::KEY)->lockForUpdate()->firstOrFail();
            $current = $this->values($record->value);
            abort_unless($current['configuration_version'] === (int) $input['expected_version'], 409,
                'Email settings changed. Review the saved configuration before applying your changes.');
            $connection = null;
            if (($input['support_connection_id'] ?? null) !== null) {
                $connection = ItMailboxConnection::query()->whereKey($input['support_connection_id'])->lockForUpdate()->first();
                if (! $connection || $connection->configuration_version !== (int) ($input['support_connection_version'] ?? 0)) {
                    if ($input['support_enabled']) {
                        throw ValidationException::withMessages(['support_connection_id' => 'The selected support mailbox changed. Reload the current connections before selecting it again.']);
                    }
                    $connection = null;
                }
            }
            if ($input['support_enabled']) {
                $issue = $this->connectionIssue($connection, $input['provider']);
                if ($issue !== null) {
                    throw ValidationException::withMessages(['support_connection_id' => $issue]);
                }
                if ($input['provider'] === 'smtp' && (trim((string) ($input['smtp_host'] ?? '')) === ''
                    || strtolower(trim((string) ($input['from_address'] ?? ''))) !== strtolower((string) $connection->mailboxEmail()))) {
                    throw ValidationException::withMessages(['from_address' => 'Set the SMTP host and use the selected support mailbox as the sender address.']);
                }
            }
            $stored = is_array($record->value) ? $record->value : [];
            $stored = array_replace($stored, [
                'configuration_version' => $current['configuration_version'] + 1,
                'provider' => $input['provider'], 'smtp_host' => $input['smtp_host'] ?? '',
                'smtp_port' => $input['smtp_port'], 'smtp_encryption' => $input['smtp_encryption'],
                'smtp_username' => $input['smtp_username'] ?? '', 'from_address' => $input['from_address'] ?? '',
                'from_name' => $input['from_name'],
                'it_support' => [
                    'enabled' => (bool) $input['support_enabled'], 'public_reply_mode' => $input['public_reply_mode'],
                    'connection_id' => $connection?->id, 'connection_version' => $connection?->configuration_version,
                    'connection_scope_hash' => $connection?->mailboxScopeHash(),
                ],
            ]);
            $record->update(['value' => $stored]);
            if ($input['clear_smtp_password']) {
                AppSetting::query()->where('key', self::PASSWORD_KEY)->delete();
            } elseif (($input['smtp_password'] ?? '') !== '') {
                AppSetting::updateOrCreate(['key' => self::PASSWORD_KEY], ['value' => Crypt::encryptString($input['smtp_password'])]);
            }
            AuditLogger::logOrFail('settings.email.updated', $record, [
                'configuration_version' => $stored['configuration_version'], 'provider' => $stored['provider'],
                'support_enabled' => $input['support_enabled'], 'public_reply_mode' => $input['public_reply_mode'],
                'support_connection_id' => $connection?->id,
                'password_changed' => $input['clear_smtp_password'] || ($input['smtp_password'] ?? '') !== '',
            ], $request);

            return $this->values($stored);
        });
    }

    public function supportConnection(array $configuration): ItMailboxConnection
    {
        $connection = ItMailboxConnection::query()->useWritePdo()->find($configuration['support_connection_id']);
        if (! $connection || $connection->configuration_version !== $configuration['support_connection_version']
            || ! is_string($configuration['support_connection_scope_hash'])
            || ! hash_equals($configuration['support_connection_scope_hash'], $connection->mailboxScopeHash())
            || $this->connectionIssue($connection, $configuration['provider']) !== null) {
            throw new \DomainException('The saved support mailbox is no longer ready. Review the email settings before sending.');
        }

        return $connection;
    }

    private function connectionIssue(?ItMailboxConnection $connection, string $provider): ?string
    {
        if (! $connection || ! $connection->isConnected()
            || ! filter_var($connection->mailboxEmail(), FILTER_VALIDATE_EMAIL)) {
            return 'Select a connected support mailbox to receive replies.';
        }

        return $provider === 'smtp' ? null : SupportMailboxSender::configurationIssue($connection, $provider);
    }

    private function values(mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $support = $stored['it_support'] ?? [];
        if (! is_array($support)) {
            throw new \DomainException('The saved email settings need review.');
        }

        return [
            'configuration_version' => (int) ($stored['configuration_version'] ?? 0),
            'provider' => $stored['provider'] ?? 'smtp',
            'smtp_host' => (string) ($stored['smtp_host'] ?? config('mail.mailers.smtp.host', '')),
            'smtp_port' => (int) ($stored['smtp_port'] ?? config('mail.mailers.smtp.port', 587)),
            'smtp_encryption' => $stored['smtp_encryption'] ?? (config('mail.mailers.smtp.scheme') === 'smtps' ? 'ssl' : 'tls'),
            'smtp_username' => (string) ($stored['smtp_username'] ?? config('mail.mailers.smtp.username', '')),
            'from_address' => (string) ($stored['from_address'] ?? config('mail.from.address', '')),
            'from_name' => (string) ($stored['from_name'] ?? config('mail.from.name', '')),
            'support_enabled' => (bool) ($support['enabled'] ?? false),
            'support_connection_id' => isset($support['connection_id']) ? (int) $support['connection_id'] : null,
            'support_connection_version' => isset($support['connection_version']) ? (int) $support['connection_version'] : null,
            'support_connection_scope_hash' => $support['connection_scope_hash'] ?? null,
            'public_reply_mode' => $support['public_reply_mode'] ?? 'link_only',
        ];
    }
}
