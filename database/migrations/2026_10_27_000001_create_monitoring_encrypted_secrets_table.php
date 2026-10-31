<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monitoring_encrypted_secrets')) {
            return;
        }

        Schema::create('monitoring_encrypted_secrets', function (Blueprint $table): void {
            $table->id();
            $table->char('reference_hash', 64)->index();
            $table->text('opaque_reference_encrypted');
            $table->unsignedInteger('version');
            $table->longText('encrypted_payload')->nullable();
            $table->timestamp('soft_deleted_at')->nullable();
            $table->timestamp('destroyed_at')->nullable();
            $table->timestamps();

            $table->unique(['reference_hash', 'version'], 'monitoring_encrypted_secrets_ref_ver_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_encrypted_secrets');
    }
};
