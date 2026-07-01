<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR Assets redesign (federate-now / unify-later).
 *
 * - Extends hr_assets with federation (fleet_asset_id), QR, supplier, depreciation
 *   and disposal columns so HR equipment reaches lifecycle parity with the canonical
 *   Fleet & Assets register without duplicating physical vehicles/keys.
 * - Extends hr_asset_assignments with due-date, e-sign acknowledgement and condition
 *   photo references.
 * - Adds hr_asset_maintenance_logs and hr_asset_documents, mirroring the canonical
 *   asset_maintenance_logs / asset_documents column shapes for a trivial future merge.
 *
 * See docs handover §4.1 + HR_ASSETS_FEDERATION_PLAN.md.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_assets', function (Blueprint $table) {
            // Federation: when set, this row is a read-through pointer at the
            // canonical Fleet & Assets register — never an owned duplicate.
            $table->foreignId('fleet_asset_id')
                ->nullable()
                ->after('status')
                ->constrained('assets')
                ->nullOnDelete();

            // Scan-to-open QR token (mirrors assets.qr_token / asset_qr_tags.token).
            $table->string('qr_token', 64)->nullable()->unique()->after('fleet_asset_id');

            // Purchase / valuation extras.
            $table->string('supplier')->nullable()->after('purchase_cost');
            $table->string('condition')->nullable()->after('warranty_expiry'); // new/good/refurb at intake
            $table->string('depreciation_method')->nullable()->after('condition'); // straight-line | diminishing
            $table->unsignedSmallInteger('useful_life_years')->nullable()->after('depreciation_method');

            // Disposal / retirement record (status='retired').
            $table->string('disposal_reason')->nullable()->after('useful_life_years'); // end-of-life|lost|stolen|sold|damaged
            $table->date('disposed_at')->nullable()->after('disposal_reason');
            $table->decimal('disposal_value', 10, 2)->nullable()->after('disposed_at');
        });

        Schema::table('hr_asset_assignments', function (Blueprint $table) {
            $table->datetime('due_at')->nullable()->after('returned_at'); // return-by date
            $table->datetime('acknowledged_at')->nullable()->after('due_at'); // e-sign captured
            // HR e-sign record (hr_document_signatures) — kept nullable so an
            // assignment can be issued before the handover is signed.
            $table->foreignId('signature_id')
                ->nullable()
                ->after('acknowledged_at')
                ->constrained('hr_document_signatures')
                ->nullOnDelete();
            $table->json('photos')->nullable()->after('signature_id'); // condition photo refs
        });

        // Maintenance logs — 1:1 column parity with canonical asset_maintenance_logs
        // (plus HR repair-cycle dates) so a future merge is a straight copy.
        Schema::create('hr_asset_maintenance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('asset_id')->constrained('hr_assets')->cascadeOnDelete();
            $table->string('type')->default('repair'); // service | repair | cleaning | calibration
            $table->string('vendor')->nullable();
            $table->decimal('cost', 10, 2)->nullable();
            $table->date('sent_at')->nullable();
            $table->date('expected_back_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->date('next_due_at')->nullable();
            $table->string('outcome')->nullable(); // repaired | replaced | no-fault
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'completed_at']);
        });

        // Documents — mirrors canonical asset_documents (private-disk storage).
        Schema::create('hr_asset_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('asset_id')->constrained('hr_assets')->cascadeOnDelete();
            $table->string('title');
            $table->string('category')->default('manual'); // manual|certificate|photo|invoice|handover
            $table->string('storage_disk')->default('local');
            $table->string('storage_path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->date('effective_at')->nullable();
            $table->date('expiry_at')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_asset_documents');
        Schema::dropIfExists('hr_asset_maintenance_logs');

        Schema::table('hr_asset_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signature_id');
            $table->dropColumn(['due_at', 'acknowledged_at', 'photos']);
        });

        Schema::table('hr_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fleet_asset_id');
            $table->dropUnique(['qr_token']);
            $table->dropColumn([
                'qr_token', 'supplier', 'condition', 'depreciation_method',
                'useful_life_years', 'disposal_reason', 'disposed_at', 'disposal_value',
            ]);
        });
    }
};
