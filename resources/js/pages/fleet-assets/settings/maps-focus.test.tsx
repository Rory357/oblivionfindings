import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Maps } from './_maps';
import type { MapSnapshot } from './_types';

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn(), visit: vi.fn() },
}));
vi.mock('./_map-tools', () => ({ MapTools: () => null }));

const initial: MapSnapshot = {
    revision: 'saved-map-revision',
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
    credentials: { browser: false, server: false },
    capabilities: [],
};

function click(button: HTMLElement) {
    button.focus();
    fireEvent.click(button);
}

async function openProvider() {
    render(
        <>
            <header>Application header</header>
            <main>
                <Maps
                    initial={initial}
                    canManage
                    query=""
                    userId={8}
                    onDirty={vi.fn()}
                />
            </main>
        </>,
    );
    const opener = screen.getByRole('button', { name: 'Configure provider' });
    click(opener);
    const provider = await screen.findByRole('dialog', {
        name: 'Configure map provider',
    });
    return { opener, provider };
}

async function openDiscard() {
    const controls = await openProvider();
    fireEvent.change(screen.getByLabelText('Google project reference'), {
        target: { value: 'synthetic-unsaved-project' },
    });
    click(screen.getByRole('button', { name: 'Continue' }));
    click(screen.getByRole('button', { name: 'Continue' }));
    click(screen.getByRole('button', { name: 'Continue' }));
    const cancel = screen.getByRole('button', { name: 'Cancel' });
    click(cancel);
    const discard = await screen.findByRole('dialog', {
        name: 'Discard map changes?',
    });
    return { ...controls, cancel, discard };
}

beforeEach(() => {
    localStorage.clear();
    vi.stubGlobal('fetch', vi.fn());
});
afterEach(async () => {
    cleanup();
    await new Promise((resolve) => window.setTimeout(resolve, 0));
    localStorage.clear();
    vi.unstubAllGlobals();
});

describe('Map provider nested discard focus', () => {
    it('returns to Configure provider after discarding the reviewed draft and closing both dialogs', async () => {
        const { opener, discard } = await openDiscard();
        click(within(discard).getByRole('button', { name: 'Discard changes' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        await waitFor(() => expect(opener).toHaveFocus());
        expect(localStorage.getItem('fleet.maps.draft.8')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it.each(['Keep editing', 'Escape'])(
        '%s restores focus inside the still-open provider without losing the draft',
        async (action) => {
            const { provider, cancel, discard } = await openDiscard();
            if (action === 'Escape')
                fireEvent.keyDown(discard, { key: 'Escape', code: 'Escape' });
            else click(within(discard).getByRole('button', { name: action }));
            await waitFor(() =>
                expect(
                    screen.queryByRole('dialog', {
                        name: 'Discard map changes?',
                    }),
                ).toBeNull(),
            );
            expect(provider).toBeInTheDocument();
            await waitFor(() => expect(cancel).toHaveFocus());
            expect(
                within(provider).getByText('synthetic-unsaved-project'),
            ).toBeVisible();
            expect(fetch).not.toHaveBeenCalled();
        },
    );

    it('preserves clean Escape restoration to the existing opener', async () => {
        const { opener, provider } = await openProvider();
        fireEvent.keyDown(provider, { key: 'Escape', code: 'Escape' });
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        await waitFor(() => expect(opener).toHaveFocus());
        expect(fetch).not.toHaveBeenCalled();
    });
});
