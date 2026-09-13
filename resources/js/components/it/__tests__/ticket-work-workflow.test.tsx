import {
    freezeItCommentIntent,
    itCommentFormData,
} from '@/hooks/it-ticket-comment-contract';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    TicketWorkFields,
    TicketWorkPersonPicker,
} from '../ticket-work-fields';
import {
    PRIORITY_LABELS,
    readWorkNote,
    workLocal,
    type TicketWork,
} from '../ticket-work-types';

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: { ...mocks, isAxiosError: () => false } }));
const work: TicketWork = {
    ready: true,
    timezone: 'Pacific/Auckland',
    recipients: [{ id: 2, name: 'Maya', email: 'maya@example.test' }],
    requester: { id: 2, name: 'Maya' },
    bookings: [],
};
function Form({
    initial = '',
    internal = true,
}: {
    initial?: string;
    internal?: boolean;
}) {
    const [value, setValue] = useState(initial);
    return (
        <>
            <TicketWorkFields
                ticketId={7}
                actorId={1}
                value={value}
                onChange={setValue}
                disabled={false}
                work={work}
                internal={internal}
            />
            <output data-testid="payload">{value}</output>
        </>
    );
}
afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
});
describe('ticket work controls', () => {
    it('keeps suggested break allocations and asks the technician to review them', async () => {
        const periods = [
            {
                starts_at: '2026-07-01T04:30:00Z',
                ends_at: '2026-07-01T05:00:00Z',
                break_minutes: 5,
                after_hours: false,
                work_type: 'remote',
            },
            {
                starts_at: '2026-07-01T05:00:00Z',
                ends_at: '2026-07-01T05:30:00Z',
                break_minutes: 5,
                after_hours: true,
                work_type: 'remote',
            },
        ];
        mocks.post.mockResolvedValue({
            data: { periods, allocated_break_minutes: 10 },
        });
        render(
            <Form
                initial={JSON.stringify({
                    periods: [
                        {
                            ...periods[0],
                            ends_at: periods[1].ends_at,
                            break_minutes: 10,
                        },
                    ],
                })}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Suggest after-hours split' }),
        );
        await screen.findByText(/10 break minutes have been shared/);
        expect(
            readWorkNote(screen.getByTestId('payload').textContent ?? '')
                .periods,
        ).toEqual(periods);
    });
    it('shows all four priority identifiers without changing stored priorities', () => {
        expect(PRIORITY_LABELS).toEqual({
            urgent: 'P1 · Critical',
            high: 'P2 · High',
            normal: 'P3 · Medium',
            low: 'P4 · Low',
        });
    });
    it('adds an actual entry beside the note with an explicit after-hours flag and breaks', () => {
        render(<Form />);
        fireEvent.click(screen.getByRole('button', { name: 'Add time entry' }));
        fireEvent.change(screen.getByLabelText('Started · entry 1'), {
            target: { value: '2026-07-01T23:30' },
        });
        fireEvent.change(screen.getByLabelText('Ended · entry 1'), {
            target: { value: '2026-07-02T00:30' },
        });
        fireEvent.change(screen.getByLabelText('Breaks (minutes)'), {
            target: { value: '10' },
        });
        fireEvent.click(screen.getByLabelText('Work done after hours'));
        expect(
            readWorkNote(screen.getByTestId('payload').textContent ?? '')
                .periods?.[0],
        ).toMatchObject({
            starts_at: '2026-07-01T23:30',
            ends_at: '2026-07-02T00:30',
            break_minutes: 10,
            after_hours: true,
        });
    });
    it('keeps separate work periods when a paused timer resumes', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-07-01T00:00:00Z'));
        render(<Form />);
        fireEvent.click(screen.getByRole('button', { name: 'Start timer' }));
        vi.setSystemTime(new Date('2026-07-01T00:10:00Z'));
        fireEvent.click(screen.getByRole('button', { name: 'Pause' }));
        vi.setSystemTime(new Date('2026-07-01T00:15:00Z'));
        fireEvent.click(screen.getByRole('button', { name: 'Resume' }));
        vi.setSystemTime(new Date('2026-07-01T00:35:00Z'));
        fireEvent.click(
            screen.getByRole('button', { name: 'Stop and review time' }),
        );
        const note = readWorkNote(
            screen.getByTestId('payload').textContent ?? '',
        );
        expect(note.timer).toBeUndefined();
        expect(note.periods).toHaveLength(2);
        expect(
            note.periods?.reduce(
                (sum, period) =>
                    sum +
                    (Date.parse(period.ends_at) -
                        Date.parse(period.starts_at)) /
                        60000,
                0,
            ),
        ).toBe(30);
    });
    it('retains explicit recipient selection independently from internal time', () => {
        render(<Form internal={false} />);
        fireEvent.click(screen.getByText('Notify selected people'));
        fireEvent.click(screen.getByLabelText('Maya · maya@example.test'));
        expect(
            readWorkNote(screen.getByTestId('payload').textContent ?? '')
                .recipient_user_ids,
        ).toEqual([]);
        expect(
            screen.getByText(/public reply remains visible/),
        ).toBeInTheDocument();
    });
    it('searches the ticket directory and disables unavailable technicians', async () => {
        mocks.get.mockResolvedValue({
            data: {
                options: [
                    {
                        id: 9,
                        name: 'Avery',
                        email: 'avery@example.test',
                        busy: true,
                    },
                    { id: 10, name: 'Casey', busy: false },
                ],
            },
        });
        const select = vi.fn();
        render(
            <TicketWorkPersonPicker
                ticketId={7}
                label="Technician"
                onSelect={select}
                startsAt="2026-09-20T10:00"
                endsAt="2026-09-20T11:00"
            />,
        );
        fireEvent.focus(screen.getByRole('combobox'));
        fireEvent.change(screen.getByRole('combobox'), {
            target: { value: 'Casey' },
        });
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: /Avery.*Unavailable/ }),
            ).toBeDisabled(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Casey · Available' }),
        );
        expect(select).toHaveBeenCalledWith(
            expect.objectContaining({ id: 10 }),
        );
        expect(mocks.get).toHaveBeenLastCalledWith(
            '/it/tickets/7/work/people',
            expect.objectContaining({
                params: expect.objectContaining({
                    q: 'Casey',
                    starts_at: '2026-09-20T10:00',
                }),
            }),
        );
    });
    it('reconstructs the exact work payload on every comment retry', () => {
        const intent = freezeItCommentIntent({
            actorId: 1,
            ticketId: 7,
            requestUuid: 'a0625856-62fd-4c9f-8d90-f69e36ed8720',
            expectedVersion: 3,
            body: 'Worked on the printer',
            isInternal: true,
            files: [],
            workPayload: '{"periods":[]}',
        });
        expect(itCommentFormData(intent).get('work_payload')).toBe(
            '{"periods":[]}',
        );
        expect(itCommentFormData(intent).get('request_uuid')).toBe(
            intent.requestUuid,
        );
    });
    it('renders dates in Auckland regardless of browser timezone', () => {
        expect(workLocal('2026-07-01T00:00:00Z')).toBe('2026-07-01T12:00');
    });
});
