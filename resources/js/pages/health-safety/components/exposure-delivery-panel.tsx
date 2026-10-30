/* Delivery checks for one private chemical exposure (drawing 129g; job 3, Stephan 26 Oct 2026).
 *
 * Each save of an exposure is delivered to the H&S register automatically, straight after the save. This panel reads
 * `GET /health-safety/chemical-exposures/{id}/deliveries`: each attempt with its checked destination (whether the H&S
 * event is really there), what the automatic delivery did (failed attempts, why, the next try) and whether the viewer
 * may retry it. Exposure managers retry with an explicit POST to `…/deliveries/{delivery}/retry`; the server proves the
 * record again and reports the result. GET never sends anything. Follow-up notices are off, so a follow-up attempt is
 * shown but never sent. A refused read (401/403/404) withdraws the private view, as before. */
import { Note, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

export type ExposureDeliveryAutomatic = {
    failed_attempts: number;
    failure: 'busy' | 'error' | 'integrity' | 'unavailable' | null;
    needs_manager: boolean;
    next_attempt_at: string | null;
    last_attempted_at: string | null;
    stuck: boolean;
};
export type ExposureDeliveryAttempt = {
    id: number;
    kind: 'health_safety' | 'followup' | null;
    attempt_state: 'pending' | 'delivered' | 'superseded' | 'unconfirmed';
    currency: 'current' | 'superseded' | 'unknown';
    source_revision: number | null;
    attempts: number | null;
    acceptance_integrity: 'recorded' | 'repair_required';
    destination: { state: string; can_deliver: boolean; blocked_reason: string | null };
    automatic?: ExposureDeliveryAutomatic;
    can: { retry: boolean };
    blocked_reason: string | null;
};
export type ExposureDeliveryCollection = {
    source_integrity: 'recorded' | 'legacy_unknown' | 'repair_required' | 'restricted' | 'unavailable';
    attempts: ExposureDeliveryAttempt[] | null;
    page: { current_page: number; total: number | null };
};

const object = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const count = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
const optionalText = (value: unknown) => value === null || typeof value === 'string';
function automatic(value: unknown): value is ExposureDeliveryAutomatic {
    return object(value) && count(value.failed_attempts) && (value.failure === null || ['busy', 'error', 'integrity', 'unavailable'].includes(String(value.failure)))
        && typeof value.needs_manager === 'boolean' && optionalText(value.next_attempt_at) && optionalText(value.last_attempted_at) && typeof value.stuck === 'boolean';
}
function attempt(value: unknown): value is ExposureDeliveryAttempt {
    return object(value) && count(value.id) && value.id > 0 && (value.kind === null || value.kind === 'health_safety' || value.kind === 'followup')
        && ['pending', 'delivered', 'superseded', 'unconfirmed'].includes(String(value.attempt_state))
        && ['current', 'superseded', 'unknown'].includes(String(value.currency))
        && (value.source_revision === null || count(value.source_revision)) && (value.attempts === null || count(value.attempts))
        && (value.acceptance_integrity === 'recorded' || value.acceptance_integrity === 'repair_required')
        && object(value.destination) && typeof value.destination.state === 'string' && typeof value.destination.can_deliver === 'boolean'
        && (value.automatic === undefined || automatic(value.automatic))
        && object(value.can) && typeof value.can.retry === 'boolean' && optionalText(value.blocked_reason);
}
function collection(value: unknown): value is ExposureDeliveryCollection {
    return object(value) && ['recorded', 'legacy_unknown', 'repair_required', 'restricted', 'unavailable'].includes(String(value.source_integrity))
        && (value.attempts === null || (Array.isArray(value.attempts) && value.attempts.length <= 25 && value.attempts.every(attempt)))
        && object(value.page);
}

/** One plain answer per attempt: where the work is now. */
function stateOf(row: ExposureDeliveryAttempt): { label: string; variant: StatusVariant } {
    if (row.acceptance_integrity === 'repair_required' || row.destination.state === 'repair_required') return { label: 'Needs checking', variant: 'critical' };
    if (row.kind === 'followup') return { label: 'Notice not sent', variant: 'neutral' };
    if (row.attempt_state === 'superseded' || row.currency === 'superseded') return { label: 'Replaced by a later save', variant: 'neutral' };
    if (row.destination.state === 'hs_event_present') return { label: 'On the H&S register', variant: 'success' };
    if (row.automatic?.needs_manager) return { label: 'Needs a manager', variant: 'critical' };
    if (row.automatic?.failure) return { label: 'Failed · trying again', variant: 'warning' };
    if (row.attempt_state === 'pending') return row.automatic?.stuck ? { label: 'Waiting over 10 minutes', variant: 'warning' } : { label: 'Waiting to be delivered', variant: 'info' };
    return { label: 'Not confirmed', variant: 'warning' };
}

const FAILURE: Record<string, string> = {
    busy: 'Another change to this exposure was saving at the same time.',
    error: 'Something went wrong while saving it to the register.',
    integrity: 'The record’s saved history didn’t check out, so it wasn’t sent.',
    unavailable: 'The record or its H&S event couldn’t be found.',
};
const BLOCKED: Record<string, string> = {
    current_manage_required: 'Only an exposure manager can retry this delivery.',
    source_advanced: 'A later save replaced this delivery.',
    followup_notices_off: 'Follow-up notices are off. The assignee sees this work in Tasks.',
    response_floor_pending: 'The H&S event’s severity is below what the exposure needs. A retry raises it.',
};

export function ExposureDeliveryPanel({ detail, actorId, active, autoCheck = false, onWithdraw }: { detail: ExposureWorkspaceDetail; actorId: number; active: boolean; autoCheck?: boolean; onWithdraw?: () => void }) {
    const admitted = detail.exposure_access === 'readable' && detail.record?.id === detail.id && detail.source_integrity !== 'repair_required' && detail.record.attribution_state !== 'repair_required';
    const [nonce, setNonce] = useState(autoCheck ? 1 : 0);
    const [result, setResult] = useState<{ key: string; value: ExposureDeliveryCollection } | null>(null);
    const [busy, setBusy] = useState(false);
    const [retrying, setRetrying] = useState<number | null>(null);
    const [message, setMessage] = useState<string | null>(null);
    const [outcome, setOutcome] = useState<string | null>(null);
    const [withdrawn, setWithdrawn] = useState(false);
    const onDenied = useRef(onWithdraw);
    useEffect(() => {
        onDenied.current = onWithdraw;
    }, [onWithdraw]);
    // A result belongs to one account, record, revision and permission; any change drops it.
    const key = `${actorId}:${detail.id}:${detail.command_context?.expected_version ?? 'unknown'}:${detail.can.correct}`;
    const enabled = active && admitted && !withdrawn;
    const current = enabled && result?.key === key ? result.value : null;
    const url = `${CHEMICAL_EXPOSURES}/${detail.id}/deliveries`;
    const refused = (error: unknown) => {
        if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) {
            setWithdrawn(true);
            onDenied.current?.();
            return true;
        }
        return false;
    };
    useEffect(() => {
        if (!enabled) {
            setBusy(false);
            return;
        }
        if (nonce === 0) return;
        const controller = new AbortController();
        setBusy(true);
        setMessage(null);
        void axios
            .get(url, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(({ data }) => {
                if (controller.signal.aborted) return;
                if (!collection(data)) throw new Error('Delivery collection unconfirmed');
                setResult({ key, value: data });
            })
            .catch((error) => {
                if (controller.signal.aborted) return;
                refused(error);
                setResult(null);
                setMessage('Delivery records could not be confirmed. Saved source acceptance remains separate.');
            })
            .finally(() => {
                if (!controller.signal.aborted) setBusy(false);
            });
        return () => controller.abort();
    }, [enabled, nonce, key, url]);

    const retry = (row: ExposureDeliveryAttempt) => {
        setRetrying(row.id);
        setOutcome(null);
        void axios
            .post(`${url}/${row.id}/retry`, {}, { headers: { Accept: 'application/json' } })
            .then(({ data }) => {
                const state = object(data) && object(data.inspection) && object(data.inspection.destination) ? data.inspection.destination.state : null;
                setOutcome(state === 'hs_event_present' ? 'Delivered. The exposure is on the H&S register.' : 'Retry sent. The result is shown below.');
            })
            .catch((error) => {
                if (refused(error)) return;
                const errors = axios.isAxiosError(error) && object(error.response?.data) && object(error.response.data.errors) ? error.response.data.errors : null;
                const first = errors && Array.isArray(errors.delivery) ? String(errors.delivery[0]) : null;
                setOutcome(first ? (BLOCKED[first] ?? first) : 'The retry wasn’t confirmed. Check the delivery records again before trying once more.');
            })
            .finally(() => {
                setRetrying(null);
                setNonce((value) => value + 1);
            });
    };

    if (!active) return null;
    if (!admitted || withdrawn) {
        return (
            <div role="status">
                <Note tone="warning">Delivery details and changes are withheld because current source access is unavailable.</Note>
            </div>
        );
    }
    return (
        <Surface
            aria-label="Delivery to the H&S register"
            title="Delivery to the H&S register"
            sub="Each save is sent to the H&S register automatically, straight after it’s saved. Nothing here sends email, SMS or push notifications."
        >
            <div className="grid gap-4 px-[18px] pt-3 pb-4">
                <div className="flex flex-wrap gap-3">
                    <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => setNonce((value) => value + 1)}>
                        Check delivery records
                    </Button>
                </div>
                {message && <p role="status" className="text-subtle text-status-warning">{message}</p>}
                {outcome && <p role="status" className="text-subtle">{outcome}</p>}
                {busy && <p role="status" className="text-subtle">Checking the delivery records…</p>}
                {current && current.attempts === null && (
                    <p className="text-subtle text-status-warning">Delivery history needs review. The collection is unavailable and its total is unconfirmed.</p>
                )}
                {current && current.source_integrity === 'legacy_unknown' && (
                    <p className="text-subtle text-status-warning">This exposure’s source attribution is unconfirmed, so these attempts are shown as recorded, not certified.</p>
                )}
                {current?.attempts && (!current.attempts.length ? (
                    <p className="text-subtle">No delivery attempts are recorded for this exposure.</p>
                ) : (
                    <>
                        <ul className="grid divide-y divide-border border-y border-border">
                            {current.attempts.map((row) => {
                                const state = stateOf(row);
                                const auto = row.kind === 'health_safety' ? row.automatic : undefined;
                                const blocked = !row.can.retry && row.blocked_reason ? BLOCKED[row.blocked_reason] : undefined;
                                return (
                                    <li key={row.id} className="grid gap-2 py-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h4 className="font-semibold">{row.kind === 'followup' ? 'Named follow-up notice' : 'H&S register'}</h4>
                                            <StatusBadge variant={state.variant}>{state.label}</StatusBadge>
                                        </div>
                                        <dl className="grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <dt className="text-caption text-muted-foreground">Saved version</dt>
                                                <dd className="text-subtle">{row.source_revision && row.source_revision > 0 ? row.source_revision : 'Unconfirmed'}</dd>
                                            </div>
                                            {auto && auto.failed_attempts > 0 ? (
                                                <div>
                                                    <dt className="text-caption text-muted-foreground">Failed attempts</dt>
                                                    <dd className="text-subtle">{auto.failed_attempts}</dd>
                                                </div>
                                            ) : null}
                                            {auto?.next_attempt_at ? (
                                                <div>
                                                    <dt className="text-caption text-muted-foreground">Next try</dt>
                                                    <dd className="text-subtle">{formatDateTime(auto.next_attempt_at)}</dd>
                                                </div>
                                            ) : null}
                                        </dl>
                                        {auto?.failure ? <p className="text-subtle">{FAILURE[auto.failure]}{auto.needs_manager ? ' It won’t be tried again automatically.' : ''}</p> : null}
                                        {blocked && row.attempt_state === 'pending' ? <p className="text-subtle">{blocked}</p> : null}
                                        {row.can.retry && row.destination.state !== 'hs_event_present' ? (
                                            <div>
                                                <Button type="button" size="sm" variant="outline" disabled={retrying !== null} onClick={() => retry(row)}>
                                                    {retrying === row.id ? 'Retrying…' : 'Retry delivery'}
                                                </Button>
                                            </div>
                                        ) : null}
                                    </li>
                                );
                            })}
                        </ul>
                        <p className="text-caption text-muted-foreground">Showing up to the 25 newest attempts.</p>
                    </>
                ))}
            </div>
        </Surface>
    );
}
