import { useSafetyDraftState, useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import { ppeHref, type PpeCommand } from '@/lib/health-safety/ppe-navigation';
import type { FormDataErrors, FormDataType } from '@inertiajs/core';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { confirmsPpeReceipt, ppeCheckedRecord, ppeCommandAllowed, ppeReceiptCommand } from './_command-state';
import type { PpeRecord, PpeReceipt } from './_workspace-types';

const SOURCE_KIND = { inventory: 'inventory', allocations: 'allocation', inspections: 'inspection' } as const;
/** Multipart values follow the same reviewed command; keys never enter navigation/history. */
export function ppeMultipart(values: Record<string, unknown>): FormData {
    const body = new FormData();
    const add = (key: string, value: unknown) => {
        if (value instanceof File) body.append(key, value);
        else if (Array.isArray(value)) value.forEach((part, index) => add(`${key}[${index}]`, part));
        else if (value !== null && typeof value === 'object') Object.entries(value).forEach(([name, part]) => add(`${key}[${name}]`, part));
        else body.append(key, value == null ? '' : typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    };
    Object.entries(values).forEach(([key, value]) => add(key, value));
    return body;
}
function hasFile(value: unknown): boolean {
    return value instanceof File || (Array.isArray(value) ? value.some(hasFile) : !!value && typeof value === 'object' && Object.values(value).some(hasFile));
}

/** A failed/uncertain request keeps its exact intent and UUID until canonical receipt recovery. */
export function usePpeCommand<T extends FormDataType<T>>({ initial, record, command, actorId, attachment }: {
    initial: T; record: PpeRecord; command: PpeCommand; actorId: number; attachment?: number | null;
}) {
    const form = useSafetyForm(initial);
    const [guard, setGuard] = useState(() => ({ version: record.detail.command_context.expected_version, key: crypto.randomUUID() }));
    const [receiptCommand] = useState(() => ppeReceiptCommand(record, command));
    const [checked, setChecked] = useState<{ origin: string; record: PpeRecord } | null>(null);
    const [receipt, setReceipt] = useState<PpeReceipt | null>(null);
    const [checking, setChecking] = useState(false);
    const [saving, setSaving] = useState(false);
    const [unresolved, setUnresolved] = useState(false);
    const [checkFailed, setCheckFailed] = useState(false);
    const [withdrawn, setWithdrawn] = useState(false);
    const [failure, setFailure] = useState<string | null>(null);
    const request = useRef<AbortController | null>(null);
    const mounted = useRef(true);
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; request.current?.abort(); }; }, []);
    const origin = JSON.stringify(record);
    const current = checked?.origin === origin ? checked.record : record;
    const allowed = !withdrawn && ppeCommandAllowed(record, command, attachment) && ppeCommandAllowed(current, command, attachment) &&
        ppeReceiptCommand(current, command) === receiptCommand;
    const stale = current.detail.command_context.expected_version !== guard.version || !!(form.errors as Record<string, unknown>).expected_version;
    const busy = checking || saving;
    useSafetyDraftState({ dirty: !receipt && (form.isDirty || unresolved), busy });
    const subjectKind = SOURCE_KIND[record.kind];
    const expected = {
        actorId, rootId: record.detail.command_context.root_id,
        sourceKind: ['upload_evidence', 'remove_evidence', 'relocate_evidence'].includes(command) ? `${subjectKind}_attachment` : command === 'allocate' ? 'allocation' : command === 'inspect' ? 'inspection' : subjectKind,
        sourceId: ['remove_evidence', 'relocate_evidence'].includes(command) ? attachment ?? undefined : ['allocate', 'inspect', 'upload_evidence'].includes(command) ? undefined : record.detail.id,
        command: receiptCommand, version: guard.version, key: guard.key,
    };
    const accept = (value: unknown) => {
        if (!confirmsPpeReceipt(value, expected)) return false;
        setReceipt(value); setUnresolved(false); setFailure(null); form.reset(); return true;
    };
    const change = <K extends keyof T>(field: K, value: T[K]) => {
        if (request.current || busy || receipt || unresolved || Object.is(form.data[field], value)) return;
        form.setData((before) => ({ ...before, [field]: value }));
        setGuard((before) => ({ ...before, key: crypto.randomUUID() })); form.clearErrors(); setFailure(null);
    };
    const checkCurrent = async () => {
        if (request.current || busy || receipt) return;
        const controller = new AbortController(); request.current = controller; setChecking(true);
        try {
            const { data } = await axios.get(ppeHref(record.kind, record.detail.id), {
                headers: { Accept: 'application/json' }, params: { receipt_key: guard.key }, signal: controller.signal,
            });
            if (controller.signal.aborted) return;
            // A proven saved command remains recoverable even if its resulting lifecycle disables that action.
            if (accept(data?.ppe_confirmation)) { setCheckFailed(false); return; }
            const fresh = ppeCheckedRecord(data, record);
            if (!fresh || data?.ppe_confirmation !== null) throw new Error('Current record identity unavailable');
            setChecked({ origin, record: fresh }); setCheckFailed(false);
            setWithdrawn(!ppeCommandAllowed(fresh, command, attachment) || ppeReceiptCommand(fresh, command) !== receiptCommand);
            setUnresolved(false); setFailure(null);
        } catch (error) {
            if (controller.signal.aborted) return;
            setChecked(null);
            setCheckFailed(true);
            if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) setWithdrawn(true);
            setFailure('We couldn’t check for changes. Your entries are kept — try again before saving.');
        } finally {
            if (request.current === controller) { request.current = null; setChecking(false); }
        }
    };
    const useCurrent = () => {
        if (request.current || busy || receipt || unresolved || !allowed || checked?.origin !== origin) return;
        setGuard({ version: current.detail.command_context.expected_version, key: crypto.randomUUID() });
        form.clearErrors(); setFailure(null);
    };
    const save = async (method: 'post' | 'put' | 'delete', href: string, values: Record<string, unknown>) => {
        if (request.current || busy || receipt || unresolved || stale || checkFailed || !allowed) return;
        setSaving(true); setUnresolved(true); setFailure(null); form.clearErrors();
        const controller = new AbortController(); request.current = controller;
        const payload = { ...values, idempotency_key: guard.key, expected_version: guard.version };
        try {
            const { data } = await axios.request({ method: hasFile(payload) && method !== 'post' ? 'post' : method,
                url: href, data: hasFile(payload) ? ppeMultipart({ ...payload, ...(method !== 'post' ? { _method: method } : {}) }) : payload,
                headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!mounted.current || controller.signal.aborted) return;
            if (!accept(data?.ppe_confirmation)) setFailure('We can’t tell if this saved. Select Check for changes before trying again — your entries are kept.');
        } catch (error) {
            if (!mounted.current) return;
            if (axios.isAxiosError(error) && error.response?.status === 422 && error.response.data?.errors) {
                const errors = Object.fromEntries(Object.entries(error.response.data.errors)
                    .flatMap(([field, messages]) => Array.isArray(messages) && typeof messages[0] === 'string' ? [[field, messages[0]]] : []));
                form.setError(errors as FormDataErrors<T>);
                // Validation refused this request before acceptance; keep intent for an explicit current check.
            }
            if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) setWithdrawn(true);
            setFailure('We can’t tell if this saved. Select Check for changes before trying again — your entries are kept.');
        } finally {
            if (request.current === controller) { request.current = null; if (mounted.current) setSaving(false); }
        }
    };
    return { form, change, current, guard, receipt, allowed, stale, busy, checking, saving, unresolved, checkFailed, failure,
        reviewAvailable: checked?.origin === origin && allowed && !unresolved && !checkFailed, checkCurrent, useCurrent, save };
}
