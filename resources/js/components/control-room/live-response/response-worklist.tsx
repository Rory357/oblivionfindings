import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import {
    severityBadge,
    targetBadge,
} from '@/components/control-room/alert-record/alert-labels';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Inbox } from 'lucide-react';
import { useId, type ReactNode } from 'react';

export type WorklistPage = {
    data: AlertWorklistRow[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    meta: { current_page: number; last_page: number; total: number };
};

const SEVERITY_DOT: Record<string, string> = {
    critical: 'bg-status-critical',
    high: 'bg-status-warning',
    medium: 'bg-status-info',
    low: 'bg-muted-foreground',
};

/**
 * The Live response worklist: one ordered list, a row per alert. Selecting a
 * row shows it in the preview beside the list; every row keeps the kebab AND
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
    toolbar,
    selection,
}: {
    title: string;
    description: string;
    worklist: WorklistPage;
    selectedId: number | null;
    onSelect: (id: number) => void;
    getActions: (row: AlertWorklistRow) => readonly ControlRoomRowAction[];
    emptyTitle: string;
    emptyDescription: string;
    /** Optional controls on the list's own header row (e.g. sort). */
    toolbar?: ReactNode;
    /** Tick rows for a bulk action (Escalated, the alert lists). */
    selection?: {
        selected: ReadonlySet<number>;
        onChange: (next: Set<number>) => void;
    };
}) {
    const headingId = useId();
    const rows = worklist.data;
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
                        icon={Inbox}
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

            {worklist.meta.last_page > 1 ? (
                <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3">
                    <p className="text-caption">
                        Showing {rows.length} of {worklist.meta.total}
                    </p>
                    <LaravelPagination
                        links={worklist.links}
                        lastPage={worklist.meta.last_page}
                    />
                </div>
            ) : null}
        </Card>
    );
}

function WorklistRow({
    row,
    selected,
    onSelect,
    rowProps,
    overflowButton,
    ticked,
    onTick,
}: {
    /** undefined = this list has no tick boxes. */
    ticked?: boolean;
    onTick: (on: boolean) => void;
    row: AlertWorklistRow;
    selected: boolean;
    onSelect: () => void;
    rowProps: { onContextMenu: (event: React.MouseEvent<HTMLElement>) => void; tabIndex: number };
    overflowButton: ReactNode;
}) {
    const severity = severityBadge(row.severity);
    const target =
        row.sla.status === 'breached' || row.sla.status === 'at_risk'
            ? targetBadge(row.sla.status)
            : null;
    const facts = [
        row.reference_number ?? 'Reference pending',
        row.source.label,
        row.site?.name ?? 'Site not recorded',
        row.person?.name,
    ].filter(Boolean);

    return (
        <li
            onContextMenu={rowProps.onContextMenu}
            className={cn(
                'relative flex items-start gap-3 px-5 py-3.5 transition-colors',
                selected
                    ? 'bg-primary-fill/10 shadow-[inset_3px_0_0_var(--primary)]'
                    : 'hover:bg-muted/40',
            )}
        >
            {ticked !== undefined ? (
                <Checkbox
                    className="mt-1"
                    checked={ticked}
                    onCheckedChange={(on) => onTick(on === true)}
                    aria-label={`Select ${row.reference_number ?? `alert ${row.id}`}`}
                />
            ) : null}
            <span
                aria-hidden="true"
                className={cn(
                    'mt-1.5 size-2.5 shrink-0 rounded-full',
                    SEVERITY_DOT[row.severity] ?? SEVERITY_DOT.low,
                )}
            />
            <button
                type="button"
                onClick={onSelect}
                aria-current={selected ? 'true' : undefined}
                className="min-w-0 flex-1 rounded-md text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                <span className="flex items-start justify-between gap-3">
                    <span className="min-w-0 font-semibold text-foreground">
                        {row.summary}
                    </span>
                    <span
                        className="text-caption shrink-0 tabular-nums"
                        title={formatDateTime(row.triggered_at)}
                    >
                        {formatRelative(row.triggered_at)}
                    </span>
                </span>
                <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                    <StatusBadge variant={severity.tone} size="sm">
                        {severity.label}
                    </StatusBadge>
                    {target ? (
                        <StatusBadge variant={target.tone} size="sm">
                            {target.label}
                        </StatusBadge>
                    ) : null}
                    <span className="text-caption min-w-0">
                        {facts.join(' · ')}
                    </span>
                </span>
                <span className="text-caption mt-1 flex flex-wrap gap-x-3 gap-y-0.5">
                    <span
                        className={
                            row.assignee ? undefined : 'font-medium text-status-warning'
                        }
                    >
                        {row.assignee?.name ?? 'Unassigned — claim or assign'}
                    </span>
                    {row.next_deadline_at ? (
                        <span>Next deadline {formatRelative(row.next_deadline_at)}</span>
                    ) : null}
                    {row.playbook ? (
                        <span>
                            {row.playbook.completed_steps}/{row.playbook.total_steps}{' '}
                            procedure steps
                        </span>
                    ) : null}
                </span>
            </button>
            <div className="shrink-0">{overflowButton}</div>
        </li>
    );
}
