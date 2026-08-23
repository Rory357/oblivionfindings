<?php

use App\Domain\Finance\Services\QuoteConversionProjection;
use App\Models\Quote;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AGREEMENT_SOURCE_UNIQUE = 'service_agreements_source_quote_unique';

    private const AGREEMENT_SOURCE_FOREIGN = 'service_agreements_source_quote_fk';

    private const AGREEMENT_BACKLINK_UNIQUE = 'service_agreements_id_source_quote_unique';

    private const INVOICE_SOURCE_UNIQUE = 'fin_invoices_quote_source_unique';

    private const INVOICE_SOURCE_FOREIGN = 'fin_invoices_quote_source_fk';

    private const INVOICE_BACKLINK_UNIQUE = 'fin_invoices_id_quote_source_unique';

    private const QUOTE_AGREEMENT_UNIQUE = 'quotes_conversion_agreement_unique';

    private const QUOTE_AGREEMENT_FOREIGN = 'quotes_conversion_agreement_fk';

    private const QUOTE_INVOICE_UNIQUE = 'quotes_conversion_invoice_unique';

    private const QUOTE_INVOICE_FOREIGN = 'quotes_conversion_invoice_fk';

    private const QUOTE_ACTOR_FOREIGN = 'quotes_converted_by_fk';

    private const QUOTE_STATE_CHECK = 'quotes_conversion_state_chk';

    private const INVOICE_SOURCE_CHECK = 'fin_invoices_quote_source_chk';

    public function up(): void
    {
        $this->assertLegacyRowsCanBeGoverned();

        Schema::create('fin_invoice_sequences', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->primary();
            $table->unsignedBigInteger('next_number');
            $table->timestamps();
        });
        $this->seedInvoiceSequences();

        Schema::table('service_agreements', function (Blueprint $table): void {
            $table->unsignedBigInteger('source_quote_id')->nullable()->after('client_id');
        });
        Schema::table('fin_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('quote_source_id')->nullable()->after('source_id');
        });
        Schema::table('quotes', function (Blueprint $table): void {
            $table->unsignedBigInteger('converted_by')->nullable()->after('converted_to_invoice_id');
            $table->dateTime('converted_at')->nullable()->after('converted_by');
            $table->char('conversion_digest', 64)->nullable()->after('converted_at');
        });

        $this->backfillCanonicalLineage();

        Schema::table('service_agreements', function (Blueprint $table): void {
            $table->unique('source_quote_id', self::AGREEMENT_SOURCE_UNIQUE);
            $table->unique(['id', 'source_quote_id'], self::AGREEMENT_BACKLINK_UNIQUE);
            $table->foreign('source_quote_id', self::AGREEMENT_SOURCE_FOREIGN)
                ->references('id')
                ->on('quotes')
                ->restrictOnDelete();
        });
        Schema::table('fin_invoices', function (Blueprint $table): void {
            $table->unique('quote_source_id', self::INVOICE_SOURCE_UNIQUE);
            $table->unique(['id', 'quote_source_id'], self::INVOICE_BACKLINK_UNIQUE);
            $table->foreign('quote_source_id', self::INVOICE_SOURCE_FOREIGN)
                ->references('id')
                ->on('quotes')
                ->restrictOnDelete();
        });
        Schema::table('quotes', function (Blueprint $table): void {
            $table->unique('converted_to_agreement_id', self::QUOTE_AGREEMENT_UNIQUE);
            $table->foreign(['converted_to_agreement_id', 'id'], self::QUOTE_AGREEMENT_FOREIGN)
                ->references(['id', 'source_quote_id'])
                ->on('service_agreements')
                ->restrictOnDelete();
            $table->unique('converted_to_invoice_id', self::QUOTE_INVOICE_UNIQUE);
            $table->foreign(['converted_to_invoice_id', 'id'], self::QUOTE_INVOICE_FOREIGN)
                ->references(['id', 'quote_source_id'])
                ->on('fin_invoices')
                ->restrictOnDelete();
            $table->foreign('converted_by', self::QUOTE_ACTOR_FOREIGN)
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        $this->dropCheckConstraints();

        Schema::table('quotes', function (Blueprint $table): void {
            $table->dropForeign(self::QUOTE_ACTOR_FOREIGN);
            $table->dropForeign(self::QUOTE_INVOICE_FOREIGN);
            $table->dropUnique(self::QUOTE_INVOICE_UNIQUE);
            $table->dropForeign(self::QUOTE_AGREEMENT_FOREIGN);
            $table->dropUnique(self::QUOTE_AGREEMENT_UNIQUE);
            $table->dropColumn(['converted_by', 'converted_at', 'conversion_digest']);
        });
        Schema::table('fin_invoices', function (Blueprint $table): void {
            $table->dropForeign(self::INVOICE_SOURCE_FOREIGN);
            $table->dropUnique(self::INVOICE_BACKLINK_UNIQUE);
            $table->dropUnique(self::INVOICE_SOURCE_UNIQUE);
            $table->dropColumn('quote_source_id');
        });
        Schema::table('service_agreements', function (Blueprint $table): void {
            $table->dropForeign(self::AGREEMENT_SOURCE_FOREIGN);
            $table->dropUnique(self::AGREEMENT_BACKLINK_UNIQUE);
            $table->dropUnique(self::AGREEMENT_SOURCE_UNIQUE);
            $table->dropColumn('source_quote_id');
        });

        Schema::dropIfExists('fin_invoice_sequences');
    }

    private function assertLegacyRowsCanBeGoverned(): void
    {
        $invalidState = DB::table('quotes')
            ->where(function ($query): void {
                $query->where(function ($converted): void {
                    $converted->where('status', 'converted')
                        ->where(function ($links): void {
                            $links->where(function ($none): void {
                                $none->whereNull('converted_to_agreement_id')
                                    ->whereNull('converted_to_invoice_id');
                            })->orWhere(function ($both): void {
                                $both->whereNotNull('converted_to_agreement_id')
                                    ->whereNotNull('converted_to_invoice_id');
                            });
                        });
                })->orWhere(function ($notConverted): void {
                    $notConverted->where('status', '<>', 'converted')
                        ->where(function ($links): void {
                            $links->whereNotNull('converted_to_agreement_id')
                                ->orWhereNotNull('converted_to_invoice_id');
                        });
                });
            })
            ->exists();
        $this->failIf($invalidState, 'quote rows have a non-exclusive or contradictory conversion state');

        $duplicateAgreement = DB::table('quotes')
            ->whereNotNull('converted_to_agreement_id')
            ->select('converted_to_agreement_id')
            ->groupBy('converted_to_agreement_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $this->failIf($duplicateAgreement, 'multiple quotes link to the same service agreement');

        $duplicateInvoice = DB::table('quotes')
            ->whereNotNull('converted_to_invoice_id')
            ->select('converted_to_invoice_id')
            ->groupBy('converted_to_invoice_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $this->failIf($duplicateInvoice, 'multiple quotes link to the same invoice');

        $invalidAgreementLink = DB::table('quotes as q')
            ->leftJoin('service_agreements as a', 'a.id', '=', 'q.converted_to_agreement_id')
            ->whereNotNull('q.converted_to_agreement_id')
            ->where(function ($query): void {
                $query->whereNull('a.id')
                    ->orWhereNull('q.client_id')
                    ->orWhereColumn('a.client_id', '<>', 'q.client_id')
                    ->orWhereNull('a.created_by')
                    ->orWhereNull('a.created_at');
            })
            ->exists();
        $this->failIf($invalidAgreementLink, 'a linked service agreement lacks matching Client or actor/time provenance');

        $invalidInvoiceLink = DB::table('quotes as q')
            ->leftJoin('fin_invoices as i', 'i.id', '=', 'q.converted_to_invoice_id')
            ->whereNotNull('q.converted_to_invoice_id')
            ->where(function ($query): void {
                $query->whereNull('i.id')
                    ->orWhereNull('q.client_id')
                    ->orWhereNull('i.client_id')
                    ->orWhereColumn('i.client_id', '<>', 'q.client_id')
                    ->orWhereNull('i.source')
                    ->orWhere('i.source', '<>', 'quote')
                    ->orWhereNull('i.source_type')
                    ->orWhere('i.source_type', '<>', Quote::class)
                    ->orWhereNull('i.source_id')
                    ->orWhereColumn('i.source_id', '<>', 'q.id')
                    ->orWhereNull('i.created_by')
                    ->orWhereNull('i.created_at');
            })
            ->exists();
        $this->failIf($invalidInvoiceLink, 'a linked invoice lacks exact quote, Client, or actor/time provenance');

        $mislabelledQuoteSource = DB::table('fin_invoices')
            ->where('source', 'quote')
            ->where(function ($query): void {
                $query->whereNull('source_type')
                    ->orWhere('source_type', '<>', Quote::class);
            })
            ->exists();
        $this->failIf($mislabelledQuoteSource, 'an invoice is labelled as quote-origin without the canonical Quote type');

        $duplicateQuoteSource = DB::table('fin_invoices')
            ->where('source_type', Quote::class)
            ->whereNotNull('source_id')
            ->select('source_id')
            ->groupBy('source_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        $this->failIf($duplicateQuoteSource, 'multiple invoices claim the same quote source');

        $invalidQuoteSource = DB::table('fin_invoices as i')
            ->leftJoin('quotes as q', 'q.id', '=', 'i.source_id')
            ->where('i.source_type', Quote::class)
            ->where(function ($query): void {
                $query->whereNull('i.source_id')
                    ->orWhereNull('i.source')
                    ->orWhere('i.source', '<>', 'quote')
                    ->orWhereNull('q.id')
                    ->orWhereNull('i.client_id')
                    ->orWhereColumn('i.client_id', '<>', 'q.client_id')
                    ->orWhereNull('q.converted_to_invoice_id')
                    ->orWhereColumn('q.converted_to_invoice_id', '<>', 'i.id');
            })
            ->exists();
        $this->failIf($invalidQuoteSource, 'an invoice quote-source tuple is orphaned or disagrees with the quote backlink');

        $this->assertLegacyDestinationsMatchCurrentConversion();
    }

    private function assertLegacyDestinationsMatchCurrentConversion(): void
    {
        DB::table('quotes')
            ->where('status', 'converted')
            ->orderBy('id')
            ->get()
            ->each(function (object $quote): void {
                $quoteLines = DB::table('quote_line_items')
                    ->where('quote_id', $quote->id)
                    ->orderBy('id')
                    ->get();
                try {
                    $projection = QuoteConversionProjection::make($quote, $quoteLines);
                } catch (RuntimeException $exception) {
                    throw new RuntimeException(
                        "Cannot govern quote conversion because legacy quote {$quote->id}'s accepted payload cannot be projected; reconciliation is required. {$exception->getMessage()}",
                        previous: $exception,
                    );
                }

                if ($quote->converted_to_agreement_id !== null) {
                    $agreement = DB::table('service_agreements')
                        ->find($quote->converted_to_agreement_id);
                    $agreementLines = DB::table('service_agreement_line_items')
                        ->where('service_agreement_id', $agreement->id)
                        ->orderBy('id')
                        ->get();

                    $this->failIf(
                        ! QuoteConversionProjection::agreementMatches(
                            $quote,
                            $agreement,
                            $agreementLines,
                            $projection,
                        ),
                        "legacy linked service agreement {$agreement->id} does not match quote {$quote->id}'s current conversion header and ordered lines; reconciliation is required",
                    );

                    return;
                }

                $invoice = DB::table('fin_invoices')->find($quote->converted_to_invoice_id);
                $client = DB::table('clients')->find($quote->client_id);
                $this->failIf(
                    ! $client,
                    "legacy quote {$quote->id} has no Client for invoice conversion reconciliation",
                );
                $invoiceLines = DB::table('fin_invoice_lines')
                    ->where('invoice_id', $invoice->id)
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get();

                $this->failIf(
                    ! QuoteConversionProjection::invoiceMatches(
                        $quote,
                        $client,
                        $invoice,
                        $invoiceLines,
                        $projection,
                        1,
                    ),
                    "legacy linked invoice {$invoice->id} does not match quote {$quote->id}'s current conversion header and ordered lines; reconciliation is required",
                );
            });
    }

    private function seedInvoiceSequences(): void
    {
        $numbers = DB::table('fin_invoices')
            ->orderBy('id')
            ->get(['organization_id', 'invoice_number'])
            ->groupBy('organization_id');
        $timestamp = now();

        foreach ($numbers as $organizationId => $invoices) {
            $maximum = $invoices->reduce(function (int $current, object $invoice): int {
                if (! preg_match('/^INV-(\d+)$/', (string) $invoice->invoice_number, $matches)) {
                    return $current;
                }

                return max($current, (int) $matches[1]);
            }, 0);

            DB::table('fin_invoice_sequences')->insert([
                'organization_id' => (int) $organizationId,
                'next_number' => $maximum + 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    private function backfillCanonicalLineage(): void
    {
        DB::table('quotes')
            ->whereNotNull('converted_to_agreement_id')
            ->orderBy('id')
            ->get(['id', 'converted_to_agreement_id'])
            ->each(function (object $quote): void {
                DB::table('service_agreements')
                    ->where('id', $quote->converted_to_agreement_id)
                    ->update(['source_quote_id' => $quote->id]);
            });

        DB::table('fin_invoices')
            ->where('source_type', Quote::class)
            ->update(['quote_source_id' => DB::raw('source_id')]);

        DB::table('quotes')
            ->where('status', 'converted')
            ->orderBy('id')
            ->get(['id', 'converted_to_agreement_id', 'converted_to_invoice_id'])
            ->each(function (object $quote): void {
                $destination = $quote->converted_to_agreement_id !== null
                    ? DB::table('service_agreements')->find($quote->converted_to_agreement_id)
                    : DB::table('fin_invoices')->find($quote->converted_to_invoice_id);

                DB::table('quotes')->where('id', $quote->id)->update([
                    'converted_by' => $destination->created_by,
                    'converted_at' => $destination->created_at,
                ]);
            });

        DB::table('quotes')
            ->where('status', 'converted')
            ->orderBy('id')
            ->get()
            ->each(function (object $quote): void {
                $lineItems = DB::table('quote_line_items')
                    ->where('quote_id', $quote->id)
                    ->orderBy('id')
                    ->get();

                DB::table('quotes')->where('id', $quote->id)->update([
                    'conversion_digest' => QuoteConversionProjection::make($quote, $lineItems)['digest'],
                ]);
            });
    }

    private function addCheckConstraints(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return;
        }

        $quoteType = DB::connection()->getPdo()->quote(Quote::class);
        DB::statement(
            'ALTER TABLE quotes ADD CONSTRAINT '.self::QUOTE_STATE_CHECK.' CHECK ('.
            "(status = 'converted' AND converted_by IS NOT NULL AND converted_at IS NOT NULL AND conversion_digest IS NOT NULL AND ".
            '((converted_to_agreement_id IS NOT NULL AND converted_to_invoice_id IS NULL) OR '.
            '(converted_to_agreement_id IS NULL AND converted_to_invoice_id IS NOT NULL))) OR '.
            "(status <> 'converted' AND converted_by IS NULL AND converted_at IS NULL AND conversion_digest IS NULL AND ".
            'converted_to_agreement_id IS NULL AND converted_to_invoice_id IS NULL))',
        );
        DB::statement(
            'ALTER TABLE fin_invoices ADD CONSTRAINT '.self::INVOICE_SOURCE_CHECK.' CHECK ('.
            "(quote_source_id IS NULL AND (source IS NULL OR source <> 'quote') ".
            'AND (source_type IS NULL OR source_type <> '.$quoteType.')) OR '.
            "(quote_source_id IS NOT NULL AND source = 'quote' AND source_type = {$quoteType} ".
            'AND source_id = quote_source_id))',
        );
    }

    private function dropCheckConstraints(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE fin_invoices DROP CHECK '.self::INVOICE_SOURCE_CHECK);
            DB::statement('ALTER TABLE quotes DROP CHECK '.self::QUOTE_STATE_CHECK);

            return;
        }
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE fin_invoices DROP CONSTRAINT '.self::INVOICE_SOURCE_CHECK);
            DB::statement('ALTER TABLE quotes DROP CONSTRAINT '.self::QUOTE_STATE_CHECK);
        }
    }

    private function failIf(bool $condition, string $reason): void
    {
        if ($condition) {
            throw new RuntimeException('Cannot govern quote conversion because '.$reason.'.');
        }
    }
};
