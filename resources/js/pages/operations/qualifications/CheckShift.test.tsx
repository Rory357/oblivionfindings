import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import CheckShift from './CheckShift';
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props: { workerTimezone: 'Pacific/Auckland' } }),
}));
const base = {
    shift: {
        id: 8,
        starts_at: '2026-10-05T01:00:00Z',
        ends_at: '2026-10-05T09:00:00Z',
        staff: { id: 9, name: 'Aroha' },
        client: { id: 5, first_name: 'Ari', last_name: 'Kauri' },
    },
    allMandatoryMet: false,
};
const requirement = { id: 1, qualification_name: 'First aid' };
describe('Qualification check meaning', () => {
    it('shows a configurable unmapped warning with manager acknowledgement rather than a missing-evidence blocker', () => {
        render(
            <CheckShift
                {...base}
                results={[
                    {
                        requirement,
                        met: false,
                        is_mandatory: true,
                        status: 'unmapped',
                        severity: 'warning',
                        requires_acknowledgement: true,
                        reasons: ['No HR qualification has been linked.'],
                    },
                ]}
            />,
        );
        expect(screen.getByText('Qualification warnings')).toBeVisible();
        expect(screen.getByText('Qualification not linked')).toBeVisible();
        expect(
            screen.getByText(/authorised manager must acknowledge/),
        ).toBeVisible();
        expect(screen.queryByText('Missing')).not.toBeInTheDocument();
        expect(
            screen.getByText(/House coverage and other safety checks/),
        ).toBeVisible();
    });
    it('shows mapped expired evidence as a blocker and does not offer manager acknowledgement', () => {
        render(
            <CheckShift
                {...base}
                results={[
                    {
                        requirement,
                        met: false,
                        is_mandatory: true,
                        status: 'expired',
                        severity: 'block',
                        reasons: ['First aid expired before this duty ends.'],
                    },
                ]}
            />,
        );
        expect(screen.getByText('Expired or no longer valid')).toBeVisible();
        expect(screen.getByText('resolve before assignment')).toBeVisible();
        expect(
            screen.queryByText(/authorised manager must acknowledge/),
        ).not.toBeInTheDocument();
    });
    it('never labels an unassigned or empty check as mandatory requirements met', () => {
        render(
            <CheckShift
                {...base}
                shift={{ ...base.shift, staff: null }}
                allMandatoryMet
                results={[]}
            />,
        );
        expect(screen.getByText('Worker not assigned')).toBeVisible();
        expect(
            screen.queryByText('Mandatory requirements met'),
        ).not.toBeInTheDocument();
    });
});
