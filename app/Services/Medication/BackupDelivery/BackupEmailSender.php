<?php

namespace App\Services\Medication\BackupDelivery;

use App\Mail\MailNotSubmitted;
use App\Mail\SupportMailboxSender;
use App\Models\AppSetting;
use App\Models\ItMailboxConnection;
use App\Services\EmailConfiguration;
use App\Services\Integration\MailboxProviderHttp;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Reuse Main Settings → Email without copying secrets or enabling IT support. */
class BackupEmailSender
{
    public function readiness(): array
    {
        $source = AppSetting::query()->useWritePdo()->where('key', EmailConfiguration::KEY)->exists() ? 'saved' : 'server';
        $capture = $this->captureMode();
        try {
            $snapshot = $this->snapshot(false);
            if (isset($snapshot['connection'])) {
                $connection = $snapshot['connection'];
                $provider = $snapshot['provider'];
                // Advisory reads never refresh; require only the credentials the next preparation needs.
                $credentials = $connection->needsRefresh()
                    ? [$connection->getRefreshToken(), config("services.{$provider}.client_id"), config("services.{$provider}.client_secret")]
                    : [$connection->getAccessToken()];
                foreach ($credentials as $credential) {
                    if (! is_string($credential) || trim($credential) === '') {
                        throw new MailNotSubmitted('backup_email_mailbox_not_ready');
                    }
                }
            }
            $reason = null;
        } catch (\Throwable) {
            $reason = $capture !== null
                ? 'Local email capture is enabled; backup email delivery is unavailable.'
                : ($source === 'saved' ? 'Review the saved email provider and sender in Main Settings → Email.' : 'Configure outgoing email in Main Settings → Email.');
        }

        return ['email_ready' => $reason === null, 'email_capture_mode' => $capture, 'email_reason' => $reason, 'email_source' => $source];
    }

    /** Rotate OAuth credentials durably before starting the clinical release transaction. */
    public function prepare(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Prepare OAuth credentials outside the clinical submission transaction.');
        }
        try {
            $connection = DB::transaction(function (): ?ItMailboxConnection {
                // Capture/server/SMTP do not need an OAuth refresh. Actual submission
                // still checks their full readiness under the clinical transaction.
                if ($this->captureMode() !== null) {
                    return null;
                }
                $record = AppSetting::query()->useWritePdo()->where('key', EmailConfiguration::KEY)->lockForUpdate()->first();
                if (! is_array($record?->value) || ! in_array($record->value['provider'] ?? null, ['google', 'microsoft'], true)) {
                    return null;
                }
                $snapshot = $this->snapshot();
                $connection = $snapshot['connection'];
                if ($connection->needsRefresh()) {
                    // Existing bounded provider refresh, ONE attempt, no clinical message.
                    MailboxProviderHttp::client($connection, $snapshot['provider']);
                }

                return $connection;
            }, 1);
            // Even a short-lived replacement must remain committed: never undo a
            // rotated refresh credential just because it is unsuitable for this batch.
            if ($connection?->needsRefresh()) {
                throw new MailNotSubmitted('backup_mailbox_preparation_required');
            }
        } catch (\Throwable) {
            throw new MailNotSubmitted('backup_email_preparation_failed');
        }
    }

    /** Caller holds a single-attempt transaction through the whole recipient batch. */
    public function make(array $addresses): Mailer
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Prepare the backup sender within its delivery transaction.');
        }
        try {
            $snapshot = $this->snapshot();
            $transport = match ($snapshot['provider']) {
                'google', 'microsoft' => new PreparedBackupOAuthTransport($snapshot['connection'], $snapshot['provider']),
                default => app('mail.manager')->createSymfonyTransport($snapshot['transport']),
            };
            $checked = new CheckedBackupTransport($transport, function () use ($snapshot): void {
                try {
                    $current = $this->snapshot();
                } catch (\Throwable) {
                    throw new MailNotSubmitted('backup_email_not_ready');
                }
                if (! hash_equals($snapshot['fingerprint'], $current['fingerprint'])) {
                    throw new MailNotSubmitted('backup_email_configuration_changed');
                }
            }, $snapshot['address'], $addresses);
            $mailer = new Mailer('emar-backup', app('view'), $checked, app('events'));
            $mailer->alwaysFrom($snapshot['address'], $snapshot['name']);
            $mailer->alwaysReplyTo($snapshot['address'], $snapshot['name']);

            return $mailer;
        } catch (\Throwable) {
            // No construction failure submits email or exposes credentials/provider details.
            throw new MailNotSubmitted('backup_email_not_ready');
        }
    }

    private function captureMode(): ?string
    {
        $name = (string) config('mail.default');
        $driver = config('mail.mailers.'.$name.'.transport');

        return in_array($name, ['array', 'log'], true) ? $name : (in_array($driver, ['array', 'log'], true) ? $driver : null);
    }

    private function snapshot(bool $locked = true): array
    {
        if ($this->captureMode() !== null) {
            throw new MailNotSubmitted('backup_email_capture_only');
        }
        $record = AppSetting::query()->useWritePdo()->where('key', EmailConfiguration::KEY)->when($locked, fn ($query) => $query->lockForUpdate())->first();
        if (! $record) {
            $name = (string) config('mail.default');
            $transport = config('mail.mailers.'.$name);
            if (! is_array($transport) || ! in_array($transport['transport'] ?? null, ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true)) {
                throw new MailNotSubmitted('backup_email_server_not_ready');
            }
            $address = $this->address(config('mail.from.address'));
            $fromName = $this->name(config('mail.from.name', ''));
            if ($transport['transport'] === 'smtp') {
                if (empty($transport['url']) && empty($transport['host'])) {
                    throw new MailNotSubmitted('backup_email_server_not_ready');
                }
                $transport['timeout'] = 20;
            }

            return ['provider' => 'server', 'transport' => $transport, 'address' => $address, 'name' => $fromName,
                'fingerprint' => hash('sha256', serialize(['server', $name, $transport, $address, $fromName]))];
        }

        // Saved drafts must never inherit missing host, sender or credentials from .env.
        $stored = $record->value;
        if (! is_array($stored) || ! is_int($stored['configuration_version'] ?? null) || $stored['configuration_version'] < 1
            || ! in_array($stored['provider'] ?? null, ['smtp', 'google', 'microsoft'], true)) {
            throw new MailNotSubmitted('backup_email_saved_not_ready');
        }
        $fromName = $this->name($stored['from_name'] ?? null);
        $provider = $stored['provider'];
        $fingerprint = [$stored];
        if ($provider === 'smtp') {
            $address = $this->address($stored['from_address'] ?? null);
            $host = $stored['smtp_host'] ?? null;
            $port = $stored['smtp_port'] ?? null;
            $encryption = $stored['smtp_encryption'] ?? null;
            $username = $stored['smtp_username'] ?? null;
            if (! is_string($host) || $host === '' || strlen($host) > 255 || preg_match('/[\x00-\x20\x7f]/', $host)
                || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false || ! in_array($encryption, ['tls', 'ssl', 'none'], true)
                || ! is_string($username) || strlen($username) > 255) {
                throw new MailNotSubmitted('backup_email_saved_not_ready');
            }
            $secret = AppSetting::query()->useWritePdo()->where('key', EmailConfiguration::PASSWORD_KEY)->when($locked, fn ($query) => $query->lockForUpdate())->first();
            $password = $secret === null ? null : Crypt::decryptString((string) $secret->value);
            if ($username !== '' && ($password === null || $password === '')) {
                throw new MailNotSubmitted('backup_email_saved_not_ready');
            }
            $fingerprint[] = $secret?->value;
            $transport = ['transport' => 'smtp', 'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'host' => $host, 'port' => (int) $port, 'username' => $username, 'password' => $password,
                'auto_tls' => $encryption !== 'none', 'require_tls' => $encryption === 'tls', 'timeout' => 20];

            return ['provider' => $provider, 'transport' => $transport, 'address' => $address, 'name' => $fromName,
                'fingerprint' => hash('sha256', serialize($fingerprint))];
        }

        $support = $stored['it_support'] ?? null;
        if (! is_array($support) || ! is_int($support['connection_id'] ?? null) || ! is_int($support['connection_version'] ?? null)
            || ! is_string($support['connection_scope_hash'] ?? null)) {
            throw new MailNotSubmitted('backup_email_saved_not_ready');
        }
        $connection = ItMailboxConnection::query()->useWritePdo()->when($locked, fn ($query) => $query->lockForUpdate())->find($support['connection_id']);
        if (! $connection || $connection->configuration_version !== $support['connection_version']
            || ! hash_equals($support['connection_scope_hash'], $connection->mailboxScopeHash())
            || SupportMailboxSender::configurationIssue($connection, $provider) !== null) {
            throw new MailNotSubmitted('backup_email_mailbox_not_ready');
        }
        $address = $this->address($connection->mailboxEmail());
        // Token refresh can legitimately rotate credentials; use the current canonical row,
        // while binding every submission to the saved mailbox identity/version and consent.
        $fingerprint[] = [$connection->id, $connection->configuration_version, $connection->mailboxScopeHash(), $connection->status, $connection->scopes];

        return ['provider' => $provider, 'connection' => $connection, 'address' => $address, 'name' => $fromName,
            'fingerprint' => hash('sha256', serialize($fingerprint))];
    }

    private function address(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 255 || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new MailNotSubmitted('backup_email_sender_not_ready');
        }

        return $value;
    }

    private function name(mixed $value): string
    {
        if (! is_string($value) || mb_strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new MailNotSubmitted('backup_email_sender_not_ready');
        }

        return $value;
    }
}
