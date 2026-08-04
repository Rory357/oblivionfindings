import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { SlidersHorizontal, X } from 'lucide-react';
import type { ReactNode } from 'react';

export type TicketAdvancedFilterKey =
    | 'source'
    | 'work_type'
    | 'service'
    | 'age'
    | 'missing'
    | 'reopened'
    | 'first_contact'
    | 'open_only'
    | 'device_linked'
    | 'resolved_from'
    | 'resolved_to';

export interface TicketAdvancedFilterValues {
    source: string | null;
    workType: string | null;
    service: number | null;
    age: string | null;
    missing: string | null;
    reopened: boolean;
    firstContact: boolean;
    openOnly: boolean;
    deviceLinked: boolean;
    resolvedFrom: string | null;
    resolvedTo: string | null;
}

interface ServiceOption {
    id: number;
    name: string;
}

interface Props {
    values: TicketAdvancedFilterValues;
    services: ServiceOption[];
    onChange: (key: TicketAdvancedFilterKey, value: string | undefined) => void;
    onClear: () => void;
}

const ALL = 'all';

const WORK_TYPES = [
    { value: 'incident', label: 'Incident' },
    { value: 'service_request', label: 'Service request' },
    { value: 'problem', label: 'Problem' },
    { value: 'change', label: 'Change' },
    { value: 'task', label: 'Task' },
    { value: 'security_request', label: 'Security request' },
    { value: 'major_incident', label: 'Major incident' },
];

const SOURCES = [
    { value: 'portal', label: 'Self-service portal' },
    { value: 'agent', label: 'Logged by agent' },
    { value: 'email', label: 'Email' },
    { value: 'system', label: 'System or monitoring' },
];

const AGES = [
    { value: 'under_2', label: 'Under 2 days' },
    { value: '2_7', label: '2–7 days' },
    { value: '8_30', label: '8–30 days' },
    { value: 'over_30', label: 'Over 30 days' },
];

const MISSING_FIELDS = [
    { value: 'service', label: 'Affected service' },
    { value: 'queue', label: 'Queue' },
    { value: 'team', label: 'Responsible team' },
    { value: 'assignee', label: 'Assignee' },
];

export function TicketAdvancedFilters({
    values,
    services,
    onChange,
    onClear,
}: Props) {
    const activeCount = [
        values.source,
        values.workType,
        values.service,
        values.age,
        values.missing,
        values.reopened,
        values.firstContact,
        values.openOnly,
        values.deviceLinked,
        values.resolvedFrom,
        values.resolvedTo,
    ].filter(Boolean).length;

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    aria-label={`More ticket filters${activeCount > 0 ? `, ${activeCount} active` : ''}`}
                >
                    <SlidersHorizontal className="h-3.5 w-3.5" />
                    More filters
                    {activeCount > 0 ? (
                        <span className="rounded-full bg-primary px-1.5 text-[11px] font-bold text-primary-foreground tabular-nums">
                            {activeCount}
                        </span>
                    ) : null}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[min(760px,calc(100vw-2rem))] space-y-4 p-4"
            >
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h3 className="text-sm font-semibold">
                            More ticket filters
                        </h3>
                        <p className="mt-0.5 text-[12px] text-muted-foreground">
                            Narrow the queue without hiding the everyday
                            filters.
                        </p>
                    </div>
                    {activeCount > 0 ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onClear}
                        >
                            <X className="h-3.5 w-3.5" />
                            Clear more filters
                        </Button>
                    ) : null}
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <FilterGroup
                        title="Classification"
                        description="How the work entered and where it belongs."
                    >
                        <FilterSelect
                            ariaLabel="Filter by work type"
                            value={values.workType ?? ALL}
                            allLabel="All work types"
                            options={WORK_TYPES}
                            onChange={(value) =>
                                onChange(
                                    'work_type',
                                    value === ALL ? undefined : value,
                                )
                            }
                        />
                        <FilterSelect
                            ariaLabel="Filter by affected service"
                            value={
                                values.service !== null
                                    ? String(values.service)
                                    : ALL
                            }
                            allLabel="All services"
                            options={services.map((service) => ({
                                value: String(service.id),
                                label: service.name,
                            }))}
                            onChange={(value) =>
                                onChange(
                                    'service',
                                    value === ALL ? undefined : value,
                                )
                            }
                        />
                        <FilterSelect
                            ariaLabel="Filter by ticket source"
                            value={values.source ?? ALL}
                            allLabel="All sources"
                            options={SOURCES}
                            onChange={(value) =>
                                onChange(
                                    'source',
                                    value === ALL ? undefined : value,
                                )
                            }
                        />
                    </FilterGroup>

                    <FilterGroup
                        title="Queue health"
                        description="Find ageing or incomplete triage work."
                    >
                        <FilterSelect
                            ariaLabel="Filter by ticket age"
                            value={values.age ?? ALL}
                            allLabel="Any age"
                            options={AGES}
                            onChange={(value) =>
                                onChange(
                                    'age',
                                    value === ALL ? undefined : value,
                                )
                            }
                        />
                        <FilterSelect
                            ariaLabel="Filter by missing ownership"
                            value={values.missing ?? ALL}
                            allLabel="Any ownership state"
                            options={MISSING_FIELDS}
                            onChange={(value) =>
                                onChange(
                                    'missing',
                                    value === ALL ? undefined : value,
                                )
                            }
                        />
                        <FilterToggle
                            label="Open tickets only"
                            checked={values.openOnly}
                            onCheckedChange={(checked) =>
                                onChange('open_only', checked ? '1' : undefined)
                            }
                        />
                        <FilterToggle
                            label="Linked to a Device"
                            checked={values.deviceLinked}
                            onCheckedChange={(checked) =>
                                onChange(
                                    'device_linked',
                                    checked ? '1' : undefined,
                                )
                            }
                        />
                    </FilterGroup>

                    <FilterGroup
                        title="Outcomes"
                        description="Review resolved, reopened and first-contact work."
                    >
                        <FilterToggle
                            label="Reopened tickets"
                            checked={values.reopened}
                            onCheckedChange={(checked) =>
                                onChange('reopened', checked ? '1' : undefined)
                            }
                        />
                        <FilterToggle
                            label="Resolved on first contact"
                            checked={values.firstContact}
                            onCheckedChange={(checked) =>
                                onChange(
                                    'first_contact',
                                    checked ? '1' : undefined,
                                )
                            }
                        />
                        <div className="grid grid-cols-2 gap-2">
                            <DateFilter
                                label="Resolved from"
                                value={values.resolvedFrom ?? ''}
                                max={values.resolvedTo ?? undefined}
                                onChange={(value) =>
                                    onChange(
                                        'resolved_from',
                                        value || undefined,
                                    )
                                }
                            />
                            <DateFilter
                                label="Resolved to"
                                value={values.resolvedTo ?? ''}
                                min={values.resolvedFrom ?? undefined}
                                onChange={(value) =>
                                    onChange('resolved_to', value || undefined)
                                }
                            />
                        </div>
                    </FilterGroup>
                </div>
            </PopoverContent>
        </Popover>
    );
}

function FilterGroup({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="space-y-2.5 rounded-xl border border-border/70 bg-muted/25 p-3">
            <div>
                <h4 className="text-[12px] font-bold text-foreground">
                    {title}
                </h4>
                <p className="mt-0.5 text-[11px] leading-snug text-muted-foreground">
                    {description}
                </p>
            </div>
            {children}
        </section>
    );
}

function FilterSelect({
    ariaLabel,
    value,
    allLabel,
    options,
    onChange,
}: {
    ariaLabel: string;
    value: string;
    allLabel: string;
    options: Array<{ value: string; label: string }>;
    onChange: (value: string) => void;
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="h-9 w-full" aria-label={ariaLabel}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{allLabel}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function FilterToggle({
    label,
    checked,
    onCheckedChange,
}: {
    label: string;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-lg border border-border/60 bg-card px-3 py-2 text-[12.5px] font-medium">
            <Checkbox
                checked={checked}
                onCheckedChange={(value) => onCheckedChange(value === true)}
            />
            {label}
        </label>
    );
}

function DateFilter({
    label,
    value,
    min,
    max,
    onChange,
}: {
    label: string;
    value: string;
    min?: string;
    max?: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className="space-y-1 text-[11px] font-semibold text-muted-foreground">
            <span>{label}</span>
            <input
                type="date"
                value={value}
                min={min}
                max={max}
                onChange={(event) => onChange(event.target.value)}
                aria-label={label}
                className="h-9 w-full rounded-md border border-border bg-card px-2 text-[12px] text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
            />
        </label>
    );
}
