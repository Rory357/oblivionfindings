import { useForm } from '@inertiajs/react';
import { History, ListChecks } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    ReviewCard,
    ReviewRow,
    StepHead,
    WizardShell,
    WizardSuccessPane,
    type WizardStep,
    useWizard,
} from './wizard';

type SectionKey =
    | 'include_journals'
    | 'include_bank_reconciliations'
    | 'include_ap'
    | 'include_ar'
    | 'include_gst'
    | 'include_fixed_assets';

const SECTIONS: { key: SectionKey; label: string; description: string; short: string }[] = [
    { key: 'include_journals', label: 'Journals & Journal Lines', description: 'All general ledger journals with line-level detail', short: 'Journals' },
    { key: 'include_bank_reconciliations', label: 'Bank Reconciliations', description: 'Bank statement reconciliation records', short: 'Bank Recon' },
    { key: 'include_ap', label: 'Accounts Payable', description: 'Bills, payment runs, and vendor transactions', short: 'AP' },
    { key: 'include_ar', label: 'Accounts Receivable', description: 'Payment allocations and receivable transactions', short: 'AR' },
    { key: 'include_gst', label: 'GST Returns', description: 'GST/tax return filings and calculations', short: 'GST' },
    { key: 'include_fixed_assets', label: 'Fixed Assets & Depreciation', description: 'Asset register and depreciation schedules', short: 'Assets' },
];

const STEPS: readonly WizardStep[] = [
    { key: 'setup', label: 'Setup', blurb: 'Period & sections', icon: History },
    { key: 'review', label: 'Review', blurb: 'Confirm & generate', icon: ListChecks },
];

const fmtDate = (d: string) =>
    new Date(`${d}T00:00:00`).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

/**
 * Audit Export wizard — configure an audit trail export as a stepper modal
 * (Setup → Review). Posts to `finance.audit-exports.store` with the same
 * payload as the retired Create page; generation runs in a background job, so
 * the success pane points back at the list where the download appears.
 */
export function AuditExportDialog({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;
    const [succeeded, setSucceeded] = useState(false);

    const form = useForm<{
        export_name: string;
        period_from: string;
        period_to: string;
        include_journals: boolean;
        include_bank_reconciliations: boolean;
        include_ap: boolean;
        include_ar: boolean;
        include_gst: boolean;
        include_fixed_assets: boolean;
        notes: string;
    }>({
        export_name: '',
        period_from: '',
        period_to: '',
        include_journals: true,
        include_bank_reconciliations: true,
        include_ap: true,
        include_ar: true,
        include_gst: true,
        include_fixed_assets: true,
        notes: '',
    });
    const { data, setData, processing, errors } = form;

    // Mirrors the backend `after_or_equal:period_from` rule.
    const rangeValid = !data.period_from || !data.period_to || data.period_to >= data.period_from;
    const setupValid = !!data.export_name.trim() && !!data.period_from && !!data.period_to && rangeValid;
    const selectedSections = SECTIONS.filter((s) => data[s.key]);

    const close = () => {
        setSucceeded(false);
        reset();
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.transform((d) => ({
            ...d,
            notes: d.notes || null,
        }));
        form.post('/finance/audit-exports', {
            preserveScroll: true,
            onSuccess: () => setSucceeded(true),
            onError: () => goTo(0),
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New audit export"
            description="Configure and generate an audit trail report for external auditors"
            railIcon={History}
            railTitle="New Export"
            railSub="Audit trail"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={setupValid ? 100 : 40}
            pctLabel="Export"
            success={succeeded ? (
                <WizardSuccessPane
                    title="Audit export queued"
                    blurb="The export is being generated in the background. It will appear in the list with a download link shortly."
                    actions={<Button onClick={close}>Done</Button>}
                />
            ) : undefined}
            footerEnd={
                <>
                    {!isFirst && (
                        <Button type="button" variant="outline" onClick={back} disabled={processing}>
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button type="button" onClick={next} disabled={!setupValid}>
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <Button type="button" onClick={submit} disabled={processing || !setupValid}>
                            Generate export
                        </Button>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={History} title="Export setup" blurb="Name the export, choose the period and the data sections." />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Export name" span required error={errors.export_name}>
                            <Input
                                value={data.export_name}
                                onChange={(e) => setData('export_name', e.target.value)}
                                placeholder="e.g. FY2025-26 Annual Audit"
                            />
                        </Field>
                        <Field label="Period from" required error={errors.period_from}>
                            <Input
                                type="date"
                                value={data.period_from}
                                onChange={(e) => setData('period_from', e.target.value)}
                            />
                        </Field>
                        <Field
                            label="Period to"
                            required
                            error={errors.period_to ?? (!rangeValid ? 'Must be on or after the from date.' : undefined)}
                        >
                            <Input
                                type="date"
                                value={data.period_to}
                                onChange={(e) => setData('period_to', e.target.value)}
                            />
                        </Field>
                        <Field label="Sections to include" span hint="each generates a separate CSV file">
                            <div className="space-y-2">
                                {SECTIONS.map((section) => (
                                    <label
                                        key={section.key}
                                        className="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-muted/50"
                                    >
                                        <Checkbox
                                            checked={data[section.key]}
                                            onCheckedChange={(checked) => setData(section.key, !!checked)}
                                            aria-label={section.label}
                                        />
                                        <span className="min-w-0">
                                            <span className="block text-sm font-medium">{section.label}</span>
                                            <span className="mt-0.5 block text-xs text-muted-foreground">{section.description}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </Field>
                        <Field label="Notes" span hint="optional" error={errors.notes}>
                            <Textarea
                                rows={2}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                placeholder="Optional notes about this export…"
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead icon={ListChecks} title="Review & generate" blurb="Generation runs in the background — download from the list when ready." />
                    <ReviewCard icon={History} title="Audit export">
                        <ReviewRow label="Name" value={data.export_name || '—'} />
                        <ReviewRow
                            label="Period"
                            value={data.period_from && data.period_to ? `${fmtDate(data.period_from)} — ${fmtDate(data.period_to)}` : '—'}
                        />
                        <ReviewRow
                            label="Sections"
                            value={selectedSections.length ? selectedSections.map((s) => s.short).join(', ') : 'None'}
                        />
                        {data.notes && <ReviewRow label="Notes" value={data.notes} />}
                    </ReviewCard>
                    {processing && <p className="mt-3 text-[13px] text-muted-foreground">Queueing…</p>}
                </div>
            )}
        </WizardShell>
    );
}

export default AuditExportDialog;
