<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_key_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('assets');
            $table->foreignId('user_id')->constrained('users');
            $table->string('action'); // checked_out, returned, transferred
            $table->foreignId('transferred_to_user_id')->nullable()->constrained('users');
            $table->string('key_number')->nullable();
            $table->string('location')->nullable(); // key safe, office, with_driver
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_key_logs');
    }
};
