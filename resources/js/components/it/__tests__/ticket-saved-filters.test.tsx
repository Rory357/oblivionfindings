import {
    TicketSavedFilters,
    type SavedTicketFilterRow,
} from '@/components/it/ticket-saved-filters';
import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: {
        post: vi.fn(),
        delete: vi.fn(),
    },
}));

const saved: SavedTicketFilterRow[] = [
    { id: 12, name: 'Urgent network tickets' },
];

describe('personal IT ticket filters', () => {
    beforeEach(() => vi.clearAllMocks());

    it('distinguishes personal filters, applies one and keeps delete explicit', () => {
        const onApply = vi.fn();
        render(
            <TicketSavedFilters
                filters={saved}
                activeId={12}
                currentFilters={{
                    ticket_priority: 'urgent',
                    ticket_category: 'network',
                }}
                canSave
                onApply={onApply}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'My saved filters' }),
        ).toBeVisible();
        expect(
            screen.getByText(/rechecked against your current Site access/i),
        ).toBeVisible();

        fireEvent.click(
            screen.getByRole('button', { name: 'Urgent network tickets' }),
        );
        expect(onApply).toHaveBeenCalledWith(12);

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Delete saved filter Urgent network tickets',
            }),
        );
        expect(
            screen.getByRole('heading', {
                name: 'Delete “Urgent network tickets”?',
            }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Delete filter' }));
        expect(router.delete).toHaveBeenCalledWith(
            '/it/ticket-filters/12',
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('saves the current server-backed filters under a user-provided name', () => {
        render(
            <TicketSavedFilters
                filters={[]}
                activeId={null}
                currentFilters={{
                    site_id: 42,
                    ticket_status: 'waiting',
                    open_only: true,
                }}
                canSave
                onApply={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Save current' }));
        fireEvent.change(screen.getByLabelText('Filter name'), {
            target: { value: 'Waiting at North Site' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save filter' }));

        expect(router.post).toHaveBeenCalledWith(
            '/it/ticket-filters',
            {
                name: 'Waiting at North Site',
                filters: {
                    site_id: 42,
                    ticket_status: 'waiting',
                    open_only: true,
                },
            },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('does not offer to save an unfiltered queue', () => {
        render(
            <TicketSavedFilters
                filters={[]}
                activeId={null}
                currentFilters={{}}
                canSave={false}
                onApply={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Save current' }),
        ).toBeDisabled();
        expect(
            screen.getByText('No personal filters saved yet.'),
        ).toBeVisible();
    });
});
