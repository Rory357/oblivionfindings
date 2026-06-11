import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Plus, Trash2 } from 'lucide-react';

export type CarePlanDomainDraft = {
    key?: string;
    label: string;
    status: 'on_track' | 'active' | 'review';
    strategies: Array<{
        text: string;
        owner: string;
    }>;
};

type CarePlanDomainsBuilderProps = {
    domains?: CarePlanDomainDraft[];
    staff?: { id: number; name: string }[];
    errors?: Record<string, string>;
    onChange: (domains: CarePlanDomainDraft[]) => void;
};

const OWNER_OPTIONS = [
    'Key worker',
    'Support worker',
    'Coordinator',
    'Clinical lead',
];

function emptyDomain(index: number): CarePlanDomainDraft {
    return {
        key: `domain_${index + 1}`,
        label: '',
        status: 'active',
        strategies: [{ text: '', owner: '' }],
    };
}

function normalise(domains?: CarePlanDomainDraft[]): CarePlanDomainDraft[] {
    return (domains ?? []).map((domain, index) => ({
        key: domain.key || `domain_${index + 1}`,
        label: domain.label ?? '',
        status: domain.status ?? 'active',
        strategies: (domain.strategies?.length
            ? domain.strategies
            : [{ text: '', owner: '' }]
        ).map((strategy) => ({
            text: strategy.text ?? '',
            owner: strategy.owner ?? '',
        })),
    }));
}

export function CarePlanDomainsBuilder({
    domains,
    staff = [],
    errors = {},
    onChange,
}: CarePlanDomainsBuilderProps) {
    const rows = normalise(domains);
    const ownerOptions = [
        ...OWNER_OPTIONS,
        ...staff.map((worker) => worker.name),
    ].filter(
        (value, index, values) => value && values.indexOf(value) === index,
    );

    const updateDomain = (
        index: number,
        patch: Partial<CarePlanDomainDraft>,
    ) => {
        onChange(
            rows.map((domain, domainIndex) =>
                domainIndex === index ? { ...domain, ...patch } : domain,
            ),
        );
    };

    const updateStrategy = (
        domainIndex: number,
        strategyIndex: number,
        patch: Partial<CarePlanDomainDraft['strategies'][number]>,
    ) => {
        onChange(
            rows.map((domain, currentDomainIndex) =>
                currentDomainIndex === domainIndex
                    ? {
                          ...domain,
                          strategies: domain.strategies.map(
                              (strategy, currentStrategyIndex) =>
                                  currentStrategyIndex === strategyIndex
                                      ? { ...strategy, ...patch }
                                      : strategy,
                          ),
                      }
                    : domain,
            ),
        );
    };

    const addStrategy = (domainIndex: number) => {
        onChange(
            rows.map((domain, index) =>
                index === domainIndex
                    ? {
                          ...domain,
                          strategies: [
                              ...domain.strategies,
                              { text: '', owner: '' },
                          ],
                      }
                    : domain,
            ),
        );
    };

    const removeStrategy = (domainIndex: number, strategyIndex: number) => {
        onChange(
            rows.map((domain, index) =>
                index === domainIndex
                    ? {
                          ...domain,
                          strategies: domain.strategies.filter(
                              (_strategy, currentIndex) =>
                                  currentIndex !== strategyIndex,
                          ),
                      }
                    : domain,
            ),
        );
    };

    return (
        <Card>
            <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <CardTitle className="text-base">
                            Support domains & strategies
                        </CardTitle>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Capture the domain cards that appear on the client
                            profile.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            onChange([...rows, emptyDomain(rows.length)])
                        }
                    >
                        <Plus className="mr-1.5 h-4 w-4" />
                        Add domain
                    </Button>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {rows.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                        No support domains yet. Add one to structure the plan
                        beyond goals.
                    </div>
                ) : null}
                {rows.map((domain, domainIndex) => (
                    <div
                        key={domain.key || domainIndex}
                        className="rounded-xl border p-4"
                    >
                        <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_12rem_auto]">
                            <div className="space-y-2">
                                <Label>Domain label</Label>
                                <Input
                                    value={domain.label}
                                    onChange={(event) =>
                                        updateDomain(domainIndex, {
                                            label: event.target.value,
                                            key:
                                                domain.key ||
                                                event.target.value
                                                    .toLowerCase()
                                                    .replace(/[^a-z0-9]+/g, '_')
                                                    .replace(/^_|_$/g, ''),
                                        })
                                    }
                                    placeholder="e.g. Daily living"
                                />
                                {errors[
                                    `content.domains.${domainIndex}.label`
                                ] ? (
                                    <p className="text-xs text-status-critical">
                                        {
                                            errors[
                                                `content.domains.${domainIndex}.label`
                                            ]
                                        }
                                    </p>
                                ) : null}
                            </div>
                            <div className="space-y-2">
                                <Label>Status</Label>
                                <Select
                                    value={domain.status}
                                    onValueChange={(value) =>
                                        updateDomain(domainIndex, {
                                            status: value as CarePlanDomainDraft['status'],
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="on_track">
                                            On track
                                        </SelectItem>
                                        <SelectItem value="review">
                                            Review
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                className="self-end text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                onClick={() =>
                                    onChange(
                                        rows.filter(
                                            (_domain, currentIndex) =>
                                                currentIndex !== domainIndex,
                                        ),
                                    )
                                }
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        </div>

                        <div className="mt-4 space-y-3">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-sm font-medium">
                                    Strategies
                                </p>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() => addStrategy(domainIndex)}
                                >
                                    <Plus className="mr-1.5 h-3.5 w-3.5" />
                                    Add strategy
                                </Button>
                            </div>
                            {domain.strategies.map(
                                (strategy, strategyIndex) => (
                                    <div
                                        key={`${domain.key}-${strategyIndex}`}
                                        className="grid gap-3 rounded-lg border bg-muted/30 p-3 md:grid-cols-[minmax(0,1fr)_14rem_auto]"
                                    >
                                        <div className="space-y-2">
                                            <Label>Strategy</Label>
                                            <Textarea
                                                value={strategy.text}
                                                onChange={(event) =>
                                                    updateStrategy(
                                                        domainIndex,
                                                        strategyIndex,
                                                        {
                                                            text: event.target
                                                                .value,
                                                        },
                                                    )
                                                }
                                                placeholder="e.g. Meds prompted after breakfast"
                                                rows={2}
                                            />
                                            {errors[
                                                `content.domains.${domainIndex}.strategies.${strategyIndex}.text`
                                            ] ? (
                                                <p className="text-xs text-status-critical">
                                                    {
                                                        errors[
                                                            `content.domains.${domainIndex}.strategies.${strategyIndex}.text`
                                                        ]
                                                    }
                                                </p>
                                            ) : null}
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Owner</Label>
                                            <Select
                                                value={
                                                    strategy.owner || '__none'
                                                }
                                                onValueChange={(value) =>
                                                    updateStrategy(
                                                        domainIndex,
                                                        strategyIndex,
                                                        {
                                                            owner:
                                                                value ===
                                                                '__none'
                                                                    ? ''
                                                                    : value,
                                                        },
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Owner" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="__none">
                                                        No owner
                                                    </SelectItem>
                                                    {ownerOptions.map(
                                                        (owner) => (
                                                            <SelectItem
                                                                key={owner}
                                                                value={owner}
                                                            >
                                                                {owner}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="self-end text-status-critical hover:bg-status-critical-bg hover:text-status-critical"
                                            onClick={() =>
                                                removeStrategy(
                                                    domainIndex,
                                                    strategyIndex,
                                                )
                                            }
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ),
                            )}
                        </div>
                    </div>
                ))}
            </CardContent>
        </Card>
    );
}
