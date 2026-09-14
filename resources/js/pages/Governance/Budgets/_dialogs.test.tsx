import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    serverErrors: null as Record<string, string> | null,
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        router: { replace: vi.fn() },
        usePage: () => ({ url: '/governance/budgets', props: {} }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const transformRef = React.useRef<(d: T) => object>((d) => d);
            const submit =
                (spy: typeof inertia.post) =>
                (
                    url: string,
                    options: {
                        onError?: (e: Record<string, string>) => void;
                        onSuccess?: (page: unknown) => void;
                    },
                ) => {
                    spy(url, transformRef.current(data), options);
                    if (inertia.serverErrors) {
                        setErrors(inertia.serverErrors);
                        options.onError?.(inertia.serverErrors);
                    }
                };
            return {
                data,
                errors,
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                setData: (key: keyof T, value: unknown) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                transform: (fn: (d: T) => object) => {
                    transformRef.current = fn;
                },
                post: submit(inertia.post),
                put: submit(inertia.put),
            };
        },
    };
});

import { BudgetWizardDialog } from './_dialogs';

const options = {
    categories: {
        staffing: 'Staffing',
        operations: 'Operations',
        capital: 'Capital',
    },
};

const clickButton = (name: RegExp) =>
    fireEvent.click(screen.getByRole('button', { name }));

afterEach(() => {
    cleanup();
    inertia.post.mockReset();
    inertia.put.mockReset();
    inertia.serverErrors = null;
});

describe('BudgetWizardDialog', () => {
    it('validates the fiscal year and each nested line before creating', () => {
        render(
            <BudgetWizardDialog isOpen onClose={() => {}} options={options} />,
        );

        fireEvent.change(screen.getByPlaceholderText('e.g. 2026'), {
            target: { value: '26' },
        });
        clickButton(/continue/i);
        expect(
            screen.getByText('Enter a four-digit year between 2000 and 2100.'),
        ).toBeTruthy();

        fireEvent.change(screen.getByPlaceholderText('e.g. 2026'), {
            target: { value: '2027' },
        });
        fireEvent.change(
            screen.getByPlaceholderText('e.g. FY2026 Operating Budget'),
            { target: { value: 'FY2027 Operating Budget' } },
        );
        clickButton(/continue/i);

        // Lines step: add two lines, leave the second incomplete.
        clickButton(/add line/i);
        clickButton(/add line/i);
        const descriptions = screen.getAllByPlaceholderText(
            'e.g. Support worker wages',
        );
        const amounts = screen.getAllByPlaceholderText('e.g. 120000');
        fireEvent.change(descriptions[0], {
            target: { value: 'Support worker wages' },
        });
        fireEvent.change(amounts[0], { target: { value: '120000' } });
        clickButton(/continue/i);
        expect(screen.getByText('Describe the line.')).toBeTruthy();

        // Remove the incomplete line and carry on to review.
        clickButton(/remove line 2/i);
        clickButton(/continue/i);
        expect(screen.getByText('Budget envelope')).toBeTruthy();
        clickButton(/continue/i);
        clickButton(/create budget/i);

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.post.mock.calls[0];
        expect(url).toBe('/governance/budgets');
        expect(payload).toEqual({
            fiscal_year: '2027',
            title: 'FY2027 Operating Budget',
            description: null,
            total_budget: '120000.00',
            board_approved: false,
            line_items: [
                {
                    category: 'operations',
                    description: 'Support worker wages',
                    account_code: null,
                    budget_amount: '120000',
                    forecast_amount: null,
                    notes: null,
                },
            ],
        });
    });

    it('edits existing lines by id and jumps to the step owning a server error', () => {
        inertia.serverErrors = {
            'line_items.0.description': 'The description field is required.',
        };

        render(
            <BudgetWizardDialog
                isOpen
                onClose={() => {}}
                options={options}
                budget={{
                    id: 9,
                    fiscal_year: '2026',
                    title: 'Care budget',
                    description: 'Homes',
                    total_budget: '30000.00',
                    status: 'proposed',
                    version_number: 2,
                    line_items: [
                        {
                            id: 41,
                            category: 'staffing',
                            description: 'Rostered care',
                            account_code: '6100',
                            budget_amount: '10000.00',
                            forecast_amount: '9000.00',
                            notes: null,
                        },
                        {
                            id: 42,
                            category: 'capital',
                            description: 'Hoists',
                            account_code: null,
                            budget_amount: '20000.00',
                            forecast_amount: null,
                            notes: null,
                        },
                    ],
                }}
            />,
        );

        // A proposed budget warns that edits invalidate its bound paper.
        expect(
            screen.getByText(
                /decision paper bound to it will no longer approve it/i,
            ),
        ).toBeTruthy();

        clickButton(/line items/i);
        clickButton(/remove line 2/i);
        clickButton(/review/i);
        clickButton(/save budget/i);

        expect(inertia.put).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.put.mock.calls[0];
        expect(url).toBe('/governance/budgets/9');
        expect(payload).toMatchObject({
            fiscal_year: '2026',
            total_budget: '10000.00',
            line_items: [
                {
                    id: 41,
                    category: 'staffing',
                    description: 'Rostered care',
                    account_code: '6100',
                    budget_amount: '10000',
                    forecast_amount: '9000',
                },
            ],
        });
        expect(payload).not.toHaveProperty('board_approved');

        expect(screen.getByText('Budgeted lines')).toBeTruthy();
        expect(
            screen.getByText('The description field is required.'),
        ).toBeTruthy();
    });
});
