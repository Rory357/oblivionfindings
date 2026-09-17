import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    flash: {} as Record<string, string>,
    createdId: null as number | null,
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        router: { visit: vi.fn(), reload: vi.fn(), replace: vi.fn() },
        Link: ({ href, children }: { href: string; children: ReactNode }) => (
            <a href={href}>{children}</a>
        ),
        usePage: () => ({
            props: { flash: {} },
            url: '/governance/resolutions',
        }),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setDataState] = ReactActual.useState(initial);
            const transformRef = ReactActual.useRef<
                ((d: Record<string, unknown>) => Record<string, unknown>) | null
            >(null);
            const send =
                (spy: typeof inertia.post) =>
                (
                    url: string,
                    options?: { onSuccess?: (page: unknown) => void },
                ) => {
                    spy(
                        url,
                        transformRef.current
                            ? transformRef.current(data)
                            : data,
                    );
                    options?.onSuccess?.({
                        props: {
                            flash: inertia.flash,
                            created_resolution_id: inertia.createdId,
                        },
                    });
                };
            return {
                data,
                errors: {},
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (
                    keyOrUpdater:
                        | string
                        | Record<string, unknown>
                        | ((
                              prev: Record<string, unknown>,
                          ) => Record<string, unknown>),
                    value?: unknown,
                ) =>
                    setDataState((current) =>
                        typeof keyOrUpdater === 'function'
                            ? keyOrUpdater(current)
                            : typeof keyOrUpdater === 'string'
                              ? { ...current, [keyOrUpdater]: value }
                              : keyOrUpdater,
                    ),
                transform: (
                    callback: (
                        d: Record<string, unknown>,
                    ) => Record<string, unknown>,
                ) => {
                    transformRef.current = callback;
                },
                post: send(inertia.post),
                put: send(inertia.put),
                clearErrors: vi.fn(),
                reset: vi.fn(),
            };
        },
    };
});

import { formatWallTime, ResolutionWizardDialog } from './_dialogs';

const meetings = [
    {
        id: 5,
        title: 'October board meeting',
        scheduled_at: '2026-10-14T01:00:00Z',
    },
];

const railStep = (blurb: RegExp) =>
    fireEvent.click(screen.getByRole('button', { name: blurb }));

const goToReview = () => railStep(/check and save/i);

describe('ResolutionWizardDialog', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.put.mockReset();
        inertia.flash = {};
        inertia.createdId = null;
    });
    afterEach(() => cleanup());

    it('requires a title before continuing', () => {
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /continue/i }));

        expect(screen.getByText('Give the resolution a title.')).toBeTruthy();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('saves a draft from the register, staying in context, with the preselected meeting', () => {
        inertia.flash = { success: 'Resolution created as a draft.' };
        inertia.createdId = 77;
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
                meetingId="5"
                authoritySubjects={{
                    budgets: [{ id: 9, label: 'Care budget — 2026/27' }],
                }}
                authoritySubjectGroups={[
                    {
                        key: 'budgets',
                        subject_type: 'budget',
                        label: 'A budget',
                    },
                ]}
            />,
        );

        fireEvent.change(
            screen.getByPlaceholderText(/approve the 2026\/27 budget/i),
            { target: { value: 'Approve the 2026/27 budget' } },
        );
        goToReview();

        // An incomplete resolution cannot be published yet, but can be saved.
        expect(
            (
                screen.getByRole('button', {
                    name: /publish & open voting/i,
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: /save draft/i }));

        const [url, payload] = inertia.post.mock.calls[0];
        expect(url).toBe('/governance/resolutions');
        expect(payload).toMatchObject({
            title: 'Approve the 2026/27 budget',
            meeting_id: 5,
            publish_now: false,
            _modal: true,
            type: 'ordinary',
        });
        // No record chosen: nothing to approve is sent at all.
        expect(payload).not.toHaveProperty('authority_binding');
        expect(screen.getByText('Resolution saved')).toBeTruthy();
        expect(screen.getByText('Resolution created as a draft.')).toBeTruthy();
        expect(
            screen
                .getByRole('link', {
                    name: /open resolution to attach documents/i,
                })
                .getAttribute('href'),
        ).toBe('/governance/resolutions/77');
    });

    it('edits with the same wizard and sends only changed fields plus the version', () => {
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
                resolution={{
                    id: 42,
                    resolution_reference: 'RES-2026-0042',
                    title: 'Existing resolution',
                    context: 'Background',
                    exact_motion: 'That the board approves.',
                    purpose: 'decision',
                    decision_type: 'financial',
                    voting_threshold: 'three_quarters',
                    governance_meeting_id: 5,
                    options: [{ label: 'Approve' }, { label: 'Decline' }],
                    recommendation: 'Approve',
                    cost_impact: { is_none: true },
                    status: 'draft',
                    version_number: 3,
                }}
                authoritySubjects={{ budgets: [] }}
                authoritySubjectGroups={[
                    {
                        key: 'budgets',
                        subject_type: 'budget',
                        label: 'A budget',
                    },
                ]}
                authorityBindings={[
                    {
                        id: 1,
                        subject_type: 'budget',
                        subject_id: 77,
                        subject_label: 'Care budget · version 2',
                        subject_type_label: 'Budget',
                    },
                ]}
            />,
        );

        fireEvent.change(screen.getByDisplayValue('Existing resolution'), {
            target: { value: 'Existing resolution (revised)' },
        });
        goToReview();

        // The current link is shown even when it is no longer selectable.
        expect(screen.getByText('Budget: Care budget · version 2')).toBeTruthy();
        // The review shows the actual wording the board votes on.
        expect(screen.getByText('That the board approves.')).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/resolutions/42');
        expect(payload).toEqual({
            title: 'Existing resolution (revised)',
            expected_version: 3,
        });
        expect(screen.getByText('Resolution updated')).toBeTruthy();
    });

    it('hides publishing from authors who cannot open voting', () => {
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
                canPublish={false}
            />,
        );

        goToReview();

        expect(
            screen.queryByRole('button', { name: /publish/i }),
        ).toBeNull();
        expect(
            screen.getByRole('button', { name: /save draft/i }),
        ).toBeTruthy();
    });

    it('reports a saved-but-unpublished resolution honestly', () => {
        inertia.flash = {
            error: "The resolution was saved as a draft, but voting couldn't be opened: quorum profile missing.",
        };
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
            />,
        );

        fireEvent.change(
            screen.getByPlaceholderText(/approve the 2026\/27 budget/i),
            { target: { value: 'Resolution' } },
        );
        goToReview();
        fireEvent.click(screen.getByRole('button', { name: /save draft/i }));

        expect(screen.getByText('Saved as a draft')).toBeTruthy();
        expect(screen.getByText(/quorum profile missing/)).toBeTruthy();
    });

    it('papers for discussion skip the voting step and publish to members, not to a vote', () => {
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
                meetingId="5"
            />,
        );

        expect(
            screen.getByRole('button', { name: /voting rule, deadline and actions/i }),
        ).toBeTruthy();

        railStep(/exact wording and what it approves/i);
        fireEvent.click(screen.getByRole('button', { name: /for discussion/i }));

        expect(
            screen.queryByRole('button', { name: /voting rule, deadline and actions/i }),
        ).toBeNull();

        goToReview();
        expect(screen.getByRole('button', { name: /publish to members/i })).toBeTruthy();
        expect(screen.queryByRole('button', { name: /open voting/i })).toBeNull();
        expect(screen.queryByText('How it passes')).toBeNull();
    });

    it('prefills the voting deadline in New Zealand time, not UTC', () => {
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
                resolution={{
                    id: 8,
                    title: 'Insurance renewal',
                    purpose: 'decision',
                    voting_threshold: 'simple_majority',
                    // 5:00 am UTC is 5:00 pm NZST.
                    deadline: '2026-09-18T05:00:00+00:00',
                    status: 'draft',
                    version_number: 1,
                }}
            />,
        );

        railStep(/voting rule, deadline and actions/i);
        expect(
            (document.getElementById('resolution-deadline') as HTMLInputElement)
                .value,
        ).toBe('2026-09-18T17:00');

        goToReview();
        expect(
            screen.getByText('18 September 2026, 5:00 pm (NZ time)'),
        ).toBeTruthy();
    });

    it('says a vote outside a meeting needs a deadline, and why publishing is blocked while voting is switched off', () => {
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
                votingRules={{
                    switched_on: false,
                    written_voting_permitted: true,
                    written_unanimity_required: true,
                }}
            />,
        );

        railStep(/voting rule, deadline and actions/i);
        expect(
            screen.getByText(/votes outside a meeting need everyone’s agreement/i),
        ).toBeTruthy();

        goToReview();
        expect(
            screen.getByText(
                'Set a voting deadline — votes outside a meeting (written resolutions) need one.',
            ),
        ).toBeTruthy();
        expect(
            screen.getByText(/board voting is switched off until the voting rules are confirmed/i),
        ).toBeTruthy();
        expect(
            (
                screen.getByRole('button', {
                    name: /publish & open voting/i,
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });
});

describe('formatWallTime', () => {
    it('formats a datetime-local value without shifting timezones', () => {
        expect(formatWallTime('2026-09-18T17:00')).toBe(
            '18 September 2026, 5:00 pm',
        );
        expect(formatWallTime('2026-01-02T00:05')).toBe(
            '2 January 2026, 12:05 am',
        );
    });
});
