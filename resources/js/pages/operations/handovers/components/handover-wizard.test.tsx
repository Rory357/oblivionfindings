/* eslint-disable no-restricted-syntax -- the two native buttons are deliberately
 * minimal controls inside the mocked WizardShell test harness. */
import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    axiosGet: vi.fn(),
    axiosPost: vi.fn(),
    inertiaPost: vi.fn(),
    inertiaPut: vi.fn(),
    onOpenChange: vi.fn(),
    toastSuccess: vi.fn(),
}));

vi.mock('axios', () => ({
    default: {
        get: (...args: unknown[]) => mocks.axiosGet(...args),
        post: (...args: unknown[]) => mocks.axiosPost(...args),
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (...args: unknown[]) => mocks.inertiaPost(...args),
        put: (...args: unknown[]) => mocks.inertiaPut(...args),
    },
}));

vi.mock('sonner', () => ({
    toast: {
        error: vi.fn(),
        success: (...args: unknown[]) => mocks.toastSuccess(...args),
        warning: vi.fn(),
    },
}));

vi.mock('@/components/wizard/shell', () => ({
    WizardShell: ({
        children,
        footerStart,
        footerEnd,
        onStepClick,
    }: {
        children: React.ReactNode;
        footerStart: React.ReactNode;
        footerEnd: React.ReactNode;
        onStepClick: (step: number) => void;
    }) => (
        <div>
            <button type="button" onClick={() => onStepClick(2)}>
                Show action step
            </button>
            <button type="button" onClick={() => onStepClick(3)}>
                Show review step
            </button>
            {children}
            {footerStart}
            {footerEnd}
        </div>
    ),
    WizardStepPane: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
}));

import { HandoverWizard } from './handover-wizard';
import {
    cardCounts,
    incomingHandoverShifts,
    outgoingHandoverShifts,
    type Catalogue,
    type CatalogueShift,
    type Handover,
} from './shared';

afterEach(cleanup);

beforeEach(() => {
    vi.clearAllMocks();
    mocks.axiosGet.mockResolvedValue({ data: { snapshot: null } });
    mocks.axiosPost.mockResolvedValue({ data: { locked: true } });
});

const shift = (
    id: number,
    over: Partial<CatalogueShift> = {},
): CatalogueShift => ({
    id,
    client_id: 7,
    site_id: 10,
    user_id: 2,
    service_context_id: 20,
    shift_type: 'support',
    status: 'scheduled',
    label: `Shift ${id}`,
    starts_at: '2026-08-28T20:00:00+12:00',
    ends_at: '2026-08-29T04:00:00+12:00',
    actual_ends_at: null,
    staff: { id: 2, name: 'Outgoing Worker' },
    ...over,
});

const baseShifts: CatalogueShift[] = [
    shift(100, {
        user_id: 2,
        status: 'in_progress',
        starts_at: '2026-08-28T12:00:00+12:00',
        ends_at: '2026-08-28T20:00:00+12:00',
    }),
    shift(101, { user_id: 3, staff: { id: 3, name: 'Eligible Witness' } }),
];

function catalogue(
    capabilities: Catalogue['capabilities'],
    shifts: CatalogueShift[] = baseShifts,
): Catalogue {
    return {
        clients: [
            {
                id: 7,
                first_name: 'Aroha',
                last_name: 'Kingi',
                service_context_id: 20,
                site_id: 10,
                medications: [{ id: 90, name: 'Regular medicine' }],
            },
        ],
        staff: [
            { id: 1, name: 'Current Manager' },
            { id: 2, name: 'Outgoing Worker' },
            { id: 3, name: 'Eligible Witness' },
            { id: 4, name: 'Foreign Site Worker' },
        ],
        staffBySite: {
            '10': [
                { id: 1, name: 'Current Manager' },
                { id: 2, name: 'Outgoing Worker' },
                { id: 3, name: 'Eligible Witness' },
            ],
        },
        sites: [{ id: 10, name: 'Tui House' }],
        serviceContexts: [{ id: 20, name: 'Residential', type: 'residential' }],
        shifts,
        controlledWitnessesBySite: {
            '10': [
                { id: 1, name: 'Current Manager' },
                { id: 2, name: 'Outgoing Worker' },
                { id: 3, name: 'Eligible Witness' },
            ],
        },
        capabilities,
    };
}

function handover(over: Partial<Handover> = {}): Handover {
    return {
        id: 50,
        status: 'draft',
        handover_notes: 'A clear and sufficiently detailed shift narrative.',
        client_mood: 'Settled',
        medications_due: ['Regular medicine — due 20:00'],
        cd_verification: {
            result: 'verified',
            witness_id: 3,
            witness_name: 'Eligible Witness',
            notes: 'Register matched.',
            verified_at: '2026-08-28T19:55:00+12:00',
            verified_by: 2,
            verified_by_name: 'Outgoing Worker',
        },
        cd_required: true,
        version: 4,
        edit_lock: null,
        incidents_to_note: [],
        follow_up_items: [],
        tasks_pending: [],
        created_at: '2026-08-28T19:56:00+12:00',
        submitted_at: null,
        acknowledged_at: null,
        client: {
            id: 7,
            first_name: 'Aroha',
            last_name: 'Kingi',
            site_id: 10,
        },
        site: { id: 10, name: 'Tui House' },
        outgoing_staff: { id: 2, name: 'Outgoing Worker' },
        incoming_staff: { id: 3, name: 'Eligible Witness' },
        submitted_incoming_staff: null,
        current_incoming_staff: { id: 3, name: 'Eligible Witness' },
        acknowledger: null,
        outgoing_shift: {
            id: 100,
            starts_at: '2026-08-28T12:00:00+12:00',
            ends_at: '2026-08-28T20:00:00+12:00',
            shift_type: 'support',
            label: 'Outgoing shift',
        },
        incoming_shift: {
            id: 101,
            starts_at: '2026-08-28T20:00:00+12:00',
            ends_at: '2026-08-29T04:00:00+12:00',
            shift_type: 'support',
            label: 'Incoming shift',
        },
        can_submit: true,
        can_acknowledge: false,
        can_edit: true,
        lock: {
            locked: false,
            reason: 'draft',
            days_left: null,
            age_days: null,
        },
        ...over,
    };
}

function renderWizard(
    capabilities: Catalogue['capabilities'],
    editing: Handover | null = handover(),
) {
    return render(
        <HandoverWizard
            open
            onOpenChange={mocks.onOpenChange}
            editing={editing}
            catalogue={catalogue(capabilities)}
            currentUser={{ id: 1, name: 'Current Manager' }}
            preselectClientId={null}
            onAddClient={vi.fn()}
            onSubmitted={vi.fn()}
            basePath="/emar/handovers"
            medicationFocus
        />,
    );
}

describe('handover wizard governance contracts', () => {
    it('offers only canonical outgoing and bounded incoming shifts', () => {
        const shifts = [
            ...baseShifts,
            shift(102, { user_id: 1 }),
            shift(103, { user_id: null, staff: null }),
            shift(104, { status: 'completed' }),
            shift(105, { starts_at: '2026-08-29T09:00:01+12:00' }),
            shift(106, {
                starts_at: '2026-08-28T19:30:00+12:00',
                ends_at: '2026-08-28T21:00:00+12:00',
            }),
            shift(107, { service_context_id: 999 }),
            shift(108, { site_id: 999 }),
            shift(109, { site_id: null }),
        ];

        expect(
            outgoingHandoverShifts(shifts, 7, 1, false).map((s) => s.id),
        ).toEqual([102]);
        expect(
            outgoingHandoverShifts(shifts, 7, 1, true).map((s) => s.id),
        ).not.toContain(103);
        expect(
            outgoingHandoverShifts(shifts, 7, 1, true).map((s) => s.id),
        ).not.toContain(109);
        expect(incomingHandoverShifts(shifts, 7, 100).map((s) => s.id)).toEqual(
            [101, 102],
        );
    });

    it('uses the actual outgoing finish for the bounded incoming window', () => {
        const shifts = [
            shift(200, {
                status: 'in_progress',
                starts_at: '2026-08-28T12:00:00+12:00',
                ends_at: '2026-08-28T20:00:00+12:00',
                actual_ends_at: '2026-08-28T21:00:00+12:00',
            }),
            shift(201, { starts_at: '2026-08-28T20:30:00+12:00' }),
            shift(202, { starts_at: '2026-08-28T21:00:00+12:00' }),
            shift(203, { starts_at: '2026-08-29T09:00:01+12:00' }),
        ];

        expect(incomingHandoverShifts(shifts, 7, 200).map((s) => s.id)).toEqual(
            [202],
        );
    });

    it('counts the canonical attendance medication-due marker on handover cards', () => {
        expect(
            cardCounts(
                handover({
                    medications_due: [
                        'Outstanding medications due from previous shift',
                    ],
                }),
            ),
        ).toMatchObject({ meds: 1, medsTotal: 1 });
    });

    it('conceals controlled evidence and controls without view capability', async () => {
        renderWizard({
            view_controlled: false,
            record_controlled: true,
            manage_any_shifts: true,
        });
        await waitFor(() =>
            expect(
                screen.queryByText('Securing this draft for editing…'),
            ).not.toBeInTheDocument(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Show action step' }),
        );

        expect(
            screen.queryByText(/Controlled-drug count evidence/i),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText('Witness password or PIN'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/Open CD register/i)).not.toBeInTheDocument();
    });

    it('shows immutable evidence to a view-only reader without mutation fields', async () => {
        renderWizard(
            {
                view_controlled: true,
                record_controlled: false,
                manage_any_shifts: true,
            },
            handover({
                status: 'submitted',
                can_edit: false,
                can_submit: false,
                submitted_at: '2026-08-28T20:00:00+12:00',
                submitted_incoming_staff: {
                    id: 3,
                    name: 'Submitted Recipient',
                },
                current_incoming_staff: {
                    id: 4,
                    name: 'Current Ack Assignee',
                },
            }),
        );
        await waitFor(() =>
            expect(
                screen.queryByText('Securing this draft for editing…'),
            ).not.toBeInTheDocument(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Show review step' }),
        );

        expect(
            screen.getByText('Controlled-drug count evidence'),
        ).toBeInTheDocument();
        expect(screen.getByText('Submitted recipient')).toBeInTheDocument();
        expect(screen.getByText('Submitted Recipient')).toBeInTheDocument();
        expect(
            screen.getByText('Current acknowledgement assignee'),
        ).toBeInTheDocument();
        expect(screen.getByText('Current Ack Assignee')).toBeInTheDocument();
        expect(screen.getAllByText('Eligible Witness')).not.toHaveLength(0);
        expect(
            screen.queryByLabelText('Witness password or PIN'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Save changes' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Continue' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Close' }),
        ).toBeInTheDocument();
    });

    it('uses the Site witness catalogue, excludes the actor, and clears the one-shot secret', async () => {
        renderWizard({
            view_controlled: true,
            record_controlled: true,
            manage_any_shifts: true,
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Show action step' }),
        );

        await waitFor(() =>
            expect(
                screen.getByLabelText('Witness (second checker)'),
            ).toBeEnabled(),
        );
        const witness = screen.getByLabelText('Witness (second checker)');
        expect(
            screen.queryByRole('option', { name: 'Current Manager' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Outgoing Worker' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Eligible Witness' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', { name: 'Foreign Site Worker' }),
        ).not.toBeInTheDocument();

        const credential = screen.getByLabelText('Witness password or PIN');
        fireEvent.change(credential, { target: { value: 'one-shot-secret' } });
        expect(credential).toHaveValue('one-shot-secret');
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(credential).toHaveValue('');
        expect(mocks.onOpenChange).toHaveBeenCalledWith(false);
    });

    it('labels the irreversible draft edit action as submission and reports the submitted result', async () => {
        renderWizard({
            view_controlled: true,
            record_controlled: true,
            manage_any_shifts: true,
        });
        await waitFor(() =>
            expect(
                screen.queryByText('Securing this draft for editing…'),
            ).not.toBeInTheDocument(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Show review step' }),
        );

        expect(
            screen.queryByRole('button', { name: 'Save changes' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit handover' }),
        );

        await waitFor(() => expect(mocks.inertiaPut).toHaveBeenCalledOnce());
        const [target, payload, options] = mocks.inertiaPut.mock.calls[0];
        expect(target).toBe('/emar/handovers/50');
        expect(payload).toMatchObject({ submit: true, version: 4 });

        options.onSuccess();
        expect(mocks.toastSuccess).toHaveBeenCalledWith(
            'Handover submitted for Aroha Kingi',
        );
    });
});
