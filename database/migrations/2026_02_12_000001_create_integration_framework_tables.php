<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Provider registry
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('provider'); // unifi/queclink/iot/hikvision/axis/generic_webhook
            $table->string('display_name');
            $table->string('status')->default('inactive'); // active/inactive/error
            $table->datetime('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('capabilities')->nullable();
            $table->json('config')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });

        // Encrypted tenant-level API keys
        Schema::create('integration_tenant_secrets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('provider');
            $table->text('secret_encrypted');
            $table->string('secret_last4', 4)->nullable();
            $table->string('status')->default('disconnected'); // connected/disconnected/error
            $table->datetime('last_tested_at')->nullable();
            $table->datetime('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('config')->nullable(); // refresh defaults, alert routing defaults, quiet hours
            $table->datetime('rotated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });

        // Per-site integration mapping
        Schema::create('integration_site_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('status')->default('tenant_only'); // tenant_only/hybrid/disconnected
            $table->string('mapped_external_site_id')->nullable();
            $table->string('mapped_external_site_name')->nullable();
            $table->json('overrides')->nullable(); // refresh intervals, alert routing overrides
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'provider']);
        });

        // Per-site local credentials per capability
        Schema::create('integration_site_secrets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('capability'); // protect/access/network/ai
            $table->string('base_url')->nullable();
            $table->text('secret_encrypted');
            $table->boolean('is_enabled')->default(false);
            $table->datetime('last_tested_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'provider', 'capability']);
        });

        // Sync history
        Schema::create('integration_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('provider');
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); // discover_sites/sync_devices/sync_health/pull_events
            $table->string('status')->default('started'); // started/success/partial/failed
            $table->unsignedInteger('items_processed')->default(0);
            $table->unsignedInteger('items_created')->default(0);
            $table->unsignedInteger('items_updated')->default(0);
            $table->unsignedInteger('items_errored')->default(0);
            $table->text('error_message')->nullable();
            $table->datetime('started_at');
            $table->datetime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_logs');
        Schema::dropIfExists('integration_site_secrets');
        Schema::dropIfExists('integration_site_configs');
        Schema::dropIfExists('integration_tenant_secrets');
        Schema::dropIfExists('integrations');
    }
};
