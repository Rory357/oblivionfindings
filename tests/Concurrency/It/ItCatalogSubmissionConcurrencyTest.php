<?php

namespace Tests\Concurrency\It;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItCatalogSubmissionService;
use App\Models\AuditLog;
use App\Models\ItAttachment;
use App\Models\ItAttachmentStorageIntent;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItEmailDelivery;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Run alone through the isolated wrapper; workers share only this disposable schema. */
final class ItCatalogSubmissionConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/', (string) getenv('TEST_TOKEN')) !== 1 || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT wrapper for catalogue concurrency verification.');
        }

        return parent::createApplication();
    }

    public function test_real_workers_converge_on_one_submission_or_cancellation(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertMatchesRegularExpression('/^oblivion_it_support_test_it_[a-f0-9]{16}$/', DB::connection()->getDatabaseName());
        Notification::fake();
        Http::preventStrayRequests();
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $root = configureItDraftConcurrencyStorage();
        $this->beforeApplicationDestroyed(fn () => removeItDraftConcurrencyStorage($root));
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $actor->roles()->sync(Role::where('name', 'support_worker')->pluck('id'));
        $site = Site::factory()->create();
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => now()->subMonth(), 'end_date' => null]);
        foreach (['service_request' => ItTicket::class, 'provisioning' => ItProvisioningRequest::class] as $type => $model) {
            $item = ItCatalogItem::factory()->create(['outcome_type' => $type,
                'provisioning_type' => $type === 'provisioning' ? 'equipment' : null,
                'form_schema' => ['fields' => [
                    ['key' => 'evidence', 'label' => 'Evidence', 'type' => 'attachment', 'required' => true, 'visibility' => 'requester'],
                    ['key' => 'extra', 'label' => 'Additional evidence', 'type' => 'attachment', 'required' => true, 'visibility' => 'requester'],
                ]]]);
            foreach ([['submit', 'submit'], ['submit', 'cancel']] as $operations) {
                $uuid = (string) Str::uuid();
                $before = $model::count();
                $beforeFiles = ItAttachment::count();
                $beforeIntents = ItAttachmentStorageIntent::count();
                $cancelAudits = AuditLog::where('action', 'it.catalogue.submission.cancelled')->count();
                $race = $this->race($actor, $item, $uuid, $operations);
                $this->assertSame($race[0]['status'], $race[1]['status']);
                $this->assertContains($race[0]['status'], ['committed', 'cancelled']);
                if ($operations === ['submit', 'submit']) {
                    $this->assertSame('committed', $race[0]['status']);
                    $created = array_column($race, 'created');
                    sort($created);
                    $this->assertSame([false, true], $created);
                }
                $committed = $race[0]['status'] === 'committed';
                $this->assertSame($before + (int) $committed, $model::count());
                $this->assertSame($beforeFiles + 2 * (int) $committed, ItAttachment::count());
                $this->assertSame($beforeIntents + 2 * (int) $committed, ItAttachmentStorageIntent::count());
                $this->assertSame(ItAttachment::count(), count(Storage::disk(ItAttachment::DISK)->allFiles()));
                $this->assertSame((int) $committed, ItCatalogSubmission::where('idempotency_key', $uuid)->count());
                $this->assertSame((int) ! $committed, ItTicketCommandReceipt::where('operation', ItTicketCommandReceipt::CATALOGUE_OPERATION)->where('request_uuid', $uuid)->count());
                $this->assertSame($cancelAudits + (int) ! $committed, AuditLog::where('action', 'it.catalogue.submission.cancelled')->count());
                $recovered = app(ItCatalogSubmissionService::class)->recover($item->id, $actor, $uuid, $actor->id);
                if ($committed) {
                    $this->assertSame($race[0]['id'], $race[1]['id']);
                    $this->assertSame($race[0]['submission_id'], $race[1]['submission_id']);
                    $this->assertSame($race[0]['id'], $recovered['result']->id);
                    $this->assertSame($race[0]['submission_id'], $recovered['submission']->id);
                    $this->assertSame($item->published_version_id, $recovered['submission']->catalog_version_id);
                    $files = $recovered['submission']->attachments()->orderBy('id')->get();
                    $this->assertSame(['evidence', 'extra'], $files->pluck('catalogue_field_key')->all());
                    $this->assertSame(['proof.txt', 'extra.txt'], $files->pluck('original_name')->all());
                    foreach ($files as $index => $file) {
                        $this->assertSame($index === 0 ? 'Synthetic concurrent evidence' : 'Synthetic additional evidence',
                            Storage::disk(ItAttachment::DISK)->get($file->path));
                        $this->assertSame('attached', ItAttachmentStorageIntent::where('attachment_id', $file->id)->sole()->state);
                    }
                    if ($type === 'service_request') {
                        $ticket = $recovered['result'];
                        $this->assertSame(1, $ticket->events()->where('type', 'created')->count());
                        $this->assertSame(1, ItEmailDelivery::where('it_ticket_id', $ticket->id)->count());
                        $this->assertNotNull(ItEmailDelivery::where('it_ticket_id', $ticket->id)->sole()->dispatch_requested_at);
                        $this->assertNull(ItEmailDelivery::where('it_ticket_id', $ticket->id)->sole()->dispatch_finished_at);
                    }
                } else {
                    $this->assertTrue($recovered['cancelled']);
                    $late = app(ItCatalogSubmissionService::class)->submit($item, $actor, [
                        'idempotency_key' => $uuid, 'schema_version' => 1, 'values' => [],
                    ]);
                    $this->assertTrue($late['cancelled']);
                    $this->assertSame($before, $model::count());
                }
            }
        }
        Notification::assertNothingSent();
    }

    private function race(User $actor, ItCatalogItem $item, string $uuid, array $operations): array
    {
        $barrier = storage_path('framework/testing/it-catalogue-concurrency-'.getenv('TEST_TOKEN').'-'.Str::uuid());
        if (! is_dir(dirname($barrier))) {
            mkdir(dirname($barrier), 0775, true);
        }
        $processes = [];
        $paths = [];
        DB::beginTransaction();
        User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        try {
            foreach ($operations as $index => $operation) {
                $ready = $barrier.'-'.$index.'.ready';
                $attempt = $barrier.'-'.$index.'.attempt';
                $paths[] = $ready;
                $paths[] = $attempt;
                $processes[] = $worker = new Process([PHP_BINARY, base_path('tests/Support/It/catalogue-submission-concurrency-worker.php'),
                    $operation, (string) $actor->id, (string) $item->id, $uuid, $ready, $attempt, $barrier.'.release'], base_path(), timeout: 45);
                $worker->start();
            }
            $this->barriers([$barrier.'-0.ready', $barrier.'-1.ready'], $processes);
            $paths[] = $barrier.'.release';
            touch($barrier.'.release');
            $this->barriers([$barrier.'-0.attempt', $barrier.'-1.attempt'], $processes);
            $releasedAt = microtime(true);
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both workers reached the command while its actor remained locked.');
            }
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));
                $result = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
                $this->assertLessThanOrEqual($releasedAt, $result['attempted_at']);
                $this->assertGreaterThanOrEqual($releasedAt, $result['completed_at']);
                $results[] = $result;
            }

            return $results;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function barriers(array $paths, array $processes): void
    {
        $deadline = microtime(true) + 30;
        while (count(array_filter($paths, 'is_file')) !== count($paths)) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException('Catalogue worker stopped before its barrier: '.trim($process->getErrorOutput()));
                }
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Catalogue worker barrier timed out.');
            }
            usleep(10_000);
        }
    }
}
