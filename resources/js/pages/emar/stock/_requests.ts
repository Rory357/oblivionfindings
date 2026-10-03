import { useRef, useState } from 'react';
import type { StockResponse } from './_types';

export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))?.slice(11);
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    return { Accept: 'application/json', ...(cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie) } : {}), ...(meta ? { 'X-CSRF-TOKEN': meta } : {}) };
}

export function useStockCommand() {
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const last = useRef<{ body: string; uuid: string } | null>(null);
    const busy = useRef(false);
    const run = async (data: Record<string, unknown>): Promise<StockResponse | null> => {
        if (busy.current) return null;
        busy.current = true;
        setSaving(true);
        setErrors({});
        const body = JSON.stringify(data);
        if (last.current?.body !== body) last.current = { body, uuid: crypto.randomUUID() };
        try {
            const response = await fetch('/emar/stock/packs/commands', {
                method: 'POST', credentials: 'same-origin', headers: { ...csrfHeaders(), 'Content-Type': 'application/json' },
                body: JSON.stringify({ ...data, request_uuid: last.current.uuid }),
            });
            const result = await response.json();
            if (!response.ok || result.success !== true) {
                const fields = Object.fromEntries(Object.entries(result.errors ?? {}).map(([key, value]) => [key, Array.isArray(value) ? String(value[0]) : String(value)]));
                setErrors(Object.keys(fields).length ? fields : { save: result.message ?? 'Could not save. Your entries are kept; check the current record and try again.' });
                window.setTimeout(() => document.getElementById(Object.keys(fields)[0])?.focus(), 0);
                return null;
            }
            return result;
        } catch {
            setErrors({ save: 'Could not confirm the save. Your entries are kept. Try again when connected; the same request will not receive stock twice.' });
            return null;
        } finally {
            busy.current = false;
            setSaving(false);
        }
    };
    return { saving, errors, run };
}

