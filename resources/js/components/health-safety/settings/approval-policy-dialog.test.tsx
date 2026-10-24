import type { WizardShell } from '@/components/wizard/shell';
import { SafetyFormDraftContext } from '@/hooks/use-safety-workspace-drafts';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { ApprovalSnapshot } from './approval-policy';
import { ApprovalPolicyDialog } from './approval-policy-dialog';

const io = vi.hoisted(() => ({ put: vi.fn(), getVisit: vi.fn() }));
vi.mock('axios', () => ({
    default: { get: vi.fn(), isAxiosError: () => false },
}));
vi.mock('@inertiajs/react', async () => {
    const React = await vi.importActual<typeof import('react')>('react');
    return {
        Link: ({ href, children }: { href: string; children: ReactNode }) => (
            <a href={href}>{children}</a>
        ),
        router: { get: io.getVisit },
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            const [defaults, setDefaults] = React.useState(initial);
            return {
                data,
                setData,
                errors: {},
                processing: false,
                isDirty: JSON.stringify(data) !== JSON.stringify(defaults),
                put: (
                    url: string,
                    options: {
                        onSuccess?: (page: {
                            props: Record<string, unknown>;
                        }) => void;
                        onError?: () => void;
                    },
                ) =>
                    io.put(url, data, {
                        ...options,
                        onSuccess: (page: {
                            props: Record<string, unknown>;
                        }) => {
                            setDefaults(data);
                            options.onSuccess?.(page);
                        },
                    }),
            };
        },
    };
});
vi.mock('@/components/wizard/shell', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/components/wizard/shell')>()),
    WizardShell: (props: ComponentProps<typeof WizardShell>) => (
        <section aria-label={props.title}>
            {props.success ?? (
                <>
                    {props.children}
                    <footer>
                        {props.footerStart}
                        {props.footerEnd}
                    </footer>
                </>
            )}
        </section>
    ),
}));
const policy: ApprovalSnapshot = {
    setting_key: 'healthSafety.restraints.plan_approval_policy',
    schema_version: 1,
    mode: 'separate_specialist',
    policy_revision: 0,
    policy_digest: 'a'.repeat(64),
    policy_snapshot: { schema_version: 1, mode: 'separate_specialist' },
    setting_change_id: 1,
    origin: 'application_default',
    reviewed_at: null,
    configuration_valid: true,
    configuration_error: null,
};
beforeEach(() => {
    io.put.mockReset();
    io.getVisit.mockReset();
    vi.mocked(axios.get).mockReset();
});
afterEach(cleanup);
const next = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
const selectAlternative = () =>
    fireEvent.click(
        screen.getByRole('button', { name: /^Authorised reviewer decision/ }),
    );
const reason = (value: string) =>
    fireEvent.change(
        screen.getByRole('textbox', {
            name: 'Reason for this policy decision',
        }),
        { target: { value } },
    );
const finishAlternative = () => {
    selectAlternative();
    next();
    reason('The organisation uses its authorised plan review workflow.');
    next();
};
const succeed = (props: Record<string, unknown>) =>
    act(() => {
        const options = io.put.mock.calls.at(-1)![2] as {
            onSuccess: (page: { props: Record<string, unknown> }) => void;
        };
        options.onSuccess({ props });
    });

it('reviews a deliberate keep and shows success only for its exact retained receipt', () => {
    render(
        <ApprovalPolicyDialog policy={policy} canManage onClose={vi.fn()} />,
    );
    expect(
        screen.queryByRole('button', { name: 'Save policy' }),
    ).not.toBeInTheDocument();
    next();
    next();
    fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
    const submitted = io.put.mock.calls[0][1];
    expect(submitted).toEqual({
        mode: 'separate_specialist',
        reason: '',
        expected_policy_revision: 0,
        idempotency_key: expect.stringMatching(/^[0-9a-f-]{36}$/i),
    });
    succeed({
        health_safety_policy_receipt: {
            accepted: true,
            idempotency_key: submitted.idempotency_key,
            setting_key: policy.setting_key,
            setting_change_id: 2,
            policy_revision: 1,
            schema_version: 1,
            mode: 'separate_specialist',
            policy_snapshot: policy.policy_snapshot,
            policy_digest: policy.policy_digest,
            action: 'kept',
            origin: 'organisation_setting',
            recorded_at: '2026-10-11T08:00:00Z',
            replayed: false,
        },
    });
    expect(screen.getByText('Approval policy saved')).toBeVisible();
});

it('requires a reason, retains uncertain acceptance as dirty and retries the identical UUID', async () => {
    const register = vi.fn();
    render(
        <SafetyFormDraftContext.Provider value={register}>
            <ApprovalPolicyDialog policy={policy} canManage onClose={vi.fn()} />
        </SafetyFormDraftContext.Provider>,
    );
    selectAlternative();
    next();
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
    reason('The organisation uses its authorised plan review workflow.');
    next();
    fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
    const original = io.put.mock.calls[0][1];
    succeed({});
    expect(screen.queryByText('Approval policy saved')).not.toBeInTheDocument();
    await waitFor(() =>
        expect(register).toHaveBeenLastCalledWith(expect.any(String), {
            dirty: true,
            busy: false,
            uncertain: false,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
    expect(io.put.mock.calls[1][1]).toEqual(original);
    fireEvent.click(screen.getAllByRole('button', { name: 'Edit' })[1]);
    reason('A revised organisation decision.');
    next();
    fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
    expect(io.put.mock.calls[2][1].idempotency_key).not.toBe(
        original.idempotency_key,
    );
});

it('checks a changed current policy without replacing history or losing the draft', async () => {
    vi.mocked(axios.get).mockResolvedValue({
        data: { policy: { ...policy, policy_revision: 1 }, can_manage: true },
    });
    render(
        <ApprovalPolicyDialog policy={policy} canManage onClose={vi.fn()} />,
    );
    finishAlternative();
    fireEvent.click(
        screen.getByRole('button', { name: 'Check current policy' }),
    );
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Review current policy' }),
        ).toBeVisible(),
    );
    expect(screen.getByRole('button', { name: 'Save policy' })).toBeDisabled();
    expect(io.getVisit).not.toHaveBeenCalled();
    expect(axios.get).toHaveBeenCalledWith(
        '/health-safety/settings',
        expect.objectContaining({
            headers: { Accept: 'application/json' },
            signal: expect.any(AbortSignal),
        }),
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Review current policy' }),
    );
    next();
    expect(
        screen.getByRole('textbox', {
            name: 'Reason for this policy decision',
        }),
    ).toHaveValue('The organisation uses its authorised plan review workflow.');
    next();
    fireEvent.click(screen.getByRole('button', { name: 'Save policy' }));
    expect(io.put.mock.calls[0][1]).toMatchObject({
        mode: 'authorised_reviewer',
        expected_policy_revision: 1,
    });
});

it('disables saving after live access withdrawal while retaining review entries', () => {
    const view = render(
        <ApprovalPolicyDialog policy={policy} canManage onClose={vi.fn()} />,
    );
    finishAlternative();
    view.rerender(
        <ApprovalPolicyDialog
            policy={policy}
            canManage={false}
            onClose={vi.fn()}
        />,
    );
    expect(screen.getByRole('button', { name: 'Save policy' })).toBeDisabled();
    expect(
        screen.getByText(
            'The organisation uses its authorised plan review workflow.',
        ),
    ).toBeVisible();
    expect(io.put).not.toHaveBeenCalled();
});

it('retains a failed current-policy check and requires a successful check before saving', async () => {
    vi.mocked(axios.get)
        .mockRejectedValueOnce(new Error('Interrupted check'))
        .mockResolvedValueOnce({ data: { policy, can_manage: true } });
    render(
        <ApprovalPolicyDialog policy={policy} canManage onClose={vi.fn()} />,
    );
    finishAlternative();
    fireEvent.click(
        screen.getByRole('button', { name: 'Check current policy' }),
    );
    await waitFor(() =>
        expect(screen.getByRole('alert')).toHaveTextContent(
            'could not be checked',
        ),
    );
    expect(screen.getByRole('button', { name: 'Save policy' })).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Check current policy' }),
    );
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Save policy' }),
        ).toBeEnabled(),
    );
    expect(
        screen.getByText(
            'The organisation uses its authorised plan review workflow.',
        ),
    ).toBeVisible();
});
