<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_support_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_referral_id')->constrained('care_referrals')->restrictOnDelete();
            $table->uuid('source_contact_id');
            $table->unsignedInteger('version');
            $table->longText('release');
            $table->timestamps();
            $table->unique(['source_referral_id', 'source_contact_id'], 'client_support_contacts_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_support_contacts');
    }
};
