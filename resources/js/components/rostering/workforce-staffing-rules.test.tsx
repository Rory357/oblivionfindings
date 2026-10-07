import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    WorkforceStaffingRules,
    type StaffingRules,
} from './workforce-staffing-rules';
const { patch, reload } = vi.hoisted(() => ({
    patch: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ router: { patch, reload } }));
const values = {
    max_hours_per_day: 12,
    max_hours_per_week: 60,
    warning_threshold_weekly: 40,
    min_rest_between_shifts_hours: 11,
    max_consecutive_days: 6,
};
const rules: StaffingRules = {
    values,
    defaults: values,
    revision: 'a'.repeat(64),
    source: 'deployment_defaults',
    limits: { max_rest_hours: 8784, max_consecutive_days: 366 },
    can_edit: true,
    can_view_history: false,
    scope: 'organisation',
    urls: {
        update: '/operations/workforce-settings/staffing-rules',
        history: null,
    },
};
const base = {
    actorId: 7,
    rules,
    visible: true,
    query: '',
    onDirtyChange: vi.fn(),
    onShow: vi.fn(),
};
beforeEach(() => vi.clearAllMocks());
describe('Workforce staffing settings review and recovery', () => {
    it('keeps read-only staff out of editing and private history', () => {
        render(
            <WorkforceStaffingRules
                {...base}
                rules={{
                    ...rules,
                    can_edit: false,
                    urls: { update: null, history: null },
                }}
            />,
        );
        expect(screen.queryByRole('spinbutton')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Review staffing rules' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Change history' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/View only/)).toBeVisible();
    });
    it('retains the draft between sections, requires reason, sends revision and keeps stale errors editable', () => {
        const view = render(<WorkforceStaffingRules {...base} />);
        fireEvent.change(screen.getByLabelText('Weekly hours threshold'), {
            target: { value: '56' },
        });
        view.rerender(<WorkforceStaffingRules {...base} visible={false} />);
        expect(
            screen.getByText(/You have unsaved staffing rules/),
        ).toBeVisible();
        view.rerender(<WorkforceStaffingRules {...base} />);
        expect(screen.getByLabelText('Weekly hours threshold')).toHaveValue(56);
        fireEvent.click(
            screen.getByRole('button', { name: 'Review staffing rules' }),
        );
        const review = screen.getByRole('dialog', {
            name: 'Review staffing rules',
        });
        expect(within(review).getByText('60 → 56 hours')).toBeVisible();
        expect(
            within(review).getByRole('button', { name: 'Save staffing rules' }),
        ).toBeDisabled();
        fireEvent.change(
            within(review).getByLabelText('Reason for this change'),
            { target: { value: 'Approved workload review' } },
        );
        fireEvent.click(
            within(review).getByRole('button', { name: 'Save staffing rules' }),
        );
        expect(patch).toHaveBeenCalledWith(
            rules.urls.update,
            {
                expected_revision: rules.revision,
                values: { ...values, max_hours_per_week: 56 },
                reason: 'Approved workload review',
            },
            expect.any(Object),
        );
        act(() => {
            patch.mock.calls[0][2].onError({
                expected_revision: 'The rules changed in another session.',
            });
            patch.mock.calls[0][2].onFinish();
        });
        expect(within(review).getByRole('alert')).toHaveTextContent(
            'The rules changed in another session.',
        );
        expect(
            within(review).getByLabelText('Reason for this change'),
        ).toHaveValue('Approved workload review');
        fireEvent.click(
            within(review).getByRole('button', { name: 'Keep editing' }),
        );
        expect(screen.getByLabelText('Weekly hours threshold')).toHaveValue(56);
    });
    it('blocks an invalid warning and does not treat equivalent numeric formatting as a policy change', () => {
        render(<WorkforceStaffingRules {...base} />);
        fireEvent.change(screen.getByLabelText('Daily hours threshold'), {
            target: { value: '12.0' },
        });
        expect(
            screen.getByRole('button', { name: 'Review staffing rules' }),
        ).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Weekly warning threshold'), {
            target: { value: '61' },
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'weekly warning threshold',
        );
        expect(
            screen.getByRole('button', { name: 'Review staffing rules' }),
        ).toBeDisabled();
        expect(patch).not.toHaveBeenCalled();
    });
    it('shows confirmed saved values without resetting them to a stale incoming prop', () => {
        render(<WorkforceStaffingRules {...base} />);
        fireEvent.change(screen.getByLabelText('Daily hours threshold'), {
            target: { value: '10' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review staffing rules' }),
        );
        fireEvent.change(screen.getByLabelText('Reason for this change'), {
            target: { value: 'Review outcome' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Save staffing rules' }),
        );
        act(() => {
            patch.mock.calls[0][2].onSuccess({
                props: {
                    auth: { user: { id: 7 } },
                    staffingRules: {
                        ...rules,
                        revision: 'b'.repeat(64),
                        values: { ...values, max_hours_per_day: 10 },
                    },
                    flash: {
                        workforce_settings_result: {
                            action: 'staffing_rules',
                            actor_id: 7,
                            expected_revision: rules.revision,
                            prior_revision: rules.revision,
                            revision: 'b'.repeat(64),
                            values: { ...values, max_hours_per_day: 10 },
                            changed: true,
                            refresh: {
                                status: 'staged',
                                recheck_id: 4,
                                source_version: 1,
                            },
                        },
                    },
                },
            });
            patch.mock.calls[0][2].onFinish();
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Daily hours threshold')).toHaveValue(10);
        expect(screen.getByRole('status')).toHaveTextContent(
            'Staffing rules saved. Duty rechecks are queued',
        );
        expect(
            screen.getByRole('button', { name: 'Review staffing rules' }),
        ).toBeDisabled();
    });
});

it('retains the rule draft and reason after an unconfirmed response and current-state review', () => {
    render(<WorkforceStaffingRules {...base} />);
    fireEvent.change(screen.getByLabelText('Daily hours threshold'), {
        target: { value: '10' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Review staffing rules' }),
    );
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: { value: 'Keep this explanation' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Save staffing rules' }),
    );
    act(() => {
        patch.mock.calls[0][2].onSuccess({ props: { staffingRules: rules } });
        patch.mock.calls[0][2].onFinish();
    });
    const dialog = screen.getByRole('dialog');
    expect(
        within(dialog).getByRole('button', { name: 'Save staffing rules' }),
    ).toBeDisabled();
    expect(within(dialog).getByLabelText('Reason for this change')).toHaveValue(
        'Keep this explanation',
    );
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Check current rules' }),
    );
    act(() => {
        reload.mock.calls[0][0].onSuccess({
            props: {
                auth: { user: { id: 7 } },
                staffingRules: {
                    ...rules,
                    revision: 'c'.repeat(64),
                    values: { ...values, max_hours_per_day: 11 },
                },
            },
        });
        reload.mock.calls[0][0].onFinish();
    });
    expect(screen.getByLabelText('Daily hours threshold')).toHaveValue(10);
    fireEvent.click(
        screen.getByRole('button', { name: 'Review staffing rules' }),
    );
    expect(screen.getByLabelText('Reason for this change')).toHaveValue(
        'Keep this explanation',
    );
    expect(screen.getByText('11 → 10 hours')).toBeVisible();
    expect(patch).toHaveBeenCalledOnce();
});
it('stops saving after edit access is removed during a review', () => {
    const view = render(<WorkforceStaffingRules {...base} />);
    fireEvent.change(screen.getByLabelText('Daily hours threshold'), {
        target: { value: '10' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Review staffing rules' }),
    );
    fireEvent.change(screen.getByLabelText('Reason for this change'), {
        target: { value: 'Review' },
    });
    view.rerender(
        <WorkforceStaffingRules
            {...base}
            rules={{
                ...rules,
                can_edit: false,
                urls: { update: null, history: null },
            }}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Save staffing rules' }),
    ).toBeDisabled();
    expect(screen.getByLabelText('Reason for this change')).toHaveValue(
        'Review',
    );
    expect(patch).not.toHaveBeenCalled();
});
