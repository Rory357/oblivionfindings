import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { History, ShieldCheck } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    confirmedNativeMonitor,
    type NativeMonitorAcknowledgement,
    type NativeMonitorAttempt,
} from './native-monitor-wizard-data';

function requestAttempt(
    row: NativeMonitorAcknowledgement,
): NativeMonitorAttempt {
    const r = row.receipt,
        m = row.monitor;
    return {
        uuid: r.request_uuid,
        actor: r.actor_id,
        site: r.site_id,
        device: r.device_id,
        operation: r.operation as NativeMonitorAttempt['operation'],
        target: r.target_monitor_id,
        payload: {},
        expected: {
            name: m.name,
            kind: m.kind,
            profile: m.profile_id,
            availability: m.affects_availability,
            enabled: m.enabled,
        },
    };
}
function validRow(value: unknown, actor: number): NativeMonitorAcknowledgement {
    const row = value as NativeMonitorAcknowledgement,
        r = row?.receipt;
    if (
        !r ||
        r.actor_id !== actor ||
        !['create', 'update', 'deactivate'].includes(r.operation) ||
        !/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
            r.request_uuid,
        ) ||
        !Number.isSafeInteger(r.site_id) ||
        r.site_id < 1 ||
        !Number.isSafeInteger(r.device_id) ||
        r.device_id < 1 ||
        (r.operation === 'create'
            ? r.target_monitor_id !== null
            : r.target_monitor_id !== r.monitor_id)
    )
        throw Error('Saved request identity unavailable.');
    return confirmedNativeMonitor(row, requestAttempt(row));
}
export function NativeMonitorSavedRequests({
    open,
    onOpenChange,
    actorId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    actorId: number;
}) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, boolean> };
    }>().props;
    if (!open) return null;
    if (
        auth?.user?.id !== actorId ||
        auth.can?.['securityDevices.monitoring.manage'] !== true
    )
        return null;
    return (
        <SavedRequestBody
            key={JSON.stringify([actorId, auth.can])}
            actorId={actorId}
            onClose={() => onOpenChange(false)}
        />
    );
}
function SavedRequestBody({
    actorId,
    onClose,
}: {
    actorId: number;
    onClose: () => void;
}) {
    const [rows, setRows] = useState<NativeMonitorAcknowledgement[]>([]),
        [cursor, setCursor] = useState<number | null>(null),
        [busy, setBusy] = useState(false),
        [notice, setNotice] = useState(''),
        [confirmed, setConfirmed] =
            useState<NativeMonitorAcknowledgement | null>(null);
    const live = useRef(true),
        call = useRef<AbortController | null>(null),
        sending = useRef(false);
    const load = useCallback(
        async (beforeId?: number) => {
            if (sending.current) return;
            sending.current = true;
            setBusy(true);
            setNotice('');
            const controller = new AbortController();
            call.current = controller;
            try {
                const response = await axios.get<unknown>(
                    '/security-devices/monitoring/native-monitors/requests',
                    {
                        params: {
                            expected_actor_id: actorId,
                            ...(beforeId ? { before_id: beforeId } : {}),
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
                    data?.actor_id !== actorId ||
                    !Array.isArray(data.rows) ||
                    data.rows.length > 25 ||
                    (data.next_before_id !== null &&
                        (!Number.isSafeInteger(data.next_before_id) ||
                            data.next_before_id <= 0 ||
                            (beforeId !== undefined &&
                                data.next_before_id >= beforeId)))
                )
                    throw Error('Saved request list unavailable.');
                const next = data.rows.map((row) => validRow(row, actorId));
                setRows((previous) =>
                    beforeId ? [...previous, ...next] : next,
                );
                setCursor(data.next_before_id);
            } catch {
                if (live.current && !controller.signal.aborted) {
                    setRows([]);
                    setCursor(null);
                    setConfirmed(null);
                    setNotice(
                        'Saved changes are unavailable in your current access. Refresh Monitoring before another save.',
                    );
                }
            } finally {
                sending.current = false;
                if (live.current && !controller.signal.aborted) setBusy(false);
            }
        },
        [actorId],
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

    async function verify(row: NativeMonitorAcknowledgement) {
        if (sending.current) return;
        sending.current = true;
        setBusy(true);
        setNotice('');
        const controller = new AbortController();
        call.current = controller;
        const attempt = requestAttempt(row);
        try {
            const response = await axios.post<unknown>(
                `/security-devices/monitoring/native-monitors/requests/${attempt.uuid}/recover`,
                {
                    expected_actor_id: actorId,
                    expected_site_id: attempt.site,
                    expected_device_id: attempt.device,
                },
                {
                    signal: controller.signal,
                    timeout: 30000,
                    headers: { Accept: 'application/json' },
                },
            );
            if (!live.current || controller.signal.aborted) return;
            setConfirmed(confirmedNativeMonitor(response.data, attempt));
        } catch {
            if (live.current && !controller.signal.aborted) {
                setRows([]);
                setCursor(null);
                setConfirmed(null);
                setNotice(
                    'This saved request is unavailable in your current access, or it could not be confirmed. No monitor change was repeated.',
                );
            }
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
            onOpenChange={(value) => {
                if (!value) onClose();
            }}
        >
            <DialogContent className="max-h-[90vh] w-[94vw] overflow-x-hidden overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Your saved monitor changes</DialogTitle>
                    <DialogDescription>
                        Find the original result after an interrupted save,
                        closed dialog or reload. Checking a saved request does
                        not repeat the change.
                    </DialogDescription>
                </DialogHeader>
                {notice ? (
                    <p role="alert" className="text-sm text-destructive">
                        {notice}
                    </p>
                ) : null}
                {confirmed ? (
                    <>
                        <ReviewCard
                            title="Original saved result"
                            icon={ShieldCheck}
                        >
                            <ReviewRow
                                label="Monitor"
                                value={`${confirmed.monitor.name} (#${confirmed.monitor.id})`}
                            />
                            <ReviewRow
                                label="Change"
                                value={confirmed.receipt.operation}
                            />
                            <ReviewRow
                                label="Scheduling"
                                value={
                                    confirmed.monitor.enabled
                                        ? 'Enabled'
                                        : 'Disabled'
                                }
                            />
                            <ReviewRow
                                label="Saved at"
                                value={new Date(
                                    confirmed.receipt.committed_at,
                                ).toLocaleString('en-NZ')}
                            />
                            <ReviewRow
                                label="Current configuration"
                                value={
                                    confirmed.receipt.current_matches_saved
                                        ? 'Matches this saved result'
                                        : 'Changed after this result was saved'
                                }
                            />
                        </ReviewCard>
                        {!confirmed.receipt.current_matches_saved ? (
                            <ReviewCard title="Current monitor" icon={History}>
                                <ReviewRow
                                    label="Name"
                                    value={confirmed.current_monitor.name}
                                />
                                <ReviewRow
                                    label="Scheduling"
                                    value={
                                        confirmed.current_monitor.enabled
                                            ? 'Enabled'
                                            : 'Disabled'
                                    }
                                />
                            </ReviewCard>
                        ) : null}
                        <p className="text-sm text-muted-foreground">
                            This confirms configuration only. Device
                            observations are still needed to establish health.
                        </p>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmed(null)}
                        >
                            Back to saved changes
                        </Button>
                    </>
                ) : (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Only your own committed changes within currently
                            approved Sites and devices appear.
                        </p>
                        {!busy && !rows.length && !notice ? (
                            <p>
                                No saved monitor changes are available in your
                                current scope.
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
                                            {row.monitor.name} · #
                                            {row.monitor.id}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {row.receipt.operation} ·{' '}
                                            {new Date(
                                                row.receipt.committed_at,
                                            ).toLocaleString('en-NZ')}
                                        </p>
                                    </div>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={busy}
                                        aria-label={`Check saved request for monitor ${row.monitor.id}`}
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
                                Load earlier changes
                            </Button>
                        ) : null}
                    </>
                )}
                {busy ? (
                    <p role="status" className="text-sm text-muted-foreground">
                        Checking saved requests…
                    </p>
                ) : null}
                <Button onClick={onClose}>Done</Button>
            </DialogContent>
        </Dialog>
    );
}
