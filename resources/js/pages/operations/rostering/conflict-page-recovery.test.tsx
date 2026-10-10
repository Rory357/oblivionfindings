import type { ConflictsProps } from '@/components/rostering/conflict-queue';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RosteringConflicts from './conflicts';
const mock = vi.hoisted(() => ({
    visit: vi.fn(),
    reload: vi.fn(),
    get: vi.fn(),
    fetch: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: mock,
    Head: () => null,
    usePage: () => ({
        props: {
            auth: { user: { id: 7 }, can: { shifts: { manageAny: false } } },
        },
    }),
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => children,
}));
vi.mock('@/components/workforce/workforce-page-header', () => ({
    WorkforcePageHeader: ({ title }: { title: string }) => <h1>{title}</h1>,
}));
vi.mock('@/components/page', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/components/page')>()),
    PageLayout: ({
        hero,
        children,
    }: {
        hero: ReactNode;
        children: ReactNode;
    }) => (
        <>
            {hero}
            {children}
        </>
    ),
}));
vi.mock(
    '@/pages/operations/shifts/components/use-create-shift-launcher',
    () => ({
        useCreateShiftLauncher: () => ({
            dialog: null,
            openWith: vi.fn(),
            loading: false,
            error: null,
        }),
    }),
);
const props: ConflictsProps = {
    weekStart: '2026-10-19',
    weekEnd: '2026-10-26',
    workerTimezone: 'Pacific/Auckland',
    staffOverlaps: [],
    clientOverlaps: [],
    timeOffConflicts: [],
    tightTurnarounds: [],
    openShifts: [],
    activeReplacements: [],
    recurringCoverageAlignment: { rule_drift: [], orphan_series: [] },
    coverageGaps: [
        {
            finding_id: 'coverage:11:window',
            site_id: 3,
            site_name: 'Matai House',
            rule_id: 11,
            rule_name: 'Morning cover',
            window_label: 'Morning',
            starts_at: '2026-10-19T09:00:00+13:00',
            ends_at: '2026-10-19T11:00:00+13:00',
            source_assessment: 'assessed',
            required_staff: 2,
            assigned_staff: 1,
            missing_staff: 1,
            coverage_window_key: 'original-key',
            action_window: {
                site_id: 3,
                coverage_requirement_id: 11,
                window_starts_at: '2026-10-19T09:00:00+13:00',
                window_ends_at: '2026-10-19T11:00:00+13:00',
            },
            acknowledgement: { id: 91, state: 'acked', since: null },
            can: { acknowledge: true, dismiss: true, clear: true },
            urls: {
                roster: '/operations/rostering?week=2026-10-19',
                ack: '/coverage/original-key/ack',
                dismiss: '/coverage/original-key/dismiss',
                clear: '/coverage/original-key/clear',
            },
        },
    ],
};
function response(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    } as Response;
}
function choose() {
    fireEvent.click(
        screen.getByRole('button', { name: /Matai House.*Morning cover/ }),
    );
}
function beginDismiss() {
    render(<RosteringConflicts {...props} />);
    choose();
    fireEvent.click(screen.getByRole('button', { name: 'Dismiss review' }));
    fireEvent.change(screen.getByLabelText('Reason (required)'), {
        target: { value: 'My unsaved review reason' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Dismiss coverage review' }),
    );
    const [url, options] = mock.fetch.mock.calls[0] as [string, RequestInit];
    return {
        url,
        options,
        data: JSON.parse(options.body as string) as Record<string, unknown>,
    };
}
function receipt(requestId: unknown) {
    return {
        version: 1,
        scope: 'coverage_gap',
        action: 'dismiss',
        actor_id: 7,
        request_id: requestId,
        window: {
            site_id: 3,
            coverage_requirement_id: 11,
            coverage_window_key: 'original-key',
            window_starts_at: '2026-10-18T20:00:00.000000Z',
            window_ends_at: '2026-10-18T22:00:00.000000Z',
        },
        outcome: 'recorded',
        changed: true,
        state: 'dismissed',
        acknowledgement_id: 92,
        cleared_ids: [91],
        reason: 'My unsaved review reason',
        audit_id: 101,
        staffing_resolved: false,
        committed_at: '2026-10-10T03:00:00.000000Z',
    };
}
function retained() {
    expect(screen.getByRole('dialog')).toBeVisible();
    expect(screen.getByLabelText('Reason (required)')).toHaveValue(
        'My unsaved review reason',
    );
    expect(
        screen.getByText('Matai House', { selector: 'span' }),
    ).toBeInTheDocument();
    expect(screen.queryByText('Coverage review recorded')).toBeNull();
    expect(mock.reload).not.toHaveBeenCalled();
    expect(mock.visit).not.toHaveBeenCalled();
}
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('fetch', mock.fetch);
});
afterEach(() => vi.unstubAllGlobals());
describe('Conflict Queue real action recovery', () => {
    it('retains the selected finding and reason on a real 422 validation response', async () => {
        mock.fetch.mockResolvedValue(
            response(422, {
                errors: {
                    coverage_window_key: ['Window changed; refresh first.'],
                },
            }),
        );
        const { url, options, data } = beginDismiss();
        expect(url).toBe('/coverage/original-key/dismiss');
        expect(options.method).toBe('POST');
        expect(options.credentials).toBe('same-origin');
        expect(data.window_starts_at).toBe('2026-10-19T09:00:00+13:00');
        expect(data.return_to).toContain('week=2026-10-19');
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'Window changed',
            ),
        );
        retained();
    });
    it('retains the draft after a real 403 without navigating to an error page', async () => {
        mock.fetch.mockResolvedValue(response(403, { message: 'Forbidden' }));
        beginDismiss();
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'Your access to this window could not be confirmed',
            ),
        );
        retained();
    });
    it('does not confirm or lose the draft after a server failure', async () => {
        mock.fetch.mockResolvedValue(response(503, { message: 'Unavailable' }));
        beginDismiss();
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'could not be confirmed',
            ),
        );
        retained();
    });
    it('retains the draft after a lost connection and does not expose transport detail', async () => {
        mock.fetch.mockRejectedValue(new Error('Internal transport detail'));
        beginDismiss();
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'could not be confirmed',
            ),
        );
        retained();
        expect(screen.queryByText(/Internal transport detail/)).toBeNull();
    });
    it('keeps the draft when the response body is invalid', async () => {
        mock.fetch.mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => {
                throw new SyntaxError('Unreadable upstream detail');
            },
        });
        beginDismiss();
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'could not be confirmed',
            ),
        );
        retained();
    });
    it('does not treat a successful response without a committed receipt as confirmation', async () => {
        mock.fetch.mockResolvedValue(response(200, { status: 'success' }));
        beginDismiss();
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'could not be confirmed',
            ),
        );
        retained();
    });
    it('rejects a receipt for another account without hiding the finding', async () => {
        mock.fetch.mockImplementation(
            async (_url: string, options: RequestInit) =>
                response(200, {
                    result: {
                        ...receipt(
                            JSON.parse(options.body as string).request_id,
                        ),
                        actor_id: 8,
                    },
                }),
        );
        beginDismiss();
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'could not be confirmed',
            ),
        );
        retained();
    });
    it('confirms the matching receipt and refreshes without hiding the staffing gap', async () => {
        mock.fetch.mockImplementation(
            async (_url: string, options: RequestInit) =>
                response(200, {
                    result: receipt(
                        JSON.parse(options.body as string).request_id,
                    ),
                }),
        );
        beginDismiss();
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(screen.getByText('Coverage review recorded')).toBeVisible();
        expect(
            screen.getByRole('button', { name: /Matai House.*Morning cover/ }),
        ).toBeVisible();
        expect(mock.reload).toHaveBeenCalledTimes(1);
        expect(
            screen.queryByText(
                /overlap cleared|resolved today|blocking the roster/i,
            ),
        ).toBeNull();
    });
    it('uses the exact DELETE window and prevents a second request while pending', async () => {
        let complete!: (value: Response) => void;
        mock.fetch.mockReturnValue(
            new Promise<Response>((resolve) => {
                complete = resolve;
            }),
        );
        render(<RosteringConflicts {...props} />);
        choose();
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear acknowledgement' }),
        );
        const save = screen.getByRole('button', {
            name: 'Clear acknowledgement',
        });
        fireEvent.click(save);
        fireEvent.click(save);
        const [url, options] = mock.fetch.mock.calls[0] as [
            string,
            RequestInit,
        ];
        expect(url).toBe('/coverage/original-key/clear');
        expect(options.method).toBe('DELETE');
        expect(JSON.parse(options.body as string).window_ends_at).toBe(
            '2026-10-19T11:00:00+13:00',
        );
        expect(mock.fetch).toHaveBeenCalledTimes(1);
        expect(save).toBeDisabled();
        await act(async () => complete(response(503, {})));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'could not be confirmed',
        );
        expect(screen.getByRole('dialog')).toBeVisible();
    });
    it('keeps review actions disabled after a saved result until current findings load', async () => {
        mock.fetch.mockImplementation(
            async (_url: string, options: RequestInit) =>
                response(200, {
                    result: receipt(
                        JSON.parse(options.body as string).request_id,
                    ),
                }),
        );
        beginDismiss();
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(
            screen.getByRole('button', { name: 'Dismiss review' }),
        ).toBeDisabled();
        const options = mock.reload.mock.calls[0][0];
        act(() => options.onError({}));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'review was recorded',
        );
        expect(
            screen.getByRole('button', { name: 'Dismiss review' }),
        ).toBeDisabled();
        act(() => options.onSuccess({ props: { flash: {} } }));
        expect(
            screen.getByRole('button', { name: 'Dismiss review' }),
        ).toBeEnabled();
    });
});
