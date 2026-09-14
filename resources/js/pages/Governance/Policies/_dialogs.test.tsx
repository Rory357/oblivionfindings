import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const transform = React.useRef<(values: T) => unknown>((v) => v);
            return {
                data,
                errors: {},
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: (next: (values: T) => unknown) => {
                    transform.current = next;
                },
                post: (url: string, options: unknown) =>
                    inertia.post(url, transform.current(data), options),
                put: (url: string, options: unknown) =>
                    inertia.put(url, transform.current(data), options),
            };
        },
    };
});

import { EvaluationWizardDialog } from '../Evaluations/_dialogs';
import { PolicyWizardDialog, type PolicyWizardRecord } from './_dialogs';

const approvedPolicy: PolicyWizardRecord = {
    id: 7,
    title: 'Delegations of Authority',
    category: 'governance',
    description: 'Who may approve what',
    content: 'Approved wording',
    status: 'active',
    effective_date: '2026-01-01',
    review_date: '2027-01-01',
    requires_attestation: true,
    attestation_frequency: 'annual',
};

const clickContinue = () =>
    fireEvent.click(screen.getByRole('button', { name: /continue/i }));

describe('PolicyWizardDialog', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.put.mockReset();
    });
    afterEach(cleanup);

    it('blocks Continue until the policy has a title', () => {
        render(<PolicyWizardDialog open onClose={vi.fn()} />);
        clickContinue();
        expect(screen.getByText('Give the policy a title.')).toBeTruthy();
    });

    it('edits prefilled, locks approved wording and omits an unchanged status', () => {
        render(
            <PolicyWizardDialog open onClose={vi.fn()} policy={approvedPolicy} />,
        );
        expect(
            (screen.getByLabelText(/policy title/i) as HTMLInputElement).value,
        ).toBe('Delegations of Authority');

        clickContinue();
        expect(
            (screen.getByLabelText(/policy content/i) as HTMLTextAreaElement)
                .disabled,
        ).toBe(true);
        clickContinue();
        clickContinue();
        fireEvent.click(screen.getByRole('button', { name: /save policy/i }));

        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/policies/7');
        expect(payload).not.toHaveProperty('status');
        expect(payload).not.toHaveProperty('content');
        expect(payload).toMatchObject({
            title: 'Delegations of Authority',
            review_date: '2027-01-01',
            requires_attestation: true,
        });
    });
});

describe('EvaluationWizardDialog', () => {
    beforeEach(() => inertia.post.mockReset());
    afterEach(cleanup);

    it('requires question text before creating the evaluation', () => {
        render(<EvaluationWizardDialog open onClose={vi.fn()} />);
        fireEvent.change(screen.getByLabelText(/^title/i), {
            target: { value: 'Board effectiveness 2026' },
        });
        clickContinue();
        fireEvent.change(screen.getByLabelText(/period start/i), {
            target: { value: '2026-01-01' },
        });
        fireEvent.change(screen.getByLabelText(/period end/i), {
            target: { value: '2026-12-31' },
        });
        fireEvent.change(screen.getByLabelText(/responses due/i), {
            target: { value: '2099-01-31' },
        });
        clickContinue();
        clickContinue();
        expect(screen.getByText('Write the question.')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('Question 1'), {
            target: { value: 'Board papers arrive with enough lead time' },
        });
        clickContinue();
        fireEvent.click(
            screen.getByRole('button', { name: /create evaluation/i }),
        );

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.post.mock.calls[0];
        expect(url).toBe('/governance/evaluations');
        expect(payload).toMatchObject({
            title: 'Board effectiveness 2026',
            evaluation_type: 'board',
            due_date: '2099-01-31',
            questions: [
                { text: 'Board papers arrive with enough lead time', type: 'rating' },
            ],
        });
    });
});
