import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EventRecordSheet from './event-record-sheet';

const { post, fetchMock } = vi.hoisted(() => ({
    post: vi.fn(),
    fetchMock: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ router: { post } }));
// The date picker has its own interaction coverage; retain the real offset/validation adapter here.
vi.mock('@/components/fleet-assets/maintenance/date-time-field', () => ({
    DateTimeField: ({
        label,
        value,
        onChange,
    }: {
        label: string;
        value: string;
        onChange: (s: string) => void;
    }) => (
        <label>
            {label}
            <input value={value} onChange={(e) => onChange(e.target.value)} />
        </label>
    ),
}));
const props = { clientId: 10, open: true, onOpenChange: vi.fn() };
const admission = {
    id: 72,
    occurred_at: '2026-01-01T23:00:00Z',
    reported_at: '2026-01-01T23:05:00Z',
};
function response(rows = [admission]) {
    return new Response(JSON.stringify({ admissions: rows }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
    });
}
async function selectType(label: string) {
    fireEvent.click(screen.getByRole('combobox', { name: 'Event type' }));
    fireEvent.click(await screen.findByRole('option', { name: label }));
}
function fill() {
    fireEvent.change(screen.getByLabelText('When it happened'), {
        target: { value: '2026-01-03T10:15' },
    });
    fireEvent.change(screen.getByLabelText('Description'), {
        target: {
            value: 'Returned from hospital with discharge documentation.',
        },
    });
}
async function endAdmission() {
    fireEvent.click(
        await screen.findByRole('combobox', { name: 'Admission being ended' }),
    );
    fireEvent.click(await screen.findByRole('option', { name: /Admitted/ }));
}
describe('clinical actual hospital event workflow', () => {
    beforeEach(() => {
        post.mockReset();
        fetchMock.mockReset();
        fetchMock.mockResolvedValue(response());
        vi.stubGlobal('fetch', fetchMock);
    });
    afterEach(() => vi.unstubAllGlobals());
    it('requires a specific admission and final confirmation, then sends an offset time', async () => {
        render(<EventRecordSheet {...props} />);
        await selectType('Hospital discharge');
        fill();
        await screen.findByRole('combobox', { name: 'Admission being ended' });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText(
                'Choose the hospital admission this discharge ends.',
            ),
        ).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
        await endAdmission();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Record event' }));
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Record event',
            }),
        );
        expect(post).toHaveBeenCalledWith(
            '/clients/10/clinical/events',
            expect.objectContaining({
                event_type: 'hospital_discharge',
                hospital_admission_id: 72,
                occurred_at: '2026-01-03T10:15:00+13:00',
            }),
            expect.any(Object),
        );
        const options = post.mock.calls[0][2];
        act(() => {
            options.onError({
                hospital_admission_id:
                    'This admission has already been discharged.',
            });
            options.onFinish();
        });
        expect(
            screen.getByText('This admission has already been discharged.'),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Description')).toHaveValue(
            'Returned from hospital with discharge documentation.',
        );
    });
    it('keeps the entry through a failed admission load and permits retry', async () => {
        fetchMock.mockResolvedValueOnce(
            new Response('<html>Login</html>', {
                status: 200,
                headers: { 'content-type': 'text/html' },
            }),
        );
        render(<EventRecordSheet {...props} />);
        await selectType('Hospital discharge');
        fill();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Try again' }),
        );
        await screen.findByRole('combobox', { name: 'Admission being ended' });
        expect(screen.getByLabelText('Description')).toHaveValue(
            'Returned from hospital with discharge documentation.',
        );
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(post).not.toHaveBeenCalled();
    });
    it('clears a draft when the person context changes and uses the shift endpoint', async () => {
        const view = render(<EventRecordSheet {...props} />);
        await selectType('Hospital discharge');
        fill();
        await endAdmission();
        view.rerender(
            <EventRecordSheet {...props} clientId={11} shiftId={88} />,
        );
        expect(screen.getByLabelText('Description')).toHaveValue('');
        expect(
            screen.getByRole('combobox', { name: 'Event type' }),
        ).toHaveTextContent('Other Clinical Event');
        await selectType('Hospital discharge');
        await waitFor(() =>
            expect(fetchMock).toHaveBeenLastCalledWith(
                '/shifts/88/clinical/hospital-admissions',
                expect.objectContaining({ signal: expect.any(AbortSignal) }),
            ),
        );
        expect(post).not.toHaveBeenCalled();
    });
    it('preserves required immediate action for Health and Safety-linked events', async () => {
        render(<EventRecordSheet {...props} />);
        await selectType('Fall');
        fill();
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText('Record what was done straight away.'),
        ).toBeInTheDocument();
        expect(post).not.toHaveBeenCalled();
    });

    it('reviews and submits an already-ended hospital stay as one request and retains a rejected draft', async () => {
        render(<EventRecordSheet {...props} />);
        await selectType('Hospital admission');
        fill();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'This hospital stay has already ended',
            }),
        );
        fireEvent.change(screen.getByLabelText('Actually discharged'), {
            target: { value: '2026-01-04T11:00' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByText('Hospital admission and discharge'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Actually admitted · Pacific/Auckland'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Actually discharged · Pacific/Auckland'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Record event' }));
        expect(post).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Record event',
            }),
        );
        expect(post.mock.calls[0][1]).toEqual(
            expect.objectContaining({
                event_type: 'hospital_admission',
                occurred_at: '2026-01-03T10:15:00+13:00',
                hospital_discharged_at: '2026-01-04T11:00:00+13:00',
            }),
        );
        expect(post.mock.calls[0][1]).not.toHaveProperty(
            'hospital_admission_id',
        );
        expect(fetchMock).not.toHaveBeenCalled();
        const callbacks = post.mock.calls[0][2];
        act(() => {
            callbacks.onError({
                hospital_discharged_at: 'This stay overlaps another admission.',
            });
            callbacks.onFinish();
        });
        expect(screen.getByLabelText('When it happened')).toHaveValue(
            '2026-01-03T10:15',
        );
        expect(screen.getByLabelText('Actually discharged')).toHaveValue(
            '2026-01-04T11:00',
        );
        expect(
            screen.getByRole('checkbox', {
                name: 'This hospital stay has already ended',
            }),
        ).toBeChecked();
    });
    it.each(['', '2026-01-02T09:00', '2099-01-04T11:00'])(
        'rejects an invalid paired discharge %s before submission',
        async (value) => {
            render(<EventRecordSheet {...props} />);
            await selectType('Hospital admission');
            fill();
            fireEvent.click(
                screen.getByRole('checkbox', {
                    name: 'This hospital stay has already ended',
                }),
            );
            fireEvent.change(screen.getByLabelText('Actually discharged'), {
                target: { value },
            });
            fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
            expect(
                screen.getByLabelText('When it happened'),
            ).toBeInTheDocument();
            expect(
                screen.getAllByText(
                    /(Choose the actual discharge|Discharge must be)/,
                ).length,
            ).toBeGreaterThan(0);
            expect(post).not.toHaveBeenCalled();
        },
    );
    it('drops the paired discharge when changing to another event type', async () => {
        render(<EventRecordSheet {...props} />);
        await selectType('Hospital admission');
        fill();
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: 'This hospital stay has already ended',
            }),
        );
        fireEvent.change(screen.getByLabelText('Actually discharged'), {
            target: { value: '2026-01-04T11:00' },
        });
        await selectType('Other Clinical Event');
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Record event' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Record event',
            }),
        );
        expect(post.mock.calls[0][1]).toHaveProperty('event_type', 'other');
        expect(post.mock.calls[0][1]).not.toHaveProperty(
            'hospital_discharged_at',
        );
    });
});
