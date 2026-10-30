import { Button } from '@/components/ui/button';
import { fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import ShiftMedicationCard from './shift-medication-card';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children?: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    router: { reload: vi.fn() },
    usePage: () => ({ props: { auth: { user: { name: 'Priya Shah' } } } }),
}));
vi.mock('@/components/emar/record-dose/record-dose-dialog', () => ({
    RecordDoseDialog: ({
        target,
        entry,
        shiftContext,
        onClose,
    }: {
        target: { orderId: number; kind: string; scheduledFor?: string };
        entry: string;
        shiftContext: { shiftId: number };
        onClose: () => void;
    }) => (
        <div role="dialog" aria-label="Record a dose">
            {entry}|{target.kind}|{target.orderId}|{target.scheduledFor}|
            {shiftContext.shiftId}
            <Button onClick={onClose}>Cancel</Button>
        </div>
    ),
}));

type Props = ComponentProps<typeof ShiftMedicationCard>;
const scheduledFor = '2026-09-29T08:00:00+13:00';
function props(): Props {
    return {
        clientId: 5,
        shiftId: 9,
        shiftStatus: 'in_progress',
        canRecord: true,
        canRecordControlled: false,
        witnesses: [],
        summary: {
            stats: { scheduled: { due: 1 } },
            allergies: [],
            recent_history: [],
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
            prn: [
                {
                    client_medication_id: 12,
                    can_record: true,
                    medication: {
                        id: 12,
                        name: 'As-needed medicine',
                        dosage: '1 tablet',
                        is_prn: true,
                    },
                },
            ],
            dose_window: { early_minutes: 90, late_minutes: 15 },
        },
    };
}

describe('Shift medicines shared recorder', () => {
    it('keeps the scheduled dose identity and shift through the shared recorder and allows cancellation', () => {
        render(<ShiftMedicationCard {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Record' }));
        expect(
            screen.getByRole('dialog', { name: 'Record a dose' }),
        ).toHaveTextContent(`shift|scheduled|11|${scheduledFor}|9`);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('opens the chosen PRN through the same recorder', () => {
        render(<ShiftMedicationCard {...props()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Record PRN' }));
        expect(
            screen.getByRole('dialog', { name: 'Record a dose' }),
        ).toHaveTextContent('shift|prn|12||9');
    });
    it.each(['completed', 'cancelled'])(
        'does not offer recording from a %s shift',
        (shiftStatus) => {
            render(
                <ShiftMedicationCard {...props()} shiftStatus={shiftStatus} />,
            );
            expect(
                screen.queryByRole('button', { name: 'Record' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Record PRN' }),
            ).not.toBeInTheDocument();
        },
    );
    it('does not offer a controlled dose to a worker without controlled-record authority', () => {
        const data = props();
        data.summary!.due[0].medication.controlled_drug = true;
        render(<ShiftMedicationCard {...data} />);
        expect(
            screen.queryByRole('button', { name: 'Record' }),
        ).not.toBeInTheDocument();
    });
});
