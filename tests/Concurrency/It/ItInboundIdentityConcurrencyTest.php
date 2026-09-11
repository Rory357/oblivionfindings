<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ItEmailDelivery;
use App\Models\ItInboundEmail;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Standalone committed fixtures; use the isolated wrapper in its own process. */
final class ItInboundIdentityConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1
            || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated wrapper for inbound identity concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_simultaneous_identical_and_conflicting_messages_claim_only_one_ticket(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $actor->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id, 'primary_site_id' => Site::factory()->create()->id,
            'is_active' => true, 'start_date' => today()->subMonth(), 'end_date' => null,
        ]);

        $identical = $this->race($actor, 'identical', ['same', 'same']);
        $this->assertSame(['processed', 'processed'], array_column($identical, 'status'));
        $this->assertSame($identical[0]['id'], $identical[1]['id']);
        $this->assertSame(1, ItInboundEmail::count());
        $this->assertSame(1, ItTicket::count());
        $this->assertSame(1, ItTicket::firstOrFail()->events()->where('type', 'created')->count());
        $this->assertSame(1, ItTicketCommandReceipt::where('channel', 'email')->count());
        $this->assertSame(1, AuditLog::where('action', 'it.ticket.created')->count());
        $this->assertSame(1, ItEmailDelivery::count());

        $conflicting = $this->race($actor, 'conflicting', ['first content', 'different content']);
        $statuses = array_column($conflicting, 'status');
        sort($statuses);
        $this->assertSame(['processed', 'quarantined'], $statuses);
        $this->assertSame(2, ItTicket::count());
        $this->assertSame(3, ItInboundEmail::count());
        $this->assertSame(2, ItInboundEmail::whereNotNull('identity_claim_key')->count());
        $quarantine = ItInboundEmail::where('status', 'quarantined')->sole();
        $this->assertSame('message_id_collision', $quarantine->quarantine_reason);
        $this->assertNull($quarantine->it_ticket_id);
        $this->assertNull($quarantine->body_preview);
        $this->assertSame(2, ItTicketCommandReceipt::where('channel', 'email')->count());
        $this->assertSame(2, AuditLog::where('action', 'it.ticket.created')->count());
        $this->assertSame(2, ItEmailDelivery::count());

        $ticket = ItTicket::orderBy('id')->firstOrFail();
        $version = $ticket->lock_version;
        $replies = $this->race($actor, 'reply', ['same reply', 'same reply'], $ticket);
        $this->assertSame(['processed', 'processed'], array_column($replies, 'status'));
        $this->assertSame($replies[0]['id'], $replies[1]['id']);
        $this->assertSame(1, $ticket->comments()->count());
        $this->assertSame('email', $ticket->comments()->sole()->source_channel);
        $this->assertGreaterThan($version, $ticket->fresh()->lock_version);
        $this->assertSame(1, AuditLog::where('action', 'it.ticket.comment.added')->count());
        $this->assertSame(1, ItTicketCommandReceipt::where('channel', 'email')->where('operation', 'ticket.comment')->count());
        $this->assertSame(2, ItTicket::count());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function race(User $actor, string $identity, array $bodies, ?ItTicket $ticket = null): array
    {
        $barrier = storage_path('framework/testing/inbound-identity-'.getenv('TEST_TOKEN').'-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $paths = [$barrier.'.release'];
        $workers = [];
        try {
            foreach ($bodies as $index => $body) {
                $paths[] = $ready = $barrier.'-'.$index.'.ready';
                $workers[] = $worker = new Process([
                    PHP_BINARY, base_path('tests/Support/It/inbound-identity-concurrency-worker.php'),
                    (string) $actor->id, $identity, $body, $ready, $barrier.'.release', (string) ($ticket?->id ?? 0),
                ], base_path(), timeout: 45);
                $worker->start();
            }
            $deadline = microtime(true) + 30;
            while (! is_file($barrier.'-0.ready') || ! is_file($barrier.'-1.ready')) {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        throw new RuntimeException('Identity worker exited before both insertion barriers: '.$worker->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Identity insertion barrier timed out.');
                }
                usleep(10_000);
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning(), 'Both workers passed the absent-identity lookup before either insertion.');
            }
            $releasedAt = microtime(true);
            touch($barrier.'.release');
            $results = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertLessThan($releasedAt, $result['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $result['completed_at']);
                $results[] = $result;
            }

            return $results;
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
