import {
    CalendarPlus,
    Copy,
    LayoutTemplate,
    MoreVertical,
    Pencil,
    Plus,
    Search,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

import { MicroStats, type MicroStat } from './micro-stats';

/* ------------------------------------------------------------------ */
/*  Shared types (also consumed by the template pop-ups)               */
/* ------------------------------------------------------------------ */

export type RosterTemplateShiftRow = {
    id?: number;
    client_id: number | null;
    user_id: number | null;
    service_context_id: number | null;
    day_of_week: number;
    start_time: string; // "HH:MM"
    end_time: string; // "HH:MM"
    shift_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    is_lone_worker: boolean;
    expected_break_minutes: number | null;
    required_skills: string[];
    location: string | null;
    notes: string | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    user?: { id: number; name: string } | null;
    service_context?: { id: number; name: string } | null;
};

export type TemplateCapabilities = {
    can_view: boolean;
    can_create: boolean;
    can_edit: boolean;
    can_duplicate: boolean;
    can_apply: boolean;
    can_delete: boolean;
};

export type RosterTemplateRow = {
    source_revision?: string;
    capabilities?: TemplateCapabilities;
    urls?: {
        edit?: string | null;
        update?: string | null;
        duplicate?: string | null;
        apply?: string | null;
        delete?: string | null;
    };
    id: number;
    name: string;
    description: string | null;
    template_type: string;
    is_active: boolean;
    template_shifts_count: number;
    creator?: { id: number; name: string } | null;
    updated_at?: string | null;
    template_shifts: RosterTemplateShiftRow[];
};

export const TEMPLATE_DAY_SHORT = [
    'Mon',
    'Tue',
    'Wed',
    'Thu',
    'Fri',
    'Sat',
    'Sun',
] as const;

export type TemplatesPaneProps = {
    /** null = not yet loaded (lazy). */
    templates: RosterTemplateRow[] | null;
    loading?: boolean;
    loadError?: string | null;
    onRetry?: () => void;
    query?: string;
    activeFilter?: boolean;
    showControls?: boolean;
    capabilities: TemplateCapabilities;
    onCreate: () => void;
    onView: (template: RosterTemplateRow) => void;
    onEdit: (template: RosterTemplateRow) => void;
    onDelete: (template: RosterTemplateRow) => void;
    actionsBlocked?: boolean;
    onDuplicate: (template: RosterTemplateRow) => void;
};

/* ------------------------------------------------------------------ */
/*  Week strip — Mon–Sun shape at a glance                             */
/* ------------------------------------------------------------------ */

function WeekStrip({ shifts }: { shifts: RosterTemplateShiftRow[] }) {
    const byDay = useMemo(() => {
        const counts = [0, 0, 0, 0, 0, 0, 0];
        for (const s of shifts) {
            if (s.day_of_week >= 0 && s.day_of_week < 7)
                counts[s.day_of_week]++;
        }
        return counts;
    }, [shifts]);

    return (
        <div className="flex gap-1">
            {TEMPLATE_DAY_SHORT.map((day, i) => {
                const count = byDay[i];
                return (
                    <div
                        key={day}
                        title={`${day}: ${count} shift${count === 1 ? '' : 's'}`}
                        className={cn(
                            'flex h-10 flex-1 flex-col items-center justify-center rounded-md border text-[10px] font-semibold',
                            count
                                ? 'border-primary/30 bg-primary/10 text-primary'
                                : 'border-border bg-muted/40 text-muted-foreground',
                        )}
                    >
                        <span className="tracking-wide uppercase">{day}</span>
                        <span className="tabular-nums">{count || '·'}</span>
                    </div>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Card                                                               */
/* ------------------------------------------------------------------ */

function TemplateCard({
    template,
    actionsBlocked,
    canEdit,
    canDuplicate,
    canApply,
    canDelete,
    onView,
    onEdit,
    onDelete,
    onDuplicate,
}: {
    template: RosterTemplateRow;
    actionsBlocked: boolean;
    canEdit: boolean;
    canDuplicate: boolean;
    canApply: boolean;
    canDelete: boolean;
    onView: () => void;
    onEdit: () => void;
    onDelete: () => void;
    onDuplicate: () => void;
}) {
    const assignedRows = template.template_shifts.filter(
        (s) => s.user_id != null,
    ).length;
    const openRows = template.template_shifts_count - assignedRows;
    const updated = template.updated_at
        ? new Date(template.updated_at).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              timeZone: 'Pacific/Auckland',
          })
        : '—';

    return (
        <div
            role="button"
            tabIndex={0}
            data-test={`template-card-${template.id}`}
            onClick={onView}
            onKeyDown={(e) => {
                if (
                    e.target === e.currentTarget &&
                    (e.key === 'Enter' || e.key === ' ')
                ) {
                    e.preventDefault();
                    onView();
                }
            }}
            className={cn(
                'group flex min-w-0 cursor-pointer flex-col gap-3 rounded-[14px] border border-border bg-card p-4 text-left shadow-sm transition-colors',
                'hover:border-primary/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                !template.is_active && 'opacity-75',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-bold tracking-tight">
                        {template.name}
                    </h3>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <span className="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary capitalize">
                            {template.template_type}
                        </span>
                        <StatusBadge
                            variant={template.is_active ? 'success' : 'neutral'}
                        >
                            {template.is_active ? 'Active' : 'Inactive'}
                        </StatusBadge>
                    </div>
                </div>

                {canEdit || canDuplicate || canDelete ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="frontline-hit h-7 w-7 shrink-0 text-muted-foreground"
                                aria-label="Template actions"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <MoreVertical className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {canEdit ? (
                                <DropdownMenuItem
                                    disabled={actionsBlocked}
                                    onSelect={() => onEdit()}
                                >
                                    <Pencil className="h-3.5 w-3.5" /> Edit
                                    template
                                </DropdownMenuItem>
                            ) : null}
                            {canDuplicate ? (
                                <DropdownMenuItem
                                    disabled={actionsBlocked}
                                    onSelect={() => onDuplicate()}
                                >
                                    <Copy className="h-3.5 w-3.5" /> Duplicate
                                </DropdownMenuItem>
                            ) : null}
                            {canDelete ? (
                                <DropdownMenuItem
                                    variant="destructive"
                                    disabled={actionsBlocked}
                                    onSelect={() => onDelete()}
                                >
                                    <Trash2 className="h-3.5 w-3.5" /> Delete
                                </DropdownMenuItem>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </div>

            {template.description ? (
                <p className="line-clamp-2 text-[13px] leading-snug [overflow-wrap:anywhere] text-muted-foreground">
                    {template.description}
                </p>
            ) : null}

            <WeekStrip shifts={template.template_shifts} />

            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                <span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {template.template_shifts_count}
                    </span>{' '}
                    {template.template_shifts_count === 1 ? 'row' : 'rows'}
                </span>
                <span aria-hidden>·</span>
                <span>
                    <span className="font-semibold text-foreground tabular-nums">
                        {assignedRows}
                    </span>{' '}
                    assigned
                </span>
                {openRows > 0 ? (
                    <>
                        <span aria-hidden>·</span>
                        <span className="text-status-warning">
                            <span className="font-semibold tabular-nums">
                                {openRows}
                            </span>{' '}
                            open
                        </span>
                    </>
                ) : null}
                <span aria-hidden>·</span>
                <span>Updated {updated}</span>
            </div>

            <div className="mt-auto flex items-center gap-2 pt-1">
                <Button
                    size="sm"
                    className="flex-1"
                    onClick={(e) => {
                        e.stopPropagation();
                        onView();
                    }}
                >
                    <CalendarPlus className="h-3.5 w-3.5" />{' '}
                    {canApply ? 'Review & apply' : 'View template'}
                </Button>
                {canEdit ? (
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={actionsBlocked}
                        onClick={(e) => {
                            e.stopPropagation();
                            onEdit();
                        }}
                    >
                        <Pencil className="h-3.5 w-3.5" /> Edit
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pane                                                               */
/* ------------------------------------------------------------------ */

export function TemplatesPane({
    templates,
    loading = false,
    loadError,
    onRetry,
    query,
    activeFilter,
    showControls = true,
    actionsBlocked = false,
    capabilities,
    onCreate,
    onView,
    onEdit,
    onDelete,
    onDuplicate,
}: TemplatesPaneProps) {
    const [search, setSearch] = useState('');
    const [localActiveOnly, setActiveOnly] = useState(false);
    const activeOnly = activeFilter ?? localActiveOnly;

    const list = useMemo(() => templates ?? [], [templates]);

    const stats: MicroStat[] = useMemo(() => {
        const rows = list.reduce((sum, t) => sum + t.template_shifts_count, 0);
        const openRows = list.reduce(
            (sum, t) =>
                sum + t.template_shifts.filter((s) => s.user_id == null).length,
            0,
        );
        return [
            { label: 'Templates', value: list.length, tone: 'info' },
            {
                label: 'Active',
                value: list.filter((t) => t.is_active).length,
                tone: 'ok',
            },
            { label: 'Shift rows', value: rows, tone: 'info' },
            {
                label: 'Open rows',
                value: openRows,
                tone: openRows > 0 ? 'warn' : 'ok',
            },
        ];
    }, [list]);

    const filtered = useMemo(() => {
        const q = (query ?? search).trim().toLowerCase();
        return list.filter((t) => {
            if (activeOnly && !t.is_active) return false;
            if (!q) return true;
            return (
                t.name.toLowerCase().includes(q) ||
                (t.description ?? '').toLowerCase().includes(q)
            );
        });
    }, [list, query, search, activeOnly]);

    if (templates === null || loading) {
        return (
            <div className="space-y-4">
                <div className="rounded-[14px] border border-border bg-card p-10 text-center text-sm text-muted-foreground shadow-sm">
                    {loading
                        ? 'Loading roster templates…'
                        : 'Templates have not loaded.'}
                    {!loading && onRetry ? (
                        <Button variant="outline" onClick={onRetry}>
                            Try again
                        </Button>
                    ) : null}
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {showControls ? (
                <>
                    {loadError ? (
                        <p role="alert" className="text-sm text-status-warning">
                            {loadError}{' '}
                            <Button variant="outline" onClick={onRetry}>
                                Try again
                            </Button>
                        </p>
                    ) : null}
                    <MicroStats stats={stats} />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search templates…"
                                    className="h-9 w-56 pl-8"
                                />
                            </div>
                            <div className="inline-flex gap-1 rounded-lg bg-muted p-1">
                                {[
                                    { key: false, label: 'All' },
                                    { key: true, label: 'Active' },
                                ].map((opt) => (
                                    <Button
                                        unstyled
                                        key={String(opt.key)}
                                        type="button"
                                        aria-pressed={activeOnly === opt.key}
                                        onClick={() => setActiveOnly(opt.key)}
                                        className={cn(
                                            'rounded-md px-3 py-1 text-[13px] font-semibold transition-colors',
                                            activeOnly === opt.key
                                                ? 'bg-card text-foreground shadow-sm'
                                                : 'text-muted-foreground hover:text-foreground',
                                        )}
                                    >
                                        {opt.label}
                                    </Button>
                                ))}
                            </div>
                        </div>
                        {capabilities.can_create ? (
                            <Button
                                size="sm"
                                disabled={actionsBlocked}
                                onClick={onCreate}
                            >
                                <Plus className="h-4 w-4" /> New template
                            </Button>
                        ) : null}
                    </div>
                </>
            ) : null}
            {filtered.length > 0 ? (
                <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                    {filtered.map((template) => (
                        <TemplateCard
                            actionsBlocked={actionsBlocked}
                            key={template.id}
                            template={template}
                            canEdit={Boolean(
                                template.capabilities?.can_edit &&
                                capabilities.can_edit &&
                                template.urls?.update,
                            )}
                            canDuplicate={Boolean(
                                template.capabilities?.can_duplicate &&
                                capabilities.can_duplicate &&
                                template.urls?.duplicate,
                            )}
                            canApply={Boolean(
                                template.capabilities?.can_apply &&
                                capabilities.can_apply &&
                                template.urls?.apply,
                            )}
                            canDelete={Boolean(
                                template.capabilities?.can_delete &&
                                capabilities.can_delete &&
                                template.urls?.delete,
                            )}
                            onView={() => onView(template)}
                            onEdit={() => onEdit(template)}
                            onDelete={() => onDelete(template)}
                            onDuplicate={() => onDuplicate(template)}
                        />
                    ))}
                </div>
            ) : (
                <div className="flex flex-col items-center gap-3 rounded-[14px] border border-dashed border-border bg-card p-10 text-center shadow-sm">
                    <span className="grid h-12 w-12 place-items-center rounded-2xl bg-primary/10 text-primary">
                        <LayoutTemplate className="h-6 w-6" />
                    </span>
                    <div>
                        <p className="text-sm font-semibold">
                            {list.length === 0
                                ? 'No roster templates yet'
                                : 'No templates match your filters'}
                        </p>
                        <p className="mt-0.5 text-[13px] text-muted-foreground">
                            {list.length === 0
                                ? 'Save a reusable weekly pattern, then apply it to any week in a couple of clicks.'
                                : 'Try clearing the search or the Active filter.'}
                        </p>
                    </div>
                    {capabilities.can_create && list.length === 0 ? (
                        <Button
                            size="sm"
                            disabled={actionsBlocked}
                            onClick={onCreate}
                        >
                            <Plus className="h-4 w-4" /> Create your first
                            template
                        </Button>
                    ) : null}
                </div>
            )}
        </div>
    );
}

export default TemplatesPane;
