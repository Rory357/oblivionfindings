<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

            // Optional: track who last updated/created
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('asset_tag')->nullable()->index();
            $table->string('qr_token', 64)->nullable()->unique();
            $table->string('name');
            $table->string('category')->nullable()->index();
            $table->text('description')->nullable();

            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable()->index();

            $table->date('purchase_date')->nullable();
            $table->date('warranty_expires_at')->nullable();

            $table->string('status')->default('active')->index(); // active|out_of_service|retired
            $table->string('risk_level')->default('medium')->index(); // low|medium|high

            $table->string('location')->nullable(); // e.g. cupboard, vehicle, room
            $table->boolean('requires_inspection')->default(false)->index();
            $table->date('inspection_due_at')->nullable()->index();

            $table->boolean('requires_maintenance')->default(false)->index();
            $table->date('maintenance_due_at')->nullable()->index();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Ensure an asset belongs to at least a site OR a client.
            // (Client assets implicitly belong to the client's site.)
        });

        Schema::create('asset_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->foreignId('inspected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('inspected_at');
            $table->string('result')->default('pass')->index(); // pass|fail|needs_followup
            $table->text('notes')->nullable();
            $table->date('next_due_at')->nullable()->index();

            $table->timestamps();
        });

        Schema::create('asset_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();

            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('performed_at');
            $table->string('type')->nullable()->index(); // service|repair|cleaning|calibration
            $table->string('vendor')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->date('next_due_at')->nullable()->index();

            $table->timestamps();
        });

        Schema::create('asset_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->string('category')->nullable()->index(); // manual|certificate|photo|invoice|policy
            $table->string('version')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('expiry_date')->nullable()->index();
            $table->text('notes')->nullable();

            $table->string('storage_disk')->default('local');
            $table->string('storage_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_documents');
        Schema::dropIfExists('asset_maintenance_logs');
        Schema::dropIfExists('asset_inspections');
        Schema::dropIfExists('assets');
    }
};
