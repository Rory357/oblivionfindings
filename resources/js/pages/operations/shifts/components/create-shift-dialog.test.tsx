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
                    tasks: [
                        {
                            id: 91,
                            label: 'Check overnight notes',
                            scheduled_time: '10:30',
                        },
                    ],
                    client: { id: 10 },
                    staff: { id: 7 },
                    site: { id: 2, name: 'Kowhai House' },
                }}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Edit shift' }),
        ).toBeVisible();

        // Wizard: fields live on their steps now — navigate via the rail.
        fireEvent.click(screen.getByRole('button', { name: /Who & where/ }));
        expect(screen.getByLabelText(/Client/)).toHaveValue('10');
        expect(screen.getByLabelText(/Staff/)).toHaveValue('7');
        expect(screen.getByLabelText(/Location/)).toHaveValue('Kowhai House');

        fireEvent.click(
            screen.getByRole('button', { name: /Schedule.*Times, break/ }),
        );
        expect(screen.getByLabelText(/Break/)).toHaveValue(45);

        fireEvent.click(screen.getByRole('button', { name: /Tasks & notes/ }));
        expect(screen.getByLabelText(/Handover notes/i)).toHaveValue(
            'Bring medication folder.',
        );
        expect(screen.getByDisplayValue('Check overnight notes')).toBeVisible();
        expect(screen.getByLabelText(/Specific time/i)).toBeChecked();
        expect(screen.getByDisplayValue('10:30')).toBeVisible();

        fireEvent.change(screen.getByDisplayValue('10:30'), {
            target: { value: '11:15' },
        });

        fireEvent.click(screen.getByRole('button', { name: /Review/ }));
        fireEvent.click(screen.getByRole('button', { name: /Save changes/i }));

        expect(inertiaSpies.put).toHaveBeenCalledWith(
            '/operations/shifts/44',
            expect.objectContaining({
                client_id: 10,
                user_id: 7,
                expected_break_minutes: '45',
                tasks: [
                    expect.objectContaining({
                        id: 91,
                        label: 'Check overnight notes',
                        scheduled_time: '11:15',
                    }),
                ],
            }),
        );
        expect(onClose).toHaveBeenCalled();
    });

    it('edit mode has no Repeat step in the rail', () => {
        render(
            <CreateShiftDialog
                open
                onClose={vi.fn()}
                clients={[
                    { id: 10, first_name: 'Ari', last_name: 'Kauri' },
                ]}
                staff={[]}
                initialShift={{
                    id: 44,
                    starts_at: '2026-05-04T09:00:00+12:00',
                    ends_at: '2026-05-04T13:00:00+12:00',
                    status: 'scheduled',
                    client: { id: 10 },
                }}
            />,
        );

        expect(screen.getByText(/Step 1 of 5/)).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /Repeat weekly/ }),
        ).toBeNull();
    });
});

describe('CreateShiftDialog create mode wizard', () => {
    beforeEach(() => {
        inertiaSpies.put.mockClear();
        inertiaSpies.post.mockClear();
        inertiaSpies.routerPost.mockClear();
    });

    function renderCreate() {
        render(
            <CreateShiftDialog
                open
                onClose={vi.fn()}
                clients={[
                    { id: 10, first_name: 'Ari', last_name: 'Kauri' },
                ]}
                staff={[{ id: 7, name: 'Aroha King' }]}
            />,
        );
    }

    it('shows all six steps and Continue advances', () => {
        renderCreate();

        // Two headings carry the name: the sr-only DialogTitle + the rail h2.
        expect(
            screen.getAllByRole('heading', { name: 'Create shift' }).length,
        ).toBeGreaterThan(0);
        expect(screen.getByText(/Step 1 of 6/)).toBeVisible();
        expect(
            screen.getByRole('button', { name: /Repeat weekly/ }),
        ).toBeVisible();
        // Create shift submit only exists on the review step.
        expect(
            screen.queryByRole('button', { name: /Create shift$/ }),
        ).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        expect(screen.getByText(/Step 2 of 6/)).toBeVisible();
    });

    it('blocks the schedule step when the end is not after the start', () => {
        renderCreate();

        fireEvent.click(
            screen.getByRole('button', { name: /Schedule.*Times, break/ }),
        );
        fireEvent.change(screen.getByLabelText(/Start/), {
            target: { value: '2026-06-15T10:00' },
        });
        fireEvent.change(screen.getByLabelText(/End/), {
            target: { value: '2026-06-15T09:00' },
        });

        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));

        expect(screen.getByText('End must be after the start')).toBeVisible();
        expect(screen.getByText(/Step 3 of 6/)).toBeVisible();
    });
});
