/* Current delivery checks for one private chemical exposure (drawing 129g).
 *
 * Reads the real `GET /health-safety/chemical-exposures/{id}/delivery-attempts` contract: up to the 25 newest
 * delivery attempts with their reported state, source revision and recorded confirmations, and a destination
 * confirmation that is always "not checked". The server has no destination-proof, inspect or retry endpoint, so
 * this panel offers none: it never sends, retries or claims that anyone received or completed the work.
 * GET never dispatches. A refused read (401/403/404) withdraws the private view, as before. */
import { Note, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

export type ExposureDeliveryAttempt = {
    id: number;
    kind: 'health_safety' | 'followup';
    reported_state: string;
    source_revision: number;
    attempts: number;
    can_retry: false;
};
export type ExposureDeliveryCollection = {
    state: 'recorded' | 'unconfirmed' | 'repair_required';
    destination_confirmation: 'not_checked';
    attempts: ExposureDeliveryAttempt[] | null;
};

const object = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
const count = (value: unknown): value is number => typeof value === 'number' && Number.isSafeInteger(value) && value >= 0;
function attempt(value: unknown): value is ExposureDeliveryAttempt {
    return object(value) && count(value.id) && value.id > 0 && (value.kind === 'health_safety' || value.kind === 'followup')
        && typeof value.reported_state === 'string' && count(value.source_revision) && count(value.attempts) && value.can_retry === false;
}
function collection(value: unknown): value is ExposureDeliveryCollection {
    return object(value) && ['recorded', 'unconfirmed', 'repair_required'].includes(String(value.state))
        && value.destination_confirmation === 'not_checked'
        && (value.attempts === null || (Array.isArray(value.attempts) && value.attempts.length <= 25 && value.attempts.every(attempt)))
        && (value.state !== 'repair_required' || value.attempts === null);
}

const REPORTED: Record<string, string> = {
    pending: 'Pending',
    delivered: 'Reported delivered · destination not checked',
    superseded: 'Earlier source revision',
    unconfirmed: 'Unconfirmed',
};

export function ExposureDeliveryPanel({ detail, actorId, active, onWithdraw }: { detail: ExposureWorkspaceDetail; actorId: number; active: boolean; onWithdraw?: () => void }) {
    const admitted = detail.exposure_access === 'readable' && detail.record?.id === detail.id && detail.source_integrity !== 'repair_required' && detail.record.attribution_state !== 'repair_required';
    const [nonce, setNonce] = useState(0);
    const [result, setResult] = useState<{ key: string; value: ExposureDeliveryCollection } | null>(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState<string | null>(null);
    const [withdrawn, setWithdrawn] = useState(false);
    const onDenied = useRef(onWithdraw);
    useEffect(() => {
        onDenied.current = onWithdraw;
    }, [onWithdraw]);
    // A result belongs to one account, record, revision and permission; any change drops it.
    const key = `${actorId}:${detail.id}:${detail.command_context?.expected_version ?? 'unknown'}:${detail.can.correct}`;
    const enabled = active && admitted && !withdrawn;
    const current = enabled && result?.key === key ? result.value : null;
    const url = `${CHEMICAL_EXPOSURES}/${detail.id}/delivery-attempts`;
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
                if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) {
                    setWithdrawn(true);
                    onDenied.current?.();
                }
                setResult(null);
                setMessage('Delivery records could not be confirmed. Saved source acceptance remains separate.');
            })
            .finally(() => {
                if (!controller.signal.aborted) setBusy(false);
            });
        return () => controller.abort();
    }, [enabled, nonce, key, url]);

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
            aria-label="Current delivery checks"
            title="Current delivery checks"
            sub="Source acceptance, a destination record, an inbox read flag and completed work are separate. These checks do not send email, SMS or push notifications."
        >
            <div className="grid gap-4 px-[18px] pt-3 pb-4">
                <div className="flex flex-wrap gap-3">
                    <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => setNonce((value) => value + 1)}>
                        Check delivery records
                    </Button>
                </div>
                {message && <p role="status" className="text-subtle text-status-warning">{message}</p>}
                {busy && <p role="status" className="text-subtle">Checking the retained delivery attempts…</p>}
                {current && current.state === 'repair_required' && (
                    <p className="text-subtle text-status-warning">Delivery history needs review. The collection is unavailable and its total is unconfirmed.</p>
                )}
                {current && current.state === 'unconfirmed' && (
                    <p className="text-subtle text-status-warning">This exposure’s source attribution is unconfirmed, so these attempts are shown as recorded, not certified.</p>
                )}
                {current?.attempts && (!current.attempts.length ? (
                    <p className="text-subtle">No delivery attempts are recorded for this exposure.</p>
                ) : (
                    <>
                        <ul className="grid divide-y divide-border border-y border-border">
                            {current.attempts.map((row) => (
                                <li key={row.id} className="grid gap-2 py-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h4 className="font-semibold">{row.kind === 'health_safety' ? 'Health & Safety' : 'Named follow-up'} delivery</h4>
                                        <StatusBadge variant="neutral">Destination not checked</StatusBadge>
                                    </div>
                                    <dl className="grid gap-3 sm:grid-cols-3">
                                        <div>
                                            <dt className="text-caption text-muted-foreground">Source revision</dt>
                                            <dd className="text-subtle">{row.source_revision > 0 ? row.source_revision : 'Unconfirmed'}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption text-muted-foreground">Reported attempt state</dt>
                                            <dd className="text-subtle">{REPORTED[row.reported_state] ?? 'Unconfirmed'}</dd>
                                        </div>
                                        <div>
                                            <dt className="text-caption text-muted-foreground">Recorded confirmations</dt>
                                            <dd className="text-subtle">{row.attempts}</dd>
                                        </div>
                                    </dl>
                                    {row.kind === 'followup' && (
                                        <p className="text-subtle">Inbox presence or a read flag does not record follow-up completion. Review the named work in Follow-ups.</p>
                                    )}
                                    <p className="text-subtle">This counter does not include every failed or interrupted retry.</p>
                                </li>
                            ))}
                        </ul>
                        <p className="text-caption text-muted-foreground">
                            Showing up to the 25 newest attempts. Whether each destination received the work is not checked here.
                        </p>
                    </>
                ))}
            </div>
        </Surface>
    );
}
