import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import {
    CalendarDays,
    ChevronDown,
    ChevronUp,
    GripVertical,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { Definition, FilterGroup, Source } from './model';
export function StudioChoice({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: { value: string; label: string }[];
    onChange: (value: string) => void;
}) {
    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            <Select
                value={value || '_none'}
                onValueChange={(v) => onChange(v === '_none' ? '' : v)}
            >
                <SelectTrigger aria-label={label}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((o) => (
                        <SelectItem
                            key={o.value || '_none'}
                            value={o.value || '_none'}
                        >
                            {o.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
export const reportDate = (iso: string) =>
    new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(iso + 'T12:00:00Z'));
export function ReportRange({
    definition,
    onChange,
}: {
    definition: Definition;
    onChange: (patch: Partial<Definition>) => void;
}) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState<{
        start: string | null;
        end: string | null;
    }>({ start: definition.date_from, end: definition.date_to });
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Pacific/Auckland',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
    const preset = (days: number) => {
        const from = new Date(today + 'T12:00:00Z');
        from.setUTCDate(from.getUTCDate() - days + 1);
        onChange({
            date_from: from.toISOString().slice(0, 10),
            date_to: today,
        });
    };
    return (
        <div className="space-y-2">
            <Label>Report period</Label>
            <div className="flex flex-wrap gap-1">
                <Button size="sm" variant="ghost" onClick={() => preset(7)}>
                    7 days
                </Button>
                <Button size="sm" variant="ghost" onClick={() => preset(30)}>
                    30 days
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        onChange({
                            date_from: today.slice(0, 8) + '01',
                            date_to: today,
                        })
                    }
                >
                    This month
                </Button>
            </div>
            <Popover
                open={open}
                onOpenChange={(v) => {
                    if (v)
                        setDraft({
                            start: definition.date_from,
                            end: definition.date_to,
                        });
                    setOpen(v);
                }}
            >
                <PopoverTrigger asChild>
                    <Button variant="outline" className="w-full justify-start">
                        <CalendarDays className="size-4 shrink-0" />
                        <span className="truncate">
                            {reportDate(definition.date_from)} –{' '}
                            {reportDate(definition.date_to)}
                        </span>
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    className="flex max-h-[min(580px,85dvh)] w-[min(370px,calc(100vw-32px))] flex-col p-0"
                >
                    <div className="min-h-0 overflow-y-auto p-4">
                        <LeaveCalendarRange
                            start={draft.start}
                            end={draft.end}
                            onChange={(start, end) => setDraft({ start, end })}
                        />
                        <p className="text-caption mt-3">
                            Inclusive dates · Pacific/Auckland
                        </p>
                    </div>
                    <div className="flex shrink-0 justify-end gap-2 border-t p-3">
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={
                                !draft.start || !draft.end || draft.end > today
                            }
                            onClick={() => {
                                onChange({
                                    date_from: draft.start!,
                                    date_to: draft.end!,
                                });
                                setOpen(false);
                            }}
                        >
                            Use dates
                        </Button>
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    );
}
export function ColumnEditor({
    definition,
    source,
    onChange,
}: {
    definition: Definition;
    source: Source;
    onChange: (patch: Partial<Definition>) => void;
}) {
    const [query, setQuery] = useState('');
    const [dragged, setDragged] = useState<string | null>(null);
    const move = (field: string, target: string) => {
        const list = definition.columns.filter((f) => f !== field);
        list.splice(definition.columns.indexOf(target), 0, field);
        onChange({ columns: list });
    };
    return (
        <>
            <p className="text-subtle">
                Choose up to 30 fields. Drag a selected field or use its arrows
                to reorder.
            </p>
            <Input
                aria-label="Find a column"
                placeholder="Find a column…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
            />
            <div className="report-field-list">
                {Object.entries(source.fields)
                    .filter(([, f]) =>
                        f.label.toLowerCase().includes(query.toLowerCase()),
                    )
                    .map(([key, f]) => (
                        <Label
                            key={key}
                            className="flex min-h-11 items-center gap-2"
                        >
                            <Checkbox
                                checked={definition.columns.includes(key)}
                                disabled={
                                    !definition.columns.includes(key) &&
                                    definition.columns.length >= 30
                                }
                                onCheckedChange={(checked) =>
                                    onChange({
                                        columns:
                                            checked === true
                                                ? [...definition.columns, key]
                                                : definition.columns.filter(
                                                      (c) => c !== key,
                                                  ),
                                    })
                                }
                            />
                            {f.label}
                        </Label>
                    ))}
            </div>
            <p className="text-caption">
                Selected order · {definition.columns.length} fields
            </p>
            <ul className="space-y-2" aria-label="Selected columns">
                {definition.columns.map((field, i) => (
                    <li
                        key={field}
                        draggable
                        onDragStart={(e) => {
                            setDragged(field);
                            e.dataTransfer.effectAllowed = 'move';
                            e.dataTransfer.setData('text/plain', field);
                        }}
                        onDragEnd={() => setDragged(null)}
                        onDragOver={(e) => {
                            if (dragged) e.preventDefault();
                        }}
                        onDrop={(e) => {
                            e.preventDefault();
                            if (dragged && dragged !== field)
                                move(dragged, field);
                            setDragged(null);
                        }}
                        className="flex items-center gap-1 rounded-lg border p-2"
                    >
                        <GripVertical className="size-4 shrink-0 text-muted-foreground" />
                        <span className="min-w-0 flex-1 text-sm">
                            {source.fields[field].label}
                        </span>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label={
                                'Move ' + source.fields[field].label + ' up'
                            }
                            disabled={i === 0}
                            onClick={() =>
                                move(field, definition.columns[i - 1])
                            }
                        >
                            <ChevronUp className="size-4" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label={
                                'Move ' + source.fields[field].label + ' down'
                            }
                            disabled={i === definition.columns.length - 1}
                            onClick={() =>
                                move(field, definition.columns[i + 1])
                            }
                        >
                            <ChevronDown className="size-4" />
                        </Button>
                    </li>
                ))}
            </ul>
        </>
    );
}
export function GroupedFilters({
    definition,
    source,
    onChange,
}: {
    definition: Definition;
    source: Source;
    onChange: (patch: Partial<Definition>) => void;
}) {
    const groups: FilterGroup[] = definition.filter_groups?.length
        ? definition.filter_groups
        : definition.filters.length
          ? [{ match: definition.match, filters: definition.filters }]
          : [];
    const count = groups.reduce((n, g) => n + g.filters.length, 0);
    const fresh = () => ({
        field: Object.keys(source.fields)[0],
        operator: 'eq',
        value: '',
    });
    const update = (next: FilterGroup[]) =>
        onChange({ filters: [], filter_groups: next });
    const alter = (i: number, group: FilterGroup) =>
        update(groups.map((g, j) => (j === i ? group : g)));
    return (
        <>
            <StudioChoice
                label="Match between groups"
                value={definition.match}
                options={[
                    { value: 'all', label: 'All groups (AND)' },
                    { value: 'any', label: 'Any group (OR)' },
                ]}
                onChange={(v) => onChange({ match: v as 'all' | 'any' })}
            />
            {groups.map((g, gi) => (
                <fieldset className="report-filter-group" key={gi}>
                    <legend>Group {gi + 1}</legend>
                    <StudioChoice
                        label={'Match within group ' + (gi + 1)}
                        value={g.match}
                        options={[
                            { value: 'all', label: 'All rules (AND)' },
                            { value: 'any', label: 'Any rule (OR)' },
                        ]}
                        onChange={(v) =>
                            alter(gi, { ...g, match: v as 'all' | 'any' })
                        }
                    />
                    {g.filters.map((f, fi) => {
                        const put = (patch: Partial<typeof f>) =>
                            alter(gi, {
                                ...g,
                                filters: g.filters.map((r, i) =>
                                    i === fi ? { ...r, ...patch } : r,
                                ),
                            });
                        return (
                            <div className="space-y-3 border-t pt-3" key={fi}>
                                <StudioChoice
                                    label={
                                        'Group ' +
                                        (gi + 1) +
                                        ' rule ' +
                                        (fi + 1) +
                                        ' field'
                                    }
                                    value={f.field}
                                    options={Object.entries(source.fields).map(
                                        ([value, m]) => ({
                                            value,
                                            label: m.label,
                                        }),
                                    )}
                                    onChange={(field) => put({ field })}
                                />
                                <StudioChoice
                                    label={
                                        'Group ' +
                                        (gi + 1) +
                                        ' rule ' +
                                        (fi + 1) +
                                        ' condition'
                                    }
                                    value={f.operator}
                                    options={Object.entries({
                                        eq: 'Equals',
                                        ne: 'Does not equal',
                                        gt: 'Greater than / after',
                                        gte: 'At least / on or after',
                                        lt: 'Less than / before',
                                        lte: 'At most / on or before',
                                        contains: 'Contains',
                                        missing: 'Unknown',
                                        known: 'Known',
                                    }).map(([value, label]) => ({
                                        value,
                                        label,
                                    }))}
                                    onChange={(operator) => put({ operator })}
                                />
                                {!['missing', 'known'].includes(f.operator) && (
                                    <Input
                                        aria-label={
                                            'Group ' +
                                            (gi + 1) +
                                            ' rule ' +
                                            (fi + 1) +
                                            ' value'
                                        }
                                        placeholder={
                                            source.fields[f.field].type ===
                                            'date'
                                                ? 'YYYY-MM-DD'
                                                : 'Value'
                                        }
                                        value={f.value ?? ''}
                                        onChange={(e) =>
                                            put({ value: e.target.value })
                                        }
                                    />
                                )}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        const filters = g.filters.filter(
                                            (_, i) => i !== fi,
                                        );
                                        if (filters.length)
                                            alter(gi, { ...g, filters });
                                        else
                                            update(
                                                groups.filter(
                                                    (_, i) => i !== gi,
                                                ),
                                            );
                                    }}
                                >
                                    <Trash2 className="size-4" />
                                    Remove rule
                                </Button>
                            </div>
                        );
                    })}
                    <Button
                        variant="outline"
                        disabled={count >= 25}
                        onClick={() =>
                            alter(gi, {
                                ...g,
                                filters: [...g.filters, fresh()],
                            })
                        }
                    >
                        <Plus className="size-4" />
                        Add rule
                    </Button>
                </fieldset>
            ))}
            <Button
                variant="outline"
                disabled={count >= 25 || groups.length >= 8}
                onClick={() =>
                    update([...groups, { match: 'all', filters: [fresh()] }])
                }
            >
                <Plus className="size-4" />
                Add filter group
            </Button>
            <p className="text-caption text-muted-foreground">
                {count}/25 rules · Unknown values require an explicit Unknown
                condition.
            </p>
        </>
    );
}
