import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AlertsDelivery, AlertsOverview, type AlertData } from './_alerts';
import { SettingsCtx, type Dialog, type SettingsContext } from './_context';
import {
    accepts,
    format,
    loosens,
    quietAt,
    stillToDecide,
    validateView,
    type AlertMeta,
    type Draft,
    type SettingDefinition,
    type SettingsPayload,
} from './_model';
import { ReachDialogHost, reachRows, type ReachGap } from './_reach';

/* P11 B2 C5: quiet hours (organisation default, then each house) and Who
 * can't be reached. */

vi.mock('@inertiajs/react', () => ({
    router: { put: vi.fn(), post: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));

const STOCK: AlertMeta = {
    key: 'stock',
    label: 'Stock running low',
    subline: 'Below its reorder level',
    groups: ['houseLead', 'stockStaff'],
    locked: [],
    group_labels: {
        houseLead: { label: 'House lead', description: '' },
        stockStaff: { label: 'People who update stock here', description: '' },
    },
    controlled: false,
    until: 'Until restocked',
    channels: ['inapp', 'email', 'push'],
};
const alertValue = (email: boolean, push = false, followUp = false) =>
    JSON.stringify({
        inapp: true,
        email,
        push,
        follow_up: followUp,
        groups: ['houseLead', 'stockStaff'],
        people: [],
    });
const base = {
    options: [],
    range: null,
    unit: null,
    rank: null,
    numeric: null,
} satisfies Partial<SettingDefinition>;
const timeDef = (
    key: string,
    label: string,
    offLabel: string,
    prefix: string,
    pairedWith: string,
): SettingDefinition => ({
    ...base,
    group: 'delivery',
    key,
    scope: 'organisation',
    section: 'delivery',
    label,
    default: 'off',
    paired_with: pairedWith,
    kind: 'time',
    off_label: offLabel,
    time_prefix: prefix,
});

const settings: SettingsPayload = {
    groups: {
        alerts: {
            key: 'alerts',
            view: 'alerts',
            effect: 'From the next alert sent, at every house',
            audit_event: 'medications.alert_recipients.updated',
            keys: ['stock'],
        },
        delivery: {
            key: 'delivery',
            view: 'alerts',
            effect: 'From the next alert sent, at every house',
            audit_event: 'medications.alert_delivery.updated',
            keys: ['quiet_from', 'quiet_until'],
        },
        quietHouse: {
            key: 'quietHouse',
            view: 'alerts',
            effect: 'From tonight, at that house',
            audit_event: 'medications.alert_quiet_hours.updated',
            keys: ['hours'],
        },
    },
    definitions: {
        alerts: {
            stock: {
                ...base,
                group: 'alerts',
                key: 'stock',
                scope: 'organisation',
                section: 'alerts',
                label: 'Who gets “Stock running low”',
                default: alertValue(false),
                paired_with: null,
                kind: 'alert',
                alert: STOCK,
            },
        },
        delivery: {
            quiet_from: timeDef(
                'quiet_from',
                'Hold non-urgent alerts overnight',
                'Off — sent straight away',
                'From',
                'quiet_until',
            ),
            quiet_until: timeDef(
                'quiet_until',
                'Quiet hours end',
                'Off',
                'Until',
                'quiet_from',
            ),
        },
        quietHouse: {
            hours: {
                ...base,
                group: 'quietHouse',
                key: 'hours',
                scope: 'site',
                section: 'delivery',
                label: 'Quiet hours',
                default: '{"mode":"org"}',
                paired_with: null,
                kind: 'quiet',
                house_managed: true,
            },
        },
    },
    values: { alerts: { stock: alertValue(true) } },
    reviewed: { alerts: { stock: { by: 'Hana Kereama', at: null } } },
    site_values: {
        '4': {
            quietHouse: {
                hours: '{"mode":"own","from":"22:00","until":"06:00"}',
            },
        },
    },
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
    site_names: { '3': 'Kōwhai House', '4': 'Rimu House' },
};

const gap = (over: Partial<ReachGap>): ReachGap => ({
    id: 1,
    name: 'Someone',
    role: 'Support worker',
    houses: 'Kōwhai House',
    site_ids: [3],
    controlled: false,
    groups: ['staffMember'],
    work_email: true,
    push: true,
    phone: true,
    leave: null,
    backup_for: [],
    on_call: false,
    ...over,
});
const GAPS: ReachGap[] = [
    gap({
        id: 11,
        name: 'Mere Kahu',
        groups: ['rostered', 'stockStaff', 'staffMember'],
        work_email: false,
    }),
    gap({
        id: 12,
        name: 'Hana Kereama',
        role: 'Clinical lead',
        houses: 'All houses',
        site_ids: [3, 4],
        groups: ['clinicalLead', 'onCall', 'staffMember'],
        push: false,
        phone: false,
        leave: 'On leave Thu 1 – Mon 5 Oct (Leave hub)',
        backup_for: ['Kōwhai House'],
        on_call: true,
    }),
    gap({ id: 13, name: 'Leilani Faleolo', phone: false }),
];

const data = (over: Partial<AlertData> = {}): AlertData => ({
    access: { view: true, manage_org: true, house_ids: [3, 4] },
    people: [],
    sites: [
        { id: 3, name: 'Kōwhai House' },
        { id: 4, name: 'Rimu House' },
    ],
    readOnlyAudit: false,
    nobodyOpen: 0,
    previews: {},
    delivery: { push_ready: 1, people: 3 },
    onCall: { houses: [], staff: {} },
    houses: [
        { id: 3, name: 'Kōwhai House' },
        { id: 4, name: 'Rimu House' },
    ],
    reachGaps: GAPS,
    ...over,
});

function Harness({
    alertData,
    initialDraft = {},
    onDraft,
    errors = {},
    canEdit = () => true,
    overview = false,
}: {
    alertData: AlertData;
    initialDraft?: Draft;
    onDraft?: (d: Draft) => void;
    errors?: Record<string, string>;
    canEdit?: (group: string) => boolean;
    overview?: boolean;
}) {
    const [draft, setDraftState] = useState<Draft>(initialDraft);
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const ctx: SettingsContext = {
        s: settings,
        draft,
        setDraft: (fn) =>
            setDraftState((d) => {
                const next = fn(d);
                onDraft?.(next);
                return next;
            }),
        canEdit,
        go: vi.fn(),
        open: setDialog,
        close: () => setDialog(null),
        flash: vi.fn(),
        freshAfter: 0,
        leave: vi.fn(),
        errors,
        clearError: vi.fn(),
    };
    return (
        <SettingsCtx.Provider value={ctx}>
            {overview ? (
                <AlertsOverview q="" data={alertData} />
            ) : (
                <AlertsDelivery
                    q=""
                    show="all"
                    clear={vi.fn()}
                    data={alertData}
                />
            )}
            <ReachDialogHost dialog={dialog} gaps={alertData.reachGaps} />
        </SettingsCtx.Provider>
    );
}

const row = (id: string) =>
    document.querySelector(`[data-setting="${id}"]`) as HTMLElement;

afterEach(cleanup);

describe('Quiet hours: the model', () => {
    const from = settings.definitions.delivery.quiet_from;
    const house = settings.definitions.quietHouse.hours;

    it('reads times and a house’s choice in the server’s words, and never calls them a loosening', () => {
        expect(format(from, 'off')).toBe('Off — sent straight away');
        expect(format(from, '21:00')).toBe('From 9:00 pm');
        expect(format(settings.definitions.delivery.quiet_until, '07:00')).toBe(
            'Until 7:00 am',
        );
        expect(accepts(from, '24:00')).toBe(false);
        expect(accepts(from, '')).toBe(false);
        expect(format(house, '{"mode":"org"}')).toBe(
            'Follows the organisation',
        );
        expect(format(house, '{"mode":"off"}')).toBe(
            'No quiet hours at this house',
        );
        expect(
            format(house, '{"mode":"own","from":"21:30","until":"06:15"}'),
        ).toBe('Own hours: 9:30 pm to 6:15 am');
        expect(format(house, '{"mode":"own","from":"","until":""}')).toBe(
            'Own hours — times not chosen',
        );
        expect(loosens(from, '21:00', 'off')).toBe(false);
        expect(
            loosens(
                house,
                '{"mode":"own","from":"21:00","until":"07:00"}',
                '{"mode":"off"}',
            ),
        ).toBe(false);
    });

    it('works out each house’s quiet hours from the draft: its own, the organisation’s, or none', () => {
        expect(quietAt(settings, {}, 3)).toBeNull();
        expect(quietAt(settings, {}, 4)).toEqual({
            from: '22:00',
            until: '06:00',
            source: 'house',
        });
        const draft: Draft = {
            delivery: { quiet_from: '21:00', quiet_until: '07:00' },
            quietHouse: { 'hours@4': '{"mode":"off"}' },
        };
        expect(quietAt(settings, draft, 3)).toEqual({
            from: '21:00',
            until: '07:00',
            source: 'org',
        });
        expect(quietAt(settings, draft, 4)).toBeNull();
    });

    it('asks for both times, and different ones, before review — with the house’s name', () => {
        expect(
            validateView(
                settings,
                { delivery: { quiet_from: '21:00', quiet_until: '' } },
                'alerts',
            ),
        ).toEqual({
            'delivery.quiet_from': 'Choose when quiet hours start and end.',
        });
        expect(
            validateView(
                settings,
                { delivery: { quiet_from: '21:00', quiet_until: '21:00' } },
                'alerts',
            ),
        ).toEqual({
            'delivery.quiet_from':
                'Quiet hours can’t start and end at the same time.',
        });
        expect(
            validateView(
                settings,
                {
                    quietHouse: {
                        'hours@3': '{"mode":"own","from":"22:00","until":""}',
                    },
                },
                'alerts',
            ),
        ).toEqual({
            'quietHouse.hours@3':
                'Kōwhai House: choose when its quiet hours start and end.',
        });
    });

    it('lists the organisation’s quiet hours as one decision, saying what happens today', () => {
        const pending = stillToDecide(settings).filter(
            (p) => p.group === 'delivery',
        );
        expect(pending).toHaveLength(1);
        expect(pending[0]).toMatchObject({
            key: 'quiet_from',
            label: 'Hold non-urgent alerts overnight',
            until: 'Today: Off — sent straight away',
        });
    });
});

describe('Delivery › Quiet hours', () => {
    it('switches the organisation’s quiet hours on with empty time pickers — no default times', () => {
        let draft: Draft = {};
        render(<Harness alertData={data()} onDraft={(d) => (draft = d)} />);
        const org = row('dl-quieton');
        expect(
            within(org).getByText(
                'Off — every alert is sent straight away (today). Houses below can still set their own.',
            ),
        ).toBeInTheDocument();
        expect(
            within(org).getByText('Default — not yet reviewed'),
        ).toBeInTheDocument();
        fireEvent.click(
            within(org).getByRole('switch', {
                name: 'Hold non-urgent alerts overnight',
            }),
        );
        expect(draft.delivery).toEqual({ quiet_from: '', quiet_until: '' });
        expect(
            screen.getByRole('button', {
                name: 'Quiet hours start: Choose time',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Quiet hours end: Choose time',
            }),
        ).toBeInTheDocument();
        // Rimu House's own hours have their pickers too; these are the organisation's.
        expect(
            within(row('dl-quieton')).getAllByText('Pacific/Auckland'),
        ).toHaveLength(2);
    });

    it('says when held email and push go, and that the bell and Follow up alerts never wait', () => {
        render(
            <Harness
                alertData={data()}
                initialDraft={{
                    delivery: { quiet_from: '21:00', quiet_until: '07:00' },
                }}
            />,
        );
        expect(
            within(row('dl-quieton')).getByText(
                'Email and push for alerts without Follow up wait until 7:00 am. They still show in the bell straight away. Alerts with Follow up are never held. Houses below can change this.',
            ),
        ).toBeInTheDocument();
        expect(
            within(row('dl-qh-3')).getByText(
                'Follows the organisation: 9:00 pm to 7:00 am.',
            ),
        ).toBeInTheDocument();
        expect(
            within(row('dl-qh-4')).getByText('Its own quiet hours.'),
        ).toBeInTheDocument();
        // What happens if nobody attends: email waits overnight, per house.
        expect(screen.getByText('Overnight')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Kōwhai House: 9:00 pm to 7:00 am · Rimu House: 10:00 pm to 6:00 am (own hours)',
            ),
        ).toBeInTheDocument();
    });

    it('lets a house follow the organisation, set its own hours or have none', () => {
        let draft: Draft = {};
        render(<Harness alertData={data()} onDraft={(d) => (draft = d)} />);
        const kowhai = row('dl-qh-3');
        expect(
            within(kowhai).getByText(
                'Follows the organisation: no quiet hours.',
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            within(kowhai).getByRole('button', { name: 'Own hours' }),
        );
        expect(draft.quietHouse).toEqual({
            'hours@3': '{"mode":"own","from":"","until":""}',
        });
        expect(
            within(row('dl-qh-3')).getByRole('button', {
                name: 'Quiet hours start at Kōwhai House: Choose time',
            }),
        ).toBeInTheDocument();
        fireEvent.click(
            within(row('dl-qh-4')).getByRole('button', { name: 'None' }),
        );
        expect(draft.quietHouse?.['hours@4']).toBe('{"mode":"off"}');
        expect(
            within(row('dl-qh-4')).getByText(
                'No quiet hours — every alert is sent straight away here.',
            ),
        ).toBeInTheDocument();
    });

    it('shows a house’s own hours read-only to someone who doesn’t manage it, and the error under a house', () => {
        render(
            <Harness
                alertData={data({
                    access: { view: true, manage_org: false, house_ids: [3] },
                })}
                canEdit={(g) => g === 'quietHouse'}
                errors={{
                    'quietHouse.hours@3':
                        'Kōwhai House: choose when its quiet hours start and end.',
                }}
            />,
        );
        const rimu = row('dl-qh-4');
        expect(
            within(rimu).getByText(
                'Its own quiet hours — only someone who manages Rimu House can change them.',
            ),
        ).toBeInTheDocument();
        expect(
            within(rimu).getByText('10:00 pm to 6:00 am'),
        ).toBeInTheDocument();
        expect(
            within(rimu).getByRole('button', { name: 'None' }),
        ).toBeDisabled();
        expect(
            within(row('dl-qh-3')).getByText(
                'Kōwhai House: choose when its quiet hours start and end.',
            ),
        ).toBeInTheDocument();
        // The organisation's switch is read-only without all-sites authority.
        expect(
            within(row('dl-quieton')).getByRole('switch', {
                name: 'Hold non-urgent alerts overnight',
            }),
        ).toBeDisabled();
    });
});

describe('Delivery › Who can’t be reached', () => {
    it('works out from the draft which gaps matter', () => {
        const rows = reachRows(settings, {}, GAPS);
        expect(rows.map((r) => r.gap.name)).toEqual([
            'Mere Kahu',
            'Hana Kereama',
            'Leilani Faleolo',
        ]);
        expect(rows[0].issues).toEqual([
            {
                kind: 'email',
                text: 'No work email',
                fix: 'An HR admin adds a work email in HR › People.',
                matters: 'Misses 1 alert type sent by email',
            },
        ]);
        // Email switched off for the alert in the draft: it no longer matters.
        const off = reachRows(
            settings,
            { alerts: { stock: alertValue(false) } },
            GAPS,
        );
        expect(off[0].issues[0].matters).toBeNull();
        expect(rows[1].issues.map((i) => [i.text, i.matters])).toEqual([
            ['Push not set up', null],
            [
                'No work phone',
                'Screens would show no number when they’re on call',
            ],
            [
                'On leave Thu 1 – Mon 5 Oct (Leave hub)',
                'On-call backup for Kōwhai House — nights they’re away show nobody',
            ],
        ]);
        expect(rows[2].issues[0].matters).toBeNull();
    });

    it('lists each person with their gaps, why they matter and a status, and explains one from the row', () => {
        render(<Harness alertData={data()} />);
        expect(
            screen.getByText('2 people matter now · 3 with a gap'),
        ).toBeInTheDocument();
        const mere = screen
            .getByText('Mere Kahu')
            .closest('[role="row"]') as HTMLElement;
        expect(
            within(mere).getByText('Misses 1 alert type sent by email'),
        ).toBeInTheDocument();
        expect(within(mere).getByText('Can’t be reached')).toBeInTheDocument();
        const leilani = screen
            .getByText('Leilani Faleolo')
            .closest('[role="row"]') as HTMLElement;
        expect(
            within(leilani).getByText('Not needed with today’s settings'),
        ).toBeInTheDocument();
        expect(within(leilani).getByText('Not needed yet')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Hana Kereama'));
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(
                'Why Hana Kereama can’t always be reached',
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('On leave Thu 1 – Mon 5 Oct (Leave hub)'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                'Choose another backup, or roster an on-call shift for those nights.',
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('button', { name: /On-call contacts/ }),
        ).toBeInTheDocument();
    });

    it('says everyone can be reached when nobody has a gap', () => {
        render(<Harness alertData={data({ reachGaps: [] })} />);
        expect(screen.getByText('Everyone can be reached')).toBeInTheDocument();
        expect(
            screen.getByText('0 people matter now · 0 with a gap'),
        ).toBeInTheDocument();
    });

    it('counts them on the Overview', () => {
        render(<Harness alertData={data()} overview />);
        expect(
            screen.getByText('3 people have a contact gap · 2 matter now.'),
        ).toBeInTheDocument();
        expect(screen.getByText('2 can’t be reached')).toBeInTheDocument();
    });
});
