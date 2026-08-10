/**
 * Fleet & Assets Module - Shared Utilities
 * Formatting, badge helpers, and color constants for consistent display.
 */

import {
    formatDate as formatWorkerDate,
    formatDateTime as formatWorkerDateTime,
    formatRelative as formatWorkerRelative,
    formatTime as formatWorkerTime,
} from './datetime';

/* ------------------------------------------------------------------ */
/*  Number & Currency Formatting                                       */
/* ------------------------------------------------------------------ */

export function formatCurrency(
    amount: number | null | undefined,
    currency = 'NZD',
): string {
    const val = Number(amount ?? 0);
    return val.toLocaleString('en-NZ', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

export function formatDistance(km: number | null | undefined): string {
    const val = Number(km ?? 0);
    return `${val.toLocaleString('en-NZ', { maximumFractionDigits: 1 })} km`;
}

export function formatNumber(n: number | null | undefined): string {
    return Number(n ?? 0).toLocaleString('en-NZ');
}

export function formatSpeed(kph: number | null | undefined): string {
    return `${Number(kph ?? 0).toLocaleString('en-NZ', { maximumFractionDigits: 1 })} km/h`;
}

export function formatPercent(value: number | null | undefined): string {
    return `${Math.round(Number(value ?? 0))}%`;
}

/* ------------------------------------------------------------------ */
/*  Duration Formatting                                                */
/* ------------------------------------------------------------------ */

export function formatDuration(seconds: number | null | undefined): string {
    const s = Math.abs(Number(seconds ?? 0));
    if (s < 60) return `${Math.round(s)}s`;
    const mins = Math.round(s / 60);
    if (mins < 60) return `${mins}m`;
    const hrs = Math.floor(mins / 60);
    const remainMins = mins % 60;
    if (hrs < 24) return remainMins > 0 ? `${hrs}h ${remainMins}m` : `${hrs}h`;
    const days = Math.floor(hrs / 24);
    const remainHrs = hrs % 24;
    return remainHrs > 0 ? `${days}d ${remainHrs}h` : `${days}d`;
}

export function formatDurationMinutes(
    minutes: number | null | undefined,
): string {
    return formatDuration(Number(minutes ?? 0) * 60);
}

/* ------------------------------------------------------------------ */
/*  Date & Time Formatting                                             */
/* ------------------------------------------------------------------ */

export function formatDate(isoDate: string | null | undefined): string {
    return formatWorkerDate(isoDate, '---');
}

export function formatDateTime(isoDate: string | null | undefined): string {
    return formatWorkerDateTime(isoDate, '---');
}

export function formatTime(isoDate: string | null | undefined): string {
    return formatWorkerTime(isoDate, '---');
}

export function formatRelativeTime(isoDate: string | null | undefined): string {
    return formatWorkerRelative(isoDate, Date.now(), '---');
}

/* ------------------------------------------------------------------ */
/*  Expiry / Compliance Helpers                                        */
/* ------------------------------------------------------------------ */

export function daysUntilExpiry(
    isoDate: string | null | undefined,
): number | null {
    if (!isoDate) return null;
    try {
        return Math.ceil(
            (new Date(isoDate).getTime() - Date.now()) / (1000 * 60 * 60 * 24),
        );
    } catch {
        return null;
    }
}

export function expiryStatus(
    isoDate: string | null | undefined,
): 'ok' | 'warning' | 'critical' | 'expired' | 'unknown' {
    const days = daysUntilExpiry(isoDate);
    if (days === null) return 'unknown';
    if (days < 0) return 'expired';
    if (days <= 30) return 'critical';
    if (days <= 60) return 'warning';
    return 'ok';
}

export function expiryBadgeClass(status: string): string {
    switch (status) {
        case 'expired':
            return 'bg-status-critical text-white';
        case 'critical':
            return 'bg-status-warning text-white';
        case 'warning':
            return 'bg-status-warning text-white';
        case 'ok':
            return 'bg-primary text-white';
        default:
            return 'bg-muted text-white';
    }
}

/* ------------------------------------------------------------------ */
/*  Badge Color Maps (consistent across module)                        */
/* ------------------------------------------------------------------ */

export function severityColor(severity: string): string {
    switch (severity) {
        case 'critical':
            return 'bg-status-critical';
        case 'major':
            return 'bg-status-critical';
        case 'high':
            return 'bg-status-critical';
        case 'moderate':
            return 'bg-status-warning';
        case 'medium':
            return 'bg-status-warning';
        case 'minor':
            return 'bg-status-warning';
        case 'low':
            return 'bg-muted';
        default:
            return 'bg-muted';
    }
}

export function severityVariant(
    severity: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (severity) {
        case 'critical':
        case 'major':
        case 'high':
            return 'destructive';
        case 'moderate':
        case 'medium':
            return 'default';
        default:
            return 'secondary';
    }
}

export function statusColor(status: string): string {
    switch (status) {
        case 'online':
        case 'active':
        case 'completed':
        case 'accepted':
        case 'passed':
        case 'resolved':
            return 'bg-primary text-white';
        case 'pending':
        case 'pending_acceptance':
        case 'reported':
            return 'bg-status-warning text-white';
        case 'approved':
        case 'investigating':
        case 'in_progress':
            return 'bg-status-info text-white';
        case 'checked_out':
        case 'moving':
            return 'bg-primary text-white';
        case 'offline':
        case 'rejected':
        case 'cancelled':
        case 'failed':
        case 'disputed':
            return 'bg-status-critical text-white';
        case 'returned':
        case 'closed':
        case 'idle':
            return 'bg-muted-foreground/80 text-white';
        default:
            return 'bg-muted text-white';
    }
}

export function priorityColor(priority: string): string {
    switch (priority) {
        case 'critical':
            return 'bg-status-critical text-white';
        case 'high':
            return 'bg-status-critical text-white';
        case 'medium':
            return 'bg-status-warning text-white';
        case 'low':
            return 'bg-status-info text-white';
        default:
            return 'bg-muted text-white';
    }
}
