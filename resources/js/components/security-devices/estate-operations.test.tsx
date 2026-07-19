import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    CoverageIndicator,
    OperationalStateBadge,
    formatCoverage,
    operationalHealthLabel,
} from './estate-operations';

describe('Security & Devices estate operation patterns', () => {
    it('uses icon-and-text status labels and never calls unknown healthy', () => {
        render(
            <div>
                <OperationalStateBadge state="critical" />
                <OperationalStateBadge state="warning" />
                <OperationalStateBadge state="unknown" />
                <OperationalStateBadge state="healthy" />
                <OperationalStateBadge state="offline" />
                <OperationalStateBadge state="pending" />
            </div>,
        );

        expect(screen.getByText('Critical')).toBeVisible();
        expect(screen.getByText('Needs attention')).toBeVisible();
        expect(screen.getByText('Unknown')).toBeVisible();
        expect(screen.getByText('Healthy')).toBeVisible();
        expect(screen.getByText('Offline')).toBeVisible();
        expect(screen.getByText('Scheduled')).toBeVisible();
        expect(operationalHealthLabel('not_configured')).toBe('Not configured');
    });

    it('presents measured and unavailable coverage without inventing a percentage', () => {
        expect(formatCoverage(67)).toBe('67% monitored');
        expect(formatCoverage(null)).toBe('Not measured');

        const { rerender } = render(
            <CoverageIndicator percent={67} monitored={2} total={3} />,
        );
        expect(screen.getByText('67% monitored')).toBeVisible();
        expect(screen.getByRole('progressbar')).toHaveAttribute(
            'aria-valuenow',
            '67',
        );

        rerender(<CoverageIndicator percent={null} monitored={0} total={0} />);
        expect(screen.getByText('Not measured')).toBeVisible();
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });
});
