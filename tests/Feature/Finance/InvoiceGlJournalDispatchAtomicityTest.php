<?php

use App\Domain\Finance\Jobs\PostFinInvoiceJournalJob;
use App\Domain\Finance\Jobs\SendInvoiceEmailJob;
use App\Domain\Finance\Models\FinInvoice;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('sending invoice durably dispatches GL journal posting job via afterCommit', function () {
    Queue::fake([PostFinInvoiceJournalJob::class, SendInvoiceEmailJob::class]);

    $user = User::factory()->create(['organization_id' => 1]);
    $perm = Permission::query()->firstOrCreate(
        ['key' => 'finance.ar.manage'],
        ['description' => 'finance.ar.manage', 'group' => 'fin', 'module' => 'Finance']
    );
    $user->permissionOverrides()->attach($perm, ['allowed' => true]);

    $invoice = FinInvoice::factory()->create([
        'organization_id' => 1,
        'status' => 'draft',
        'client_email' => 'client@example.com',
        'journal_id' => null,
    ]);

    $response = $this->actingAs($user)->post(route('finance.invoices.send', $invoice));
    $response->assertRedirect();

    $invoice->refresh();
    expect($invoice->status)->toBe('sent')
        ->and($invoice->sent_at)->not->toBeNull();

    // Verify PostFinInvoiceJournalJob was dispatched for this invoice
    Queue::assertPushed(PostFinInvoiceJournalJob::class, function ($job) use ($invoice) {
        return $job->invoice->id === $invoice->id;
    });

    // Verify SendInvoiceEmailJob was also dispatched
    Queue::assertPushed(SendInvoiceEmailJob::class, function ($job) use ($invoice) {
        return $job->invoiceId === $invoice->id;
    });
});
