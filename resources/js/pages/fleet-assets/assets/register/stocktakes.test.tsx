import { Button } from '@/components/ui/button';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { Stocktakes } from './stocktakes';

const { reloadMock, apiOverride } = vi.hoisted(() => ({
    reloadMock: vi.fn(),
    apiOverride: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ router: { reload: reloadMock } }));
vi.mock('./api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./api')>()),
    api: (path: string) =>
        apiOverride(path) ??
        Promise.resolve(
            path === '/stocktakes/3'
                ? { id: 3, status: 'draft' }
                : { data: [], current_page: 1, last_page: 1, total: 0 },
        ),
}));
vi.mock('./stocktake-modal', () => ({
    StocktakeModal: ({ onClose }: { onClose: () => void }) => (
        <Button onClick={onClose}>Close saved count</Button>
    ),
}));

it('closes a linked count before refreshing the hub, so reload does not reopen it', async () => {
    history.replaceState(
        { preserved: true },
        '',
        '/fleet-assets/assets?view=stocktake&stocktake=3',
    );
    let refreshUrl = '';
    reloadMock.mockImplementation(() => {
        refreshUrl = location.href;
    });
    render(
        <Stocktakes
            initialStatus=""
            sites={[]}
            staff={[]}
            canCount
            initialSite=""
            initialRoom=""
            selected={[]}
        />,
    );
    fireEvent.click(
        await screen.findByRole('button', { name: 'Close saved count' }),
    );
    await waitFor(() => expect(reloadMock).toHaveBeenCalled());
    expect(new URL(refreshUrl).searchParams.has('stocktake')).toBe(false);
    expect(new URL(refreshUrl).searchParams.get('view')).toBe('stocktake');
    expect(history.state).toEqual({ preserved: true });
});

it('clears count rows while loading site coverage and retains the selected section on a matching hero filter', async () => {
    history.replaceState({}, '', '/fleet-assets/assets?view=stocktake');
    let resolveCoverage!: (data: unknown) => void;
    const pending = new Promise((resolve) => {
        resolveCoverage = resolve;
    });
    apiOverride.mockImplementation((path: string) =>
        path.includes('workspace=coverage')
            ? pending
            : Promise.resolve({
                  data: [
                      {
                          id: 1,
                          title: 'Equipment check',
                          site: 'Aurora House',
                          room: 'Equipment room',
                          status: 'completed',
                          counter: 'Maya Chen',
                          total: 3,
                          answered: 3,
                          differences: 0,
                          updated_at: '2026-09-27T00:00:00Z',
                      },
                  ],
                  current_page: 1,
                  last_page: 1,
                  total: 1,
                  summary: { counts: 1, followups: 0, sites: 1 },
              }),
    );
    const props = {
        sites: [],
        staff: [],
        canCount: true,
        initialSite: '',
        initialRoom: '',
        selected: [],
    };
    const errors = vi.spyOn(console, 'error').mockImplementation(() => {});
    const { rerender } = render(<Stocktakes {...props} initialStatus="" />);
    await screen.findByRole('tab', { name: /Site coverage\s*1/ });
    fireEvent.click(screen.getByRole('tab', { name: /Site coverage\s*1/ }));
    expect(screen.getByText('Loading saved stocktakes…')).toBeInTheDocument();
    expect(screen.queryAllByText('Equipment check')).toHaveLength(0);
    expect(errors).not.toHaveBeenCalled();
    await act(async () =>
        resolveCoverage({
            data: [
                {
                    id: 1,
                    name: 'Aurora House',
                    rooms: 2,
                    checked_rooms: 1,
                    counts: 1,
                    last_completed_at: '2026-09-27T00:00:00Z',
                },
            ],
            current_page: 1,
            last_page: 1,
            total: 1,
            summary: { counts: 1, followups: 0, sites: 1 },
        }),
    );
    expect(await screen.findByText('1 / 2 rooms checked')).toBeInTheDocument();
    rerender(<Stocktakes {...props} initialStatus="coverage" />);
    expect(
        screen.queryByText('Loading saved stocktakes…'),
    ).not.toBeInTheDocument();
    expect(errors).not.toHaveBeenCalled();
    errors.mockRestore();
    apiOverride.mockReset();
});
