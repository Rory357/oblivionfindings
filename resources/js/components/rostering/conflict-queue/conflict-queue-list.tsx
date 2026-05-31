import { Check, ChevronRight } from 'lucide-react';

import { cn } from '@/lib/utils';

import { TYPE_META, TYPE_ORDER, type QueueItem, type Severity } from './types';
import type { QueueFilter } from './use-conflict-queue';

const SEV_TILE: Record<Severity, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    info: 'bg-accent text-primary',
};

const SEV_BAR: Record<Severity, string> = {
    critical: 'bg-status-critical',
    warning: 'bg-status-warning',
    info: 'bg-primary',
};

function QueueRow({
    item,
    selected,
    hideType,
    onClick,
}: {
    item: QueueItem;
    selected: boolean;
    hideType: boolean;
    onClick: () => void;
}) {
    const meta = TYPE_META[item.type];
    const Icon = meta.icon;
    return (
        // eslint-disable-next-line no-restricted-syntax -- full-width queue row with severity bar + icon tile; not a shadcn Button.
        <button
            type="button"
            onClick={onClick}
            aria-pressed={selected}
            className={cn(
                'relative flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition-colors',
                selected
                    ? 'border-primary/30 bg-[color-mix(in_oklch,var(--primary)_8%,transparent)]'
                    : 'border-transparent hover:bg-muted',
            )}
        >
            <span
                className={cn(
                    'absolute inset-y-[9px] left-0 w-[3px] rounded-full',
                    SEV_BAR[meta.severity],
                )}
            />
            <span
                className={cn(
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                    SEV_TILE[meta.severity],
                )}
            >
                <Icon className="h-4 w-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-semibold">
                    {item.who}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {item.summary}
                </span>
            </span>
            <span className="flex shrink-0 items-center gap-1.5">
                {hideType ? null : (
                    <span className="text-[11px] text-muted-foreground">
                        {meta.label}
                    </span>
                )}
                <ChevronRight className="h-4 w-4 text-muted-foreground" />
            </span>
        </button>
    );
}

function ListEmpty({ allClear }: { allClear: boolean }) {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
            <span className="flex h-14 w-14 items-center justify-center rounded-full bg-status-success-bg text-status-success">
                <Check className="h-7 w-7" />
            </span>
            <p className="mt-3 text-sm font-semibold">
                {allClear ? 'Queue clear — nice work' : 'Nothing here'}
            </p>
            <p className="mt-1 max-w-[260px] text-xs text-muted-foreground">
                {allClear
                    ? 'Every conflict for this week is resolved. The roster is ready to publish.'
                    : 'No items match this filter right now.'}
            </p>
        </div>
    );
}

export interface ConflictQueueListProps {
    filter: QueueFilter;
    visible: QueueItem[];
    selectedId: string | null;
    onSelect: (id: string) => void;
    allResolved: boolean;
}

export function ConflictQueueList({
    filter,
    visible,
    selectedId,
    onSelect,
    allResolved,
}: ConflictQueueListProps) {
    const grouped = filter === 'all';
    const headerLabel = grouped ? 'All conflicts' : TYPE_META[filter].short;

    const groups = grouped
        ? TYPE_ORDER.map((type) => ({
              type,
              items: visible.filter((item) => item.type === type),
          })).filter((group) => group.items.length > 0)
        : [];

    return (
        <div className="rounded-2xl border bg-card">
            <div className="flex items-center justify-between gap-2 border-b px-4 py-3">
                <span className="text-sm font-semibold">{headerLabel}</span>
                <span className="text-xs text-muted-foreground">
                    {visible.length} item{visible.length === 1 ? '' : 's'} ·{' '}
                    {grouped ? 'grouped by type' : 'severity order'}
                </span>
            </div>
            <div className="max-h-[700px] space-y-1.5 overflow-auto p-2">
                {visible.length === 0 ? (
                    <ListEmpty allClear={allResolved} />
                ) : grouped ? (
                    groups.map((group, groupIndex) => {
                        const meta = TYPE_META[group.type];
                        const GroupIcon = meta.icon;
                        return (
                            <div
                                key={group.type}
                                className={cn(
                                    groupIndex > 0 &&
                                        'mt-1.5 border-t border-border/70 pt-1.5',
                                )}
                            >
                                <div className="flex items-center gap-2 px-2 py-1.5">
                                    <span
                                        className={cn(
                                            'flex h-5 w-5 items-center justify-center rounded-md',
                                            SEV_TILE[meta.severity],
                                        )}
                                    >
                                        <GroupIcon className="h-3 w-3" />
                                    </span>
                                    <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                        {meta.short}
                                    </span>
                                    <span className="ml-auto text-[11px] font-semibold text-muted-foreground tabular-nums">
                                        {group.items.length}
                                    </span>
                                </div>
                                <div className="space-y-1.5">
                                    {group.items.map((item) => (
                                        <QueueRow
                                            key={item.id}
                                            item={item}
                                            hideType
                                            selected={item.id === selectedId}
                                            onClick={() => onSelect(item.id)}
                                        />
                                    ))}
                                </div>
                            </div>
                        );
                    })
                ) : (
                    visible.map((item) => (
                        <QueueRow
                            key={item.id}
                            item={item}
                            hideType={false}
                            selected={item.id === selectedId}
                            onClick={() => onSelect(item.id)}
                        />
                    ))
                )}
            </div>
        </div>
    );
}

export default ConflictQueueList;
