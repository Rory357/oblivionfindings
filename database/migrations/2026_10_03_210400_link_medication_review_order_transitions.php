<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_review_items', function (Blueprint $table) {
            $table->foreignId('linked_stopped_order_version_id')->nullable()->constrained('medication_order_versions');
            $table->foreignId('linked_replacement_order_version_id')->nullable()->constrained('medication_order_versions');
        });
    }

    public function down(): void
    {
        Schema::table('medication_review_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_replacement_order_version_id');
            $table->dropConstrainedForeignId('linked_stopped_order_version_id');
        });
    }
};
