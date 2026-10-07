<?php

use App\Mail\MailNotSubmitted;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\ItMailboxConnection;
use App\Models\Permission;
use App\Models\User;
use App\Services\EmailConfiguration;
use App\Services\Medication\BackupDelivery\BackupEmailSender;
use App\Services\Medication\BackupDelivery\BackupMailTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

/** A fake transport with no network/capture driver, so SMTP construction is observable. */
final class SyntheticBackupEmailTransport extends AbstractTransport
{
    public array $messages = [];

    public function __construct(private readonly ?Closure $submitted = null)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $this->messages[] = $message->getOriginalMessage();
        ($this->submitted ?? static function (): void {})();
    }

    public function __toString(): string
    {
        return 'synthetic-backup-email';
    }
}

function backupEmailSavedSmtp(array $changes = [], ?string $password = 'saved-synthetic-password'): AppSetting
{
    $row = AppSetting::create(['key' => EmailConfiguration::KEY, 'value' => array_replace([
        'configuration_version' => 1, 'provider' => 'smtp', 'smtp_host' => 'saved.example.test', 'smtp_port' => 2525,
        'smtp_encryption' => 'ssl', 'smtp_username' => 'saved-user', 'from_address' => 'saved@example.test', 'from_name' => 'Saved Sender',
        'it_support' => ['enabled' => false, 'connection_id' => null, 'connection_version' => null, 'connection_scope_hash' => null],
    ], $changes)]);
    if ($password !== null) {
        AppSetting::create(['key' => EmailConfiguration::PASSWORD_KEY, 'value' => Crypt::encryptString($password)]);
    }

    return $row;
}

function backupEmailSavedOAuth(string $provider): ItMailboxConnection
{
    $connection = ItMailboxConnection::create([
        'provider' => $provider, 'status' => 'connected', 'account_email' => 'saved@example.test',
        'access_token' => 'synthetic-backup-token', 'refresh_token' => 'synthetic-refresh',
        'token_expires_at' => now()->addHour(), 'scopes' => [$provider === 'google' ? 'https://www.googleapis.com/auth/gmail.send' : 'Mail.Send'],
    ])->fresh();
    AppSetting::create(['key' => EmailConfiguration::KEY, 'value' => [
        'configuration_version' => 1, 'provider' => $provider, 'from_name' => 'Saved Sender', 'from_address' => 'ignored@example.test',
        'it_support' => ['enabled' => false, 'connection_id' => (int) $connection->id,
            'connection_version' => $connection->configuration_version, 'connection_scope_hash' => $connection->mailboxScopeHash()],
    ]]);

    return $connection;
}

function backupEmailMockManager(Closure $expectations): void
{
    $mock = Mockery::mock(MailManager::class, [app()])->makePartial();
    $expectations($mock);
    app()->instance('mail.manager', $mock);
}
beforeEach(function () {
    Http::preventStrayRequests();
    config(['emar-catalogue-backups.send_enabled' => true, 'mail.default' => 'smtp',
        'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => 'server.example.test', 'port' => 587, 'username' => 'server-user', 'password' => 'server-secret'],
        'mail.from.address' => 'server@example.test', 'mail.from.name' => 'Server Sender']);
});

test('backup uses saved SMTP credentials sender and encryption with IT support disabled', function () {
    // Save through the actual shared configuration contract; no parallel eMAR setting.
    $actor = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    foreach (['settings.access.manage', 'integrations.manage_secrets'] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => 'Synthetic email owner', 'group' => 'settings']);
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
    }
    $this->actingAs($actor)->putJson('/settings/email', [
        'expected_actor_id' => $actor->id, 'expected_version' => 0, 'provider' => 'smtp', 'smtp_host' => 'saved.example.test',
        'smtp_port' => 2525, 'smtp_encryption' => 'ssl', 'smtp_username' => 'saved-user', 'smtp_password' => 'saved-synthetic-password',
        'clear_smtp_password' => false, 'from_address' => 'saved@example.test', 'from_name' => 'Saved Sender', 'support_enabled' => false,
        'support_connection_id' => null, 'support_connection_version' => null, 'public_reply_mode' => 'link_only',
    ])->assertOk();
    $fake = new SyntheticBackupEmailTransport;
    backupEmailMockManager(function (MockInterface $mock) use ($fake) {
        $mock->shouldReceive('createSymfonyTransport')->once()->withArgs(function (array $settings) {
            expect($settings)->toBe(['transport' => 'smtp', 'scheme' => 'smtps', 'host' => 'saved.example.test', 'port' => 2525,
                'username' => 'saved-user', 'password' => 'saved-synthetic-password', 'auto_tls' => true, 'require_tls' => false, 'timeout' => 20]);

            return true;
        })->andReturn($fake);
    });
    app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED-SYNTHETIC', '2026-10-07');
    $email = $fake->messages[0];
    expect($email->getFrom()[0]->getAddress())->toBe('saved@example.test')->and($email->getFrom()[0]->getName())->toBe('Saved Sender')
        ->and($email->getReplyTo()[0]->getAddress())->toBe('saved@example.test')->and($email->getTo()[0]->getAddress())->toBe('approved@example.test')
        ->and($email->getAttachments()[0]->getBody())->toBe('%PDF-PROTECTED-SYNTHETIC')->and($email->getAttachments()[0]->getFilename())->toBe('chart-backup-2026-10-07.pdf')
        ->and($email->getTextBody())->not->toContain('saved-synthetic-password');
    expect(app(BackupEmailSender::class)->readiness())->toBe(['email_ready' => true, 'email_capture_mode' => null, 'email_reason' => null, 'email_source' => 'saved']);
    expect(json_encode(AuditLog::where('action', 'settings.email.updated')->sole()->toArray()))->not->toContain('saved-synthetic-password');
    Http::assertNothingSent();
});

test('backup OAuth submits protected MIME from the canonical saved mailbox with IT support disabled', function (string $provider) {
    backupEmailSavedOAuth($provider);
    $mime = [];
    Http::fake(function ($request) use ($provider, &$mime) {
        expect($request->hasHeader('Authorization', 'Bearer synthetic-backup-token'))->toBeTrue();
        $mime[] = $provider === 'google' ? base64_decode(strtr($request['raw'], '-_', '+/'), true) : base64_decode($request->body(), true);

        return $provider === 'google' ? Http::response(['id' => 'synthetic-provider-id'], 200) : Http::response('', 202);
    });
    app(BackupMailTransport::class)->send(['approved@example.test', 'second@example.test'], '%PDF-PROTECTED-SYNTHETIC', '2026-10-07');
    foreach ($mime as $index => $value) {
        expect($value)->toContain('From: Saved Sender <saved@example.test>', 'Reply-To: Saved Sender <saved@example.test>',
            'To: '.(['approved@example.test', 'second@example.test'][$index]), 'Protected chart backup', 'chart-backup-2026-10-07.pdf')
            ->not->toContain('ignored@example.test', 'server@example.test', 'synthetic-backup-token');
    }
    Http::assertSentCount(2);
    expect(app(BackupEmailSender::class)->readiness()['email_ready'])->toBeTrue();
})->with(['google', 'microsoft']);

test('explicit local capture never constructs a real transport or claims backup delivery ready', function (string $capture) {
    backupEmailSavedOAuth('google');
    config(['mail.default' => $capture, 'mail.mailers.local' => ['transport' => 'log']]);
    backupEmailMockManager(fn (MockInterface $mock) => $mock->shouldNotReceive('createSymfonyTransport'));
    $ready = app(BackupEmailSender::class)->readiness();
    expect($ready['email_ready'])->toBeFalse()->and($ready['email_capture_mode'])->toBe($capture === 'local' ? 'log' : $capture)
        ->and($ready['email_source'])->toBe('saved')->and($ready['email_reason'])->toContain('capture');
    expect(fn () => app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED', '2026-10-07'))->toThrow(MailNotSubmitted::class);
    Http::assertNothingSent();
})->with(['array', 'log', 'local']);

test('invalid saved drafts never fall back to valid server settings or passwords', function (string $change) {
    $record = backupEmailSavedSmtp();
    $stored = $record->value;
    match ($change) {
        'host' => $stored['smtp_host'] = '',
        'sender' => $stored['from_address'] = '',
        'provider' => $stored['provider'] = 'unsupported',
        'malformed' => $stored = 'malformed',
        'missing fields' => $stored = ['configuration_version' => 1, 'provider' => 'smtp', 'from_name' => 'Saved Sender'],
        'missing password' => AppSetting::where('key', EmailConfiguration::PASSWORD_KEY)->delete(),
        'invalid password' => AppSetting::where('key', EmailConfiguration::PASSWORD_KEY)->update(['value' => json_encode('not-encrypted')]),
    };
    $record->update(['value' => $stored]);
    backupEmailMockManager(fn (MockInterface $mock) => $mock->shouldNotReceive('createSymfonyTransport'));
    expect(app(BackupEmailSender::class)->readiness())->toBe(['email_ready' => false, 'email_capture_mode' => null,
        'email_reason' => 'Review the saved email provider and sender in Main Settings → Email.', 'email_source' => 'saved']);
    try {
        app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED', '2026-10-07');
        $this->fail('Incomplete saved configurations cannot submit email.');
    } catch (MailNotSubmitted $error) {
        expect($error->getMessage())->toBe('backup_email_not_ready')->and($error->getPrevious())->toBeNull();
    }
    Http::assertNothingSent();
})->with(['host', 'sender', 'provider', 'malformed', 'missing fields', 'missing password', 'invalid password']);

test('server transport is used only before any central email configuration exists', function () {
    $fake = new SyntheticBackupEmailTransport;
    backupEmailMockManager(function (MockInterface $mock) use ($fake) {
        $mock->shouldReceive('createSymfonyTransport')->once()->withArgs(fn (array $settings) => $settings['host'] === 'server.example.test' && $settings['password'] === 'server-secret')->andReturn($fake);
    });
    app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED', '2026-10-07');
    expect($fake->messages[0]->getFrom()[0]->getAddress())->toBe('server@example.test')->and(app(BackupEmailSender::class)->readiness()['email_source'])->toBe('server');
    Http::assertNothingSent();
});

test('an anonymous saved SMTP configuration never inherits the server password', function () {
    backupEmailSavedSmtp(['smtp_username' => ''], null);
    $fake = new SyntheticBackupEmailTransport;
    backupEmailMockManager(function (MockInterface $mock) use ($fake) {
        $mock->shouldReceive('createSymfonyTransport')->once()->withArgs(fn (array $settings) => $settings['username'] === '' && $settings['password'] === null)->andReturn($fake);
    });
    app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED', '2026-10-07');
    expect($fake->messages)->toHaveCount(1);
    Http::assertNothingSent();
});

test('backup rechecks changed settings or canonical mailbox after message listeners before submission', function (string $change) {
    $connection = backupEmailSavedOAuth('google');
    Event::listen(MessageSending::class, function () use ($connection, $change) {
        match ($change) {
            'configuration' => AppSetting::where('key', EmailConfiguration::KEY)->sole()->update(['value' => ['configuration_version' => 2]]),
            'disconnected' => $connection->update(['status' => 'disconnected']),
            'mailbox' => $connection->update(['mailbox_email' => 'changed@example.test']),
            'consent' => $connection->update(['scopes' => []]),
            'version' => $connection->forceFill(['configuration_version' => 2])->save(),
        };
    });
    Http::fake();
    expect(fn () => app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED', '2026-10-07'))->toThrow(MailNotSubmitted::class);
    Http::assertNothingSent();
})->with(['configuration', 'disconnected', 'mailbox', 'consent', 'version']);

test('backup recipient envelope remains approved after message listeners', function (string $change) {
    backupEmailSavedOAuth('google');
    Event::listen(MessageSending::class, function (MessageSending $event) use ($change) {
        match ($change) {
            'to' => $event->message->to('unapproved@example.test'),
            'other approved' => $event->message->to('second@example.test'),
            'cc' => $event->message->cc('unapproved@example.test'),
            'bcc' => $event->message->bcc('unapproved@example.test'),
            'from' => $event->message->from('unapproved@example.test'),
            'reply' => $event->message->replyTo('unapproved@example.test'),
        };
    });
    Http::fake();
    expect(fn () => app(BackupMailTransport::class)->send(['approved@example.test', 'second@example.test'], '%PDF-PROTECTED', '2026-10-07'))->toThrow(MailNotSubmitted::class);
    Http::assertNothingSent();
})->with(['to', 'other approved', 'cc', 'bcc', 'from', 'reply']);

test('SMTP credential changes between accepted recipients stop without retry or secret disclosure', function () {
    backupEmailSavedSmtp();
    $fake = new SyntheticBackupEmailTransport(static function (): void {
        AppSetting::where('key', EmailConfiguration::PASSWORD_KEY)->sole()->update(['value' => Crypt::encryptString('replacement-secret')]);
    });
    backupEmailMockManager(fn (MockInterface $mock) => $mock->shouldReceive('createSymfonyTransport')->once()->andReturn($fake));
    try {
        app(BackupMailTransport::class)->send(['approved@example.test', 'second@example.test'], '%PDF-PROTECTED', '2026-10-07');
        $this->fail('Partial acceptance cannot be called unsent.');
    } catch (RuntimeException $error) {
        expect($error)->not->toBeInstanceOf(MailNotSubmitted::class)->and($error->getMessage())->toBe('backup_transport_incomplete')->and($error->getPrevious())->toBeNull();
    }
    expect($fake->messages)->toHaveCount(1);
    Http::assertNothingSent();
});

test('server composite transports cannot silently accept a local capture fallback', function (string $driver) {
    config(['mail.default' => 'composite', 'mail.mailers.composite' => ['transport' => $driver, 'mailers' => ['smtp', 'log']]]);
    backupEmailMockManager(fn (MockInterface $mock) => $mock->shouldNotReceive('createSymfonyTransport'));
    expect(app(BackupEmailSender::class)->readiness()['email_ready'])->toBeFalse()
        ->and(app(BackupEmailSender::class)->readiness()['email_source'])->toBe('server');
    expect(fn () => app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED', '2026-10-07'))->toThrow(MailNotSubmitted::class);
    Http::assertNothingSent();
})->with(['failover', 'roundrobin']);

test('readiness is advisory and never acquires locks or changes transaction depth', function () {
    backupEmailSavedOAuth('google');
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });
    $depth = DB::transactionLevel();
    expect(app(BackupEmailSender::class)->readiness()['email_ready'])->toBeTrue()->and(DB::transactionLevel())->toBe($depth);
    expect(implode(' ', $queries))->not->toContain('for update', 'for share');
    Http::assertNothingSent();
});

test('an explicit first provider rejection remains known unsent without secret details', function (string $provider, int $status) {
    backupEmailSavedOAuth($provider);
    Http::fake(fn () => Http::response('Synthetic private provider detail', $status));
    try {
        app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED', '2026-10-07');
        $this->fail('An explicit rejection cannot count as acceptance.');
    } catch (MailNotSubmitted $error) {
        expect($error->getMessage())->toBe('backup_transport_not_submitted')->and($error->getPrevious())->toBeNull();
    }
    Http::assertSentCount(1);
})->with(['google', 'microsoft'])->with([401, 403, 422, 429]);

test('a rejection after an earlier accepted recipient remains uncertain with no resend', function (string $provider) {
    backupEmailSavedOAuth($provider);
    $calls = 0;
    Http::fake(function () use ($provider, &$calls) {
        $calls++;
        if ($calls === 1) {
            return $provider === 'google' ? Http::response(['id' => 'synthetic-accepted'], 200) : Http::response('', 202);
        }

        return Http::response('Synthetic private refusal', 422);
    });
    try {
        app(BackupMailTransport::class)->send(['approved@example.test', 'second@example.test'], '%PDF-PROTECTED', '2026-10-07');
        $this->fail('Partial acceptance is never known unsent.');
    } catch (RuntimeException $error) {
        expect($error)->not->toBeInstanceOf(MailNotSubmitted::class)->and($error->getMessage())->toBe('backup_transport_incomplete')->and($error->getPrevious())->toBeNull();
    }
    Http::assertSentCount(2);
    expect($calls)->toBe(2);
})->with(['google', 'microsoft']);

test('an ambiguous provider timeout stays unknown with one attempt and no secret details', function (string $provider) {
    backupEmailSavedOAuth($provider);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        throw new ConnectionException('Synthetic private credential and network detail');
    });
    try {
        app(BackupMailTransport::class)->send(['approved@example.test'], '%PDF-PROTECTED', '2026-10-07');
        $this->fail('A lost acknowledgement must remain unknown.');
    } catch (RuntimeException $error) {
        expect($error)->not->toBeInstanceOf(MailNotSubmitted::class)->and($error->getMessage())->toBe('backup_transport_submission_unknown')->and($error->getPrevious())->toBeNull();
    }
    expect($calls)->toBe(1);
})->with(['google', 'microsoft']);

test('crossing OAuth refresh threshold during a batch never submits a refresh request', function (string $provider) {
    $connection = backupEmailSavedOAuth($provider);
    $connection->update(['token_expires_at' => now()->addMinutes(6)]);
    $calls = 0;
    Http::fake(function ($request) use ($provider, &$calls) {
        $calls++;
        expect($request->url())->toBe($provider === 'google' ? 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' : 'https://graph.microsoft.com/v1.0/me/sendMail');
        Carbon\Carbon::setTestNow(now('UTC')->addMinutes(2));

        return $provider === 'google' ? Http::response(['id' => 'synthetic-first-accepted'], 200) : Http::response('', 202);
    });
    try {
        app(BackupMailTransport::class)->send(['approved@example.test', 'second@example.test'], '%PDF-PROTECTED', '2026-10-07');
        $this->fail('The second recipient needs a fresh preparation boundary.');
    } catch (RuntimeException $error) {
        expect($error)->not->toBeInstanceOf(MailNotSubmitted::class)->and($error->getMessage())->toBe('backup_transport_incomplete');
    } finally {
        Carbon\Carbon::setTestNow();
    }
    expect($calls)->toBe(1)->and($connection->fresh()->getRefreshToken())->toBe('synthetic-refresh');
    Http::assertSentCount(1);
})->with(['google', 'microsoft']);
