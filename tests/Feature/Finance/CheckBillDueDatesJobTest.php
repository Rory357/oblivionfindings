<?php

use App\Domain\Finance\Jobs\CheckBillDueDatesJob;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinVendor;
use App\Domain\Finance\Notifications\BillDueNotification;
use App\Domain\Finance\Notifications\BillOverdueNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

test('bill due date job notifies only unpaid approved bills due soon or overdue', function () {
    $this->travelTo(Carbon::parse('2026-05-01 09:00:00'));
    Notification::fake();

    $user = User::factory()->create(['organization_id' => 1]);
    $vendor = FinVendor::factory()->create(['organization_id' => 1]);

    $dueSoon = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'approved',
        'bill_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(3)->toDateString(),
        'total_amount' => 1000,
        'amount_paid' => 250,
        'created_by' => $user->id,
    ]);

    $overdue = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'partially_paid',
        'bill_date' => now()->subDays(20)->toDateString(),
        'due_date' => now()->subDay()->toDateString(),
        'total_amount' => 800,
        'amount_paid' => 100,
        'created_by' => $user->id,
    ]);

    $paid = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'approved',
        'bill_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(2)->toDateString(),
        'total_amount' => 300,
        'amount_paid' => 300,
        'created_by' => $user->id,
    ]);

    $future = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
        'total_amount' => 500,
        'amount_paid' => 0,
        'created_by' => $user->id,
    ]);

    $draft = FinBill::factory()->create([
        'organization_id' => 1,
        'vendor_id' => $vendor->id,
        'status' => 'draft',
        'bill_date' => now()->subDays(2)->toDateString(),
        'due_date' => now()->addDay()->toDateString(),
        'total_amount' => 200,
        'amount_paid' => 0,
        'created_by' => $user->id,
    ]);

    app(CheckBillDueDatesJob::class)->handle();

    Notification::assertSentTo(
        $user,
        BillDueNotification::class,
        fn (BillDueNotification $notification) => $notification->bill->is($dueSoon),
    );

    Notification::assertSentTo(
        $user,
        BillOverdueNotification::class,
        fn (BillOverdueNotification $notification) => $notification->bill->is($overdue),
    );

    Notification::assertNotSentTo(
        $user,
        BillDueNotification::class,
        fn (BillDueNotification $notification) => $notification->bill->is($paid)
            || $notification->bill->is($future)
            || $notification->bill->is($draft),
    );
});
