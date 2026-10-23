import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import type { JobBoardViewer, JobPost } from '@/components/job-board/types';
import JobBoardIndex from './Index';

const mock = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    listeners: new Set<(event: unknown) => void>(),
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: 18 } } } }),
    Link: ({ children, ...props }: { children: ReactNode; href: string }) => (
        <a {...props}>{children}</a>
    ),
    router: {
        get: mock.get,
        post: mock.post,
        reload: mock.reload,
        visit: mock.visit,
        on: (_name: string, callback: (event: unknown) => void) => {
            mock.listeners.add(callback);
            return () => mock.listeners.delete(callback);
        },
    },
}));
vi.mock('@/hooks/use-mobile', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/hooks/use-mobile')>()),
    useIsMobile: () => false,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
}));

const fetchMock = vi.fn<typeof fetch>();
const response = (value: unknown, status = 200) =>
    ({ status, redirected: false, json: async () => value }) as Response;

const week = {
    start: '2026-10-19',
    end: '2026-10-25',
    start_label: '19 Oct',
    end_label: '25 Oct',
    prev: '2026-10-12',
    next: '2026-10-26',
    is_current: true,
};

function job(overrides: Partial<JobPost>): JobPost {
    return {
        id: 70,
        title: 'Morning support',
        status: 'open',
        date: '2026-10-21',
        start_time: '09:00',
        end_time: '13:00',
        location: 'Matai House',
        required_skills: [],
        your_skills: [],
        coverage_roles: [],
        coverage: null,
        privacy: { can_view_sensitive_details: false },
        client: null,
        replacement: null,
        claimed_by: null,
        eligibility: null,
        viewer_eligibility: {
            is_eligible: true,
            blocked_reasons: [],
            warning_count: 0,
            first_warning: null,
        },
        your_schedule: null,
        tasks: [],
        tasks_total: 0,
        past_shifts_here: 0,
        site_familiar: false,
        ...overrides,
    };
}
const jobs = {
    data: [
        job({ id: 70, title: 'Morning support' }),
        job({
            id: 71,
            title: 'Evening support',
            status: 'claimed',
            claimed_by: { id: 24, name: 'Mere Tane' },
        }),
    ],
    links: [],
    current_page: 1,
    last_page: 1,
    total: 2,
};
const renderPage = (viewer: JobBoardViewer) =>
    render(
        <JobBoardIndex
            week={week}
            filters={{ scope: 'all' }}
            jobs={jobs}
            viewer={viewer}
        />,
    );

beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock);
    fetchMock.mockReset();
    mock.reload.mockReset();
    mock.visit.mockReset();
    mock.listeners.clear();
    document.cookie = 'XSRF-TOKEN=test-token';
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('Available shifts page actions', () => {
    it('uses the Available shifts name', () => {
        renderPage({ first_name: 'Sam' });
        expect(
            screen.getByRole('heading', { name: 'Available shifts' }),
        ).toBeVisible();
    });

    it('shows no claim, approve or post controls when the flags are missing', () => {
        renderPage({ first_name: 'Sam' });
        expect(screen.getByText('Morning support')).toBeVisible();
        expect(
            screen.queryByRole('button', { name: /Claim shift/ }),
        ).toBeNull();
        expect(screen.queryByRole('button', { name: /^Approve/ })).toBeNull();
        expect(
            screen.queryByRole('button', { name: /Post position/ }),
        ).toBeNull();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('treats approval and claiming as separate permissions', () => {
        renderPage({ first_name: 'Sam', can_approve: true });
        expect(
            screen.queryByRole('button', { name: /Claim shift/ }),
        ).toBeNull();
        expect(screen.getByRole('button', { name: /^Approve/ })).toBeVisible();
    });

    it('opens the posting wizard from Post position', async () => {
        fetchMock.mockResolvedValueOnce(
            response({
                data: [],
                page: 1,
                per_page: 20,
                has_more: false,
                workerTimezone: 'Pacific/Auckland',
            }),
        );
        renderPage({ first_name: 'Sam', can_post_position: true });
        fireEvent.click(
            screen.getAllByRole('button', { name: /Post position/ })[0],
        );
        expect(await screen.findByText('Choose a shift')).toBeVisible();
        expect(String(fetchMock.mock.calls[0][0])).toContain(
            '/operations/job-board/postable-shifts?',
        );
        expect(
            await screen.findByText(/No shifts match this search/),
        ).toBeVisible();
    });

    it('claims through the command endpoint and refreshes the current query once confirmed', async () => {
        const source = {
            actor_id: 18,
            action: 'claim',
            source_revision: 'a'.repeat(64),
            display: {
                position_id: 70,
                shift_id: 32,
                status: 'open',
                starts_at: '2026-10-20T20:00:00Z',
                ends_at: '2026-10-21T00:00:00Z',
                client: 'A. K.',
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
                warning_reasons: [],
                blocked_reasons: [],
                acknowledgement_required: false,
                reason_required: false,
            },
        };
        fetchMock
            .mockResolvedValueOnce(response(source))
            .mockImplementationOnce(async (_url, init) => {
                const body = JSON.parse(String(init?.body));
                return response({
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
                        committed_at: '2026-10-10T11:00:00Z',
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
                            starts_at: source.display.starts_at,
                            ends_at: source.display.ends_at,
                        },
                        preference: null,
                        eligibility: source.eligibility,
                    },
                });
            });
        renderPage({ first_name: 'Sam', can_claim: true });
        expect(mock.post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: /Claim shift/ }));
        expect(await screen.findByText('House or site')).toBeVisible();
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Claim shift' }).at(-1)!,
        );
        expect(await screen.findByText('Claim sent')).toBeVisible();
        expect(mock.reload).toHaveBeenCalledTimes(1);
        expect(mock.reload).toHaveBeenCalledWith({
            only: ['jobs', 'stats', 'viewer', 'provenance'],
        });
        expect(mock.post).not.toHaveBeenCalled();
    });

    it('asks once before leaving the page while a claim is unconfirmed', async () => {
        const source = {
            actor_id: 18,
            action: 'claim',
            source_revision: 'a'.repeat(64),
            display: {
                position_id: 70,
                shift_id: 32,
                status: 'open',
                starts_at: null,
                ends_at: null,
                client: 'A. K.',
                client_redacted: true,
                site: null,
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
                warning_reasons: [],
                blocked_reasons: [],
                acknowledgement_required: false,
                reason_required: false,
            },
        };
        fetchMock
            .mockResolvedValueOnce(response(source))
            .mockResolvedValueOnce(response({ result: null }, 202));
        renderPage({ first_name: 'Sam', can_claim: true });
        fireEvent.click(screen.getByRole('button', { name: /Claim shift/ }));
        await screen.findAllByRole('button', { name: 'Claim shift' });
        fireEvent.click(
            screen.getAllByRole('button', { name: 'Claim shift' }).at(-1)!,
        );
        expect(await screen.findByText('Not confirmed')).toBeVisible();
        await waitFor(() => expect(mock.listeners.size).toBe(1));

        const visit = (href: string, preserveState = true) => {
            const event = {
                detail: {
                    visit: {
                        url: new URL(href, window.location.origin),
                        method: 'get',
                        preserveState,
                    },
                },
                prevented: false,
                preventDefault() {
                    this.prevented = true;
                },
            };
            act(() => mock.listeners.forEach((listener) => listener(event)));
            return event.prevented;
        };
        // Staying on this page (a filter or refresh) is never blocked.
        expect(visit(`${window.location.pathname}?scope=mine`)).toBe(false);
        // Another page asks first.
        expect(visit('/dashboard', false)).toBe(true);
        expect(await screen.findByText('Leave this page?')).toBeVisible();
        expect(mock.visit).not.toHaveBeenCalled();
    });
});
