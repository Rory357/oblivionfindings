<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CREDENTIAL_LIFECYCLE_CREDENTIAL_FK = 'access_credential_lifecycle_credential_fk';

    private const CREDENTIAL_LIFECYCLE_CREDENTIAL_SITE_FK = 'access_credential_lifecycle_credential_site_fk';

    private const REVISION_SCHEDULE_FK = 'access_schedule_revisions_schedule_retention_fk';

    private const REVISION_SCHEDULE_SITE_FK = 'access_schedule_revisions_schedule_site_fk';

    public function up(): void
    {
        $this->assertExistingLifecycleSiteIntegrity();

        Schema::table('access_control_schedules', function (Blueprint $table): void {
            $table->string('provider_reconciliation_request_key', 191)->nullable()->after('provider_reconciliation_status');
            $table->string('provider_reconciliation_event_key', 191)->nullable()->after('provider_reconciliation_request_key');
            $table->timestamp('provider_reconciliation_confirmed_at')->nullable()->after('provider_reconciliation_event_key');
            $table->string('provider_reconciliation_failure_reason', 500)->nullable()->after('provider_reconciliation_confirmed_at');
        });

        Schema::table('access_control_credentials', function (Blueprint $table): void {
            $table->string('provider_reconciliation_request_key', 191)->nullable()->after('provider_reconciliation_action');
            $table->string('provider_reconciliation_event_key', 191)->nullable()->after('provider_reconciliation_request_key');
            $table->unique(['id', 'site_id'], 'access_credentials_id_site_uq');
        });

        Schema::table('access_control_credential_lifecycle_events', function (Blueprint $table): void {
            $table->string('provider_request_key', 191)->nullable()->after('provider_action');
            $table->string('provider_event_key', 191)->nullable()->after('provider_request_key');
            $table->unique('provider_event_key', 'access_credential_lifecycle_provider_event_uq');
            $table->dropForeign(self::CREDENTIAL_LIFECYCLE_CREDENTIAL_FK);
            $table->foreign(['access_credential_id', 'site_id'], self::CREDENTIAL_LIFECYCLE_CREDENTIAL_SITE_FK)
                ->references(['id', 'site_id'])
                ->on('access_control_credentials')
                ->restrictOnDelete();
        });

        Schema::table('access_control_schedule_revisions', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->nullable()->after('access_schedule_id');
            $table->unsignedInteger('provider_confirmed_credentials_affected')
                ->default(0)
                ->after('active_credentials_affected');
            $table->string('provider_request_key', 191)->nullable()->after('provider_confirmed_credentials_affected');
            $table->string('provider_event_key', 191)->nullable()->after('provider_request_key');
            $table->boolean('provider_confirmed')->default(false)->after('provider_event_key');
        });

        DB::statement(<<<'SQL'
            UPDATE access_control_schedule_revisions revision
            INNER JOIN access_control_schedules schedule
                ON schedule.id = revision.access_schedule_id
            SET revision.site_id = schedule.site_id
            WHERE revision.site_id IS NULL
        SQL);

        if (DB::table('access_control_schedule_revisions')->whereNull('site_id')->exists()) {
            throw new RuntimeException('Access-control schedule revision Site provenance could not be derived. The migration will not discard or guess evidence.');
        }

        // Imported revision counts were legacy local claims. Post-import governed
        // revisions already counted only reconciled credentials, so preserve those
        // in an explicitly provider-confirmed field without rewriting source history.
        DB::table('access_control_schedule_revisions')
            ->where('action', '!=', 'imported')
            ->update([
                'provider_confirmed_credentials_affected' => DB::raw('active_credentials_affected'),
            ]);

        Schema::table('access_control_schedule_revisions', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->nullable(false)->change();
            $table->unique('provider_event_key', 'access_schedule_revisions_provider_event_uq');
            $table->dropForeign(self::REVISION_SCHEDULE_FK);
            $table->foreign('site_id', 'access_schedule_revisions_site_fk')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->foreign(['access_schedule_id', 'site_id'], self::REVISION_SCHEDULE_SITE_FK)
                ->references(['id', 'site_id'])
                ->on('access_control_schedules')
                ->restrictOnDelete();
        });

        Schema::create('access_control_credential_device_binding_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('access_credential_id');
            $table->unsignedBigInteger('site_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedInteger('sequence');
            $table->string('binding_status', 24);
            $table->string('provider_action', 16);
            $table->string('provider_reconciliation_status', 32);
            $table->string('provider_request_key', 191)->nullable();
            $table->string('provider_event_key', 191)->nullable();
            $table->boolean('provider_confirmed')->default(false);
            $table->timestamp('occurred_at')->nullable();
            // Retain numeric provenance even if the actor is later removed.
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->json('binding_snapshot');
            $table->timestamp('created_at');

            $table->foreign('site_id', 'access_credential_binding_site_fk')
                ->references('id')
                ->on('sites')
                ->restrictOnDelete();
            $table->foreign('device_id', 'access_credential_binding_device_fk')
                ->references('id')
                ->on('devices')
                ->restrictOnDelete();
            $table->foreign(['access_credential_id', 'site_id'], 'access_credential_binding_credential_site_fk')
                ->references(['id', 'site_id'])
                ->on('access_control_credentials')
                ->restrictOnDelete();
            $table->unique(
                ['access_credential_id', 'device_id', 'sequence'],
                'access_credential_binding_sequence_uq',
            );
            $table->unique(
                ['provider_event_key', 'device_id'],
                'access_credential_binding_provider_event_device_uq',
            );
            $table->index(
                ['site_id', 'device_id', 'created_at'],
                'access_credential_binding_site_device_created_idx',
            );
        });

        $this->snapshotLegacyDeviceLinks();
    }

    public function down(): void
    {
        if ((Schema::hasTable('access_control_credential_device_binding_events')
                && DB::table('access_control_credential_device_binding_events')->exists())
            || (Schema::hasTable('access_control_credential_lifecycle_events')
                && DB::table('access_control_credential_lifecycle_events')->whereNotNull('provider_event_key')->exists())
            || (Schema::hasTable('access_control_schedule_revisions')
                && DB::table('access_control_schedule_revisions')->exists())) {
            throw new RuntimeException(
                'Cannot remove governed Access Control provider evidence while binding, lifecycle, or schedule revision evidence exists.',
            );
        }

        Schema::dropIfExists('access_control_credential_device_binding_events');

        Schema::table('access_control_schedule_revisions', function (Blueprint $table): void {
            $table->dropForeign(self::REVISION_SCHEDULE_SITE_FK);
            $table->dropForeign('access_schedule_revisions_site_fk');
            $table->dropUnique('access_schedule_revisions_provider_event_uq');
            $table->foreign('access_schedule_id', self::REVISION_SCHEDULE_FK)
                ->references('id')
                ->on('access_control_schedules')
                ->restrictOnDelete();
            $table->dropColumn([
                'site_id',
                'provider_confirmed_credentials_affected',
                'provider_request_key',
                'provider_event_key',
                'provider_confirmed',
            ]);
        });

        Schema::table('access_control_credential_lifecycle_events', function (Blueprint $table): void {
            $table->dropForeign(self::CREDENTIAL_LIFECYCLE_CREDENTIAL_SITE_FK);
            $table->dropUnique('access_credential_lifecycle_provider_event_uq');
            $table->foreign('access_credential_id', self::CREDENTIAL_LIFECYCLE_CREDENTIAL_FK)
                ->references('id')
                ->on('access_control_credentials')
                ->restrictOnDelete();
            $table->dropColumn(['provider_request_key', 'provider_event_key']);
        });

        Schema::table('access_control_credentials', function (Blueprint $table): void {
            $table->dropUnique('access_credentials_id_site_uq');
            $table->dropColumn([
                'provider_reconciliation_request_key',
                'provider_reconciliation_event_key',
            ]);
        });

        Schema::table('access_control_schedules', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_reconciliation_request_key',
                'provider_reconciliation_event_key',
                'provider_reconciliation_confirmed_at',
                'provider_reconciliation_failure_reason',
            ]);
        });
    }

    private function assertExistingLifecycleSiteIntegrity(): void
    {
        $mismatch = DB::table('access_control_credential_lifecycle_events as event')
            ->join('access_control_credentials as credential', 'credential.id', '=', 'event.access_credential_id')
            ->whereColumn('event.site_id', '!=', 'credential.site_id')
            ->select(['event.id', 'event.site_id', 'credential.site_id as credential_site_id'])
            ->orderBy('event.id')
            ->first();

        if ($mismatch !== null) {
            throw new RuntimeException(sprintf(
                'Credential lifecycle event %d belongs to Site %d but its credential belongs to Site %d. Resolve the source evidence before retrying.',
                $mismatch->id,
                $mismatch->site_id,
                $mismatch->credential_site_id,
            ));
        }
    }

    private function snapshotLegacyDeviceLinks(): void
    {
        DB::table('access_control_credential_device as link')
            ->join('access_control_credentials as credential', 'credential.id', '=', 'link.access_credential_id')
            ->select([
                'link.access_credential_id',
                'link.device_id',
                'link.created_at',
                'link.updated_at',
                'credential.site_id',
                'credential.provider_reconciliation_action',
            ])
            ->orderBy('link.access_credential_id')
            ->orderBy('link.device_id')
            ->chunk(100, function ($links): void {
                $now = now();
                $rows = collect($links)->map(fn (object $link): array => [
                    'access_credential_id' => $link->access_credential_id,
                    'site_id' => $link->site_id,
                    'device_id' => $link->device_id,
                    'sequence' => 1,
                    'binding_status' => 'unconfirmed',
                    'provider_action' => in_array($link->provider_reconciliation_action, ['issue', 'revoke'], true)
                        ? $link->provider_reconciliation_action
                        : 'issue',
                    'provider_reconciliation_status' => 'required',
                    'provider_request_key' => null,
                    'provider_event_key' => null,
                    'provider_confirmed' => false,
                    'occurred_at' => $link->created_at ?? $now,
                    'recorded_by_user_id' => null,
                    'binding_snapshot' => json_encode([
                        'evidence_kind' => 'legacy_unconfirmed_link',
                        'legacy_created_at' => $link->created_at,
                        'legacy_updated_at' => $link->updated_at,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                ])->all();

                DB::table('access_control_credential_device_binding_events')->insert($rows);
            });
    }
};
