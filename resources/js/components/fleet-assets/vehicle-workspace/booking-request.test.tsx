import { Button } from '@/components/ui/button';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { VehicleCalendarSummary } from './calendar-types';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { auth: { user: { id: 51, name: 'Requester' } } },
    }),
}));
vi.mock('@/components/fleet-assets/maintenance/date-time-field', () => ({
    DateTimeField: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
vi.mock('./booking-vehicle-picker', () => ({
    BookingVehiclePicker: ({
        vehicles,
        value,
        onChange,
    }: {
        vehicles: { id: number; name: string }[];
        value: number | null;
        onChange: (id: number) => void;
    }) => (
        <select
            id="request_vehicle"
            value={value ?? ''}
            onChange={(event) => onChange(Number(event.target.value))}
        >
            <option value="">Choose a vehicle</option>
            {vehicles.map((vehicle) => (
                <option key={vehicle.id} value={vehicle.id}>
                    {vehicle.name}
                </option>
            ))}
        </select>
    ),
}));
vi.mock('./wizard-kit', async () => {
    const actual =
        await vi.importActual<typeof import('./wizard-kit')>('./wizard-kit');
    return {
        ...actual,
        WorkspaceWizard: (
            props: ComponentProps<typeof actual.WorkspaceWizard>,
        ) => (
            <section aria-label={props.title}>
                <h1>{props.title}</h1>
                <h2>{props.steps[props.step].label}</h2>
                {props.children}
                <Button
                    onClick={() => props.setStep(Math.max(0, props.step - 1))}
                >
                    Back
                </Button>
                <Button
                    onClick={() => {
                        if (props.onValidateStep(props.step))
                            props.setStep(props.step + 1);
                    }}
                >
                    Continue
                </Button>
                <Button onClick={props.onSubmit}>Save booking request</Button>
            </section>
        ),
    };
});

import { BookingWizard } from './booking-wizard';

const vehicles = [1, 2].map((id) => ({
    id,
    name: `Vehicle ${id}`,
    asset_tag: `VH-${id}`,
    registration_number: `REG-${id}`,
    site: { id, name: `Site ${id}` },
}));
const summary = (id: number, request = true): VehicleCalendarSummary => ({
    asset: vehicles[id - 1],
    restriction: null,
    use_problem: null,
    use_problem_code: null,
    use_problem_kind: null,
    readiness_label: 'Not assessed',
    next_appointment: null,
    next_due: null,
    bookings: [],
    drivers: [],
    open_work: [],
    can: {
        request,
        view_bookings: true,
        manage: false,
        approve: false,
        authority: false,
        schedule_service: false,
        report_work: false,
        add_reminder: false,
        mark_unavailable: false,
        view_maintenance: false,
    },
});
const response = (id: number, request = true) =>
    new Response(JSON.stringify(summary(id, request)), { status: 200 });
const start = '2099-10-01T09:15';
const end = '2099-10-02T11:45';
const open = (initialVehicleId?: number, options = vehicles) =>
    render(
        <BookingWizard
            requestVehicles={options}
            initialVehicleId={initialVehicleId}
            mode={{ kind: 'request', startLocal: start, endLocal: end }}
            onClose={vi.fn()}
            onSaved={vi.fn()}
        />,
    );
afterEach(() => vi.unstubAllGlobals());

describe('Fleet request opens inside the canonical wizard', () => {
    it('shows vehicle and exact pickup/return fields together, and blocks unselected requests', () => {
        const fetcher = vi.fn();
        vi.stubGlobal('fetch', fetcher);
        open();
        expect(
            screen.getByRole('heading', { name: 'Request vehicle booking' }),
        ).toBeVisible();
        expect(screen.getByLabelText('Vehicle')).toBeVisible();
        expect(screen.getByLabelText('Pickup')).toHaveValue(
            start,
        );
        expect(screen.getByLabelText('Return')).toHaveValue(end);
        fireEvent.click(screen.getByText('Continue'));
        expect(
            screen.getByText('Choose a vehicle for this request.'),
        ).toBeVisible();
        fireEvent.click(screen.getByText('Save booking request'));
        expect(fetcher).not.toHaveBeenCalled();
    });

    it('retains entered times while loading and switching vehicles, and ignores late responses', async () => {
        let finishFirst!: (value: Response) => void;
        const fetcher = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise<Response>((resolve) => {
                        finishFirst = resolve;
                    }),
            )
            .mockResolvedValueOnce(response(2));
        vi.stubGlobal('fetch', fetcher);
        open();
        fireEvent.change(screen.getByLabelText('Pickup'), {
            target: { value: '2099-10-01T08:45' },
        });
        fireEvent.change(screen.getByLabelText('Vehicle'), {
            target: { value: '1' },
        });
        fireEvent.click(screen.getByText('Continue'));
        expect(
            screen.getByText('Wait for current booking permissions to load.'),
        ).toBeVisible();
        fireEvent.change(screen.getByLabelText('Vehicle'), {
            target: { value: '2' },
        });
        await waitFor(() =>
            expect(
                screen.queryByText(
                    'Loading current booking permissions and readiness…',
                ),
            ).toBeNull(),
        );
        await act(async () => {
            finishFirst(response(1, false));
        });
        expect((fetcher.mock.calls[0][1] as RequestInit).signal?.aborted).toBe(
            true,
        );
        expect(screen.queryByText(/not permitted for this vehicle/)).toBeNull();
        expect(screen.getByLabelText('Pickup')).toHaveValue(
            '2099-10-01T08:45',
        );
        expect(screen.getByLabelText('Return')).toHaveValue(end);
        fireEvent.click(screen.getByText('Continue'));
        expect(
            screen.getByRole('heading', { name: 'People & purpose' }),
        ).toBeVisible();
    });

    it('keeps the draft available when permissions fail and retries in place', async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValueOnce(new Response('', { status: 503 }))
            .mockResolvedValueOnce(response(1));
        vi.stubGlobal('fetch', fetcher);
        open();
        fireEvent.change(screen.getByLabelText('Vehicle'), {
            target: { value: '1' },
        });
        await screen.findByText(
            'Current booking permissions could not be loaded. Try again.',
        );
        expect(screen.getByLabelText('Return')).toHaveValue(end);
        fireEvent.click(screen.getByText('Try again'));
        await waitFor(() =>
            expect(
                screen.queryByText(
                    'Loading current booking permissions and readiness…',
                ),
            ).toBeNull(),
        );
        fireEvent.click(screen.getByText('Continue'));
        expect(
            screen.getByRole('heading', { name: 'People & purpose' }),
        ).toBeVisible();
        expect(fetcher).toHaveBeenCalledTimes(2);
    });

    it('retains a specifically selected vehicle and denies a withdrawn permission without substitution', async () => {
        const fetcher = vi.fn().mockResolvedValue(response(2, false));
        vi.stubGlobal('fetch', fetcher);
        open(2);
        expect(screen.queryByLabelText('Vehicle')).toBeNull();
        await screen.findByText(/Booking requests are not permitted/);
        fireEvent.click(screen.getByText('Continue'));
        expect(
            screen.getByRole('heading', { name: 'Vehicle & times' }),
        ).toBeVisible();
        expect(fetcher).toHaveBeenCalledWith(
            '/fleet-assets/vehicles/2/calendar/summary',
            expect.anything(),
        );
        expect(screen.getByLabelText('Pickup')).toHaveValue(
            start,
        );
    });

    it('explains empty scope without fetching or silently choosing a vehicle', () => {
        const fetcher = vi.fn();
        vi.stubGlobal('fetch', fetcher);
        open(undefined, []);
        expect(screen.getByRole('status')).toHaveTextContent(
            'No vehicles at your approved sites',
        );
        fireEvent.click(screen.getByText('Continue'));
        expect(
            screen.getByRole('heading', { name: 'Vehicle & times' }),
        ).toBeVisible();
        expect(fetcher).not.toHaveBeenCalled();
    });

    it('submits the selected vehicle and exact range through the canonical booking endpoint', async () => {
        const fetcher = vi
            .fn()
            .mockResolvedValueOnce(response(2))
            .mockResolvedValueOnce(
                new Response(
                    JSON.stringify({
                        message: 'Booking request submitted.',
                        booking: { id: 91 },
                    }),
                    { status: 200 },
                ),
            );
        vi.stubGlobal('fetch', fetcher);
        open();
        fireEvent.change(screen.getByLabelText('Vehicle'), {
            target: { value: '2' },
        });
        await waitFor(() =>
            expect(
                screen.queryByText(
                    'Loading current booking permissions and readiness…',
                ),
            ).toBeNull(),
        );
        fireEvent.click(screen.getByText('Continue'));
        fireEvent.change(screen.getByLabelText('Purpose'), {
            target: { value: 'Community journey' },
        });
        fireEvent.click(screen.getByText('Continue'));
        fireEvent.click(screen.getByText('Continue'));
        fireEvent.click(screen.getByText('Save booking request'));
        await waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
        expect(fetcher.mock.calls[1][0]).toBe('/fleet-assets/bookings');
        expect(JSON.parse(fetcher.mock.calls[1][1].body)).toMatchObject({
            asset_id: 2,
            starts_local: start,
            ends_local: end,
            approval_route: 'required',
            purpose: 'Community journey',
        });
    });
});
