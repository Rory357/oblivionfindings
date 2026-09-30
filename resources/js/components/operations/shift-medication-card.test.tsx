import { fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        Link: ({ href, children }: { href: string; children?: ReactNode }) =>
            ReactActual.createElement('a', { href }, children),
        router: { reload: vi.fn() },
        useForm: (initial: Record<string, unknown>) => {
            const [data, setDataState] = ReactActual.useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key: string, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                reset: () => setDataState(initial),
            };
        },
    };
});

vi.mock('@/lib/emar-offline', () => ({
    emarMutationWasAccepted: vi.fn(() => true),
    submitEmarMutation: vi.fn(),
}));

import ShiftMedicationCard from './shift-medication-card';

type Summary = NonNullable<
    ComponentProps<typeof ShiftMedicationCard>['summary']
>;

const scheduledFor = '2026-09-29T08:00:00+13:00';

function summaryWithWindow(doseWindow: Summary['dose_window']): Summary {
    return {
        stats: { scheduled: { due: 1 } },
        allergies: [],
        due: [
            {
                client_medication_id: 11,
                scheduled_for: scheduledFor,
                scheduled_time: '08:00',
                schedule_state: 'due',
                can_record: true,
                medication: {
                    id: 11,
                    name: 'Paracetamol',
                    dosage: '500 mg',
                },
            },
        ],
        prn: [],
        recent_history: [],
        dose_window: doseWindow,
    };
}

// The dialog's "Administered at" field is a local datetime-local value.
function localInputAt(offsetMinutes: number): string {
    const d = new Date(Date.parse(scheduledFor) + offsetMinutes * 60000);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function openDialogGivenAt(
    doseWindow: Summary['dose_window'],
    offsetMinutes: number,
) {
    render(
        <ShiftMedicationCard
            clientId={5}
            shiftId={9}
            shiftStatus="in_progress"
            canRecord
            canRecordControlled={false}
            summary={summaryWithWindow(doseWindow)}
            witnesses={[]}
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Record' }));

    const administeredAt = document.querySelector<HTMLInputElement>(
        'input[type="datetime-local"]',
    );
    expect(administeredAt).not.toBeNull();
    fireEvent.change(administeredAt!, {
        target: { value: localInputAt(offsetMinutes) },
    });
}

const saveButton = () =>
    screen.getByRole('button', { name: 'Save administration' });

describe('ShiftMedicationCard dose window', () => {
    const serverDefault = { early_minutes: 30, late_minutes: 60 };

    it('asks for a reason when a dose is given 45 minutes early', () => {
        openDialogGivenAt(serverDefault, -45);

        expect(
            screen.getByText(
                'This is more than 30 minutes before the scheduled time. Add a reason.',
            ),
        ).toBeInTheDocument();
        expect(saveButton()).toBeDisabled();
    });

    it('does not ask for a reason when a dose is given 45 minutes late', () => {
        openDialogGivenAt(serverDefault, 45);

        expect(screen.queryByText(/Add a reason\./)).not.toBeInTheDocument();
        expect(saveButton()).toBeEnabled();
    });

    it.each([-30, 60])(
        'treats the window edge (%i minutes) as on time, like the server',
        (offset) => {
            openDialogGivenAt(serverDefault, offset);

            expect(saveButton()).toBeEnabled();
        },
    );

    it('asks for a reason once a dose is past the late edge', () => {
        openDialogGivenAt(serverDefault, 61);

        expect(
            screen.getByText(
                'This is more than 60 minutes after the scheduled time. Add a reason.',
            ),
        ).toBeInTheDocument();
        expect(saveButton()).toBeDisabled();

        fireEvent.change(
            screen.getByPlaceholderText('Required for this administration'),
            { target: { value: 'Client was at an appointment' } },
        );

        expect(saveButton()).toBeEnabled();
    });

    it('uses the window the server sends, not a built-in default', () => {
        openDialogGivenAt({ early_minutes: 90, late_minutes: 15 }, -45);
        expect(saveButton()).toBeEnabled();

        fireEvent.change(
            document.querySelector<HTMLInputElement>(
                'input[type="datetime-local"]',
            )!,
            { target: { value: localInputAt(20) } },
        );

        expect(
            screen.getByText(
                'This is more than 15 minutes after the scheduled time. Add a reason.',
            ),
        ).toBeInTheDocument();
        expect(saveButton()).toBeDisabled();
    });
});
