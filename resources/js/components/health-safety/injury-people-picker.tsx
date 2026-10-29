import { HazardStaffPicker } from '@/components/health-safety/hazard-staff-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import type { InjuryPickerPage } from './injury-workspace-types';

type Person = { id: number; name: string };
/** Search and continuation use the same current source/Site boundary as the command. */
export function InjuryPeoplePicker({ id, label, endpoint, group, value, onChange, onSelection, siteId, canonicalSiteId, injuryId, version, disabled = false }: {
    id: string; label: string; endpoint: string; group: 'workers' | 'coordinators' | 'assessors';
    value: string; onChange: (value: string) => void; onSelection?: (person: Person) => void; siteId?: string; canonicalSiteId?: number; injuryId?: number; version?: number; disabled?: boolean;
}) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [items, setItems] = useState<Person[]>([]);
    const [pagination, setPagination] = useState<InjuryPickerPage | null>(null);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const [retry, setRetry] = useState(0);
    const active = useRef<AbortController | null>(null);
    useEffect(() => { setPage(1); }, [siteId, injuryId]);
    useEffect(() => {
        const controller = new AbortController();
        active.current?.abort(); active.current = controller;
        setItems([]); setPagination(null); setFailure(null);
        if (siteId === '') { setLoading(false); return () => controller.abort(); }
        setLoading(true);
        const timeout = setTimeout(() => {
            axios.get(endpoint, { headers: { Accept: 'application/json' }, signal: controller.signal,
                params: { search, page, ...(siteId ? { site_id: siteId } : {}) } }).then(({ data }) => {
                if (controller.signal.aborted) return;
                const meta = data[group === 'workers' ? 'worker_picker' : group === 'coordinators' ? 'coordinator_picker' : 'assessor_picker'];
                if ((injuryId != null && (data.injury_id !== injuryId || data.expected_version !== version)) ||
                    (canonicalSiteId != null && (data.canonical_site_id !== canonicalSiteId || data.site_id !== Number(siteId))) ||
                    !Array.isArray(data[group]) || !data[group].every((item: Person | null) => !!item && Number.isSafeInteger(item.id) && item.id > 0 && typeof item.name === 'string') ||
                    !meta || meta.page !== page || meta.search !== search.trim() || meta.per_page !== 100 || typeof meta.has_more !== 'boolean' ||
                    meta.next_page !== (meta.has_more ? page + 1 : null)) throw new Error('Current choices changed');
                setItems(data[group]); setPagination(meta);
            }).catch(() => {
                if (!controller.signal.aborted) setFailure('We couldn’t load the list. Search again, or reopen the record if it keeps happening.');
            }).finally(() => { if (!controller.signal.aborted) setLoading(false); });
        }, 250);
        return () => { clearTimeout(timeout); controller.abort(); };
    }, [endpoint, group, search, page, siteId, canonicalSiteId, injuryId, version, retry]);
    return <div className="space-y-3">
        <Input aria-label={`Search ${label.toLowerCase()}`} className="frontline-tap" maxLength={200} placeholder={`Search ${label.toLowerCase()}…`} value={search} disabled={disabled} onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
        <HazardStaffPicker id={id} placeholder={value && !items.some((item) => String(item.id) === value) ? 'Your choice is kept — check it’s still right' : `Choose ${label.toLowerCase()}`} value={items.some((item) => String(item.id) === value) ? value : ''} onChange={(next) => { const person = items.find((item) => String(item.id) === next); if (person) { onChange(next); onSelection?.(person); } }} disabled={disabled || loading || !!failure || siteId === ''} options={items.map((item) => ({ value: String(item.id), label: item.name }))} searchLabel="Search this page" listLabel={label} />
        <div className="flex flex-wrap items-center gap-3"><Button type="button" variant="outline" className="frontline-tap" disabled={disabled || loading || page === 1} onClick={() => setPage(page - 1)}>Previous choices</Button><span className="text-caption">Page {page}</span><Button type="button" variant="outline" className="frontline-tap" disabled={disabled || loading || !pagination?.has_more} onClick={() => setPage(page + 1)}>More choices</Button></div>
        {loading && <p role="status" className="text-subtle">Loading…</p>}
        {failure && <div className="space-y-2"><p role="alert" className="text-subtle text-status-critical">{failure}</p><Button type="button" variant="outline" className="frontline-tap" disabled={disabled || loading} onClick={() => setRetry(retry + 1)}>Search again</Button></div>}
    </div>;
}
