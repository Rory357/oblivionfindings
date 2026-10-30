<?php

namespace Tests\Support;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

final class FrontlineFixtureResetDiagnostic
{
    public static function assertDisposableContext(string $environment, string $connection, string $database, string $url): void
    {
        $private = preg_match('/\Aoblivion_findings_codex_test_[a-z0-9_]+_[1-9]\d*\z/', $database) === 1;
        if (! in_array($environment, ['local', 'testing'], true)
            || $connection !== 'mysql'
            || strlen($database) > 64
            || (! $private && $database !== 'oblivion_findings_visual')
            || $url !== '') {
            throw new \LogicException('Frontline fixture diagnostic requires an exact disposable local or testing database.');
        }
    }

    /** @return array<string, mixed> */
    public static function describe(Throwable $exception): array
    {
        $result = ['ok' => false, 'exception_class' => $exception::class];
        if ($exception instanceof QueryException || $exception instanceof PDOException) {
            $info = $exception->errorInfo;
            if (is_array($info)) {
                if (is_string($info[0] ?? null) && preg_match('/\A[A-Z0-9]{5}\z/', $info[0])) {
                    $result['sqlstate'] = $info[0];
                }
                if (is_int($info[1] ?? null)) {
                    $result['driver_code'] = $info[1];
                }
            }
            if (preg_match('/CONSTRAINT[\s\x60\'"]+([A-Za-z_][A-Za-z0-9_]{0,100})/i', $exception->getMessage(), $matches)) {
                $result['constraint'] = $matches[1];
            }
        }
        if ($exception instanceof ValidationException) {
            $result['validation_fields'] = array_values(array_filter(
                array_keys($exception->errors()),
                static fn ($key): bool => in_array($key, [
                    'attendance', 'shift', 'timesheet', 'break_minutes', 'handover',
                    'handover_notes', 'client_id', 'site_id', 'shift_id', 'session_id',
                    'clock_out', 'medication', 'order', 'approval',
                ], true),
            ));
        }
        foreach ([
            'Attendance sessions for the same staff member cannot overlap.' => 'overlapping_attendance',
            'Attendance-backed time-entry clock-in provenance conflicts.' => 'attendance_clock_identity_conflict',
            'This worker already has an overlapping time entry.' => 'overlapping_time_entry',
            'The linked timesheet has already been approved' => 'protected_attendance_timesheet',
            'An approved time entry cannot be changed through attendance.' => 'protected_attendance_entry',
            'The submitted attendance fixture has conflicting canonical identity.' => 'attendance_fixture_identity_conflict',
            'The active clean attendance fixture has conflicting canonical identity.' => 'attendance_fixture_identity_conflict',
            'Approved or payroll-linked timesheets are immutable.' => 'immutable_timesheet',
            'Medication browser fixture reset cannot erase ' => 'retained_medication_evidence',
            'Frontline fixture diagnostic requires an exact disposable' => 'unsafe_database_context',
        ] as $text => $reason) {
            if (str_contains($exception->getMessage(), $text)) {
                $result['reason'] = $reason;
                break;
            }
        }

        if ($exception instanceof \LogicException) {
            $file = basename(str_replace('\\', '/', $exception->getFile()));
            if (in_array($file, [
                'FrontlineLifecycleDemoSeeder.php', 'AttendanceTimeEntryProjector.php',
                'AttendanceService.php', 'ShiftHandoverService.php', 'Timesheet.php',
            ], true)) {
                $result['source'] = ['file' => $file, 'line' => $exception->getLine()];
            }
        }

        return $result;
    }
}
