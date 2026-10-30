import { router } from '@inertiajs/react';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ActivityView, AsNeededView, FollowUpsView } from './_lists';
import type {
    ActivityPage,
    ActivityRow,
    ClientInfo,
    PrnFollowUp,
    PrnMedication,
    PrnRecorded,
    RefusalFollowUp,
} from './types';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: { get: vi.fn() },
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const client: ClientInfo = {
    id: 7,
    name: 'Mere Jones',
    preferred: 'Mere',
    nhi: null,
    dob: null,
    age: null,
    site_id: 1,
    site_name: 'Kauri House',
    allergies: [],
};
const clients = new Map([[client.id, client]]);
const activity: ActivityRow = {
    id: 81,
    client_id: client.id,
    preferred: 'Mere',
    surname: 'Jones',
    photo_url: null,
    at: '2026-04-29T21:30:00Z',
    time: '9:30 am',
    day: 'Thu 30 Apr',
    medication_name: 'Paracetamol 500 mg',
    is_controlled: false,
    status: 'given',
    outcome: 'Given',
    by: 'Ana Smith',
    detail: '1 tablet · headache',
};

function activityPage(rows = [activity]): ActivityPage {
    return {
        data: rows,
        links: [
            { url: null, label: '&laquo; Previous', active: false },
            {
                url: '/meds/today?view=activity&page=1',
                label: '1',
                active: true,
            },
            {
                url: '/meds/today?view=activity&page=2',
                label: '2',
                active: false,
            },
            {
                url: '/meds/today?view=activity&page=2',
                label: 'Next &raquo;',
                active: false,
            },
        ],
        current_page: 1,
        last_page: rows.length ? 2 : 1,
        total: rows.length ? 11 : 0,
    };
}

function activityProps() {
    const open = vi.fn();
    return {
        page: activityPage(),
        range: 'today',
        houseLabel: 'Kauri House',
        canReportError: true,
        chartFor: () => open,
        onReportError: vi.fn(),
        onContext: vi.fn(),
        open,
    };
}

function medication(over: Partial<PrnMedication> = {}): PrnMedication {
    return {
        id: 34,
        client_id: client.id,
        client_name: client.name,
        name: 'Paracetamol 500 mg',
        dose: '1 tablet',
        route: 'oral',
        form: 'tablet',
        instructions: 'For headache. Swallow with water.',
        prn_reason: 'Headache',
        max_per_day: 4,
        given_last_24h: 1,
        remaining_today: 3,
        near_limit: false,
        over_limit: false,
        is_controlled: false,
        requires_witness: false,
        min_hours_between: 4,
        last_given_at: activity.at,
        last_given_label: 'Today 9:30 am',
        next_allowed_at: null,
        next_allowed_label: null,
        interval_blocked: false,
        ...over,
    };
}

const recorded: PrnRecorded = {
    id: 81,
    client_id: client.id,
    medication_name: 'Paracetamol 500 mg',
    status: 'given',
    time: '9:30 am',
    dose_given: '1 tablet',
    reason: 'Headache',
    by: 'Ana Smith',
    check_at: '10:30 am',
    effect_recorded: false,
};

function asNeededProps() {
    return {
        medications: [medication()],
        recorded: [recorded],
        clients,
        search: '',
        person: null,
        canRecord: () => true,
        canReportError: true,
        onRecord: vi.fn(),
        chartFor: () => vi.fn(),
        onReportError: vi.fn(),
        onContext: vi.fn(),
    };
}

const refusal: RefusalFollowUp = {
    id: 20,
    refusal_id: 19,
    client_id: client.id,
    preferred: 'Mere',
    medication_id: 30,
    medication_name: 'Vitamin D',
    is_controlled: false,
    scheduled_for: '2026-04-30T08:00:00+12:00',
    refused_time: '8:00 am',
    due_at: '2026-04-30T10:00:00+12:00',
    due_time: '10:00 am',
    overdue: true,
    owner: 'Ben Taylor',
    escalated: false,
};
const effect: PrnFollowUp = {
    administration_id: 81,
    client_id: client.id,
    medication_name: recorded.medication_name,
    is_controlled: false,
    dose_given: recorded.dose_given,
    given_at: activity.at,
    given_time: recorded.time,
    check_due_at: '2026-04-30T10:30:00+12:00',
    check_at: recorded.check_at,
    by: recorded.by,
};

function followUpProps() {
    return {
        refusals: [refusal],
        effects: [effect],
        clients,
        nowIso: '2026-04-30T10:15:00+12:00',
        canRecordRefusal: () => true,
        canRecordEffect: () => true,
        onReoffer: vi.fn(),
        onEffect: vi.fn(),
        chartFor: () => vi.fn(),
        onContext: vi.fn(),
    };
}

async function openMenu(card: HTMLElement, person = client.name) {
    fireEvent.keyDown(
        within(card).getByRole('button', { name: `Actions for ${person}` }),
        { key: 'Enter' },
    );
    return screen.findByRole('menu');
}

describe('phone medication lists', () => {
    it('distinguishes an initial or explicitly pending Activity load from a resolved empty page', () => {
        const props = activityProps();
        const { rerender } = render(
            <ActivityView {...props} page={undefined} />,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'Loading medication activity…',
        );
        expect(screen.queryByText('Nothing recorded')).not.toBeInTheDocument();
        expect(screen.queryByText('0 of 0 shown')).not.toBeInTheDocument();

        rerender(<ActivityView {...props} loading />);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Loading medication activity…',
        );
        expect(
            screen.queryByRole('list', { name: 'Recorded medication cards' }),
        ).not.toBeInTheDocument();

        rerender(<ActivityView {...props} page={activityPage([])} />);
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        expect(screen.getByText('Nothing recorded')).toBeInTheDocument();
        expect(screen.getByText('0 of 0 shown')).toBeInTheDocument();
    });

    it('shows all Activity facts in the card, uses NZ time across a UTC date boundary and keeps one paginator', () => {
        const props = activityProps();
        render(<ActivityView {...props} />);
        const cards = screen.getByRole('list', {
            name: 'Recorded medication cards',
        });
        expect(cards).toHaveClass('md:hidden');
        for (const text of [
            client.name,
            activity.medication_name!,
            activity.outcome,
            activity.by!,
            activity.detail!,
            'Thu 30 Apr, 9:30 am NZ time',
        ]) {
            expect(within(cards).getByText(text)).toBeInTheDocument();
        }
        expect(cards.querySelector('time')).toHaveAttribute(
            'dateTime',
            activity.at,
        );
        expect(screen.getByText('1 of 11 shown')).toBeInTheDocument();
        expect(
            screen.getAllByRole('navigation', { name: 'Pagination' }),
        ).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(router.get).toHaveBeenCalledWith(
            '/meds/today?view=activity&page=2',
            {},
            { preserveState: true, preserveScroll: true },
        );
    });

    it('keeps Activity card, kebab and context actions within the existing viewing rights', async () => {
        const props = activityProps();
        const { rerender } = render(<ActivityView {...props} />);
        const card = screen
            .getByRole('list', { name: 'Recorded medication cards' })
            .querySelector<HTMLElement>('[data-slot="card"]')!;
        fireEvent.keyDown(card, { key: 'Enter' });
        expect(props.open).toHaveBeenCalledOnce();
        fireEvent.contextMenu(card);
        const actions = props.onContext.mock.calls[0][2];
        const menu = await openMenu(card);
        expect(
            within(menu)
                .getAllByRole('menuitem')
                .map((item) => item.textContent),
        ).toEqual(actions.map((item: { label: string }) => item.label));
        fireEvent.click(
            within(menu).getByRole('menuitem', {
                name: 'Report a medication error',
            }),
        );
        expect(props.onReportError).toHaveBeenCalledWith(client.id);
        expect(props.open).toHaveBeenCalledOnce();

        rerender(
            <ActivityView
                {...props}
                chartFor={() => null}
                canReportError={false}
            />,
        );
        const deniedCards = screen.getByRole('list', {
            name: 'Recorded medication cards',
        });
        expect(
            within(deniedCards).queryByRole('button'),
        ).not.toBeInTheDocument();
        expect(
            within(deniedCards).queryByText('Medication record'),
        ).not.toBeInTheDocument();
        fireEvent.click(deniedCards.querySelector('[data-slot="card"]')!);
        expect(props.open).toHaveBeenCalledOnce();
    });

    it('keeps blocked PRN explanations and the same authorised recording menu on phone cards', async () => {
        const props = asNeededProps();
        const blocked = medication({
            interval_blocked: true,
            next_allowed_label: '1:30 pm',
        });
        render(<AsNeededView {...props} medications={[blocked]} />);
        const cards = screen.getByRole('list', {
            name: 'As-needed medicine cards',
        });
        expect(cards).toHaveClass('md:hidden');
        for (const text of [
            client.name,
            blocked.name,
            blocked.dose!,
            blocked.instructions!,
            '1 of 4',
            'Today 9:30 am NZ time',
            'Too soon · from 1:30 pm',
        ]) {
            expect(within(cards).getByText(text)).toBeInTheDocument();
        }
        fireEvent.click(
            within(cards).getByRole('button', {
                name: 'Why can’t I record this?',
            }),
        );
        expect(props.onRecord).toHaveBeenCalledExactlyOnceWith(blocked);
        const card = cards.querySelector<HTMLElement>('[data-slot="card"]')!;
        fireEvent.contextMenu(card);
        const actions = props.onContext.mock.calls[0][2];
        const menu = await openMenu(card);
        expect(
            within(menu)
                .getAllByRole('menuitem')
                .map((item) => item.textContent),
        ).toEqual(
            actions
                .filter((item: { separator?: boolean }) => !item.separator)
                .map((item: { label: string }) => item.label),
        );
    });

    it('does not grant recording through a PRN card when the existing row callback denies it', () => {
        const props = asNeededProps();
        render(
            <AsNeededView
                {...props}
                canRecord={() => false}
                chartFor={() => null}
                canReportError={false}
            />,
        );
        const cards = screen.getByRole('list', {
            name: 'As-needed medicine cards',
        });
        expect(within(cards).queryByRole('button')).not.toBeInTheDocument();
        fireEvent.click(cards.querySelector('[data-slot="card"]')!);
        expect(props.onRecord).not.toHaveBeenCalled();
    });

    it.each([
        ['pending', 'Second-person confirmation pending'],
        ['disputed', 'Confirmation disputed'],
        ['expired', 'Confirmation overdue'],
    ] as const)(
        'shows %s second-person evidence in the desktop as-needed list',
        (status, label) => {
            const props = asNeededProps();
            const { container } = render(
                <AsNeededView
                    {...props}
                    recorded={[
                        {
                            ...recorded,
                            second_person_confirmation: {
                                id: 90,
                                status,
                                nominated_name: 'Ben Taylor',
                                due_at: '2026-04-30T10:00:00+12:00',
                            },
                        },
                    ]}
                />,
            );
            const desktop = container.querySelector(
                '[data-slot="card"].md\\:flex',
            )!;
            expect(
                within(desktop as HTMLElement).getByText(label),
            ).toBeInTheDocument();
            expect(
                within(desktop as HTMLElement).queryByText(
                    'Witness PIN verified',
                ),
            ).not.toBeInTheDocument();
            expect(props.onRecord).not.toHaveBeenCalled();
        },
    );

    it('shows recorded PRN dose, reason, author and effect-check time without offering to record it again', () => {
        const props = asNeededProps();
        render(<AsNeededView {...props} />);
        const cards = screen.getByRole('list', {
            name: 'Recorded as-needed dose cards',
        });
        expect(cards).toHaveClass('md:hidden');
        for (const text of [
            client.name,
            recorded.medication_name!,
            recorded.dose_given!,
            '9:30 am NZ time',
            recorded.reason!,
            recorded.by!,
            'Check by 10:30 am NZ time',
            'Given',
        ]) {
            expect(within(cards).getByText(text)).toBeInTheDocument();
        }
        expect(within(cards).queryByRole('button')).not.toBeInTheDocument();
        fireEvent.click(cards.querySelector('[data-slot="card"]')!);
        expect(props.onRecord).not.toHaveBeenCalled();
    });

    it('names an unknown effect-check time without inventing a deadline or marking it overdue', () => {
        render(
            <FollowUpsView
                {...followUpProps()}
                refusals={[]}
                effects={[{ ...effect, check_due_at: null, check_at: null }]}
            />,
        );
        const cards = screen.getByRole('list', {
            name: 'Medication follow-up cards',
        });
        expect(
            within(cards).getByText('Check time not set'),
        ).toBeInTheDocument();
        expect(within(cards).queryByText('Overdue')).not.toBeInTheDocument();
        expect(
            screen.getByText(/If no check time is set, arrange it with/),
        ).toBeInTheDocument();
    });

    it('shows follow-up medicine, due time and owner and retains both canonical dialog callbacks', () => {
        const props = followUpProps();
        const { rerender } = render(<FollowUpsView {...props} />);
        const cards = screen.getByRole('list', {
            name: 'Medication follow-up cards',
        });
        expect(cards).toHaveClass('md:hidden');
        for (const text of [
            refusal.medication_name!,
            refusal.owner!,
            effect.medication_name!,
            effect.by!,
            'Thu 30 Apr, 10:00 am NZ time',
            'Thu 30 Apr, 10:30 am NZ time',
            'Overdue',
            'Open',
        ]) {
            expect(within(cards).getByText(text)).toBeInTheDocument();
        }
        fireEvent.click(
            within(cards).getByRole('button', { name: 'Record re-offer' }),
        );
        fireEvent.click(
            within(cards).getByRole('button', {
                name: 'Record whether it helped',
            }),
        );
        expect(props.onReoffer).toHaveBeenCalledExactlyOnceWith(refusal);
        expect(props.onEffect).toHaveBeenCalledExactlyOnceWith(effect);

        rerender(
            <FollowUpsView
                {...props}
                canRecordRefusal={() => false}
                canRecordEffect={() => false}
                chartFor={() => null}
            />,
        );
        const deniedCards = screen.getByRole('list', {
            name: 'Medication follow-up cards',
        });
        expect(
            within(deniedCards).queryByRole('button'),
        ).not.toBeInTheDocument();
        for (const card of deniedCards.querySelectorAll('[data-slot="card"]'))
            fireEvent.click(card);
        expect(props.onReoffer).toHaveBeenCalledOnce();
        expect(props.onEffect).toHaveBeenCalledOnce();
    });
});
