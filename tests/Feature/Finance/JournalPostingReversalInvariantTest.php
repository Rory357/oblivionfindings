<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\JournalPostingService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\FinancePermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->actor = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $this->site = Site::factory()->create([
        'name' => 'Journal source Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->cash = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '1000',
        'name' => 'Journal reversal cash',
        'type' => 'asset',
        'is_active' => true,
    ]);
    $this->revenue = FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => '4000',
        'name' => 'Journal reversal revenue',
        'type' => 'revenue',
        'is_active' => true,
    ]);
    $this->period = FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'Journal reversal period',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
    $this->service = app(JournalPostingService::class);

    $this->postJournal = function (string $reference = 'SOURCE-1'): FinJournal {
        return $this->service->createAndPost(1, [
            'journal_date' => now()->toDateString(),
            'type' => 'standard',
            'reference' => $reference,
            'description' => 'Canonical source journal',
            'source_type' => 'reversal_invariant_test',
            'source_id' => 91,
            'actor_id' => $this->actor->id,
            'lines' => [
                [
                    'account_id' => $this->cash->id,
                    'description' => 'Cash side',
                    'debit' => '125.50',
                    'credit' => '0.00',
                    'site_id' => $this->site->id,
                ],
                [
                    'account_id' => $this->revenue->id,
                    'description' => 'Revenue side',
                    'debit' => '0.00',
                    'credit' => '125.50',
                    'site_id' => $this->site->id,
                ],
            ],
        ]);
    };
});

it('creates one posted exact inverse with durable two-way provenance and returns it on replay', function (): void {
    $source = ($this->postJournal)();

    $reversal = $this->service->reverse($source, 'Incorrect source classification');
    $replay = $this->service->reverse($source->fresh(), 'A different retry reason is not a second effect');

    $source->refresh()->load('lines');
    $reversal->load('lines');

    expect($replay->id)->toBe($reversal->id)
        ->and($source->status)->toBe('posted')
        ->and($source->reversed_by_journal_id)->toBe($reversal->id)
        ->and($reversal->status)->toBe('posted')
        ->and($reversal->reversal_of_journal_id)->toBe($source->id)
        ->and($reversal->source_type)->toBe($source->source_type)
        ->and($reversal->source_id)->toBe($source->source_id)
        ->and((string) $reversal->total_amount)->toBe((string) $source->total_amount)
        ->and(FinJournal::query()->where('reversal_of_journal_id', $source->id)->count())->toBe(1)
        ->and(FinJournal::query()->count())->toBe(2);

    $sourceCash = $source->lines->firstWhere('account_id', $this->cash->id);
    $reversalCash = $reversal->lines->firstWhere('account_id', $this->cash->id);
    $sourceRevenue = $source->lines->firstWhere('account_id', $this->revenue->id);
    $reversalRevenue = $reversal->lines->firstWhere('account_id', $this->revenue->id);

    expect((string) $reversalCash->debit)->toBe((string) $sourceCash->credit)
        ->and((string) $reversalCash->credit)->toBe((string) $sourceCash->debit)
        ->and($reversalCash->site_id)->toBe($sourceCash->site_id)
        ->and((string) $reversalRevenue->debit)->toBe((string) $sourceRevenue->credit)
        ->and((string) $reversalRevenue->credit)->toBe((string) $sourceRevenue->debit)
        ->and($reversalRevenue->site_id)->toBe($sourceRevenue->site_id)
        ->and($reversal->lines->sum(fn ($line) => (float) $line->debit))
        ->toBe($reversal->lines->sum(fn ($line) => (float) $line->credit));
});

it('re-reads the canonical locked source and rejects stale or closed-period reversal attempts without effects', function (): void {
    $staleSource = ($this->postJournal)('STALE-SOURCE');
    DB::table('fin_journals')->where('id', $staleSource->id)->update(['status' => 'draft']);

    expect(fn () => $this->service->reverse($staleSource))
        ->toThrow(InvalidArgumentException::class, "status is 'draft'")
        ->and(FinJournal::query()->count())->toBe(1)
        ->and($staleSource->fresh()->reversed_by_journal_id)->toBeNull();

    DB::table('fin_journals')->where('id', $staleSource->id)->update(['status' => 'posted']);
    $this->period->update(['status' => 'closed']);

    expect(fn () => $this->service->reverse($staleSource->fresh()))
        ->toThrow(InvalidArgumentException::class, "expected 'open'")
        ->and(FinJournal::query()->count())->toBe(1)
        ->and($staleSource->fresh()->reversed_by_journal_id)->toBeNull()
        ->and(FinJournal::query()->whereNotNull('reversal_of_journal_id')->exists())->toBeFalse();
});

it('rolls back the posted inverse and every link when failure is injected before provenance linking', function (): void {
    $source = ($this->postJournal)();
    $failingService = new class extends JournalPostingService
    {
        public function post(FinJournal $journal): FinJournal
        {
            $posted = parent::post($journal);

            if ($journal->reversal_of_journal_id !== null) {
                throw new RuntimeException('Injected failure after inverse posting.');
            }

            return $posted;
        }
    };

    expect(fn () => $failingService->reverse($source))
        ->toThrow(RuntimeException::class, 'Injected failure after inverse posting.')
        ->and(FinJournal::query()->count())->toBe(1)
        ->and($source->fresh()->reversed_by_journal_id)->toBeNull()
        ->and(FinJournal::query()->whereNotNull('reversal_of_journal_id')->exists())->toBeFalse();
});

it('fails closed when a source points at a wrong balanced journal instead of blessing false provenance', function (): void {
    $source = ($this->postJournal)('SOURCE-WRONG-LINK');
    $unrelated = ($this->postJournal)('UNRELATED-POSTED');
    DB::table('fin_journals')->where('id', $source->id)->update([
        'reversed_by_journal_id' => $unrelated->id,
    ]);

    expect(fn () => $this->service->reverse($source->fresh()))
        ->toThrow(RuntimeException::class, 'invalid reversal provenance')
        ->and(FinJournal::query()->count())->toBe(2)
        ->and(FinJournal::query()->whereNotNull('reversal_of_journal_id')->exists())->toBeFalse();
});

it('enforces the one-reversal source key in MySQL independently of service code', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    $source = ($this->postJournal)();
    $reversal = $this->service->reverse($source);

    expect(fn () => FinJournal::query()->create([
        'organization_id' => 1,
        'journal_number' => 'JNL-DUPLICATE-REVERSAL',
        'journal_date' => now()->toDateString(),
        'type' => 'adjustment',
        'status' => 'draft',
        'reversal_of_journal_id' => $source->id,
        'total_amount' => '0.00',
    ]))->toThrow(QueryException::class)
        ->and($source->fresh()->reversed_by_journal_id)->toBe($reversal->id)
        ->and(FinJournal::query()->where('reversal_of_journal_id', $source->id)->count())->toBe(1);
});

it('denies an unprivileged direct id while the explicit global finance role can reverse and replay a Site-dimensioned journal', function (): void {
    $source = ($this->postJournal)();
    $viewer = journalReversalUserWithPermissions(['finance.ledger.view']);
    ensureCanonicalHrStaffProfile($viewer, $this->site);

    $this->actingAs($viewer)
        ->post(route('finance.journals.reverse', $source), ['reason' => 'Must not run'])
        ->assertForbidden();

    expect(FinJournal::query()->count())->toBe(1)
        ->and($source->fresh()->reversed_by_journal_id)->toBeNull();

    $otherSite = Site::factory()->create([
        'name' => 'Global finance actor local Site',
        'is_active' => true,
        'archived' => false,
    ]);
    $this->seed(FinancePermissionsSeeder::class);
    $globalFinanceActor = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $globalFinanceActor->roles()->sync([Role::query()->where('name', 'finance')->firstOrFail()->id]);
    ensureCanonicalHrStaffProfile($globalFinanceActor, $otherSite);

    $first = $this->actingAs($globalFinanceActor)
        ->post(route('finance.journals.reverse', $source), ['reason' => 'Authorised correction']);
    $reversalId = $source->fresh()->reversed_by_journal_id;

    $first->assertRedirect(route('finance.journals.show', $reversalId));
    $this->actingAs($globalFinanceActor)
        ->post(route('finance.journals.reverse', $source), ['reason' => 'Safe retry'])
        ->assertRedirect(route('finance.journals.show', $reversalId));

    expect(FinJournal::query()->count())->toBe(2)
        ->and(FinJournal::query()->where('reversal_of_journal_id', $source->id)->value('id'))
        ->toBe($reversalId);
});

it('serializes two independent MySQL workers to the same single reversal', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $source = ($this->postJournal)('CONCURRENT-SOURCE');
    $database = $connection->getDatabaseName();
    $token = Str::uuid()->toString();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-journal-reversal-go-{$token}";
    $readyPaths = [
        sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-journal-reversal-ready-a-{$token}",
        sys_get_temp_dir().DIRECTORY_SEPARATOR."fin-journal-reversal-ready-b-{$token}",
    ];
    $processes = [];

    // Independent workers must see the source before either enters the locked
    // reversal transaction.
    $connection->commit();

    try {
        foreach ($readyPaths as $readyPath) {
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/FinanceJournalReversalWorker.php'),
                $database,
                (string) $source->id,
                $readyPath,
                $releasePath,
            ]);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        foreach ($readyPaths as $index => $readyPath) {
            journalReversalWaitForWorker($processes[$index], $readyPath);
        }

        file_put_contents($releasePath, 'go', LOCK_EX);

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())
                ->toBeTrue(trim($process->getErrorOutput()) ?: 'Concurrent journal-reversal worker failed.');
            $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }

        $reversalIds = collect($results)->pluck('reversal_id')->unique()->values();
        expect($reversalIds)->toHaveCount(1)
            ->and(collect($results)->pluck('reversal_of_journal_id')->unique()->all())->toBe([$source->id])
            ->and(FinJournal::query()->count())->toBe(2)
            ->and(FinJournal::query()->where('reversal_of_journal_id', $source->id)->count())->toBe(1)
            ->and($source->fresh()->reversed_by_journal_id)->toBe($reversalIds->first());
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
        DB::table('fin_journals')->update([
            'reversed_by_journal_id' => null,
            'reversal_of_journal_id' => null,
        ]);
        DB::table('fin_journal_lines')->delete();
        DB::table('fin_journals')->delete();
        DB::table('fin_fiscal_periods')->delete();
        DB::table('fin_accounts')->delete();
        DB::table('audit_logs')->delete();
        DB::table('sites')->where('id', $this->site->id)->delete();
        DB::table('users')->where('id', $this->actor->id)->delete();
        $connection->beginTransaction();
    }
});

/** @param list<string> $permissionKeys */
function journalReversalUserWithPermissions(array $permissionKeys): User
{
    $user = User::factory()->create([
        'organization_id' => 1,
        'approved_at' => now(),
    ]);

    foreach ($permissionKeys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $user;
}

function journalReversalWaitForWorker(Process $process, string $readyPath): void
{
    $deadline = microtime(true) + 15;
    while (! is_file($readyPath)) {
        if (! $process->isRunning()) {
            throw new RuntimeException(
                trim($process->getErrorOutput()) ?: 'Journal-reversal worker exited before becoming ready.',
            );
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Journal-reversal worker did not reach the concurrency barrier.');
        }

        usleep(20_000);
    }
}
