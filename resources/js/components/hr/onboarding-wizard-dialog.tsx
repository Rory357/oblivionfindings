/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import {
    Briefcase,
    ClipboardCheck,
    ClipboardList,
    ListChecks,
    ShieldCheck,
    UserPlus,
} from 'lucide-react';
import { useMemo } from 'react';

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

export interface OnboardingEmployee {
    id: number;
    name: string;
    email?: string | null;
    position_title?: string | null;
    position_role?: string | null;
    primary_site_name?: string | null;
    primary_site_type?: string | null;
    start_date?: string | null;
}

export interface OnboardingTemplateOption {
    id: number;
    role: string;
    site_type: string | null;
    is_active: boolean;
    task_count: number;
    tasks: Array<{
        category: string;
        title: string;
        is_required: boolean;
        sign_off_required: boolean;
    }>;
}

export interface OnboardingEmailOption {
    id: number;
    template_name: string;
    send_days_before_start: number;
}

const STEPS: readonly WizardStep[] = [
    { key: 'person', label: 'Employee', blurb: 'Who is starting', icon: UserPlus },
    { key: 'role', label: 'Role & start', blurb: 'Confirm details', icon: Briefcase },
    { key: 'template', label: 'Template', blurb: 'Checklist source', icon: ClipboardList },
    { key: 'preview', label: 'Preview', blurb: 'Tasks created', icon: ListChecks },
    { key: 'options', label: 'Options', blurb: 'Compliance & email', icon: ShieldCheck },
    { key: 'review', label: 'Review', blurb: 'Confirm & launch', icon: ClipboardCheck },
];

const prettyRole = (r?: string | null) =>
    r ? r.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '—';

/**
 * Resolve the onboarding template that auto-matches an employee's role + site,
 * mirroring OnboardingService::resolveTemplate priority:
 *   exact role+site → role + site 'all' → 'default' role.
 */
function autoMatch(
    templates: OnboardingTemplateOption[],
    employee: OnboardingEmployee | undefined,
): OnboardingTemplateOption | null {
    if (!employee) return null;
    const active = templates.filter((t) => t.is_active);
    const role = employee.position_role ?? '';
    const site = employee.primary_site_type ?? 'all';

    return (
        active.find((t) => t.role === role && t.site_type === site) ??
        active.find((t) => t.role === role && t.site_type === 'all') ??
        active.find((t) => t.role === 'default' && t.site_type === site) ??
        active.find((t) => t.role === 'default') ??
        null
    );
}

/**
 * Stepper-modal replacement for the one-field onboarding "create" page. Picks the
 * new hire, confirms role/site/start, chooses (or auto-matches) a checklist
 * template, previews the tasks, and optionally seeds compliance requirements and
 * fires a welcome email — all in one WizardShell flow, posting to onboarding.store.
 */
export function OnboardingWizardDialog({
    open,
    onClose,
    employees,
    templates,
    emailTemplates,
}: {
    open: boolean;
    onClose: () => void;
    employees: OnboardingEmployee[];
    templates: OnboardingTemplateOption[];
    emailTemplates: OnboardingEmailOption[];
}) {
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        employee_profile_id: string;
        template_id: string;
        assign_compliance: boolean;
        send_welcome_email: boolean;
        welcome_email_id: string;
    }>({
        employee_profile_id: '',
        template_id: '',
        assign_compliance: true,
        send_welcome_email: false,
        welcome_email_id: '',
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

    const matched = useMemo(
        () => autoMatch(templates, employee),
        [templates, employee],
    );

    // Template used for the preview/launch: explicit selection else auto-match.
    const chosen = form.data.template_id
        ? (templates.find((t) => String(t.id) === form.data.template_id) ?? null)
        : matched;

    // Radix Select disallows empty-string item values, so 'auto' is the sentinel
    // for "let the server auto-match"; it maps back to an empty template_id.
    const templateOptions = [
        { value: 'auto', label: 'Auto-match by role & site' },
        ...templates
            .filter((t) => t.is_active)
            .map((t) => ({
                value: String(t.id),
                label: `${prettyRole(t.role)} · ${t.site_type ?? 'all'} (${t.task_count} tasks)`,
            })),
    ];

    const emailName =
        emailTemplates.find((e) => String(e.id) === form.data.welcome_email_id)
            ?.template_name ?? '—';

    const canSubmit =
        form.data.employee_profile_id !== '' &&
        chosen !== null &&
        (!form.data.send_welcome_email || form.data.welcome_email_id !== '');

    const submit = () => {
        form.post('/hr/onboarding', {
            preserveScroll: true,
            onSuccess: () => close(),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Start onboarding"
            description="Generate an onboarding checklist for a new employee."
            railIcon={UserPlus}
            railTitle="Start onboarding"
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
                            {form.processing ? 'Launching…' : 'Start onboarding'}
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
                        icon={UserPlus}
                        title="Who is starting?"
                        blurb="Pick the new employee. Only active people without an in-flight onboarding checklist are shown."
                    />
                    <Field label="Employee" required error={form.errors.employee_profile_id}>
                        <PeoplePicker
                            value={form.data.employee_profile_id}
                            onChange={(v) =>
                                form.setData('employee_profile_id', v)
                            }
                            people={people}
                            placeholder="Select an employee…"
                        />
                    </Field>
                    {employees.length === 0 && (
                        <p className="mt-2 text-sm text-muted-foreground">
                            Every active employee already has an onboarding
                            checklist.
                        </p>
                    )}
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Briefcase}
                        title="Role & start"
                        blurb="These come from the employee's profile and decide which template auto-matches."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Briefcase} title="Profile">
                            <ReviewRow
                                label="Name"
                                value={employee?.name}
                            />
                            <ReviewRow
                                label="Position"
                                value={employee?.position_title}
                            />
                            <ReviewRow
                                label="Access role"
                                value={prettyRole(employee?.position_role)}
                            />
                        </ReviewCard>
                        <ReviewCard icon={ClipboardList} title="Placement">
                            <ReviewRow
                                label="Primary site"
                                value={employee?.primary_site_name}
                            />
                            <ReviewRow
                                label="Site type"
                                value={employee?.primary_site_type ?? 'all'}
                            />
                            <ReviewRow
                                label="Start date"
                                value={employee?.start_date}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardList}
                        title="Checklist template"
                        blurb="Auto-match picks the template for this role & site, or choose one explicitly."
                    />
                    <Field label="Template" error={form.errors.template_id}>
                        <SelectInput
                            value={form.data.template_id || 'auto'}
                            onChange={(v) =>
                                form.setData('template_id', v === 'auto' ? '' : v)
                            }
                            placeholder="Auto-match by role & site"
                            options={templateOptions}
                        />
                    </Field>
                    {chosen ? (
                        <p className="mt-2 text-sm text-muted-foreground">
                            {form.data.template_id
                                ? 'Using the selected template'
                                : 'Auto-matched'}
                            : <span className="font-medium text-foreground">{prettyRole(chosen.role)} · {chosen.site_type ?? 'all'}</span> ({chosen.task_count} tasks).
                        </p>
                    ) : (
                        <p className="mt-2 text-sm text-status-critical">
                            No active template matches this role/site. Pick one
                            explicitly, or create a template first.
                        </p>
                    )}
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ListChecks}
                        title="Tasks to be created"
                        blurb="A checklist task is created for each row below, with due dates relative to the start date."
                    />
                    {chosen && chosen.tasks.length > 0 ? (
                        <ul className="divide-y rounded-lg border">
                            {chosen.tasks.map((t, i) => (
                                <li
                                    key={i}
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
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No template resolved yet — go back and choose one.
                        </p>
                    )}
                </WizardStepPane>
            )}

            {wizard.index === 4 && (
                <WizardStepPane>
                    <StepHead
                        icon={ShieldCheck}
                        title="On launch"
                        blurb="Optional extras that run when the checklist is created."
                    />
                    <div className="space-y-3">
                        <label className="flex items-start gap-2.5 rounded-lg border p-3 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.assign_compliance}
                                onChange={(e) =>
                                    form.setData(
                                        'assign_compliance',
                                        e.target.checked,
                                    )
                                }
                                className="mt-0.5 rounded border-border"
                            />
                            <span>
                                <span className="block font-medium">
                                    Assign compliance requirements
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    Seed the role's required checks so the new
                                    hire appears in the compliance matrix from
                                    day one.
                                </span>
                            </span>
                        </label>

                        <label
                            className={cn(
                                'flex items-start gap-2.5 rounded-lg border p-3 text-sm',
                                emailTemplates.length === 0 && 'opacity-60',
                            )}
                        >
                            <input
                                type="checkbox"
                                disabled={emailTemplates.length === 0}
                                checked={form.data.send_welcome_email}
                                onChange={(e) =>
                                    form.setData(
                                        'send_welcome_email',
                                        e.target.checked,
                                    )
                                }
                                className="mt-0.5 rounded border-border"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium">
                                    Send a welcome email now
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {emailTemplates.length === 0
                                        ? 'No active email templates — create one under Email Templates first.'
                                        : 'Fires immediately, regardless of the template’s day-offset schedule.'}
                                </span>
                                {form.data.send_welcome_email &&
                                    emailTemplates.length > 0 && (
                                        <span className="mt-2 block">
                                            <SelectInput
                                                value={form.data.welcome_email_id}
                                                onChange={(v) =>
                                                    form.setData(
                                                        'welcome_email_id',
                                                        v,
                                                    )
                                                }
                                                placeholder="Select an email template"
                                                options={emailTemplates.map(
                                                    (e) => ({
                                                        value: String(e.id),
                                                        label: e.template_name,
                                                    }),
                                                )}
                                            />
                                        </span>
                                    )}
                            </span>
                        </label>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 5 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & launch"
                        blurb="Generate the checklist and notify the assignees."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={UserPlus}
                            title="Employee"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Name" value={employee?.name} />
                            <ReviewRow
                                label="Position"
                                value={employee?.position_title}
                            />
                            <ReviewRow
                                label="Start"
                                value={employee?.start_date}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={ClipboardList}
                            title="Checklist"
                            onEdit={() => wizard.goTo(2)}
                        >
                            <ReviewRow
                                label="Template"
                                value={
                                    chosen
                                        ? `${prettyRole(chosen.role)} · ${chosen.site_type ?? 'all'}`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Tasks"
                                value={chosen ? String(chosen.task_count) : undefined}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={ShieldCheck}
                            title="On launch"
                            span
                            onEdit={() => wizard.goTo(4)}
                        >
                            <ReviewRow
                                label="Compliance"
                                value={
                                    form.data.assign_compliance
                                        ? 'Assign role requirements'
                                        : 'Skip'
                                }
                            />
                            <ReviewRow
                                label="Welcome email"
                                value={
                                    form.data.send_welcome_email
                                        ? emailName
                                        : 'Don’t send'
                                }
                            />
                        </ReviewCard>
                    </div>
                    {!chosen && (
                        <p className="mt-3 text-sm text-status-critical">
                            Pick a template before launching.
                        </p>
                    )}
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default OnboardingWizardDialog;
