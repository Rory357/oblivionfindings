import type { StockPackLine } from '@/components/medications/stock-pack-fields';
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

export type TransitDoseFacts = {
    quantity_given: string;
    amount_mode: '' | 'as_ordered' | 'less' | 'more';
    amount_reason: string;
    late_reason: string;
    more_severity: string;
    more_immediate_action: string;
    quantity_wasted: string;
    waste_reason: string;
};
export const blankTransitDoseFacts = (): TransitDoseFacts => ({
    quantity_given: '',
    amount_mode: '',
    amount_reason: '',
    late_reason: '',
    more_severity: '',
    more_immediate_action: '',
    quantity_wasted: '',
    waste_reason: '',
});
export function validateTransitDoseFacts(
    facts: TransitDoseFacts,
    lines: StockPackLine[],
    removed: string,
    clinicalUnit: string | null | undefined,
    stockUnit: string,
): string | null {
    if (!clinicalUnit?.trim())
        return 'The order needs a structured clinical unit before this dose can be recorded. Ask the medication lead to check the order.';
    if (
        !facts.quantity_given.trim() ||
        !Number.isFinite(Number(facts.quantity_given)) ||
        Number(facts.quantity_given) <= 0 ||
        !facts.amount_mode
    )
        return 'Enter the actual clinical amount and how it compares with the order.';
    if (
        !facts.quantity_wasted.trim() ||
        !Number.isFinite(Number(facts.quantity_wasted)) ||
        Number(facts.quantity_wasted) < 0
    )
        return 'Enter the total wasted, including 0 if none.';
    if (facts.amount_mode !== 'as_ordered' && !facts.amount_reason.trim())
        return 'Explain why a different amount was given.';
    if (
        facts.amount_mode === 'more' &&
        (!facts.more_severity || !facts.more_immediate_action.trim())
    )
        return 'Record the severity and immediate action for the extra amount.';
    if (Number(facts.quantity_wasted) > 0 && !facts.waste_reason.trim())
        return 'Record what happened to the waste.';
    if (
        lines.some(
            (line) =>
                !line.quantity_wasted?.trim() ||
                !Number.isFinite(Number(line.quantity_wasted)) ||
                Number(line.quantity_wasted) < 0 ||
                Number(line.quantity_wasted) > Number(line.quantity),
        )
    )
        return 'Enter waste for every actual pack, including 0 if none.';
    if (
        Math.round(
            lines.reduce((sum, line) => sum + Number(line.quantity_wasted), 0) *
                100,
        ) !== Math.round(Number(facts.quantity_wasted) * 100)
    )
        return 'The waste recorded against packs must match the total waste.';
    if (
        clinicalUnit.trim().toLowerCase() === stockUnit.trim().toLowerCase() &&
        Math.round((Number(removed) - Number(facts.quantity_wasted)) * 100) !==
            Math.round(Number(facts.quantity_given) * 100)
    )
        return 'The amount removed must account for both the amount given and the waste.';
    return null;
}
export function TransitDoseFields({
    value,
    onChange,
    clinicalUnit,
    stockUnit,
}: {
    value: TransitDoseFacts;
    onChange: (value: TransitDoseFacts) => void;
    clinicalUnit?: string | null;
    stockUnit: string;
}) {
    const text = (
        key: keyof TransitDoseFacts,
        label: string,
        numeric = false,
    ) => (
        <div className="space-y-2">
            <Label htmlFor={`transit-${key}`}>{label}</Label>
            {numeric ? (
                <Input
                    id={`transit-${key}`}
                    type="number"
                    min="0"
                    step="any"
                    value={value[key]}
                    onChange={(event) =>
                        onChange({ ...value, [key]: event.target.value })
                    }
                />
            ) : (
                <Textarea
                    id={`transit-${key}`}
                    value={value[key]}
                    onChange={(event) =>
                        onChange({ ...value, [key]: event.target.value })
                    }
                />
            )}
        </div>
    );
    return (
        <div className="space-y-4">
            {text(
                'quantity_given',
                `Actual amount given (${clinicalUnit || 'clinical unit not configured'})`,
                true,
            )}
            <div className="space-y-2">
                <Label htmlFor="transit-amount-mode">
                    Compared with the order
                </Label>
                <Select
                    value={value.amount_mode}
                    onValueChange={(mode) =>
                        onChange({
                            ...value,
                            amount_mode:
                                mode as TransitDoseFacts['amount_mode'],
                            amount_reason: '',
                            more_severity: '',
                            more_immediate_action: '',
                        })
                    }
                >
                    <SelectTrigger id="transit-amount-mode">
                        <SelectValue placeholder="Choose the actual outcome" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="as_ordered">As ordered</SelectItem>
                        <SelectItem value="less">Less than ordered</SelectItem>
                        <SelectItem value="more">More than ordered</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            {value.amount_mode &&
                value.amount_mode !== 'as_ordered' &&
                text('amount_reason', 'Why a different amount was given')}
            {value.amount_mode === 'more' && (
                <>
                    <div className="space-y-2">
                        <Label htmlFor="transit-severity">Severity</Label>
                        <Select
                            value={value.more_severity}
                            onValueChange={(severity) =>
                                onChange({ ...value, more_severity: severity })
                            }
                        >
                            <SelectTrigger id="transit-severity">
                                <SelectValue placeholder="Choose the observed severity" />
                            </SelectTrigger>
                            <SelectContent>
                                {['minor', 'moderate', 'major', 'critical'].map(
                                    (severity) => (
                                        <SelectItem
                                            key={severity}
                                            value={severity}
                                        >
                                            {severity}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </div>
                    {text(
                        'more_immediate_action',
                        'What you did straight away',
                    )}
                </>
            )}
            {text(
                'quantity_wasted',
                `Total wasted (${stockUnit}) — enter 0 if none`,
                true,
            )}
            {Number(value.quantity_wasted) > 0 &&
                text('waste_reason', 'What happened to the waste')}
            {text('late_reason', 'Reason if given outside the dose window')}
        </div>
    );
}
