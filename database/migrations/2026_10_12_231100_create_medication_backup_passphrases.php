<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EA-141 (Stephan, 10 Oct): each backup recipient's personal passphrase.
 * Only what's needed to encrypt is kept: an encrypted one-page template whose
 * user password is the passphrase, and the template's random owner password
 * (both encrypted at rest). The passphrase itself is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medication_backup_passphrases')) {
            return;
        }
        Schema::create('medication_backup_passphrases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->longText('template');
            $table->text('owner_secret');
            $table->timestamp('set_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_backup_passphrases');
    }
};
