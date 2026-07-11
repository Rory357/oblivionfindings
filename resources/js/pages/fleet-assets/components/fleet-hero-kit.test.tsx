import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import { Plus } from 'lucide-react';
import { FleetComplianceBadges, FleetHeroAction } from './fleet-hero-kit';

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
}));

const complianceHrefs = {
    wof: '/fleet-assets/compliance',
    rego: '/fleet-assets/compliance',
    cof: '/fleet-assets/compliance',
    insurance: '/fleet-assets/compliance',
};

describe('FleetComplianceBadges', () => {
    it('lets expired documents outrank due-soon counts with critical wording', () => {
        render(
            <FleetComplianceBadges
                wofDue={4}
                wofExpired={1}
                regoDue={4}
                regoExpired={2}
                cofDue={4}
                cofExpired={3}
                insuranceExpiring={4}
                insuranceExpired={5}
                hrefs={complianceHrefs}
            />,
        );

        for (const label of [
            'WOF · 1 expired',
            'Rego · 2 expired',
            'CoF · 3 expired',
            'Insurance · 5 expired',
        ]) {
            const chip = screen.getByText(label);
            expect(chip).toHaveClass('bg-status-critical/25');
            expect(chip.closest('a')).toHaveAttribute(
                'href',
                '/fleet-assets/compliance',
            );
        }
    });

    it('uses warning chrome for future due-soon documents', () => {
        render(
            <FleetComplianceBadges
                wofDue={1}
                regoDue={2}
                cofDue={3}
                insuranceExpiring={4}
            />,
        );

        for (const label of [
            'WOF · 1 due 30d',
            'Rego · 2 due 30d',
            'CoF · 3 due 30d',
            'Insurance · 4 expiring',
        ]) {
            expect(screen.getByText(label)).toHaveClass('bg-status-warning/25');
        }
    });

    it('renders healthy documents as current and hides unsupported insurance', () => {
        render(
            <FleetComplianceBadges
                insuranceExpiring={null}
                insuranceExpired={null}
            />,
        );

        expect(screen.getByText('WOF · Current')).toBeInTheDocument();
        expect(screen.getByText('Rego · Current')).toBeInTheDocument();
        expect(screen.getByText('CoF · Current')).toBeInTheDocument();
        expect(screen.queryByText(/Insurance/)).not.toBeInTheDocument();
    });
});

describe('FleetHeroAction', () => {
    it('uses the shared emphasis chrome for a real button action', () => {
        const onClick = vi.fn();

        render(
            <FleetHeroAction icon={Plus} emphasis onClick={onClick}>
                New claim
            </FleetHeroAction>,
        );

        const action = screen.getByRole('button', { name: 'New claim' });
        expect(action).toHaveAttribute('type', 'button');
        expect(action).toHaveClass('bg-primary-foreground', 'font-extrabold');

        fireEvent.click(action);
        expect(onClick).toHaveBeenCalledOnce();
    });
});
