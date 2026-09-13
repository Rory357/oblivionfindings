<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_setup_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('resource', 16);
            $table->uuid('request_uuid');
            $table->char('request_hash', 64)->nullable();
            $table->foreignId('it_team_id')->nullable()->constrained('it_teams')->restrictOnDelete();
            $table->foreignId('it_queue_id')->nullable()->constrained('it_queues')->restrictOnDelete();
            $table->foreignId('it_service_id')->nullable()->constrained('it_services')->restrictOnDelete();
            $table->char('committed_configuration_version', 64)->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['actor_user_id', 'request_uuid'], 'it_setup_command_actor_uuid_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('it_setup_command_receipts') && DB::table('it_setup_command_receipts')->exists()) {
            throw new RuntimeException('Retain Setup command receipts while browser creates can be retried. Use a forward repair instead of deleting their bindings.');
        }
        Schema::dropIfExists('it_setup_command_receipts');
    }
};
