import { useSafetyDraftState } from '@/hooks/use-safety-workspace-drafts';
import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

export type ExposureCommand = 'create_exposure' | 'correct_exposure' | 'review_exposure' | 'assign_followup' | 'reassign_followup' | 'complete_followup' | 'upload_evidence' | 'remove_evidence';
export type ExposureCommandTarget = {
    actorId: number; command: ExposureCommand; rootId: number | null; substanceId: number | null;
    sourceType: 'exposure' | 'review' | 'followup' | 'evidence'; sourceId: number | null; expectedVersion: number | null;
    method: 'post' | 'put' | 'delete'; path: string; allowed: boolean; canRecover: boolean;
};
export interface ExposureReceipt {
    accepted: true; actor_id: number; command: ExposureCommand; idempotency_key: string;
    root_type: 'exposure'; root_id: number; source_type: ExposureCommandTarget['sourceType']; source_id: number;
    expected_version: number | null; version: number; source_version_id: number | null; changed: boolean;
}
const RECEIPT_KEYS = ['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id', 'source_type', 'source_id', 'expected_version', 'version', 'source_version_id', 'changed'];
const object = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
/** An exact private receipt confirms the submitted command; it never grants saved read or destination delivery. */
export function confirmsExposureReceipt(value: unknown, target: ExposureCommandTarget, key: string): value is ExposureReceipt {
    const sourceType = target.command === 'review_exposure' ? 'review' : ['assign_followup', 'reassign_followup', 'complete_followup'].includes(target.command)
        ? 'followup' : ['upload_evidence', 'remove_evidence'].includes(target.command) ? 'evidence' : 'exposure';
    if (!positive(target.actorId) || target.sourceType !== sourceType || (target.command === 'create_exposure'
        ? !positive(target.substanceId) || target.rootId !== null || target.sourceId !== null || target.expectedVersion !== null
        : !positive(target.rootId) || !Number.isSafeInteger(target.expectedVersion) || Number(target.expectedVersion) < 0)) return false;
    if (target.command === 'correct_exposure' && target.sourceId !== target.rootId
        || ['reassign_followup', 'complete_followup', 'remove_evidence'].includes(target.command) && !positive(target.sourceId)) return false;
    if (!object(value) || Object.keys(value).length !== RECEIPT_KEYS.length || !RECEIPT_KEYS.every((field) => field in value)
        || value.accepted !== true || value.actor_id !== target.actorId || value.command !== target.command || value.idempotency_key !== key
        || value.root_type !== 'exposure' || !positive(value.root_id) || !positive(value.source_id) || value.source_type !== target.sourceType
        || value.expected_version !== target.expectedVersion || typeof value.changed !== 'boolean' || !Number.isSafeInteger(value.version)
        || Number(value.version) < 0 || (value.changed ? !positive(value.source_version_id) : value.source_version_id !== null)) return false;
    if ((target.rootId !== null && value.root_id !== target.rootId) || (target.sourceId !== null && value.source_id !== target.sourceId)) return false;
    if (value.source_type === 'exposure' && value.source_id !== value.root_id) return false;
    if (target.command !== 'correct_exposure' && !value.changed) return false;
    if (target.command === 'create_exposure') return target.expectedVersion === null && value.version === 1 && value.changed;
    return target.expectedVersion !== null && value.version === target.expectedVersion + (value.changed ? 1 : 0);
}
function multipart(values: Record<string, unknown>) {
    const form = new FormData();
    for (const [key, value] of Object.entries(values)) form.append(key, value instanceof File ? value : value === null ? '' : typeof value === 'boolean' ? value ? '1' : '0' : String(value));
    return form;
}
type Attempt = { key: string; target: ExposureCommandTarget; values: Record<string, unknown>; multipart: boolean };
/** Retains exact original UUID, reviewed revision, fields and selected file in memory through an ambiguous response. */
export function useExposureCommand(target: ExposureCommandTarget, dirty: boolean) {
    const [receipt, setReceipt] = useState<ExposureReceipt | null>(null); const [href, setHref] = useState<string | null>(null);
    const [busy, setBusy] = useState(false); const [uncertain, setUncertain] = useState(false); const [withdrawn, setWithdrawn] = useState(false);
    const [rejected, setRejected] = useState(false); const [errors, setErrors] = useState<Record<string, string>>({}); const [message, setMessage] = useState<string | null>(null);
    const attempt = useRef<Attempt | null>(null); const request = useRef<AbortController | null>(null); const mounted = useRef(true);
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; request.current?.abort(); request.current = null; }; }, []);
    useSafetyDraftState({ dirty: !receipt && (dirty || uncertain), busy, uncertain: !receipt && uncertain && !rejected });
    const accept = (data: unknown, original: Attempt) => {
        if (!object(data) || !confirmsExposureReceipt(data.chemical_exposure_confirmation, original.target, original.key)) return false;
        const confirmation = data.chemical_exposure_confirmation;
        // Optional URL is independently server-admitted. It must still name this accepted root, without a command or receipt in history.
        const destination = data.chemical_exposure_href;
        setHref(destination === `${CHEMICAL_EXPOSURES}/${confirmation.root_id}` ? destination : null);
        setReceipt(confirmation); setUncertain(false); setErrors({}); setMessage(null); return true;
    };
    const failure = (error: unknown, submitted: boolean) => {
        if (axios.isAxiosError(error)) {
            if ([401, 403, 404].includes(error.response?.status ?? 0)) setWithdrawn(true);
            if (error.response?.status === 422 && object(error.response.data?.errors)) {
                setErrors(Object.fromEntries(Object.entries(error.response.data.errors).flatMap(([key, value]) =>
                    Array.isArray(value) && typeof value[0] === 'string' ? [[key, value[0]]] : [])));
                if (submitted) setRejected(true);
            }
        }
        setMessage('This submission is unconfirmed. Your entries are retained. Check or retry the exact submission before starting another.');
    };
    const send = async (original: Attempt, recovery = false) => {
        if (request.current || receipt || withdrawn || !(recovery ? target.canRecover : target.allowed)) return;
        const controller = new AbortController(); request.current = controller; setBusy(true); setUncertain(true); setRejected(false); setMessage(null);
        try {
            const response = await axios.request({ method: original.target.method, url: original.target.path,
                data: original.multipart ? multipart(original.values) : original.values, headers: { Accept: 'application/json' }, signal: controller.signal });
            if (mounted.current && !controller.signal.aborted && !accept(response.data, original)) setMessage('The response did not confirm this exact submission. Keep your entries and check before sending again.');
        } catch (error) { if (mounted.current && !controller.signal.aborted) failure(error, true); }
        finally { if (request.current === controller) { request.current = null; if (mounted.current) setBusy(false); } }
    };
    const submit = (values: Record<string, unknown>, isMultipart = false) => {
        if (request.current || busy || receipt || uncertain || withdrawn || !target.allowed) return;
        const original: Attempt = { key: crypto.randomUUID(), target: { ...target }, multipart: isMultipart,
            values: { ...values, ...(target.expectedVersion === null ? {} : { expected_version: target.expectedVersion }) } };
        original.values.idempotency_key = original.key; attempt.current = original; void send(original);
    };
    const check = async () => {
        const original = attempt.current;
        if (!original || request.current || busy || receipt || withdrawn || !target.canRecover) return;
        const capture = original.target.command === 'create_exposure';
        if (capture ? original.target.substanceId === null : original.target.rootId === null) return;
        const path = capture ? `${CHEMICAL_EXPOSURES}/capture-confirmation` : `${CHEMICAL_EXPOSURES}/${original.target.rootId}/confirmation`;
        const params = { receipt_key: original.key, ...(capture ? { substance_id: original.target.substanceId } : {}) };
        const controller = new AbortController(); request.current = controller; setBusy(true);
        try {
            const response = await axios.get(path, { params, headers: { Accept: 'application/json' }, signal: controller.signal });
            if (mounted.current && !controller.signal.aborted && !accept(response.data, original)) setMessage('No accepted submission was confirmed. This does not prove that nothing was saved. The original submission is retained.');
        } catch (error) { if (mounted.current && !controller.signal.aborted) failure(error, false); }
        finally { if (request.current === controller) { request.current = null; if (mounted.current) setBusy(false); } }
    };
    return { receipt, href, busy, uncertain, withdrawn, rejected, errors, message, submit, check,
        locked: busy || uncertain || withdrawn || !!receipt || !target.allowed,
        canCheck: !!attempt.current && !busy && !withdrawn && !receipt && target.canRecover,
        canRetry: !!attempt.current && !busy && !withdrawn && !receipt && target.canRecover,
        retry: () => { if (attempt.current && !busy) void send(attempt.current, true); },
        canReviewRejected: rejected && !busy && !withdrawn && !errors.idempotency_key,
        resetReviewedRejection: () => {
            if (!rejected || request.current || busy || withdrawn || errors.idempotency_key) return false;
            attempt.current = null; setUncertain(false); setRejected(false); setErrors({}); setMessage(null); return true;
        },
        canEditRejected: rejected && !busy && !withdrawn && !errors.expected_version && !errors.idempotency_key,
        editRejected: () => {
            if (!rejected || request.current || busy || withdrawn || errors.expected_version || errors.idempotency_key) return false;
            attempt.current = null; setUncertain(false); setRejected(false); setMessage(null); return true;
        },
    };
}
