import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { CreateShiftDialog } from './create-shift-dialog';

const inertiaSpies = vi.hoisted(() => ({
    put: vi.fn(),
    post: vi.fn(),
    routerPost: vi.fn(),
    serverError: null as string | null,
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        router: {
            post: inertiaSpies.routerPost,
        },
        useForm: (initialData: Record<string, unknown>) => {
            const [data, setDataState] = ReactActual.useState(initialData);
            const [errors, setErrors] = ReactActual.useState({});
            const transformRef = ReactActual.useRef<
                | ((data: Record<string, unknown>) => Record<string, unknown>)
                | null
            >(null);

            const form = {
                data,
                errors,
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
                put: (
                    url: string,
                    options?: { onSuccess?: () => void; onError?: () => void },
                ) => {
                    inertiaSpies.put(
                        url,
                        transformRef.current
                            ? transformRef.current(data)
                            : data,
                    );
                    if (inertiaSpies.serverError) {
                        setErrors({ user_id: inertiaSpies.serverError });
                        options?.onError?.();
                    } else {
                        options?.onSuccess?.();
                    }
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

const eligible = { is_allowed: true, blocked_reasons: [], warning_reasons: [] };
const previewResponse = (
    body: {
        is_allowed: boolean;
        blocked_reasons: string[];
        warning_reasons: string[];
    } = eligible,
) => ({ ok: true, json: async () => body });

beforeEach(() => {
    inertiaSpies.serverError = null;
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(previewResponse()));
});
afterEach(() => vi.unstubAllGlobals());

describe('CreateShiftDialog edit mode', () => {
    beforeEach(() => {
        inertiaSpies.put.mockClear();
        inertiaSpies.post.mockClear();
        inertiaSpies.routerPost.mockClear();
    });

    it('prefills from initialShift and submits a PUT to the update route', async () => {
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
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: /Save changes/i }),
            ).toBeEnabled(),
        );
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
                clients={[{ id: 10, first_name: 'Ari', last_name: 'Kauri' }]}
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

    it('can clear an existing shift licence requirement', async () => {
        render(
            <CreateShiftDialog
                open
                onClose={vi.fn()}
                clients={[{ id: 10, first_name: 'Ari', last_name: 'Kauri' }]}
                staff={[{ id: 7, name: 'Aroha King' }]}
                initialShift={{
                    id: 44,
                    starts_at: '2026-05-04T09:00:00+12:00',
                    ends_at: '2026-05-04T13:00:00+12:00',
                    status: 'scheduled',
                    client: { id: 10 },
                    staff: { id: 7 },
                    required_licence_class: '2',
                    required_licence_endorsements: ['P'],
                }}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Who & where/ }));
        fireEvent.change(screen.getByLabelText(/Required licence class/i), {
            target: { value: '' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: /Passenger endorsement/i }),
        );
        fireEvent.click(screen.getByRole('button', { name: /Review/ }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: /Save changes/i }),
            ).toBeEnabled(),
        );
        fireEvent.click(screen.getByRole('button', { name: /Save changes/i }));

        expect(inertiaSpies.put).toHaveBeenCalledWith(
            '/operations/shifts/44',
            expect.objectContaining({
                required_licence_class: '',
                required_licence_endorsements: [],
            }),
        );
    });

    function renderAssignedEdit(onClose = vi.fn()) {
        render(
            <CreateShiftDialog
                open
                onClose={onClose}
                clients={[{ id: 10, first_name: 'Ari', last_name: 'Kauri' }]}
                staff={[{ id: 7, name: 'Aroha King' }]}
                initialShift={{
                    id: 44,
                    starts_at: '2026-05-04T09:00:00+12:00',
                    ends_at: '2026-05-04T13:00:00+12:00',
                    status: 'scheduled',
                    client: { id: 10 },
                    staff: { id: 7 },
                }}
            />,
        );
    }

    it('waits through debounce and ignores an old eligibility response after an edit', async () => {
        const responses: Array<
            (value: ReturnType<typeof previewResponse>) => void
        > = [];
        vi.mocked(fetch).mockImplementation(
            () =>
                new Promise((resolve) => {
                    responses.push(
                        resolve as (
                            value: ReturnType<typeof previewResponse>,
                        ) => void,
                    );
                }),
        );
        renderAssignedEdit();
        fireEvent.click(screen.getByRole('button', { name: /Review/ }));
        const save = screen.getByRole('button', { name: /Save changes/i });
        expect(save).toBeDisabled();
        fireEvent.submit(save.closest('form')!);
        expect(inertiaSpies.put).not.toHaveBeenCalled();
        await waitFor(() => expect(responses).toHaveLength(1));

        fireEvent.click(
            screen.getByRole('button', { name: /Schedule.*Times, break/ }),
        );
        fireEvent.change(screen.getByLabelText(/^Start/), {
            target: { value: '2026-05-04T09:30' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Review/ }));
        await act(async () => responses[0](previewResponse()));
        expect(
            screen.getByRole('button', { name: /Save changes/i }),
        ).toBeDisabled();
        await waitFor(() => expect(responses).toHaveLength(2));
        await act(async () => responses[1](previewResponse()));
        expect(
            screen.getByRole('button', { name: /Save changes/i }),
        ).toBeEnabled();
        expect(inertiaSpies.put).not.toHaveBeenCalled();
    });

    it('keeps the draft and refreshes warnings when the server rejects a stale preview', async () => {
        const onClose = vi.fn();
        renderAssignedEdit(onClose);
        fireEvent.click(screen.getByRole('button', { name: /Review/ }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: /Save changes/i }),
            ).toBeEnabled(),
        );
        inertiaSpies.serverError = 'Staff not available. Review before saving.';
        vi.mocked(fetch).mockResolvedValue(
            previewResponse({
                ...eligible,
                warning_reasons: ['Staff not available.'],
            }) as Response,
        );
        fireEvent.click(screen.getByRole('button', { name: /Save changes/i }));
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByText(inertiaSpies.serverError)).toBeVisible();
        expect(
            screen.getByRole('button', { name: /Save changes/i }),
        ).toBeDisabled();
        await waitFor(() =>
            expect(
                screen.getByText('Staff eligibility warnings'),
            ).toBeVisible(),
        );
        fireEvent.click(screen.getByRole('button', { name: /Save changes/i }));
        expect(
            screen.getByRole('dialog', {
                name: 'Override Eligibility Warnings',
            }),
        ).toBeVisible();
        expect(inertiaSpies.put).toHaveBeenCalledTimes(1);
        expect(onClose).not.toHaveBeenCalled();
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
                clients={[{ id: 10, first_name: 'Ari', last_name: 'Kauri' }]}
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

    it('submits optional licence class and endorsement requirements', () => {
        renderCreate();

        fireEvent.click(screen.getByRole('button', { name: /Who & where/ }));
        fireEvent.change(screen.getByLabelText(/Required licence class/i), {
            target: { value: '2' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: /Passenger endorsement/i }),
        );

        fireEvent.click(screen.getByRole('button', { name: /Review/ }));
        fireEvent.click(screen.getByRole('button', { name: /Create shift$/ }));

        expect(inertiaSpies.post).toHaveBeenCalledWith(
            '/operations/shifts',
            expect.objectContaining({
                required_licence_class: '2',
                required_licence_endorsements: ['P'],
            }),
        );
    });

    it('omits licence requirement keys for an ordinary shift', () => {
        renderCreate();

        fireEvent.click(screen.getByRole('button', { name: /Review/ }));
        fireEvent.click(screen.getByRole('button', { name: /Create shift$/ }));

        const payload = inertiaSpies.post.mock.calls.at(-1)?.[1] as Record<
            string,
            unknown
        >;
        expect(payload).not.toHaveProperty('required_licence_class');
        expect(payload).not.toHaveProperty('required_licence_endorsements');
    });

    it("prefills location from the client's site and follows client changes", () => {
        render(
            <CreateShiftDialog
                open
                onClose={vi.fn()}
                clients={[
                    {
                        id: 10,
                        first_name: 'Ari',
                        last_name: 'Kauri',
                        site_id: 2,
                    },
                    {
                        id: 11,
                        first_name: 'Mere',
                        last_name: 'Pono',
                        site_id: 3,
                    },
                ]}
                staff={[]}
                sites={[
                    { id: 2, name: 'Kowhai House' },
                    { id: 3, name: 'Aroha Respite' },
                ]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Who & where/ }));
        expect(screen.getByLabelText(/Location/)).toHaveValue('Kowhai House');

        // Changing the client follows their site…
        fireEvent.change(screen.getByLabelText(/Client/), {
            target: { value: '11' },
        });
        expect(screen.getByLabelText(/Location/)).toHaveValue('Aroha Respite');

        // …but a custom location typed by the coordinator is kept.
        fireEvent.change(screen.getByLabelText(/Location/), {
            target: { value: 'Te Papa community outing' },
        });
        fireEvent.change(screen.getByLabelText(/Client/), {
            target: { value: '10' },
        });
        expect(screen.getByLabelText(/Location/)).toHaveValue(
            'Te Papa community outing',
        );
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
