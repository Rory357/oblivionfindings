<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('device_assignments', 'tracking_purpose')) {
            Schema::table('device_assignments', function (Blueprint $table): void {
                $table->text('tracking_purpose')->nullable()->after('consent_id');
            });
        } elseif (Schema::getColumnType('device_assignments', 'tracking_purpose') !== 'text') {
            Schema::table('device_assignments', function (Blueprint $table): void {
                $table->text('tracking_purpose')->nullable()->change();
            });
        }

        if (! Schema::hasColumn('device_assignments', 'authority_basis')) {
            Schema::table('device_assignments', function (Blueprint $table): void {
                $table->string('authority_basis', 80)->nullable()->after('tracking_purpose');
            });
        }

        if (! Schema::hasColumn('device_assignments', 'access_audience')) {
            Schema::table('device_assignments', function (Blueprint $table): void {
                $table->json('access_audience')->nullable()->after('authority_basis');
            });
        }

        if (! Schema::hasColumn('device_assignments', 'retention_days')) {
            Schema::table('device_assignments', function (Blueprint $table): void {
                $table->unsignedSmallInteger('retention_days')->nullable()->after('access_audience');
            });
        }

        if (! Schema::hasColumn('device_assignments', 'collection_started_at')) {
            Schema::table('device_assignments', function (Blueprint $table): void {
                $table->timestamp('collection_started_at')->nullable()->after('retention_days');
            });
        }

        if (! Schema::hasColumn('device_assignments', 'collection_stopped_at')) {
            Schema::table('device_assignments', function (Blueprint $table): void {
                $table->timestamp('collection_stopped_at')->nullable()->after('collection_started_at');
            });
        }

        if (! Schema::hasColumn('device_assignments', 'collection_stop_reason')) {
            Schema::table('device_assignments', function (Blueprint $table): void {
                $table->string('collection_stop_reason', 80)->nullable()->after('collection_stopped_at');
            });
        }

        if (! Schema::hasColumn('device_assignments', 'withdrawal_outcome')) {
            Schema::table('device_assignments', function (Blueprint $table): void {
                $table->string('withdrawal_outcome', 120)->nullable()->after('collection_stop_reason');
            });
        }

        Schema::whenTableDoesntHaveIndex(
            'device_assignments',
            'dev_assign_collection_active_idx',
            function (Blueprint $table): void {
                $table->index(
                    ['device_id', 'collection_stopped_at'],
                    'dev_assign_collection_active_idx',
                );
            },
        );

        $now = now()->toDateTimeString();

        DB::table('device_assignments')
            ->orderBy('id')
            ->chunkById(200, function ($assignments) use ($now): void {
                foreach ($assignments as $assignment) {
                    $device = DB::table('devices')->where('id', $assignment->device_id)->first();

                    if (! $device || $device->domain !== 'tracking'
                        || ! in_array($assignment->assignable_type, ['client', 'staff'], true)) {
                        continue;
                    }

                    $isClient = $assignment->assignable_type === 'client';
                    $consent = $isClient && $assignment->consent_id
                        ? DB::table('client_consents')->where('id', $assignment->consent_id)->first()
                        : null;
                    $consentType = $consent
                        ? DB::table('consent_types')->where('id', $consent->consent_type_id)->first()
                        : null;
                    $isConsentValid = $consent
                        && (int) $consent->client_id === (int) $assignment->assignable_id
                        && $consent->status === 'given'
                        && $consent->withdrawn_at === null
                        && $consent->superseded_by_consent_id === null
                        && ($consent->expires_at === null || $consent->expires_at > $now)
                        && preg_match('/tracking|tracker|location/i', (string) ($consentType->name ?? '')) === 1;
                    $releasedAt = $assignment->released_at;
                    $collectionStoppedAt = $releasedAt
                        ?? ($isClient && ! $isConsentValid ? ($consent->withdrawn_at ?? $now) : null);

                    DB::table('device_assignments')
                        ->where('id', $assignment->id)
                        ->update([
                            'tracking_purpose' => $isClient
                                ? ($consentType->purpose ?? $consentType->name ?? 'Client personal safety tracking')
                                : 'Staff lone-worker safety',
                            'authority_basis' => $isClient
                                ? 'assignment_linked_client_consent'
                                : 'active_lone_worker_session',
                            'access_audience' => json_encode($isClient
                                ? ['authorised_client_care', 'control_room', 'health_and_safety']
                                : ['control_room', 'health_and_safety']),
                            'retention_days' => 90,
                            'collection_started_at' => $assignment->assigned_at,
                            'collection_stopped_at' => $collectionStoppedAt,
                            'collection_stop_reason' => $releasedAt
                                ? 'assignment_released'
                                : ($collectionStoppedAt ? 'consent_not_active' : null),
                            'withdrawal_outcome' => $collectionStoppedAt
                                ? 'collection_stopped_and_live_projection_revoked'
                                : null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('device_assignments', function (Blueprint $table): void {
            $table->dropIndex('dev_assign_collection_active_idx');
            $table->dropColumn([
                'tracking_purpose',
                'authority_basis',
                'access_audience',
                'retention_days',
                'collection_started_at',
                'collection_stopped_at',
                'collection_stop_reason',
                'withdrawal_outcome',
            ]);
        });
    }
};
