import { HazardStaffPicker } from '@/components/health-safety/hazard-staff-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { object } from './_command-state';
import type { PpePage } from './_workspace-types';
import type { Ref } from './ppe-shared';

export function PpePeoplePicker({ id, siteId, value, disabled, onChange, onValidity }: {
    id: string; siteId: number; value: string; disabled: boolean;
    onChange: (value: string, person: Ref) => void; onValidity: (valid: boolean) => void;
}) {
    const [search, setSearch] = useState(''); const [page, setPage] = useState(1);
    const [items, setItems] = useState<Ref[]>([]); const [meta, setMeta] = useState<PpePage | null>(null);
    const [loading, setLoading] = useState(true); const [failed, setFailed] = useState(false); const [retry, setRetry] = useState(0);
    useEffect(() => { setPage(1); }, [siteId]);
    useEffect(() => {
        const controller = new AbortController(); setLoading(true); setFailed(false); setItems([]); setMeta(null); onValidity(false);
        const timer = setTimeout(() => {
            axios.get('/health-safety/ppe/options', { params: { site_id: siteId, staff_search: search, staff_page: page },
                headers: { Accept: 'application/json' }, signal: controller.signal }).then(({ data }) => {
                if (controller.signal.aborted) return;
                if (!object(data) || !Array.isArray(data.sites) || !data.sites.some((site) => object(site) && site.id === siteId) ||
                    !Array.isArray(data.staff) || !data.staff.every((person) => object(person) && Number.isSafeInteger(person.id) && Number(person.id) > 0 && typeof person.name === 'string') ||
                    !object(data.staff_page) || data.staff_page.current_page !== page || !Number.isSafeInteger(data.staff_page.last_page) || Number(data.staff_page.last_page) < 1 ||
                    !Number.isSafeInteger(data.staff_page.total) || Number(data.staff_page.total) < 0) throw new Error('Current choices unavailable');
                setItems(data.staff as Ref[]); setMeta(data.staff_page as PpePage); onValidity(true);
            }).catch(() => { if (!controller.signal.aborted) { setFailed(true); onValidity(false); } })
                .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [siteId, search, page, retry, onValidity]);
    return <div className="space-y-3">
        <Input className="frontline-tap" aria-label="Search workers at this Site" value={search} maxLength={200} disabled={disabled} placeholder="Search workers…" onChange={(event) => { setSearch(event.target.value); setPage(1); }} />
        <HazardStaffPicker id={id} listLabel="Workers at this Site" searchLabel="Search this page" options={items.map((person) => ({ value: String(person.id), label: person.name }))}
            value={items.some((person) => String(person.id) === value) ? value : ''}
            placeholder={value ? 'Selected worker retained · search to change' : 'Choose worker'} disabled={disabled || loading || failed}
            onChange={(selected) => { const person = items.find((item) => String(item.id) === selected); if (person) onChange(selected, person); }} />
        <div className="flex flex-wrap items-center gap-3">
            <Button type="button" className="frontline-tap" variant="outline" disabled={disabled || loading || page === 1} onClick={() => setPage(page - 1)}>Previous choices</Button>
            <span className="text-caption">Page {page}{meta ? ` of ${meta.last_page} · ${meta.total} choices` : ''}</span>
            <Button type="button" className="frontline-tap" variant="outline" disabled={disabled || loading || failed || !meta || page >= meta.last_page} onClick={() => setPage(page + 1)}>More choices</Button>
        </div>
        {loading && <p role="status" className="text-subtle">Checking current choices…</p>}
        {failed && <div className="space-y-2"><p role="alert" className="text-subtle text-status-critical">Current worker choices could not be loaded. Your selection is retained.</p><Button type="button" className="frontline-tap" variant="outline" disabled={disabled || loading} onClick={() => setRetry(retry + 1)}>Retry choices</Button></div>}
    </div>;
}
