import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
export function stateLabel(state: string | null | undefined): string {
    if (!state) return 'Unknown';
    const labels: Record<string, string> = {
        update_available: 'Update available',
        not_observed: 'Not observed',
        not_collected: 'Not collected',
        no_evidence: 'No evidence',
        desired_only: 'Desired only',
        in_progress: 'In progress',
    };
    return (
        labels[state] ??
        state
            .replaceAll('_', ' ')
            .replace(/^./, (letter) => letter.toUpperCase())
    );
}

export function snapshotDiffLabel(snapshot: {
    previousSnapshotId: number | null;
    diff: {
        added: string[];
        changed: string[];
        removed: string[];
        truncated: boolean;
    };
}): string {
    if (snapshot.previousSnapshotId === null) {
        return 'Initial retained baseline';
    }

    const breakdown = [
        snapshot.diff.added.length > 0
            ? `${snapshot.diff.added.length} added`
            : null,
        snapshot.diff.changed.length > 0
            ? `${snapshot.diff.changed.length} changed`
            : null,
        snapshot.diff.removed.length > 0
            ? `${snapshot.diff.removed.length} removed`
            : null,
    ].filter((value): value is string => value !== null);
    const summary =
        breakdown.length === 0 ? 'no structural changes' : breakdown.join(', ');

    return `Compared with snapshot #${snapshot.previousSnapshotId}: ${summary}${snapshot.diff.truncated ? ' (bounded result)' : ''}`;
}

export function stateVariant(state: string): StatusVariant {
    if (['healthy', 'active', 'normal', 'aligned', 'known'].includes(state)) {
        return 'success';
    }
    if (
        [
            'warning',
            'degraded',
            'partial',
            'update_available',
            'drifted',
        ].includes(state)
    ) {
        return 'warning';
    }
    if (['failed', 'critical', 'offline'].includes(state)) {
        return 'critical';
    }
    if (['open', 'in_progress'].includes(state)) return 'info';
    return 'neutral';
}

export function StateBadge({
    state,
    label,
}: {
    state: string | null | undefined;
    label?: string;
}) {
    const resolved = state ?? 'unknown';
    return (
        <StatusBadge variant={stateVariant(resolved)}>
            {label ?? stateLabel(resolved)}
        </StatusBadge>
    );
}

export function formatNumber(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(1);
}

export function formatRate(value: number | null): string {
    if (value === null) return 'Not collected';
    if (value >= 1_000_000_000)
        return `${formatNumber(value / 1_000_000_000)} Gbps`;
    if (value >= 1_000_000) return `${formatNumber(value / 1_000_000)} Mbps`;
    if (value >= 1_000) return `${formatNumber(value / 1_000)} Kbps`;
    return `${value} bps`;
}

export function formatUtilisation(
    value: number | null,
    direction: string,
): string {
    return value === null
        ? 'Not collected'
        : `${formatNumber(value)}% ${direction}`;
}

export function capacityLabel(state: string): string {
    if (state === 'warning') return 'Capacity warning';
    if (state === 'critical') return 'Capacity critical';
    if (state === 'normal') return 'Capacity normal';
    return stateLabel(state);
}

export function shortHash(value: string | null): string {
    if (!value) return 'Not observed';
    return value.length > 18
        ? `${value.slice(0, 8)}…${value.slice(-6)}`
        : value;
}
