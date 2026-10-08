import { ProfileDialogs } from '@/components/clients/profile/dialog-host';
import type {
    DayDose,
    DayMedicine,
} from '@/components/clients/profile/mar-day/types';
import {
    doseRecoveryKey,
    keepDoseRecovery,
} from '@/components/emar/record-dose/recovery';
import { setOfflineQueueActor } from '@/lib/offline-queue';
import { profileDialogStateFromSearch } from '@/pages/operations/clients/tabs/_groups';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RecordDoseLaunch } from './record-dose-launch';

const state = vi.hoisted(() => ({
    load: 'ready',
    record: true,
    reason: null as string | null,
    hasPrn: true,
    hidden: 0,
    medicines: [] as DayMedicine[],
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
                          today: '2026-10-07',
                          medicines: state.medicines,
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
        medicines: [],
    });
});

function scheduledMedicine(
    id: number,
    status: DayDose['status'] = 'due',
): DayMedicine {
    const name = `Scheduled medicine ${id}`;
    const doses = ['08:00', '12:00'].map(
        (time): DayDose => ({
            key: `${id}:${time}`,
            client_id: 16,
            client_name: 'Aroha',
            medication_id: id,
            medication_name: name,
            dose: '1 tablet',
            route: 'oral',
            is_controlled: false,
            requires_witness: false,
            scheduled_for: `2026-10-07T${time}:00+13:00`,
            time,
            round_label: 'Test round',
            status,
            state: status === 'due' ? 'due' : status,
            recorded: null,
            mar_url: '/emar/mar?client_id=16',
        }),
    );
    return {
        id,
        name,
        dose: '1 tablet',
        route: 'oral',
        is_controlled: false,
        requires_witness: false,
        cells: { '08:00': [doses[0]], '12:00': [doses[1]] },
    };
}

function openProfileMedicine(id: number) {
    return render(
        <ProfileDialogs
            dialog={profileDialogStateFromSearch(`?dialog=emar&record=${id}`)}
            onClose={() => {}}
            flowContext={{
                clientId: 16,
                clientLabel: 'Aroha',
                preferredName: 'Aroha',
                staffOptions: [],
                goalOptions: [],
                consentTypeOptions: [],
                fundOptions: [],
                carePlanId: null,
                carePlanTitle: null,
                onboardingWorkflowId: null,
                canSendFamilyChat: false,
            }}
            medications={[]}
            canRecord
            canRecordControlled={false}
            witnessOptions={[]}
        />,
    );
}

describe('profile medicine deep links through the shared picker', () => {
    it('keeps the requested PRN and never substitutes another medicine', () => {
        state.medicines = [scheduledMedicine(12)];
        openProfileMedicine(73);
        expect(
            screen.queryByText(/Scheduled medicine 12/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('No scheduled doses are due now'),
        ).not.toBeInTheDocument();
        expect(state.recordAsNeeded).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Record as-needed dose · Synthetic as-needed tablet',
            }),
        );
        expect(state.recordAsNeeded).toHaveBeenCalledExactlyOnceWith(73);
        expect(state.recordScheduled).not.toHaveBeenCalled();
        expect(state.request).toHaveBeenCalledWith('/emar/clients/16/day');
    });

    it('offers only the requested scheduled medicine and requires a specific dose choice', () => {
        const requested = scheduledMedicine(12);
        state.medicines = [scheduledMedicine(13), requested];
        openProfileMedicine(12);
        expect(
            screen.queryByText(/Scheduled medicine 13/),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /as-needed/ }),
        ).not.toBeInTheDocument();
        const choices = screen.getAllByRole('button', {
            name: 'Record this dose',
        });
        expect(choices).toHaveLength(2);
        expect(state.recordScheduled).not.toHaveBeenCalled();
        fireEvent.click(choices[1]);
        expect(state.recordScheduled).toHaveBeenCalledExactlyOnceWith(
            requested.cells['12:00'][0],
        );
        expect(state.recordAsNeeded).not.toHaveBeenCalled();
    });

    it.each(['upcoming', 'given', 'pending_check'] as const)(
        'explains a requested %s medicine without selecting another due dose',
        (status) => {
            state.medicines = [
                scheduledMedicine(13),
                scheduledMedicine(12, status),
            ];
            openProfileMedicine(12);
            expect(
                screen.getByRole('heading', {
                    name: 'No dose is available to record for Scheduled medicine 12',
                }),
            ).toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: 'Record this dose' }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /as-needed/ }),
            ).not.toBeInTheDocument();
            expect(state.recordScheduled).not.toHaveBeenCalled();
        },
    );

    it.each([0, 1])(
        'keeps a missing or hidden requested medicine scoped (hidden=%s)',
        (hidden) => {
            state.hidden = hidden;
            state.medicines = [scheduledMedicine(13)];
            openProfileMedicine(999);
            expect(
                screen.getByRole('heading', {
                    name: 'The requested medicine is unavailable',
                }),
            ).toBeInTheDocument();
            expect(
                screen.queryByText(/Scheduled medicine 13/),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText(/Synthetic as-needed tablet/),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', {
                    name: /Record this dose|as-needed/,
                }),
            ).not.toBeInTheDocument();
            expect(state.recordScheduled).not.toHaveBeenCalled();
            expect(state.recordAsNeeded).not.toHaveBeenCalled();
        },
    );

    it.each(['no_permission', 'no_shift'])(
        'retains current %s checks for the requested medicine',
        (reason) => {
            Object.assign(state, { record: false, reason });
            openProfileMedicine(73);
            expect(screen.getByRole('status')).toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /Record as-needed/ }),
            ).not.toBeInTheDocument();
            expect(state.recordAsNeeded).not.toHaveBeenCalled();
        },
    );

    it('retains only the original requested recovery when that order has retired', () => {
        for (const id of [12, 41]) {
            keepDoseRecovery(doseRecoveryKey(16, id, 'prn'), {
                display: { order: { name: `Original medicine ${id}` } },
                form: { when: '2026-10-07T08:05', outcome: 'given' },
                target: { kind: 'prn', orderId: id },
            });
        }
        openProfileMedicine(12);
        expect(
            screen.getByText('Unconfirmed attempt · Original medicine 12'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Original medicine 41/),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByRole('button', { name: 'Check original attempt' }),
        ).toHaveLength(1);
        expect(
            screen.getByRole('heading', {
                name: 'The requested medicine is unavailable',
            }),
        ).toBeInTheDocument();
        expect(state.recordAsNeeded).not.toHaveBeenCalled();
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
