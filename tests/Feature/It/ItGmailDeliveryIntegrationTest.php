<?php

use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Mail\GoogleGmailTransport;
use App\Models\AuditLog;
use App\Models\ItEmailDelivery;
use App\Models\ItMailboxConnection;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

test('the canonical outbox records Gmail acceptance separately from the submitted reply identity and never blindly resends', function (bool $lostResponse) {
    Http::preventStrayRequests();
    $this->seed(RbacSeeder::class);
    $recipient = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $recipient->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($recipient);
    $connection = ItMailboxConnection::create([
        'provider' => 'google', 'status' => 'connected', 'account_email' => 'support@example.test',
        'access_token' => 'synthetic-access', 'token_expires_at' => now()->addHour(),
        'scopes' => ['https://www.googleapis.com/auth/gmail.modify'],
    ]);
    // Test-local transport wiring: the production settings/channel integration is still W12 work.
    $mailer = Mail::mailer('array');
    $mailer->setSymfonyTransport(new GoogleGmailTransport($connection));
    $mailer->alwaysFrom('support@example.test');
    $mailer->alwaysReplyTo('support@example.test');
    $attempts = 0;
    $submittedId = null;
    Http::fake(function ($request) use ($lostResponse, &$attempts, &$submittedId) {
        $attempts++;
        expect($request->url())->toBe('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
        $mime = base64_decode(strtr($request['raw'], '-_', '+/'), true);
        preg_match('/^Message-ID: (<[^>]+>)/mi', $mime, $match);
        $submittedId = $match[1] ?? null;
        $delivery = ItEmailDelivery::query()->sole();
        expect($submittedId)->not->toBeNull()
            ->and($delivery->rfc_message_id)->toBe($submittedId)
            ->and($delivery->status)->toBe('sending')
            ->and($mime)->toContain('Auto-Submitted: auto-generated', 'Reply-To: support@example.test');
        if ($lostResponse) {
            throw new ConnectionException('Synthetic private provider detail');
        }

        return Http::response(['id' => 'gmail-accepted-id', 'threadId' => 'gmail-thread-id'], 200);
    });

    app(ItTicketIntakeService::class)->createCommand($recipient, [
        'request_uuid' => (string) Str::uuid(), 'title' => 'Synthetic Gmail outbox integration', 'category' => 'other', 'priority' => 'normal',
    ]);
    $outbox = app(ItEmailDeliveryService::class);
    expect($outbox->dispatchPending())->toBe(1)->and($outbox->dispatchPending())->toBe(0);
    $delivery = ItEmailDelivery::query()->sole();
    expect($attempts)->toBe(1)->and($recipient->notifications()->count())->toBe(1)
        ->and($delivery->attempt_count)->toBe(1)->and($delivery->rfc_message_id)->toBe($submittedId)
        ->and($delivery->getRawOriginal('rfc_message_id'))->not->toBe($submittedId)
        ->and(AuditLog::where('action', 'it.email.message_identity_recorded')->count())->toBe(1);
    if ($lostResponse) {
        expect($delivery->status)->toBe('sending')->and($delivery->accepted_at)->toBeNull()
            ->and($delivery->failed_at)->toBeNull()->and($delivery->provider_message_id)->toBeNull()
            ->and($delivery->last_error)->toBe('Delivery outcome is unknown. Reconcile the provider result before retrying.');
    } else {
        expect($delivery->status)->toBe('accepted')->and($delivery->accepted_at)->not->toBeNull()
            ->and($delivery->provider_message_id)->toBe('gmail-accepted-id')
            ->and($delivery->delivered_at)->toBeNull();
    }
})->with([false, true]);
