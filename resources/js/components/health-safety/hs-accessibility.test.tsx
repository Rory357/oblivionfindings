import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { PaneLink, RecordProgress } from '@/components/signature/record-progress';
import { RiskMap } from '@/components/signature/blocks';
import { FocusDonut } from '@/pages/health-safety/analytics-charts';
import { EventTable, type EventRow } from '@/pages/health-safety/events/index';

describe('Health & Safety accessibility regressions', () => {
    it('keeps <dl> groups in RecordProgress strictly composed of <dt> and <dd> children', () => {
        const { container } = render(
            <RecordProgress
                next={{ title: 'Check the completed action', text: 'Someone independent verifies the fix.' }}
                pane={{
                    title: 'Step 1 — Reported',
                    items: [
                        { key: 'what', label: 'What happened', value: 'Slipped near laundry doorway.' },
                        {
                            key: 'owner',
                            label: 'Owner',
                            value: 'Jamie Taylor',
                            action: <PaneLink onClick={() => undefined}>Change</PaneLink>,
                        },
                    ],
                }}
            />,
        );

        const dl = container.querySelector('dl.sg-pane-items');
        expect(dl).not.toBeNull();
        const groups = Array.from(dl!.children);
        expect(groups).toHaveLength(2);

        for (const group of groups) {
            expect(group.tagName).toBe('DIV');
            const childTags = Array.from(group.children).map((el) => el.tagName);
            expect(childTags.every((tag) => tag === 'DT' || tag === 'DD')).toBe(true);
        }
    });

    it('wraps RiskMap rowheaders, cells, and columnheaders inside role="row" elements', () => {
        render(
            <RiskMap
                title="Risk map"
                cells={[
                    [0, 1, 0, 0, 0],
                    [0, 0, 2, 0, 0],
                ]}
            />,
        );

        const table = screen.getByRole('table', { name: 'Risk map: likelihood by consequence' });
        const tableChildren = Array.from(table.children);
        expect(tableChildren.length).toBeGreaterThanOrEqual(6);
        for (const row of tableChildren) {
            expect(row.getAttribute('role')).toBe('row');
        }

        const rows = within(table).getAllByRole('row');
        expect(rows).toHaveLength(6);
        expect(within(rows[0]!).getByRole('rowheader', { name: 'Almost certain' })).toBeInTheDocument();
        expect(within(rows[5]!).getByRole('columnheader', { name: 'Catastrophic' })).toBeInTheDocument();
    });

    it('keeps FocusDonut aria-hidden chart container free of tabbable SVG elements while exposing segment buttons', () => {
        const { container } = render(
            <FocusDonut
                segments={[
                    { key: 'open', label: 'Open', value: 4, color: 'var(--chart-1)' },
                    { key: 'closed', label: 'Closed', value: 6, color: 'var(--chart-2)' },
                ]}
            />,
        );

        const hiddenChart = container.querySelector('[aria-hidden="true"]');
        expect(hiddenChart).not.toBeNull();
        expect(hiddenChart!.querySelector('[tabindex="0"]')).toBeNull();

        expect(screen.getByRole('button', { name: /open\s*4\s*40%/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /closed\s*6\s*60%/i })).toBeInTheDocument();
    });

    it('uses mode-aware status foreground utilities instead of unlayered text-caption on tinted safety review rows and supports keyboard activation', () => {
        const onOpen = vi.fn();
        const blockedRow: EventRow = {
            id: 57,
            reference_number: 'HSE-2026-9906',
            event_category: 'near_miss',
            severity: 'low',
            status: 'monitoring',
            occurred_at: '2026-11-01T21:15:00Z',
            reported_at: '2026-11-01T21:20:00Z',
            site_name: 'Kauri House',
            client_name: null,
            staff_name: 'Aroha Ngata',
            worksafe_notifiable: false,
            worksafe_status: null,
            worksafe_decision_signed: true,
            handover: {
                status: 'accepted',
                owner: null,
                accepted_by: null,
                accepted_at: null,
            },
            investigation_required: false,
            source: null,
            flags: {
                investigation_overdue: false,
                awaiting_verification: 0,
                worksafe_pending: false,
                unwired: false,
            },
            has_investigation: false,
            has_open_actions: false,
            summary: 'Ready to close',
            next: null,
            blocked: 'Waiting for independent sign-off',
        };

        render(<EventTable rows={[blockedRow]} onRowCtx={() => undefined} onOpen={onOpen} />);

        const row = screen.getByRole('row', { name: 'Open safety review HSE-2026-9906' });
        expect(row.querySelector('.text-caption')).toBeNull();
        expect(within(row).getByText('Waiting for independent sign-off').className).toContain(
            'dark:text-status-info-foreground/80',
        );

        fireEvent.keyDown(row, { key: 'Enter' });
        expect(onOpen).toHaveBeenCalledWith(57);
    });
});
