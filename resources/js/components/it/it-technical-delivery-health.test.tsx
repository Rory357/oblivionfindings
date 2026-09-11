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
    ItTechnicalDeliveryHealth,
    type TechnicalDeliveryHealth,
} from './it-technical-delivery-health';

vi.mock('@inertiajs/react', async () => ({
    ...(await vi.importActual('@inertiajs/react')),
    router: { get: vi.fn() },
}));

const health: TechnicalDeliveryHealth = {
    viewer_user_id: 7,
    checked_at: '2026-09-11T20:00:00Z',
    sources: [
        {
            source: 'device',
            can_view: true,
            available: true,
            coverage: 'source_bound_it_intents',
            total: 26,
            history_total: 26,
            search_query: '',
            links: [
                { label: 'Previous', url: null, active: false },
                {
                    label: '1',
                    url: '/it/setup?device_delivery_page=1&tab=operations#it-device-delivery-history',
                    active: true,
                },
                {
                    label: '2',
                    url: '/it/setup?device_delivery_page=2&tab=operations#it-device-delivery-history',
                    active: false,
                },
                {
                    label: 'Next',
                    url: '/it/setup?device_delivery_page=2&tab=operations#it-device-delivery-history',
                    active: false,
                },
            ],
            pending: 0,
            failures: 2,
            legacy_unverified: null,
            last_success_at: null,
            oldest_pending_at: '2026-09-11T18:00:00Z',
            rows: [
                {
                    id: 21,
                    state: 'failed',
                    outcome_code: 'processing_failed',
                    source_delivered: true,
                    attempts: 4,
                    attempt_limit: 5,
                    last_attempt_at: '2026-09-11T19:00:00Z',
                    completed_at: null,
                    created_at: '2026-09-11T18:00:00Z',
                },
            ],
            page: 1,
            last_page: 2,
            previous_url: null,
            next_url:
                '/it/setup?device_delivery_page=2&tab=operations#it-device-delivery-history',
        },
    ],
};

beforeEach(() => {
    vi.restoreAllMocks();
    vi.mocked(router.get).mockClear();
    vi.spyOn(axios, 'get').mockResolvedValue({
        data: {
            data: {
                ...health.sources[0].rows[0],
                viewer_user_id: 7,
                source: 'device',
                version: 'a'.repeat(64),
                can_retry: true,
            },
        },
    });
});

describe('technical delivery operations', () => {
    it.each(['recorded', 'unconfirmed', 'stopped'] as const)(
        'refreshes authoritative filtered history only after closing a %s retry',
        async (outcome) => {
            const response = {
                data: {
                    data: {
                        ...health.sources[0].rows[0],
                        viewer_user_id: 7,
                        source: 'device',
                        version: 'b'.repeat(64),
                        can_retry: false,
                        retry_requested: true,
                        state: 'applied',
                        outcome_code: 'ticket_created',
                        attempts: 5,
                    },
                },
            };
            const post = vi.spyOn(axios, 'post');
            if (outcome === 'recorded') post.mockResolvedValueOnce(response);
            else if (outcome === 'unconfirmed')
                post.mockRejectedValueOnce(new Error('Lost response'));
            else post.mockImplementationOnce(() => new Promise(() => {}));
            render(<ItTechnicalDeliveryHealth health={health} viewerId={7} />);
            fireEvent.click(screen.getByText('Delivery 21'));
            const dialog = await screen.findByRole('dialog');
            fireEvent.click(
                await within(dialog).findByRole('checkbox', {
                    name: 'I want to request one delivery attempt.',
                }),
            );
            fireEvent.click(
                within(dialog).getByRole('button', {
                    name: 'Request one retry',
                }),
            );
            if (outcome === 'stopped')
                fireEvent.click(
                    await within(dialog).findByRole('button', {
                        name: 'Stop waiting',
                    }),
                );
            await within(dialog).findByText(
                outcome === 'recorded'
                    ? /One retry allowance was recorded/
                    : outcome === 'stopped'
                      ? /Stopped waiting/
                      : /retry result could not be confirmed/,
            );
            expect(router.get).not.toHaveBeenCalled();
            expect(
                screen.getByText('26 total · 2 need attention'),
            ).toBeInTheDocument();
            fireEvent.click(
                within(dialog).getByRole('button', { name: 'Close details' }),
            );
            await waitFor(() => expect(router.get).toHaveBeenCalledOnce());
            expect(router.get).toHaveBeenCalledWith(
                window.location.href,
                {},
                expect.objectContaining({
                    preserveState: false,
                    preserveScroll: true,
                    replace: true,
                }),
            );
            expect(post).toHaveBeenCalledOnce();
        },
    );

    it('refreshes a changed live review and offers recovery if that refresh fails', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce({
            data: {
                data: {
                    ...health.sources[0].rows[0],
                    viewer_user_id: 7,
                    source: 'device',
                    version: 'b'.repeat(64),
                    can_retry: false,
                    attempts: 5,
                },
            },
        });
        render(<ItTechnicalDeliveryHealth health={health} viewerId={7} />);
        fireEvent.click(screen.getByText('Delivery 21'));
        const dialog = await screen.findByRole('dialog');
        await within(dialog).findByText('5 / 5');
        expect(router.get).not.toHaveBeenCalled();
        fireEvent.keyDown(dialog, { key: 'Escape' });
        await waitFor(() => expect(router.get).toHaveBeenCalledOnce());
        const options = vi.mocked(router.get).mock.calls[0][2]!;
        act(() =>
            options.onFinish?.(
                {} as Parameters<NonNullable<typeof options.onFinish>>[0],
            ),
        );
        expect(
            screen.getByText(/Displayed history and totals may be out of date/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh delivery history' }),
        );
        expect(router.get).toHaveBeenCalledTimes(2);
    });
    it('conceals delivery totals and the open dialog when live review denies access', async () => {
        vi.mocked(axios.get).mockRejectedValueOnce({
            isAxiosError: true,
            response: { status: 403 },
        });
        render(<ItTechnicalDeliveryHealth health={health} viewerId={7} />);
        fireEvent.click(screen.getByText('Delivery 21'));
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(
            screen.queryByText('26 total · 2 need attention'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Delivery 21')).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Refresh delivery access' }),
        ).toBeInTheDocument();
        await waitFor(() =>
            expect(
                document.getElementById('it-device-delivery-unavailable'),
            ).toHaveFocus(),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh delivery access' }),
        );
        expect(router.get).toHaveBeenCalledWith(
            window.location.href,
            {},
            {
                preserveState: false,
                preserveScroll: true,
                replace: true,
            },
        );
        // A refresh request alone is not evidence that permission was restored.
        expect(screen.queryByText('Delivery 21')).not.toBeInTheDocument();
    });

    it('distinguishes a filtered empty history from the complete scoped health totals', () => {
        render(
            <ItTechnicalDeliveryHealth
                health={{
                    ...health,
                    sources: [
                        {
                            ...health.sources[0],
                            search_query: 'specific outcome',
                            history_total: 0,
                            rows: [],
                            last_page: 1,
                            links: [],
                        },
                    ],
                }}
                viewerId={7}
            />,
        );
        expect(
            screen.getByText('26 total · 2 need attention'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('0 of 0 shown · page 1 of 1'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/No deliveries match this search/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Search results for “specific outcome”/),
        ).toBeInTheDocument();
    });
    it('distinguishes source acknowledgement, failed IT delivery and unverified historical work', () => {
        render(<ItTechnicalDeliveryHealth health={health} viewerId={7} />);
        expect(screen.getByText('Source acknowledged')).toBeInTheDocument();
        expect(screen.getByText('Delivery failed')).toBeInTheDocument();
        expect(
            screen.getByText('26 total · 2 need attention'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('No verified success recorded'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/their delivery remains unverified/),
        ).toBeInTheDocument();
        expect(
            screen.getByText('1 of 26 shown · page 1 of 2'),
        ).toBeInTheDocument();
    });

    it('conceals existing details immediately when the current actor changes', async () => {
        const view = render(
            <ItTechnicalDeliveryHealth health={health} viewerId={7} />,
        );
        fireEvent.click(screen.getByText('Delivery 21'));
        expect(
            await screen.findByRole('dialog', { name: 'Delivery 21' }),
        ).toBeVisible();
        view.rerender(
            <ItTechnicalDeliveryHealth health={health} viewerId={8} />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText('Delivery 21')).not.toBeInTheDocument();
        expect(
            screen.queryByText('26 total · 2 need attention'),
        ).not.toBeInTheDocument();
    });

    it('conceals history when source access is removed and distinguishes missing schema from zero', () => {
        const view = render(
            <ItTechnicalDeliveryHealth
                health={{
                    ...health,
                    sources: [{ ...health.sources[0], can_view: false }],
                }}
                viewerId={7}
            />,
        );
        expect(screen.queryByText('Delivery 21')).not.toBeInTheDocument();
        expect(
            screen.getByText(/require current IT and source-record access/),
        ).toBeInTheDocument();
        view.rerender(
            <ItTechnicalDeliveryHealth
                health={{
                    ...health,
                    sources: [{ ...health.sources[0], available: false }],
                }}
                viewerId={7}
            />,
        );
        expect(screen.getByText(/tracking is unavailable/)).toBeInTheDocument();
        expect(
            screen.queryByText('26 total · 2 need attention'),
        ).not.toBeInTheDocument();
    });

    it('opens from the keyboard and returns focus to the same stable delivery row', async () => {
        render(<ItTechnicalDeliveryHealth health={health} viewerId={7} />);
        const row = screen.getByRole('row', { name: /Delivery 21/ });
        row.focus();
        fireEvent.keyDown(row, { key: 'Enter' });
        const dialog = await screen.findByRole('dialog', {
            name: 'Delivery 21',
        });
        expect(
            await within(dialog).findByText('No completed outcome'),
        ).toBeInTheDocument();
        fireEvent.keyDown(dialog, { key: 'Escape' });
        await waitFor(() => expect(row).toHaveFocus());
        expect(router.get).not.toHaveBeenCalled();
    });

    it('offers the same detail review in the row context menu', async () => {
        render(<ItTechnicalDeliveryHealth health={health} viewerId={7} />);
        fireEvent.contextMenu(
            screen.getByRole('row', { name: /Delivery 21/ }),
            { clientX: 180, clientY: 150 },
        );
        fireEvent.click(screen.getByText('Review delivery details'));
        expect(
            await screen.findByRole('dialog', { name: 'Delivery 21' }),
        ).toBeVisible();
    });

    it('navigates history using canonical pagination without retaining an old selection', () => {
        render(<ItTechnicalDeliveryHealth health={health} viewerId={7} />);
        fireEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(router.get).toHaveBeenCalledWith(
            health.sources[0].next_url,
            {},
            { preserveState: false },
        );
    });

    it('does not render unknown diagnostic strings supplied as outcome codes', () => {
        render(
            <ItTechnicalDeliveryHealth
                health={{
                    ...health,
                    sources: [
                        {
                            ...health.sources[0],
                            rows: [
                                {
                                    ...health.sources[0].rows[0],
                                    outcome_code: 'private-exception-sentinel',
                                },
                            ],
                        },
                    ],
                }}
                viewerId={7}
            />,
        );
        expect(
            screen.getByText('No classified outcome recorded'),
        ).toBeInTheDocument();
        expect(screen.queryByText(/private-exception/)).not.toBeInTheDocument();
    });
});
