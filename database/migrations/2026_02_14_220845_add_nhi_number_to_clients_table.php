<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('nhi_number', 10)
                ->nullable()
                ->unique()
                ->after('user_id')
                ->comment('New Zealand National Health Index number (3 letters + 4 numbers)');
            
            $table->index('nhi_number');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['nhi_number']);
            $table->dropColumn('nhi_number');
        });
    }
};
