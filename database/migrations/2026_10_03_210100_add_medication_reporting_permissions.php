<?php

use App\Services\Medication\Reporting\MedicationReportingPermissions;
use Illuminate\Database\Migrations\Migration;

// Approved P09 v1.1 README Q5/build note 5; approval commit 806334f96.
return new class extends Migration
{
    public function up(): void
    {
        MedicationReportingPermissions::install();
    }

    public function down(): void {}
};
