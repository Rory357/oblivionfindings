/* eslint-disable no-restricted-syntax -- Test harness buttons represent wizard navigation and synthetic file selection. */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { RaWizardDialog } from './ra-wizard-dialog';
import type { RaDetail } from './types';
const state = vi.hoisted(() => ({
    create: vi.fn(),
    upload: vi.fn(),
    failed: false,
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        router: { post: state.upload },
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = React.useState(initial);
            const transform = React.useRef(
                (value: Record<string, unknown>) => value,
            );
            return {
                data,
                errors: {},
                isDirty: false,
                processing: false,
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                transform: (fn: typeof transform.current) => {
                    transform.current = fn;
                },
                reset: vi.fn(),
                clearErrors: vi.fn(),
                setDefaults: vi.fn(),
                post: (
                    url: string,
                    options: { onSuccess: (page: { props: object }) => void },
                ) => {
                    state.create(url, transform.current(data));
                    options.onSuccess({
                        props: { flash: { created_risk_assessment_id: 19 } },
                    });
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
                        {steps.map((step, i) => (
                            <button key={i} onClick={() => onStepClick(i)}>
                                {'Go to ' + step.label}
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
    WizardSuccessPane: ({
        title,
        actions,
    }: {
        title: string;
        actions: ReactNode;
    }) => (
        <div>
            {title}
            {actions}
        </div>
    ),
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
        <div>
            {label}: {value}
        </div>
    ),
}));
vi.mock('@/components/ui/file-dropzone', () => ({
    FileDropzone: ({ onFiles }: { onFiles: (files: File[]) => void }) => (
        <button
            onClick={() =>
                onFiles([
                    new File(['synthetic'], 'first.pdf'),
                    new File(['synthetic'], 'second.pdf'),
                ])
            }
        >
            Stage evidence
        </button>
    ),
    StagedFileCard: ({
        file,
        children,
    }: {
        file: File;
        children: ReactNode;
    }) => (
        <div>
            {file.name}
            {children}
        </div>
    ),
}));
const pickers = { sites: [], clients: [], events: [] };
beforeEach(() => {
    vi.clearAllMocks();
    state.failed = false;
    state.upload.mockImplementation(
        (
            _url: string,
            body: FormData,
            options: {
                onSuccess: (page: { props: object }) => void;
                onError: (errors: object) => void;
            },
        ) => {
            if (
                (body.get('file') as File).name === 'second.pdf' &&
                !state.failed
            ) {
                state.failed = true;
                options.onError({ file: 'Synthetic upload failed' });
            } else options.onSuccess({ props: { flash: {} } });
        },
    );
});
afterEach(cleanup);
it('retains failed evidence and retries it without creating a duplicate assessment', () => {
    render(<RaWizardDialog kind="new" pickers={pickers} onClose={vi.fn()} />);
    fireEvent.change(
        screen.getByPlaceholderText('e.g. Manual handling — hoist transfers'),
        { target: { value: 'Synthetic hoist assessment' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Go to Evidence' }));
    fireEvent.click(screen.getByRole('button', { name: 'Stage evidence' }));
    fireEvent.click(
        screen.getByRole('button', { name: 'Go to Review & create' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Create assessment' }));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Synthetic upload failed',
    );
    expect(state.create).toHaveBeenCalledTimes(1);
    expect(state.upload).toHaveBeenCalledTimes(2);
    fireEvent.click(
        screen.getAllByRole('button', { name: 'Retry remaining uploads' })[0],
    );
    expect(state.create).toHaveBeenCalledTimes(1);
    expect(
        state.upload.mock.calls.map(
            (call) => (call[1].get('file') as File).name,
        ),
    ).toEqual(['first.pdf', 'second.pdf', 'second.pdf']);
    expect(screen.getByText('Risk assessment created')).toBeInTheDocument();
});
it('preserves unknown residual values, acceptability and cadence when editing a draft', () => {
    const detail = {
        id: 7,
        reference_number: 'RA-SYNTHETIC',
        form: {
            title: 'Existing draft',
            risk_description: null,
            attach_type: 'standalone',
            attach_id: null,
            likelihood: 3,
            consequence: 3,
            existing_controls: null,
            additional_controls: null,
            residual_likelihood: null,
            residual_consequence: null,
            risk_acceptable: null,
            review_frequency_days: null,
            review_due_at: null,
        },
    } as RaDetail;
    render(
        <RaWizardDialog
            kind="edit"
            detail={detail}
            pickers={pickers}
            onClose={vi.fn()}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Go to Review & create' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
    expect(state.create.mock.calls[0][1]).toMatchObject({
        residual_likelihood: null,
        residual_consequence: null,
        risk_acceptable: null,
        review_frequency_days: null,
    });
});

it('renders an explicit unknown acceptability choice without inventing yes or no', () => {
    render(<RaWizardDialog kind="new" pickers={pickers} onClose={vi.fn()} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Go to Residual risk' }),
    );
    expect(
        screen.getByRole('combobox', { name: 'Not recorded' }),
    ).toHaveTextContent('Not recorded');
});
