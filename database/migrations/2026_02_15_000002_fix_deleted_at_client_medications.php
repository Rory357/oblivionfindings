<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add soft deletes if not exists
        if (!Schema::hasColumn('client_medications', 'deleted_at')) {
            Schema::table('client_medications', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('client_medications', 'deleted_at')) {
            Schema::table('client_medications', function (Blueprint $table) {
                $table->dropColumn('deleted_at');
            });
        }
    }
};
