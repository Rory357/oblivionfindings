import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const readSource = (path: string) =>
    readFileSync(resolve(process.cwd(), path), 'utf8');

const completeShiftData = readSource(
    'app/Domain/Shifts/Lifecycle/Data/CompleteShiftData.php',
);
const attendanceService = readSource(
    'app/Domain/Hr/Services/AttendanceService.php',
);
const attendanceController = readSource(
    'app/Http/Controllers/AttendanceController.php',
);
const acknowledgementController = attendanceController.slice(
    attendanceController.indexOf('public function acknowledgeHandover('),
);
const wizard = readSource(
    'resources/js/pages/attendance/components/clock-out-wizard.tsx',
);

describe('attendance clock-out governance contracts', () => {
    it('defers routine completion without manufacturing a handover waiver', () => {
        expect(completeShiftData).toContain(
            'public readonly bool $deferCompletionUntilHandoverSubmitted = false',
        );
        expect(completeShiftData).toContain(
            'deferCompletionUntilHandoverSubmitted: ! $forced',
        );
        expect(completeShiftData).toContain(
            "handoverWaiverReason: $forced && $reason !== '' ? $reason : null",
        );
        expect(completeShiftData).not.toContain('clock_out_auto_complete');
    });

    it('makes every critical attendance override audit transaction-fatal', () => {
        for (const action of [
            'attendance.clockOut.forced',
            'attendance.session.adminEnded',
            'attendance.session.corrected',
        ]) {
            expect(attendanceService).toContain(
                `AuditLogger::logOrFail('${action}'`,
            );
        }
    });

    it('authorizes acknowledgement from the current incoming Shift instead of retained staff provenance', () => {
        expect(acknowledgementController).toContain(
            "->whereHas('incomingShift', fn (Builder $query) => $query",
        );
        expect(acknowledgementController).toContain(
            "->where('user_id', $auth->id)",
        );
        expect(acknowledgementController).not.toContain(
            "->where('incoming_staff_id', $auth->id)",
        );
        expect(acknowledgementController).toContain(
            "array_key_exists('incoming_shift_id', $exception->errors())",
        );
    });

    it('tells workers that clock-out saves a draft and only defers a Shift when an incoming Shift is due', () => {
        expect(wizard).toContain('Clock-out saves this as a draft.');
        expect(wizard).toMatch(
            /If an incoming Shift is due,\s+submission unblocks completion but does not\s+complete the Shift automatically/,
        );
        expect(wizard).toContain(
            'Your handover was saved as a draft. If an incoming Shift is due',
        );
        expect(wizard).toContain('complete the Shift separately');
        expect(wizard).toContain('Attendance is already clocked out');
        expect(wizard).not.toContain('No handover needed');
        expect(wizard).not.toContain(
            'waiting for the incoming shift to acknowledge',
        );
    });
});
