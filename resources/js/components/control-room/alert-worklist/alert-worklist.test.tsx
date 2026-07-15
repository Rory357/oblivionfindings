import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { AlertWorklist } from './alert-worklist';
import type { AlertWorklistRow } from './types';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: vi.fn() },
}));

const row: AlertWorklistRow = {
    id: 31,
    reference_number: 'CR-2026-0031',
    summary: 'Missed welfare check',
    source: { key: 'manual', label: 'Manual' },
    status: 'open',
    severity: 'critical',
    priority: { level: 'critical', rank: 0, reason: 'Response SLA breached' },
    playbook: {
        name: 'Welfare response',
        status: 'in_progress',
        completed_steps: 1,
        total_steps: 3,
    },
    triggered_at: '2026-07-15T08:00:00Z',
    next_deadline_at: '2026-07-15T08:15:00Z',
    sla: { status: 'breached', next_deadline_at: '2026-07-15T08:15:00Z' },
    site: { id: 2, name: 'Kōwhai House' },
    person: { id: 4, name: 'Aroha Ngata' },
    assignee: null,
    queue: { id: 1, name: 'Immediate response' },
    journey: {
        incident_reference: null,
        health_safety_reference: null,
        handover_status: null,
    },
    next_action: {
        label: 'Continue response',
        href: '/control-room/alerts/31',
    },
    href: '/control-room/alerts/31',
};

afterEach(cleanup);

describe('canonical alert worklist', () => {
    it('names selection and sorting controls and shows readable SLA/playbook context', () => {
        const onSelectionChange = vi.fn();
        const onSort = vi.fn();
        render(
            <AlertWorklist
                rows={[row]}
                selected={new Set()}
                onSelectionChange={onSelectionChange}
                onSort={onSort}
                onOpen={vi.fn()}
            />,
        );

        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Select CR-2026-0031' }),
        );
        expect(onSelectionChange).toHaveBeenCalledWith(new Set([31]));
        fireEvent.click(
            screen.getByRole('button', { name: 'Sort by severity' }),
        );
        expect(onSort).toHaveBeenCalledWith('severity');
        expect(screen.getByText('Response SLA breached')).toBeInTheDocument();
        expect(screen.getByText(/Welfare response/)).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Continue response for CR-2026-0031',
            }),
        ).toBeInTheDocument();
    });
});
