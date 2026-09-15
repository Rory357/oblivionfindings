import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        replace: vi.fn(),
        visit: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => ({ url: '/governance/meetings/12', props: { flash: {} } }),
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        processing: false,
        isDirty: false,
        setData: vi.fn(),
        transform: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

vi.mock('../Resolutions/_dialogs', () => ({
    NewResolutionDialog: () => null,
}));

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

import MeetingShow from './Show';

type ShowProps = ComponentProps<typeof MeetingShow>;

const DAY = 86_400_000;

function meeting(overrides: Record<string, unknown> = {}) {
    return {
        id: 12,
        title: 'October board meeting',
        meeting_type: 'full_board',
        scheduled_at: new Date(Date.now() + 5 * DAY).toISOString(),
        duration_minutes: 120,
        location: 'Board room',
        virtual_link: null,
        notes: null,
        status: 'scheduled',
        quorum_met: false,
        quorum_required: 50,
        chair: { id: 1, user: { name: 'Aroha Ngata' } },
        secretary: null,
        agenda_items: [
            {
                id: 1,
                order: 1,
                title: 'Budget for 2026/27',
                description: null,
                presenter: null,
                duration_minutes: 20,
                item_type: 'decision',
                is_confidential: false,
                resolution_id: 40,
            },
            {
                id: 2,
                order: 2,
                title: 'Complaints policy',
                description: null,
                presenter: null,
                duration_minutes: 15,
                item_type: 'decision',
                is_confidential: false,
                resolution_id: 41,
            },
        ],
        attendances: [],
        rsvps: [],
        minutes: null,
        board_pack: null,
        ...overrides,
    };
}

const resolutions = [
    { id: 41, resolution_reference: 'RES-41', title: 'Adopt the complaints policy', status: 'draft', can_vote: true },
    { id: 40, resolution_reference: 'RES-40', title: 'Approve the budget', status: 'open', can_vote: true, my_vote: null },
];

function props(overrides: Partial<Record<string, unknown>> = {}): ShowProps {
    return {
        auth: {
            user: { id: 5, name: 'Board Member' },
            can: { governance: { resolutions: { view: true, vote: true } } },
        },
        meeting: meeting(),
        boardMembers: [],
        quorum: { present: 0, required: 2, total: 3, met: false },
        canEdit: false,
        canManageMinutes: false,
        canApproveMinutes: false,
        canSignMinutes: false,
        workflowChecklist: { counts: { done: 0, remaining: 0, blocked: 0 }, next_step: null, items: [] },
        meetingCockpit: { cards: [] },
        packReading: null,
        viewerCanRsvp: true,
        viewerRsvp: null,
        resolutions,
        ...overrides,
    } as unknown as ShowProps;
}

const meterLabels = () =>
    Array.from(document.querySelectorAll('.eh-meter-label')).map((el) => el.textContent);

afterEach(() => cleanup());

describe('Meeting workspace — a board member', () => {
    it('shows their own preparation, the tabs in the order they use them, and no admin readiness', () => {
        render(<MeetingShow {...props()} />);

        expect(meterLabels()).toEqual(['Board pack', 'Your votes', 'Conflicts', 'Attendance']);
        expect(document.querySelector('[dusk="meeting-status-strip"]')).toBeNull();
        expect(screen.queryByText(/previous follow-through/i)).toBeNull();
        expect(screen.queryByText(/ceo report/i)).toBeNull();

        const tabs = screen.getAllByRole('tab').map((tab) => tab.textContent ?? '');
        expect(tabs).toHaveLength(4);
        expect(tabs[0]).toMatch(/^Agenda/);
        expect(tabs[1]).toMatch(/^Resolutions/);
        expect(tabs[2]).toMatch(/^Attendance/);
        expect(tabs[3]).toMatch(/^Minutes/);

        expect(screen.getByText('Will you be at this meeting?')).toBeTruthy();
        expect(screen.queryByText(/generate draft pack/i)).toBeNull();
    });

    it('only invites a vote where the member can vote now', () => {
        render(<MeetingShow {...props()} />);

        const buttons = Array.from(
            document.querySelectorAll('[data-test="agenda-open-resolution"]'),
        ).map((button) => button.textContent);
        expect(buttons).toEqual(['Read resolution & vote', 'Read resolution']);
    });

    it('lists resolutions in agenda order with one button each', () => {
        render(<MeetingShow {...props()} />);
        fireEvent.click(screen.getByRole('tab', { name: /resolutions/i }));

        const rows = Array.from(document.querySelectorAll('[data-test="meeting-paper-row"]'));
        expect(rows.map((row) => row.querySelector('p')?.textContent)).toEqual([
            'Approve the budget',
            'Adopt the complaints policy',
        ]);
        expect(rows[0].querySelectorAll('button')).toHaveLength(1);
        expect(screen.queryByText('Full record')).toBeNull();
        expect(screen.getByText('Open for your vote · Ref RES-40')).toBeTruthy();
    });
});

describe('Meeting workspace — the people running a meeting that has happened', () => {
    it('asks for attendance and minutes instead of offering to prepare the board pack', () => {
        render(
            <MeetingShow
                {...props({
                    auth: { user: { id: 1, name: 'Chair' }, can: { governance: { resolutions: { view: true, manage: true } } } },
                    meeting: meeting({ scheduled_at: new Date(Date.now() - 2 * DAY).toISOString() }),
                    boardMembers: [{ id: 1, user: { id: 7, name: 'Aroha Ngata' } }],
                    canEdit: true,
                    canManageMinutes: true,
                    canApproveMinutes: true,
                    canSignMinutes: true,
                    viewerCanRsvp: false,
                    workflowChecklist: {
                        counts: { done: 1, remaining: 1, blocked: 1 },
                        next_step: {
                            key: 'quorum',
                            label: 'Attendance recorded',
                            status: 'todo',
                            status_label: 'To do',
                            detail: "Attendance hasn't been recorded yet. It's recorded at the meeting.",
                            action_label: 'Record attendance',
                            action_url: '/governance/meetings/12?tab=attendance',
                            blocked_by: null,
                        },
                        items: [
                            {
                                key: 'agenda',
                                label: 'Agenda prepared',
                                status: 'done',
                                status_label: 'Done',
                                detail: '2 agenda items are ready.',
                                action_label: 'Open agenda',
                                action_url: '/governance/meetings/12?tab=agenda',
                                blocked_by: null,
                            },
                            {
                                key: 'minutes_approved',
                                label: 'Minutes approved',
                                status: 'blocked',
                                status_label: 'Waiting on an earlier step',
                                detail: 'Send the draft minutes to the chair for approval.',
                                action_label: 'Review minutes',
                                action_url: '/governance/meetings/12?tab=minutes',
                                blocked_by: "The minutes haven't been written",
                            },
                        ],
                    },
                    meetingCockpit: {
                        cards: [
                            {
                                key: 'ceo_report',
                                title: 'CEO report',
                                status: 'done',
                                value: 'Submitted',
                                detail: 'The CEO report has been submitted.',
                                href: '/governance/ceo-reports/3',
                            },
                        ],
                    },
                })}
            />,
        );

        expect(
            screen.getByText('This meeting has happened — record attendance and write the minutes'),
        ).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Record attendance' })).toBeTruthy();
        expect(screen.queryByText(/generate draft pack/i)).toBeNull();
        expect(meterLabels()).toEqual(['Workflow', 'Agenda', 'Resolutions', 'Replies', 'Quorum']);

        fireEvent.click(screen.getByRole('tab', { name: /workflow/i }));
        expect(screen.getByText('At a glance')).toBeTruthy();
        expect(screen.getByText('Submitted')).toBeTruthy();
        expect(screen.getByText('Waiting on an earlier step')).toBeTruthy();
        expect(screen.getByText("Waiting because the minutes haven't been written.")).toBeTruthy();
    });
});
