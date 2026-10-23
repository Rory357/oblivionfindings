import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { useSafetyDraftState } from '@/hooks/use-safety-workspace-drafts';
import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

type DestinationState = 'hs_event_present' | 'inbox_present' | 'unconfirmed' | 'superseded' | 'repair_required' | 'restricted' | 'unavailable';
export type ExposureDeliveryAttempt = { id: number; kind: 'health_safety' | 'followup' | null; attempt_state: 'pending' | 'delivered' | 'superseded' | 'unconfirmed';
    currency: 'current' | 'superseded' | 'unknown'; source_revision: number | null; attempts: number | null; acceptance_integrity: 'recorded' | 'repair_required';
    destination: { state: DestinationState; channel: 'database' | 'health_safety' | null; external_delivery: 'not_attempted'; href: string | null; href_reason: string | null; read: boolean | null;
        actioned: false; notification_preference: 'enabled' | 'disabled' | null; can_deliver: boolean; blocked_reason: string | null }; can: { retry: boolean }; blocked_reason: string | null };
type Attempt = ExposureDeliveryAttempt;
export type ExposureDeliveryCollection = { source_integrity: string; attempts: Attempt[] | null; page: { current_page: number; per_page: 25; total: number | null; last_page: number | null; has_more: boolean | null } };
type Collection = ExposureDeliveryCollection;
const object = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const positive = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const nonnegative = (value: unknown) => value === null || typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const optionalText = (value: unknown) => value === null || typeof value === 'string';
function attempt(value: unknown): value is Attempt {
    if (!object(value) || !positive(value.id) || !['health_safety', 'followup', null].includes(value.kind as string | null)
        || !['pending', 'delivered', 'superseded', 'unconfirmed'].includes(String(value.attempt_state)) || !['current', 'superseded', 'unknown'].includes(String(value.currency))
        || !nonnegative(value.source_revision) || !nonnegative(value.attempts) || !['recorded', 'repair_required'].includes(String(value.acceptance_integrity))
        || !object(value.can) || typeof value.can.retry !== 'boolean' || !optionalText(value.blocked_reason) || !object(value.destination)) return false;
    const d = value.destination;
    if (!['hs_event_present', 'inbox_present', 'unconfirmed', 'superseded', 'repair_required', 'restricted', 'unavailable'].includes(String(d.state))
        || !['database', 'health_safety', null].includes(d.channel as string | null) || d.external_delivery !== 'not_attempted'
        || !optionalText(d.href) || !optionalText(d.href_reason) || !(d.read === null || typeof d.read === 'boolean') || d.actioned !== false
        || !['enabled', 'disabled', null].includes(d.notification_preference as string | null) || typeof d.can_deliver !== 'boolean' || !optionalText(d.blocked_reason)) return false;
    if (value.acceptance_integrity === 'repair_required' && (value.can.retry || d.href !== null)) return false;
    if (value.acceptance_integrity === 'recorded' && (!positive(value.source_revision) || value.attempts === null || value.kind === null)) return false;
    if (value.can.retry && (value.currency !== 'current' || !d.can_deliver)) return false;
    if (d.state === 'hs_event_present' && value.kind !== 'health_safety' || d.state === 'inbox_present' && value.kind !== 'followup') return false;
    return true;
}
function collection(value: unknown, page: number): value is Collection {
    return object(value) && ['recorded', 'legacy_unknown', 'repair_required', 'restricted', 'unavailable'].includes(String(value.source_integrity)) && (value.attempts === null || Array.isArray(value.attempts) && value.attempts.every(attempt))
        && object(value.page) && value.page.current_page === page && value.page.per_page === 25 && nonnegative(value.page.total)
        && (value.page.last_page === null || positive(value.page.last_page)) && (value.page.has_more === null || typeof value.page.has_more === 'boolean');
}
const STATES: Record<DestinationState, string> = { hs_event_present: 'H&S record present', inbox_present: 'Inbox notification present', unconfirmed: 'Destination unconfirmed',
    superseded: 'Earlier source revision', repair_required: 'Destination proof needs review', restricted: 'Destination access restricted', unavailable: 'Destination unavailable' };
const REASONS: Record<string, string> = { current_manage_required: 'A current safety manager must retry this work.', source_advanced: 'The source has changed. This earlier attempt cannot deliver the current revision.',
    confirmation_dependency_unmounted: 'Current destination confirmation is unavailable.', destination_authority_unavailable: 'Current destination access could not be confirmed.',
    destination_source_unavailable: 'The destination source is currently unavailable.', destination_proof_unavailable: 'Destination history needs review before retrying.',
    source_acceptance_unavailable: 'Original source acceptance could not be confirmed.', hse_read_composition_unmounted: 'The H&S record link is unavailable here. Destination presence and opening the record are separate checks.',
    response_floor_pending: 'The saved H&S response needs updating to meet the current reported facts. Its separate ownership and closure decisions are retained.',
    recipient_opted_out: 'The named recipient has opted out of this inbox notification. Their assigned work remains open.' };
/** Source-only composition against the concrete delivery workspace DTO; GET never dispatches. */
export function ExposureDeliveryPanel({ detail, actorId, active, onWithdraw }: { detail: ExposureWorkspaceDetail; actorId: number; active: boolean; onWithdraw?: () => void }) {
    const admitted = detail.exposure_access === 'readable' && detail.record?.id === detail.id && detail.source_integrity !== 'repair_required' && detail.record.attribution_state !== 'repair_required';
    const [requested, setRequested] = useState(false); const [page, setPage] = useState(1); const [nonce, setNonce] = useState(0);
    const [result, setResult] = useState<{ key: string; value: Collection } | null>(null); const [busy, setBusy] = useState(false);
    const [uncertainId, setUncertainId] = useState<number | null>(null); const [message, setMessage] = useState<string | null>(null); const [withdrawn, setWithdrawn] = useState(false);
    const request = useRef<AbortController | null>(null); const onDenied = useRef(onWithdraw); onDenied.current = onWithdraw;
    const requestMode = useRef<'read' | 'write' | null>(null);
    const key = `${actorId}:${detail.id}:${detail.command_context?.expected_version ?? 'unknown'}:${detail.can.correct}:${page}:${nonce}`;
    const enabled = active && admitted && !withdrawn; const current = enabled && result?.key === key ? result.value : null;
    const live = useRef({ enabled, key }); live.current = { enabled, key };
    const base = `${CHEMICAL_EXPOSURES}/${detail.id}/deliveries`;
    useSafetyDraftState({ dirty: uncertainId !== null, busy, uncertain: uncertainId !== null });
    const failure = (error: unknown) => { if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) { setWithdrawn(true); onDenied.current?.(); } };
    useEffect(() => () => {
        if (requestMode.current === 'read') { request.current?.abort(); request.current = null; requestMode.current = null; setBusy(false); }
    }, [enabled, key]);
    useEffect(() => {
        if (!enabled) { setResult(null); if (request.current === null) setBusy(false); return; }
        if (!requested || uncertainId !== null) return;
        const controller = new AbortController(); request.current = controller; requestMode.current = 'read'; setBusy(true); setMessage(null);
        void axios.get(base, { headers: { Accept: 'application/json' }, params: { page }, signal: controller.signal }).then(({ data }) => {
            if (controller.signal.aborted) return; if (!collection(data, page)) throw new Error('Delivery collection unconfirmed'); setResult({ key, value: data });
        }).catch((error) => { if (!controller.signal.aborted) { failure(error); setResult(null); setMessage('Delivery records could not be confirmed. Saved source acceptance remains separate.'); } })
            .finally(() => { if (request.current === controller) { request.current = null; requestMode.current = null; if (!controller.signal.aborted) setBusy(false); } });
        return () => { controller.abort(); if (request.current === controller) { request.current = null; requestMode.current = null; } };
        // The complete request identity binds current actor/root/revision/permission/page to the detached result.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [enabled, requested, key, base, page, uncertainId]);
    useEffect(() => () => { request.current?.abort(); request.current = null; }, []);
    const inspect = async (id: number) => {
        if (!enabled || request.current || !positive(id) || uncertainId !== null && uncertainId !== id) return;
        const controller = new AbortController(); request.current = controller; requestMode.current = 'read'; setBusy(true); setMessage(null);
        try {
            const response = await axios.get(`${base}/${id}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (controller.signal.aborted || !live.current.enabled || live.current.key !== key) return;
            if (!attempt(response.data?.inspection) || response.data.inspection.id !== id) throw new Error('Original attempt not confirmed');
            const row = response.data.inspection as Attempt;
            setResult((previous) => previous?.key === key ? { key, value: { ...previous.value, attempts: previous.value.attempts?.map((item) => item.id === id ? row : item) ?? null } } : previous);
            setUncertainId(null); setMessage('The current saved state of this original attempt was checked. This does not certify a person received or completed the work.');
        } catch (error) { if (!controller.signal.aborted) { failure(error); setMessage('The original delivery state is still unconfirmed. Keep this attempt and check again before another change.'); } }
        finally { if (request.current === controller) { request.current = null; requestMode.current = null; if (!controller.signal.aborted) setBusy(false); } }
    };
    const retry = async (id: number) => {
        const row = current?.attempts?.find((item) => item.id === id);
        if (!enabled || request.current || uncertainId !== null || !detail.can.correct || !row?.can.retry || row.currency !== 'current' || row.acceptance_integrity !== 'recorded') return;
        const controller = new AbortController(); request.current = controller; requestMode.current = 'write'; setBusy(true); setMessage(null);
        try {
            const response = await axios.post(`${base}/${id}/retry`, {}, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (controller.signal.aborted) return;
            if (!live.current.enabled || live.current.key !== key) { setUncertainId(id); setMessage('Check the original delivery attempt under current access before another change.'); return; }
            if (response.data?.retry_requested_by !== actorId || !attempt(response.data?.inspection) || response.data.inspection.id !== id) throw new Error('Retry state did not identify the original attempt');
            const next = response.data.inspection as Attempt;
            setResult((previous) => previous?.key === key ? { key, value: { ...previous.value, attempts: previous.value.attempts?.map((item) => item.id === id ? next : item) ?? null } } : previous);
            setMessage('Retry processing returned a fresh destination check. Read the result below; a retry request alone is not delivery confirmation.');
        } catch (error) { if (!controller.signal.aborted) { failure(error); setUncertainId(id); setMessage('The delivery response is unconfirmed. Check this original attempt before retrying it.'); } }
        finally { if (request.current === controller) { request.current = null; requestMode.current = null; if (!controller.signal.aborted) setBusy(false); } }
    };
    if (!active) return null;
    if (!admitted || withdrawn) return <p role="status" className="text-subtle text-status-warning">Delivery details and changes are withheld because current source access is unavailable.</p>;
    return <section className="space-y-4" aria-label="Current delivery checks"><h3 className="text-section-title">Current delivery checks</h3>
        <p className="text-subtle">Source acceptance, a destination record, an inbox read flag and completed work are separate. These checks do not send email, SMS or push notifications.</p>
        <div className="flex flex-wrap gap-3"><Button type="button" variant="outline" className="frontline-tap" disabled={busy || uncertainId !== null}
            onClick={() => { setRequested(true); setNonce((value) => value + 1); }}>Check delivery records</Button>
            {uncertainId !== null && <Button type="button" variant="outline" className="frontline-tap" disabled={busy} onClick={() => void inspect(uncertainId)}>Check original delivery attempt</Button>}</div>
        {message && <p role="status" className="text-subtle text-status-warning">{message}</p>}
        {busy && <p role="status" className="text-subtle">Checking the retained delivery attempt…</p>}
        {current?.attempts === null && <p className="text-subtle text-status-warning">The collection is unavailable. Its total is unconfirmed.</p>}
        {current?.attempts && (!current.attempts.length ? <p className="text-subtle">No admitted delivery attempts are available on this page.</p> : <ul className="space-y-4">{current.attempts.map((row) => <li key={row.id} className="space-y-3 rounded-lg border border-border bg-card p-4">
            <h4 className="text-section-title">{row.kind === 'health_safety' ? 'Health & Safety' : row.kind === 'followup' ? 'Named follow-up' : 'Unconfirmed source'} delivery</h4>
            <StatusBadge variant={['hs_event_present', 'inbox_present'].includes(row.destination.state) ? 'neutral' : 'warning'}>{STATES[row.destination.state]}</StatusBadge>
            <dl className="grid gap-3 sm:grid-cols-2"><div><dt className="text-caption text-muted-foreground">Source revision</dt><dd className="text-subtle">{row.source_revision ?? 'Unconfirmed'}</dd></div>
                <div><dt className="text-caption text-muted-foreground">Reported attempt state</dt><dd className="text-subtle">{row.attempt_state === 'delivered' ? 'Reported delivered · see destination proof' : row.attempt_state === 'pending' ? 'Pending' : row.attempt_state === 'superseded' ? 'Superseded' : 'Unconfirmed'}</dd></div>
                <div><dt className="text-caption text-muted-foreground">Recorded confirmations</dt><dd className="text-subtle">{row.attempts ?? 'Unconfirmed'}</dd></div>
                {row.kind === 'followup' && <div><dt className="text-caption text-muted-foreground">Inbox read state</dt><dd className="text-subtle">{row.destination.read === null ? 'Unconfirmed' : row.destination.read ? 'Inbox item marked read' : 'Not marked read'}</dd></div>}</dl>
            {row.kind === 'followup' && <p className="text-subtle">Inbox presence or a read flag does not record follow-up completion. Review the named work in Follow-ups.</p>}
            <p className="text-subtle">This counter does not include every failed or interrupted retry.</p>
            {(row.blocked_reason || row.destination.blocked_reason) && <p className="text-subtle text-status-warning">{REASONS[row.blocked_reason ?? row.destination.blocked_reason ?? ''] ?? 'Current source or destination conditions need review before this work can proceed.'}</p>}
            {row.destination.href_reason && row.destination.href_reason !== row.blocked_reason && row.destination.href_reason !== row.destination.blocked_reason && <p className="text-subtle">{REASONS[row.destination.href_reason] ?? 'The destination link is currently unavailable.'}</p>}
            {row.destination.notification_preference === 'disabled' && <p className="text-subtle">The recipient has opted out of this inbox notification; their assigned source work remains open.</p>}
            <div className="flex flex-wrap gap-3"><Button type="button" variant="outline" className="frontline-tap" disabled={busy || uncertainId !== null && uncertainId !== row.id} onClick={() => void inspect(row.id)}>Check this destination</Button>
                {detail.can.correct && row.can.retry && <Button type="button" variant="outline" className="frontline-tap" disabled={busy || uncertainId !== null} onClick={() => void retry(row.id)}>
                    {row.kind === 'health_safety' && row.destination.blocked_reason === 'response_floor_pending' ? 'Update H&S response' : 'Retry this delivery'}</Button>}
                {row.destination.state === 'hs_event_present' && typeof row.destination.href === 'string' && /^\/health-safety\/events\/[1-9]\d*$/.test(row.destination.href) &&
                    <Button asChild variant="outline" className="frontline-tap"><a href={row.destination.href}>Open confirmed H&S record</a></Button>}</div>
        </li>)}</ul>)}
        {current && <nav aria-label="Delivery pages" className="flex flex-wrap items-center gap-3"><Button type="button" variant="outline" className="frontline-tap" disabled={busy || uncertainId !== null || page <= 1} onClick={() => setPage((value) => value - 1)}>Previous delivery page</Button>
            <p className="text-caption text-muted-foreground">Page {page}{current.page.last_page === null ? ' · total unconfirmed' : ` of ${current.page.last_page}`}</p>
            <Button type="button" variant="outline" className="frontline-tap" disabled={busy || uncertainId !== null || current.page.has_more !== true} onClick={() => setPage((value) => value + 1)}>Next delivery page</Button></nav>}
    </section>;
}
