<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_personal_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');                        // e.g. "Wheelchair", "PlayStation 5"
            $table->string('category')->nullable();        // e.g. mobility_aid, electronics, furniture, clothing, other
            $table->text('description')->nullable();       // free-text details
            $table->string('serial_number')->nullable();   // serial / model number if applicable
            $table->decimal('estimated_value', 10, 2)->nullable(); // NZD
            $table->string('condition')->nullable();       // good, fair, poor, new
            $table->string('location')->nullable();        // where the item is kept
            $table->string('photo_path')->nullable();      // optional photo
            $table->date('acquired_at')->nullable();       // when the item was obtained
            $table->text('notes')->nullable();             // any extra info
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_personal_assets');
    }
};
