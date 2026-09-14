import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
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

import { RiskWizardDialog } from './_dialogs';

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

        expect(screen.getByText('Choose a risk category')).toBeTruthy();
        expect(screen.getByText('A risk title is required')).toBeTruthy();
        expect(
            screen.getByText('Describe the risk and its causes'),
        ).toBeTruthy();
    });

    it('registers a risk from the register and shows the success pane', () => {
        render(<RiskWizardDialog open onClose={vi.fn()} options={options} />);

        fireEvent.click(screen.getByRole('button', { name: /financial/i }));
        fireEvent.change(
            screen.getByPlaceholderText(/client information privacy breach/i),
            { target: { value: 'Funding shortfall' } },
        );
        fireEvent.change(screen.getByPlaceholderText(/what could happen/i), {
            target: { value: 'Contract renewal delayed' },
        });
        for (let i = 0; i < 3; i += 1) {
            fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        }
        fireEvent.click(screen.getByRole('button', { name: /register risk/i }));

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
        expect(screen.getByText('Risk registered')).toBeTruthy();
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
            screen.getByRole('button', { name: /confirm before saving/i }),
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
        expect(screen.getByText('Risk updated')).toBeTruthy();
    });
});
