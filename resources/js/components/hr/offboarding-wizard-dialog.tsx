/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import {
    CalendarX2,
    ClipboardCheck,
    ListChecks,
    MessageSquare,
    Package,
    ShieldAlert,
    UserMinus,
} from 'lucide-react';
import { useMemo } from 'react';

import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

import { PeoplePicker, type PersonOption } from './people-picker';
import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from './wizard';

export interface OffboardingEmployee {
    id: number;
    name: string;
    email?: string | null;
    position_title?: string | null;
    end_date?: string | null;
    active_assets: Array<{ id: number; name: string; asset_tag?: string | null }>;
}

export interface OffboardingTaskPreview {
    category: string;
    title: string;
    description?: string | null;
    is_required: boolean;
    sign_off_required?: boolean;
}

export interface DepartureReason {
    value: string;
    label: string;
}

export interface OffboardingInterviewer {
    id: number;
    name: string;
}

const STEPS: readonly WizardStep[] = [
    { key: 'person', label: 'Employee', blurb: 'Who is leaving', icon: UserMinus },
    { key: 'date', label: 'Last day', blurb: 'Final working date', icon: CalendarX2 },
    { key: 'tasks', label: 'Checklist', blurb: 'Tasks created', icon: ListChecks },
    { key: 'reminders', label: 'Reminders', blurb: 'Access & pay', icon: ShieldAlert },
    { key: 'exit', label: 'Exit interview', blurb: 'Optional', icon: MessageSquare },
    { key: 'review', label: 'Review', blurb: 'Confirm & launch', icon: ClipboardCheck },
];

/**
 * Stepper-modal replacement for the 2-field offboarding "create" page. Picks the
 * leaver, sets the last day, previews the standard checklist + auto asset-return
 * rows, warns about the access-revoke / final-pay side-effects, and optionally
 * schedules a real exit interview — posting it all to offboarding.store.
 */
export function OffboardingWizardDialog({
    open,
    onClose,
    employees,
    defaultTasks,
    departureReasons,
    interviewers,
    defaultEndDate,
}: {
    open: boolean;
    onClose: () => void;
    employees: OffboardingEmployee[];
    defaultTasks: OffboardingTaskPreview[];
    departureReasons: DepartureReason[];
    interviewers: OffboardingInterviewer[];
    defaultEndDate: string;
}) {
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        employee_profile_id: string;
        end_date: string;
        schedule_exit_interview: boolean;
        departure_reason: string;
        interviewer_user_id: string;
        interview_date: string;
    }>({
        employee_profile_id: '',
        end_date: defaultEndDate,
        schedule_exit_interview: false,
        departure_reason: '',
        interviewer_user_id: '',
        interview_date: '',
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const people: PersonOption[] = useMemo(
        () =>
            employees.map((e) => ({
                value: String(e.id),
                label: e.name,
                sub:
                    [e.position_title, e.email].filter(Boolean).join(' · ') ||
                    undefined,
            })),
        [employees],
    );

    const employee = employees.find(
        (e) => String(e.id) === form.data.employee_profile_id,
    );

    const selectEmployee = (v: string) => {
        const next = employees.find((e) => String(e.id) === v);
        form.setData({
            ...form.data,
            employee_profile_id: v,
            end_date: next?.end_date || form.data.end_date || defaultEndDate,
        });
    };

    const assets = employee?.active_assets ?? [];
    const taskCount = defaultTasks.length + assets.length;

    const reasonLabel =
        departureReasons.find((r) => r.value === form.data.departure_reason)
            ?.label ?? '—';
    const interviewerName =
        interviewers.find(
            (i) => String(i.id) === form.data.interviewer_user_id,
        )?.name ?? '—';

    const canSubmit =
        form.data.employee_profile_id !== '' &&
        form.data.end_date !== '' &&
        (!form.data.schedule_exit_interview ||
            (form.data.departure_reason !== '' &&
                form.data.interviewer_user_id !== '' &&
                form.data.interview_date !== ''));

    const submit = () => {
        form.post('/hr/offboarding', {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (form.errors.end_date) wizard.goTo(1);
                else if (
                    form.errors.departure_reason ||
                    form.errors.interviewer_user_id ||
                    form.errors.interview_date
                ) {
                    wizard.goTo(4);
                }
            },
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Start offboarding"
            description="Generate an offboarding checklist for a departing employee."
            railIcon={UserMinus}
            railTitle="Start offboarding"
            railSub="HR"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            footerStart={
                wizard.isFirst ? null : (
                    <button
                        type="button"
                        onClick={wizard.back}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Back
                    </button>
                )
            }
            footerEnd={
                <>
                    <button
                        type="button"
                        onClick={close}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Cancel
                    </button>
                    {wizard.isLast ? (
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!canSubmit || form.processing}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                (!canSubmit || form.processing) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            {form.processing ? 'Launching…' : 'Start offboarding'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            disabled={
                                wizard.index === 0 &&
                                form.data.employee_profile_id === ''
                            }
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground',
                                wizard.index === 0 &&
                                    form.data.employee_profile_id === '' &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            Continue
                        </button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={UserMinus}
                        title="Who is leaving?"
                        blurb="Pick the departing employee. Only active people without an in-flight offboarding checklist are shown."
                    />
                    <Field label="Employee" required error={form.errors.employee_profile_id}>
                        <PeoplePicker
                            value={form.data.employee_profile_id}
                            onChange={selectEmployee}
                            people={people}
                            placeholder="Select an employee…"
                        />
                    </Field>
                    {employees.length === 0 && (
                        <p className="mt-2 text-sm text-muted-foreground">
                            No active employees are available to offboard.
                        </p>
                    )}
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarX2}
                        title="Last working day"
                        blurb="Task due dates are calculated relative to this date."
                    />
                    <Field label="Last working day" required error={form.errors.end_date}>
                        <Input
                            type="date"
                            value={form.data.end_date}
                            onChange={(e) =>
                                form.setData('end_date', e.target.value)
                            }
                        />
                    </Field>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ListChecks}
                        title="Tasks to be created"
                        blurb="These standard tasks are created (a role-specific template may add or replace them), plus one return task per active asset."
                    />
                    <ul className="divide-y rounded-lg border">
                        {defaultTasks.map((t, i) => (
                            <li
                                key={`t-${i}`}
                                className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                            >
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">
                                        {t.title}
                                    </span>
                                    <span className="block truncate text-xs text-muted-foreground">
                                        {t.category}
                                    </span>
                                </span>
                                <span className="flex shrink-0 gap-1.5 text-[11px]">
                                    {t.is_required && (
                                        <span className="rounded bg-muted px-1.5 py-0.5 font-semibold text-muted-foreground">
                                            Required
                                        </span>
                                    )}
                                    {t.sign_off_required && (
                                        <span className="rounded bg-status-info-bg px-1.5 py-0.5 font-semibold text-status-info">
                                            Sign-off
                                        </span>
                                    )}
                                </span>
                            </li>
                        ))}
                        {assets.map((a) => (
                            <li
                                key={`a-${a.id}`}
                                className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                            >
                                <span className="flex min-w-0 items-center gap-2">
                                    <Package className="h-4 w-4 shrink-0 text-muted-foreground" />
                                    <span className="min-w-0">
                                        <span className="block truncate font-medium">
                                            Return asset: {a.name}
                                        </span>
                                        {a.asset_tag && (
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {a.asset_tag}
                                            </span>
                                        )}
                                    </span>
                                </span>
                                <span className="shrink-0 rounded bg-status-info-bg px-1.5 py-0.5 text-[11px] font-semibold text-status-info">
                                    Sign-off
                                </span>
                            </li>
                        ))}
                    </ul>
                    {employee && assets.length === 0 && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            No active company assets are assigned to this
                            employee.
                        </p>
                    )}
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ShieldAlert}
                        title="Before you launch"
                        blurb="What the checklist enforces."
                    />
                    <ul className="space-y-2 text-sm">
                        <li className="rounded-lg border p-3">
                            <span className="font-medium">
                                Revoke system access
                            </span>{' '}
                            and{' '}
                            <span className="font-medium">
                                final pay calculation
                            </span>{' '}
                            are required tasks with sign-off.
                        </li>
                        <li className="rounded-lg border p-3">
                            The employee is removed from rosters and their
                            documents archived per retention policy.
                        </li>
                        <li className="rounded-lg border border-status-warning/40 bg-status-warning-bg/40 p-3">
                            When every required task is completed, the profile is
                            automatically deactivated and the end date stamped —
                            no separate step needed.
                        </li>
                    </ul>
                </WizardStepPane>
            )}

            {wizard.index === 4 && (
                <WizardStepPane>
                    <StepHead
                        icon={MessageSquare}
                        title="Exit interview"
                        blurb="Optionally schedule the exit interview now — it links to the checklist's exit-interview task."
                    />
                    <label className="flex items-start gap-2.5 rounded-lg border p-3 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.schedule_exit_interview}
                            onChange={(e) =>
                                form.setData(
                                    'schedule_exit_interview',
                                    e.target.checked,
                                )
                            }
                            className="mt-0.5 rounded border-border"
                        />
                        <span>
                            <span className="block font-medium">
                                Schedule an exit interview
                            </span>
                            <span className="block text-xs text-muted-foreground">
                                Creates an exit-interview record you can complete
                                with feedback later.
                            </span>
                        </span>
                    </label>
                    {form.data.schedule_exit_interview && (
                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Departure reason"
                                required
                                error={form.errors.departure_reason}
                            >
                                <SelectInput
                                    value={form.data.departure_reason}
                                    onChange={(v) =>
                                        form.setData('departure_reason', v)
                                    }
                                    placeholder="Select a reason"
                                    options={departureReasons}
                                />
                            </Field>
                            <Field
                                label="Interviewer"
                                required
                                error={form.errors.interviewer_user_id}
                            >
                                <SelectInput
                                    value={form.data.interviewer_user_id}
                                    onChange={(v) =>
                                        form.setData('interviewer_user_id', v)
                                    }
                                    placeholder="Select an interviewer"
                                    options={interviewers.map((i) => ({
                                        value: String(i.id),
                                        label: i.name,
                                    }))}
                                />
                            </Field>
                            <Field
                                label="Interview date"
                                required
                                span
                                error={form.errors.interview_date}
                            >
                                <Input
                                    type="date"
                                    value={form.data.interview_date}
                                    onChange={(e) =>
                                        form.setData(
                                            'interview_date',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    )}
                </WizardStepPane>
            )}

            {wizard.index === 5 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & launch"
                        blurb="Generate the offboarding checklist."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={UserMinus}
                            title="Employee"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Name" value={employee?.name} />
                            <ReviewRow
                                label="Position"
                                value={employee?.position_title}
                            />
                            <ReviewRow
                                label="Last day"
                                value={form.data.end_date}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={ListChecks}
                            title="Checklist"
                            onEdit={() => wizard.goTo(2)}
                        >
                            <ReviewRow
                                label="Standard tasks"
                                value={String(defaultTasks.length)}
                            />
                            <ReviewRow
                                label="Asset returns"
                                value={String(assets.length)}
                            />
                            <ReviewRow label="Total" value={String(taskCount)} />
                        </ReviewCard>
                        <ReviewCard
                            icon={MessageSquare}
                            title="Exit interview"
                            span
                            onEdit={() => wizard.goTo(4)}
                        >
                            {form.data.schedule_exit_interview ? (
                                <>
                                    <ReviewRow
                                        label="Reason"
                                        value={reasonLabel}
                                    />
                                    <ReviewRow
                                        label="Interviewer"
                                        value={interviewerName}
                                    />
                                    <ReviewRow
                                        label="Date"
                                        value={form.data.interview_date}
                                    />
                                </>
                            ) : (
                                <ReviewRow
                                    label="Scheduled"
                                    value="Not now"
                                />
                            )}
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default OffboardingWizardDialog;
