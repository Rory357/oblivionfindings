<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'healthSafety.events.close' => 'Close an H&S event after its canonical readiness checks pass',
        'healthSafety.events.closeAny' => 'Close an H&S event owned by another H&S lead',
        'healthSafety.closureExceptions.request' => 'Request a narrow H&S closure exception',
        'healthSafety.closureExceptions.approve' => 'Independently approve, reject, or revoke H&S closure exceptions',
    ];

    public function up(): void
    {
        Schema::table('hs_events', function (Blueprint $table): void {
            $table->string('worksafe_site_preservation_status', 24)->nullable()->after('worksafe_site_preserved');
            $table->timestamp('worksafe_site_preservation_decided_at')->nullable()->after('worksafe_site_preservation_status');
            $table->foreignId('worksafe_site_preservation_decided_by_user_id')
                ->nullable()
                ->after('worksafe_site_preservation_decided_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('worksafe_site_preservation_decision_reference', 500)
                ->nullable()
                ->after('worksafe_site_preservation_decided_by_user_id');
            $table->timestamp('worksafe_site_preservation_released_at')
                ->nullable()
                ->after('worksafe_site_preservation_decision_reference');
            $table->foreignId('worksafe_site_preservation_released_by_user_id')
                ->nullable()
                ->after('worksafe_site_preservation_released_at')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('worksafe_site_preservation_release_reference', 500)
                ->nullable()
                ->after('worksafe_site_preservation_released_by_user_id');
        });

        // Existing true values prove that preservation work started, not that it
        // finished. They remain active until a release is explicitly recorded.
        // False/default legacy values are deliberately left undecided and cannot
        // silently become a completed statutory decision.
        DB::table('hs_events')
            ->where('worksafe_notifiable', true)
            ->where('worksafe_site_preserved', true)
            ->update(['worksafe_site_preservation_status' => 'active']);

        Schema::create('hs_closure_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hs_event_id')->constrained('hs_events')->restrictOnDelete();
            $table->foreignId('site_id')->constrained('sites')->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 64);
            $table->text('reason');
            $table->string('evidence_reference', 500);
            $table->json('scope');
            $table->json('request_provenance');
            $table->char('provenance_hash', 64);
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->timestamp('review_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['hs_event_id', 'requested_at'], 'hs_close_exc_event_requested_idx');
            $table->index(['site_id', 'expires_at'], 'hs_close_exc_site_expiry_idx');
        });

        Schema::create('hs_closure_exception_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hs_closure_exception_id')
                ->constrained('hs_closure_exceptions')
                ->restrictOnDelete();
            $table->string('decision', 16);
            $table->string('decision_phase', 16);
            $table->foreignId('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('previous_decision_id')
                ->nullable()
                ->constrained('hs_closure_exception_decisions')
                ->restrictOnDelete();
            $table->json('decision_provenance');
            $table->char('provenance_hash', 64);
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['hs_closure_exception_id', 'decision_phase'],
                'hs_close_exc_decision_phase_uq',
            );
            $table->unique('previous_decision_id', 'hs_close_exc_previous_decision_uq');
        });

        $this->installPermissions();
        $this->installMysqlGuards();
    }

    public function down(): void
    {
        $this->dropMysqlGuards();

        Schema::dropIfExists('hs_closure_exception_decisions');
        Schema::dropIfExists('hs_closure_exceptions');

        Schema::table('hs_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('worksafe_site_preservation_released_by_user_id');
            $table->dropConstrainedForeignId('worksafe_site_preservation_decided_by_user_id');
            $table->dropColumn([
                'worksafe_site_preservation_status',
                'worksafe_site_preservation_decided_at',
                'worksafe_site_preservation_decision_reference',
                'worksafe_site_preservation_released_at',
                'worksafe_site_preservation_release_reference',
            ]);
        });

        if (Schema::hasTable('roles') && Schema::hasTable('permissions') && Schema::hasTable('role_permission')) {
            $closurePermissionIds = DB::table('permissions')
                ->whereIn('key', array_keys(self::PERMISSIONS))
                ->pluck('id');
            DB::table('role_permission')->whereIn('permission_id', $closurePermissionIds)->delete();
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->whereIn('permission_id', $closurePermissionIds)->delete();
            }
            DB::table('permissions')->whereIn('id', $closurePermissionIds)->delete();
        }
    }

    private function installPermissions(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        $permissionIds = [];
        foreach (self::PERMISSIONS as $key => $description) {
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            if (! $permissionId) {
                $attributes = [
                    'key' => $key,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (Schema::hasColumn('permissions', 'group')) {
                    $attributes['group'] = 'hazards';
                }
                if (Schema::hasColumn('permissions', 'module')) {
                    $attributes['module'] = 'Compliance';
                }
                $permissionId = DB::table('permissions')->insertGetId($attributes);
            }
            $permissionIds[$key] = (int) $permissionId;
        }

        $grants = [
            'health_safety_officer' => [
                'healthSafety.events.close',
                'healthSafety.events.closeAny',
                'healthSafety.closureExceptions.request',
            ],
            'team_lead' => [
                'healthSafety.events.close',
                'healthSafety.closureExceptions.request',
            ],
            // This is the explicit product policy for application-wide independent
            // exception decisions. Generic admin/provider/global access is not enough.
            'compliance_lead' => [
                'healthSafety.closureExceptions.approve',
                'healthSafety.viewAllSites',
                'hazards.view',
            ],
        ];

        foreach ($grants as $roleName => $keys) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (! $roleId) {
                continue;
            }

            foreach ($keys as $key) {
                $permissionId = $permissionIds[$key]
                    ?? DB::table('permissions')->where('key', $key)->value('id');
                if ($permissionId) {
                    DB::table('role_permission')->insertOrIgnore([
                        'role_id' => $roleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }

        // The legacy capability may remain in history/settings for review, but no
        // assignment or free-text value can authorise a future closure.
        $legacyPermissionId = DB::table('permissions')
            ->where('key', 'healthSafety.overrideClosure')
            ->value('id');
        if ($legacyPermissionId) {
            DB::table('role_permission')->where('permission_id', $legacyPermissionId)->delete();
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->where('permission_id', $legacyPermissionId)->delete();
            }
        }
    }

    private function installMysqlGuards(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TRIGGER hs_events_close_path_guard
BEFORE UPDATE ON hs_events
FOR EACH ROW
BEGIN
    IF OLD.status <> 'closed'
       AND NEW.status = 'closed'
       AND COALESCE(@hs_canonical_close_event_id, 0) <> OLD.id THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'H&S events may only close through the canonical closure service';
    END IF;

    IF OLD.status = 'closed'
       AND (NOT (NEW.status <=> OLD.status)
            OR NOT (NEW.closed_at <=> OLD.closed_at)
            OR NOT (NEW.closed_by <=> OLD.closed_by)
            OR NOT (NEW.closure_summary <=> OLD.closure_summary)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Closed H&S event provenance is immutable';
    END IF;
END
SQL);

        foreach (['hs_closure_exceptions', 'hs_closure_exception_decisions'] as $table) {
            $prefix = $table === 'hs_closure_exceptions' ? 'hs_close_exc' : 'hs_close_exc_dec';
            DB::unprepared("CREATE TRIGGER {$prefix}_immutable_update BEFORE UPDATE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'H&S closure exception provenance is append-only and immutable'");
            DB::unprepared("CREATE TRIGGER {$prefix}_immutable_delete BEFORE DELETE ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'H&S closure exception provenance is append-only and immutable'");
        }
    }

    private function dropMysqlGuards(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        foreach ([
            'hs_events_close_path_guard',
            'hs_close_exc_immutable_update',
            'hs_close_exc_immutable_delete',
            'hs_close_exc_dec_immutable_update',
            'hs_close_exc_dec_immutable_delete',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
