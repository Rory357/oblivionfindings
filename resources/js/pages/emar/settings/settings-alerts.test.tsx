import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState, type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    AlertDialogHost,
    AlertsDelivery,
    AlertsTable,
    type AlertData,
    type AlertPerson,
    type AlertPreview,
} from './_alerts';
import { SettingsCtx, type Dialog, type SettingsContext } from './_context';
import {
    changes,
    fallbackHouses,
    fallbackWarnings,
    format,
    loosens,
    siteSlot,
    validateView,
    type AlertMeta,
    type Draft,
    type SettingDefinition,
    type SettingsPayload,
} from './_model';

vi.mock('@inertiajs/react', () => ({
    router: { put: vi.fn(), post: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));

const LABELS = {
    rostered: {
        label: 'Everyone rostered on a covering shift',
        description: 'Checked against the roster at the time of the alert',
    },
    houseLead: {
        label: 'House lead',
        description: 'The lead for the house the alert is about',
    },
    clinicalLead: {
        label: 'Clinical lead',
        description: 'Clinical leads with access to the house',
    },
    stockStaff: {
        label: 'People who update stock here',
        description: 'Anyone with “update stock” at the house',
    },
};
const meta = (
    key: string,
    label: string,
    groups: (keyof typeof LABELS)[],
    locked: string[] = [],
    controlled = false,
): AlertMeta => ({
    key,
    label,
    subline: `${label} — subline`,
    groups,
    locked,
    group_labels: Object.fromEntries(groups.map((g) => [g, LABELS[g]])),
    controlled,
    until: 'Until done',
    channels: ['inapp', 'email', 'push'],
});
const value = (groups: string[], people: number[] = [], inapp = true) =>
    JSON.stringify({
        inapp,
        email: false,
        push: false,
        follow_up: false,
        groups,
        people,
    });
const alertDef = (m: AlertMeta, dflt: string): SettingDefinition => ({
    group: 'alerts',
    key: m.key,
    scope: 'organisation',
    section: 'alerts',
    label: `Who gets “${m.label}”`,
    options: [],
    default: dflt,
    range: null,
    unit: null,
    paired_with: null,
    rank: null,
    numeric: null,
    kind: 'alert',
    decide_label: `Alert: ${m.label}`,
    alert: m,
});
const extraDef = (key: string, label: string): SettingDefinition => ({
    group: 'alertExtra',
    key,
    scope: 'site',
    section: 'alerts',
    label: `${label} — extra people`,
    options: [],
    default: '[]',
    range: null,
    unit: null,
    paired_with: null,
    rank: null,
    numeric: null,
    kind: 'people',
    house_managed: true,
    empty_label: 'Nobody extra',
});

const followNumber = (
    key: string,
    label: string,
    unit: string,
    range: [number, number],
    direction: 'higher_is_looser' | 'higher_is_stricter',
    pairedWith: string | null,
): SettingDefinition => ({
    group: 'delivery',
    key,
    scope: 'organisation',
    section: 'delivery',
    label,
    options: [],
    default: 'off',
    range,
    unit,
    paired_with: pairedWith,
    rank: null,
    numeric: { direction, off: 'off', off_is_loosest: true },
    off_label: 'Off',
});

const STOCK = meta('stock', 'Stock running low', [
    'houseLead',
    'stockStaff',
    'clinicalLead',
    'rostered',
]);
const FOLLOWUPS = meta(
    'followups',
    'Follow-ups overdue',
    ['rostered', 'houseLead', 'clinicalLead'],
    ['rostered', 'houseLead'],
);
const CD_CHECK = meta(
    'cdCheck',
    'Controlled-drug balance check overdue',
    ['houseLead', 'clinicalLead'],
    [],
    true,
);

const settings: SettingsPayload = {
    groups: {
        alerts: {
            key: 'alerts',
            view: 'alerts',
            effect: 'From the next alert sent, at every house',
            audit_event: 'medications.alert_recipients.updated',
            keys: ['followups', 'stock', 'cdCheck'],
        },
        alertExtra: {
            key: 'alertExtra',
            view: 'alerts',
            effect: 'From the next alert at that house',
            audit_event: 'medications.alert_recipients.updated',
            keys: ['followups', 'stock', 'cdCheck'],
        },
        delivery: {
            key: 'delivery',
            view: 'alerts',
            effect: 'From the next alert sent, at every house',
            audit_event: 'medications.alert_delivery.updated',
            keys: [
                'private',
                'realert_every',
                'realert_max',
                'attended',
                'escalate_after',
                'escalate_to',
            ],
        },
    },
    definitions: {
        alerts: {
            followups: alertDef(FOLLOWUPS, value(['rostered', 'houseLead'])),
            stock: alertDef(STOCK, value(['houseLead', 'stockStaff'])),
            cdCheck: alertDef(CD_CHECK, value(['houseLead'])),
        },
        delivery: {
            private: {
                group: 'delivery',
                key: 'private',
                scope: 'organisation',
                section: 'delivery',
                label: 'Keep client names and medicines out of email and push',
                options: [
                    {
                        value: 'no',
                        label: 'Off — email and push include client names and medicines',
                    },
                    { value: 'yes', label: 'On' },
                ],
                default: 'yes',
                range: null,
                unit: null,
                paired_with: null,
                rank: ['no', 'yes'],
                numeric: null,
            },
            realert_every: followNumber(
                'realert_every',
                'Re-alert until someone attends',
                'minutes between re-alerts',
                [15, 1440],
                'higher_is_looser',
                'realert_max',
            ),
            realert_max: followNumber(
                'realert_max',
                'Most re-alerts',
                'times',
                [1, 10],
                'higher_is_stricter',
                'realert_every',
            ),
            attended: {
                group: 'delivery',
                key: 'attended',
                scope: 'organisation',
                section: 'delivery',
                label: 'An alert counts as attended when',
                options: [
                    { value: 'open', label: 'Someone opens it' },
                    { value: 'ack', label: 'Someone acknowledges it' },
                    { value: 'done', label: 'It’s dealt with' },
                ],
                default: 'ack',
                range: null,
                unit: null,
                paired_with: null,
                rank: ['open', 'ack', 'done'],
                numeric: null,
            },
            escalate_after: followNumber(
                'escalate_after',
                'Escalate if still not attended',
                'minutes',
                [15, 1440],
                'higher_is_looser',
                null,
            ),
            escalate_to: {
                group: 'delivery',
                key: 'escalate_to',
                scope: 'organisation',
                section: 'delivery',
                label: 'Escalate to',
                options: [],
                default: '[]',
                range: null,
                unit: null,
                paired_with: null,
                rank: null,
                numeric: null,
                kind: 'groups',
                group_options: [
                    { value: 'houseLead', label: 'House lead' },
                    { value: 'clinicalLead', label: 'Clinical lead' },
                    { value: 'providerManager', label: 'Provider manager' },
                ],
            },
        },
        alertExtra: {
            followups: extraDef('followups', 'Follow-ups overdue'),
            stock: extraDef('stock', 'Stock running low'),
            cdCheck: extraDef(
                'cdCheck',
                'Controlled-drug balance check overdue',
            ),
        },
    },
    values: {
        alerts: {
            followups: value(['rostered', 'houseLead']),
            stock: value(['houseLead', 'stockStaff', 'clinicalLead'], [7]),
            cdCheck: value(['houseLead']),
        },
    },
    reviewed: {
        alerts: {
            followups: null,
            stock: { by: 'Hana Kereama', at: null },
            cdCheck: null,
        },
    },
    site_values: { '3': { alertExtra: { stock: '[8]' } } },
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
    people_names: {
        '7': 'Rangi Parata',
        '8': 'Mere Wilson',
        '9': 'Sione Taufa',
    },
    site_names: { '3': 'Kōwhai House', '4': 'Rimu House' },
    // Kōwhai has a house lead; Rimu has nobody in any group, and one
    // medication settings manager (without controlled-medicine access).
    alert_reach: {
        houses: {
            '3': {
                groups: {
                    rostered: { all: 0, controlled: 0 },
                    houseLead: { all: 1, controlled: 1 },
                    clinicalLead: { all: 0, controlled: 0 },
                    stockStaff: { all: 1, controlled: 1 },
                },
                fallback: { all: 2, controlled: 2 },
            },
            '4': {
                groups: {
                    rostered: { all: 0, controlled: 0 },
                    houseLead: { all: 0, controlled: 0 },
                    clinicalLead: { all: 0, controlled: 0 },
                    stockStaff: { all: 0, controlled: 0 },
                },
                fallback: { all: 1, controlled: 0 },
            },
        },
        people: {
            '7': { ok: true, site_ids: [3], controlled: true },
            '8': { ok: true, site_ids: [3], controlled: true },
            '9': { ok: true, site_ids: [4], controlled: false },
        },
    },
};

const person = (
    id: number,
    name: string,
    houses: [number, string][],
    controlled = true,
): AlertPerson => ({
    id,
    name,
    role: 'Support worker',
    houses: houses.map(([, n]) => n),
    site_ids: houses.map(([h]) => h),
    all_houses: false,
    controlled,
});
const people: AlertPerson[] = [
    person(7, 'Rangi Parata', [[3, 'Kōwhai House']]),
    person(8, 'Mere Wilson', [[3, 'Kōwhai House']]),
    person(9, 'Sione Taufa', [[4, 'Rimu House']], false),
];
const sites = [
    { id: 3, name: 'Kōwhai House' },
    { id: 4, name: 'Rimu House' },
];

function Harness({
    data,
    initialDialog = null,
    onDraft,
    show = 'all',
    delivery = false,
    initialDraft = {},
}: {
    data: AlertData;
    initialDialog?: Dialog | null;
    onDraft?: (d: Draft) => void;
    show?: string;
    delivery?: boolean;
    initialDraft?: Draft;
}) {
    const [draft, setDraftState] = useState<Draft>(initialDraft);
    const [dialog, setDialog] = useState<Dialog | null>(initialDialog);
    const ctx: SettingsContext = {
        s: settings,
        draft,
        setDraft: (fn) =>
            setDraftState((d) => {
                const next = fn(d);
                onDraft?.(next);
                return next;
            }),
        canEdit: () => true,
        go: vi.fn(),
        open: setDialog,
        close: () => setDialog(null),
        flash: vi.fn(),
        freshAfter: 0,
        leave: vi.fn(),
        errors: {},
        clearError: vi.fn(),
    };
    return (
        <SettingsCtx.Provider value={ctx}>
            {delivery ? (
                <AlertsDelivery q="" show={show} clear={vi.fn()} data={data} />
            ) : (
                <AlertsTable q="" show={show} clear={vi.fn()} data={data} />
            )}
            <AlertDialogHost dialog={dialog} data={data} />
        </SettingsCtx.Provider>
    );
}

// As the server renders them (MedicationAlertPreviews through the real notification).
const STOCK_PREVIEW: AlertPreview = {
    controlled: false,
    inapp: {
        title: 'Stock running low',
        message:
            'Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.',
    },
    private: {
        email: {
            subject: 'Stock running low',
            lines: [
                'A medicine at Kōwhai House is running low.',
                'Open Oblivion Care to see the details and respond.',
            ],
            action: 'Open in Oblivion Care',
        },
        push: {
            title: 'Stock running low',
            body: 'A medicine at Kōwhai House is running low.',
        },
    },
    open: {
        email: {
            subject:
                'Stock running low — Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.',
            lines: [
                'Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.',
                'Open Oblivion Care to see the details and respond.',
            ],
            action: 'Open in Oblivion Care',
        },
        push: {
            title: 'Stock running low',
            body: 'Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.',
        },
    },
};
const orgEditor: AlertData = {
    access: { view: true, manage_org: true, house_ids: [3, 4] },
    people,
    sites,
    readOnlyAudit: false,
    nobodyOpen: 0,
    previews: { stock: STOCK_PREVIEW },
    delivery: { push_ready: 2, people: 3 },
    onCall: { houses: [], staff: {} },
    houses: [],
    reachGaps: [],
};
const houseLead: AlertData = {
    access: { view: true, manage_org: false, house_ids: [3] },
    people: people.filter((p) => p.site_ids.includes(3)),
    sites: [sites[0]],
    readOnlyAudit: false,
    nobodyOpen: 0,
    previews: { stock: STOCK_PREVIEW },
    delivery: { push_ready: 1, people: 2 },
    onCall: { houses: [], staff: {} },
    houses: [],
    reachGaps: [],
};

afterEach(cleanup);

describe('Alerts & access › Alerts', () => {
    it('lists each alert with who gets it, its status and decided alerts locked in the bell', () => {
        render(<Harness data={orgEditor} />);
        const stock = screen
            .getByText('Stock running low')
            .closest('[role="row"]') as HTMLElement;
        expect(
            within(stock).getByText(
                'House lead, People who update stock here +2 · 1 house extra',
            ),
        ).toBeInTheDocument();
        expect(within(stock).getByText('Reviewed')).toBeInTheDocument();
        const followups = screen
            .getByText('Follow-ups overdue')
            .closest('[role="row"]') as HTMLElement;
        expect(within(followups).getByText('Always on')).toBeInTheDocument();
        expect(
            within(followups).getByRole('switch', { name: /in-app/ }),
        ).toBeDisabled();
        // B2 C1 review: while in-app is the only channel on, it stays on.
        expect(
            within(stock).getByText('Only way it’s sent'),
        ).toBeInTheDocument();
        expect(
            within(stock).getByRole('switch', { name: /in-app/ }),
        ).toBeDisabled();
        expect(
            within(followups).getByText('Default — not yet reviewed'),
        ).toBeInTheDocument();
        // Email and push send since B2 C2; Follow up since C3.
        expect(screen.getByText('Email')).toBeInTheDocument();
        expect(screen.getByText('Push')).toBeInTheDocument();
        expect(screen.getByText('Follow up')).toBeInTheDocument();
    });

    it('edits who gets an alert into the draft: groups, named people and a house’s extras', () => {
        let draft: Draft = {};
        render(
            <Harness
                data={orgEditor}
                initialDialog={{ kind: 'alertwho', key: 'stock' }}
                onDraft={(d) => (draft = d)}
            />,
        );
        // Groups: switch off the clinical lead.
        fireEvent.click(screen.getByLabelText('Clinical lead'));
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        // Named people: remove Rangi.
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Rangi Parata' }),
        );
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        // House extras at Kōwhai House: remove Mere.
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Mere Wilson' }),
        );
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        fireEvent.click(screen.getByRole('button', { name: /Apply to draft/ }));

        expect(JSON.parse(draft.alerts.stock)).toMatchObject({
            groups: ['houseLead', 'stockStaff'],
            people: [],
        });
        expect(draft.alertExtra[siteSlot('stock', 3)]).toBe('[]');
    });

    it('lets a house lead change only their own house’s extras', () => {
        render(
            <Harness
                data={houseLead}
                initialDialog={{ kind: 'alertwho', key: 'stock' }}
            />,
        );
        expect(
            screen.getByText(
                /Only someone with all-sites authority changes the groups/,
            ),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('House lead')).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        expect(screen.queryByRole('button', { name: /^Remove / })).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        expect(
            screen.getByRole('button', { name: 'Remove Mere Wilson' }),
        ).toBeEnabled();
    });

    it('won’t offer someone without controlled-medicine access for a controlled alert', () => {
        render(
            <Harness
                data={orgEditor}
                initialDialog={{ kind: 'alertperson', key: 'cdCheck' }}
            />,
        );
        fireEvent.click(screen.getByRole('combobox'));
        expect(
            screen.getByText(/Sione Taufa/).closest('[cmdk-item]'),
        ).toHaveAttribute('aria-disabled', 'true');
        expect(
            screen.getByText(/can’t be chosen: no controlled-medicine access/),
        ).toBeInTheDocument();
    });
});

describe('Who gets an alert, in the settings model', () => {
    const def = settings.definitions.alerts.stock;
    it('reads in the server’s words, naming people', () => {
        expect(
            format(def, value(['houseLead'], [7]), settings.people_names),
        ).toBe(
            'In-app on · email off · push off · follow up off · House lead, Rangi Parata',
        );
        expect(format(def, value([]), settings.people_names)).toBe(
            'In-app on · email off · push off · follow up off · nobody',
        );
        expect(format(settings.definitions.alertExtra.stock, '[]')).toBe(
            'Nobody extra',
        );
    });

    it('loosens when a channel goes off or anyone is dropped', () => {
        expect(
            loosens(
                def,
                value(['houseLead']),
                value(['houseLead', 'rostered']),
            ),
        ).toBe(false);
        expect(
            loosens(
                def,
                value(['houseLead', 'rostered']),
                value(['houseLead']),
            ),
        ).toBe(true);
        expect(
            loosens(def, value(['houseLead'], [7]), value(['houseLead'])),
        ).toBe(true);
        expect(
            loosens(def, value(['houseLead']), value(['houseLead'], [], false)),
        ).toBe(true);
        expect(
            loosens(settings.definitions.alertExtra.stock, '[8]', '[]'),
        ).toBe(true);
    });

    it('lists a house’s change with its house, and stops an alert nobody would get', () => {
        const draft: Draft = {
            alerts: { cdCheck: value(['houseLead'], [], false) },
            alertExtra: { [siteSlot('stock', 3)]: '[8,9]' },
        };
        const list = changes(settings, draft, 'alerts');
        expect(list.map((c) => [c.label, c.site_id, c.toText])).toEqual([
            [
                'Who gets “Controlled-drug balance check overdue”',
                null,
                'In-app off · email off · push off · follow up off · House lead',
            ],
            [
                'Stock running low — extra people at Kōwhai House',
                3,
                'Mere Wilson, Sione Taufa',
            ],
        ]);
        expect(validateView(settings, draft, 'alerts')).toEqual({
            'alerts.cdCheck':
                '“Controlled-drug balance check overdue”: turn on in-app, email or push — otherwise nobody is told.',
        });
    });
});

describe('The safety net (Main, 2 Oct)', () => {
    it('finds the houses where an alert’s choices would tell nobody, like the server', () => {
        expect(fallbackHouses(settings, {}, 'stock')).toEqual([
            { siteId: 4, name: 'Rimu House', nobody: false },
        ]);
        // Controlled: Rimu's only settings manager lacks controlled-medicine access.
        expect(fallbackHouses(settings, {}, 'cdCheck')).toEqual([
            { siteId: 4, name: 'Rimu House', nobody: true },
        ]);
        // A house extra who can get it there fills the gap…
        expect(
            fallbackHouses(
                settings,
                { alertExtra: { [siteSlot('stock', 4)]: '[9]' } },
                'stock',
            ),
        ).toEqual([]);
        // …but not for a controlled alert, without controlled-medicine access.
        expect(
            fallbackHouses(
                settings,
                { alertExtra: { [siteSlot('cdCheck', 4)]: '[9]' } },
                'cdCheck',
            ),
        ).toHaveLength(1);
        // Dropping the house lead makes Kōwhai fall back too.
        expect(
            fallbackHouses(
                settings,
                { alerts: { cdCheck: value([]) } },
                'cdCheck',
            ).map((h) => h.name),
        ).toEqual(['Kōwhai House', 'Rimu House']);
    });

    it('says it plainly, and shows it in Goes to', () => {
        expect(
            fallbackWarnings([
                { siteId: 4, name: 'Rimu House', nobody: false },
                { siteId: 5, name: 'Kauri House', nobody: true },
            ]),
        ).toEqual([
            'Nobody at Rimu House in these groups — goes to medication settings managers',
            'Nobody at Kauri House in these groups or among medication settings managers — nobody would be told',
        ]);
        render(<Harness data={orgEditor} />);
        const cd = screen
            .getByText('Controlled-drug balance check overdue')
            .closest('[role="row"]') as HTMLElement;
        expect(
            within(cd).getByText(
                'Nobody at Rimu House in these groups or among medication settings managers — nobody would be told',
            ),
        ).toBeInTheDocument();
    });
});

describe('Alerts & access › email, push and delivery (B2 C2)', () => {
    it('turns email on for an alert, after which in-app can be switched off but not the last channel', () => {
        let last: Draft = {};
        render(<Harness data={orgEditor} onDraft={(d) => (last = d)} />);
        const stock = screen
            .getByText('Stock running low')
            .closest('[role="row"]') as HTMLElement;
        fireEvent.click(
            within(stock).getByRole('switch', {
                name: 'Stock running low: email',
            }),
        );
        expect(JSON.parse(last.alerts!.stock)).toMatchObject({
            inapp: true,
            email: true,
            push: false,
        });
        const inapp = within(stock).getByRole('switch', { name: /in-app/ });
        expect(inapp).toBeEnabled();
        expect(within(stock).queryByText('Only way it’s sent')).toBeNull();
        fireEvent.click(inapp);
        expect(JSON.parse(last.alerts!.stock)).toMatchObject({
            inapp: false,
            email: true,
        });
        // Email off too: in-app comes back on — never no channel at all.
        fireEvent.click(
            within(stock).getByRole('switch', {
                name: 'Stock running low: email',
            }),
        );
        expect(JSON.parse(last.alerts!.stock)).toMatchObject({
            inapp: true,
            email: false,
        });
    });

    it('filters to alerts sent by email or push, and offers the message preview on the row menu', () => {
        const emailOn = JSON.stringify({
            inapp: true,
            email: true,
            push: false,
            follow_up: false,
            groups: ['houseLead'],
            people: [],
        });
        render(
            <Harness
                data={orgEditor}
                show="email"
                initialDraft={{ alerts: { cdCheck: emailOn } }}
            />,
        );
        expect(
            screen.getByText('Controlled-drug balance check overdue'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Stock running low')).toBeNull();
        cleanup();

        render(<Harness data={orgEditor} />);
        const stock = screen
            .getByText('Stock running low')
            .closest('[role="row"]') as HTMLElement;
        fireEvent.contextMenu(stock);
        expect(
            screen.getByRole('menuitem', { name: 'Preview message' }),
        ).toBeInTheDocument();
    });

    it('shows Delivery: channel counts, push set-up and the privacy switch, on by default', () => {
        let last: Draft = {};
        render(
            <Harness data={orgEditor} delivery onDraft={(d) => (last = d)} />,
        );
        const row = (label: string) =>
            screen.getByText(label).closest('[data-setting]') as HTMLElement;
        expect(
            within(row('Alert types sent by email')).getByText('0 of 3'),
        ).toBeInTheDocument();
        expect(
            within(row('Alert types sent in-app')).getByText('3 of 3'),
        ).toBeInTheDocument();
        expect(
            within(row('Staff with push set up')).getByText('2 of 3'),
        ).toBeInTheDocument();
        const privacy = screen.getByRole('switch', {
            name: 'Keep client names and medicines out of email and push',
        });
        expect(privacy).toBeChecked();
        expect(
            within(
                row('Keep client names and medicines out of email and push'),
            ).getByText('Default — not yet reviewed'),
        ).toBeInTheDocument();
        fireEvent.click(privacy);
        expect(last.delivery?.private).toBe('no');
        // Hide-unbuilt: the digest and personal copies aren't shown yet.
        expect(
            screen.queryByText('Group emails into an hourly summary'),
        ).toBeNull();
    });

    it('previews a message from the server’s rendering, with the privacy switch from the draft', () => {
        render(
            <Harness
                data={orgEditor}
                initialDialog={{ kind: 'msgpreview', key: 'stock' }}
            />,
        );
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(
                'Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.',
            ),
        ).toBeInTheDocument();
        fireEvent.click(within(dialog).getByRole('tab', { name: /Email/ }));
        expect(
            within(dialog).getByText('Stock running low', {
                selector: 'p.font-semibold',
            }),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(
                'A medicine at Kōwhai House is running low.',
            ),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/Client names and medicines are left out/),
        ).toBeInTheDocument();
        cleanup();

        render(
            <Harness
                data={orgEditor}
                initialDialog={{ kind: 'msgpreview', key: 'stock' }}
                initialDraft={{ delivery: { private: 'no' } }}
            />,
        );
        const open = screen.getByRole('dialog');
        fireEvent.click(within(open).getByRole('tab', { name: /Push/ }));
        expect(
            within(open).getByText(
                'Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.',
            ),
        ).toBeInTheDocument();
        expect(
            within(open).getByText(/Client names and medicines are included/),
        ).toBeInTheDocument();
    });
});

describe('Alerts & access › follow-up (B2 C3)', () => {
    const followed = JSON.stringify({
        inapp: true,
        email: false,
        push: false,
        follow_up: true,
        groups: ['rostered', 'houseLead'],
        people: [],
    });

    it('turns Follow up on for an alert from its column', () => {
        let last: Draft = {};
        render(<Harness data={orgEditor} onDraft={(d) => (last = d)} />);
        fireEvent.click(
            screen.getByRole('switch', {
                name: 'Stock running low: follow up until attended',
            }),
        );
        expect(JSON.parse(last.alerts!.stock).follow_up).toBe(true);
    });

    it('re-alerts and escalates from Delivery, asking for the numbers and who it escalates to', () => {
        let last: Draft = {};
        render(
            <Harness
                data={orgEditor}
                delivery
                initialDraft={{ alerts: { followups: followed } }}
                onDraft={(d) => (last = d)}
            />,
        );
        // Follow up on, both off: still sent once.
        expect(
            screen.getByText(/re-alerting and escalation are both off/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('switch', {
                name: 'Re-alert until someone attends',
            }),
        );
        // On asks for numbers: never invented.
        expect(last.delivery).toMatchObject({
            realert_every: '',
            realert_max: '',
        });
        fireEvent.change(screen.getByLabelText('Minutes between re-alerts'), {
            target: { value: '30' },
        });
        fireEvent.change(screen.getByLabelText('Most re-alerts'), {
            target: { value: '2' },
        });
        fireEvent.click(
            screen.getByRole('switch', {
                name: 'Escalate if still not attended',
            }),
        );
        fireEvent.change(screen.getByLabelText('Minutes before escalating'), {
            target: { value: '30' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Clinical lead' }));
        expect(last.delivery).toMatchObject({
            realert_every: '30',
            realert_max: '2',
            escalate_after: '30',
            escalate_to: '["clinicalLead"]',
        });
        // What happens if nobody attends, worked out from the draft.
        const preview = screen
            .getByText('What happens if nobody attends')
            .closest('div.rounded-xl') as HTMLElement;
        expect(
            within(preview).getByText('Re-alert and escalate'),
        ).toBeInTheDocument();
        expect(
            within(preview).getByText(
                'The same people again, plus Clinical lead · in-app',
            ),
        ).toBeInTheDocument();
        expect(
            within(preview).getByText(
                'Everyone told so far, including Clinical lead · in-app',
            ),
        ).toBeInTheDocument();
    });

    it('stops a save that escalates to nobody', () => {
        expect(
            validateView(
                settings,
                { delivery: { escalate_after: '60', escalate_to: '[]' } },
                'alerts',
            ),
        ).toMatchObject({
            'delivery.escalate_to': 'Choose who it escalates to.',
        });
        expect(
            loosens(settings.definitions.delivery.attended, 'done', 'open'),
        ).toBe(true);
        expect(
            loosens(settings.definitions.delivery.attended, 'ack', 'done'),
        ).toBe(false);
        expect(
            format(
                settings.definitions.delivery.escalate_to,
                '["houseLead","clinicalLead"]',
            ),
        ).toBe('House lead, Clinical lead');
    });
});
