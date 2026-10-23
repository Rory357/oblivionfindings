import { useSafetyDraftState } from '@/hooks/use-safety-workspace-drafts';
import type { ChemicalReceipt } from '@/lib/health-safety/chemical-contract';
import { CHEMICAL_REGISTER } from '@/lib/health-safety/chemical-navigation';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

export type ChemicalCommandTarget = {
    command: string; actorId: number; substanceId: number | null; sourceType: 'catalogue' | 'sds' | 'storage';
    sourceId: number | null; expectedVersion: number | null; parentExpectedVersion: number | null;
    method: 'post' | 'put' | 'patch' | 'delete'; path: string; allowed: boolean; canRecover?: boolean;
};
const object = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const RECEIPT_KEYS = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id', 'source_type', 'source_id', 'parent_id', 'expected_version', 'parent_expected_version', 'version', 'source_version_id', 'changed'];
/** Success must identify the exact original operation; a generic 2xx or current row cannot confirm it. */
export function confirmsChemicalReceipt(value: unknown, target: ChemicalCommandTarget, key: string): value is ChemicalReceipt {
    if (!object(value) || Object.keys(value).length !== RECEIPT_KEYS.length || !RECEIPT_KEYS.every((item) => item in value)
        || value.accepted !== true || value.actor_id !== target.actorId || value.command !== target.command || value.idempotency_key !== key
        || value.source_type !== target.sourceType || !positive(value.source_id) || !positive(value.root_id)
        || value.expected_version !== target.expectedVersion || value.parent_expected_version !== target.parentExpectedVersion
        || typeof value.changed !== 'boolean' || !Number.isSafeInteger(value.version) || Number(value.version) < 0
        || (value.changed ? !positive(value.source_version_id) : value.source_version_id !== null)) return false;
    if (target.sourceId !== null && value.source_id !== target.sourceId) return false;
    if (target.expectedVersion === null && value.changed !== true) return false;
    if (target.sourceType === 'storage') {
        if (value.root_type !== 'storage' || value.root_id !== value.source_id || value.parent_id !== target.substanceId) return false;
    } else {
        if (value.root_type !== 'catalogue' || (target.substanceId !== null && value.root_id !== target.substanceId)) return false;
        if (target.sourceType === 'catalogue' ? value.source_id !== value.root_id || value.parent_id !== null : value.parent_id !== target.substanceId) return false;
    }
    const version = target.expectedVersion === null ? 1 : target.expectedVersion + (value.changed ? 1 : 0);
    return value.version === version;
}
export function chemicalMultipart(values: Record<string, unknown>): FormData {
    const form = new FormData();
    for (const [key, value] of Object.entries(values)) {
        if (Array.isArray(value)) value.forEach((item, index) => form.append(`${key}[${index}]`, String(item)));
        else form.append(key, value instanceof File ? value : value === null ? '' : typeof value === 'boolean' ? value ? '1' : '0' : String(value));
    }
    return form;
}
type Attempt = { key: string; values: Record<string, unknown>; target: ChemicalCommandTarget; multipart: boolean };
/** Exact submissions and selected bytes stay in memory through uncertainty; no private content enters browser storage. */
export function useChemicalCommand(target: ChemicalCommandTarget, dirty: boolean) {
    const [receipt, setReceipt] = useState<ChemicalReceipt | null>(null);
    const [href, setHref] = useState<string | null>(null); const [busy, setBusy] = useState(false);
    const [uncertain, setUncertain] = useState(false); const [withdrawn, setWithdrawn] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({}); const [message, setMessage] = useState<string | null>(null);
    const [rejected, setRejected] = useState(false);
    const attempt = useRef<Attempt | null>(null); const request = useRef<AbortController | null>(null); const mounted = useRef(true);
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; request.current?.abort(); request.current = null; }; }, []);
    useSafetyDraftState({ dirty: !receipt && (dirty || uncertain), busy, uncertain: !receipt && uncertain && !rejected });
    const accept = (data: unknown, original: Attempt) => {
        if (!object(data) || !confirmsChemicalReceipt(data.chemical_confirmation, original.target, original.key)) return false;
        const confirmation = data.chemical_confirmation;
        const id = confirmation.root_type === 'catalogue' ? confirmation.root_id : confirmation.parent_id;
        // The independently proved optional destination must name the same public catalogue.
        const destination = data.chemical_href;
        const safeHref = typeof destination === 'string' && /^\/health-safety\/substances\/[1-9]\d*(?:\?return_to=[^#\s\\]*)?$/.test(destination)
            && destination.split('?')[0] === `${CHEMICAL_REGISTER}/${id}` ? destination : null;
        setReceipt(confirmation); setHref(safeHref); setUncertain(false); setErrors({}); setMessage(null); return true;
    };
    const failure = (error: unknown, submission = false) => {
        if (axios.isAxiosError(error)) {
            const status = error.response?.status;
            if ([401, 403, 404].includes(status ?? 0)) setWithdrawn(true);
            if (status === 422 && object(error.response?.data?.errors)) {
                setErrors(Object.fromEntries(Object.entries(error.response.data.errors).flatMap(([key, value]) =>
                    Array.isArray(value) && typeof value[0] === 'string' ? [[key, value[0]]] : [])));
                // A failed receipt lookup does not prove that the original write was rejected.
                if (submission) setRejected(true);
            }
        }
        setMessage('This submission is unconfirmed. Your entries are retained. Check it or retry the exact submission before starting another.');
    };
    const send = async (original: Attempt, recovery = false) => {
        if (request.current || receipt || withdrawn || !(recovery ? target.canRecover ?? target.allowed : target.allowed)) return;
        const controller = new AbortController(); request.current = controller; setBusy(true); setUncertain(true); setMessage(null); setRejected(false);
        try {
            const response = await axios.request({ method: original.target.method, url: original.target.path,
                data: original.multipart ? chemicalMultipart(original.values) : original.values,
                headers: { Accept: 'application/json' }, signal: controller.signal });
            if (mounted.current && !controller.signal.aborted && !accept(response.data, original)) setMessage('The response did not confirm this submission. Keep these entries and check before sending again.');
        } catch (error) { if (mounted.current && !controller.signal.aborted) failure(error, true); }
        finally { if (request.current === controller) { request.current = null; if (mounted.current) setBusy(false); } }
    };
    const submit = (values: Record<string, unknown>, multipart = false) => {
        if (request.current || busy || receipt || uncertain || withdrawn || !target.allowed) return;
        const original: Attempt = { target: { ...target }, key: crypto.randomUUID(), multipart,
            values: { ...Object.fromEntries(Object.entries(values).map(([key, value]) => [key, Array.isArray(value) ? [...value] : value])), ...(target.expectedVersion === null ? {} : { expected_version: target.expectedVersion }),
                ...(target.parentExpectedVersion === null ? {} : { parent_expected_version: target.parentExpectedVersion }) } };
        original.values.idempotency_key = original.key; attempt.current = original; void send(original);
    };
    const check = async () => {
        const original = attempt.current;
        if (!original || request.current || busy || receipt || withdrawn || !(target.canRecover ?? target.allowed) || original.target.substanceId === null) return;
        const recoverUpload = original.target.command === 'upload_sds';
        if (!recoverUpload && original.target.sourceId === null) return;
        const path = recoverUpload ? `${CHEMICAL_REGISTER}/${original.target.substanceId}/sds/recover/${original.key}` :
            `${CHEMICAL_REGISTER}/${original.target.substanceId}/confirmations/${original.key}`;
        const controller = new AbortController(); request.current = controller; setBusy(true);
        try {
            const response = await axios.get(path, { params: recoverUpload ? {} : { source_type: original.target.sourceType, source_id: original.target.sourceId },
                headers: { Accept: 'application/json' }, signal: controller.signal });
            if (mounted.current && !controller.signal.aborted && !accept(response.data, original)) setMessage('No accepted submission was confirmed. This does not prove that nothing was saved. The exact original submission is retained.');
        } catch (error) { if (mounted.current && !controller.signal.aborted) failure(error); }
        finally { if (request.current === controller) { request.current = null; if (mounted.current) setBusy(false); } }
    };
    return { receipt, href, busy, uncertain, withdrawn, errors, message, rejected, submit, check,
        canReviewRejected: rejected && !busy && !withdrawn && !errors.idempotency_key,
        resetReviewedRejection: () => {
            if (!rejected || request.current || busy || withdrawn || errors.idempotency_key) return false;
            attempt.current = null; setUncertain(false); setRejected(false); setErrors({}); setMessage(null); return true;
        },
        canEditRejected: rejected && !busy && !withdrawn && !errors.expected_version && !errors.parent_expected_version && !errors.idempotency_key,
        editRejected: () => {
            if (!rejected || request.current || busy || withdrawn || errors.expected_version || errors.parent_expected_version || errors.idempotency_key) return;
            attempt.current = null; setUncertain(false); setRejected(false); setMessage(null);
        },
        retry: () => { if (attempt.current && !busy) void send(attempt.current, true); },
        canRetry: !withdrawn && (target.canRecover ?? target.allowed),
        canCheck: !withdrawn && (target.canRecover ?? target.allowed) && target.substanceId !== null && (target.sourceId !== null || target.command === 'upload_sds'),
        locked: busy || uncertain || !!receipt || withdrawn || !target.allowed };
}
