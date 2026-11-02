import type { NetworkCheckPanel, NetworkCheckSession } from '@/lib/security-devices/network-checks';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import axios from 'axios';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NetworkCheckPopup } from './network-check-popup';

vi.mock('axios', async () => {
    const actual = await vi.importActual<typeof import('axios')>('axios');
    return { default: { ...actual.default, get: vi.fn(), post: vi.fn(), isAxiosError: actual.default.isAxiosError } };
});

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return { ...actual, Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => <a href={href} {...rest}>{children}</a> };
});

const panel = (overrides: Partial<NetworkCheckPanel> = {}): NetworkCheckPanel => ({
    site: { id: 4, name: 'Synthetic House' },
    ready: { ok: true, reason: null, hub: 'Head office hub' },
    session: null,
    ...overrides,
});

const session = (overrides: Partial<NetworkCheckSession> = {}): NetworkCheckSession => ({
    uuid: '9b2f6c1e-0d4a-4c3b-8e7f-1a2b3c4d5e6f',
    state: 'planning',
    open: true,
    badge: { label: 'Planning', tone: 'info' },
    site: { id: 4, name: 'Synthetic House' },
    hub: 'Head office hub',
    hub_at_site: false,
    asked_by: 'Test Person',
    mine: true,
    asked_at: '2026-10-28T06:41:00Z',
    title: 'Video calls dropping in Room 5',
    question: 'Video calls keep dropping in Room 5 in the evenings. Can you check?',
    intro: null,
    plan: [],
    planned_at: null,
    answer: null,
    answered_at: null,
    unchecked_numbers: false,
    failure: null,
    steps: [],
    focus: null,
    unifi_href: null,
    ticket: null,
    can_ticket: false,
    note: 'Read-only: nothing on the network was changed · kept on the Site’s logbook for a year, and on the IT job if one is raised',
    ...overrides,
});

const answered = session({
    state: 'answered',
    open: false,
    badge: { label: 'Answered', tone: 'success' },
    intro: 'I’ll check through Head office hub, which watches Synthetic House. These checks only look; I won’t change anything.',
    plan: ['Check the switch ports for both Wi-Fi points', 'Look at Wi-Fi signal and drop-outs on Lounge Wi-Fi'],
    planned_at: '2026-10-28T06:41:20Z',
    answer: '**It isn’t the internet.** Phones near Room 5 stay on Lounge Wi-Fi at a weak signal.',
    answered_at: '2026-10-28T06:43:00Z',
    steps: [
        { key: 's1', title: 'Switch ports on Synthetic switch', state: 'done', finding: '8 of 8 ports up · no errors', at: '2026-10-28T06:42:00Z' },
        { key: 's2', title: 'Speed test from the gateway', state: 'not_available', finding: 'Not available: the gateway hasn’t run its own speed test', at: '2026-10-28T06:42:10Z' },
    ],
    focus: { id: 21, name: 'Lounge Wi-Fi', href: '/security-devices/devices/21' },
    unifi_href: 'https://unifi.ui.com',
    can_ticket: true,
});

describe('Obli checks a Site’s network (pop-up 97)', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('asks, follows the hub’s checks and shows the answer, with what was checked as Oblivion found it', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({ data: panel() }).mockResolvedValueOnce({ data: { session: answered } });
        vi.mocked(axios.post).mockResolvedValueOnce({ data: { session: session() } });
        render(<NetworkCheckPopup open siteId={4} siteName="Synthetic House" onClose={() => undefined} />);

        const popup = await screen.findByTestId('sd-network-check-popup');
        expect(within(popup).getByText('Obli checks Synthetic House’s network')).toBeInTheDocument();
        expect(within(popup).getByText(/it can’t change router, switch or Wi-Fi settings/)).toBeInTheDocument();
        const ask = within(popup).getByRole('button', { name: 'Ask Obli' });
        expect(ask).toBeDisabled();

        fireEvent.change(within(popup).getByLabelText(/What’s wrong\?/), { target: { value: 'Video calls keep dropping in Room 5 in the evenings. Can you check?' } });
        fireEvent.click(ask);

        await waitFor(() =>
            expect(axios.post).toHaveBeenCalledWith('/security-devices/sites/4/network-checks', {
                question: 'Video calls keep dropping in Room 5 in the evenings. Can you check?',
                device_id: null,
            }),
        );
        expect(await within(popup).findByText('Obli is choosing which checks to run…')).toBeInTheDocument();

        expect(await within(popup).findByText('It isn’t the internet.', {}, { timeout: 3500 })).toBeInTheDocument();
        expect(axios.get).toHaveBeenLastCalledWith('/security-devices/network-checks/9b2f6c1e-0d4a-4c3b-8e7f-1a2b3c4d5e6f');
        const checked = within(popup).getByRole('list', { name: 'What Obli checked' });
        expect(within(checked).getByText('8 of 8 ports up · no errors')).toBeInTheDocument();
        expect(within(checked).getByText('Not available: the gateway hasn’t run its own speed test')).toBeInTheDocument();
        expect(within(popup).getByText('Through Head office hub, which watches Synthetic House')).toBeInTheDocument();
        expect(within(popup).getByRole('link', { name: 'Open in UniFi' })).toHaveAttribute('rel', 'noopener noreferrer');
        expect(within(popup).getByRole('link', { name: 'Open Lounge Wi-Fi' })).toHaveAttribute('href', '/security-devices/devices/21');
        expect(within(popup).getAllByRole('button', { name: 'Raise IT job' })).toHaveLength(2);
        expect(within(popup).getByText(/Read-only: nothing on the network was changed/)).toBeInTheDocument();
    });

    it('says honestly when Obli can’t check, and offers no way to ask', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({ data: panel({ ready: { ok: false, reason: 'Not available: Obli’s AI is turned off in Settings › Obli.', hub: 'Head office hub' } }) });
        render(<NetworkCheckPopup open siteId={4} siteName="Synthetic House" onClose={() => undefined} />);

        const popup = await screen.findByTestId('sd-network-check-popup');
        expect(await within(popup).findByText('Not available: Obli’s AI is turned off in Settings › Obli.')).toBeInTheDocument();
        expect(within(popup).queryByRole('button', { name: 'Ask Obli' })).not.toBeInTheDocument();
        expect(within(popup).queryByLabelText(/What’s wrong\?/)).not.toBeInTheDocument();
    });

    it('raises an IT job with the session on it, then links to it', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({ data: panel({ session: answered }) });
        vi.mocked(axios.post).mockResolvedValueOnce({ data: { ticket: { id: 88, reference: 'IT-0088', href: '/it/tickets/88' } } });
        render(<NetworkCheckPopup open siteId={4} siteName="Synthetic House" sessionId={answered.uuid} onClose={() => undefined} />);

        const popup = await screen.findByTestId('sd-network-check-popup');
        await within(popup).findByText('It isn’t the internet.');
        expect(axios.get).toHaveBeenCalledWith('/security-devices/sites/4/network-checks', { params: { session: answered.uuid } });
        fireEvent.click(within(popup).getAllByRole('button', { name: 'Raise IT job' })[0]);

        expect(await within(popup).findByRole('link', { name: 'Open IT job IT-0088' })).toHaveAttribute('href', '/it/tickets/88');
        expect(axios.post).toHaveBeenCalledWith('/security-devices/network-checks/9b2f6c1e-0d4a-4c3b-8e7f-1a2b3c4d5e6f/ticket');
        expect(within(popup).queryByRole('button', { name: 'Raise IT job' })).not.toBeInTheDocument();
    });

    it('starts from the Wi-Fi point it was opened on', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({ data: panel() });
        vi.mocked(axios.post).mockResolvedValueOnce({ data: { session: session() } });
        render(<NetworkCheckPopup open siteId={4} siteName="Synthetic House" device={{ id: 21, name: 'Lounge Wi-Fi' }} onClose={() => undefined} />);

        const popup = await screen.findByTestId('sd-network-check-popup');
        expect(await within(popup).findByText('Obli starts with this Wi-Fi point.')).toBeInTheDocument();
        fireEvent.change(within(popup).getByLabelText(/What’s wrong\?/), { target: { value: 'Phones keep losing Wi-Fi near the lounge.' } });
        fireEvent.click(within(popup).getByRole('button', { name: 'Ask Obli' }));

        await waitFor(() => expect(axios.post).toHaveBeenCalledWith('/security-devices/sites/4/network-checks', { question: 'Phones keep losing Wi-Fi near the lounge.', device_id: 21 }));
    });

    it('shows a check that couldn’t be finished, and keeps what was checked', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: panel({
                session: session({
                    state: 'failed',
                    open: false,
                    failure: 'Obli ran the checks but couldn’t write up what it found. What it checked is below.',
                    steps: [{ key: 's1', title: 'Delay from Head office hub to Synthetic router', state: 'done', finding: '14 ms typical (9–31 ms) · none lost', at: '2026-10-28T06:42:00Z' }],
                    can_ticket: true,
                }),
            }),
        });
        render(<NetworkCheckPopup open siteId={4} siteName="Synthetic House" onClose={() => undefined} />);

        const popup = await screen.findByTestId('sd-network-check-popup');
        expect(await within(popup).findByText('Obli ran the checks but couldn’t write up what it found. What it checked is below.')).toBeInTheDocument();
        expect(within(popup).getByText('14 ms typical (9–31 ms) · none lost')).toBeInTheDocument();
        expect(within(popup).getByRole('button', { name: 'Raise IT job' })).toBeInTheDocument();
    });
});
