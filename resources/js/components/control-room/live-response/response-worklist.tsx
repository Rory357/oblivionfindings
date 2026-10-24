import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import {
    severityBadge,
    targetBadge,
} from '@/components/control-room/alert-record/alert-labels';
import {
    OwnerChip,
    rowClock,
    UrgencyBar,
    whoAndWhere,
} from '@/components/control-room/live-response/alert-glance';
import { raisedWords, useNow } from '@/components/control-room/live-response/response-clock';
import { TargetLens } from '@/components/control-room/live-response/target-lens';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Inbox, type LucideIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

export type WorklistPage = {
    data: AlertWorklistRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    meta: { current_page: number; last_page: number; total: number };
};

/** Alerts that arrived after the list first drew, glowing for a moment. */
function useArrivals(rows: AlertWorklistRow[]): ReadonlySet<number> {
    const seen = useRef<Set<number> | null>(null);
    const [fresh, setFresh] = useState<ReadonlySet<number>>(new Set());
    useEffect(() => {
        if (seen.current === null) {
            seen.current = new Set(rows.map((row) => row.id));
            return;
        }
        const arrived = rows.filter((row) => !seen.current!.has(row.id));
        if (arrived.length === 0) return;
        arrived.forEach((row) => seen.current!.add(row.id));
        setFresh(new Set(arrived.map((row) => row.id)));
        const id = window.setTimeout(() => setFresh(new Set()), 4000);
        return () => window.clearTimeout(id);
    }, [rows]);
    return fresh;
}

/**
 * The Live response list: one row per alert, most urgent first. Each row
 * leads with the person and the house, says what happened in their words,
 * who is on it, and counts down to the next response target in the lens.
 * Selecting a row shows it beside the list; every row keeps the kebab AND
 * the right-click menu with the same items (LIST_STYLE_GUIDE.md).
 */
export function ResponseWorklist({
    title,
    description,
    worklist,
    selectedId,
    onSelect,
    getActions,
    emptyTitle,
    emptyDescription,
    emptyIcon = Inbox,
    toolbar,
    selection,
    keyHint = false,
}: {
    title: string;
    description: string;
    worklist: WorklistPage;
    selectedId: number | null;
    onSelect: (id: number) => void;
    getActions: (row: AlertWorklistRow) => readonly ControlRoomRowAction[];
    emptyTitle: string;
    emptyDescription: string;
    emptyIcon?: LucideIcon;
    /** Optional controls on the list's own header row (e.g. sort). */
    toolbar?: ReactNode;
    /** Tick rows for a bulk action (Escalated, the alert lists). */
    selection?: {
        selected: ReadonlySet<number>;
        onChange: (next: Set<number>) => void;
    };
    /** Show the keyboard reminder (the page wires useListKeys). */
    keyHint?: boolean;
}) {
    const headingId = useId();
    const rows = worklist.data;
    const now = useNow();
    const arrivals = useArrivals(rows);
    const allTicked =
        rows.length > 0 && rows.every((row) => selection?.selected.has(row.id));
    const toggleRow = (id: number, on: boolean) => {
        if (!selection) return;
        const next = new Set(selection.selected);
        if (on) next.add(id);
        else next.delete(id);
        selection.onChange(next);
    };

    return (
        <Card
            className="min-w-0 gap-0 overflow-hidden py-0"
            aria-labelledby={headingId}
            data-focus-return-owner
        >
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div className="flex min-w-0 items-start gap-3">
                    {selection && rows.length ? (
                        <Checkbox
                            className="mt-1"
                            checked={allTicked}
                            onCheckedChange={(on) =>
                                selection.onChange(
                                    on === true
                                        ? new Set(rows.map((row) => row.id))
                                        : new Set(),
                                )
                            }
                            aria-label="Select every alert on this page"
                        />
                    ) : null}
                    <div className="min-w-0">
                        <h2 id={headingId} className="text-section-title">
                            {title}
                        </h2>
                        <p className="text-subtle mt-0.5">{description}</p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    {toolbar}
                    <StatusBadge variant="neutral" className="tabular-nums">
                        {worklist.meta.total}{' '}
                        {worklist.meta.total === 1 ? 'alert' : 'alerts'}
                    </StatusBadge>
                </div>
            </div>

            {rows.length === 0 ? (
                <div className="px-5 py-10">
                    <EmptyState
                        icon={emptyIcon}
                        title={emptyTitle}
                        description={emptyDescription}
                    />
                </div>
            ) : (
                <ol className="divide-y divide-border">
                    {rows.map((row) => (
                        <ControlRoomRowActions
                            key={row.id}
                            label={`Actions for ${row.reference_number ?? `alert ${row.id}`}`}
                            items={getActions(row)}
                        >
                            {({ rowProps, overflowButton }) => (
                                <WorklistRow
                                    row={row}
                                    now={now}
                                    arrived={arrivals.has(row.id)}
                                    selected={row.id === selectedId}
                                    onSelect={() => onSelect(row.id)}
                                    rowProps={rowProps}
                                    overflowButton={overflowButton}
                                    ticked={
                                        selection
                                            ? selection.selected.has(row.id)
                                            : undefined
                                    }
                                    onTick={(on) => toggleRow(row.id, on)}
                                />
                            )}
                        </ControlRoomRowActions>
                    ))}
                </ol>
            )}

            {keyHint && rows.length > 1 ? (
                <div className="hidden border-t border-border px-5 py-2.5 lg:block">
                    <ListKeysHint />
                </div>
            ) : null}

            {worklist.meta.last_page > 1 ? (
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
                    <p className="text-caption">
                        Showing {rows.length} of {worklist.meta.total}
                    </p>
                    {/* Pagination: wide page links scroll here, never the page. */}
                    <div className="min-w-0 max-w-full overflow-x-auto scrollbar-pretty">
                        <div className="w-max">
                            <LaravelPagination
                                links={worklist.links}
                                lastPage={worklist.meta.last_page}
                            />
                        </div>
                    </div>
                </div>
            ) : null}
        </Card>
    );
}

/** ↑ ↓ move · A acknowledge · Enter open — shown where the list has the keys. */
export function ListKeysHint() {
    const key = 'rounded-[5px] border border-border bg-muted px-1.5 py-px font-sans text-[11px] font-semibold text-foreground';
    return (
        <span className="text-caption inline-flex flex-wrap items-center gap-1.5" aria-hidden="true">
            Keys:
            <kbd className={key}>↑</kbd>
            <kbd className={key}>↓</kbd> move
            <kbd className={key}>A</kbd> acknowledge
            <kbd className={key}>Enter</kbd> open
        </span>
    );
}

function WorklistRow({
    row,
    now,
    arrived,
    selected,
    onSelect,
    rowProps,
    overflowButton,
    ticked,
    onTick,
}: {
    row: AlertWorklistRow;
    now: number;
    arrived: boolean;
    selected: boolean;
    onSelect: () => void;
    rowProps: { onContextMenu: (event: React.MouseEvent<HTMLElement>) => void; tabIndex: number };
    overflowButton: ReactNode;
    /** undefined = this list has no tick boxes. */
    ticked?: boolean;
    onTick: (on: boolean) => void;
}) {
    const severity = severityBadge(row.severity);
    const target =
        row.sla.status === 'breached' || row.sla.status === 'at_risk'
            ? targetBadge(row.sla.status)
            : null;
    const clock = rowClock(row, now);
    const { who, where } = whoAndWhere(row);
    const raised = raisedWords(row.triggered_at, now);

    return (
        <li
            onContextMenu={rowProps.onContextMenu}
            data-alert-row={row.id}
            data-selected={selected ? 'true' : undefined}
            className={cn(
                'relative flex items-start gap-3 py-3.5 pr-3 pl-5 transition-colors',
                selected
                    ? 'bg-primary-fill/10 shadow-[inset_-3px_0_0_var(--primary)]'
                    : 'hover:bg-muted/40',
                arrived && 'cr-row-new',
            )}
        >
            <UrgencyBar severity={row.severity} />
            {ticked !== undefined ? (
                <Checkbox
                    className="mt-3"
                    checked={ticked}
                    onCheckedChange={(on) => onTick(on === true)}
                    aria-label={`Select ${row.reference_number ?? `alert ${row.id}`}`}
                />
            ) : null}
            <TargetLens clock={clock} label="Next response target" />
            <Button
                unstyled
                type="button"
                onClick={onSelect}
                aria-current={selected ? 'true' : undefined}
                className="min-w-0 flex-1 rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <span className="flex flex-wrap items-baseline gap-x-1.5">
                    <span className="font-semibold text-foreground">{who}</span>
                    {where ? (
                        <span className="text-subtle">· {where}</span>
                    ) : null}
                </span>
                <span className="mt-0.5 block text-sm break-words text-foreground">
                    {row.summary}
                </span>
                <span className="text-caption mt-1.5 flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <StatusBadge variant={severity.tone} size="sm">
                        {severity.label}
                    </StatusBadge>
                    {target ? (
                        <StatusBadge variant={target.tone} size="sm">
                            {target.label}
                        </StatusBadge>
                    ) : null}
                    <OwnerChip name={row.assignee?.name} />
                    {raised ? (
                        <span title={formatDateTime(row.triggered_at)}>{raised}</span>
                    ) : null}
                    {row.playbook ? (
                        <span>
                            {row.playbook.completed_steps} of {row.playbook.total_steps}{' '}
                            procedure steps
                        </span>
                    ) : null}
                    <span className="tabular-nums">
                        {row.reference_number ?? `Alert ${row.id}`}
                    </span>
                </span>
            </Button>
            <div className="shrink-0">{overflowButton}</div>
        </li>
    );
}
