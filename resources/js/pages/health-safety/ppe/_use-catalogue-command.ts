import { useSafetyDraftState, useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import type { FormDataErrors, FormDataType } from '@inertiajs/core';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import { checkedPpeCatalogue, checkedPpeOptions, confirmsPpeCatalogueReceipt, type CatalogueCommand, type CatalogueRow, type PpeCreateOptions } from './_catalogue-state';
import type { PpeReceipt } from './_workspace-types';
import { ppeMultipart } from './_use-command';

/** New stock and global catalogue commands retain exact uncertain intent in memory. */
export function usePpeCatalogueCommand<T extends FormDataType<T>>(initial: T, command: CatalogueCommand, actorId: number, type?: CatalogueRow) {
    const form = useSafetyForm(initial);
    const [guard, setGuard] = useState(() => ({ key: crypto.randomUUID(), version: type?.command_context.expected_version ?? null }));
    const [checked, setChecked] = useState<{ options: PpeCreateOptions | null; type: CatalogueRow | null } | null>(null);
    const [receipt, setReceipt] = useState<PpeReceipt | null>(null);
    const [saving, setSaving] = useState(false); const [checking, setChecking] = useState(false);
    const [unresolved, setUnresolved] = useState(false); const [failure, setFailure] = useState<string | null>(null);
    const [withdrawn, setWithdrawn] = useState(false); const [checkFailed, setCheckFailed] = useState(false);
    const request = useRef<AbortController | null>(null); const mounted = useRef(true);
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; request.current?.abort(); request.current = null; }; }, []);
    const busy = saving || checking;
    const currentType = checked?.type ?? type ?? null;
    const allowed = !withdrawn && !checkFailed && (type ? type.can.manage && currentType?.can.manage === true :
        command === 'create_inventory' ? checked?.options?.can.create === true : checked?.options?.can.catalogue_manage === true);
    const stale = type ? currentType?.command_context.expected_version !== guard.version || !!(form.errors as Record<string, unknown>).expected_version : false;
    useSafetyDraftState({ dirty: !receipt && (form.isDirty || unresolved), busy });
    const accept = (value: unknown) => {
        if (!confirmsPpeCatalogueReceipt(value, { actorId, command, key: guard.key, version: guard.version, id: type?.id })) return false;
        setReceipt(value); setUnresolved(false); setFailure(null); form.reset(); return true;
    };
    const change = <K extends keyof T>(key: K, value: T[K]) => {
        if (request.current || busy || unresolved || receipt || Object.is(form.data[key], value)) return;
        form.setData((before) => ({ ...before, [key]: value })); setGuard((before) => ({ ...before, key: crypto.randomUUID() }));
        form.clearErrors(); setFailure(null);
    };
    const checkCurrent = async () => {
        if (request.current || busy || receipt) return;
        const controller = new AbortController(); request.current = controller; setChecking(true);
        try {
            // Recover accepted work first; receipt authority is independently checked by its canonical service.
            const confirmation = await axios.get('/health-safety/ppe/command-confirmation', { params: { receipt_key: guard.key },
                headers: { Accept: 'application/json' }, signal: controller.signal });
            if (controller.signal.aborted) return;
            if (accept(confirmation.data?.ppe_confirmation)) { setCheckFailed(false); return; }
            if (confirmation.data?.ppe_confirmation !== null) throw new Error('Receipt identity unavailable');
            const response = await axios.get(type ? '/health-safety/ppe' : '/health-safety/ppe/options', {
                params: type ? { tab: 'types' } : {}, headers: { Accept: 'application/json' }, signal: controller.signal });
            if (controller.signal.aborted) return;
            const freshType = type ? checkedPpeCatalogue(response.data, type.id) : null;
            const options = type ? null : checkedPpeOptions(response.data);
            if ((type && !freshType) || (!type && !options)) throw new Error('Current choices unavailable');
            setChecked({ type: freshType, options }); setCheckFailed(false); setWithdrawn(false); setUnresolved(false); setFailure(null);
        } catch (error) {
            if (controller.signal.aborted) return;
            setChecked(null); setCheckFailed(true);
            if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) setWithdrawn(true);
            setFailure('We couldn’t check for changes. Your entries are kept — try again before saving.');
        } finally { if (request.current === controller) { request.current = null; if (mounted.current) setChecking(false); } }
    };
    const useCurrent = () => {
        if (request.current || busy || receipt || unresolved || !allowed || !checked?.type) return;
        setGuard({ key: crypto.randomUUID(), version: checked.type.command_context.expected_version }); form.clearErrors(); setFailure(null);
    };
    const save = async (values: Record<string, unknown>, multipart = false) => {
        if (request.current || busy || receipt || unresolved || stale || !allowed) return;
        setSaving(true); setUnresolved(true); setFailure(null); form.clearErrors();
        const controller = new AbortController(); request.current = controller;
        const href = command === 'create_inventory' ? '/health-safety/ppe/inventory' : command === 'create_type' ? '/health-safety/ppe/types' :
            `/health-safety/ppe/types/${type!.id}${command === 'activate_type' ? '/activate' : command === 'deactivate_type' ? '/deactivate' : ''}`;
        const payload = { ...values, idempotency_key: guard.key, ...(guard.version === null ? {} : { expected_version: guard.version }) };
        try {
            const response = await axios.request({ method: command === 'update_type' ? 'put' : command === 'activate_type' || command === 'deactivate_type' ? 'patch' : 'post', url: href,
                data: multipart ? ppeMultipart(payload) : payload, headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!mounted.current || controller.signal.aborted) return;
            if (!accept(response.data?.ppe_confirmation)) setFailure('We can’t tell if this saved. Select Check for changes before trying again — your entries are kept.');
        } catch (error) {
            if (!mounted.current) return;
            if (axios.isAxiosError(error) && error.response?.status === 422 && error.response.data?.errors) form.setError(Object.fromEntries(
                Object.entries(error.response.data.errors).flatMap(([field, messages]) => Array.isArray(messages) && typeof messages[0] === 'string' ? [[field, messages[0]]] : [])) as FormDataErrors<T>);
            if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) { setWithdrawn(true); setChecked(null); }
            setFailure('We can’t tell if this saved. Select Check for changes before trying again — your entries are kept.');
        } finally { if (request.current === controller) { request.current = null; if (mounted.current) setSaving(false); } }
    };
    // `guard` (the retained idempotency key and reviewed revision) is read-only for callers; only this hook changes it.
    return { form, change, guard, busy, saving, checking, receipt, failure, unresolved, stale, allowed, currentType, options: checked?.options ?? null,
        checkCurrent, useCurrent, checkFailed, reviewAvailable: !!checked && allowed && !unresolved, save };
}
