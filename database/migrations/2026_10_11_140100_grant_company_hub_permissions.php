<?php

use Database\Seeders\CompanyHubPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        (new CompanyHubPermissionsSeeder)->run();
    }

    public function down(): void
    {
        // Retain permission assignments: an administrator may have customised them.
    }
};
