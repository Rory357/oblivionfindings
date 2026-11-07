import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CareAlert, { type CareAlertProps } from './care-alert';

const reload = vi.fn();

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, reload: (...args: unknown[]) => reload(...args), get: vi.fn(), visit: vi.fn(), on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

function alert(overrides: Partial<CareAlertProps['alert']> = {}): CareAlertProps['alert'] {
    return {
        id: 41,
        title: 'Synthetic lounge button: panic button pressed',
        tone: 'critical',
        what: 'Panic button pressed',
        detail: 'Pressed twice in the lounge',
        site: 'Synthetic House',
        device: 'Synthetic lounge button',
        opened_at: minutesAgo(1),
        active: true,
        attending: null,
        escalation: { state: 'will', minutes: 2 },
        timeline: [{ id: 1, at: minutesAgo(1), title: 'Panic button pressed', tone: 'critical' }],
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    reload.mockReset();
});

describe('care alert (one alert, opened from a push)', () => {
    it('shows what happened, the house and when the on-call manager is told', () => {
        render(<CareAlert alert={alert()} />);

        expect(screen.getByRole('heading', { name: 'Synthetic lounge button: panic button pressed' })).toBeInTheDocument();
        expect(screen.getByText('Needs someone')).toBeInTheDocument();
        expect(screen.getAllByText('Synthetic House').length).toBeGreaterThan(0);
        expect(screen.getByText(/within 2 min of it starting, the on-call manager or Control Room is told/)).toBeInTheDocument();
        expect(screen.getByText('What’s happened')).toBeInTheDocument();
    });

    it('offers no snooze, no assign and no link to other alerts', () => {
        render(<CareAlert alert={alert()} />);

        expect(screen.queryByRole('button', { name: /snooze/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /assign/i })).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: /all alerts|alerts page/i })).not.toBeInTheDocument();
    });

    it('says I’m attending with one tap, then reloads the alert', async () => {
        const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { message: 'Everyone told knows you’re on it.' } });
        render(<CareAlert alert={alert()} />);

        fireEvent.click(screen.getByRole('button', { name: 'I’m attending' }));

        await waitFor(() => expect(post).toHaveBeenCalledWith('/security-devices/care-alerts/41/attend', {}, { headers: { Accept: 'application/json' } }));
        await waitFor(() => expect(reload).toHaveBeenCalledWith({ only: ['alert'] }));
    });

    it('explains a refusal in plain words when the alert is already done', async () => {
        vi.spyOn(axios, 'post').mockRejectedValue(Object.assign(new Error('Conflict'), { isAxiosError: true, response: { status: 409, data: {} } }));
        vi.spyOn(axios, 'isAxiosError').mockReturnValue(true);
        render(<CareAlert alert={alert()} />);

        fireEvent.click(screen.getByRole('button', { name: 'I’m attending' }));

        expect(await screen.findByText('This alert is already done.')).toBeInTheDocument();
    });

    it('shows who is attending and hides the button', () => {
        render(<CareAlert alert={alert({ attending: { name: 'Synthetic Worker', at: minutesAgo(1), you: false } })} />);

        expect(screen.queryByRole('button', { name: 'I’m attending' })).not.toBeInTheDocument();
        expect(screen.getByText('Being attended to')).toBeInTheDocument();
        expect(screen.getByText(/Synthetic Worker is attending/)).toBeInTheDocument();
    });

    it('says when the alert is done', () => {
        render(<CareAlert alert={alert({ active: false })} />);

        expect(screen.queryByRole('button', { name: 'I’m attending' })).not.toBeInTheDocument();
        expect(screen.getByText('Done')).toBeInTheDocument();
        expect(screen.getByText('This alert is done. Nothing more is needed from you.')).toBeInTheDocument();
    });

    it('says the on-call manager has been told once it escalated', () => {
        render(<CareAlert alert={alert({ escalation: { state: 'told', minutes: null } })} />);

        expect(screen.getByText(/the on-call manager or Control Room has been told/)).toBeInTheDocument();
    });
});
