import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { HistorySection } from './history';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

function serve(body: unknown) {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({ ok: true, json: async () => body }),
    );
}

const page = (current: number, last: number) => ({
    data: [],
    current_page: current,
    last_page: last,
    total: 0,
    from: null,
    to: null,
    links: [
        {
            url: current > 1 ? `/x?page=${current - 1}` : null,
            label: '&laquo; Previous',
            active: false,
        },
        ...Array.from({ length: last }, (_, i) => ({
            url: `/x?page=${i + 1}`,
            label: String(i + 1),
            active: i + 1 === current,
        })),
        {
            url: current < last ? `/x?page=${current + 1}` : null,
            label: 'Next &raquo;',
            active: false,
        },
    ],
});

describe('record History, empty and paging (1440 walk)', () => {
    it('shows an empty state for the sub-tab and no pager on one page', async () => {
        serve({ page: page(1, 1), can_correct: false });
        render(
            <HistorySection
                clientId={7}
                view="doses"
                page={1}
                onPage={vi.fn()}
            />,
        );
        expect(
            await screen.findByText('No doses in this period'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Try a longer date range or clear the outcome filter.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('No records in this view.')).toBeNull();
        expect(screen.queryByRole('button', { name: /Previous/ })).toBeNull();
        expect(screen.queryByRole('button', { name: /Next/ })).toBeNull();
        expect(screen.queryByText(/Page 1 of 1/)).toBeNull();
    });

    it('names the empty sub-tab', async () => {
        serve({ page: page(1, 1), can_correct: false });
        const { rerender } = render(
            <HistorySection
                clientId={7}
                view="corrections"
                page={1}
                onPage={vi.fn()}
            />,
        );
        expect(
            await screen.findByText('No corrections in this period'),
        ).toBeInTheDocument();
        rerender(
            <HistorySection
                clientId={7}
                view="changes"
                page={1}
                onPage={vi.fn()}
            />,
        );
        expect(
            await screen.findByText('No changes in this period'),
        ).toBeInTheDocument();
    });

    it('pages with the shared paginator', async () => {
        serve({ page: page(1, 3), can_correct: false });
        const onPage = vi.fn();
        render(
            <HistorySection
                clientId={7}
                view="doses"
                page={1}
                onPage={onPage}
            />,
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Next page' }),
        );
        expect(onPage).toHaveBeenCalledWith(2);
        fireEvent.click(screen.getByRole('button', { name: '3' }));
        expect(onPage).toHaveBeenCalledWith(3);
    });
});
