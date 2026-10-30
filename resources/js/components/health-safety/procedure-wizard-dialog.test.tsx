/* eslint-disable no-restricted-syntax -- Test-only wizard navigation buttons. */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { ProcedureFormData } from './procedure-detail-dialog';
import { ProcedureWizardDialog } from './procedure-wizard-dialog';
const state = vi.hoisted(() => ({
    post: vi.fn(),
    reset: vi.fn(),
    defaults: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        router: { on: () => () => {} },
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = React.useState(initial);
            const transform = React.useRef((v: Record<string, unknown>) => v);
            return {
                data,
                errors: {},
                isDirty: true,
                processing: false,
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                transform: (fn: typeof transform.current) => {
                    transform.current = fn;
                },
                reset: state.reset,
                clearErrors: vi.fn(),
                setDefaults: state.defaults,
                post: (
                    url: string,
                    options: {
                        onSuccess: (page: { props: object }) => void;
                        onFinish: () => void;
                    },
                ) => {
                    state.post(url, transform.current(data));
                    options.onSuccess({
                        props: { flash: { created_procedure_id: 9 } },
                    });
                    options.onFinish();
                },
            };
        },
    };
});
vi.mock('@/components/wizard/shell', () => ({
    WizardShell: ({
        children,
        steps,
        onStepClick,
        footerEnd,
        success,
    }: {
        children: ReactNode;
        steps: { label: string }[];
        onStepClick: (index: number) => void;
        footerEnd: ReactNode;
        success: ReactNode;
    }) => (
        <div>
            {success ?? (
                <>
                    <nav>
                        {steps.map((s, i) => (
                            <button key={i} onClick={() => onStepClick(i)}>
                                Go to {s.label}
                            </button>
                        ))}
                    </nav>
                    {children}
                    {footerEnd}
                </>
            )}
        </div>
    ),
    WizardStepPane: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    WizardSuccessPane: ({ title }: { title: string }) => <p>{title}</p>,
    ReviewCard: ({
        title,
        children,
    }: {
        title: string;
        children: ReactNode;
    }) => (
        <section>
            {title}
            {children}
        </section>
    ),
    ReviewRow: ({ label, value }: { label: string; value: ReactNode }) => (
        <p>
            {label}: {value}
        </p>
    ),
}));
beforeEach(() => {
    state.post.mockReset();
    state.reset.mockReset();
    state.defaults.mockReset();
});
afterEach(cleanup);
const initial: ProcedureFormData = {
    id: 9,
    expected_version: 4,
    expected_lock_version: 7,
    title: 'Safe hoist use',
    reference_number: 'SWP-009',
    category: 'manual_handling',
    purpose: 'Safe transfers',
    scope: '',
    steps: [
        { step_number: 1, description: 'Check the hoist', safety_notes: '' },
    ],
    ppe_required: [],
    hazards_addressed: [],
    emergency_procedures: '',
    applicable_roles: null,
    applicable_sites: null,
    related_training: null,
    review_date: null,
    review_frequency_months: null,
    owner_id: null,
};
const options = {
    sites: [{ id: 1, name: 'Approved site' }],
    roles: [],
    owners: [],
    trainingCourses: [],
    categories: [{ value: 'manual_handling', label: 'Manual handling' }],
    can_apply_organisation_wide: true,
};
it('preserves unknown legacy audience on save instead of broadening it to all roles or sites', () => {
    render(
        <ProcedureWizardDialog
            open
            initial={initial}
            options={options}
            onClose={vi.fn()}
            onOpenProcedure={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Go to Applies to' }));
    expect(
        screen.getByText(/Site applicability was not recorded/),
    ).toBeTruthy();
    fireEvent.click(
        screen.getByRole('button', { name: 'Go to Review & save' }),
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: /Summary of changes/ }),
        { target: { value: 'Clarified safe transfer wording.' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(state.post).toHaveBeenCalledTimes(1);
    const payload = state.post.mock.calls[0][1];
    expect(payload).not.toHaveProperty('applicable_sites');
    expect(payload).not.toHaveProperty('applicable_roles');
    expect(payload).toMatchObject({
        expected_version: 4,
        expected_lock_version: 7,
        review_frequency_months: null,
    });
});
it('asks the owning page before destroying an edited draft', () => {
    const close = vi.fn();
    render(
        <ProcedureWizardDialog
            open
            initial={initial}
            options={options}
            onClose={close}
            onOpenProcedure={vi.fn()}
        />,
    );
    fireEvent.change(screen.getByRole('textbox', { name: /^Title/ }), {
        target: { value: 'Unsaved hoist instructions' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(close).toHaveBeenCalledOnce();
    expect(state.reset).not.toHaveBeenCalled();
    expect(
        (screen.getByRole('textbox', { name: /^Title/ }) as HTMLInputElement)
            .value,
    ).toBe('Unsaved hoist instructions');
});
it('prevents a site-limited author from submitting an empty organisation-wide audience', () => {
    render(
        <ProcedureWizardDialog
            open
            initial={{ ...initial, applicable_sites: [] }}
            options={{ ...options, can_apply_organisation_wide: false }}
            onClose={vi.fn()}
            onOpenProcedure={vi.fn()}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Go to Review & save' }),
    );
    fireEvent.change(
        screen.getByRole('textbox', { name: /Summary of changes/ }),
        { target: { value: 'Changed instructions.' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(state.post).not.toHaveBeenCalled();
    expect(
        screen.getByText(
            'Choose at least one approved site for this procedure.',
        ),
    ).toBeTruthy();
    expect(
        (
            screen.getByRole('button', {
                name: 'Organisation-wide instructions',
            }) as HTMLButtonElement
        ).disabled,
    ).toBe(true);
});
