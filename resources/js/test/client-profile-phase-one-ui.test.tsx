import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { FamilyChatPopup } from '@/components/clients/profile/family-chat';
import { ActionsReviewsTab } from '@/pages/operations/clients/tabs/actions-reviews';
import { CareSupportPlanTab } from '@/pages/operations/clients/tabs/care-support-plan';
import { CommunicationNotesTab } from '@/pages/operations/clients/tabs/communication-notes';
import {
    DailyNotesTab,
    type ClientDailyNote,
} from '@/pages/operations/clients/tabs/daily-notes';
import { GoalsPathTab } from '@/pages/operations/clients/tabs/goals-path';
import { ClientTimelineTab } from '@/pages/operations/clients/tabs/timeline-tab';

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
    useForm: <T,>(initial: T) => ({
        data: initial,
        errors: {},
        processing: false,
        post: vi.fn(),
        reset: vi.fn(),
        setData: vi.fn(),
    }),
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

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
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

    it('uses record capabilities instead of broad note permissions for review actions', () => {
        render(
            <DailyNotesTab
                clientId={1}
                notes={[
                    {
                        ...notes[0],
                        can: {
                            update: false,
                            delete: false,
                            flag: false,
                            review: false,
                        },
                    },
                ]}
                summary={{ total: 1, flagged_open: 1, drafts: 0 }}
                canReview
                canUpdate
                onCreateDaily={vi.fn()}
                onCreateQuick={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Mark Reviewed' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Review' }),
        ).not.toBeInTheDocument();
    });

    it('offers the author an in-profile resume action for an editable draft', () => {
        const onEditNote = vi.fn();

        render(
            <DailyNotesTab
                clientId={1}
                notes={[
                    {
                        ...notes[2],
                        can: {
                            update: true,
                            delete: true,
                            flag: false,
                            review: false,
                        },
                    },
                ]}
                summary={{ total: 1, drafts: 1 }}
                onEditNote={onEditNote}
            />,
        );

        fireEvent.click(
            screen.getAllByRole('button', { name: 'Resume draft' })[0],
        );

        expect(onEditNote).toHaveBeenCalledWith(
            expect.objectContaining({ id: 3, is_draft: true }),
        );
    });

    it('offers an exact-capability edit action for a communication note', () => {
        const onEditNote = vi.fn();
        const note: ClientDailyNote = {
            id: 81,
            type: 'communication',
            category: 'communication',
            body: 'Spoke with the client’s sister.',
            can: { update: true, delete: false, flag: false, review: false },
        };

        render(
            <CommunicationNotesTab
                notes={[note]}
                familyNotes={[]}
                familyNotesOpenCount={0}
                onEditNote={onEditNote}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Edit note' }));
        expect(onEditNote).toHaveBeenCalledWith(note);
    });

    it('groups actions by due bucket before severity', () => {
        // The "due this week" / "upcoming" buckets are computed relative to the
        // current date, so pin the clock to keep the fixed due_at dates below in
        // their intended buckets (2026-05-25 = due this week, 2026-06-20 = upcoming).
        // Fake only Date so React/RTL timers stay real.
        vi.useFakeTimers({
            toFake: ['Date'],
            now: new Date('2026-05-22T12:00:00Z'),
        });

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

    it('reveals a long timeline source detail in profile without a page escape', () => {
        const fullDetail = `${'Detailed source context. '.repeat(20)}Final outcome.`;

        render(
            <ClientTimelineTab
                clientId={1}
                events={[
                    {
                        id: 91,
                        type: 'incident',
                        subject: 'Incident follow-up',
                        body: fullDetail,
                        occurred_at: '2026-07-12T08:00:00Z',
                    },
                ]}
                handover={[]}
                canCreateNote={false}
                canPinHandover={false}
            />,
        );

        expect(screen.queryByText('Final outcome.')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Show full detail' }),
        );
        expect(screen.getByText(fullDetail)).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Hide detail' }),
        ).toBeVisible();
        expect(screen.queryByRole('link', { name: /view source/i })).toBeNull();
    });

    it('keeps goal management and PATH editing independently gated', () => {
        const props = {
            clientId: 1,
            clientName: 'Tane Rangi',
            goals: [],
            canManageGoals: false,
            canEditPath: true,
            onAddGoal: vi.fn(),
            onManageGoal: vi.fn(),
            onEditPlan: vi.fn(),
        };
        const { rerender } = render(<GoalsPathTab {...props} />);

        expect(
            screen.queryByRole('button', { name: 'Add goal' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Edit planning' }),
        ).toBeVisible();

        rerender(
            <GoalsPathTab {...props} canManageGoals canEditPath={false} />,
        );

        expect(screen.getByRole('button', { name: 'Add goal' })).toBeVisible();
        expect(
            screen.queryByRole('button', { name: 'Edit planning' }),
        ).not.toBeInTheDocument();
    });

    it('discloses bounded daily communication timeline care-plan and action collections', () => {
        const { rerender } = render(
            <DailyNotesTab
                clientId={1}
                notes={notes}
                summary={{ total: 73, loaded: 3, has_more: true }}
            />,
        );
        expect(
            screen.getByText('Showing the latest 3 of 73 daily notes.'),
        ).toBeVisible();

        rerender(
            <CommunicationNotesTab
                notes={[notes[0]]}
                familyNotes={[]}
                familyNotesOpenCount={0}
                coverage={{ total: 61, loaded: 1, has_more: true }}
            />,
        );
        expect(screen.getByText('61')).toBeVisible();
        expect(
            screen.getByText('Showing the latest 1 of 61 communication notes.'),
        ).toBeVisible();

        rerender(
            <ClientTimelineTab
                clientId={1}
                events={[]}
                handover={[]}
                summary={{
                    total: 101,
                    loaded: 80,
                    has_more: true,
                    pinned_handover_total: 8,
                    pinned_handover_loaded: 5,
                    pinned_handover_has_more: true,
                }}
                canCreateNote={false}
                canPinHandover={false}
            />,
        );
        expect(
            screen.getByText('Showing the latest 80 of 101 timeline events.'),
        ).toBeVisible();
        expect(
            screen.getByText('Showing 5 of 8 pinned handover items.'),
        ).toBeVisible();

        rerender(
            <CareSupportPlanTab
                client={{ id: 1, first_name: 'Tane' }}
                summary={{
                    active_plan: {
                        id: 2,
                        title: 'Current plan',
                        status: 'active',
                        goals: [],
                        sign_offs: [],
                    },
                    versions: [
                        {
                            id: 1,
                            title: 'Plan version',
                            status: 'archived',
                            version: 1,
                        },
                    ],
                    versions_total: 32,
                    versions_loaded: 30,
                    versions_has_more: true,
                }}
                canEdit={false}
                canCreate={false}
                onCreatePlan={vi.fn()}
                onEditPlan={vi.fn()}
                onGoToGoals={vi.fn()}
            />,
        );
        expect(
            screen.getByText('Showing the latest 30 of 32 plan versions.'),
        ).toBeVisible();

        rerender(
            <ActionsReviewsTab
                summary={{
                    open: 20,
                    loaded: 20,
                    has_more: true,
                    critical: 2,
                    warning: 5,
                }}
                items={[]}
            />,
        );
        expect(screen.getByText('20+')).toBeVisible();
        expect(screen.getByText('Open actions shown')).toBeVisible();
        expect(screen.getByText(/Additional open actions exist/)).toBeVisible();
    });

    it('keeps family chat read-only while disclosing its recent-message window', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    conversation: {
                        id: 5,
                        title: 'Whānau chat',
                        participants: [],
                    },
                    messages: [],
                    meta: { total: 125, loaded: 100, has_more: true },
                    portal_users: [],
                }),
            }),
        );

        render(
            <FamilyChatPopup
                open
                onClose={vi.fn()}
                clientId={1}
                clientName="Tane"
                canSend={false}
            />,
        );

        expect(
            await screen.findByText('Showing the latest 100 of 125 messages.'),
        ).toBeVisible();
        expect(
            screen.getByText('This conversation is read-only for your role.'),
        ).toBeVisible();
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        expect(screen.queryByPlaceholderText('Type a message')).toBeNull();
    });
});
