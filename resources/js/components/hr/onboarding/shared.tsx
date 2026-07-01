import { StatusBadge } from '@/components/ui/status-badge';

export type OnboardingStatus =
    | 'pending'
    | 'in_progress'
    | 'completed'
    | 'cancelled'
    | 'archived';

type Variant = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

/** Map a checklist status (+ overdue flag) to a StatusBadge variant + label. */
export function statusMeta(
    status: string,
    isOverdue?: boolean,
): { variant: Variant; label: string } {
    if (isOverdue && status !== 'completed') {
        return { variant: 'critical', label: 'Overdue' };
    }
    switch (status) {
        case 'completed':
            return { variant: 'success', label: 'Completed' };
        case 'in_progress':
            return { variant: 'info', label: 'In progress' };
        case 'cancelled':
            return { variant: 'neutral', label: 'Cancelled' };
        case 'archived':
            return { variant: 'neutral', label: 'Archived' };
        default:
            return { variant: 'neutral', label: 'Pending' };
    }
}

export function ChecklistStatusBadge({
    status,
    isOverdue,
}: {
    status: string;
    isOverdue?: boolean;
}) {
    const meta = statusMeta(status, isOverdue);
    return (
        <StatusBadge variant={meta.variant} size="sm">
            {meta.label}
        </StatusBadge>
    );
}

export function initials(name?: string | null): string {
    if (!name) return '—';
    return name
        .split(' ')
        .map((w) => w[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

/** Deterministic soft avatar palette keyed on a name. */
export function avatarStyle(seed: string): { background: string; color: string } {
    const hues = [277, 200, 150, 25, 320, 90];
    let h = 0;
    for (let i = 0; i < seed.length; i++) h = (h + seed.charCodeAt(i)) % hues.length;
    const hue = hues[h];
    return {
        background: `oklch(0.92 0.05 ${hue})`,
        color: `oklch(0.42 0.14 ${hue})`,
    };
}

/** Colour for a task category dot/group header. */
export function categoryColor(category: string): string {
    const c = category.toLowerCase();
    if (c.includes('compli')) return 'var(--status-warning)';
    if (c.includes('it') || c.includes('access')) return 'var(--primary)';
    if (c.includes('pay')) return 'var(--status-success)';
    if (c.includes('induct')) return 'var(--category-hr, var(--status-warning))';
    return 'var(--muted-foreground)';
}

export function prettyLabel(value?: string | null): string {
    if (!value) return '—';
    return value.replace(/_/g, ' ').replace(/\b\w/g, (ch) => ch.toUpperCase());
}

export function formatDate(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function formatShort(value?: string | null): string {
    if (!value) return '—';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}
