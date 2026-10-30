<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedInteger('asset_profile_version')->default(1);
            $table->string('condition', 80)->nullable();
        });
        Schema::create('asset_custody_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('state', 30)->default('pending_receipt');
            $table->foreignId('origin_site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('destination_site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('destination_room_id')->nullable()->constrained('site_rooms')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('dispatched_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('returns_movement_id')->nullable()->index();
            $table->text('reason');
            $table->json('kit_snapshot');
            $table->json('received_kit')->nullable();
            $table->text('receipt_note')->nullable();
            $table->timestamp('dispatched_at');
            $table->timestamp('received_at')->nullable();
            $table->date('return_due_on')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'state']);
        });
        Schema::create('asset_kit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('component_asset_id')->nullable()->constrained('assets')->restrictOnDelete();
            $table->string('name', 160);
            $table->foreignId('added_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->text('removal_reason')->nullable();
            $table->timestamps();
            $table->index(['asset_id', 'removed_at']);
        });
        Schema::create('asset_profile_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('request_key', 80);
            $table->string('fingerprint', 64);
            $table->json('payload');
            $table->timestamp('occurred_at');
            $table->unique(['asset_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_profile_events');
        Schema::dropIfExists('asset_kit_items');
        Schema::dropIfExists('asset_custody_movements');
        Schema::table('assets', fn (Blueprint $table) => $table->dropColumn(['asset_profile_version', 'condition']));
    }
};
