<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medication_stocks', function (Blueprint $table) {
            $table->date('expiry_date')->nullable()->after('notes');
            $table->string('batch_number', 100)->nullable()->after('expiry_date');
            $table->integer('reorder_quantity')->nullable()->after('reorder_level');
            $table->timestamp('last_reorder_alert_at')->nullable()->after('reorder_quantity');
            $table->string('supplier_name', 255)->nullable()->after('last_reorder_alert_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_medication_stocks', function (Blueprint $table) {
            $table->dropColumn([
                'expiry_date',
                'batch_number',
                'reorder_quantity',
                'last_reorder_alert_at',
                'supplier_name',
            ]);
        });
    }
};
