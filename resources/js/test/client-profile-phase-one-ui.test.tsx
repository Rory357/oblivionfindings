import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ActionsReviewsTab } from '@/pages/operations/clients/tabs/actions-reviews';
import {
    DailyNotesTab,
    type ClientDailyNote,
} from '@/pages/operations/clients/tabs/daily-notes';

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

const notes: ClientDailyNote[] = [
    {
        id: 1,
        type: 'daily_note',
        category: 'concern',
        subject: 'Needs review',
        body: 'Coordinator should review this note.',
        is_flagged: true,
        reviewed_at: null,
        created_at: '2026-05-22T09:00:00Z',
        author: { id: 1, name: 'Support Worker' },
    },
    {
        id: 2,
        type: 'daily_note',
        category: 'activity',
        subject: 'Community walk',
        body: 'Enjoyed a short walk.',
        is_flagged: false,
        reviewed_at: null,
        created_at: '2026-05-22T10:00:00Z',
        author: { id: 1, name: 'Support Worker' },
    },
    {
        id: 3,
        type: 'daily_note',
        category: 'health',
        subject: 'Draft health note',
        body: 'Draft note text.',
        is_draft: true,
        is_flagged: false,
        created_at: '2026-05-22T11:00:00Z',
        author: { id: 1, name: 'Support Worker' },
    },
];

describe('client profile phase-one tabs', () => {
    beforeEach(() => {
        window.history.replaceState(
            {},
            '',
            '/operations/clients/1?tab=progress_notes&flagged=1&reviewed=0',
        );
    });

    it('opens daily notes filtered to the review queue from query params and still surfaces drafts', () => {
        render(
            <DailyNotesTab
                clientId={1}
                notes={notes}
                summary={{ total: 3, flagged_open: 1, drafts: 1 }}
                canReview
                canUpdate
                onCreateDaily={vi.fn()}
                onCreateQuick={vi.fn()}
            />,
        );

        expect(screen.getAllByText('Needs review')[0]).toBeVisible();
        expect(screen.queryByText('Community walk')).not.toBeInTheDocument();
        expect(screen.getByText('My Drafts')).toBeVisible();
        expect(screen.getByText('Draft health note')).toBeVisible();
    });

    it('groups actions by due bucket before severity', () => {
        render(
            <ActionsReviewsTab
                summary={{ open: 3, critical: 1, warning: 1 }}
                items={[
                    {
                        type: 'overdue_follow_up',
                        severity: 'critical',
                        due_at: '2026-05-20T00:00:00Z',
                        summary: 'Call GP',
                        deep_link: '/operations/clients/1?tab=progress_notes',
                        source_id: 1,
                    },
                    {
                        type: 'care_plan_review_due',
                        severity: 'warning',
                        due_at: '2026-05-25T00:00:00Z',
                        summary: 'Care plan review',
                        deep_link: '/operations/clients/1?tab=care_plans',
                        source_id: 2,
                    },
                    {
                        type: 'document_expiring',
                        severity: 'info',
                        due_at: '2026-06-20T00:00:00Z',
                        summary: 'Medication authority expires',
                        deep_link: '/operations/clients/1?tab=documents',
                        source_id: 3,
                    },
                ]}
            />,
        );

        expect(screen.getByText('Overdue')).toBeVisible();
        expect(screen.getByText('Due this week')).toBeVisible();
        expect(screen.getByText('Upcoming')).toBeVisible();
        expect(screen.getByText('Call GP')).toBeVisible();
    });
});
