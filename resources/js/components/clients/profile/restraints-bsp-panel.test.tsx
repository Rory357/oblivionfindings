import { act, cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import {
    RestraintsBspPanel,
    RestraintsBspSummary,
    type RestraintSummary,
} from './restraints-bsp-panel';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
function summary(title = 'Recorded plan'): RestraintSummary {
    return {
        active_plan: {
            id: 8,
            reference: 'BSP-8',
            title,
            status: 'active',
            review_date: null,
            review_state: 'unknown',
            published_version_id: null,
            authorization_state: 'unknown',
        },
        total_events: 1,
        recent_events: [
            {
                id: 7,
                reference: 'RE-7',
                restraint_type: 'physical',
                severity: 'high',
                started_at: '2026-10-11T23:45:07Z',
                within_support_plan: false,
                injury_occurred: true,
                reviewed_at: '2026-10-11T23:50:00Z',
                source_revision: 5,
                needs_review: true,
            },
        ],
    };
}
it('opens dedicated source records with the person or stay return context and cautious approval labels', () => {
    render(
        <RestraintsBspSummary
            summary={summary()}
            clientId={12}
            origin="/respite/stays/21"
        />,
    );
    const plan = screen.getByRole('link', { name: /Recorded plan/ });
    expect(plan).toHaveAttribute(
        'href',
        '/health-safety/restraints/plans/8?return_to=%2Frespite%2Fstays%2F21',
    );
    const event = screen.getByRole('link', { name: /RE-7/ });
    expect(event).toHaveAttribute(
        'href',
        '/health-safety/restraints/events/7?return_to=%2Frespite%2Fstays%2F21',
    );
    expect(
        screen.getByText('Approval has not been established here.'),
    ).toBeVisible();
    expect(screen.getByText('Review date unknown')).toBeVisible();
    expect(screen.getByText('Current record needs review')).toBeVisible();
    expect(screen.getByText('Injury')).toBeVisible();
    expect(screen.getByText('Outside plan')).toBeVisible();
    expect(plan.getAttribute('href')).not.toContain('?plan=');
    expect(event.getAttribute('href')).not.toContain('?event=');
});
it('states only the missing recorded plan rather than granting or directing intervention', () => {
    render(
        <RestraintsBspSummary
            summary={{ ...summary(), active_plan: null }}
            clientId={12}
            origin="/operations/clients/12?tab=observations"
        />,
    );
    expect(
        screen.getByText('No active behaviour-support plan is recorded here.'),
    ).toBeVisible();
    expect(screen.getByRole('link', { name: /RE-7/ })).toHaveAttribute(
        'href',
        '/health-safety/restraints/events/7?return_to=%2Foperations%2Fclients%2F12%3Ftab%3Dobservations',
    );
});
it('immediately hides the previous person’s loaded summary and keeps it hidden after a current denial', async () => {
    let deny!: (value: Response) => void;
    const fetch = vi
        .fn()
        .mockResolvedValueOnce({
            ok: true,
            json: async () => summary('First person private plan'),
        })
        .mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    deny = resolve;
                }),
        );
    vi.stubGlobal('fetch', fetch);
    const view = render(<RestraintsBspPanel clientId={12} />);
    await act(async () => {
        await Promise.resolve();
    });
    expect(screen.getByText('First person private plan')).toBeVisible();
    view.rerender(<RestraintsBspPanel clientId={13} />);
    expect(
        screen.queryByText('First person private plan'),
    ).not.toBeInTheDocument();
    await act(async () => {
        deny({ ok: false } as Response);
    });
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
    expect(fetch).toHaveBeenLastCalledWith(
        '/health-safety/restraints/clients/13/summary',
        { headers: { Accept: 'application/json' } },
    );
});
it('does not replace the current person with an old summary response arriving late', async () => {
    let old!: (value: unknown) => void;
    const fetch = vi
        .fn()
        .mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    old = resolve;
                }),
        )
        .mockResolvedValueOnce({
            ok: true,
            json: async () => summary('Current person plan'),
        });
    vi.stubGlobal('fetch', fetch);
    const view = render(<RestraintsBspPanel clientId={12} />);
    view.rerender(<RestraintsBspPanel clientId={13} />);
    await act(async () => {
        await Promise.resolve();
    });
    expect(screen.getByText('Current person plan')).toBeVisible();
    await act(async () => {
        old({ ok: true, json: async () => summary('Late private plan') });
    });
    expect(screen.queryByText('Late private plan')).not.toBeInTheDocument();
    expect(screen.getByText('Current person plan')).toBeVisible();
});
