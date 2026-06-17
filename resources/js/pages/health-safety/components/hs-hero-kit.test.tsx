import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import { HeroClusterTile, HeroComplianceBadges } from './hs-hero-kit';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...props }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

describe('HeroComplianceBadges — canonical NZ labels', () => {
    it('renders the five canonical labels in the healthy state', () => {
        render(<HeroComplianceBadges worksafeAwaiting={0} sdsExpiring={0} />);

        expect(screen.getByText('WorkSafe notifiable · 0 awaiting')).toBeInTheDocument();
        // The :2021 suffix is part of the canonical wording — analytics was missing it.
        expect(screen.getByText('Ngā Paerewa NZS 8134:2021 · Certified')).toBeInTheDocument();
        // Lower-case "substances".
        expect(screen.getByText('Hazardous substances · SDS current')).toBeInTheDocument();
        expect(screen.getByText('Fire · Drills current')).toBeInTheDocument();
        expect(screen.getByText('First aid · Cover OK')).toBeInTheDocument();
    });

    it('escalates WorkSafe + SDS to warning chrome when counts are non-zero', () => {
        render(<HeroComplianceBadges worksafeAwaiting={2} sdsExpiring={3} />);

        const worksafe = screen.getByText('WorkSafe notifiable · 2 awaiting');
        expect(worksafe).toHaveClass('bg-status-warning/25');
        expect(screen.getByText('Hazardous substances · 3 SDS expiring')).toHaveClass('bg-status-warning/25');
    });

    it('uses one fire-drill threshold: overdue (critical) outranks due (warning)', () => {
        const { rerender } = render(<HeroComplianceBadges worksafeAwaiting={0} sdsExpiring={0} drillsDue={1} />);
        expect(screen.getByText('Fire · 1 drill due')).toHaveClass('bg-status-warning/25');

        rerender(<HeroComplianceBadges worksafeAwaiting={0} sdsExpiring={0} drillsDue={1} drillsOverdue={2} />);
        expect(screen.getByText('Fire · 2 drills overdue')).toHaveClass('bg-status-critical/25');
    });
});

describe('HeroClusterTile — optional delta slot', () => {
    it('renders no delta line by default (dashboard parity)', () => {
        render(<HeroClusterTile label="LTIFR" value="1.2" caption="per M hrs" tone="neutral" />);
        expect(screen.getByText('1.2')).toBeInTheDocument();
        expect(screen.queryByText(/▼/)).not.toBeInTheDocument();
    });

    it('renders a delta line and links when href + delta are supplied (analytics)', () => {
        render(
            <HeroClusterTile
                href="/health-safety/injuries"
                label="LTIFR"
                value="1.2"
                caption="per M hrs"
                tone="neutral"
                delta="▼ 0.3"
                deltaTone="success"
            />,
        );
        const delta = screen.getByText('▼ 0.3');
        expect(delta).toHaveClass('text-status-success');
        expect(screen.getByRole('link')).toHaveAttribute('href', '/health-safety/injuries');
    });
});
