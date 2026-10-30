import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Maps } from './_maps';
import type { MapSnapshot } from './_types';

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn(), visit: vi.fn() },
}));
vi.mock('./_map-tools', () => ({ MapTools: () => null }));

const snapshot: MapSnapshot = {
    revision: 'a'.repeat(64),
    values: {
        google: false,
        project: '',
        display: false,
        places: false,
        geocoding: false,
        routes: false,
        restrictions_reviewed: false,
        terms_reviewed: false,
    },
    credentials: { browser: true, server: false },
    capabilities: ['display', 'places', 'geocoding', 'routes'].map(
        (key, index) => ({
            key,
            title: [
                'Map display',
                'Address search',
                'Reverse geocoding',
                'Routing',
            ][index],
            enabled: false,
            status: 'Google is off',
        }),
    ),
};
const response = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
function open(initial = snapshot) {
    render(
        <Maps
            initial={initial}
            canManage
            query=""
            userId={19}
            onDirty={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Configure provider' }));
}
function enable() {
    fireEvent.click(screen.getByRole('switch', { name: 'Use Google Maps' }));
    fireEvent.change(screen.getByLabelText('Google project reference'), {
        target: { value: 'synthetic-project' },
    });
}
function review() {
    fireEvent.click(screen.getByRole('button', { name: /Review & save/ }));
    fireEvent.click(screen.getByLabelText(/deployment owner has restricted/));
    fireEvent.click(screen.getByLabelText(/organisation has reviewed/));
}
beforeEach(() => {
    localStorage.clear();
    vi.stubGlobal('fetch', vi.fn());
});
afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.unstubAllGlobals();
});

describe('Guided Google setup', () => {
    it('explains the missing browser key immediately and keeps entered project text', async () => {
        open({ ...snapshot, credentials: { browser: false, server: false } });
        enable();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByRole('alert', { name: 'Map configuration error' }),
        ).toHaveTextContent('restricted browser key');
        expect(screen.getByLabelText('Google project reference')).toHaveValue(
            'synthetic-project',
        );
        expect(fetch).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(
                screen.getByRole('alert', { name: 'Map configuration error' }),
            ).toHaveFocus(),
        );
    });
    it('enables the required display foundation and reports optional server prerequisites separately', () => {
        open();
        enable();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByRole('switch', { name: 'Map display' }),
        ).toBeChecked();
        expect(
            screen.getByRole('switch', { name: 'Map display' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('switch', { name: 'Routing' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByRole('alert', { name: 'Map configuration error' }),
        ).toHaveTextContent('separate server key');
        expect(screen.getByRole('switch', { name: 'Routing' })).toBeChecked();
        expect(fetch).not.toHaveBeenCalled();
    });
    it('saves a reviewed display-only configuration and never claims live verification', async () => {
        const saved = {
            ...snapshot,
            revision: 'b'.repeat(64),
            values: {
                ...snapshot.values,
                google: true,
                display: true,
                project: 'synthetic-project',
                restrictions_reviewed: true,
                terms_reviewed: true,
            },
        };
        vi.mocked(fetch).mockResolvedValue(response(saved));
        open();
        enable();
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save configuration' }),
        );
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(fetch).toHaveBeenCalledTimes(1);
        const request = JSON.parse(
            String(vi.mocked(fetch).mock.calls[0][1]?.body),
        );
        expect(request.values).toEqual(saved.values);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Map configuration saved',
        );
        expect(screen.getByText('Not run by this setup')).toBeVisible();
    });
    it('returns server validation to the connection step without losing the draft', async () => {
        vi.mocked(fetch).mockResolvedValue(
            response(
                {
                    message: 'Browser credential was removed.',
                    errors: {
                        'values.google': ['Browser credential was removed.'],
                    },
                },
                422,
            ),
        );
        open();
        enable();
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save configuration' }),
        );
        await screen.findByText('Browser credential was removed.');
        expect(screen.getByLabelText('Google project reference')).toHaveValue(
            'synthetic-project',
        );
        expect(
            screen.getByRole('switch', { name: 'Use Google Maps' }),
        ).toBeChecked();
    });
    it('merges a concurrent credential revision without discarding edited project fields', async () => {
        const latest = {
            ...snapshot,
            revision: 'c'.repeat(64),
            credentials: { browser: true, server: true },
        };
        vi.mocked(fetch).mockResolvedValue(
            response({ message: 'Changed', latest }, 409),
        );
        open();
        enable();
        review();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save configuration' }),
        );
        await screen.findByRole('dialog', {
            name: 'Map configuration changed',
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep my changes' }),
        );
        expect(screen.getByText('synthetic-project')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Save configuration' }),
        ).toBeVisible();
    });
});
