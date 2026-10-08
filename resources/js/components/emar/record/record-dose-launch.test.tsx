import {
    doseRecoveryKey,
    keepDoseRecovery,
} from '@/components/emar/record-dose/recovery';
import { setOfflineQueueActor } from '@/lib/offline-queue';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RecordDoseLaunch } from './record-dose-launch';

const state = vi.hoisted(() => ({
    load: 'ready',
    record: true,
    reason: null as string | null,
    hasPrn: true,
    hidden: 0,
    recordAsNeeded: vi.fn(),
    recordScheduled: vi.fn(),
    reload: vi.fn(),
    request: vi.fn(),
}));

vi.mock('./use-record-json', () => ({
    useRecordJson: (url: string) => {
        state.request(url);
        return {
            load: state.load,
            reload: state.reload,
            data:
                state.load !== 'ready'
                    ? null
                    : {
                          date: '2026-10-07',
                          medicines: [],
                          can: {
                              record: state.record,
                              record_reason: state.reason,
                              record_controlled: false,
                          },
                          hidden_controlled: { total: 0, overdue: 0 },
                          prn: {
                              hidden: state.hidden,
                              rows: state.hasPrn
                                  ? [
                                        {
                                            id: 73,
                                            client_id: 16,
                                            client_name: 'Aroha',
                                            name: 'Synthetic as-needed tablet',
                                            given_last_24h: 0,
                                            max_per_day: null,
                                            last_given_label: null,
                                            over_limit: false,
                                            interval_blocked: false,
                                            is_controlled: false,
                                        },
                                    ]
                                  : [],
                          },
                          recorder: {
                              client: { id: 16, name: 'Aroha' },
                              witnesses: [],
                              not_given_reasons: [],
                              signed_as: {
                                  name: 'Worker',
                                  role_label: 'Support worker',
                              },
                          },
                      },
        };
    },
}));
vi.mock('@/components/emar/recording/use-dose-recorder', () => ({
    useDoseRecorder: () => ({
        element: null,
        recordAsNeeded: state.recordAsNeeded,
        recordScheduled: state.recordScheduled,
    }),
}));

beforeEach(() => {
    setOfflineQueueActor(null);
    setOfflineQueueActor(71);
    vi.clearAllMocks();
    Object.assign(state, {
        load: 'ready',
        record: true,
        reason: null,
        hasPrn: true,
        hidden: 0,
    });
});

function open() {
    render(<RecordDoseLaunch clientId={16} personName="Aroha" asNeeded />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Record as-needed dose' }),
    );
}

describe('MAR as-needed recording entry', () => {
    it('keeps unrelated as-needed choices available beside an unconfirmed attempt', () => {
        keepDoseRecovery(doseRecoveryKey(16, 41, '2026-10-08T08:00:00+13:00'), {
            display: { order: { name: 'Original antihistamine' } },
            form: { when: '2026-10-08T08:05', outcome: 'given' },
            target: {
                kind: 'scheduled',
                orderId: 41,
                scheduledFor: '2026-10-08T08:00:00+13:00',
            },
        });
        open();
        expect(
            screen.getByText('Unconfirmed attempt · Original antihistamine'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('8 Oct 2026 · 8:05 am · NZ time · given'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/even if the medicine order has changed/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Check original attempt' }),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Choose an as-needed medicine',
            }),
        );
        expect(state.recordAsNeeded).toHaveBeenCalledOnce();
        expect(state.recordScheduled).not.toHaveBeenCalled();
    });
    it('opens the current person’s searchable medicine picker directly and uses the canonical recorder', () => {
        open();
        expect(screen.getByRole('dialog')).toHaveAccessibleName(
            'Record an as-needed dose',
        );
        expect(
            screen.getByRole('combobox', {
                name: 'Search as-needed medicines',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('No scheduled doses are due now'),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/maximum not recorded/)).toBeInTheDocument();
        expect(screen.queryByText(/no limit/)).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('option', { name: /Synthetic as-needed tablet/ }),
        );
        expect(state.recordAsNeeded).toHaveBeenCalledExactlyOnceWith(73);
        expect(state.recordScheduled).not.toHaveBeenCalled();
        expect(state.request).toHaveBeenCalledWith('/emar/clients/16/day');
    });

    it.each([
        [
            'no_permission',
            /Ask your medication lead to check your recording access/,
        ],
        ['no_shift', /Clock in to a shift covering Aroha/],
    ])(
        'explains %s without allowing recording or showing scheduled-dose noise',
        (reason, message) => {
            Object.assign(state, { record: false, reason });
            open();
            expect(screen.getByRole('status')).toHaveTextContent(message);
            expect(screen.queryByRole('option')).not.toBeInTheDocument();
            expect(
                screen.queryByText(/No scheduled doses/),
            ).not.toBeInTheDocument();
            expect(state.recordAsNeeded).not.toHaveBeenCalled();
        },
    );

    it('explains a missing current order and returns keyboard focus when closed', async () => {
        state.hasPrn = false;
        open();
        expect(
            screen.getByRole('heading', {
                name: 'No as-needed medicines for Aroha',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /An as-needed dose needs a current medication order/,
            ),
        ).toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: 'Close' })[0]);
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Record as-needed dose' }),
            ).toHaveFocus(),
        );
    });

    it('does not mistake hidden controlled medicines for an empty chart', () => {
        Object.assign(state, { hasPrn: false, hidden: 1 });
        open();
        expect(
            screen.getByRole('heading', {
                name: 'No as-needed medicines available to you',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Controlled medicines require controlled-medicine access/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('No as-needed medicines for Aroha'),
        ).not.toBeInTheDocument();
    });

    it('keeps load failures distinct from no medicines and offers retry', () => {
        state.load = 'error';
        open();
        expect(
            screen.getByRole('heading', {
                name: 'Couldn’t load recording choices',
            }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(state.reload).toHaveBeenCalledOnce();
        expect(
            screen.queryByText(/No as-needed medicines/),
        ).not.toBeInTheDocument();
    });
});
