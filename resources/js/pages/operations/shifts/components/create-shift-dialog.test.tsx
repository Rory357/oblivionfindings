import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { CreateShiftDialog } from './create-shift-dialog';

const inertiaSpies = vi.hoisted(() => ({
    put: vi.fn(),
    post: vi.fn(),
    routerPost: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        router: {
            post: inertiaSpies.routerPost,
        },
        useForm: (initialData: Record<string, unknown>) => {
            const [data, setDataState] = ReactActual.useState(initialData);
            const transformRef = ReactActual.useRef<
                | ((data: Record<string, unknown>) => Record<string, unknown>)
                | null
            >(null);

            const form = {
                data,
                errors: {},
                processing: false,
                setData: (
                    key: string | Record<string, unknown>,
                    value?: unknown,
                ) => {
                    if (typeof key === 'string') {
                        setDataState((current) => ({
                            ...current,
                            [key]: value,
                        }));
                    } else {
                        setDataState(key);
                    }
                },
                transform: (
                    callback: (
                        data: Record<string, unknown>,
                    ) => Record<string, unknown>,
                ) => {
                    transformRef.current = callback;
                    return form;
                },
                put: (url: string, options?: { onSuccess?: () => void }) => {
                    inertiaSpies.put(
                        url,
                        transformRef.current
                            ? transformRef.current(data)
                            : data,
                    );
                    options?.onSuccess?.();
                },
                post: (url: string, options?: { onSuccess?: () => void }) => {
                    inertiaSpies.post(
                        url,
                        transformRef.current
                            ? transformRef.current(data)
                            : data,
                    );
                    options?.onSuccess?.();
                },
            };

            return form;
        },
    };
});

describe('CreateShiftDialog edit mode', () => {
    beforeEach(() => {
        inertiaSpies.put.mockClear();
        inertiaSpies.post.mockClear();
        inertiaSpies.routerPost.mockClear();
    });

    it('prefills from initialShift and submits a PUT to the update route', () => {
        const onClose = vi.fn();

        render(
            <CreateShiftDialog
                open
                onClose={onClose}
                clients={[
                    {
                        id: 10,
                        first_name: 'Ari',
                        last_name: 'Kauri',
                        service_context_id: 3,
                        site_id: 2,
                    },
                ]}
                staff={[{ id: 7, name: 'Aroha King' }]}
                sites={[{ id: 2, name: 'Kowhai House' }]}
                serviceContexts={[
                    {
                        id: 3,
                        name: 'Residential',
                        type: 'residential',
                        is_active: true,
                    },
                ]}
                defaultServiceContextId={3}
                initialShift={{
                    id: 44,
                    starts_at: '2026-05-04T09:00:00+12:00',
                    ends_at: '2026-05-04T13:00:00+12:00',
                    status: 'scheduled',
                    shift_type: 'standard',
                    location: 'Kowhai House',
                    notes: 'Bring medication folder.',
                    expected_break_minutes: 45,
                    service_context_id: 3,
                    coverage_roles: ['caregiver'],
                    tasks: [{ id: 91, label: 'Check overnight notes' }],
                    client: { id: 10 },
                    staff: { id: 7 },
                    site: { id: 2, name: 'Kowhai House' },
                }}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Edit shift' }),
        ).toBeVisible();
        expect(screen.getByLabelText(/Client/)).toHaveValue('10');
        expect(screen.getByLabelText(/Staff/)).toHaveValue('7');
        expect(screen.getByLabelText(/Location/)).toHaveValue('Kowhai House');
        expect(screen.getByLabelText(/Break/)).toHaveValue(45);
        expect(screen.getByLabelText(/Handover notes/i)).toHaveValue(
            'Bring medication folder.',
        );
        expect(screen.getByDisplayValue('Check overnight notes')).toBeVisible();

        fireEvent.click(screen.getByRole('button', { name: /Save changes/i }));

        expect(inertiaSpies.put).toHaveBeenCalledWith(
            '/operations/shifts/44',
            expect.objectContaining({
                client_id: 10,
                user_id: 7,
                expected_break_minutes: '45',
            }),
        );
        expect(onClose).toHaveBeenCalled();
    });
});
