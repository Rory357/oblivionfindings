<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_ticket_command_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('channel', 30);
            $table->string('operation', 60);
            $table->uuid('request_uuid');
            $table->char('request_hash', 64);
            $table->foreignId('it_ticket_id')->nullable()->constrained('it_tickets')->restrictOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['actor_user_id', 'channel', 'operation', 'request_uuid'],
                'it_ticket_command_actor_operation_uuid_unique',
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('it_ticket_command_receipts')
            && DB::table('it_ticket_command_receipts')->exists()) {
            throw new RuntimeException('Retain ticket command receipts while saved requests can be retried. Use a forward repair instead of deleting their bindings.');
        }

        Schema::dropIfExists('it_ticket_command_receipts');
    }
};
