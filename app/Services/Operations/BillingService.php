<?php

namespace App\Services\Operations;

use App\Models\BillingEntry;
use App\Models\Timesheet;

class BillingService
{
    public function generateFromTimesheet(Timesheet $timesheet): ?BillingEntry
    {
        // Auto-generate billing entry from approved timesheet
        // Link to service agreement line items
        // Calculate rates based on agreement, time of day, day of week
        return null;
    }

    public function generateInvoice(array $billingEntryIds): void
    {
        // Group billing entries and create invoice with items
    }
}
