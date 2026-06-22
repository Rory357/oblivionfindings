/* eslint-disable no-restricted-syntax -- The wizard footer + the recruitment
 * toggle row use native <button>/<label> to match the Add-Client modal chrome
 * (see components/wizard/shell.tsx). All colours are semantic design tokens. */
import { useForm } from '@inertiajs/react';
import { Briefcase, ClipboardList, FileText, GitBranch } from 'lucide-react';

import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from './wizard';

export interface PositionParent {
    id: number;
    title: string;
    code: string;
}

export interface PositionRow {
    id: number;
    title: string;
    code: string;
    department: string | null;
    team: string | null;
    employment_type: string;
    fte: number;
    headcount_budget: number;
    is_active: boolean;
    description?: string | null;
    requirements?: string | null;
    summary?: string | null;
    responsibilities?: string | null;
    reports_to_position_id?: number | null;
}

const STEPS: readonly WizardStep[] = [
    { key: 'role', label: 'Role', blurb: 'Title, code & type', icon: Briefcase },
    {
        key: 'jd',
        label: 'Job description',
        blurb: 'Summary & criteria',
        icon: FileText,
    },
    {
        key: 'structure',
        label: 'Structure',
        blurb: 'Reporting & recruitment',
        icon: GitBranch,
    },
];

const TYPE_OPTIONS = [
    { value: 'full_time', label: 'Full time' },
    { value: 'part_time', label: 'Part time' },
    { value: 'casual', label: 'Casual' },
    { value: 'fixed_term', label: 'Fixed term' },
] as const;

export function PositionDialog({
    open,
    onClose,
    position,
    parentPositions,
    departments,
    canRecruit = false,
}: {
    open: boolean;
    onClose: () => void;
    position: PositionRow | null;
    parentPositions: PositionParent[];
    departments: { id: number; name: string }[];
    canRecruit?: boolean;
}) {
    const isEdit = !!position;
    const wizard = useWizard(STEPS.length);

    const form = useForm({
        title: position?.title ?? '',
        code: position?.code ?? '',
        department: position?.department ?? '',
        team: position?.team ?? '',
        summary: position?.summary ?? '',
        responsibilities: position?.responsibilities ?? '',
        requirements: position?.requirements ?? '',
        description: position?.description ?? '',
        employment_type: position?.employment_type ?? 'full_time',
        fte: position ? String(position.fte) : '1.00',
        headcount_budget: position ? String(position.headcount_budget) : '1',
        reports_to_position_id: position?.reports_to_position_id
            ? String(position.reports_to_position_id)
            : '',
        is_active: position?.is_active ?? true,
        open_requisition: false,
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const submit = () => {
        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (
                    form.errors.title ||
                    form.errors.code ||
                    form.errors.employment_type ||
                    form.errors.fte ||
                    form.errors.headcount_budget
                ) {
                    wizard.goTo(0);
                } else if (
                    form.errors.summary ||
                    form.errors.responsibilities ||
                    form.errors.requirements ||
                    form.errors.description
                ) {
                    wizard.goTo(1);
                } else {
                    wizard.goTo(2);
                }
            },
        };
        if (isEdit) form.put(`/hr/positions/${position!.id}`, opts);
        else form.post('/hr/positions', opts);
    };

    const canSubmit =
        form.data.title.trim() !== '' && form.data.code.trim() !== '';

    // Exclude self from the parent list when editing (prevent self-reference).
    const parentOptions = parentPositions
        .filter((p) => !position || p.id !== position.id)
        .map((p) => ({ value: String(p.id), label: `${p.title} (${p.code})` }));

    const budgetN = Math.max(1, parseInt(form.data.headcount_budget, 10) || 1);
    const showRecruitToggle = !isEdit && canRecruit;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit position' : 'New position'}
            description="Define a job position, its job description, headcount budget and reporting line."
            railIcon={Briefcase}
            railTitle={isEdit ? 'Edit position' : 'New position'}
            railSub="Org structure"
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
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save position'
                                  : 'Create position'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
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
                        icon={Briefcase}
                        title="Role basics"
                        blurb="The position title, a unique code, and how it's employed."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Title" required error={form.errors.title}>
                            <Input
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="e.g. Senior Support Worker"
                            />
                        </Field>
                        <Field label="Code" required error={form.errors.code}>
                            <Input
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                placeholder="e.g. SSW-001"
                            />
                        </Field>
                        <Field
                            label="Department"
                            hint="optional"
                            error={form.errors.department}
                        >
                            <SelectInput
                                value={form.data.department}
                                onChange={(v) => form.setData('department', v)}
                                placeholder="Select a department"
                                options={departments.map((d) => ({
                                    value: d.name,
                                    label: d.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Team"
                            hint="optional"
                            error={form.errors.team}
                        >
                            <Input
                                value={form.data.team}
                                onChange={(e) =>
                                    form.setData('team', e.target.value)
                                }
                                placeholder="e.g. Team A"
                            />
                        </Field>
                        <Field
                            label="Employment type"
                            required
                            span
                            error={form.errors.employment_type}
                        >
                            <Segmented
                                value={form.data.employment_type}
                                onChange={(v) =>
                                    form.setData('employment_type', v)
                                }
                                options={TYPE_OPTIONS.map((t) => ({
                                    value: t.value,
                                    label: t.label,
                                }))}
                            />
                        </Field>
                        <Field label="FTE" required error={form.errors.fte}>
                            <Input
                                type="number"
                                min="0.01"
                                max="1.00"
                                step="0.01"
                                value={form.data.fte}
                                onChange={(e) =>
                                    form.setData('fte', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Headcount budget"
                            required
                            error={form.errors.headcount_budget}
                        >
                            <Input
                                type="number"
                                min="1"
                                max="999"
                                step="1"
                                value={form.data.headcount_budget}
                                onChange={(e) =>
                                    form.setData(
                                        'headcount_budget',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Job description"
                        blurb="The canonical description for this role — it flows into any requisition and public posting."
                    />
                    <div className="grid gap-4">
                        <Field
                            label="Role summary"
                            hint="optional"
                            error={form.errors.summary}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.summary}
                                onChange={(e) =>
                                    form.setData('summary', e.target.value)
                                }
                                placeholder="A short overview of the role and its purpose…"
                            />
                        </Field>
                        <Field
                            label="Key responsibilities"
                            hint="optional"
                            error={form.errors.responsibilities}
                        >
                            <Textarea
                                rows={4}
                                value={form.data.responsibilities}
                                onChange={(e) =>
                                    form.setData(
                                        'responsibilities',
                                        e.target.value,
                                    )
                                }
                                placeholder="What this role is accountable for, one per line…"
                            />
                        </Field>
                        <Field
                            label="Essential criteria"
                            hint="optional"
                            error={form.errors.requirements}
                        >
                            <Textarea
                                rows={4}
                                value={form.data.requirements}
                                onChange={(e) =>
                                    form.setData('requirements', e.target.value)
                                }
                                placeholder="Required qualifications, experience, vetting, right-to-work…"
                            />
                        </Field>
                        <Field
                            label="Preferred"
                            hint="optional"
                            error={form.errors.description}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Nice-to-have skills or experience…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={GitBranch}
                        title="Structure & recruitment"
                        blurb="Where this position sits, and whether to start recruiting for it now."
                    />
                    <div className="grid gap-4">
                        <Field
                            label="Reports to"
                            hint="optional"
                            error={form.errors.reports_to_position_id}
                        >
                            <SelectInput
                                value={form.data.reports_to_position_id}
                                onChange={(v) =>
                                    form.setData('reports_to_position_id', v)
                                }
                                placeholder="Select a parent position"
                                options={parentOptions}
                            />
                        </Field>
                        {isEdit && (
                            <Field label="Status">
                                <Segmented
                                    value={
                                        form.data.is_active
                                            ? 'active'
                                            : 'inactive'
                                    }
                                    onChange={(v) =>
                                        form.setData(
                                            'is_active',
                                            v === 'active',
                                        )
                                    }
                                    options={[
                                        { value: 'active', label: 'Active' },
                                        {
                                            value: 'inactive',
                                            label: 'Inactive',
                                        },
                                    ]}
                                />
                            </Field>
                        )}
                        {showRecruitToggle && (
                            <InfoCard icon={ClipboardList} tone="info">
                                <label className="flex cursor-pointer items-center justify-between gap-4">
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold text-foreground">
                                            Open a job requisition for{' '}
                                            {budgetN}{' '}
                                            {budgetN === 1
                                                ? 'vacancy'
                                                : 'vacancies'}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            Creates a draft requisition prefilled
                                            from this position, ready to publish.
                                        </span>
                                    </span>
                                    <Switch
                                        checked={form.data.open_requisition}
                                        onCheckedChange={(v) =>
                                            form.setData('open_requisition', v)
                                        }
                                    />
                                </label>
                            </InfoCard>
                        )}
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default PositionDialog;
