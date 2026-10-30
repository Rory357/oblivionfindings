import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GlobalNavSearch from './global-nav-search';

const state = vi.hoisted(() => ({
    page: {
        url: '/company-hub?view=home',
        props: {
            auth: {
                user: { id: 7, role: 'support_worker' },
                can: { companyHub: { view: true } },
            },
        },
    },
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => state.page,
    router: { visit: state.visit },
}));
vi.mock('@/components/app-sidebar', () => ({
    buildNavSearchCatalog: () => [
        {
            id: 'overview',
            section: 'Work',
            label: 'Overview',
            href: '/dashboard',
        },
    ],
}));
const response = (
    results: Array<{
        id: string;
        kind: string;
        title: string;
        description: string;
        url: string;
    }>,
    actor = 7,
) =>
    ({
        ok: true,
        json: async () => ({ actor_id: actor, results }),
    }) as Response;
const person = {
    id: 'person:4',
    kind: 'Person',
    title: 'Compass Colleague',
    description: 'Coordinator',
    url: '/company-hub/people/4',
};
function open() {
    fireEvent.keyDown(window, { key: 'k', ctrlKey: true });
}
beforeEach(() => {
    vi.clearAllMocks();
    state.page = {
        url: '/company-hub?view=home',
        props: {
            auth: {
                user: { id: 7, role: 'support_worker' },
                can: { companyHub: { view: true } },
            },
        },
    };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response([])));
});
afterEach(() => vi.unstubAllGlobals());

describe('Company Hub command search', () => {
    it('uses the existing Ctrl+K palette and opens a Hub destination by arrow and Enter', async () => {
        render(<GlobalNavSearch />);
        open();
        const input = screen.getByRole('combobox', {
            name: 'Search the company',
        });
        expect(
            screen.getByRole('dialog', {
                name: 'Find your way around Company Hub',
            }),
        ).toBeInTheDocument();
        const first = screen.getByRole('option', { name: /Company news/ });
        await waitFor(() =>
            expect(first).toHaveAttribute('data-selected', 'true'),
        );
        fireEvent.keyDown(input, { key: 'ArrowDown' });
        expect(
            screen.getByRole('option', { name: /Find a colleague/ }),
        ).toHaveAttribute('data-selected', 'true');
        fireEvent.keyDown(input, { key: 'Enter' });
        expect(state.visit).toHaveBeenCalledWith('/company-hub?view=people');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('keeps a server skill match visible even when the displayed title does not repeat the query', async () => {
        vi.mocked(fetch).mockResolvedValue(response([person]));
        render(<GlobalNavSearch />);
        open();
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Search the company' }),
            { target: { value: 'Accessible documents' } },
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Searching Company Hub',
        );
        const option = await screen.findByRole('option', {
            name: /Compass Colleague/,
        });
        expect(fetch).toHaveBeenCalledWith(
            '/company-hub/search?query=Accessible%20documents',
            expect.objectContaining({
                credentials: 'same-origin',
                signal: expect.any(AbortSignal),
            }),
        );
        fireEvent.click(option);
        expect(state.visit).toHaveBeenCalledWith('/company-hub/people/4');
    });

    it('does not show a late result for the previous query', async () => {
        let finish!: (value: Response) => void;
        vi.mocked(fetch)
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }),
            )
            .mockResolvedValueOnce(
                response([{ ...person, title: 'New result' }]),
            );
        render(<GlobalNavSearch />);
        open();
        const input = screen.getByRole('combobox', {
            name: 'Search the company',
        });
        fireEvent.change(input, { target: { value: 'Old query' } });
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        fireEvent.change(input, { target: { value: 'New query' } });
        expect(
            await screen.findByRole('option', { name: /New result/ }),
        ).toBeInTheDocument();
        await act(async () => finish(response([person])));
        expect(
            screen.queryByRole('option', { name: /Compass Colleague/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: /New result/ }),
        ).toBeInTheDocument();
    });

    it('rejects a response from another signed-in account and provides a real retry', async () => {
        vi.mocked(fetch)
            .mockResolvedValueOnce(response([person], 99))
            .mockResolvedValueOnce(response([person]));
        render(<GlobalNavSearch />);
        open();
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Search the company' }),
            { target: { value: 'Compass' } },
        );
        expect(await screen.findByRole('alert')).toHaveTextContent(
            'Company Hub search is unavailable',
        );
        expect(
            screen.queryByRole('option', { name: /Compass Colleague/ }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Retry Hub search' }),
        );
        expect(
            await screen.findByRole('option', { name: /Compass Colleague/ }),
        ).toBeInTheDocument();
    });

    it('clears pending results when the account changes and never applies the old reply', async () => {
        let finish!: (value: Response) => void;
        vi.mocked(fetch)
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finish = resolve;
                    }),
            )
            .mockResolvedValueOnce(response([], 8));
        const { rerender } = render(<GlobalNavSearch />);
        open();
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Search the company' }),
            { target: { value: 'Compass' } },
        );
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        state.page.props.auth.user.id = 8;
        rerender(<GlobalNavSearch />);
        await act(async () => finish(response([person])));
        expect(
            screen.queryByRole('option', { name: /Compass Colleague/ }),
        ).not.toBeInTheDocument();
        expect(
            await screen.findByText(
                'No matching Hub content, colleagues or services.',
            ),
        ).toBeInTheDocument();
    });

    it.each([
        '/dashboard',
        '/company-hub/manage/1/edit',
        '/company-hub/showcase',
    ])('keeps existing application navigation on %s', (url) => {
        state.page.url = url;
        render(<GlobalNavSearch />);
        open();
        expect(
            screen.getByRole('dialog', { name: 'Search modules and pages' }),
        ).toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Search modules and pages' }),
            { target: { value: 'Overview' } },
        );
        fireEvent.click(screen.getByRole('option', { name: 'Overview' }));
        expect(state.visit).toHaveBeenCalledWith('/dashboard');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('offers the full search view for an empty match without inventing results', async () => {
        render(<GlobalNavSearch />);
        open();
        fireEvent.change(
            screen.getByRole('combobox', { name: 'Search the company' }),
            { target: { value: 'A & B' } },
        );
        expect(
            await screen.findByText(
                'No matching Hub content, colleagues or services.',
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('option', {
                name: 'See all Hub results for “A & B”',
            }),
        );
        expect(state.visit).toHaveBeenCalledWith(
            '/company-hub?view=home&search=A%20%26%20B',
        );
    });
});
