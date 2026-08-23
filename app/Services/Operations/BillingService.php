<?php

namespace App\Services\Operations;

use App\Domain\Finance\Models\FinInvoice;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementLineItem;
use App\Models\ServiceAgreementRate;
use App\Models\Site;
use App\Models\Timesheet;
use App\Services\ShiftOperationalSnapshotService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BillingService
{
    private const APPLICATION_STORAGE_CONTEXT_ID = 1;

    public function __construct(
        protected PayrollRateResolver $rateResolver,
        protected ShiftOperationalSnapshotService $snapshots,
    ) {}

    /**
     * Generate BillingEntry rows from an approved timesheet — one entry per
     * `timesheet_client_allocations` row. Legacy single-client timesheets
     * fall through `effectiveClientAllocations()` which synthesises a single
     * row, so they continue to produce exactly one entry.
     *
     * Idempotency: existing pending entries for the timesheet are deleted
     * before re-creating, so this method can be replayed after the worker
     * edits the allocation breakdown. Once entries are invoiced/paid the
     * caller must not re-run generation — we leave those rows alone.
     */
    public function generateFromTimesheet(Timesheet $timesheet): Collection
    {
        if ($timesheet->status !== 'approved') {
            return new Collection;
        }

        if (! $timesheet->is_snapshot_complete) {
            throw new \RuntimeException("Timesheet {$timesheet->id} is missing required snapshot data and cannot be billed safely.");
        }

        $allocations = $timesheet->effectiveClientAllocations();

        if ($allocations->isEmpty()) {
            return new Collection;
        }

        $rateType = $this->determineRateType($timesheet);
        $payroll = $this->rateResolver->resolve($timesheet);
        $baseSnapshot = $this->snapshots->billingSnapshotForTimesheet(
            $timesheet,
            $payroll['pay_rate'],
            $payroll['payroll_cost']
        );

        return DB::transaction(function () use ($timesheet, $allocations, $rateType, $baseSnapshot) {
            $existing = BillingEntry::where('timesheet_id', $timesheet->id)
                ->with('fundingClaimItem:id,billing_entry_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $invoicedClientIds = $existing
                ->filter(fn (BillingEntry $entry): bool => $entry->status !== 'pending' || $entry->fundingClaimItem !== null)
                ->pluck('client_id')
                ->all();

            // Tear down only pending entries — anything invoiced/paid is
            // financially locked and must not be replaced.
            BillingEntry::where('timesheet_id', $timesheet->id)
                ->where('status', 'pending')
                ->whereDoesntHave('fundingClaimItem')
                ->delete();

            $created = new Collection;
            foreach ($allocations as $allocation) {
                $clientId = (int) $allocation['client_id'];
                if (! $clientId || in_array($clientId, $invoicedClientIds, true)) {
                    continue;
                }

                $client = Client::query()
                    ->whereKey($clientId)
                    ->whereNotNull('site_id')
                    ->whereHas('site', fn ($siteQuery) => $siteQuery
                        ->active()
                        ->notArchived()
                        ->whereNull('archived_at'))
                    ->first();
                if (! $client) {
                    continue;
                }

                $hours = bcadd((string) $allocation['hours'], '0', 2);
                if (bccomp($hours, '0', 2) <= 0) {
                    continue;
                }

                $agreement = $this->findActiveAgreement($client, $timesheet->work_date);
                $lineItem = $agreement ? $this->matchLineItem($agreement, $rateType) : null;
                $rate = $this->resolveRate($agreement, $rateType, $timesheet->work_date, $lineItem);

                $entry = BillingEntry::create([
                    'timesheet_id' => $timesheet->id,
                    'shift_id' => $timesheet->shift_id,
                    'client_id' => $client->id,
                    'staff_id' => $timesheet->user_id,
                    'service_agreement_id' => $agreement?->id,
                    'line_item_id' => $lineItem?->id,
                    'service_date' => $timesheet->work_date,
                    'hours' => $hours,
                    'rate' => $rate,
                    'amount' => bcmul($hours, $rate, 2),
                    'rate_type' => $rateType,
                    ...$baseSnapshot,
                    'site_id' => $client->site_id,
                    'client_name_snapshot' => $this->clientName($client) ?: $baseSnapshot['client_name_snapshot'] ?? null,
                    'status' => 'pending',
                    'notes' => $allocation['notes'] ?: $timesheet->notes,
                ]);

                $created->push($entry);
            }

            return $created;
        });
    }

    protected function findActiveAgreement(Client $client, $workDate): ?ServiceAgreement
    {
        $agreements = ServiceAgreement::where('client_id', $client->id)
            ->where('status', 'active')
            ->where(function ($query) use ($workDate): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $workDate);
            })
            ->where(function ($q) use ($workDate) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $workDate);
            })
            ->orderBy('id')
            ->get();

        if ($agreements->count() > 1) {
            throw new \RuntimeException(
                "Client {$client->id} has multiple active Service Agreements for the delivery date."
            );
        }

        return $agreements->first();
    }

    protected function clientName(?Client $client): ?string
    {
        if (! $client) {
            return null;
        }

        $name = trim(($client->first_name ?? '').' '.($client->last_name ?? ''));

        return $name !== '' ? $name : ($client->full_name ?? null);
    }

    public function generateInvoice(array $billingEntryIds, int $createdBy): FinInvoice
    {
        $entryIds = collect($billingEntryIds)
            ->map(fn ($entryId) => (int) $entryId)
            ->filter(fn (int $entryId) => $entryId > 0)
            ->unique()
            ->values();

        return DB::transaction(function () use ($entryIds, $createdBy) {
            $entries = $this->lockInvoiceDeliveries($entryIds->all());

            $firstEntry = $entries->first();
            $client = $firstEntry->client;
            $subtotal = $entries->reduce(
                fn (string $sum, BillingEntry $entry): string => bcadd($sum, (string) $entry->amount, 2),
                '0.00',
            );
            $taxAmount = bcmul($subtotal, '0.15', 2); // NZ GST
            $totalAmount = bcadd($subtotal, $taxAmount, 2);

            $invoice = FinInvoice::create([
                'organization_id' => self::APPLICATION_STORAGE_CONTEXT_ID,
                'client_id' => $firstEntry->client_id,
                'funding_body' => $firstEntry->serviceAgreement?->funding_body,
                'invoice_number' => $this->generateInvoiceNumber(),
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(20)->toDateString(),
                'client_name' => $client?->full_name ?? $firstEntry->client_name_snapshot ?? 'Client',
                'client_email' => $client?->email,
                'client_address' => $this->formatClientAddress($client),
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'currency_code' => 'NZD',
                'status' => 'draft',
                'terms' => 'Due within 20 days of the 20th of the month',
                'source' => 'operations',
                'source_type' => BillingEntry::class,
                'source_id' => $firstEntry->id,
                'created_by' => $createdBy,
            ]);

            foreach ($entries as $index => $entry) {
                $lineSubtotal = bcadd((string) $entry->amount, '0', 2);
                $lineTax = bcmul($lineSubtotal, '0.15', 2);

                $invoice->lines()->create([
                    'billing_entry_id' => $entry->id,
                    'description' => sprintf(
                        '%s - %s (%s hrs @ $%s)',
                        $entry->service_date->format('d M Y'),
                        $entry->rate_type,
                        $entry->hours,
                        number_format((float) $entry->rate, 2)
                    ),
                    'quantity' => $entry->hours,
                    'unit_price' => $entry->rate,
                    'tax_amount' => $lineTax,
                    'line_total' => bcadd($lineSubtotal, $lineTax, 2),
                    'sort_order' => $index,
                    'service_date' => $entry->service_date,
                    'category' => $entry->rate_type,
                ]);

                $entry->update(['status' => 'invoiced']);
            }

            return $invoice;
        });
    }

    /**
     * Lock the canonical delivery hierarchy before reserving Billing Entries
     * for an invoice. Funding Claims use the same Agreement -> Client -> Site
     * -> Billing Entry order, so competing monetisation commands serialize on
     * the same durable rows without a reverse lock cycle.
     *
     * @param  array<int, int|string>  $billingEntryIds
     * @return Collection<int, BillingEntry>
     */
    public function lockInvoiceDeliveries(array $billingEntryIds, ?int $expectedClientId = null): Collection
    {
        $entryIds = collect($billingEntryIds)
            ->map(fn ($entryId): int => (int) $entryId)
            ->filter(fn (int $entryId): bool => $entryId > 0)
            ->unique()
            ->sort()
            ->values();
        abort_if($entryIds->isEmpty(), 422, 'No complete billable billing-entry selection was found.');

        // This non-locking read is only a locator for the canonical lock set.
        // Every value is re-read and compared after the ordered locks are held.
        $candidates = BillingEntry::query()
            ->whereIn('id', $entryIds)
            ->orderBy('id')
            ->get(['id', 'client_id', 'site_id', 'service_agreement_id']);
        abort_if(
            $candidates->count() !== $entryIds->count(),
            422,
            'No complete billable billing-entry selection was found.',
        );

        $clientIds = $candidates->pluck('client_id')->map(fn ($id): int => (int) $id)->unique()->values();
        abort_unless(
            $clientIds->count() === 1,
            422,
            'Billing entries for different clients cannot share one invoice.',
        );
        $clientId = (int) $clientIds->first();
        abort_if(
            $expectedClientId !== null && $clientId !== $expectedClientId,
            422,
            'Every billing entry must belong to the selected Client.',
        );

        $agreementIds = $candidates->pluck('service_agreement_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $agreements = ServiceAgreement::query()
            ->whereIn('id', $agreementIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        abort_unless(
            $agreements->count() === $agreementIds->count()
                && $agreements->every(fn (ServiceAgreement $agreement): bool => (int) $agreement->client_id === $clientId),
            422,
            'A billing entry no longer belongs to its canonical Service Agreement.',
        );

        $client = Client::query()->whereKey($clientId)->lockForUpdate()->first();
        abort_unless($client && (int) $client->site_id > 0, 422, 'The invoice Client has no active Site.');
        $site = Site::query()
            ->whereKey($client->site_id)
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first();
        abort_unless($site, 422, 'The invoice Client has no active Site.');

        $entries = BillingEntry::query()
            ->whereIn('id', $entryIds)
            ->where('client_id', $clientId)
            ->where('site_id', $site->id)
            ->whereIn('status', ['pending', 'approved'])
            ->whereDoesntHave('fundingClaimItem')
            ->whereNotExists(fn ($invoiceLines) => $invoiceLines
                ->selectRaw('1')
                ->from('fin_invoice_lines')
                ->whereColumn('fin_invoice_lines.billing_entry_id', 'billing_entries.id'))
            ->where(function ($query): void {
                $query->whereNull('service_agreement_id')
                    ->orWhereHas('serviceAgreement', fn ($agreementQuery) => $agreementQuery
                        ->whereColumn('service_agreements.client_id', 'billing_entries.client_id'));
            })
            ->with(['client', 'serviceAgreement'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        abort_if(
            $entries->count() !== $entryIds->count(),
            422,
            'No complete billable billing-entry selection was found.',
        );

        $lockedAgreementIds = $entries->pluck('service_agreement_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        abort_unless(
            $lockedAgreementIds->all() === $agreementIds->all(),
            409,
            'A billing entry changed Service Agreement while the invoice was being created.',
        );

        return $entries;
    }

    public function updateAgreementUsage(ServiceAgreement $agreement): void
    {
        $totalUsed = BillingEntry::where('service_agreement_id', $agreement->id)
            ->whereIn('status', ['pending', 'claimed', 'invoiced', 'paid'])
            ->sum('amount');

        $hoursUsed = BillingEntry::where('service_agreement_id', $agreement->id)
            ->whereIn('status', ['pending', 'claimed', 'invoiced', 'paid'])
            ->sum('hours');

        $agreement->update([
            'budget_used' => $totalUsed,
            'hours_used' => $hoursUsed,
        ]);

        // Update line item usage
        $lineItemUsage = BillingEntry::where('service_agreement_id', $agreement->id)
            ->whereIn('status', ['pending', 'claimed', 'invoiced', 'paid'])
            ->whereNotNull('line_item_id')
            ->selectRaw('line_item_id, SUM(amount) as total_used')
            ->groupBy('line_item_id')
            ->pluck('total_used', 'line_item_id');

        foreach ($lineItemUsage as $lineItemId => $used) {
            ServiceAgreementLineItem::where('id', $lineItemId)->update(['budget_used' => $used]);
        }
    }

    protected function determineRateType(Timesheet $timesheet): string
    {
        if ($timesheet->sleepover) {
            return 'sleepover';
        }
        if ($timesheet->on_call) {
            return 'on_call';
        }
        if ($timesheet->public_holiday) {
            return 'public_holiday';
        }

        $dayOfWeek = Carbon::parse($timesheet->work_date)->dayOfWeek;
        if (in_array($dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) {
            return 'weekend';
        }

        if ($timesheet->starts_at) {
            $hour = Carbon::parse($timesheet->starts_at)->hour;
            if ($hour >= 20 || $hour < 6) {
                return 'active_night';
            }
            if ($hour >= 18) {
                return 'evening';
            }
        }

        return 'weekday';
    }

    protected function resolveRate(
        ?ServiceAgreement $agreement,
        string $rateType,
        mixed $serviceDate,
        ?ServiceAgreementLineItem $lineItem,
    ): string {
        if (! $agreement) {
            return '0.00';
        }

        $rateSchedule = ServiceAgreementRate::where('service_agreement_id', $agreement->id)
            ->where('rate_type', $rateType);
        $hasExplicitSchedule = (clone $rateSchedule)->exists();
        $rate = $rateSchedule
            ->where(function ($q) use ($serviceDate) {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $serviceDate);
            })
            ->where(function ($q) use ($serviceDate) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $serviceDate);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('rate');

        if ($hasExplicitSchedule && $rate === null) {
            throw new \RuntimeException(
                "Service Agreement {$agreement->id} has no {$rateType} rate effective on the delivery date."
            );
        }

        $canonical = $rate ?? $lineItem?->unit_price ?? $agreement->hourly_rate ?? '0';

        return bcadd((string) $canonical, '0', 2);
    }

    protected function matchLineItem(ServiceAgreement $agreement, string $rateType): ?ServiceAgreementLineItem
    {
        $candidates = ServiceAgreementLineItem::where('service_agreement_id', $agreement->id)
            ->where(function ($q) use ($rateType) {
                $q->where('category', 'like', "%{$rateType}%")
                    ->orWhereNull('category');
            })
            ->orderByRaw('CASE WHEN category LIKE ? THEN 0 ELSE 1 END', ["%{$rateType}%"])
            ->orderBy('id')
            ->get();
        $exact = $candidates->filter(fn (ServiceAgreementLineItem $line): bool => $line->category !== null && str_contains((string) $line->category, $rateType)
        );
        $eligible = $exact->isNotEmpty()
            ? $exact
            : $candidates->filter(fn (ServiceAgreementLineItem $line): bool => $line->category === null);
        if ($eligible->count() > 1) {
            throw new \RuntimeException(
                "Service Agreement {$agreement->id} has multiple {$rateType} delivery lines."
            );
        }

        return $eligible->first();
    }

    protected function generateInvoiceNumber(): string
    {
        return FinInvoice::nextNumber(
            self::APPLICATION_STORAGE_CONTEXT_ID,
            minimum: 1001,
            padding: 6,
        );
    }

    protected function formatClientAddress($client): ?string
    {
        if (! $client) {
            return null;
        }

        return collect([
            $client->address_line_1,
            $client->address_line_2,
            $client->suburb,
            $client->city,
            $client->postcode,
        ])->filter()->implode(', ') ?: null;
    }
}
