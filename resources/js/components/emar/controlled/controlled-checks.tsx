import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    BookOpen,
    ClipboardCheck,
    FileWarning,
    RefreshCw,
    ShieldCheck,
    Users,
} from 'lucide-react';
import {
    useControlledProduct,
    type ControlledWorkspace,
} from './product-client';
import type {
    ControlledMedicine,
    ControlledProductPayload,
} from './product-types';
import {
    dateTime,
    medicineLabel,
    Notice,
    quantity,
    RecordList,
    StateBadge,
} from './product-ui';
import { useControlledDialogs } from './workspace-dialogs';

export function controlledAttention(payload: ControlledProductPayload): {
    count: number;
    alert: boolean;
} {
    return {
        count: payload.meters?.controlled_attention_count ?? 0,
        alert: payload.meters?.controlled_attention_alert ?? false,
    };
}
/** Composable P07a tab. The containing Meds today page owns its header and rail. */
export function ControlledChecks({
    initialPayload,
    search = '',
    siteId,
    needsDoingNow = false,
}: {
    initialPayload?: ControlledProductPayload;
    search?: string;
    siteId?: number;
    needsDoingNow?: boolean;
}) {
    const workspace = useControlledProduct(initialPayload);
    return (
        <ControlledChecksContent
            workspace={workspace}
            search={search}
            siteId={siteId}
            needsDoingNow={needsDoingNow}
        />
    );
}
export function ControlledChecksContent({
    workspace,
    search = '',
    siteId,
    needsDoingNow = false,
}: {
    workspace: ControlledWorkspace;
    search?: string;
    siteId?: number;
    needsDoingNow?: boolean;
}) {
    const dialogs = useControlledDialogs(workspace);
    const payload = workspace.payload;
    if (workspace.loading && !payload)
        return (
            <Card
                aria-busy="true"
                aria-label="Loading controlled checks"
                className="p-5"
            >
                <SkeletonTable />
                <span className="sr-only">Loading controlled checks…</span>
            </Card>
        );
    if (!payload)
        return (
            <ErrorState
                title={
                    workspace.error?.status === 403
                        ? 'You can’t view controlled medicines'
                        : 'Couldn’t load controlled checks'
                }
                message={workspace.error?.message}
                onRetry={() => void workspace.refresh()}
            />
        );
    if (payload.can.view === false)
        return (
            <EmptyState
                icon={ShieldCheck}
                title="You can’t view controlled medicines"
                description="Ask your manager if you need controlled-medicine access for your work."
            />
        );
    const q = search.trim().toLowerCase();
    const all = payload.medicines.filter(
        (medicine) =>
            (!siteId || medicine.site_id === siteId) &&
            (!q ||
                `${medicine.name} ${medicine.client_name} ${medicine.site_name}`
                    .toLowerCase()
                    .includes(q)),
    );
    const medicines = all.filter(
        (medicine) =>
            !needsDoingNow || ['due', 'overdue'].includes(medicine.count.state),
    );
    const sites = payload.sites.filter(
        (site) =>
            (!siteId || site.id === siteId) &&
            all.some((medicine) => medicine.site_id === site.id),
    );
    const actions = (medicine: ControlledMedicine): MenuItem[] =>
        compactMenu([
            {
                label: 'Open the register',
                icon: BookOpen,
                onClick: () =>
                    dialogs.detail({ kind: 'medicine', id: medicine.id }),
            },
            payload.can.record && {
                label: 'Count this medicine',
                icon: ClipboardCheck,
                disabled:
                    medicine.balance === null
                        ? 'Balance not configured. Record a witnessed receipt first.'
                        : medicine.can_record
                          ? undefined
                          : (medicine.record_reason ??
                            'You cannot record at this house'),
                onClick: () => dialogs.count([medicine.id]),
            },
            payload.can.record && {
                label: 'Record going out or coming back',
                icon: ClipboardCheck,
                disabled: medicine.can_record
                    ? undefined
                    : (medicine.record_reason ??
                      'You cannot record at this house'),
                onClick: () =>
                    dialogs.action({
                        action: 'movement',
                        medicineId: medicine.id,
                    }),
            },
            payload.can.record && {
                label: 'Ask someone to witness',
                icon: Users,
                disabled: medicine.can_record
                    ? undefined
                    : (medicine.record_reason ??
                      'You cannot record at this house'),
                onClick: () =>
                    dialogs.action({
                        action: 'witness_request',
                        medicineId: medicine.id,
                    }),
            },
            payload.can.record && {
                label: 'Request a witness override',
                icon: ShieldCheck,
                disabled: medicine.can_record
                    ? undefined
                    : (medicine.record_reason ??
                      'You cannot request at this house'),
                onClick: () =>
                    dialogs.action({
                        action: 'override_request',
                        medicineId: medicine.id,
                    }),
            },
        ]);
    const requests = payload.requests.filter(
        (request) =>
            (!siteId || request.site_id === siteId) &&
            (!needsDoingNow || request.can_answer),
    );
    const discrepancies = payload.discrepancies.filter(
        (record) =>
            !['resolved', 'closed'].includes(record.status) &&
            all.some((medicine) => medicine.id === record.client_medication_id),
    );
    const followups = payload.overrides.filter(
        (override) =>
            (!siteId || override.site_id === siteId) &&
            override.doses.some((dose) => !dose.signed_off_at),
    );
    const snapshotAt = payload.as_at ? Date.parse(payload.as_at) : NaN;
    const activeOverrides = payload.overrides.filter(
        (override) =>
            override.status === 'approved' &&
            (!siteId || override.site_id === siteId) &&
            !!override.starts_at &&
            !!override.expires_at &&
            Date.parse(override.starts_at) <= snapshotAt &&
            Date.parse(override.expires_at) > snapshotAt,
    );
    return (
        <div className="space-y-5">
            {workspace.offline ? (
                <Notice title="You’re offline">
                    Counts and witnessed changes cannot be saved offline. PINs
                    are never stored on this device.
                </Notice>
            ) : null}
            {workspace.error ? (
                <Notice title="These details may be out of date">
                    {workspace.error.message}
                    <Button
                        variant="outline"
                        className="mt-2 min-h-11"
                        onClick={() => void workspace.refresh()}
                    >
                        <RefreshCw className="size-4" />
                        Refresh
                    </Button>
                </Notice>
            ) : null}
            {!payload.cadence.configured ? (
                <Notice title="Count cadence is not configured">
                    No count is shown as due or overdue until the organisation
                    sets the cadence. Counts can still be recorded any time.
                </Notice>
            ) : null}
            {activeOverrides.map((override) => (
                <Notice
                    key={override.id}
                    title={`Witness override active · ${override.site_name}`}
                >
                    Approved by{' '}
                    {override.decided_by_name ?? 'the permitted manager'} until{' '}
                    {dateTime(override.expires_at)} for{' '}
                    {override.medicine_ids
                        .map((id) => medicineLabel(payload, id))
                        .join('; ')}
                    . Counts and register changes still need a witness.
                    <Button
                        variant="outline"
                        className="mt-2 min-h-11"
                        onClick={() =>
                            dialogs.detail({
                                kind: 'override',
                                id: override.id,
                            })
                        }
                    >
                        View override
                    </Button>
                </Notice>
            ))}
            {sites.map((site) => {
                const siteMedicines = payload.medicines.filter(
                    (medicine) => medicine.site_id === site.id,
                );
                const eligible = siteMedicines.every(
                    (medicine) =>
                        medicine.can_record && medicine.balance !== null,
                );
                const hasWitness = (
                    payload.witnesses_by_site[String(site.id)] ?? []
                ).some(
                    (witness) =>
                        witness.id !== payload.current_user_id &&
                        witness.eligible &&
                        witness.pin_status === 'set',
                );
                return (
                    <Card key={site.id} className="gap-3 p-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p className="text-section-title">
                                    {site.name}
                                </p>
                                <p className="text-caption">
                                    {payload.cadence.configured
                                        ? (payload.cadence.label ??
                                          'Every shift change')
                                        : 'Not configured'}{' '}
                                    · {siteMedicines.length} controlled
                                    medicine(s)
                                </p>
                            </div>
                            {payload.can.record ? (
                                <Button
                                    className="min-h-11"
                                    disabled={!eligible || !hasWitness}
                                    onClick={() =>
                                        dialogs.count(
                                            siteMedicines.map(
                                                (medicine) => medicine.id,
                                            ),
                                        )
                                    }
                                >
                                    <ClipboardCheck className="size-4" />
                                    Count medicines at this house
                                </Button>
                            ) : null}
                        </div>
                        {!eligible ? (
                            <p className="text-caption">
                                {siteMedicines.some(
                                    (medicine) => medicine.balance === null,
                                )
                                    ? 'A balance is not configured for every medicine. Record a witnessed receipt first, then count the medicines at this house.'
                                    : (siteMedicines.find(
                                          (medicine) => !medicine.can_record,
                                      )?.record_reason ??
                                      'Counts are recorded by permitted staff on shift at this house.')}
                            </p>
                        ) : !hasWitness ? (
                            <Notice title="Nobody available can witness">
                                Ask the house lead to arrange an eligible
                                colleague. The count still needs a witness.
                            </Notice>
                        ) : null}
                    </Card>
                );
            })}
            <ListCaption
                title="Controlled counts"
                caption={`${medicines.length} of ${all.length} shown`}
                right={
                    <Button
                        variant="outline"
                        className="min-h-11"
                        disabled={workspace.loading}
                        onClick={() => void workspace.refresh()}
                    >
                        <RefreshCw className="size-4" />
                        Refresh
                    </Button>
                }
            />
            <RecordList
                rows={medicines}
                rowKey={(medicine) => medicine.id}
                identity={(medicine) => ({
                    name: medicine.name,
                    icon: ShieldCheck,
                    subline: `${medicine.client_name} · ${medicine.site_name}`,
                })}
                actionsFor={actions}
                onOpen={(medicine) =>
                    dialogs.detail({ kind: 'medicine', id: medicine.id })
                }
                columns={[
                    {
                        key: 'balance',
                        label: 'Register',
                        width: '0.7fr',
                        cell: (medicine) =>
                            quantity(medicine.balance, medicine.unit),
                    },
                    {
                        key: 'state',
                        label: 'Shift-change count',
                        width: '1.6fr',
                        cell: (medicine) => (
                            <div>
                                <StatusBadge
                                    variant={
                                        medicine.count.state === 'overdue'
                                            ? 'critical'
                                            : medicine.count.state === 'due'
                                              ? 'warning'
                                              : medicine.count.state ===
                                                  'counted'
                                                ? 'success'
                                                : 'neutral'
                                    }
                                >
                                    {medicine.count.title}
                                </StatusBadge>
                                {medicine.count.overdue_at &&
                                medicine.count.state === 'due' ? (
                                    <p className="text-caption">
                                        Overdue after{' '}
                                        {dateTime(medicine.count.overdue_at)}
                                    </p>
                                ) : null}
                            </div>
                        ),
                    },
                    {
                        key: 'last',
                        label: 'Last count',
                        width: '1.3fr',
                        cell: (medicine) => dateTime(medicine.count.last_at),
                    },
                    ...(payload.can.record
                        ? [
                              {
                                  key: 'count_action',
                                  label: 'Count',
                                  width: '0.8fr',
                                  cell: (medicine: ControlledMedicine) => (
                                      <Button
                                          variant="outline"
                                          className="min-h-11"
                                          disabled={
                                              !medicine.can_record ||
                                              medicine.balance === null
                                          }
                                          onClick={(event) => {
                                              event.stopPropagation();
                                              dialogs.count([medicine.id]);
                                          }}
                                          aria-label={`Count ${medicine.name} for ${medicine.client_name}`}
                                      >
                                          <ClipboardCheck className="size-4" />
                                          Count
                                      </Button>
                                  ),
                              },
                          ]
                        : []),
                ]}
                emptyTitle={
                    all.length
                        ? 'No counts need doing now'
                        : 'No controlled medicines to show'
                }
                emptyDescription={
                    all.length
                        ? 'Show all controlled medicines to record a count at any time.'
                        : 'Controlled medicines at your approved houses appear here.'
                }
            />
            {requests.length ? (
                <>
                    <ListCaption title="Witness requests" />
                    <RecordList
                        rows={requests}
                        rowKey={(request) => request.id}
                        identity={(request) => ({
                            name: `${request.requested_by_name} → ${request.witness_name}`,
                            icon: Users,
                            subline: `${request.reason ?? 'Witness request'} · ${dateTime(request.created_at)}`,
                        })}
                        columns={[
                            {
                                key: 'answer',
                                label: 'Answer',
                                width: '1fr',
                                cell: (request) => (
                                    <StateBadge
                                        status={
                                            request.response ?? request.status
                                        }
                                    />
                                ),
                            },
                        ]}
                        actionsFor={(request) =>
                            compactMenu([
                                {
                                    label: 'View request',
                                    icon: BookOpen,
                                    onClick: () =>
                                        dialogs.detail({
                                            kind: 'request',
                                            id: request.id,
                                        }),
                                },
                                request.can_answer && {
                                    label: 'Answer request',
                                    icon: Users,
                                    onClick: () =>
                                        dialogs.action({
                                            action: 'witness_answer',
                                            targetId: request.id,
                                            siteId: request.site_id,
                                        }),
                                },
                                request.can_cancel && {
                                    label: 'Cancel request',
                                    onClick: () =>
                                        dialogs.action({
                                            action: 'witness_cancel',
                                            targetId: request.id,
                                            siteId: request.site_id,
                                        }),
                                },
                            ])
                        }
                        onOpen={(request) =>
                            dialogs.detail({ kind: 'request', id: request.id })
                        }
                    />
                </>
            ) : null}
            {discrepancies.length ? (
                <>
                    <ListCaption title="Open discrepancies" />
                    <RecordList
                        rows={discrepancies}
                        rowKey={(record) => record.id}
                        identity={(record) => ({
                            name: medicineLabel(
                                payload,
                                record.client_medication_id,
                            ),
                            icon: FileWarning,
                            subline: `Discrepancy ${record.id} · ${dateTime(record.reported_at)}`,
                        })}
                        columns={[
                            {
                                key: 'state',
                                label: 'Status',
                                width: '1fr',
                                cell: (record) => (
                                    <StateBadge status={record.status} />
                                ),
                            },
                            {
                                key: 'owner',
                                label: 'Owner',
                                width: '1fr',
                                cell: (record) =>
                                    record.owner_name ?? 'Not configured',
                            },
                        ]}
                        actionsFor={(record) => [
                            {
                                label: 'View discrepancy',
                                icon: BookOpen,
                                onClick: () =>
                                    dialogs.detail({
                                        kind: 'discrepancy',
                                        id: record.id,
                                    }),
                            },
                        ]}
                        onOpen={(record) =>
                            dialogs.detail({
                                kind: 'discrepancy',
                                id: record.id,
                            })
                        }
                    />
                </>
            ) : null}
            {followups.length ? (
                <>
                    <ListCaption title="Witness override follow-ups" />
                    <RecordList
                        rows={followups}
                        rowKey={(record) => record.id}
                        identity={(record) => ({
                            name: record.site_name,
                            icon: ShieldCheck,
                            subline: `Override ${record.id} · due ${dateTime(record.followup_due_at)}`,
                        })}
                        columns={[
                            {
                                key: 'due',
                                label: 'Follow-up',
                                width: '1fr',
                                cell: (record) => (
                                    <StatusBadge
                                        variant={
                                            record.followup_overdue
                                                ? 'critical'
                                                : 'info'
                                        }
                                    >
                                        {record.followup_overdue
                                            ? 'Sign-off overdue'
                                            : 'Count and sign-off due'}
                                    </StatusBadge>
                                ),
                            },
                            {
                                key: 'doses',
                                label: 'Unchecked doses',
                                width: '1fr',
                                cell: (record) =>
                                    record.doses.filter(
                                        (dose) => !dose.signed_off_at,
                                    ).length,
                            },
                        ]}
                        actionsFor={(record) =>
                            compactMenu([
                                {
                                    label: 'View override',
                                    icon: BookOpen,
                                    onClick: () =>
                                        dialogs.detail({
                                            kind: 'override',
                                            id: record.id,
                                        }),
                                },
                                record.can_signoff && {
                                    label: 'Check and sign off',
                                    icon: ClipboardCheck,
                                    onClick: () => dialogs.followup(record),
                                },
                            ])
                        }
                        onOpen={(record) =>
                            record.can_signoff
                                ? dialogs.followup(record)
                                : dialogs.detail({
                                      kind: 'override',
                                      id: record.id,
                                  })
                        }
                    />
                </>
            ) : null}
            {dialogs.node}
        </div>
    );
}
export default ControlledChecks;
