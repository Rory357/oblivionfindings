<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safeguarding_concerns', function (Blueprint $table): void {
            $table->unsignedInteger('sensitivity_version')->default(0)->after('is_sensitive');
        });

        DB::table('safeguarding_concerns')
            ->where('is_sensitive', true)
            ->update(['sensitivity_version' => 1]);

        Schema::create('safeguarding_declassification_reviews', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('safeguarding_concern_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->unsignedBigInteger('active_concern_id')->nullable();
            $table->unsignedInteger('concern_sensitivity_version');
            $table->timestamp('concern_updated_at');
            $table->string('status', 24)->default('pending');
            $table->unsignedBigInteger('requested_by_user_id');
            $table->timestamp('requested_at');
            $table->text('reason');
            $table->json('audience_snapshot');
            $table->char('audience_hash', 64);
            $table->uuid('request_replay_key');
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->uuid('decision_replay_key')->nullable();
            $table->timestamps();

            $table->foreign('safeguarding_concern_id', 'safe_declass_concern_fk')
                ->references('id')
                ->on('safeguarding_concerns')
                ->restrictOnDelete();
            $table->foreign('site_id', 'safe_declass_site_fk')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->foreign('active_concern_id', 'safe_declass_active_concern_fk')
                ->references('id')
                ->on('safeguarding_concerns')
                ->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'safe_declass_requester_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('reviewed_by_user_id', 'safe_declass_reviewer_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->unique('active_concern_id', 'safe_declass_active_concern_uniq');
            $table->unique('request_replay_key', 'safe_declass_request_replay_uniq');
            $table->unique('decision_replay_key', 'safe_declass_decision_replay_uniq');
            $table->index(
                ['safeguarding_concern_id', 'status', 'requested_at'],
                'safe_declass_concern_status_idx',
            );
            $table->index(['site_id', 'status', 'requested_at'], 'safe_declass_site_status_idx');
        });

        $this->installPermission();
        $this->installImmutabilityGuards();
    }

    public function down(): void
    {
        $this->dropImmutabilityGuards();

        Schema::dropIfExists('safeguarding_declassification_reviews');

        Schema::table('safeguarding_concerns', function (Blueprint $table): void {
            $table->dropColumn('sensitivity_version');
        });

        $permissionId = DB::table('permissions')
            ->where('key', 'safeguarding.declassification.approve')
            ->value('id');
        if ($permissionId) {
            DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }

    private function installPermission(): void
    {
        $key = 'safeguarding.declassification.approve';
        $permissionId = DB::table('permissions')->where('key', $key)->value('id');
        if (! $permissionId) {
            $row = [
                'key' => $key,
                'description' => 'Independently approve safeguarding declassification',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('permissions', 'group')) {
                $row['group'] = 'safeguarding';
            }
            if (Schema::hasColumn('permissions', 'module')) {
                $row['module'] = 'Compliance';
            }
            $permissionId = DB::table('permissions')->insertGetId($row);
        }

        $adminId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($adminId) {
            DB::table('role_permission')
                ->where('role_id', $adminId)
                ->where('permission_id', $permissionId)
                ->delete();
        }

        $complianceLeadId = DB::table('roles')->where('name', 'compliance_lead')->value('id');
        if ($complianceLeadId) {
            $reviewPermissionIds = DB::table('permissions')
                ->whereIn('key', [
                    'safeguarding.viewAny',
                    'safeguarding.viewSensitive',
                    $key,
                ])
                ->pluck('id');
            foreach ($reviewPermissionIds as $reviewPermissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $complianceLeadId,
                    'permission_id' => $reviewPermissionId,
                ]);
            }
        }
    }

    private function installImmutabilityGuards(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER safe_declass_reviews_before_insert_guard
            BEFORE INSERT ON safeguarding_declassification_reviews
            FOR EACH ROW
            BEGIN
                IF NEW.status <> 'pending'
                    OR NEW.active_concern_id IS NULL
                    OR NEW.active_concern_id <> NEW.safeguarding_concern_id
                    OR CHAR_LENGTH(TRIM(NEW.reason)) < 20
                    OR NEW.reviewed_by_user_id IS NOT NULL
                    OR NEW.reviewed_at IS NOT NULL
                    OR NEW.decision_reason IS NOT NULL
                    OR NEW.decision_replay_key IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Safeguarding declassification review must begin as a complete pending request.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER safe_declass_reviews_before_update_guard
            BEFORE UPDATE ON safeguarding_declassification_reviews
            FOR EACH ROW
            BEGIN
                IF NOT (OLD.safeguarding_concern_id <=> NEW.safeguarding_concern_id)
                    OR NOT (OLD.site_id <=> NEW.site_id)
                    OR NOT (OLD.concern_sensitivity_version <=> NEW.concern_sensitivity_version)
                    OR NOT (OLD.concern_updated_at <=> NEW.concern_updated_at)
                    OR NOT (OLD.requested_by_user_id <=> NEW.requested_by_user_id)
                    OR NOT (OLD.requested_at <=> NEW.requested_at)
                    OR NOT (OLD.reason <=> NEW.reason)
                    OR NOT (OLD.audience_snapshot <=> NEW.audience_snapshot)
                    OR NOT (OLD.audience_hash <=> NEW.audience_hash)
                    OR NOT (OLD.request_replay_key <=> NEW.request_replay_key)
                    OR NOT (OLD.content_hash <=> NEW.content_hash)
                    OR NOT (OLD.created_at <=> NEW.created_at) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Safeguarding declassification request provenance is immutable.';
                END IF;

                IF OLD.status <> 'pending'
                    OR NEW.status NOT IN ('approved', 'rejected')
                    OR NEW.active_concern_id IS NOT NULL
                    OR NEW.reviewed_by_user_id IS NULL
                    OR NEW.reviewed_at IS NULL
                    OR NEW.decision_reason IS NULL
                    OR CHAR_LENGTH(TRIM(NEW.decision_reason)) < 10
                    OR (NEW.status = 'approved' AND NEW.reviewed_by_user_id = OLD.requested_by_user_id)
                    OR NEW.decision_replay_key IS NULL THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Safeguarding declassification review transition is invalid or incomplete.';
                END IF;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER safe_declass_reviews_before_delete_guard
            BEFORE DELETE ON safeguarding_declassification_reviews
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Safeguarding declassification provenance cannot be deleted.';
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER safe_concerns_before_declass_guard
            BEFORE UPDATE ON safeguarding_concerns
            FOR EACH ROW
            BEGIN
                IF OLD.is_sensitive = 1 AND NEW.is_sensitive = 0 AND (
                    NEW.sensitivity_version <> OLD.sensitivity_version + 1
                    OR NOT EXISTS (
                        SELECT 1
                        FROM safeguarding_declassification_reviews AS approved_review
                        WHERE approved_review.safeguarding_concern_id = OLD.id
                          AND approved_review.site_id <=> OLD.site_id
                          AND approved_review.concern_sensitivity_version = OLD.sensitivity_version
                          AND approved_review.concern_updated_at = OLD.updated_at
                          AND approved_review.status = 'approved'
                          AND approved_review.active_concern_id IS NULL
                          AND approved_review.reviewed_by_user_id = NEW.updated_by
                    )
                ) THEN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'Safeguarding declassification requires its matching governed approval.';
                END IF;
            END
            SQL);
    }

    private function dropImmutabilityGuards(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS safe_concerns_before_declass_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS safe_declass_reviews_before_delete_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS safe_declass_reviews_before_update_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS safe_declass_reviews_before_insert_guard');
    }
};
