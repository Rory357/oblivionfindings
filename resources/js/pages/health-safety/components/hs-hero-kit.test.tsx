import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { HeroClusterTile, HeroComplianceBadges } from './hs-hero-kit';

const mockPage = vi.hoisted(() => ({
    certification_status: 'unknown',
    first_aid_coverage_status: 'unknown',
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        props: {
            nzsAssurance: mockPage,
        },
    }),
}));

describe('HeroComplianceBadges — canonical NZ labels', () => {
    beforeEach(() => {
        mockPage.certification_status = 'unknown';
        mockPage.first_aid_coverage_status = 'unknown';
    });

    it('renders unknown assurance without resolver-backed evidence', () => {
        render(<HeroComplianceBadges worksafeAwaiting={0} sdsExpiring={0} />);

        expect(
            screen.getByText('WorkSafe notifiable · 0 awaiting'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Ngā Paerewa NZS 8134:2021 · Evidence unknown'),
        ).toBeInTheDocument();
        // Lower-case "substances".
        expect(
            screen.getByText('Hazardous substances · SDS current'),
        ).toBeInTheDocument();
        expect(screen.getByText('Fire · Drills current')).toBeInTheDocument();
        expect(screen.getByText('First aid · Cover unknown')).toBeInTheDocument();
    });

    it('retains green claims only for explicit resolver-backed positive states', () => {
        mockPage.certification_status = 'certified';
        mockPage.first_aid_coverage_status = 'certified';
        render(<HeroComplianceBadges />);

        expect(
            screen.getByText('Ngā Paerewa NZS 8134:2021 · Certified'),
        ).toHaveClass('bg-primary-foreground/10');
        expect(screen.getByText('First aid · Cover OK')).toHaveClass(
            'bg-primary-foreground/10',
        );
    });

    it('renders action-required assurance without success chrome', () => {
        mockPage.certification_status = 'action_required';
        mockPage.first_aid_coverage_status = 'action_required';
        render(<HeroComplianceBadges />);

        expect(
            screen.getByText('Ngā Paerewa NZS 8134:2021 · Action required'),
        ).toHaveClass('bg-status-warning/25');
        expect(screen.getByText('First aid · Cover gaps')).toHaveClass(
            'bg-status-warning/25',
        );
    });

    it('escalates WorkSafe + SDS to warning chrome when counts are non-zero', () => {
        render(<HeroComplianceBadges worksafeAwaiting={2} sdsExpiring={3} />);

        const worksafe = screen.getByText('WorkSafe notifiable · 2 awaiting');
        expect(worksafe).toHaveClass('bg-status-warning/25');
        expect(
            screen.getByText('Hazardous substances · 3 SDS expiring'),
        ).toHaveClass('bg-status-warning/25');
    });

    it('uses one fire-drill threshold: overdue (critical) outranks due (warning)', () => {
        const { rerender } = render(
            <HeroComplianceBadges
                worksafeAwaiting={0}
                sdsExpiring={0}
                drillsDue={1}
            />,
        );
        expect(screen.getByText('Fire · 1 drill due')).toHaveClass(
            'bg-status-warning/25',
        );

        rerender(
            <HeroComplianceBadges
                worksafeAwaiting={0}
                sdsExpiring={0}
                drillsDue={1}
                drillsOverdue={2}
            />,
        );
        expect(screen.getByText('Fire · 2 drills overdue')).toHaveClass(
            'bg-status-critical/25',
        );
    });
});

describe('HeroClusterTile — optional delta slot', () => {
    it('renders no delta line by default (dashboard parity)', () => {
        render(
            <HeroClusterTile
                label="LTIFR"
                value="1.2"
                caption="per M hrs"
                tone="neutral"
            />,
        );
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
        expect(screen.getByRole('link')).toHaveAttribute(
            'href',
            '/health-safety/injuries',
        );
    });
});
