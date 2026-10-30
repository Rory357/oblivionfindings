import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';

import JobBoardHero from './job-board-hero';
import JobCard from './job-card';
import { SmartMatchesStrip } from './smart-matches-strip';
import type { JobBoardStats, JobBoardWeek, JobPost } from './types';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        post: vi.fn(),
    },
}));

const stats: JobBoardStats = {
    open: 6,
    claimed: 3,
    filled_today: 1,
    eligible_for_you: 4,
    expiring_soon: 0,
    mine: 0,
    replacements: 0,
    pending_approval: 2,
    sites: 2,
    sites_worked_this_week: 1,
};

const week: JobBoardWeek = {
    start: '2026-05-25',
    end: '2026-05-31',
    start_label: '25 May',
    end_label: '31 May',
    prev: '2026-05-18',
    next: '2026-06-01',
    is_current: true,
};

function job(overrides: Partial<JobPost> = {}): JobPost {
    return {
        id: 51,
        title: 'Support shift for Ari Kauri',
        status: 'claimed',
        date: '2026-05-28',
        start_time: '09:00',
        end_time: '13:00',
        location: 'Matai House',
        required_skills: [],
        your_skills: [],
        coverage_roles: [],
        coverage: null,
        privacy: { can_view_sensitive_details: true },
        client: {
            id: 9,
            first_name: 'Ari',
            last_name: 'Kauri',
            display_name: 'Ari Kauri',
            suburb: 'Hamilton',
            is_redacted: false,
        },
        replacement: null,
        claimed_by: null,
        eligibility: null,
        viewer_eligibility: null,
        your_schedule: null,
        tasks: [],
        tasks_total: 0,
        past_shifts_here: 0,
        site_familiar: false,
        ...overrides,
    };
}

describe('job board audit fixes', () => {
    it('keeps the new header filters and scoped meters connected without asserting unknown compliance', () => {
        const change = vi.fn(),
            scope = vi.fn(),
            weekChange = vi.fn();
        render(
            <JobBoardHero
                week={week}
                stats={stats}
                availableSkills={['all', 'Hoist']}
                filters={{ skill: 'all' }}
                onFilterChange={change}
                onWeekChange={weekChange}
                onScopeChange={scope}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Available shifts' }),
        ).toBeVisible();
        expect(
            screen.queryByText(/Your compliance is current|refreshed just now/),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'View pending' }),
        ).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'View my claims' }));
        expect(scope).toHaveBeenCalledWith('mine');
        fireEvent.click(screen.getByRole('button', { name: 'Previous week' }));
        expect(weekChange).toHaveBeenCalledWith(week.prev);
        fireEvent.click(
            screen.getByRole('button', { name: 'Clear All skills' }),
        );
        expect(change).toHaveBeenCalledWith('skill', null);
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'North' },
        });
        expect(change).toHaveBeenCalledWith('q', 'North');
        expect(
            document.querySelector('[data-test="job-board-skill-filter"]'),
        ).toHaveTextContent('all');
    });

    it('uses pending approval count for the Pending hero stat', () => {
        render(
            <JobBoardHero
                canApprove
                firstName="Sheila"
                week={week}
                stats={stats}
                availableSkills={[]}
                filters={{}}
                onFilterChange={vi.fn()}
                onWeekChange={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('link', { name: 'View pending' }),
        ).toHaveTextContent('2');
    });

    it('shows the combined week number and date range in the picker button', () => {
        render(
            <JobBoardHero
                firstName="Sheila"
                week={week}
                stats={stats}
                availableSkills={[]}
                filters={{}}
                onFilterChange={vi.fn()}
                onWeekChange={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('button', {
                name: /Wk 22 · 25 May → 31 May · pick week/i,
            }),
        ).toBeVisible();
    });

    it('does not render a bare dash for a missing viewer schedule', () => {
        render(<JobCard job={job()} />);

        expect(screen.getByText('Your schedule')).toBeVisible();
        expect(screen.getByText('Schedule unknown')).toBeVisible();
        expect(screen.queryByText('—')).not.toBeInTheDocument();
    });
});

describe('action controls are permission-gated (default deny)', () => {
    const open = job({
        status: 'open',
        viewer_eligibility: {
            is_eligible: true,
            blocked_reasons: [],
            warning_count: 0,
            first_warning: null,
        },
        your_schedule: {
            conflict: null,
            time_off: null,
            fatigue: null,
            free: true,
        },
    });

    it('shows no Claim or Approve button without a handler, and never posts by itself', () => {
        const { rerender } = render(<JobCard job={open} />);
        expect(
            screen.queryByRole('button', { name: /Claim shift/ }),
        ).toBeNull();
        rerender(<JobCard job={job({ status: 'claimed' })} />);
        expect(screen.queryByRole('button', { name: /Approve/ })).toBeNull();
    });

    it('hands the card to the page owner when the viewer may act', () => {
        const claim = vi.fn();
        const approve = vi.fn();
        const { rerender } = render(<JobCard job={open} onClaim={claim} />);
        fireEvent.click(screen.getByRole('button', { name: /Claim shift/ }));
        expect(claim).toHaveBeenCalledWith(open);
        const claimed = job({ status: 'claimed' });
        rerender(<JobCard job={claimed} onApprove={approve} />);
        fireEvent.click(screen.getByRole('button', { name: /Approve/ }));
        expect(approve).toHaveBeenCalledWith(claimed);
    });

    it('keeps an ineligible claim disabled with the reason visible', () => {
        const claim = vi.fn();
        render(
            <JobCard
                job={job({
                    status: 'open',
                    viewer_eligibility: {
                        is_eligible: false,
                        blocked_reasons: ['Hoist training is out of date.'],
                        warning_count: 0,
                        first_warning: null,
                    },
                })}
                onClaim={claim}
            />,
        );
        const button = screen.getByRole('button', { name: /Not eligible/ });
        expect(button).toBeDisabled();
        expect(
            screen.getByText('Hoist training is out of date.'),
        ).toBeVisible();
        expect(claim).not.toHaveBeenCalled();
    });

    it('shows smart matches for information only when the viewer cannot claim', () => {
        const { rerender } = render(
            <SmartMatchesStrip jobs={[open]} totalMatches={1} />,
        );
        expect(screen.getByText('Smart matches')).toBeVisible();
        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.queryByText('Quick claim')).toBeNull();
        const claim = vi.fn();
        rerender(
            <SmartMatchesStrip
                jobs={[open]}
                totalMatches={1}
                onQuickClaim={claim}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Quick claim/ }));
        expect(claim).toHaveBeenCalledWith(open);
    });
});

describe('replacement cover privacy', () => {
    it('says only that cover is needed, never why the rostered person is away', () => {
        render(
            <JobCard
                job={job({
                    status: 'open',
                    // Even a stale payload that still carried a reason must not show it.
                    replacement: {
                        id: 5,
                        status: 'requested',
                        reason: 'Sick leave',
                    } as unknown as JobPost['replacement'],
                })}
            />,
        );
        expect(
            document.querySelector('[data-test="job-board-replacement-cover"]'),
        ).toHaveTextContent(/^Replacement cover$/);
        expect(screen.queryByText(/Sick leave/)).toBeNull();
    });
});
