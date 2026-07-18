import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { EscalationQueueFilters } from './escalation-queue-filters';

describe('EscalationQueueFilters', () => {
    it('explains extreme overload without clipping or hiding the real count', () => {
        render(
            <EscalationQueueFilters
                queues={[
                    {
                        id: 3,
                        name: 'Emergency',
                        code: 'emergency',
                        tier: 3,
                        description: null,
                        alert_count: 1639,
                        breached_count: 317,
                        capacity: 20,
                        utilization_percent: 8195,
                        pressure_label: 'Severe overload',
                        capacity_explanation:
                            '20-alert operational display threshold; alerts remain paginated and no work is hidden.',
                    },
                ]}
                activeQueueId={null}
                totalAlerts={1639}
                hasFilters={false}
                onSelect={() => undefined}
                onClear={() => undefined}
            />,
        );

        expect(screen.getByText('1639/20')).toBeInTheDocument();
        expect(screen.getByText(/Severe overload · 8195%/)).toBeInTheDocument();
        expect(screen.getByTestId('escalation-queue-filters')).toHaveClass(
            'overflow-x-auto',
        );
        expect(
            screen.getByRole('button', { name: /Emergency/ }),
        ).toHaveAttribute(
            'title',
            expect.stringContaining('no work is hidden'),
        );
    });

    it('exposes pressed-state queue selection and a clear path', () => {
        const onSelect = vi.fn();
        const onClear = vi.fn();
        render(
            <EscalationQueueFilters
                queues={[
                    {
                        id: 2,
                        name: 'Urgent',
                        code: 'urgent',
                        tier: 2,
                        description: null,
                        alert_count: 8,
                        breached_count: 1,
                        capacity: 20,
                        utilization_percent: 40,
                        pressure_label: 'Available',
                        capacity_explanation: 'Capacity explanation',
                    },
                ]}
                activeQueueId="2"
                totalAlerts={8}
                hasFilters
                onSelect={onSelect}
                onClear={onClear}
            />,
        );

        expect(screen.getByRole('button', { name: /Urgent/ })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        fireEvent.click(screen.getByRole('button', { name: /All queues.*8/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(onSelect).toHaveBeenCalledWith(null);
        expect(onClear).toHaveBeenCalledOnce();
    });
});
