import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { request } from './api';
import type { Boundary, BoundaryRecord } from './data';
import { OperationalMap } from './operational-map';

vi.mock('./api', async (original) => ({
    ...(await original<typeof import('./api')>()),
    request: vi.fn(),
}));
vi.mock('./operational-canvas', () => ({
    OperationalCanvas: ({
        boundaries,
        selectedBoundary,
    }: {
        boundaries: Boundary[];
        selectedBoundary: string;
    }) => (
        <div data-testid="rendered-boundaries" data-selected={selectedBoundary}>
            {boundaries.map((b) => b.name).join(', ')}
        </div>
    ),
}));
vi.mock('./remote-picker', () => ({ RemotePicker: () => null }));

const boundary: BoundaryRecord = {
    id: 130,
    name: 'Saved test boundary',
    site_id: 1,
    site: 'Permitted site',
    address: 'Test location',
    geometry: {
        type: 'circle',
        center: { lat: -41.29, lng: 174.78 },
        radius_m: 220,
    },
    geometry_version: 1,
    revision: 1,
    uses: ['Vehicles'],
    personal_eligible: false,
    retired_at: null,
    legacy_monitoring: false,
    copy_source: null,
    updated_at: null,
};
const catalogue = { data: [boundary], total: 1, page: 1, last_page: 1 };
const resources = {
    data: [],
    total: 0,
    page: 1,
    last_page: 1,
    as_of: '2026-09-27T04:00:00Z',
};
const props = {
    site: 1,
    canManage: true,
    onCreate: vi.fn(),
    onStart: vi.fn(),
    onInspect: vi.fn(),
    boundaryActions: () => [],
    onRule: vi.fn(),
    refresh: 0,
};
const pending = () => {
    let resolve!: (value: unknown) => void;
    let reject!: (reason: Error) => void;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
};
beforeEach(() => {
    vi.mocked(request)
        .mockReset()
        .mockImplementation(async (url) =>
            url.includes('/catalogue') ? catalogue : resources,
        );
});
afterEach(cleanup);

describe('boundary visibility during map refresh', () => {
    it('keeps saved geometry visible while same-scope refresh is pending, then replaces it', async () => {
        render(<OperationalMap {...props} />);
        await waitFor(() =>
            expect(screen.getByTestId('rendered-boundaries')).toHaveTextContent(
                boundary.name,
            ),
        );
        const next = pending();
        vi.mocked(request).mockImplementation((url) =>
            url.includes('/catalogue')
                ? next.promise
                : Promise.resolve(resources),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(screen.getByRole('status')).toHaveTextContent(
            'Refreshing permitted map data',
        );
        expect(screen.getByTestId('rendered-boundaries')).toHaveTextContent(
            boundary.name,
        );
        await act(async () =>
            next.resolve({
                ...catalogue,
                data: [{ ...boundary, name: 'Updated permitted boundary' }],
            }),
        );
        expect(screen.getByTestId('rendered-boundaries')).toHaveTextContent(
            'Updated permitted boundary',
        );
    });

    it('clears the prior scope immediately and clears geometry when refresh is denied', async () => {
        const view = render(<OperationalMap {...props} />);
        await waitFor(() =>
            expect(screen.getByTestId('rendered-boundaries')).toHaveTextContent(
                boundary.name,
            ),
        );
        const next = pending();
        vi.mocked(request).mockImplementation((url) =>
            url.includes('/catalogue')
                ? next.promise
                : Promise.resolve(resources),
        );
        view.rerender(<OperationalMap {...props} site={2} />);
        expect(screen.getByTestId('rendered-boundaries')).toBeEmptyDOMElement();
        await act(async () =>
            next.resolve({ ...catalogue, data: [{ ...boundary, site_id: 2 }] }),
        );
        expect(screen.getByTestId('rendered-boundaries')).toHaveTextContent(
            boundary.name,
        );
        vi.mocked(request).mockRejectedValue(
            new Error('Access to this map is no longer permitted.'),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        await screen.findByText('Access to this map is no longer permitted.');
        expect(screen.getByTestId('rendered-boundaries')).toBeEmptyDOMElement();
    });

    it('pins a permitted boundary from a direct link without bypassing the Site filter', async () => {
        const view = render(
            <OperationalMap {...props} initialBoundary={boundary} />,
        );
        await waitFor(() =>
            expect(screen.getByTestId('rendered-boundaries')).toHaveAttribute(
                'data-selected',
                '130',
            ),
        );
        expect(
            screen.getByRole('complementary', {
                name: 'Pinned boundary details',
            }),
        ).toHaveTextContent(boundary.name);
        view.rerender(
            <OperationalMap {...props} initialBoundary={boundary} site={2} />,
        );
        await waitFor(() =>
            expect(screen.getByTestId('rendered-boundaries')).toHaveAttribute(
                'data-selected',
                '',
            ),
        );
    });
});
