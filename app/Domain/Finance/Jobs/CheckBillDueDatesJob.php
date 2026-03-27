<?php

namespace App\Domain\Finance\Jobs;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Notifications\BillDueNotification;
use App\Domain\Finance\Notifications\BillOverdueNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckBillDueDatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $today = now()->startOfDay();
        $sevenDaysFromNow = now()->addDays(7)->endOfDay();

        // Bills due within the next 7 days (not yet overdue)
        $dueSoon = FinBill::whereIn('status', ['approved', 'partially_paid'])
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->whereBetween('due_date', [$today, $sevenDaysFromNow])
            ->with('vendor:id,name', 'createdBy')
            ->get();

        foreach ($dueSoon as $bill) {
            $notifiable = $this->getNotifiable($bill);
            if ($notifiable) {
                $notifiable->notify(new BillDueNotification($bill));
            }
        }

        Log::info("CheckBillDueDatesJob: {$dueSoon->count()} bills due within 7 days.");

        // Overdue bills
        $overdue = FinBill::whereIn('status', ['approved', 'partially_paid'])
            ->whereColumn('amount_paid', '<', 'total_amount')
            ->where('due_date', '<', $today)
            ->with('vendor:id,name', 'createdBy')
            ->get();

        foreach ($overdue as $bill) {
            $notifiable = $this->getNotifiable($bill);
            if ($notifiable) {
                $notifiable->notify(new BillOverdueNotification($bill));
            }
        }

        Log::info("CheckBillDueDatesJob: {$overdue->count()} overdue bills.");
    }

    /**
     * Get the user to notify for a bill.
     */
    private function getNotifiable(FinBill $bill): ?User
    {
        if ($bill->created_by) {
            return User::find($bill->created_by);
        }

        // Fallback: find an admin user in the organisation
        return User::where('organization_id', $bill->organization_id)
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->first();
    }
}
