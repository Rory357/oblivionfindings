import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MeetingInvitationPage, {
    type MeetingInvitation,
} from './meeting-invitation';

const current = vi.hoisted(() => ({
    actor: 7,
    hazards: false,
    url: '/my-day/safety/meetings/31',
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: current.url,
        props: {
            auth: {
                user: { id: current.actor },
                can: { hazards: { view: current.hazards } },
            },
        },
    }),
    Link: ({
        preserveState: _state,
        preserveScroll: _scroll,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & {
        preserveState?: boolean;
        preserveScroll?: boolean;
    }) => <a {...props} />,
    Head: () => null,
    router: { get: vi.fn() },
}));
// Keep the actual page, shared header/rail/meters/cards and access lifecycle.
// Only unrelated navigation and the HTTP transport are replaced.
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <main>{children}</main>,
}));
const transport = vi.fn();
beforeEach(() => {
    current.actor = 7;
    current.hazards = false;
    current.url = '/my-day/safety/meetings/31';
    transport.mockReset();
    vi.stubGlobal('fetch', transport);
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

function invitation(): MeetingInvitation {
    return {
        meeting: {
            id: 31,
            scheduled_at: '2026-10-14T23:30:00Z',
            location: 'Private named meeting room',
            status: 'scheduled',
            committee: { id: 8, name: 'Private named committee' },
            site: { id: 4, name: 'North House' },
            site_ownership: 'recorded',
            attendance: { response: 'pending', attended: false },
        },
        context: { can_open_register: false, register_href: null },
        source_viewer_id: 7,
    };
}
function expectWithheld() {
    expect(
        screen.queryByText('Private named meeting room', { exact: true }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByText('Private named committee', { exact: true }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByText(/Private named committee ·/),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Open H&S meeting' }),
    ).not.toBeInTheDocument();
}
function refresh() {
    fireEvent.click(screen.getByRole('button', { name: 'Refresh invitation' }));
}
async function loaded(record = invitation()) {
    transport.mockResolvedValue({ ok: true, json: async () => record });
    const view = render(<MeetingInvitationPage {...record} />);
    // Cached server props are never sufficient for visible private content.
    expectWithheld();
    await waitFor(() =>
        expect(screen.getByRole('status')).toHaveTextContent(
            'Invitation details available.',
        ),
    );
    transport.mockClear();
    return view;
}

describe('Current named meeting invitation page', () => {
    it('shows the minimal worker view after native admission with shared header, NZ time and My day return', async () => {
        await loaded();
        expect(
            screen.getByRole('heading', { name: 'Meeting invitation' }),
        ).toBeInTheDocument();
        expect(
            screen.getAllByText('Private named meeting room', { exact: true })
                .length,
        ).toBeGreaterThan(0);
        expect(screen.getAllByText(/15 October 2026/).length).toBeGreaterThan(
            0,
        );
        expect(
            screen.getAllByText(/12:30 pm · Pacific\/Auckland/).length,
        ).toBeGreaterThan(0);
        expect(
            screen.getByRole('link', { name: 'Back to My day' }),
        ).toHaveAttribute('href', '/my-day');
        expect(
            screen.queryByRole('link', { name: 'Open H&S meeting' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: /accept|confirm attendance|acknowledge/i,
            }),
        ).not.toBeInTheDocument();
    });
    it('requires independent server and current shared register permission for the full-register link', async () => {
        const record = invitation();
        record.context = {
            can_open_register: true,
            register_href:
                '/health-safety/worker-participation?tab=meetings&meeting=31',
        };
        current.hazards = true;
        const view = await loaded(record);
        expect(
            screen.getByRole('link', { name: 'Open H&S meeting' }),
        ).toHaveAttribute('href', record.context.register_href);
        current.hazards = false;
        view.rerender(<MeetingInvitationPage {...record} />);
        expect(
            screen.queryByRole('link', { name: 'Open H&S meeting' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByText('Private named meeting room', { exact: true })
                .length,
        ).toBeGreaterThan(0);
    });
    it.each([401, 403, 404])(
        'clears retained details on native refresh %s without reading an error body',
        async (status) => {
            await loaded();
            const body = vi.fn();
            transport.mockResolvedValue({ ok: false, status, json: body });
            refresh();
            expectWithheld();
            await screen.findByText('This invitation is unavailable');
            expectWithheld();
            expect(body).not.toHaveBeenCalled();
            expect(transport).toHaveBeenCalledWith(
                '/my-day/safety/meetings/31',
                expect.objectContaining({
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { Accept: 'application/json' },
                }),
            );
        },
    );
    it('does not restore a pending old-account response after the current account changes', async () => {
        const record = invitation();
        const view = await loaded(record);
        let release!: (response: unknown) => void;
        transport.mockReturnValue(
            new Promise((resolve) => {
                release = resolve;
            }),
        );
        refresh();
        current.actor = 8;
        view.rerender(<MeetingInvitationPage {...record} />);
        expectWithheld();
        await act(async () => {
            release({ ok: true, json: async () => record });
        });
        expectWithheld();
        expect(
            screen.getByText('This invitation is unavailable'),
        ).toBeInTheDocument();
    });
    it('does not restore an earlier meeting after the current route changes', async () => {
        const record = invitation();
        const view = await loaded(record);
        let release!: (response: unknown) => void;
        transport.mockReturnValue(
            new Promise((resolve) => {
                release = resolve;
            }),
        );
        refresh();
        current.url = '/my-day/safety/meetings/32';
        view.rerender(<MeetingInvitationPage {...record} />);
        expectWithheld();
        await act(async () => {
            release({ ok: true, json: async () => record });
        });
        expectWithheld();
        expect(screen.getByRole('status')).toHaveTextContent(
            'could not be confirmed',
        );
    });
    it('clears retained details when a refresh fails to reach the server', async () => {
        await loaded();
        transport.mockRejectedValue(new TypeError('Network unavailable'));
        refresh();
        expect(screen.getByRole('status')).toHaveTextContent(
            'hidden while access is checked',
        );
        expectWithheld();
        await screen.findByText('This invitation is unavailable');
        expectWithheld();
    });
    it('allows retry after a stalled refresh without restoring its late reply', async () => {
        await loaded();
        vi.useFakeTimers();
        let release!: (response: unknown) => void;
        transport.mockReturnValueOnce(
            new Promise((resolve) => {
                release = resolve;
            }),
        );
        refresh();
        const firstSignal = transport.mock.calls[0][1].signal as AbortSignal;
        act(() => {
            vi.advanceTimersByTime(15_000);
        });
        expectWithheld();
        expect(firstSignal.aborted).toBe(true);
        expect(
            screen.getByRole('button', { name: 'Refresh invitation' }),
        ).not.toBeDisabled();
        const next = invitation();
        next.meeting.location = 'Current retry room';
        transport.mockResolvedValueOnce({ ok: true, json: async () => next });
        await act(async () => {
            refresh();
        });
        expect(
            screen.getAllByText('Current retry room', { exact: true }).length,
        ).toBeGreaterThan(0);
        await act(async () => {
            release({ ok: true, json: async () => invitation() });
        });
        expect(
            screen.queryByText('Private named meeting room', { exact: true }),
        ).not.toBeInTheDocument();
        expect(
            screen.getAllByText('Current retry room', { exact: true }).length,
        ).toBeGreaterThan(0);
    });
    it.each(['actor', 'meeting', 'link'])(
        'withholds a malformed or mismatched native %s reply',
        async (fault) => {
            await loaded();
            const next = invitation();
            if (fault === 'actor') next.source_viewer_id = 8;
            if (fault === 'meeting') next.meeting.id = 32;
            if (fault === 'link')
                next.context = {
                    can_open_register: true,
                    register_href: 'https://unrelated.invalid/private',
                };
            transport.mockResolvedValue({ ok: true, json: async () => next });
            refresh();
            await screen.findByText('This invitation is unavailable');
            expectWithheld();
        },
    );
    it('shows a fresh response only after focus rechecks current access', async () => {
        await loaded();
        const next = invitation();
        next.meeting.location = 'Fresh admitted room';
        transport.mockResolvedValue({ ok: true, json: async () => next });
        fireEvent.focus(window);
        expectWithheld();
        await screen.findAllByText('Fresh admitted room', { exact: true });
        expect(
            screen.queryByText('Private named meeting room', { exact: true }),
        ).not.toBeInTheDocument();
    });
    it.each(
        [
            'legacy_unknown',
            'constructor',
            'toString',
            '__proto__',
            'hasOwnProperty',
            '',
        ].flatMap((value) => [
            { field: 'status', value },
            { field: 'response', value },
        ]),
    )(
        'shows an unconfirmed label for unknown $field "$value" without changing the other label or confirming attendance',
        async ({ field, value }) => {
            const record = invitation();
            if (field === 'status') record.meeting.status = value;
            else record.meeting.attendance.response = value;
            await loaded(record);
            // Status shows once (the header chip) and the response once (Your
            // invitation); the known sibling field must keep its own label
            // after current native admission.
            expect(
                screen.getAllByText(
                    field === 'status' ? 'Status not confirmed' : 'Scheduled',
                    { exact: true },
                ),
            ).toHaveLength(1);
            expect(
                screen.getAllByText(
                    field === 'response'
                        ? 'Response not confirmed'
                        : 'Awaiting response',
                    { exact: true },
                ),
            ).toHaveLength(1);
            expect(
                screen.queryByText('Accepted', { exact: true }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('Completed', { exact: true }),
            ).not.toBeInTheDocument();
            expect(
                screen.getAllByText('Not recorded', { exact: true }).length,
            ).toBeGreaterThan(0);
        },
    );
    it('retains missing ownership as unconfirmed for an admitted wide reader', async () => {
        const record = invitation();
        record.meeting.site = null;
        record.meeting.site_ownership = 'unconfirmed';
        await loaded(record);
        expect(
            screen.getAllByText('Site ownership not confirmed').length,
        ).toBeGreaterThan(0);
        expect(screen.getByRole('note')).toHaveTextContent(
            'does not confirm organisation-wide ownership',
        );
    });
    it('rechecks fresh source props under the same account rather than restoring a retained body', async () => {
        const view = await loaded();
        const next = invitation();
        next.meeting.location = 'Fresh server room';
        transport.mockResolvedValue({ ok: true, json: async () => next });
        view.rerender(<MeetingInvitationPage {...next} />);
        expectWithheld();
        await screen.findAllByText('Fresh server room', { exact: true });
        expect(
            screen.queryByText('Private named meeting room', { exact: true }),
        ).not.toBeInTheDocument();
    });
    it('does not show cached matching server props when native mount admission is denied', async () => {
        transport.mockResolvedValue({ ok: false, status: 403, json: vi.fn() });
        render(<MeetingInvitationPage {...invitation()} />);
        expectWithheld();
        await screen.findByText('This invitation is unavailable');
        expectWithheld();
    });
    it('rechecks different invalid prop frames after a valid native admission', async () => {
        const first = invitation();
        first.source_viewer_id = 8;
        transport.mockResolvedValueOnce({
            ok: true,
            json: async () => invitation(),
        });
        const view = render(<MeetingInvitationPage {...first} />);
        expectWithheld();
        await screen.findAllByText('Private named meeting room', {
            exact: true,
        });
        transport.mockClear();
        const second = invitation();
        second.source_viewer_id = 9;
        const body = vi.fn();
        transport.mockResolvedValueOnce({ ok: false, status: 403, json: body });
        view.rerender(<MeetingInvitationPage {...second} />);
        expectWithheld();
        await screen.findByText('This invitation is unavailable');
        expectWithheld();
        expect(transport).toHaveBeenCalledTimes(1);
        expect(body).not.toHaveBeenCalled();
    });
    it('clears a retained page on browser pageshow before native admission can restore it', async () => {
        await loaded();
        transport.mockResolvedValue({ ok: false, status: 403, json: vi.fn() });
        fireEvent(window, new Event('pageshow'));
        expectWithheld();
        await screen.findByText('This invitation is unavailable');
        expectWithheld();
    });
    it('conceals the page before browser retention and rejects a pending old reply', async () => {
        await loaded();
        let release!: (response: unknown) => void;
        transport.mockReturnValueOnce(
            new Promise((resolve) => {
                release = resolve;
            }),
        );
        refresh();
        const signal = transport.mock.calls[0][1].signal as AbortSignal;
        fireEvent(window, new Event('pagehide'));
        expect(signal.aborted).toBe(true);
        expectWithheld();
        await act(async () => {
            release({ ok: true, json: async () => invitation() });
        });
        expectWithheld();
        transport.mockResolvedValueOnce({
            ok: false,
            status: 403,
            json: vi.fn(),
        });
        fireEvent(window, new Event('pageshow'));
        await screen.findByText('This invitation is unavailable');
        expectWithheld();
    });
    it('conceals details in a hidden tab until returning triggers fresh admission', async () => {
        await loaded();
        const visibility = vi.spyOn(document, 'visibilityState', 'get');
        visibility.mockReturnValue('hidden');
        fireEvent(document, new Event('visibilitychange'));
        expectWithheld();
        expect(transport).not.toHaveBeenCalled();
        fireEvent.focus(window);
        expect(transport).not.toHaveBeenCalled();
        const next = invitation();
        next.meeting.location = 'Admitted after return';
        transport.mockResolvedValueOnce({ ok: true, json: async () => next });
        visibility.mockReturnValue('visible');
        fireEvent(document, new Event('visibilitychange'));
        expectWithheld();
        await screen.findAllByText('Admitted after return', { exact: true });
    });
});
