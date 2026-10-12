import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import JobBoardIndex from './Index';

const mock = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    actor: 5,
    mobile: true,
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: mock.actor } } } }),
    Link: ({ children, ...props }: { children: ReactNode; href: string }) => (
        <a {...props}>{children}</a>
    ),
    router: { get: mock.get, post: mock.post },
}));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => mock.mobile }));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/job-board/smart-matches-strip', () => ({
    SmartMatchesStrip: () => null,
}));

const week = {
    start: '2026-10-05',
    end: '2026-10-11',
    start_label: '5 Oct',
    end_label: '11 Oct',
    prev: '2026-09-28',
    next: '2026-10-12',
    is_current: true,
};
beforeEach(() => {
    mock.get.mockReset();
    mock.post.mockReset();
    mock.actor = 5;
    mock.mobile = true;
});
afterEach(cleanup);

describe('Job Board owning-page search', () => {
    it('retains the real search field when phone details are closed and reopened', () => {
        render(<JobBoardIndex week={week} filters={{ week: week.start }} />);
        const toggle = screen.getByRole('button', {
            name: /Details & filters/,
        });
        fireEvent.click(toggle);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'North House' },
        });
        expect(mock.get).not.toHaveBeenCalled();
        expect(screen.getByRole('status')).toHaveTextContent('Select Search');
        fireEvent.click(toggle);
        fireEvent.click(toggle);
        expect(screen.getByRole('searchbox')).toHaveValue('North House');
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(mock.get).toHaveBeenCalledWith(
            '/operations/job-board',
            { week: week.start, q: 'North House' },
            expect.any(Object),
        );
        expect(screen.getByRole('searchbox')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Next week' }),
        ).toBeDisabled();
        expect(screen.getByRole('status')).toHaveTextContent(
            'Updating results',
        );
        expect(mock.post).not.toHaveBeenCalled();
    });

    it('paginates with the typed search instead of the previous link query', () => {
        mock.mobile = false;
        render(
            <JobBoardIndex
                week={week}
                filters={{ q: 'Old' }}
                jobs={{
                    data: [],
                    current_page: 1,
                    last_page: 2,
                    total: 15,
                    links: [
                        {
                            url: '/operations/job-board?q=Old&page=2',
                            label: '2',
                            active: false,
                        },
                    ],
                }}
            />,
        );
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'North' },
        });
        fireEvent.click(screen.getByRole('button', { name: '2' }));
        expect(mock.get).toHaveBeenCalledWith(
            '/operations/job-board',
            { q: 'North', page: '2' },
            expect.any(Object),
        );
        expect(screen.getByRole('button', { name: '2' })).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Next week' }),
        ).toBeDisabled();
    });

    it('uses the Search form for Enter and retains failed choices visibly for retry', () => {
        mock.mobile = false;
        render(<JobBoardIndex week={week} />);
        const input = screen.getByRole('searchbox');
        fireEvent.change(input, { target: { value: 'Hoist' } });
        fireEvent.submit(input.closest('form')!);
        const read = mock.get.mock.calls[0][2];
        act(() => {
            read.onError({});
            read.onFinish();
        });
        expect(input).toBeEnabled();
        expect(input).toHaveValue('Hoist');
        expect(screen.getByRole('status')).toHaveTextContent(
            'Results were not updated',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(mock.get).toHaveBeenCalledTimes(2);
        expect(mock.post).not.toHaveBeenCalled();
    });

    it('clears the previous account search without letting its response change the new search', () => {
        mock.mobile = false;
        const { rerender } = render(<JobBoardIndex week={week} />);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'Account A' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        const old = mock.get.mock.calls[0][2];
        mock.actor = 8;
        rerender(<JobBoardIndex week={week} />);
        expect(screen.getByRole('searchbox')).toHaveValue('');
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'Account B' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        act(() => {
            old.onError({});
            old.onFinish();
        });
        expect(screen.getByRole('searchbox')).toHaveValue('Account B');
        expect(screen.getByRole('searchbox')).toBeDisabled();
        expect(screen.queryByText(/Results were not updated/)).toBeNull();
    });
});
