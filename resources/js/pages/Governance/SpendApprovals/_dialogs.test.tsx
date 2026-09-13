import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        router: { replace: vi.fn() },
        usePage: () => ({ url: '/governance/spend-approvals', props: {} }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            return {
                data,
                errors,
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: vi.fn(),
                post: (url: string, options: { onError?: (e: Record<string, string>) => void }) => {
                    inertia.post(url, data, options);
                },
                put: (
                    url: string,
                    options: { onError?: (e: Record<string, string>) => void },
                ) => {
                    inertia.put(url, data, options);
                    const serverErrors = { amount: 'The amount is too large.' };
                    setErrors(serverErrors);
                    options.onError?.(serverErrors);
                },
            };
        },
    };
});

import { SpendApprovalWizardDialog } from './_dialogs';

const options = {
    categories: { capex: 'Capital expenditure', opex: 'Operating expenditure' },
    thresholds: { capex: 5000, opex: 10000 },
    sites: [
        { id: 7, name: 'Aurora House' },
        { id: 8, name: 'Kowhai Lodge' },
    ],
};

afterEach(() => {
    cleanup();
    inertia.post.mockReset();
    inertia.put.mockReset();
});

describe('SpendApprovalWizardDialog', () => {
    it('blocks Continue until the request step has its required fields', () => {
        render(
            <SpendApprovalWizardDialog isOpen onClose={() => {}} options={options} />,
        );

        fireEvent.click(screen.getByRole('button', { name: /continue/i }));

        expect(screen.getByText('Give the request a title.')).toBeTruthy();
        expect(
            screen.getByText('Choose the site this spend is for.'),
        ).toBeTruthy();
        expect(screen.getByText('What needs sign-off?')).toBeTruthy();
    });

    it('edits with the version it opened and jumps to the step owning a server error', () => {
        render(
            <SpendApprovalWizardDialog
                isOpen
                onClose={() => {}}
                options={options}
                approval={{
                    id: 42,
                    reference: 'SA-42',
                    title: 'Replace van',
                    description: null,
                    category: 'capex',
                    amount: 28500,
                    currency: 'NZD',
                    site_id: 7,
                    valid_until: null,
                    version: 3,
                }}
            />,
        );

        // Jump straight to the review step via the rail.
        fireEvent.click(screen.getByRole('button', { name: /review/i }));
        fireEvent.click(screen.getByRole('button', { name: /save changes/i }));

        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/spend-approvals/42');
        expect(payload).toMatchObject({
            expected_version: 3,
            site_id: '7',
            amount: '28500',
            category: 'capex',
        });

        expect(screen.getByText('Amount and validity')).toBeTruthy();
        expect(screen.getByText('The amount is too large.')).toBeTruthy();
    });
});
