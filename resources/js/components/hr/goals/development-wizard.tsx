/* eslint-disable no-restricted-syntax -- Wizard footer + level pickers use
 * native controls to match the Add-Client modal chrome. Semantic tokens only. */
import { useForm } from '@inertiajs/react';
import {
    CalendarClock,
    ClipboardCheck,
    Sprout,
    Target,
    Users,
} from 'lucide-react';
import { useMemo } from 'react';

import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

const STEPS: WizardStep[] = [
    {
        key: 'person',
        label: 'Person',
        blurb: 'Employee & manager',
        icon: Users,
    },
    { key: 'focus', label: 'Focus', blurb: 'Competency & level', icon: Target },
    {
        key: 'cadence',
        label: 'Cadence',
        blurb: 'Review rhythm',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & create',
        icon: ClipboardCheck,
    },
];

const NONE = '__none__';
const CATS = [
    { value: 'growth', label: 'Growth' },
    { value: 'performance', label: 'Performance' },
    { value: 'leadership', label: 'Leadership' },
    { value: 'compliance', label: 'Compliance' },
    { value: 'capability', label: 'Capability' },
];

function LevelPicker({
    value,
    onChange,
}: {
    value: number;
    onChange: (n: number) => void;
}) {
    return (
        <div className="flex gap-1.5">
            {[1, 2, 3, 4, 5].map((n) => (
                <button
                    key={n}
                    type="button"
                    aria-pressed={value === n}
                    onClick={() => onChange(n)}
                    className={cn(
                        'h-9 w-9 rounded-lg border text-sm font-bold transition-colors',
                        value === n
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-card text-foreground hover:border-primary/50',
                    )}
                >
                    {n}
                </button>
            ))}
        </div>
    );
}

export function DevelopmentWizard({
    open,
    onClose,
    staff,
    objectives,
    competencies = [],
}: {
    open: boolean;
    onClose: () => void;
    staff: { id: number; name: string }[];
    objectives: { id: number; title: string }[];
    competencies?: { id: number; name: string }[];
}) {
    const wizard = useWizard(STEPS.length);
    const stepKey = STEPS[wizard.index]?.key;

    const form = useForm<{
        employee_user_id: string;
        manager_user_id: string;
        hr_goal_id: string;
        competency_id: string;
        title: string;
        competency_area: string;
        category: string;
        current_level: number;
        target_level: number;
        description: string;
        review_frequency: string;
        due_date: string;
        review_notes: string;
    }>({
        employee_user_id: '',
        manager_user_id: '',
        hr_goal_id: NONE,
        competency_id: NONE,
        title: '',
        competency_area: '',
        category: 'capability',
        current_level: 1,
        target_level: 3,
        description: '',
        review_frequency: 'monthly',
        due_date: '',
        review_notes: '',
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const staffOptions = useMemo(
        () => staff.map((s) => ({ value: String(s.id), label: s.name })),
        [staff],
    );
    const objectiveOptions = useMemo(
        () => [
            { value: NONE, label: 'None' },
            ...objectives.map((o) => ({ value: String(o.id), label: o.title })),
        ],
        [objectives],
    );

    const step0Valid =
        form.data.employee_user_id !== '' && form.data.manager_user_id !== '';
    const step1Valid =
        form.data.competency_area.trim() !== '' && form.data.category !== '';
    const canSubmit = step0Valid && step1Valid && form.data.due_date !== '';

    const submit = (stay = false) => {
        form.transform((data) => ({
            employee_user_id: data.employee_user_id,
            manager_user_id: data.manager_user_id,
            hr_goal_id: data.hr_goal_id === NONE ? null : data.hr_goal_id,
            competency_id:
                data.competency_id === NONE ? null : data.competency_id,
            title: data.competency_area.trim(),
            competency_area: data.competency_area.trim(),
            description: data.description.trim() || null,
            category: data.category,
            current_level: data.current_level,
            target_level: data.target_level,
            status: 'not_started',
            review_frequency: data.review_frequency,
            due_date: data.due_date,
            review_notes: data.review_notes.trim() || null,
        }));

        form.post('/hr/goals/development', {
            preserveScroll: true,
            onSuccess: () => {
                if (stay) {
                    form.reset();
                    wizard.reset();
                } else {
                    close();
                }
            },
            onError: () => {
                if (form.errors.employee_user_id || form.errors.manager_user_id)
                    wizard.goTo(0);
            },
        });
    };

    const empName =
        staff.find((s) => String(s.id) === form.data.employee_user_id)?.name ??
        '—';
    const mgrName =
        staff.find((s) => String(s.id) === form.data.manager_user_id)?.name ??
        '—';

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New development plan"
            description="Create a growth and competency plan for an employee."
            railIcon={Sprout}
            railTitle="New development plan"
            railSub="Growth & competency"
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
                        <>
                            <button
                                type="button"
                                onClick={() => submit(true)}
                                disabled={!canSubmit || form.processing}
                                className="rounded-md border border-border bg-card px-3 py-2 text-sm font-semibold text-foreground hover:bg-muted disabled:opacity-50"
                            >
                                Save &amp; add another
                            </button>
                            <button
                                type="button"
                                onClick={() => submit(false)}
                                disabled={!canSubmit || form.processing}
                                className="rounded-md bg-status-success px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                            >
                                {form.processing ? 'Saving…' : 'Create plan'}
                            </button>
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            disabled={
                                (wizard.index === 0 && !step0Valid) ||
                                (wizard.index === 1 && !step1Valid)
                            }
                            className="rounded-md bg-status-success px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            Continue
                        </button>
                    )}
                </>
            }
        >
            {stepKey === 'person' && (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title="Person & manager"
                        blurb="Who is the plan for, and who supports it?"
                    />
                    <div className="grid max-w-xl gap-4 sm:grid-cols-2">
                        <Field
                            label="Employee"
                            required
                            error={form.errors.employee_user_id}
                        >
                            <SelectInput
                                value={form.data.employee_user_id}
                                onChange={(v) =>
                                    form.setData('employee_user_id', v)
                                }
                                placeholder="Select…"
                                options={staffOptions}
                            />
                        </Field>
                        <Field
                            label="Manager"
                            required
                            error={form.errors.manager_user_id}
                        >
                            <SelectInput
                                value={form.data.manager_user_id}
                                onChange={(v) =>
                                    form.setData('manager_user_id', v)
                                }
                                placeholder="Select…"
                                options={staffOptions}
                            />
                        </Field>
                        <Field
                            label="Link to OKR objective"
                            hint="optional"
                            span
                            error={form.errors.hr_goal_id}
                        >
                            <SelectInput
                                value={form.data.hr_goal_id}
                                onChange={(v) => form.setData('hr_goal_id', v)}
                                placeholder="None"
                                options={objectiveOptions}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {stepKey === 'focus' && (
                <WizardStepPane>
                    <StepHead
                        icon={Target}
                        title="Focus & level"
                        blurb="The competency and where they're heading."
                    />
                    <div className="flex max-w-2xl flex-col gap-4">
                        {competencies.length > 0 && (
                            <Field
                                label="Linked competency"
                                hint="optional — pulls from the Competencies module"
                            >
                                <SelectInput
                                    value={form.data.competency_id}
                                    onChange={(v) => {
                                        form.setData('competency_id', v);
                                        const name = competencies.find(
                                            (c) => String(c.id) === v,
                                        )?.name;
                                        if (
                                            name &&
                                            !form.data.competency_area.trim()
                                        )
                                            form.setData(
                                                'competency_area',
                                                name,
                                            );
                                    }}
                                    placeholder="None"
                                    options={[
                                        { value: NONE, label: 'None' },
                                        ...competencies.map((c) => ({
                                            value: String(c.id),
                                            label: c.name,
                                        })),
                                    ]}
                                />
                            </Field>
                        )}
                        <Field
                            label="Competency area"
                            required
                            error={form.errors.competency_area}
                        >
                            <Input
                                value={form.data.competency_area}
                                onChange={(e) =>
                                    form.setData(
                                        'competency_area',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Medication administration"
                            />
                        </Field>
                        <Field
                            label="Category"
                            required
                            error={form.errors.category}
                        >
                            <Segmented
                                value={form.data.category}
                                onChange={(v) => form.setData('category', v)}
                                options={CATS}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Current level">
                                <LevelPicker
                                    value={form.data.current_level}
                                    onChange={(n) =>
                                        form.setData('current_level', n)
                                    }
                                />
                            </Field>
                            <Field label="Target level">
                                <LevelPicker
                                    value={form.data.target_level}
                                    onChange={(n) =>
                                        form.setData('target_level', n)
                                    }
                                />
                            </Field>
                        </div>
                        <Field label="Development plan" hint="optional">
                            <Textarea
                                rows={3}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Actions, supports and milestones…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {stepKey === 'cadence' && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarClock}
                        title="Cadence & review"
                        blurb="How often you'll review progress."
                    />
                    <div className="grid max-w-xl gap-4 sm:grid-cols-2">
                        <Field
                            label="Review frequency"
                            required
                            error={form.errors.review_frequency}
                        >
                            <SelectInput
                                value={form.data.review_frequency}
                                onChange={(v) =>
                                    form.setData('review_frequency', v)
                                }
                                placeholder="Select…"
                                options={[
                                    { value: 'weekly', label: 'Weekly' },
                                    {
                                        value: 'fortnightly',
                                        label: 'Fortnightly',
                                    },
                                    { value: 'monthly', label: 'Monthly' },
                                    { value: 'quarterly', label: 'Quarterly' },
                                ]}
                            />
                        </Field>
                        <Field
                            label="First review"
                            required
                            error={form.errors.due_date}
                        >
                            <Input
                                type="date"
                                value={form.data.due_date}
                                onChange={(e) =>
                                    form.setData('due_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Notes" hint="optional" span>
                            <Textarea
                                rows={2}
                                value={form.data.review_notes}
                                onChange={(e) =>
                                    form.setData('review_notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {stepKey === 'review' && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & create"
                        blurb="Confirm the development plan."
                    />
                    <div className="max-w-md">
                        <ReviewCard
                            icon={Sprout}
                            title="Development plan"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Employee" value={empName} />
                            <ReviewRow label="Manager" value={mgrName} />
                            <ReviewRow
                                label="Competency"
                                value={form.data.competency_area || undefined}
                            />
                            <ReviewRow
                                label="Category"
                                value={
                                    CATS.find(
                                        (c) => c.value === form.data.category,
                                    )?.label
                                }
                            />
                            <ReviewRow
                                label="Level"
                                value={`${form.data.current_level} → ${form.data.target_level}`}
                            />
                            <ReviewRow
                                label="Review"
                                value={form.data.review_frequency}
                            />
                            <ReviewRow
                                label="First review"
                                value={form.data.due_date || undefined}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default DevelopmentWizard;
