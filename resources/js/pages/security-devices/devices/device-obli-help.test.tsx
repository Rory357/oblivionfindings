import type { ObliFixRequestPanel, ObliHelpTabData, ObliSessionRecord } from '@/lib/security-devices/obli-fixes';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ObliHelpTab } from './_device-obli-help';

vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, get: vi.fn(), post: vi.fn(), isAxiosError: actual.default.isAxiosError } };
});
const inertia = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, router: { ...actual.router, ...inertia } };
});

const now = new Date('2026-10-29T20:20:00Z');
const at = (minutes: number) => new Date(now.getTime() - minutes * 60_000).toISOString();

function record(overrides: Partial<ObliSessionRecord> = {}): ObliSessionRecord {
    return {
        uuid: '6f1c2c55-9a43-4a0b-9f0e-1f2a3b4c5d6e',
        title: 'Printer won’t print',
        who: 'Synthetic Staff',
        at: at(8),
        device: { id: 41, name: 'Synthetic office PC' },
        badge: { label: 'Fixed', tone: 'success' },
        sub: 'Synthetic Staff · fixed in 2 min',
        problem: 'The printer won’t print. I’ve sent my letter three times.',
        kept: true,
        found: [{ tone: 'warning', text: '3 jobs stuck in the queue' }],
        plan: [
            { text: 'Cancel your 3 stuck jobs', note: null },
            { text: 'Restart Windows’ printing service', note: 'IT has allowed this fix' },
        ],
        refusal: null,
        planned_at: at(8),
        approved_at: at(7),
        outcome: { text: 'Fixed. Your letter printed.', at: at(6) },
        steps: [
            { key: 1, at: at(7), text: 'Cancelling stuck jobs', note: null, tone: 'success', picture: true },
            { key: 2, at: at(7), text: 'Didn’t do something it isn’t allowed to', note: 'Not done: that key or shortcut isn’t allowed', tone: 'warning', picture: false },
        ],
        pictures: { count: 5, deleted_on: '2026-11-05T20:13:00Z', can_open: true },
        views: [],
        ticket: null,
        note: 'I keep pictures of your screen for 7 days so IT can check my work.',
        ...overrides,
    };
}

function data(overrides: Partial<ObliHelpTabData> = {}): ObliHelpTabData {
    const latest = record();
    return {
        sessions: [
            { uuid: latest.uuid, title: latest.title, who: latest.who, at: latest.at, badge: latest.badge, ring: 'success', sub: latest.sub },
            { uuid: '7a1c2c55-9a43-4a0b-9f0e-1f2a3b4c5d6f', title: 'Outlook keeps asking for my password', who: 'Other Staff', at: at(3000), badge: { label: 'Needs IT', tone: 'warning' }, ring: 'warning', sub: 'Other Staff · needs IT · ticket IT-2041' },
        ],
        latest,
        may: [
            ['Use the computer', 'After showing its plan and one OK'],
            ['Fixes through the Oblivion service', '1 of 4 turned on (Setup)'],
        ],
        requests: [],
        can: { open_pictures: true, decide: true, manage_fixes: true },
        ...overrides,
    };
}

describe('Computer › Obli help (pg-sdr-9d) and pop-ups 94, 95, 96', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
        window.history.replaceState({}, '', '/');
    });

    it('shows the newest session’s conversation and steps, and every session on this PC', () => {
        render(<ObliHelpTab data={data()} now={now} />);

        expect(screen.getByText('The printer won’t print. I’ve sent my letter three times.')).toBeInTheDocument();
        expect(screen.getByText('Here’s what I’ll do:')).toBeInTheDocument();
        expect(screen.getByText(/IT has allowed this fix/)).toBeInTheDocument();
        expect(screen.getByText('Fixed. Your letter printed.')).toBeInTheDocument();
        expect(screen.getByText('Cancelling stuck jobs')).toBeInTheDocument();
        expect(screen.getByText(/5 screen pictures · kept until/)).toHaveTextContent('only IT admins can open them, and each view is logged');
        expect(screen.getByText('Help on this PC')).toBeInTheDocument();
        expect(screen.getByText('Outlook keeps asking for my password')).toBeInTheDocument();
        expect(screen.getByText('What Obli may do here')).toBeInTheDocument();
        // No picture is ever on the tab itself.
        expect(document.querySelector('img')).toBeNull();
    });

    it('opens a session record, and its pictures only after a reason (pop-ups 96 and 94)', async () => {
        vi.mocked(axios.post).mockResolvedValueOnce({
            data: { view_id: 9, expires_at: at(-30), pictures: [{ id: 3, turn: 2, width: 1280, height: 720, taken_at: at(7), url: '/security-devices/obli/pictures/3?view=9' }] },
        });
        render(<ObliHelpTab data={data()} now={now} />);

        fireEvent.click(screen.getByRole('button', { name: 'Open the record: Printer won’t print' }));
        const viewer = await screen.findByTestId('sd-obli-session-popup');
        expect(within(viewer).getByText('Obli help · Printer won’t print')).toBeInTheDocument();
        expect(within(viewer).queryByRole('img')).toBeNull();

        fireEvent.click(within(viewer).getByRole('button', { name: /Open screen pictures/ }));
        const ask = await screen.findByTestId('sd-obli-pictures-popup');
        expect(within(ask).getByText('Open Obli’s screen pictures?')).toBeInTheDocument();
        expect(within(ask).getByText('They may show client details.')).toBeInTheDocument();
        const open = within(ask).getByRole('button', { name: 'Open pictures' });
        expect(open).toBeDisabled();
        fireEvent.change(within(ask).getByLabelText(/Why do you need to see them/), { target: { value: 'Checking a fix that didn’t work' } });
        fireEvent.click(open);

        await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/security-devices/obli/sessions/6f1c2c55-9a43-4a0b-9f0e-1f2a3b4c5d6e/pictures', { reason: 'Checking a fix that didn’t work' }));
        const shown = await screen.findByRole('img', { name: /Obli’s screen picture, step 2/ });
        expect(shown).toHaveAttribute('src', '/security-devices/obli/pictures/3?view=9');
    });

    it('hides “Open screen pictures” from people without the permission', async () => {
        render(<ObliHelpTab data={data({ latest: record({ pictures: { count: 5, deleted_on: at(-60), can_open: false } }) })} now={now} />);
        fireEvent.click(screen.getByRole('button', { name: 'Open the record: Printer won’t print' }));
        const viewer = await screen.findByTestId('sd-obli-session-popup');
        expect(within(viewer).queryByRole('button', { name: /Open screen pictures/ })).toBeNull();
    });

    it('reviews a one-off request and approves it once through the governed form (pop-up 95)', async () => {
        const panel: ObliFixRequestPanel = {
            id: 12,
            device: { id: 41, name: 'Synthetic office PC' },
            asker: 'Synthetic Staff',
            asked_at: at(4),
            problem: 'Scanning to email stopped working.',
            problem_at: at(5),
            what: 'Restart Print Spooler (cancelling stuck jobs)',
            kind: 'fix',
            fix: { key: 'service:Spooler:restart', label: 'Restart Print Spooler' },
            commands: ['SCM> restart "Spooler"'],
            host: 'What will run · through the Oblivion service, from IT’s governed form',
            state: 'requested',
            route: null,
            decided: null,
            result: null,
            governed_href: '/security-devices/devices/41?section=management&action=endpoint.service.manage&command_service_name=Spooler',
            session: '6f1c2c55-9a43-4a0b-9f0e-1f2a3b4c5d6e',
            can_decide: true,
            live: true,
        };
        vi.mocked(axios.get).mockResolvedValueOnce({ data: panel });
        vi.mocked(axios.post).mockResolvedValueOnce({ data: { state: 'approved', route: 'governed', governed_href: panel.governed_href } });
        render(<ObliHelpTab data={data({ requests: [{ id: 12, asked_at: at(4), who: 'Synthetic Staff' }] })} now={now} />);

        fireEvent.click(screen.getByRole('button', { name: /Review Synthetic Staff’s request/ }));
        const popup = await screen.findByTestId('sd-obli-request-popup');
        expect(await within(popup).findByText('Scanning to email stopped working.')).toBeInTheDocument();
        expect(within(popup).getByLabelText('The exact commands')).toHaveTextContent('SCM> restart "Spooler"');
        expect(within(popup).queryByRole('button', { name: /Add to the fix list/ })).toBeNull();

        fireEvent.click(within(popup).getByRole('button', { name: /Approve once/ }));
        await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/security-devices/obli/requests/12/decide', { decision: 'approve' }));
        expect(await within(popup).findByText(/Run it from the computer’s fix form/)).toBeInTheDocument();
        fireEvent.click(within(popup).getByRole('button', { name: 'Open the fix form' }));
        expect(inertia.visit).toHaveBeenCalledWith(panel.governed_href);
    });

    it('says nothing has happened yet when nobody asked Obli for help', () => {
        render(<ObliHelpTab data={data({ sessions: [], latest: null })} now={now} />);
        expect(screen.getByText('Nobody has asked Obli for help on this PC in the last 30 days.')).toBeInTheDocument();
    });
});
