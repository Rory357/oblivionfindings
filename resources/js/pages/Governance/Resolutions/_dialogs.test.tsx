import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    flash: {} as Record<string, string>,
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        router: { visit: vi.fn(), reload: vi.fn(), replace: vi.fn() },
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
                    options?.onSuccess?.({ props: { flash: inertia.flash } });
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

import { ResolutionWizardDialog } from './_dialogs';

const meetings = [
    {
        id: 5,
        title: 'October board meeting',
        scheduled_at: '2026-10-14T01:00:00Z',
    },
];

const goToReview = () =>
    fireEvent.click(
        screen.getByRole('button', { name: /check readiness and save/i }),
    );

describe('ResolutionWizardDialog', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.put.mockReset();
        inertia.flash = {};
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

        expect(screen.getByText('Give the paper a title.')).toBeTruthy();
        expect(inertia.post).not.toHaveBeenCalled();
    });

    it('saves a draft from the register, staying in context, with the preselected meeting', () => {
        inertia.flash = { success: 'Decision paper RES-2026-0007 created.' };
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
                meetingId="5"
                authoritySubjects={{
                    budgets: [{ id: 9, label: 'FY27 budget' }],
                }}
                authoritySubjectGroups={[
                    {
                        key: 'budgets',
                        subject_type: 'budget',
                        label: 'Budgets',
                    },
                ]}
            />,
        );

        fireEvent.change(
            screen.getByPlaceholderText(
                /approval of the 2026\/27 strategic plan/i,
            ),
            { target: { value: 'Approve the FY27 budget' } },
        );
        goToReview();

        // An incomplete paper cannot be published yet, but can be saved.
        expect(
            (
                screen.getByRole('button', {
                    name: /publish for voting/i,
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        fireEvent.click(screen.getByRole('button', { name: /save draft/i }));

        const [url, payload] = inertia.post.mock.calls[0];
        expect(url).toBe('/governance/resolutions');
        expect(payload).toMatchObject({
            title: 'Approve the FY27 budget',
            meeting_id: 5,
            publish_now: false,
            _modal: true,
            type: 'ordinary',
        });
        // No record chosen: authority is not sent at all.
        expect(payload).not.toHaveProperty('authority_binding');
        expect(screen.getByText('Decision paper created')).toBeTruthy();
        expect(
            screen.getByText('Decision paper RES-2026-0007 created.'),
        ).toBeTruthy();
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
                    title: 'Existing paper',
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
                        label: 'Budgets',
                    },
                ]}
                authorityBindings={[
                    {
                        id: 1,
                        subject_type: 'budget',
                        subject_id: 77,
                        subject_label: 'Budget #77 · revision 2',
                        subject_type_label: 'Budget',
                    },
                ]}
            />,
        );

        fireEvent.change(screen.getByDisplayValue('Existing paper'), {
            target: { value: 'Existing paper (revised)' },
        });
        goToReview();

        // The current binding is shown even when it is no longer selectable.
        expect(
            screen.getByText('Budget: Budget #77 · revision 2'),
        ).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/resolutions/42');
        expect(payload).toEqual({
            title: 'Existing paper (revised)',
            expected_version: 3,
        });
        expect(screen.getByText('Decision paper updated')).toBeTruthy();
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
            screen.queryByRole('button', { name: /publish for voting/i }),
        ).toBeNull();
        expect(
            screen.getByRole('button', { name: /save draft/i }),
        ).toBeTruthy();
    });

    it('reports a saved-but-unpublished paper honestly', () => {
        inertia.flash = {
            error: 'Decision paper RES-1 was saved as a draft but not published: quorum profile missing.',
        };
        render(
            <ResolutionWizardDialog
                isOpen
                onClose={vi.fn()}
                meetings={meetings}
            />,
        );

        fireEvent.change(
            screen.getByPlaceholderText(
                /approval of the 2026\/27 strategic plan/i,
            ),
            { target: { value: 'Paper' } },
        );
        goToReview();
        fireEvent.click(screen.getByRole('button', { name: /save draft/i }));

        expect(screen.getByText('Saved as a draft')).toBeTruthy();
        expect(screen.getByText(/quorum profile missing/)).toBeTruthy();
    });
});
