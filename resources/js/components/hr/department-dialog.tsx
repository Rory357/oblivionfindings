/* eslint-disable no-restricted-syntax -- The wizard footer uses native <button>
 * elements to match the Add-Client modal chrome (see components/wizard/shell.tsx).
 * All colours are semantic design tokens. */
import { useForm } from '@inertiajs/react';
import { Building2, ClipboardCheck, GitBranch } from 'lucide-react';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

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
} from './wizard';

export interface Department {
    id: number;
    name: string;
    code: string | null;
    cost_centre?: string | null;
    description: string | null;
    manager_user_id: number | null;
    parent_id: number | null;
    is_active: boolean;
    sort_order: number;
    employees_count: number;
    manager?: { id: number; name: string } | null;
    parent?: { id: number; name: string } | null;
}

const STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Name, code & cost centre', icon: Building2 },
    { key: 'structure', label: 'Structure', blurb: 'Parent, head & order', icon: GitBranch },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ClipboardCheck },
];

/** Create/edit a department on the shared wizard shell. People-hub Departments tab. */
export function DepartmentDialog({
    open,
    onClose,
    department,
    managers,
    parentOptions,
}: {
    open: boolean;
    onClose: () => void;
    department: Department | null;
    managers: Array<{ id: number; name: string }>;
    parentOptions: Array<{ id: number; name: string }>;
}) {
    const isEdit = !!department;
    const wizard = useWizard(STEPS.length);

    const form = useForm({
        name: department?.name ?? '',
        code: department?.code ?? '',
        cost_centre: department?.cost_centre ?? '',
        description: department?.description ?? '',
        manager_user_id: department?.manager_user_id
            ? String(department.manager_user_id)
            : '',
        parent_id: department?.parent_id ? String(department.parent_id) : '',
        sort_order: department ? String(department.sort_order) : '0',
        is_active: department?.is_active ?? true,
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            manager_user_id: data.manager_user_id || null,
            parent_id: data.parent_id || null,
            sort_order: parseInt(data.sort_order, 10) || 0,
        }));
        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (form.errors.name || form.errors.code || form.errors.cost_centre) {
                    wizard.goTo(0);
                } else {
                    wizard.goTo(1); // parent_id (incl. cycle), manager, sort order
                }
            },
        };
        if (isEdit) form.put(`/hr/departments/${department!.id}`, opts);
        else form.post('/hr/departments', opts);
    };

    const canSubmit = form.data.name.trim() !== '';

    const parentLabel =
        parentOptions.find((p) => String(p.id) === form.data.parent_id)?.name ??
        '—';
    const managerLabel =
        managers.find((m) => String(m.id) === form.data.manager_user_id)?.name ??
        '—';

    const parentChoices = parentOptions
        .filter((p) => !department || p.id !== department.id)
        .map((p) => ({ value: String(p.id), label: p.name }));
    const managerChoices = managers.map((m) => ({
        value: String(m.id),
        label: m.name,
    }));

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit department' : 'New department'}
            description="Define an organisational unit — cost centre, hierarchy and head."
            railIcon={Building2}
            railTitle={isEdit ? 'Edit department' : 'New department'}
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
                                  ? 'Save department'
                                  : 'Create department'}
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
                        icon={Building2}
                        title="Department details"
                        blurb="The unit's name, an optional code, and the cost centre it reports against."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Name" required span error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Care Services"
                            />
                        </Field>
                        <Field label="Code" hint="optional" error={form.errors.code}>
                            <Input
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                placeholder="e.g. CS"
                            />
                        </Field>
                        <Field
                            label="Cost centre"
                            hint="optional"
                            error={form.errors.cost_centre}
                        >
                            <Input
                                value={form.data.cost_centre}
                                onChange={(e) =>
                                    form.setData('cost_centre', e.target.value)
                                }
                                placeholder="e.g. CC-4100"
                            />
                        </Field>
                        <Field
                            label="Description"
                            hint="optional"
                            span
                            error={form.errors.description}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                                placeholder="Brief description of the department…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={GitBranch}
                        title="Structure"
                        blurb="Where it sits in the hierarchy, who heads it, and its list order."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Parent department"
                            hint="optional"
                            error={form.errors.parent_id}
                        >
                            <SelectInput
                                value={form.data.parent_id}
                                onChange={(v) => form.setData('parent_id', v)}
                                placeholder="None (top-level)"
                                options={parentChoices}
                            />
                        </Field>
                        <Field
                            label="Department head"
                            hint="optional"
                            error={form.errors.manager_user_id}
                        >
                            <SelectInput
                                value={form.data.manager_user_id}
                                onChange={(v) =>
                                    form.setData('manager_user_id', v)
                                }
                                placeholder="Select a head"
                                options={managerChoices}
                            />
                        </Field>
                        <Field
                            label="Sort order"
                            hint="optional"
                            error={form.errors.sort_order}
                        >
                            <Input
                                type="number"
                                min="0"
                                value={form.data.sort_order}
                                onChange={(e) =>
                                    form.setData('sort_order', e.target.value)
                                }
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
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & save"
                        blurb="Confirm the department details."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={Building2}
                            title="Details"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Name" value={form.data.name} />
                            <ReviewRow label="Code" value={form.data.code} />
                            <ReviewRow
                                label="Cost centre"
                                value={form.data.cost_centre}
                            />
                            <ReviewRow
                                label="Description"
                                value={form.data.description}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={GitBranch}
                            title="Structure"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow label="Parent" value={parentLabel} />
                            <ReviewRow label="Head" value={managerLabel} />
                            <ReviewRow
                                label="Sort order"
                                value={form.data.sort_order}
                            />
                            {isEdit ? (
                                <ReviewRow
                                    label="Status"
                                    value={
                                        form.data.is_active
                                            ? 'Active'
                                            : 'Inactive'
                                    }
                                />
                            ) : null}
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default DepartmentDialog;
