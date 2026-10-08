import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { renderToString } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { MedsTodayProps, ScheduleRow } from './types';

const pageContext = vi.hoisted(() => ({ url: '/meds/today' }));
afterEach(() => {
    vi.unstubAllGlobals();
    pageContext.url = '/meds/today';
});

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));
vi.mock('@/hooks/use-emar-breadcrumbs', () => ({
    useEmarBreadcrumbs: () => [],
}));
vi.mock('@/components/emar/controlled/controlled-checks', () => ({
    ControlledChecks: ({
        search,
        pageUrl,
    }: {
        search: string;
        pageUrl?: string;
    }) => (
        <section
            aria-label="Controlled checks workspace"
            data-read-url={pageUrl}
        >
            {search}
        </section>
    ),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        get: vi.fn(),
        reload: vi.fn(),
        visit: vi.fn(),
        on: vi.fn(() => () => undefined),
    },
    usePage: () => ({ props: {}, url: pageContext.url }),
}));
// The one recording dialog is tested on its own; here we check what the page opens.
vi.mock('@/components/emar/record-dose/record-dose-dialog', () => ({
    RecordDoseDialog: (p: {
        mode?: string;
        entry: string;
        target: { kind: string; orderId: number; scheduledFor?: string };
        nextLabel?: string | null;
    }) => (
        <div role="dialog" aria-label="Record a dose">
            {`${p.mode ?? 'record'}|${p.entry}|${p.target.kind}|${p.target.orderId}|${p.target.scheduledFor ?? ''}|${p.nextLabel ?? ''}`}
        </div>
    ),
}));
vi.mock('@/components/emar/record-dose/dialogs', () => ({
    WhyDialog: (p: { target: { orderId: number } }) => (
        <div role="dialog" aria-label="Why can’t I record this?">
            {`why|${p.target.orderId}`}
        </div>
    ),
    AsNeededPicker: () => (
        <div role="dialog" aria-label="Record an as-needed dose" />
    ),
}));
vi.mock('@/pages/emar/components/report-error-modal', () => ({
    ReportErrorModal: (p: { initialClientId?: number | null }) => (
        <div role="dialog" aria-label="Report a medication error">
            {`error|${p.initialClientId ?? ''}`}
        </div>
    ),
}));

import MedsToday from './index';

const NZ = (hhmm: string) => `2026-04-30T${hhmm}:00+12:00`;

function row(
    over: Partial<ScheduleRow> &
        Pick<
            ScheduleRow,
            | 'key'
            | 'client_id'
            | 'client_name'
            | 'medication_id'
            | 'medication_name'
            | 'scheduled_for'
            | 'status'
        >,
): ScheduleRow {
    const at = new Date(over.scheduled_for).getTime();
    return {
        dose: '1 tablet',
        route: 'oral',
        is_controlled: false,
        requires_witness: false,
        time: '',
        round_label: 'Morning',
        recorded: null,
        mar_url: `/emar/mar?client_id=${over.client_id}`,
        window_opens_at: new Date(at - 3_600_000).toISOString(),
        window_ends_at: new Date(at + 3_600_000).toISOString(),
        req: null,
        ...over,
    };
}

const okReq = {
    block_all: null,
    block_given: null,
    competency: 'current' as const,
    second_person: null,
    witness_available: true,
    allergy_match: false,
    not_simple: [],
    window: 'due' as const,
};

function props(over: Partial<MedsTodayProps> = {}): MedsTodayProps {
    return {
        today: 'Thursday, 30 April 2026',
        date: '2026-04-30',
        date_label: 'Thursday 30 April 2026',
        is_today: true,
        server_now: NZ('09:30'),
        now_label: '9:30 am',
        stats: {
            meds_due: 0,
            meds_overdue: 0,
            due_now: 0,
            due_later: 0,
            upcoming_rounds: 0,
        },
        active_round: null,
        upcoming_rounds: [],
        rounds: [],
        schedule: [
            row({
                key: 'a-para',
                client_id: 1,
                client_name: 'Aroha Ngata',
                medication_id: 11,
                medication_name: 'Paracetamol 500mg',
                scheduled_for: NZ('09:30'),
                status: 'due',
                req: okReq,
            }),
            row({
                key: 'a-leve',
                client_id: 1,
                client_name: 'Aroha Ngata',
                medication_id: 12,
                medication_name: 'Amoxicillin 500mg',
                scheduled_for: NZ('08:00'),
                status: 'overdue',
                req: {
                    ...okReq,
                    block_given: 'allergyBlocked',
                    allergy_match: true,
                    window: 'late',
                },
            }),
            row({
                key: 'h-sert',
                client_id: 2,
                client_name: 'Hemi Walker',
                medication_id: 13,
                medication_name: 'Sertraline 50mg',
                scheduled_for: NZ('08:00'),
                status: 'refused',
                recorded: {
                    id: 90,
                    status: 'refused',
                    administered_at: NZ('08:05'),
                    time: '08:05',
                    by: 'Priya Shah',
                    witness: null,
                    reason: 'refused',
                    reason_label: 'Refused',
                    notes: 'Said she felt fine',
                },
            }),
        ],
        clients: [
            {
                id: 1,
                name: 'Aroha Ngata',
                preferred: 'Aroha',
                nhi: null,
                dob: null,
                age: null,
                site_id: 5,
                site_name: 'Kōwhai House',
                allergies: [],
            },
            {
                id: 2,
                name: 'Hemi Walker',
                preferred: 'Hemi',
                nhi: null,
                dob: null,
                age: null,
                site_id: 5,
                site_name: 'Kōwhai House',
                allergies: [],
            },
        ],
        sites: [{ id: 5, name: 'Kōwhai House' }],
        prn_medications: [
            {
                id: 21,
                client_id: 1,
                client_name: 'Aroha Ngata',
                name: 'Ibuprofen 200mg',
                dose: '200mg',
                route: 'oral',
                form: 'tablet',
                instructions: null,
                prn_reason: 'Pain',
                max_per_day: 3,
                given_last_24h: 1,
                remaining_today: 2,
                near_limit: false,
                over_limit: false,
                is_controlled: false,
                requires_witness: false,
                min_hours_between: 4,
                last_given_at: NZ('08:20'),
                last_given_label: '8:20 am',
                next_allowed_at: NZ('12:20'),
                next_allowed_label: '12:20 pm',
                interval_blocked: true,
            },
        ],
        prn_follow_ups: [
            {
                administration_id: 70,
                client_id: 1,
                medication_name: 'Ibuprofen 200mg',
                dose_given: '200mg',
                given_at: NZ('08:20'),
                given_time: '8:20 am',
                check_due_at: NZ('09:20'),
                check_at: '9:20 am',
                by: 'Priya Shah',
            },
        ],
        stock_alerts: [],
        activity: [],
        witnesses: [],
        not_given_reasons: [],
        shift_label: '7:00 am – 3:00 pm',
        board_user: {
            first_name: 'Priya',
            name: 'Priya Shah',
            role_label: 'Support worker',
            med_competent: true,
            controlled_record: false,
            cd_witness: false,
        },
        board_can: {
            view_emar: true,
            view_audit: false,
            record_administration: true,
            record_controlled: false,
            view_controlled: false,
            manage_stock: false,
        },
        has_shift_context: true,
        clocked_in: true,
        house_label: 'Kōwhai House',
        on_call: {
            configured: true,
            name: 'Rangi Parata',
            phone: '021 555 0142',
            warning: null,
        },
        off_shift: [
            row({
                key: 'm-metf',
                client_id: 3,
                client_name: 'Mere Tane',
                medication_id: 14,
                medication_name: 'Metformin 500mg',
                scheduled_for: NZ('09:00'),
                status: 'due',
                req: { ...okReq, block_all: 'notOnShift', window: null },
            }),
        ],
        refusal_follow_ups: [
            {
                id: 40,
                refusal_id: 90,
                client_id: 2,
                preferred: 'Hemi',
                medication_id: 13,
                medication_name: 'Sertraline 50mg',
                is_controlled: false,
                scheduled_for: NZ('08:00'),
                refused_time: '8:05 am',
                due_at: NZ('10:00'),
                due_time: '10:00 am',
                overdue: false,
                owner: 'Priya Shah',
                escalated: false,
            },
        ],
        prn_recorded_today: [],
        board_extra_can: { report_error: true, view_handovers: true },
        mar_client_ids: [1, 2],
        ...over,
    };
}

describe('Meds today (P01 C3)', () => {
    beforeEach(() => window.history.replaceState(null, '', '/meds/today'));

    it.each(['client_id', 'client', 'pp'])(
        'retains %s person scope between schedule and controlled checks',
        (alias) => {
            window.history.replaceState(
                null,
                '',
                `/meds/today?${alias}=1&date=2026-04-30&site_id=5`,
            );
            const data = props();
            render(
                <MedsToday
                    {...data}
                    schedule={data.schedule.filter(
                        (row) => row.client_id === 1,
                    )}
                    clients={data.clients.filter((client) => client.id === 1)}
                    person_options={data.clients}
                    selected_client_id={1}
                    board_can={{ ...data.board_can, view_controlled: true }}
                />,
            );
            expect(
                screen.getByRole('button', { name: 'Clear All people' }),
            ).toBeInTheDocument();
            expect(
                screen.queryByText('Sertraline 50mg'),
            ).not.toBeInTheDocument();
            fireEvent.click(
                screen.getByRole('tab', { name: 'Controlled checks' }),
            );
            const scope = new URL(
                screen
                    .getByRole('region', {
                        name: 'Controlled checks workspace',
                    })
                    .getAttribute('data-read-url')!,
                'https://example.test',
            ).searchParams;
            expect(Object.fromEntries(scope)).toMatchObject({
                client_id: '1',
                date: '2026-04-30',
                site_id: '5',
                view: 'controlled',
            });
            expect(scope.has('client')).toBe(false);
            expect(scope.has('pp')).toBe(false);
        },
    );

    it('clears person scope through a fresh server read while retaining the selected day and view', async () => {
        const { router } = await import('@inertiajs/react');
        window.history.replaceState(
            null,
            '',
            '/meds/today?client=1&date=2026-04-30&site_id=5&view=asneeded&page=2',
        );
        render(
            <MedsToday
                {...props()}
                selected_client_id={1}
                person_options={props().clients}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear All people' }),
        );
        expect(router.get).toHaveBeenLastCalledWith(
            '/meds/today',
            { date: '2026-04-30', site_id: '5', view: 'asneeded' },
            expect.objectContaining({
                preserveState: true,
                preserveScroll: true,
            }),
        );
    });

    it('opens the controlled-checks deep link for a reader without recording authority', () => {
        window.history.replaceState(
            null,
            '',
            '/meds/today?view=controlled&q=Kōwhai',
        );
        render(
            <MedsToday
                {...props({
                    board_can: {
                        ...props().board_can,
                        record_administration: false,
                        view_controlled: true,
                    },
                })}
            />,
        );

        expect(
            screen.getByRole('region', { name: 'Controlled checks workspace' }),
        ).toHaveTextContent('Kōwhai');
        expect(
            screen.getByRole('tab', { name: 'Controlled checks' }),
        ).toHaveAttribute('aria-selected', 'true');
        expect(
            screen.queryByText('At Kōwhai House, not on your shift'),
        ).not.toBeInTheDocument();
    });

    it('opens controlled checks from the rail and preserves existing query parameters', () => {
        window.history.replaceState(null, '', '/meds/today?site_id=5');
        render(
            <MedsToday
                {...props({
                    board_can: { ...props().board_can, view_controlled: true },
                })}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Controlled checks' }));

        expect(
            screen.getByRole('region', { name: 'Controlled checks workspace' }),
        ).toBeInTheDocument();
        expect(new URLSearchParams(window.location.search).get('view')).toBe(
            'controlled',
        );
        expect(new URLSearchParams(window.location.search).get('site_id')).toBe(
            '5',
        );
    });

    it('does not mount controlled checks or offer its tab without controlled read access', () => {
        window.history.replaceState(null, '', '/meds/today?view=controlled');
        render(<MedsToday {...props()} />);

        expect(
            screen.getByText('You can’t view controlled medicines'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('tab', { name: 'Controlled checks' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('region', {
                name: 'Controlled checks workspace',
            }),
        ).not.toBeInTheDocument();
    });

    it('keeps undated open follow-ups visible without claiming none are open', () => {
        const base = props();
        render(
            <MedsToday
                {...props({
                    refusal_follow_ups: [],
                    prn_follow_ups: Array.from({ length: 4 }, (_, i) => ({
                        ...base.prn_follow_ups[0],
                        administration_id: 70 + i,
                        check_due_at: null,
                        check_at: null,
                    })),
                })}
            />,
        );
        const followUps = screen.getByRole('button', {
            name: 'View follow-ups, 0 overdue',
        });
        expect(followUps).toHaveTextContent('4');
        expect(followUps).toHaveTextContent('Open follow-ups to check');
        expect(
            within(followUps).queryByText('None open'),
        ).not.toBeInTheDocument();
    });

    it('describes the displayed people without claiming shift assignment when not clocked in', () => {
        render(<MedsToday {...props({ clocked_in: false })} />);

        expect(screen.getByText(/^Showing medicines for /)).toBeInTheDocument();
        expect(
            screen.queryByText(/^Showing the people on your shift/),
        ).not.toBeInTheDocument();
    });

    it('keeps a due-soon dose outside the Due now count until the canonical window opens', () => {
        const base = props();
        const future = row({
            key: 'due-soon',
            client_id: 1,
            client_name: 'Aroha Ngata',
            medication_id: 31,
            medication_name: 'Vitamin D',
            scheduled_for: NZ('10:00'),
            status: 'due',
            state: 'not_due',
            due_soon: true,
            window_opens_at: NZ('09:45'),
            window_ends_at: NZ('10:15'),
            req: { ...okReq, window: 'notdue' },
        });
        const view = render(
            <MedsToday
                {...props({
                    server_now: NZ('09:15'),
                    schedule: [...base.schedule, future],
                })}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'View 1 doses due now' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Due soon · 10:00 am · window opens 9:45 am/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/Due now · 10:00 am/),
        ).not.toBeInTheDocument();
        view.rerender(
            <MedsToday
                {...props({
                    server_now: NZ('09:45'),
                    schedule: [{ ...future, state: 'due', req: okReq }],
                })}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'View 1 doses due now' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Due now · 10:00 am · window until 10:15 am/),
        ).toBeInTheDocument();
        view.rerender(
            <MedsToday
                {...props({
                    server_now: NZ('10:16'),
                    schedule: [
                        {
                            ...future,
                            status: 'overdue',
                            state: 'late',
                            req: { ...okReq, window: 'late' },
                        },
                    ],
                })}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'View 0 doses due now' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'View 1 late doses' }),
        ).toBeInTheDocument();
    });

    it('keeps an order waiting for a check out of the recorded denominator', () => {
        const base = props();
        render(
            <MedsToday
                {...props({
                    schedule: [
                        ...base.schedule,
                        row({
                            key: 'waiting-check',
                            client_id: 1,
                            client_name: 'Aroha Ngata',
                            medication_id: 32,
                            medication_name: 'New order',
                            scheduled_for: NZ('09:00'),
                            status: 'pending_check',
                            req: {
                                ...okReq,
                                block_all: 'awaitingVerification',
                                window: null,
                            },
                        }),
                    ],
                })}
            />,
        );
        expect(
            screen.getByRole('button', {
                name: /View activity, 1 of 3 recorded/,
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Due 9:00 am · waiting for the order check/),
        ).toBeInTheDocument();
    });

    it('does not claim completion when the only dose waits for an order check', () => {
        render(
            <MedsToday
                {...props({
                    schedule: [
                        row({
                            key: 'waiting-only',
                            client_id: 1,
                            client_name: 'Aroha Ngata',
                            medication_id: 32,
                            medication_name: 'New order',
                            scheduled_for: NZ('09:00'),
                            status: 'pending_check',
                            state: 'pending_check',
                        }),
                    ],
                })}
            />,
        );
        expect(
            screen.getByRole('button', {
                name: 'Recorded: not applicable, no doses were due',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Waiting for order checks'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Nothing left to record on your shift'),
        ).not.toBeInTheDocument();
    });

    it('excludes self-managed, away and waiting slots from the staff denominator', () => {
        const recorded = props().schedule[2];
        render(
            <MedsToday
                {...props({
                    schedule: [
                        recorded,
                        row({
                            key: 'self',
                            client_id: 1,
                            client_name: 'Aroha Ngata',
                            medication_id: 33,
                            medication_name: 'Self-managed order',
                            scheduled_for: NZ('08:00'),
                            status: 'upcoming',
                            state: 'self_managed',
                        }),
                        row({
                            key: 'away',
                            client_id: 1,
                            client_name: 'Aroha Ngata',
                            medication_id: 34,
                            medication_name: 'Away order',
                            scheduled_for: NZ('08:00'),
                            status: 'away',
                            state: 'away',
                        }),
                        row({
                            key: 'check',
                            client_id: 1,
                            client_name: 'Aroha Ngata',
                            medication_id: 35,
                            medication_name: 'Unchecked order',
                            scheduled_for: NZ('08:00'),
                            status: 'pending_check',
                            state: 'pending_check',
                        }),
                    ],
                })}
            />,
        );
        expect(
            screen.getByRole('button', {
                name: /View activity, 1 of 1 recorded/,
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Self-managed · not a staff dose to record/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Nothing left to record on your shift'),
        ).not.toBeInTheDocument();
    });

    it.each([false, true])(
        'counts concealed late doses without an empty or all-clear claim (ordinary recorded: %s)',
        (withOrdinary) => {
            render(
                <MedsToday
                    {...props({
                        schedule: withOrdinary ? [props().schedule[2]] : [],
                        hidden_controlled_doses: 1,
                        hidden_controlled_overdue: 1,
                        concealed_schedule: {
                            total: 1,
                            overdue: 1,
                            due_now: 0,
                            open: 1,
                            waiting: 0,
                            due_so_far: 1,
                            recorded_so_far: 0,
                        },
                    })}
                />,
            );
            expect(
                screen.getByRole('button', { name: 'View 1 late doses' }),
            ).toBeInTheDocument();
            expect(screen.queryByText('Nothing late')).not.toBeInTheDocument();
            expect(
                screen.queryByText('No scheduled doses on your shift today'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('Nothing left to record on your shift'),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', {
                    name: new RegExp(
                        `View activity, ${withOrdinary ? '1 of 2' : '0 of 1'} recorded`,
                    ),
                }),
            ).toBeInTheDocument();
        },
    );

    it('shows the header facts and meters that link to their views', () => {
        render(<MedsToday {...props()} />);

        expect(
            screen.getByRole('heading', { name: 'Meds today' }),
        ).toBeInTheDocument();
        expect(screen.getByText('On shift')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Thu 30 Apr 2026 · Kōwhai House · shift 7:00 am–3:00 pm',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Updated 9:30 am NZST/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'View 1 doses due now' }),
        ).toHaveTextContent('1 person · by 10:30 am');
        expect(
            screen.getByRole('button', { name: 'View 1 late doses' }),
        ).toHaveTextContent('Oldest due 8:00 am');
        expect(
            screen.getByRole('button', {
                name: 'View 1 doses you can’t record as given',
            }),
        ).toHaveTextContent('Allergy match');
        expect(
            screen.getByRole('button', {
                name: /View activity, 1 of 3 recorded/,
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'View follow-ups, 1 overdue' }),
        ).toHaveTextContent('1 overdueOldest 9:20 am');
        expect(
            screen.getByRole('link', { name: 'Shift handover — medication' }),
        ).toHaveAttribute('href', '/emar/handovers');
        fireEvent.click(
            screen.getByRole('button', { name: 'Record as-needed dose' }),
        );
        expect(
            screen.getByRole('dialog', { name: 'Record an as-needed dose' }),
        ).toBeInTheDocument();
    });

    it('words each dose’s state and opens the one dialog from the row', () => {
        render(<MedsToday {...props()} />);

        expect(
            screen.getByText(/Due now · 9:30 am · window until 10:30 am/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Due 8:00 am · outside today’s window \(7:00 am–9:00 am\)/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Allergy match — can’t be recorded as given/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Refused 8:05 am · Priya Shah · Said she felt fine',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Follow-up · Priya Shah · offer again by 10:00 am',
            ),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Record' }));
        expect(
            screen.getByRole('dialog', { name: 'Record a dose' }),
        ).toHaveTextContent(`record|meds-today|scheduled|11|${NZ('09:30')}|`);
    });

    it('opens “Why can’t I record?” for a blocked dose', () => {
        render(<MedsToday {...props()} />);

        // The allergy-blocked row on the shift, and the off-shift row underneath.
        const why = screen.getAllByRole('button', {
            name: /Why can’t I record\?/,
        });
        expect(why).toHaveLength(2);
        fireEvent.click(why[0]);
        expect(
            screen.getByRole('dialog', { name: 'Why can’t I record this?' }),
        ).toHaveTextContent('why|12');
    });

    it('offers a re-offer on a refused dose', () => {
        render(<MedsToday {...props()} />);

        fireEvent.click(
            screen.getByRole('button', { name: /Record re-offer/ }),
        );
        expect(
            screen.getByRole('dialog', { name: 'Record a dose' }),
        ).toHaveTextContent(`reoffer|meds-today|scheduled|13|${NZ('08:00')}|`);
    });

    it('gives the row one menu on right-click', () => {
        render(<MedsToday {...props()} />);

        const cell = screen.getByText('Paracetamol 500mg');
        fireEvent.contextMenu(cell, { clientX: 200, clientY: 200 });
        const menu = screen.getByRole('menu');
        // One entry for an open dose: the dialog's first step offers every outcome.
        expect(
            within(menu).getByRole('menuitem', { name: /Record dose/ }),
        ).toBeInTheDocument();
        expect(
            within(menu).queryByRole('menuitem', { name: /Record not given/ }),
        ).not.toBeInTheDocument();
        expect(
            within(menu).getByRole('menuitem', {
                name: /Open Aroha’s medication record/,
            }),
        ).toBeInTheDocument();
        fireEvent.click(
            within(menu).getByRole('menuitem', {
                name: /Report a medication error/,
            }),
        );
        expect(
            screen.getByRole('dialog', { name: 'Report a medication error' }),
        ).toHaveTextContent('error|1');
    });

    it('opens the dose details for a not-yet-due dose', () => {
        const base = props();
        render(
            <MedsToday
                {...props({
                    schedule: [
                        ...base.schedule,
                        row({
                            key: 'a-met12',
                            client_id: 1,
                            client_name: 'Aroha Ngata',
                            medication_id: 15,
                            medication_name: 'Metformin 500mg',
                            scheduled_for: NZ('12:00'),
                            status: 'upcoming',
                        }),
                    ],
                })}
            />,
        );

        expect(
            screen.getByText(/Due 12:00 pm · window opens 11:00 am/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'View' }));
        const dialog = screen.getByRole('dialog', {
            name: 'Metformin 500mg · Aroha',
        });
        expect(dialog).toHaveTextContent('Not yet due — window opens 11:00 am');
        expect(
            within(dialog).getByRole('link', {
                name: /Open Aroha’s medication record/,
            }),
        ).toHaveAttribute('href', '/emar/mar?client_id=1');
    });

    it('lists people not on your shift underneath, each saying why', () => {
        render(<MedsToday {...props()} />);

        expect(
            screen.getByText('At Kōwhai House, not on your shift'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Mere isn’t on your shift/),
        ).toBeInTheDocument();
    });

    it('tells a worker who isn’t clocked in what to do and who is on call', () => {
        render(<MedsToday {...props({ clocked_in: false })} />);

        expect(screen.getByText('Not clocked in')).toBeInTheDocument();
        expect(screen.getByText('You’re not clocked in')).toBeInTheDocument();
        const clockInLinks = screen.getAllByRole('link', { name: /Clock in/ });
        expect(clockInLinks).toHaveLength(2);
        for (const link of clockInLinks) {
            expect(link).toHaveAttribute('href', '/attendance');
        }
        expect(screen.getByText('Rangi Parata')).toBeInTheDocument();
    });

    it('says the board may be out of date when a refresh fails', async () => {
        const { router } = await import('@inertiajs/react');
        vi.mocked(router.reload).mockImplementationOnce((options) => {
            act(() => options?.onFinish?.({} as never));
        });
        render(<MedsToday {...props()} />);

        fireEvent.click(
            screen.getByRole('button', { name: /Updated 9:30 am/ }),
        );
        // The filter-row button and the banner both say so; the banner offers Refresh now.
        expect(
            screen.getAllByText(/^Not updated since 9:30 am NZST/),
        ).toHaveLength(2);
        expect(
            screen.getByText(
                /^Not updated since 9:30 am NZST \(\d+ min ago\)$/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Refresh now/ }),
        ).toBeInTheDocument();
    });

    it.each(['pending', 'expired'] as const)(
        'includes named %s second-person tasks without hiding them behind an empty follow-up state',
        (status) => {
            render(
                <MedsToday
                    {...props({
                        refusal_follow_ups: [],
                        prn_follow_ups: [],
                        second_person_confirmations: [
                            {
                                id: 81,
                                client_id: 1,
                                client_name: 'Aroha Ngata',
                                medication_name: 'Ordinary tablets',
                                due_at: NZ(
                                    status === 'pending' ? '09:45' : '09:15',
                                ),
                                status,
                                followup_url: '/medication-followups?open=91',
                            },
                        ],
                    })}
                />,
            );
            const tab = screen.getByRole('tab', { name: /^Follow-ups\s*1$/ });
            fireEvent.click(tab);
            expect(
                screen.queryByText('No follow-ups open'),
            ).not.toBeInTheDocument();
            const region = screen.getByRole('region', {
                name: 'Your second-person confirmations',
            });
            expect(
                within(region).getByText('Ordinary tablets'),
            ).toBeInTheDocument();
            expect(
                within(region).getByText(
                    status === 'pending'
                        ? 'Not yet verified'
                        : 'Confirmation overdue',
                ),
            ).toBeInTheDocument();
            expect(
                within(region).getByRole('link', {
                    name: 'Review confirmation',
                }),
            ).toHaveAttribute('href', '/medication-followups?open=91');
            expect(
                screen.getByRole('button', {
                    name:
                        'View follow-ups, ' +
                        (status === 'pending' ? '0' : '1') +
                        ' overdue',
                }),
            ).toBeInTheDocument();
        },
    );

    it('lists refusal and as-needed follow-ups and records a re-offer from them', () => {
        render(<MedsToday {...props()} />);

        fireEvent.click(screen.getByRole('tab', { name: /Follow-ups/ }));
        expect(
            screen.getByText('Refused: Sertraline 50mg'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Did it help? Ibuprofen 200mg'),
        ).toBeInTheDocument();
        expect(screen.getByText('9:20 am')).toBeInTheDocument();

        const reoffer = screen.getAllByRole('button', {
            name: /Record re-offer/,
        });
        fireEvent.click(reoffer[reoffer.length - 1]);
        expect(
            screen.getByRole('dialog', { name: 'Record a dose' }),
        ).toHaveTextContent(`reoffer|follow-up|scheduled|13|${NZ('08:00')}|`);
    });
});

describe('visible MAR entry point', () => {
    beforeEach(() =>
        window.history.replaceState(null, '', '/meds/today?site_id=5'),
    );
    it('server-renders the chart link with the original house, person, day and return view', () => {
        pageContext.url =
            '/meds/today?site_id=5&client_id=1&date=2026-04-30&view=asneeded';
        vi.stubGlobal('window', undefined);
        const container = document.createElement('div');
        container.innerHTML = renderToString(
            <MedsToday {...props()} selected_client_id={1} />,
        );
        const link = [...container.querySelectorAll('a')].find((item) =>
            item.textContent?.includes('Open MAR chart'),
        );
        const target = new URL(
            link!.getAttribute('href')!,
            'https://medication.invalid',
        );
        expect(target.pathname).toBe('/emar/mar');
        expect(target.searchParams.get('client_id')).toBe('1');
        expect(target.searchParams.get('site_id')).toBe('5');
        expect(target.searchParams.get('date')).toBe('2026-04-30');
        expect(target.searchParams.get('return_to')).toBe(pageContext.url);
    });
    it('opens the selected person’s chart with the same NZ date and house', () => {
        render(<MedsToday {...props()} selected_client_id={1} />);
        const link = screen.getByRole('link', { name: 'Open MAR chart' });
        const url = new URL(link.getAttribute('href')!, window.location.origin);
        expect(url.pathname).toBe('/emar/mar');
        expect(Object.fromEntries(url.searchParams)).toEqual({
            date: '2026-04-30',
            client_id: '1',
            site_id: '5',
            return_to: '/meds/today?site_id=5',
        });
    });
    it('hides the shortcut when the worker has no MAR access', () => {
        const data = props();
        render(
            <MedsToday
                {...data}
                board_can={{ ...data.board_can, view_emar: false }}
            />,
        );
        expect(
            screen.queryByRole('link', { name: 'MAR charts' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Open MAR chart' }),
        ).not.toBeInTheDocument();
    });
});
