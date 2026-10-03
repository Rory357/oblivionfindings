import { ConfirmDialog } from '@/components/confirm-dialog';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';
import { ManageAlertsDialog } from '@/pages/emar/components/mar-governance-dialogs';
import { router } from '@inertiajs/react';
import { BellRing, ClipboardCheck, Pill, Plus } from 'lucide-react';
import { useState } from 'react';
import { AllergyRecord } from './allergy-record';
import { ReadingState } from './reading';
import { isConcealed, type MaybeConcealed } from './types';
import { concealedIdentity, SectionCard } from './ui';
import { useRecordJson } from './use-record-json';

type Alert = {
    key: string;
    id: number;
    title: string;
    detail: string | null;
    type: string;
    enabled: boolean;
    prompt_on_open: boolean;
    created_at: string;
    created_by: string | null;
    resolved_at: string | null;
    resolved_by: string | null;
};
type Pair = {
    key: string;
    a: string;
    b: string;
    severity: string;
    description: string | null;
    management: string | null;
    by: string | null;
    at: string;
};
type Safety = {
    alerts: MaybeConcealed<Alert>[];
    interactions: { rows: MaybeConcealed<Pair>[]; hidden: number };
    suppression: { suppressed: boolean; reason: string | null };
    can_manage: boolean;
};

export function SafetySection({
    clientId,
    view,
}: {
    clientId: number;
    view: string;
}) {
    const { data, load, reload } = useRecordJson<Safety>(
        `/emar/clients/${clientId}/record/safety`,
    );
    const [manage, setManage] = useState(false);
    const [resolve, setResolve] = useState<Alert | null>(null);
    const [busy, setBusy] = useState(false);
    if (view === 'allergies') return <AllergyRecord clientId={clientId} />;
    if (!data || load !== 'ready')
        return <ReadingState load={load} reload={reload} />;
    return (
        <>
            {view === 'interactions' ? (
                <SectionCard title="Recorded interactions" icon={Pill}>
                    <p className="text-sm">
                        Recorded interaction rules matching current medicines.
                        Follow the recorded clinical guidance; this list does
                        not establish that other interactions are absent.
                    </p>
                    {data.interactions.rows.length ? (
                        <EntityTable
                            rows={data.interactions.rows}
                            rowKey={(row) => row.key}
                            identityLabel="Medicines"
                            identity={(row) =>
                                isConcealed(row)
                                    ? concealedIdentity
                                    : {
                                          icon: Pill,
                                          name: `${row.a} + ${row.b}`,
                                          subline: row.description,
                                      }
                            }
                            minWidth={800}
                            rowHeight="content"
                            actionsFor={() => []}
                            columns={[
                                {
                                    key: 'severity',
                                    label: 'Severity',
                                    width: '1fr',
                                    cell: (row) =>
                                        isConcealed(row) ? '—' : row.severity,
                                },
                                {
                                    key: 'guidance',
                                    label: 'Guidance',
                                    width: '2fr',
                                    cell: (row) =>
                                        isConcealed(row)
                                            ? '—'
                                            : (row.management ??
                                              'Not recorded'),
                                },
                                {
                                    key: 'by',
                                    label: 'Recorded by',
                                    width: '1fr',
                                    cell: (row) =>
                                        isConcealed(row)
                                            ? '—'
                                            : (row.by ?? '—'),
                                },
                            ]}
                        />
                    ) : (
                        <p className="text-caption text-muted-foreground">
                            No matching interaction rule recorded.
                        </p>
                    )}
                </SectionCard>
            ) : (
                <SectionCard
                    title="Chart alerts"
                    icon={BellRing}
                    right={
                        data.can_manage && (
                            <Button
                                variant="outline"
                                onClick={() => setManage(true)}
                            >
                                <Plus className="size-4" /> Add alert / settings
                            </Button>
                        )
                    }
                >
                    <p className="text-sm">
                        Administration alerts are{' '}
                        {data.suppression.suppressed ? 'suppressed' : 'on'}
                        {data.suppression.reason
                            ? ` · ${data.suppression.reason}`
                            : ''}
                        .
                    </p>
                    {data.alerts.length ? (
                        <EntityTable
                            rows={data.alerts}
                            rowKey={(row) => row.key}
                            identityLabel="Alert"
                            identity={(row) =>
                                isConcealed(row)
                                    ? concealedIdentity
                                    : {
                                          icon: BellRing,
                                          name: row.title,
                                          subline: row.detail,
                                      }
                            }
                            minWidth={850}
                            rowHeight="content"
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '1fr',
                                    cell: (row) =>
                                        isConcealed(row) ? (
                                            '—'
                                        ) : (
                                            <StatusBadge
                                                variant={
                                                    row.resolved_at ||
                                                    !row.enabled
                                                        ? 'neutral'
                                                        : 'warning'
                                                }
                                            >
                                                {row.resolved_at
                                                    ? 'Resolved'
                                                    : row.enabled
                                                      ? 'Active'
                                                      : 'Off'}
                                            </StatusBadge>
                                        ),
                                },
                                {
                                    key: 'prompt',
                                    label: 'On opening chart',
                                    width: '1fr',
                                    cell: (row) =>
                                        isConcealed(row)
                                            ? '—'
                                            : row.prompt_on_open
                                              ? 'Prompt staff'
                                              : 'Shown in record',
                                },
                                {
                                    key: 'by',
                                    label: 'Added',
                                    width: '1.5fr',
                                    cell: (row) =>
                                        isConcealed(row)
                                            ? '—'
                                            : `${row.created_by ?? 'Not recorded'} · ${formatDateTime(row.created_at)}`,
                                },
                            ]}
                            actionsFor={(row) =>
                                isConcealed(row) ||
                                !data.can_manage ||
                                row.resolved_at
                                    ? []
                                    : [
                                          {
                                              label: 'Resolve alert',
                                              icon: ClipboardCheck,
                                              onClick: () => setResolve(row),
                                          },
                                      ]
                            }
                        />
                    ) : (
                        <p className="text-caption text-muted-foreground">
                            No chart alerts recorded.
                        </p>
                    )}
                </SectionCard>
            )}
            {manage && (
                <ManageAlertsDialog
                    clientId={clientId}
                    suppression={data.suppression}
                    onClose={() => {
                        setManage(false);
                        reload();
                    }}
                />
            )}
            <ConfirmDialog
                open={resolve !== null}
                onClose={() => setResolve(null)}
                title="Resolve this chart alert?"
                description="The alert remains in history. Follow the person’s plan before resolving it."
                processing={busy}
                confirmText="Resolve alert"
                onConfirm={() => {
                    if (!resolve) return;
                    setBusy(true);
                    router.post(
                        `/emar/attention-alerts/${resolve.id}/resolve`,
                        {},
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setResolve(null);
                                reload();
                            },
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            />
        </>
    );
}
