<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_catalogue_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('supplier', 120);
            $table->string('source_name', 120);
            $table->string('source_version', 100);
            $table->string('attribution', 500);
            $table->string('licence_reference', 500);
            $table->timestamp('licence_attested_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->char('dataset_sha256', 64)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier', 'source_name', 'source_version'], 'medicine_source_version_unique');
        });
        Schema::create('medicine_catalogue_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained('medicine_catalogue_sources')->restrictOnDelete();
            $table->string('code_system', 40);
            $table->string('code', 100);
            $table->string('name', 200);
            $table->string('strength', 100);
            $table->string('form', 100);
            $table->char('identity_sha256', 64);
            $table->string('photo_path', 300)->nullable();
            $table->string('photo_mime', 30)->nullable();
            $table->char('photo_sha256', 64)->nullable();
            $table->uuid('photo_request_uuid')->nullable();
            $table->timestamps();
            $table->unique(['source_id', 'identity_sha256'], 'medicine_product_identity_unique');
            $table->index('identity_sha256');
        });
        Schema::create('medicine_catalogue_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_medication_id')->constrained('client_medications')->restrictOnDelete();
            $table->unsignedInteger('medication_version');
            $table->foreignId('product_id')->constrained('medicine_catalogue_products')->restrictOnDelete();
            $table->char('medicine_identity_sha256', 64);
            $table->string('reference', 500);
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at');
            $table->index(['client_medication_id', 'id'], 'medicine_binding_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_catalogue_bindings');
        Schema::dropIfExists('medicine_catalogue_products');
        Schema::dropIfExists('medicine_catalogue_sources');
    }
};
