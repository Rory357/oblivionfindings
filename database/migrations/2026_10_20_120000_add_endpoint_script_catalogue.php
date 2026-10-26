<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endpoint_scripts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->unsignedInteger('script_version')->default(1);
            $t->string('status', 30);
            $t->unsignedInteger('status_version')->default(1);
            $t->text('manifest_json');
            $t->text('review_json');
            $t->char('content_hash', 64);
            $t->longText('artifact_ciphertext');
            $t->longText('runtime_ciphertext');
            $t->foreignId('uploaded_by_user_id')->constrained('users');
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->foreignId('reviewed_by_user_id')->nullable()->constrained('users');
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('withdrawn_at')->nullable();
            $t->timestamps();
            $t->unique(['uploaded_by_user_id', 'request_key'], 'endpoint_script_upload_receipt');
            $t->index(['status', 'id']);
        });
        Schema::create('endpoint_script_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('endpoint_script_id')->constrained('endpoint_scripts');
            $t->foreignId('actor_user_id')->constrained('users');
            $t->uuid('request_key');
            $t->char('request_hash', 64);
            $t->char('content_hash', 64);
            $t->string('event_type', 30);
            $t->string('status', 30);
            $t->unsignedInteger('status_version');
            $t->string('reason', 500);
            $t->timestamp('occurred_at');
            $t->unique(['actor_user_id', 'request_key'], 'endpoint_script_action_receipt');
            $t->unique(['endpoint_script_id', 'status_version'], 'endpoint_script_event_version');
        });
        Schema::create('endpoint_script_material_reads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('endpoint_script_id')->constrained('endpoint_scripts');
            $t->foreignId('actor_user_id')->constrained('users');
            $t->char('content_hash', 64);
            $t->timestamp('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_script_material_reads');
        Schema::dropIfExists('endpoint_script_events');
        Schema::dropIfExists('endpoint_scripts');
    }
};
