import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ReadingAttention } from './_reading-attention';
import { hubData } from './_test-fixtures';

vi.mock('@inertiajs/react', () => ({ Link: 'a' }));
const optional = {
    id: 1,
    title: 'Pinned social notice',
    content: '',
    priority: 'normal',
    requires_acknowledgement: false,
};

describe('Required reading attention', () => {
    it('uses the authoritative pending notice even outside the capped or searched feed', () => {
        render(
            <ReadingAttention
                home
                data={hubData({
                    announcements: [optional],
                    readingAttention: {
                        pending_count: 12,
                        first: { id: 99, title: 'Read the updated policy' },
                    },
                })}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'Read the updated policy' }),
        ).toHaveAttribute('href', '/company-hub?view=updates&announcement=99');
        expect(screen.getByText('12 to read')).toBeInTheDocument();
        expect(screen.queryByText(optional.title)).not.toBeInTheDocument();
    });
    it('only reports caught up when the server supplies a zero pending count', () => {
        const view = render(<ReadingAttention home data={hubData()} />);
        expect(
            screen.queryByText(/up to date with required reading/),
        ).not.toBeInTheDocument();
        view.rerender(
            <ReadingAttention
                home
                data={hubData({
                    readingAttention: { pending_count: 0, first: null },
                })}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'View updates' }),
        ).toHaveAttribute('href', '/company-hub?view=updates');
        expect(
            screen.getByText(/up to date with required reading/),
        ).toBeInTheDocument();
    });
    it('replaces the notice as current receipts change and never infers confirmation from seen state', () => {
        const data = hubData({
            announcements: [
                optional,
                {
                    ...optional,
                    id: 2,
                    title: 'Required notice',
                    requires_acknowledgement: true,
                    viewer_acknowledged: false,
                },
            ],
        });
        const view = render(<ReadingAttention home={false} data={data} />);
        expect(
            screen.getByRole('link', { name: 'Read and acknowledge' }),
        ).toHaveAttribute('href', '/company-hub?view=updates&announcement=2');
        view.rerender(
            <ReadingAttention
                home={false}
                data={{ ...data, announcements: [optional] }}
            />,
        );
        expect(
            screen.getByRole('link', { name: 'Read notice' }),
        ).toHaveAttribute('href', '/company-hub?view=updates&announcement=1');
    });
    it('does not expose live announcement links in the showcase', () => {
        render(
            <ReadingAttention
                home
                data={hubData({ demo: true, announcements: [optional] })}
            />,
        );
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});
