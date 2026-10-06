<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_review_items', function (Blueprint $table) {
            $table->foreignId('linked_stopped_order_version_id')->nullable()->constrained('medication_order_versions', indexName: 'mri_stopped_version_fk');
            $table->foreignId('linked_replacement_order_version_id')->nullable()->constrained('medication_order_versions', indexName: 'mri_replacement_version_fk');
        });
    }

    public function down(): void
    {
        Schema::table('medication_review_items', function (Blueprint $table) {
            $table->dropForeign('mri_replacement_version_fk');
            $table->dropForeign('mri_stopped_version_fk');
            $table->dropColumn(['linked_replacement_order_version_id', 'linked_stopped_order_version_id']);
        });
    }
};
