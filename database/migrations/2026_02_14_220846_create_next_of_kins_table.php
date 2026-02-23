<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('next_of_kins', function (Blueprint $table) {
            $table->id();
            
            // Link to auth user
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->onDelete('cascade');
            
            // Link to client
            $table->foreignId('client_id')
                ->constrained('clients')
                ->onDelete('cascade');
            
            // Relationship details
            $table->string('relationship')->comment('e.g., parent, sibling, spouse, child');
            $table->boolean('is_primary_contact')->default(false);
            $table->boolean('is_emergency_contact')->default(true);
            
            // Additional contact info
            $table->string('phone')->nullable();
            $table->string('alternate_phone')->nullable();
            $table->string('address')->nullable();
            
            // Portal access settings
            $table->boolean('can_view_medical')->default(false);
            $table->boolean('can_view_medications')->default(false);
            $table->boolean('can_view_incidents')->default(false);
            $table->boolean('can_receive_updates')->default(true);
            
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('client_id');
            $table->index('relationship');
            $table->index(['client_id', 'is_primary_contact']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('next_of_kins');
    }
};
