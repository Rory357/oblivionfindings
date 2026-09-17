import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    routerPost: vi.fn(),
    formPost: vi.fn(),
    canVote: true,
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        router: { post: inertia.routerPost, replace: vi.fn(), visit: vi.fn() },
        usePage: () => ({
            props: {
                auth: {
                    can: {
                        governance: {
                            resolutions: { vote: inertia.canVote },
                        },
                    },
                },
                flash: {},
            },
            url: '/governance/meetings/12',
        }),
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string;
            children: ReactNode;
        }) => (
            <a href={href} {...rest}>
                {children}
            </a>
        ),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setDataState] = ReactActual.useState(initial);
            const transformRef = ReactActual.useRef<
                ((d: Record<string, unknown>) => Record<string, unknown>) | null
            >(null);
            return {
                data,
                errors: {},
                processing: false,
                setData: (key: string, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: (
                    callback: (
                        d: Record<string, unknown>,
                    ) => Record<string, unknown>,
                ) => {
                    transformRef.current = callback;
                },
                post: (
                    url: string,
                    options?: { onSuccess?: (page: unknown) => void },
                ) => {
                    inertia.formPost(
                        url,
                        transformRef.current
                            ? transformRef.current(data)
                            : data,
                    );
                    options?.onSuccess?.({ props: { flash: {} } });
                },
            };
        },
    };
});

import {
    MeetingPaperWorkspace,
    type PaperResolution,
} from './MeetingPaperWorkspace';

const baseResolution: PaperResolution = {
    id: 40,
    resolution_reference: 'RES-2026-040',
    title: 'Approve the 2027 budget',
    status: 'closed',
    outcome: 'carried',
    purpose: 'decision',
    voting_threshold: 'simple_majority',
    version_number: 2,
    my_vote: {
        id: 1,
        vote: 'for',
        conflict_declared: false,
        voted_at: '2026-09-10T02:30:00Z',
    },
    action_items: [
        {
            id: 7,
            reference: 'ACT-7',
            title: 'Publish the approved budget',
            status: 'open',
            due_label: '1 Oct 2026',
            assignee_name: 'Aroha Ngata',
            is_mine: true,
            can_open: true,
            open_url: '/governance/actions/7',
        },
        {
            id: 8,
            reference: 'ACT-8',
            title: 'Brief the finance committee',
            status: 'open',
            assignee_name: 'Ben Carter',
            is_mine: false,
            can_open: false,
            open_url: null,
        },
    ],
    restricted_action_items_count: 0,
};

const openResolution: PaperResolution = {
    ...baseResolution,
    status: 'open',
    outcome: null,
    deadline: '2099-01-01T04:00:00Z',
    can_vote: true,
    my_vote: null,
    action_items: [],
};

beforeEach(() => {
    inertia.routerPost.mockReset();
    inertia.formPost.mockReset();
    inertia.canVote = true;
});

afterEach(cleanup);

describe('MeetingPaperWorkspace', () => {
    it('opens permitted follow-up actions with a return to the same resolution', () => {
        render(
            <MeetingPaperWorkspace
                resolution={baseResolution}
                meetingId={12}
                meetingTitle="September board"
                onClose={() => {}}
            />,
        );

        const links = Array.from(
            document.querySelectorAll('[data-test="paper-follow-up-action-open"]'),
        );
        // Only the action the viewer may open renders a link.
        expect(links).toHaveLength(1);

        const href = links[0].getAttribute('href') ?? '';
        const [path, query] = href.split('?');
        expect(path).toBe('/governance/actions/7');
        expect(new URLSearchParams(query).get('return')).toBe(
            '/governance/meetings/12?tab=resolutions&paper=40&focus=follow-ups',
        );
        expect(links[0].textContent).toContain('Update');
    });

    it('shows the vote receipt with the NZ time, resolution version and receipt reference — and no overclaims', () => {
        render(
            <MeetingPaperWorkspace
                resolution={baseResolution}
                meetingId={12}
                onClose={() => {}}
            />,
        );

        const receipt = document.querySelector(
            '[data-test="paper-vote-receipt-time"]',
        ) as HTMLElement;
        // 02:30 UTC on 10 Sep 2026 is 2:30 pm NZST.
        expect(receipt.textContent).toContain('10 September 2026, 2:30 pm');
        expect(receipt.textContent).not.toMatch(/\d{1,2}\/\d{1,2}\/\d{2,4}/);
        expect(receipt.querySelector('time')?.getAttribute('dateTime')).toBe(
            '2026-09-10T02:30:00Z',
        );
        expect(receipt.textContent).toContain('Resolution version 2');
        expect(receipt.textContent).toContain('Receipt VOTE-RCP-1');

        const text = document.body.textContent ?? '';
        expect(text).not.toMatch(/tamper-proof|cryptographic|immutable|conflict noted/i);
    });

    it('asks for confirmation before recording a vote and sends the reason as a vote note, never a conflict', () => {
        render(
            <MeetingPaperWorkspace
                resolution={openResolution}
                meetingId={12}
                onClose={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('radio', { name: 'For' }));
        fireEvent.change(
            screen.getByLabelText('Reason for your vote (optional)'),
            { target: { value: 'The costs are in this year’s budget.' } },
        );
        fireEvent.click(
            document.querySelector('[data-test="ballot-cast"]') as HTMLElement,
        );

        // Nothing is recorded until the member confirms.
        expect(inertia.routerPost).not.toHaveBeenCalled();
        const confirm = screen.getByRole('alertdialog');
        expect(confirm.textContent).toContain(
            'You\'re voting For on "Approve the 2027 budget". You can\'t change your vote once it\'s recorded.',
        );

        fireEvent.click(within(confirm).getByRole('button', { name: 'Cast vote' }));

        expect(inertia.routerPost).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.routerPost.mock.calls[0];
        expect(url).toBe('/governance/resolutions/40/vote');
        expect(payload).toEqual({
            vote: 'for',
            vote_note: 'The costs are in this year’s budget.',
        });
        expect(payload).not.toHaveProperty('conflict_note');
    });

    it('explains how the resolution passes next to the ballot', () => {
        render(
            <MeetingPaperWorkspace
                resolution={{ ...openResolution, voting_threshold: 'unanimous' }}
                meetingId={12}
                onClose={() => {}}
            />,
        );

        expect(
            screen.getByText(/It passes only if every voting member votes For/),
        ).toBeTruthy();
        expect(
            screen.getByText(/this resolution needs everyone's For vote, so it can't pass/i),
        ).toBeTruthy();
    });

    it('lets a member declare a conflict on a draft, posting exactly the fields the server validates', () => {
        render(
            <MeetingPaperWorkspace
                resolution={{
                    ...openResolution,
                    status: 'draft',
                    deadline: null,
                    can_vote: false,
                }}
                meetingId={12}
                onClose={() => {}}
            />,
        );

        // Voting isn't possible yet, and the page says why.
        expect(
            screen.getByText(
                "Voting hasn't opened yet — the chair or secretary opens it at the meeting.",
            ),
        ).toBeTruthy();

        fireEvent.click(
            document.querySelector(
                '[data-test="ballot-declare-conflict"]',
            ) as HTMLElement,
        );

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText('Tell the board about a conflict of interest'),
        ).toBeTruthy();
        expect(dialog.textContent).toContain(
            "You still count towards the board's size",
        );

        fireEvent.click(
            within(dialog).getByRole('button', {
                name: /link to a person or organisation involved/i,
            }),
        );
        fireEvent.change(
            within(dialog).getByPlaceholderText(/my sister is a director/i),
            {
                target: {
                    value: 'My sister is a director of the company quoting for this work.',
                },
            },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Declare conflict' }),
        );

        expect(inertia.formPost).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.formPost.mock.calls[0];
        expect(url).toBe('/governance/resolutions/40/conflict');
        expect(payload).toEqual({
            type: 'related',
            description:
                'My sister is a director of the company quoting for this work.',
            withdraw_from_voting: true,
            withdraw_from_discussion: false,
        });
        expect(payload).not.toHaveProperty('declaration_type');
    });

    it('warns that stepping aside means a unanimous resolution cannot pass', () => {
        render(
            <MeetingPaperWorkspace
                resolution={{ ...openResolution, voting_threshold: 'unanimous' }}
                meetingId={12}
                onClose={() => {}}
            />,
        );

        fireEvent.click(
            document.querySelector(
                '[data-test="ballot-declare-conflict"]',
            ) as HTMLElement,
        );

        expect(screen.getByRole('dialog').textContent).toContain(
            'stepping aside means it cannot pass',
        );
    });

    it('offers the next resolution inside the vote receipt', () => {
        const onOpenPaper = vi.fn();
        render(
            <MeetingPaperWorkspace
                resolution={{
                    ...openResolution,
                    my_vote: {
                        id: 5,
                        vote: 'against',
                        voted_at: '2026-09-10T02:30:00Z',
                    },
                }}
                meetingId={12}
                onClose={() => {}}
                nextPaper={{
                    id: 41,
                    title: 'Renew the office lease',
                    resolution_reference: 'RES-2026-041',
                }}
                onOpenPaper={onOpenPaper}
            />,
        );

        const receipt = document.querySelector(
            '[data-test="vote-receipt"]',
        ) as HTMLElement;
        fireEvent.click(
            within(receipt).getByRole('button', {
                name: /next resolution: renew the office lease/i,
            }),
        );

        expect(onOpenPaper).toHaveBeenCalledWith(41);
        expect(
            within(receipt).getByRole('button', { name: 'Back to resolutions' }),
        ).toBeTruthy();
    });

    it('says papers for information are not voted on, and shows no ballot', () => {
        render(
            <MeetingPaperWorkspace
                resolution={{
                    ...openResolution,
                    purpose: 'information',
                    status: 'proposed',
                    can_vote: false,
                }}
                meetingId={12}
                onClose={() => {}}
            />,
        );

        expect(
            screen.getByText(
                "This paper is for information — the board reads it but doesn't vote on it.",
            ),
        ).toBeTruthy();
        expect(document.querySelector('[data-test="ballot-cast"]')).toBeNull();
    });

    it('does not offer a conflict declaration to viewers who cannot vote', () => {
        inertia.canVote = false;
        render(
            <MeetingPaperWorkspace
                resolution={{ ...openResolution, can_vote: false }}
                meetingId={12}
                onClose={() => {}}
            />,
        );

        expect(
            document.querySelector('[data-test="ballot-declare-conflict"]'),
        ).toBeNull();
    });
});
