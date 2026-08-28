<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('unique migration fails closed without changing duplicate ledger or linked evidence', function (): void {
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $worker = User::factory()->create(['approved_at' => now()]);
    $session = HrAttendanceSession::query()->create([
        'user_id' => $worker->id,
        'site_id' => $site->id,
        'clock_in_at' => now()->subHours(9),
        'clock_out_at' => now()->subHour(),
        'break_minutes' => 30,
        'status' => 'closed',
        'source' => 'legacy',
        'created_by' => $worker->id,
        'closed_by' => $worker->id,
    ]);

    $connection = DB::connection();
    expect($connection->getDriverName())->toBe('mysql');
    $connection->commit();

    $migration = null;
    $entryIds = [];
    try {
        $migration = require database_path(
            'migrations/2026_06_27_120000_add_unique_attendance_session_id_to_hr_time_entries.php',
        );
        $migration->down();
        expect(Schema::hasIndex(
            'hr_time_entries',
            'hr_time_entries_attendance_session_id_unique',
        ))->toBeFalse()
            ->and(Schema::hasIndex(
                'hr_time_entries',
                'hr_time_entries_attendance_session_id_index',
            ))->toBeTrue();

        $workDate = $session->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString();
        $approved = HrTimeEntry::query()->create([
            'user_id' => $worker->id,
            'attendance_session_id' => $session->id,
            'site_id' => $site->id,
            'entry_date' => $workDate,
            'clock_in' => $session->clock_in_at,
            'clock_out' => $session->clock_out_at,
            'break_minutes' => 30,
            'total_hours' => 7.5,
            'entry_type' => 'clock',
            'status' => 'submitted',
            'source_type' => 'attendance',
            'source_id' => $session->id,
            'created_by' => $worker->id,
        ]);
        $entryIds[] = $approved->id;
        $approved->forceFill([
            'status' => 'approved',
            'approved_by' => $worker->id,
            'approved_at' => now(),
        ])->saveQuietly();
        $duplicate = HrTimeEntry::query()->create([
            'user_id' => $worker->id,
            'attendance_session_id' => $session->id,
            'site_id' => $site->id,
            'entry_date' => $workDate,
            'clock_in' => $session->clock_in_at,
            'clock_out' => $session->clock_out_at->copy()->subMinutes(15),
            'break_minutes' => 15,
            'total_hours' => 7.5,
            'entry_type' => 'clock',
            'status' => 'submitted',
            'source_type' => 'attendance',
            'source_id' => $session->id,
            'created_by' => $worker->id,
        ]);
        $entryIds[] = $duplicate->id;

        $amendment = HrTimeEntryAmendment::query()->create([
            'hr_time_entry_id' => $approved->id,
            'amended_by' => $worker->id,
            'field_name' => 'clock_out',
            'old_value' => $session->clock_out_at->copy()->subMinutes(30)->toDateTimeString(),
            'new_value' => $session->clock_out_at->toDateTimeString(),
            'reason' => 'Retained approval evidence.',
        ]);
        $timesheet = Timesheet::query()->create([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'attendance_session_id' => $session->id,
            'hr_time_entry_id' => $duplicate->id,
            'activity_type' => 'other',
            'work_date' => $workDate,
            'starts_at' => $session->clock_in_at,
            'ends_at' => $session->clock_out_at,
            'break_minutes' => 30,
            'status' => 'draft',
            'created_by' => $worker->id,
        ]);
        $timesheet->forceFill([
            'status' => 'approved',
            'payroll_reference' => 'duplicate-migration-preservation',
        ])->saveQuietly();

        $entriesBefore = HrTimeEntry::withTrashed()
            ->whereIn('id', $entryIds)
            ->orderBy('id')
            ->get()
            ->map(fn (HrTimeEntry $entry): array => $entry->getAttributes())
            ->all();
        $amendmentBefore = $amendment->fresh()->getAttributes();
        $timesheetBefore = $timesheet->fresh()->getAttributes();

        $thrown = null;
        try {
            $migration->up();
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        expect($thrown)->toBeInstanceOf(RuntimeException::class)
            ->and($thrown?->getMessage())->toContain((string) $session->id)
            ->and(HrTimeEntry::withTrashed()
                ->whereIn('id', $entryIds)
                ->orderBy('id')
                ->get()
                ->map(fn (HrTimeEntry $entry): array => $entry->getAttributes())
                ->all())->toBe($entriesBefore)
            ->and($amendment->fresh()->getAttributes())->toBe($amendmentBefore)
            ->and($timesheet->fresh()->getAttributes())->toBe($timesheetBefore)
            ->and((int) $timesheet->fresh()->hr_time_entry_id)->toBe($duplicate->id);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        try {
            DB::table('timesheets')->where('attendance_session_id', $session->id)->delete();
            if ($entryIds !== []) {
                DB::table('hr_time_entry_amendments')->whereIn('hr_time_entry_id', $entryIds)->delete();
                DB::table('hr_time_entries')->whereIn('id', $entryIds)->delete();
            }
            DB::table('audit_logs')->where('user_id', $worker->id)->delete();
            DB::table('hr_attendance_sessions')->where('id', $session->id)->delete();
            DB::table('users')->where('id', $worker->id)->delete();
            DB::table('sites')->where('id', $site->id)->delete();
        } finally {
            try {
                if (
                    $migration !== null
                    && ! Schema::hasIndex(
                        'hr_time_entries',
                        'hr_time_entries_attendance_session_id_unique',
                    )
                ) {
                    $migration->up();
                }
            } finally {
                $connection->beginTransaction();
            }
        }
    }
});

test('post-mutex attendance backfill projects eligible rows and records deterministic protected skips', function (): void {
    $site = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $worker = User::factory()->create(['approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    $eligible = HrAttendanceSession::query()->create([
        'user_id' => $worker->id,
        'site_id' => $site->id,
        'clock_in_at' => now()->subHours(10),
        'clock_out_at' => now()->subHours(2),
        'break_minutes' => 30,
        'status' => 'closed',
        'source' => 'legacy',
        'created_by' => $worker->id,
        'closed_by' => $worker->id,
    ]);
    $missingSite = HrAttendanceSession::query()->create([
        'user_id' => $worker->id,
        'site_id' => null,
        'clock_in_at' => now()->subDays(2)->subHours(8),
        'clock_out_at' => now()->subDays(2),
        'status' => 'closed',
        'source' => 'legacy',
        'created_by' => $worker->id,
        'closed_by' => $worker->id,
    ]);
    $protected = HrAttendanceSession::query()->create([
        'user_id' => $worker->id,
        'site_id' => $site->id,
        'clock_in_at' => now()->subDays(4)->subHours(8),
        'clock_out_at' => now()->subDays(4),
        'status' => 'closed',
        'source' => 'legacy',
        'created_by' => $worker->id,
        'closed_by' => $worker->id,
    ]);
    $partialSegment = HrAttendanceSession::query()->create([
        'user_id' => $worker->id,
        'site_id' => $site->id,
        'clock_in_at' => now()->subDays(6)->subHours(8),
        'clock_out_at' => now()->subDays(6),
        'status' => 'closed',
        'source' => 'legacy',
        'created_by' => $worker->id,
        'closed_by' => $worker->id,
    ]);
    $completeSegment = HrAttendanceSession::query()->create([
        'user_id' => $worker->id,
        'site_id' => $site->id,
        'clock_in_at' => now()->subDays(8)->subHours(8),
        'clock_out_at' => now()->subDays(8),
        'status' => 'closed',
        'source' => 'legacy',
        'created_by' => $worker->id,
        'closed_by' => $worker->id,
    ]);
    $timesheet = Timesheet::query()->create([
        'user_id' => $worker->id,
        'site_id' => $site->id,
        'attendance_session_id' => $protected->id,
        'activity_type' => 'other',
        'work_date' => $protected->clock_in_at->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString(),
        'starts_at' => $protected->clock_in_at,
        'ends_at' => $protected->clock_out_at,
        'break_minutes' => 0,
        'status' => 'draft',
        'created_by' => $worker->id,
    ]);
    $timesheet->forceFill([
        'status' => 'approved',
        'approved_at' => now(),
        'approved_by' => $worker->id,
    ])->saveQuietly();
    $timesheetBefore = $timesheet->fresh()->getAttributes();
    foreach ([
        [$partialSegment, [['segment_minutes' => 60]]],
        [$completeSegment, [['segment_minutes' => 480]]],
    ] as [$segmentSession, $segments]) {
        Timesheet::query()->create([
            'user_id' => $worker->id,
            'site_id' => $site->id,
            'attendance_session_id' => $segmentSession->id,
            'activity_type' => 'other',
            'work_date' => $segmentSession->clock_in_at->copy()
                ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
                ->toDateString(),
            'starts_at' => $segmentSession->clock_in_at,
            'ends_at' => $segmentSession->clock_out_at,
            'break_minutes' => 0,
            'status' => 'draft',
            'payroll_segments_exported' => $segments,
            'created_by' => $worker->id,
        ]);
    }

    Log::spy();
    $migration = require database_path('migrations/2026_08_23_000215_retry_attendance_time_entry_backfill.php');
    $migration->up();

    $entry = HrTimeEntry::query()->where('attendance_session_id', $eligible->id)->sole();
    expect($entry->status)->toBe('submitted')
        ->and($entry->clock_out?->timestamp)->toBe($eligible->clock_out_at->timestamp)
        ->and((int) $entry->site_id)->toBe($site->id)
        ->and(HrTimeEntry::query()->where('attendance_session_id', $missingSite->id)->exists())->toBeFalse()
        ->and(HrTimeEntry::query()->where('attendance_session_id', $protected->id)->exists())->toBeFalse()
        ->and(HrTimeEntry::query()->where('attendance_session_id', $partialSegment->id)->exists())->toBeTrue()
        ->and(HrTimeEntry::query()->where('attendance_session_id', $completeSegment->id)->exists())->toBeFalse()
        ->and($missingSite->fresh()->site_id)->toBeNull()
        ->and($timesheet->fresh()->getAttributes())->toBe($timesheetBefore);

    Log::shouldHaveReceived('notice')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'HR attendance time-entry retry backfill completed.'
            && $context['projected_count'] === 2
            && $context['skipped_count'] === 3
            && array_keys($context['skipped_sessions']) === [
                $missingSite->id,
                $protected->id,
                $completeSegment->id,
            ]);
});
