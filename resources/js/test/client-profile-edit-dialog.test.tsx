import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ClientEditDialog } from '@/components/client-edit-dialog';

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        router: { put: vi.fn(), reload: vi.fn() },
        usePage: () => ({ props: { flash: {} } }),
        useForm: <T,>(initial: T) => {
            const [data, setState] = React.useState(initial);
            return {
                data,
                setData: (key: keyof T, value: T[keyof T]) =>
                    setState((current) => ({
                        ...current,
                        [key]: value,
                    })),
                processing: false,
                errors: {},
                post: vi.fn(),
                transform: vi.fn(),
                reset: vi.fn(),
                clearErrors: vi.fn(),
            };
        },
    };
});

describe('client profile edit dialog', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    client: {
                        id: 9040,
                        first_name: 'Tane',
                        last_name: 'Wineera',
                        status: 'active',
                    },
                    initialValues: {
                        first_name: 'Tane',
                        last_name: 'Wineera',
                        status: 'active',
                        ethnicity: 'Māori',
                    },
                    sites: [],
                    serviceContexts: [],
                    keyWorkers: [],
                    geofences: [],
                    defaultServiceContextId: null,
                }),
            }),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('uses Add Client completion mode instead of the flat legacy form', async () => {
        render(
            <ClientEditDialog clientId={9040} open onOpenChange={vi.fn()} />,
        );

        expect(
            await screen.findByRole('heading', {
                name: 'Who are we adding?',
            }),
        ).toBeVisible();
        expect(
            screen.queryByRole('heading', { name: 'Edit client' }),
        ).not.toBeInTheDocument();
    });
});
