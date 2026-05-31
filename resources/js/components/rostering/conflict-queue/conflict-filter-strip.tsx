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
}

/**
 * Severity filter strip — the app's real `TabStrip`, one tab per view, tone-matched
 * to each type's severity with live count badges. Replaces the old wall of seven
 * count-cards. (Resolved progress lives in the hero, not here.)
 */
export function ConflictFilterStrip({
    filter,
    onFilter,
    counts,
    total,
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

    return (
        <TabStrip
            value={filter}
            onChange={(next) => onFilter(next as QueueFilter)}
            items={items}
            className="w-full"
        />
    );
}

export default ConflictFilterStrip;
