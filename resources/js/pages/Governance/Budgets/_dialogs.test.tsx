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

import { BudgetWizardDialog, financialYearOptions } from './_dialogs';

const options = {
    categories: {
        staffing: 'Staffing',
        operations: 'Operations',
        capital: 'Equipment and buildings',
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

describe('financialYearOptions', () => {
    it('labels NZ financial years by the year they end', () => {
        const years = financialYearOptions(new Date('2026-09-14T00:00:00Z'));

        expect(years.map((year) => year.label)).toEqual([
            '2025/26',
            '2026/27',
            '2027/28',
            '2028/29',
        ]);
        expect(years.find((year) => year.is_current)).toMatchObject({
            value: 2027,
            range: '1 July 2026 – 30 June 2027',
        });
    });
});

describe('BudgetWizardDialog', () => {
    it('defaults to this financial year and validates each nested line before creating', () => {
        const current = financialYearOptions().find((year) => year.is_current);

        render(
            <BudgetWizardDialog isOpen onClose={() => {}} options={options} />,
        );

        expect(
            screen.getByText(
                'Set up a yearly budget for the board to approve.',
            ),
        ).toBeTruthy();
        fireEvent.change(
            screen.getByPlaceholderText('e.g. 2026/27 operating budget'),
            { target: { value: 'Operating budget' } },
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
        expect(
            screen.getByText('Describe what this line pays for.'),
        ).toBeTruthy();

        // Remove the incomplete line and carry on to review.
        clickButton(/remove line 2/i);
        clickButton(/continue/i);
        expect(
            screen.getByRole('heading', { name: 'Total budget' }),
        ).toBeTruthy();
        clickButton(/continue/i);
        clickButton(/create budget/i);

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, payload] = inertia.post.mock.calls[0];
        expect(url).toBe('/governance/budgets');
        expect(payload).toEqual({
            fiscal_year: String(current?.value),
            title: 'Operating budget',
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

    it('requires the approval date and minutes reference for a budget the board already approved', () => {
        render(
            <BudgetWizardDialog isOpen onClose={() => {}} options={options} />,
        );

        clickButton(/^total/i);
        fireEvent.change(screen.getByPlaceholderText('e.g. 1500000'), {
            target: { value: '250000' },
        });
        fireEvent.click(
            screen.getByRole('checkbox', {
                name: /already approved this budget/i,
            }),
        );
        clickButton(/continue/i);

        expect(
            screen.getByText('Enter the date the board approved this budget.'),
        ).toBeTruthy();
        expect(
            screen.getByText(
                'Enter the minutes reference for the meeting that approved it.',
            ),
        ).toBeTruthy();
        expect(inertia.post).not.toHaveBeenCalled();

        fireEvent.change(
            screen.getByPlaceholderText(
                'e.g. Board minutes 24 June 2026, item 5',
            ),
            { target: { value: 'Minutes 24 June 2026, item 5' } },
        );
        const date = document.querySelector<HTMLInputElement>(
            '#budget-approved-on',
        );
        expect(date).not.toBeNull();
        fireEvent.change(date!, { target: { value: '2026-06-24' } });
        clickButton(/continue/i);
        clickButton(/create budget/i);

        expect(inertia.post).toHaveBeenCalledTimes(1);
        expect(inertia.post.mock.calls[0][1]).toMatchObject({
            total_budget: '250000',
            board_approved: true,
            approved_on: '2026-06-24',
            approval_reference: 'Minutes 24 June 2026, item 5',
        });
    });

    it('edits existing lines by id and jumps to the step owning a server error', () => {
        inertia.serverErrors = {
            'line_items.0.description': 'Describe what this line pays for.',
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

        // A budget sent to the board warns that edits need the board again.
        expect(
            screen.getByText(/board must see the updated budget/i),
        ).toBeTruthy();

        clickButton(/budget lines/i);
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

        expect(
            screen.getByRole('heading', { name: 'Budget lines' }),
        ).toBeTruthy();
        expect(
            screen.getByText('Describe what this line pays for.'),
        ).toBeTruthy();
    });
});
