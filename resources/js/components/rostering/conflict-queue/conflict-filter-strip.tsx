import { LayoutGrid } from 'lucide-react';

import {
    TabStrip,
    type RosterTabItem,
    type RosterTabTone,
} from '@/components/rostering';

import {
    TYPE_META,
    TYPE_ORDER,
    type ConflictType,
    type Severity,
} from './types';
import type { QueueFilter } from './use-conflict-queue';

const SEVERITY_TONE: Record<Severity, RosterTabTone> = {
    critical: 'critical',
    warning: 'warning',
    info: 'info',
};

export interface ConflictFilterStripProps {
    filter: QueueFilter;
    onFilter: (next: QueueFilter) => void;
    counts: Record<ConflictType, number>;
    total: number;
    resolvedToday: number;
    seedTotal: number;
}

/**
 * Severity filter strip — the app's real `TabStrip` (one tab per view, tone-matched
 * to each type's severity, live count badges) with a resolved-progress track on the
 * right. Replaces the old wall of seven count-cards.
 */
export function ConflictFilterStrip({
    filter,
    onFilter,
    counts,
    total,
    resolvedToday,
    seedTotal,
}: ConflictFilterStripProps) {
    const items: RosterTabItem[] = [
        {
            id: 'all',
            label: 'All conflicts',
            icon: LayoutGrid,
            tone: 'violet',
            badge: total,
        },
        ...TYPE_ORDER.map((type) => ({
            id: type,
            label: TYPE_META[type].short,
            icon: TYPE_META[type].icon,
            tone: SEVERITY_TONE[TYPE_META[type].severity],
            badge: counts[type],
        })),
    ];

    const pct = seedTotal ? Math.round((resolvedToday / seedTotal) * 100) : 0;

    return (
        <div className="flex flex-wrap items-center gap-3">
            <TabStrip
                value={filter}
                onChange={(next) => onFilter(next as QueueFilter)}
                items={items}
                className="min-w-0 flex-1"
            />
            <div className="flex items-center gap-2.5 px-1">
                <span className="text-[11px] font-medium text-muted-foreground tabular-nums">
                    {resolvedToday} of {seedTotal} resolved
                </span>
                <span className="h-1.5 w-[168px] overflow-hidden rounded-full bg-muted">
                    <span
                        className="block h-full rounded-full bg-status-success transition-[width] duration-300"
                        style={{ width: `${pct}%` }}
                    />
                </span>
            </div>
        </div>
    );
}

export default ConflictFilterStrip;
