<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_catalog_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalog_item_id')->constrained('it_catalog_items')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('contract');
            $table->string('provenance', 40);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['catalog_item_id', 'version']);
        });
        Schema::table('it_catalog_items', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('published_version_id')->nullable()->constrained('it_catalog_versions')->restrictOnDelete();
        });
        Schema::table('it_catalog_submissions', function (Blueprint $table) {
            $table->foreignId('catalog_version_id')->nullable()->constrained('it_catalog_versions')->restrictOnDelete();
            $table->json('contract_snapshot')->nullable();
            $table->char('input_sha256', 64)->nullable();
        });

        // Preserve only the observable current publication. Do not invent
        // historical approval/version evidence for existing submissions.
        DB::table('it_catalog_items')->where('is_published', true)->orderBy('id')->chunkById(100, function ($items) {
            foreach ($items as $item) {
                $contract = [];
                foreach (['it_service_id', 'name', 'slug', 'description', 'outcome_type', 'category', 'provisioning_type', 'default_priority', 'requires_approval', 'internal_only', 'form_schema_version', 'form_schema', 'search_terms', 'sort_order'] as $field) {
                    $contract[$field] = $item->{$field};
                }
                foreach (['form_schema', 'search_terms'] as $field) {
                    $contract[$field] = $contract[$field] === null ? null : json_decode($contract[$field], true, flags: JSON_THROW_ON_ERROR);
                }
                foreach (['requires_approval', 'internal_only'] as $field) {
                    $contract[$field] = (bool) $contract[$field];
                }
                $id = DB::table('it_catalog_versions')->insertGetId([
                    'catalog_item_id' => $item->id,
                    'version' => $item->form_schema_version,
                    'contract' => json_encode($contract, JSON_THROW_ON_ERROR),
                    'provenance' => 'legacy_current',
                    'published_by' => null,
                    'created_at' => now(),
                ]);
                DB::table('it_catalog_items')->where('id', $item->id)->update(['published_version_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('it_catalog_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_version_id');
            $table->dropColumn(['contract_snapshot', 'input_sha256']);
        });
        Schema::table('it_catalog_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_version_id');
            $table->dropColumn('lock_version');
        });
        Schema::dropIfExists('it_catalog_versions');
    }
};
