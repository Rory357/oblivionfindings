<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Jobs\GenerateRecurringJournalsJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinRecurringJournal;
use App\Domain\Finance\Models\FinRecurringJournalOccurrence;
use App\Domain\Finance\Services\JournalPostingService;
use App\Domain\Finance\Services\RecurringJournalService;
use App\Models\Permission;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RecurringJournalOccurrenceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const ORGANIZATION_ID = 1;

    private User $actor;

    private FinAccount $cash;

    private FinAccount $revenue;

    private RecurringJournalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-23 03:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23 03:00:00', 'UTC'));

        $this->actor = User::factory()->create([
            'organization_id' => self::ORGANIZATION_ID,
            'approved_at' => now(),
        ]);
        $this->cash = FinAccount::factory()->create([
            'organization_id' => self::ORGANIZATION_ID,
            'code' => '1000',
            'name' => 'Recurring journal cash',
            'type' => 'asset',
            'is_active' => true,
        ]);
        $this->revenue = FinAccount::factory()->create([
            'organization_id' => self::ORGANIZATION_ID,
            'code' => '4000',
            'name' => 'Recurring journal revenue',
            'type' => 'revenue',
            'is_active' => true,
        ]);
        FinFiscalPeriod::query()->create([
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Recurring journal August 2026',
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-31',
            'status' => 'open',
            'created_by' => $this->actor->id,
        ]);
        $this->service = app(RecurringJournalService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_same_schedule_date_replay_returns_one_posted_journal_and_database_identity_is_unique(): void
    {
        $recurring = $this->recurringJournal();

        $journal = $this->service->processOccurrence(
            $recurring->id,
            '2026-08-23',
            self::ORGANIZATION_ID,
        );
        $replay = $this->service->processOccurrence(
            $recurring->id,
            '2026-08-23',
            self::ORGANIZATION_ID,
        );
        $occurrence = FinRecurringJournalOccurrence::query()->sole();

        $this->assertSame($journal->id, $replay->id);
        $this->assertSame('posted', $occurrence->status);
        $this->assertSame(1, $occurrence->attempt_count);
        $this->assertSame($journal->id, $occurrence->journal_id);
        $this->assertSame(
            FinRecurringJournalOccurrence::buildOccurrenceKey(
                $recurring->id,
                CarbonImmutable::parse('2026-08-23', 'UTC'),
            ),
            $occurrence->occurrence_key,
        );
        $this->assertSame('recurring', $journal->type);
        $this->assertSame('REC-'.$recurring->id.'-20260823', $journal->reference);
        $this->assertSame(FinRecurringJournalOccurrence::class, $journal->source_type);
        $this->assertSame($occurrence->id, $journal->source_id);
        $this->assertSame(1, FinJournal::query()->count());
        $this->assertSame(1, $occurrence->attempts()->count());
        $this->assertSame('posted', $occurrence->attempts()->sole()->outcome);
        $this->assertSame('2026-08-23', $recurring->fresh()->last_run_date->toDateString());
        $this->assertSame('2026-08-24', $recurring->fresh()->next_run_date->toDateString());

        try {
            FinJournal::query()->create([
                'organization_id' => self::ORGANIZATION_ID,
                'journal_number' => 'JNL-999999',
                'journal_date' => '2026-08-23',
                'type' => 'recurring',
                'reference' => 'NON-CANONICAL-DUPLICATE',
                'source_type' => FinRecurringJournalOccurrence::class,
                'source_id' => $occurrence->id,
                'status' => 'posted',
                'total_amount' => '125.50',
            ]);
            $this->fail('The posted occurrence source key must reject a second journal effect.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, FinJournal::query()->where('status', 'posted')->count());

        try {
            $occurrence->delete();
            $this->fail('A governed recurring occurrence must remain durable.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }

        try {
            DB::table('fin_recurring_journal_occurrences')
                ->where('id', $occurrence->id)
                ->delete();
            $this->fail('Attempt history must prevent direct deletion of its occurrence identity.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, FinRecurringJournalOccurrence::query()->count());

        $migration = require database_path(
            'migrations/2026_08_23_000090_govern_recurring_journal_occurrences.php',
        );
        try {
            $migration->down();
            $this->fail('Retained occurrence evidence must prevent a destructive rollback.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retained occurrence or attempt evidence', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('fin_recurring_journal_occurrences'));
        $this->assertTrue(Schema::hasTable('fin_recurring_journal_occurrence_attempts'));
        $this->assertTrue(Schema::hasColumn('fin_journals', 'recurring_occurrence_posted_source_id'));
    }

    public function test_failure_after_post_rolls_back_every_effect_and_the_job_retry_preserves_attempt_history(): void
    {
        $recurring = $this->recurringJournal();
        $failingPosting = new class extends JournalPostingService
        {
            public function createAndPost(?int $orgId, array $data): FinJournal
            {
                parent::createAndPost($orgId, $data);

                throw new RuntimeException('Injected failure after recurring journal post.');
            }
        };
        $failingService = new RecurringJournalService($failingPosting);
        $job = new GenerateRecurringJournalsJob;

        try {
            $job->handle($failingService);
            $this->fail('A failed occurrence must fail the queue job for governed retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Recurring journal generation failed', $exception->getMessage());
        }

        $failed = FinRecurringJournalOccurrence::query()->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertSame(1, $failed->attempt_count);
        $this->assertNull($failed->journal_id);
        $this->assertNotNull($failed->failed_at);
        $this->assertSame('runtime_exception', $failed->last_error_code);
        $this->assertStringContainsString('Injected failure after recurring journal post', $failed->last_error);
        $this->assertSame(0, FinJournal::query()->count());
        $this->assertSame('2026-08-23', $recurring->fresh()->next_run_date->toDateString());
        $this->assertSame('failed', $failed->attempts()->sole()->outcome);

        $foreignRecurring = $this->recurringJournal([
            'organization_id' => 2,
            'name' => 'Foreign recurring schedule',
            'is_active' => false,
        ]);
        FinRecurringJournalOccurrence::query()->create([
            'recurring_journal_id' => $foreignRecurring->id,
            'scheduled_for' => '2026-08-23',
            'occurrence_key' => FinRecurringJournalOccurrence::buildOccurrenceKey(
                $foreignRecurring->id,
                CarbonImmutable::parse('2026-08-23', 'UTC'),
            ),
            'status' => 'failed',
            'attempt_count' => 1,
            'last_attempted_at' => now(),
            'failed_at' => now(),
            'last_error_code' => 'foreign_failure',
            'last_error' => 'This must never be exposed to another organisation.',
        ]);

        $this->grantPermission($this->actor, 'finance.ledger.view');
        $this->actingAs($this->actor)
            ->get('/finance/journals')
            ->assertInertia(fn (Assert $page) => $page
                ->component('finance/journals/Index')
                ->has('recurringOccurrenceHistory', 1)
                ->where('recurringOccurrenceHistory.0.schedule_name', 'Daily recurring revenue')
                ->where('recurringOccurrenceHistory.0.scheduled_for', '2026-08-23')
                ->where('recurringOccurrenceHistory.0.status', 'failed')
                ->where('recurringOccurrenceHistory.0.attempt_count', 1)
                ->where('recurringOccurrenceHistory.0.last_error_code', 'runtime_exception')
                ->where('recurringOccurrenceHistory.0.attempts.0.outcome', 'failed')
                ->missing('recurringOccurrenceHistory.0.last_error')
                ->missing('recurringOccurrenceHistory.0.attempts.0.error_message'));

        $job->handle($this->service);

        $posted = $failed->fresh();
        $this->assertSame('posted', $posted->status);
        $this->assertSame(2, $posted->attempt_count);
        $this->assertNull($posted->failed_at);
        $this->assertNull($posted->last_error);
        $this->assertSame(['failed', 'posted'], $posted->attempts()->orderBy('id')->pluck('outcome')->all());
        $this->assertSame(1, FinJournal::query()->where('status', 'posted')->count());
        $this->assertSame('2026-08-24', $recurring->fresh()->next_run_date->toDateString());
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff);
        $this->assertSame('finance-recurring-journals', $job->uniqueId());

        $this->actingAs($this->actor)
            ->get('/finance/journals')
            ->assertInertia(fn (Assert $page) => $page
                ->where('recurringOccurrenceHistory.0.status', 'posted')
                ->where('recurringOccurrenceHistory.0.attempt_count', 2)
                ->where('recurringOccurrenceHistory.0.attempts.0.outcome', 'posted')
                ->where('recurringOccurrenceHistory.0.attempts.1.outcome', 'failed')
                ->has('recurringOccurrenceHistory.0.journal.id'));
    }

    public function test_failure_before_post_leaves_no_draft_and_exposes_one_retryable_attempt(): void
    {
        $recurring = $this->recurringJournal();
        $failingPosting = new class extends JournalPostingService
        {
            public function createAndPost(?int $orgId, array $data): FinJournal
            {
                throw new RuntimeException('Injected failure before recurring journal post.');
            }
        };
        $service = new RecurringJournalService($failingPosting);

        try {
            $service->processOccurrence(
                $recurring->id,
                '2026-08-23',
                self::ORGANIZATION_ID,
            );
            $this->fail('A failed posting call must remain a retryable occurrence.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected failure before recurring journal post.', $exception->getMessage());
        }

        $occurrence = FinRecurringJournalOccurrence::query()->sole();
        $attempt = $occurrence->attempts()->sole();
        $this->assertSame('failed', $occurrence->status);
        $this->assertSame(1, $occurrence->attempt_count);
        $this->assertSame('failed', $attempt->outcome);
        $this->assertStringContainsString('before recurring journal post', $attempt->error_message);
        $this->assertSame(0, FinJournal::query()->count());
        $this->assertSame('2026-08-23', $recurring->fresh()->next_run_date->toDateString());
    }

    public function test_one_unambiguous_legacy_post_is_bound_and_advances_without_a_duplicate(): void
    {
        $recurring = $this->recurringJournal();
        $legacy = app(JournalPostingService::class)->createAndPost(self::ORGANIZATION_ID, [
            'journal_date' => '2026-08-23',
            'type' => 'standard',
            'reference' => 'REC-'.$recurring->id,
            'description' => $recurring->description,
            'lines' => $recurring->template_lines,
        ]);

        $recovered = $this->service->processOccurrence(
            $recurring->id,
            '2026-08-23',
            self::ORGANIZATION_ID,
        );
        $occurrence = FinRecurringJournalOccurrence::query()->sole();

        $this->assertSame($legacy->id, $recovered->id);
        $this->assertSame(1, FinJournal::query()->count());
        $this->assertSame('posted', $occurrence->status);
        $this->assertNotNull($occurrence->recovered_at);
        $this->assertSame('recovered', $occurrence->attempts()->sole()->outcome);
        $this->assertSame(FinRecurringJournalOccurrence::class, $legacy->fresh()->source_type);
        $this->assertSame($occurrence->id, $legacy->fresh()->source_id);
        $this->assertSame('2026-08-24', $recurring->fresh()->next_run_date->toDateString());
    }

    public function test_stale_storage_context_snapshot_is_rejected_before_any_mutex_or_occurrence_mutation(): void
    {
        $recurring = $this->recurringJournal();

        try {
            $this->service->processOccurrence($recurring->id, '2026-08-23', 999);
            $this->fail('A mismatched scheduler storage context must reject the recurring journal snapshot.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, FinRecurringJournalOccurrence::query()->count());
        $this->assertSame(0, FinJournal::query()->count());
        $this->assertFalse(DB::table('fin_journal_sequences')->where('organization_id', 999)->exists());
        $this->assertSame('2026-08-23', $recurring->fresh()->next_run_date->toDateString());
    }

    public function test_zero_organization_context_cannot_bypass_due_schedule_scope(): void
    {
        $recurring = $this->recurringJournal();

        try {
            $this->service->processDueRecurringJournals(0);
            $this->fail('An invalid organization context must fail closed.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('organization context', $exception->getMessage());
        }

        $this->assertSame(0, FinRecurringJournalOccurrence::query()->count());
        $this->assertSame(0, FinJournal::query()->count());
        $this->assertSame('2026-08-23', $recurring->fresh()->next_run_date->toDateString());
    }

    public function test_posted_replay_rejects_a_conflicting_schedule_identity(): void
    {
        $requested = $this->recurringJournal();
        $other = $this->recurringJournal(['name' => 'Other recurring schedule']);
        $occurrence = FinRecurringJournalOccurrence::query()->create([
            'recurring_journal_id' => $other->id,
            'scheduled_for' => '2026-08-23',
            'occurrence_key' => FinRecurringJournalOccurrence::buildOccurrenceKey(
                $requested->id,
                CarbonImmutable::parse('2026-08-23', 'UTC'),
            ),
            'status' => 'processing',
            'attempt_count' => 0,
        ]);
        $journal = FinJournal::query()->create([
            'organization_id' => self::ORGANIZATION_ID,
            'journal_number' => 'JNL-CONFLICTING-REPLAY',
            'journal_date' => '2026-08-23',
            'type' => 'recurring',
            'reference' => 'CONFLICTING-REPLAY',
            'source_type' => FinRecurringJournalOccurrence::class,
            'source_id' => $occurrence->id,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $this->actor->id,
            'total_amount' => '125.50',
        ]);
        $occurrence->forceFill([
            'status' => 'posted',
            'journal_id' => $journal->id,
            'posted_at' => now(),
        ])->save();

        try {
            $this->service->processOccurrence(
                $requested->id,
                '2026-08-23',
                self::ORGANIZATION_ID,
            );
            $this->fail('A conflicting occurrence key must not replay another schedule journal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('conflicting schedule provenance', $exception->getMessage());
        }

        $this->assertSame('2026-08-23', $requested->fresh()->next_run_date->toDateString());
        $this->assertSame(0, $occurrence->attempts()->count());
        $this->assertSame(1, FinJournal::query()->count());
    }

    public function test_due_processing_requires_an_explicit_organization_and_leaves_foreign_schedules_untouched(): void
    {
        $recurring = $this->recurringJournal();
        $foreignRecurring = $this->recurringJournal([
            'organization_id' => 2,
            'name' => 'Foreign due recurring schedule',
        ]);

        $journals = $this->service->processDueRecurringJournals(self::ORGANIZATION_ID);

        $this->assertCount(1, $journals);
        $this->assertSame(self::ORGANIZATION_ID, (int) $journals[0]->organization_id);
        $this->assertSame(
            [$recurring->id],
            FinRecurringJournalOccurrence::query()->pluck('recurring_journal_id')->all(),
        );
        $this->assertSame('2026-08-23', $foreignRecurring->fresh()->next_run_date->toDateString());
        $this->assertNull($foreignRecurring->fresh()->last_run_date);
    }

    public function test_two_independent_mysql_workers_converge_on_one_occurrence_and_journal(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $recurring = $this->recurringJournal();
        $database = $connection->getDatabaseName();
        $token = (string) Str::uuid();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-recurring-go-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-recurring-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-recurring-ready-b-{$token}",
        ];
        $processes = [];

        $connection->commit();

        try {
            foreach ($readyPaths as $readyPath) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/FinanceRecurringJournalWorker.php'),
                    $database,
                    (string) $recurring->id,
                    '2026-08-23',
                    (string) self::ORGANIZATION_ID,
                    $readyPath,
                    $releasePath,
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }

            foreach ($readyPaths as $index => $readyPath) {
                $this->waitForWorker($processes[$index], $readyPath);
            }
            file_put_contents($releasePath, 'go', LOCK_EX);

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'Concurrent recurring-journal worker failed.',
                );
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertCount(1, collect($results)->pluck('journal_id')->unique());
            $this->assertCount(1, collect($results)->pluck('source_id')->unique());
            $this->assertSame(1, FinJournal::query()->where('status', 'posted')->count());
            $this->assertSame(1, FinRecurringJournalOccurrence::query()->count());
            $this->assertSame(1, FinRecurringJournalOccurrence::query()->sole()->attempts()->count());
            $this->assertSame('2026-08-24', $recurring->fresh()->next_run_date->toDateString());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            DB::table('fin_recurring_journal_occurrence_attempts')->delete();
            DB::table('fin_recurring_journal_occurrences')->delete();
            DB::table('fin_journal_lines')->delete();
            DB::table('fin_journals')->delete();
            DB::table('fin_recurring_journals')->delete();
            DB::table('fin_fiscal_periods')->delete();
            DB::table('fin_accounts')->delete();
            DB::table('fin_journal_sequences')->delete();
            DB::table('audit_logs')->delete();
            DB::table('users')->where('id', $this->actor->id)->delete();
            $connection->beginTransaction();
        }
    }

    private function recurringJournal(array $attributes = []): FinRecurringJournal
    {
        return FinRecurringJournal::query()->create([
            'organization_id' => self::ORGANIZATION_ID,
            'name' => 'Daily recurring revenue',
            'description' => 'Daily governed recurring journal',
            'frequency' => 'daily',
            'next_run_date' => '2026-08-23',
            'last_run_date' => null,
            'template_lines' => [
                [
                    'account_id' => $this->cash->id,
                    'description' => 'Cash side',
                    'debit' => '125.50',
                    'credit' => '0.00',
                ],
                [
                    'account_id' => $this->revenue->id,
                    'description' => 'Revenue side',
                    'debit' => '0.00',
                    'credit' => '125.50',
                ],
            ],
            'is_active' => true,
            'created_by' => $this->actor->id,
            ...$attributes,
        ]);
    }

    private function grantPermission(User $user, string $key): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    private function waitForWorker(Process $process, string $readyPath): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($readyPath)) {
            if (! $process->isRunning()) {
                throw new RuntimeException(
                    trim($process->getErrorOutput())
                    ?: 'Recurring-journal worker exited before becoming ready.',
                );
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Recurring-journal worker did not reach the concurrency barrier.');
            }

            usleep(20_000);
        }
    }
}
