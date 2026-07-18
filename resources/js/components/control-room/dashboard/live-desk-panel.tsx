import { AlertStatus } from '@/components/control-room/alert-worklist/alert-status';
import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    BookOpenCheck,
    Clock3,
    Filter,
    Search,
    UserRound,
} from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

export type DeskFilters = {
    q?: string;
    status?: string;
    severity?: string;
    source?: string;
    queue_id?: number | string | null;
    assigned_to?: string;
    site_id?: number | string | null;
    period?: string;
};

export type DeskWorklist = {
    data: AlertWorklistRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
};

type Option = { id: number; name: string };

export function LiveDeskPanel({
    worklist,
    filters,
    sites,
    staff,
    queues,
    onFilter,
    onOpen,
    getActions,
}: {
    worklist: DeskWorklist;
    filters: DeskFilters;
    sites: Option[];
    staff: Option[];
    queues: Array<{ id: number; name: string }>;
    onFilter: (filters: DeskFilters) => void;
    onOpen: (id: number) => void;
    getActions: (row: AlertWorklistRow) => readonly ControlRoomRowAction[];
}) {
    const [draft, setDraft] = useState<DeskFilters>(filters);

    useEffect(() => setDraft(filters), [filters]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onFilter(draft);
    };

    return (
        <Card
            data-desk-section="worklist"
            className="gap-0 overflow-hidden py-0"
        >
            <CardHeader className="gap-1 border-b px-5 py-5">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <CardTitle>
                            <h2>Priority worklist</h2>
                        </CardTitle>
                        <CardDescription className="mt-1">
                            One ordered list: breached deadlines first, then
                            severity, escalation, next deadline, and oldest
                            alert.
                        </CardDescription>
                    </div>
                    <span className="rounded-full border bg-muted/50 px-2.5 py-1 text-xs font-semibold tabular-nums">
                        {worklist.meta.total}{' '}
                        {worklist.meta.total === 1 ? 'item' : 'items'}
                    </span>
                </div>

                <form
                    role="search"
                    aria-label="Filter priority work"
                    onSubmit={submit}
                    className="mt-4 grid gap-2 xl:grid-cols-[minmax(240px,1.5fr)_repeat(5,minmax(110px,0.7fr))_auto]"
                >
                    <label className="relative">
                        <span className="sr-only">Search alerts</span>
                        <Search
                            className="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            value={draft.q ?? ''}
                            onChange={(event) =>
                                setDraft((current) => ({
                                    ...current,
                                    q: event.target.value,
                                }))
                            }
                            placeholder="Reference, incident, H&S or summary"
                            className="pl-9"
                        />
                    </label>
                    <FilterSelect
                        label="Severity"
                        value={draft.severity ?? ''}
                        onChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                severity: value,
                            }))
                        }
                    >
                        <option value="">All severities</option>
                        <option value="critical">Critical</option>
                        <option value="high">High</option>
                        <option value="medium">Medium</option>
                        <option value="low">Low</option>
                    </FilterSelect>
                    <FilterSelect
                        label="Source"
                        value={draft.source ?? ''}
                        onChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                source: value,
                            }))
                        }
                    >
                        <option value="">All sources</option>
                        <option value="manual">Manual</option>
                        <option value="fleet">Fleet</option>
                        <option value="compliance">Compliance</option>
                        <option value="incident">Incident</option>
                    </FilterSelect>
                    <FilterSelect
                        label="Owner"
                        value={draft.assigned_to ?? ''}
                        onChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                assigned_to: value,
                            }))
                        }
                    >
                        <option value="">All owners</option>
                        <option value="unassigned">Unassigned</option>
                        <option value="me">My queue</option>
                        {staff.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name}
                            </option>
                        ))}
                    </FilterSelect>
                    <FilterSelect
                        label="Queue"
                        value={draft.queue_id ?? ''}
                        onChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                queue_id: value,
                            }))
                        }
                    >
                        <option value="">All queues</option>
                        {queues.map((queue) => (
                            <option key={queue.id} value={queue.id}>
                                {queue.name}
                            </option>
                        ))}
                    </FilterSelect>
                    <FilterSelect
                        label="Site"
                        value={draft.site_id ?? ''}
                        onChange={(value) =>
                            setDraft((current) => ({
                                ...current,
                                site_id: value,
                            }))
                        }
                    >
                        <option value="">All permitted sites</option>
                        {sites.map((site) => (
                            <option key={site.id} value={site.id}>
                                {site.name}
                            </option>
                        ))}
                    </FilterSelect>
                    <Button type="submit" variant="outline">
                        <Filter className="h-4 w-4" aria-hidden />
                        Apply
                    </Button>
                </form>
            </CardHeader>

            <CardContent className="p-0">
                {worklist.data.length === 0 ? (
                    <div className="flex min-h-64 flex-col items-center justify-center gap-2 px-6 text-center">
                        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-muted">
                            <Search
                                className="h-5 w-5 text-muted-foreground"
                                aria-hidden
                            />
                        </span>
                        <p className="font-semibold">
                            No priority work matches these filters
                        </p>
                        <p className="max-w-md text-sm text-muted-foreground">
                            Clear a filter or check the history view. Snoozed
                            and dismissed alerts are intentionally kept out of
                            this live desk.
                        </p>
                    </div>
                ) : (
                    <ol className="divide-y">
                        {worklist.data.map((row) => (
                            <ControlRoomRowActions
                                key={row.id}
                                label={`Actions for ${row.reference_number ?? `alert ${row.id}`}`}
                                items={getActions(row)}
                            >
                                {({ rowProps, overflowButton }) => (
                                    <li
                                        {...rowProps}
                                        className="grid gap-4 px-4 py-4 transition-colors hover:bg-muted/30 sm:px-5 xl:grid-cols-[minmax(310px,1.4fr)_minmax(260px,1fr)_minmax(230px,0.9fr)_auto] xl:items-center"
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Link
                                                    href={row.href}
                                                    className="font-mono text-xs font-semibold text-primary hover:underline"
                                                >
                                                    {row.reference_number ??
                                                        'Reference pending'}
                                                </Link>
                                                <span className="text-xs text-muted-foreground">
                                                    {row.source.label}
                                                </span>
                                            </div>
                                            <p className="mt-1 leading-5 font-semibold text-foreground">
                                                {row.summary}
                                            </p>
                                            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                <span>
                                                    {row.site?.name ??
                                                        'Site not recorded'}
                                                </span>
                                                {row.person ? (
                                                    <span>
                                                        {row.person.name}
                                                    </span>
                                                ) : null}
                                                <span
                                                    title={formatDateTime(
                                                        row.triggered_at,
                                                    )}
                                                >
                                                    {formatRelative(
                                                        row.triggered_at,
                                                    )}
                                                </span>
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <AlertStatus
                                                status={row.status}
                                                severity={row.severity}
                                                slaStatus={row.sla.status}
                                            />
                                            <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                                                <Clock3
                                                    className="mt-0.5 h-3.5 w-3.5 shrink-0"
                                                    aria-hidden
                                                />
                                                <span>
                                                    {row.priority.reason}
                                                    {row.next_deadline_at
                                                        ? ` · deadline ${formatRelative(row.next_deadline_at)}`
                                                        : ''}
                                                </span>
                                            </p>
                                        </div>

                                        <div className="space-y-2 text-xs">
                                            <p className="flex items-center gap-1.5 text-muted-foreground">
                                                <UserRound
                                                    className="h-3.5 w-3.5"
                                                    aria-hidden
                                                />
                                                {row.assignee?.name ??
                                                    'Unassigned — claim or assign'}
                                            </p>
                                            {row.playbook ? (
                                                <p className="flex items-center gap-1.5 text-muted-foreground">
                                                    <BookOpenCheck
                                                        className="h-3.5 w-3.5"
                                                        aria-hidden
                                                    />
                                                    {row.playbook.name ??
                                                        'Response playbook'}{' '}
                                                    ·{' '}
                                                    {
                                                        row.playbook
                                                            .completed_steps
                                                    }
                                                    /{row.playbook.total_steps}{' '}
                                                    steps
                                                </p>
                                            ) : null}
                                            <p className="text-muted-foreground">
                                                {row.journey
                                                    .incident_reference ??
                                                    'Incident record not started'}
                                                {' · '}
                                                {row.journey
                                                    .health_safety_reference ??
                                                    'H&S not started'}
                                            </p>
                                        </div>

                                        <div className="flex items-center justify-end gap-1">
                                            <Button
                                                type="button"
                                                size="sm"
                                                onClick={() => onOpen(row.id)}
                                            >
                                                {row.next_action.label}
                                                <ArrowRight
                                                    className="h-4 w-4"
                                                    aria-hidden
                                                />
                                            </Button>
                                            {overflowButton}
                                        </div>
                                    </li>
                                )}
                            </ControlRoomRowActions>
                        ))}
                    </ol>
                )}

                <div className="flex items-center justify-between gap-4 border-t px-5 py-4">
                    <p className="text-xs text-muted-foreground">
                        Showing {worklist.data.length} of {worklist.meta.total}{' '}
                        priority items
                    </p>
                    <LaravelPagination
                        links={worklist.links}
                        lastPage={worklist.meta.last_page}
                    />
                </div>
            </CardContent>
        </Card>
    );
}

function FilterSelect({
    label,
    value,
    onChange,
    children,
}: {
    label: string;
    value: string | number;
    onChange: (value: string) => void;
    children: React.ReactNode;
}) {
    return (
        <label>
            <span className="sr-only">{label}</span>
            <select
                aria-label={label}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                {children}
            </select>
        </label>
    );
}
