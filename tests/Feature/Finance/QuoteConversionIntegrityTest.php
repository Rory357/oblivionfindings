<?php

use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Services\QuoteLifecycleService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

function quoteConversionActor(Site $site, array $permissions = ['finance.ar.manage']): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    foreach ($permissions as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key],
        );
        $actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    HrEmployeeProfile::query()->create([
        'user_id' => $actor->id,
        'employee_number' => 'EMP-QUOTE-INTEGRITY-'.$actor->id,
        'work_email' => $actor->email,
        'position_title' => 'Accounts Receivable Manager',
        'position_role' => 'manager',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $actor;
}

function quoteConversionQuote(Client $client, string $reference, string $status = 'accepted'): Quote
{
    $quote = Quote::query()->create([
        'client_id' => $client->id,
        'quote_number' => $reference,
        'title' => 'Support quote '.$reference,
        'status' => $status,
        'client_name' => trim($client->first_name.' '.$client->last_name),
        'client_email' => 'quote-client@example.test',
        'subtotal' => '150.00',
        'tax_amount' => '22.50',
        'total_amount' => '172.50',
        'sent_at' => in_array($status, ['sent', 'accepted'], true) ? now()->subHour() : null,
        'accepted_at' => $status === 'accepted' ? now() : null,
        'terms' => 'Support terms',
        'notes' => 'Support notes',
    ]);

    foreach ([['Line A', '100.00'], ['Line B', '50.00']] as [$description, $amount]) {
        QuoteLineItem::query()->create([
            'quote_id' => $quote->id,
            'description' => $description,
            'quantity' => '1.00',
            'unit' => 'hour',
            'unit_price' => $amount,
            'amount' => $amount,
        ]);
    }

    return $quote;
}

beforeEach(function (): void {
    $this->quoteSite = Site::factory()->create();
    $this->quoteClient = Client::factory()->create([
        'site_id' => $this->quoteSite->id,
        'first_name' => 'Ana',
        'last_name' => 'Smith',
    ]);
    $this->governedQuote = quoteConversionQuote($this->quoteClient, 'Q-INTEGRITY-1');
});

it('creates one complete agreement with durable source and conversion lineage', function (): void {
    $actor = quoteConversionActor($this->quoteSite);

    $this->actingAs($actor)
        ->post(route('finance.quotes.convert', $this->governedQuote))
        ->assertRedirect();

    $agreement = ServiceAgreement::query()
        ->where('source_quote_id', $this->governedQuote->id)
        ->with('lineItems')
        ->firstOrFail();
    $quote = $this->governedQuote->fresh();

    expect($agreement->client_id)->toBe($this->quoteClient->id)
        ->and($agreement->agreement_type)->toBe('private')
        ->and((string) $agreement->total_budget)->toBe('172.50')
        ->and($agreement->gst_inclusive)->toBeTrue()
        ->and($agreement->created_by)->toBe($actor->id)
        ->and($agreement->created_at)->not->toBeNull()
        ->and($agreement->lineItems)->toHaveCount(2)
        ->and((string) $agreement->lineItems->firstWhere('description', 'Line A')->budget_allocated)->toBe('115.00')
        ->and((string) $agreement->lineItems->firstWhere('description', 'Line B')->budget_allocated)->toBe('57.50')
        ->and($quote->status)->toBe('converted')
        ->and($quote->converted_to_agreement_id)->toBe($agreement->id)
        ->and($quote->converted_to_invoice_id)->toBeNull()
        ->and($quote->converted_by)->toBe($actor->id)
        ->and($quote->converted_at)->not->toBeNull()
        ->and($quote->conversion_digest)->toHaveLength(64);

    $this->actingAs($actor)
        ->post(route('finance.quotes.convert', $quote))
        ->assertRedirect();
    expect(ServiceAgreement::query()->where('source_quote_id', $quote->id)->count())->toBe(1);

    $this->actingAs($actor)
        ->from(route('finance.quotes.show', $quote))
        ->post(route('finance.quotes.convert-to-invoice', $quote))
        ->assertRedirect(route('finance.quotes.show', $quote))
        ->assertSessionHasErrors('quote');
    expect(FinInvoice::query()->where('quote_source_id', $quote->id)->exists())->toBeFalse();
});

it('binds terminal agreement and invoice replays to the accepted quote payload', function (): void {
    $actor = quoteConversionActor($this->quoteSite);

    $this->actingAs($actor)
        ->post(route('finance.quotes.convert', $this->governedQuote))
        ->assertRedirect();
    $agreementId = $this->governedQuote->fresh()->converted_to_agreement_id;

    DB::table('quotes')->where('id', $this->governedQuote->id)->update([
        'title' => 'Changed after agreement conversion',
    ]);

    $this->actingAs($actor)
        ->from(route('finance.quotes.show', $this->governedQuote))
        ->post(route('finance.quotes.convert', $this->governedQuote))
        ->assertSessionHasErrors('quote');
    expect($this->governedQuote->fresh()->converted_to_agreement_id)->toBe($agreementId)
        ->and(ServiceAgreement::query()->where('source_quote_id', $this->governedQuote->id)->count())->toBe(1);

    $invoiceQuote = quoteConversionQuote($this->quoteClient, 'Q-DIGEST-INVOICE');
    $this->actingAs($actor)
        ->post(route('finance.quotes.convert-to-invoice', $invoiceQuote))
        ->assertRedirect();
    $invoiceId = $invoiceQuote->fresh()->converted_to_invoice_id;

    DB::table('quotes')->where('id', $invoiceQuote->id)->update([
        'terms' => 'Changed after invoice conversion',
    ]);

    $this->actingAs($actor)
        ->from(route('finance.quotes.show', $invoiceQuote))
        ->post(route('finance.quotes.convert-to-invoice', $invoiceQuote))
        ->assertSessionHasErrors('quote');
    expect($invoiceQuote->fresh()->converted_to_invoice_id)->toBe($invoiceId)
        ->and(FinInvoice::query()->where('quote_source_id', $invoiceQuote->id)->count())->toBe(1);
});

it('rejects terminal replay when a linked destination no longer matches the conversion', function (): void {
    $actor = quoteConversionActor($this->quoteSite);

    $this->actingAs($actor)
        ->post(route('finance.quotes.convert', $this->governedQuote))
        ->assertRedirect();
    $agreementId = $this->governedQuote->fresh()->converted_to_agreement_id;
    DB::table('service_agreements')->where('id', $agreementId)->update([
        'terms' => 'Tampered agreement terms',
    ]);

    $this->actingAs($actor)
        ->from(route('finance.quotes.show', $this->governedQuote))
        ->post(route('finance.quotes.convert', $this->governedQuote))
        ->assertSessionHasErrors('quote');
    expect($this->governedQuote->fresh()->converted_to_agreement_id)->toBe($agreementId)
        ->and(ServiceAgreement::query()->where('source_quote_id', $this->governedQuote->id)->count())->toBe(1);

    $invoiceQuote = quoteConversionQuote($this->quoteClient, 'Q-TAMPERED-INVOICE');
    $this->actingAs($actor)
        ->post(route('finance.quotes.convert-to-invoice', $invoiceQuote))
        ->assertRedirect();
    $invoiceId = $invoiceQuote->fresh()->converted_to_invoice_id;
    $invoiceLineId = DB::table('fin_invoice_lines')
        ->where('invoice_id', $invoiceId)
        ->orderBy('sort_order')
        ->orderBy('id')
        ->value('id');
    DB::table('fin_invoice_lines')->where('id', $invoiceLineId)->update([
        'line_total' => '999.00',
    ]);

    $this->actingAs($actor)
        ->from(route('finance.quotes.show', $invoiceQuote))
        ->post(route('finance.quotes.convert-to-invoice', $invoiceQuote))
        ->assertSessionHasErrors('quote');
    expect($invoiceQuote->fresh()->converted_to_invoice_id)->toBe($invoiceId)
        ->and(FinInvoice::query()->where('quote_source_id', $invoiceQuote->id)->count())->toBe(1);
});

it('owns terminal lifecycle transitions and rejects generic or invalid state rewrites', function (): void {
    $actor = quoteConversionActor($this->quoteSite);
    $draft = quoteConversionQuote($this->quoteClient, 'Q-LIFECYCLE', 'draft');

    $this->actingAs($actor)
        ->put(route('finance.quotes.update', $draft), ['status' => 'accepted'])
        ->assertSessionHasErrors('quote');
    expect($draft->fresh()->status)->toBe('draft');

    $this->actingAs($actor)->post(route('finance.quotes.send', $draft))->assertRedirect();
    expect($draft->fresh()->status)->toBe('sent')
        ->and($draft->fresh()->sent_at)->not->toBeNull();

    $this->actingAs($actor)->post(route('finance.quotes.accept', $draft))->assertRedirect();
    expect($draft->fresh()->status)->toBe('accepted')
        ->and($draft->fresh()->accepted_at)->not->toBeNull();

    $this->actingAs($actor)->post(route('finance.quotes.accept', $draft))->assertRedirect();
    $this->actingAs($actor)
        ->put(route('finance.quotes.update', $draft), ['title' => 'Terminal rewrite'])
        ->assertSessionHasErrors('quote');
    $this->actingAs($actor)
        ->post(route('finance.quotes.send', $draft))
        ->assertSessionHasErrors('quote');
    expect($draft->fresh()->title)->toBe('Support quote Q-LIFECYCLE');

    $unaccepted = quoteConversionQuote($this->quoteClient, 'Q-NOT-ACCEPTED', 'draft');
    $this->actingAs($actor)
        ->post(route('finance.quotes.convert-to-invoice', $unaccepted))
        ->assertSessionHasErrors('quote');
    expect($unaccepted->fresh()->status)->toBe('draft')
        ->and(FinInvoice::query()->where('quote_source_id', $unaccepted->id)->exists())->toBeFalse();
});

it('conceals foreign Site quote IDs while global scope still requires the finance action', function (): void {
    $otherSite = Site::factory()->create();
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $foreignQuote = quoteConversionQuote($otherClient, 'Q-FOREIGN');
    $siteActor = quoteConversionActor($this->quoteSite);

    $this->actingAs($siteActor)
        ->post(route('finance.quotes.convert-to-invoice', $foreignQuote))
        ->assertNotFound();
    $this->actingAs($siteActor)
        ->put(route('finance.quotes.update', $foreignQuote), [
            'title' => '',
            'status' => 'not-a-real-status',
        ])
        ->assertNotFound();
    expect(DB::table('fin_invoice_sequences')->exists())->toBeFalse()
        ->and($foreignQuote->fresh()->status)->toBe('accepted');

    $globalWithoutAction = quoteConversionActor($this->quoteSite, ['reports.viewAny']);
    $this->actingAs($globalWithoutAction)
        ->post(route('finance.quotes.convert-to-invoice', $foreignQuote))
        ->assertForbidden();
    expect($foreignQuote->fresh()->status)->toBe('accepted');

    $globalManager = quoteConversionActor($this->quoteSite, [
        'reports.viewAny',
        'finance.ar.manage',
    ]);
    $this->actingAs($globalManager)
        ->post(route('finance.quotes.convert-to-invoice', $foreignQuote))
        ->assertRedirect();

    $invoice = FinInvoice::query()->where('quote_source_id', $foreignQuote->id)->firstOrFail();
    expect($foreignQuote->fresh()->status)->toBe('converted')
        ->and($foreignQuote->fresh()->converted_by)->toBe($globalManager->id)
        ->and($invoice->source)->toBe('quote')
        ->and($invoice->source_type)->toBe(Quote::class)
        ->and($invoice->source_id)->toBe($foreignQuote->id)
        ->and($invoice->created_by)->toBe($globalManager->id)
        ->and((string) $invoice->total_amount)->toBe('172.50')
        ->and($invoice->lines()->count())->toBe(2)
        ->and(FinInvoice::query()->where('quote_source_id', $foreignQuote->id)->count())->toBe(1);
});

it('rolls back destination, lines, link, and number allocation as one command', function (): void {
    $actor = quoteConversionActor($this->quoteSite);
    $service = new class(app(UserSiteAccessService::class)) extends QuoteLifecycleService
    {
        protected function afterDestinationCreated(Quote $quote, Model $destination): void
        {
            throw new RuntimeException('Injected conversion failure.');
        }
    };

    expect(fn () => $service->convertToInvoice($actor, $this->governedQuote->id))
        ->toThrow(RuntimeException::class, 'Injected conversion failure.');

    expect($this->governedQuote->fresh()->status)->toBe('accepted')
        ->and($this->governedQuote->fresh()->converted_to_invoice_id)->toBeNull()
        ->and($this->governedQuote->fresh()->converted_by)->toBeNull()
        ->and($this->governedQuote->fresh()->conversion_digest)->toBeNull()
        ->and(FinInvoice::query()->where('quote_source_id', $this->governedQuote->id)->exists())->toBeFalse()
        ->and(DB::table('fin_invoice_lines')->exists())->toBeFalse()
        ->and(DB::table('fin_invoice_sequences')->exists())->toBeFalse();
});

it('backfills only matching legacy quote lineage and seeds the durable number floor', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    $actor = quoteConversionActor($this->quoteSite);
    $connection->commit();

    $path = database_path('migrations/2026_08_23_000120_govern_quote_conversion.php');
    /** @var Migration $migration */
    $migration = require $path;
    $migration->down();

    try {
        $sourceInvoice = FinInvoice::query()->create([
            'organization_id' => 1,
            'client_id' => $this->quoteClient->id,
            'invoice_number' => 'INV-00123',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_name' => 'Ana Smith',
            'subtotal' => '150.00',
            'tax_amount' => '22.50',
            'total_amount' => '172.50',
            'status' => 'draft',
            'source' => 'quote',
            'source_type' => Quote::class,
            'source_id' => $this->governedQuote->id,
            'client_email' => 'quote-client@example.test',
            'terms' => 'Support terms',
            'notes' => 'Support notes',
            'created_by' => $actor->id,
        ]);
        FinInvoice::query()->create([
            'organization_id' => 1,
            'invoice_number' => 'INV-00999',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_name' => 'Legacy number floor',
            'subtotal' => '1.00',
            'tax_amount' => '0.15',
            'total_amount' => '1.15',
            'status' => 'draft',
            'source' => 'manual',
            'created_by' => $actor->id,
        ]);
        DB::table('quotes')->where('id', $this->governedQuote->id)->update([
            'status' => 'converted',
            'converted_to_invoice_id' => $sourceInvoice->id,
        ]);

        expect(fn () => $migration->up())
            ->toThrow(RuntimeException::class, 'reconciliation is required');
        expect(Schema::hasTable('fin_invoice_sequences'))->toBeFalse()
            ->and(Schema::hasColumn('quotes', 'conversion_digest'))->toBeFalse();

        foreach ([
            ['Line A', '100.00', '15.00', '115.00', 0],
            ['Line B', '50.00', '7.50', '57.50', 1],
        ] as [$description, $unitPrice, $tax, $gross, $sortOrder]) {
            $sourceInvoice->lines()->create([
                'description' => $description,
                'quantity' => '1.00',
                'unit_price' => $unitPrice,
                'tax_amount' => $tax,
                'line_total' => $gross,
                'sort_order' => $sortOrder,
            ]);
        }

        $migration->up();

        $quote = $this->governedQuote->fresh();
        $digest = $quote->conversion_digest;
        $replay = app(QuoteLifecycleService::class)
            ->convertToInvoice($actor, $quote->id);
        expect($sourceInvoice->fresh()->quote_source_id)->toBe($quote->id)
            ->and($quote->converted_by)->toBe($actor->id)
            ->and($quote->converted_at?->equalTo($sourceInvoice->created_at))->toBeTrue()
            ->and($quote->conversion_digest)->toHaveLength(64)
            ->and($replay['replayed'])->toBeTrue()
            ->and($replay['invoice']->id)->toBe($sourceInvoice->id)
            ->and($quote->fresh()->conversion_digest)->toBe($digest)
            ->and(DB::table('fin_invoice_sequences')->where('organization_id', 1)->value('next_number'))->toBe(1000);
    } finally {
        if (Schema::hasColumn('quotes', 'converted_by')) {
            DB::table('quotes')->update([
                'status' => 'accepted',
                'converted_to_agreement_id' => null,
                'converted_to_invoice_id' => null,
                'converted_by' => null,
                'converted_at' => null,
                'conversion_digest' => null,
            ]);
        }
        DB::table('fin_invoice_lines')->delete();
        DB::table('fin_invoices')->delete();
        DB::table('quote_line_items')->delete();
        DB::table('quotes')->delete();
        if (Schema::hasTable('fin_invoice_sequences')) {
            DB::table('fin_invoice_sequences')->delete();
        }
        DB::table('audit_logs')->delete();
        DB::table('hr_employee_profiles')->delete();
        DB::table('clients')->delete();
        DB::table('users')->delete();
        DB::table('sites')->delete();
        $connection->beginTransaction();
    }
});

it('serializes same, cross-destination, lifecycle, and number races on MySQL', function (): void {
    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');

    $actor = quoteConversionActor($this->quoteSite);
    $database = $connection->getDatabaseName();
    $connection->commit();

    try {
        DB::table('fin_invoice_sequences')->insertOrIgnore([
            'organization_id' => 1,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $same = quoteConversionConcurrentRound($connection, $database, [
            ['actor_id' => $actor->id, 'quote_id' => $this->governedQuote->id, 'action' => 'invoice'],
            ['actor_id' => $actor->id, 'quote_id' => $this->governedQuote->id, 'action' => 'invoice'],
        ], 'sequence', 1);
        expect(array_column($same, 'status'))->toBe(['invoice', 'invoice'])
            ->and(array_unique(array_column($same, 'destination_id')))->toHaveCount(1)
            ->and(FinInvoice::query()->where('quote_source_id', $this->governedQuote->id)->count())->toBe(1);

        $crossQuote = quoteConversionQuote($this->quoteClient, 'Q-CROSS');
        $cross = quoteConversionConcurrentRound($connection, $database, [
            ['actor_id' => $actor->id, 'quote_id' => $crossQuote->id, 'action' => 'invoice'],
            ['actor_id' => $actor->id, 'quote_id' => $crossQuote->id, 'action' => 'agreement'],
        ], 'quote', $crossQuote->id);
        $crossStatuses = array_column($cross, 'status');
        expect(collect($crossStatuses)->filter(fn (string $status) => $status === 'conflict'))->toHaveCount(1)
            ->and(collect($crossStatuses)->filter(fn (string $status) => in_array($status, ['invoice', 'agreement'], true)))->toHaveCount(1);
        $crossQuote->refresh();
        $hasExactlyOneDestination = (
            ($crossQuote->converted_to_invoice_id === null)
            xor ($crossQuote->converted_to_agreement_id === null)
        );
        expect($crossQuote->status)->toBe('converted')
            ->and($hasExactlyOneDestination)->toBeTrue();

        $lifecycleQuote = quoteConversionQuote($this->quoteClient, 'Q-LIFECYCLE-RACE', 'sent');
        $lifecycle = quoteConversionConcurrentRound($connection, $database, [
            ['actor_id' => $actor->id, 'quote_id' => $lifecycleQuote->id, 'action' => 'accept'],
            ['actor_id' => $actor->id, 'quote_id' => $lifecycleQuote->id, 'action' => 'invoice'],
        ], 'quote', $lifecycleQuote->id);
        $lifecycleStatuses = array_column($lifecycle, 'status');
        expect($lifecycleStatuses)->toContain('accepted')
            ->and(collect($lifecycleStatuses)->filter(fn (string $status) => in_array($status, ['conflict', 'invoice'], true)))->toHaveCount(1);
        $lifecycleQuote->refresh();
        expect(in_array($lifecycleQuote->status, ['accepted', 'converted'], true))->toBeTrue()
            ->and(ServiceAgreement::query()->where('source_quote_id', $lifecycleQuote->id)->exists())->toBeFalse()
            ->and(FinInvoice::query()->where('quote_source_id', $lifecycleQuote->id)->count())
            ->toBe($lifecycleQuote->status === 'converted' ? 1 : 0);

        FinInvoice::query()->create([
            'organization_id' => 1,
            'invoice_number' => 'INV-09000',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'client_name' => 'Manual floor invoice',
            'subtotal' => '1.00',
            'tax_amount' => '0.15',
            'total_amount' => '1.15',
            'status' => 'draft',
            'source' => 'manual',
            'created_by' => $actor->id,
        ]);
        $firstNumberQuote = quoteConversionQuote($this->quoteClient, 'Q-NUMBER-A');
        $secondNumberQuote = quoteConversionQuote($this->quoteClient, 'Q-NUMBER-B');
        $numberRace = quoteConversionConcurrentRound($connection, $database, [
            ['actor_id' => $actor->id, 'quote_id' => $firstNumberQuote->id, 'action' => 'invoice'],
            ['actor_id' => $actor->id, 'quote_id' => $secondNumberQuote->id, 'action' => 'invoice'],
        ], 'sequence', 1);
        $numbers = array_column($numberRace, 'number');
        sort($numbers);
        expect(array_column($numberRace, 'status'))->toBe(['invoice', 'invoice'])
            ->and($numbers)->toBe(['INV-09001', 'INV-09002'])
            ->and(array_unique($numbers))->toHaveCount(2);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        DB::table('quotes')->update([
            'status' => 'accepted',
            'converted_to_agreement_id' => null,
            'converted_to_invoice_id' => null,
            'converted_by' => null,
            'converted_at' => null,
            'conversion_digest' => null,
        ]);
        DB::table('fin_invoice_lines')->delete();
        DB::table('service_agreement_line_items')->delete();
        DB::table('fin_invoices')->delete();
        DB::table('service_agreements')->delete();
        DB::table('quote_line_items')->delete();
        DB::table('quotes')->delete();
        DB::table('fin_invoice_sequences')->delete();
        DB::table('audit_logs')->delete();
        DB::table('hr_employee_profiles')->delete();
        DB::table('clients')->delete();
        DB::table('users')->delete();
        DB::table('sites')->delete();
        $connection->beginTransaction();
    }
});

/**
 * @param  list<array{actor_id:int,quote_id:int,action:string}>  $commands
 * @return list<array<string, mixed>>
 */
function quoteConversionConcurrentRound(
    ConnectionInterface $connection,
    string $database,
    array $commands,
    string $barrier,
    int $barrierId,
): array {
    $token = bin2hex(random_bytes(8));
    $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."quote-conversion-release-{$token}";
    $readyPaths = [];
    $attemptPaths = [];
    $processes = [];

    $connection->beginTransaction();
    if ($barrier === 'sequence') {
        DB::table('fin_invoice_sequences')
            ->where('organization_id', $barrierId)
            ->lockForUpdate()
            ->firstOrFail();
    } elseif ($barrier === 'quote') {
        Quote::query()->whereKey($barrierId)->lockForUpdate()->firstOrFail();
    } else {
        throw new RuntimeException('Unsupported quote-conversion barrier.');
    }

    try {
        foreach ($commands as $index => $command) {
            $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."quote-conversion-ready-{$index}-{$token}";
            $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."quote-conversion-attempt-{$index}-{$token}";
            $process = new Process([
                PHP_BINARY,
                base_path('tests/Support/QuoteConversionWorker.php'),
                $database,
                (string) $command['actor_id'],
                (string) $command['quote_id'],
                $command['action'],
                $readyPaths[$index],
                $releasePath,
                $attemptPaths[$index],
            ], base_path());
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }

        quoteConversionWaitForFiles($readyPaths, 'Quote-conversion workers did not become ready.');
        touch($releasePath);
        quoteConversionWaitForFiles($attemptPaths, 'Quote-conversion workers did not reach the command.');
        usleep(250_000);
        foreach ($processes as $process) {
            if (! $process->isRunning()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A quote-conversion worker exited before lock release.');
            }
        }

        $connection->commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A quote-conversion concurrency worker failed.');
            }
            $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

/** @param list<string> $paths */
function quoteConversionWaitForFiles(array $paths, string $message): void
{
    $deadline = microtime(true) + 20;
    while (collect($paths)->contains(fn (string $path) => ! is_file($path))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }

        usleep(20_000);
    }
}
