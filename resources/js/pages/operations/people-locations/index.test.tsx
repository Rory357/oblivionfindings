import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PeopleLocations from './index';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: { children: ReactNode }) => <span>{children}</span>,
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        url: '/operations/people-locations/people?peopleView=list',
    }),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/people-locations/people-map', () => ({
    default: () => null,
}));
vi.mock('@/components/people-locations/analytics', () => ({
    default: () => null,
}));
vi.mock('@/components/page', () => ({
    PageHeader: ({ actions }: { actions: ReactNode }) => <>{actions}</>,
    PageHeaderSearch: () => null,
    PageHeaderFilterSelect: () => null,
    PageHeaderMeterBig: () => null,
    PageHeaderMeterBlock: () => null,
    PageHeaderMeterCaption: () => null,
    PageHeaderRail: () => null,
    PageHeaderViewToggle: () => null,
}));
const snapshot = {
    people: [
        {
            id: 'c1',
            name: 'Permitted Maia',
            kind: 'client',
            site: { id: 1, name: 'Harbour' },
            sources: [],
            authority: 'Current',
            reference: 'Client 1',
            position: null,
            positionState: 'unknown',
            battery: null,
            power: 'unknown',
            motion: 'unknown',
            profileUrl: '/operations/clients/1',
        },
    ],
    sites: [],
    alerts: [],
    checkedAt: '2026-09-27T00:00:00Z',
    filters: { selected: '', source: '', date: '2026-09-27' },
    history: null,
    canReadAlerts: false,
    canExport: false,
};
const response = (status = 200) => ({
    ok: status === 200,
    status,
    redirected: false,
    json: async () => snapshot,
});
const fetchMock = vi.fn();
beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
    Object.defineProperty(document, 'hidden', {
        configurable: true,
        value: false,
    });
});
afterEach(() => vi.unstubAllGlobals());
describe('People Locations privacy lifecycle', () => {
    it('shows identities only after a current access response and rechecks on a fresh mount', async () => {
        let resolve!: (value: unknown) => void;
        fetchMock.mockReturnValueOnce(
            new Promise((r) => {
                resolve = r;
            }),
        );
        const first = render(<PeopleLocations view="people" />);
        expect(screen.queryByText('Permitted Maia')).not.toBeInTheDocument();
        await act(async () => resolve(response()));
        expect(await screen.findByText('Permitted Maia')).toBeInTheDocument();
        first.unmount();
        fetchMock.mockReturnValueOnce(new Promise(() => {}));
        render(<PeopleLocations view="people" />);
        expect(screen.queryByText('Permitted Maia')).not.toBeInTheDocument();
    });
    it.each([403, 500])(
        'hides a prior snapshot when revalidation returns %s',
        async (status) => {
            fetchMock
                .mockResolvedValueOnce(response())
                .mockResolvedValueOnce(response(status));
            render(<PeopleLocations view="people" />);
            await screen.findByText('Permitted Maia');
            fireEvent.click(screen.getByRole('button', { name: /^Refresh$/ }));
            await waitFor(() =>
                expect(
                    screen.queryByText('Permitted Maia'),
                ).not.toBeInTheDocument(),
            );
            expect(screen.getByRole('alert')).toBeInTheDocument();
        },
    );
    it('clears personal evidence when the document becomes hidden', async () => {
        fetchMock.mockResolvedValueOnce(response());
        render(<PeopleLocations view="people" />);
        await screen.findByText('Permitted Maia');
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            value: true,
        });
        fireEvent(document, new Event('visibilitychange'));
        expect(screen.queryByText('Permitted Maia')).not.toBeInTheDocument();
    });
});
