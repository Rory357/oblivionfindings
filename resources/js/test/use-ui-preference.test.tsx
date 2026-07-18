import { useUiPreference } from '@/hooks/use-ui-preference';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    put: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    router: inertia,
}));

beforeEach(() => {
    vi.clearAllMocks();
});

function PreferenceHarness({ initial = [] }: { initial?: string[] }) {
    const preference = useUiPreference<string[]>({
        key: 'sites.profile.pinned-tabs',
        initialValue: initial,
    });

    return (
        <div>
            <output>{preference.value.join(',')}</output>
            <button
                type="button"
                onClick={() =>
                    preference.setValue([
                        ...preference.value,
                        'hazards',
                        'hazards',
                    ])
                }
            >
                Pin hazards
            </button>
            <span>{preference.saving ? 'Saving' : 'Saved'}</span>
            {preference.error ? <p role="alert">{preference.error}</p> : null}
        </div>
    );
}

describe('useUiPreference', () => {
    it('optimistically saves a normalized value to the generic endpoint', () => {
        render(<PreferenceHarness initial={['documents']} />);

        fireEvent.click(screen.getByRole('button', { name: 'Pin hazards' }));

        expect(screen.getByText('documents,hazards')).toBeVisible();
        expect(screen.getByText('Saving')).toBeVisible();
        expect(inertia.put).toHaveBeenCalledWith(
            '/settings/ui-preferences/sites.profile.pinned-tabs',
            { value: ['documents', 'hazards'] },
            expect.objectContaining({
                preserveScroll: true,
                preserveState: true,
                only: [],
            }),
        );
    });

    it('rolls back and exposes an accessible message when persistence fails', () => {
        render(<PreferenceHarness initial={['documents']} />);

        fireEvent.click(screen.getByRole('button', { name: 'Pin hazards' }));
        const options = inertia.put.mock.calls[0][2] as {
            onError: () => void;
            onFinish: () => void;
        };
        act(() => {
            options.onError();
            options.onFinish();
        });

        expect(screen.getByText('documents')).toBeVisible();
        expect(screen.getByText('Saved')).toBeVisible();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Could not save this preference. Try again.',
        );
    });
});
