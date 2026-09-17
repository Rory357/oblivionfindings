<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_vendors', function (Blueprint $table) {
            $table->string('visibility', 30)->default('site');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finance_vendor_id')->nullable()->constrained('fin_vendors')->restrictOnDelete();
            $table->text('supplied_services')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
        });
        Schema::create('vendor_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('site_vendors')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('visibility', 30)->default('site');
            $table->string('kind', 30);
            $table->string('title');
            $table->string('reference')->nullable();
            $table->string('status', 30)->default('active');
            $table->date('starts_on')->nullable();
            $table->date('renews_on')->nullable()->index();
            $table->unsignedSmallInteger('notice_days')->default(30);
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->default('NZD');
            $table->text('terms')->nullable();
            $table->text('evidence')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });
        Schema::create('vendor_agreement_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained('vendor_agreements')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->json('snapshot');
            $table->text('evidence')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('vendor_agreement_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->constrained('vendor_agreements')->restrictOnDelete();
            $table->uuid('series_id');
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('path');
            $table->string('mime');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('state', 30)->default('reserved');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['series_id', 'version']);
        });
        Schema::create('vendor_renewal_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agreement_id')->unique()->constrained('vendor_agreements')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->date('due_on')->nullable()->index();
            $table->string('status', 30)->default('scheduled');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
        Schema::table('site_credentials', function (Blueprint $table) {
            $table->string('visibility', 30)->default('site');
            // Deliberately independent from legacy is_shareable. No historical grants.
            $table->boolean('house_staff_access')->default(false);
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('retired_at')->nullable();
            $table->string('rotation_kind', 30)->nullable();
            $table->text('rotation_evidence')->nullable();
            $table->timestamp('storage_key_maintained_at')->nullable();
        });
        Schema::create('site_credential_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained('site_credentials')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->longText('encrypted_snapshot');
            $table->string('action', 40);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique(['credential_id', 'version']);
        });
    }

    public function down(): void
    {
        // History and encrypted recovery material require an approved retention
        // decision. Roll application code forward; never drop these via rollback.
        throw new LogicException('Vendor and vault history is retained. Use a reviewed forward migration.');
    }
};
