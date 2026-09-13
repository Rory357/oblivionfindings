<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_setup_command_receipts', function (Blueprint $table): void {
            $table->foreignId('it_catalog_item_id')->nullable()->constrained('it_catalog_items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('it_setup_command_receipts')->where('resource', 'catalogue-items')->exists()) {
            throw new RuntimeException('Catalogue command history must be retained; review its dependencies before rollback.');
        }
        Schema::table('it_setup_command_receipts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('it_catalog_item_id');
        });
    }
};
