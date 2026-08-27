<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBankTransaction;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinExternalSettlement;
use App\Domain\Finance\Models\FinExternalSettlementEvent;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentAllocation;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Services\ExternalSettlementService;
use App\Domain\Finance\Services\JournalPostingService;
use App\Domain\Finance\Services\PaymentRunService;
use App\Domain\Finance\Services\PaymentSettlementSiteScope;
use App\Domain\Finance\Support\BankReconciliationMutationGuard;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Storage::fake('local');
    foreach ([
        ['1000', 'Bank', 'asset'],
        ['2000', 'Accounts Payable', 'liability'],
    ] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'is_active' => true,
        ]);
    }
    FinFiscalPeriod::query()->create([
        'organization_id' => 1,
        'name' => 'Settlement test period',
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
        'status' => 'open',
    ]);
});

it('requires maker checker and makes rejection terminal while releasing active bill membership', function (): void {
    [$bill, $bank, $creator, $approver, $checker] = externalSettlementFixture('321.09');
    $runs = app(PaymentRunService::class);
    $run = $runs->createPaymentRun(1, $creator, [
        'bank_account_id' => $bank->id,
        'payment_date' => now()->toDateString(),
        'bill_ids' => [$bill->id],
    ]);
    expect(fn () => $runs->createPaymentRun(1, $creator, [
        'bank_account_id' => $bank->id,
        'payment_date' => now()->addDay()->toDateString(),
        'bill_ids' => [$bill->id],
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $runs->approvePaymentRun($run, $creator))
        ->toThrow(InvalidArgumentException::class);
    $runs->approvePaymentRun($run, $approver);
    $runs->processPaymentRun($run, $approver);

    $settlements = app(ExternalSettlementService::class);
    expect(fn () => $settlements->accept(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $checker,
        'accept-before-export',
        'BANK-NOT-EXPORTED',
        ['digest' => hash('sha256', 'not-exported')],
    ))->toThrow(InvalidArgumentException::class);
    $settlements->markExported($run, ExternalSettlementService::PAYMENT_RUN, $approver);
    expect(fn () => $settlements->accept(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $creator,
        'creator-must-not-check',
        'BANK-DECLINED-321',
        ['digest' => hash('sha256', 'declined-321')],
    ))->toThrow(InvalidArgumentException::class);
    expect(fn () => $settlements->accept(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $approver,
        'preparer-must-not-check',
        'BANK-DECLINED-321',
        ['digest' => hash('sha256', 'declined-321')],
    ))->toThrow(InvalidArgumentException::class);

    $settlement = $run->externalSettlement()->firstOrFail();
    $artifact = Storage::disk('local')->get($settlement->artifact_path);
    Storage::disk('local')->put($settlement->artifact_path, 'tampered before rejection');
    expect(fn () => $settlements->reject(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $checker,
        'reject-run-321',
        'BANK-DECLINED-321',
        'Account details rejected by bank.',
        ['digest' => hash('sha256', 'declined-321')],
    ))->toThrow(InvalidArgumentException::class);
    expect($settlement->fresh()->status)->toBe('exported')
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'rejected')->count())->toBe(0);
    Storage::disk('local')->put($settlement->artifact_path, $artifact);

    $rejected = $settlements->reject(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $checker,
        'reject-run-321',
        'BANK-DECLINED-321',
        'Account details rejected by bank.',
        ['digest' => hash('sha256', 'declined-321')],
    );

    expect($rejected->status)->toBe('rejected')
        ->and($run->fresh()->status)->toBe('rejected')
        ->and($run->items()->value('active_settlement_bill_id'))->toBeNull()
        ->and($bill->fresh()->status)->toBe('approved')
        ->and((string) $bill->fresh()->amount_paid)->toBe('0.00')
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'rejected')->count())->toBe(1);
    expect(fn () => $settlements->reject(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        externalSettlementUser(),
        'reject-run-321',
        'BANK-DECLINED-321',
        'Account details rejected by bank.',
        ['digest' => hash('sha256', 'declined-321')],
    ))->toThrow(InvalidArgumentException::class, 'decision or actor');

    $corrected = $runs->createPaymentRun(1, $creator, [
        'bank_account_id' => $bank->id,
        'payment_date' => now()->addDay()->toDateString(),
        'bill_ids' => [$bill->id],
    ]);
    expect($corrected->items()->value('active_settlement_bill_id'))->toBe($bill->id);
});

it('preserves DECIMAL(14,2) exactly and blocks changed or overpaid sources before GL', function (): void {
    $amount = '999999999999.99';
    [$bill, $bank, $creator, $approver, $checker] = externalSettlementFixture($amount);
    $runs = app(PaymentRunService::class);
    $run = $runs->createPaymentRun(1, $creator, [
        'bank_account_id' => $bank->id,
        'payment_date' => now()->toDateString(),
        'bill_ids' => [$bill->id],
    ]);
    $runs->approvePaymentRun($run, $approver);
    $runs->processPaymentRun($run, $approver);

    $settlements = app(ExternalSettlementService::class);
    $settlements->markExported($run, ExternalSettlementService::PAYMENT_RUN, $approver);
    $settlements->accept(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $checker,
        'accept-large-exact',
        'BANK-ACCEPTED-LARGE',
        ['digest' => hash('sha256', 'large-exact')],
    );
    expect(fn () => $settlements->accept(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $checker,
        'accept-large-exact',
        'BANK-ACCEPTED-DIFFERENT',
        ['digest' => hash('sha256', 'different-evidence')],
    ))->toThrow(InvalidArgumentException::class);
    $otherChecker = externalSettlementUser();
    expect(fn () => $settlements->accept(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $otherChecker,
        'accept-large-exact',
        'BANK-ACCEPTED-LARGE',
        ['digest' => hash('sha256', 'large-exact')],
    ))->toThrow(InvalidArgumentException::class, 'different evidence or actor');
    expect(FinExternalSettlementEvent::query()->where('event_type', 'accepted')->count())->toBe(1);

    $bill->forceFill(['amount_paid' => '0.01', 'status' => 'partially_paid'])->save();
    expect(fn () => $settlements->settlePaymentRun($run, $checker, 'settle-large-exact'))
        ->toThrow(InvalidArgumentException::class);
    expect(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(FinPaymentAllocation::query()->count())->toBe(0)
        ->and($run->externalSettlement()->firstOrFail()->status)->toBe('accepted');

    $bill->forceFill(['amount_paid' => '0.00', 'status' => 'approved'])->save();
    $settled = $settlements->settlePaymentRun($run, $checker, 'settle-large-exact');
    $journal = FinJournal::query()->findOrFail($settled->journal_id)->load('lines');
    expect((string) $bill->fresh()->amount_paid)->toBe($amount)
        ->and($journal->lines->reduce(
            fn (string $total, $line): string => bcadd($total, (string) $line->debit, 2),
            '0.00',
        ))->toBe($amount)
        ->and((string) FinPaymentAllocation::query()->sole()->amount)->toBe($amount);
    expect(fn () => $settlements->settlePaymentRun($run, $otherChecker, 'settle-large-exact'))
        ->toThrow(InvalidArgumentException::class, 'another result');

    $foreignBankTransaction = FinBankTransaction::query()->create([
        'organization_id' => 2,
        'bank_account_id' => $bank->id,
        'transaction_date' => now()->toDateString(),
        'amount' => '-'.$amount,
        'description' => 'Foreign organisation settlement clearing',
        'reference' => 'BANK-FOREIGN-LARGE',
        'source' => 'manual',
        'status' => 'matched',
    ]);
    $this->actingAs($checker)->post(route('finance.payment-runs.reconcile', $run), [
        'idempotency_key' => 'reconcile-foreign-large',
        'reference' => 'BANK-FOREIGN-LARGE',
        'evidence' => ['digest' => hash('sha256', 'foreign-large')],
        'bank_transaction_id' => $foreignBankTransaction->id,
    ])->assertNotFound();
    expect(FinExternalSettlementEvent::query()->where('event_type', 'reconciled')->count())->toBe(0);

    $unlinkedBankTransaction = FinBankTransaction::query()->create([
        'organization_id' => 1,
        'bank_account_id' => $bank->id,
        'transaction_date' => now()->toDateString(),
        'amount' => '-'.$amount,
        'description' => 'Exact settlement clearing',
        'reference' => 'BANK-CLEARED-LARGE',
        'source' => 'manual',
        'status' => 'reconciled',
    ]);
    expect(fn () => $settlements->reconcile(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $unlinkedBankTransaction->id,
        $checker,
        'reconcile-unlinked-large',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    ))->toThrow(InvalidArgumentException::class, 'not linked to this settlement journal');
    $bankJournalLine = $journal->lines->first(
        fn ($line): bool => bccomp((string) $line->credit, '0.00', 2) > 0,
    );
    expect($bankJournalLine)->not->toBeNull();
    $bankTransaction = FinBankTransaction::query()->create([
        'organization_id' => 1,
        'bank_account_id' => $bank->id,
        'transaction_date' => now()->toDateString(),
        'amount' => '-'.$amount,
        'description' => 'Exact linked settlement clearing',
        'reference' => 'BANK-CLEARED-LARGE',
        'source' => 'manual',
        'matched_journal_line_id' => $bankJournalLine->id,
        'status' => 'reconciled',
    ]);
    $bankTransaction->update(['amount' => $amount]);
    expect(fn () => $settlements->reconcile(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $bankTransaction->id,
        $checker,
        'reconcile-inflow-large',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    ))->toThrow(InvalidArgumentException::class, 'does not match this settlement');
    $bankTransaction->update(['amount' => '-'.$amount]);
    $apAccountId = FinAccount::query()->where('code', '2000')->value('id');
    DB::table('fin_journal_lines')->where('id', $bankJournalLine->id)->update(['account_id' => $apAccountId]);
    expect(fn () => $settlements->reconcile(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $bankTransaction->id,
        $checker,
        'reconcile-wrong-bank-gl-large',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    ))->toThrow(InvalidArgumentException::class, 'not linked to this settlement journal');
    DB::table('fin_journal_lines')->where('id', $bankJournalLine->id)->update([
        'account_id' => $bank->gl_account_id,
        'credit' => bcsub($amount, '0.01', 2),
    ]);
    expect(fn () => $settlements->reconcile(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $bankTransaction->id,
        $checker,
        'reconcile-partial-bank-line-large',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    ))->toThrow(InvalidArgumentException::class, 'not linked to this settlement journal');
    DB::table('fin_journal_lines')->where('id', $bankJournalLine->id)->update(['credit' => $amount]);
    $settlement = $run->externalSettlement()->firstOrFail();
    $artifact = Storage::disk('local')->get($settlement->artifact_path);
    Storage::disk('local')->put($settlement->artifact_path, 'tampered before reconciliation');
    expect(fn () => $settlements->reconcile(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $bankTransaction->id,
        $checker,
        'reconcile-large-exact',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    ))->toThrow(InvalidArgumentException::class);
    expect($settlement->fresh()->status)->toBe('settled')
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'reconciled')->count())->toBe(0);
    Storage::disk('local')->put($settlement->artifact_path, $artifact);
    $reconciled = $settlements->reconcile(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $bankTransaction->id,
        $checker,
        'reconcile-large-exact',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    );
    expect($reconciled->status)->toBe('reconciled')
        ->and($run->fresh()->status)->toBe('reconciled')
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'reconciled')->count())->toBe(1);
    BankReconciliationMutationGuard::run(
        fn () => $bankTransaction->update(['status' => 'unreconciled']),
    );
    expect(fn () => $settlements->reconcile(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        $bankTransaction->id,
        $checker,
        'reconcile-large-exact',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    ))->toThrow(InvalidArgumentException::class, 'does not match this settlement');
    BankReconciliationMutationGuard::run(
        fn () => $bankTransaction->update(['status' => 'reconciled']),
    );
    expect($settlements->reconcile(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        $bankTransaction->id,
        $checker,
        'reconcile-large-exact',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    )->id)->toBe($reconciled->id)
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'reconciled')->count())->toBe(1);
    expect(fn () => $settlements->reconcile(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        $bankTransaction->id,
        $otherChecker,
        'reconcile-large-exact',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    ))->toThrow(InvalidArgumentException::class, 'evidence, or actor');
    expect(fn () => $settlements->reconcile(
        $run->fresh(),
        ExternalSettlementService::PAYMENT_RUN,
        $foreignBankTransaction->id,
        $checker,
        'reconcile-large-exact',
        'BANK-CLEARED-LARGE',
        ['digest' => hash('sha256', 'large-cleared')],
    ))->toThrow(InvalidArgumentException::class, 'another bank match');
});

it('rolls back every paid effect on journal and post-journal failures, then retries once', function (): void {
    [$bill, $bank, $creator, $approver, $checker] = externalSettlementFixture('700.00');
    $secondBill = FinBill::factory()->create([
        'organization_id' => 1,
        'site_id' => $bill->site_id,
        'vendor_id' => $bill->vendor_id,
        'status' => 'approved',
        'total_amount' => '300.00',
        'amount_paid' => '0.00',
    ]);
    $runs = app(PaymentRunService::class);
    $run = $runs->createPaymentRun(1, $creator, [
        'bank_account_id' => $bank->id,
        'payment_date' => now()->toDateString(),
        'bill_ids' => [$bill->id, $secondBill->id],
    ]);
    $runs->approvePaymentRun($run, $approver);
    $runs->processPaymentRun($run, $approver);
    $settlements = app(ExternalSettlementService::class);
    $settlements->markExported($run, ExternalSettlementService::PAYMENT_RUN, $approver);
    $settlements->accept(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $checker,
        'accept-before-failure',
        'BANK-ACCEPTED-FAILURE',
        ['digest' => hash('sha256', 'accepted-before-failure')],
    );

    $realPosting = app(JournalPostingService::class);
    $posting = Mockery::mock(JournalPostingService::class);
    $posting->shouldReceive('lockJournalSequence')->once()->with(1);
    $posting->shouldReceive('createAndPost')->once()->andThrow(new RuntimeException('Forced GL failure.'));
    app()->instance(JournalPostingService::class, $posting);
    app()->forgetInstance(ExternalSettlementService::class);

    expect(fn () => app(ExternalSettlementService::class)->settlePaymentRun(
        $run,
        $checker,
        'settle-forced-failure',
    ))->toThrow(RuntimeException::class, 'Forced GL failure.');

    expect($run->externalSettlement()->firstOrFail()->status)->toBe('accepted')
        ->and($run->fresh()->journal_id)->toBeNull()
        ->and((string) $bill->fresh()->amount_paid)->toBe('0.00')
        ->and((string) $secondBill->fresh()->amount_paid)->toBe('0.00')
        ->and($bill->fresh()->status)->toBe('approved')
        ->and(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'settled')->count())->toBe(0);

    app()->instance(JournalPostingService::class, $realPosting);
    app()->forgetInstance(ExternalSettlementService::class);
    $blockSettlementAudit = true;
    DB::listen(function ($query) use (&$blockSettlementAudit): void {
        if ($blockSettlementAudit
            && str_contains(strtolower($query->sql), 'insert into `audit_logs`')
            && in_array('finance.payment_run.settled', $query->bindings, true)) {
            throw new RuntimeException('Forced post-journal settlement audit failure.');
        }
    });

    expect(fn () => app(ExternalSettlementService::class)->settlePaymentRun(
        $run->fresh(),
        $checker,
        'settle-forced-failure',
    ))->toThrow(RuntimeException::class, 'Forced post-journal settlement audit failure.');
    expect($run->externalSettlement()->firstOrFail()->status)->toBe('accepted')
        ->and($run->fresh()->journal_id)->toBeNull()
        ->and((string) $bill->fresh()->amount_paid)->toBe('0.00')
        ->and((string) $secondBill->fresh()->amount_paid)->toBe('0.00')
        ->and($bill->fresh()->status)->toBe('approved')
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
        ->and(FinPaymentAllocation::query()->count())->toBe(0)
        ->and(FinExternalSettlementEvent::query()->where('event_type', 'settled')->count())->toBe(0);

    $blockSettlementAudit = false;
    $settled = app(ExternalSettlementService::class)->settlePaymentRun(
        $run->fresh(),
        $checker,
        'settle-forced-failure',
    );
    $journal = FinJournal::query()->findOrFail($settled->journal_id)->load('lines');
    $bankLines = $journal->lines->where('account_id', $bank->gl_account_id);
    expect($settled->status)->toBe('settled')
        ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(1)
        ->and(FinPaymentAllocation::query()->count())->toBe(2)
        ->and((string) $bill->fresh()->amount_paid)->toBe('700.00')
        ->and((string) $secondBill->fresh()->amount_paid)->toBe('300.00')
        ->and($bankLines)->toHaveCount(1)
        ->and((string) $bankLines->sole()->credit)->toBe('1000.00');
});

it('revalidates the locked source and settlement against the sequence mutex organization', function (): void {
    [$bill, $bank, $creator, $approver, $checker] = externalSettlementFixture('712.34');
    $runs = app(PaymentRunService::class);
    $run = $runs->createPaymentRun(1, $creator, [
        'bank_account_id' => $bank->id,
        'payment_date' => now()->toDateString(),
        'bill_ids' => [$bill->id],
    ]);
    $runs->approvePaymentRun($run, $approver);
    $runs->processPaymentRun($run, $approver);
    $settlements = app(ExternalSettlementService::class);
    $settlements->markExported($run, ExternalSettlementService::PAYMENT_RUN, $approver);
    $settlements->accept(
        $run,
        ExternalSettlementService::PAYMENT_RUN,
        $checker,
        'accept-before-org-revalidation',
        'BANK-ACCEPTED-ORG-REVALIDATION',
        ['digest' => hash('sha256', 'accepted-before-org-revalidation')],
    );

    $realPosting = app(JournalPostingService::class);
    foreach (['fin_payment_runs', 'fin_external_settlements'] as $table) {
        $posting = Mockery::mock(JournalPostingService::class);
        $posting->shouldReceive('lockJournalSequence')
            ->once()
            ->with(1)
            ->andReturnUsing(function () use ($table, $run): void {
                $query = DB::table($table);
                $table === 'fin_payment_runs'
                    ? $query->where('id', $run->id)->update(['organization_id' => 2])
                    : $query
                        ->where('source_type', $run->getMorphClass())
                        ->where('source_id', $run->id)
                        ->where('purpose', ExternalSettlementService::PAYMENT_RUN)
                        ->update(['organization_id' => 2]);
            });
        $posting->shouldNotReceive('createAndPost');
        app()->instance(JournalPostingService::class, $posting);
        app()->forgetInstance(ExternalSettlementService::class);

        try {
            expect(fn () => app(ExternalSettlementService::class)->settlePaymentRun(
                $run->fresh(),
                $checker,
                "settle-after-{$table}-org-change",
            ))->toThrow(NotFoundHttpException::class);
        } finally {
            app()->instance(JournalPostingService::class, $realPosting);
            app()->forgetInstance(ExternalSettlementService::class);
        }
        expect((int) $run->fresh()->organization_id)->toBe(1)
            ->and((int) $run->externalSettlement()->firstOrFail()->organization_id)->toBe(1)
            ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(0)
            ->and(FinPaymentAllocation::query()->count())->toBe(0);
    }
});

it('keeps the 000130 foreign-key supporting indexes in a MySQL-safe order', function (): void {
    $source = file_get_contents(database_path('migrations/2026_08_23_000130_create_external_settlement_lifecycle.php'));
    $upStart = strpos($source, 'public function up(): void');
    $downStart = strpos($source, 'public function down(): void');
    $up = substr($source, $upStart, $downStart - $upStart);
    $down = substr($source, $downStart);
    $replacementIndexPosition = strpos(
        $up,
        "index('settlement_bill_id', 'fin_payment_run_items_settlement_bill_index')",
    );
    $legacyUniqueDropPosition = strpos(
        $up,
        "dropUnique('fin_payment_run_items_settlement_bill_unique')",
    );
    $legacyUniqueRestorePosition = strpos(
        $down,
        "unique('settlement_bill_id', 'fin_payment_run_items_settlement_bill_unique')",
    );
    $replacementIndexDropPosition = strpos(
        $down,
        "dropIndex('fin_payment_run_items_settlement_bill_index')",
    );
    $foreignPosition = strpos($down, "dropForeign(['active_settlement_bill_id'])");
    $uniquePosition = strpos($down, 'dropUnique(self::ACTIVE_BILL_UNIQUE)');
    $columnPosition = strpos($down, "dropColumn('active_settlement_bill_id')");

    expect($replacementIndexPosition)->not->toBeFalse()
        ->and($legacyUniqueDropPosition)->not->toBeFalse()
        ->and($legacyUniqueRestorePosition)->not->toBeFalse()
        ->and($replacementIndexDropPosition)->not->toBeFalse()
        ->and($foreignPosition)->not->toBeFalse()
        ->and($uniquePosition)->not->toBeFalse()
        ->and($columnPosition)->not->toBeFalse()
        ->and($replacementIndexPosition)->toBeLessThan($legacyUniqueDropPosition)
        ->and($legacyUniqueRestorePosition)->toBeLessThan($replacementIndexDropPosition)
        ->and($foreignPosition)->toBeLessThan($uniquePosition)
        ->and($uniquePosition)->toBeLessThan($columnPosition);
});

it('serializes export with accept and accept with settle at forced internal MySQL locks', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    [$exportBill, $exportBank, $exportCreator, $exportApprover, $exportChecker] = externalSettlementFixture('845.67');
    [$settleBill, $settleBank, $settleCreator, $settleApprover, $settleChecker] = externalSettlementFixture('956.78');
    $runs = app(PaymentRunService::class);
    $exportRun = $runs->createPaymentRun(1, $exportCreator, [
        'bank_account_id' => $exportBank->id,
        'payment_date' => now()->toDateString(),
        'bill_ids' => [$exportBill->id],
    ]);
    $runs->approvePaymentRun($exportRun, $exportApprover);
    $runs->processPaymentRun($exportRun, $exportApprover);

    $settleRun = $runs->createPaymentRun(1, $settleCreator, [
        'bank_account_id' => $settleBank->id,
        'payment_date' => now()->addDay()->toDateString(),
        'bill_ids' => [$settleBill->id],
    ]);
    $runs->approvePaymentRun($settleRun, $settleApprover);
    $runs->processPaymentRun($settleRun, $settleApprover);
    app(ExternalSettlementService::class)->markExported(
        $settleRun,
        ExternalSettlementService::PAYMENT_RUN,
        $settleApprover,
    );

    $database = $connection->getDatabaseName();
    $artifactRoot = Storage::disk('local')->path('');
    $userIds = [
        $exportCreator->id,
        $exportApprover->id,
        $exportChecker->id,
        $settleCreator->id,
        $settleApprover->id,
        $settleChecker->id,
    ];
    $siteIds = [$exportBill->site_id, $settleBill->site_id];
    $vendorIds = [$exportBill->vendor_id, $settleBill->vendor_id];
    $bankIds = [$exportBank->id, $settleBank->id];
    $billIds = [$exportBill->id, $settleBill->id];
    $connection->commit();

    try {
        $first = externalSettlementConcurrentRound(
            $database,
            $exportRun->id,
            $artifactRoot,
            [
                ['export_hold', $exportApprover->id],
                ['accept', $exportChecker->id],
            ],
        );
        expect(collect($first)->pluck('action')->sort()->values()->all())->toBe(['accept', 'export_hold'])
            ->and($exportRun->externalSettlement()->firstOrFail()->status)->toBe('accepted')
            ->and(FinExternalSettlementEvent::query()->where('event_type', 'accepted')->count())->toBe(1);

        $second = externalSettlementConcurrentRound(
            $database,
            $settleRun->id,
            $artifactRoot,
            [
                ['accept_hold', $settleChecker->id],
                ['settle', $settleChecker->id],
            ],
        );
        expect(collect($second)->pluck('action')->sort()->values()->all())->toBe(['accept_hold', 'settle'])
            ->and($settleRun->fresh()->status)->toBe('settled')
            ->and(FinJournal::query()->where('source_type', FinExternalSettlement::class)->count())->toBe(1)
            ->and(FinPaymentAllocation::query()->count())->toBe(1)
            ->and(FinExternalSettlementEvent::query()->where('event_type', 'settled')->count())->toBe(1);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::table('audit_logs')->delete();
        DB::table('fin_payment_allocations')->delete();
        DB::table('fin_external_settlement_events')->delete();
        DB::table('fin_external_settlements')->delete();
        DB::table('fin_payment_run_items')->delete();
        DB::table('fin_payment_runs')->delete();
        DB::table('fin_journal_lines')->delete();
        DB::table('fin_journals')->delete();
        DB::table('fin_bills')->whereIn('id', $billIds)->delete();
        DB::table('fin_vendors')->whereIn('id', $vendorIds)->delete();
        DB::table('fin_bank_accounts')->whereIn('id', $bankIds)->delete();
        DB::table('fin_fiscal_periods')->delete();
        DB::table('fin_accounts')->delete();
        DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        DB::table('sites')->whereIn('id', $siteIds)->delete();
        $connection->beginTransaction();
    }
});

/** @return array{FinBill, FinBankAccount, User, User, User} */
function externalSettlementFixture(string $amount): array
{
    $site = Site::factory()->create();
    $bank = FinBankAccount::factory()->create([
        'organization_id' => 1,
        'gl_account_id' => FinAccount::query()->where('code', '1000')->value('id'),
        'is_active' => true,
        'is_primary' => true,
    ]);
    $vendor = FinVendor::factory()->create([
        'organization_id' => 1,
        'bank_account_number' => '12-3456-7890123-00',
    ]);
    $bill = FinBill::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
        'vendor_id' => $vendor->id,
        'status' => 'approved',
        'total_amount' => $amount,
        'amount_paid' => '0.00',
    ]);

    return [
        $bill,
        $bank,
        externalSettlementUser(),
        externalSettlementUser(),
        externalSettlementUser(),
    ];
}

function externalSettlementUser(): User
{
    $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    foreach (['finance.ap.view', 'finance.ap.manage', PaymentSettlementSiteScope::GLOBAL_PERMISSION] as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $user;
}

/**
 * The first action must be the holder (`*_hold`). It acquires source and
 * settlement locks before the second worker starts. The second worker signals
 * immediately before its source lock and must not acquire it until release.
 *
 * @param  array{array{0:string,1:int},array{0:string,1:int}}  $actions
 * @return array<int, array{action:string,status:string,journal_id:int|null}>
 */
function externalSettlementConcurrentRound(
    string $database,
    int $runId,
    string $artifactRoot,
    array $actions,
): array {
    if (! str_ends_with($actions[0][0], '_hold') || str_ends_with($actions[1][0], '_hold')) {
        throw new InvalidArgumentException('A forced external-settlement round requires one holder followed by one contender.');
    }

    $token = (string) Str::uuid();
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."external-settlement-release-{$token}";
    $readyPaths = [];
    $acquiredPaths = [];
    $processes = [];

    try {
        foreach ($actions as $index => [$action, $actorId]) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."external-settlement-ready-{$index}-{$token}";
            $acquiredPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."external-settlement-acquired-{$index}-{$token}";
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/ExternalSettlementInterleavingWorker.php'),
                $database,
                $action,
                (string) $runId,
                (string) $actorId,
                $readyPaths[$index],
                $acquiredPaths[$index],
                $releasePath,
                $artifactRoot,
            ]);
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;

            $deadline = microtime(true) + 15;
            while (! is_file($readyPaths[$index])) {
                if (! $process->isRunning()) {
                    throw new RuntimeException(trim($process->getErrorOutput()) ?: 'An external-settlement worker exited before its lock barrier.');
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('An external-settlement worker did not reach its lock barrier.');
                }
                usleep(20_000);
            }
        }

        // The holder has already confirmed both locks. Give the contender's
        // immediately-following SELECT ... FOR UPDATE time to block and prove
        // it has not crossed the acquired marker before release.
        usleep(300_000);
        if (is_file($acquiredPaths[1]) || ! $processes[1]->isRunning()) {
            throw new RuntimeException('The external-settlement contender did not block on the held source lock.');
        }
        touch($releasePath);

        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'An external-settlement worker failed.');
            }
            $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([...$readyPaths, ...$acquiredPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
