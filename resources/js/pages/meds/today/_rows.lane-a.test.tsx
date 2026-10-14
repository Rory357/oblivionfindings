/* eMAR audit Lane A (EA-116, EA-043): a Meds today row says when its dose is
 * saved on this device (and can't be recorded again), and when it is an
 * evening dose still owed after midnight. */
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import type { OfflineQueueState } from '@/lib/offline-queue';

import { DoseActionCell, DoseStateCell, mainAction } from './_rows';
import type { ScheduleRow } from './types';

vi.mock('@/hooks/use-offline-queue', () => ({
    useOfflineQueueState: vi.fn(),
}));
const queueState = vi.hoisted(() => ({
    current: null as OfflineQueueState | null,
}));
vi.mock('@/lib/offline-queue', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/offline-queue')>();
    return {
        ...actual,
        getOfflineQueueSnapshot: () => queueState.current,
    };
});

const emptyQueue: OfflineQueueState = {
    online: true,
    pendingCount: 0,
    needsAttentionCount: 0,
    pendingSubmissions: [],
    rejectedCount: 0,
    rejectedSubmissions: [],
    syncing: false,
};

function row(overrides: Partial<ScheduleRow> = {}): ScheduleRow {
    return {
        key: '41:202604292000',
        client_id: 201,
        client_name: 'Aroha Ngata',
        medication_id: 41,
        medication_name: 'Losartan 50mg',
        dose: '1 tablet',
        route: 'Oral',
        is_controlled: false,
        requires_witness: false,
        scheduled_for: '2026-04-30T08:00:00+12:00',
        state: 'due',
        time: '08:00',
        round_label: 'Morning',
        status: 'due',
        recorded: null,
        mar_url: null,
        window_opens_at: '2026-04-30T07:30:00+12:00',
        window_ends_at: '2026-04-30T09:00:00+12:00',
        req: null,
        ...overrides,
    } as ScheduleRow;
}

const can = { canRecord: () => true, canReportError: false };
const actions = {
    record: vi.fn(),
    notGiven: vi.fn(),
    reoffer: vi.fn(),
    why: vi.fn(),
    detail: vi.fn(),
    chart: vi.fn(),
    reportError: vi.fn(),
};

function queued(): OfflineQueueState {
    return {
        ...emptyQueue,
        pendingCount: 1,
        pendingSubmissions: [
            {
                id: 'saved',
                actorId: '71',
                action: 'administration',
                method: 'post',
                url: '/meds/today/record',
                // The dialog sends the same due time, in UTC or with an offset.
                payload: {
                    client_medication_id: 41,
                    scheduled_for: '2026-04-29T20:00:00.000Z',
                },
                createdAt: '2026-04-29T20:01:00.000Z',
                lastAttemptAt: null,
                attempts: 0,
                lastError: null,
            },
        ],
    };
}

describe('Meds today rows — Lane A', () => {
    beforeEach(() => {
        queueState.current = emptyQueue;
        vi.mocked(useOfflineQueueState).mockReturnValue(emptyQueue);
    });

    it('says a dose saved on this device isn’t on the chart and blocks recording it again (EA-116)', () => {
        queueState.current = queued();
        vi.mocked(useOfflineQueueState).mockReturnValue(queued());

        render(<DoseStateCell row={row()} person="Aroha" />);
        expect(screen.getByText('Saved on this device')).toBeInTheDocument();
        expect(
            screen.getByText(/Not on the chart yet — it sends automatically/),
        ).toBeInTheDocument();

        render(<DoseActionCell row={row()} can={can} actions={actions} />);
        expect(
            screen.getByRole('button', { name: /Waiting to send/ }),
        ).toBeDisabled();
        expect(
            screen.queryByRole('button', { name: 'Record' }),
        ).not.toBeInTheDocument();
        expect(mainAction(row(), can, actions)).toBeNull();
    });

    it('marks an evening dose still owed after midnight (EA-043)', () => {
        render(
            <DoseStateCell
                row={row({
                    scheduled_for: '2026-04-30T22:00:00+12:00',
                    status: 'overdue',
                    state: 'not_recorded',
                    from_yesterday: true,
                })}
                person="Aroha"
            />,
        );
        expect(
            screen.getByText('From yesterday · nothing recorded yet'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/yesterday · outside its window/),
        ).toBeInTheDocument();
    });
});
