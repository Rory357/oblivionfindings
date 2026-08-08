<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queclink_presets', function (Blueprint $table): void {
            $table->timestamp('retired_at')->nullable()->after('is_system');
            // Retain immutable actor provenance even if that User is later removed.
            $table->unsignedBigInteger('retired_by_user_id')->nullable()->after('retired_at');
            $table->string('retirement_reason', 500)->nullable()->after('retired_by_user_id');
            $table->index(['retired_at', 'is_system'], 'queclink_presets_retired_system_idx');
        });

        Schema::table('device_documents', function (Blueprint $table): void {
            $table->string('storage_disk')->default('private')->change();
            $table->string('content_sha256', 64)->nullable()->after('size_bytes');
            $table->timestamp('removed_at')->nullable()->after('notes');
            // Retain immutable actor provenance even if that User is later removed.
            $table->unsignedBigInteger('removed_by_user_id')->nullable()->after('removed_at');
            $table->string('removal_reason', 500)->nullable()->after('removed_by_user_id');
            $table->timestamp('storage_deleted_at')->nullable()->after('removal_reason');
            $table->index(['device_id', 'removed_at'], 'dev_docs_device_removed_idx');
        });

        // The two disks intentionally share the same protected filesystem root,
        // but only `local` can issue framework-served temporary URLs. Move the
        // retained metadata boundary to the non-served disk without copying or
        // exposing the existing blobs.
        DB::table('device_documents')
            ->where('storage_disk', 'local')
            ->update(['storage_disk' => 'private']);

        Schema::table('device_documents', function (Blueprint $table): void {
            $table->dropForeign(['device_id']);
            $table->foreign('device_id', 'device_documents_device_retention_fk')
                ->references('id')
                ->on('devices')
                ->restrictOnDelete();
        });

        // Historical hard-deleted preset wrappers cannot be reconstructed.
        // Contain any surviving governed profile rather than leaving it
        // selectable. Device-specific immutable drafts are not preset wrappers.
        $orphanProfileIds = DB::table('device_configuration_profiles as profile')
            ->where('profile.provider', 'queclink')
            ->where('profile.status', 'active')
            ->where('profile.profile_key', 'not like', 'queclink:device-%:draft:%')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('queclink_presets as preset')
                    ->whereColumn('preset.device_configuration_profile_id', 'profile.id');
            })
            ->pluck('profile.id');
        if ($orphanProfileIds->isNotEmpty()) {
            DB::table('device_configuration_profiles')
                ->whereIn('id', $orphanProfileIds)
                ->update(['status' => 'retired', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $containedOrphanProfilesExist = DB::table('device_configuration_profiles as profile')
            ->where('profile.provider', 'queclink')
            ->where('profile.status', 'retired')
            ->where('profile.profile_key', 'not like', 'queclink:device-%:draft:%')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('queclink_presets as preset')
                    ->whereColumn('preset.device_configuration_profile_id', 'profile.id');
            })
            ->exists();

        if (DB::table('queclink_presets')->whereNotNull('retired_at')->exists()
            || DB::table('device_documents')->exists()
            || $containedOrphanProfilesExist) {
            throw new RuntimeException(
                'Cannot remove governed preset or document lifecycle fields while retained configuration or document evidence exists.',
            );
        }

        Schema::table('device_documents', function (Blueprint $table): void {
            $table->dropForeign('device_documents_device_retention_fk');
            $table->foreign('device_id')->references('id')->on('devices')->cascadeOnDelete();
            $table->string('storage_disk')->default('local')->change();
            $table->dropIndex('dev_docs_device_removed_idx');
            $table->dropColumn([
                'content_sha256',
                'removed_at',
                'removed_by_user_id',
                'removal_reason',
                'storage_deleted_at',
            ]);
        });

        Schema::table('queclink_presets', function (Blueprint $table): void {
            $table->dropIndex('queclink_presets_retired_system_idx');
            $table->dropColumn(['retired_at', 'retired_by_user_id', 'retirement_reason']);
        });
    }
};
