import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import { PageHeaderFilterSelect, PageHeaderSearch } from '@/components/page';
import { router } from '@inertiajs/react';
import { Building2, Gauge, Inbox, Radio, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';

export type LiveResponseFilterValues = {
    q?: string | null;
    severity?: string | null;
    source?: string | null;
    assigned_to?: string | number | null;
    site_id?: string | number | null;
    queue_id?: string | number | null;
};

type Option = { id: number; name: string };

/** Visit the same view with these filters (page resets; the preview selection is dropped). */
export function applyLiveResponseFilters(
    path: string,
    next: Record<string, unknown>,
) {
    const query = Object.fromEntries(
        Object.entries(next).filter(
            ([, value]) => value !== '' && value !== null && value !== undefined,
        ),
    ) as Record<string, string | number>;
    router.get(path, query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

/** The header search: types freely, applies on Enter, clears on Esc. */
export function LiveResponseSearch({
    value,
    onApply,
    placeholder = 'Search alerts',
}: {
    value: string;
    onApply: (q: string) => void;
    placeholder?: string;
}) {
    const [draft, setDraft] = useState(value);
    useEffect(() => setDraft(value), [value]);
    return (
        <PageHeaderSearch
            value={draft}
            onChange={setDraft}
            placeholder={placeholder}
            ariaLabel="Search alerts — press Enter to search"
            onKeyDown={(event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    onApply(draft.trim());
                }
                if (event.key === 'Escape' && draft) {
                    event.preventDefault();
                    setDraft('');
                    onApply('');
                }
            }}
        />
    );
}

/**
 * The header filter chips shared by the Live response views: severity,
 * source, owner and site (at most four, DESIGN.md header rules). A queue
 * chip appears only while a queue filter is set, so it can be cleared.
 * Sources come from the alerts this person can already see — never a fixed
 * list that hides real sources or advertises ones they can't open.
 */
export function LiveResponseFilters({
    values,
    rows,
    staff,
    sites,
    queues,
    onChange,
    show = { severity: true, source: true, owner: true, site: true },
}: {
    values: LiveResponseFilterValues;
    rows: AlertWorklistRow[];
    staff?: Option[];
    sites?: Option[];
    queues?: Option[];
    onChange: (next: LiveResponseFilterValues) => void;
    show?: { severity?: boolean; source?: boolean; owner?: boolean; site?: boolean };
}) {
    const sourceOptions = new Map<string, string>();
    for (const row of rows) sourceOptions.set(row.source.key, row.source.label);
    if (values.source && !sourceOptions.has(values.source))
        sourceOptions.set(values.source, values.source);

    const set = (key: keyof LiveResponseFilterValues) => (value: string) =>
        onChange({ ...values, [key]: value === 'all' ? '' : value });

    return (
        <>
            {show.severity ? (
                <PageHeaderFilterSelect
                    icon={Gauge}
                    label="All severities"
                    value={values.severity || 'all'}
                    onChange={set('severity')}
                    options={[
                        { value: 'all', label: 'All severities' },
                        { value: 'critical', label: 'Critical' },
                        { value: 'high', label: 'High' },
                        { value: 'medium', label: 'Medium' },
                        { value: 'low', label: 'Low' },
                    ]}
                />
            ) : null}
            {show.source ? (
                <PageHeaderFilterSelect
                    icon={Radio}
                    label="All sources"
                    value={values.source || 'all'}
                    onChange={set('source')}
                    options={[
                        { value: 'all', label: 'All sources' },
                        ...[...sourceOptions].map(([value, label]) => ({
                            value,
                            label,
                        })),
                    ]}
                />
            ) : null}
            {show.owner ? (
                <PageHeaderFilterSelect
                    icon={UserRound}
                    label="All owners"
                    value={values.assigned_to ? String(values.assigned_to) : 'all'}
                    onChange={set('assigned_to')}
                    options={[
                        { value: 'all', label: 'All owners' },
                        { value: 'unassigned', label: 'Unassigned' },
                        { value: 'me', label: 'Assigned to me' },
                        ...(staff ?? []).map((person) => ({
                            value: String(person.id),
                            label: person.name,
                        })),
                    ]}
                />
            ) : null}
            {show.site && sites && sites.length > 1 ? (
                <PageHeaderFilterSelect
                    icon={Building2}
                    label="All sites"
                    value={values.site_id ? String(values.site_id) : 'all'}
                    onChange={set('site_id')}
                    options={[
                        { value: 'all', label: 'All sites' },
                        ...sites.map((site) => ({
                            value: String(site.id),
                            label: site.name,
                        })),
                    ]}
                />
            ) : null}
            {values.queue_id && queues ? (
                <PageHeaderFilterSelect
                    icon={Inbox}
                    label="All queues"
                    value={String(values.queue_id)}
                    onChange={set('queue_id')}
                    options={[
                        { value: 'all', label: 'All queues' },
                        ...queues.map((queue) => ({
                            value: String(queue.id),
                            label: queue.name,
                        })),
                    ]}
                />
            ) : null}
        </>
    );
}
