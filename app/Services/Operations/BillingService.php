<?php

namespace App\Services\Operations;

use App\Domain\Finance\Models\FinInvoice;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementLineItem;
use App\Models\ServiceAgreementRate;
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
            $existing = BillingEntry::where('timesheet_id', $timesheet->id)->get();
            $invoicedClientIds = $existing
                ->whereNotIn('status', ['pending'])
                ->pluck('client_id')
                ->all();

            // Tear down only pending entries — anything invoiced/paid is
            // financially locked and must not be replaced.
            BillingEntry::where('timesheet_id', $timesheet->id)
                ->where('status', 'pending')
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

                $hours = round((float) $allocation['hours'], 2);
                if ($hours <= 0) {
                    continue;
                }

                $agreement = $this->findActiveAgreement($client, $timesheet->work_date);
                $rate = $this->resolveRate($agreement, $rateType);

                $entry = BillingEntry::create([
                    'timesheet_id' => $timesheet->id,
                    'shift_id' => $timesheet->shift_id,
                    'client_id' => $client->id,
                    'staff_id' => $timesheet->user_id,
                    'service_agreement_id' => $agreement?->id,
                    'line_item_id' => $agreement ? $this->matchLineItem($agreement, $rateType)?->id : null,
                    'service_date' => $timesheet->work_date,
                    'hours' => $hours,
                    'rate' => $rate,
                    'amount' => round($hours * $rate, 2),
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
        return ServiceAgreement::where('client_id', $client->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', $workDate)
            ->where(function ($q) use ($workDate) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $workDate);
            })
            ->first();
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

        $entries = BillingEntry::whereIn('id', $entryIds)
            ->whereIn('status', ['pending', 'approved'])
            ->whereHas('client', fn ($clientQuery) => $clientQuery
                ->whereNotNull('site_id')
                ->whereHas('site', fn ($siteQuery) => $siteQuery
                    ->active()
                    ->notArchived()
                    ->whereNull('archived_at')))
            ->where(function ($query): void {
                $query->whereNull('service_agreement_id')
                    ->orWhereHas('serviceAgreement', fn ($agreementQuery) => $agreementQuery
                        ->whereColumn('service_agreements.client_id', 'billing_entries.client_id'));
            })
            ->with(['client', 'serviceAgreement'])
            ->get();

        abort_if(
            $entries->isEmpty() || $entries->count() !== $entryIds->count(),
            422,
            'No complete billable billing-entry selection was found.',
        );
        abort_unless(
            $entries->pluck('client_id')->unique()->count() === 1,
            422,
            'Billing entries for different clients cannot share one invoice.',
        );

        return DB::transaction(function () use ($entries, $createdBy) {
            $firstEntry = $entries->first();
            $client = $firstEntry->client;
            $subtotal = $entries->sum('amount');
            $taxRate = 0.15; // NZ GST
            $taxAmount = round($subtotal * $taxRate, 2);

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
                'total_amount' => $subtotal + $taxAmount,
                'currency_code' => 'NZD',
                'status' => 'draft',
                'terms' => 'Due within 20 days of the 20th of the month',
                'source' => 'operations',
                'source_type' => BillingEntry::class,
                'source_id' => $firstEntry->id,
                'created_by' => $createdBy,
            ]);

            foreach ($entries as $index => $entry) {
                $lineSubtotal = (float) $entry->amount;
                $lineTax = round($lineSubtotal * $taxRate, 2);

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
                    'line_total' => $lineSubtotal + $lineTax,
                    'sort_order' => $index,
                    'service_date' => $entry->service_date,
                    'category' => $entry->rate_type,
                ]);

                $entry->update(['status' => 'invoiced']);
            }

            return $invoice;
        });
    }

    public function updateAgreementUsage(ServiceAgreement $agreement): void
    {
        $totalUsed = BillingEntry::where('service_agreement_id', $agreement->id)
            ->whereIn('status', ['pending', 'invoiced', 'paid'])
            ->sum('amount');

        $hoursUsed = BillingEntry::where('service_agreement_id', $agreement->id)
            ->whereIn('status', ['pending', 'invoiced', 'paid'])
            ->sum('hours');

        $agreement->update([
            'budget_used' => $totalUsed,
            'hours_used' => $hoursUsed,
        ]);

        // Update line item usage
        $lineItemUsage = BillingEntry::where('service_agreement_id', $agreement->id)
            ->whereIn('status', ['pending', 'invoiced', 'paid'])
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

    protected function resolveRate(?ServiceAgreement $agreement, string $rateType): float
    {
        if (! $agreement) {
            return $agreement?->hourly_rate ?? 0;
        }

        $rate = ServiceAgreementRate::where('service_agreement_id', $agreement->id)
            ->where('rate_type', $rateType)
            ->where(function ($q) {
                $q->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->first();

        if ($rate) {
            return (float) $rate->rate;
        }

        return (float) ($agreement->hourly_rate ?? 0);
    }

    protected function matchLineItem(ServiceAgreement $agreement, string $rateType): ?ServiceAgreementLineItem
    {
        return ServiceAgreementLineItem::where('service_agreement_id', $agreement->id)
            ->where(function ($q) use ($rateType) {
                $q->where('category', 'like', "%{$rateType}%")
                    ->orWhereNull('category');
            })
            ->orderByRaw('CASE WHEN category LIKE ? THEN 0 ELSE 1 END', ["%{$rateType}%"])
            ->first();
    }

    protected function generateInvoiceNumber(): string
    {
        $lastInvoice = FinInvoice::withTrashed()
            ->where('organization_id', self::APPLICATION_STORAGE_CONTEXT_ID)
            ->orderByDesc('id')
            ->first();

        $nextNumber = $lastInvoice
            ? ((int) preg_replace('/[^0-9]/', '', $lastInvoice->invoice_number)) + 1
            : 1001;

        return 'INV-'.str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
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
