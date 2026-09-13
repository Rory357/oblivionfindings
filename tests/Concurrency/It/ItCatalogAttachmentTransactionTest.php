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
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Run alone: actual outer rollback, private bytes and commit acknowledgement. */
final class ItCatalogAttachmentTransactionTest extends TestCase
{
    public function createApplication()
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'oblivion_it_support_test'
            || preg_match('/^it_[a-f0-9]{16}$/D', (string) getenv('TEST_TOKEN')) !== 1
            || getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('Use the isolated IT wrapper for catalogue attachment transactions.');
        }

        return parent::createApplication();
    }

    public function test_outer_rollback_removes_partial_files_and_acknowledgement_failure_preserves_committed_evidence(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame('array', config('mail.default'));
        $this->assertSame('sync', config('queue.default'));
        Http::preventStrayRequests();
        Notification::fake();
        config(['it.drafts.enabled' => false]);
        require_once base_path('tests/Support/It/draft-concurrency-filesystem.php');
        $root = configureItDraftConcurrencyStorage();
        $this->beforeApplicationDestroyed(fn () => removeItDraftConcurrencyStorage($root));
        $this->seed(RbacSeeder::class);
        $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
        $actor->roles()->sync(Role::where('name', 'hr')->pluck('id'));
        $site = Site::factory()->create();
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'is_active' => true, 'start_date' => today()->subMonth(), 'end_date' => null]);
        $commands = app(ItCatalogSubmissionService::class);
        $disk = Storage::disk(ItAttachment::DISK);
        $faultMode = null;
        // Eloquent shares one dispatcher across all models. Keep its model
        // boot listeners intact while arming this one synthetic audit fault.
        AuditLog::creating(function (AuditLog $audit) use (&$faultMode): void {
            if ($audit->action !== 'it.catalogue.attachments.recorded') {
                return;
            }
            if ($faultMode === 'audit') {
                throw new RuntimeException('Synthetic catalogue evidence audit failure');
            }
            if ($faultMode === 'acknowledgement') {
                DB::afterCommit(fn () => throw new RuntimeException('Synthetic catalogue commit acknowledgement failure'));
            }
        });
        try {
            foreach (['service_request', 'provisioning'] as $type) {
                $item = ItCatalogItem::factory()->create(['outcome_type' => $type,
                    'provisioning_type' => $type === 'provisioning' ? 'equipment' : null,
                    'form_schema' => ['fields' => [
                        ['key' => 'public_files', 'label' => 'Supporting files', 'type' => 'attachment', 'required' => true, 'visibility' => 'requester'],
                        ['key' => 'private_files', 'label' => 'IT evidence', 'type' => 'attachment', 'required' => true, 'visibility' => 'internal'],
                    ]]]);
                foreach (['partial_write', 'audit'] as $fault) {
                    $input = $this->input($actor, $site);
                    $before = $this->counts();
                    $existingPaths = $disk->allFiles();
                    $firstIntent = (int) ItAttachmentStorageIntent::max('id');
                    $faultMode = $fault;
                    if ($fault === 'partial_write') {
                        $writes = 0;
                        $broken = Mockery::mock($disk)->makePartial();
                        $broken->shouldReceive('putFileAs')->twice()->andReturnUsing(
                            function ($directory, $file, $name) use ($disk, &$writes) {
                                return ++$writes === 2 ? false : $disk->putFileAs($directory, $file, $name);
                            });
                        Storage::set(ItAttachment::DISK, $broken);
                    }
                    try {
                        $commands->submit($item, $actor, $input);
                        $this->fail('The failing catalogue command must not claim success.');
                    } catch (DomainException|RuntimeException $failure) {
                        $this->assertSame($fault === 'audit' ? 'Synthetic catalogue evidence audit failure'
                            : 'The attachment could not be stored. Keep the original submission and try again.', $failure->getMessage());
                    } finally {
                        $faultMode = null;
                        Storage::set(ItAttachment::DISK, $disk);
                    }
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertSame($before, $this->counts());
                    $this->assertSame($existingPaths, $disk->allFiles());
                    $intents = ItAttachmentStorageIntent::where('id', '>', $firstIntent)->get();
                    $this->assertCount(2, $intents);
                    $this->assertSame(['deleted'], $intents->pluck('state')->unique()->values()->all());
                    $this->assertSame(2, AuditLog::where('action', 'it.attachment.cleanup.completed')
                        ->whereIn('auditable_id', $intents->modelKeys())->count());

                    // A fresh attempt with the same key, bytes and filenames is safe.
                    $saved = $commands->submit($item, $actor, $input);
                    $this->assertTrue($saved['created']);
                    $this->assertEvidence($saved['submission']);
                    $after = $this->counts();
                    $replayed = $commands->submit($item, $actor, $input);
                    $this->assertFalse($replayed['created']);
                    $this->assertSame($saved['submission']->id, $replayed['submission']->id);
                    $this->assertSame($after, $this->counts());
                    $this->assertSame(4, ItAttachmentStorageIntent::where('id', '>', $firstIntent)->count());
                }

                // Simulate loss of the final acknowledgement after physical commit.
                $input = $this->input($actor, $site);
                $firstIntent = (int) ItAttachmentStorageIntent::max('id');
                $faultMode = 'acknowledgement';
                try {
                    $commands->submit($item, $actor, $input);
                    $this->fail('Expected the lost commit acknowledgement.');
                } catch (RuntimeException $failure) {
                    $this->assertSame('Synthetic catalogue commit acknowledgement failure', $failure->getMessage());
                } finally {
                    $faultMode = null;
                }
                $this->assertSame(0, DB::transactionLevel());
                $submission = ItCatalogSubmission::where('idempotency_key', $input['idempotency_key'])->sole();
                $this->assertEvidence($submission);
                $after = $this->counts();
                $recovered = $commands->recover($item->id, $actor, $input['idempotency_key'], $actor->id);
                $this->assertSame($submission->id, $recovered['submission']->id);
                $this->assertFalse($commands->submit($item, $actor, $input)['created']);
                $this->assertSame($after, $this->counts());
                $this->assertSame(2, ItAttachmentStorageIntent::where('id', '>', $firstIntent)->where('state', 'attached')->count());
                $this->assertSame(0, ItAttachmentStorageIntent::where('id', '>', $firstIntent)->where('state', 'deleted')->count());
            }
            Notification::assertNothingSent();
        } finally {
            $faultMode = null;
            removeItDraftConcurrencyStorage($root);
            $this->assertDirectoryDoesNotExist($root);
        }
    }

    private function input(User $actor, Site $site): array
    {
        return ['actor_user_id' => $actor->id, 'schema_version' => 1, 'site_id' => $site->id,
            'idempotency_key' => (string) Str::uuid(), 'values' => [
                'public_files' => [UploadedFile::fake()->createWithContent('public.txt', 'Synthetic public evidence')],
                'private_files' => [UploadedFile::fake()->createWithContent('private.txt', 'Synthetic private evidence')],
            ]];
    }

    private function counts(): array
    {
        return [ItTicket::count(), ItProvisioningRequest::count(), ItCatalogSubmission::count(), ItAttachment::count(), ItEmailDelivery::count()];
    }

    private function assertEvidence(ItCatalogSubmission $submission): void
    {
        $files = $submission->attachments()->orderBy('id')->get();
        $this->assertCount(2, $files);
        $this->assertSame(['public_files', 'private_files'], $files->pluck('catalogue_field_key')->all());
        foreach ($files as $index => $file) {
            $this->assertSame($index === 0 ? 'Synthetic public evidence' : 'Synthetic private evidence',
                Storage::disk(ItAttachment::DISK)->get($file->path));
            $this->assertSame('attached', ItAttachmentStorageIntent::where('attachment_id', $file->id)->sole()->state);
        }
    }
}
