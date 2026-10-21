<?php

// Reuses the exact disposable-schema and fictional-provider guards.
require __DIR__.'/seed-staff.php';

use App\Domain\Finance\Models\FinTaxRate;

// Required legacy finance storage column; never an authorization boundary.
$tax = FinTaxRate::firstOrCreate(['code' => 'REF-QA-FICTIONAL'], [
    'organization_id' => 1,
    'name' => 'Fictional QA tax - not an actual tax determination',
    'rate' => '0.1500',
    'type' => 'gst',
    'is_active' => true,
    'is_default' => false,
]);
echo json_encode(['fictional_tax_id' => $tax->id, 'default_changed' => false]).PHP_EOL;
