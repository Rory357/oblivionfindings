<?php

namespace App\Services\Operations;

use App\Models\BillingEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementLineItem;
use App\Models\ServiceAgreementRate;
use App\Models\Timesheet;
use Carbon\Carbon;

class BillingService
{
    public function __construct(
        protected PayrollRateResolver $rateResolver,
        protected \App\Services\ShiftOperationalSnapshotService $snapshots,
    ) {
    }

    public function generateFromTimesheet(Timesheet $timesheet): ?BillingEntry
    {
        if ($timesheet->status !== 'approved') {
            return null;
        }

        if (! $timesheet->is_snapshot_complete) {
            throw new \RuntimeException("Timesheet {$timesheet->id} is missing required snapshot data and cannot be billed safely.");
        }

        // Check if already billed
        if (BillingEntry::where('timesheet_id', $timesheet->id)->exists()) {
            return null;
        }

        $shift = $timesheet->shift;
        $client = $timesheet->client;

        if (!$client) {
            return null;
        }

        // Find active service agreement for this client
        $agreement = ServiceAgreement::where('client_id', $client->id)
            ->where('status', 'active')
            ->where('starts_at', '<=', $timesheet->work_date)
            ->where(function ($q) use ($timesheet) {
                $q->whereNull('ends_at')
                  ->orWhere('ends_at', '>=', $timesheet->work_date);
            })
            ->first();

        $hours = $this->calculateHours($timesheet);
        $rateType = $this->determineRateType($timesheet);
        $rate = $this->resolveRate($agreement, $rateType);
        $payroll = $this->rateResolver->resolve($timesheet);

        return BillingEntry::create([
            'organization_id' => $client->organization_id,
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
            ...$this->snapshots->billingSnapshotForTimesheet($timesheet, $payroll['pay_rate'], $payroll['payroll_cost']),
            'status' => 'pending',
            'notes' => $timesheet->notes,
        ]);
    }

    public function generateInvoice(array $billingEntryIds, int $organizationId, int $createdBy): Invoice
    {
        $entries = BillingEntry::whereIn('id', $billingEntryIds)
            ->where('organization_id', $organizationId)
            ->where('status', 'pending')
            ->get();

        abort_if($entries->isEmpty(), 422, 'No pending billing entries found.');

        $clientId = $entries->first()->client_id;
        $subtotal = $entries->sum('amount');
        $taxRate = 0.15; // NZ GST
        $taxAmount = round($subtotal * $taxRate, 2);

        $invoice = Invoice::create([
            'organization_id' => $organizationId,
            'client_id' => $clientId,
            'funding_body' => $entries->first()->serviceAgreement?->funding_body,
            'invoice_number' => $this->generateInvoiceNumber($organizationId),
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(20)->toDateString(),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $subtotal + $taxAmount,
            'payment_terms' => 'Due within 20 days of the 20th of the month',
            'created_by' => $createdBy,
        ]);

        foreach ($entries as $entry) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => sprintf(
                    '%s — %s (%s hrs @ $%s)',
                    $entry->service_date->format('d M Y'),
                    $entry->rate_type,
                    $entry->hours,
                    number_format($entry->rate, 2)
                ),
                'quantity' => $entry->hours,
                'unit_price' => $entry->rate,
                'amount' => $entry->amount,
                'tax_rate' => $taxRate,
            ]);

            $entry->update(['status' => 'invoiced']);
        }

        return $invoice;
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

    protected function calculateHours(Timesheet $timesheet): float
    {
        if (!$timesheet->starts_at || !$timesheet->ends_at) {
            return 0;
        }

        $minutes = Carbon::parse($timesheet->starts_at)->diffInMinutes(Carbon::parse($timesheet->ends_at));
        $breakMinutes = $timesheet->break_minutes ?? 0;

        return round(($minutes - $breakMinutes) / 60, 2);
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
        if (!$agreement) {
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
            ->orderByRaw("CASE WHEN category LIKE ? THEN 0 ELSE 1 END", ["%{$rateType}%"])
            ->first();
    }

    protected function generateInvoiceNumber(int $organizationId): string
    {
        $lastInvoice = Invoice::where('organization_id', $organizationId)
            ->orderByDesc('id')
            ->first();

        $nextNumber = $lastInvoice
            ? ((int) preg_replace('/[^0-9]/', '', $lastInvoice->invoice_number)) + 1
            : 1001;

        return 'INV-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }
}
