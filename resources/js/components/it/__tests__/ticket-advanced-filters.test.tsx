import { TicketAdvancedFilters } from '@/components/it/ticket-advanced-filters';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

describe('IT ticket advanced filters', () => {
    it('makes the server-backed classification queue-health and outcome filters understandable', () => {
        const onChange = vi.fn();
        const onClear = vi.fn();

        render(
            <TicketAdvancedFilters
                values={{
                    source: 'system',
                    workType: 'incident',
                    service: 12,
                    age: null,
                    missing: 'assignee',
                    reopened: true,
                    firstContact: false,
                    openOnly: false,
                    deviceLinked: false,
                    resolvedFrom: null,
                    resolvedTo: null,
                }}
                services={[{ id: 12, name: 'Site connectivity' }]}
                onChange={onChange}
                onClear={onClear}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: 'More ticket filters, 5 active',
            }),
        );

        expect(screen.getByText('Classification')).toBeVisible();
        expect(screen.getByText('Queue health')).toBeVisible();
        expect(screen.getByText('Outcomes')).toBeVisible();
        expect(screen.getByLabelText('Filter by work type')).toBeVisible();
        expect(
            screen.getByLabelText('Filter by affected service'),
        ).toBeVisible();
        expect(screen.getByLabelText('Filter by ticket source')).toBeVisible();
        expect(screen.getByLabelText('Filter by ticket age')).toBeVisible();
        expect(
            screen.getByLabelText('Filter by missing ownership'),
        ).toBeVisible();

        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Linked to a Device' }),
        );
        expect(onChange).toHaveBeenCalledWith('device_linked', '1');

        fireEvent.change(screen.getByLabelText('Resolved from'), {
            target: { value: '2026-07-01' },
        });
        expect(onChange).toHaveBeenCalledWith('resolved_from', '2026-07-01');

        fireEvent.click(
            screen.getByRole('button', { name: 'Clear more filters' }),
        );
        expect(onClear).toHaveBeenCalledOnce();
    });
});
