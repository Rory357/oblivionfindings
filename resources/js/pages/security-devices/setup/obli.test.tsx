import type { ObliFixesSetupData, ObliFixRow, ObliSetupData } from '@/lib/security-devices/obli-fixes';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SetupFixes } from './_fixes';
import { SetupObli } from './_obli';

vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, put: vi.fn(), post: vi.fn(), isAxiosError: actual.default.isAxiosError } };
});
const inertia = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, ...inertia } };
});

const obli = (overrides: Partial<ObliSetupData> = {}): ObliSetupData => ({
    helper: { on: true, computers_on: true, can_change: true },
    health: { on: true, reads: 'Disk, memory and Windows' },
    fixes: { on: false, version: 3, blocker: null, can_change: true },
    pictures: { days: 7, who: 'IT admin' },
    ai: { enabled: true, provider: 'Anthropic Claude', computer_use: true, limit: '$10.00 a day · $100.00 a month' },
    limits: { minutes: 15, actions: 60 },
    settings_href: '/settings/obli',
    ...overrides,
});

const row = (overrides: Partial<ObliFixRow> = {}): ObliFixRow => ({
    key: 'service:Spooler:restart',
    label: 'Restart Print Spooler',
    sub: 'Windows service “Spooler”',
    icon: 'cog',
    runs_as: 'Through the Oblivion service',
    turned_on: null,
    where: [],
    used: null,
    state: { label: 'Off', tone: 'neutral', note: null },
    can_turn_on: true,
    script: ['# Asks the person at the computer first (attended)', 'SCM> restart "Spooler"'],
    ...overrides,
});

const fixes = (rows: ObliFixRow[] = [row()]): ObliFixesSetupData => ({
    rows,
    computers: [
        { id: 41, name: 'Synthetic office PC', site: 'Synthetic House' },
        { id: 42, name: 'Synthetic laptop', site: 'Synthetic House' },
    ],
    can_manage: true,
});

describe('Setup › Computers & Obli › Obli (pg-sdr-15i)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('asks before letting Obli use computers, then saves through the fixes switch', async () => {
        vi.mocked(axios.put).mockResolvedValueOnce({ data: { enabled: true, version: 4 } });
        render(<SetupObli data={obli()} />);

        expect(screen.getByText('Show the plan first and wait for one OK')).toBeInTheDocument();
        expect(screen.getByText('Never types a password')).toBeInTheDocument();
        expect(screen.getByText('7 days, then deleted')).toBeInTheDocument();
        expect(screen.getByText('$10.00 a day · $100.00 a month')).toBeInTheDocument();
        // Rules are facts, never switches.
        expect(screen.queryByRole('switch', { name: /Never types a password/ })).toBeNull();
        expect(screen.queryByRole('switch', { name: /Stop when the person moves the mouse/ })).toBeNull();

        fireEvent.click(screen.getByRole('switch', { name: /Let Obli use the computer to fix things/ }));
        expect(axios.put).not.toHaveBeenCalled();
        const confirm = await screen.findByRole('alertdialog');
        expect(within(confirm).getByText('Let Obli use staff computers to fix things?')).toBeInTheDocument();
        fireEvent.click(within(confirm).getByRole('button', { name: 'Let Obli fix things' }));
        await waitFor(() => expect(axios.put).toHaveBeenCalledWith('/security-devices/setup/obli/fixes-switch', { enabled: true, expected_version: 3 }));
    });

    it('turns Obli for staff off through today’s setting, and locks the switches for others', async () => {
        vi.mocked(axios.put).mockResolvedValueOnce({ data: {} });
        const { unmount } = render(<SetupObli data={obli()} />);
        fireEvent.click(screen.getByRole('switch', { name: /Obli on staff computers/ }));
        await waitFor(() => expect(axios.put).toHaveBeenCalledWith('/security-devices/setup/obli/helper', { enabled: false }));
        unmount();

        render(<SetupObli data={obli({ fixes: { on: false, version: 3, blocker: null, can_change: false }, helper: { on: true, computers_on: true, can_change: false } })} />);
        expect(screen.getByRole('switch', { name: /Let Obli use the computer to fix things/ })).toBeDisabled();
        expect(screen.getByRole('switch', { name: /Obli on staff computers/ })).toBeDisabled();
        expect(screen.getByText(/Only an IT admin who manages settings and secrets can let Obli use computers/)).toBeInTheDocument();
    });

    it('says when fixes are on but can’t run, and when the AI isn’t Anthropic', () => {
        render(<SetupObli data={obli({ fixes: { on: true, version: 4, blocker: 'Needs Anthropic in Settings › Obli', can_change: true }, ai: { enabled: true, provider: 'OpenAI', computer_use: false, limit: '$10.00 a day · $100.00 a month' } })} />);
        expect(screen.getByText(/On, but it can’t run yet: Needs Anthropic/)).toBeInTheDocument();
        expect(screen.getByText('OpenAI · fixes need Anthropic')).toBeInTheDocument();
    });
});

describe('Setup › Computers & Obli › Fixes Obli can make (pg-sdr-15j) and pop-up 93', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('lists the fixes honestly, with who turned each on and where', () => {
        render(
            <SetupFixes
                data={fixes([
                    row({
                        turned_on: { by: 'Synthetic IT', at: '2026-10-26T01:00:00Z' },
                        where: [{ device_id: 41, name: 'Synthetic office PC', site: 'Synthetic House', by: 'Synthetic IT', at: '2026-10-26T01:00:00Z', blocked: null }],
                        used: { asked: 14, ran: 13 },
                        state: { label: 'On', tone: 'success', note: 'Synthetic office PC' },
                    }),
                    row({ key: 'restart:computer', label: 'Restart the computer', icon: 'power', can_turn_on: false, state: { label: 'Not available', tone: 'neutral', note: 'Restarts aren’t switched on for this server' } }),
                ])}
                query=""
            />,
        );

        expect(screen.getByText('Most real fixes need an administrator.')).toBeInTheDocument();
        expect(screen.getByText('14 · ran 13')).toBeInTheDocument();
        expect(screen.getByText('On')).toBeInTheDocument();
        expect(screen.getByText('Not available')).toBeInTheDocument();
        expect(screen.getByText('Obli never does these')).toBeInTheDocument();
        expect(screen.getByText('Type a password, or read one out')).toBeInTheDocument();
    });

    it('turns a fix on for one computer only after the computer, a reason and “I’ve read what it does”', async () => {
        vi.mocked(axios.post).mockResolvedValueOnce({ data: { message: 'Turned on.' } });
        render(<SetupFixes data={fixes()} query="" />);

        fireEvent.click(screen.getByText('Restart Print Spooler'));
        const popup = await screen.findByTestId('sd-obli-turn-on-popup');
        expect(within(popup).getByText('Turn on “Restart Print Spooler”')).toBeInTheDocument();
        expect(within(popup).getByLabelText('The exact commands')).toHaveTextContent('SCM> restart "Spooler"');
        const submit = within(popup).getByRole('button', { name: 'Turn on for 1 computer' });
        expect(submit).toBeDisabled();

        fireEvent.click(within(popup).getByRole('combobox'));
        fireEvent.click(await screen.findByText('Synthetic laptop'));
        fireEvent.change(within(popup).getByLabelText(/Why you’re turning it on/), { target: { value: 'Printers keep getting stuck here' } });
        expect(submit).toBeDisabled();
        fireEvent.click(within(popup).getByRole('checkbox', { name: /I’ve read what it does/ }));
        expect(submit).toBeEnabled();
        fireEvent.click(submit);

        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/security-devices/setup/obli/fixes/on', { fix_key: 'service:Spooler:restart', device_id: 42, reason: 'Printers keep getting stuck here', read: true }),
        );
    });
});
