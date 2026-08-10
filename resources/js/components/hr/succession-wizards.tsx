import { useForm } from '@inertiajs/react';
import {
    Briefcase,
    CheckCircle2,
    ClipboardCheck,
    ShieldAlert,
    UserCircle2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { PeoplePicker, type PersonOption } from '@/components/hr/people-picker';
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
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';

export type SuccessionPositionOption = {
    id: number;
    title: string;
    department?: string | null;
};

export type SuccessionHolderOption = {
    id: number;
    name: string;
    email?: string | null;
    site_ids: number[];
};

export type SuccessionSiteOption = {
    id: number;
    name: string;
};

export type SuccessionPlanPrefill = {
    site_id: number;
    source_review_id: number;
    candidate: {
        employee_profile_id: number;
        name: string;
        readiness: string;
    };
};

export type ExistingSuccessionPlan = {
    id: number;
    role_title: string;
    department: string | null;
    risk_level: string;
    site: SuccessionSiteOption;
    current_holder: { id: number; name: string } | null;
    position: { id: number; title: string } | null;
    notes: string | null;
};

/** Radix Select crashes on value="" — sentinel for "no seat / vacant". */
const NONE = 'none';

const STEPS: readonly WizardStep[] = [
    { key: 'role', label: 'Role', blurb: 'Title & seat', icon: Briefcase },
    {
        key: 'risk',
        label: 'Risk & holder',
        blurb: 'Exposure & incumbent',
        icon: ShieldAlert,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & save',
        icon: CheckCircle2,
    },
];

const RISK_OPTIONS: { value: string; label: string }[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

const RISK_LABELS: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};

/**
 * Create / edit a succession plan in a WizardShell modal — replaces the
 * full-page /hr/succession/create form. Create POSTs hr.succession.store;
 * edit PUTs hr.succession.update. All original form fields are preserved
 * (role_title, department, risk_level, position_id, current_holder_user_id,
 * notes).
 */
export function SuccessionPlanWizard({
    onClose,
    positions,
    holders,
    sites,
    prefill,
    plan,
}: {
    onClose: () => void;
    positions: SuccessionPositionOption[];
    holders: SuccessionHolderOption[];
    sites: SuccessionSiteOption[];
    prefill?: SuccessionPlanPrefill | null;
    plan?: ExistingSuccessionPlan | null;
}) {
    const isEdit = !!plan;
    const wizard = useWizard(STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        site_id: plan
            ? String(plan.site.id)
            : prefill
              ? String(prefill.site_id)
              : '',
        role_title: plan?.role_title ?? '',
        department: plan?.department ?? '',
        risk_level: plan?.risk_level ?? 'medium',
        position_id: plan?.position ? String(plan.position.id) : NONE,
        current_holder_user_id: plan?.current_holder
            ? String(plan.current_holder.id)
            : NONE,
        notes: plan?.notes ?? '',
        candidates: prefill
            ? [
                  {
                      employee_profile_id:
                          prefill.candidate.employee_profile_id,
                      readiness: prefill.candidate.readiness,
                  },
              ]
            : [],
        source_review_id: prefill?.source_review_id ?? null,
    });

    const selectedSiteId = Number(form.data.site_id);
    const availableHolders = holders.filter((holder) =>
        holder.site_ids.includes(selectedSiteId),
    );
    const people: PersonOption[] = [
        { value: NONE, label: 'Vacant — no current holder' },
        ...availableHolders.map((h) => ({
            value: String(h.id),
            label: h.name,
            sub: h.email ?? undefined,
        })),
    ];

    const pickedPosition =
        positions.find((p) => String(p.id) === form.data.position_id) ?? null;
    const pickedHolder =
        holders.find(
            (h) => String(h.id) === form.data.current_holder_user_id,
        ) ?? null;
    const pickedSite = sites.find((site) => site.id === selectedSiteId) ?? null;

    const pickSite = (value: string) => {
        const nextSiteId = Number(value);
        form.setData((data) => ({
            ...data,
            site_id: value,
            current_holder_user_id:
                data.current_holder_user_id === NONE ||
                holders
                    .find(
                        (holder) =>
                            String(holder.id) === data.current_holder_user_id,
                    )
                    ?.site_ids.includes(nextSiteId)
                    ? data.current_holder_user_id
                    : NONE,
        }));
    };

    const pickPosition = (v: string) => {
        const pos = positions.find((p) => String(p.id) === v) ?? null;
        form.setData((d) => ({
            ...d,
            position_id: v,
            role_title: d.role_title === '' && pos ? pos.title : d.role_title,
            department:
                d.department === '' && pos?.department
                    ? pos.department
                    : d.department,
        }));
    };

    const roleValid =
        form.data.site_id !== '' && form.data.role_title.trim() !== '';

    const submit = () => {
        form.transform((d) => ({
            ...d,
            position_id: d.position_id === NONE ? null : d.position_id,
            current_holder_user_id:
                d.current_holder_user_id === NONE
                    ? null
                    : d.current_holder_user_id,
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: (page: unknown) => {
                const err = (page as { props?: { flash?: { error?: string } } })
                    .props?.flash?.error;
                if (err) {
                    toast.error(err);
                    return;
                }
                if (isEdit) {
                    toast.success('Succession plan updated.');
                    onClose();
                    return;
                }
                setDone(true);
                fireConfetti();
            },
            onError: (errors: Record<string, string>) => {
                if (
                    errors.site_id ||
                    errors.role_title ||
                    errors.department ||
                    errors.position_id
                ) {
                    wizard.goTo(0);
                } else if (
                    errors.risk_level ||
                    errors.current_holder_user_id ||
                    errors.notes ||
                    errors.source_review_id ||
                    Object.keys(errors).some((key) =>
                        key.startsWith('candidates.'),
                    )
                ) {
                    wizard.goTo(1);
                }
            },
        };

        if (isEdit) {
            form.put(`/hr/succession/${plan.id}`, opts);
        } else {
            form.post('/hr/succession', opts);
        }
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title={isEdit ? 'Edit succession plan' : 'New succession plan'}
            description={
                isEdit
                    ? `Update the plan for ${plan.role_title}.`
                    : 'Define a key role and its cover risk, then add successors from the plan page.'
            }
            railIcon={ShieldAlert}
            railTitle={isEdit ? 'Edit plan' : 'New plan'}
            railSub="Succession"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Succession plan created"
                        blurb={
                            <>
                                “{form.data.role_title}” is now on the
                                succession board. Open it to add candidates and
                                readiness assessments.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || !roleValid}
                        >
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Create plan'}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={wizard.index === 0 && !roleValid}
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Briefcase}
                        title="Which role does this plan cover?"
                        blurb="The key role you need succession cover for — link an establishment seat where one exists."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field
                            label="Site"
                            required
                            error={form.errors.site_id}
                            span
                        >
                            {isEdit || prefill ? (
                                <Input
                                    value={
                                        pickedSite?.name ?? 'Unavailable Site'
                                    }
                                    disabled
                                />
                            ) : (
                                <SelectInput
                                    value={form.data.site_id}
                                    onChange={pickSite}
                                    placeholder="Choose the Site this plan covers…"
                                    options={sites.map((site) => ({
                                        value: String(site.id),
                                        label: site.name,
                                    }))}
                                />
                            )}
                        </Field>
                        {positions.length > 0 ? (
                            <Field
                                label="Establishment seat"
                                hint="optional"
                                error={form.errors.position_id}
                                span
                            >
                                <SelectInput
                                    value={form.data.position_id}
                                    onChange={pickPosition}
                                    placeholder="Link a position…"
                                    options={[
                                        {
                                            value: NONE,
                                            label: 'No linked position',
                                        },
                                        ...positions.map((p) => ({
                                            value: String(p.id),
                                            label: p.department
                                                ? `${p.title} · ${p.department}`
                                                : p.title,
                                        })),
                                    ]}
                                />
                            </Field>
                        ) : null}
                        <Field
                            label="Role title"
                            required
                            error={form.errors.role_title}
                        >
                            <Input
                                value={form.data.role_title}
                                onChange={(e) =>
                                    form.setData('role_title', e.target.value)
                                }
                                placeholder="e.g. Head of Operations"
                            />
                        </Field>
                        <Field
                            label="Department"
                            hint="optional"
                            error={form.errors.department}
                        >
                            <Input
                                value={form.data.department}
                                onChange={(e) =>
                                    form.setData('department', e.target.value)
                                }
                                placeholder="e.g. Care"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ShieldAlert}
                        title="Risk & current holder"
                        blurb="How exposed is the organisation if this role empties out, and who holds it today?"
                    />
                    <div className="space-y-4">
                        <Field
                            label="Risk level"
                            required
                            error={form.errors.risk_level}
                        >
                            <Segmented
                                value={form.data.risk_level}
                                onChange={(v) => form.setData('risk_level', v)}
                                options={RISK_OPTIONS}
                            />
                        </Field>
                        <Field
                            label="Current holder"
                            hint="optional"
                            error={form.errors.current_holder_user_id}
                        >
                            <PeoplePicker
                                value={form.data.current_holder_user_id}
                                onChange={(v) =>
                                    form.setData('current_holder_user_id', v)
                                }
                                people={people}
                                placeholder="Vacant — no current holder"
                            />
                        </Field>
                        <Field
                            label="Notes"
                            hint="optional"
                            error={form.errors.notes}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="Context, cover arrangements, development focus…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review the plan"
                        blurb="Check the details, then save."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={Briefcase}
                            title="Role"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Site" value={pickedSite?.name} />
                            <ReviewRow
                                label="Title"
                                value={form.data.role_title}
                            />
                            <ReviewRow
                                label="Department"
                                value={form.data.department || undefined}
                            />
                            <ReviewRow
                                label="Seat"
                                value={pickedPosition?.title}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={UserCircle2}
                            title="Risk & holder"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Risk"
                                value={
                                    RISK_LABELS[form.data.risk_level] ??
                                    form.data.risk_level
                                }
                            />
                            <ReviewRow
                                label="Holder"
                                value={pickedHolder?.name ?? 'Vacant'}
                            />
                            {prefill ? (
                                <ReviewRow
                                    label="Initial candidate"
                                    value={`${prefill.candidate.name} · from signed-off review #${prefill.source_review_id}`}
                                />
                            ) : null}
                            <ReviewRow
                                label="Notes"
                                value={form.data.notes || undefined}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default SuccessionPlanWizard;
