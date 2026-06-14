import { useForm } from '@inertiajs/react';
import { BookOpen, FileText, ListChecks, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    FieldErr,
    Segmented,
    SelectInput,
    StepHead,
    WizardShell,
    type WizardStep,
    useWizard,
} from './wizard';
import { AmountField } from './money';
import { PostingPreview, journalBalance, type PostingLine } from './posting-preview';

type RefItem = { id: number; code: string; name: string };

type LineForm = {
    account_id: string;
    description: string;
    debit: string;
    credit: string;
    cost_centre_id: string;
    funding_stream_id: string;
    tax_rate_id: string;
    tax_amount: string;
};

const emptyLine = (): LineForm => ({
    account_id: '',
    description: '',
    debit: '',
    credit: '',
    cost_centre_id: '',
    funding_stream_id: '',
    tax_rate_id: '',
    tax_amount: '',
});

const STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Date, type & reference', icon: FileText },
    { key: 'lines', label: 'Lines', blurb: 'Debit & credit lines', icon: BookOpen },
    { key: 'review', label: 'Review & post', blurb: 'Confirm and post', icon: ListChecks },
];

/**
 * New Journal wizard — the multi-line DR/CR journal entry as an Add-Client-grade
 * stepper modal. Reuses the shared {@link PostingPreview} + {@link journalBalance}
 * so the live balance check is identical wherever money posts to the GL. Posts to
 * `finance.journals.store` (draft or post-immediately); the controller redirects
 * to the new journal on success.
 */
export function NewJournalDialog({
    open,
    onClose,
    accounts,
    costCentres,
    fundingStreams,
}: {
    open: boolean;
    onClose: () => void;
    accounts: RefItem[];
    costCentres: RefItem[];
    fundingStreams: RefItem[];
}) {
    const wizard = useWizard(STEPS.length);
    const { index, goTo, next, back, isFirst, isLast, reset } = wizard;
    const [postImmediately, setPostImmediately] = useState(false);

    const form = useForm<{
        journal_date: string;
        type: string;
        reference: string;
        description: string;
        lines: LineForm[];
        post_immediately: boolean;
    }>({
        journal_date: new Date().toISOString().split('T')[0],
        type: 'standard',
        reference: '',
        description: '',
        lines: [emptyLine(), emptyLine()],
        post_immediately: false,
    });
    const { data, setData, processing, errors } = form;
    // `posting` is a controller-level error (not a form field), so it isn't in the typed error map.
    const postingError = (errors as Record<string, string | undefined>).posting;

    const accountName = (id: string) => {
        const a = accounts.find((x) => String(x.id) === id);
        return a ? `${a.code} · ${a.name}` : '';
    };
    const accountCode = (id: string) =>
        accounts.find((x) => String(x.id) === id)?.code;

    const previewLines: PostingLine[] = useMemo(
        () =>
            data.lines
                .filter((l) => l.account_id && (l.debit || l.credit))
                .map((l) => ({
                    accountCode: accountCode(l.account_id),
                    accountName:
                        accounts.find((x) => String(x.id) === l.account_id)?.name ??
                        'Unassigned',
                    debit: l.debit,
                    credit: l.credit,
                    memo: l.description || undefined,
                })),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [data.lines, accounts],
    );
    const balance = journalBalance(previewLines);

    const updateLine = (i: number, field: keyof LineForm, value: string) => {
        const updated = [...data.lines];
        updated[i] = { ...updated[i], [field]: value };
        setData('lines', updated);
    };
    const addLine = () => setData('lines', [...data.lines, emptyLine()]);
    const removeLine = (i: number) => {
        if (data.lines.length <= 2) return;
        setData(
            'lines',
            data.lines.filter((_, idx) => idx !== i),
        );
    };

    const close = () => {
        reset();
        form.reset();
        form.clearErrors();
        setPostImmediately(false);
        onClose();
    };

    const submit = (post: boolean) => {
        setPostImmediately(post);
        setData('post_immediately', post);
        // flush the post_immediately flag before submitting
        setTimeout(() => {
            form.post('/finance/journals', {
                preserveScroll: true,
                onError: () => goTo(1), // surface line errors on the Lines step
            });
        }, 0);
    };

    const accountOptions = accounts.map((a) => ({
        value: String(a.id),
        label: `${a.code} - ${a.name}`,
    }));
    // No empty-string option values — Radix Select forbids them (it reserves ''
    // for "cleared"). The placeholder ("None") conveys the unselected state.
    const ccOptions = costCentres.map((c) => ({ value: String(c.id), label: `${c.code} - ${c.name}` }));
    const fsOptions = fundingStreams.map((f) => ({ value: String(f.id), label: `${f.code} - ${f.name}` }));

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New journal entry"
            description="Create a manual general ledger journal entry"
            railIcon={BookOpen}
            railTitle="New Journal"
            railSub="General ledger"
            steps={STEPS}
            stepIndex={index}
            onStepClick={goTo}
            pct={balance.balanced ? 100 : Math.min(90, previewLines.length * 30)}
            pctLabel="Balanced"
            footerStart={
                <span
                    className={
                        balance.balanced
                            ? 'text-[13px] font-semibold text-status-success'
                            : 'text-[13px] font-semibold text-status-warning'
                    }
                >
                    {balance.balanced
                        ? 'Balanced'
                        : `Out of balance by ${new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Math.abs(balance.difference))}`}
                </span>
            }
            footerEnd={
                <>
                    {!isFirst && (
                        <Button type="button" variant="outline" onClick={back} disabled={processing}>
                            Back
                        </Button>
                    )}
                    {!isLast && (
                        <Button type="button" onClick={next}>
                            Continue
                        </Button>
                    )}
                    {isLast && (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => submit(false)}
                                disabled={processing}
                            >
                                Save as draft
                            </Button>
                            <Button
                                type="button"
                                onClick={() => submit(true)}
                                disabled={processing || !balance.balanced}
                            >
                                Save &amp; post
                            </Button>
                        </>
                    )}
                </>
            }
        >
            {index === 0 && (
                <div>
                    <StepHead icon={FileText} title="Journal details" blurb="When, what kind, and a reference." />
                    {postingError && <FieldErr>{postingError}</FieldErr>}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="Date" required error={errors.journal_date}>
                            <Input
                                type="date"
                                value={data.journal_date}
                                onChange={(e) => setData('journal_date', e.target.value)}
                            />
                        </Field>
                        <Field label="Type" error={errors.type}>
                            <Segmented
                                value={data.type}
                                onChange={(v) => setData('type', v)}
                                options={[
                                    { value: 'standard', label: 'Standard' },
                                    { value: 'adjustment', label: 'Adjustment' },
                                    { value: 'opening', label: 'Opening' },
                                ]}
                            />
                        </Field>
                        <Field label="Reference" hint="optional" error={errors.reference}>
                            <Input
                                value={data.reference}
                                onChange={(e) => setData('reference', e.target.value)}
                                placeholder="e.g. INV-1024"
                            />
                        </Field>
                        <Field label="Description" span error={errors.description}>
                            <Textarea
                                rows={2}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="What is this journal for?"
                            />
                        </Field>
                    </div>
                </div>
            )}

            {index === 1 && (
                <div>
                    <StepHead icon={BookOpen} title="Debit & credit lines" blurb="Each line debits or credits a GL account. Debits must equal credits to post." />
                    {typeof errors.lines === 'string' && <FieldErr>{errors.lines}</FieldErr>}
                    <div className="space-y-3">
                        {data.lines.map((line, i) => (
                            // eslint-disable-next-line no-restricted-syntax -- per-line field-group panel, not a content card
                            <div key={i} className="rounded-xl border border-border bg-card/60 p-3">
                                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <Field label="Account" required error={errors[`lines.${i}.account_id` as keyof typeof errors] as string | undefined}>
                                        <SelectInput
                                            value={line.account_id}
                                            onChange={(v) => updateLine(i, 'account_id', v)}
                                            placeholder="Select account"
                                            options={accountOptions}
                                        />
                                    </Field>
                                    <Field label="Line description">
                                        <Input
                                            value={line.description}
                                            onChange={(e) => updateLine(i, 'description', e.target.value)}
                                            placeholder="Optional"
                                        />
                                    </Field>
                                    <Field label="Debit">
                                        <AmountField
                                            value={line.debit}
                                            onValueChange={(v) => {
                                                updateLine(i, 'debit', v);
                                                if (v) updateLine(i, 'credit', '');
                                            }}
                                            aria-label={`Line ${i + 1} debit`}
                                        />
                                    </Field>
                                    <Field label="Credit">
                                        <AmountField
                                            value={line.credit}
                                            onValueChange={(v) => {
                                                updateLine(i, 'credit', v);
                                                if (v) updateLine(i, 'debit', '');
                                            }}
                                            aria-label={`Line ${i + 1} credit`}
                                        />
                                    </Field>
                                    <Field label="Cost centre">
                                        <SelectInput
                                            value={line.cost_centre_id}
                                            onChange={(v) => updateLine(i, 'cost_centre_id', v)}
                                            placeholder="None"
                                            options={ccOptions}
                                        />
                                    </Field>
                                    <Field label="Funding stream">
                                        <SelectInput
                                            value={line.funding_stream_id}
                                            onChange={(v) => updateLine(i, 'funding_stream_id', v)}
                                            placeholder="None"
                                            options={fsOptions}
                                        />
                                    </Field>
                                </div>
                                <div className="mt-2 flex justify-end">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => removeLine(i)}
                                        disabled={data.lines.length <= 2}
                                        className="text-muted-foreground hover:text-status-critical"
                                    >
                                        <Trash2 className="mr-1 h-4 w-4" /> Remove line
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                    <Button type="button" variant="outline" size="sm" onClick={addLine} className="mt-3">
                        <Plus className="mr-1 h-4 w-4" /> Add line
                    </Button>
                    <div className="mt-4">
                        <PostingPreview lines={previewLines} title="Live balance" />
                    </div>
                </div>
            )}

            {index === 2 && (
                <div>
                    <StepHead icon={ListChecks} title="Review & post" blurb="Confirm the entry. Save as a draft, or post it straight to the ledger." />
                    <div className="mb-4 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <div className="text-muted-foreground">Date</div>
                            <div className="font-medium">{data.journal_date}</div>
                        </div>
                        <div>
                            <div className="text-muted-foreground">Type</div>
                            <div className="font-medium capitalize">{data.type}</div>
                        </div>
                        {data.reference && (
                            <div>
                                <div className="text-muted-foreground">Reference</div>
                                <div className="font-medium">{data.reference}</div>
                            </div>
                        )}
                        {data.description && (
                            <div className="col-span-2">
                                <div className="text-muted-foreground">Description</div>
                                <div className="font-medium">{data.description}</div>
                            </div>
                        )}
                    </div>
                    <PostingPreview lines={previewLines} title="Journal preview" />
                    {!balance.balanced && (
                        <p className="mt-3 text-[13px] text-status-warning">
                            This journal isn't balanced yet — you can still save it as a draft, but it can't be posted until debits equal credits.
                        </p>
                    )}
                    {postImmediately && processing && (
                        <p className="mt-3 text-[13px] text-muted-foreground">Posting…</p>
                    )}
                </div>
            )}
        </WizardShell>
    );
}

export default NewJournalDialog;
