<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queclink_raw_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queclink_device_id')->nullable()->constrained('queclink_devices')->nullOnDelete();
            $table->string('imei', 20)->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('frame_type', ['RESP', 'ACK', 'SACK', 'BUFF', 'AT', 'unknown'])->default('unknown');
            $table->string('command_word', 10)->nullable();

            $table->text('raw_frame');
            $table->json('parsed_payload')->nullable();
            $table->boolean('parse_ok')->default(true);
            $table->string('parse_error', 255)->nullable();

            $table->string('session_id', 64)->nullable();
            $table->string('remote_address', 64)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['imei', 'created_at']);
            $table->index('created_at');
            $table->index('command_word');
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queclink_raw_frames');
    }
};
