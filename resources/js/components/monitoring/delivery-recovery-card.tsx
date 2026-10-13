import type { EvidenceRecordsView } from '@/components/lists/evidence-records';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ArchiveX,
    History,
    Loader2,
    RefreshCw,
    ShieldCheck,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    confirmedRecovery,
    type RecoveryAcknowledgement,
    type RecoveryAttempt,
} from './delivery-recovery-data';
import { DeliveryRecoveryHistory } from './delivery-recovery-history';
import {
    DeliveryContracts,
    DeliveryFailureRecords,
    deliveryLabel,
    type DeliveryRecoveryRow,
} from './delivery-recovery-records';
export type DeliveryRecovery = {
    actor_id?: number;
    contracts: {
        envelope_current: number;
        envelope_accepted: number[];
        payloads: Record<string, { current: number; accepted: number[] }>;
        commands: {
            standard_current?: number;
            break_glass_current?: number;
            accepted?: number[];
            retry_policy?: string;
        };
    };
    dead_letters: {
        visible: boolean;
        total: number | null;
        shown: number;
        truncated: boolean;
        note: string;
        rows: Array<{
            id: number;
            review_token?: string;
            reason_code: string;
            reason_message: string;
            consumer: string;
            message_reference: string;
            site: { id: number; name: string; href: string } | null;
            replay_count: number;
            created_at: string | null;
            schema_version: number | null;
            payload_version: number | null;
            message_type: string | null;
            can_replay: boolean;
            can_discard: boolean;
            pending_replay: boolean;
            operator_note: string;
        }>;
    };
};

type Action = { row: DeliveryRecoveryRow; kind: 'replay' | 'discard' };
function RecoveryDialog({
    action,
    actor,
    onClose,
    onRecorded,
}: {
    action: Action;
    actor: number;
    onClose: () => void;
    onRecorded: () => void;
}) {
    const [reason, setReason] = useState(''),
        [state, setState] = useState<
            'idle' | 'busy' | 'uncertain' | 'denied' | 'conflict' | 'saved'
        >('idle'),
        [notice, setNotice] = useState(''),
        [error, setError] = useState(''),
        [saved, setSaved] = useState<RecoveryAcknowledgement | null>(null);
    const live = useRef(true),
        sending = useRef(false),
        call = useRef<AbortController | null>(null),
        attempt = useRef<RecoveryAttempt | null>(null),
        notified = useRef(false);
    useEffect(() => {
        live.current = true;
        return () => {
            live.current = false;
            call.current?.abort();
        };
    }, []);
    const busy = state === 'busy',
        blocked = state !== 'idle';
    const guard = useSettingsLeaveConfirmation(
        !saved && (reason.trim().length > 0 || busy || state === 'uncertain'),
        'Discard this recovery draft?',
        'Your operational reason will be cleared. A request already sent may still finish. Saved recovery decisions remain available from Monitoring. Cancel to keep editing.',
    );
    const done = () => {
        if (notified.current) return;
        notified.current = true;
        onClose();
        onRecorded();
    };
    const close = () => {
        if (busy) return;
        if (saved) {
            done();
            return;
        }
        guard.request(onClose);
    };
    async function send(recovering = false) {
        if (
            sending.current ||
            !live.current ||
            (!recovering && state !== 'idle')
        )
            return;
        if (
            !recovering &&
            (reason.trim().length < 3 ||
                reason.trim().length > 500 ||
                [...reason].some(
                    (c) => c.charCodeAt(0) < 32 && c !== '\n' && c !== '\r',
                ))
        ) {
            setError('Record an operational reason of 3 to 500 characters.');
            return;
        }
        const next = recovering
            ? attempt.current
            : {
                  uuid: crypto.randomUUID(),
                  actor,
                  site: action.row.site?.id ?? null,
                  letter: action.row.id,
                  operation: action.kind,
              };
        if (!next) return;
        attempt.current = next;
        sending.current = true;
        setState('busy');
        setNotice('');
        setError('');
        const controller = new AbortController();
        call.current = controller;
        try {
            const options = {
                signal: controller.signal,
                timeout: 30000,
                headers: { Accept: 'application/json' },
            };
            const response = recovering
                ? await axios.post<unknown>(
                      `/security-devices/monitoring/dead-letters/requests/${next.uuid}/recover`,
                      { expected_actor_id: actor, expected_site_id: next.site },
                      options,
                  )
                : await axios.post<unknown>(
                      `/security-devices/monitoring/dead-letters/${next.letter}/${next.operation}`,
                      {
                          request_uuid: next.uuid,
                          expected_actor_id: actor,
                          expected_site_id: next.site,
                          review_token: action.row.review_token,
                          reason: reason.trim(),
                      },
                      options,
                  );
            if (!live.current || controller.signal.aborted) return;
            setSaved(confirmedRecovery(response.data, next));
            setState('saved');
        } catch (e) {
            if (!live.current || controller.signal.aborted) return;
            const status = axios.isAxiosError(e)
                ? e.response?.status
                : undefined;
            if (!recovering && status === 422) {
                const message = axios.isAxiosError(e)
                    ? e.response?.data?.errors?.reason?.[0]
                    : null;
                setError(
                    typeof message === 'string'
                        ? message.slice(0, 500)
                        : 'Review your operational reason.',
                );
                setState('idle');
            } else if (!recovering && [401, 403, 404].includes(status ?? 0)) {
                setNotice(
                    'Current access or the reviewed record is unavailable. Your reason is still here. Refresh Monitoring before another decision.',
                );
                setState('denied');
            } else if (!recovering && status === 409) {
                setNotice(
                    'This record or request changed after review. Your reason is still here. Refresh Monitoring before another decision.',
                );
                setState('conflict');
            } else {
                setNotice(
                    recovering
                        ? 'The saved request is unavailable in your current scope or may still be finishing. No recovery action was repeated.'
                        : 'The decision could not be confirmed. It may have been recorded. Check the saved request before another decision.',
                );
                setState('uncertain');
            }
        } finally {
            sending.current = false;
            if (live.current && !controller.signal.aborted) call.current = null;
        }
    }
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent className="max-h-[90vh] w-[94vw] overflow-x-hidden overflow-y-auto sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {saved
                                ? action.kind === 'replay'
                                    ? 'Replay request recorded'
                                    : 'Discard recorded'
                                : action.kind === 'replay'
                                  ? 'Replay monitoring evidence'
                                  : 'Discard from processing'}
                        </DialogTitle>
                        <DialogDescription>
                            {saved
                                ? 'The original decision is retained. Check processing separately.'
                                : action.kind === 'replay'
                                  ? 'Replay consumes the original signed evidence. Device commands are not repeated.'
                                  : 'Processing stops and the original evidence remains retained with your reason.'}
                        </DialogDescription>
                    </DialogHeader>
                    <ReviewCard title="Reviewed delivery record" icon={History}>
                        <ReviewRow
                            label="Failure"
                            value={`#${action.row.id}`}
                        />
                        <ReviewRow
                            label="Message"
                            value={action.row.message_reference}
                        />
                        <ReviewRow
                            label="Consumer"
                            value={deliveryLabel(action.row.consumer)}
                        />
                        <ReviewRow
                            label="Site"
                            value={
                                action.row.site?.name ??
                                'Application-wide intake'
                            }
                        />
                    </ReviewCard>
                    {saved ? (
                        <>
                            <ReviewCard
                                title="Recorded decision"
                                icon={ShieldCheck}
                            >
                                <ReviewRow
                                    label="Decision"
                                    value={
                                        saved.receipt.operation === 'replay'
                                            ? 'Replay requested'
                                            : 'Discard from processing'
                                    }
                                />
                                <ReviewRow
                                    label="Current processing"
                                    value={deliveryLabel(saved.current.state)}
                                />
                                <ReviewRow
                                    label="Replay count"
                                    value={saved.current.replay_count}
                                />
                            </ReviewCard>
                            <DialogFooter>
                                <Button onClick={done}>Done</Button>
                            </DialogFooter>
                        </>
                    ) : (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="monitoring-recovery-reason">
                                    Operational reason
                                </Label>
                                <Textarea
                                    id="monitoring-recovery-reason"
                                    value={reason}
                                    maxLength={500}
                                    rows={3}
                                    disabled={blocked}
                                    onChange={(e) => {
                                        if (!blocked) {
                                            setReason(e.target.value);
                                            setError('');
                                        }
                                    }}
                                    placeholder="Record what you checked and why this decision is appropriate."
                                />
                                <p className="text-xs text-muted-foreground">
                                    Keep passwords, tokens, raw payloads and
                                    personal information out of the reason.
                                </p>
                                {error ? (
                                    <p
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {error}
                                    </p>
                                ) : null}
                                {notice ? (
                                    <p
                                        role="alert"
                                        className="text-sm text-muted-foreground"
                                    >
                                        {notice}
                                    </p>
                                ) : null}
                                {state === 'uncertain' ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => void send(true)}
                                    >
                                        Check saved request
                                    </Button>
                                ) : null}
                            </div>
                            <DialogFooter>
                                <Button
                                    variant="outline"
                                    onClick={close}
                                    disabled={busy}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    variant={
                                        action.kind === 'replay'
                                            ? 'default'
                                            : 'destructive'
                                    }
                                    disabled={blocked}
                                    onClick={() => void send()}
                                >
                                    {busy ? (
                                        <Loader2 className="size-4 animate-spin" />
                                    ) : action.kind === 'replay' ? (
                                        <RefreshCw className="size-4" />
                                    ) : (
                                        <ArchiveX className="size-4" />
                                    )}
                                    {busy
                                        ? 'Recording…'
                                        : action.kind === 'replay'
                                          ? 'Request replay'
                                          : 'Discard and retain evidence'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </DialogContent>
            </Dialog>
            {guard.confirmation}
        </>
    );
}
export function DeliveryRecoveryCard({
    delivery,
    view = 'cards',
}: {
    delivery: DeliveryRecovery;
    view?: EvidenceRecordsView;
}) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, boolean> };
    }>().props;
    const permitted =
        auth?.can?.['securityDevices.integrations.manage'] === true &&
        delivery.actor_id === auth?.user?.id &&
        delivery.dead_letters.visible;
    return (
        <DeliveryBody
            key={JSON.stringify([auth?.user?.id, auth?.can, delivery])}
            delivery={delivery}
            view={view}
            actor={auth?.user?.id ?? 0}
            permitted={permitted}
            profiles={auth?.can?.['securityDevices.devices.view'] === true}
        />
    );
}
function DeliveryBody({
    delivery,
    view,
    actor,
    permitted,
    profiles,
}: {
    delivery: DeliveryRecovery;
    view: EvidenceRecordsView;
    actor: number;
    permitted: boolean;
    profiles: boolean;
}) {
    const [action, setAction] = useState<Action | null>(null),
        [history, setHistory] = useState(false);
    const refresh = () => router.reload({ only: ['workspace'] });
    return (
        <div data-test="monitoring-delivery-recovery" className="space-y-5">
            <DeliveryContracts delivery={delivery} view={view} />
            {permitted ? (
                <>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            {delivery.dead_letters.shown} loaded failures shown
                            {delivery.dead_letters.truncated
                                ? '; earlier failures may also need a decision.'
                                : '.'}
                        </p>
                        <Button
                            variant="outline"
                            onClick={() => setHistory(true)}
                        >
                            Saved recovery decisions
                        </Button>
                    </div>
                    <DeliveryFailureRecords
                        delivery={delivery}
                        view={view}
                        profiles={profiles}
                        onAct={(row, kind) => {
                            if (
                                Number.isSafeInteger(row.id) &&
                                row.id > 0 &&
                                /^[0-9a-f]{64}$/.test(row.review_token ?? '') &&
                                !row.pending_replay &&
                                (kind === 'replay'
                                    ? row.can_replay
                                    : row.can_discard)
                            )
                                setAction({ row, kind });
                        }}
                    />
                    {action ? (
                        <RecoveryDialog
                            key={JSON.stringify(action)}
                            action={action}
                            actor={actor}
                            onClose={() => setAction(null)}
                            onRecorded={refresh}
                        />
                    ) : null}
                    <DeliveryRecoveryHistory
                        open={history}
                        actorId={actor}
                        onOpenChange={setHistory}
                    />
                </>
            ) : (
                <div className="space-y-2">
                    <StatusBadge variant="neutral">
                        Recovery restricted
                    </StatusBadge>
                    <p role="note" className="text-sm text-muted-foreground">
                        Recovery details require current integration management
                        access. Refresh Monitoring if your access context
                        changed.
                    </p>
                </div>
            )}
        </div>
    );
}
