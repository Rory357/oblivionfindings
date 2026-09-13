import { router } from '@inertiajs/react';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    ItServiceOperations,
    type AutomationDefinition,
    type AutomationRunRow,
    type EmailDeliveryRow,
    type OperationsAudit,
} from '../it-service-operations';

const session = vi.hoisted(() => ({ id: 7, manage: true, audit: true }));
const transport = vi.hoisted(() => ({
    post: vi.fn(),
    isAxiosError: (value: unknown) =>
        !!value && typeof value === 'object' && 'response' in value,
}));
vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({
        props: {
            auth: {
                user: { id: session.id },
                can: {
                    it: { manage: session.manage },
                    audit: { viewAny: session.audit },
                },
            },
        },
    }),
}));
vi.mock('axios', () => ({ default: transport }));
const at = '2026-09-09T02:00:00Z';
const definition: AutomationDefinition = {
    key: 'it.retry-attachment-cleanup',
    label: 'Recover pending attachment cleanup',
    expression: '*/5 * * * *',
    timezone: 'Pacific/Auckland',
    next_run_at: at,
    without_overlapping: true,
    on_one_server: true,
    overlap_minutes: 10,
    latest_status: 'succeeded',
    latest_at: at,
    freshness: {
        state: 'stale',
        last_success_at: '2026-09-09T01:00:00Z',
        latest_status: 'succeeded',
        latest_started_at: at,
        required_since: at,
        evaluated_at: at,
        grace_seconds: 300,
    },
};
const audit: OperationsAudit = {
    teams: { total: 0, active: 0, missing_manager: 0, without_members: 0 },
    queues: {
        total: 0,
        active: 0,
        missing_team: 0,
        without_default_assignee: 0,
    },
    catalogue: { total: 0, published: 0, missing_service: 0 },
    forms: { configured: 0, empty: 0 },
    email: {
        connections: 0,
        connected: 0,
        connection_errors: 0,
        failed_or_bounced: 0,
    },
    api: { identities: 0, active: 0, revoked: 0, request_errors: 0 },
    slas: { custom_policies: 0, effective_priorities: 4 },
    settings: {
        inbound_status_callback: false,
        outbound_status_callback: false,
    },
    attachment_cleanup: {
        viewer_user_id: 7,
        can_view_counts: true,
        readiness: 'ready',
        checked_at: at,
        counts: {
            cleanup_pending: 12,
            reserved_unclassified: 23,
            reconciliation_required: 34,
        },
    },
};
const run: AutomationRunRow = {
    id: 9,
    automation_key: definition.key,
    status: 'failed',
    started_at: at,
    finished_at: at,
    runtime_ms: 10,
    error_summary: 'Attachment cleanup did not complete.',
    cleanup: {
        limit: 100,
        counts: {
            requested: 2,
            deleted: 1,
            failed: 1,
            deferred: 0,
            reconciliation_required: 0,
        },
    },
};
const delivery: EmailDeliveryRow = {
    id: 81,
    notification_uuid: 'e38c08e9-cb88-4a0d-81e0-3f6d8db3c01f',
    ticket: { id: 4, reference: 'IT-004', title: 'VPN access' },
    recipient: 'Mere Tupu',
    recipient_email: 'mere@example.test',
    subject: 'VPN access update',
    status: 'failed',
    attempt_count: 1,
    retry_count: 0,
    last_error: 'The provider rejected the mailbox.',
    queued_at: at,
    accepted_at: null,
    provider_status_at: null,
    delivered_at: null,
    can_retry: true,
};

function view(
    nextAudit = audit,
    definitions = [definition],
    runs = [run],
    deliveries: EmailDeliveryRow[] = [],
) {
    return (
        <ItServiceOperations
            audit={nextAudit}
            deliveries={deliveries}
            automationDefinitions={definitions}
            automationRuns={runs}
        />
    );
}
beforeEach(() => {
    session.id = 7;
    session.manage = true;
    session.audit = true;
    transport.post.mockReset();
    vi.mocked(axios.post).mockReset();
    vi.mocked(router.reload).mockReset();
});

describe('Operations cleanup and scheduler evidence', () => {
    it('uses authoritative staleness rather than the historical successful status', () => {
        render(view());
        expect(screen.getByText('Overdue check')).toBeVisible();
        expect(screen.queryByText('Current')).not.toBeInTheDocument();
        expect(screen.getByText('Last successful check')).toBeVisible();
        expect(screen.getByText('On · 10 minute expiry')).toBeVisible();
        expect(screen.getByText(/Wed 9 Sep.*1:00 pm/)).toBeVisible();
    });
    it('renders recovered freshness without changing the retained failed batch counts', () => {
        const { rerender } = render(view());
        rerender(
            view(audit, [
                {
                    ...definition,
                    freshness: { ...definition.freshness!, state: 'fresh' },
                },
            ]),
        );
        expect(screen.getByText('Current')).toBeVisible();
        expect(screen.queryByText('Overdue check')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                '1 deleted · 1 failed · 0 deferred · 0 need reconciliation',
            ),
        ).toBeVisible();
    });
    it('does not manufacture freshness when no watchdog evidence arrived', () => {
        render(view(audit, [{ ...definition, freshness: undefined }]));
        expect(screen.getByText('No verified check')).toBeVisible();
        expect(screen.queryByText('Current')).not.toBeInTheDocument();
    });
    it('shows authorized live counts separately from the selected historical batch', () => {
        render(view());
        const evidence = within(
            screen.getByRole('region', { name: 'Attachment cleanup evidence' }),
        );
        expect(evidence.getByText('12')).toBeVisible();
        expect(evidence.getByText('23')).toBeVisible();
        expect(evidence.getByText('34')).toBeVisible();
        expect(
            evidence.getByText(
                /unclassified reservations may still belong to active or uncertain work/i,
            ),
        ).toBeVisible();
        expect(evidence.getByText(/Latest shown batch/)).toBeVisible();
    });
    it.each(['audit', 'manage', 'actor'] as const)(
        'conceals stale private counts immediately after current %s authority changes',
        (change) => {
            const { rerender } = render(view());
            if (change === 'actor') session.id = 8;
            else session[change] = false;
            rerender(view());
            const evidence = within(
                screen.getByRole('region', {
                    name: 'Attachment cleanup evidence',
                }),
            );
            expect(
                evidence.getByText(
                    'Cleanup counts require IT management and audit access.',
                ),
            ).toBeVisible();
            for (const value of ['12', '23', '34'])
                expect(evidence.queryByText(value)).not.toBeInTheDocument();
            expect(
                evidence.queryByText(/Latest shown batch/),
            ).not.toBeInTheDocument();
            expect(
                evidence.getByText('Storage records available'),
            ).toBeVisible();
        },
    );
    it('respects the fresh server denial even if cached client capabilities still allow audit', () => {
        render(
            view({
                ...audit,
                attachment_cleanup: {
                    ...audit.attachment_cleanup!,
                    can_view_counts: false,
                },
            }),
        );
        expect(
            screen.getByText(
                'Cleanup counts require IT management and audit access.',
            ),
        ).toBeVisible();
        expect(screen.queryByText('12')).not.toBeInTheDocument();
    });
    it.each(['not_ready', 'unavailable'] as const)(
        'shows %s without substituting zero for unavailable counts',
        (readiness) => {
            render(
                view(
                    {
                        ...audit,
                        attachment_cleanup: {
                            ...audit.attachment_cleanup!,
                            readiness,
                            counts: null,
                        },
                    },
                    [definition],
                    [],
                ),
            );
            const evidence = within(
                screen.getByRole('region', {
                    name: 'Attachment cleanup evidence',
                }),
            );
            expect(
                evidence.getByText('Cleanup counts are unavailable.'),
            ).toBeVisible();
            expect(evidence.queryByText('0')).not.toBeInTheDocument();
            expect(
                evidence.getByText(
                    readiness === 'not_ready'
                        ? 'Storage setup incomplete'
                        : 'Storage evidence unavailable',
                ),
            ).toBeVisible();
        },
    );
});

describe('Operations delivery recovery', () => {
    it('renders every scoped delivery row instead of silently dropping rows after 25', () => {
        const deliveries = Array.from({ length: 50 }, (_, index) => ({
            ...delivery,
            id: index + 1,
            notification_uuid: `a0000000-0000-4000-8000-${String(index + 1).padStart(12, '0')}`,
            subject: `Delivery attempt ${index + 1}`,
            can_retry: false,
        }));
        render(view(audit, [definition], [], deliveries));
        const table = screen.getByRole('region', { name: 'Email delivery' });
        expect(within(table).getByText('Delivery attempt 1')).toBeVisible();
        expect(within(table).getByText('Delivery attempt 50')).toBeVisible();
        expect(within(table).getAllByRole('article')).toHaveLength(50);
    });

    it('distinguishes accepted, delivered, and unconfirmed sending with server times', () => {
        render(
            view(
                audit,
                [definition],
                [],
                [
                    {
                        ...delivery,
                        id: 1,
                        status: 'accepted',
                        can_retry: false,
                        last_error: null,
                        accepted_at: at,
                        provider_status_at: '2026-09-09T02:05:00Z',
                    },
                    {
                        ...delivery,
                        id: 2,
                        status: 'sending',
                        can_retry: false,
                        last_error: null,
                        provider_status_at: at,
                    },
                    {
                        ...delivery,
                        id: 3,
                        status: 'delivered',
                        can_retry: false,
                        last_error: null,
                        delivered_at: at,
                    },
                ],
            ),
        );
        expect(
            screen.getByText(
                /Provider accepted this email\. Delivery is not yet confirmed\./,
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                /Submission started; the outcome is unconfirmed\. Check the provider result before retrying\./,
            ),
        ).toBeVisible();
        expect(screen.getByText(/Delivery confirmed/)).toBeVisible();
        expect(screen.getAllByText(/Wed 9 Sep.*1:00 pm/)).not.toHaveLength(0);
        expect(screen.getAllByText(/Provider update/)).toHaveLength(2);
    });

    it('sends the current actor, disables duplicate retries, and reloads only after a valid queued receipt', async () => {
        let resolve!: (value: unknown) => void;
        transport.post.mockReturnValueOnce(
            new Promise((next) => {
                resolve = next;
            }),
        );
        render(view(audit, [definition], [], [delivery]));

        const retry = screen.getByRole('button', { name: 'Retry delivery' });
        fireEvent.click(retry);
        fireEvent.click(retry);

        expect(transport.post).toHaveBeenCalledTimes(1);
        expect(transport.post).toHaveBeenCalledWith(
            '/it/setup/email-deliveries/81/retry',
            { expected_actor_id: 7 },
            expect.objectContaining({
                headers: { Accept: 'application/json' },
                timeout: 30000,
            }),
        );
        expect(
            screen.getByRole('button', { name: 'Retrying delivery…' }),
        ).toBeDisabled();

        await act(async () => {
            resolve({
                status: 200,
                data: {
                    data: {
                        original_delivery_id: 81,
                        retry_delivery_id: 82,
                        status: 'queued',
                        actor_id: 7,
                    },
                },
            });
        });

        expect(
            await screen.findByText(
                'Email was queued for another delivery attempt. Refreshing current delivery state.',
            ),
        ).toBeVisible();
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: [
                    'auth',
                    'emailDeliveries',
                    'emailDeliveryFilter',
                    'operationsAudit',
                    'generatedAt',
                ],
                preserveScroll: true,
            }),
        );
        expect(
            screen.queryByRole('button', { name: 'Retry delivery' }),
        ).not.toBeInTheDocument();
    });

    it('withholds another retry and offers a refresh when access is denied', async () => {
        transport.post.mockRejectedValueOnce({
            response: { status: 404, data: { message: 'Not found' } },
        });
        render(view(audit, [definition], [], [delivery]));
        fireEvent.click(screen.getByRole('button', { name: 'Retry delivery' }));

        expect(
            await screen.findByText(
                'This delivery is no longer available to retry. Refresh current delivery state.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Retry delivery' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh current state' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Refreshing current state…' }),
        );
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: [
                    'auth',
                    'emailDeliveries',
                    'emailDeliveryFilter',
                    'operationsAudit',
                    'generatedAt',
                ],
                preserveScroll: true,
            }),
        );
        expect(router.reload).toHaveBeenCalledTimes(1);
        const options = vi.mocked(router.reload).mock.calls[0]?.[0];
        await act(async () => {
            options?.onError?.({});
        });
        expect(
            await screen.findByText(
                'Current delivery state could not be refreshed. Try again before retrying.',
            ),
        ).toBeVisible();
    });

    it.each([
        ['an unknown transport outcome', new Error('network')],
        [
            'a malformed success receipt',
            {
                status: 200,
                data: {
                    data: {
                        original_delivery_id: 81,
                        retry_delivery_id: '82',
                        status: 'queued',
                        actor_id: 7,
                    },
                },
            },
        ],
        [
            'a self-referencing receipt',
            {
                status: 200,
                data: {
                    data: {
                        original_delivery_id: 81,
                        retry_delivery_id: 81,
                        status: 'queued',
                        actor_id: 7,
                    },
                },
            },
        ],
    ])(
        'requires a refresh rather than resending after %s',
        async (_case, result) => {
            if (result instanceof Error)
                transport.post.mockRejectedValueOnce(result);
            else transport.post.mockResolvedValueOnce(result);
            render(view(audit, [definition], [], [delivery]));
            fireEvent.click(
                screen.getByRole('button', { name: 'Retry delivery' }),
            );

            expect(
                await screen.findByText(
                    'The retry outcome was not confirmed. Refresh current delivery state before trying again.',
                ),
            ).toBeVisible();
            expect(
                screen.queryByRole('button', { name: 'Retry delivery' }),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: 'Refresh current state' }),
            ).toBeEnabled();
        },
    );

    it.each([409, 422])(
        'keeps a stale retry error in recovery until the current row is refreshed (%s)',
        async (status) => {
            transport.post.mockRejectedValueOnce({
                response: { status, data: { message: 'Delivery changed.' } },
            });
            render(view(audit, [definition], [], [delivery]));
            fireEvent.click(
                screen.getByRole('button', { name: 'Retry delivery' }),
            );

            expect(
                await screen.findByText(
                    'Delivery changed. Refresh current delivery state before trying again.',
                ),
            ).toBeVisible();
            expect(
                screen.queryByRole('button', { name: 'Retry delivery' }),
            ).not.toBeInTheDocument();
        },
    );

    it('re-enables retry only when a fresh reload verifies the same actor and eligibility', async () => {
        transport.post.mockRejectedValueOnce({
            response: { status: 409, data: { message: 'Delivery changed.' } },
        });
        render(view(audit, [definition], [], [delivery]));
        fireEvent.click(screen.getByRole('button', { name: 'Retry delivery' }));
        await screen.findByText(
            'Delivery changed. Refresh current delivery state before trying again.',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh current state' }),
        );

        const options = vi.mocked(router.reload).mock.calls[0]?.[0];
        await act(async () => {
            options?.onSuccess?.({
                props: {
                    auth: { user: { id: 7 } },
                    emailDeliveries: [delivery],
                },
            } as never);
        });
        expect(
            screen.getByRole('button', { name: 'Retry delivery' }),
        ).toBeEnabled();
    });

    it.each([
        [
            'a different signed-in actor',
            {
                props: {
                    auth: { user: { id: 8 } },
                    emailDeliveries: [delivery],
                },
            },
        ],
        ['missing authentication', { props: { emailDeliveries: [delivery] } }],
        [
            'an ineligible current delivery',
            {
                props: {
                    auth: { user: { id: 7 } },
                    emailDeliveries: [{ ...delivery, can_retry: false }],
                },
            },
        ],
    ])(
        'does not re-enable retry after fresh state has %s',
        async (_case, page) => {
            transport.post.mockRejectedValueOnce({
                response: {
                    status: 409,
                    data: { message: 'Delivery changed.' },
                },
            });
            render(view(audit, [definition], [], [delivery]));
            fireEvent.click(
                screen.getByRole('button', { name: 'Retry delivery' }),
            );
            await screen.findByText(
                'Delivery changed. Refresh current delivery state before trying again.',
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh current state' }),
            );

            const options = vi.mocked(router.reload).mock.calls[0]?.[0];
            await act(async () => {
                options?.onSuccess?.(page as never);
            });
            expect(
                screen.getByText(
                    'Current delivery state does not confirm that another retry is eligible.',
                ),
            ).toBeVisible();
            expect(
                screen.queryByRole('button', { name: 'Retry delivery' }),
            ).not.toBeInTheDocument();
        },
    );

    it.each(['onError', 'onCancel'] as const)(
        'allows another refresh after reload %s',
        async (callback) => {
            transport.post.mockRejectedValueOnce({
                response: {
                    status: 409,
                    data: { message: 'Delivery changed.' },
                },
            });
            render(view(audit, [definition], [], [delivery]));
            fireEvent.click(
                screen.getByRole('button', { name: 'Retry delivery' }),
            );
            await screen.findByText(
                'Delivery changed. Refresh current delivery state before trying again.',
            );
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh current state' }),
            );

            const options = vi.mocked(router.reload).mock.calls[0]?.[0];
            await act(async () => {
                if (callback === 'onError') options?.onError?.({});
                else options?.onCancel?.();
            });
            expect(
                screen.getByRole('button', { name: 'Refresh current state' }),
            ).toBeEnabled();
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh current state' }),
            );
            expect(router.reload).toHaveBeenCalledTimes(2);
        },
    );

    it('cancels and conceals retry state immediately when the authenticated actor changes', async () => {
        let resolve!: (value: unknown) => void;
        transport.post.mockReturnValueOnce(
            new Promise((next) => {
                resolve = next;
            }),
        );
        const rendered = render(view(audit, [definition], [], [delivery]));
        fireEvent.click(screen.getByRole('button', { name: 'Retry delivery' }));

        session.id = 8;
        rendered.rerender(view(audit, [definition], [], [delivery]));
        expect(
            screen.queryByRole('button', { name: /Retry.*delivery/ }),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();

        await act(async () => {
            resolve({
                status: 200,
                data: {
                    data: {
                        original_delivery_id: 81,
                        retry_delivery_id: 82,
                        status: 'queued',
                        actor_id: 7,
                    },
                },
            });
        });
        await waitFor(() => expect(router.reload).not.toHaveBeenCalled());
    });
});
