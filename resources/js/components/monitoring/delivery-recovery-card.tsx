import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Link, router } from '@inertiajs/react';
import { ArchiveX, RefreshCw, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

export type DeliveryRecovery = {
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

type RecoveryAction = {
    row: DeliveryRecovery['dead_letters']['rows'][number];
    kind: 'replay' | 'discard';
};

const label = (value: string) =>
    value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());

function accepted(values: number[]): string {
    return values.length ? values.join(', ') : 'None';
}

function RecoveryDialog({
    action,
    onClose,
}: {
    action: RecoveryAction | null;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        setReason('');
        setError(null);
        setProcessing(false);
    }, [action?.kind, action?.row.id]);

    if (!action) return null;

    const isReplay = action.kind === 'replay';
    const submit = () => {
        if (reason.trim().length < 3) {
            setError('Record a short operational reason.');
            return;
        }

        setProcessing(true);
        setError(null);
        router.post(
            `/security-devices/monitoring/dead-letters/${action.row.id}/${action.kind}`,
            { reason: reason.trim() },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (errors) => {
                    setError(
                        typeof errors.reason === 'string'
                            ? errors.reason
                            : 'The recovery action could not be completed.',
                    );
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {isReplay
                            ? 'Replay signed monitoring evidence'
                            : 'Discard from processing'}
                    </DialogTitle>
                    <DialogDescription>
                        {isReplay
                            ? 'The original immutable bytes will be consumed again. This never re-runs a device command.'
                            : 'Processing stops, but the exact evidence and your reason remain in the audit record.'}
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2 py-2">
                    <Label htmlFor="monitoring-recovery-reason">
                        Operational reason
                    </Label>
                    <Textarea
                        id="monitoring-recovery-reason"
                        value={reason}
                        maxLength={500}
                        rows={3}
                        placeholder={
                            isReplay
                                ? 'For example: missing sequence restored and verified'
                                : 'For example: invalid payload confirmed and source corrected'
                        }
                        onChange={(event) => setReason(event.target.value)}
                    />
                    <p className="text-xs text-muted-foreground">
                        Do not include passwords, tokens, raw payloads, or
                        personal information.
                    </p>
                    {error ? (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant={isReplay ? 'default' : 'destructive'}
                        onClick={submit}
                        disabled={processing}
                    >
                        {isReplay ? (
                            <RefreshCw className="mr-2 h-4 w-4" />
                        ) : (
                            <ArchiveX className="mr-2 h-4 w-4" />
                        )}
                        {processing
                            ? 'Recording…'
                            : isReplay
                              ? 'Queue replay'
                              : 'Discard and retain evidence'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function DeliveryRecoveryCard({
    delivery,
}: {
    delivery: DeliveryRecovery;
}) {
    const [action, setAction] = useState<RecoveryAction | null>(null);
    const observation = delivery.contracts.payloads.observation;
    const commands = delivery.contracts.commands;

    return (
        <Card data-test="monitoring-delivery-recovery">
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle>Delivery contracts & recovery</CardTitle>
                        <CardDescription>
                            Version compatibility, poison-message recovery and
                            immutable replay evidence.
                        </CardDescription>
                    </div>
                    <StatusBadge
                        variant={
                            delivery.dead_letters.total
                                ? 'warning'
                                : delivery.dead_letters.visible
                                  ? 'success'
                                  : 'neutral'
                        }
                    >
                        {delivery.dead_letters.total === null
                            ? 'Recovery restricted'
                            : `${delivery.dead_letters.shown}${delivery.dead_letters.truncated ? '+' : ''} waiting`}
                    </StatusBadge>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid gap-3 md:grid-cols-3">
                    <div className="rounded-xl border p-3">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Transport envelope
                        </p>
                        <p className="mt-1 font-semibold">
                            Current v{delivery.contracts.envelope_current}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Accepts v
                            {accepted(delivery.contracts.envelope_accepted)}
                        </p>
                    </div>
                    <div className="rounded-xl border p-3">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Observation & event payloads
                        </p>
                        <p className="mt-1 font-semibold">
                            Current v{observation?.current ?? '—'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Accepts v{accepted(observation?.accepted ?? [])}
                        </p>
                    </div>
                    <div className="rounded-xl border p-3">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Device commands
                        </p>
                        <p className="mt-1 font-semibold">
                            Standard v{commands.standard_current ?? '—'} · Break
                            glass v{commands.break_glass_current ?? '—'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Reconcile actual state before any retry
                        </p>
                    </div>
                </div>

                <p className="text-sm text-muted-foreground">
                    {delivery.dead_letters.note}
                </p>

                {delivery.dead_letters.visible ? (
                    delivery.dead_letters.rows.length ? (
                        <div className="space-y-3">
                            {delivery.dead_letters.rows.map((row) => (
                                <article
                                    key={row.id}
                                    className="rounded-xl border p-4"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <strong>
                                                    {label(row.reason_code)}
                                                </strong>
                                                <StatusBadge
                                                    variant={
                                                        row.pending_replay
                                                            ? 'info'
                                                            : 'warning'
                                                    }
                                                >
                                                    {row.pending_replay
                                                        ? 'Replay queued'
                                                        : 'Needs decision'}
                                                </StatusBadge>
                                                {row.message_type ? (
                                                    <StatusBadge variant="neutral">
                                                        {label(
                                                            row.message_type,
                                                        )}{' '}
                                                        v{row.payload_version}
                                                    </StatusBadge>
                                                ) : null}
                                            </div>
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {row.reason_message}
                                            </p>
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                {row.site ? (
                                                    <Link
                                                        href={row.site.href}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {row.site.name}
                                                    </Link>
                                                ) : (
                                                    'Application-wide intake'
                                                )}{' '}
                                                · {label(row.consumer)} ·
                                                Message {row.message_reference}{' '}
                                                · Replayed {row.replay_count}{' '}
                                                times
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {row.can_replay ? (
                                                <Button
                                                    size="sm"
                                                    data-test={`monitoring-replay-${row.id}`}
                                                    onClick={() =>
                                                        setAction({
                                                            row,
                                                            kind: 'replay',
                                                        })
                                                    }
                                                >
                                                    <RefreshCw className="mr-2 h-4 w-4" />
                                                    Replay
                                                </Button>
                                            ) : null}
                                            {row.can_discard ? (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    data-test={`monitoring-discard-${row.reason_code}-${row.id}`}
                                                    onClick={() =>
                                                        setAction({
                                                            row,
                                                            kind: 'discard',
                                                        })
                                                    }
                                                >
                                                    Discard
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>
                                    <p className="mt-3 rounded-lg bg-muted/50 p-2 text-xs text-muted-foreground">
                                        {row.operator_note}
                                    </p>
                                </article>
                            ))}
                        </div>
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={ShieldCheck}
                            title="No monitoring delivery failures"
                            description="There are no permission-scoped dead letters waiting for a decision."
                        />
                    )
                ) : (
                    <div className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                        Recovery actions are hidden. Monitoring health remains
                        visible without exposing global delivery evidence.
                    </div>
                )}
            </CardContent>
            <RecoveryDialog action={action} onClose={() => setAction(null)} />
        </Card>
    );
}
