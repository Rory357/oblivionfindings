import { describe, expect, it } from 'vitest';

import { canEditTimesheetRow, needsApprovalBadgeClassName } from './index';

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
                attendance_session_id: 42,
            }),
        ).toBe(false);
        expect(
            canEditTimesheetRow({
                can_edit: true,
                attendance_session_id: null,
            }),
        ).toBe(true);
    });
});
