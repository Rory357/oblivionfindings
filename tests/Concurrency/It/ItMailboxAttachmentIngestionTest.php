<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\InboundEmailIngestor;
use App\Jobs\PollItMailboxJob;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItInboundEmail;
use App\Models\ItMailboxConnection;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use App\Services\Files\MalwareScanResult;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** Run alone: canonical commits, local private files, synthetic provider/scanner boundaries. */
final class ItMailboxAttachmentIngestionTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT wrapper for mailbox file ingestion.');
        }

        return parent::createApplication();
    }

    public function test_both_provider_files_use_canonical_commands_with_scan_provenance_and_recovery(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('array', config('mail.default'));
        Http::preventStrayRequests();
        Notification::fake();
        config(['it.drafts.enabled' => false]);
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $root = configureItDraftConcurrencyStorage();
        $this->beforeApplicationDestroyed(fn () => removeItDraftConcurrencyStorage($root));
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $sender = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'email_verified_at' => now()]);
        $stranger = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'email_verified_at' => now()]);
        foreach ([$sender, $stranger] as $actor) {
            $actor->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
            HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
                'is_active' => true, 'start_date' => today()->subMonth(), 'end_date' => null]);
        }
        $scanner = new class extends MalwareScanner
        {
            public MalwareScanDisposition $verdict = MalwareScanDisposition::Unavailable;

            public function scanPath(string $path, array $settings): MalwareScanResult
            {
                if (! is_file($path)) {
                    throw new RuntimeException('A real private test file is required.');
                }

                return new MalwareScanResult($this->verdict, 'synthetic-scanner',
                    $this->verdict === MalwareScanDisposition::Unavailable ? 'scanner_unavailable' : null);
            }
        };
        $this->app->instance(MalwareScanner::class, $scanner);
        $failReceipt = false;
        ItInboundEmail::saving(function (ItInboundEmail $receipt) use (&$failReceipt): void {
            if ($failReceipt && $receipt->status === 'processed') {
                throw new RuntimeException('Synthetic final receipt failure.');
            }
        });

        foreach (['google', 'microsoft'] as $provider) {
            $scanner->verdict = MalwareScanDisposition::Unavailable;
            $ticketCount = ItTicket::count();
            $connection = ItMailboxConnection::create(['provider' => $provider, 'status' => 'connected',
                'account_email' => 'support@demo.test', 'created_by' => $sender->id,
                'access_token' => 'synthetic-token', 'token_expires_at' => now()->addDay()]);
            $remote = $provider.'-new';
            $identity = '<'.$provider.'-new@demo.test>';
            $subject = 'Synthetic email evidence';
            $body = 'Please inspect the attached evidence.';
            $author = $sender->email;
            $parent = null;
            $bytes = 'Private '.$provider.' email evidence';
            $originalBytes = $bytes;
            $reads = $downloads = $acks = [];
            $failAck = true;
            Http::fake(function ($request) use ($provider, &$remote, &$identity, &$subject, &$body, &$author, &$parent, &$bytes, &$reads, &$downloads, &$acks, &$failAck) {
                // Laravel keeps earlier fake callbacks: each provider owns only its own host.
                if (parse_url($request->url(), PHP_URL_HOST) !== ($provider === 'google' ? 'gmail.googleapis.com' : 'graph.microsoft.com')) {
                    return null;
                }
                $this->assertSame(0, DB::transactionLevel(), 'Provider HTTP must not run under database locks.');
                $url = (string) parse_url($request->url(), PHP_URL_PATH);
                $base = $provider === 'google' ? '/gmail/v1/users/me/messages' : '/v1.0/users/support%40demo.test/messages';
                $detail = $base.'/'.$remote;
                if (($provider === 'google' && $url === $detail.'/modify') || ($provider === 'microsoft' && $url === $detail && $request->method() === 'PATCH')) {
                    $acks[$remote] = ($acks[$remote] ?? 0) + 1;
                    if ($failAck) {
                        $failAck = false;

                        return Http::response([], 503);
                    }

                    return Http::response([]);
                }
                if ($url === ($provider === 'google' ? $base : '/v1.0/users/support%40demo.test/mailFolders/inbox/messages')) {
                    return Http::response($provider === 'google' ? ['messages' => [['id' => $remote]], 'resultSizeEstimate' => 1] : ['value' => [['id' => $remote]]]);
                }
                $row = ['@odata.type' => '#microsoft.graph.fileAttachment', 'id' => 'file-1', 'name' => 'evidence.txt',
                    'contentType' => 'text/plain', 'size' => strlen($bytes), 'isInline' => false];
                if ($url === $detail.'/attachments') {
                    return Http::response(['value' => [$row]]);
                }
                if ($url === $detail.'/attachments/file-1') {
                    $downloads[$remote] = ($downloads[$remote] ?? 0) + 1;

                    return Http::response($provider === 'google' ? ['size' => strlen($bytes), 'data' => base64_encode($bytes)] : [...$row, 'contentBytes' => base64_encode($bytes)]);
                }
                if ($url === $detail) {
                    $reads[$remote] = ($reads[$remote] ?? 0) + 1;
                    $headers = [['name' => 'From', 'value' => $author], ['name' => 'Subject', 'value' => $subject], ['name' => 'Message-ID', 'value' => $identity]];
                    if ($parent !== null) {
                        $headers[] = ['name' => 'In-Reply-To', 'value' => $parent];
                    }

                    return Http::response($provider === 'google' ? ['id' => $remote, 'payload' => [
                        'mimeType' => 'multipart/mixed', 'headers' => $headers, 'parts' => [
                            ['mimeType' => 'text/plain', 'body' => ['data' => base64_encode($body), 'size' => strlen($body)]],
                            ['mimeType' => 'text/plain', 'filename' => 'evidence.txt', 'body' => ['attachmentId' => 'file-1', 'size' => strlen($bytes)]],
                        ],
                    ]] : ['id' => $remote, 'subject' => $subject, 'from' => ['emailAddress' => ['address' => $author]],
                        'internetMessageId' => $identity, 'internetMessageHeaders' => $headers, 'body' => ['contentType' => 'text', 'content' => $body]]);
                }
                throw new RuntimeException('Unexpected synthetic provider path.');
            });
            $poll = function () use ($connection): void {
                $this->app['auth']->forgetGuards();
                (new PollItMailboxJob($connection->id))->handle(app(InboundEmailIngestor::class));
            };
            $receipt = function () use ($connection, &$remote): ?ItInboundEmail {
                return ItInboundEmail::where('it_mailbox_connection_id', $connection->id)->get()
                    ->first(fn (ItInboundEmail $row) => $row->remote_message_id === $remote);
            };

            $poll();
            $this->assertSame($ticketCount, ItTicket::count());
            $this->assertSame('scanner_unavailable', $connection->refresh()->last_poll_failure_code);
            $this->assertNull($connection->last_polled_at);
            $this->assertSame('pending', $receipt()->status);
            $this->assertSame(0, $acks[$remote] ?? 0);
            $stageId = $receipt()->attachments()->sole()->id;

            $scanner->verdict = MalwareScanDisposition::Clean;
            $this->travel(3)->minutes();
            $poll();
            $accepted = $receipt();
            $this->assertSame('processed', $accepted->status);
            $this->assertSame($ticketCount + 1, ItTicket::count());
            $this->assertSame('unavailable', $connection->refresh()->last_poll_failure_code);
            $this->assertNull($accepted->acknowledged_at);
            $ticket = $accepted->ticket;
            $file = $ticket->attachments()->sole();
            $this->assertSame($sender->id, $file->uploaded_by);
            $this->assertSame($stageId, $file->source_inbound_attachment_id);
            $this->assertSame('clean', $file->malware_scan_status);
            $this->assertSame(hash('sha256', $bytes), $file->inbound_content_hash);
            $this->assertSame($bytes, Storage::disk('private')->get($file->path));
            $this->assertSame('deleted', ItAttachment::findOrFail($stageId)->inbound_storage_state);
            $this->actingAs($sender)->get('/it/attachments/'.$file->id)->assertOk();
            $this->actingAs($stranger)->get('/it/attachments/'.$file->id)->assertNotFound();
            $readCount = $reads[$remote];
            $downloadCount = $downloads[$remote];
            $this->travel(3)->minutes();
            $poll();
            $this->assertNotNull($receipt()->acknowledged_at);
            $this->assertSame('connected', $connection->refresh()->status);
            $this->assertSame($readCount, $reads[$remote]);
            $this->assertSame($downloadCount, $downloads[$remote]);
            $this->assertSame($ticketCount + 1, ItTicket::count());

            $originalIdentity = $identity;
            $remote = $provider.'-duplicate';
            $poll();
            $this->assertSame('duplicate', $receipt()->status);
            $this->assertSame($ticket->id, $receipt()->it_ticket_id);
            $this->assertSame(1, $ticket->attachments()->count());
            $this->assertSame($ticketCount + 1, ItTicket::count());

            $remote = $provider.'-unknown';
            $identity = '<'.$remote.'@demo.test>';
            $author = 'unknown-synthetic@demo.test';
            $poll();
            $this->assertSame('sender_unknown', $receipt()->quarantine_reason);
            $this->assertNull($receipt()->it_ticket_id);
            $this->actingAs($sender)->get('/it/attachments/'.$receipt()->attachments()->sole()->id)->assertNotFound();

            $remote = $provider.'-reply';
            $identity = '<'.$remote.'@demo.test>';
            $author = $sender->email;
            $parent = $originalIdentity;
            $subject = 'Entirely changed subject';
            $body = 'Additional public evidence';
            $poll();
            $this->assertSame('processed', $receipt()->status);
            $this->assertSame($ticket->id, $receipt()->it_ticket_id);
            $comment = $ticket->comments()->sole();
            $this->assertFalse($comment->is_internal);
            $replyFile = $comment->attachments()->sole();
            $this->assertSame('clean', $replyFile->malware_scan_status);
            $this->assertSame($bytes, Storage::disk('private')->get($replyFile->path));
            $this->actingAs($sender)->get('/it/attachments/'.$replyFile->id)->assertOk();

            // Isolate a file-only identity collision after proving the valid reply first.
            $remote = $provider.'-collision';
            $identity = $originalIdentity;
            $parent = null;
            $subject = 'Synthetic email evidence';
            $body = 'Please inspect the attached evidence.';
            $bytes = str_repeat('x', strlen($bytes));
            $poll();
            $this->assertSame('quarantined', $receipt()->status);
            $this->assertSame('message_id_collision', $receipt()->quarantine_reason);
            $this->assertNull($receipt()->body_preview);
            $this->assertSame($ticketCount + 1, ItTicket::count());
            $this->assertSame($originalBytes, Storage::disk('private')->get($file->path));

            $remote = $provider.'-ambiguous-reply';
            $identity = '<'.$remote.'@demo.test>';
            $parent = $originalIdentity;
            $poll();
            $this->assertSame('reference_ambiguous', $receipt()->quarantine_reason);
            $this->assertSame(1, $ticket->comments()->count());

            $remote = $provider.'-infected';
            $identity = '<'.$remote.'@demo.test>';
            $parent = null;
            $scanner->verdict = MalwareScanDisposition::Infected;
            $poll();
            $this->assertSame('attachment_infected', $receipt()->quarantine_reason);
            $this->assertSame('infected', $receipt()->attachments()->sole()->malware_scan_status);
            $this->assertNotNull($receipt()->acknowledged_at);

            $remote = $provider.'-rollback';
            $identity = '<'.$remote.'@demo.test>';
            $scanner->verdict = MalwareScanDisposition::Clean;
            $deletedBefore = ItAttachmentStorageIntent::where('state', 'deleted')->count();
            $failReceipt = true;
            $poll();
            $failReceipt = false;
            $this->assertSame('pending', $receipt()->status);
            $this->assertSame($ticketCount + 1, ItTicket::count());
            $this->assertSame($deletedBefore + 1, ItAttachmentStorageIntent::where('state', 'deleted')->count());
            $reserved = $receipt()->attachments()->sole();
            $this->assertSame('ready', $reserved->inbound_storage_state);
            $this->assertSame(0, ItAttachment::where('source_inbound_attachment_id', $reserved->id)->count());
            $this->travel(3)->minutes();
            $poll();
            $this->assertSame('processed', $receipt()->status);
            $this->assertSame($ticketCount + 2, ItTicket::count());
            $this->assertSame(1, ItAttachment::where('source_inbound_attachment_id', $reserved->id)->count());
        }
    }
}
