import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useForm } from '@inertiajs/react';
import { Wand2 } from 'lucide-react';
import { useEffect, type FormEvent } from 'react';

export const RULE_TYPES: { value: string; label: string }[] = [
    { value: 'exact_amount', label: 'Exact amount' },
    { value: 'reference_match', label: 'Reference match' },
    { value: 'vendor_pattern', label: 'Vendor pattern' },
    { value: 'recurring_pattern', label: 'Recurring pattern' },
    { value: 'amount_tolerance', label: 'Amount tolerance' },
];

export const ruleTypeLabel = (type: string) =>
    RULE_TYPES.find((option) => option.value === type)?.label ?? type;

export interface MatchRuleRecord {
    id: number;
    name: string;
    priority: number;
    rule_type: string;
    conditions: Record<string, unknown> | null;
    auto_confirm_threshold: number;
    is_active: boolean;
}

type MatchRuleFormData = {
    name: string;
    priority: number;
    rule_type: string;
    auto_confirm_threshold: number;
    is_active: boolean;
    conditions: Record<string, string | number | boolean | null>;
};

const blank = (): MatchRuleFormData => ({
    name: '',
    priority: 0,
    rule_type: 'exact_amount',
    auto_confirm_threshold: 95,
    is_active: true,
    conditions: {},
});

const fromRule = (rule: MatchRuleRecord): MatchRuleFormData => ({
    name: rule.name,
    priority: rule.priority,
    rule_type: rule.rule_type,
    auto_confirm_threshold: rule.auto_confirm_threshold,
    is_active: rule.is_active,
    conditions: (rule.conditions ?? {}) as MatchRuleFormData['conditions'],
});

/**
 * One dialog for creating and editing a match rule — the two used to be
 * separate components carrying ~150 lines of duplicated form JSX between them.
 */
export function MatchRuleDialog({
    open,
    onClose,
    rule = null,
}: {
    open: boolean;
    onClose: () => void;
    rule?: MatchRuleRecord | null;
}) {
    const isEdit = Boolean(rule);
    const form = useForm<MatchRuleFormData>(rule ? fromRule(rule) : blank());
    const { data, setData, processing, errors, reset, clearErrors } = form;

    useEffect(() => {
        if (!open) return;
        clearErrors();
        setData(rule ? fromRule(rule) : blank());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, rule?.id]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const done = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };

        if (isEdit && rule) {
            form.put(`/finance/match-rules/${rule.id}`, done);
        } else {
            form.post('/finance/match-rules', done);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) onClose();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(94vw, 560px)',
                    width: 'min(94vw, 560px)',
                }}
            >
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Wand2 className="h-4 w-4 text-primary" />
                            {isEdit ? 'Edit match rule' : 'Add a match rule'}
                        </DialogTitle>
                        <DialogDescription>
                            Rules run in priority order when matching bank
                            transactions. Anything scoring above the
                            auto-confirm threshold is allocated without review.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-3 space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="rule-name">Name</Label>
                            <Input
                                id="rule-name"
                                value={data.name}
                                onChange={(event) =>
                                    setData('name', event.target.value)
                                }
                                placeholder="e.g. Exact amount match for utilities"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="rule-type">Rule type</Label>
                                <Select
                                    value={data.rule_type}
                                    onValueChange={(value) =>
                                        setData('rule_type', value)
                                    }
                                >
                                    <SelectTrigger id="rule-type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {RULE_TYPES.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.rule_type} />
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="rule-priority">Priority</Label>
                                <Input
                                    id="rule-priority"
                                    type="number"
                                    min={0}
                                    value={data.priority}
                                    onChange={(event) =>
                                        setData(
                                            'priority',
                                            parseInt(event.target.value, 10) ||
                                                0,
                                        )
                                    }
                                />
                                <p className="text-[12px] text-muted-foreground">
                                    Lower numbers run first.
                                </p>
                                <InputError message={errors.priority} />
                            </div>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="rule-threshold">
                                Auto-confirm threshold (%)
                            </Label>
                            <Input
                                id="rule-threshold"
                                type="number"
                                min={0}
                                max={100}
                                step={0.01}
                                value={data.auto_confirm_threshold}
                                onChange={(event) =>
                                    setData(
                                        'auto_confirm_threshold',
                                        parseFloat(event.target.value) || 0,
                                    )
                                }
                            />
                            <p className="text-[12px] text-muted-foreground">
                                A match scoring above this is confirmed
                                automatically, which posts the payment to the
                                ledger.
                            </p>
                            <InputError
                                message={errors.auto_confirm_threshold}
                            />
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="rule-active"
                                checked={data.is_active}
                                onCheckedChange={(checked) =>
                                    setData('is_active', checked === true)
                                }
                            />
                            <Label
                                htmlFor="rule-active"
                                className="font-normal"
                            >
                                Rule is active
                            </Label>
                        </div>
                    </div>

                    <DialogFooter className="mt-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={processing || !data.name}
                        >
                            {processing && <Spinner className="mr-2" />}
                            {isEdit ? 'Save rule' : 'Add rule'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
