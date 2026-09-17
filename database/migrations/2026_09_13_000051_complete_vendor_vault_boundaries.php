<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['site_credentials', 'vendor_agreements'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('creation_key')->nullable()->unique();
                $table->string('creation_digest', 64)->nullable();
            });
        }
        Schema::table('site_vendors', function (Blueprint $table) {
            $table->json('related_records')->nullable();
        });
        Schema::table('site_credential_audit_logs', function (Blueprint $table) {
            // Preserve existing events while accepting the explicit lifecycle
            // and copy-outcome vocabulary; the controller owns allowed actions.
            $table->string('action', 40)->change();
            $table->foreignId('copy_intent_id')->nullable()->constrained('site_credential_audit_logs')->restrictOnDelete();
            $table->unique('copy_intent_id');
        });
        Schema::create('site_credential_step_up_uses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('authenticator_window');
            $table->timestamp('created_at')->index();
            $table->unique(['user_id', 'authenticator_window'], 'vault_personal_authenticator_window');
        });
    }

    public function down(): void
    {
        throw new LogicException('Vendor references and vault security state require a reviewed forward migration.');
    }
};
