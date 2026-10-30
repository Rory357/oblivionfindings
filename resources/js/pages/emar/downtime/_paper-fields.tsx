import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatDateOnly, workerTimeOffsets } from '@/lib/datetime';
import { Plus, Trash2 } from 'lucide-react';
import type {
    PaperClinicalFacts,
    PaperStockFacts,
    RecoveryStock,
} from './types';

export const blankClinicalFacts = (): PaperClinicalFacts => ({
    quantity_given: '',
    amount_mode: '',
    amount_reason: '',
    late_reason: '',
    prn_reason: '',
    effect_check_due_at: '',
    more_severity: '',
    more_immediate_action: '',
});
export const blankStockFacts = (): PaperStockFacts => ({
    stock_id: null,
    unit: '',
    quantity_removed: '',
    quantity_wasted: '',
    waste_reason: '',
    lines: [],
});

function PaperText({
    id,
    label,
    value,
    onChange,
    number = false,
    multiline = false,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    number?: boolean;
    multiline?: boolean;
}) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            {multiline ? (
                <Textarea
                    id={id}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            ) : (
                <Input
                    id={id}
                    value={value}
                    type={number ? 'number' : 'text'}
                    min={number ? 0 : undefined}
                    step={number ? 'any' : undefined}
                    onChange={(e) => onChange(e.target.value)}
                />
            )}
        </div>
    );
}

export function PaperClinicalFields({
    value,
    onChange,
    prn,
    doseUnit,
}: {
    value: PaperClinicalFacts;
    onChange: (value: PaperClinicalFacts) => void;
    prn: boolean;
    doseUnit?: string | null;
}) {
    const effectLocal = value.effect_check_due_at.slice(0, 16);
    const effectOffsets = workerTimeOffsets(effectLocal);
    const effectOffset =
        value.effect_check_due_at.match(/[+-]\d{2}:\d{2}$/)?.[0] ?? '';
    function set<K extends keyof PaperClinicalFacts>(
        key: K,
        next: PaperClinicalFacts[K],
    ) {
        onChange({ ...value, [key]: next });
    }
    return (
        <div className="space-y-5">
            <p className="text-subtle">
                Copy what was given and the checks written on paper. Leave
                unknown details blank for review.
            </p>
            <div className="grid grid-cols-2 gap-4">
                <PaperText
                    id="paper-quantity-given"
                    label={`Actual amount given (${doseUnit || 'unit on the order'})`}
                    number
                    value={value.quantity_given}
                    onChange={(v) => set('quantity_given', v)}
                />
                <div className="space-y-2">
                    <Label htmlFor="paper-amount-mode">
                        How it compares with the order
                    </Label>
                    <Select
                        value={value.amount_mode}
                        onValueChange={(v) =>
                            set(
                                'amount_mode',
                                v as PaperClinicalFacts['amount_mode'],
                            )
                        }
                    >
                        <SelectTrigger
                            id="paper-amount-mode"
                            className="w-full"
                        >
                            <SelectValue placeholder="Check the signed paper" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="as_ordered">
                                As ordered
                            </SelectItem>
                            <SelectItem value="less">
                                Less than ordered
                            </SelectItem>
                            <SelectItem value="more">
                                More than ordered
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
            {(value.amount_mode === 'less' || value.amount_mode === 'more') && (
                <PaperText
                    id="paper-amount-reason"
                    label="Reason for the different amount"
                    multiline
                    value={value.amount_reason}
                    onChange={(v) => set('amount_reason', v)}
                />
            )}
            {value.amount_mode === 'more' && (
                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="paper-more-severity">
                            How serious it was
                        </Label>
                        <Select
                            value={value.more_severity}
                            onValueChange={(v) => set('more_severity', v)}
                        >
                            <SelectTrigger
                                id="paper-more-severity"
                                className="w-full"
                            >
                                <SelectValue placeholder="Choose from the paper" />
                            </SelectTrigger>
                            <SelectContent>
                                {['minor', 'moderate', 'major', 'critical'].map(
                                    (v) => (
                                        <SelectItem key={v} value={v}>
                                            {v[0].toUpperCase() + v.slice(1)}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </div>
                    <PaperText
                        id="paper-more-action"
                        label="Immediate action taken"
                        multiline
                        value={value.more_immediate_action}
                        onChange={(v) => set('more_immediate_action', v)}
                    />
                </div>
            )}
            {!prn && (
                <PaperText
                    id="paper-late-reason"
                    label="Reason if given outside the allowed time"
                    multiline
                    value={value.late_reason}
                    onChange={(v) => set('late_reason', v)}
                />
            )}
            {prn && (
                <>
                    <PaperText
                        id="paper-prn-reason"
                        label="Why the as-needed medicine was given"
                        multiline
                        value={value.prn_reason}
                        onChange={(v) => set('prn_reason', v)}
                    />
                    <DateTimeField
                        compact
                        id="paper-effect-due"
                        label="Effect check due on paper"
                        value={effectLocal}
                        onChange={(v) => {
                            const offsets = workerTimeOffsets(v);
                            set(
                                'effect_check_due_at',
                                v && offsets.length === 1
                                    ? `${v}:00${offsets[0]}`
                                    : v,
                            );
                        }}
                        hint="Copy the planned NZ date and time. An overdue check remains visible for follow-up."
                    />
                    {effectOffsets.length > 1 && (
                        <div className="space-y-2">
                            <Label htmlFor="paper-effect-offset">
                                Which occurrence of this time?
                            </Label>
                            <Select
                                value={effectOffset}
                                onValueChange={(offset) =>
                                    set(
                                        'effect_check_due_at',
                                        `${effectLocal}:00${offset}`,
                                    )
                                }
                            >
                                <SelectTrigger id="paper-effect-offset">
                                    <SelectValue placeholder="Check the time written on paper" />
                                </SelectTrigger>
                                <SelectContent>
                                    {effectOffsets.map((offset) => (
                                        <SelectItem key={offset} value={offset}>
                                            UTC{offset}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    {effectLocal && effectOffsets.length === 0 && (
                        <p className="text-status-warning-foreground">
                            This NZ clock time did not occur. Check the signed
                            paper.
                        </p>
                    )}
                </>
            )}
        </div>
    );
}

export function PaperStockFields({
    value,
    onChange,
    stock,
}: {
    value: PaperStockFacts;
    onChange: (value: PaperStockFacts) => void;
    stock?: RecoveryStock;
}) {
    const unit = stock?.unit || 'stock units';
    const unused =
        stock?.lots.filter(
            (lot) => !value.lines.some((line) => line.lot_id === lot.id),
        ) ?? [];
    return (
        <div className="space-y-5">
            <p className="text-subtle">
                Record the packs actually used. This saves evidence; a stock
                reviewer checks how it affects the current balance before the
                dose is applied.
            </p>
            {(!stock || stock.unavailable || !stock.lots.length) && (
                <p
                    role="status"
                    className="rounded-lg border border-status-warning bg-status-warning-bg p-3 text-status-warning-foreground"
                >
                    {stock?.unavailable ||
                        'No pack evidence is available for this medicine. Keep the signed paper and ask the stock lead to review it.'}
                </p>
            )}
            {stock?.clinical_unavailable && (
                <p
                    role="status"
                    className="rounded-lg border border-status-warning bg-status-warning-bg p-3 text-status-warning-foreground"
                >
                    {stock.clinical_unavailable}
                </p>
            )}
            <div className="grid grid-cols-2 gap-4">
                <PaperText
                    id="paper-removed"
                    label={`Removed from stock (${unit})`}
                    number
                    value={value.quantity_removed}
                    onChange={(v) =>
                        onChange({ ...value, quantity_removed: v })
                    }
                />
                <PaperText
                    id="paper-wasted"
                    label={`Wasted (${unit}) — enter 0 if none`}
                    number
                    value={value.quantity_wasted}
                    onChange={(v) => onChange({ ...value, quantity_wasted: v })}
                />
            </div>
            {Number(value.quantity_wasted) > 0 && (
                <PaperText
                    id="paper-waste-reason"
                    label="What happened to the wasted medicine"
                    multiline
                    value={value.waste_reason}
                    onChange={(v) => onChange({ ...value, waste_reason: v })}
                />
            )}
            <div className="space-y-3">
                <h3 className="text-section-title">Actual packs used</h3>
                {value.lines.map((line, index) => {
                    const lot = stock?.lots.find(
                        (candidate) => candidate.id === line.lot_id,
                    );
                    return (
                        <div
                            key={line.lot_id}
                            className="grid grid-cols-[minmax(0,1fr)_140px_140px_auto] items-end gap-3 rounded-lg border p-4"
                        >
                            <div className="space-y-1">
                                <p className="font-medium">
                                    {lot?.batch_number
                                        ? `Batch ${lot.batch_number}`
                                        : `Pack ${line.lot_id} · batch not recorded`}
                                </p>
                                <p className="text-subtle">
                                    {lot?.expiry_date
                                        ? `Expiry ${formatDateOnly(lot.expiry_date)}`
                                        : 'Expiry not recorded'}{' '}
                                    · {lot?.state ?? 'Review required'}
                                </p>
                            </div>
                            <PaperText
                                id={`paper-pack-${line.lot_id}`}
                                label={`Amount used (${unit})`}
                                number
                                value={line.quantity}
                                onChange={(v) =>
                                    onChange({
                                        ...value,
                                        lines: value.lines.map((entry, at) =>
                                            at === index
                                                ? { ...entry, quantity: v }
                                                : entry,
                                        ),
                                    })
                                }
                            />
                            <PaperText
                                id={`paper-pack-waste-${line.lot_id}`}
                                label={`Wasted (${unit})`}
                                number
                                value={line.quantity_wasted}
                                onChange={(v) =>
                                    onChange({
                                        ...value,
                                        lines: value.lines.map((entry, at) =>
                                            at === index
                                                ? {
                                                      ...entry,
                                                      quantity_wasted: v,
                                                  }
                                                : entry,
                                        ),
                                    })
                                }
                            />
                            <Button
                                variant="ghost"
                                aria-label={`Remove pack ${line.lot_id}`}
                                onClick={() =>
                                    onChange({
                                        ...value,
                                        lines: value.lines.filter(
                                            (_, at) => at !== index,
                                        ),
                                    })
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    );
                })}
                {unused.length > 0 && (
                    <div className="space-y-2">
                        <Label htmlFor="paper-add-pack">
                            Add a pack shown on paper
                        </Label>
                        <Select
                            value=""
                            onValueChange={(v) =>
                                onChange({
                                    ...value,
                                    stock_id: stock!.stock_id,
                                    unit: stock!.unit,
                                    lines: [
                                        ...value.lines,
                                        {
                                            lot_id: Number(v),
                                            quantity: '',
                                            quantity_wasted: '',
                                        },
                                    ],
                                })
                            }
                        >
                            <SelectTrigger
                                id="paper-add-pack"
                                className="w-full"
                            >
                                <Plus className="size-4" />
                                <SelectValue placeholder="Choose the actual pack" />
                            </SelectTrigger>
                            <SelectContent>
                                {unused.map((lot) => (
                                    <SelectItem
                                        key={lot.id}
                                        value={String(lot.id)}
                                    >
                                        {lot.batch_number
                                            ? `Batch ${lot.batch_number}`
                                            : `Pack ${lot.id} · batch not recorded`}{' '}
                                        ·{' '}
                                        {lot.expiry_date
                                            ? formatDateOnly(lot.expiry_date)
                                            : 'expiry not recorded'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}
                <p className="text-subtle">
                    Packs are not chosen automatically. If the paper cannot
                    identify a pack, retain it for review.
                </p>
            </div>
        </div>
    );
}
