import { describe, expect, it } from 'vitest';

import { needsApprovalBadgeClassName } from './index';

describe('timesheets index presentation helpers', () => {
    it('uses the readable warning background for needs-approval badges', () => {
        expect(needsApprovalBadgeClassName).toContain('bg-status-warning-bg');
        expect(needsApprovalBadgeClassName.split(/\s+/)).not.toContain(
            'bg-status-warning',
        );
    });
});
