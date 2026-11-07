/**
 * Shared types and helpers for the Sites inspections & maintenance pop-ups
 * (schedule an inspection, record its result, log maintenance, the Overdue
 * list). Used by the Sites list, a Site's inspections page and the cross-site
 * Inspections & Maintenance page. Reads come from SiteWorkController; saving
 * goes to the existing endpoints, which keep their own permissions.
 */
import type { VehicleSelectOption } from '@/components/fleet-assets/vehicle-workspace/search-select';
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { useEffect, useState } from 'react';

export type SiteWorkSite = {
    id: number;
    name: string;
    type?: string | null;
    region?: string | null;
    is_active?: boolean;
};

/** Which actions the viewer may take (the server still enforces each one). */
export type SiteWorkCan = {
    schedule: boolean;
    record: boolean;
    maintenance: boolean;
    runChecklists?: boolean;
};

export type SiteWorkScheduleRef = {
    id: number;
    title: string;
    inspection_type?: string | null;
    frequency?: string | null;
    due_date?: string | null;
    /** Days past due; null when not overdue. */
    days_overdue?: number | null;
};

export type SiteWorkAssetRef = {
    id: number;
    name: string;
    asset_tag?: string | null;
    due_date?: string | null;
    /** Days past the service date (0 = due today); null when not due. */
    days_overdue?: number | null;
};

export type SiteWorkRequest =
    | { kind: 'schedule'; site?: SiteWorkSite }
    | {
          kind: 'record';
          site?: SiteWorkSite;
          schedule?: SiteWorkScheduleRef;
      }
    | { kind: 'maintenance'; site?: SiteWorkSite; asset?: SiteWorkAssetRef };

export const SITE_TYPE_LABELS: Record<string, string> = {
    head_office: 'Head office',
    house: 'House',
    residential: 'Residential',
    facility: 'Facility',
};

export const FREQUENCY_LABELS: Record<string, string> = {
    weekly: 'Weekly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    bi_annual: 'Every 6 months',
    annual: 'Yearly',
    custom: 'Custom',
};

/**
 * Whole days a YYYY-MM-DD due date is behind today on the NZ calendar, or
 * null when it isn't past due. Pure calendar arithmetic (no instants).
 */
export function daysPastDue(due: string | null | undefined): number | null {
    if (!due || !/^\d{4}-\d{2}-\d{2}$/.test(due)) return null;
    const today = toDateInput(new Date());
    if (!today) return null;
    const toUtc = (value: string) => {
        const [y, m, d] = value.split('-').map(Number);
        return Date.UTC(y, m - 1, d);
    };
    const days = Math.round((toUtc(today) - toUtc(due)) / 86_400_000);
    return days > 0 ? days : null;
}

/** "12 days overdue", "1 day overdue", "Due today". */
export function overdueLabel(days: number | null | undefined): string {
    if (days === null || days === undefined) return 'Overdue';
    if (days <= 0) return 'Due today';
    return `${days} ${days === 1 ? 'day' : 'days'} overdue`;
}

/** Two weeks late reads critical; anything less is a warning. */
export function overdueVariant(days: number | null | undefined): StatusVariant {
    return (days ?? 0) >= 14 ? 'critical' : 'warning';
}

/** "Due 21 Oct 2026 · 12 days overdue" for picker rows and context lines. */
export function dueLine(
    dueDate: string | null | undefined,
    daysOverdue: number | null | undefined,
): string {
    const parts: string[] = [];
    if (dueDate) parts.push(`Due ${formatDateOnly(dueDate)}`);
    if (daysOverdue !== null && daysOverdue !== undefined) {
        parts.push(overdueLabel(daysOverdue).toLowerCase());
    }
    return parts.join(' · ');
}

export function siteSelectOptions(
    sites: SiteWorkSite[],
): VehicleSelectOption[] {
    return sites.map((site) => ({
        value: String(site.id),
        label: site.name,
        description:
            [
                site.type ? (SITE_TYPE_LABELS[site.type] ?? null) : null,
                site.region ?? null,
                site.is_active === false ? 'Inactive' : null,
            ]
                .filter(Boolean)
                .join(' · ') || undefined,
    }));
}

export type LoadState<T> =
    | { status: 'idle' }
    /** `data` is the previous answer for the same scope, kept while a new
     *  search loads so the list doesn't flash empty. */
    | { status: 'loading'; data?: T }
    | { status: 'failed' }
    | { status: 'ready'; data: T };

/**
 * GET a SiteWorkController read with loading / failed / ready states. An
 * aborted or superseded request never overwrites a newer one. Pass a null
 * url to stay idle. `scope` names what the answer belongs to (e.g. the Site):
 * a previous answer is only kept while loading within the same scope, so one
 * Site's choices never show under another.
 */
export function useSiteWorkRead<T>(
    url: string | null,
    reloadKey: number = 0,
    scope: string = '',
): LoadState<T> {
    const [state, setState] = useState<LoadState<T>>({ status: 'idle' });
    const [stateScope, setStateScope] = useState(scope);

    useEffect(() => {
        if (!url) {
            setState({ status: 'idle' });
            return;
        }
        const abort = new AbortController();
        const sameScope = stateScope === scope;
        setStateScope(scope);
        setState((previous) => ({
            status: 'loading',
            data:
                sameScope &&
                (previous.status === 'ready' || previous.status === 'loading')
                    ? previous.data
                    : undefined,
        }));
        fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: abort.signal,
        })
            .then(async (response) => {
                if (!response.ok) throw new Error(String(response.status));
                return (await response.json()) as T;
            })
            .then((data) => {
                if (!abort.signal.aborted) setState({ status: 'ready', data });
            })
            .catch(() => {
                if (!abort.signal.aborted) setState({ status: 'failed' });
            });
        return () => abort.abort();
        // stateScope is read only to compare with the incoming scope.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url, reloadKey, scope]);

    // A scope change shows nothing stale, even before the effect runs.
    if (stateScope !== scope && state.status !== 'idle') {
        return { status: 'loading' };
    }

    return state;
}
