<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR P11 B1: Medication Settings storage.
 *
 * Organisation values stay in `app_settings` (the rules already read them
 * there). Per-house values live in `medication_site_settings`. Every save and
 * every "Keep today's value" writes one append-only row per setting to
 * `medication_setting_changes`, with the structured value before and after so
 * an earlier value can be put back, and whether the change loosened a check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_site_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('key', 120);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'key'], 'med_site_settings_site_key_unique');
        });

        Schema::create('medication_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->string('setting_group', 40);
            $table->string('setting_key', 80);
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            // changed | kept (a default deliberately kept as it is).
            $table->string('action', 20);
            $table->string('view', 40);
            $table->string('section', 40);
            $table->string('label', 255);
            $table->json('before_value')->nullable();
            $table->json('after_value')->nullable();
            $table->text('before_text');
            $table->text('after_text');
            $table->boolean('loosens')->default(false);
            $table->string('note', 255)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audit_event', 120);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['setting_group', 'setting_key', 'site_id', 'id'], 'med_setting_changes_setting_idx');
            $table->index('created_at', 'med_setting_changes_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_setting_changes');
        Schema::dropIfExists('medication_site_settings');
    }
};
