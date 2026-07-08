<?php

use App\Domain\Finance\Jobs\PostExpenseJournalJob;
use App\Domain\Finance\Services\ExpenseJournalService;
use App\Domain\Hr\Models\HrExpenseClaim;
use App\Domain\Hr\Services\ExpenseService;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

/**
 * Seam S8 — Expenses → Finance GL. Approving an expense claim posts it to the
 * General Ledger exactly once, and a posting failure is visible/recoverable:
 *   - ExpenseService::approveClaim dispatches PostExpenseJournalJob ONLY when
 *     journal_id is null (idempotent — no double-post);
 *   - PostExpenseJournalJob re-throws on failure (tries=3, backoff=30), so a GL
 *     outage is retried and lands in failed_jobs (visible), leaving journal_id
 *     null so the claim stays re-postable — never silently marked posted.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

test('S8 seam: approving a submitted expense claim dispatches the GL journal-posting job', function () {
    Bus::fake();
    Notification::fake();

    $claim = HrExpenseClaim::factory()->create(['status' => 'submitted']);
    $approver = User::factory()->create();

    app(ExpenseService::class)->approveClaim($claim, $approver);

    Bus::assertDispatched(PostExpenseJournalJob::class);
});

test('S8 seam: an already-journaled claim does NOT re-dispatch the posting job (idempotent, no double-post)', function () {
    Bus::fake();
    Notification::fake();

    $claim = HrExpenseClaim::factory()->create(['status' => 'submitted']);
    $claim->forceFill(['journal_id' => 999])->saveQuietly();
    $approver = User::factory()->create();

    app(ExpenseService::class)->approveClaim($claim->fresh(), $approver);

    Bus::assertNotDispatched(PostExpenseJournalJob::class);
});

test('S8 seam: a GL posting failure re-throws (retryable + visible) and leaves the claim un-journaled', function () {
    $claim = HrExpenseClaim::factory()->create(['status' => 'approved']);

    $service = Mockery::mock(ExpenseJournalService::class);
    $service->shouldReceive('postExpenseClaimJournal')
        ->once()
        ->andThrow(new \RuntimeException('GL unavailable'));

    // The job re-throws → Laravel retries (tries=3) then fails to failed_jobs;
    // the failure is never swallowed.
    expect(fn () => (new PostExpenseJournalJob($claim))->handle($service))
        ->toThrow(\RuntimeException::class);

    // No journal_id was set → the claim is still re-postable (recoverable).
    expect($claim->fresh()->journal_id)->toBeNull();
});
