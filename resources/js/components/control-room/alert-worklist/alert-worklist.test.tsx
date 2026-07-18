import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { Copy, Eye } from 'lucide-react';
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
    actions: {
        can_claim: true,
        can_acknowledge: true,
        can_move_queue: true,
        can_escalate: true,
        can_create_incident: true,
        can_snooze: true,
        can_unsnooze: false,
        can_copy_reference: true,
        incident_href: null,
        health_safety_href: null,
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

    it('uses one responsive row that becomes a real mobile card without a nested scroller', () => {
        render(
            <AlertWorklist
                rows={[row]}
                selected={new Set()}
                onSelectionChange={vi.fn()}
                onSort={vi.fn()}
                onOpen={vi.fn()}
                getActions={() => [
                    {
                        key: 'open',
                        label: 'Open alert',
                        onSelect: vi.fn(),
                    },
                ]}
            />,
        );

        const worklist = screen.getByRole('region', {
            name: 'Actionable alerts',
        });
        expect(worklist).not.toHaveClass('overflow-x-auto');
        expect(screen.getByTestId('alert-worklist-row')).toHaveClass(
            'grid-cols-[auto_minmax(0,1fr)_auto]',
        );
        expect(screen.getByTestId('alert-worklist-row')).toHaveClass(
            'md:grid-cols-[2.5rem_minmax(0,2fr)_minmax(14rem,1fr)_minmax(12rem,0.8fr)_auto]',
        );
        expect(
            screen.getByRole('button', {
                name: 'Actions for CR-2026-0031',
            }),
        ).toHaveClass('min-h-11');
    });

    it('keeps an empty queue inside the bounded worklist with useful recovery copy', () => {
        render(
            <AlertWorklist
                rows={[]}
                selected={new Set()}
                onSelectionChange={vi.fn()}
                onSort={vi.fn()}
                onOpen={vi.fn()}
                heading="Escalation worklist"
                allowSorting={false}
            />,
        );

        expect(
            screen.getByRole('region', { name: 'Escalation worklist' }),
        ).toBeInTheDocument();
        expect(screen.getByText('No alerts in this view')).toBeInTheDocument();
        expect(screen.getByText(/clear the filters/i)).toBeInTheDocument();
    });

    it('exposes identical permission-filtered actions by right click and overflow', async () => {
        render(
            <AlertWorklist
                rows={[row]}
                selected={new Set()}
                onSelectionChange={vi.fn()}
                onSort={vi.fn()}
                onOpen={vi.fn()}
                getActions={() => [
                    {
                        key: 'open',
                        label: 'Open alert',
                        icon: Eye,
                        onSelect: vi.fn(),
                    },
                    {
                        key: 'copy',
                        label: 'Copy reference',
                        icon: Copy,
                        onSelect: vi.fn(),
                    },
                ]}
            />,
        );

        fireEvent.contextMenu(screen.getByTestId('alert-worklist-row'), {
            clientX: 180,
            clientY: 120,
        });
        expect(
            await screen.findByRole('menuitem', { name: 'Open alert' }),
        ).toBeInTheDocument();
        fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' });
        await waitFor(() =>
            expect(screen.queryByRole('menu')).not.toBeInTheDocument(),
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Actions for CR-2026-0031',
            }),
        );
        expect(
            await screen.findByRole('menuitem', { name: 'Copy reference' }),
        ).toBeInTheDocument();
    });
});
