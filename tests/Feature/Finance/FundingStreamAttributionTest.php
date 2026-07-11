<?php

use App\Domain\Finance\Jobs\PostFinInvoiceJournalJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\AccountsReceivableService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Funder attribution on AR captures (the GL-level "drawdown"): a capture whose
 * funder key matches a configured FinFundingStream carries the stream (and its
 * default revenue account) on the invoice line, and the send-journal's revenue
 * line carries funding_stream_id — which the funding-stream summary reads.
 * Helpers `rfa_*`.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create(['organization_id' => 1]));

    foreach ([['1100', 'Accounts Receivable', 'asset'], ['4000', 'Whaikaha Funding Revenue', 'revenue'], ['2200', 'GST Collected', 'liability']] as [$code, $name, $type]) {
        FinAccount::factory()->create([
            'organization_id' => 1, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true,
        ]);
    }
    FinFiscalPeriod::create([
        'organization_id' => 1, 'name' => 'FY', 'status' => 'open',
        'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(),
    ]);

    $this->stream = FinFundingStream::create([
        'organization_id' => 1,
        'code' => 'WHAIKAHA',
        'name' => 'Whaikaha — Individualised Funding',
        'funder_type' => 'whaikaha',
        'default_revenue_account_id' => FinAccount::where('code', '4000')->value('id'),
        'is_active' => true,
    ]);
});

it('resolves a funding stream from an operational funder key (code or funder_type)', function () {
    $svc = app(AccountsReceivableService::class);

    expect($svc->resolveFundingStream(1, 'whaikaha')?->id)->toBe($this->stream->id)
        ->and($svc->resolveFundingStream(1, 'WHAIKAHA')?->id)->toBe($this->stream->id)
        ->and($svc->resolveFundingStream(1, 'msd'))->toBeNull()
        ->and($svc->resolveFundingStream(1, null))->toBeNull();
});

it('carries the stream + its default revenue account onto the captured invoice line', function () {
    $invoice = app(AccountsReceivableService::class)->captureOperationalInvoice(1, [
        'source_type' => 'App\\Models\\RespiteBooking',
        'source_id' => 31,
        'funding_body' => $this->stream->name,
        'description' => 'Respite care — 3 night(s)',
        'quantity' => 3,
        'unit_price' => 250,
        'gst_rate' => 0,
        'revenue_account_id' => $this->stream->default_revenue_account_id,
        'revenue_account_code' => '4030', // must lose to the explicit id
        'funding_stream_id' => $this->stream->id,
    ]);

    $line = $invoice->lines()->first();
    expect($line->funding_stream_id)->toBe($this->stream->id)
        ->and($line->account_id)->toBe($this->stream->default_revenue_account_id);
});

it('the send-journal revenue line carries the funding stream into the GL', function () {
    $invoice = app(AccountsReceivableService::class)->captureOperationalInvoice(1, [
        'source_type' => 'App\\Models\\RespiteBooking',
        'source_id' => 32,
        'funding_body' => $this->stream->name,
        'description' => 'Respite care — 2 night(s)',
        'quantity' => 2,
        'unit_price' => 250,
        'gst_rate' => 0,
        'revenue_account_id' => $this->stream->default_revenue_account_id,
        'funding_stream_id' => $this->stream->id,
    ]);

    $invoice->update(['status' => 'sent']);
    PostFinInvoiceJournalJob::dispatch($invoice->fresh()); // sync → inline

    $journal = FinJournal::with('lines')->find($invoice->fresh()->journal_id);
    expect($journal)->not->toBeNull();

    $revenueLine = $journal->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 2) > 0);
    expect($revenueLine->funding_stream_id)->toBe($this->stream->id)
        ->and((float) $revenueLine->credit)->toBe(500.0);
});
