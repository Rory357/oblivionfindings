/* eslint-disable no-restricted-syntax -- Wizard footer + file input use native
 * elements to match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import { Banknote, ClipboardCheck, FileText, HandCoins } from 'lucide-react';

import { Dialog, DialogContent } from '@/components/ui/dialog';
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

export interface OfferSite {
    id: number;
    name: string;
}
export interface OfferRole {
    value: string;
    label: string;
}

const STEPS: readonly WizardStep[] = [
    {
        key: 'comp',
        label: 'Role & pay',
        blurb: 'Position & rate',
        icon: HandCoins,
    },
    { key: 'terms', label: 'Terms', blurb: 'Dates & site', icon: Banknote },
    {
        key: 'letter',
        label: 'Letter',
        blurb: 'Attach offer doc',
        icon: FileText,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm & create',
        icon: ClipboardCheck,
    },
];

const TYPE_OPTIONS = [
    { value: 'full_time', label: 'Full time' },
    { value: 'part_time', label: 'Part time' },
    { value: 'casual', label: 'Casual' },
    { value: 'fixed_term', label: 'Fixed term' },
    { value: 'contractor', label: 'Contractor' },
] as const;

const WEEKS = 52;

export function OfferWizardDialog({
    open,
    onClose,
    applicationId,
    positionTitle = '',
    positionRole = '',
    sites,
    roles,
}: {
    open: boolean;
    onClose: () => void;
    applicationId: number;
    positionTitle?: string;
    positionRole?: string | null;
    sites: OfferSite[];
    roles: OfferRole[];
}) {
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        application_id: number;
        position_title: string;
        position_role: string;
        proposed_start_date: string;
        primary_site_id: string;
        employment_type: string;
        hours_per_week: string;
        hourly_rate: string;
        annual_salary: string;
        conditions: string;
        offer_letter: File | null;
    }>({
        application_id: applicationId,
        position_title: positionTitle,
        position_role: positionRole ?? '',
        proposed_start_date: '',
        primary_site_id: '',
        employment_type: 'full_time',
        hours_per_week: '',
        hourly_rate: '',
        annual_salary: '',
        conditions: '',
        offer_letter: null,
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const hours = parseFloat(form.data.hours_per_week) || 0;

    const setRate = (v: string) => {
        const rate = parseFloat(v);
        const annual =
            !Number.isNaN(rate) && hours > 0
                ? (rate * hours * WEEKS).toFixed(2)
                : form.data.annual_salary;
        form.setData({ ...form.data, hourly_rate: v, annual_salary: annual });
    };
    const setAnnual = (v: string) => {
        const annual = parseFloat(v);
        const rate =
            !Number.isNaN(annual) && hours > 0
                ? (annual / (hours * WEEKS)).toFixed(2)
                : form.data.hourly_rate;
        form.setData({ ...form.data, annual_salary: v, hourly_rate: rate });
    };

    const submit = () => {
        form.post('/hr/recruitment/offers', {
            forceFormData: true,
            preserveScroll: true,
            onError: () => {
                if (
                    form.errors.position_title ||
                    form.errors.employment_type ||
                    form.errors.hours_per_week ||
                    form.errors.position_role
                ) {
                    wizard.goTo(0);
                } else if (
                    form.errors.proposed_start_date ||
                    form.errors.primary_site_id
                ) {
                    wizard.goTo(1);
                }
            },
            // Success redirects to the candidate page (Inertia follows it).
        });
    };

    const canSubmit =
        form.data.position_title.trim() !== '' &&
        form.data.proposed_start_date !== '' &&
        form.data.primary_site_id !== '' &&
        hours > 0;

    const siteName =
        sites.find((s) => String(s.id) === form.data.primary_site_id)?.name ??
        '—';
    const roleLabel =
        roles.find((r) => r.value === form.data.position_role)?.label ?? '—';
    const typeLabel =
        TYPE_OPTIONS.find((t) => t.value === form.data.employment_type)
            ?.label ?? '—';

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Create offer"
            description="Draft an employment offer for this candidate."
            railIcon={HandCoins}
            railTitle="Create offer"
            railSub="Recruitment"
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
                            {form.processing ? 'Creating…' : 'Create offer'}
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
                        icon={HandCoins}
                        title="Role & pay"
                        blurb="The position being offered and the remuneration."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Position title"
                            required
                            span
                            error={form.errors.position_title}
                        >
                            <Input
                                value={form.data.position_title}
                                onChange={(e) =>
                                    form.setData(
                                        'position_title',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. Senior Support Worker"
                            />
                        </Field>
                        <Field
                            label="Access role"
                            hint="optional"
                            error={form.errors.position_role}
                        >
                            <SelectInput
                                value={form.data.position_role}
                                onChange={(v) =>
                                    form.setData('position_role', v)
                                }
                                placeholder="Select a role"
                                options={roles}
                            />
                        </Field>
                        <Field
                            label="Employment type"
                            required
                            error={form.errors.employment_type}
                        >
                            <SelectInput
                                value={form.data.employment_type}
                                onChange={(v) =>
                                    form.setData('employment_type', v)
                                }
                                placeholder="Select a type"
                                options={TYPE_OPTIONS.map((t) => ({
                                    value: t.value,
                                    label: t.label,
                                }))}
                            />
                        </Field>
                        <Field
                            label="Hours per week"
                            required
                            error={form.errors.hours_per_week}
                        >
                            <Input
                                type="number"
                                min="1"
                                max="60"
                                step="0.5"
                                value={form.data.hours_per_week}
                                onChange={(e) =>
                                    form.setData(
                                        'hours_per_week',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Hourly rate (NZD)"
                            hint="optional"
                            error={form.errors.hourly_rate}
                        >
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.hourly_rate}
                                onChange={(e) => setRate(e.target.value)}
                            />
                        </Field>
                        <Field
                            label="Annual salary (NZD)"
                            hint="auto-calculates"
                            error={form.errors.annual_salary}
                        >
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                value={form.data.annual_salary}
                                onChange={(e) => setAnnual(e.target.value)}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Banknote}
                        title="Terms & placement"
                        blurb="Start date, primary site, and any conditions of the offer."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Proposed start date"
                            required
                            error={form.errors.proposed_start_date}
                        >
                            <Input
                                type="date"
                                value={form.data.proposed_start_date}
                                onChange={(e) =>
                                    form.setData(
                                        'proposed_start_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Primary site"
                            required
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
                            label="Conditions"
                            hint="optional"
                            span
                            error={form.errors.conditions}
                        >
                            <Textarea
                                rows={4}
                                value={form.data.conditions}
                                onChange={(e) =>
                                    form.setData('conditions', e.target.value)
                                }
                                placeholder="e.g. Subject to satisfactory reference & police checks…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Offer letter"
                        blurb="Optionally attach a signed/prepared offer letter (PDF or Word)."
                    />
                    <Field
                        label="Offer letter"
                        hint="optional · PDF/DOC, max 20MB"
                        error={form.errors.offer_letter}
                    >
                        <input
                            type="file"
                            accept=".pdf,.doc,.docx"
                            onChange={(e) =>
                                form.setData(
                                    'offer_letter',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-primary"
                        />
                    </Field>
                    {form.data.offer_letter ? (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Selected: {form.data.offer_letter.name}
                        </p>
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & create"
                        blurb="The offer is created as a draft — you can approve and send it next."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={HandCoins}
                            title="Role & pay"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow
                                label="Position"
                                value={form.data.position_title}
                            />
                            <ReviewRow label="Role" value={roleLabel} />
                            <ReviewRow label="Type" value={typeLabel} />
                            <ReviewRow
                                label="Hours/wk"
                                value={form.data.hours_per_week}
                            />
                            <ReviewRow
                                label="Hourly"
                                value={
                                    form.data.hourly_rate
                                        ? `$${form.data.hourly_rate}`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Annual"
                                value={
                                    form.data.annual_salary
                                        ? `$${form.data.annual_salary}`
                                        : undefined
                                }
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Banknote}
                            title="Terms"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Start"
                                value={form.data.proposed_start_date}
                            />
                            <ReviewRow label="Site" value={siteName} />
                            <ReviewRow
                                label="Conditions"
                                value={form.data.conditions}
                            />
                            <ReviewRow
                                label="Letter"
                                value={form.data.offer_letter?.name}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/** Record a candidate's response to a sent offer (replaces the prompt()). */
export function OfferRespondDialog({
    open,
    onClose,
    offerId,
}: {
    open: boolean;
    onClose: () => void;
    offerId: number;
}) {
    const form = useForm({
        response: 'accepted',
        response_notes: '',
        signature_name: '',
        terms_accepted: false as boolean,
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/hr/recruitment/offers/${offerId}/respond`, {
            preserveScroll: true,
            onSuccess: close,
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-4">
                    <div>
                        <h2 className="text-lg font-bold">
                            Record offer response
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Capture how the candidate responded to the offer.
                        </p>
                    </div>
                    <Field label="Response">
                        <Segmented
                            value={form.data.response}
                            onChange={(v) => form.setData('response', v)}
                            options={[
                                { value: 'accepted', label: 'Accepted' },
                                { value: 'declined', label: 'Declined' },
                                { value: 'withdrawn', label: 'Withdrawn' },
                            ]}
                        />
                    </Field>
                    <Field
                        label="Notes"
                        hint="optional"
                        error={form.errors.response_notes}
                    >
                        <Textarea
                            rows={3}
                            value={form.data.response_notes}
                            onChange={(e) =>
                                form.setData('response_notes', e.target.value)
                            }
                            placeholder="Any notes about the response…"
                        />
                    </Field>
                    {form.data.response === 'accepted' && (
                        <>
                            <Field
                                label="Signature name"
                                hint="optional"
                                error={form.errors.signature_name}
                            >
                                <Input
                                    value={form.data.signature_name}
                                    onChange={(e) =>
                                        form.setData(
                                            'signature_name',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Full name as signed"
                                />
                            </Field>
                            {form.data.signature_name.trim() !== '' && (
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={form.data.terms_accepted}
                                        onChange={(e) =>
                                            form.setData(
                                                'terms_accepted',
                                                e.target.checked,
                                            )
                                        }
                                        className="rounded border-border"
                                    />
                                    Candidate accepted the terms
                                </label>
                            )}
                        </>
                    )}
                    <div className="flex justify-end gap-2 pt-2">
                        <button
                            type="button"
                            onClick={close}
                            className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50"
                        >
                            {form.processing ? 'Saving…' : 'Record response'}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default OfferWizardDialog;
