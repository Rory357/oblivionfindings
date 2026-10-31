import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import type { SignalRuleData } from '@/components/control-room/settings/types';
import ControlRoomSettings from './settings';

const inertia = vi.hoisted(() => ({
    visit: vi.fn(),
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    reload: vi.fn(),
    url: '/control-room/settings?tab=rules',
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...props }: { href: string; children: ReactNode }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        url: inertia.url,
        props: { auth: { can: { controlRoom: { viewAny: true, alertsManage: true } } } },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    inertia.url = '/control-room/settings?tab=rules';
});

function rule(overrides: Partial<SignalRuleData>): SignalRuleData {
    return {
        id: 1,
        name: 'Rule',
        signal_type_id: null,
        signal_type_code: null,
        signal_type_name: null,
        signal_source_id: null,
        signal_source_name: null,
        priority: 100,
        conditions: null,
        output_severity: null,
        output_escalation_level: null,
        output_tier: null,
        dedup_window_minutes: 5,
        deduplicate: true,
        suppress_in_maintenance: true,
        notify_roles: [],
        notify_users: [],
        playbook_id: null,
        playbook_name: null,
        is_active: true,
        ...overrides,
    };
}

const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

function props(
    overrides: Partial<ComponentProps<typeof ControlRoomSettings>> = {},
): ComponentProps<typeof ControlRoomSettings> {
    return {
        activeTab: 'rules',
        signalTypes: [
            { id: 10, code: 'panic_sos', name: 'Panic/SOS (Person)', category: 'people_safety', default_severity: 'critical' },
            { id: 11, code: 'fire_alarm', name: 'Fire alarm', category: 'home_facility', default_severity: 'critical' },
            { id: 12, code: 'fleet_speeding', name: 'Fleet speeding', category: 'fleet', default_severity: 'medium' },
        ],
        signalRules: [
            rule({
                id: 1,
                name: 'Panic/SOS person to critical',
                signal_type_id: 10,
                signal_type_code: 'panic_sos',
                signal_type_name: 'Panic/SOS (Person)',
                output_severity: 'critical',
            }),
            rule({
                id: 2,
                name: 'Fire alarm to critical',
                signal_type_id: 11,
                signal_type_code: 'fire_alarm',
                signal_type_name: 'Fire alarm',
                output_severity: 'critical',
            }),
            rule({
                id: 3,
                name: 'Fleet speeding to medium',
                signal_type_id: 12,
                signal_type_code: 'fleet_speeding',
                signal_type_name: 'Fleet speeding',
                signal_source_id: 4,
                signal_source_name: 'Queclink Fleet',
                output_severity: 'medium',
                is_active: false,
            }),
            rule({ id: 4, name: 'Catch-all', priority: 999 }),
        ],
        signalSources: [
            {
                id: 4,
                name: 'Queclink Fleet',
                slug: 'queclink',
                vendor: 'Queclink',
                status: 'active',
                capabilities: [],
                last_heartbeat_at: minutesAgo(2),
                last_signal_at: minutesAgo(2),
                signal_count_24h: 1200,
                is_healthy: true,
            },
            {
                id: 5,
                name: 'UniFi Protect',
                slug: 'unifi',
                vendor: 'Ubiquiti',
                status: 'active',
                capabilities: [],
                last_heartbeat_at: minutesAgo(45),
                last_signal_at: minutesAgo(45),
                signal_count_24h: 34,
                is_healthy: false,
            },
        ],
        triageQueues: [],
        maintenanceWindows: [],
        signalOutbox: [],
        playbooks: [],
        sites: [],
        configOptions: {},
        ...overrides,
    };
}

/** Each area is its own row group, headed by one cell naming it and its count. */
const groupHeadings = () =>
    [...document.querySelectorAll('tbody[data-area] > tr:first-child > th[scope="rowgroup"]')].map(
        (cell) => cell.textContent,
    );

describe('Settings › Routing', () => {
    it('groups the rules under area headings with a count, rules with no signal type last', () => {
        render(<ControlRoomSettings {...props()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Settings' })).toBeInTheDocument();
        expect(groupHeadings()).toEqual([
            'People safety1 rule',
            'Home and facility1 rule',
            'Fleet and vehicles1 rule',
            'Any signal type1 rule',
        ]);
    });

    it('searches rules by name, signal type and source', () => {
        render(<ControlRoomSettings {...props()} />);
        const search = screen.getByRole('searchbox', { name: 'Search rules by name, signal type or source' });

        fireEvent.change(search, { target: { value: 'queclink' } });
        expect(screen.getByText('Fleet speeding to medium')).toBeInTheDocument();
        expect(screen.queryByText('Fire alarm to critical')).not.toBeInTheDocument();
        expect(groupHeadings()).toEqual(['Fleet and vehicles1 rule']);
        expect(screen.getByText('Showing 1 of 4')).toBeInTheDocument();

        fireEvent.change(search, { target: { value: 'panic/sos (person)' } });
        expect(screen.getByText('Panic/SOS person to critical')).toBeInTheDocument();

        fireEvent.change(search, { target: { value: 'nothing like this' } });
        expect(screen.getByText('No rules match these filters')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(screen.getByText('Catch-all')).toBeInTheDocument();
    });

    it('header numbers count the whole view and filter it in place', () => {
        render(<ControlRoomSettings {...props()} />);

        const rules = screen.getByRole('button', { name: 'Show every routing rule' });
        expect(rules).toHaveTextContent('4');
        expect(rules).toHaveTextContent('in 4 areas');
        expect(screen.getByRole('button', { name: 'Show routing rules that are on' })).toHaveTextContent('3');

        fireEvent.click(screen.getByRole('button', { name: 'Show routing rules that are off' }));
        expect(screen.getByText('Fleet speeding to medium')).toBeInTheDocument();
        expect(screen.queryByText('Catch-all')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Show rules that raise critical alerts' }));
        // Off and critical: nothing matches both.
        expect(screen.getByText('No rules match these filters')).toBeInTheDocument();

        fireEvent.click(rules);
        expect(screen.getByText('Catch-all')).toBeInTheDocument();
        expect(rules).toHaveAttribute('aria-pressed', 'true');
    });

    it('has the area, severity and on/off filters in the header', () => {
        render(<ControlRoomSettings {...props()} />);

        fireEvent.click(screen.getByRole('button', { name: 'All areas' }));
        fireEvent.click(screen.getByRole('button', { name: 'Home and facility' }));
        expect(screen.getByText('Fire alarm to critical')).toBeInTheDocument();
        expect(screen.queryByText('Panic/SOS person to critical')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Clear All areas' }));
        fireEvent.click(screen.getByRole('button', { name: 'All severities' }));
        fireEvent.click(screen.getByRole('button', { name: "Keeps the signal's severity" }));
        expect(screen.getByText('Catch-all')).toBeInTheDocument();
        expect(screen.queryByText('Fire alarm to critical')).not.toBeInTheDocument();
    });

    it('turning a rule off asks first, then saves it off', () => {
        render(<ControlRoomSettings {...props()} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Routing rule Fire alarm to critical on' }));
        expect(inertia.put).not.toHaveBeenCalled();
        const confirm = screen.getByRole('alertdialog', { name: 'Turn off this rule?' });
        fireEvent.click(within(confirm).getByRole('button', { name: 'Turn off rule' }));
        expect(inertia.put).toHaveBeenCalledWith(
            '/control-room/settings/rules/2',
            expect.objectContaining({ id: 2, is_active: false }),
            expect.any(Object),
        );
    });

    it('turning a rule back on is direct', () => {
        render(<ControlRoomSettings {...props()} />);

        fireEvent.click(screen.getByRole('switch', { name: 'Routing rule Fleet speeding to medium on' }));
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(inertia.put).toHaveBeenCalledWith(
            '/control-room/settings/rules/3',
            expect.objectContaining({ id: 3, is_active: true }),
            expect.any(Object),
        );
    });

    it('rows keep the kebab and the right-click menu with the same actions', () => {
        render(<ControlRoomSettings {...props()} />);

        const row = screen.getByText('Catch-all').closest('tr')!;
        fireEvent.contextMenu(row);
        expect(screen.getByRole('menuitem', { name: 'Edit rule' })).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: 'Turn off' })).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: 'Delete rule' })).toBeInTheDocument();
    });
});

describe('Settings › every tab has the same header shape', () => {
    // Each tab's own first filter, so the filter row never collapses.
    const tabs: Array<[string, string]> = [
        ['rules', 'All areas'],
        ['queues', 'On and off'],
        ['sources', 'Every source'],
        ['maintenance', 'Every status'],
        ['signal-outbox', 'Every problem'],
        ['ticket-options', 'All lists'],
    ];

    it.each(tabs)('%s: a subline, four numbers that are all links or in-page filters, and a filter row', (tab, filter) => {
        inertia.url = `/control-room/settings?tab=${tab}`;
        const { container } = render(<ControlRoomSettings {...props({ activeTab: tab })} />);

        expect(container.querySelector('[data-slot="page-header-subline"]')?.textContent).toBeTruthy();
        const meters = container.querySelectorAll('[data-slot="page-header-meters"] > *');
        expect(meters).toHaveLength(4);
        meters.forEach((meter) => expect(['A', 'BUTTON']).toContain(meter.tagName));
        expect(screen.getByRole('button', { name: filter })).toBeInTheDocument();
    });

    it('Sources: says which sources are silent, and filters to them', () => {
        inertia.url = '/control-room/settings?tab=sources';
        render(<ControlRoomSettings {...props({ activeTab: 'sources' })} />);

        expect(screen.getByRole('button', { name: 'Show sources that have gone silent' })).toHaveTextContent('1');
        expect(screen.getByRole('link', { name: 'Open the sources with a signal in the last 24 hours' })).toHaveAttribute(
            'href',
            '/control-room/devices?activity=recent',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Show sources that have gone silent' }));
        expect(screen.getByText('UniFi Protect')).toBeInTheDocument();
        expect(screen.queryByText('Queclink Fleet')).not.toBeInTheDocument();
    });
});
