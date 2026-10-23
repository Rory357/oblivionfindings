import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { ActionIntent, PositionSource } from './_action-contract';
import { BoardActionDialog, PostPositionDialog } from './_dialogs';
import type { PostableShift } from './_posting-contract';
import {
    useAlertPreference,
    useBoardAction,
    type PositionTarget,
} from './_use-board-action';
import { usePosting } from './_use-posting';

const toasts = vi.hoisted(() => ({
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
}));
vi.mock('sonner', () => ({ toast: toasts }));
vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => undefined, visit: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
}));

const fetchMock = vi.fn<typeof fetch>();
const response = (value: unknown, status = 200) =>
    ({ status, redirected: false, json: async () => value }) as Response;
const click = (name: string | RegExp) =>
    fireEvent.click(screen.getByRole('button', { name }));
const posts = () =>
    fetchMock.mock.calls.filter(
        ([, init]) => (init as RequestInit | undefined)?.method === 'POST',
    );

beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockReset();
    Object.values(toasts).forEach((fn) => fn.mockReset());
    document.cookie = 'XSRF-TOKEN=test-token';
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    document.cookie = 'XSRF-TOKEN=; Max-Age=0';
});

/* ------------------------------------------------------------------ */
/*  Post position                                                       */
/* ------------------------------------------------------------------ */

const shift: PostableShift = {
    id: 32,
    label: 'Support shift',
    location: 'Matai House',
    starts_at: '2026-10-20T20:00:00Z',
    ends_at: '2026-10-21T04:00:00Z',
    assigned: false,
    status: 'scheduled',
    source_revision: 'a'.repeat(64),
    post_url: '/operations/shifts/32/open-position',
    can_post: true,
    reason: null,
    active_position: null,
};
const shiftPage = (data: PostableShift[] = [shift]) => ({
    data,
    page: 1,
    per_page: 20,
    has_more: false,
    workerTimezone: 'Pacific/Auckland',
});
const postingReceipt = (body: Record<string, unknown>) => ({
    result: {
        version: 1,
        action: 'post_position',
        outcome: 'posted',
        changed: true,
        actor_id: 18,
        request_id: body.request_id,
        source_revision: body.source_revision,
        committed_at: '2026-10-10T10:00:00Z',
        shift: {
            id: 32,
            site_id: 9,
            status: 'scheduled',
            assigned: false,
            starts_at: shift.starts_at,
            ends_at: shift.ends_at,
        },
        position: {
            id: 70,
            shift_id: 32,
            status: 'open',
            replacement_request_id: null,
            required_skills: body.required_skills,
            notes: body.notes,
            expires_at: body.expires_at,
            coverage_roles: [],
            claimed_by: null,
            claimed_at: null,
            approved_by: null,
            approved_at: null,
        },
    },
});

function PostingHarness({ onConfirmed }: { onConfirmed: () => void }) {
    const [open, setOpen] = useState(true);
    const form = usePosting({
        open,
        actor: 18,
        allowed: true,
        week: '2026-10-19',
        onConfirmed,
    });
    return (
        <>
            {!open && <Button onClick={() => setOpen(true)}>Reopen</Button>}
            <PostPositionDialog
                open={open}
                onClose={() => setOpen(false)}
                form={form}
                week="2026-10-19"
            />
        </>
    );
}

async function enterReview() {
    fireEvent.click(
        await screen.findByRole('button', { name: /^Support shift/ }),
    );
    click('Continue');
    fireEvent.change(screen.getByLabelText(/Posting notes/), {
        target: { value: ' Wheelchair access at the back ' },
    });
    click('Continue');
    expect(await screen.findByText('Review your posting')).toBeVisible();
}

describe('Post position dialog', () => {
    it('sends one reviewed post and shows success only for the matching saved result', async () => {
        const confirmed = vi.fn();
        let release: (value: Response) => void = () => undefined;
        fetchMock
            .mockResolvedValueOnce(response(shiftPage()))
            .mockImplementationOnce(
                () =>
                    new Promise<Response>((resolve) => {
                        release = resolve;
                    }),
            );
        render(<PostingHarness onConfirmed={confirmed} />);
        expect(String(fetchMock.mock.calls[0][0])).toContain('week=2026-10-19');
        await enterReview();
        expect(screen.getByText('Wheelchair access at the back')).toBeVisible();

        click('Post position');
        // A second press while the first is in flight sends nothing.
        expect(screen.getByRole('button', { name: /Posting/ })).toBeDisabled();
        expect(posts()).toHaveLength(1);
        const body = JSON.parse(String(posts()[0][1]?.body));
        expect(body).toMatchObject({
            shift_id: 32,
            source_revision: 'a'.repeat(64),
            notes: 'Wheelchair access at the back',
            required_skills: [],
            expires_at: null,
        });
        expect(body.request_id).toMatch(/^[0-9a-f-]{36}$/);
        expect(screen.queryByText('Position posted')).toBeNull();

        await act(async () => release(response(postingReceipt(body), 201)));
        expect(await screen.findByText('Position posted')).toBeVisible();
        expect(confirmed).toHaveBeenCalledTimes(1);
    });

    it('keeps the entries when the server refuses before saving', async () => {
        fetchMock
            .mockResolvedValueOnce(response(shiftPage()))
            .mockResolvedValueOnce(
                response(
                    {
                        message: 'Invalid',
                        errors: {
                            shift_id: [
                                'This shift already has an active open position.',
                            ],
                        },
                    },
                    422,
                ),
            )
            .mockResolvedValueOnce(response(shiftPage()));
        render(<PostingHarness onConfirmed={vi.fn()} />);
        await enterReview();
        click('Post position');
        expect(
            await screen.findByText(/already has an active open position/),
        ).toBeVisible();
        expect(screen.getByText(/Your entries are kept/)).toBeVisible();
        expect(screen.queryByText('Position posted')).toBeNull();
        // Back on Choose shift; the typed notes are still there.
        fireEvent.click(
            await screen.findByRole('button', { name: /^Support shift/ }),
        );
        click('Continue');
        expect(screen.getByLabelText(/Posting notes/)).toHaveValue(
            ' Wheelchair access at the back ',
        );
        expect(posts()).toHaveLength(1);
    });

    it('shows a lost result as not confirmed, checks the shift by its number and never re-sends', async () => {
        fetchMock
            .mockResolvedValueOnce(response(shiftPage()))
            .mockResolvedValueOnce(response({ result: null }, 202))
            .mockResolvedValueOnce(
                response(
                    shiftPage([
                        {
                            ...shift,
                            can_post: false,
                            reason: 'duplicate',
                            active_position: {
                                id: 70,
                                status: 'open',
                                expired: false,
                            },
                        },
                    ]),
                ),
            );
        render(<PostingHarness onConfirmed={vi.fn()} />);
        await enterReview();
        click('Post position');
        expect(await screen.findByText('Not confirmed')).toBeVisible();
        expect(screen.queryByText('Position posted')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Post position' }),
        ).toBeNull();

        click('Check the shift');
        expect(await screen.findByText(/posted for cover now/)).toBeVisible();
        const check = String(fetchMock.mock.calls[2][0]);
        expect(check).toContain('q=32');
        expect(check).not.toContain('week=');
        expect(posts()).toHaveLength(1);

        // Closing keeps the attempt for this page; reopening shows it again.
        click('Close form');
        await screen.findByText('Close this unconfirmed post?');
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Close form' }).at(-1)!,
        );
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Reopen' }),
            ).toBeVisible(),
        );
        click('Reopen');
        expect(await screen.findByText('Not confirmed')).toBeVisible();
        expect(posts()).toHaveLength(1);
    });
});

/* ------------------------------------------------------------------ */
/*  Claim / approve                                                     */
/* ------------------------------------------------------------------ */

const claimSource = (over: Partial<PositionSource> = {}): PositionSource => ({
    actor_id: 18,
    action: 'claim',
    source_revision: 'a'.repeat(64),
    display: {
        position_id: 70,
        shift_id: 32,
        status: 'open',
        starts_at: '2026-10-20T20:00:00Z',
        ends_at: '2026-10-21T04:00:00Z',
        client: 'Ari K.',
        client_redacted: true,
        site: 'Matai House',
        claimed_by: null,
        claimant: null,
    },
    can: { claim: true, approve: false },
    urls: {
        claim: '/operations/job-board/70/claim',
        approve: '/operations/job-board/70/approve',
    },
    eligibility: {
        assessment_status: 'assessed',
        warning_reasons: ['The gap between duties needs review.'],
        blocked_reasons: [],
        acknowledgement_required: false,
        reason_required: false,
    },
    ...over,
});
const claimResult = (body: ActionIntent['body']) => ({
    result: {
        version: 1,
        scope: 'job_board',
        action: 'claim',
        outcome: 'claimed',
        changed: true,
        actor_id: 18,
        request_id: body.request_id,
        source_revision: body.source_revision,
        result_revision: 'b'.repeat(64),
        committed_at: '2026-10-10T11:00:00.987654Z',
        position: {
            id: 70,
            shift_id: 32,
            status: 'claimed',
            claimed_by: 18,
            claimed_at: '2026-10-10T11:00:00Z',
            approved_by: null,
            approved_at: null,
        },
        shift: {
            id: 32,
            status: 'scheduled',
            assigned: false,
            starts_at: '2026-10-20T20:00:00Z',
            ends_at: '2026-10-21T04:00:00Z',
        },
        preference: null,
        eligibility: claimSource().eligibility,
    },
});

const callbacks = { confirmed: vi.fn(), settled: vi.fn() };
function ActionHarness({ target }: { target: PositionTarget }) {
    const action = useBoardAction({
        actor: 18,
        onConfirmed: callbacks.confirmed,
        onSettled: callbacks.settled,
    });
    return (
        <>
            <Button onClick={() => action.start(target)}>Open action</Button>
            <BoardActionDialog action={action} />
        </>
    );
}
const claimTarget: PositionTarget = { action: 'claim', positionId: 70 };

describe('Claim and approve dialog', () => {
    beforeEach(() => {
        callbacks.confirmed.mockReset();
        callbacks.settled.mockReset();
    });

    it('reads the shift first, sends one claim with its revision and shows success only when confirmed', async () => {
        let release: (value: Response) => void = () => undefined;
        fetchMock
            .mockResolvedValueOnce(response(claimSource()))
            .mockImplementationOnce(
                () =>
                    new Promise<Response>((resolve) => {
                        release = resolve;
                    }),
            );
        render(<ActionHarness target={claimTarget} />);
        click('Open action');
        expect(String(fetchMock.mock.calls[0][0])).toBe(
            '/operations/job-board/70/action-source?action=claim',
        );
        expect(await screen.findByText('Matai House')).toBeVisible();
        expect(
            screen.getByText('The gap between duties needs review.'),
        ).toBeVisible();

        const confirm = screen
            .getAllByRole('button', { name: 'Claim shift' })
            .at(-1)!;
        fireEvent.click(confirm);
        fireEvent.click(confirm);
        expect(posts()).toHaveLength(1);
        expect(String(posts()[0][0])).toBe('/operations/job-board/70/claim');
        const body = JSON.parse(String(posts()[0][1]?.body));
        expect(body.source_revision).toBe('a'.repeat(64));
        expect(body.request_id).toMatch(/^[0-9a-f-]{36}$/);
        // Can't be dismissed while sending.
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(screen.queryByText('Claim sent')).toBeNull();

        await act(async () => release(response(claimResult(body))));
        expect(await screen.findByText('Claim sent')).toBeVisible();
        expect(callbacks.confirmed).toHaveBeenCalledTimes(1);
        click('Done');
        expect(callbacks.settled).not.toHaveBeenCalled();
    });

    it('treats a refusal before saving as nothing saved and shows the current shift', async () => {
        fetchMock
            .mockResolvedValueOnce(response(claimSource()))
            .mockResolvedValueOnce(
                response(
                    {
                        message: 'Invalid',
                        errors: {
                            claim: [
                                'This position was just claimed by another worker.',
                            ],
                        },
                    },
                    422,
                ),
            )
            .mockResolvedValueOnce(
                response(
                    claimSource({
                        can: { claim: false, approve: false },
                        display: {
                            ...claimSource().display,
                            status: 'claimed',
                            claimed_by: 24,
                            claimant: 'Mere Tane',
                        },
                    }),
                ),
            );
        render(<ActionHarness target={claimTarget} />);
        click('Open action');
        await screen.findByText('Matai House');
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Claim shift' }).at(-1)!,
        );
        expect(
            await screen.findByText(
                /just claimed by another worker\. Nothing was saved\./,
            ),
        ).toBeVisible();
        expect(
            await screen.findByText(/claimed by Mere Tane/, {
                selector: 'p',
            }),
        ).toBeVisible();
        expect(
            screen.getAllByRole('button', { name: 'Claim shift' }),
        ).toHaveLength(0);
        expect(callbacks.confirmed).not.toHaveBeenCalled();
    });

    it.each([202, 403, 500])(
        'shows HTTP %s after sending as not confirmed, offers a check and never re-sends',
        async (status) => {
            fetchMock
                .mockResolvedValueOnce(response(claimSource()))
                .mockResolvedValueOnce(response({ result: null }, status))
                .mockResolvedValueOnce(
                    response(
                        claimSource({
                            can: { claim: false, approve: false },
                            display: {
                                ...claimSource().display,
                                status: 'claimed',
                                claimed_by: 18,
                                claimant: 'Sam Rua',
                            },
                        }),
                    ),
                );
            render(<ActionHarness target={claimTarget} />);
            click('Open action');
            await screen.findByText('Matai House');
            fireEvent.click(
                screen.getAllByRole('button', { name: 'Claim shift' }).at(-1)!,
            );
            expect(await screen.findByText('Not confirmed')).toBeVisible();
            expect(screen.queryByText('Claim sent')).toBeNull();
            click('Check the shift');
            expect(
                await screen.findByText(
                    /Right now this shift is claimed by Sam Rua.*cannot show whether/,
                ),
            ).toBeVisible();
            expect(
                screen.queryByRole('button', { name: 'Try again' }),
            ).toBeNull();
            expect(posts()).toHaveLength(1);
            click('Close and refresh');
            expect(callbacks.settled).toHaveBeenCalledTimes(1);
            expect(callbacks.confirmed).not.toHaveBeenCalled();
        },
    );

    it('offers no confirm button for a blocked claim and explains why', async () => {
        fetchMock.mockResolvedValueOnce(
            response(
                claimSource({
                    can: { claim: false, approve: false },
                    eligibility: {
                        assessment_status: 'assessed',
                        warning_reasons: [],
                        blocked_reasons: ['Another duty overlaps this Shift.'],
                        acknowledgement_required: false,
                        reason_required: false,
                    },
                }),
            ),
        );
        render(<ActionHarness target={claimTarget} />);
        click('Open action');
        expect(
            await screen.findByText("You can't claim this shift right now."),
        ).toBeVisible();
        expect(
            screen.getByText('Another duty overlaps this Shift.'),
        ).toBeVisible();
        expect(
            screen.getAllByRole('button', { name: 'Claim shift' }),
        ).toHaveLength(0);
    });
});

/* ------------------------------------------------------------------ */
/*  Alerts                                                              */
/* ------------------------------------------------------------------ */

function AlertHarness({
    enabled,
    settled,
}: {
    enabled: boolean;
    settled: () => void;
}) {
    const alerts = useAlertPreference({ actor: 18, onSettled: settled });
    return (
        <Button
            disabled={alerts.pending}
            onClick={() => void alerts.setAlerts(enabled)}
        >
            Set alerts
        </Button>
    );
}
const preference = (enabled: boolean) => ({
    actor_id: 18,
    source_revision: 'c'.repeat(64),
    enabled,
    can_update: true,
});

describe('Alert setting', () => {
    it('sends the explicit choice with a fresh revision and confirms it', async () => {
        const settled = vi.fn();
        fetchMock
            .mockResolvedValueOnce(response(preference(false)))
            .mockImplementationOnce(async (_url, init) => {
                const body = JSON.parse(String(init?.body));
                return response({
                    result: {
                        version: 1,
                        scope: 'job_board',
                        action: 'set_alert_preference',
                        outcome: 'changed',
                        changed: true,
                        actor_id: 18,
                        request_id: body.request_id,
                        source_revision: body.source_revision,
                        result_revision: 'd'.repeat(64),
                        committed_at: '2026-10-10T11:00:00Z',
                        position: null,
                        shift: null,
                        preference: { enabled: true },
                        eligibility: null,
                    },
                });
            });
        render(<AlertHarness enabled settled={settled} />);
        click('Set alerts');
        await waitFor(() => expect(toasts.success).toHaveBeenCalled());
        expect(toasts.success.mock.calls[0][0]).toBe('Alerts are on');
        const body = JSON.parse(String(posts()[0][1]?.body));
        expect(body).toMatchObject({
            enabled: true,
            source_revision: 'c'.repeat(64),
        });
        expect(settled).toHaveBeenCalledTimes(1);
    });

    it('reports an unconfirmed change honestly with the current setting', async () => {
        const settled = vi.fn();
        fetchMock
            .mockResolvedValueOnce(response(preference(false)))
            .mockRejectedValueOnce(new TypeError('Network lost'))
            .mockResolvedValueOnce(response(preference(true)));
        render(<AlertHarness enabled settled={settled} />);
        click('Set alerts');
        await waitFor(() => expect(toasts.warning).toHaveBeenCalled());
        expect(toasts.success).not.toHaveBeenCalled();
        expect(toasts.warning.mock.calls[0][1]).toEqual({
            description: 'Your alerts are on right now.',
        });
        expect(posts()).toHaveLength(1);
        expect(settled).toHaveBeenCalledTimes(1);
    });

    it('sends nothing when the current setting cannot be read', async () => {
        fetchMock.mockResolvedValueOnce(response({}, 500));
        render(<AlertHarness enabled={false} settled={vi.fn()} />);
        click('Set alerts');
        await waitFor(() => expect(toasts.error).toHaveBeenCalled());
        expect(posts()).toHaveLength(0);
    });
});
