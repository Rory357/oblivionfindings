import { RecordDoseDialog } from '@/components/emar/record-dose/record-dose-dialog';
import RoundAuditTimeline, {
    itemsToAuditEntries,
} from '@/components/emar/rounds/round-audit-timeline';
import { DoseStatusBadge } from '@/components/emar/rounds/round-bits';
import {
    isRecordable,
    notOwedCaption,
    type GuidedRound,
    type RoundItem,
    type StaffOption,
} from '@/components/emar/rounds/types';
import {
    EntityContextMenu,
    EntityKebab,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ClientAvatar } from '@/components/meds/board-bits';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { useIsMobile } from '@/hooks/use-mobile';
import { formatTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { Check, Eye, Pill, Play, Printer, Repeat } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

type Props = {
    guided: GuidedRound;
    witnesses: StaffOption[];
    notGivenReasons: {
        value: string;
        label: string;
        requires_detail: boolean;
    }[];
    signer: {
        name?: string;
        role_label?: string | null;
        med_competent: boolean;
        controlled_record: boolean;
        cd_witness: boolean;
    };
    canExport: boolean;
    onPrint: () => void;
    onClose: () => void;
    /** Worker board stays on Meds today through start/finish redirects. */
    workerBoard?: boolean;
    workerContext?: { client_id?: number; site_id?: number };
};

const slotKey = (item: RoundItem) =>
    `${item.medication_id}|${item.scheduled_for}`;

/** P01 C4: the round walks the same live dose rows and opens the one recorder. */
export default function GuidedRoundDialog({
    guided,
    signer,
    canExport,
    onPrint,
    onClose,
    workerBoard = false,
    workerContext,
}: Props) {
    const { round, items, progress } = guided;
    const mobile = useIsMobile();
    const [recording, setRecording] = useState<{
        item: RoundItem;
        reoffer: boolean;
    } | null>(null);
    const [saving, setSaving] = useState(false);
    const [refreshState, setRefreshState] = useState<
        'idle' | 'loading' | 'failed'
    >('idle');
    const [queuedDose, setQueuedDose] = useState<{
        roundId: number;
        key: string;
    } | null>(null);
    const awaitingQueuedDose =
        queuedDose?.roundId === round.id &&
        !items.some(
            (item) => slotKey(item) === queuedDose.key && item.administration,
        );
    const recordingLocked = refreshState !== 'idle' || awaitingQueuedDose;
    const refreshRound = () => {
        if (refreshState === 'loading') return;
        setRefreshState('loading');
        let loaded = false;
        const offException = router.on('exception', () => false);
        const offInvalid = router.on('invalid', () => false);
        router.reload({
            preserveScroll: true,
            onSuccess: () => {
                loaded = true;
            },
            onFinish: () => {
                offException();
                offInvalid();
                setRefreshState(loaded ? 'idle' : 'failed');
            },
        });
    };
    const ctx = useEntityContextMenu<RoundItem>();
    const canRecordRound = guided.can_record && signer.med_competent;
    const canCompleteRound = guided.can_complete && canRecordRound;
    const permitted = (item: RoundItem) =>
        canRecordRound && (!item.is_controlled || signer.controlled_record);
    const isDue = (item: RoundItem) =>
        isRecordable(item) && item.dose_state !== 'upcoming';
    const next = items.find((item) => isDue(item) && permitted(item));
    const nextAfter = recording
        ? items.find(
              (item) =>
                  slotKey(item) !== slotKey(recording.item) &&
                  isDue(item) &&
                  permitted(item),
          )
        : next;
    const startable = round.status === 'pending' || round.status === 'partial';
    const transition = (action: 'start' | 'complete') => {
        if (saving || recordingLocked) return;
        setSaving(true);
        router.post(
            action === 'start'
                ? `/emar/rounds/${round.id}/guided/start`
                : `/emar/rounds/${round.id}/guided/complete`,
            workerBoard ? { return_to: 'meds-today', ...workerContext } : {},
            {
                preserveScroll: true,
                onError: () =>
                    toast.error(
                        action === 'start'
                            ? 'Could not start this round'
                            : 'This round cannot be completed yet',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };
    const menu = (item: RoundItem): MenuItem[] => [
        ...(isRecordable(item)
            ? [
                  {
                      label: 'Record dose',
                      icon: Pill,
                      ...(permitted(item) && !recordingLocked
                          ? {
                                onClick: () =>
                                    setRecording({ item, reoffer: false }),
                            }
                          : {
                                disabled: recordingLocked
                                    ? 'Wait for the recorded dose to reach the chart and refresh the round.'
                                    : 'Start the round and check your recording permission.',
                            }),
                  },
              ]
            : []),
        ...(item.administration?.status === 'refused' &&
        permitted(item) &&
        !recordingLocked
            ? [
                  {
                      label: 'Record re-offer',
                      icon: Repeat,
                      onClick: () => setRecording({ item, reoffer: true }),
                  },
              ]
            : []),
        {
            label: 'View dose requirements',
            icon: Eye,
            ...(permitted(item) && !item.administration && !recordingLocked
                ? { onClick: () => setRecording({ item, reoffer: false }) }
                : {
                      disabled: item.administration
                          ? 'This dose is already recorded.'
                          : 'Recording is not available for this round.',
                  }),
        },
    ];
    return (
        <Card className="gap-5 p-5" aria-label={`Guided round: ${round.name}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-section-title">
                        Guided round · {round.name}
                    </h2>
                    <p className="text-subtle">
                        {progress.completed} of {progress.total} recorded ·{' '}
                        {round.scheduled_time} · ±{round.window_minutes} min
                    </p>
                    {guided.selected_progress &&
                        guided.selected_progress.total !== progress.total && (
                            <p className="text-caption">
                                Showing the selected person:{' '}
                                {guided.selected_progress.completed} of{' '}
                                {guided.selected_progress.total} recorded. Round
                                progress includes everyone in your view.
                            </p>
                        )}
                    {notOwedCaption(progress.waiting, progress.away) && (
                        <p className="text-caption">
                            {notOwedCaption(progress.waiting, progress.away)}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        className="frontline-tap"
                        variant="outline"
                        onClick={onClose}
                        disabled={saving}
                    >
                        Leave the round
                    </Button>
                    {canExport && (
                        <Button
                            className="frontline-tap"
                            variant="outline"
                            onClick={onPrint}
                        >
                            <Printer className="size-4" />
                            Print round sheet
                        </Button>
                    )}
                    {startable && guided.can_start && signer.med_competent ? (
                        <Button
                            className="frontline-tap"
                            onClick={() => transition('start')}
                            disabled={saving || recordingLocked}
                        >
                            <Play className="size-4" />
                            {round.status === 'partial'
                                ? 'Resume round'
                                : 'Start round'}
                        </Button>
                    ) : next ? (
                        <Button
                            className="frontline-tap max-w-full whitespace-normal"
                            disabled={recordingLocked}
                            onClick={() =>
                                setRecording({ item: next, reoffer: false })
                            }
                        >
                            <Play className="size-4 shrink-0" />
                            Record next: {next.client_name} ·{' '}
                            {next.medication_name}
                        </Button>
                    ) : progress.pending === 0 && canCompleteRound ? (
                        <Button
                            className="frontline-tap"
                            onClick={() => transition('complete')}
                            disabled={saving || recordingLocked}
                        >
                            <Check className="size-4" />
                            Finish round
                        </Button>
                    ) : (
                        round.status !== 'completed' && (
                            <p className="text-caption">
                                Round completion is not available yet.
                            </p>
                        )
                    )}
                </div>
            </div>
            {refreshState === 'loading' ? (
                <p role="status" className="text-caption">
                    Updating the round before the next dose…
                </p>
            ) : refreshState === 'failed' || awaitingQueuedDose ? (
                <div role="status" className="space-y-2 rounded-lg border p-3">
                    <p className="text-sm">
                        {awaitingQueuedDose
                            ? 'This dose is saved on this device and is waiting to reach the chart. Reconnect and check the round before recording more.'
                            : 'The dose was recorded, but the round could not refresh. Refresh before recording more; don’t give the dose again.'}
                    </p>
                    <Button
                        className="frontline-tap"
                        variant="outline"
                        onClick={refreshRound}
                    >
                        Refresh round
                    </Button>
                </div>
            ) : null}
            <Progress
                value={progress.percent}
                aria-label="Round progress"
                className="h-1.5"
            />
            {mobile ? (
                <ul className="divide-y" aria-label="Round doses">
                    {items.map((item) => (
                        <li
                            key={slotKey(item)}
                            className="min-w-0 space-y-2 py-3"
                            onContextMenu={(event) => ctx.open(event, item)}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-semibold break-words">
                                        {item.client_name}
                                    </p>
                                    <p className="text-caption">
                                        {item.site_name}
                                    </p>
                                </div>
                                <EntityKebab
                                    actions={menu(item)}
                                    label={`Actions for ${item.client_name} · ${item.medication_name}`}
                                />
                            </div>
                            <p className="font-semibold break-words">
                                {item.medication_name}
                                {item.is_controlled ? ' · controlled' : ''}
                            </p>
                            <p className="text-caption">
                                {[item.dose, item.route]
                                    .filter(Boolean)
                                    .join(' · ')}{' '}
                                · Due {formatTime(item.scheduled_for)}
                            </p>
                            <DoseStatusBadge
                                status={
                                    item.administration?.status ??
                                    item.dose_state ??
                                    'due'
                                }
                            />
                            {item.away_reason && (
                                <p className="text-caption">
                                    {item.away_reason}
                                </p>
                            )}
                            {item.administration && (
                                <p className="text-caption">
                                    {formatTime(
                                        item.administration.administered_at,
                                    )}{' '}
                                    · {item.administration.administered_by}
                                </p>
                            )}
                            {isRecordable(item) && permitted(item) && (
                                <Button
                                    className="frontline-tap"
                                    disabled={recordingLocked}
                                    onClick={() =>
                                        setRecording({ item, reoffer: false })
                                    }
                                >
                                    Record dose
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <EntityTable
                    rows={items}
                    rowKey={slotKey}
                    rowHeight="content"
                    identityLabel="Person"
                    minWidth={850}
                    identity={(item) => ({
                        name: item.client_name,
                        mark: item.client_photo_url ? (
                            <img
                                src={item.client_photo_url}
                                alt={`Photo of ${item.client_name} on file`}
                                className="size-8 rounded-full object-cover"
                            />
                        ) : (
                            <ClientAvatar
                                name={item.client_name}
                                clientId={item.client_id}
                            />
                        ),
                        subline: item.site_name,
                    })}
                    columns={[
                        {
                            key: 'medicine',
                            label: 'Medicine',
                            width: '1.6fr',
                            cell: (item) => (
                                <div>
                                    <p className="font-semibold">
                                        {item.medication_name}
                                        {item.is_controlled
                                            ? ' · controlled'
                                            : ''}
                                    </p>
                                    <p className="text-caption">
                                        {[item.dose, item.route]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: 'due',
                            label: 'Due',
                            width: '100px',
                            cell: (item) => formatTime(item.scheduled_for),
                        },
                        {
                            key: 'state',
                            label: 'State',
                            width: '1.3fr',
                            cell: (item) => (
                                <div className="flex flex-col gap-1">
                                    <DoseStatusBadge
                                        status={
                                            item.administration?.status ??
                                            item.dose_state ??
                                            'due'
                                        }
                                    />
                                    {item.away_reason && (
                                        <p className="text-caption">
                                            {item.away_reason}
                                        </p>
                                    )}
                                    {item.administration && (
                                        <p className="text-caption">
                                            {formatTime(
                                                item.administration
                                                    .administered_at,
                                            )}{' '}
                                            ·{' '}
                                            {
                                                item.administration
                                                    .administered_by
                                            }
                                        </p>
                                    )}
                                </div>
                            ),
                        },
                        {
                            key: 'action',
                            label: '',
                            width: '130px',
                            align: 'right',
                            cell: (item) =>
                                isRecordable(item) && permitted(item) ? (
                                    <Button
                                        className="frontline-tap"
                                        disabled={recordingLocked}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            setRecording({
                                                item,
                                                reoffer: false,
                                            });
                                        }}
                                    >
                                        Record
                                    </Button>
                                ) : (
                                    <span className="text-caption">
                                        {item.administration
                                            ? 'Recorded'
                                            : item.dose_state ===
                                                'pending_check'
                                              ? 'Order to check'
                                              : item.dose_state === 'away'
                                                ? 'Away'
                                                : 'View only'}
                                    </span>
                                ),
                        },
                    ]}
                    actionsFor={menu}
                    onRowContextMenu={ctx.open}
                />
            )}
            <details className="rounded-lg border p-3">
                <summary className="frontline-tap flex cursor-pointer items-center font-semibold">
                    Round history and observations
                </summary>
                <div className="pt-3">
                    <RoundAuditTimeline
                        meta={round}
                        entries={itemsToAuditEntries(items)}
                    />
                </div>
            </details>
            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    title={ctx.ctx.record.medication_name}
                    items={menu(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            )}
            {recording && (
                <RecordDoseDialog
                    key={`${slotKey(recording.item)}:${recording.reoffer}`}
                    target={{
                        kind: 'scheduled',
                        orderId: recording.item.medication_id,
                        scheduledFor: recording.item.scheduled_for,
                        label: {
                            person: recording.item.client_name,
                            medicine: recording.item.medication_name,
                        },
                    }}
                    entry="round"
                    roundId={round.id}
                    mode={recording.reoffer ? 'reoffer' : 'record'}
                    signedAs={{
                        name: signer.name ?? 'Signed-in worker',
                        role_label: signer.role_label ?? null,
                    }}
                    onRecorded={(result) => {
                        if (result.status === 'queued') {
                            setQueuedDose({
                                roundId: round.id,
                                key: slotKey(recording.item),
                            });
                        } else {
                            refreshRound();
                        }
                    }}
                    nextLabel={
                        nextAfter && !recordingLocked
                            ? `Next due: ${nextAfter.client_name} · ${nextAfter.medication_name}`
                            : null
                    }
                    onNext={
                        nextAfter && !recordingLocked
                            ? () =>
                                  setRecording({
                                      item: nextAfter,
                                      reoffer: false,
                                  })
                            : undefined
                    }
                    onClose={() => setRecording(null)}
                />
            )}
        </Card>
    );
}
