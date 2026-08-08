import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import TimesheetStatusBadge from './timesheet-status-badge';

describe('TimesheetStatusBadge', () => {
    it('renders submitted status with a readable warning background token', () => {
        render(<TimesheetStatusBadge status="submitted" showIcon />);

        const badge = screen
            .getByText('Submitted')
            .closest('[data-slot="badge"]');

        expect(badge).toHaveClass('bg-status-warning-bg');
        expect(badge).toHaveClass('text-status-warning');
        expect(badge).not.toHaveClass('bg-status-warning');
    });

    it('renders terminal statuses with matching readable background tokens', () => {
        const { rerender } = render(<TimesheetStatusBadge status="approved" />);

        expect(
            screen.getByText('Approved').closest('[data-slot="badge"]'),
        ).toHaveClass('bg-status-success-bg');

        rerender(<TimesheetStatusBadge status="rejected" />);

        expect(
            screen.getByText('Rejected').closest('[data-slot="badge"]'),
        ).toHaveClass('bg-status-critical-bg');
    });
});
