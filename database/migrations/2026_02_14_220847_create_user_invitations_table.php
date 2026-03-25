<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table) {
            $table->id();
            
            // Who was invited
            $table->string('email')->index();
            $table->string('name');
            
            // What type of user
            $table->enum('user_type', ['staff', 'client', 'next_of_kin', 'board_member'])
                ->default('staff');
            
            // Optional: link to entity record if pre-created
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('next_of_kin_id')->nullable()->constrained('next_of_kins')->nullOnDelete();
            
            // Invitation details
            $table->string('token', 64)->unique();
            $table->enum('status', ['pending', 'accepted', 'expired', 'cancelled'])
                ->default('pending');
            
            // Roles to assign on acceptance
            $table->json('role_ids')->nullable()->comment('Array of role IDs to assign');
            
            // Who invited them
            $table->foreignId('invited_by')
                ->constrained('users')
                ->onDelete('cascade');
            
            // Timestamps
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            // Indexes
            $table->index('status');
            $table->index('user_type');
            $table->index(['email', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
