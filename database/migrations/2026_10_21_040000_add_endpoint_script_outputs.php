<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_script_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('endpoint_delivery_id')->unique()->constrained('endpoint_deliveries');
            $table->text('receipt_json');
            $table->char('receipt_hash', 64);
            $table->longText('encrypted_content')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_script_outputs');
    }
};
