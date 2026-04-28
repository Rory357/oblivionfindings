import { describe, expect, it } from 'vitest';

import {
    index as attendanceIndex,
    clockIn,
    clockOut,
} from '@/routes/attendance';
import {
    end as endBreak,
    start as startBreak,
} from '@/routes/attendance/break';
import { index as rosteringIndex } from '@/routes/operations/rostering';
import {
    create as createShift,
    edit as editShift,
    index as shiftsIndex,
    show as showShift,
} from '@/routes/operations/shifts';
import {
    create as createTimesheet,
    edit as editTimesheet,
    approvals as timesheetApprovals,
    index as timesheetsIndex,
} from '@/routes/operations/timesheets';

describe('shifts frontend canonical routes', () => {
    it('resolves manager shift and timesheet surfaces under operations', () => {
        expect(shiftsIndex.url()).toBe('/operations/shifts');
        expect(createShift.url()).toBe('/operations/shifts/create');
        expect(showShift.url(123)).toBe('/operations/shifts/123');
        expect(editShift.url(123)).toBe('/operations/shifts/123/edit');

        expect(rosteringIndex.url()).toBe('/operations/rostering');

        expect(timesheetsIndex.url()).toBe('/operations/timesheets');
        expect(timesheetApprovals.url()).toBe(
            '/operations/timesheets/approvals',
        );
        expect(createTimesheet.url({ query: { shift_id: 123 } })).toBe(
            '/operations/timesheets/create?shift_id=123',
        );
        expect(editTimesheet.url(456)).toBe('/operations/timesheets/456/edit');
    });

    it('keeps attendance endpoints in their frontline namespace', () => {
        expect(attendanceIndex.url()).toBe('/attendance');
        expect(clockIn.url()).toBe('/attendance/clock-in');
        expect(clockOut.url()).toBe('/attendance/clock-out');
        expect(startBreak.url()).toBe('/attendance/break/start');
        expect(endBreak.url()).toBe('/attendance/break/end');
    });
});
