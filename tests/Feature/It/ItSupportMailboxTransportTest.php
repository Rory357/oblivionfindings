<?php

use App\Mail\GoogleGmailTransport;
use App\Mail\MicrosoftGraphTransport;
use App\Models\Identity;
use App\Models\ItMailboxConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

function itSupportTransportConnection(string $provider): ItMailboxConnection
{
    return ItMailboxConnection::create([
        'provider' => $provider, 'status' => 'connected', 'account_email' => 'support@example.test',
        'access_token' => 'synthetic-access', 'token_expires_at' => now()->addHour(),
        'scopes' => [$provider === 'google' ? 'https://www.googleapis.com/auth/gmail.modify' : 'https://graph.microsoft.com/Mail.Send'],
    ]);
}

function itSupportTransportMessage(): Email
{
    $email = (new Email)->from('support@example.test')->replyTo('support@example.test')
        ->to('one@example.test', 'two@example.test')->cc('copy@example.test')->bcc('blind@example.test')
        ->subject('Synthetic public reply')->text('Complete plain text')->html('<p>Complete HTML</p>')
        ->attach('Synthetic attachment', 'proof.txt', 'text/plain');
    $email->getHeaders()->addIdHeader('Message-ID', 'rfc-submitted@example.test');
    $email->getHeaders()->addIdHeader('In-Reply-To', 'original@example.test');
    $email->getHeaders()->addTextHeader('References', '<ancestor@example.test> <original@example.test>');
    $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

    return $email;
}

beforeEach(function () {
    Http::preventStrayRequests();
});

test('approved support transports preserve full MIME and separate Gmail provider identity', function (string $provider) {
    $connection = itSupportTransportConnection($provider);
    $mime = null;
    Http::fake(function ($request) use ($provider, &$mime) {
        expect($request->method())->toBe('POST')->and($request->hasHeader('Authorization', 'Bearer synthetic-access'))->toBeTrue();
        if ($provider === 'google') {
            expect($request->url())->toBe('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
            $raw = $request['raw'];
            expect($raw)->toMatch('/^[A-Za-z0-9_-]+$/');
            $mime = base64_decode(strtr($raw, '-_', '+/'), true);

            return Http::response(['id' => 'gmail-provider-id', 'threadId' => 'gmail-thread-id'], 200);
        }
        expect($request->url())->toBe('https://graph.microsoft.com/v1.0/me/sendMail')
            ->and($request->hasHeader('Content-Type', 'text/plain'))->toBeTrue();
        $mime = base64_decode($request->body(), true);

        return Http::response('', 202);
    });
    $transport = $provider === 'google' ? new GoogleGmailTransport($connection) : new MicrosoftGraphTransport($connection);
    $sent = $transport->send(itSupportTransportMessage());
    expect($sent->getMessageId())->toBe($provider === 'google' ? 'gmail-provider-id' : 'rfc-submitted@example.test')
        ->and($sent->getOriginalMessage()->getHeaders()->get('Message-ID')->getBodyAsString())->toBe('<rfc-submitted@example.test>');
    foreach (['From: support@example.test', 'Reply-To: support@example.test', 'one@example.test', 'two@example.test',
        'Cc: copy@example.test', 'Bcc: blind@example.test', 'Message-ID: <rfc-submitted@example.test>',
        'In-Reply-To: <original@example.test>', '<ancestor@example.test>', 'Auto-Submitted: auto-generated',
        'multipart/mixed', 'multipart/alternative', 'Complete plain text', 'Complete HTML', 'proof.txt'] as $fragment) {
        expect($mime)->toContain($fragment);
    }
    expect(explode("\r\n\r\n", $mime, 2)[0])->toContain('Content-Type: multipart/mixed');
    Http::assertSentCount(1);
})->with(['google', 'microsoft']);

test('support submission rechecks the canonical mailbox and consent before any HTTP', function (string $provider, string $change) {
    $connection = itSupportTransportConnection($provider);
    $transport = $provider === 'google' ? new GoogleGmailTransport($connection) : new MicrosoftGraphTransport($connection);
    match ($change) {
        'disconnected' => $connection->update(['status' => 'disconnected']),
        'mailbox' => $connection->update(['mailbox_email' => 'different@example.test']),
        'account' => $connection->update(['account_email' => 'different@example.test']),
        'provider' => $connection->update(['provider' => $provider === 'google' ? 'microsoft' : 'google']),
        'consent' => $connection->update(['scopes' => []]),
        'token' => $connection->update(['access_token' => null]),
        'version' => $connection->forceFill(['configuration_version' => $connection->configuration_version + 1])->save(),
        'deleted' => $connection->delete(),
    };
    Http::fake();
    expect(fn () => $transport->send(itSupportTransportMessage()))->toThrow(TransportException::class);
    Http::assertNothingSent();
})->with(['google', 'microsoft'])->with(['disconnected', 'mailbox', 'account', 'provider', 'consent', 'token', 'version', 'deleted']);

test('support submission rejects forged sender reply address and recipient envelope', function (string $provider, string $change) {
    $connection = itSupportTransportConnection($provider);
    $transport = $provider === 'google' ? new GoogleGmailTransport($connection) : new MicrosoftGraphTransport($connection);
    $email = itSupportTransportMessage();
    $envelope = null;
    match ($change) {
        'from' => $email->from('unapproved@example.test'),
        'reply' => $email->replyTo('unapproved@example.test'),
        'sender' => $email->sender('unapproved@example.test'),
        'recipients' => $envelope = new Envelope(new Address('support@example.test'), [new Address('unapproved@example.test')]),
        'envelope_sender' => $envelope = new Envelope(new Address('unapproved@example.test'), [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]),
    };
    Http::fake();
    expect(fn () => $transport->send($email, $envelope))->toThrow(TransportException::class);
    Http::assertNothingSent();
})->with(['google', 'microsoft'])->with(['from', 'reply', 'sender', 'recipients', 'envelope_sender']);

test('Google does not submit from a different mailbox and Graph requires shared sending consent', function (string $provider, bool $sharedConsent) {
    $connection = itSupportTransportConnection($provider);
    $connection->update(['account_email' => 'delegate@example.test', 'mailbox_email' => 'support@example.test',
        'scopes' => $sharedConsent ? ['https://graph.microsoft.com/Mail.Send.Shared'] : $connection->scopes]);
    Http::fake(['graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 202)]);
    $transport = $provider === 'google' ? new GoogleGmailTransport($connection) : new MicrosoftGraphTransport($connection);
    if ($provider === 'microsoft' && $sharedConsent) {
        expect($transport->send(itSupportTransportMessage()))->not->toBeNull();
        Http::assertSentCount(1);
    } else {
        expect(fn () => $transport->send(itSupportTransportMessage()))->toThrow(TransportException::class);
        Http::assertNothingSent();
    }
})->with(['google', 'microsoft'])->with([false, true]);

test('Gmail rejects missing malformed and unsuccessful acknowledgements without retry', function (int $status, mixed $body) {
    $connection = itSupportTransportConnection('google');
    Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response($body, $status)]);
    try {
        (new GoogleGmailTransport($connection))->send(itSupportTransportMessage());
        $this->fail('Expected unconfirmed submission.');
    } catch (TransportException $exception) {
        expect($exception->getMessage())->not->toContain('Synthetic private detail')
            ->and(strtolower($exception->getMessage()))->not->toContain('poll');
    }
    Http::assertSentCount(1);
})->with([
    [200, []], [200, ['id' => '']], [200, ['id' => ['Synthetic private detail']]],
    [200, ['id' => "invalid\r\nidentifier"]], [200, ['id' => str_repeat('x', 256)]], [200, 'Synthetic private detail'],
    [202, ['id' => 'accepted-with-wrong-status']], [204, ''], [302, 'Synthetic private detail'],
    [400, 'Synthetic private detail'], [401, 'Synthetic private detail'], [403, 'Synthetic private detail'],
    [429, 'Synthetic private detail'], [500, 'Synthetic private detail'],
]);

test('Microsoft shared-send consent also supports the connected accounts own support mailbox', function (string $scope) {
    $connection = itSupportTransportConnection('microsoft');
    $connection->update(['scopes' => [$scope]]);
    Http::fake(['graph.microsoft.com/v1.0/me/sendMail' => Http::response('', 202)]);
    expect((new MicrosoftGraphTransport($connection))->send(itSupportTransportMessage()))->not->toBeNull();
    Http::assertSentCount(1);
})->with(['Mail.Send.Shared', 'https://graph.microsoft.com/Mail.Send.Shared']);

test('Gmail reports a lost response as uncertain with no automatic second submission', function () {
    $connection = itSupportTransportConnection('google');
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('Synthetic private detail');
    });
    expect(fn () => (new GoogleGmailTransport($connection))->send(itSupportTransportMessage()))
        ->toThrow(TransportException::class, 'Reconcile the delivery outcome before retrying.');
    expect($attempts)->toBe(1);
});

test('support submission uses refreshed stored credentials instead of a stale model token', function (string $provider) {
    $connection = itSupportTransportConnection($provider);
    $transport = $provider === 'google' ? new GoogleGmailTransport($connection) : new MicrosoftGraphTransport($connection);
    $connection->fresh()->update(['access_token' => 'synthetic-rotated-access']);
    Http::fake(function ($request) use ($provider) {
        expect($request->hasHeader('Authorization', 'Bearer synthetic-rotated-access'))->toBeTrue();

        return $provider === 'google' ? Http::response(['id' => 'gmail-rotated-id'], 200) : Http::response('', 202);
    });
    $transport->send(itSupportTransportMessage());
    Http::assertSentCount(1);
})->with(['google', 'microsoft']);

test('Gmail refuses missing expired and wrong-provider identities before submission', function (string $scenario) {
    $identity = new Identity(['provider' => 'google', 'access_token' => 'synthetic-access', 'token_expires_at' => now()->addHour()]);
    if ($scenario === 'expired') {
        $identity->token_expires_at = now()->subMinute();
    } elseif ($scenario === 'provider') {
        $identity->provider = 'microsoft';
    }
    Http::fake();
    expect(fn () => (new GoogleGmailTransport($scenario === 'missing' ? null : $identity))->send(itSupportTransportMessage()))
        ->toThrow(TransportException::class);
    Http::assertNothingSent();
})->with(['missing', 'expired', 'provider']);
