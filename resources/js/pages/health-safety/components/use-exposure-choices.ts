import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureCapture, ExposureCommandChoices } from '@/lib/health-safety/chemical-exposure-read-contract';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

const object = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const identities = (value: unknown) => Array.isArray(value) && value.every((row) => object(row) && Number.isSafeInteger(row.id) && Number(row.id) > 0 && typeof row.name === 'string');
const staff = (value: unknown) => object(value) && identities(value.items) && (value.next_cursor === null || Number.isSafeInteger(value.next_cursor) && Number(value.next_cursor) > 0);
const incidents = (value: unknown) => object(value) && ['not_selected', 'restricted', 'readable'].includes(String(value.access))
    && (value.access === 'restricted' ? value.items === null : Array.isArray(value.items) && value.items.every((row) => object(row)
        && Number.isSafeInteger(row.id) && Number(row.id) > 0 && typeof row.reference === 'string' && object(row.client)
        && Number.isSafeInteger(row.client.id) && typeof row.client.name === 'string'));
/** Choices are current server-admitted data, never a local Site, staff, Client or command grant. */
export function useExposureChoices({ actorId, rootId, sourceRevision, substanceId, siteId, enabled, onWithdraw }: {
    actorId: number; rootId?: number; sourceRevision?: number; substanceId?: number; siteId: string; enabled: boolean; onWithdraw?: () => void;
}) {
    const [search, setSearch] = useState(''); const [incidentSearch, setIncidentSearch] = useState('');
    const [afterId, setAfterId] = useState(0); const [incidentPage, setIncidentPage] = useState(1); const [nonce, setNonce] = useState(0);
    const key = JSON.stringify([actorId, rootId, sourceRevision, substanceId, siteId, search, incidentSearch, afterId, incidentPage, nonce]);
    const [result, setResult] = useState<{ key: string; value: ExposureCapture | ExposureCommandChoices | null; error: string | null; withdrawn: boolean } | null>(null);
    const [pendingKey, setPendingKey] = useState<string | null>(null); const onDenied = useRef(onWithdraw); onDenied.current = onWithdraw;
    useEffect(() => {
        if (!enabled || !siteId || (!rootId && !substanceId)) { setResult(null); setPendingKey(null); return; }
        const controller = new AbortController(); setPendingKey(key);
        const timer = setTimeout(() => {
            const path = rootId ? `${CHEMICAL_EXPOSURES}/${rootId}/command-options` : `${CHEMICAL_EXPOSURES}/capture-options`;
            const params = { site_id: siteId, after_id: afterId, search, incident_search: incidentSearch, incident_page: incidentPage,
                ...(!rootId ? { substance_id: substanceId } : {}) };
            axios.get(path, { params, headers: { Accept: 'application/json' }, signal: controller.signal }).then(({ data }) => {
                if (controller.signal.aborted) return;
                const valid = object(data) && identities(data.sites) && staff(data.staff) && incidents(data.incidents)
                    && (rootId ? staff(data.receivers) : object(data.can) && data.can.create === true && object(data.substance) && data.substance.id === substanceId);
                setResult({ key, value: valid ? data as unknown as ExposureCapture | ExposureCommandChoices : null,
                    error: valid ? null : 'Current choices could not be confirmed. Keep your entries and check again.', withdrawn: false });
            }).catch((error) => {
                if (controller.signal.aborted) return;
                const withdrawn = axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0);
                setResult({ key, value: null, error: withdrawn ? 'Current access to these choices is unavailable.' : 'Current choices could not be loaded. Keep your entries and check again.', withdrawn });
                if (withdrawn) onDenied.current?.();
            }).finally(() => { if (!controller.signal.aborted) setPendingKey((current) => current === key ? null : current); });
        }, 250);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [key, enabled, siteId, rootId, substanceId, afterId, search, incidentSearch, incidentPage]);
    const current = enabled && result?.key === key ? result : null;
    return { choices: current?.value ?? null, error: current?.error ?? null, withdrawn: current?.withdrawn ?? false,
        loading: enabled && !!siteId && (pendingKey === key || current === null), search, incidentSearch, incidentPage,
        setSearch: (value: string) => { setSearch(value.slice(0, 200)); setAfterId(0); },
        setIncidentSearch: (value: string) => { setIncidentSearch(value.slice(0, 200)); setIncidentPage(1); },
        moreStaff: (cursor: number) => setAfterId(cursor), setIncidentPage,
        resetPages: () => { setAfterId(0); setIncidentPage(1); setSearch(''); setIncidentSearch(''); },
        refresh: () => setNonce((value) => value + 1),
    };
}
