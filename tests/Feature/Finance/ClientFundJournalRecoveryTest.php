<?php

use App\Domain\Finance\Jobs\PostClientFundJournalJob;
use App\Domain\Finance\Jobs\ReconcileUnpostedClientFundJournalsJob;
use App\Domain\Finance\Models\FinJournal;
use App\Models\Client;
use App\Models\ClientFund;
use App\Models\ClientFundTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function makeRecoverableClientFundTransaction(array $attributes = []): ClientFundTransaction
{
    $organizationId = (int) ($attributes['organization_id'] ?? 1);
    $client = Client::factory()->create(['organization_id' => $organizationId]);
    $fund = ClientFund::query()->create([
        'organization_id' => $organizationId,
        'client_id' => $client->id,
        'fund_name' => 'Recovery test fund',
        'fund_type' => 'trust',
        'balance' => '10.00',
        'is_active' => true,
    ]);
    $actor = User::factory()->create(['organization_id' => $organizationId]);

    $transaction = $fund->transactions()->create(array_merge([
        'organization_id' => $organizationId,
        'transaction_type' => 'credit',
        'amount' => '10.00',
        'running_balance' => '10.00',
        'description' => 'Pending GL recovery',
        'transaction_date' => now()->toDateString(),
        'recorded_by' => $actor->id,
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ], $attributes));

    // created_at/updated_at are intentionally not mass assignable on the
    // production model. Set the requested recovery age explicitly.
    $transaction->timestamps = false;
    $transaction->forceFill([
        'created_at' => $attributes['created_at'] ?? now()->subMinutes(10),
        'updated_at' => $attributes['updated_at'] ?? now()->subMinutes(10),
    ])->saveQuietly();

    return $transaction->refresh();
}

it('redispatches every durable unposted client-fund transaction and skips ineligible rows', function () {
    $pending = makeRecoverableClientFundTransaction();
    $alreadyQueuedRecently = makeRecoverableClientFundTransaction([
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $zeroAmount = makeRecoverableClientFundTransaction(['amount' => '0.00']);
    $journal = FinJournal::factory()->create(['organization_id' => 1]);
    $posted = makeRecoverableClientFundTransaction([
        'journal_id' => $journal->id,
        'gl_posted_at' => now(),
    ]);

    // Ignore any model-observer activity while fixtures are constructed; only
    // jobs emitted by the recovery sweep count for this assertion.
    Queue::fake([PostClientFundJournalJob::class]);

    (new ReconcileUnpostedClientFundJournalsJob)->handle();

    Queue::assertPushed(
        PostClientFundJournalJob::class,
        fn (PostClientFundJournalJob $job): bool => $job->transaction->is($pending),
    );
    Queue::assertNotPushed(
        PostClientFundJournalJob::class,
        fn (PostClientFundJournalJob $job): bool => in_array(
            $job->transaction->id,
            [$alreadyQueuedRecently->id, $zeroAmount->id, $posted->id],
            true,
        ),
    );
    Queue::assertCount(1);
});

it('configures journal posting for retry and leaves the transaction as the durable recovery record', function () {
    $transaction = makeRecoverableClientFundTransaction();
    $job = new PostClientFundJournalJob($transaction);

    expect($job->tries)->toBeGreaterThan(1)
        ->and($job->backoff)->toBeArray()
        ->and($job->backoff)->not->toBeEmpty()
        ->and($transaction->fresh()->journal_id)->toBeNull();
});
