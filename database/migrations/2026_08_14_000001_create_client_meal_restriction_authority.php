<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_recipes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('iddsi_food_level')->nullable()->after('serves_default');
        });

        Schema::create('client_meal_restrictions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('pending');
            $table->foreignId('replaces_id')->nullable()->constrained('client_meal_restrictions')->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('proposed_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approval_replay_key')->nullable()->unique();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->date('review_due_at');
            $table->unsignedTinyInteger('iddsi_food_level')->nullable();
            $table->string('iddsi_food_label', 120)->nullable();
            $table->unsignedTinyInteger('fluid_iddsi_level')->nullable();
            $table->string('fluid_label', 120)->nullable();
            $table->json('allergen_tag_ids');
            $table->json('dietary_tag_ids');
            $table->text('clinical_notes')->nullable();
            $table->text('amendment_reason');
            $table->char('content_hash', 64);
            $table->timestamps();

            $table->unique(['client_id', 'version'], 'cmr_client_version_unique');
            $table->unique('replaces_id', 'cmr_replaces_unique');
            $table->index(['client_id', 'status', 'effective_from'], 'cmr_client_status_effective');
            $table->index(['site_id', 'status', 'review_due_at'], 'cmr_site_status_review');
        });

        Schema::create('client_meal_restriction_discrepancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('restriction_id')->nullable()->constrained('client_meal_restrictions')->restrictOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->uuid('report_replay_key')->unique();
            $table->string('status', 24)->default('open');
            $table->text('details');
            $table->timestamp('reported_at');
            $table->timestamps();

            $table->index(['site_id', 'status', 'reported_at'], 'cmrd_site_status_reported');
            $table->index(['client_id', 'status'], 'cmrd_client_status');
        });

        $this->installPermissions();
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('key', [
                'clinical.mealRestrictions.author',
                'clinical.mealRestrictions.approve',
            ])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permission')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('client_meal_restriction_discrepancies');
        Schema::dropIfExists('client_meal_restrictions');

        Schema::table('meal_recipes', function (Blueprint $table): void {
            $table->dropColumn('iddsi_food_level');
        });
    }

    private function installPermissions(): void
    {
        $definitions = [
            'clinical.mealRestrictions.author' => 'Propose clinically governed resident meal restrictions',
            'clinical.mealRestrictions.approve' => 'Independently approve resident meal restrictions',
        ];

        $permissionIds = [];
        foreach ($definitions as $key => $description) {
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            if (! $permissionId) {
                $row = [
                    'key' => $key,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('permissions', 'group')) {
                    $row['group'] = 'clinical';
                }
                if (Schema::hasColumn('permissions', 'module')) {
                    $row['module'] = 'Health & Clinical';
                }
                $permissionId = DB::table('permissions')->insertGetId($row);
            }
            $permissionIds[] = $permissionId;
        }

        $roleIds = DB::table('roles')
            ->whereIn('name', ['admin', 'clinical_lead'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        // The former override capability cannot be retained on upgraded
        // installations: clinical safety conflicts are now always fail-closed.
        $legacyOverrideId = DB::table('permissions')
            ->where('key', 'sites.meals.allergen.override')
            ->value('id');
        if ($legacyOverrideId) {
            DB::table('role_permission')->where('permission_id', $legacyOverrideId)->delete();
        }
    }
};
