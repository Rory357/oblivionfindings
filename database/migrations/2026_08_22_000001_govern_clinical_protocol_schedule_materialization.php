<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The unique occurrence constraint is the concurrency backstop. Refuse
        // ambiguous legacy rows before the first DDL statement rather than
        // guessing which clinical action should survive.
        $duplicate = DB::table('clinical_protocol_schedules')
            ->select(['clinical_protocol_id', 'due_at'])
            ->groupBy('clinical_protocol_id', 'due_at')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('clinical_protocol_id')
            ->orderBy('due_at')
            ->first();

        if ($duplicate) {
            throw new RuntimeException(
                "Clinical protocol {$duplicate->clinical_protocol_id} has duplicate legacy schedule occurrences at {$duplicate->due_at}."
            );
        }

        Schema::table('clinical_protocols', function (Blueprint $table): void {
            $table->timestamp('schedule_anchor_at')->nullable()->after('ends_at');
            $table->unsignedInteger('schedule_version')->default(1)->after('schedule_anchor_at');
        });

        Schema::table('clinical_protocol_schedules', function (Blueprint $table): void {
            $table->unsignedInteger('schedule_version')->default(1)->after('clinical_protocol_id');
            $table->char('occurrence_key', 64)->nullable()->after('schedule_version');
        });

        DB::table('clinical_protocol_schedules')
            ->selectRaw('clinical_protocol_id, MIN(due_at) AS schedule_anchor_at')
            ->groupBy('clinical_protocol_id')
            ->orderBy('clinical_protocol_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('clinical_protocols')
                        ->where('id', $row->clinical_protocol_id)
                        ->update([
                            'schedule_anchor_at' => CarbonImmutable::parse(
                                (string) $row->schedule_anchor_at,
                                'UTC',
                            )->utc()->startOfSecond(),
                        ]);
                }
            });

        DB::table('clinical_protocol_schedules')
            ->select(['id', 'clinical_protocol_id', 'due_at'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $dueAt = CarbonImmutable::parse((string) $row->due_at, 'UTC')
                        ->utc()
                        ->startOfSecond();

                    DB::table('clinical_protocol_schedules')
                        ->where('id', $row->id)
                        ->update([
                            'schedule_version' => 1,
                            'occurrence_key' => hash('sha256', implode('|', [
                                'clinical-protocol-occurrence-v1',
                                (string) $row->clinical_protocol_id,
                                '1',
                                $dueAt->format('Y-m-d\TH:i:s\Z'),
                            ])),
                        ]);
                }
            });

        Schema::table('clinical_protocol_schedules', function (Blueprint $table): void {
            $table->char('occurrence_key', 64)->nullable(false)->change();
            $table->unique('occurrence_key', 'clin_sched_occurrence_key_uq');
        });

        Schema::create('clinical_protocol_schedule_materializations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('idempotency_key')->unique('clin_sched_materialization_key_uq');
            $table->string('action', 24);
            $table->char('request_fingerprint', 64);
            // Null denotes the internal scheduler; actor-driven commands retain
            // the exact authenticated requester.
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('clinical_protocol_id')->nullable();
            $table->unsignedInteger('schedule_version')->nullable();
            $table->timestamp('window_start_at')->nullable();
            $table->timestamp('window_end_at')->nullable();
            $table->string('materialization_timezone', 64)->default('UTC');
            $table->json('occurrence_keys')->nullable();
            $table->unsignedInteger('occurrence_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('requested_by', 'clin_sched_materialization_requester_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('clinical_protocol_id', 'clin_sched_materialization_protocol_fk')
                ->references('id')
                ->on('clinical_protocols')
                ->cascadeOnDelete();

            $table->index(
                ['clinical_protocol_id', 'action'],
                'clin_sched_materialization_protocol_action_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_protocol_schedule_materializations');

        Schema::table('clinical_protocol_schedules', function (Blueprint $table): void {
            $table->dropUnique('clin_sched_occurrence_key_uq');
            $table->dropColumn(['schedule_version', 'occurrence_key']);
        });

        Schema::table('clinical_protocols', function (Blueprint $table): void {
            $table->dropColumn(['schedule_anchor_at', 'schedule_version']);
        });
    }
};
