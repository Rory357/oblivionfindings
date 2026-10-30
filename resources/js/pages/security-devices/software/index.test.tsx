import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ApprovedSoftware from './index';
import { catalogue } from './software-fixtures';

const mocks = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        router: {
            ...actual.router,
            visit: mocks.visit,
            reload: mocks.reload,
        },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

beforeEach(() => {
    mocks.visit.mockReset();
    mocks.reload.mockReset();
});
afterEach(cleanup);

describe('Approved software header', () => {
    it('uses the shared header search, glass secondary and one white primary', () => {
        render(<ApprovedSoftware {...catalogue()} />);
        const search = screen.getByRole('searchbox', {
            name: 'Search approved software',
        });
        fireEvent.change(search, { target: { value: 'Document viewer' } });
        fireEvent.keyDown(search, { key: 'Enter' });
        expect(mocks.visit).toHaveBeenLastCalledWith(
            '/security-devices/software?expected_actor_id=9001&status=published&search=Document+viewer&device_id=9100',
            { preserveScroll: true },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));
        expect(mocks.visit).toHaveBeenCalledTimes(2);
        expect(
            screen.getByRole('link', { name: 'Computer support' }),
        ).toHaveAttribute('href', '/it/remote-support?device=9100');
        expect(
            screen.getByRole('button', { name: 'Add release' }),
        ).toBeInTheDocument();
        expect(document.querySelector('form')).toBeNull();
    });

    it('offers a clear-search chip only while a search is applied', () => {
        const page = catalogue();
        render(<ApprovedSoftware {...page} />);
        expect(
            screen.queryByRole('button', { name: /^Clear search/ }),
        ).not.toBeInTheDocument();
        cleanup();
        page.query.search = 'viewer';
        render(<ApprovedSoftware {...page} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear search: viewer' }),
        );
        expect(mocks.visit).toHaveBeenLastCalledWith(
            '/security-devices/software?expected_actor_id=9001&status=published&device_id=9100',
            { preserveScroll: true },
        );
    });
});
