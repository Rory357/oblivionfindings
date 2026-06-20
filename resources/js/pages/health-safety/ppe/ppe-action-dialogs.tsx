/**
 * PPE single-step action modals — Return PPE, Record inspection, Condemn, Dispose.
 * Each is a one-step `WizardShell` (Add-Client shell family) owning its own form +
 * submit. Every onSuccess applies the mandatory flash-error guard (a blocked
 * action returns 302 + flash.error which fires onSuccess, not onError).
 */
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import { WizardShell, type WizardStep } from '@/components/wizard/shell';
import { type Page } from '@inertiajs/core';
import { useForm } from '@inertiajs/react';
import { Ban, ClipboardCheck, Reply, Trash2 } from 'lucide-react';
import { type FormEvent } from 'react';

const guarded = (onClose: () => void) => (page: Page) => {
    if (!(page.props as { flash?: { error?: string } }).flash?.error) onClose();
};

function ActionShell({
    open,
    onClose,
    title,
    description,
    railIcon,
    railTitle,
    railSub,
    step,
    processing,
    submitLabel,
    onSubmit,
    children,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description: string;
    railIcon: WizardStep['icon'];
    railTitle: string;
    railSub: string;
    step: WizardStep;
    processing: boolean;
    submitLabel: string;
    onSubmit: (e: FormEvent) => void;
    children: React.ReactNode;
}) {
    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={title}
            description={description}
            railIcon={railIcon}
            railTitle={railTitle}
            railSub={railSub}
            steps={[step]}
            stepIndex={0}
            onStepClick={() => {}}
            pct={null}
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Cancel
                </Button>
            }
            footerEnd={
                <Button onClick={onSubmit} disabled={processing}>
                    {submitLabel}
                </Button>
            }
        >
            <form onSubmit={onSubmit} className="flex flex-col gap-4">
                {children}
            </form>
        </WizardShell>
    );
}

type DocDraft = { file: File; kind: string; note: string };

// ───────────────────────── Return PPE ─────────────────────────

export function ReturnDialog({
    open,
    onClose,
    allocationId,
    worker,
    itemLabel,
}: {
    open: boolean;
    onClose: () => void;
    allocationId: number;
    worker: string;
    itemLabel: string;
}) {
    const form = useForm<{ condition: string; notes: string }>({
        condition: 'good',
        notes: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/ppe/allocations/${allocationId}/return`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: guarded(onClose),
        });
    };
    return (
        <ActionShell
            open={open}
            onClose={onClose}
            title="Return PPE"
            description="Record the condition of returned PPE."
            railIcon={Reply}
            railTitle="Return PPE"
            railSub={worker}
            step={{
                key: 'return',
                label: 'Return PPE',
                blurb: 'Condition on return',
                icon: Reply,
            }}
            processing={form.processing}
            submitLabel="Confirm return"
            onSubmit={submit}
        >
            <StepHead
                icon={Reply}
                title="Return PPE"
                blurb="Grade the item so condemned stock is flagged for disposal."
            />
            <InfoCard icon={Reply}>
                Returning <span className="font-semibold">{itemLabel}</span>{' '}
                from <span className="font-semibold">{worker}</span>.
            </InfoCard>
            <Field
                label="Condition on return"
                required
                error={form.errors.condition}
            >
                <Segmented
                    value={form.data.condition}
                    onChange={(v) => form.setData('condition', v)}
                    options={[
                        { value: 'good', label: 'Good' },
                        { value: 'fair', label: 'Fair' },
                        { value: 'poor', label: 'Poor' },
                        { value: 'condemned', label: 'Condemned' },
                    ]}
                />
            </Field>
            <Field label="Notes" hint="Optional" error={form.errors.notes}>
                <Textarea
                    rows={3}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    placeholder="Anything noted on return…"
                />
            </Field>
        </ActionShell>
    );
}

// ───────────────────────── Record inspection ─────────────────────────

type InspectionForm = {
    result: string;
    condition_after: string;
    findings: string;
    action_taken: string;
    next_inspection_due: string;
    documents: DocDraft[];
};

export function InspectionDialog({
    open,
    onClose,
    inventoryId,
    itemLabel,
}: {
    open: boolean;
    onClose: () => void;
    inventoryId: number;
    itemLabel: string;
}) {
    const form = useForm<InspectionForm>({
        result: 'pass',
        condition_after: 'good',
        findings: '',
        action_taken: '',
        next_inspection_due: '',
        documents: [],
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/ppe/inventory/${inventoryId}/inspections`, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: guarded(onClose),
        });
    };
    return (
        <ActionShell
            open={open}
            onClose={onClose}
            title="Record inspection"
            description="Log a PPE inspection result."
            railIcon={ClipboardCheck}
            railTitle="Record inspection"
            railSub={itemLabel}
            step={{
                key: 'inspect',
                label: 'Record inspection',
                blurb: 'Result & evidence',
                icon: ClipboardCheck,
            }}
            processing={form.processing}
            submitLabel="Save inspection"
            onSubmit={submit}
        >
            <StepHead
                icon={ClipboardCheck}
                title="Record inspection"
                blurb="Log the outcome; a fail or condemn flags the item automatically."
            />
            <Field label="Result" required error={form.errors.result}>
                <Segmented
                    value={form.data.result}
                    onChange={(v) => form.setData('result', v)}
                    options={[
                        { value: 'pass', label: 'Pass' },
                        { value: 'needs_repair', label: 'Needs repair' },
                        { value: 'fail', label: 'Fail' },
                        { value: 'condemned', label: 'Condemn' },
                    ]}
                />
            </Field>
            <Field label="Condition after" error={form.errors.condition_after}>
                <Segmented
                    value={form.data.condition_after}
                    onChange={(v) => form.setData('condition_after', v)}
                    options={[
                        { value: 'new', label: 'New' },
                        { value: 'good', label: 'Good' },
                        { value: 'fair', label: 'Fair' },
                        { value: 'poor', label: 'Poor' },
                    ]}
                />
            </Field>
            <Field label="Findings" error={form.errors.findings}>
                <Textarea
                    rows={3}
                    value={form.data.findings}
                    onChange={(e) => form.setData('findings', e.target.value)}
                    placeholder="What was checked and observed…"
                />
            </Field>
            <Field
                label="Next inspection due"
                error={form.errors.next_inspection_due}
            >
                <Input
                    type="date"
                    value={form.data.next_inspection_due}
                    onChange={(e) =>
                        form.setData('next_inspection_due', e.target.value)
                    }
                />
            </Field>
            <Field label="Photos & report" hint="Optional">
                <div className="flex flex-col gap-2">
                    <FileDropzone
                        onFiles={(files) =>
                            form.setData('documents', [
                                ...form.data.documents,
                                ...files.map((file) => ({
                                    file,
                                    kind: file.type.startsWith('image/')
                                        ? 'inspection_photo'
                                        : 'inspection_report',
                                    note: '',
                                })),
                            ])
                        }
                        accept="image/*,.pdf,.doc,.docx"
                        hint="Inspection photos and report — up to 20 MB each"
                    />
                    {form.data.documents.map((d, i) => (
                        <StagedFileCard
                            key={i}
                            file={d.file}
                            onRemove={() =>
                                form.setData(
                                    'documents',
                                    form.data.documents.filter(
                                        (_, idx) => idx !== i,
                                    ),
                                )
                            }
                        />
                    ))}
                </div>
            </Field>
        </ActionShell>
    );
}

// ───────────────────────── Condemn ─────────────────────────

export function CondemnDialog({
    open,
    onClose,
    inventoryId,
    itemLabel,
}: {
    open: boolean;
    onClose: () => void;
    inventoryId: number;
    itemLabel: string;
}) {
    const form = useForm<{ reason: string }>({ reason: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/ppe/inventory/${inventoryId}/condemn`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: guarded(onClose),
        });
    };
    return (
        <ActionShell
            open={open}
            onClose={onClose}
            title="Condemn item"
            description="Remove a PPE item from service."
            railIcon={Ban}
            railTitle="Condemn item"
            railSub={itemLabel}
            step={{
                key: 'condemn',
                label: 'Condemn item',
                blurb: 'Reason & audit',
                icon: Ban,
            }}
            processing={form.processing}
            submitLabel="Condemn item"
            onSubmit={submit}
        >
            <StepHead
                icon={Ban}
                title="Condemn item"
                blurb="Removes this item from service and flags it for disposal."
            />
            <InfoCard icon={Ban} tone="crit">
                Condemning removes this item from service. It moves to “awaiting
                disposal”. Return it from any worker first.
            </InfoCard>
            <Field label="Reason" required error={form.errors.reason}>
                <Textarea
                    rows={3}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="Why is this item being condemned?"
                />
            </Field>
        </ActionShell>
    );
}

// ───────────────────────── Dispose ─────────────────────────

const DISPOSAL_METHODS = [
    { value: 'General waste', label: 'General waste' },
    {
        value: 'Hazardous waste contractor',
        label: 'Hazardous waste contractor',
    },
    { value: 'Returned to supplier', label: 'Returned to supplier' },
    { value: 'Recycled', label: 'Recycled' },
    { value: 'Destroyed on site', label: 'Destroyed on site' },
];

export function DisposeDialog({
    open,
    onClose,
    inventoryId,
    itemLabel,
}: {
    open: boolean;
    onClose: () => void;
    inventoryId: number;
    itemLabel: string;
}) {
    const form = useForm<{ disposal_method: string; reason: string }>({
        disposal_method: '',
        reason: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/health-safety/ppe/inventory/${inventoryId}/dispose`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: guarded(onClose),
        });
    };
    return (
        <ActionShell
            open={open}
            onClose={onClose}
            title="Dispose item"
            description="Archive a condemned PPE item."
            railIcon={Trash2}
            railTitle="Dispose item"
            railSub={itemLabel}
            step={{
                key: 'dispose',
                label: 'Dispose item',
                blurb: 'Method & record',
                icon: Trash2,
            }}
            processing={form.processing}
            submitLabel="Record disposal"
            onSubmit={submit}
        >
            <StepHead
                icon={Trash2}
                title="Dispose item"
                blurb="Record how this condemned item was disposed of."
            />
            <Field
                label="Disposal method"
                required
                error={form.errors.disposal_method}
            >
                <SelectInput
                    value={form.data.disposal_method}
                    onChange={(v) => form.setData('disposal_method', v)}
                    placeholder="Choose a method"
                    options={DISPOSAL_METHODS}
                />
            </Field>
            <Field label="Notes" hint="Optional" error={form.errors.reason}>
                <Textarea
                    rows={3}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder="Disposal reference, contractor, date collected…"
                />
            </Field>
        </ActionShell>
    );
}
