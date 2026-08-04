import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, CheckCircle2, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

export type AutoRuleField =
    | 'domain'
    | 'category'
    | 'subcategory'
    | 'provider'
    | 'status'
    | 'health_status';

export type AutoRuleOperator = 'equals' | 'not_equals' | 'in';

export type AutoRuleCondition = {
    field: AutoRuleField;
    op: AutoRuleOperator;
    value: string | string[];
};

export type AutoRules = {
    match: 'all' | 'any';
    conditions: AutoRuleCondition[];
};

type PreviewDevice = {
    id: number;
    name: string;
    device_uid: string;
    category: string;
};

type PreviewResult = {
    count: number;
    sample: PreviewDevice[];
};

const fields: Array<{ value: AutoRuleField; label: string }> = [
    { value: 'domain', label: 'Area' },
    { value: 'category', label: 'Device type' },
    { value: 'subcategory', label: 'Device subtype' },
    { value: 'provider', label: 'Provider' },
    { value: 'status', label: 'Operational status' },
    { value: 'health_status', label: 'Health status' },
];

const operators: Array<{ value: AutoRuleOperator; label: string }> = [
    { value: 'equals', label: 'Is' },
    { value: 'not_equals', label: 'Is not' },
    { value: 'in', label: 'Is one of' },
];

const initialCondition = (): AutoRuleCondition => ({
    field: 'domain',
    op: 'equals',
    value: '',
});

export function normaliseAutoRules(rules: AutoRules | null): AutoRules | null {
    if (!rules) {
        return null;
    }

    return {
        match: rules.match,
        conditions: rules.conditions.map((condition) => {
            const textValue = Array.isArray(condition.value)
                ? condition.value.join(',')
                : condition.value;

            return {
                ...condition,
                value:
                    condition.op === 'in'
                        ? Array.from(
                              new Set(
                                  textValue
                                      .split(',')
                                      .map((item) => item.trim())
                                      .filter(Boolean),
                              ),
                          )
                        : textValue.trim(),
            };
        }),
    };
}

function previewErrorMessage(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const errors = error.response?.data?.errors;
        if (errors && typeof errors === 'object') {
            for (const value of Object.values(
                errors as Record<string, unknown>,
            )) {
                if (Array.isArray(value) && typeof value[0] === 'string') {
                    return value[0];
                }
                if (typeof value === 'string') {
                    return value;
                }
            }
        }
    }

    return 'The preview could not be loaded. Check the rule values and try again.';
}

export function AutoRuleBuilder({
    value,
    onChange,
}: {
    value: AutoRules | null;
    onChange: (rules: AutoRules | null) => void;
}) {
    const [preview, setPreview] = useState<PreviewResult | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const [previewing, setPreviewing] = useState(false);

    const changeRules = (rules: AutoRules | null) => {
        setPreview(null);
        setPreviewError(null);
        onChange(rules);
    };

    const updateCondition = (
        index: number,
        patch: Partial<AutoRuleCondition>,
    ) => {
        if (!value) return;
        const conditions = value.conditions.map((condition, conditionIndex) =>
            conditionIndex === index ? { ...condition, ...patch } : condition,
        );
        changeRules({ ...value, conditions });
    };

    const previewMatches = async () => {
        const rules = normaliseAutoRules(value);
        if (
            !rules ||
            rules.conditions.some((condition) => condition.value.length === 0)
        ) {
            setPreview(null);
            setPreviewError(
                'Enter a value for every condition before previewing.',
            );
            return;
        }

        setPreviewing(true);
        setPreviewError(null);
        try {
            const response = await axios.post<PreviewResult>(
                '/security-devices/device-groups/auto-rules/preview',
                { auto_rules: rules },
            );
            setPreview(response.data);
        } catch (error) {
            setPreview(null);
            setPreviewError(previewErrorMessage(error));
        } finally {
            setPreviewing(false);
        }
    };

    return (
        <div className="space-y-5">
            <div className="flex min-h-11 items-center justify-between gap-4 rounded-lg border p-4">
                <div>
                    <label
                        htmlFor="automatic-membership"
                        className="text-sm font-medium"
                    >
                        Automatic membership
                    </label>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Define which visible devices should belong to this
                        group.
                    </p>
                </div>
                <Switch
                    id="automatic-membership"
                    aria-label="Automatic membership"
                    checked={value !== null}
                    onCheckedChange={(checked) =>
                        changeRules(
                            checked
                                ? {
                                      match: 'all',
                                      conditions: [initialCondition()],
                                  }
                                : null,
                        )
                    }
                />
            </div>

            <p className="text-sm text-muted-foreground">
                Saving a rule does not add or remove devices. Review the
                matches, save the group, then apply the proposed membership from
                the group page.
            </p>

            {value && (
                <div className="space-y-4">
                    <div className="max-w-sm">
                        <label
                            htmlFor="automatic-membership-match"
                            className="mb-1.5 block text-sm font-medium"
                        >
                            A device must match
                        </label>
                        <Select
                            value={value.match}
                            onValueChange={(match: 'all' | 'any') =>
                                changeRules({ ...value, match })
                            }
                        >
                            <SelectTrigger id="automatic-membership-match">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All conditions
                                </SelectItem>
                                <SelectItem value="any">
                                    Any condition
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="space-y-3">
                        {value.conditions.map((condition, index) => (
                            <div
                                key={index}
                                className="grid gap-3 rounded-lg border bg-muted/20 p-4 lg:grid-cols-[1fr_1fr_1.4fr_auto]"
                            >
                                <div>
                                    <label
                                        htmlFor={`auto-rule-field-${index}`}
                                        className="mb-1.5 block text-sm font-medium"
                                    >
                                        Field
                                    </label>
                                    <Select
                                        value={condition.field}
                                        onValueChange={(field: AutoRuleField) =>
                                            updateCondition(index, { field })
                                        }
                                    >
                                        <SelectTrigger
                                            id={`auto-rule-field-${index}`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {fields.map((field) => (
                                                <SelectItem
                                                    key={field.value}
                                                    value={field.value}
                                                >
                                                    {field.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <label
                                        htmlFor={`auto-rule-operator-${index}`}
                                        className="mb-1.5 block text-sm font-medium"
                                    >
                                        Comparison
                                    </label>
                                    <Select
                                        value={condition.op}
                                        onValueChange={(op: AutoRuleOperator) =>
                                            updateCondition(index, { op })
                                        }
                                    >
                                        <SelectTrigger
                                            id={`auto-rule-operator-${index}`}
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {operators.map((operator) => (
                                                <SelectItem
                                                    key={operator.value}
                                                    value={operator.value}
                                                >
                                                    {operator.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div>
                                    <label
                                        htmlFor={`auto-rule-value-${index}`}
                                        className="mb-1.5 block text-sm font-medium"
                                    >
                                        Value
                                    </label>
                                    <Input
                                        id={`auto-rule-value-${index}`}
                                        aria-label="Value"
                                        value={
                                            Array.isArray(condition.value)
                                                ? condition.value.join(', ')
                                                : condition.value
                                        }
                                        onChange={(event) =>
                                            updateCondition(index, {
                                                value: event.target.value,
                                            })
                                        }
                                        placeholder={
                                            condition.op === 'in'
                                                ? 'camera, nvr, door controller'
                                                : 'Enter an exact value'
                                        }
                                    />
                                    {condition.op === 'in' && (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Separate multiple values with
                                            commas.
                                        </p>
                                    )}
                                </div>

                                <div className="flex items-end">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="min-h-11 min-w-11 text-muted-foreground hover:text-destructive"
                                        aria-label={`Remove condition ${index + 1}`}
                                        disabled={value.conditions.length === 1}
                                        onClick={() =>
                                            changeRules({
                                                ...value,
                                                conditions:
                                                    value.conditions.filter(
                                                        (_, conditionIndex) =>
                                                            conditionIndex !==
                                                            index,
                                                    ),
                                            })
                                        }
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={value.conditions.length >= 8}
                            onClick={() =>
                                changeRules({
                                    ...value,
                                    conditions: [
                                        ...value.conditions,
                                        {
                                            field: 'category',
                                            op: 'in',
                                            value: '',
                                        },
                                    ],
                                })
                            }
                        >
                            <Plus className="mr-2 h-4 w-4" /> Add condition
                        </Button>
                        <Button
                            type="button"
                            onClick={previewMatches}
                            disabled={previewing}
                        >
                            {previewing ? 'Checking…' : 'Preview matches'}
                        </Button>
                    </div>

                    {previewError && (
                        <Alert variant="destructive" aria-live="polite">
                            <AlertCircle />
                            <AlertTitle>Preview unavailable</AlertTitle>
                            <AlertDescription>{previewError}</AlertDescription>
                        </Alert>
                    )}

                    {preview && (
                        <Alert aria-live="polite">
                            <CheckCircle2 />
                            <AlertTitle>
                                {preview.count}{' '}
                                {preview.count === 1
                                    ? 'device matches'
                                    : 'devices match'}
                            </AlertTitle>
                            <AlertDescription>
                                {preview.sample.length > 0 ? (
                                    <ul className="mt-1 space-y-1">
                                        {preview.sample.map((device) => (
                                            <li key={device.id}>
                                                <Link
                                                    href={`/security-devices/devices/${device.id}`}
                                                    className="frontline-focus rounded-sm text-primary hover:underline"
                                                >
                                                    {device.name}
                                                </Link>{' '}
                                                <span className="text-muted-foreground">
                                                    ({device.device_uid})
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p>No visible devices currently match.</p>
                                )}
                                {preview.count > preview.sample.length && (
                                    <p className="mt-1">
                                        Plus{' '}
                                        {preview.count - preview.sample.length}{' '}
                                        more.
                                    </p>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}
                </div>
            )}
        </div>
    );
}
