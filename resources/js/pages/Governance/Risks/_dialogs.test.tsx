import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        Link: ({
            href,
            children,
            ...rest
        }: {
            href: string;
            children: React.ReactNode;
        }) => ReactActual.createElement('a', { href, ...rest }, children),
        router: { visit: vi.fn(), reload: vi.fn(), replace: vi.fn() },
        usePage: () => ({ props: { flash: {} }, url: '/governance/risks' }),
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
                    options?.onSuccess?.({ props: { flash: {} } });
                };
            return {
                data,
                errors: {},
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (
                    keyOrUpdater:
                        | string
                        | ((
                              prev: Record<string, unknown>,
                          ) => Record<string, unknown>),
                    value?: unknown,
                ) =>
                    setDataState((current) =>
                        typeof keyOrUpdater === 'function'
                            ? keyOrUpdater(current)
                            : { ...current, [keyOrUpdater]: value },
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

import { AcceptRiskDialog, CloseRiskDialog, RiskWizardDialog } from './_dialogs';
import { riskStatusChip, strategyLabel } from './_shared';

const options = {
    categories: [
        { value: 'financial', label: 'Financial' },
        { value: 'workforce', label: 'Workforce' },
    ],
    owners: [{ id: 7, name: 'Aroha Rangi' }],
};

describe('RiskWizardDialog', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.put.mockReset();
    });
    afterEach(() => cleanup());

    it('blocks Continue until category, title and description are provided', () => {
        render(<RiskWizardDialog open onClose={vi.fn()} options={options} />);

        fireEvent.click(screen.getByRole('button', { name: /continue/i }));

        expect(screen.getByText('Choose what kind of risk this is.')).toBeTruthy();
        expect(screen.getByText('Give the risk a name.')).toBeTruthy();
        expect(
            screen.getByText('Describe what could happen and why.'),
        ).toBeTruthy();
    });

    it('explains control effectiveness and uses before/after controls wording', () => {
        render(
            <RiskWizardDialog
                open
                onClose={vi.fn()}
                options={{ ...options, limits: { financial: 12 } }}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /financial/i }));
        fireEvent.change(screen.getByPlaceholderText(/shared by mistake/i), {
            target: { value: 'Funding shortfall' },
        });
        fireEvent.change(screen.getByPlaceholderText(/what could happen/i), {
            target: { value: 'Contract renewal delayed' },
        });
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));

        expect(
            screen.getByText(/strong controls cut the score to a fifth; none leave it unchanged/i),
        ).toBeTruthy();
        expect(screen.getByText(/risk before controls/i).textContent).toMatch(
            /scores are recalculated when you save/i,
        );
        expect(screen.queryByText(/inherent|residual|appetite/i)).toBeNull();
    });

    it('adds a risk from the register and shows the success pane', () => {
        render(<RiskWizardDialog open onClose={vi.fn()} options={options} />);

        fireEvent.click(screen.getByRole('button', { name: /financial/i }));
        fireEvent.change(screen.getByPlaceholderText(/shared by mistake/i), {
            target: { value: 'Funding shortfall' },
        });
        fireEvent.change(screen.getByPlaceholderText(/what could happen/i), {
            target: { value: 'Contract renewal delayed' },
        });
        for (let i = 0; i < 3; i += 1) {
            fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        }
        fireEvent.click(screen.getByRole('button', { name: /^add risk$/i }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/governance/risks',
            expect.objectContaining({
                _modal: true,
                category: 'financial',
                title: 'Funding shortfall',
                likelihood_score: 3,
                impact_score: 3,
                review_frequency: 'quarterly',
            }),
        );
        expect(screen.getByText('Risk added')).toBeTruthy();
    });

    it('edits with the same wizard and only sends fields the update accepts', () => {
        render(
            <RiskWizardDialog
                open
                onClose={vi.fn()}
                options={options}
                risk={{
                    id: 42,
                    risk_reference: 'R-2026-001',
                    title: 'Existing risk',
                    category: 'workforce',
                    description: 'Night shift cover',
                    likelihood_score: 4,
                    impact_score: 2,
                    control_effectiveness: 'weak',
                    mitigation_strategy: 'treat',
                    review_frequency: 'monthly',
                    risk_owner: { id: 7, name: 'Aroha Rangi' },
                }}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: /check before saving/i }),
        );
        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/risks/42');
        expect(payload).toMatchObject({
            title: 'Existing risk',
            likelihood_score: 4,
            impact_score: 2,
            control_effectiveness: 'weak',
            mitigation_strategy: 'treat',
        });
        expect(payload).not.toHaveProperty('category');
        expect(payload).not.toHaveProperty('review_frequency');
        expect(payload).not.toHaveProperty('risk_owner_id');
        expect(screen.getByText('Risk saved')).toBeTruthy();
    });
});

describe('AcceptRiskDialog', () => {
    beforeEach(() => inertia.post.mockReset());
    afterEach(() => cleanup());

    const justification =
        'The board accepts this risk while the new rostering system beds in.';

    it('needs a passed resolution for a risk above the board’s limit', () => {
        render(
            <AcceptRiskDialog
                open
                onClose={vi.fn()}
                riskId={9}
                categoryLabel="Staff and workforce"
                appetiteThreshold={12}
                aboveLimit
                resolutionOptions={[
                    {
                        id: 31,
                        title: 'Accept the rostering risk',
                        reference: 'RES-2026-010',
                        decided_at: '2026-09-01T00:00:00Z',
                    },
                ]}
                canViewResolutions
            />,
        );

        const submit = screen.getByRole('button', {
            name: /^accept risk$/i,
        }) as HTMLButtonElement;
        fireEvent.change(screen.getByLabelText(/why the board accepts it/i), {
            target: { value: justification },
        });
        expect(screen.getByText(`${justification.length} / 50 characters`)).toBeTruthy();
        // Still blocked: no resolution chosen yet.
        expect(submit.disabled).toBe(true);
    });

    it('explains who can unblock it when no passed resolution exists', () => {
        render(
            <AcceptRiskDialog
                open
                onClose={vi.fn()}
                riskId={9}
                categoryLabel="Staff and workforce"
                appetiteThreshold={12}
                aboveLimit
                resolutionOptions={[]}
                canViewResolutions
            />,
        );

        expect(
            screen.getByText(/ask the board secretary to put a resolution to the board/i),
        ).toBeTruthy();
        expect(
            screen.getByRole('link', { name: /go to resolutions/i }).getAttribute('href'),
        ).toBe('/governance/resolutions');
        expect(
            (screen.getByRole('button', { name: /^accept risk$/i }) as HTMLButtonElement)
                .disabled,
        ).toBe(true);
    });

    it('lets a risk within the limit be accepted without a resolution', () => {
        render(
            <AcceptRiskDialog
                open
                onClose={vi.fn()}
                riskId={9}
                categoryLabel="Finance"
                appetiteThreshold={15}
                aboveLimit={false}
                resolutionOptions={[]}
                canViewResolutions={false}
            />,
        );
        fireEvent.change(screen.getByLabelText(/why the board accepts it/i), {
            target: { value: justification },
        });
        fireEvent.change(screen.getByLabelText(/conditions/i), {
            target: { value: 'Report monthly\n\nReview in March' },
        });
        fireEvent.click(screen.getByRole('button', { name: /^accept risk$/i }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/governance/risks/9/accept',
            expect.objectContaining({
                justification,
                expiry_months: 12,
                conditions: ['Report monthly', 'Review in March'],
                resolution_id: null,
            }),
        );
    });
});

describe('CloseRiskDialog', () => {
    beforeEach(() => inertia.post.mockReset());
    afterEach(() => cleanup());

    it('asks for a reason before closing', () => {
        render(
            <CloseRiskDialog open onClose={vi.fn()} riskId={4} riskTitle="Old site lease" />,
        );
        expect(screen.getByText('Close this risk?')).toBeTruthy();
        const submit = screen.getByRole('button', {
            name: /^close risk$/i,
        }) as HTMLButtonElement;
        expect(submit.disabled).toBe(true);

        fireEvent.change(screen.getByLabelText(/why it is being closed/i), {
            target: { value: 'The lease ended and the site was handed back.' },
        });
        expect(submit.disabled).toBe(false);
        fireEvent.click(submit);
        expect(inertia.post).toHaveBeenCalledWith('/governance/risks/4/close', {
            rationale: 'The lease ended and the site was handed back.',
        });
    });
});

describe('risk wording', () => {
    it('names responses in plain words first', () => {
        expect(strategyLabel('treat')).toBe('Reduce it (treat)');
        expect(strategyLabel('transfer')).toBe('Share it (transfer)');
        expect(strategyLabel('terminate')).toBe('Stop the activity (avoid)');
        expect(strategyLabel('tolerate')).toBe('Live with it and monitor (tolerate)');
    });

    it('shows accepted risks as accepted by the board until the expiry date', () => {
        expect(
            riskStatusChip({
                status: 'accepted',
                within_appetite: false,
                accepted_until: '2027-03-31',
            }),
        ).toEqual({
            label: 'Accepted by the board until 31 Mar 2027',
            variant: 'success',
        });
        expect(
            riskStatusChip({ status: 'open', within_appetite: false }).label,
        ).toBe("Above the board's limit");
    });
});
