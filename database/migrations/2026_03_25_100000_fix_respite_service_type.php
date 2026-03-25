<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_contexts')
            ->where('type', 'respite')
            ->update(['type' => 'planned_respite']);
    }

    public function down(): void
    {
        DB::table('service_contexts')
            ->where('type', 'planned_respite')
            ->update(['type' => 'respite']);
    }
};
