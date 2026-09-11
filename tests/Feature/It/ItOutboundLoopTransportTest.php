<?php

use App\Domain\It\Services\ItTicketIntakeService;
use App\Mail\MicrosoftGraphTransport;
use App\Models\Identity;
use App\Models\ItEmailDelivery;
use App\Models\Role;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

function itLoopTransportIdentity(): Identity
{
    return new Identity(['provider' => 'microsoft', 'access_token' => 'synthetic-access', 'token_expires_at' => now()->addHour()]);
}

test('tracked notification markers survive Graph MIME with all recipients and content', function () {
    Http::preventStrayRequests();
    $this->seed(RbacSeeder::class);
    $recipient = User::factory()->create(['email' => 'one@example.test', 'role' => 'support_worker', 'approved_at' => now()]);
    $recipient->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($recipient);
    $ticket = app(ItTicketIntakeService::class)->createCommand($recipient, [
        'request_uuid' => (string) Str::uuid(), 'title' => 'Synthetic transport identity',
        'category' => 'other', 'priority' => 'normal',
    ])->ticket;
    $delivery = ItEmailDelivery::where('it_ticket_id', $ticket->id)->where('recipient_user_id', $recipient->id)->sole();
    $delivery->forceFill(['status' => 'sending', 'sending_at' => now()])->save();
    $mime = null;
    Http::fake(function ($request) use (&$mime) {
        expect($request->url())->toBe('https://graph.microsoft.com/v1.0/me/sendMail')
            ->and($request->method())->toBe('POST')
            ->and($request->hasHeader('Content-Type', 'text/plain'))->toBeTrue();
        $mime = base64_decode($request->body(), true);

        return Http::response('', 202);
    });
    $email = (new Email)->from('support@example.test')->to('one@example.test', 'two@example.test')
        ->cc('copy@example.test')->bcc('blind@example.test')->replyTo('replies@example.test')
        ->subject('Synthetic notification')->text('Complete plain text')->html('<p>Complete HTML</p>')
        ->attach('Synthetic attachment', 'proof.txt', 'text/plain');
    $email->getHeaders()->addTextHeader('Auto-Submitted', 'no');
    $email->getHeaders()->addIdHeader('Message-ID', 'stable-outgoing@example.test');
    $email->getHeaders()->addIdHeader('In-Reply-To', 'original@example.test');
    $event = new MessageSending($email, ['__laravel_notification' => TicketCreatedNotification::class,
        '__laravel_notification_id' => $delivery->notification_uuid]);
    Event::dispatch($event);
    Event::dispatch($event);
    expect(iterator_to_array($email->getHeaders()->all('Auto-Submitted')))->toHaveCount(1);
    $sent = (new MicrosoftGraphTransport(itLoopTransportIdentity()))->send($email);
    expect($sent->getMessageId())->toBe('stable-outgoing@example.test');
    foreach (['Auto-Submitted: auto-generated', 'X-Auto-Response-Suppress: All',
        'one@example.test', 'two@example.test', 'Cc: copy@example.test', 'Bcc: blind@example.test',
        'Reply-To: replies@example.test', 'Message-ID: <stable-outgoing@example.test>',
        'In-Reply-To: <original@example.test>', 'multipart/mixed', 'multipart/alternative',
        'Complete plain text', 'Complete HTML', 'proof.txt'] as $fragment) {
        expect($mime)->toContain($fragment);
    }
    // MIME content headers belong to the top-level header block, not the body.
    expect(explode("\r\n\r\n", $mime, 2)[0])->toContain('Content-Type: multipart/mixed');
    Http::assertSentCount(1);
});

test('ordinary mail is not relabelled as an automatic IT notification', function () {
    $email = (new Email)->from('person@example.test')->to('recipient@example.test')->text('Human message');
    Event::dispatch(new MessageSending($email));
    Event::dispatch(new MessageSending($email, ['__laravel_notification' => stdClass::class]));
    expect($email->getHeaders()->has('Auto-Submitted'))->toBeFalse()
        ->and($email->getHeaders()->has('X-Auto-Response-Suppress'))->toBeFalse();
});

test('Graph rejects non-acceptance without a silent success or automatic resend', function (int $status) {
    Http::preventStrayRequests();
    Http::fake(['graph.microsoft.com/v1.0/me/sendMail' => Http::response('Synthetic private provider error', $status)]);
    $email = (new Email)->from('support@example.test')->to('person@example.test')->text('Synthetic message');
    try {
        (new MicrosoftGraphTransport(itLoopTransportIdentity()))->send($email);
        $this->fail('Expected provider refusal.');
    } catch (TransportException $exception) {
        expect($exception->getMessage())->not->toContain('Synthetic private provider error')
            ->and(strtolower($exception->getMessage()))->not->toContain('poll');
    }
    Http::assertSentCount(1);
})->with([200, 204, 302, 400, 401, 403, 429, 500]);

test('Graph requires a configured identity and an exact recipient envelope before HTTP', function (bool $identityPresent) {
    Http::preventStrayRequests();
    Http::fake();
    $email = (new Email)->from('support@example.test')->to('person@example.test')->text('Synthetic message');
    $envelope = $identityPresent ? new Envelope(new Address('support@example.test'), [new Address('different@example.test')]) : null;
    expect(fn () => (new MicrosoftGraphTransport($identityPresent ? itLoopTransportIdentity() : null))->send($email, $envelope))
        ->toThrow(TransportException::class);
    Http::assertNothingSent();
})->with([false, true]);

test('an expired Graph identity cannot silently send with its old token', function () {
    Http::preventStrayRequests();
    Http::fake();
    $identity = itLoopTransportIdentity();
    $identity->token_expires_at = now()->subMinute();
    $email = (new Email)->from('support@example.test')->to('person@example.test')->text('Synthetic message');
    expect(fn () => (new MicrosoftGraphTransport($identity))->send($email))->toThrow(TransportException::class);
    Http::assertNothingSent();
});

test('a lost Graph response is surfaced without an automatic second send', function () {
    Http::preventStrayRequests();
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        throw new ConnectionException('Synthetic private connection detail');
    });
    $email = (new Email)->from('support@example.test')->to('person@example.test')->text('Synthetic message');
    try {
        (new MicrosoftGraphTransport(itLoopTransportIdentity()))->send($email);
        $this->fail('Expected uncertain transport outcome.');
    } catch (TransportException $exception) {
        expect($exception->getMessage())->not->toContain('Synthetic private connection detail')
            ->and($exception->getMessage())->toContain('Reconcile the delivery outcome before retrying.');
    }
    expect($attempts)->toBe(1);
});
