import { describe, expect, it } from 'vitest';

import {
    canEditTimesheetRow,
    formatTimesheetTime,
    menuItemsFor,
    needsApprovalBadgeClassName,
    type TimesheetRow,
} from './index';

describe('timesheets index presentation helpers', () => {
    it('uses the readable warning background for needs-approval badges', () => {
        expect(needsApprovalBadgeClassName).toContain('bg-status-warning-bg');
        expect(needsApprovalBadgeClassName.split(/\s+/)).not.toContain(
            'bg-status-warning',
        );
    });

    it('never exposes generic editing for attendance-backed rows', () => {
        expect(
            canEditTimesheetRow({
                can_edit: true,
                can_update: true,
                attendance_session_id: 42,
            }),
        ).toBe(false);
        expect(
            canEditTimesheetRow({
                can_edit: true,
                can_update: true,
                attendance_session_id: null,
            }),
        ).toBe(true);
    });
});

const row: TimesheetRow = {
    id: 1,
    work_date: '2026-10-05',
    starts_at: '2026-10-05T00:00:00Z',
    ends_at: '2026-10-05T01:00:00Z',
    break_minutes: 0,
    status: 'submitted',
    can_mutate: true,
    can_edit: true,
    can_update: true,
    staff: { id: 7, name: 'Hemi' },
};
it('does not expose review actions without the exact record review capability', () => {
    expect(
        menuItemsFor({ ...row, can_approve: false }).map((item) => item.id),
    ).not.toContain('approve');
    expect(
        menuItemsFor({
            ...row,
            can_approve: true,
            can_return: true,
            can_reject: true,
        }).map((item) => item.id),
    ).toEqual(expect.arrayContaining(['approve', 'return', 'reject']));
});
it('uses only the permitted canonical HR link and hides unavailable source links', () => {
    expect(menuItemsFor(row).map((item) => item.id)).not.toEqual(
        expect.arrayContaining(['staff', 'shift', 'client']),
    );
    expect(
        menuItemsFor({ ...row, staff_profile_url: '/hr/employees/42' }).map(
            (item) => item.id,
        ),
    ).toContain('staff');
});
it('never offers unimplemented export, reassignment or payment actions as working buttons', () => {
    for (const status of [
        'draft',
        'returned',
        'submitted',
        'approved',
        'paid',
        'rejected',
        'archived',
    ]) {
        const ids = menuItemsFor({
            ...row,
            status,
            can_approve: true,
            can_return: true,
            can_reject: true,
        }).map((item) => item.id);
        for (const id of [
            'pdf',
            'duplicate',
            'reassign',
            'payroll',
            'payslip',
            'discard',
            'correction',
            'recreate',
        ])
            expect(ids).not.toContain(id);
    }
});

it('uses the explicitly labelled worker timezone across midnight', () => {
    expect(
        formatTimesheetTime('2026-10-05T01:00:00Z', 'America/New_York'),
    ).toContain('9:00 pm');
    expect(
        formatTimesheetTime('2026-10-05T01:00:00Z', 'Pacific/Auckland'),
    ).toContain('2:00 pm');
    expect(formatTimesheetTime('bad', 'Pacific/Auckland')).toBe('—');
});

it('does not let broad record mutation imply the exact update or submit grant', () => {
    const draft = {
        ...row,
        status: 'draft',
        can_edit: true,
        can_update: false,
        can_submit: false,
    };
    expect(canEditTimesheetRow(draft)).toBe(false);
    expect(menuItemsFor(draft).map((item) => item.id)).not.toEqual(
        expect.arrayContaining(['edit', 'submit']),
    );
    expect(
        menuItemsFor({ ...draft, can_update: true, can_submit: true }).map(
            (item) => item.id,
        ),
    ).toEqual(expect.arrayContaining(['edit', 'submit']));
});
