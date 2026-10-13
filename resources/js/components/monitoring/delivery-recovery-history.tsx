import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { History, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    confirmedRecovery,
    type RecoveryAcknowledgement,
    type RecoveryAttempt,
} from './delivery-recovery-data';
import { deliveryLabel } from './delivery-recovery-records';
function attempt(row: RecoveryAcknowledgement): RecoveryAttempt {
    return {
        uuid: row.receipt.request_uuid,
        actor: row.receipt.actor_id,
        site: row.receipt.site_id,
        letter: row.receipt.dead_letter_id,
        operation: row.receipt.operation,
    };
}
function validRow(value: unknown, actor: number): RecoveryAcknowledgement {
    const row = value as RecoveryAcknowledgement,
        r = row?.receipt;
    if (
        !r ||
        r.actor_id !== actor ||
        !Number.isSafeInteger(r.dead_letter_id) ||
        r.dead_letter_id < 1 ||
        (r.site_id !== null &&
            (!Number.isSafeInteger(r.site_id) || r.site_id < 1)) ||
        !['replay', 'discard'].includes(r.operation) ||
        !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
            r.request_uuid,
        )
    )
        throw Error('Saved decision identity unavailable.');
    return confirmedRecovery(row, attempt(row));
}
export function DeliveryRecoveryHistory({
    open,
    actorId,
    onOpenChange,
}: {
    open: boolean;
    actorId: number;
    onOpenChange: (open: boolean) => void;
}) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, boolean> };
    }>().props;
    return open &&
        auth?.user?.id === actorId &&
        auth.can?.['securityDevices.integrations.manage'] === true ? (
        <HistoryBody
            key={JSON.stringify([actorId, auth.can])}
            actor={actorId}
            onClose={() => onOpenChange(false)}
        />
    ) : null;
}
function HistoryBody({
    actor,
    onClose,
}: {
    actor: number;
    onClose: () => void;
}) {
    const [rows, setRows] = useState<RecoveryAcknowledgement[]>([]),
        [cursor, setCursor] = useState<number | null>(null),
        [busy, setBusy] = useState(false),
        [notice, setNotice] = useState(''),
        [saved, setSaved] = useState<RecoveryAcknowledgement | null>(null);
    const live = useRef(true),
        sending = useRef(false),
        call = useRef<AbortController | null>(null);
    const unavailable = useCallback((message: string) => {
        setRows([]);
        setCursor(null);
        setSaved(null);
        setNotice(message);
    }, []);
    const load = useCallback(
        async (before?: number) => {
            if (sending.current) return;
            sending.current = true;
            setBusy(true);
            setNotice('');
            const controller = new AbortController();
            call.current = controller;
            try {
                const response = await axios.get<unknown>(
                    '/security-devices/monitoring/dead-letters/requests',
                    {
                        params: {
                            expected_actor_id: actor,
                            ...(before ? { before_id: before } : {}),
                        },
                        signal: controller.signal,
                        timeout: 30000,
                        headers: { Accept: 'application/json' },
                    },
                );
                if (!live.current || controller.signal.aborted) return;
                const data = response.data as {
                    actor_id: number;
                    rows: unknown[];
                    next_before_id: number | null;
                };
                if (
                    data?.actor_id !== actor ||
                    !Array.isArray(data.rows) ||
                    data.rows.length > 25 ||
                    (data.next_before_id !== null &&
                        (!Number.isSafeInteger(data.next_before_id) ||
                            data.next_before_id <= 0 ||
                            (before !== undefined &&
                                data.next_before_id >= before)))
                )
                    throw Error('Saved recovery decisions unavailable.');
                const next = data.rows.map((row) => validRow(row, actor));
                setRows((previous) => (before ? [...previous, ...next] : next));
                setCursor(data.next_before_id);
            } catch {
                if (live.current && !controller.signal.aborted)
                    unavailable(
                        'Saved recovery decisions are unavailable in your current access. Refresh Monitoring before another decision.',
                    );
            } finally {
                if (call.current === controller) {
                    sending.current = false;
                    if (live.current && !controller.signal.aborted)
                        setBusy(false);
                }
            }
        },
        [actor, unavailable],
    );
    useEffect(() => {
        live.current = true;
        void load();
        return () => {
            live.current = false;
            call.current?.abort();
            sending.current = false;
        };
    }, [load]);
    async function verify(row: RecoveryAcknowledgement) {
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setNotice('');
        const controller = new AbortController();
        call.current = controller;
        const selected = attempt(row);
        try {
            const response = await axios.post<unknown>(
                `/security-devices/monitoring/dead-letters/requests/${selected.uuid}/recover`,
                { expected_actor_id: actor, expected_site_id: selected.site },
                {
                    signal: controller.signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (!live.current || controller.signal.aborted) return;
            setSaved(confirmedRecovery(response.data, selected));
        } catch {
            if (live.current && !controller.signal.aborted)
                unavailable(
                    'This saved decision is unavailable or could not be confirmed. No recovery action was repeated.',
                );
        } finally {
            if (call.current === controller) {
                sending.current = false;
                if (live.current && !controller.signal.aborted) setBusy(false);
            }
        }
    }
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent className="max-h-[90vh] w-[94vw] overflow-x-hidden overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Saved recovery decisions</DialogTitle>
                    <DialogDescription>
                        Find your original decision after an interrupted
                        request, closed dialog or reload. Checking a saved
                        decision does not repeat it.
                    </DialogDescription>
                </DialogHeader>
                {notice ? (
                    <p role="alert" className="text-sm text-destructive">
                        {notice}
                    </p>
                ) : null}
                {saved ? (
                    <>
                        <ReviewCard
                            title="Original recorded decision"
                            icon={ShieldCheck}
                        >
                            <ReviewRow
                                label="Failure"
                                value={`#${saved.receipt.dead_letter_id}`}
                            />
                            <ReviewRow
                                label="Message"
                                value={saved.decision.message_reference}
                            />
                            <ReviewRow
                                label="Decision"
                                value={
                                    saved.receipt.operation === 'replay'
                                        ? 'Replay requested'
                                        : 'Discard from processing'
                                }
                            />
                            <ReviewRow
                                label="Recorded"
                                value={formatDateTime(
                                    saved.receipt.committed_at,
                                )}
                            />
                        </ReviewCard>
                        <ReviewCard title="Current processing" icon={History}>
                            <ReviewRow
                                label="State"
                                value={deliveryLabel(saved.current.state)}
                            />
                            <ReviewRow
                                label="Replay count"
                                value={saved.current.replay_count}
                            />
                            <ReviewRow
                                label="Resolved"
                                value={
                                    saved.current.resolved_at
                                        ? formatDateTime(
                                              saved.current.resolved_at,
                                          )
                                        : 'Not resolved'
                                }
                            />
                        </ReviewCard>
                        <p className="text-sm text-muted-foreground">
                            The decision and processing state are separate.
                            Device commands are not replayed.
                        </p>
                        <Button
                            variant="outline"
                            onClick={() => setSaved(null)}
                        >
                            Back to decisions
                        </Button>
                    </>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Only your own recorded decisions within current
                            permitted access appear.
                        </p>
                        {!busy && !rows.length && !notice ? (
                            <p>
                                No saved recovery decisions are available in
                                your current scope.
                            </p>
                        ) : null}
                        <div className="divide-y rounded-lg border">
                            {rows.map((row) => (
                                <div
                                    key={row.receipt.request_uuid}
                                    className="flex min-h-[50px] items-center gap-3 px-3 py-2"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            Failure #
                                            {row.receipt.dead_letter_id} ·
                                            Message{' '}
                                            {row.decision.message_reference}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {row.receipt.operation === 'replay'
                                                ? 'Replay requested'
                                                : 'Discard recorded'}{' '}
                                            ·{' '}
                                            {formatDateTime(
                                                row.receipt.committed_at,
                                            )}
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-label={`Check recorded decision for failure ${row.receipt.dead_letter_id}`}
                                        disabled={busy}
                                        onClick={() => void verify(row)}
                                    >
                                        Check
                                    </Button>
                                </div>
                            ))}
                        </div>
                        {cursor ? (
                            <Button
                                variant="outline"
                                disabled={busy}
                                onClick={() => void load(cursor)}
                            >
                                Load earlier decisions
                            </Button>
                        ) : null}
                    </>
                )}
                {busy ? (
                    <p role="status" className="text-sm text-muted-foreground">
                        Checking saved decisions…
                    </p>
                ) : null}
                <Button onClick={onClose}>Done</Button>
            </DialogContent>
        </Dialog>
    );
}
