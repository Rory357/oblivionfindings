<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\FinInvoice;
use App\Models\Quote;
use App\Models\ServiceAgreement;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class QuoteLifecycleService
{
    private const APPLICATION_STORAGE_CONTEXT_ID = 1;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /**
     * Resolve a direct quote identifier through the actor's current Site scope
     * before request-field validation. Mutating commands repeat this check
     * while holding the canonical quote lock.
     */
    public function assertAccessible(User $actor, int $quoteId): void
    {
        $this->authorizeManager($actor);
        $this->accessibleQuotes($actor)->whereKey($quoteId)->firstOrFail();
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, int $quoteId, array $attributes): Quote
    {
        return DB::transaction(function () use ($actor, $quoteId, $attributes): Quote {
            $quote = $this->lockAccessibleQuote($actor, $quoteId);
            $this->requireStatus($quote, 'draft', 'Only a draft quote can be updated.');

            if (array_key_exists('status', $attributes)
                && $attributes['status'] !== $quote->status) {
                $this->conflict('Quote status changes must use the canonical lifecycle action.');
            }
            unset($attributes['status']);

            if (array_key_exists('client_id', $attributes)) {
                $this->siteAccess->assertCanAccessClientId(
                    $actor,
                    (int) $attributes['client_id'],
                    ['reports.viewAny'],
                );
            }

            $quote->update($attributes);

            return $quote->refresh();
        }, attempts: 3);
    }

    public function send(User $actor, int $quoteId): Quote
    {
        return DB::transaction(function () use ($actor, $quoteId): Quote {
            $quote = $this->lockAccessibleQuote($actor, $quoteId);
            if ($quote->status === 'sent') {
                return $quote;
            }

            $this->requireStatus($quote, 'draft', 'Only a draft quote can be sent.');
            $quote->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            return $quote->refresh();
        }, attempts: 3);
    }

    public function accept(User $actor, int $quoteId): Quote
    {
        return DB::transaction(function () use ($actor, $quoteId): Quote {
            $quote = $this->lockAccessibleQuote($actor, $quoteId);
            if ($quote->status === 'accepted') {
                return $quote;
            }

            $this->requireStatus($quote, 'sent', 'Only a sent quote can be accepted.');
            $quote->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            return $quote->refresh();
        }, attempts: 3);
    }

    public function convertToAgreement(User $actor, int $quoteId): ServiceAgreement
    {
        return DB::transaction(function () use ($actor, $quoteId): ServiceAgreement {
            $quote = $this->lockAccessibleQuote($actor, $quoteId, withLines: true);
            $projection = $this->conversionProjection($quote);
            $conversionDigest = $projection['digest'];

            if ($quote->converted_to_agreement_id !== null
                || $quote->converted_to_invoice_id !== null) {
                $this->assertExclusiveDestination($quote, 'agreement');
                $this->assertReplayDigest($quote, $conversionDigest);

                $agreement = ServiceAgreement::query()
                    ->lockForUpdate()
                    ->findOrFail($quote->converted_to_agreement_id);
                if ((int) $agreement->source_quote_id !== (int) $quote->id
                    || (int) $agreement->client_id !== (int) $quote->client_id) {
                    throw new RuntimeException(
                        'The quote agreement link has conflicting source provenance.',
                    );
                }

                $agreementLines = $agreement->lineItems()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if (! QuoteConversionProjection::agreementMatches(
                    $quote,
                    $agreement,
                    $agreementLines,
                    $projection,
                )) {
                    $this->conflict(
                        'The linked agreement no longer matches the completed quote conversion.',
                    );
                }

                return $agreement->setRelation('lineItems', $agreementLines);
            }

            $this->assertUnconvertedAcceptedQuote($quote);
            $agreement = ServiceAgreement::query()->create([
                ...QuoteConversionProjection::agreementHeader($quote, $projection),
                'source_quote_id' => $quote->id,
                'status' => 'draft',
                'budget_used' => '0.00',
                'created_by' => $actor->id,
            ]);

            foreach (QuoteConversionProjection::agreementLines($projection) as $line) {
                $agreement->lineItems()->create([
                    ...$line,
                    'budget_used' => '0.00',
                ]);
            }

            $this->afterDestinationCreated($quote, $agreement);
            $quote->update([
                'status' => 'converted',
                'converted_to_agreement_id' => $agreement->id,
                'converted_by' => $actor->id,
                'converted_at' => now(),
                'conversion_digest' => $conversionDigest,
            ]);

            return $agreement->load('lineItems');
        }, attempts: 3);
    }

    /** @return array{invoice: FinInvoice, replayed: bool} */
    public function convertToInvoice(User $actor, int $quoteId): array
    {
        // Conceal an inaccessible direct object before the sequence row can be
        // established, then re-authorize and reselect under lock in the command.
        $this->assertAccessible($actor, $quoteId);

        return DB::transaction(function () use ($actor, $quoteId): array {
            // All generated invoice producers share this mutex. It precedes the
            // quote aggregate so different source services cannot deadlock while
            // allocating the same application-wide invoice number.
            FinInvoice::lockNumberSequence(self::APPLICATION_STORAGE_CONTEXT_ID);

            $quote = $this->lockAccessibleQuote($actor, $quoteId, withLines: true);
            $projection = $this->conversionProjection($quote);
            $conversionDigest = $projection['digest'];

            if ($quote->converted_to_invoice_id !== null
                || $quote->converted_to_agreement_id !== null) {
                $this->assertExclusiveDestination($quote, 'invoice');
                $this->assertReplayDigest($quote, $conversionDigest);

                $invoice = FinInvoice::query()
                    ->lockForUpdate()
                    ->findOrFail($quote->converted_to_invoice_id);
                if ($invoice->source_type !== Quote::class
                    || (int) $invoice->source_id !== (int) $quote->id
                    || (int) $invoice->quote_source_id !== (int) $quote->id
                    || (int) $invoice->client_id !== (int) $quote->client_id) {
                    throw new RuntimeException(
                        'The quote invoice link has conflicting source provenance.',
                    );
                }

                $invoiceLines = $invoice->lines()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if (! QuoteConversionProjection::invoiceMatches(
                    $quote,
                    $quote->client,
                    $invoice,
                    $invoiceLines,
                    $projection,
                    self::APPLICATION_STORAGE_CONTEXT_ID,
                )) {
                    $this->conflict(
                        'The linked invoice no longer matches the completed quote conversion.',
                    );
                }

                return [
                    'invoice' => $invoice->setRelation('lines', $invoiceLines),
                    'replayed' => true,
                ];
            }

            $this->assertUnconvertedAcceptedQuote($quote);
            $invoice = FinInvoice::query()->create([
                ...QuoteConversionProjection::invoiceHeader(
                    $quote,
                    $quote->client,
                    $projection,
                    self::APPLICATION_STORAGE_CONTEXT_ID,
                ),
                'invoice_number' => FinInvoice::nextNumber(self::APPLICATION_STORAGE_CONTEXT_ID),
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'status' => 'draft',
                'quote_source_id' => $quote->id,
                'created_by' => $actor->id,
            ]);

            foreach (QuoteConversionProjection::invoiceLines($projection) as $line) {
                $invoice->lines()->create($line);
            }

            $this->afterDestinationCreated($quote, $invoice);
            $quote->update([
                'status' => 'converted',
                'converted_to_invoice_id' => $invoice->id,
                'converted_by' => $actor->id,
                'converted_at' => now(),
                'conversion_digest' => $conversionDigest,
            ]);

            return ['invoice' => $invoice->load('lines'), 'replayed' => false];
        }, attempts: 3);
    }

    /**
     * Failure-injection seam for atomicity tests. Production subclasses should
     * not override this hook.
     */
    protected function afterDestinationCreated(Quote $quote, Model $destination): void {}

    private function lockAccessibleQuote(User $actor, int $quoteId, bool $withLines = false): Quote
    {
        $this->authorizeManager($actor);
        $quote = $this->accessibleQuotes($actor)
            ->whereKey($quoteId)
            ->lockForUpdate()
            ->firstOrFail();

        $this->siteAccess->assertCanAccessClientId(
            $actor,
            (int) $quote->client_id,
            ['reports.viewAny'],
        );
        $quote->load('client');

        if ($withLines) {
            $quote->setRelation(
                'lineItems',
                $quote->lineItems()->orderBy('id')->lockForUpdate()->get(),
            );
        }

        return $quote;
    }

    private function authorizeManager(User $actor): void
    {
        abort_unless($actor->canDo('finance.ar.manage'), 403);
    }

    private function accessibleQuotes(User $actor): Builder
    {
        return Quote::query()
            ->whereHas('client', fn (Builder $clientQuery) => $this->siteAccess->applyClientScope(
                $clientQuery,
                $actor,
                ['reports.viewAny'],
            ));
    }

    private function requireStatus(Quote $quote, string $status, string $message): void
    {
        if ($quote->status !== $status) {
            $this->conflict($message);
        }
        if ($quote->converted_to_agreement_id !== null
            || $quote->converted_to_invoice_id !== null
            || $quote->converted_by !== null
            || $quote->converted_at !== null
            || $quote->conversion_digest !== null) {
            throw new RuntimeException('The quote lifecycle has conflicting destination provenance.');
        }
    }

    private function assertUnconvertedAcceptedQuote(Quote $quote): void
    {
        $this->requireStatus(
            $quote,
            'accepted',
            'Only an accepted, unconverted quote can be converted.',
        );
    }

    private function assertExclusiveDestination(Quote $quote, string $expected): void
    {
        $hasAgreement = $quote->converted_to_agreement_id !== null;
        $hasInvoice = $quote->converted_to_invoice_id !== null;

        if ($quote->status !== 'converted'
            || $hasAgreement === $hasInvoice
            || $quote->converted_by === null
            || $quote->converted_at === null
            || ! is_string($quote->conversion_digest)
            || strlen($quote->conversion_digest) !== 64) {
            throw new RuntimeException('The quote has conflicting conversion provenance.');
        }
        if (($expected === 'agreement' && $hasInvoice)
            || ($expected === 'invoice' && $hasAgreement)) {
            $this->conflict('This quote has already been converted to the other destination.');
        }
    }

    private function assertReplayDigest(Quote $quote, string $conversionDigest): void
    {
        if (! hash_equals((string) $quote->conversion_digest, $conversionDigest)) {
            $this->conflict(
                'The accepted quote payload no longer matches the completed conversion.',
            );
        }
    }

    /** @return array<string, mixed> */
    private function conversionProjection(Quote $quote): array
    {
        try {
            return QuoteConversionProjection::make($quote, $quote->lineItems);
        } catch (RuntimeException $exception) {
            $this->conflict($exception->getMessage());
        }
    }

    private function conflict(string $message): never
    {
        throw ValidationException::withMessages(['quote' => $message]);
    }
}
