import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AlertsIndex, { type AlertsProps } from './index';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    visit: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), warning: vi.fn() } }));

const ago = (minutes: number) =>
    new Date(Date.now() - minutes * 60_000).toISOString();

function fixture(overrides: Partial<AlertsProps> = {}): AlertsProps {
    return {
        tab: 'open',
        counts: { open: 2, snoozed: 1, done: 4, rules: 1 },
        site: 'all',
        sites: [
            { value: '1', label: 'Synthetic House' },
            { value: '2', label: 'Synthetic Lodge' },
        ],
        q: null,
        meters: {
            needs_now: { count: 1, caption: 'Synthetic Lodge router' },
            waiting: {
                count: 1,
                caption: { text: 'Oldest {ago}', at: ago(30) },
            },
            quiet: { text: 'None now', caption: 'Nothing planned' },
        },
        queue: [
            {
                id: 1,
                title: 'Synthetic Lodge router is offline',
                tone: 'critical',
                state: 'open',
                opened_at: ago(125),
                closed_at: null,
                snoozed_until: null,
                sub: 'Synthetic Lodge · Test Person told',
                badge: { tone: 'critical', text: 'Offline' },
            },
            {
                id: 2,
                title: 'Lounge Wi-Fi is offline',
                tone: 'success',
                state: 'done',
                opened_at: ago(300),
                closed_at: ago(200),
                snoozed_until: null,
                sub: 'Synthetic House · Resolved {closed}',
                badge: { tone: 'success', text: 'Resolved' },
            },
        ],
        done: null,
        selected: {
            id: 1,
            title: 'Synthetic Lodge router is offline',
            tone: 'critical',
            state: 'open',
            label: { text: 'Needs someone now · offline {for}', at: ago(125) },
            opened_at: ago(125),
            closed_at: null,
            device: {
                id: 9,
                name: 'Synthetic Lodge router',
                sub: 'Test Router 1 · 10.0.0.1 · Synthetic Lodge',
            },
            facts: [
                {
                    icon: 'map-pin',
                    text: 'Synthetic Lodge · comms cupboard',
                    at: null,
                },
                { icon: 'clock', text: '**{time}**', at: ago(125) },
            ],
            saw: 'Synthetic Lodge router stopped answering at {time}.',
            affects: {
                count: 3,
                text: '3 devices behind it can’t be checked: 3 cameras.',
            },
            timeline: [
                {
                    id: 1,
                    at: ago(125),
                    tone: 'critical',
                    title: 'Stopped answering',
                    sub: '3 missed checks in a row',
                },
                {
                    id: 2,
                    at: ago(120),
                    tone: 'info',
                    title: 'Test Person told',
                    sub: 'App and email',
                },
            ],
            acknowledged: null,
            assigned: null,
            can: {
                acknowledge: true,
                assign: true,
                snooze: true,
                report_incident: true,
            },
        },
        rules: null,
        per_week: null,
        can: {
            acknowledge: true,
            quiet_time: true,
            add_rule: true,
            report_incident: true,
        },
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.clearAllMocks();
});

describe('Alerts', () => {
    it('shows the queue, most urgent first, with the chosen alert’s next step, story and timeline in NZ words', () => {
        render(<AlertsIndex {...fixture()} />);

        expect(
            screen.getByRole('heading', { name: 'Alerts' }),
        ).toBeInTheDocument();
        const box = screen.getByRole('region', { name: 'The alert you chose' });
        expect(
            within(box).getByText(/Needs someone now · offline 2 h 5 min/),
        ).toBeInTheDocument();
        expect(
            within(box).getByText('Test Router 1 · 10.0.0.1 · Synthetic Lodge'),
        ).toBeInTheDocument();
        expect(
            within(box).getByRole('button', { name: 'Acknowledge' }),
        ).toBeInTheDocument();
        expect(
            within(box).getByRole('button', { name: 'Assign' }),
        ).toBeInTheDocument();
        expect(
            within(box).getByRole('button', { name: 'Snooze' }),
        ).toBeInTheDocument();
        expect(screen.getByText('What we saw')).toBeInTheDocument();
        expect(
            screen.getByText(
                '3 devices behind it can’t be checked: 3 cameras.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Stopped answering')).toBeInTheDocument();
        expect(
            screen.getByText(/Synthetic House · Resolved 3 h 20 min ago/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Is someone already on it?'),
        ).toBeInTheDocument();
        // Placeholders and markup never reach the screen.
        expect(
            screen.queryByText(/\{for\}|\{time\}|\{closed\}|\{ago\}|\*\*/),
        ).not.toBeInTheDocument();
    });

    it('leads with the three numbers and folds the zeros into one calm line', () => {
        const props = fixture({
            meters: {
                needs_now: { count: 0, caption: 'Nobody needed right now' },
                waiting: { count: 0, caption: 'Nothing waiting' },
                quiet: { text: '1 now', caption: 'Until 7:00 am' },
            },
        });
        render(<AlertsIndex {...props} />);

        expect(
            screen.getByText('Nobody needs to act on an alert'),
        ).toBeInTheDocument();
        expect(screen.getByText('1 now')).toBeInTheDocument();
        expect(
            screen.getByText('Quiet time', { selector: 'span' }),
        ).toBeInTheDocument();
    });

    it('loads only the chosen alert’s story when another alert is picked', () => {
        render(<AlertsIndex {...fixture()} />);

        fireEvent.click(
            screen.getByRole('button', { name: /Lounge Wi-Fi is offline/ }),
        );

        expect(inertia.get).toHaveBeenCalledWith(
            '/security-devices/alerts',
            { alert: 2 },
            expect.objectContaining({
                only: ['selected'],
                preserveState: true,
                replace: true,
            }),
        );
    });

    it('hides the buttons the server wouldn’t allow', () => {
        const props = fixture();
        props.selected = {
            ...props.selected!,
            can: {
                acknowledge: false,
                assign: false,
                snooze: false,
                report_incident: false,
            },
        };
        render(
            <AlertsIndex
                {...props}
                can={{
                    acknowledge: false,
                    quiet_time: false,
                    add_rule: false,
                    report_incident: false,
                }}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Acknowledge' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Quiet time' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /Add rule/ }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Report incident' }),
        ).not.toBeInTheDocument();
    });

    it('acknowledges from the next-step box with a note and the reminder', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockResolvedValue({
                data: { message: 'Everyone can see you have it.' },
            });
        render(<AlertsIndex {...fixture()} />);

        fireEvent.click(
            within(
                screen.getByRole('region', { name: 'The alert you chose' }),
            ).getByRole('button', { name: 'Acknowledge' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Acknowledge alert',
        });
        expect(
            within(dialog).getByText('Synthetic Lodge router is offline'),
        ).toBeInTheDocument();
        fireEvent.change(within(dialog).getByLabelText('Note'), {
            target: { value: 'Called the house' },
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Acknowledge' }),
        );

        await waitFor(() =>
            expect(post).toHaveBeenCalledWith(
                '/security-devices/alerts/1/acknowledge',
                { note: 'Called the house', remind: true },
                expect.anything(),
            ),
        );
        await waitFor(() => expect(inertia.reload).toHaveBeenCalled());
    });

    it('says the result is not confirmed when the answer never arrives, and offers a check instead of a second save', async () => {
        vi.spyOn(axios, 'post').mockRejectedValue(
            Object.assign(new Error('Network Error'), {
                isAxiosError: true,
                response: undefined,
            }),
        );
        render(<AlertsIndex {...fixture()} />);

        fireEvent.click(
            within(
                screen.getByRole('region', { name: 'The alert you chose' }),
            ).getByRole('button', { name: 'Acknowledge' }),
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Acknowledge alert',
        });
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Acknowledge' }),
        );

        expect(
            await within(dialog).findByText(/Result not confirmed/),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('button', { name: 'Check result' }),
        ).toBeInTheDocument();
    });

    it('lists rules as when, where and then, with Turn off in the row menu', async () => {
        const props = fixture({
            tab: 'rules',
            queue: [],
            selected: null,
            rules: {
                rows: [
                    {
                        id: 5,
                        version: 2,
                        name: 'Synthetic rule',
                        when: 'A device stops answering',
                        sub: 'Rule · network gear',
                        icon: 'wifi-off',
                        where: 'All Sites',
                        then: 'When it fails, tell IT for that Site (app and email)',
                        on: true,
                        can_change: true,
                    },
                ],
            },
            per_week: { labels: ['19 Oct', '26 Oct'], values: [5, 4] },
        });
        render(<AlertsIndex {...props} />);

        expect(
            screen.getByText('A device stops answering'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'When it fails, tell IT for that Site (app and email)',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Not available')).toBeInTheDocument();
        // The "On" column heading, and the rule's own badge.
        expect(screen.getAllByText('On')).toHaveLength(2);
        expect(screen.getByText('Alerts per week')).toBeInTheDocument();
        expect(screen.getByText(/, down from 5\./)).toBeInTheDocument();
        // Without automations.manage there's no Add automation button.
        expect(
            screen.queryByRole('button', { name: /automation/i }),
        ).not.toBeInTheDocument();

        fireEvent.contextMenu(screen.getByText('A device stops answering'));
        fireEvent.click(
            await screen.findByRole('menuitem', { name: /Turn off/ }),
        );
        expect(
            await screen.findByRole('alertdialog', {
                name: 'Turn off this rule?',
            }),
        ).toBeInTheDocument();
    });

    it('lists the house automations beside the rules, with Edit, Test it, Turn off and Remove (S&D Phase F)', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({
            data: {
                run: {
                    id: 40,
                    test: true,
                    at: ago(0),
                    summary: 'Front door opened at 10:21 pm',
                    outcome: 'ok',
                    lines: [
                        {
                            tone: 'success',
                            text: 'You got “TEST · Front door at night” in the app',
                            at: ago(0),
                        },
                    ],
                    result: 'The action worked',
                },
            },
        });
        const base = fixture();
        const props = fixture({
            tab: 'rules',
            queue: [],
            selected: null,
            counts: { ...base.counts, rules: 1 },
            rules: { rows: [] },
            automations: [
                {
                    id: 5,
                    version: 2,
                    name: 'Front door at night',
                    when: 'Front door opens between 10:00 pm and 6:00 am',
                    sub: 'Automation · door',
                    icon: 'door-open',
                    where: 'Synthetic House',
                    then: 'Tell the staff on shift (app) · check the alarm is armed',
                    last_run: { at: ago(60 * 30), test: true, outcome: 'ok' },
                    on: true,
                    can_change: true,
                    definition: null,
                },
            ],
            can: { ...base.can, add_automation: true },
        });
        render(<AlertsIndex {...props} />);

        expect(
            screen.getByText('Front door opens between 10:00 pm and 6:00 am'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Automation · door · Front door at night'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Tell the staff on shift (app) · check the alarm is armed',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText(/\(test\)$/)).toBeInTheDocument();
        expect(
            screen.getByText(
                'When something happens, who is told and what Oblivion does',
            ),
        ).toBeInTheDocument();

        fireEvent.contextMenu(
            screen.getByText('Front door opens between 10:00 pm and 6:00 am'),
        );
        expect(
            await screen.findByRole('menuitem', { name: /Edit/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: /Turn off/ }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: /Remove/ }),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('menuitem', { name: /Test it/ }));

        const viewer = await screen.findByRole('dialog', {
            name: 'Test: Front door at night',
        });
        expect(
            await within(viewer).findByText(
                'You got “TEST · Front door at night” in the app',
            ),
        ).toBeInTheDocument();
        expect(post).toHaveBeenCalledWith(
            '/security-devices/automations/5/test',
            {},
            expect.anything(),
        );
    });

    it('opens Add automation and loads its choices only then', async () => {
        const base = fixture();
        render(
            <AlertsIndex
                {...fixture({
                    tab: 'rules',
                    queue: [],
                    selected: null,
                    rules: { rows: [] },
                    automations: [],
                    can: { ...base.can, add_automation: true },
                })}
            />,
        );

        expect(
            screen.getByText(
                'No rules or automations yet, so the standard routing tells IT for each Site.',
            ),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Add automation' }));
        expect(inertia.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['automationForm'] }),
        );
        expect(
            await screen.findByText('Loading your Sites and devices…'),
        ).toBeInTheDocument();
    });

    it('says everything is quiet when nothing is open', () => {
        render(<AlertsIndex {...fixture({ queue: [], selected: null })} />);

        expect(
            screen.getByText('No alerts. Everything is quiet.'),
        ).toBeInTheDocument();
    });

    it('shows Done as a table that opens what happened', async () => {
        const props = fixture({
            tab: 'done',
            queue: [],
            done: {
                rows: [
                    {
                        id: 2,
                        title: 'Lounge Wi-Fi is offline',
                        site: 'Synthetic House',
                        opened_at: ago(300),
                        closed_at: ago(200),
                        lasted: '1 h 40 min',
                        how: 'Cleared by itself',
                    },
                ],
                page: 1,
                pages: 1,
                total: 1,
            },
        });
        props.selected = {
            ...props.selected!,
            id: 2,
            title: 'Lounge Wi-Fi is offline',
            tone: 'success',
            state: 'done',
            label: { text: 'Resolved · {closed}', at: ago(200) },
            closed_at: ago(200),
        };
        render(<AlertsIndex {...props} />);

        expect(screen.getByText('Cleared by itself')).toBeInTheDocument();
        expect(screen.getByText('Showing 1–1 of 1')).toBeInTheDocument();
        fireEvent.click(screen.getByText('Lounge Wi-Fi is offline'));
        const viewer = await screen.findByRole('dialog', {
            name: 'Lounge Wi-Fi is offline',
        });
        expect(
            within(viewer).getByText(/Resolved · 3 h 20 min ago/),
        ).toBeInTheDocument();
        expect(within(viewer).getByText('Timeline')).toBeInTheDocument();
    });
});
