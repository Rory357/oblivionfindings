/* eslint-disable no-restricted-syntax -- The wizard footer uses native <button>
 * elements to match the Add-Client modal chrome (see components/wizard/shell.tsx
 * and primitives.tsx, which do the same). */
import { useForm } from '@inertiajs/react';
import {
    Briefcase,
    ClipboardCheck,
    UserPlus,
    UsersRound,
} from 'lucide-react';

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

export interface AddEmployeeFormData {
    positions: { id: number; title: string }[];
    managers: { value: string; label: string }[];
    roles: { value: string; label: string }[];
    employmentTypes: { value: string; label: string }[];
}

const STEPS: readonly WizardStep[] = [
    { key: 'person', label: 'Person', blurb: 'Name & contact', icon: UsersRound },
    { key: 'job', label: 'Job', blurb: 'Role & placement', icon: Briefcase },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & create',
        icon: ClipboardCheck,
    },
];

export function AddEmployeeDialog({
    open,
    onClose,
    formData,
    departments,
    sites,
}: {
    open: boolean;
    onClose: () => void;
    formData: AddEmployeeFormData;
    departments: { id: number; name: string }[];
    sites: { id: number; name: string }[];
}) {
    const wizard = useWizard(STEPS.length);
    const form = useForm({
        name: '',
        email: '',
        preferred_name: '',
        role: 'support_worker',
        position_id: '',
        employment_type: 'full_time',
        department: '',
        primary_site_id: '',
        manager_user_id: '',
        start_date: '',
        work_phone: '',
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
            position_id: data.position_id || null,
            primary_site_id: data.primary_site_id || null,
            manager_user_id: data.manager_user_id || null,
        }));
        form.post('/hr/people', {
            preserveScroll: true,
            // On success the controller redirects to the new profile — Inertia
            // follows it, so no success pane is needed here.
            onError: () => {
                // Jump to the step that owns the first error.
                if (form.errors.name || form.errors.email) wizard.goTo(0);
                else wizard.goTo(1);
            },
        });
    };

    const canSubmit =
        form.data.name.trim() !== '' && form.data.email.trim() !== '';

    const managerOptions: PersonOption[] = formData.managers.map((m) => ({
        value: m.value,
        label: m.label,
    }));

    const positionLabel =
        formData.positions.find((p) => String(p.id) === form.data.position_id)
            ?.title ?? '—';
    const departmentLabel = form.data.department || '—';
    const siteLabel =
        sites.find((s) => String(s.id) === form.data.primary_site_id)?.name ??
        '—';
    const managerLabel =
        formData.managers.find((m) => m.value === form.data.manager_user_id)
            ?.label ?? '—';
    const roleLabel =
        formData.roles.find((r) => r.value === form.data.role)?.label ??
        form.data.role;
    const typeLabel =
        formData.employmentTypes.find(
            (t) => t.value === form.data.employment_type,
        )?.label ?? '—';

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Add employee"
            description="Create a new employee record and user account."
            railIcon={UserPlus}
            railTitle="Add employee"
            railSub="New team member"
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
                            {form.processing ? 'Adding…' : 'Add employee'}
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
                        icon={UsersRound}
                        title="Who are you adding?"
                        blurb="Their name and a work email — a sign-in account is created automatically."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Full name" required error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Ana Williams"
                            />
                        </Field>
                        <Field
                            label="Preferred name"
                            hint="optional"
                            error={form.errors.preferred_name}
                        >
                            <Input
                                value={form.data.preferred_name}
                                onChange={(e) =>
                                    form.setData(
                                        'preferred_name',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Ana"
                            />
                        </Field>
                        <Field
                            label="Work email"
                            required
                            error={form.errors.email}
                        >
                            <Input
                                type="email"
                                value={form.data.email}
                                onChange={(e) =>
                                    form.setData('email', e.target.value)
                                }
                                placeholder="ana@example.co.nz"
                            />
                        </Field>
                        <Field
                            label="Work phone"
                            hint="optional"
                            error={form.errors.work_phone}
                        >
                            <Input
                                value={form.data.work_phone}
                                onChange={(e) =>
                                    form.setData('work_phone', e.target.value)
                                }
                                placeholder="021 555 0000"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Briefcase}
                        title="Role & placement"
                        blurb="Set their access role, position, and where they work. All optional — you can refine later."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Access role" error={form.errors.role}>
                            <SelectInput
                                value={form.data.role}
                                onChange={(v) => form.setData('role', v)}
                                placeholder="Select a role"
                                options={formData.roles}
                            />
                        </Field>
                        <Field
                            label="Position"
                            hint="optional"
                            error={form.errors.position_id}
                        >
                            <SelectInput
                                value={form.data.position_id}
                                onChange={(v) =>
                                    form.setData('position_id', v)
                                }
                                placeholder="Select a position"
                                options={formData.positions.map((p) => ({
                                    value: String(p.id),
                                    label: p.title,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Employment type"
                            hint="optional"
                            error={form.errors.employment_type}
                        >
                            <SelectInput
                                value={form.data.employment_type}
                                onChange={(v) =>
                                    form.setData('employment_type', v)
                                }
                                placeholder="Select a type"
                                options={formData.employmentTypes}
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
                            label="Primary site"
                            hint="optional"
                            error={form.errors.primary_site_id}
                        >
                            <SelectInput
                                value={form.data.primary_site_id}
                                onChange={(v) =>
                                    form.setData('primary_site_id', v)
                                }
                                placeholder="Select a site"
                                options={sites.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Start date"
                            hint="optional"
                            error={form.errors.start_date}
                        >
                            <Input
                                type="date"
                                value={form.data.start_date}
                                onChange={(e) =>
                                    form.setData('start_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Reports to"
                            hint="optional"
                            span
                            error={form.errors.manager_user_id}
                        >
                            <PeoplePicker
                                value={form.data.manager_user_id}
                                onChange={(v) =>
                                    form.setData('manager_user_id', v)
                                }
                                people={managerOptions}
                                placeholder="Select a manager"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & create"
                        blurb="Confirm the details. A user account is created and an invite can be sent from their profile."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={UsersRound}
                            title="Person"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Name" value={form.data.name} />
                            <ReviewRow
                                label="Preferred"
                                value={form.data.preferred_name}
                            />
                            <ReviewRow label="Email" value={form.data.email} />
                            <ReviewRow
                                label="Phone"
                                value={form.data.work_phone}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Briefcase}
                            title="Job"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow label="Role" value={roleLabel} />
                            <ReviewRow label="Position" value={positionLabel} />
                            <ReviewRow label="Type" value={typeLabel} />
                            <ReviewRow
                                label="Department"
                                value={departmentLabel}
                            />
                            <ReviewRow label="Site" value={siteLabel} />
                            <ReviewRow
                                label="Start date"
                                value={form.data.start_date}
                            />
                            <ReviewRow label="Reports to" value={managerLabel} />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default AddEmployeeDialog;
