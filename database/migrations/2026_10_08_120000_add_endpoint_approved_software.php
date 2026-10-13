<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_software_packages', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedInteger('package_version')->default(1);
            $t->string('status', 30);
            $t->unsignedInteger('status_version')->default(1);
            $t->string('name', 200);
            $t->string('publisher', 200);
            $t->string('product_code', 38);
            $t->string('product_version', 20);
            $t->string('architecture', 10);
            $t->char('signer_sha256', 64);
            $t->char('sha256', 64);
            $t->unsignedBigInteger('size_bytes');
            $t->string('storage_key')->unique();
            $t->char('metadata_hash', 64);
            $t->foreignId('uploaded_by_user_id')->constrained('users');
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->foreignId('reviewed_by_user_id')->nullable()->constrained('users');
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('withdrawn_at')->nullable();
            $t->timestamps();
            $t->unique(['uploaded_by_user_id', 'request_key'], 'endpoint_software_upload_receipt_unique');
            $t->index(['status', 'id']);
        });
        Schema::create('endpoint_software_package_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('endpoint_software_package_id')->constrained('endpoint_software_packages', indexName: 'endpoint_software_event_package_fk');
            $t->foreignId('actor_user_id')->constrained('users');
            $t->string('event_type', 40);
            $t->unsignedInteger('status_version');
            $t->text('reason');
            $t->timestamp('occurred_at');
        });
        Schema::create('endpoint_software_policies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('device_id')->unique()->constrained('devices');
            $t->unsignedInteger('version');
            $t->boolean('enabled')->default(false);
            $t->string('consent_mode', 20)->default('attended');
            $t->foreignId('updated_by_user_id')->constrained('users');
            $t->timestamps();
        });
        Schema::table('endpoint_agents', function (Blueprint $t): void {
            $t->json('software_actions')->nullable();
            $t->json('software_observations')->nullable();
        });
        Schema::table('endpoint_deliveries', fn (Blueprint $t) => $t->json('software_readback')->nullable());
    }

    public function down(): void
    {
        Schema::table('endpoint_deliveries', fn (Blueprint $t) => $t->dropColumn('software_readback'));
        Schema::table('endpoint_agents', fn (Blueprint $t) => $t->dropColumn(['software_actions', 'software_observations']));
        Schema::dropIfExists('endpoint_software_policies');
        Schema::dropIfExists('endpoint_software_package_events');
        Schema::dropIfExists('endpoint_software_packages');
    }
};
