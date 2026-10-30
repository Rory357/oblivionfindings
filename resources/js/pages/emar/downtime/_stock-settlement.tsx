import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import { formatDateOnly } from '@/lib/datetime';
import { useForm } from '@inertiajs/react';
import { ClipboardCheck, FileText, Loader2, Package } from 'lucide-react';
import { useRef, useState } from 'react';
import { PaperFactsReview } from './_paper-review';
import type { PaperEntry } from './types';

export function StockSettlementDialog({
    entry,
    downtimeId,
    onClose,
}: {
    entry: PaperEntry;
    downtimeId: number;
    onClose: () => void;
}) {
    const stock = entry.reconciliation.stock_evidence;
    const facts = entry.stock_evidence;
    const [step, setStep] = useState(0);
    const [discard, setDiscard] = useState(false);
    const replay = useRef({ uuid: crypto.randomUUID(), fingerprint: '' });
    const form = useForm({
        settlement: {
            settlement: '' as '' | 'deduct_now' | 'covered_by_count',
            closing_count_id: '',
            lines: (facts?.lines ?? []).map((line) => ({
                lot_id: line.lot_id,
                revision:
                    stock?.lots.find((lot) => lot.id === line.lot_id)
                        ?.revision ?? null,
                closing_quantity: '',
            })),
        },
        accountable_confirmation: false,
    });
    const steps = [
        {
            key: 'facts',
            label: 'Paper evidence',
            blurb: 'Actual packs and amounts',
            icon: FileText,
        },
        {
            key: 'balance',
            label: 'Check the balance',
            blurb: 'How stock is accounted for',
            icon: Package,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Record your stock decision',
            icon: ClipboardCheck,
        },
    ];
    const complete =
        !!form.data.settlement.settlement &&
        form.data.settlement.lines.length > 0 &&
        form.data.settlement.lines.every(
            (line) =>
                line.revision !== null &&
                line.closing_quantity.trim() !== '' &&
                Number.isFinite(Number(line.closing_quantity)) &&
                Number(line.closing_quantity) >= 0,
        ) &&
        (form.data.settlement.settlement !== 'covered_by_count' ||
            !!form.data.settlement.closing_count_id);
    const chosenCount = stock?.reviewed_counts.find(
        (count) => String(count.id) === form.data.settlement.closing_count_id,
    );
    const close = () => {
        if (!form.processing) {
            if (form.isDirty) setDiscard(true);
            else onClose();
        }
    };
    const save = () => {
        if (
            !complete ||
            !stock?.can_record_settlement ||
            !form.data.accountable_confirmation ||
            form.processing
        )
            return;
        const fingerprint = JSON.stringify(form.data.settlement);
        if (
            replay.current.fingerprint &&
            replay.current.fingerprint !== fingerprint
        )
            replay.current.uuid = crypto.randomUUID();
        replay.current.fingerprint = fingerprint;
        form.transform((data) => ({
            ...data.settlement,
            closing_count_id: data.settlement.closing_count_id
                ? Number(data.settlement.closing_count_id)
                : null,
            accountable_confirmation: data.accountable_confirmation,
            request_uuid: replay.current.uuid,
        }));
        form.post(
            `/emar/downtime/${downtimeId}/paper/${entry.id}/stock-evidence`,
            { preserveScroll: true, onSuccess: onClose },
        );
    };
    const changeMode = (value: 'deduct_now' | 'covered_by_count') => {
        form.setData('settlement', {
            settlement: value,
            closing_count_id: '',
            lines: (facts?.lines ?? []).map((line) => ({
                lot_id: line.lot_id,
                revision:
                    stock?.lots.find((lot) => lot.id === line.lot_id)
                        ?.revision ?? null,
                closing_quantity: '',
            })),
        });
        form.setData('accountable_confirmation', false);
    };
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close}
                title="Review stock for a paper dose"
                description="Reconcile this dose with the exact packs and reviewed closing balance."
                railIcon={Package}
                railTitle="Paper stock review"
                railSub={`${entry.snapshot.person} · ${entry.snapshot.medicine}`}
                steps={steps}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!form.processing && index < step) {
                        setStep(index);
                        form.setData('accountable_confirmation', false);
                    }
                }}
                footerStart={
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={
                            step
                                ? () => {
                                      setStep(step - 1);
                                      form.setData(
                                          'accountable_confirmation',
                                          false,
                                      );
                                  }
                                : close
                        }
                    >
                        {step ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    <Button
                        disabled={
                            form.processing ||
                            !stock?.can_record_settlement ||
                            (step > 0 && !complete) ||
                            (step === 2 && !form.data.accountable_confirmation)
                        }
                        onClick={() => {
                            if (step < 2) {
                                setStep(step + 1);
                                return;
                            }
                            save();
                        }}
                    >
                        {form.processing && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        {step === 2 ? 'Record stock review' : 'Continue'}
                    </Button>
                }
            >
                <WizardStepPane key={steps[step].key}>
                    <fieldset
                        disabled={form.processing}
                        className="min-w-0 space-y-5"
                    >
                        {Object.keys(form.errors).length > 0 && (
                            <div
                                role="alert"
                                className="rounded-lg border border-status-critical bg-status-critical-bg p-3 text-status-critical-foreground"
                            >
                                {Object.entries(form.errors).map(
                                    ([key, error]) => (
                                        <p key={key}>{error}</p>
                                    ),
                                )}
                            </div>
                        )}
                        {step === 0 && (
                            <>
                                <PaperFactsReview
                                    clinical={entry.clinical_facts}
                                    stock={facts}
                                    doseUnit={entry.snapshot.dose_unit}
                                />
                                <p className="text-subtle">
                                    Check the signed paper against the packs and
                                    stock records. If a reviewed count already
                                    includes this dose, link it so stock is not
                                    deducted again.
                                </p>
                            </>
                        )}
                        {step === 1 && (
                            <>
                                <div className="space-y-2">
                                    <Label htmlFor="paper-stock-method">
                                        How this paper dose affects stock
                                    </Label>
                                    <Select
                                        value={form.data.settlement.settlement}
                                        onValueChange={changeMode}
                                    >
                                        <SelectTrigger
                                            id="paper-stock-method"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Choose after checking the evidence" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="deduct_now">
                                                The paper removal still needs to
                                                be deducted
                                            </SelectItem>
                                            <SelectItem
                                                value="covered_by_count"
                                                disabled={
                                                    !stock?.reviewed_counts
                                                        .length
                                                }
                                            >
                                                A reviewed count already
                                                includes this paper dose
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                {form.data.settlement.settlement ===
                                    'covered_by_count' && (
                                    <div className="space-y-2">
                                        <Label htmlFor="paper-closing-count">
                                            Reviewed count covering this exact
                                            paper entry
                                        </Label>
                                        <Select
                                            value={
                                                form.data.settlement
                                                    .closing_count_id
                                            }
                                            onValueChange={(id) => {
                                                const count =
                                                    stock?.reviewed_counts.find(
                                                        (row) =>
                                                            String(row.id) ===
                                                            id,
                                                    );
                                                form.setData('settlement', {
                                                    ...form.data.settlement,
                                                    closing_count_id: id,
                                                    lines: (
                                                        facts?.lines ?? []
                                                    ).map((line) => {
                                                        const counted =
                                                            count?.lines.find(
                                                                (row) =>
                                                                    row.lot_id ===
                                                                    line.lot_id,
                                                            );
                                                        return {
                                                            lot_id: line.lot_id,
                                                            revision:
                                                                counted?.revision ??
                                                                null,
                                                            closing_quantity:
                                                                counted
                                                                    ? String(
                                                                          counted.closing_quantity,
                                                                      )
                                                                    : '',
                                                        };
                                                    }),
                                                });
                                            }}
                                        >
                                            <SelectTrigger
                                                id="paper-closing-count"
                                                className="w-full"
                                            >
                                                <SelectValue placeholder="Choose a reviewed count" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {stock?.reviewed_counts.map(
                                                    (count) => (
                                                        <SelectItem
                                                            key={count.id}
                                                            value={String(
                                                                count.id,
                                                            )}
                                                        >
                                                            {count.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        <p className="text-subtle">
                                            Only reviewed counts explicitly
                                            covering this paper entry are
                                            offered.
                                        </p>
                                    </div>
                                )}
                                {form.data.settlement.settlement &&
                                    form.data.settlement.lines.map(
                                        (line, index) => {
                                            const lot = stock?.lots.find(
                                                (candidate) =>
                                                    candidate.id ===
                                                    line.lot_id,
                                            );
                                            return (
                                                <div
                                                    key={line.lot_id}
                                                    className="grid grid-cols-[minmax(0,1fr)_240px] items-end gap-4 rounded-lg border p-4"
                                                >
                                                    <div>
                                                        <p className="font-medium">
                                                            {lot?.batch_number
                                                                ? `Batch ${lot.batch_number}`
                                                                : `Pack ${line.lot_id}`}
                                                        </p>
                                                        <p className="text-subtle">
                                                            {lot?.expiry_date
                                                                ? `Expiry ${formatDateOnly(lot.expiry_date)}`
                                                                : 'Expiry not recorded'}
                                                        </p>
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label
                                                            htmlFor={`paper-closing-${line.lot_id}`}
                                                        >
                                                            Closing balance
                                                            after this dose (
                                                            {stock?.unit})
                                                        </Label>
                                                        <Input
                                                            id={`paper-closing-${line.lot_id}`}
                                                            type="number"
                                                            min="0"
                                                            step="any"
                                                            value={
                                                                line.closing_quantity
                                                            }
                                                            readOnly={
                                                                form.data
                                                                    .settlement
                                                                    .settlement ===
                                                                'covered_by_count'
                                                            }
                                                            onChange={(event) =>
                                                                form.setData(
                                                                    'settlement',
                                                                    {
                                                                        ...form
                                                                            .data
                                                                            .settlement,
                                                                        lines: form.data.settlement.lines.map(
                                                                            (
                                                                                row,
                                                                                at,
                                                                            ) =>
                                                                                at ===
                                                                                index
                                                                                    ? {
                                                                                          ...row,
                                                                                          closing_quantity:
                                                                                              event
                                                                                                  .target
                                                                                                  .value,
                                                                                      }
                                                                                    : row,
                                                                        ),
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                </div>
                                            );
                                        },
                                    )}
                                <p className="text-subtle">
                                    Use the independently checked closing
                                    balance. A changed pack balance or count
                                    requires another review before posting.
                                </p>
                            </>
                        )}
                        {step === 2 && (
                            <>
                                <ReviewCard
                                    title="Stock decision"
                                    icon={Package}
                                >
                                    <ReviewRow
                                        label="Treatment"
                                        value={
                                            form.data.settlement.settlement ===
                                            'deduct_now'
                                                ? 'Deduct the recorded paper removal once when the dose is applied'
                                                : 'Already included in the reviewed count — no second deduction'
                                        }
                                    />
                                    {chosenCount && (
                                        <ReviewRow
                                            label="Reviewed count"
                                            value={chosenCount.label}
                                        />
                                    )}
                                    {form.data.settlement.lines.map((line) => (
                                        <ReviewRow
                                            key={line.lot_id}
                                            label={`Pack ${line.lot_id} closing balance`}
                                            value={`${line.closing_quantity} ${stock?.unit}`}
                                        />
                                    ))}
                                </ReviewCard>
                                <div className="flex items-start gap-3">
                                    <Checkbox
                                        id="paper-stock-confirm"
                                        checked={
                                            form.data.accountable_confirmation
                                        }
                                        onCheckedChange={(value) =>
                                            form.setData(
                                                'accountable_confirmation',
                                                value === true,
                                            )
                                        }
                                    />
                                    <Label
                                        htmlFor="paper-stock-confirm"
                                        className="leading-relaxed"
                                    >
                                        I checked the signed paper, exact packs
                                        and closing balance. Record this stock
                                        review under my account.
                                    </Label>
                                </div>
                                <p className="text-subtle">
                                    The dose is applied separately after all
                                    required confirmations and recording checks
                                    pass.
                                </p>
                            </>
                        )}
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                frontline
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={onClose}
                title="Discard this stock review draft?"
                description="This review has not been saved."
                confirmText="Discard draft"
            />
        </>
    );
}
