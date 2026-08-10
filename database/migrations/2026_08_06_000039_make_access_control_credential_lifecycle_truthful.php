<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SCHEDULE_SITE_RETENTION_FK = 'access_schedules_site_retention_fk';

    private const CREDENTIAL_SITE_RETENTION_FK = 'access_credentials_site_retention_fk';

    private const CREDENTIAL_SCHEDULE_SITE_FK = 'access_credentials_schedule_site_fk';

    private const REVISION_SCHEDULE_RETENTION_FK = 'access_schedule_revisions_schedule_retention_fk';

    public function up(): void
    {
        $this->assertCredentialScheduleSiteIntegrity();

        $this->replaceEvidenceForeignKeys();

        Schema::table('access_control_credentials', function (Blueprint $table): void {
            $table->string('status', 20)->default('pending_issue')->change();
            $table->string('provider_reconciliation_status', 32)->default('required')->after('status');
            $table->string('provider_reconciliation_action', 16)->default('issue')->after('provider_reconciliation_status');
            $table->timestamp('provider_reconciliation_requested_at')->nullable()->after('provider_reconciliation_action');
            $table->timestamp('provider_reconciliation_confirmed_at')->nullable()->after('provider_reconciliation_requested_at');
            $table->string('provider_reconciliation_failure_reason', 500)->nullable()->after('provider_reconciliation_confirmed_at');
        });

        Schema::create('access_control_credential_lifecycle_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('access_credential_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedInteger('sequence');
            $table->string('event_type', 48);
            $table->string('evidence_kind', 32);
            $table->string('provider_action', 16)->nullable();
            $table->boolean('provider_confirmed')->default(false);
            $table->timestamp('occurred_at')->nullable();
            // Immutable numeric provenance is retained even if the referenced user is later removed.
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->timestamp('legacy_revoked_at')->nullable();
            $table->unsignedBigInteger('legacy_revoked_by_user_id')->nullable();
            $table->string('legacy_revocation_reason', 500)->nullable();
            $table->json('credential_snapshot');
            $table->timestamp('created_at');

            $table->foreign('access_credential_id', 'access_credential_lifecycle_credential_fk')
                ->references('id')
                ->on('access_control_credentials')
                ->restrictOnDelete();
            $table->foreign('site_id', 'access_credential_lifecycle_site_fk')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->unique(['access_credential_id', 'sequence'], 'access_credential_lifecycle_sequence_uq');
            $table->index(['site_id', 'created_at'], 'access_credential_lifecycle_site_created_idx');
        });

        $this->snapshotLegacyCredentials();
        $this->relabelLegacyLocalClaims();
    }

    private function assertCredentialScheduleSiteIntegrity(): void
    {
        $mismatch = DB::table('access_control_credentials as credential')
            ->leftJoin('access_control_schedules as schedule', 'schedule.id', '=', 'credential.access_schedule_id')
            ->where(function ($query): void {
                $query->whereNull('schedule.id')
                    ->orWhereColumn('credential.site_id', '!=', 'schedule.site_id');
            })
            ->select(['credential.id', 'credential.site_id', 'credential.access_schedule_id', 'schedule.site_id as schedule_site_id'])
            ->orderBy('credential.id')
            ->first();

        if ($mismatch !== null) {
            throw new RuntimeException(sprintf(
                'Credential %d belongs to Site %d but references schedule %d at Site %s. Resolve the source evidence before retrying; this migration will not rewrite it.',
                $mismatch->id,
                $mismatch->site_id,
                $mismatch->access_schedule_id,
                $mismatch->schedule_site_id ?? 'missing',
            ));
        }
    }

    public function down(): void
    {
        if ((Schema::hasTable('access_control_credentials') && DB::table('access_control_credentials')->exists())
            || (Schema::hasTable('access_control_credential_lifecycle_events')
                && DB::table('access_control_credential_lifecycle_events')->exists())) {
            throw new RuntimeException(
                'Cannot roll back provider credential reconciliation while credential or lifecycle event evidence exists.',
            );
        }

        Schema::dropIfExists('access_control_credential_lifecycle_events');

        Schema::table('access_control_credentials', function (Blueprint $table): void {
            $table->dropForeign(self::CREDENTIAL_SCHEDULE_SITE_FK);
            $table->dropForeign(self::CREDENTIAL_SITE_RETENTION_FK);
            $table->dropIndex('access_credentials_schedule_site_idx');
            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->foreign('access_schedule_id')
                ->references('id')
                ->on('access_control_schedules')
                ->restrictOnDelete();
            $table->string('status', 20)->default('active')->change();
            $table->dropColumn([
                'provider_reconciliation_status',
                'provider_reconciliation_action',
                'provider_reconciliation_requested_at',
                'provider_reconciliation_confirmed_at',
                'provider_reconciliation_failure_reason',
            ]);
        });

        Schema::table('access_control_schedules', function (Blueprint $table): void {
            $table->dropForeign(self::SCHEDULE_SITE_RETENTION_FK);
            $table->dropUnique('access_schedules_id_site_uq');
            $table->foreign('site_id')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
        });

        Schema::table('access_control_schedule_revisions', function (Blueprint $table): void {
            $table->dropForeign(self::REVISION_SCHEDULE_RETENTION_FK);
            $table->foreign('access_schedule_id')
                ->references('id')
                ->on('access_control_schedules')
                ->restrictOnDelete();
        });
    }

    private function replaceEvidenceForeignKeys(): void
    {
        Schema::table('access_control_credentials', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
            $table->dropForeign(['access_schedule_id']);
        });
        Schema::table('access_control_schedules', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
            $table->unique(['id', 'site_id'], 'access_schedules_id_site_uq');
            $table->foreign('site_id', self::SCHEDULE_SITE_RETENTION_FK)
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
        });
        Schema::table('access_control_credentials', function (Blueprint $table): void {
            $table->index(['access_schedule_id', 'site_id'], 'access_credentials_schedule_site_idx');
            $table->foreign('site_id', self::CREDENTIAL_SITE_RETENTION_FK)
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->foreign(['access_schedule_id', 'site_id'], self::CREDENTIAL_SCHEDULE_SITE_FK)
                ->references(['id', 'site_id'])
                ->on('access_control_schedules')
                ->restrictOnDelete();
        });
        Schema::table('access_control_schedule_revisions', function (Blueprint $table): void {
            $table->dropForeign(['access_schedule_id']);
            $table->foreign('access_schedule_id', self::REVISION_SCHEDULE_RETENTION_FK)
                ->references('id')
                ->on('access_control_schedules')
                ->restrictOnDelete();
        });
    }

    private function snapshotLegacyCredentials(): void
    {
        DB::table('access_control_credentials')
            ->orderBy('id')
            ->chunkById(100, function ($credentials): void {
                $now = now();
                $events = collect($credentials)->map(function (object $credential) use ($now): array {
                    $isLegacyRevocation = in_array($credential->status, ['revoked', 'pending_revoke', 'revoke_failed'], true);

                    return [
                        'access_credential_id' => $credential->id,
                        'site_id' => $credential->site_id,
                        'sequence' => 1,
                        'event_type' => 'legacy_local_state_snapshot',
                        'evidence_kind' => 'unconfirmed_local_claim',
                        'provider_action' => $isLegacyRevocation ? 'revoke' : 'issue',
                        'provider_confirmed' => false,
                        'occurred_at' => $isLegacyRevocation
                            ? ($credential->revoked_at ?? $credential->updated_at ?? $credential->created_at)
                            : ($credential->created_at ?? $now),
                        'recorded_by_user_id' => $isLegacyRevocation
                            ? ($credential->revoked_by_user_id ?? $credential->created_by_user_id)
                            : $credential->created_by_user_id,
                        'legacy_revoked_at' => $credential->revoked_at,
                        'legacy_revoked_by_user_id' => $credential->revoked_by_user_id,
                        'legacy_revocation_reason' => $credential->revocation_reason,
                        'credential_snapshot' => json_encode(get_object_vars($credential), JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                    ];
                })->all();

                DB::table('access_control_credential_lifecycle_events')->insert($events);
            }, 'id');
    }

    private function relabelLegacyLocalClaims(): void
    {
        DB::table('access_control_credentials')
            ->whereIn('status', ['active', 'pending_issue', 'issue_failed'])
            ->update([
                'status' => DB::raw("CASE WHEN status = 'active' THEN 'pending_issue' ELSE status END"),
                'provider_reconciliation_status' => DB::raw("CASE WHEN status = 'issue_failed' THEN 'failed' ELSE 'required' END"),
                'provider_reconciliation_action' => 'issue',
                'provider_reconciliation_requested_at' => DB::raw('COALESCE(updated_at, created_at)'),
                'provider_reconciliation_confirmed_at' => null,
            ]);

        DB::table('access_control_credentials')
            ->whereIn('status', ['revoked', 'pending_revoke', 'revoke_failed'])
            ->update([
                'status' => DB::raw("CASE WHEN status = 'revoked' THEN 'pending_revoke' ELSE status END"),
                'provider_reconciliation_status' => DB::raw("CASE WHEN status = 'revoke_failed' THEN 'failed' ELSE 'required' END"),
                'provider_reconciliation_action' => 'revoke',
                'provider_reconciliation_requested_at' => DB::raw('COALESCE(revoked_at, updated_at, created_at)'),
                'provider_reconciliation_confirmed_at' => null,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'revocation_reason' => null,
            ]);
    }
};
