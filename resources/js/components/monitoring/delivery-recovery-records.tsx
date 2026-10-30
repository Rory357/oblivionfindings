import { EntityChip, type MenuItem } from '@/components/lists';
import {
    EvidenceRecords,
    type EvidenceRecordsView,
} from '@/components/lists/evidence-records';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { ArchiveX, History, RefreshCw, ShieldCheck } from 'lucide-react';
import type { DeliveryRecovery } from './delivery-recovery-card';
export type DeliveryRecoveryRow =
    DeliveryRecovery['dead_letters']['rows'][number];
export const deliveryLabel = (value: string) =>
    value.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());
const created = (value: string | null) =>
    value ? formatDateTime(value) : 'Not recorded';
const versions = (value: number[] | undefined) =>
    value?.length ? value.join(', ') : 'None recorded';
export function DeliveryContracts({
    delivery,
    view,
}: {
    delivery: DeliveryRecovery;
    view: EvidenceRecordsView;
}) {
    const rows = [
        {
            key: 'envelope',
            name: 'Transport envelope',
            current: `v${delivery.contracts.envelope_current}`,
            accepted: versions(delivery.contracts.envelope_accepted),
            note: 'Accepted transport versions do not confirm delivery.',
        },
        ...Object.entries(delivery.contracts.payloads).map(([key, value]) => ({
            key: 'payload:' + key,
            name: deliveryLabel(key) + ' payload',
            current: `v${value.current}`,
            accepted: versions(value.accepted),
            note: 'Accepted payload versions do not confirm processing.',
        })),
        {
            key: 'commands',
            name: 'Device commands',
            current: `Standard ${delivery.contracts.commands.standard_current === undefined ? 'not recorded' : 'v' + delivery.contracts.commands.standard_current} · Break glass ${delivery.contracts.commands.break_glass_current === undefined ? 'not recorded' : 'v' + delivery.contracts.commands.break_glass_current}`,
            accepted: versions(delivery.contracts.commands.accepted),
            note: 'Reconcile actual state before any retry',
        },
    ];
    return (
        <EvidenceRecords
            title="Delivery versions"
            description="Supported message formats. Recovery consumes the original evidence; it does not repeat a device command."
            rows={rows}
            view={view}
            rowKey={(r) => r.key}
            identity={(r) => ({ name: r.name, subline: r.current })}
            icon={ShieldCheck}
            actionsFor={() => []}
            cardFor={(r) => ({
                meridian: 'neutral',
                icon: ShieldCheck,
                name: r.name,
                subline: r.current,
                chips: <EntityChip>Accepted {r.accepted}</EntityChip>,
                footer: { primary: r.note, secondary: 'Version compatibility' },
            })}
            compactColumns={[
                {
                    key: 'accepted',
                    label: 'Accepted versions',
                    width: '1fr',
                    cell: (r) => r.accepted,
                },
            ]}
            columns={[
                {
                    key: 'versions',
                    label: 'Version details',
                    width: '1fr',
                    cell: (r) => (
                        <>
                            <ReviewRow label="Current" value={r.current} />
                            <ReviewRow
                                label="Accepted versions"
                                value={r.accepted}
                            />
                            <ReviewRow label="Policy" value={r.note} />
                            {r.key === 'commands' ? (
                                <ReviewRow
                                    label="Recorded retry policy"
                                    value={
                                        delivery.contracts.commands
                                            .retry_policy ?? 'Not recorded'
                                    }
                                />
                            ) : null}
                        </>
                    ),
                },
            ]}
            empty="No delivery version metadata recorded"
            minWidth={850}
        />
    );
}
export function DeliveryFailureRecords({
    delivery,
    view,
    profiles,
    onAct,
}: {
    delivery: DeliveryRecovery;
    view: EvidenceRecordsView;
    profiles: boolean;
    onAct: (row: DeliveryRecoveryRow, kind: 'replay' | 'discard') => void;
}) {
    const site = (r: DeliveryRecoveryRow) =>
        r.site &&
        profiles &&
        r.site.href === `/security-devices/sites/${r.site.id}` ? (
            <Link href={r.site.href} className="font-medium hover:underline">
                {r.site.name}
            </Link>
        ) : (
            (r.site?.name ?? 'Application-wide intake')
        );
    const actions = (r: DeliveryRecoveryRow): MenuItem[] => [
        ...(r.can_replay
            ? [
                  {
                      label: 'Replay',
                      disabled: !/^[0-9a-f]{64}$/.test(r.review_token ?? '')
                          ? 'Refresh Monitoring to load the current review.'
                          : r.pending_replay
                            ? 'A replay has already been requested.'
                            : undefined,
                      icon: RefreshCw,
                      onClick: () => onAct(r, 'replay'),
                  },
              ]
            : []),
        ...(r.can_discard
            ? [
                  {
                      label: 'Discard',
                      disabled: !/^[0-9a-f]{64}$/.test(r.review_token ?? '')
                          ? 'Refresh Monitoring to load the current review.'
                          : r.pending_replay
                            ? 'A replay has already been requested.'
                            : undefined,
                      icon: ArchiveX,
                      danger: true,
                      onClick: () => onAct(r, 'discard'),
                  },
              ]
            : []),
    ];
    return (
        <EvidenceRecords
            title="Delivery recovery"
            description={delivery.dead_letters.note}
            rows={delivery.dead_letters.rows}
            view={view}
            rowKey={(r) => r.id}
            identity={(r) => ({
                name: deliveryLabel(r.reason_code),
                subline: `Failure #${r.id} · Message ${r.message_reference}`,
            })}
            articleLabel={(r) =>
                `Delivery failure ${r.id}: ${deliveryLabel(r.reason_code)}`
            }
            icon={History}
            actionsFor={actions}
            cardFor={(r) => ({
                meridian: r.pending_replay ? 'neutral' : 'warning',
                icon: History,
                name: deliveryLabel(r.reason_code),
                subline: `Failure #${r.id} · Message ${r.message_reference}`,
                chips: (
                    <StatusBadge
                        variant={r.pending_replay ? 'neutral' : 'warning'}
                    >
                        {r.pending_replay
                            ? 'Replay requested'
                            : 'Needs decision'}
                    </StatusBadge>
                ),
                alerts: (
                    <p
                        className="truncate text-xs text-muted-foreground"
                        title={r.reason_message}
                    >
                        {r.reason_message}
                    </p>
                ),
                footer: {
                    primary: site(r),
                    secondary: `${deliveryLabel(r.consumer)} · ${created(r.created_at)}`,
                },
            })}
            compactColumns={[
                {
                    key: 'state',
                    label: 'Decision',
                    width: '145px',
                    cell: (r) => (
                        <StatusBadge
                            variant={r.pending_replay ? 'neutral' : 'warning'}
                        >
                            {r.pending_replay
                                ? 'Replay requested'
                                : 'Needs decision'}
                        </StatusBadge>
                    ),
                },
                { key: 'site', label: 'Site', width: '1fr', cell: site },
            ]}
            columns={[
                {
                    key: 'evidence',
                    label: 'Delivery evidence',
                    width: '1fr',
                    cell: (r) => (
                        <>
                            <ReviewRow label="Failure" value={`#${r.id}`} />
                            <ReviewRow
                                label="Reason"
                                value={deliveryLabel(r.reason_code)}
                            />
                            <ReviewRow
                                label="Details"
                                value={r.reason_message}
                            />
                            <ReviewRow
                                label="Consumer"
                                value={deliveryLabel(r.consumer)}
                            />
                            <ReviewRow
                                label="Message reference"
                                value={r.message_reference}
                            />
                            <ReviewRow label="Site" value={site(r)} />
                            <ReviewRow
                                label="Recorded"
                                value={created(r.created_at)}
                            />
                            <ReviewRow
                                label="Replayed"
                                value={r.replay_count}
                            />
                            <ReviewRow
                                label="Transport version"
                                value={r.schema_version ?? 'Not recorded'}
                            />
                            <ReviewRow
                                label="Payload version"
                                value={r.payload_version ?? 'Not recorded'}
                            />
                            <ReviewRow
                                label="Message type"
                                value={
                                    r.message_type
                                        ? deliveryLabel(r.message_type)
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Decision state"
                                value={
                                    r.pending_replay
                                        ? 'Replay requested'
                                        : 'Awaiting decision'
                                }
                            />
                            <ReviewRow
                                label="Replay available"
                                value={r.can_replay ? 'Yes' : 'No'}
                            />
                            <ReviewRow
                                label="Discard available"
                                value={r.can_discard ? 'Yes' : 'No'}
                            />
                            <ReviewRow
                                label="Operator note"
                                value={r.operator_note}
                            />
                        </>
                    ),
                },
            ]}
            empty="No delivery failures shown in your permitted scope"
            gap={
                delivery.dead_letters.truncated ? (
                    <p role="note" className="text-sm text-muted-foreground">
                        Showing {delivery.dead_letters.shown} loaded failures.
                        Earlier permitted failures may also need a decision.
                    </p>
                ) : null
            }
            minWidth={1000}
        />
    );
}
