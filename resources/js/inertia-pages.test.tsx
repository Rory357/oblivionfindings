import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const resolve = vi.hoisted(() => vi.fn());
vi.mock('laravel-vite-plugin/inertia-helpers', () => ({
    resolvePageComponent: resolve,
}));

import { resolveInertiaPage } from './inertia-pages';

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('page module loading', () => {
    it('preserves a successfully loaded page', async () => {
        const page = { default: () => <main>Medication follow-ups</main> };
        resolve.mockResolvedValueOnce(page);
        expect(await resolveInertiaPage('emar/Followups')).toBe(page);
    });

    it('renders an actionable recovery page when a browser chunk fails', async () => {
        const report = vi.spyOn(console, 'error').mockImplementation(() => {});
        resolve.mockRejectedValueOnce(
            new TypeError('Failed to fetch dynamically imported module'),
        );
        const page = (await resolveInertiaPage('emar/Followups')) as {
            default: () => React.ReactNode;
        };
        render(<page.default />);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This page couldn’t load',
        );
        expect(
            screen.getByRole('button', { name: 'Reload this page' }),
        ).toBeEnabled();
        expect(screen.queryByText(/TypeError/)).not.toBeInTheDocument();
        expect(report).toHaveBeenCalledWith(
            'Unable to load this page module.',
            expect.any(TypeError),
        );
    });

    it('keeps server rendering failures visible to server error handling', async () => {
        vi.stubGlobal('window', undefined);
        const error = new Error('Server module failure');
        resolve.mockRejectedValueOnce(error);
        await expect(resolveInertiaPage('emar/downtime/index')).rejects.toBe(
            error,
        );
    });
});
