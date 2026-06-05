// Pure helpers shared across the Checklists workspace. Colours resolve to
// semantic CSS variables only (no raw hex) so the module re-brands with the
// accent colour chosen in Settings → Appearance.
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    type LucideIcon,
    PlayCircle,
    SkipForward,
} from 'lucide-react';

import type { ChecklistRun } from './types';

const CATEGORY_TONES = ['ops', 'hr', 'compliance', 'incidents', 'governance', 'sites', 'fleet'];

export function catColorVar(tone?: string | null): string {
    if (!tone) return 'var(--muted-foreground)';
    if (CATEGORY_TONES.includes(tone)) return `var(--category-${tone})`;
    if (tone === 'critical') return 'var(--status-critical)';
    if (tone === 'warning') return 'var(--status-warning)';
    if (tone === 'success') return 'var(--status-success)';
    if (tone === 'info') return 'var(--status-info)';
    return 'var(--muted-foreground)';
}

export function catBgVar(tone?: string | null): string {
    if (!tone) return 'var(--muted)';
    if (CATEGORY_TONES.includes(tone)) return `var(--category-${tone}-bg)`;
    if (tone === 'critical') return 'var(--status-critical-bg)';
    if (tone === 'warning') return 'var(--status-warning-bg)';
    if (tone === 'success') return 'var(--status-success-bg)';
    if (tone === 'info') return 'var(--status-info-bg)';
    return 'var(--muted)';
}

export function initials(name?: string): string {
    return (name || '?')
        .split(' ')
        .map((w) => w[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('');
}

export function fmtDate(s?: string | null): string {
    if (!s) return '—';
    return new Date(`${s}T00:00:00`).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
}

export function relDay(s: string | null | undefined, today: string): string {
    if (!s) return '—';
    const d = Math.round(
        (new Date(`${s}T00:00:00`).getTime() - new Date(`${today}T00:00:00`).getTime()) / 86_400_000,
    );
    if (d === 0) return 'Today';
    if (d === -1) return 'Yesterday';
    if (d === 1) return 'Tomorrow';
    if (d < 0) return `${-d}d overdue`;
    return `in ${d}d`;
}

export type StatusTone = 'success' | 'critical' | 'warning' | 'info' | 'neutral';

export interface RunStatusMeta {
    tone: StatusTone;
    Icon: LucideIcon;
    label: string;
}

export function runStatusMeta(
    run: Pick<ChecklistRun, 'status' | 'scheduled_date'>,
    today: string,
): RunStatusMeta {
    if (run.status === 'completed') {
        return { tone: 'success', Icon: CheckCircle2, label: 'Completed' };
    }
    if (run.status === 'skipped') {
        return { tone: 'neutral', Icon: SkipForward, label: 'Skipped' };
    }
    const overdue = run.scheduled_date != null && run.scheduled_date < today;
    if (overdue) {
        return { tone: 'critical', Icon: AlertTriangle, label: 'Overdue' };
    }
    if (run.status === 'in_progress') {
        return { tone: 'warning', Icon: PlayCircle, label: 'In progress' };
    }
    return { tone: 'info', Icon: CalendarClock, label: 'Scheduled' };
}
